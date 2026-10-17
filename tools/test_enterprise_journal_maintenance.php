<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/EnterpriseIntegrationsService.php';
require __DIR__.'/../slsmassnotifyserver/EnterpriseOperationsStore.php';
require __DIR__.'/../slsmassnotifyserver/EnterpriseApprovalService.php';
use SLS\MassNotify\{EnterpriseIdentityStore,EnterpriseIntegrationsStore,EnterpriseIntegrationsService,EnterpriseIntegrationsProvider,EnterpriseIntegrationsConfig,EnterpriseOperationsConfig,EnterpriseOperationsStore,EnterpriseApprovalService};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $work):void{try{$work();}catch(DomainException|RuntimeException $error){check($error->getMessage()!=='','Replay fence rejection lacked a diagnostic.');return;}throw new RuntimeException('Expected replay fence rejection');}
function journalRead(string $path):string{$bytes=file_get_contents($path);if(!is_string($bytes)){throw new RuntimeException('Unable to read enterprise journal fixture evidence.');}return $bytes;}
function journalStat(string $path):array{$stat=stat($path);if(!is_array($stat)){throw new RuntimeException('Unable to inspect enterprise journal fixture evidence.');}return $stat;}
function journalCleanup(string $root,bool $strict):void
{
    if(!file_exists($root)&&!is_link($root)){return;}
    if(!is_dir($root)||is_link($root)){if($strict){throw new RuntimeException('Unsafe enterprise-journal cleanup root.');}return;}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){$removed=$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());if(!$removed&&$strict){throw new RuntimeException('Unable to remove an enterprise-journal fixture entry.');}}
    if(!rmdir($root)&&$strict){throw new RuntimeException('Unable to remove the enterprise-journal fixture root.');}
}
class JournalProviderFixture extends EnterpriseIntegrationsProvider{public int $calls=0;protected function request(string $url,string $body,array $headers,?array $device=null):array{$this->calls++;return ['status'=>200,'error'=>0,'body'=>'{}'];}}
$root=sys_get_temp_dir().'/sls-journal-'.bin2hex(random_bytes(8));$now=time();$passed=false;
try{
    check(mkdir($root,0700)&&chmod($root,0700),'Unable to create the private enterprise-journal fixture.');
    $integrations=new EnterpriseIntegrationsStore($root.'/integrations');$provider=new JournalProviderFixture();$clock=$now-90000;$service=new EnterpriseIntegrationsService($integrations,$provider,fn()=>$clock);
    $config=EnterpriseIntegrationsConfig::defaults()['access_control'];$door=['id'=>'door_'.str_repeat('a',24),'name'=>'Test door','token'=>'local-token','allow_lock'=>true];$config=array_replace($config,['enabled'=>'1','origin'=>'https://device.example.test','pinned_ipv4'=>'192.168.10.10','username'=>'fixture','password'=>'fixture','doors'=>[$door]]);
    $input=['request_id'=>str_repeat('1',32),'door_id'=>$door['id'],'operation'=>'lock'];$first=$service->door($config,$input);check($provider->calls===1,'Initial provider operation failed');
    $integrations->transaction(static function(array &$state)use($now):void{foreach(['submitting','uncertain'] as $index=>$status){$id=str_repeat((string)($index+2),32);$row=['request_id'=>$id,'kind'=>'door','fingerprint'=>str_repeat('b',64),'state'=>$status,'created_at'=>$now-90000];if($status==='uncertain'){$row+=['detail'=>'Fixture uncertainty retained for review.','finished_at'=>$now-89999];}$state['operations'][$id]=$row;}});
    $result=$integrations->maintain($now,86400,86400);check($result['archived']['operations']===1,'Terminal operation was not archived');check(count($integrations->read()['operations'])===2,'Pending or uncertain evidence was discarded');
    $integrationJournal=$root.'/integrations/worker-state.json';clearstatcache(true,$integrationJournal);$integrationBefore=journalStat($integrationJournal);$integrationBytes=journalRead($integrationJournal);
    for($i=0;$i<10;$i++){check($service->door($config,$input)===$first&&$provider->calls===1,'Archived request repeated physical actuation');}
    clearstatcache(true,$integrationJournal);$integrationAfter=journalStat($integrationJournal);check($integrationAfter['ino']===$integrationBefore['ino']&&journalRead($integrationJournal)===$integrationBytes,'Read-only provider retries rewrote the integration journal');
    $different=$config;$different['doors'][0]['token']='different';reject(fn()=>$service->door($different,$input));
    check($integrations->archiveReport('operations')[$input['request_id']]===$first,'Archived report lost original receipt');
    $resolved=$integrations->resolveUncertain(str_repeat('3',32),'confirmed_completed','provider receipt fixture',hash('sha256','fixture administrator'),$now);
    check($resolved['state']==='resolved'&&$resolved['evidence_reference']==='provider receipt fixture'
        &&$resolved['finished_at']===$now-89999&&$resolved['resolved_at']===$now,'Reviewed uncertainty lost its original timestamp or resolution evidence');
    reject(fn()=>$integrations->resolveUncertain(str_repeat('2',32),'confirmed_completed','unsafe pending resolution',hash('sha256','fixture administrator'),$now));
    $compacted=$integrations->maintain($now,86400,86400);check($compacted['archived']['tombstoned_operations']===1&&$compacted['archived']['operations']===0,'A newly reviewed resolution was archived before its live retention window elapsed');
    $resolvedArchive=$integrations->maintain($now+86401,86400,86400);check($resolvedArchive['archived']['operations']===1,'Reviewed resolution did not archive after its terminal retention window');
    check($integrations->archived('operations',$input['request_id'])['state']==='archived','Replay tombstone was not returned as an explicit archived result');
    check(isset($integrations->tombstoneReport('operations')[$input['request_id']]),'Replay tombstone was omitted from private export evidence');
    check($service->door($config,$input)['state']==='archived'&&$provider->calls===1,'Tombstoned request repeated physical actuation');
    $resolvedReport=$integrations->archiveReport('operations')[str_repeat('3',32)]??null;check(($resolvedReport['state']??'')==='resolved','Reviewed resolution disappeared from full evidence');
    $tooRecent=str_repeat('4',32);$integrations->transaction(static function(array &$state)use($tooRecent,$now):void{$state['operations'][$tooRecent]=['request_id'=>$tooRecent,'kind'=>'door','fingerprint'=>str_repeat('c',64),'state'=>'uncertain','created_at'=>$now-30,'detail'=>'Recent uncertainty fixture.','finished_at'=>$now];});
    reject(fn()=>$integrations->resolveUncertain($tooRecent,'abandoned_unconfirmed','premature fixture review',hash('sha256','fixture administrator'),$now));
    $operations=new EnterpriseOperationsStore($root.'/operations');$approvalClock=$now-90000;
    $approvalService=new EnterpriseApprovalService($operations,static function()use(&$approvalClock):int{return $approvalClock;});
    $approvalPolicy=['id'=>'approval_'.str_repeat('3',24),'name'=>'Fixture review','enabled'=>true,'channels'=>['phones'],
        'approver_ids'=>['pbx:alice','pbx:bob'],'expires_seconds'=>300];
    $approvalSettings=['enterprise_operations'=>array_replace(EnterpriseOperationsConfig::defaults(),
        ['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[$approvalPolicy]]])];
    $approvalRequest=['title'=>'Fixture','message'=>'Archived fixture','phones'=>['1000']];
    $approval=$approvalService->request($approvalSettings,$approvalRequest,'pbx:alice','alice-identity')['review_id'];
    $approvalService->approve($approval,$approvalSettings,'pbx:bob',static fn():bool=>true,'bob-identity');
    $approvalService->claim($approval,$approvalSettings,'pbx:bob',static fn():bool=>true);
    $approvalService->finish($approval,['success'=>true,'job_id'=>'job_'.str_repeat('6',32)]);
    $pendingRequest=array_replace($approvalRequest,['message'=>'Uncertain fixture']);
    $pendingApproval=$approvalService->request($approvalSettings,$pendingRequest,'pbx:alice','alice-identity')['review_id'];
    $approvalService->approve($pendingApproval,$approvalSettings,'pbx:bob',static fn():bool=>true,'bob-identity');
    $approvalService->claim($pendingApproval,$approvalSettings,'pbx:bob',static fn():bool=>true);
    $approvalService->finish($pendingApproval,['success'=>false]);
    $drill='run_'.str_repeat('4',64);$unreviewedDrill='run_'.str_repeat('5',64);
    $operations->transaction(static function(array &$state)use($drill,$unreviewedDrill,$now):void{
        $state['drills'][$drill]=['id'=>$drill,'assignment'=>[],'state'=>'queued','created_at'=>gmdate('c',$now-90000),'reviews'=>[['completed'=>true]]];
        $state['drills'][$unreviewedDrill]=['id'=>$unreviewedDrill,'assignment'=>[],'state'=>'queued','created_at'=>gmdate('c',$now-90000),'reviews'=>[]];
    });
    $journal=$root.'/operations/worker-state.json';clearstatcache(true,$journal);$before=journalStat($journal);$original=journalRead($journal);
    for($i=0;$i<10;$i++){$operations->transaction(static fn(array &$state):array=>$state['drills']);}
    clearstatcache(true,$journal);$after=journalStat($journal);
    check($after['ino']===$before['ino']&&journalRead($journal)===$original,'Read-only operation reports rewrote the full journal');
    $operations->transaction(static function(array &$state)use($unreviewedDrill):void{$row=&$state['drills'][$unreviewedDrill];$row['note']='Persist this nested reference update';});
    clearstatcache(true,$journal);check(journalStat($journal)['ino']!==$before['ino'],'Changed operation state was not replaced durably');
    check($operations->transaction(static fn(array &$state):array=>$state['drills'])[$unreviewedDrill]['note']==='Persist this nested reference update','Nested state update disappeared');
    $archived=$operations->maintain($now,86400);check($archived['archived']===['approvals'=>1,'drills'=>1],'Coordination archive eligibility incorrect');check(isset($operations->transaction(static fn(array &$state)=>$state)['approvals'][$pendingApproval],$operations->transaction(static fn(array &$state)=>$state)['drills'][$unreviewedDrill]),'Uncertain or unreviewed work disappeared');check($operations->archived('drills',$drill)!==null,'Drill report unavailable after archival');
    // Project capacity before a provider effect and recover one slot only through
    // reviewed resolution plus archival.
    $capacityStore=new EnterpriseIntegrationsStore($root.'/capacity');$capacityProvider=new JournalProviderFixture();$capacityService=new EnterpriseIntegrationsService($capacityStore,$capacityProvider,fn()=>$now);
    $capacityIds=[];$capacityStore->transaction(static function(array &$state)use($now,&$capacityIds):void{for($i=0;$i<500;$i++){$id=substr(hash('sha256','capacity-'.$i),0,32);$capacityIds[]=$id;$state['operations'][$id]=['request_id'=>$id,'kind'=>'door','fingerprint'=>hash('sha256','fingerprint-'.$i),'state'=>'uncertain','created_at'=>$now-90000,'detail'=>'Capacity fixture remains uncertain.','finished_at'=>$now-89999];}});
    $newInput=array_replace($input,['request_id'=>str_repeat('f',32)]);reject(fn()=>$capacityService->door($config,$newInput));check($capacityProvider->calls===0,'Full live journal reached a provider before capacity refusal');
    $capacityStore->resolveUncertain($capacityIds[0],'confirmed_not_completed','provider ticket capacity fixture',hash('sha256','fixture administrator'),$now);
    $capacityStore->maintain($now+86401,86400,2592000);check($capacityService->door($config,$newInput)['state']==='accepted'&&$capacityProvider->calls===1,'Reviewed archival did not safely release one live operation slot');
    // A delayed provider result cannot erase an administrator's evidence-backed
    // resolution; it is retained as a late-outcome hash for another review.
    $lateStore=new EnterpriseIntegrationsStore($root.'/late');$lateClock=$now-600;$lateService=new EnterpriseIntegrationsService($lateStore,new JournalProviderFixture(),function()use(&$lateClock):int{return $lateClock;});$lateInput=array_replace($input,['request_id'=>str_repeat('e',32)]);
    $late=$lateService->door($config,$lateInput,function($send,$createdAt)use($lateStore,$lateInput,$door,$now,&$lateClock):array{
        $lateStore->transaction(static function(array &$state)use($lateInput,$lateClock):void{$row=&$state['operations'][$lateInput['request_id']];$row['state']='uncertain';$row['detail']='Fixture delayed provider result.';$row['finished_at']=$lateClock;});
        $lateClock=$now;$lateStore->resolveUncertain($lateInput['request_id'],'abandoned_unconfirmed','provider review before delayed result',hash('sha256','fixture administrator'),$now);
        return ['state'=>'accepted','door_id'=>$door['id'],'operation'=>'lock','detail'=>'Delayed accepted fixture.'];
    });
    check($late['state']==='resolved'&&preg_match('/^[a-f0-9]{64}$/D',$late['late_outcome_hash']??'')&&$late['finished_at']===$now-600
        &&$late['resolved_at']===$now&&$late['late_outcome_at']===$now&&str_contains($late['detail'],'late provider result'),'Late provider result erased reviewed resolution or its event timestamps: '.json_encode($late));
    // A valid row placed in a mismatched shard is corruption, not a replay miss.
    $wrong=new EnterpriseIntegrationsStore($root.'/wrong');$wrongArchive=new EnterpriseIdentityStore($root.'/wrong/archive');
    $wrongArchive->transaction('operations_ff',static function(array &$rows)use($first):void{$rows[$first['request_id']]=$first;});reject(fn()=>$wrong->archiveReport('operations'));
    // Loss of an established tombstone shard must fence replay, not recreate empty history.
    check(unlink($root.'/integrations/archive/operation_tombstones_11.json'),'Unable to remove the tombstone-shard fixture.');reject(fn()=>$service->door($config,$input));check($provider->calls===1,'Missing tombstone shard repeated side effect');
    $passed=true;echo "Journal maintenance: sharded full evidence, durable replay tombstones, reviewed uncertainty, projected capacity, no replay, conflict rejection, drill reports and missing-archive fences passed.\n";
}finally{journalCleanup($root,$passed);}
