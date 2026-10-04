<?php
declare(strict_types=1);
require_once (getenv('SLS_ANNOUNCEMENT_TRAIT') ?: dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php');
class TestDelivery {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $commands = [];
    public $desktopFails = true;
    public $audioFails = true;
    public $audioThrows = false;
    public $audioUncertain = false;
    public $audioPermissionDeniedTargets = ['phones' => [], 'voice_recipient_ids' => []];
    public $audioPriority = '';
    public $jobDirectory = '';
    public $notifyLogs = [];
    public $cooldownRemaining = 0;
    public $currentTargets = ['phones' => ['1000'], 'desktops' => ['gabe'], 'webhooks' => ['hook']];
    private function currentAnnouncementDestinationIds() { return $this->currentTargets; }
    private function startAnnouncementWorker($id) {}
    private function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function announcementJobDirectory() { return $this->jobDirectory; }
    // Presence I/O is exercised by test_desktop_receipts.php; this transport
    // adapter has no PBX data directory and never reads production presence.
    private function announcementDesktopPresence(): array { return []; }
    protected function readAnnouncementPhoneOutcomes($correlation) { return ['available' => false, 'active' => false, 'uncertain' => true, 'targets' => [], 'truncated' => false, 'playback_confirmed' => false]; }
    public function run($request) { return $this->executeResolvedAnnouncement($request); }
    private function sanitizeScheduleText($text, $limit, $single) { return substr($text, 0, $limit); }
    private function buildAnnouncementVisualPushCommand($message, $targets, $timeout, $options) { return $options['mode']; }
    private function executeAnnouncementVisualPushCommand($command) { $this->commands[] = $command; return ['success' => $command !== 'api_only' || !$this->desktopFails]; }
    protected function executeAnnouncementPhoneVisualBatch(array $commands, callable $completed): void {
        foreach ($commands as $target => $command) { $completed((string)$target, array_merge($this->executeAnnouncementVisualPushCommand($command), ['started' => true])); }
    }
    private function sendAnnouncementTtsAudio($phones, $message, $context) {
        $this->audioPriority = $context['priority'] ?? 'normal';
        if ($this->audioThrows) { throw new RuntimeException('simulated audio exception'); }
        $this->lastAudioQueueResults = ['1000' => !$this->audioFails];
        return ['success' => !$this->audioFails, 'delivery_started' => false, 'message' => 'fixture failure', 'submission_uncertain' => $this->audioUncertain,
            'permission_denied_targets' => $this->audioPermissionDeniedTargets];
    }
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted' => $args[0] ? [['id' => 'hook', 'name' => 'Fixture']] : [], 'failed' => []]; }
    private function setAnnouncementCooldown() {}
    private function recordInteractiveAnnouncementAdmission(array $request): void {}
    private function getAnnouncementCooldownState() { return ['remaining' => $this->cooldownRemaining]; }
    private function appendAnnouncementNotifyLog(...$args) { $this->notifyLogs[]=$args; }
}
$request = ['message' => 'fixture', 'sender' => 'Gabe', 'phones' => ['1000'], 'desktops' => ['gabe'], 'webhooks' => ['hook'], 'audio_mode' => 'tts', 'timeout_mode' => 'none', 'display_timeout' => 0, 'opening_tone' => '', 'closing_tone' => '', 'voice' => 'original-voice', 'volume' => 25, 'trigger_source' => 'fixture', 'image' => false, 'title' => 'Fixture', 'background_color' => '#000000',
    'delivery_id' => 'announcement-fixture-original', 'delivery_timestamp' => '2026-09-20T12:00:00+00:00', 'webhook_fingerprints' => ['hook' => hash('sha256', 'https://hooks.example.com/original')]];
$fixture = new TestDelivery();
$request['priority'] = 'urgent';
$result = $fixture->run($request);
if ($fixture->audioPriority !== 'urgent') { throw new RuntimeException('Urgent priority did not reach audio preparation'); }
if ($result['success'] || !$result['partial_delivery'] || count($result['receipts']) !== 4 || !in_array('phone_only', $fixture->commands, true)) {
    throw new RuntimeException('Independent phone/webhook channels were blocked or partial success was misreported');
}
if ($result['sender'] !== 'Gabe') { throw new RuntimeException('Sender missing'); }
if (($fixture->notifyLogs[0][1]['sender']??'')!=='Gabe' || count($fixture->notifyLogs[0][1]['delivery_receipts']??[])!==4) { throw new RuntimeException('Announcement activity did not retain sender and per-destination outcomes.'); }
if (!$result['submission_uncertain']) { throw new RuntimeException('Uncertain channel was hidden in the delivery summary.'); }
$uncertainOnly = $fixture->run(array_replace($request, ['phones' => [], 'webhooks' => [], 'audio_mode' => 'none']));
if (!$uncertainOnly['submission_uncertain'] || !$uncertainOnly['delivery_started'] || strpos($uncertainOnly['message'], 'may have been delivered') === false) {
    throw new RuntimeException('All-uncertain delivery was incorrectly reported as no submission.');
}
echo "Independent-channel failures, sender and partial receipts checks passed.\n";
$unavailableRequest = $request;
$unavailableRequest['unavailable_phones'] = ['1002'];
$unavailable = $fixture->run($unavailableRequest);
$unavailableReceipts = array_filter($unavailable['receipts'], static function ($row) { return $row['target'] === '1002' && $row['state'] === 'unavailable'; });
if (count($unavailableReceipts) !== 2 || $unavailable['success']) { throw new RuntimeException('Unavailable requested phones disappeared from channel outcomes.'); }
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
    if ($job['state'] !== 'failed' || count($job['receipts']) !== 4 || !$job['submission_uncertain']) { throw new RuntimeException('Durable receipts and uncertainty were not persisted'); }
    if (!$job['retryable']) { throw new RuntimeException('Known audio failure did not offer retry'); }
    $retry = $fixture->retryFailedAnnouncementJob($id, ['sender'=>'Retry operator']);
    if (empty($retry['queued'])) { throw new RuntimeException('Failed destination retry was not queued'); }
    $retryRequest = json_decode(file_get_contents($directory . '/' . $retry['job_id'] . '.json'), true)['request'];
    foreach (['sender', 'delivery_id', 'delivery_timestamp', 'voice', 'webhook_fingerprints', 'trigger_source'] as $field) {
        if ($retryRequest[$field] !== $request[$field]) { throw new RuntimeException('Retry changed original payload field: ' . $field); }
    }
    if ($retryRequest['retry_operator'] !== 'Retry operator') { throw new RuntimeException('Retry operator was not audited separately.'); }
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
    if ($fixture->getAnnouncementJob($id)['state'] !== 'failed' || !$fixture->getAnnouncementJob($id)['submission_uncertain'] || count($fixture->commands) !== $before) { throw new RuntimeException('Interrupted job was replayed'); }
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'queued', 'created_at'=>gmdate('c', time()-901), 'request'=>$request]));
    $fixture->processAnnouncementJobs($id);
    if ($fixture->getAnnouncementJob($id)['state'] !== 'expired') { throw new RuntimeException('Stale job was delivered'); }
    $fixture->audioThrows = false; $fixture->audioFails = false; $fixture->desktopFails = false;
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'queued', 'created_at'=>gmdate('c'), 'request'=>$request]));
    $fixture->processAnnouncementJobs($id);
    $completed = $fixture->getAnnouncementJob($id);
    $fixture->audioUncertain = true;
    $uncertainAudio = $fixture->run(array_replace($request, ['desktops' => [], 'webhooks' => []]));
    $uncertainAudioRows = array_values(array_filter($uncertainAudio['receipts'], static function ($row) { return $row['channel'] === 'audio'; }));
    if (!$uncertainAudio['submission_uncertain'] || $uncertainAudioRows[0]['state'] !== 'uncertain' || $uncertainAudioRows[0]['retryable']) {
        throw new RuntimeException('Uncertain call-file durability was reported as confirmed submission or eligible for replay.');
    }
    $fixture->audioUncertain = false;
    $audioReceipts = array_values(array_filter($completed['receipts'], static function ($row) { return $row['channel'] === 'audio'; }));
    if ($completed['state'] !== 'complete' || count($audioReceipts) !== 1 || $audioReceipts[0]['state'] !== 'queued'
        || strpos($audioReceipts[0]['detail'], 'handset answer and playback are unverified') === false
        || stripos($audioReceipts[0]['detail'], 'awaiting') !== false) {
        throw new RuntimeException('Completed announcement falsely promised a later handset playback result');
    }
    file_put_contents($path, json_encode(['id'=>$id, 'state'=>'failed', 'created_at'=>gmdate('c'), 'request'=>$request,
        'receipts'=>[['state'=>'failed', 'channel'=>'webhook', 'target'=>'hook', 'retryable'=>false]]]));
    if ($fixture->getAnnouncementJob($id)['retryable']) { throw new RuntimeException('A route snapshot failure offered an unsafe retry.'); }
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
