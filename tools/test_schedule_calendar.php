<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\ScheduleCalendar as Calendar;
$checks = 0;
function calendar_check($condition, string $message): void { ++$GLOBALS['checks']; if (!$condition) { throw new RuntimeException($message); } }
function calendar_reject(callable $call, string $message): void { try { $call(); } catch (DomainException $e) { calendar_check($e->getMessage() !== '', $message); return; } throw new RuntimeException($message); }
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor(); $zone = new DateTimeZone('America/Chicago');
$build = $reflection->getMethod('buildScheduledOccurrences');
$plan = static function (string $start, array $calendar, array $existing = [], int $minimum = 0) use ($build, $module, $zone): array {
    return $build->invoke($module, 'sched_calendar', [$start], 'calendar', $zone, $existing, $minimum, PHP_INT_MAX, $calendar);
};
$config = ['until'=>'2027-03-19', 'weekdays'=>[1,2,3,4,5],
    'exclusions'=>[['start'=>'2027-03-09','end'=>'2027-03-10','reason'=>'Holiday']],
    'overrides'=>[['date'=>'2027-03-15','time'=>'10:15','reason'=>'Late start']]];
$result = $plan('2027-03-08T08:00', $config);
calendar_check(!$result['errors'] && count($result['occurrences']) === 8, 'Calendar weekday/holiday expansion failed.');
$times = array_column($result['occurrences'], 'run_at_utc', 'local_datetime');
calendar_check($times['2027-03-08T08:00'] === '2027-03-08T14:00:00Z' && $times['2027-03-15T10:15'] === '2027-03-15T15:15:00Z'
    && $times['2027-03-16T08:00'] === '2027-03-16T13:00:00Z', 'Local time or exact override lost across DST.');
calendar_check(!isset($times['2027-03-09T08:00']) && !isset($times['2027-03-10T08:00']), 'Excluded holiday was scheduled.');
calendar_check(count(array_unique(array_column($result['occurrences'], 'id'))) === 8, 'Occurrence identities are not distinct.');
calendar_check($result === $plan('2027-03-08T08:00', $config), 'Repeated preview changed identity or publication time.');
foreach ([['2027-03-14T02:30','2027-03-14'], ['2027-11-07T01:30','2027-11-07']] as [$start,$until]) {
    $bad = $plan($start, ['until'=>$until,'weekdays'=>[7]]);
    calendar_check($bad['errors'] && !$bad['occurrences'], 'Unsafe DST time generated a partial calendar.');
}
calendar_check(count($plan('2028-02-28T08:00', ['until'=>'2028-03-01','weekdays'=>[1,2,3,4,5,6,7]])['occurrences']) === 3, 'Leap day lost.');
foreach ([['until'=>'2027-03-09','weekdays'=>[]], ['until'=>'2027-03-09','weekdays'=>[1,1]],
    ['until'=>'2027-03-09','weekdays'=>['1']], ['until'=>'2027-02-30','weekdays'=>[1]],
    $config+['remote_url'=>'https://example.invalid/'], ['until'=>'2027-03-09','weekdays'=>[1],'overrides'=>[['date'=>'2027-03-08','time'=>'24:00']]],
    ['until'=>'2027-03-09','weekdays'=>[1],'exclusions'=>[['start'=>'2027-03-09','end'=>'2027-03-08']]],
    ['until'=>'2027-03-09','weekdays'=>[1],'overrides'=>[['date'=>'2027-03-08','time'=>'08:00'],['date'=>'2027-03-08','time'=>'09:00']]],
    ['until'=>'2027-03-09','weekdays'=>[1],'exclusions'=>array_fill(0,101,['start'=>'2027-03-09','end'=>'2027-03-09'])]] as $bad) {
    calendar_reject(static fn()=>Calendar::validate($bad), 'Invalid calendar accepted.');
}
foreach (['2027-03-09','2027-03-14','2027-03-25'] as $unused) {
    $bad=$config;$bad['overrides']=[['date'=>$unused,'time'=>'09:00']];
    calendar_check($plan('2027-03-08T08:00',$bad)['errors'], 'Excluded/off-pattern/out-of-range override silently ignored.');
}
calendar_check($plan('2027-03-08T08:00',['until'=>'2029-03-08','weekdays'=>[1,2,3,4,5]])['errors'], 'More than 366 dates silently truncated.');
calendar_check($plan('2027-03-08T08:00',['until'=>'2034-03-08','weekdays'=>[1]])['errors'], 'Unbounded date horizon accepted.');
$known=array_fill_keys(array_column($result['occurrences'],'run_at_utc'),true);
calendar_check(!$plan('2027-03-08T08:00',$config,$known,strtotime('2027-03-17T00:00:00Z'))['errors'], 'Unchanged existing past dates blocked edit.');
calendar_check($plan('2027-03-08T08:00',$config,[],strtotime('2027-03-17T00:00:00Z'))['errors'], 'New past dates accepted.');
$form=['schedule_calendar_json'=>json_encode($config),'schedule_calendar_complete'=>'1'];
calendar_check(Calendar::fromForm($form) === Calendar::validate($config), 'Form calendar changed.');
foreach ([$form+['unused'=>'x'], array_replace($form,['schedule_calendar_complete'=>'0']),array_replace($form,['schedule_calendar_json'=>'{bad'])] as $index=>$bad) {
    if ($index) { calendar_reject(static fn()=>Calendar::fromForm($bad), 'Incomplete or corrupt editor accepted.'); }
}
$schedule=['id'=>'sched_calendar','name'=>'Calendar fixture','message'=>'Full message','enabled'=>'1','timezone'=>'America/Chicago',
    'recurrence'=>$result['recurrence'],'occurrences'=>$result['occurrences'],'targets'=>[], 'delivery'=>['audio_mode'=>'none']];
$validate=$reflection->getMethod('validateScheduledAnnouncementRecurrences');
calendar_check(!$validate->invoke($module,[$schedule]), 'Valid protected calendar rejected.');
$reordered=$schedule;foreach($reordered['occurrences']as &$row){ksort($row);}unset($row);
calendar_check(!$validate->invoke($module,[$reordered]), 'JSON key order changed calendar validity.');
foreach (['id','run_at_utc','local_datetime'] as $field) {
    $tampered=$schedule;$tampered['occurrences'][0][$field].='x';
    calendar_check($validate->invoke($module,[$tampered]), 'Tampered occurrence '.$field.' accepted.');
}
$normalized=$reflection->getMethod('normalizeScheduledAnnouncements')->invoke($module,[$schedule]);
calendar_check($normalized[0]['recurrence'] === $result['recurrence'] && $normalized[0]['occurrences'] === $result['occurrences'], 'Normalization discarded calendar rules.');
calendar_check(!$validate->invoke($module,$normalized), 'Normalized calendar no longer validates.');
calendar_check(\SLS\MassNotify\SchedulePresentation::textErrors(['message'=>str_repeat('x',501)]), 'Long scheduled message silently accepted.');

$ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fixture-1\r\nDTSTART;VALUE=DATE:20271224\r\nDTEND;VALUE=DATE:20271227\r\nSUMMARY:Winter\\, break\r\n and staff day\r\nURL:https://example.invalid/no-fetch\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20280101\r\nSUMMARY:New year\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$import=Calendar::import($ics,'ics');
calendar_check(Calendar::import(str_replace('VERSION:2.0',"VERSION:2.0\nMETHOD:PUBLISH",$ics),'ics')===$import,'Published holiday calendar rejected.');
calendar_check($import === [['start'=>'2027-12-24','end'=>'2027-12-26','reason'=>'Winter, breakand staff day'],['start'=>'2028-01-01','end'=>'2028-01-01','reason'=>'New year']], 'iCalendar exclusive end/default day/unfolding/escaped text changed.');
calendar_check(Calendar::import("start,end,reason\n2027-12-24,2027-12-26,\"Winter, break\"\n",'csv')[0]['reason']==='Winter, break', 'Quoted CSV failed.');
foreach (['', str_repeat('a',262145), str_replace('DTSTART;VALUE=DATE:20271224','DTSTART:20271224T090000Z',$ics),
    str_replace('UID:fixture-1','RRULE:FREQ=YEARLY',$ics), str_replace('UID:fixture-1','RECURRENCE-ID;VALUE=DATE:20271224',$ics),
    str_replace('UID:fixture-1','STATUS:CANCELLED',$ics), str_replace('DTEND;VALUE=DATE:20271227','DTEND;VALUE=DATE:20271224',$ics),
    str_replace('DTSTART;VALUE=DATE:20271224','DTSTART;VALUE=DATE:20270230',$ics),
    str_replace('END:VCALENDAR','',$ics), str_replace('UID:fixture-1',"BEGIN:VALARM\nEND:VALARM",$ics),
    str_replace('UID:fixture-1','DTSTART;VALUE=DATE:20271224',$ics),"BEGIN:VCALENDAR\nEND:VCALENDAR",$ics.$ics] as $bad) {
    calendar_reject(static fn()=>Calendar::import($bad,'ics'), 'Unsafe/unsupported calendar import accepted.');
}
calendar_reject(static fn()=>Calendar::import("start,end,reason\n2027-12-24,2027-12-26,broken,extra",'csv'), 'Malformed CSV accepted.');
calendar_reject(static fn()=>Calendar::import($ics,'url'), 'Unsupported import format accepted.');

$page=dirname(__DIR__).'/slsmassnotifyserver/page.slsmassnotifyserver_scheduling.php';
foreach ([['preview_schedule_calendar',true,200,1],['preview_schedule_calendar',false,403,0],
    ['import_schedule_calendar',true,200,0],['import_schedule_calendar',false,403,0]] as [$action,$csrf,$status,$calls]) {
    $post=['slsmassnotifyserver_action'=>$action,'calendar_text'=>$ics,'calendar_format'=>'ics'];
    $code='<?php class FreePBX{static function create(){return (object)["Slsmassnotifyserver"=>new Fixture];}}
    class Fixture{function enforceOperatorPageAccess($page){}function validateCsrfToken($token){return '.var_export($csrf,true).';}function previewScheduleCalendar($input){$GLOBALS["calls"]++;return ["success"=>true];}
    function __call($name,$args){throw new RuntimeException("Unexpected mutation: ".$name);}}
    $GLOBALS["calls"]=0;$_SESSION=[];$_SERVER["REQUEST_METHOD"]="POST";$_POST='.var_export($post,true).';
    register_shutdown_function(static function(){file_put_contents("php://stderr",json_encode([http_response_code()?:200,$GLOBALS["calls"]]));});require '.var_export($page,true).';';
    $process=proc_open([PHP_BINARY],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],$code);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);fclose($pipes[1]);$meta=stream_get_contents($pipes[2]);fclose($pipes[2]);
    calendar_check(proc_close($process)===0 && json_decode($meta,true)===[$status,$calls], 'Actual calendar controller method/CSRF behavior failed: '.$meta);
    calendar_check(is_array(json_decode($body,true)), 'Calendar controller returned a redirect or invalid JSON.');
}
echo "Schedule calendars: $checks expansion, DST, immutable dates, import, validation and controller checks passed.\n";
