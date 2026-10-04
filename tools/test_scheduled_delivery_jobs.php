<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
class ScheduledDeliveryFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $directory, $started=[], $admitted=[], $defer=false, $sent=[], $settings=[], $email=[];
    public function __construct($directory){$this->directory=$directory;}
    private function announcementJobDirectory(){return $this->directory;}
    private function startAnnouncementWorker($id){$this->started[]=$id;return true;}
    private function admitScheduledAnnouncementRequest(array $request):array{$this->admitted[]=$request;return ['success'=>!$this->defer,'deferred'=>$this->defer];}
    private function recordInteractiveAnnouncementAdmission(array $request):void{}
    private function acquireAnnouncementActivityLock($exclusive=false,$timeout=30){return null;}
    private function releaseNativeBackupFileLock($handle){}
    private function currentAnnouncementDestinationIds(){return ['phones'=>[], 'desktops'=>[], 'webhooks'=>[]];}
    private function getActiveSettings(){return $this->settings;}
    private function getAnnouncementCooldownState(){return ['remaining'=>0];}
    private function sanitizeScheduleText($value,$limit,$single){return $value;}
    private function appendAnnouncementNotifyLog(...$args){}
    private function dispatchAnnouncementWebhooks(...$args){return ['accepted'=>[],'failed'=>[]];}
    protected function executeAnnouncementPhoneVisualBatch(array $commands,callable $completed,?int $deadline=null):void{}
    private function announcementEmailTargets($settings){return $this->email;}
    private function announcementEmailFingerprint($target){return hash('sha256',json_encode([$target['id'],$target['address'],$target['sender']]));}
    protected function submitAnnouncementEmail(array $envelope):array{$this->sent[]=$envelope;return ['state'=>'accepted','retryable'=>false,'detail'=>'inert accepted'];}
    public function build($request,$context){return $this->buildScheduledAnnouncementJob($request,$context);}
    public function persist($job){return $this->persistPreparedScheduledAnnouncementJob($job);}
    public function read($record){return $this->readScheduledAnnouncementJob($record);}
    public function activate($record){return $this->activateScheduledAnnouncementJob($record);}
}
$n=0;
function ensure($value,$message){global $n;++$n;if(!$value)throw new RuntimeException($message);}
function rejected(callable $callback,$message){try{$callback();}catch(Throwable $e){ensure(true,$message);return;}throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/sls-scheduled-job-'.bin2hex(random_bytes(8));mkdir($root,0700);
function context($due,$suffix='a'){$schedule='sched_'.str_repeat($suffix,20);$run=gmdate('Y-m-d\TH:i:s\Z',$due);return ['version'=>1,'schedule_id'=>$schedule,'occurrence_id'=>'occ_'.substr(hash('sha256',$schedule.'|'.$run),0,20),'run_at_utc'=>$run,'deadline_at'=>$due+900];}
function record($job){return array_merge($job['request']['schedule_context'],['job_id'=>$job['id'],'request_fingerprint'=>$job['request_fingerprint']]);}
try{
    $m=new ScheduledDeliveryFixture($root);$store=new SlsAnnouncementJobStore($root);
    $request=['message'=>'Frozen message','title'=>'Notice','sender'=>'Scheduler','phones'=>[],'desktops'=>[],'webhooks'=>[],
        'voice_recipient_ids'=>[],'email_recipient_ids'=>[],'audio_mode'=>'none','timeout_mode'=>'fixed','display_timeout'=>10,
        'image'=>false,'background_color'=>'#ffffff','trigger_source'=>'schedule','is_test'=>false];
    $ctx=context(time());$job=$m->build($request,$ctx);$link=record($job);
    ensure($job['state']==='prepared' && $m->started===[] && $m->admitted===[],'Builder executed side effects');
    ensure($m->build($request,$ctx)['id']===$job['id'],'Deterministic occurrence ID changed');
    ensure($m->read($link)===null,'Missing prepared job not distinguishable');
    $m->persist($job);ensure($store->pendingIds()===[$job['id']],'Prepared job omitted from admission/installer pending index');
    ensure($m->persist($job)===$job,'Identical preparation not idempotent');
    $modified=$job;$modified['request']['message']='Changed';rejected(fn()=>$m->persist($modified),'Corrupt snapshot accepted');
    $modified['request_fingerprint']=SlsAnnouncementJobStore::requestFingerprint($modified['request']);
    rejected(fn()=>$m->persist($modified),'Conflicting preparation overwritten');
    ensure($m->read($link)['request']['message']==='Frozen message','Prepared snapshot changed');
    $m->processAnnouncementJobs($job['id']);ensure($store->read($job['id'])['state']==='prepared','Worker executed prepared job');
    $old=$job;$old['created_at']=gmdate('c',time()-60);$store->write($old);$store->reconcile($job['id']);
    ensure($store->read($job['id'])['state']==='prepared','Ordinary30sec reconciliation failed intentional prepared deferral');
    $m->defer=true;$result=$m->activate($link);ensure(!empty($result['deferred']) && $m->started===[],'Deferred admission launched worker');
    $m->defer=false;$result=$m->activate($link);ensure($result['state']==='queued' && count($m->started)===1,'Activation did not queue same job');
    $m->activate($link);ensure(count($m->started)===1,'Queued occurrence started again');
    $before=$store->read($job['id'])['request'];$m->processAnnouncementJobs($job['id']);
    ensure($store->read($job['id'])['request']===$before,'Worker mutated fingerprinted request');
    $m->activate($link);ensure(count($m->started)===1,'Terminal job restarted');
    $badLink=$link;$badLink['request_fingerprint']=str_repeat('0',64);rejected(fn()=>$m->read($badLink),'Wrong ledger fingerprint accepted');
    $expired=$m->build($request,context(time()-901,'b'));$m->persist($expired);$m->activate(record($expired));
    ensure($store->read($expired['id'])['state']==='expired' && count($m->started)===1,'Expired prepared job activated');
    $running=$m->build($request,context(time(),'c'));$m->persist($running);$running['state']='running';$store->write($running);$store->reconcile($running['id']);
    ensure(!empty($store->read($running['id'])['submission_uncertain']),'Lost running worker was replayable');
    $fault=$m->build($request,context(time(),'d'));unlink($root.'/worker-state.json');mkdir($root.'/worker-state.json');
    rejected(fn()=>$m->persist($fault),'Post-job persistence fault hidden');
    ensure($store->read($fault['id'])['request_fingerprint']===$fault['request_fingerprint'],'Committed preparation lost after later write failure');
    rmdir($root.'/worker-state.json');ensure($m->persist($fault)===$fault,'Retry preparation did not recover exact committed snapshot');
    foreach([['deadline_at'=>$ctx['deadline_at']+1],['version'=>2],['occurrence_id'=>'occ_'.str_repeat('0',20)]]as $patch){
        rejected(fn()=>$m->build($request,array_replace($ctx,$patch)),'Invalid context accepted');
    }
    $id='email_'.str_repeat('e',24);$target=['id'=>$id,'address'=>'test@example.com','sender'=>['name'=>'Fixture','address'=>'notify@example.com']];
    $m->email=[$id=>$target];$mail=$request;$mail['email_recipient_ids']=[$id];
    $mail['email_targets']=[$id=>$target+['fingerprint'=>(new ReflectionMethod($m,'announcementEmailFingerprint'))->invoke($m,$target)]];
    $emailJob=$m->build($mail,context(time(),'e'));ensure($emailJob['request']['email_expires_at']===$emailJob['request']['schedule_context']['deadline_at'],'Email deadline extended');
    $m->persist($emailJob);$emailJob['state']='queued';$store->write($emailJob);$m->processAnnouncementJobs($emailJob['id']);
    ensure(count($m->sent)===1 && $store->read($emailJob['id'])['state']==='complete','Scheduled immutable email worker failed: '.json_encode($store->read($emailJob['id'])['result'] ?? []));
    ensure($store->read($emailJob['id'])['request']===$emailJob['request'],'Email attempt mutated prepared snapshot');
    echo "Scheduled durable delivery: $n checks passed; no real transports.\n";
}finally{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
