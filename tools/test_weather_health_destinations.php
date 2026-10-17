<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$sourcePath=$reflection->getFileName();
if(!is_string($sourcePath)||$sourcePath===''){throw new RuntimeException('Module source path is unavailable.');}
$lines=file($sourcePath);
if(!is_array($lines)){throw new RuntimeException('Module source is unreadable.');}
$method=$reflection->getMethod('dashboardService');
$dashboard=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
$start=strpos($dashboard,'foreach ($zoneGroups as $zoneGroup)');
if($start===false){throw new RuntimeException('Weather health branch start is missing.');}
$end=strpos($dashboard,'if (parse_url(', $start);
if($end===false||$end<=$start){throw new RuntimeException('Weather health branch end is missing.');}
$weather=substr($dashboard,$start,$end-$start);
$start=strpos($dashboard,'foreach ($enabledLightningGroups as $group)');
if($start===false){throw new RuntimeException('Lightning health branch start is missing.');}
$end=strpos($dashboard,'$queryInterval =', $start);
if($end===false||$end<=$start){throw new RuntimeException('Lightning health branch end is missing.');}
$lightning=substr($dashboard,$start,$end-$start);
$fixture='class WeatherHealthFixture {';
foreach(['nwsZoneHasDeliveryDestination','xweatherGroupHasDeliveryDestination','hasEnabledSharedAlertWebhook','destinationListHasValue','normalizeNwsZone']as$name){
    $method=$reflection->getMethod($name);$methodLines=array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1);
    if(!$methodLines){throw new RuntimeException('Weather health helper source is missing: '.$name);}$fixture.=implode('',$methodLines);
}
$fixture.='private function normalizeWebhookDestinations($rows,$kind){return $rows;}'
    .' public function weather($route){$zoneGroups=[$route];$enabledDesktopUsernames=["alice"=>true];$warnings=[];'.$weather.' return $warnings;}'
    .' public function lightning($route,$settings=[]){$enabledLightningGroups=[$route];$enabledDesktopUsernames=["alice"=>true];$validZoneIds=[];$xweather=["adaptive_free_tier"=>"0"];$warnings=[];'.$lightning.' return $warnings;} }';
eval($fixture);$module=new WeatherHealthFixture;
$route=['name'=>'Private route','zone'=>'TXZ163','location'=>'Fixture','extensions'=>[],'desktop_clients'=>[]];
foreach(['extensions','desktop_clients','email_recipients','sms_recipient_ids','voice_recipient_ids','discord_webhook_ids','generic_webhook_ids']as$field){
    $selected=array_replace($route,[$field=>[$field==='desktop_clients'?'alice':'saved-id']]);
    if($module->weather($selected)!==[]){throw new RuntimeException('Weather health omitted configured '.$field);}
    if(!in_array($field,['discord_webhook_ids','generic_webhook_ids'],true)&&$module->lightning($selected)!==[]){throw new RuntimeException('Lightning health omitted configured '.$field);}
}
if($module->lightning($route,['discord_webhooks'=>[['enabled'=>'1']]])!==[]){throw new RuntimeException('Lightning health omitted shared webhook routing.');}
if(count($module->weather($route))!==1||count($module->lightning($route))!==1){throw new RuntimeException('Empty weather route did not need attention.');}
if(count($module->weather(array_replace($route,['desktop_clients'=>['revoked']])))!==2){throw new RuntimeException('Disabled desktop was counted as an available route.');}
echo "Actual dashboard Weather/Lightning routing checks preserve external-only channels, shared webhooks, empty-route warnings and revoked desktops.\n";
