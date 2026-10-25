<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/DeliveryRehearsal.php';

$directory = sys_get_temp_dir() . '/sls-announcement-snapshot-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$source = file(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$methods = '';
foreach (['sendSipNotifyAnnouncement', 'resolveAnnouncementRequest', 'dispatchAnnouncementWebhooks', 'collectBoundedProcessOutput', 'sendAnnouncementTtsAudio', 'normalizeAnnouncementAudioMode', 'normalizeControlGroupSelectors', 'webhookPayloadFormat', 'webhookDestinationFingerprint', 'aggregateSavedAudienceTargets', 'validateAggregateSavedAudienceTargets', 'validateAudienceWebhookIds', 'normalizeExtensionSelector', 'announcementTone'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
// Exercise the real orchestration/preflight methods with only disposable paths
// and inert delivery adapters. No FreePBX bootstrap or network is available here.
$methods = str_replace("require_once __DIR__.'/DeliveryReview.php';", 'require_once ' . var_export(dirname(__DIR__) . '/slsmassnotifyserver/DeliveryReview.php', true) . ';', $methods);
$fixtureSource = <<<'PHP'
class AnnouncementSnapshotFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery { deliverResolvedAnnouncement as private actualPreviewDelivery; }
    use \FreePBX\modules\SlsEnterpriseOperations;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsApiPermissionGuards;
    use \FreePBX\modules\SlsWeatherChannels;
    const MAX_WEBHOOK_DESTINATIONS = 10;
    const MAX_SAVED_CHANNEL_RECIPIENTS = 50;
    const MAX_EXTERNAL_VOICE_RECIPIENTS = 1000;
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
    private function getDeviceOverrideInventory() { return ['devices'=>$this->phones]; }
    private function getDesktopClients($settings) { return $settings['desktop_clients'] ?? []; }
    private function normalizeDesktopUsername($value) { return strtolower($value); }
    private function normalizeDesktopClientId($value) { return strtolower($value); }
    private function normalizeWebhookDestinations($rows, $kind) { return $rows; }
    private function getAnnouncementGroups() { return $this->settings['announcement_groups'] ?? [['id'=>'offline-group', 'extensions'=>['1002'], 'desktop_clients'=>[]]]; }
    private function getAvailableTones() { return []; }
    private function normalizeToneName($name) { return $name; }
    private function normalizeHexColor($color, $fallback) { return $color; }
    private function normalizeAnnouncementTimeoutMode($mode) { return $mode; }
    private function normalizeAnnouncementTimeoutSeconds($seconds) { return $seconds; }
    private function sanitizeScheduleText($text, $limit, $single) { return substr($text, 0, $limit); }
    private function deliverResolvedAnnouncement($request) { return !empty($request['preview']) ? $this->actualPreviewDelivery($request) : ['success'=>true, 'request'=>$request]; }
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
    protected function weatherChannelAuthorization(array $context): array {
        return ['status'=>'eligible','voice_recipient_ids'=>['voice_'.str_repeat('a',24)],'sms_recipient_ids'=>[]];
    }
    private function startAnnouncementWorker($id) { return true; }
    public function audio($context) { return $this->sendAnnouncementTtsAudio(['1000'], 'Original message', $context); }
    public function apiGroups($selectors) { return $this->normalizeControlGroupSelectors($selectors); }
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
    $reviewFixture = new AnnouncementSnapshotFixture();
    $reviewFixture->settings = ['announcement_piper_voice'=>'original-voice', 'desktop_auth_key'=>base64_encode(str_repeat('k',32))];
    $review = $reviewFixture->sendSipNotifyAnnouncement(['1000'], 'Private no-delivery review', false, true, [], ['preview'=>true, '_review_requested'=>true]);
    $rehearsal=\SLS\MassNotify\DeliveryRehearsal::simulate($review,['default'=>'uncertain']);
    if (empty($review['success']) || empty($review['preview']) || $review['delivery_started'] !== false
        || $review['transports_attempted'] !== [] || $review['review']['counts']['phones'] !== 1
        || !$review['review']['token'] || $reviewFixture->preparedVoices !== []
        || $rehearsal['simulated_receipts'][0]['outcome']!=='uncertain' || $rehearsal['transports_attempted']!==[]
        || glob($directory.'/announcement-jobs/job_*.json') !== [] || file_exists($directory.'/capture.jsonl')) {
        throw new RuntimeException('Actual delivery review started synthesis, queued work or a transport.');
    }
    foreach ([['array message'], 1234, true, "Invalid \xFF text"] as $invalidMessage) {
        set_error_handler(static function () { return true; });
        try {
            $invalid = $module->sendSipNotifyAnnouncement(['1000'], $invalidMessage, true, false);
        } finally { restore_error_handler(); }
        if (($invalid['error_code'] ?? '') !== 'invalid_message' || ($invalid['delivery_started'] ?? true)) {
            throw new RuntimeException('Malformed announcement text reached delivery orchestration.');
        }
    }
    $boundary=$module->sendSipNotifyAnnouncement(['1000'],str_repeat('é',500),true,false,[],['title'=>str_repeat('界',80)]);
    if(empty($boundary['success'])||mb_strlen($boundary['request']['message']??'')!==500||mb_strlen($boundary['request']['title']??'')!==80){throw new RuntimeException('Exact multilingual title/body limit was rejected or shortened.');}
    foreach([['message'=>str_repeat('é',501),'title'=>'Boundary','error'=>'message_too_long'],['message'=>'Boundary','title'=>str_repeat('界',81),'error'=>'title_too_long']] as $case){
        $refused=$module->sendSipNotifyAnnouncement(['1000'],$case['message'],true,false,[],['title'=>$case['title']]);
        if(($refused['error_code']??'')!==$case['error']||($refused['delivery_started']??true)){throw new RuntimeException('Oversized announcement reached delivery.');}
    }
    $unicode = $module->sendSipNotifyAnnouncement(['1000'], "Aviso: evacuación — 日本語", true, false);
    if (($unicode['request']['message'] ?? '') !== "Aviso: evacuación — 日本語") {
        throw new RuntimeException('Valid multilingual announcement text was changed or rejected.');
    }
    $originalGroup='grp_'.str_repeat('a',12);$otherGroup='grp_'.str_repeat('b',12);
    $module->settings['announcement_groups']=[['id'=>$originalGroup,'name'=>'Original audience'],['id'=>$otherGroup,'name'=>$originalGroup]];
    if ($module->apiGroups([$originalGroup])!==[$originalGroup]) { throw new RuntimeException('A group name shadowed another saved audience ID.'); }
    if ($module->apiGroups(['Original audience'])!==[$originalGroup]) { throw new RuntimeException('A valid legacy group-name alias was lost.'); }
    $mixed=$module->apiGroups([$originalGroup,'removed-group']);
    if (!in_array('removed-group',$mixed,true)) { throw new RuntimeException('An unavailable API audience was silently omitted.'); }
    $rejectedGroups=$module->sendSipNotifyAnnouncement([], 'Private selection fixture',false,false,$mixed);
    if (($rejectedGroups['error_code']??'')!=='unavailable_group'||($rejectedGroups['delivery_started']??true)) {
        throw new RuntimeException('A mixed available/unavailable API audience reached delivery.');
    }
    $module->settings['announcement_groups'][0]['name']='Shared name';$module->settings['announcement_groups'][1]['name']='Shared name';
    if ($module->apiGroups(['Shared name'])!==['Shared name']) { throw new RuntimeException('An ambiguous group name silently selected one audience.'); }
    unset($module->settings['announcement_groups']);
    $module->settings['nws_piper_voice']='original-voice';$module->settings['nws_tts_volume']=25;
    $module->settings['outbound_voice']=['enabled'=>'1','recipients'=>[
        ['id'=>'voice_'.str_repeat('a',24),'name'=>'Private external recipient','number'=>'+15551230000','enabled'=>'1']]];
    $weatherText=str_repeat('Review the complete weather instructions. ',20);
    $weatherContext=['source_validity'=>['version'=>1,'provider'=>'nws','group_id'=>'fixture'],
        'event'=>'Fixture warning','severity'=>'Severe','deadline_at'=>time()+300,
        'channels'=>['voice_recipient_ids'=>['voice_'.str_repeat('a',24)],'sms_recipient_ids'=>[],
            'fingerprints'=>['voice:voice_'.str_repeat('a',24)=>str_repeat('d',64)]]];
    $weatherGate=(new SlsAnnouncementJobStore($directory))->admissionLock();
    if (!is_resource($weatherGate)) { throw new RuntimeException('Weather shared-admission fixture could not acquire its gate.'); }
    try {
        $gatedWeather=$module->queueWeatherChannels(['key'=>str_repeat('e',64),'context'=>$weatherContext,
            'title'=>'Private gated weather fixture','body'=>$weatherText]);
        if (($gatedWeather['status']??'')!=='deferred') { throw new RuntimeException('Weather queue bypassed the shared announcement gate.'); }
    } finally { SlsAnnouncementJobStore::unlock($weatherGate); }
    $invalidWeatherContext=$weatherContext;$invalidWeatherContext['channels']['voice_recipient_ids'][]='voice_bad';
    $invalidWeatherRejected=false;
    try { $module->queueWeatherChannels(['key'=>str_repeat('f',64),'context'=>$invalidWeatherContext,
        'title'=>'Private malformed weather fixture','body'=>$weatherText]); }
    catch (InvalidArgumentException $error) { $invalidWeatherRejected=true; }
    if (!$invalidWeatherRejected) { throw new RuntimeException('Malformed frozen Weather recipients reached authorization or queue storage.'); }
    $weather=$module->queueWeatherChannels(['key'=>str_repeat('b',64),'context'=>$weatherContext,
        'title'=>'Private weather fixture','body'=>$weatherText]);
    if (empty($weather['success'])) { throw new RuntimeException('Valid complete weather speech was rejected: '.json_encode($weather)); }
    $weatherJob=(new SlsAnnouncementJobStore($directory.'/announcement-jobs'))->read($weather['job_id']);
    if (($weatherJob['request']['message']??'')!==trim($weatherText)) { throw new RuntimeException('Weather instructions were truncated before queue storage.'); }
    $weatherJob['state']='complete';(new SlsAnnouncementJobStore($directory.'/announcement-jobs'))->write($weatherJob);
    $weatherAgain=$module->queueWeatherChannels(['key'=>str_repeat('b',64),'context'=>$weatherContext,
        'title'=>'Private weather fixture','body'=>$weatherText]);
    if (($weatherAgain['success']??false)!==true||($weatherAgain['status']??'')!=='accepted'||($weatherAgain['job_id']??'')!==$weather['job_id']) {
        throw new RuntimeException('A completed Weather handoff was not acknowledged with the producer contract.');
    }
    $weatherConflict=false;
    try {$module->queueWeatherChannels(['key'=>str_repeat('b',64),'context'=>$weatherContext,
        'title'=>'Private weather fixture','body'=>$weatherText.' changed']);}
    catch (DomainException $error) {$weatherConflict=str_contains($error->getMessage(),'different content');}
    if (!$weatherConflict) { throw new RuntimeException('A Weather delivery key was reused with different immutable content.'); }
    $oversizedRejected=false;
    try { $module->queueWeatherChannels(['key'=>str_repeat('c',64),'context'=>$weatherContext,
        'title'=>'Private oversized weather fixture','body'=>str_repeat('a',3001)]); }
    catch (InvalidArgumentException $error) { $oversizedRejected=true; }
    if (!$oversizedRejected) { throw new RuntimeException('Weather text exceeded its existing 3000-byte boundary.'); }
    $longOrdinary=$module->sendSipNotifyAnnouncement(['1000'],$weatherText,true,false,[],['message_limit'=>3000]);
    if (($longOrdinary['error_code']??'')!=='message_too_long') { throw new RuntimeException('The trusted Weather path expanded ordinary announcement limits.'); }
    unset($module->settings['outbound_voice']);
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
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname()); }
    rmdir($directory);
}
