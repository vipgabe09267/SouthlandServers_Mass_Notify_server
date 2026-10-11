<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\ScheduleProgrammes as Programmes;
use SLS\MassNotify\ScheduleCalendar as Calendar;
$checks=0;
function verify($condition,string $message):void {++$GLOBALS['checks'];if(!$condition){throw new RuntimeException($message);}}
function reject(callable $fn):void {try{$fn();}catch(DomainException $e){verify($e->getMessage()!=='','Missing validation reason.');return;}throw new RuntimeException('Invalid programme accepted.');}
$row=['id'=>'cal_'.str_repeat('a',24),'name'=>'School year','timezone'=>'America/Chicago',
    'cycle_start'=>'2027-03-08','cycle_mode'=>'school_days','day_types'=>['A','B','EARLY'],
    'date_types'=>[['date'=>'2027-03-15','day_type'=>'EARLY'],['date'=>'2027-03-17','day_type'=>'']],
    'calendar'=>['until'=>'2027-03-19','weekdays'=>[1,2,3,4,5],
        'exclusions'=>[['start'=>'2027-03-09','end'=>'2027-03-10','reason'=>'Holiday']],
        'overrides'=>[['date'=>'2027-03-15','time'=>'10:15','reason'=>'Late start']]]];
$config=['schema'=>1,'enabled'=>true,'calendars'=>[$row]];
$snapshot=Programmes::snapshot($config,$row['id'],['A']);$dates=Programmes::dateTypes($snapshot);
verify($dates===['2027-03-08'=>'A','2027-03-11'=>'B','2027-03-12'=>'EARLY','2027-03-15'=>'EARLY',
    '2027-03-16'=>'B','2027-03-17'=>'','2027-03-18'=>'A','2027-03-19'=>'B'],'Holiday or substitution incorrectly advanced the cycle.');
$zone=new DateTimeZone('America/Chicago');
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$module=$reflection->newInstanceWithoutConstructor();$resolver=$reflection->getMethod('resolveScheduleLocalDateTime');
$build=static fn($calendar,$tz=null)=>Calendar::build('sched_fixture',['2027-03-08T08:00'],$calendar,$tz??$zone,[],0,PHP_INT_MAX,366,static fn($local,$tz)=>$resolver->invoke($module,$local,$tz));
$plan=$build($snapshot);verify(!$plan['errors']&&count($plan['occurrences'])===2,'A-day expansion failed.');
verify(array_column($plan['occurrences'],'run_at_utc')===['2027-03-08T14:00:00Z','2027-03-18T13:00:00Z'],'Local time drifted across DST.');
verify($plan===$build($snapshot),'Repeated preview changed identities.');
$early=Programmes::snapshot($config,$row['id'],['EARLY']);
verify(array_column($build($early)['occurrences'],'local_datetime')===['2027-03-12T08:00','2027-03-15T10:15'],'Whole-day/time substitutions failed.');
verify((bool)$build($snapshot,new DateTimeZone('UTC'))['errors'],'Programme timezone mismatch accepted.');
$config['calendars'][0]['date_types']=[];
verify($build($snapshot)===$plan,'An edit changed frozen dates.');
$weekly=$row;$weekly['day_types']=['A','B'];$weekly['cycle_mode']='weeks';$weekly['date_types']=[];
$weekly=Programmes::snapshot(['enabled'=>true,'calendars'=>[$weekly]],$row['id'],['B']);
verify(count($build($weekly)['occurrences'])===5,'Weekly cycle or DST week arithmetic failed.');
reject(static fn()=>Programmes::snapshot([], $row['id'],['A']));
reject(static fn()=>Programmes::snapshot(['enabled'=>true,'calendars'=>[]],$row['id'],['A']));
foreach([[],['missing'],['A','A'],[['nested']]]as $selected){reject(static fn()=>Programmes::snapshot(['enabled'=>true,'calendars'=>[$row]],$row['id'],$selected));}
foreach([['timezone'=>'bad'],['day_types'=>['a']],['cycle_mode'=>'unknown'],['cycle_start'=>'2027-02-30'],
    ['date_types'=>[['date'=>'2027-03-09','day_type'=>'A']]],['calendar'=>['until'=>'2035-01-01','weekdays'=>[1]]],
    ['name'=>"bad\nname"],['unknown'=>'value']]as $patch){
    reject(static fn()=>Programmes::normalize(['enabled'=>true,'calendars'=>[array_replace($row,$patch)]]));
}
reject(static fn()=>Programmes::normalize(['enabled'=>true,'calendars'=>[$row,$row]]));
$escape=$snapshot;$escape['programme']['selected_types']=['missing'];reject(static fn()=>Calendar::validate($escape));
$ui=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/views/schedule_programmes.js');
verify(str_contains($ui,'textContent')&&!str_contains($ui,'innerHTML'),'Calendar editor uses unsafe markup.');
echo "Shared calendar programmes: {$checks} cycle, holiday, DST, snapshot and validation checks passed.\n";
