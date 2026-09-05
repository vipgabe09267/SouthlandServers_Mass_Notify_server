<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php';
class TestDelivery {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $commands = [];
    public $desktopFails = true;
    public $audioFails = true;
    public $audioThrows = false;
    public $audioPriority = '';
    public $jobDirectory = '';
    public $cooldownRemaining = 0;
    public $currentTargets = ['phones' => ['1000'], 'desktops' => ['gabe'], 'webhooks' => ['hook']];
    private function currentAnnouncementDestinationIds() { return $this->currentTargets; }
    private function startAnnouncementWorker($id) {}
    private function announcementJobDirectory() { return $this->jobDirectory; }
    public function run($request) { return $this->executeResolvedAnnouncement($request); }
    private function sanitizeScheduleText($text, $limit, $single) { return substr($text, 0, $limit); }
    private function buildAnnouncementVisualPushCommand($message, $targets, $timeout, $options) { return $options['mode']; }
    private function executeAnnouncementVisualPushCommand($command) { $this->commands[] = $command; return ['success' => $command !== 'api_only' || !$this->desktopFails]; }
    private function sendAnnouncementTtsAudio($phones, $message, $context) {
        $this->audioPriority = $context['priority'] ?? 'normal';
        if ($this->audioThrows) { throw new RuntimeException('simulated audio exception'); }
        $this->lastAudioQueueResults = ['1000' => !$this->audioFails];
        return ['success' => !$this->audioFails, 'delivery_started' => false, 'message' => 'fixture failure'];
    }
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted' => $args[0] ? [['id' => 'hook', 'name' => 'Fixture']] : [], 'failed' => []]; }
    private function setAnnouncementCooldown() {}
    private function getAnnouncementCooldownState() { return ['remaining' => $this->cooldownRemaining]; }
    private function appendAnnouncementNotifyLog(...$args) {}
}
$request = ['message' => 'fixture', 'sender' => 'Gabe', 'phones' => ['1000'], 'desktops' => ['gabe'], 'webhooks' => ['hook'], 'audio_mode' => 'tts', 'timeout_mode' => 'none', 'display_timeout' => 0, 'opening_tone' => '', 'closing_tone' => '', 'voice' => '', 'volume' => 25, 'trigger_source' => 'fixture', 'image' => false, 'title' => 'Fixture', 'background_color' => '#000000'];
$fixture = new TestDelivery();
$request['priority'] = 'urgent';
$result = $fixture->run($request);
if ($fixture->audioPriority !== 'urgent') { throw new RuntimeException('Urgent priority did not reach audio preparation'); }
if ($result['success'] || !$result['partial_delivery'] || count($result['receipts']) !== 4 || !in_array('phone_only', $fixture->commands, true)) {
    throw new RuntimeException('Independent phone/webhook channels were blocked or partial success was misreported');
}
if ($result['sender'] !== 'Gabe') { throw new RuntimeException('Sender missing'); }
echo "Independent-channel failures, sender and partial receipts checks passed.\n";
$fixture->audioThrows = true;
$result = $fixture->run($request);
if (count($result['receipts']) !== 4 || !$result['partial_delivery']) { throw new RuntimeException('Audio exception suppressed another channel'); }
$directory = sys_get_temp_dir() . '/sls-job-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); $fixture->jobDirectory = $directory;
$id = 'job_' . str_repeat('a', 32);
$path = $directory . '/' . $id . '.json';
try {
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'queued', 'created_at'=>gmdate('c'), 'request'=>$request]));
    $fixture->processAnnouncementJobs($id);
    $job = $fixture->getAnnouncementJob($id);
    if ($job['state'] !== 'partial_or_failed' || count($job['receipts']) !== 4) { throw new RuntimeException('Durable receipts were not persisted'); }
    if (!$job['retryable']) { throw new RuntimeException('Known audio failure did not offer retry'); }
    $retry = $fixture->retryFailedAnnouncementJob($id, ['sender'=>'Retry operator']);
    if (empty($retry['queued'])) { throw new RuntimeException('Failed destination retry was not queued'); }
    if (!empty($fixture->retryFailedAnnouncementJob($id)['success'])) { throw new RuntimeException('Double click created a second retry'); }
    $beforeRetry = count($fixture->commands);
    $fixture->processAnnouncementJobs($retry['job_id']);
    $child = $fixture->getAnnouncementJob($retry['job_id']);
    if (count($child['receipts']) !== 1 || $child['receipts'][0]['channel'] !== 'audio' || count($fixture->commands) !== $beforeRetry) {
        throw new RuntimeException('Retry replayed an accepted or uncertain visual destination');
    }
    $before = count($fixture->commands); $fixture->processAnnouncementJobs($id);
    if (count($fixture->commands) !== $before) { throw new RuntimeException('Completed job was replayed'); }
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'running', 'created_at'=>gmdate('c'), 'request'=>$request]));
    $fixture->processAnnouncementJobs($id);
    if ($fixture->getAnnouncementJob($id)['state'] !== 'uncertain' || count($fixture->commands) !== $before) { throw new RuntimeException('Interrupted job was replayed'); }
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'queued', 'created_at'=>gmdate('c', time()-901), 'request'=>$request]));
    $fixture->processAnnouncementJobs($id);
    if ($fixture->getAnnouncementJob($id)['state'] !== 'expired') { throw new RuntimeException('Stale job was delivered'); }
    $fixture->currentTargets = ['phones'=>[], 'desktops'=>[], 'webhooks'=>[]];
    $before = count($fixture->commands);
    $disabled = $fixture->run($request);
    if (count($fixture->commands) !== $before || count(array_filter($disabled['receipts'], static function($row) { return $row['state'] === 'cancelled'; })) !== 4) {
        throw new RuntimeException('Removed destinations were still submitted');
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
echo "Durable jobs, exception isolation, interrupted-worker no-replay and expiry checks passed.\n";
