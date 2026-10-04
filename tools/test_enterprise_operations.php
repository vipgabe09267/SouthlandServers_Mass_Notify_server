<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/LabsSafety.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseApprovalService.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseFloorplanStore.php';
use SLS\MassNotify\{LabsSafety,EnterpriseOperationsConfig,EnterpriseOperationsStore,EnterpriseApprovalService,EnterpriseFloorplanStore};
function check(bool $ok,string $label):void{if(!$ok){throw new RuntimeException($label);}}
function rejected(callable $work,string $label):void{try{$work();}catch(InvalidArgumentException|DomainException|RuntimeException $e){return;}throw new RuntimeException('Expected rejection: '.$label);}
$base=sys_get_temp_dir().'/sls-enterprise-ops-'.bin2hex(random_bytes(8));mkdir($base,0700);
register_shutdown_function(static function()use($base):void{if(!is_dir($base)){return;}$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink()){rmdir($f->getPathname());}else{unlink($f->getPathname());}}rmdir($base);});

$session=[];$challenge=LabsSafety::begin($session,'enterprise_cluster','alice','session-a',100,1000000000);
$accept=['feature'=>'enterprise_cluster','challenge'=>$challenge['challenge'],'agree'=>true];
rejected(fn()=>LabsSafety::accept($session,$accept,'alice','session-a',104,5999999999),'4.999999999 seconds');
rejected(fn()=>LabsSafety::accept($session,$accept,'bob','session-a',105,6000000000),'another actor');
rejected(fn()=>LabsSafety::accept($session,$accept,'alice','session-b',105,6000000000),'another session');
rejected(fn()=>LabsSafety::accept($session,array_replace($accept,['challenge'=>str_repeat('a',64)]),'alice','session-a',105,6000000000),'different nonce');
$receipt=LabsSafety::accept($session,$accept,'alice','session-a',105,6000000000);
check($receipt['receipt']['actor']==='alice','five second acceptance');
rejected(fn()=>LabsSafety::accept($session,$accept,'alice','session-a',106,7000000000),'challenge replay');
$settings=['labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$receipt['receipt']]]];
check(LabsSafety::hasReceipt($settings,'enterprise_cluster'),'persistent acknowledgment');
check(!LabsSafety::hasReceipt($settings,'access_control_actuation'),'not a global acknowledgment');
$settings['labs_safety']['receipts']['enterprise_cluster']['revision']=str_repeat('b',64);
rejected(fn()=>LabsSafety::requireReceipt($settings,'enterprise_cluster'),'warning revision change');
$session=[];$expired=LabsSafety::begin($session,'enterprise_cluster','alice','session-a',100,1000000000);
rejected(fn()=>LabsSafety::accept($session,['feature'=>'enterprise_cluster','challenge'=>$expired['challenge'],'agree'=>true],'alice','session-a',701,602000000000),'expired challenge');
rejected(fn()=>LabsSafety::begin($session,'ordinary_feature','alice','session-a'),'ordinary features no catastrophic nag');

$template='tpl_'.str_repeat('1',24);$default=EnterpriseOperationsConfig::defaults();
check(!$default['enabled']&&!$default['dual_approval']['enabled'],'disabled defaults');
$route=['id'=>'shift_'.str_repeat('2',24),'name'=>'Overnight','enabled'=>true,'template_ids'=>[$template],
    'timezone'=>'America/Chicago','days'=>[1],'start'=>'22:00','end'=>'06:00','delivery'=>['group_ids'=>['grp_test']]];
$settings=['enterprise_operations'=>array_replace($default,['enabled'=>true,'shift_routing'=>['enabled'=>true,'routes'=>[$route]]])];
check(EnterpriseOperationsConfig::activeShift($settings,$template,strtotime('2026-10-06T02:00:00-05:00'))['name']==='Overnight','overnight previous weekday');
rejected(fn()=>EnterpriseOperationsConfig::activeShift($settings,$template,strtotime('2026-10-06T06:00:00-05:00')),'end exclusive coverage gap');
$overlap=$settings;$second=$route;$second['id']='shift_'.str_repeat('3',24);$overlap['enterprise_operations']['shift_routing']['routes'][]=$second;
rejected(fn()=>EnterpriseOperationsConfig::activeShift($overlap,$template,strtotime('2026-10-06T02:00:00-05:00')),'overlapping schedules');
$dst=$route;$dst['days']=[7];$dst['start']='01:00';$dst['end']='03:00';$settings['enterprise_operations']['shift_routing']['routes']=[$dst];
check(EnterpriseOperationsConfig::activeShift($settings,$template,strtotime('2026-11-01T01:30:00-05:00'))!==null,'first DST occurrence');
check(EnterpriseOperationsConfig::activeShift($settings,$template,strtotime('2026-11-01T01:30:00-06:00'))!==null,'second DST occurrence');
rejected(fn()=>EnterpriseOperationsConfig::normalize(array_replace($default,['extra'=>true])),'unknown configuration');

$policy=['id'=>'approval_'.str_repeat('4',24),'name'=>'Phone review','enabled'=>true,'channels'=>['phones'],
    'approver_ids'=>['pbx:alice','pbx:bob'],'expires_seconds'=>300];
$settings=['enterprise_operations'=>array_replace($default,['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[$policy]]])];
$store=new EnterpriseOperationsStore($base.'/journal');$now=10000;$service=new EnterpriseApprovalService($store,static function()use(&$now):int{return $now;});
$request=['message'=>'Evacuate the east wing.','title'=>'Evacuate','phones'=>['1000'],'desktops'=>[],'is_test'=>true,'force_queue'=>false,
    'email_targets'=>['email_'.str_repeat('5',24)=>['address'=>'fixture@example.invalid']]];
$review=$service->request($settings,$request,'pbx:alice','alice-identity');$id=$review['review_id'];
check(!$review['allowed'],'review waits for another person');
rejected(fn()=>$service->approve($id,$settings,'pbx:alice',static fn()=>true),'self approval counted once');
rejected(fn()=>$service->approve($id,$settings,'pbx:mallory',static fn()=>true),'unassigned person');
rejected(fn()=>$service->claim($id,$settings,'pbx:alice',static fn()=>true),'one-person submission');
rejected(fn()=>$service->approve($id,$settings,'pbx:bob',static fn()=>false),'audience isolation');
check($service->approve($id,$settings,'pbx:bob',static fn()=>true,'bob-identity')['ready'],'two identities');
$approved=$service->claim($id,$settings,'pbx:bob',static fn()=>true);
$current=static fn($person,$frozen,$identity):bool=>$identity===($person==='pbx:alice'?'alice-identity':'bob-identity');
check($service->permits($settings,$approved,$current),'worker authority');
$runtime=$approved;$runtime['force_queue']=true;$runtime['delivery_id']='announcement-fixture';$runtime['delivery_timestamp']='2026-10-04T00:00:00Z';
$runtime['email_targets']['email_'.str_repeat('5',24)]['message_id']='<fixture@example.invalid>';
check($service->permits($settings,$runtime,$current),'queue metadata preserves approval');
$changed=$approved;$changed['message']='Different instructions';check(!$service->permits($settings,$changed,$current),'content mutation');
$changed=$approved;$changed['phones'][]='2000';check(!$service->permits($settings,$changed,$current),'recipient addition');
check(!$service->permits($settings,$approved,static fn()=>false),'revoked account');
check(!$service->permits($settings,$approved,static fn($p,$r,$i)=>$p!=='pbx:alice'),'one approver identity changed');
$newPolicy=$settings;$newPolicy['enterprise_operations']['dual_approval']['policies'][0]['expires_seconds']=400;
check(!$service->permits($newPolicy,$approved,$current),'policy revision');
rejected(fn()=>$service->claim($id,$settings,'pbx:bob',static fn()=>true),'duplicate send');
$service->finish($id,['success'=>true,'job_id'=>'job_'.str_repeat('6',32)]);
check($service->permits($settings,$approved,$current),'submitted job retains approval before expiry');
$now=10300;check(!$service->permits($settings,$approved,$current),'absolute expiry');
$now=10299;rejected(fn()=>$service->permits($settings,$approved,$current),'clock rollback');
$now=10301;$uncertain=$service->request($settings,$request,'pbx:alice','alice-identity');
$service->approve($uncertain['review_id'],$settings,'pbx:bob',static fn()=>true,'bob-identity');
$service->claim($uncertain['review_id'],$settings,'pbx:bob',static fn()=>true);$service->finish($uncertain['review_id'],['success'=>false]);
rejected(fn()=>$service->claim($uncertain['review_id'],$settings,'pbx:bob',static fn()=>true),'uncertain transport never replayed');
unlink($base.'/journal/worker-state.json');rejected(fn()=>$store->transaction(static fn()=>null),'established journal disappeared');

$images=new EnterpriseFloorplanStore($base.'/images');$pixel=imagecreatetruecolor(64,64);imagefill($pixel,0,0,imagecolorallocate($pixel,22,44,66));ob_start();imagepng($pixel);$png=ob_get_clean();imagedestroy($pixel);
$saved=$images->put($png.'<?php attacker_payload(); ?>');$image=$images->read($saved['image_id']);
check(!str_contains($image['bytes'],'attacker_payload'),'upload re-encodes and strips appended source');
check($image['type']==='image/png'&&$image['width']===64,'private normalized image');
check($images->put($png)['image_id']===$saved['image_id'],'same image deduplicated');
rejected(fn()=>$images->put('<svg onload="alert(1)"></svg>'),'SVG script rejected');
rejected(fn()=>$images->read('../../etc/passwd'),'path traversal');
unlink($base.'/images/'.$saved['image_id']);symlink('/etc/passwd',$base.'/images/'.$saved['image_id']);
rejected(fn()=>$images->read($saved['image_id']),'image symlink');
if(!interface_exists('BMO')){interface BMO{}}
require_once __DIR__.'/../slsmassnotifyserver/Slsmassnotifyserver.class.php';
$stateMethod=(new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->getMethod('enterpriseDrillDeliveryState');
foreach(['planned','awaiting_approval','queued','not_submitted','uncertain'] as $expected){
    $result=['success'=>true,'id'=>'inc_'.str_repeat('7',32),'state'=>$expected==='planned'?'planned':'open','operations'=>[['kind'=>'initial','state'=>$expected]]];
    check($stateMethod->invoke(null,$result)===$expected,'drill retains '.$expected.' instead of launched');
}
$recovery=(new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->getMethod('disableEnterpriseAfterRecovery');
$module=(new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->newInstanceWithoutConstructor();
$recover=['enterprise_cluster'=>['enabled'=>true,'witness_epoch'=>str_repeat('8',64)],'enterprise_operations'=>['enabled'=>true],
    'enterprise_integrations'=>['enabled'=>'1'],'labs_safety'=>['receipts'=>['enterprise_cluster'=>['actor'=>'fixture']]]];
$arguments=[&$recover];check($recovery->invokeArgs($module,$arguments),'recovery disables Labs');
check($recover['enterprise_cluster']['enabled']===false&&$recover['enterprise_cluster']['witness_epoch']===''&&$recover['enterprise_operations']['enabled']===false
    &&$recover['enterprise_integrations']['enabled']==='0'&&$recover['labs_safety']['receipts']===[],'recovery requires fresh reviewed authority and danger acceptance');
class DrillReportFixture
{
    use \FreePBX\modules\SlsEnterpriseOperations;
    public array $settings=[];
    public array $incidents=[];
    public function __construct(private EnterpriseOperationsStore $journal) {}
    protected function enterpriseOperationsStore(): EnterpriseOperationsStore { return $this->journal; }
    public function currentOperator(): array { return ['id'=>'recovery_admin','username'=>'alice','operator_role'=>'administrator']; }
    public function getActiveSettings(): array { return $this->settings; }
    public function incidentStore(): object { return new class($this->incidents) {
        public function __construct(private array $records) {}
        public function read(string $id): ?array { return $this->records[$id]??null; }
    }; }
}
$drillStore=new EnterpriseOperationsStore($base.'/drill-report');$drillFixture=new DrillReportFixture($drillStore);
$assignment=['id'=>'drill_'.str_repeat('9',24),'name'=>'Evacuation exercise','enabled'=>true,'template_id'=>$template,
    'site_ids'=>[],'owner_id'=>'pbx:alice','reviewer_ids'=>[],'due_at'=>'2026-10-01T12:00:00Z','objectives'=>['Check the east exit.']];
$drillFixture->settings=['enterprise_operations'=>array_replace($default,['enabled'=>true,'drills'=>['enabled'=>true,'assignments'=>[$assignment]]])];
$incidentId='inc_'.str_repeat('a',32);$runId='run_'.str_repeat('b',64);
$drillStore->transaction(static function(array &$state)use($assignment,$incidentId,$runId):void {
    $state['drills'][$runId]=['id'=>$runId,'assignment'=>$assignment,'state'=>'awaiting_approval','incident_id'=>$incidentId,'reviews'=>[]];
});
$drillFixture->incidents[$incidentId]=['id'=>$incidentId,'state'=>'open','operations'=>[['kind'=>'initial','state'=>'awaiting_approval']]];
check($drillFixture->getEnterpriseDrillReport()['assignments'][0]['status']==='awaiting_approval','pending drill is not labeled launched');
$drillFixture->incidents[$incidentId]['operations'][0]['state']='queued';
$report=$drillFixture->getEnterpriseDrillReport()['assignments'][0];
check($report['status']==='launched_unreviewed'&&$report['runs'][0]['state']==='queued','report observes later approved admission');
check($drillStore->transaction(static fn(array &$state):array=>$state['drills'])[$runId]['state']==='awaiting_approval','report projection leaves frozen drill journal intact');
$drillFixture->incidents[$incidentId]['operations'][0]['state']='not_submitted';
check($drillFixture->getEnterpriseDrillReport()['assignments'][0]['status']==='not_submitted','rejected drill is not labeled launched');
echo "Enterprise operations: safety timing, shifts/DST, dual approval, durable replay and private images passed.\n";
