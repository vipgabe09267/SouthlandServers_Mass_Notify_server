<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/AutomationService.php';
require_once __DIR__.'/../slsmassnotifyserver/OperatorLoginRate.php';
use SLS\MassNotify\{AutomationConfig,AutomationStore,AutomationService,OperatorLoginRate};
function historyCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function historyReject(callable $operation):void{try{$operation();}catch(RuntimeException|JsonException $error){return;}throw new RuntimeException('Damaged established history was accepted.');}
$parent=sys_get_temp_dir().'/sls-runtime-history-'.bin2hex(random_bytes(8));mkdir($parent,0700);
register_shutdown_function(static function()use($parent):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($parent,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
    }rmdir($parent);
});
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Private action','enabled'=>true,'kind'=>'brightsign_udp','host'=>'192.168.1.20','port'=>5000,'message'=>'fixture']);
$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Private sensor','enabled'=>true,'kind'=>'sensor','secret'=>str_repeat('c',64),'event'=>'smoke','action_ids'=>[$action['id']],'allow_tests'=>true]);
$settings=['runtime_enabled'=>true,'automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[$action]]];$calls=0;$now=time();
$directory=$parent.'/automations';$store=new AutomationStore($directory);
$service=new AutomationService($store,static fn()=>$settings,static fn()=>[],static function()use(&$calls):array{++$calls;return ['state'=>'submitted'];},static fn()=>$now);
$input=['request_id'=>'private-event','sent_at'=>$now,'expires_at'=>$now+300,'event'=>'smoke','message'=>'Fixture only','is_test'=>true];
$event=$service->activate($rule['id'],$input,'sensor');$service->process($event['id']);historyCheck($calls===1,'Private action did not complete once.');
$path=$directory.'/worker-state.json';$saved=file_get_contents($path);$marker=$parent.'/.automations-required';$fence=file_get_contents($marker);
unlink($path);historyReject(static fn()=>$service->activate($rule['id'],$input,'sensor'));historyCheck($calls===1&&!file_exists($path),'Lost journal was reset or action repeated.');
file_put_contents($path,$saved);chmod($path,0640);unlink($marker);historyReject(static fn()=>$store->read());
file_put_contents($marker,$fence);chmod($marker,0600);$service->process($event['id']);historyCheck($calls===1,'Restored history replayed the action.');
rename($directory,$parent.'/retained-history');historyReject(static fn()=>new AutomationStore($directory));historyCheck(!is_dir($directory),'Lost directory was recreated.');rename($parent.'/retained-history',$directory);
// Valid legacy history gains metadata before effects; contents and mutex inode survive.
$legacy=json_decode($saved,true);unset($legacy['replay_fence_required']);file_put_contents($path,json_encode($legacy));unlink($marker);
$inode=fileinode($directory.'/announcement-send.lock');$migrated=new AutomationStore($directory);
$migrated->transaction(static fn(array &$state)=>count($state['events']));
historyCheck($migrated->read()['events']===$legacy['events']&&fileinode($directory.'/announcement-send.lock')===$inode,'Legacy enrollment changed event history or replaced its mutex.');
foreach(range(0,2) as $stage){
    $base=$parent.'/initial-'.$stage;mkdir($base,0700);$folder=$base.'/automations';mkdir($folder,0700);
    $initial=['schema'=>1,'events'=>[],'challenges'=>[],'cooldowns'=>[],'invalidated'=>[],'replay_fence_required'=>true];
    $intent="SLS_AUTOMATION_INIT_V1\n".hash('sha256',json_encode($initial,JSON_THROW_ON_ERROR))."\n";
    file_put_contents($folder.'/initialization.pending',$intent);chmod($folder.'/initialization.pending',0600);
    if($stage>=1){file_put_contents($folder.'/worker-state.json',json_encode($initial));chmod($folder.'/worker-state.json',0640);}
    if($stage>=2){file_put_contents($base.'/.automations-required',$fence);chmod($base.'/.automations-required',0600);}
    $recovering=new AutomationStore($folder);$recovering->transaction(static fn(array &$state)=>count($state['events']));
    historyCheck($recovering->read()===$initial&&!file_exists($folder.'/initialization.pending'),'Empty first initialization did not resume safely.');
}
$parallel=$parent.'/parallel';mkdir($parallel,0700);$children=[];$childScript=$parent.'/concurrent.php';
$childCode=<<<'CHILD'
$store=new SLS\MassNotify\AutomationStore($argv[1]);$index=$argv[2];
for($attempt=0;$attempt<30;$attempt++){
    try{$store->transaction(static function(array &$state)use($index):void{$state['cooldowns']['trg_'.str_repeat($index,24)]=time()+60;});exit(0);}
    catch(RuntimeException $error){if(!str_contains($error->getMessage(),'busy')){fwrite(STDERR,'Private initialization failed.');exit(1);}usleep(10000);}
}
exit(1);
CHILD;
file_put_contents($childScript,'<?php require '.var_export(__DIR__.'/../slsmassnotifyserver/AutomationStore.php',true).';'.$childCode);
for($i=0;$i<4;$i++){
    $process=proc_open([PHP_BINARY,$childScript,$parallel.'/automations',(string)$i],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    historyCheck(is_resource($process),'Private worker failed to start.');$children[]=[$process,$pipes];
}
foreach($children as [$process,$pipes]){stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);historyCheck(proc_close($process)===0,'Concurrent first initialization failed.');}
historyCheck(count((new AutomationStore($parallel.'/automations'))->read()['cooldowns'])===4,'Concurrent initialization lost private state.');
$rate=$parent.'/rate.json';$ip='192.0.2.1';
for($i=0;$i<6;$i++){$grant=OperatorLoginRate::begin($rate,$ip,$now);OperatorLoginRate::finish($rate,$ip,$grant['reservation'],true,$now);}
historyCheck(OperatorLoginRate::begin($rate,$ip,$now+1)['retry_after']===299,'Failure window was not established.');
$rateSaved=file_get_contents($rate);unlink($rate);historyReject(static fn()=>OperatorLoginRate::begin($rate,$ip,$now+1));historyCheck(!file_exists($rate),'Lost failure history was reset.');
file_put_contents($rate,$rateSaved);chmod($rate,0640);$lock=$rate.'.lock';$rateInode=fileinode($lock);
file_put_contents($lock,'');historyCheck(OperatorLoginRate::begin($rate,$ip,$now+1)['retry_after']===299&&fileinode($lock)===$rateInode,'Legacy rate migration reset the budget or mutex.');
file_put_contents($rate,'{"schema":1,"last_time":0,"last_time":1,"ips":[]}');historyReject(static fn()=>OperatorLoginRate::begin($rate,$ip,$now));
echo "Trigger/failure history: missing state and markers, retained legacy bytes/inodes, empty initialization recovery, and duplicate JSON checks passed; no transport was used.\n";
