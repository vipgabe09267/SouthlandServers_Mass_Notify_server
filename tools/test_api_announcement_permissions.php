<?php
declare(strict_types=1);
// Actual delivery trait, disposable job storage and inert channel adapters.
require __DIR__ . '/test_independent_channels.php';
class CredentialDeliveryFixture extends TestDelivery {
    public $settings=[];
    public function getActiveSettings(){return $this->settings;}
    public function getDeviceOverrideInventory(){return ['devices'=>[]];}
}
$issued=\SLS\MassNotify\ApiSecurity::issue(['name'=>'Restricted sender','scopes'=>['read','send'],
    'audience'=>['unrestricted'=>false,'extensions'=>['1000'],'desktop_client_ids'=>['device_gabe'],'webhook_ids'=>['hook']]]);
$principal=$issued['credential'];unset($principal['secret_hash']);
$fixture=new CredentialDeliveryFixture();$fixture->desktopFails=false;$fixture->audioFails=false;
$fixture->settings=['control_api'=>['enabled'=>true,'credentials'=>[$issued['credential']]],
    'desktop_clients'=>[['client_id'=>'device_gabe','username'=>'gabe','enabled'=>true]]];
$request['api_credential_id']=$principal['id'];$request['audio_mode']='none';
$request['desktop_client_ids']=['gabe'=>'device_gabe'];
$GLOBALS['sls_control_principal']=$principal;
$method=new ReflectionMethod(CredentialDeliveryFixture::class,'deliverResolvedAnnouncement');
$bad=$request;$bad['phones'][]='1001';
$denied=$method->invoke($fixture,$bad);
if(($denied['error_code']??'')!=='permission_denied'||$fixture->commands){throw new RuntimeException('Final resolved audience bypassed restricted credential.');}
$preview=$request;$preview['preview']=true;
$previewDirectory=sys_get_temp_dir().'/sls-api-preview-'.bin2hex(random_bytes(8));mkdir($previewDirectory,0700);
$fixture->jobDirectory=$previewDirectory;
try {
    $previewResult=$method->invoke($fixture,$preview);
    if(empty($previewResult['success'])||($previewResult['preview']??null)!==true){throw new RuntimeException('Authorized preview was rejected.');}
    if($fixture->commands||$fixture->notifyLogs||$fixture->audioPriority!==''||(glob($previewDirectory.'/*')?:[])){
        throw new RuntimeException('Authorized preview submitted a channel, recorded delivery, or queued work.');
    }
}finally{foreach(glob($previewDirectory.'/*')?:[] as $file)unlink($file);rmdir($previewDirectory);$fixture->jobDirectory='';}
unset($GLOBALS['sls_control_principal']);
$fixture->settings['control_api']['credentials'][0]['revoked_at']=gmdate('c');
$before=count($fixture->commands);$cancelled=$fixture->run($request);
if(count($fixture->commands)!==$before||count($cancelled['receipts'])!==3
    ||count(array_filter($cancelled['receipts'],static fn($row)=>$row['state']==='cancelled'))!==3){
    throw new RuntimeException('Revoked queued credential still submitted a channel.');
}
$fixture->settings['control_api']['credentials'][0]['revoked_at']='';
$fixture->audioPermissionDeniedTargets=['phones'=>['1000'],'voice_recipient_ids'=>[]];
$audioRequest=$request;$audioRequest['audio_mode']='tts';
$audioDenied=$fixture->run($audioRequest);
$deniedRows=array_values(array_filter($audioDenied['receipts'],static fn($row)=>$row['channel']==='audio'&&$row['target']==='1000'));
if(count($deniedRows)!==1||$deniedRows[0]['state']!=='cancelled'||$deniedRows[0]['retryable']){
    throw new RuntimeException('Audio recipients removed during speech preparation were reported as retryable failures.');
}
$fixture->audioPermissionDeniedTargets=['phones'=>[],'voice_recipient_ids'=>[]];
$fixture->settings['control_api']['credentials'][0]['audience']['extensions']=['1001'];
$fixture->currentTargets['phones']=['1000','1001'];
$partial=$fixture->run($request);
if(count(array_filter($partial['receipts'],static fn($row)=>$row['target']==='1001'))
    ||count(array_filter($partial['receipts'],static fn($row)=>$row['target']==='1000'&&$row['state']==='cancelled'))!==1){
    throw new RuntimeException('Changed permissions added new recipients or retained a removed recipient.');
}
$directory=sys_get_temp_dir().'/sls-api-jobs-'.bin2hex(random_bytes(8));mkdir($directory,0700);$fixture->jobDirectory=$directory;
$jobId='job_'.str_repeat('d',32);
try{
 $GLOBALS['sls_control_principal']=$principal;
 $job=['id'=>$jobId,'state'=>'queued','created_at'=>gmdate('c'),'request'=>$request,'receipts'=>[],'message'=>'fixture'];
 file_put_contents($directory.'/'.$jobId.'.json',json_encode($job));
 if(($fixture->getAnnouncementJob($jobId)['api_credential_id']??'')!==$principal['id'])throw new RuntimeException('Own job read failed.');
 $GLOBALS['sls_control_principal']['id']='api_'.str_repeat('e',24);
 foreach([$fixture->getAnnouncementJob($jobId),$fixture->retryFailedAnnouncementJob($jobId)] as $result){
   if(($result['error_code']??'')!=='permission_denied')throw new RuntimeException('Another key could read or retry a restricted job.');
 }
}finally{unset($GLOBALS['sls_control_principal']);foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory);}
echo "Resolved API audiences, queued revocation, recipient intersection and job ownership passed.\n";
