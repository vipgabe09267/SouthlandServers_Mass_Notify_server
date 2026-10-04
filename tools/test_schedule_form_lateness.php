<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/SchedulePresentation.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$directory = sys_get_temp_dir() . '/sls-scheduled-voice-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory . '/voice.onnx', 'isolated readable fixture');
file_put_contents($directory . '/beep.wav', 'isolated readable fixture');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
$methods = '';
foreach (['saveScheduledAnnouncement', 'previewScheduleCalendar', 'getScheduledAnnouncements', 'normalizeScheduledAnnouncements', 'findLiveScheduledOccurrence', 'scheduleExecutionRecord',
    'validateScheduledAnnouncementRecurrences', 'sanitizeScheduleText', 'normalizeScheduleRecurrenceMode',
    'scheduleRecurrenceIntervalDays', 'buildScheduledOccurrences', 'scheduleRecurrenceMatchesOccurrences',
    'resolveScheduleLocalDateTime', 'parseScheduleUtcTimestamp', 'normalizeAnnouncementAudioMode', 'normalizeToneName', 'announcementTone', 'validateAudienceWebhookIds'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
$fixture = <<<'FIXTURE'
class ScheduleFormLatenessFixture {
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsAnnouncementEmail; use \FreePBX\modules\SlsAnnouncementSms;
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
    public function sendSipNotifyAnnouncement($phones, $message, $mass, $tts, $groups, $options) {
        // Capture scheduler's exact handoff. No synthesis, files, PBX calls or
        // network submission exist in this inert adapter.
        $this->submissions[] = compact('phones', 'message', 'groups', 'options');
        $ids = $options['voice_recipient_ids'];
        if ($this->validateVoiceRecipientIds($ids)) { return ['success'=>false, 'delivery_started'=>false, 'message'=>'Invalid saved voice ID.']; }
        $current = $this->outboundVoiceTargets($this->settings);
        foreach ($ids as $id) {
            if (!isset($current[$id])) { return ['success'=>false, 'delivery_started'=>false, 'message'=>'Saved voice recipient is disabled or removed.']; }
        }
        return ['success'=>true, 'message'=>'Mock submission accepted.'];
    }
FIXTURE;
foreach (['MAX_WEBHOOK_DESTINATIONS', 'MAX_SCHEDULES', 'MAX_SCHEDULE_OCCURRENCES', 'MAX_SCHEDULE_YEARS', 'SCHEDULE_GRACE_SECONDS'] as $name) {
    $fixture .= 'const ' . $name . ' = ' . var_export($reflection->getConstant($name), true) . ';';
}
$fixture .= 'const SCHEDULE_LOCK_FILE = ' . var_export($directory . '/schedule.lock', true) . ';'
    . 'const SETTINGS_JSON = ' . var_export($directory . '/unused.config', true) . ';'
    . 'const TONES_DIR = ' . var_export($directory, true) . ';';
eval($fixture . $methods . '}');

$module = new ScheduleFormLatenessFixture(); $module->voiceFile = $directory . '/voice.onnx';
$module->settings = ['scheduled_announcements'=>[], 'announcement_groups'=>[]];
$input = ['schedule_name'=>'Fixture','schedule_message'=>'Fixture message','schedule_enabled'=>'1',
    'schedule_occurrences'=>[gmdate('Y-m-d\TH:i', time()+86400)],'schedule_extensions'=>['1000'],'schedule_audio_mode'=>'none'];
$checks = 0;
function form_delay_check($condition, $message) { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
try {
    $result=$module->saveScheduledAnnouncement($input);
    form_delay_check($result['success'], 'Default save failed: '.json_encode($result));
    $row=$module->settings['scheduled_announcements'][0];
    form_delay_check($row['max_lateness_minutes']===15, 'New save lacks default');
    $input['schedule_id']=$row['id']; $input['schedule_max_lateness_minutes']='3';
    form_delay_check($module->saveScheduledAnnouncement($input)['success'], 'Explicit delay rejected');
    form_delay_check($module->settings['scheduled_announcements'][0]['max_lateness_minutes']===3, 'Explicit delay not saved');
    unset($input['schedule_max_lateness_minutes']);
    form_delay_check($module->saveScheduledAnnouncement($input)['success'], 'Legacy edit rejected');
    form_delay_check($module->settings['scheduled_announcements'][0]['max_lateness_minutes']===3, 'Legacy edit overwrote delay');
    foreach ([null,[],true,'0','16','3.0','03',' 3'] as $bad) {
        $before=$module->settings;
        $result=$module->saveScheduledAnnouncement($input+['schedule_max_lateness_minutes'=>$bad]);
        form_delay_check(!$result['success'], 'Invalid delay saved');
        form_delay_check($module->settings===$before, 'Rejected delay changed settings');
        form_delay_check(strpos(implode(' ', $result['errors']),'Maximum start delay')!==false, 'Invalid delay error generic');
    }
    $calendarInput=$input;
    $start=gmdate('Y-m-d',time()+86400);
    $calendar=['until'=>gmdate('Y-m-d',time()+8*86400),'weekdays'=>[1,2,3,4,5,6,7],
        'exclusions'=>[['start'=>gmdate('Y-m-d',time()+2*86400),'end'=>gmdate('Y-m-d',time()+2*86400),'reason'=>'Closed']],
        'overrides'=>[['date'=>gmdate('Y-m-d',time()+3*86400),'time'=>'10:00','reason'=>'Late start']]];
    $calendarInput['schedule_recurrence_mode']='calendar';$calendarInput['schedule_occurrences']=[$start.'T08:00'];
    $calendarInput['schedule_calendar_complete']='1';$calendarInput['schedule_calendar_json']=json_encode($calendar);
    $before=$module->settings;
    $preview=$module->previewScheduleCalendar($calendarInput);
    form_delay_check($preview['success']&&count($preview['occurrences'])===7&&$module->settings===$before,'Preview mutated settings or planned wrong dates');
    $saved=$module->saveScheduledAnnouncement($calendarInput);
    form_delay_check($saved['success'],'Calendar form save failed: '.json_encode($saved));
    $row=$module->settings['scheduled_announcements'][0];
    form_delay_check($row['recurrence']['calendar']===$calendar&&$row['occurrences']===$preview['occurrences'],'Calendar save disagreed with preview');
    form_delay_check(!$module->validate([$row]),'Saved calendar failed import validation');
    foreach ([['schedule_calendar_complete'=>'0'],['schedule_calendar_json'=>'{}'],['schedule_message'=>str_repeat('x',501)],['schedule_title'=>str_repeat('x',81)]] as $bad) {
        $before=$module->settings;$saved=$module->saveScheduledAnnouncement(array_replace($calendarInput,$bad));
        form_delay_check(!$saved['success']&&$module->settings===$before,'Rejected calendar/text changed configuration');
    }
    // A later PBX timezone change must not silently reinterpret existing rules.
    $builder=$reflection->getMethod('buildScheduledOccurrences');$actual=$reflection->newInstanceWithoutConstructor();
    $chicago=$builder->invoke($actual,$row['id'],[$start.'T08:00'],'calendar',new DateTimeZone('America/Chicago'),[],0,PHP_INT_MAX,$calendar);
    $row['timezone']='America/Chicago';$row['occurrences']=$chicago['occurrences'];$module->settings['scheduled_announcements']=[$row];
    $preview=$module->previewScheduleCalendar($calendarInput);$saved=$module->saveScheduledAnnouncement($calendarInput);
    form_delay_check($preview['timezone']==='America/Chicago'&&$preview['occurrences']===$chicago['occurrences'],'Preview reinterpreted saved timezone');
    form_delay_check($saved['success']&&$module->settings['scheduled_announcements'][0]['timezone']==='America/Chicago'
        &&$module->settings['scheduled_announcements'][0]['occurrences']===$chicago['occurrences'],'Editing calendar changed saved timezone');
    echo "Actual schedule form lateness/calendar: $checks assertions passed.\n";
} finally {
    foreach (glob($directory.'/*') as $path) { unlink($path); } rmdir($directory);
}
