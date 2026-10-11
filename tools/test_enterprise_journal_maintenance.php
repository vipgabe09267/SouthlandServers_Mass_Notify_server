<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/EnterpriseIntegrationsService.php';
require __DIR__.'/../slsmassnotifyserver/EnterpriseOperationsStore.php';
use SLS\MassNotify\{EnterpriseIntegrationsStore,EnterpriseIntegrationsService,EnterpriseIntegrationsProvider,EnterpriseIntegrationsConfig,EnterpriseOperationsStore};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $work):void{try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Expected replay fence rejection');}
class JournalProviderFixture extends EnterpriseIntegrationsProvider{public int $calls=0;protected function request(string $url,string $body,array $headers,?array $device=null):array{$this->calls++;return ['status'=>200,'error'=>0,'body'=>'{}'];}}
$root=sys_get_temp_dir().'/sls-journal-'.bin2hex(random_bytes(8));mkdir($root,0700);$now=time();
try{
    $integrations=new EnterpriseIntegrationsStore($root.'/integrations');$provider=new JournalProviderFixture();$clock=$now-90000;$service=new EnterpriseIntegrationsService($integrations,$provider,fn()=>$clock);
    $config=EnterpriseIntegrationsConfig::defaults()['access_control'];$door=['id'=>'door_'.str_repeat('a',24),'name'=>'Test door','token'=>'local-token','allow_lock'=>true];$config=array_replace($config,['enabled'=>'1','origin'=>'https://device.example.test','pinned_ipv4'=>'192.168.10.10','username'=>'fixture','password'=>'fixture','doors'=>[$door]]);
    $input=['request_id'=>str_repeat('1',32),'door_id'=>$door['id'],'operation'=>'lock'];$first=$service->door($config,$input);check($provider->calls===1,'Initial provider operation failed');
    $integrations->transaction(static function(array &$state)use($now):void{foreach(['submitting','uncertain'] as $index=>$status){$id=str_repeat((string)($index+2),32);$state['operations'][$id]=['request_id'=>$id,'kind'=>'door','fingerprint'=>str_repeat('b',64),'state'=>$status,'created_at'=>$now-90000];}});
    $result=$integrations->maintain($now,86400);check($result['archived']['operations']===1,'Terminal operation was not archived');check(count($integrations->read()['operations'])===2,'Pending or uncertain evidence was discarded');
    check($service->door($config,$input)===$first&&$provider->calls===1,'Archived request repeated physical actuation');
    $different=$config;$different['doors'][0]['token']='different';reject(fn()=>$service->door($different,$input));
    check($integrations->archiveReport('operations')[$input['request_id']]===$first,'Archived report lost original receipt');
    $operations=new EnterpriseOperationsStore($root.'/operations');$approval='approval_'.str_repeat('3',32);$drill='run_'.str_repeat('4',32);
    $operations->transaction(static function(array &$state)use($approval,$drill,$now):void{
        $state['approvals'][$approval]=['state'=>'submitted','created_at'=>$now-90000,'expires_at'=>$now-80000];
        $state['approvals']['pending']=['state'=>'uncertain','created_at'=>$now-90000,'expires_at'=>$now-80000];
        $state['drills'][$drill]=['state'=>'queued','created_at'=>gmdate('c',$now-90000),'reviews'=>[['completed'=>true]]];
        $state['drills']['unreviewed']=['state'=>'queued','created_at'=>gmdate('c',$now-90000),'reviews'=>[]];
    });
    $archived=$operations->maintain($now,86400);check($archived['archived']===['approvals'=>1,'drills'=>1],'Coordination archive eligibility incorrect');check(isset($operations->transaction(static fn(array &$state)=>$state)['approvals']['pending'],$operations->transaction(static fn(array &$state)=>$state)['drills']['unreviewed']),'Uncertain or unreviewed work disappeared');check($operations->archived('drills',$drill)!==null,'Drill report unavailable after archival');
    // Loss of an established archive must fence replay, not recreate empty history.
    unlink($root.'/integrations/archive/operations.json');reject(fn()=>$service->door($config,$input));check($provider->calls===1,'Missing archive repeated side effect');
    echo "Journal maintenance: terminal archival, durable receipts, no replay, conflict rejection, uncertain retention, drill reports and missing-archive fences passed.\n";
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);}
