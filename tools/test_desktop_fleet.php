<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/DesktopFleet.php';
use SLS\MassNotify\DesktopFleet;
function fleetCheck($ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$now = 1800000000;
$client = ['client_id'=>'cli_fixture', 'enabled'=>'1'];
$seen = ['client_id'=>'cli_fixture', 'seen_at'=>gmdate('c', $now), 'connected_until'=>$now+30,
    'client_report'=>['version'=>'1.2.3', 'payload_schema'=>1, 'sse_protocol'=>2, 'reported_at'=>gmdate('c', $now)]];
foreach ([''=>'meets_minimum', '1.2.2'=>'meets_minimum', '1.2.3'=>'meets_minimum', '1.2.4'=>'upgrade_required', '1.2.3-beta.1'=>'meets_minimum'] as $minimum=>$expected) {
    $result = DesktopFleet::readiness($client,$seen,$minimum,$now);
    fleetCheck($result['compatibility']===$expected && $result['connection']==='streaming', 'Incorrect compatibility: '.$minimum);
}
$changed=$seen; $changed['client_report']['version']='1.2.3-beta.2';
fleetCheck(DesktopFleet::readiness($client,$changed,'1.2.3',$now)['compatibility']==='upgrade_required','Prerelease accepted as stable');
$changed=$seen; $changed['client_report']['version']='1.0.13-beta';
fleetCheck(DesktopFleet::readiness($client,$changed,DesktopFleet::DEFAULT_MINIMUM,$now)['compatibility']==='meets_minimum','Current beta fails default version policy');
$changed['client_report']['version']='1.0.12-beta';
fleetCheck(DesktopFleet::readiness($client,$changed,DesktopFleet::DEFAULT_MINIMUM,$now)['compatibility']==='upgrade_required','Older beta meets current minimum');
fleetCheck(DesktopFleet::readiness($client,$changed,'1.10.0',$now)['compatibility']==='upgrade_required','Explicit administrator minimum was weakened');
foreach (['1.0.12-beta+build.7', '1.0.12-preview.1', '1.0.12+001'] as $reported) {
    $changed=$seen; $changed['client_report']['version']=$reported;
    fleetCheck(DesktopFleet::readiness($client,$changed,'1.0.10-beta',$now)['compatibility']==='meets_minimum','Valid reported SemVer was lost: '.$reported);
}
foreach (['1.0.12-beta.01', '01.0.12', '1.0.12+', '1.0.12-alpha..1'] as $reported) {
    $changed=$seen; $changed['client_report']['version']=$reported;
    fleetCheck(DesktopFleet::readiness($client,$changed,'1.0.10-beta',$now)['compatibility']==='invalid_version','Invalid reported version was not identified');
}
$precedence=['1.0.0-alpha','1.0.0-alpha.1','1.0.0-alpha.beta','1.0.0-beta','1.0.0-beta.2','1.0.0-beta.11','1.0.0-rc.1','1.0.0'];
foreach ($precedence as $index=>$version) {
    if ($index) { fleetCheck(DesktopFleet::compareVersions($precedence[$index-1],$version)<0,'SemVer prerelease order changed'); }
}
fleetCheck(DesktopFleet::compareVersions('1.2.3+build.8','1.2.3+build.1')===0,'Build metadata changed precedence');
fleetCheck(DesktopFleet::compareVersions('12345678901234567890.0.0','12345678901234567891.0.0')<0,'Large numeric version overflow');
$changed=$seen; $changed['client_report']['sse_protocol']=3;
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['compatibility']==='unsupported_protocol','Unknown protocol shown as supported');
foreach ([['payload_schema'],['sse_protocol'],['payload_schema','sse_protocol']] as $missing) {
    $changed=$seen;foreach($missing as $field){unset($changed['client_report'][$field]);}
    fleetCheck(DesktopFleet::readiness($client,$changed,'1.2.3',$now)['compatibility']==='protocol_not_reported','Missing protocol metadata was inferred from the app version.');
}
$changed=$seen; $changed['client_report']['reported_at']=gmdate('c',$now-86401);
fleetCheck(DesktopFleet::readiness($client,$changed,'1.2.3',$now)['compatibility']==='stale_report','Old metadata treated as current');
fleetCheck(DesktopFleet::readiness(['client_id'=>'cli_new','enabled'=>'1'],$seen,'1.2.3',$now)['compatibility']==='not_reported','Another device inherited compatibility');
fleetCheck(DesktopFleet::readiness(array_replace($client,['enabled'=>'0']),$seen,'',$now)['connection']==='disabled','Revoked client shown as connected');
foreach ([null,[],true,' 1.2.3','1.2','01.2.3','1.2.3<script>',str_repeat('1',50).'.2.3'] as $bad) { fleetCheck(!DesktopFleet::validMinimum($bad),'Invalid minimum accepted'); }
foreach (['','1.2.3','0.1.5-beta','1.2.3-rc.2'] as $good) { fleetCheck(DesktopFleet::validMinimum($good),'Valid minimum rejected'); }
$changed=$seen; unset($changed['connected_until']);
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['connection']==='recent','Polling activity treated as disconnected');
foreach ([[], (string)($now+30), true, (float)($now+30)] as $invalidConnectedUntil) {
    $changed=$seen; $changed['connected_until']=$invalidConnectedUntil;
    fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['connection']==='recent','Malformed stream expiry was coerced into an active connection.');
}
$changed['seen_at']=gmdate('c',$now+61);
fleetCheck(DesktopFleet::readiness($client,$changed,'',$now)['connection']==='clock_error','Future presence treated as current');
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$class=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class); $module=$class->newInstanceWithoutConstructor();
$patch=$class->getMethod('validateAndNormalizeControlConfigPatch');
fleetCheck(!$patch->invoke($module,['desktop_minimum_version'=>'1.2.3-beta.1'])['errors'],'API rejected minimum policy');
foreach ([null,'garbage',false,[]] as $bad) { fleetCheck((bool)$patch->invoke($module,['desktop_minimum_version'=>$bad])['errors'],'API accepted invalid minimum policy'); }
fleetCheck(DesktopFleet::DEFAULT_MINIMUM==='1.0.13-beta','Default minimum changed');
fleetCheck(DesktopFleet::readiness($client,[],DesktopFleet::DEFAULT_MINIMUM,$now)['upgrade_required']===true,'Unreported version did not require an update');
$changed=$seen;$changed['client_report']['version']='1.0.13-beta';
fleetCheck(!DesktopFleet::readiness($client,$changed,DesktopFleet::DEFAULT_MINIMUM,$now)['upgrade_required'],'Current minimum unnecessarily flagged');
foreach (['1.0.2-beta','1.0.9-beta','1.0.10-beta','1.0.11-beta','1.0.12-beta','1.0.12'] as $version) {
    $changed=$seen;$changed['client_report']['version']=$version;
    $result=DesktopFleet::readiness($client,$changed,'',$now);
    fleetCheck($result['upgrade_required'] && $result['compatibility']==='upgrade_required','Below-default app not flagged: '.$version);
}
foreach (['1.0.13-beta','1.0.13-beta+build.7','1.0.13','1.0.14-alpha'] as $version) {
    $changed=$seen;$changed['client_report']['version']=$version;
    fleetCheck(!DesktopFleet::readiness($client,$changed,'',$now)['upgrade_required'],'At/above-default app unnecessarily flagged: '.$version);
}
echo "Desktop readiness: identity, revocation, version policy, protocol, clock/staleness and API validation passed.\n";
