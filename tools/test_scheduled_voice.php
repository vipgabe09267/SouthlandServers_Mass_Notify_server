<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$directory = sys_get_temp_dir() . '/sls-scheduled-voice-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory . '/voice.onnx', 'isolated readable fixture');
file_put_contents($directory . '/beep.wav', 'isolated readable fixture');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
$methods = '';
foreach (['saveScheduledAnnouncement', 'normalizeScheduledAnnouncements', 'getScheduledAnnouncementHealthWarnings',
    'processScheduledAnnouncements', 'findLiveScheduledOccurrence', 'scheduleExecutionRecord',
    'validateScheduledAnnouncementRecurrences', 'sanitizeScheduleText', 'normalizeScheduleRecurrenceMode',
    'scheduleRecurrenceIntervalDays', 'buildScheduledOccurrences', 'scheduleRecurrenceMatchesOccurrences',
    'resolveScheduleLocalDateTime', 'parseScheduleUtcTimestamp', 'normalizeAnnouncementAudioMode', 'normalizeToneName', 'validateAudienceWebhookIds', 'announcementTone'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
$fixture = <<<'FIXTURE'
class ScheduledVoiceFixture {
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
    use \FreePBX\modules\SlsScheduledDelivery;
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $settings = [], $store = ['occurrences'=>[]], $submissions = [], $lastStatus = [], $voiceFile;
    private function getActiveSettings() { return $this->settings; }
    private function isSetupComplete($settings) { return true; }
    private function getPbxDateTimeZone() { return new DateTimeZone('UTC'); }
    private function getAllPjsipExtensions() { return [['extension'=>'1000']]; }
    private function getDesktopClients($settings) { return []; }
    private function normalizeDesktopUsername($value) { return (string)$value; }
    private function getAvailableTones() { return ['beep']; }
    private function getAvailablePiperVoices() { return [['path'=>$this->voiceFile, 'available'=>true]]; }
    private function normalizeTtsVolume($value, $fallback) { return (int)$value; }
    private function normalizeHexColor($value, $fallback) { return $value; }
    private function announcementSender() { return 'Fixture operator'; }
    private function persistScheduledAnnouncements($schedules, $expected) { $this->settings['scheduled_announcements'] = $this->normalizeScheduledAnnouncements($schedules); }
    private function ensurePluginDataDir() {}
    private function setOwnership($path) {}
    private function acquireNativeBackupFileLock(...$args) { return null; }
    private function acquireAnnouncementActivityLock(...$args) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function admitScheduledAnnouncementRequest(array $request): array { return ['success'=>true]; }
    private function startAnnouncementWorker($id) { return true; }
    protected function readAnnouncementPhoneOutcomes($correlation) { return ['available'=>false,'targets'=>[]]; }
    private function loadScheduleExecutionStore($failClosed) { return $this->store; }
    private function writeScheduleExecutionStore($store) { $this->store = $store; }
    private function acquireSettingsLock() { return null; }
    private function releaseSettingsLock($lock) {}
    private function loadSettingsFile($path) { return $this->settings; }
    private function normalizeSettings($settings) { return $settings; }
    private function updateStatusData($status) { $this->lastStatus = $status; }
    public function normalized($rows) { return $this->normalizeScheduledAnnouncements($rows); }
    public function warnings() { return $this->getScheduledAnnouncementHealthWarnings($this->settings); }
    public function validate($rows) { return $this->validateScheduledAnnouncementRecurrences($rows, $this->settings['announcement_groups'] ?? []); }
    private function resolveAnnouncementRequest($phones, $message, $mass, $tts, $groups, $options, $settings): array {
        // Capture scheduler's exact handoff. No synthesis, files, PBX calls or
        // network submission exist in this inert adapter.
        $this->submissions[] = compact('phones', 'message', 'groups', 'options');
        $ids = $options['voice_recipient_ids'];
        if ($this->validateVoiceRecipientIds($ids)) { return ['success'=>false, 'delivery_started'=>false, 'message'=>'Invalid saved voice ID.']; }
        $current = $this->outboundVoiceTargets($this->settings);
        foreach ($ids as $id) {
            if (!isset($current[$id])) { return ['success'=>false, 'delivery_started'=>false, 'message'=>'Saved voice recipient is disabled or removed.']; }
        }
        return ['success'=>true, 'request'=>['message'=>$message, 'sender'=>'Fixture', 'phones'=>$phones, 'desktops'=>[], 'webhooks'=>[],
            'voice_recipient_ids'=>$ids, 'email_recipient_ids'=>[], 'audio_mode'=>$options['audio_mode'], 'is_test'=>false]];
    }
FIXTURE;
foreach (['MAX_WEBHOOK_DESTINATIONS', 'MAX_SCHEDULES', 'MAX_SCHEDULE_OCCURRENCES', 'MAX_SCHEDULE_YEARS', 'SCHEDULE_GRACE_SECONDS'] as $name) {
    $fixture .= 'const ' . $name . ' = ' . var_export($reflection->getConstant($name), true) . ';';
}
$fixture .= 'const SCHEDULE_LOCK_FILE = ' . var_export($directory . '/schedule.lock', true) . ';'
    . 'const PLUGIN_DATA_DIR = ' . var_export($directory, true) . ';'
    . 'const SETTINGS_JSON = ' . var_export($directory . '/unused.config', true) . ';'
    . 'const TONES_DIR = ' . var_export($directory, true) . ';';
eval($fixture . $methods . '}');
function voice_schedule_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
try {
    $module = new ScheduledVoiceFixture();
    $module->voiceFile = $directory . '/voice.onnx';
    $recipientId = 'voice_' . str_repeat('a', 24);
    $otherId = 'voice_' . str_repeat('b', 24);
    $module->settings = ['outbound_voice'=>['enabled'=>'1','recipients'=>[
        ['id'=>$recipientId,'name'=>'Fixture voice','number'=>'+15551234567','enabled'=>'1'],
        ['id'=>$otherId,'name'=>'Disabled voice','number'=>'+15551234568','enabled'=>'0'],
    ]], 'announcement_groups'=>[['id'=>'voice-group','voice_recipient_ids'=>[$recipientId],'extensions'=>[],'desktop_clients'=>[]]], 'scheduled_announcements'=>[]];
    $input = ['schedule_name'=>'Voice schedule','schedule_enabled'=>'1','schedule_message'=>'Complete fixture message',
        'schedule_occurrences'=>[gmdate('Y-m-d\TH:i', time()+86400)], 'schedule_audio_mode'=>'tts',
        'schedule_voice'=>$module->voiceFile, 'schedule_voice_recipient_ids'=>[$recipientId]];
    $saved = $module->saveScheduledAnnouncement($input);
    voice_schedule_assert($saved['success'], 'External-only audio schedule was rejected: '.json_encode($saved));
    $schedule = $module->settings['scheduled_announcements'][0];
    voice_schedule_assert($schedule['targets']['voice_recipient_ids'] === [$recipientId], 'Saved voice IDs were lost or retargeted.');
    voice_schedule_assert($module->warnings() === [], 'A valid external-only audio schedule reports missing phone targets.');
    voice_schedule_assert($module->normalized([$schedule])[0] === $schedule, 'Voice schedule normalization is not idempotent.');
    voice_schedule_assert($module->validate([$schedule]) === [], 'Valid voice schedule fails protected validation.');

    foreach ([[$otherId], ['voice_missing'], ['+15551234567'], $recipientId, null] as $invalid) {
        $bad = $input; $bad['schedule_voice_recipient_ids'] = $invalid;
        voice_schedule_assert(!$module->saveScheduledAnnouncement($bad)['success'], 'Invalid, disabled or raw-number target was accepted.');
    }
    $visual = $input; $visual['schedule_audio_mode']='none';
    voice_schedule_assert(!$module->saveScheduledAnnouncement($visual)['success'], 'Direct external voice target accepted visual-only delivery.');
    $module->settings['outbound_voice']['enabled']='0';
    voice_schedule_assert(!$module->saveScheduledAnnouncement($input)['success'], 'Disabled voice service accepted a new voice schedule.');
    $module->settings['outbound_voice']['enabled']='1';
    $group = $input; unset($group['schedule_voice_recipient_ids']); $group['schedule_groups']=['voice-group'];
    voice_schedule_assert($module->saveScheduledAnnouncement($group)['success'], 'Voice-only group was not recognized as an audio target.');
    $group['schedule_audio_mode']='none';
    voice_schedule_assert(!$module->saveScheduledAnnouncement($group)['success'], 'Voice-only group accepted visual-only delivery.');
    $module->settings['outbound_voice']['recipients'][0]['enabled']='0';
    $group['schedule_audio_mode']='tts';
    voice_schedule_assert(!$module->saveScheduledAnnouncement($group)['success'], 'Group with disabled external recipient was silently accepted.');

    $module->settings['scheduled_announcements']=[$schedule];
    $warnings = implode(' ', $module->warnings());
    voice_schedule_assert(strpos($warnings,'removed or disabled') !== false && strpos($warnings,'no resolvable recipient') !== false, 'Removed voice target was not reported in schedule health.');
    voice_schedule_assert($module->normalized([$schedule])[0]['targets']['voice_recipient_ids'] === [$recipientId], 'Normalization discarded a removed recipient instead of retaining an explicit dispatch failure.');
    $badSchedule = $schedule; $badSchedule['targets']['voice_recipient_ids'] = ['voice_' . str_repeat('a',24) . '/'];
    voice_schedule_assert($module->normalized([$badSchedule])[0]['targets']['voice_recipient_ids'] === $badSchedule['targets']['voice_recipient_ids'], 'Malformed ID was sanitized into a valid recipient.');
    voice_schedule_assert($module->validate([$badSchedule]) !== [], 'Malformed scheduled voice IDs passed protected validation.');
    $badSchedule['targets']['voice_recipient_ids']=[$recipientId]; $badSchedule['delivery']['audio_mode']='none';
    voice_schedule_assert($module->validate([$badSchedule]) !== [], 'Raw visual-only external schedule passed validation.');
    $badSchedule['targets']['voice_recipient_ids']=[]; $badSchedule['targets']['groups']=['voice-group'];
    voice_schedule_assert($module->validate([$badSchedule]) !== [], 'Raw visual-only external group schedule passed validation.');
    $rawModule = $reflection->newInstanceWithoutConstructor();
    $validateTypes=$reflection->getMethod('validateConfigValueTypes'); $validateTypes->setAccessible(true);
    voice_schedule_assert($validateTypes->invoke($rawModule, ['scheduled_announcements'=>[['targets'=>['voice_recipient_ids'=>'+15551234567']]]]) !== [], 'Raw scalar schedule recipients passed type validation.');

    // Fire a due occurrence with its stale ID. The scheduler must hand off that
    // exact selection and record an explicit failure, then avoid replaying it.
    $schedule['occurrences'][0]['run_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-5);
    $schedule=$module->normalized([$schedule])[0];
    $module->settings['scheduled_announcements']=[$schedule];
    $result=$module->processScheduledAnnouncements();
    voice_schedule_assert($result['processed']===0 && $result['attention']===1, 'Stale voice schedule did not produce an explicit failed execution.');
    voice_schedule_assert($module->submissions[0]['options']['voice_recipient_ids']===[$recipientId], 'Scheduler lost the original voice ID at dispatch.');
    voice_schedule_assert(array_values($module->store['occurrences'])[0]['state']==='failed', 'Stale voice target was reported as accepted.');
    $module->processScheduledAnnouncements();
    voice_schedule_assert(count($module->submissions)===1, 'Failed voice schedule was replayed.');
    $module->store=['occurrences'=>[]]; $module->submissions=[];
    $module->settings['outbound_voice']['recipients'][0]['enabled']='1';
    $module->settings['outbound_voice']['recipients'][0]['number']='+15551234569';
    $result=$module->processScheduledAnnouncements();
    voice_schedule_assert($result['processed']===1 && $result['attention']===0, 'Current enabled recipient did not reach the announcement adapter.');
    voice_schedule_assert(!array_key_exists('number', $module->submissions[0]['options']), 'Scheduler leaked a separate unvalidated dial-number override.');
    echo "External voice scheduling: save, group audio, validation, retained IDs, health, exact dispatch and no replay passed.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    }
    rmdir($directory);
}
