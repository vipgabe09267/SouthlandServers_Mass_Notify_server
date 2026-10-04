<?php
/** Real admission storage with a deterministic clock and private directories. */
declare(strict_types=1);
namespace FreePBX\modules { function time() { return $GLOBALS['admission_clock']; } }
namespace {
require dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementAdmission.php';
$root=sys_get_temp_dir().'/sls-admission-'.bin2hex(random_bytes(8));mkdir($root,0700);
eval('class AdmissionFixture {
    use \\FreePBX\\modules\\SlsAnnouncementAdmission;
    const PLUGIN_DATA_DIR = '.var_export($root,true).';
    public function scheduled(array $request) { return $this->admitScheduledAnnouncementRequest($request); }
    public function interactive(array $request) { $this->recordInteractiveAnnouncementAdmission($request); }
    private function getAnnouncementCooldownState() {
        $last=(new \\SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionCooldown();
        return ["last_run"=>$last,"remaining"=>max(0,30-($GLOBALS["admission_clock"]-$last)),"duration"=>30];
    }
}');
$count=0;
function check($value,$message){global $count;++$count;if(!$value)throw new RuntimeException($message);}
function refused(callable $callback,$message){try{$callback();}catch(Throwable $error){check(true,$message);return;}throw new RuntimeException($message);}
function request($phone,$suffix='a') { return ['phones'=>[$phone],'desktops'=>[],'webhooks'=>[],'voice_recipient_ids'=>[],'email_recipient_ids'=>[],
    'delivery_id'=>'announcement-'.str_repeat($suffix,32),'schedule_context'=>['deadline_at'=>1800]]; }
$GLOBALS['admission_clock']=1000;
$module=new AdmissionFixture();$store=new SlsAnnouncementJobStore($root);
$statePath=$root.'/announcement-admission.json';$cooldownPath=$root.'/announcement-cooldown.ts';$lockPath=$root.'/announcement-send.lock';
try {
    $module->interactive(request('1000'));
    check($store->admissionCooldown()===1000,'Interactive cooldown was not durably recorded');
    check(file_get_contents($lockPath)==="SLS_ANNOUNCEMENT_ADMISSION_V1\n",'Initialization marker missing');
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
    $original=file_get_contents($statePath);$GLOBALS['admission_clock']=1010;
    check($module->scheduled(request('1001','b'))['success'] && file_get_contents($statePath)===$original,'Reconciliation renewed admission');
    refused(fn()=>$module->scheduled(request('1002','b')),'Same owner changed audience');
    refused(fn()=>$module->interactive(request('1002')),'Interactive race bypassed global cooldown');
    $GLOBALS['admission_clock']=999;refused(fn()=>$module->scheduled(request('1002','c')),'Clock rollback erased cooldown');
    check(file_get_contents($statePath)===$original,'Rejected requests mutated admission history');
    $GLOBALS['admission_clock']=1031;
    check($module->scheduled(request('1000','c'))['success'],'Expired recipient cooldown did not release');
    $original=file_get_contents($statePath);
    $held=$store->admissionLock();check(is_resource($held),'Cannot hold admission lock');
    $busy=$module->scheduled(request('1003','d'));
    check(!empty($busy['deferred']) && file_get_contents($statePath)===$original,'Contention was not a bounded deferral');
    SlsAnnouncementJobStore::unlock($held);
    $expired=request('1003','d');$expired['schedule_context']['deadline_at']=1031;
    check(($module->scheduled($expired)['error_code']??'')==='schedule_deadline_expired','Expired schedule admitted');
    // Simulate a crash after JSON commit but before its compatibility timestamp.
    file_put_contents($cooldownPath,"1000\n");
    check($module->scheduled(request('1000','c'))['success'],'Committed scheduled owner could not reconcile after interrupted timestamp write');
    check(file_get_contents($statePath)===$original,'Interrupted commit reconciliation renewed recipients');
    file_put_contents($cooldownPath,"invalid\n");
    refused(fn()=>$module->scheduled(request('1003','d')),'Corrupt timestamp was reset');
    file_put_contents($cooldownPath,"1031\n");
    unlink($statePath);refused(fn()=>$module->scheduled(request('1003','d')),'Missing established state was treated as fresh');
    check(!file_exists($statePath),'Missing established state recreated');
    file_put_contents($statePath,$original);chmod($statePath,0640);
    foreach (["{\"schema\":1,\"schema\":1,\"global_stamp\":1031,\"reservations\":[]}\n",substr($original,0,-5)] as $bad) {
        file_put_contents($statePath,$bad);
        refused(fn()=>$module->scheduled(request('1003','d')),'Duplicate or partial JSON accepted');
        check(file_get_contents($statePath)===$bad,'Corrupt journal was overwritten');
    }
    file_put_contents($statePath,$original);
    link($statePath,$root.'/linked-state');refused(fn()=>$module->scheduled(request('1003','d')),'Hardlinked journal accepted');unlink($root.'/linked-state');
    unlink($statePath);symlink($cooldownPath,$statePath);
    refused(fn()=>$module->scheduled(request('1003','d')),'Linked journal accepted');unlink($statePath);file_put_contents($statePath,$original);
    unlink($lockPath);symlink($cooldownPath,$lockPath);
    refused(fn()=>$module->scheduled(request('1003','d')),'Linked send lock accepted');unlink($lockPath);
    if (function_exists('posix_mkfifo')) {
        posix_mkfifo($lockPath,0600);
        refused(fn()=>$module->scheduled(request('1003','d')),'FIFO send lock accepted');unlink($lockPath);
    }
    mkdir($cooldownPath.'.directory',0700);rename($cooldownPath,$cooldownPath.'.saved');rename($cooldownPath.'.directory',$cooldownPath);
    refused(fn()=>$module->scheduled(request('1003','d')),'Unsafe cooldown destination was replaced');
    check(file_get_contents($statePath)===$original,'Unsafe state destination changed reservation history');
    echo "Announcement admission: $count durability, cooldown, contention, identity and unsafe-path checks passed.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    } rmdir($root);
}
}
