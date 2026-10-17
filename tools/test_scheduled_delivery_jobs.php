<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
    if(!(error_reporting()&$severity)){return false;}
    throw new ErrorException($message,0,$severity,$file,$line);
});
class ScheduledDeliveryFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $directory, $admissionDirectory, $started=[], $admitted=[], $defer=false, $sent=[], $settings=[], $email=[];
    public $destinations=['phones'=>[], 'desktops'=>[], 'webhooks'=>[]], $weatherStatus='eligible', $weatherStatuses=[], $webhookDeliveryIds=[], $webhookCalls=[];
    public function __construct($directory,$admissionDirectory){$this->directory=$directory;$this->admissionDirectory=$admissionDirectory;}
    private function announcementJobDirectory(){return $this->directory;}
    protected function announcementAdmissionDirectory():string{return $this->admissionDirectory;}
    private function startAnnouncementWorker($id){$this->started[]=$id;return true;}
    private function admitScheduledAnnouncementRequest(array $request):array{$this->admitted[]=$request;return ['success'=>!$this->defer,'deferred'=>$this->defer];}
    private function commitScheduledAnnouncementAdmission(array $request,callable $commit):array{
        $this->admitted[]=$request;
        if($this->defer){return ['success'=>false,'deferred'=>true];}
        return ['success'=>true,'value'=>$commit()];
    }
    private function recordInteractiveAnnouncementAdmission(array $request,?callable $commit=null,string $jobId=''){
        return $commit===null?null:$commit();
    }
    private function acquireAnnouncementActivityLock($exclusive=false,$timeout=30){return null;}
    private function releaseNativeBackupFileLock($handle){}
    private function currentAnnouncementDestinationIds(){return $this->destinations;}
    private function getActiveSettings(){return $this->settings;}
    private function getAnnouncementCooldownState(){return ['remaining'=>0];}
    private function sanitizeScheduleText($value,$limit,$single){return $value;}
    private function appendAnnouncementNotifyLog(...$args){}
    private function dispatchAnnouncementWebhooks(...$args){
        $this->webhookDeliveryIds[]=$args[5]??null;
        $this->webhookCalls[]=['targets'=>$args[0]??[],'delivery_id'=>$args[5]??null];
        return ['accepted'=>array_map(static fn($id)=>['id'=>$id,'name'=>$id,'http_status'=>202],$args[0]??[]),'failed'=>[]];
    }
    private function weatherChannelAuthorization(array $context):array{
        $status=$this->weatherStatuses?array_shift($this->weatherStatuses):$this->weatherStatus;
        return ['status'=>$status,'voice_recipient_ids'=>[],'sms_recipient_ids'=>[]];
    }
    private function refreshAnnouncementSmsReceipts(array $result):array{return $result;}
    protected function executeAnnouncementPhoneVisualBatch(array $commands,callable $completed,?int $deadline=null):void{}
    private function announcementEmailTargets($settings){return $this->email;}
    private function announcementEmailFingerprint($target){return hash('sha256',json_encode([$target['id'],$target['address'],$target['sender']],JSON_THROW_ON_ERROR));}
    protected function submitAnnouncementEmail(array $envelope):array{$this->sent[]=$envelope;return ['state'=>'accepted','retryable'=>false,'detail'=>'inert accepted'];}
    public function build($request,$context){return $this->buildScheduledAnnouncementJob($request,$context);}
    public function persist($job){return $this->persistPreparedScheduledAnnouncementJob($job);}
    public function read($record){return $this->readScheduledAnnouncementJob($record);}
    public function activate($record){return $this->activateScheduledAnnouncementJob($record);}
}
$n=0;
function ensure(bool $value,string $message):void{global $n;++$n;if(!$value)throw new RuntimeException($message);}
function rejected(callable $callback,string $message):void{
    try{$callback();}
    catch(Throwable $error){
        ensure($error instanceof DomainException||$error instanceof InvalidArgumentException||$error instanceof RuntimeException,
            $message.' (unexpected '.get_class($error).')');
        ensure($error->getMessage()!=='',$message.' (empty diagnostic)');
        return;
    }
    throw new RuntimeException($message);
}
$root=sys_get_temp_dir().'/sls-scheduled-job-'.bin2hex(random_bytes(8));$created=false;
function context(int $due,string $suffix='a'):array{$schedule='sched_'.str_repeat($suffix,20);$run=gmdate('Y-m-d\TH:i:s\Z',$due);return ['version'=>1,'schedule_id'=>$schedule,'occurrence_id'=>'occ_'.substr(hash('sha256',$schedule.'|'.$run),0,20),'run_at_utc'=>$run,'deadline_at'=>$due+900];}
function record(array $job):array{return array_merge($job['request']['schedule_context'],['job_id'=>$job['id'],'request_fingerprint'=>$job['request_fingerprint']]);}
try{
    ensure(mkdir($root,0700),'Private scheduled-job fixture could not be created.');$created=true;
    ensure(chmod($root,0700),'Private scheduled-job fixture permissions could not be set.');
    $jobs=$root.'/announcement-jobs';ensure(mkdir($jobs,0700),'Private scheduled-job directory could not be created.');
    $m=new ScheduledDeliveryFixture($jobs,$root);$store=new SlsAnnouncementJobStore($jobs);
    $request=['message'=>'Frozen message','title'=>'Notice','sender'=>'Scheduler','phones'=>[],'desktops'=>[],'webhooks'=>[],
        'voice_recipient_ids'=>[],'email_recipient_ids'=>[],'audio_mode'=>'none','timeout_mode'=>'fixed','display_timeout'=>10,
        'image'=>false,'background_color'=>'#ffffff','trigger_source'=>'schedule','is_test'=>false];
    $ctx=context(time());$job=$m->build($request,$ctx);$link=record($job);
    ensure(($job['state']??null)==='prepared' && $m->started===[] && $m->admitted===[],'Builder executed side effects');
    ensure(($m->build($request,$ctx)['id']??null)===($job['id']??null),'Deterministic occurrence ID changed');
    ensure($m->read($link)===null,'Missing prepared job not distinguishable');
    $m->persist($job);ensure($store->pendingIds()===[$job['id']],'Prepared job omitted from admission/installer pending index');
    ensure($m->persist($job)===$job,'Identical preparation not idempotent');
    $modified=$job;$modified['request']['message']='Changed';rejected(fn()=>$m->persist($modified),'Corrupt snapshot accepted');
    $modified['request_fingerprint']=SlsAnnouncementJobStore::requestFingerprint($modified['request']);
    rejected(fn()=>$m->persist($modified),'Conflicting preparation overwritten');
    $prepared=$m->read($link);ensure(($prepared['request']['message']??null)==='Frozen message','Prepared snapshot changed');
    $gate=(new SlsAnnouncementJobStore($root))->admissionLock();ensure(is_resource($gate),'Shared queue-gate fixture could not acquire the admission lock.');
    $gated=$m->build($request,context(time(),'f'));
    try{rejected(fn()=>$m->persist($gated),'Scheduled preparation bypassed the shared queue gate');}
    finally{SlsAnnouncementJobStore::unlock($gate);}
    ensure($store->read($gated['id'])===null,'Rejected scheduled preparation left a runnable or prepared job.');
    ensure($m->persist($gated)===$gated,'Scheduled preparation did not recover after queue-gate contention.');
    $m->processAnnouncementJobs($job['id']);$prepared=$store->read($job['id']);ensure(($prepared['state']??null)==='prepared','Worker executed prepared job');
    $old=$job;$old['created_at']=gmdate('c',time()-60);$store->write($old);$store->reconcile($job['id']);
    $prepared=$store->read($job['id']);ensure(($prepared['state']??null)==='prepared','Ordinary 30-second reconciliation failed intentional prepared deferral');
    $m->defer=true;$result=$m->activate($link);ensure(!empty($result['deferred']) && $m->started===[],'Deferred admission launched worker');
    $m->defer=false;$result=$m->activate($link);ensure(($result['state']??null)==='queued' && count($m->started)===1,'Activation did not queue same job');
    $m->activate($link);ensure(count($m->started)===1,'Queued occurrence started again');
    $queued=$store->read($job['id']);ensure(is_array($queued['request']??null),'Queued request snapshot is unavailable.');$before=$queued['request'];$m->processAnnouncementJobs($job['id']);
    $completed=$store->read($job['id']);ensure(($completed['request']??null)===$before,'Worker mutated fingerprinted request');
    ensure(!array_key_exists('receipts',$completed['result']??[]),'Completed job duplicated its receipt journal inside result metadata.');
    $m->activate($link);ensure(count($m->started)===1,'Terminal job restarted');
    $weatherExpired=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'queued','created_at'=>gmdate('c'),
        'request'=>$request+['weather_context'=>['deadline_at'=>time()-1]],'receipts'=>[],'message'=>'queued'];
    $store->write($weatherExpired);ensure($m->processAnnouncementJobs($weatherExpired['id'])===false,'Expired weather job reported worker success.');
    $weatherExpiredState=$store->read($weatherExpired['id']);
    ensure(($weatherExpiredState['state']??null)==='expired'&&($weatherExpiredState['failure_category']??null)==='job_expired','Expired weather job did not retain its terminal category.');
    $weatherCancelled=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'queued','created_at'=>gmdate('c'),
        'request'=>$request+['weather_context'=>['deadline_at'=>time()+900]],'receipts'=>[],'message'=>'queued'];
    $m->weatherStatus='cancelled';$store->write($weatherCancelled);
    ensure($m->processAnnouncementJobs($weatherCancelled['id'])===true,'Cancelled weather job was reported as a worker fault.');
    $weatherCancelledState=$store->read($weatherCancelled['id']);
    ensure(($weatherCancelledState['state']??null)==='cancelled'&&($weatherCancelledState['failure_category']??null)==='weather_source_cancelled','Cancelled weather job lost its explicit terminal state or source category.');
    $m->weatherStatus='eligible';
    $webhookId='webhook_'.str_repeat('a',24);$m->destinations['webhooks']=[$webhookId];
    $weatherRevoked=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'queued','created_at'=>gmdate('c'),
        'request'=>array_replace($request,['webhooks'=>[$webhookId],'weather_context'=>['deadline_at'=>time()+900]]),'receipts'=>[],'message'=>'queued'];
    $m->weatherStatuses=['eligible','cancelled'];$store->write($weatherRevoked);
    ensure($m->processAnnouncementJobs($weatherRevoked['id'])===false,'Mid-delivery weather cancellation reported success.');
    $weatherRevokedState=$store->read($weatherRevoked['id']);$weatherReceipts=$weatherRevokedState['receipts']??[];
    ensure(count($weatherReceipts)===1&&($weatherReceipts[0]['channel']??null)==='webhook'&&($weatherReceipts[0]['state']??null)==='cancelled','Mid-delivery weather cancellation did not exclude the remaining webhook.');
    ensure((end($m->webhookCalls)['targets']??null)===[],'Cancelled weather webhook reached the dispatcher.');
    $legacy=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'queued','created_at'=>gmdate('c'),
        'request'=>array_replace($request,['webhooks'=>[$webhookId]]),'receipts'=>[],'message'=>'queued'];
    $store->write($legacy);ensure($m->processAnnouncementJobs($legacy['id'])===true,'Legacy queued webhook job did not complete.');
    ensure(end($m->webhookDeliveryIds)==='announcement-'.substr($legacy['id'],4),'Legacy job derived a malformed delivery correlation identity.');
    $m->destinations=['phones'=>['1000'],'desktops'=>[],'webhooks'=>[]];
    $audioRequest=array_replace($request,['phones'=>['1000'],'audio_mode'=>'tts','voice'=>'en_US-lessac-medium','opening_tone'=>'','closing_tone'=>'','volume'=>1]);
    $audioJob=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'queued','created_at'=>gmdate('c'),'request'=>$audioRequest,'receipts'=>[],'message'=>'queued'];
    $store->write($audioJob);ensure($m->processAnnouncementJobs($audioJob['id'])===false,'Unknown audio submission outcome reported success.');
    $audioState=$store->read($audioJob['id']);$audioReceipts=array_values(array_filter($audioState['receipts']??[],static fn($row)=>($row['channel']??'')==='audio'));
    ensure(count($audioReceipts)===1&&($audioReceipts[0]['state']??null)==='uncertain'&&($audioReceipts[0]['retryable']??null)===false,'Audio exception remained replayable despite an unknown submission outcome.');
    $expiredSms=['id'=>'job_'.bin2hex(random_bytes(16)),'state'=>'failed','created_at'=>gmdate('c'),
        'failure_category'=>'channel_submission_failed','request'=>array_replace($request,['sms_recipient_ids'=>['sms_'.str_repeat('b',24)],'sms_expires_at'=>time()-1]),
        'receipts'=>[['channel'=>'sms','target'=>'sms_'.str_repeat('b',24),'state'=>'failed','retryable'=>true]],'message'=>'failed'];
    $store->write($expiredSms);ensure(($m->getAnnouncementJob($expiredSms['id'])['retryable']??null)===false,'Expired SMS destination remained eligible for retry.');
    $projectionId='job_'.bin2hex(random_bytes(16));$authoritativeReceipt=['channel'=>'webhook','target'=>'reviewed','state'=>'accepted','retryable'=>false];
    $projection=['id'=>$projectionId,'state'=>'complete','created_at'=>gmdate('c'),'request'=>array_replace($request,['sender'=>'Reviewed sender']),
        'receipts'=>[$authoritativeReceipt],'message'=>'Reviewed completion','result'=>[
            'job_id'=>'job_'.str_repeat('0',32),'state'=>'failed','created_at'=>'forged','sender'=>'forged','receipts'=>[['channel'=>'audio','state'=>'failed']],
            'retryable'=>true,'failure_category'=>'channel_submission_failed','message'=>'forged','display_timeout_seconds'=>7,'desktop_published'=>true]];
    $store->write($projection);$projected=$m->getAnnouncementJob($projectionId);
    ensure(($projected['job_id']??null)===$projectionId&&($projected['state']??null)==='complete'&&($projected['created_at']??null)===$projection['created_at']
        &&($projected['sender']??null)==='Reviewed sender'&&($projected['receipts']??null)===[$authoritativeReceipt+['needs_attention'=>false]]
        &&($projected['retryable']??null)===false&&($projected['failure_category']??null)===''&&($projected['message']??null)==='Reviewed completion',
        'Stored channel result overrode authoritative public job fields.');
    ensure(($projected['display_timeout_seconds']??null)===7&&($projected['desktop_published']??null)===true,'Typed non-authoritative result metadata was lost.');
    $legacyRetryId='job_'.bin2hex(random_bytes(16));$legacyRetryReceipt=['channel'=>'webhook','target'=>$webhookId,'state'=>'failed','retryable'=>true];
    $legacyRetryJob=['id'=>$legacyRetryId,'state'=>'failed','created_at'=>gmdate('c'),'failure_category'=>'channel_submission_failed',
        'request'=>array_replace($request,['webhooks'=>[$webhookId]]),'receipts'=>[],'message'=>'Legacy failed delivery',
        'result'=>['success'=>false,'receipts'=>[$legacyRetryReceipt]]];
    $store->write($legacyRetryJob);ensure(($m->getAnnouncementJob($legacyRetryId)['retryable']??null)===true,'Legacy result-only receipt was not offered for explicit retry.');
    $legacyRetry=$m->retryFailedAnnouncementJob($legacyRetryId,['sender'=>'Fixture retry']);
    ensure(!empty($legacyRetry['queued'])&&is_string($legacyRetry['job_id']??null),'Legacy result-only receipt did not create a retry job.');
    $legacyRetryChild=$store->read($legacyRetry['job_id']);
    ensure(($legacyRetryChild['request']['only_channels']??null)===['webhook'=>[$webhookId]],'Legacy result-only retry claimed its parent but created an empty or incorrect child request.');
    foreach([[['channel'=>[],'target'=>$webhookId,'state'=>'failed','retryable'=>true]],
             [['channel'=>'unsupported','target'=>$webhookId,'state'=>'failed','retryable'=>true]],
             [['channel'=>'webhook','target'=>['invalid'],'state'=>'failed','retryable'=>true]],
             [['channel'=>'webhook','target'=>"bad\nrecipient",'state'=>'failed','retryable'=>true]]] as $damagedReceipts){
        $damagedId='job_'.bin2hex(random_bytes(16));$damaged=$legacyRetryJob;$damaged['id']=$damagedId;$damaged['result']['receipts']=$damagedReceipts;
        $store->write($damaged);ensure(($m->getAnnouncementJob($damagedId)['retryable']??null)===false,'Malformed or unsupported legacy receipt remained retryable.');
    }
    $m->destinations=['phones'=>[],'desktops'=>[],'webhooks'=>[]];
    $badLink=$link;$badLink['request_fingerprint']=str_repeat('0',64);rejected(fn()=>$m->read($badLink),'Wrong ledger fingerprint accepted');
    $startedBeforeExpired=count($m->started);
    $expired=$m->build($request,context(time()-901,'b'));$m->persist($expired);$m->activate(record($expired));
    $expiredState=$store->read($expired['id']);ensure(($expiredState['state']??null)==='expired' && count($m->started)===$startedBeforeExpired,'Expired prepared job activated');
    $running=$m->build($request,context(time(),'c'));$m->persist($running);$running['state']='running';$store->write($running);$store->reconcile($running['id']);
    $runningState=$store->read($running['id']);ensure(!empty($runningState['submission_uncertain']),'Lost running worker was replayable');
    $fault=$m->build($request,context(time(),'d'));ensure(unlink($jobs.'/worker-state.json'),'Worker-state fault fixture could not remove the journal.');ensure(mkdir($jobs.'/worker-state.json',0700),'Worker-state fault fixture directory could not be created.');
    rejected(fn()=>$m->persist($fault),'Post-job persistence fault hidden');
    $faultState=$store->read($fault['id']);ensure(($faultState['request_fingerprint']??null)===$fault['request_fingerprint'],'Committed preparation lost after later write failure');
    ensure(rmdir($jobs.'/worker-state.json'),'Worker-state fault fixture directory could not be removed.');ensure($m->persist($fault)===$fault,'Retry preparation did not recover exact committed snapshot');
    foreach([['deadline_at'=>$ctx['deadline_at']+1],['version'=>2],['occurrence_id'=>'occ_'.str_repeat('0',20)]]as $patch){
        rejected(fn()=>$m->build($request,array_replace($ctx,$patch)),'Invalid context accepted');
    }
    $id='email_'.str_repeat('e',24);$target=['id'=>$id,'address'=>'test@example.com','sender'=>['name'=>'Fixture','address'=>'notify@example.com']];
    $m->email=[$id=>$target];$mail=$request;$mail['email_recipient_ids']=[$id];
    $fingerprint=(new ReflectionMethod($m,'announcementEmailFingerprint'))->invoke($m,$target);
    ensure(is_string($fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$fingerprint)===1,'Scheduled email fingerprint is invalid.');
    $mail['email_targets']=[$id=>$target+['fingerprint'=>$fingerprint]];
    $emailJob=$m->build($mail,context(time(),'e'));ensure($emailJob['request']['email_expires_at']===$emailJob['request']['schedule_context']['deadline_at'],'Email deadline extended');
    $m->persist($emailJob);$emailJob['state']='queued';$store->write($emailJob);$m->processAnnouncementJobs($emailJob['id']);
    $emailState=$store->read($emailJob['id']);
    ensure(count($m->sent)===1 && ($emailState['state']??null)==='complete','Scheduled immutable email worker failed: '.json_encode($emailState['result']??[],JSON_THROW_ON_ERROR));
    ensure(($emailState['request']??null)===$emailJob['request'],'Email attempt mutated prepared snapshot');
    $weatherMail=$m->build($mail,context(time(),'g'));$weatherMail['request']['weather_context']=['deadline_at'=>time()+900];
    $weatherMail['request_fingerprint']=SlsAnnouncementJobStore::requestFingerprint($weatherMail['request']);$weatherMail['state']='queued';
    $beforeMail=count($m->sent);$m->weatherStatuses=['eligible','cancelled'];$store->write($weatherMail);
    ensure($m->processAnnouncementJobs($weatherMail['id'])===false,'Weather cancellation before email submission reported success.');
    $weatherMailState=$store->read($weatherMail['id']);$mailReceipts=array_values(array_filter($weatherMailState['receipts']??[],static fn($row)=>($row['channel']??'')==='email'));
    ensure(count($m->sent)===$beforeMail&&count($mailReceipts)===1&&($mailReceipts[0]['state']??null)==='cancelled','Cancelled weather email was submitted or lacked a terminal receipt.');
    echo "Scheduled durable delivery: $n checks passed; no real transports.\n";
}finally{
    try{
        if($created){
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $entry){
                ensure($entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()),'Scheduled-job fixture cleanup failed.');
            }
            ensure(rmdir($root),'Scheduled-job fixture root could not be removed.');
        }
    }finally{restore_error_handler();}
}
