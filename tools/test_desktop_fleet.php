<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/DesktopFleet.php';
use SLS\MassNotify\DesktopFleet;
function fleetCheck($ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$now = 1800000000;
$client = ['client_id'=>'cli_fixture', 'enabled'=>'1'];
$seen = ['client_id'=>'cli_fixture', 'seen_at'=>gmdate('c', $now), 'connected_until'=>$now+30,
    'client_report'=>['version'=>'1.2.3', 'payload_schema'=>1, 'sse_protocol'=>2, 'reported_at'=>gmdate('c', $now)]];
foreach ([''=>'no_minimum', '1.2.2'=>'meets_minimum', '1.2.3'=>'meets_minimum', '1.2.4'=>'upgrade_required', '1.2.3-beta.1'=>'meets_minimum'] as $minimum=>$expected) {
    $result = DesktopFleet::readiness($client,$seen,$minimum,$now);
    fleetCheck($result['compatibility']===$expected && $result['connection']==='streaming', 'Incorrect compatibility: '.$minimum);
}
$changed=$seen; $changed['client_report']['version']='1.2.3-beta.2';
fleetCheck(DesktopFleet::readiness($client,$changed,'1.2.3',$now)['compatibility']==='upgrade_required','Prerelease accepted as stable');
$changed=$seen; $changed['client_report']['sse_protocol']=3;
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['compatibility']==='unsupported_protocol','Unknown protocol shown as supported');
$changed=$seen; $changed['client_report']['reported_at']=gmdate('c',$now-86401);
fleetCheck(DesktopFleet::readiness($client,$changed,'1.2.3',$now)['compatibility']==='stale_report','Old metadata treated as current');
fleetCheck(DesktopFleet::readiness(['client_id'=>'cli_new','enabled'=>'1'],$seen,'1.2.3',$now)['compatibility']==='not_reported','Another device inherited compatibility');
fleetCheck(DesktopFleet::readiness(array_replace($client,['enabled'=>'0']),$seen,'',$now)['connection']==='disabled','Revoked client shown as connected');
foreach ([null,[],true,' 1.2.3','1.2','01.2.3','1.2.3<script>',str_repeat('1',50).'.2.3'] as $bad) { fleetCheck(!DesktopFleet::validMinimum($bad),'Invalid minimum accepted'); }
foreach (['','1.2.3','0.1.5-beta','1.2.3-rc.2'] as $good) { fleetCheck(DesktopFleet::validMinimum($good),'Valid minimum rejected'); }
$changed=$seen; unset($changed['connected_until']);
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['connection']==='recent','Polling activity treated as disconnected');
$changed['seen_at']=gmdate('c',$now+61);
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['connection']==='clock_error','Future presence treated as current');
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$class=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class); $module=$class->newInstanceWithoutConstructor();
$patch=$class->getMethod('validateAndNormalizeControlConfigPatch');
fleetCheck(!$patch->invoke($module,['desktop_minimum_version'=>'1.2.3-beta.1'])['errors'],'API rejected minimum policy');
foreach ([null,'garbage',false,[]] as $bad) { fleetCheck((bool)$patch->invoke($module,['desktop_minimum_version'=>$bad])['errors'],'API accepted invalid minimum policy'); }
echo "Desktop readiness: identity, revocation, version policy, protocol, clock/staleness and API validation passed.\n";
