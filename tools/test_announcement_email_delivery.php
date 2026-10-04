<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
use SLS\MassNotify\ApiSecurity;
function emailCheck($value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
class EmailDeliveryFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery;
    public $directory, $settings=[], $sent=[], $outcomes=[], $afterSend, $failure='', $failureAfter=0, $clock=0, $clockStep=0;
    public function __construct($directory) { $this->directory=$directory; }
    private function announcementJobDirectory() { return $this->directory; }
    private function startAnnouncementWorker($id) { return true; }
    private function acquireAnnouncementActivityLock($exclusive=false,$timeout=30) { return null; }
    private function releaseNativeBackupFileLock($handle) {}
    private function currentAnnouncementDestinationIds() { return ['phones'=>[],'desktops'=>[],'webhooks'=>[]]; }
    private function getDesktopClients($settings) { return []; }
    private function normalizeWebhookDestinations($values,$kind) { return []; }
    private function getActiveSettings() { return $this->settings; }
    private function announcementEmailTargets($settings) { return !empty($settings['enabled']) ? $settings['targets'] : []; }
    private function announcementEmailFingerprint($target) { return hash('sha256',json_encode([$target['id'],$target['address'],$target['sender']['name'],$target['sender']['address']])); }
    private function setAnnouncementCooldown() {}
    private function recordInteractiveAnnouncementAdmission(array $request): void {}
    private function getAnnouncementCooldownState() { return ['remaining'=>0]; }
    private function sanitizeScheduleText($value,$limit,$single) { return $value; }
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted'=>[],'failed'=>[]]; }
    protected function executeAnnouncementPhoneVisualBatch(array $commands,callable $completed): void {}
    protected function readAnnouncementPhoneOutcomes($correlation) { return []; }
    private function appendAnnouncementNotifyLog(...$args) {}
    protected function announcementEmailMonotonic(): float { $value=$this->clock;$this->clock+=$this->clockStep;return (float)$value; }
    private function writeAnnouncementJob(array $job) {
        $rows=$job['receipts']??[];$last=$rows?end($rows):[];
        if (count($rows)>=$this->failureAfter && (($this->failure==='intent' && ($last['state']??'')==='submitting') || ($this->failure==='result' && ($last['state']??'')==='accepted'))) {
            $this->failure='';throw new RuntimeException('inert persistence failure');
        }
        (new SlsAnnouncementJobStore($this->directory))->write($job);
    }
    protected function submitAnnouncementEmail(array $envelope): array {
        $found=false;
        foreach (glob($this->directory.'/job_*.json')?:[] as $path) {
            $job=json_decode(file_get_contents($path),true);$rows=$job['receipts']??[];$last=$rows?end($rows):[];
            if (($job['state']??'')==='running' && ($last['state']??'')==='submitting' && ($last['message_id']??'')===$envelope['message_id']) {$found=true;}
        }
        emailCheck($found,'Sender launched before durable per-recipient intent');
        $this->sent[]=$envelope;
        if ($this->afterSend) { ($this->afterSend)($this); }
        return array_shift($this->outcomes) ?: ['state'=>'accepted','retryable'=>false,'detail'=>'Accepted by PBX mail service'];
    }
    public function submit($ids) {
        $request=['email_recipient_ids'=>$ids,'email_severity'=>'warning','is_test'=>true,'message'=>"line one\nline two",'title'=>'Fixture',
            'sender'=>'Operator','phones'=>[],'desktops'=>[],'webhooks'=>[],'audio_mode'=>'none','timeout_mode'=>'none','display_timeout'=>0,
            'voice'=>'','volume'=>25,'opening_tone'=>'','closing_tone'=>'','trigger_source'=>'Fixture','image'=>false,'background_color'=>'#000000'];
        return $this->deliverResolvedAnnouncement($this->snapshotAnnouncementRequest($request,$this->settings));
    }
    public function unsafeSync($request) { return $this->executeResolvedAnnouncement($request); }
}
$root=sys_get_temp_dir().'/sls-email-delivery-'.bin2hex(random_bytes(8));mkdir($root,0700);
$a='email_'.str_repeat('a',24);$b='email_'.str_repeat('b',24);
$fixtures=[];
$fixture=function()use($root,$a,$b,&$fixtures){
    $directory=$root.'/case'.count($fixtures);mkdir($directory,0700);$x=new EmailDeliveryFixture($directory);$fixtures[]=$x;
    $x->settings=['enabled'=>true,'targets'=>[$a=>['id'=>$a,'address'=>'first@example.com','sender'=>['name'=>'PBX','address'=>'notify@example.com']],$b=>['id'=>$b,'address'=>'second@example.com','sender'=>['name'=>'PBX','address'=>'notify@example.com']]]];return $x;
};
try {
    $x=$fixture();$queued=$x->submit([$a,$b]);emailCheck(!empty($queued['queued']) && !$x->sent,'CLI email bypassed queue');
    $store=new SlsAnnouncementJobStore($x->directory);$original=$store->read($queued['job_id']);
    $x->processAnnouncementJobs($queued['job_id']);$job=$store->read($queued['job_id']);
    emailCheck($job['state']==='complete' && count($x->sent)===2,'Email-only durable queue failed');
    emailCheck($x->sent[0]['created_at']===$original['request']['delivery_timestamp'] && $x->sent[0]['is_test']===true && $x->sent[0]['severity']==='warning','Explicit metadata lost');
    emailCheck(count(array_unique(array_column($x->sent,'message_id')))===2,'Recipients shared message identity');
    emailCheck($job['receipts'][0]['attempt_id']===$queued['job_id'],'Attempt identity not durable');
    emailCheck(strpos(json_encode($x->getAnnouncementJob($queued['job_id'])),'first@example.com')===false,'Public receipts exposed destination address');
    foreach (['address','sender','disable','revoke'] as $change) {
        $x=$fixture();$q=$x->submit([$a,$b]);$store=new SlsAnnouncementJobStore($x->directory);
        if ($change==='revoke') {
            $credential=ApiSecurity::issue(['name'=>'fixture','scopes'=>['send'],'audience'=>['unrestricted'=>true]])['credential'];
            $x->settings['control_api']=['enabled'=>'1','credentials'=>[$credential]];$j=$store->read($q['job_id']);$j['request']['api_credential_id']=$credential['id'];$store->write($j);
        }
        $x->afterSend=function($x)use($change,$b){
            if($change==='address')$x->settings['targets'][$b]['address']='changed@example.com';
            elseif($change==='sender')$x->settings['targets'][$b]['sender']['address']='other@example.com';
            elseif($change==='disable')$x->settings['enabled']=false;
            else $x->settings['control_api']['credentials']=[];
        };
        $x->processAnnouncementJobs($q['job_id']);$j=$store->read($q['job_id']);
        emailCheck(count($x->sent)===1 && $j['receipts'][1]['state']==='cancelled','Per-recipient '.$change.' recheck failed');
    }
    foreach (['intent','result'] as $failure) {
        $x=$fixture();$q=$x->submit([$a,$b]);$x->failure=$failure;$x->processAnnouncementJobs($q['job_id']);$j=(new SlsAnnouncementJobStore($x->directory))->read($q['job_id']);
        emailCheck(count($x->sent)===($failure==='intent'?0:1),'Persistence failure permitted another launch');
        emailCheck(!$x->getAnnouncementJob($q['job_id'])['retryable'],'Persistence uncertainty became replayable');
        if($failure==='result')emailCheck($j['receipts'][0]['state']==='uncertain','Lost result erased durable unresolved intent');
    }
    $x=$fixture();$x->outcomes=[['state'=>'failed','retryable'=>true,'detail'=>'temporary failure']];$q=$x->submit([$a,$b]);$x->processAnnouncementJobs($q['job_id']);
    $first=$x->sent[0];$retry=$x->retryFailedAnnouncementJob($q['job_id'],['sender'=>'Retry operator']);
    emailCheck(!empty($retry['queued']),'Definite failure not eligible for explicit retry');
    $x->processAnnouncementJobs($retry['job_id']);emailCheck(count($x->sent)===3,'Retry resent accepted destination');
    emailCheck($x->sent[2]===$first,'Retry changed immutable envelope/Message-ID/timestamp');
    $retryJob=(new SlsAnnouncementJobStore($x->directory))->read($retry['job_id']);emailCheck($retryJob['receipts'][0]['attempt_id']===$retry['job_id'],'Retry reused attempt identity');
    $x=$fixture();$q=$x->submit([$a,$b]);$x->failure='result';$x->failureAfter=2;$x->processAnnouncementJobs($q['job_id']);
    $j=(new SlsAnnouncementJobStore($x->directory))->read($q['job_id']);
    emailCheck($j['receipts'][0]['state']==='accepted' && $j['receipts'][1]['state']==='uncertain' && count($x->sent)===2,'Later failed receipt erased earlier acceptance or intent');
    $x=$fixture();$x->outcomes=[['state'=>'uncertain','retryable'=>false,'detail'=>'timeout'],['state'=>'failed','retryable'=>true,'detail'=>'temporary']];
    $q=$x->submit([$a,$b]);$x->processAnnouncementJobs($q['job_id']);$retry=$x->retryFailedAnnouncementJob($q['job_id'],['sender'=>'Operator']);
    emailCheck(!empty($retry['queued']),'Other definite failure lost explicit retry');$x->processAnnouncementJobs($retry['job_id']);
    emailCheck(count($x->sent)===3 && $x->sent[2]['recipient_id']===$b,'Uncertain recipient replayed during eligible retry');
    $x->processAnnouncementJobs($retry['job_id']);emailCheck(count($x->sent)===3,'Completed email replayed');
    foreach([['arbitrary@example.com'],[$a,$a],array_fill(0,51,$a),[[$a]]]as $bad){
        $x=$fixture();$rejected=false;try{$x->submit($bad);}catch(DomainException $e){$rejected=true;}
        emailCheck($rejected && !$x->sent && !(glob($x->directory.'/job_*.json')?:[]),'Invalid email IDs entered queue');
    }
    $x=$fixture();$q=$x->submit([$a]);$store=new SlsAnnouncementJobStore($x->directory);$j=$store->read($q['job_id']);
    $j['request']['email_targets'][$a]['address']='tampered@example.com';$store->write($j);$x->processAnnouncementJobs($q['job_id']);
    emailCheck(!$x->sent,'Changed frozen target bypassed its own fingerprint');
    $x=$fixture();$x->clockStep=80;$q=$x->submit([$a,$b]);$x->processAnnouncementJobs($q['job_id']);$j=(new SlsAnnouncementJobStore($x->directory))->read($q['job_id']);
    emailCheck(!$x->sent && count($j['receipts'])===2 && $j['receipts'][0]['retryable'],'Batch budget started an over-budget sender');
    $x=$fixture();$q=$x->submit([$a]);$store=new SlsAnnouncementJobStore($x->directory);$j=$store->read($q['job_id']);$j['request']['email_expires_at']=time()-1;$store->write($j);$x->processAnnouncementJobs($q['job_id']);
    emailCheck(!$x->sent && !$x->getAnnouncementJob($q['job_id'])['retryable'],'Expired original email became replayable');
    $x=$fixture();$q=$x->submit([$a]);$store=new SlsAnnouncementJobStore($x->directory);$j=$store->read($q['job_id']);$j['state']='running';$j['receipts']=[['channel'=>'email','target'=>$a,'state'=>'submitting','retryable'=>false,'message_id'=>'frozen']];$store->write($j);$store->reconcile($q['job_id']);$j=$store->read($q['job_id']);
    emailCheck($j['receipts'][0]['state']==='uncertain' && !$x->getAnnouncementJob($q['job_id'])['retryable'],'Abandoned intent was replayable');
    emailCheck(!$x->unsafeSync(['email_recipient_ids'=>[$a]])['delivery_started'],'Email synchronous unsafe path accepted');
    echo "Durable email queue, per-recipient authority/snapshot checks, intent/result failures, retries, budget, expiry and abandoned-intent cases passed.\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $p){$p->isDir()?rmdir((string)$p):unlink((string)$p);}rmdir($root);
}
