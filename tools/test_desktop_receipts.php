<?php
declare(strict_types=1);
// Only disposable fixture files and inert delivery adapters are used.
$root = sys_get_temp_dir() . '/sls-desktop-receipts-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/sipnotify', 0750);
define('DESKTOP_ACK_DIRECTORY', $root . '/sipnotify/acknowledgements');
define('RETENTION_MAX_EVENTS', 1000);
$api = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/api/sipnotify/index.php');
foreach ([['function atomic_replace_journal', 'function retained_events'], ['function record_desktop_acknowledgement', "\n\n\$endpoint ="]] as [$start, $end]) {
    $offset = strpos($api, $start); eval(substr($api, $offset, strpos($api, $end, $offset) - $offset));
}
function retention_days($settings) { return 90; }
function expectReceipt($value, $message) { if (!$value) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
require dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php';
$fixture = <<<'PHP'
class ReceiptDeliveryFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $earlyAck = false;
    public $id = '';
    private function announcementJobDirectory() { return self::PLUGIN_DATA_DIR; }
    private function currentAnnouncementDestinationIds() { return ['phones'=>[], 'desktops'=>['alice'], 'webhooks'=>[]]; }
    private function acquireAnnouncementActivityLock(...$args) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function sanitizeScheduleText($text, ...$args) { return $text; }
    private function buildAnnouncementVisualPushCommand(...$args) { return 'inert'; }
    private function executeAnnouncementVisualPushCommand($command) {
        if ($this->earlyAck) { record_desktop_acknowledgement([], ['username'=>'alice', 'client_id'=>'client-a'], ['id'=>$this->id]); }
        return ['success'=>true, 'output'=>['noise', 'SLS_DESKTOP_PUBLICATION ' . json_encode(['event_id'=>$this->id])]];
    }
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted'=>[], 'failed'=>[]]; }
    private function setAnnouncementCooldown() {}
    private function getAnnouncementCooldownState() { return ['remaining'=>0]; }
    private function appendAnnouncementNotifyLog(...$args) {}
    public function refresh($rows, $created = '') { return $this->refreshAnnouncementDesktopReceipts(['receipts'=>$rows], $created ?: gmdate('c')); }
    public function deliver($request) { return $this->executeResolvedAnnouncement($request); }
PHP;
eval($fixture . 'const PLUGIN_DATA_DIR = ' . var_export($root, true) . ';}');
$module = new ReceiptDeliveryFixture();
$first = 'announcement-20260920220339477893-' . str_repeat('a', 32);
$second = 'announcement-20260920220411336883-' . str_repeat('b', 32);
$client = ['username'=>'alice', 'client_id'=>'client-a'];
$row = ['channel'=>'desktop', 'target'=>'alice', 'client_id'=>'client-a', 'event_id'=>$first, 'state'=>'published'];
$now = time();
try {
    expectReceipt($module->refresh([$row])['receipts'][0] === $row, 'Missing evidence fabricated receipt.');
    $presence = $root . '/desktop-last-seen.json';
    file_put_contents($presence, json_encode(['alice'=>['client_id'=>'client-a','seen_at'=>gmdate('c', $now-120)]])); chmod($presence,0640);
    $waiting = $module->refresh([$row])['receipts'][0];
    expectReceipt($waiting['state'] === 'published' && strpos($waiting['detail'], 'no recent authenticated request') !== false,
        'Stale presence did not explain missing receipt without fabricating delivery failure.');
    file_put_contents($presence,json_encode(['alice'=>['client_id'=>'client-a','seen_at'=>gmdate('c')]]));
    expectReceipt($module->refresh([$row])['receipts'][0]['state'] === 'published', 'Presence was mistaken for a receipt.');
    file_put_contents($presence,json_encode(['alice'=>['client_id'=>'different-device','seen_at'=>gmdate('c', $now-120)]]));
    expectReceipt($module->refresh([$row])['receipts'][0] === $row, 'Presence crossed device identity.');
    unlink($presence); symlink($root.'/outside', $presence);
    expectReceipt($module->refresh([$row])['receipts'][0] === $row, 'Unsafe advisory presence changed the receipt.'); unlink($presence);
    expectReceipt(record_desktop_acknowledgement([], $client, ['id'=>$first], $now-5), 'First ACK failed.');
    expectReceipt(record_desktop_acknowledgement([], $client, ['id'=>$second], $now-3), 'Second ACK failed.');
    expectReceipt(record_desktop_acknowledgement([], $client, ['id'=>$first], $now-1), 'Duplicate ACK failed.');
    $rows = [$row, array_replace($row, ['event_id'=>$second]), array_replace($row, ['target'=>'bob']),
        array_replace($row, ['client_id'=>'replacement-device']), array_diff_key($row, ['event_id'=>true]),
        array_diff_key($row, ['client_id'=>true])];
    $result = $module->refresh($rows);
    expectReceipt($result['receipts'][0]['state'] === 'received' && $result['receipts'][1]['state'] === 'received', 'Consecutive ACKs did not reconcile.');
    expectReceipt($result['receipts'][0]['received_at'] === gmdate('c', $now-5), 'Duplicate ACK changed first receipt time.');
    expectReceipt($result['receipts'][0]['detail'] === 'Received by desktop app', 'Receipt made a human acknowledgement claim.');
    foreach ([2,3,4,5] as $index) { expectReceipt($result['receipts'][$index] === $rows[$index], 'Receipt crossed identity or guessed missing metadata.'); }
    $store = new SlsAnnouncementJobStore($root);
    $store->write(['id'=>'job_' . str_repeat('a', 32), 'state'=>'complete', 'created_at'=>gmdate('c'), 'receipts'=>[],
        'request'=>['audio_mode'=>'none'], 'result'=>['success'=>true, 'receipts'=>[$row]]]);
    $job = $module->getAnnouncementJob('job_' . str_repeat('a', 32));
    expectReceipt($job['receipts'][0]['state'] === 'received' && !$job['receipt_poll_pending'], 'Final result rows were not refreshed.');
    $missing = array_replace($row, ['event_id'=>'unknown']);
    expectReceipt($module->refresh([$missing])['receipt_poll_pending'], 'New unconfirmed event did not request refresh.');
    expectReceipt(!$module->refresh([$missing], gmdate('c', $now-601))['receipt_poll_pending'], 'Receipt refresh exceeded ten minutes.');
    $module->id = 'announcement-20260920220511336883-' . str_repeat('c', 32); $module->earlyAck = true;
    $delivered = $module->deliver(['message'=>'fixture', 'sender'=>'Fixture', 'phones'=>[], 'desktops'=>['alice'], 'desktop_client_ids'=>['alice'=>'client-a'],
        'webhooks'=>[], 'audio_mode'=>'none', 'timeout_mode'=>'none', 'display_timeout'=>0, 'trigger_source'=>'fixture', 'image'=>false,
        'title'=>'Fixture', 'background_color'=>'#000000', 'delivery_timestamp'=>gmdate('c')]);
    expectReceipt($delivered['success'] && $delivered['receipts'][0]['event_id'] === $module->id
        && $delivered['receipts'][0]['client_id'] === 'client-a' && $delivered['receipts'][0]['state'] === 'received', 'Early ACK or synchronous receipt identity was lost.');
    $path = DESKTOP_ACK_DIRECTORY . '/' . hash('sha256', 'id:client-a') . '.json';
    $lock = fopen($path . '.lock', 'c+'); flock($lock, LOCK_EX);
    try { expectReceipt(!record_desktop_acknowledgement([], $client, ['id'=>'busy']), 'Contended storage did not return a retryable failure.'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    // A username edit must not relabel a prior receipt in the same device ledger.
    expectReceipt(record_desktop_acknowledgement([], ['username'=>'renamed', 'client_id'=>'client-a'], ['id'=>'new-name']), 'Renamed client could not record a new receipt.');
    expectReceipt($module->refresh([$row])['receipts'][0]['state'] === 'received', 'Renaming a client lost its earlier exact receipt.');
    expectReceipt($module->refresh([array_replace($row, ['target'=>'renamed'])])['receipts'][0]['state'] === 'published', 'Renaming a client relabeled an earlier receipt.');
    file_put_contents($path, '{broken');
    expectReceipt(isset($module->refresh([$row])['receipt_status_error']), 'Corrupt evidence looked like successful status.');
    expectReceipt(!record_desktop_acknowledgement([], $client, ['id'=>'failure']) && file_get_contents($path) === '{broken', 'Corrupt history was replaced.');
    unlink($path); file_put_contents($root . '/outside', 'untouched'); symlink($root . '/outside', $path);
    expectReceipt(isset($module->refresh([$row])['receipt_status_error']), 'Linked receipt file was followed.');
    unlink($path); link($root . '/outside', $path);
    expectReceipt(isset($module->refresh([$row])['receipt_status_error']), 'Hard-linked receipt file was accepted.');
    expectReceipt(file_get_contents($root . '/outside') === 'untouched', 'Unsafe receipt target changed.');
    echo "Exact desktop receipts, early ACK, consecutive events, identities, duplicate timestamps, GET/synchronous reconciliation and unsafe storage checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($root);
}
