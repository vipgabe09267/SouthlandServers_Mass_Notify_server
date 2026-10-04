<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/SchedulePresentation.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\SchedulePresentation as Presentation;
$checks = 0;
function late_check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$invoke = static function ($method, ...$args) use ($reflection, $module) { return $reflection->getMethod($method)->invoke($module, ...$args); };
$base = ['id'=>'sched_fixture','name'=>'Fixture','enabled'=>'1','timezone'=>'UTC','message'=>'Fixture message',
    'occurrences'=>[['id'=>'occ','run_at_utc'=>'2029-01-15T15:30:00Z']], 'targets'=>['extensions'=>['1000']], 'delivery'=>['audio_mode'=>'none']];
late_check($invoke('normalizeScheduledAnnouncements', [$base])[0]['max_lateness_minutes'] === 15, 'Legacy default changed');
late_check(Presentation::formLateness([], []) === 15, 'Legacy new form default changed');
late_check(Presentation::formLateness([], ['max_lateness_minutes'=>4]) === 4, 'Old edit erased existing delay');
foreach ([1, 4, 15] as $value) {
    $schedule = $base + ['max_lateness_minutes'=>$value];
    late_check($invoke('normalizeScheduledAnnouncements', [$schedule])[0]['max_lateness_minutes'] === $value, 'Valid delay lost');
    late_check($invoke('validateScheduledAnnouncementRecurrences', [$schedule]) === [], 'Valid delay rejected');
    late_check($invoke('validateConfigValueTypes', ['scheduled_announcements'=>[$schedule]]) === [], 'Import rejected integer delay');
    late_check(Presentation::formLateness(['schedule_max_lateness_minutes'=>(string)$value], []) === $value, 'HTML decimal not parsed');
}
foreach ([null, true, false, 0, 16, 1.0, '1', [], new stdClass()] as $value) {
    $schedule = $base + ['max_lateness_minutes'=>$value];
    late_check($invoke('normalizeScheduledAnnouncements', [$schedule])[0]['max_lateness_minutes'] === $value, 'Malformed delay silently clamped');
    late_check($invoke('validateScheduledAnnouncementRecurrences', [$schedule]) !== [], 'Raw delay passed validator');
    late_check($invoke('validateConfigValueTypes', ['scheduled_announcements'=>[$schedule]]) !== [], 'Raw delay passed import');
}
foreach (['01', '1.0', '1e1', ' 1', '+1', '', null, true, [], 16] as $value) {
    try { Presentation::formLateness(['schedule_max_lateness_minutes'=>$value], []); throw new LogicException('Malformed HTML delay accepted'); }
    catch (DomainException $expected) { late_check(true, 'Malformed form rejected'); }
}
$other = array_replace($base, ['id'=>'sched_other','name'=>'Other','targets'=>['groups'=>['staff']]]);
$now = strtotime('2029-01-15T15:00:00Z');
$snapshot = ['scheduled_announcements'=>[$base,$other], 'announcement_groups'=>[['id'=>'staff','extensions'=>['1000']]], 'announcement_cooldown_seconds'=>60];
$report = Presentation::conflicts($snapshot,$now);
late_check(count($report['rows']) === 1 && $report['rows'][0]['shared_recipients'] === 1, 'Group overlap missed');
$snapshot['announcement_groups'][0]['extensions']=['1001'];
late_check(Presentation::conflicts($snapshot,$now)['rows'] === [], 'Disjoint recipients falsely conflicted');
$snapshot['scheduled_announcements'][0]['targets']['phones_all']='1';
late_check(Presentation::conflicts($snapshot,$now)['rows'][0]['dynamic_audience'], 'All-phone overlap omitted');
$snapshot['scheduled_announcements'][1]['enabled']='0';
late_check(Presentation::conflicts($snapshot,$now)['rows'] === [], 'Disabled future schedule included');
$snapshot['scheduled_announcements']=[];
for($i=0;$i<100;$i++) {
    $schedule=array_replace($base,['id'=>'sched_'.$i,'occurrences'=>array_fill(0,366,$base['occurrences'][0])]);
    $snapshot['scheduled_announcements'][]=$schedule;
}
$started=microtime(true);$report=Presentation::conflicts($snapshot,$now);
late_check($report['incomplete'] && count($report['rows'])<=100, 'Conflict scan unbounded or omitted incomplete signal');
late_check(microtime(true)-$started<3, 'Bounded conflict analysis exceeded three seconds');
echo "Schedule lateness/configuration: $checks assertions passed.\n";
