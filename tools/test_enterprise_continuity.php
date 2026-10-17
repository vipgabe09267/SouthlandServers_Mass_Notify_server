<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/EnterpriseContinuity.php';
use SLS\MassNotify\{EnterpriseContinuity,OperatorAuth};
function check(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
$report=EnterpriseContinuity::report([]);check(count($report['channels'])===11&&array_filter($report['channels'],static fn($channel)=>$channel['enabled'])===[],'Unconfigured continuity report enabled a channel');
$auth=['password_hash'=>OperatorAuth::dummyHash(),'version'=>str_repeat('a',64),'totp_secret_enc'=>'op1:'.str_repeat('A',80),'totp_last_counter'=>-1,'recovery_hashes'=>[],'force_password_change'=>false];
$settings=['enabled'=>'1','page_group'=>'1000','desktop_clients'=>[['username'=>'1000','enabled'=>true]],'operator_access'=>['enabled'=>true,'portal_enabled'=>true,'accounts'=>[['id'=>'operator','username'=>'Local admin','source'=>'portal','role'=>'administrator','enabled'=>true,'auth'=>$auth]]],
 'enterprise_identity'=>['enabled'=>true],'subscriber_browser'=>['enabled'=>true,'push'=>['enabled'=>true]],'enterprise_integrations'=>['enabled'=>'1','connectors'=>['enabled'=>'1']]];
$report=EnterpriseContinuity::report($settings);$byId=array_column($report['channels'],null,'id');
check($report['schema']===2&&$byId['browser_push']['enabled']&&str_contains($byId['browser_push']['offline_behavior'],'external'),'Cloud push inaccurately advertised offline capability');
check($byId['phones']['configured_destinations']===1&&$byId['desktops']['configured_destinations']===1,
    'Numeric extension or desktop identities disappeared through PHP array-key coercion.');
check($byId['phones']['state']==='configured_unverified','Configured numeric phone destination was not kept distinct from acceptance.');
foreach(['browser_push','connectors','identity'] as $id){check($byId[$id]['state']==='enabled_incomplete'&&!empty($byId[$id]['missing_prerequisites']),$id.' did not expose incomplete setup');}
check($byId['desktops']['state']==='configured_unverified'&&$byId['desktops']['configured_destinations']===1,'Configured desktop channel was not reported.');
check($report['local_recovery']['ready_for_local_login']&&!str_contains(json_encode($report),'password_hash'),'Local break-glass readiness or secret isolation failed');
$settings['announcement_email']=['enabled'=>'1','recipients'=>[['id'=>'email_'.str_repeat('a',24),'enabled'=>true]]];
$email=array_column(EnterpriseContinuity::report($settings)['channels'],null,'id')['email'];
check($email['state']==='configured_unverified'&&$email['configured_destinations']===1&&$email['accepted_destinations']===0,'Configured email was not kept distinct from acceptance');
$settings['outbound_voice']=['enabled'=>'1','recipients'=>[]];
$voice=array_column(EnterpriseContinuity::report($settings)['channels'],null,'id')['external_voice'];
check($voice['state']==='enabled_incomplete'&&$voice['configured']===false,'Empty external voice setup was presented as configured');
$settings['announcement_sms']=['enabled'=>true,'provider'=>'','recipients'=>[]];
$sms=array_column(EnterpriseContinuity::report($settings)['channels'],null,'id')['sms'];
check($sms['state']==='enabled_incomplete'&&count($sms['missing_prerequisites'])===2,'Empty SMS setup did not report destination and provider prerequisites');
$settings['operator_access']['enabled']=false;
check(EnterpriseContinuity::report($settings)['local_recovery']['ready_for_local_login'],'PBX-account restrictions disabled an independent local portal');
$settings['operator_access']['accounts'][0]['expires_at']=time()-1;
check(!EnterpriseContinuity::report($settings)['local_recovery']['ready_for_local_login'],'Expired administrator counted as available for recovery');
$settings['operator_access']['accounts'][0]['expires_at']=time()+60;
check(EnterpriseContinuity::report($settings)['local_recovery']['ready_for_local_login'],'Unexpired administrator incorrectly excluded');
$settings['operator_access']['accounts'][0]['expires_at']=0;
check(str_contains($report['cluster_scope'],'not qualified'),'Report claimed complete PBX failover');
$settings['operator_access']['accounts'][0]['enabled']=false;check(!EnterpriseContinuity::report($settings)['local_recovery']['ready_for_local_login'],'Disabled local administrator considered available');
$settings['operator_access']['accounts'][0]['enabled']=true;$settings['enabled']='0';$settings['nws_zones']=[['zone'=>'TXC491']];$settings['runtime_enabled']=true;
$report=EnterpriseContinuity::report($settings);$byId=array_column($report['channels'],null,'id');check($byId['phones']['enabled']&&$byId['connectors']['enabled']&&$byId['browser_push']['enabled']&&!$byId['weather']['enabled'],'Weather-disabled report incorrectly gated ordinary channels or enabled NWS');
$settings['xweather']['enabled']='1';check(array_column(EnterpriseContinuity::report($settings)['channels'],null,'id')['weather']['enabled'],'Weather-disabled report hid independently enabled lightning');
$settings['runtime_enabled']=false;$report=EnterpriseContinuity::report($settings);$byId=array_column($report['channels'],null,'id');check(!$report['notifications_running']&&!$byId['phones']['enabled']&&!$byId['connectors']['enabled']&&!$byId['browser_push']['enabled']&&!$byId['weather']['enabled']&&$report['local_recovery']['ready_for_local_login']&&$byId['identity']['enabled'],'Stopped runtime report admitted notifications or hid recovery/identity');
echo "Continuity review: disabled defaults, cloud/LAN separation, local recovery, secret isolation and notification-only failover scope passed.\n";
