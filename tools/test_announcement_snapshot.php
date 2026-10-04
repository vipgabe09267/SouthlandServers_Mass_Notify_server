<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

$directory = sys_get_temp_dir() . '/sls-announcement-snapshot-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$source = file(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$methods = '';
foreach (['sendSipNotifyAnnouncement', 'resolveAnnouncementRequest', 'dispatchAnnouncementWebhooks', 'sendAnnouncementTtsAudio', 'normalizeAnnouncementAudioMode', 'webhookPayloadFormat', 'webhookDestinationFingerprint', 'validateAudienceWebhookIds', 'announcementTone'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
// Exercise the real orchestration/preflight methods with only disposable paths
// and inert delivery adapters. No FreePBX bootstrap or network is available here.
$fixtureSource = <<<'PHP'
class AnnouncementSnapshotFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    use \FreePBX\modules\SlsEnterpriseOperations;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsApiPermissionGuards;
    const MAX_WEBHOOK_DESTINATIONS = 10;
    const PIPER_VOICE = 'built-in-voice';
    const PIPER_BIN = '/usr/bin/true';
    const VISUAL_PUSH_SCRIPT = '/usr/bin/true';
    public $settings = [];
    public $phones = [['extension'=>'1000']];
    public $voices = ['original-voice', 'new-default'];
    public $preparedVoices = [];
    private $announcementAdmissionLock;
    private function acquireAnnouncementActivityLock($exclusive=false,$timeout=30) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function getActiveSettings() { return $this->settings; }
    protected function currentIncidentAnnouncementContext() { return null; }
    private function isSetupComplete($settings) { return true; }
    private function ensurePluginDataDir() {}
    private function setOwnership($path) {}
    private function getAnnouncementCooldownState() { return ['remaining'=>0]; }
    private function getSipNotifyTargets() { return $this->phones; }
    private function getDesktopClients($settings) { return $settings['desktop_clients'] ?? []; }
    private function normalizeDesktopUsername($value) { return strtolower($value); }
    private function normalizeDesktopClientId($value) { return strtolower($value); }
    private function normalizeWebhookDestinations($rows, $kind) { return $rows; }
    private function getAnnouncementGroups() { return [['id'=>'offline-group', 'extensions'=>['1002'], 'desktop_clients'=>[]]]; }
    private function getAvailableTones() { return []; }
    private function normalizeToneName($name) { return $name; }
    private function normalizeHexColor($color, $fallback) { return $color; }
    private function normalizeAnnouncementTimeoutMode($mode) { return $mode; }
    private function normalizeAnnouncementTimeoutSeconds($seconds) { return $seconds; }
    private function sanitizeScheduleText($text, $limit, $single) { return substr($text, 0, $limit); }
    private function deliverResolvedAnnouncement($request) { return ['success'=>true, 'request'=>$request]; }
    private function normalizeTtsVolume($value, $fallback) { return $value; }
    private function getAvailablePiperVoices() { return array_map(static function ($path) { return ['path'=>$path]; }, $this->voices); }
    private function isValidPiperVoiceFile($path) { return in_array($path, $this->voices, true); }
    private function pruneTtsCache() {}
    private function generateAnnouncementTtsFile($message, $settings) { $this->preparedVoices[] = $settings['announcement_piper_voice']; return 'fixture'; }
    private function buildAnnouncementAudioSequence($base, $settings) { return 'fixture'; }
    private function getAnnouncementSequenceDuration($sequence) { return 2; }
    private function queueAnnouncementAudioCalls($phones, $sequence, $duration, $priority) { return count($phones); }
    private function updateStatusData($data) {}
    private function appendAnnouncementAudioLog(...$args) {}
    public function audio($context) { return $this->sendAnnouncementTtsAudio(['1000'], 'Original message', $context); }
    public function webhook($request) {
        return $this->dispatchAnnouncementWebhooks($request['webhooks'], $request['message'], $request['title'],
            $request['background_color'], [['Sent by', $request['sender']]], $request['delivery_id'],
            $request['webhook_fingerprints'] ?? [], $request['delivery_timestamp'], null, $request['incident_context'] ?? null);
    }
PHP;
eval($fixtureSource . ' const ANNOUNCEMENT_LOCK_FILE = ' . var_export($directory . '/announce.lock', true)
    . '; const PLUGIN_DATA_DIR = ' . var_export($directory, true)
    . '; const TTS_DIR = ' . var_export($directory, true)
    . '; const RUNTIME_DIR = ' . var_export($directory, true)
    . '; const SETTINGS_JSON = ' . var_export($directory . '/unused.config', true) . ';' . $methods . '}');
$dispatcher = $directory . '/sls_notification_destinations.py';
file_put_contents($dispatcher, <<<'PY'
#!/usr/bin/python3
import json
import os
from pathlib import Path
captured = {key: value for key, value in os.environ.items() if key.startswith('SLS_DESTINATION_')}
with Path(__file__).with_name('capture.jsonl').open('a') as out:
    out.write(json.dumps(captured) + '\n')
print(json.dumps({'results': [{'id': value, 'status': 'accepted'} for value in os.environ['SLS_DESTINATION_IDS'].split(',')]}))
PY
);
chmod($dispatcher, 0700);
$module = new AnnouncementSnapshotFixture();
$module->settings = ['announcement_piper_voice'=>'original-voice', 'announcement_webhooks'=>[
    ['id'=>'hook', 'enabled'=>'1', 'name'=>'Original receiver', 'url'=>'https://hooks.example.com/original-secret'],
]];
try {
    $GLOBALS['sls_control_principal']=['username'=>'fixture_operator','id'=>'api_'.str_repeat('a',24)];
    $operator=$module->sendSipNotifyAnnouncement(['1000'],'Operator fixture',true,false,[],
        ['audio_mode'=>'none','trigger_source'=>'SLS Operator Portal','sender'=>'spoofed sender']);
    if(($operator['request']['sender']??'')!=='Operator: fixture_operator'){throw new RuntimeException('Operator sender attribution was not frozen from the verified principal.');}
    unset($GLOBALS['sls_control_principal']);
    $resolved = $module->sendSipNotifyAnnouncement(['1000'], 'Original message', true, true, [],
        ['webhook_ids'=>['hook'], 'audio_mode'=>'tts', 'sender'=>'Original sender']);
    if (!$resolved['success']) { throw new RuntimeException('Fixture send was rejected: '.json_encode($resolved)); }
    $request = $resolved['request'];
    if (($request['is_test'] ?? null) !== false) { throw new RuntimeException('Ordinary announcement test metadata is not explicitly false.'); }
    $testRequest = $module->sendSipNotifyAnnouncement(['1000'], 'Neutral fixture message', true, true, [],
        ['webhook_ids'=>['hook'], 'audio_mode'=>'tts', 'sender'=>'Original sender', '_is_test'=>true]);
    if (($testRequest['request']['is_test'] ?? null) !== true) { throw new RuntimeException('Trusted test metadata was not preserved in the frozen request.'); }
    $request['delivery_id'] = 'announcement-original';
    $request['delivery_timestamp'] = '2026-09-20T12:00:00+00:00';
    if ($request['voice'] !== 'original-voice'
        || $request['webhook_fingerprints'] !== ['hook'=>hash('sha256', 'https://hooks.example.com/original-secret')]) {
        throw new RuntimeException('Send did not freeze the default voice and original webhook route.');
    }
    $module->settings['announcement_piper_voice'] = 'new-default';
    $audio = $module->audio(['audio_mode'=>'tts', 'piper_voice'=>$request['voice']]);
    if (!$audio['success'] || $module->preparedVoices !== ['original-voice']) { throw new RuntimeException('Queued audio changed to the new default voice.'); }
    $module->voices = ['new-default'];
    $missing = $module->audio(['audio_mode'=>'tts', 'piper_voice'=>$request['voice']]);
    $legacyVoice = $module->audio(['audio_mode'=>'tts', 'piper_voice'=>'']);
    if (($missing['error'] ?? '') !== 'selected_voice_unavailable'
        || ($legacyVoice['error'] ?? '') !== 'legacy_voice_snapshot_missing'
        || ($legacyVoice['retryable'] ?? true) || count($module->preparedVoices) !== 1) {
        throw new RuntimeException('Missing original voice silently selected a replacement.');
    }

    $module->phones = [];
    $module->settings['announcement_groups'] = [['id'=>'offline-group', 'extensions'=>['1002'], 'desktop_clients'=>[]]];
    foreach ([[['1002'], []], [[], ['offline-group']]] as [$phones, $groups]) {
        $offline = $module->sendSipNotifyAnnouncement($phones, 'Original message', true, false, $groups);
        if (($offline['error_code'] ?? '') !== 'selected_phones_unavailable' || $offline['delivery_started']
            || $offline['unavailable_phones'] !== ['1002'] || strpos($offline['message'], '1 selected phone') === false) {
            throw new RuntimeException('An offline-only audience produced a generic empty-selection error: '.json_encode($offline));
        }
    }

    $capture = $directory . '/capture.jsonl';
    $module->settings['announcement_webhooks'][0]['url'] = 'https://hooks.example.com/new-secret';
    $changed = $module->webhook($request);
    if (($changed['failed'][0]['error'] ?? '') !== 'destination_changed' || file_exists($capture)) {
        throw new RuntimeException('An edited webhook URL reached the dispatcher.');
    }
    $legacyRequest = $request;
    unset($legacyRequest['webhook_fingerprints']);
    $legacy = $module->webhook($legacyRequest);
    if (($legacy['failed'][0]['error'] ?? '') !== 'legacy_route_snapshot_missing'
        || $legacy['failed'][0]['retryable'] || file_exists($capture)) {
        throw new RuntimeException('A legacy missing route snapshot guessed a current destination.');
    }
    $module->settings['announcement_webhooks'][0]['url'] = 'https://hooks.example.com/original-secret';
    $module->settings['announcement_webhooks'][0]['payload_format'] = 'slack';
    $changedAdapter = $module->webhook($request);
    if (($changedAdapter['failed'][0]['error'] ?? '') !== 'destination_changed' || file_exists($capture)) {
        throw new RuntimeException('An edited webhook adapter reached the dispatcher for an old job.');
    }
    unset($module->settings['announcement_webhooks'][0]['payload_format']);
    foreach ([1, 2] as $attempt) {
        if (count($module->webhook($request)['accepted']) !== 1) { throw new RuntimeException('Unchanged frozen route was not accepted.'); }
    }
    $calls = array_map(static function ($line) { return json_decode($line, true); }, file($capture, FILE_IGNORE_NEW_LINES));
    if (count($calls) !== 2 || $calls[0] !== $calls[1]
        || $calls[0]['SLS_DESTINATION_EVENT_ID'] !== $request['delivery_id']
        || $calls[0]['SLS_DESTINATION_TIME'] !== $request['delivery_timestamp']
        || json_decode($calls[0]['SLS_DESTINATION_FINGERPRINTS_JSON'], true) !== $request['webhook_fingerprints']) {
        throw new RuntimeException('Dispatcher lost stable payload identity, timestamp or route binding.');
    }
    // PHP casts numeric destination IDs to integer array keys. Their hashes
    // must still cross JSON as an object, including the valid ID "0".
    $module->settings['announcement_webhooks'][0]['id'] = '0';
    $numeric = $module->sendSipNotifyAnnouncement([], 'Original message', true, false, [], ['webhook_ids'=>['0']])['request'];
    $numeric['delivery_id'] = $request['delivery_id'];
    $numeric['delivery_timestamp'] = $request['delivery_timestamp'];
    if ($numeric['webhooks'] !== ['0'] || count($module->webhook($numeric)['accepted']) !== 1) {
        throw new RuntimeException('A valid numeric webhook ID lost its route binding.');
    }
    $numericCapture = json_decode(array_slice(file($capture, FILE_IGNORE_NEW_LINES), -1)[0], true);
    if (!is_object(json_decode($numericCapture['SLS_DESTINATION_FINGERPRINTS_JSON']))) {
        throw new RuntimeException('Numeric destination fingerprint was encoded as a JSON list.');
    }
    $numeric['incident_context'] = ['schema'=>1, 'incident_id'=>'inc_' . str_repeat('a', 32), 'sequence'=>1,
        'kind'=>'initial', 'severity'=>'critical', 'is_test'=>false];
    $module->webhook($numeric);
    $incidentCapture = json_decode(array_slice(file($capture, FILE_IGNORE_NEW_LINES), -1)[0], true);
    if ($incidentCapture['SLS_DESTINATION_INCIDENT_ID'] !== $numeric['incident_context']['incident_id']) {
        throw new RuntimeException('Typed incident identity was lost at the PHP/Python handoff.');
    }
    if (json_decode($incidentCapture['SLS_DESTINATION_INCIDENT_JSON'], true) !== $numeric['incident_context']) {
        throw new RuntimeException('Incident severity, sequence or drill flag was lost at the PHP/Python handoff.');
    }
    unset($numeric['incident_context']);
    putenv('SLS_DESTINATION_INCIDENT_ID=inc_' . str_repeat('b', 32));
    putenv('SLS_DESTINATION_INCIDENT_JSON={"schema":1}');
    $module->webhook($numeric); putenv('SLS_DESTINATION_INCIDENT_ID'); putenv('SLS_DESTINATION_INCIDENT_JSON');
    $ordinaryCapture = json_decode(array_slice(file($capture, FILE_IGNORE_NEW_LINES), -1)[0], true);
    if (($ordinaryCapture['SLS_DESTINATION_INCIDENT_ID'] ?? '') !== '') {
        throw new RuntimeException('An inherited environment forged ordinary announcement incident context.');
    }
    if (($ordinaryCapture['SLS_DESTINATION_INCIDENT_JSON'] ?? '') !== '') { throw new RuntimeException('An inherited environment forged typed incident metadata.'); }
    echo "Frozen announcement voice/routes, no-fallback failures, stable webhook handoff and offline-only audience checks passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}
