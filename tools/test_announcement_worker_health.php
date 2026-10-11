<?php
declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
require dirname(__DIR__) . '/slsmassnotifyserver/StatusHealth.php';

$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php');
$start = strpos($source, '    public function getAnnouncementWorkerHealth()');
$end = strpos($source, "\n    private function executeResolvedAnnouncement(", $start);
if ($start === false || $end === false) { throw new RuntimeException('Worker health method is unavailable.'); }
$directory = sys_get_temp_dir() . '/sls-worker-health-' . bin2hex(random_bytes(8));
mkdir($directory, 0750); mkdir($directory . '/jobs', 0750);
$helper = $directory . '/sls_mass_notify_announcement_worker.php';
file_put_contents($helper, '<?php'); chmod($helper, 0750);
$method = substr($source, $start, $end - $start);
eval('namespace FreePBX\\modules; final class WorkerHealthFixture {'
    . 'const RUNTIME_DIR=' . var_export($directory, true) . ';'
    . 'private function announcementJobDirectory(){return self::RUNTIME_DIR."/jobs";}'
    . $method . '}');
$fixture = new \FreePBX\modules\WorkerHealthFixture();
$store = new SlsAnnouncementJobStore($directory . '/jobs');
$checks = 0;
$check = static function ($condition, string $message) use (&$checks): void {
    $checks++; if (!$condition) { throw new RuntimeException($message); }
};
$state = ['state'=>'complete','updated_at'=>gmdate('c'),'finished_at'=>gmdate('c')];
$store->atomic('worker-state.json', $state);
try {
    $health = $fixture->getAnnouncementWorkerHealth();
    $check(!$health['ok'] && $health['health_failure_code'] === 'worker_probe_missing', 'Completed history cannot substitute for a missing health probe.');
    $check($health['latest_state'] === 'complete' && str_contains($health['health_reason'], 'maintenance'), 'Missing probe has a specific maintenance diagnosis.');
    foreach ([[-601,'worker_probe_stale'], [61,'worker_probe_future'], [0,'']] as [$offset,$code]) {
        $store->atomic('worker-probe.json', ['ok'=>true,'bootstrap'=>true,'module_loaded'=>true,'checked_at'=>gmdate('c',time()+$offset)]);
        $before = file_get_contents($directory . '/jobs/worker-state.json');
        $health = $fixture->getAnnouncementWorkerHealth();
        $check($health['health_failure_code'] === $code && $health['ok'] === ($code === ''), 'Probe age must govern current health independently of history.');
        $check(file_get_contents($directory . '/jobs/worker-state.json') === $before, 'Health reads must preserve delivery history.');
    }
    $store->atomic('worker-probe.json', ['ok'=>false,'bootstrap'=>false,'module_loaded'=>false,'checked_at'=>gmdate('c'),'failure_category'=>'worker_bootstrap_failed']);
    $health = $fixture->getAnnouncementWorkerHealth();
    $check($health['health_failure_code'] === 'worker_probe_failed' && $health['failure_category'] === 'worker_bootstrap_failed', 'Bootstrap failures retain their specific category.');
    $store->atomic('worker-probe.json', ['ok'=>true,'bootstrap'=>true,'module_loaded'=>true,'checked_at'=>gmdate('c')]);
    chmod($helper, 0640); clearstatcache();
    $health = $fixture->getAnnouncementWorkerHealth();
    $check(!$health['ok'] && $health['health_failure_code'] === 'worker_helper_missing', 'Missing execute permission blocks readiness.');
    chmod($helper, 0750); clearstatcache();
    $check($fixture->getAnnouncementWorkerHealth()['ok'], 'A fresh successful probe clears the warning without erasing history.');
    unlink($directory . '/jobs/worker-probe.json');
    symlink('/dev/null', $directory . '/jobs/worker-probe.json');
    $health = $fixture->getAnnouncementWorkerHealth();
    $check(!$health['ok'] && $health['health_failure_code'] === 'worker_storage_unavailable', 'Unsafe state links cannot pass health checks.');
    echo "Announcement worker health checks passed ($checks).\n";
} finally {
    foreach (glob($directory . '/jobs/*') as $file) { unlink($file); }
    rmdir($directory . '/jobs'); unlink($helper); rmdir($directory);
}
