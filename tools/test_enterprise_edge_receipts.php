<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterEdge.php';
use SLS\MassNotify\{EnterpriseClusterConfig,EnterpriseClusterRuntime,EnterpriseClusterProtocol,EnterpriseClusterEdge};
function check(bool $ok,string $message): void { if(!$ok){throw new RuntimeException($message);} }
function rejects(callable $work,string $message): void {try{$work();}catch(DomainException|RuntimeException $error){check($error->getMessage()!=='',$message);return;}throw new RuntimeException($message);}
function edgeReceiptCleanup(string $root,bool $strict=false):void
{
    if(!file_exists($root)&&!is_link($root)){return;}
    if(!is_dir($root)||is_link($root)){if($strict){throw new RuntimeException('Unsafe edge-receipt cleanup root.');}return;}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){$removed=$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());if(!$removed&&$strict){throw new RuntimeException('Unable to remove an edge-receipt fixture entry.');}}
    if(!rmdir($root)&&$strict){throw new RuntimeException('Unable to remove the edge-receipt fixture root.');}
}
if(getenv('SLS_TEST_NAMESPACE')!=='entered'){throw new RuntimeException('Use the private isolation wrapper.');}
$root='/var/lib/asterisk/sls-edge-receipts-'.bin2hex(random_bytes(8));check(mkdir($root,0700)&&chmod($root,0700),'Unable to create the private edge-receipt fixture.');
register_shutdown_function(static fn()=>edgeReceiptCleanup($root,false));
$audio='RIFF'.pack('V',356).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',320).str_repeat("\0",320);
$asset=$root.'/tone.wav';check(file_put_contents($asset,$audio,LOCK_EX)===strlen($audio)&&chmod($asset,0600),'Unable to create the private edge audio fixture.');
$intent=['message'=>'Private edge qualification','title'=>'Local audio','audio_asset_id'=>'tone'];
$fingerprint=hash('sha256',EnterpriseClusterProtocol::canonical($intent));$token=str_repeat('T',48);$otherToken=str_repeat('U',48);
$config=['enabled'=>true,'remote_enabled'=>true,'mode'=>'edge','cluster_id'=>'test','node_id'=>'edge','site_id'=>'site',
    'tls_cert'=>'/fixture','tls_key'=>'/fixture','tls_ca'=>'/fixture','site_recipients'=>['speaker','other','display-only'],
    'local_device_channels'=>['local_display','local_audio'],'offline_cache_enabled'=>true,'approved_cache'=>[$fingerprint],
    'edge_devices'=>[['id'=>'speaker','token_sha256'=>hash('sha256',$token),'channels'=>['local_display','local_audio']],
        ['id'=>'other','token_sha256'=>hash('sha256',$otherToken),'channels'=>['local_display','local_audio']],
        ['id'=>'display-only','token_sha256'=>hash('sha256',str_repeat('V',48)),'channels'=>['local_display']]],
    'edge_assets'=>[['id'=>'tone','path'=>$asset,'sha256'=>hash('sha256',$audio)]],
    'peers'=>[['node_id'=>'coordinator','site_id'=>'site','role'=>'coordinator','https_url'=>'https://localhost/fixture',
        'cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]];
$runtime=new EnterpriseClusterRuntime(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config],$root.'/enterprise-cluster',str_repeat('c',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config]);
$runtime->initialize();$edge=new EnterpriseClusterEdge($runtime);$device=$edge->authenticate('speaker',$token);$other=$edge->authenticate('other',$otherToken);$now=time();
$job=['id'=>'job-a','delivery_id'=>'delivery-a','site_id'=>'site','created_at'=>$now,'expires_at'=>$now+60,
    'recipients'=>['speaker'],'channels'=>['local_audio','local_display'],'intent'=>$intent,'content_sha256'=>$fingerprint,'offline_approved'=>true];
$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>$job]);$edge->drain(false);
$previousHandler=set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
try{rejects(static fn()=>$edge->media($device,'missing-event','tone'),'An unknown event did not fail cleanly.');}
finally{restore_error_handler();}
$runtime->store()->transaction(static function(array &$state):void{$state['control']['stopped']=true;});
check(count($edge->poll($device))===1,'STOP retracted an already published event.');
check($edge->media($device,'job-a','tone')['bytes']===$audio,'Initial exact audio was unavailable.');
$received=$edge->receipt($device,['id'=>'job-a','kind'=>'application_received']);
$runtime->store()->transaction(static function(array &$state):void{$state['control']['stopped']=false;});
check($edge->poll($device)===[],'Received event was re-advertised.');
check($edge->media($device,'job-a','tone')['bytes']===$audio,'Application receipt incorrectly revoked later audio retrieval.');
$displayed=$edge->receipt($device,['id'=>'job-a','kind'=>'displayed']);
check($edge->media($device,'job-a','tone')['bytes']===$audio,'Display receipt incorrectly revoked later audio retrieval.');
rejects(static fn()=>$edge->media($other,'job-a','tone'),'Another enrolled device retrieved this event.');
rejects(static fn()=>$edge->media($device,'job-a','different'),'A different asset was accepted.');
$changedAudio=$audio.'changed';check(file_put_contents($asset,$changedAudio,LOCK_EX)===strlen($changedAudio),'Unable to change the edge audio fixture.');
rejects(static fn()=>$edge->media($device,'job-a','tone'),'Changed asset hash was accepted.');check(file_put_contents($asset,$audio,LOCK_EX)===strlen($audio),'Unable to restore the edge audio fixture.');
$played=$edge->receipt($device,['id'=>'job-a','kind'=>'audio_played']);
check($edge->receipt($device,['id'=>'job-a','kind'=>'application_received'])===$received,'Application receipt lost its exact identity.');
check($edge->receipt($device,['id'=>'job-a','kind'=>'displayed'])===$displayed,'Display receipt lost its exact identity.');
check($edge->receipt($device,['id'=>'job-a','kind'=>'audio_played'])===$played,'Playback receipt was not idempotent.');
rejects(static fn()=>$edge->media($device,'job-a','tone'),'Completed audio was available for replay.');
$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'job-b','delivery_id'=>'delivery-b'])]);
$edge->drain(false);
$runtime->store()->transaction(static function(array &$state):void{
    $first=$state['cache']['job-b'];$firstQueue=$state['queue']['job-b'];$ordered=[];$orderedQueue=[];
    for($i=0;$i<55;$i++){$id='older-'.$i;$copy=$first;$copy['job_id']=$id;$ordered[$id]=$copy;
        $queueCopy=$firstQueue;$queueCopy['job']['id']=$id;$orderedQueue[$id]=$queueCopy;}
    $state['cache']=$ordered+$state['cache'];$state['queue']=$orderedQueue+$state['queue'];
});
check(count($edge->poll($device))===50,'Private pagination boundary was not exercised.');
check($edge->media($device,'job-b','tone')['bytes']===$audio,'Audio authorization depended on the first polling page.');
$displayOnly=$device;$displayOnly['channels']=['local_display'];
rejects(static fn()=>$edge->media($displayOnly,'job-b','tone'),'A display-only device retrieved audio.');
$incompatible=array_replace($job,['id'=>'incompatible','delivery_id'=>'incompatible-delivery','recipients'=>['display-only'],'channels'=>['local_audio']]);
rejects(static fn()=>$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>$incompatible]),'Dispatch admitted a recipient without the requested capability.');
check(!isset($runtime->store()->read()['queue']['incompatible']),'Rejected capability dispatch created a queue entry.');
$runtime->store()->transaction(static function(array &$state)use($incompatible,$now):void{
    $state['queue']['incompatible']=['job'=>$incompatible,'state'=>'queued','received_at'=>$now,'receipt'=>null];
});
rejects(static fn()=>$edge->drain(false),'An incompatible recipient consumed a local-audio job.');
check($runtime->store()->read()['queue']['incompatible']['state']==='queued','Capability rejection consumed the no-replay queue entry.');
$beforeDetached=file_get_contents($root.'/enterprise-cluster/worker-state.json');
rejects(static fn()=>$runtime->store()->transaction(static function(array &$state):void{unset($state['queue']['job-a']);}),'A detached edge cache record was accepted.');
check(file_get_contents($root.'/enterprise-cluster/worker-state.json')===$beforeDetached,'Rejected detached cache state changed the journal.');
$runtime->store()->transaction(static function(array &$state):void{$created=time()-61;$expires=time()-1;
    $state['cache']['job-b']['created_at']=$created;$state['cache']['job-b']['expires_at']=$expires;
    $state['queue']['job-b']['job']['created_at']=$created;$state['queue']['job-b']['job']['expires_at']=$expires;});
rejects(static fn()=>$edge->media($device,'job-b','tone'),'Expired audio was served.');
edgeReceiptCleanup($root,true);
echo "Exact audio remains available between receipt stages; STOP, capability, ownership, expiry and asset checks remain enforced.\n";
