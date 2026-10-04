<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/ScheduledDelivery.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_announcement_jobs.php';
if (!class_exists('FreePBX', false)) { class FreePBX {} }
class ampuser {
    public static array $rows=[];
    public function __construct(public string $username, public string $source='database') {}
    public function getAmpUser($username) { return self::$rows[$this->source.':'.$username] ?? false; }
}
$root = sys_get_temp_dir() . '/sls-schedule-durable-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$lines = file($reflection->getFileName()); $methods = '';
foreach (['processScheduledAnnouncements', 'scheduleExecutionRecord', 'sanitizeScheduleText', 'parseScheduleUtcTimestamp',
    'normalizeAnnouncementAudioMode', 'loadScheduleExecutionStore', 'writeScheduleExecutionStore',
    'acquireAnnouncementActivityLock', 'acquireNativeBackupFileLock', 'releaseNativeBackupFileLock',
    'getScheduleExecutionState', 'validateNativeScheduleLedger'] as $name) {
    $method = $reflection->getMethod($name);
    $body = implode('', array_slice($lines, $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
    if ($name === 'writeScheduleExecutionStore') { $body = str_replace('function writeScheduleExecutionStore(', 'function commitFixtureLedger(', $body); }
    $methods .= $body;
}
$class = <<<'PHP'
class ScheduledDeliveryFixture {
    use \FreePBX\modules\SlsScheduledDelivery;
    use \FreePBX\modules\SlsAnnouncementDelivery { persistPreparedScheduledAnnouncementJob as actualPersist; }
    public $settings = [], $fault = '', $started = [], $statuses = [], $resolved = [], $admit = true;
    private function ensurePluginDataDir() {}
    private function setPrivateOwnership($path) { chmod($path, 0600); }
    public function getActiveSettings() { return $this->settings; }
    private function updateStatusData($value) { $this->statuses[] = $value; }
    private function writeScheduleExecutionStore(array $store) {
        $this->commitFixtureLedger($store);
        $last = end($store['occurrences']);
        if ($this->fault === 'after_intent' && ($last['state'] ?? '') === 'preparing') { throw new RuntimeException('fixture interruption after intent'); }
        if ($this->fault === 'after_link' && ($last['state'] ?? '') === 'queued') { throw new RuntimeException('fixture interruption after link'); }
    }
    private function persistPreparedScheduledAnnouncementJob(array $job): array {
        $result = $this->actualPersist($job);
        if ($this->fault === 'after_job') { throw new RuntimeException('fixture interruption after durable job'); }
        return $result;
    }
    private function resolveAnnouncementRequest($phones, $message, $mass, $tts, $groups, array $options, array $settings): array {
        $this->resolved[] = compact('phones', 'message', 'groups', 'options', 'settings');
        return ['success'=>true, 'request'=>['message'=>$message, 'phones'=>$phones, 'desktops'=>[], 'webhooks'=>[],
            'audio_mode'=>'none', 'sender'=>$options['sender'], 'email_recipient_ids'=>[], 'voice_recipient_ids'=>[],
            'voice'=>'', 'is_test'=>false]];
    }
    private function admitScheduledAnnouncementRequest(array $request): array { return ['success'=>$this->admit, 'deferred'=>!$this->admit]; }
    private function startAnnouncementWorker($id) {
        // Exact durable startup boundary, without processes, PBX or channels.
        $ledger = $this->ledger(); $row = end($ledger['occurrences']);
        if (($row['job_id'] ?? '') !== $id || ($row['state'] ?? '') !== 'queued') { throw new RuntimeException('worker before durable ledger link'); }
        $store = new SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR . '/announcement-jobs');
        $lock = $store->lock($id);
        try { $job=$store->read($id); $job['state']='worker_starting'; $job['startup_attempted_at']=gmdate('c'); $store->write($job); }
        finally { SlsAnnouncementJobStore::unlock($lock); }
        $this->started[]=$id; return true;
    }
    private function refreshAnnouncementDesktopReceipts(array $value, $created='') { return $value; }
    public function ledger() { return $this->loadScheduleExecutionStore(true); }
    public function saveLedger($store) { $this->commitFixtureLedger($store); }
    public function validateLedger($value) { return $this->validateNativeScheduleLedger(json_encode($value)); }
}
PHP;
$constants = 'const PLUGIN_DATA_DIR=' . var_export($root,true) . '; const SCHEDULE_LOCK_FILE=self::PLUGIN_DATA_DIR."/schedule-worker.lock";'
    . 'const SCHEDULE_STATE_JSON=self::PLUGIN_DATA_DIR."/schedule-state.json"; const ANNOUNCEMENT_ACTIVITY_LOCK_FILE=self::PLUGIN_DATA_DIR."/announcement-activity.lock";'
    . 'const NATIVE_BACKUP_MAX_LEDGER_BYTES=4194304; const MAX_SCHEDULES=100; const MAX_SCHEDULE_OCCURRENCES=1000;';
eval(str_replace("\n}\n", "\n".$constants."\n".$methods."\n}\n", $class."\n"));
$count = 0;
function check($condition, $message) { global $count; if (!$condition) { throw new RuntimeException($message); } ++$count; }
function cleanDirectory($directory) { foreach (scandir($directory) as $entry) { if ($entry==='.' || $entry==='..')continue; $path=$directory.'/'.$entry; if(is_dir($path)&&!is_link($path)){cleanDirectory($path);rmdir($path);}else{unlink($path);} } }
function fixture($fault='') {
    global $root; cleanDirectory($root);
    $f=new ScheduledDeliveryFixture();$f->fault=$fault;
    $utc=gmdate('Y-m-d\TH:i:s\Z',time()-2); $id='sched_fixture';
    $f->settings=['scheduled_announcements'=>[['id'=>$id,'name'=>'Fixture','enabled'=>'1','max_lateness_minutes'=>15,
        'message'=>'Private fixture','created_by'=>'Operator','targets'=>['extensions'=>['1000'],'groups'=>[]],
        'delivery'=>['audio_mode'=>'none'],'occurrences'=>[['id'=>'occ_'.substr(hash('sha256',$id.'|'.$utc),0,20),'run_at_utc'=>$utc]]]]];
    return $f;
}
try {
    $login=['mode'=>'database','username'=>'planner','password_sha1'=>str_repeat('a',40),'sections'=>['slsmassnotifyserver_operations']];
    ampuser::$rows['database:planner']=$login;
    $origin=['id'=>'api_'.str_repeat('e',24),'username'=>'planner','name'=>'Planner','source'=>'database','identity'=>\SLS\MassNotify\OperatorAccess::identity($login),'role'=>'scheduler','enabled'=>true,'site_ids'=>['loc_'.str_repeat('a',24)],'group_ids'=>[]];
    foreach (['allowed','disabled','sender','offsite','login_changed'] as $scenario) {
        $f=fixture(); $f->settings['scheduled_announcements'][0]['operator_credential_id']=$origin['id'];
        $f->settings['operator_access']=['schema'=>1,'enabled'=>true,'accounts'=>[$origin]];
        $f->settings['location_directory']=['nodes'=>[['id'=>'loc_'.str_repeat('a',24),'name'=>'Assigned site','type'=>'site','members'=>['extensions'=>[$scenario==='offsite'?'2000':'1000']]]]];
        if($scenario==='disabled')$f->settings['operator_access']['accounts'][0]['enabled']=false;
        if($scenario==='sender')$f->settings['operator_access']['accounts'][0]['role']='sender';
        ampuser::$rows['database:planner']=$login;
        if($scenario==='login_changed')ampuser::$rows['database:planner']['password_sha1']=str_repeat('b',40);
        $f->processScheduledAnnouncements(); $record=end($f->ledger()['occurrences']);
        if($scenario==='allowed') {
            check(count($f->started)===1,'Authorized scoped schedule did not run');
            $job=(new SlsAnnouncementJobStore($root.'/announcement-jobs'))->read($record['job_id']);
            check($job['request']['api_credential_id']===$origin['id'],'Prepared job lost originating role authorization');
        } else check($f->started===[]&&$record['state']==='failed'&&empty($record['job_id']),'Unauthorized schedule reached preparation: '.$scenario);
    }
    ampuser::$rows['database:planner']=$login;
    foreach (['after_intent','after_job','after_link'] as $boundary) {
        $f=fixture($boundary);
        try {$f->processScheduledAnnouncements();throw new LogicException('fault did not occur');}catch(RuntimeException $e){check(strpos($e->getMessage(),'fixture interruption')!==false,'unexpected fault');}
        check($f->started===[],'interrupted preparation launched worker');
        $before=end($f->ledger()['occurrences']);$f->fault='';
        $f->processScheduledAnnouncements();$f->processScheduledAnnouncements();
        $after=end($f->ledger()['occurrences']);
        check($before['job_id']===$after['job_id'],'recovery changed deterministic identity');
        check($before['deadline_at']===$after['deadline_at'],'recovery extended absolute deadline');
        check($before['request_fingerprint']===$after['request_fingerprint'],'recovery changed immutable recipients');
        check(count($f->resolved)===1,'recovery resolved recipients again');
        check(count($f->started)===($boundary==='after_intent'?0:1),'recovery duplicated or lost safe activation');
        check($after['state']===($boundary==='after_intent'?'failed':'queued'),'queue admission masqueraded as completion');
    }
    $f=fixture('after_job');try{$f->processScheduledAnnouncements();}catch(RuntimeException $e){}
    $f->fault='';$f->settings['scheduled_announcements']=[];$f->processScheduledAnnouncements();
    check(count($f->started)===1,'deleted schedule lost already-prepared delivery');
    $public=$f->getScheduleExecutionState();check(!empty($public['sched_fixture']['orphaned_schedule']),'orphan link hidden');
    check(!isset($public['sched_fixture']['request_fingerprint']),'internal fingerprint leaked in public projection');
    $record=end($f->ledger()['occurrences']);$jobPath=$root.'/announcement-jobs/'.$record['job_id'].'.json';unlink($jobPath);
    $f->processScheduledAnnouncements();check(end($f->ledger()['occurrences'])['state']==='uncertain','missing linked job recreated');
    check(!file_exists($jobPath),'missing job was regenerated');
    $f=fixture();$f->admit=false;$f->processScheduledAnnouncements();$record=end($f->ledger()['occurrences']);
    check($record['state']==='queued'&&$record['job_state']==='prepared'&&$f->started===[],'deferred admission started delivery');
    $f->admit=true;$f->processScheduledAnnouncements();check(count($f->started)===1,'deferred job not admitted once');
    $store=new SlsAnnouncementJobStore($root.'/announcement-jobs');$job=$store->read($record['job_id']);$job['state']='complete';$job['message']='Submitted';$store->write($job);
    $f->processScheduledAnnouncements();check(end($f->ledger()['occurrences'])['state']==='success','durable completion not projected');
    $valid=$f->ledger();$f->validateLedger($valid);check(true,'valid additive ledger accepted');
    $bad=$valid;$key=array_key_first($bad['occurrences']);$bad['occurrences'][$key]['deadline_at']='900';
    try{$f->validateLedger($bad);throw new LogicException('invalid deadline accepted');}catch(RuntimeException $e){check(true,'invalid deadline rejected');}
    $f=fixture();$row=$f->settings['scheduled_announcements'][0];$occ=$row['occurrences'][0];
    $f->saveLedger(['version'=>1,'occurrences'=>[$occ['id']=>['schedule_id'=>$row['id'],'schedule_name'=>'Fixture','occurrence_id'=>$occ['id'],
        'run_at_utc'=>$occ['run_at_utc'],'state'=>'claimed','claimed_at'=>gmdate('c',time()-400)]]]);
    $f->processScheduledAnnouncements();check(end($f->ledger()['occurrences'])['state']==='uncertain'&&$f->started===[],'legacy claim replayed');
    echo "$count durable scheduler checks passed; no PBX or outbound actions.\n";
} finally {cleanDirectory($root);rmdir($root);}
