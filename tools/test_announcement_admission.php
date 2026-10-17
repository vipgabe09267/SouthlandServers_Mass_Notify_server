<?php
/** Real admission storage with a deterministic clock and private directories. */
declare(strict_types=1);
namespace FreePBX\modules { function time() { return $GLOBALS['admission_clock']; } }
namespace {
require dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementAdmission.php';
final class AdmissionFixtureFailure extends RuntimeException {}
$count=0;
function check($value,$message){global $count;++$count;if(!$value)throw new AdmissionFixtureFailure($message);}
function fixtureRead(string $path):string{$value=@file_get_contents($path);if(!is_string($value)){throw new AdmissionFixtureFailure('Could not read fixture '.$path);}return $value;}
function fixtureWrite(string $path,string $value):void{if(@file_put_contents($path,$value)!==strlen($value)){throw new AdmissionFixtureFailure('Could not write fixture '.$path);}}
function fixtureFs(bool $result,string $message):void{if(!$result){throw new AdmissionFixtureFailure($message);}}
function refused(callable $callback,string $message,string $expected,string $class=RuntimeException::class):void {
    try{$callback();}
    catch(Throwable $error){
        if($error instanceof AdmissionFixtureFailure){throw $error;}
        if(!($error instanceof $class)||trim($error->getMessage())===''||!str_contains($error->getMessage(),$expected)){
            throw new AdmissionFixtureFailure($message.'; unexpected '.get_class($error).': '.$error->getMessage(),0,$error);
        }
        check(true,$message);return;
    }
    throw new AdmissionFixtureFailure($message.'; no exception was raised');
}
function request($phone,$suffix='a') {
    $schedule='sched_'.$suffix;$run=gmdate('Y-m-d\TH:i:s\Z',900);
    return ['phones'=>[$phone],'desktops'=>[],'webhooks'=>[],'voice_recipient_ids'=>[],'email_recipient_ids'=>[],
        'delivery_id'=>'announcement-'.str_repeat($suffix,32),'schedule_context'=>['version'=>1,'schedule_id'=>$schedule,
            'occurrence_id'=>'occ_'.substr(hash('sha256',$schedule.'|'.$run),0,20),'run_at_utc'=>$run,'deadline_at'=>1800]];
}
$root=sys_get_temp_dir().'/sls-admission-'.bin2hex(random_bytes(8));
fixtureFs(@mkdir($root,0700),'Could not create the private admission fixture.');
$GLOBALS['admission_clock']=1000;
$testFailure=null;
try {
    eval('class AdmissionFixture {
        use \\FreePBX\\modules\\SlsAnnouncementAdmission;
        const PLUGIN_DATA_DIR = '.var_export($root,true).';
        public function scheduled(array $request) { return $this->admitScheduledAnnouncementRequest($request); }
        public function interactive(array $request) { $this->recordInteractiveAnnouncementAdmission($request); }
        public function transactional(array $request, string $jobId, callable $commit) { return $this->recordInteractiveAnnouncementAdmission($request, $commit, $jobId); }
        private function getAnnouncementCooldownState() {
            $last=(new \\SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionCooldown();
            return ["last_run"=>$last,"remaining"=>max(0,30-($GLOBALS["admission_clock"]-$last)),"duration"=>30];
        }
    }');
    $module=new AdmissionFixture();$store=new SlsAnnouncementJobStore($root);
    $statePath=$root.'/announcement-admission.json';$cooldownPath=$root.'/announcement-cooldown.ts';$lockPath=$root.'/announcement-send.lock';
    $module->interactive(request('1000'));
    check($store->admissionCooldown()===1000,'Interactive cooldown was not durably recorded');
    check(fixtureRead($lockPath)==="SLS_ANNOUNCEMENT_ADMISSION_V1\n",'Initialization marker missing');
    $overlap=$module->scheduled(request('1000'));
    check(empty($overlap['success']) && $overlap['retry_at']===1030,'Overlapping scheduled audience ignored cooldown');
    check($module->scheduled(request('1001','b'))['success'],'Disjoint schedule was blocked by the global UI cooldown');
    $sms=request('1001','e');$sms['phones']=[];$sms['sms_recipient_ids']=['sms_'.str_repeat('a',24)];
    check($module->scheduled($sms)['success'],'SMS-only scheduled audience could not be admitted');
    $sms['delivery_id']='announcement-'.str_repeat('f',32);
    $overlap=$module->scheduled($sms);
    check(empty($overlap['success'])&&$overlap['retry_at']===1030,'Overlapping SMS/MMS recipient bypassed cooldown');
    $sms['sms_recipient_ids']=['sms_'.str_repeat('b',24)];
    check($module->scheduled($sms)['success'],'Disjoint SMS audience was incorrectly blocked');
    foreach (['browser_recipient_ids'=>['sub_', '1', '2'], 'connector_ids'=>['conn_', '3', '4']] as $channel=>[$prefix,$first,$second]) {
        $labs=request('1001',$first); $labs['phones']=[]; $labs[$channel]=[$prefix.str_repeat('a',24)];
        check($module->scheduled($labs)['success'],'Labs-only scheduled audience could not be admitted');
        $labs['delivery_id']='announcement-'.str_repeat($second,32);
        $overlap=$module->scheduled($labs);
        check(empty($overlap['success'])&&$overlap['retry_at']===1030,'Overlapping '.$channel.' recipient bypassed cooldown');
        $labs[$channel]=[$prefix.str_repeat('b',24)];
        check($module->scheduled($labs)['success'],'Disjoint Labs audience was incorrectly blocked');
    }
    $original=fixtureRead($statePath);$GLOBALS['admission_clock']=1010;
    check($module->scheduled(request('1001','b'))['success'] && fixtureRead($statePath)===$original,'Reconciliation renewed admission');
    refused(fn()=>$module->scheduled(request('1002','b')),'Same owner changed audience','original audience or deadline');
    $changedSnapshot=request('1001','b');$changedSnapshot['title']='Changed after reservation';
    refused(fn()=>$module->scheduled($changedSnapshot),'Same owner changed delivery snapshot','original delivery snapshot');
    refused(fn()=>$module->interactive(request('1002')),'Interactive race bypassed global cooldown','on cooldown');
    $GLOBALS['admission_clock']=999;refused(fn()=>$module->scheduled(request('1002','c')),'Clock rollback erased cooldown','clock moved backwards');
    check(fixtureRead($statePath)===$original,'Rejected requests mutated admission history');
    $GLOBALS['admission_clock']=1031;
    check($module->scheduled(request('1000','c'))['success'],'Expired recipient cooldown did not release');
    $GLOBALS['admission_clock']=1062;
    $original=fixtureRead($statePath);
    $cooldownOriginal=fixtureRead($cooldownPath);
    $transaction=request('1010','9');
    $failedJob='job_'.str_repeat('9',32);
    refused(fn()=>$module->transactional($transaction,$failedJob,static function(){throw new OverflowException('queue full');}),'Queue-full transaction did not fail','queue full',OverflowException::class);
    check(fixtureRead($statePath)===$original && fixtureRead($cooldownPath)===$cooldownOriginal,'Queue-full transaction left a cooldown reservation');
    refused(fn()=>$module->transactional($transaction,$failedJob,static function(){throw new RuntimeException('write failed');}),'Failed job write did not fail admission','write failed');
    check(fixtureRead($statePath)===$original && fixtureRead($cooldownPath)===$cooldownOriginal,'Failed job write left a cooldown reservation');
    $commitCount=0;
    check($module->transactional($transaction,$failedJob,static function()use(&$commitCount){$commitCount++;return 'committed';})==='committed','Successful transaction did not return the durable commit result');
    $committed=json_decode(fixtureRead($statePath),true,16,JSON_THROW_ON_ERROR);
    $matching=array_values(array_filter($committed['reservations'],static fn($row)=>($row['job_id']??'')===$failedJob));
    check($commitCount===1&&count($matching)===1&&($matching[0]['committed']??false)===true,'Successful transaction did not bind and finalize its reservation');
    $GLOBALS['admission_clock']=1093;
    $original=fixtureRead($statePath);
    $held=$store->admissionLock();check(is_resource($held),'Cannot hold admission lock');
    $busy=$module->scheduled(request('1003','d'));
    check(!empty($busy['deferred']) && fixtureRead($statePath)===$original,'Contention was not a bounded deferral');
    SlsAnnouncementJobStore::unlock($held);
    $expired=request('1003','d');$expired['schedule_context']['deadline_at']=1031;
    check(($module->scheduled($expired)['error_code']??'')==='schedule_deadline_expired','Expired schedule admitted');
    // Simulate a crash after JSON commit but before its compatibility timestamp.
    fixtureWrite($cooldownPath,"1000\n");
    check($module->scheduled(request('1000','c'))['success'],'Committed scheduled owner could not reconcile after interrupted timestamp write');
    check(fixtureRead($statePath)===$original,'Interrupted commit reconciliation renewed recipients');
    fixtureWrite($cooldownPath,"invalid\n");
    refused(fn()=>$module->scheduled(request('1003','d')),'Corrupt timestamp was reset','cooldown state is corrupt');
    fixtureWrite($cooldownPath,"1031\n");
    fixtureFs(@unlink($statePath),'Could not remove the admission-state fixture.');refused(fn()=>$module->scheduled(request('1003','d')),'Missing established state was treated as fresh','Established announcement admission state is missing');
    check(!file_exists($statePath),'Missing established state recreated');
    fixtureWrite($statePath,$original);fixtureFs(@chmod($statePath,0640),'Could not protect the restored admission-state fixture.');
    foreach (["{\"schema\":1,\"schema\":1,\"global_stamp\":1031,\"reservations\":[]}\n",substr($original,0,-5)] as $bad) {
        fixtureWrite($statePath,$bad);
        refused(fn()=>$module->scheduled(request('1003','d')),'Duplicate or partial JSON accepted','storage has unsafe ownership, permissions, type, size, or content');
        check(fixtureRead($statePath)===$bad,'Corrupt journal was overwritten');
    }
    fixtureWrite($statePath,$original);
    foreach([0644,0650,0755] as $mode){fixtureFs(@chmod($statePath,$mode),'Could not set the admission-state fixture mode.');refused(fn()=>$module->scheduled(request('1003','d')),'Public or executable admission state accepted','storage has unsafe ownership, permissions, type, size, or content');}
    fixtureFs(@chmod($statePath,0640),'Could not restore the admission-state fixture mode.');
    fixtureFs(@link($statePath,$root.'/linked-state'),'Could not hard-link the admission-state fixture.');refused(fn()=>$module->scheduled(request('1003','d')),'Hardlinked journal accepted','storage has unsafe ownership, permissions, type, size, or content');fixtureFs(@unlink($root.'/linked-state'),'Could not remove the admission-state hard link.');
    fixtureFs(@unlink($statePath),'Could not remove the admission-state fixture before symlink testing.');fixtureFs(@symlink($cooldownPath,$statePath),'Could not create the admission-state symlink fixture.');
    refused(fn()=>$module->scheduled(request('1003','d')),'Linked journal accepted','storage has unsafe ownership, permissions, type, size, or content');
    fixtureFs(@unlink($statePath),'Could not remove the admission-state symlink fixture.');
    fixtureWrite($statePath,$original);fixtureFs(@chmod($statePath,0640),'Could not restore the private admission-state fixture.');
    fixtureFs(@unlink($lockPath),'Could not remove the admission lock before symlink testing.');fixtureFs(@symlink($cooldownPath,$lockPath),'Could not create the admission-lock symlink fixture.');
    refused(fn()=>$module->scheduled(request('1003','d')),'Linked send lock accepted','Unsafe announcement state file');fixtureFs(@unlink($lockPath),'Could not remove the admission-lock symlink fixture.');
    if (function_exists('posix_mkfifo')) {
        fixtureFs(@posix_mkfifo($lockPath,0600),'Could not create the admission-lock FIFO fixture.');
        refused(fn()=>$module->scheduled(request('1003','d')),'FIFO send lock accepted','Unsafe announcement state file');fixtureFs(@unlink($lockPath),'Could not remove the admission-lock FIFO fixture.');
    }
    fixtureFs(@mkdir($cooldownPath.'.directory',0700),'Could not create the cooldown directory fixture.');fixtureFs(@rename($cooldownPath,$cooldownPath.'.saved'),'Could not preserve the cooldown fixture.');fixtureFs(@rename($cooldownPath.'.directory',$cooldownPath),'Could not substitute the cooldown directory fixture.');
    refused(fn()=>$module->scheduled(request('1003','d')),'Unsafe cooldown destination was replaced','Unsafe announcement state file');
    check(fixtureRead($statePath)===$original,'Unsafe state destination changed reservation history');
    echo "Announcement admission: $count durability, cooldown, contention, identity and unsafe-path checks passed.\n";
} catch(Throwable $error) {
    $testFailure=$error;throw $error;
} finally {
    $cleanupErrors=[];
    try{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry){$removed=$entry->isDir()&&!$entry->isLink()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());if(!$removed){$cleanupErrors[]=$entry->getPathname();}}
    }catch(Throwable $cleanupError){$cleanupErrors[]=$cleanupError->getMessage();}
    if(is_dir($root)&&!@rmdir($root)){$cleanupErrors[]=$root;}
    if($cleanupErrors){$cleanup=new AdmissionFixtureFailure('Admission fixture cleanup failed: '.implode(', ',$cleanupErrors));if($testFailure===null){throw $cleanup;}fwrite(STDERR,$cleanup->getMessage()."\n");}
}
}
