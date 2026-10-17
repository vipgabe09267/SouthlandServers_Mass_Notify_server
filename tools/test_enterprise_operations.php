<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/LabsSafety.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseAdministration.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseApprovalService.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseFloorplanStore.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseFloorplanAudit.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseFloorplanReferences.php';
use SLS\MassNotify\{LabsSafety,EnterpriseOperationsConfig,EnterpriseOperationsStore,EnterpriseApprovalService,EnterpriseFloorplanStore,EnterpriseFloorplanAudit,EnterpriseFloorplanReferences};
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
$session=['_sls_labs_challenges'=>'damaged'];$recovered=LabsSafety::begin($session,'enterprise_cluster','alice','session-a',100,1000000000);
check(is_array($session['_sls_labs_challenges']),'damaged challenge container was not replaced');
$session['_sls_labs_challenges']['enterprise_cluster']['nonce']=[];
rejected(fn()=>LabsSafety::accept($session,['feature'=>'enterprise_cluster','challenge'=>$recovered['challenge'],'agree'=>true],'alice','session-a',105,6000000000),'malformed server-side challenge');
rejected(fn()=>LabsSafety::begin($session,'enterprise_cluster',str_repeat('a',201),'session-a',100,1000000000),'oversized administrator identity');

$safetyRoot=$base.'/safety';mkdir($safetyRoot,0700);
$safetyClass='SafetyMergeRuntimeFixture';
eval('class '.$safetyClass.' { use \\FreePBX\\modules\\SlsEnterpriseAdministration;
    const SETTINGS_JSON='.var_export($safetyRoot.'/active.json',true).'; const PENDING_SETTINGS_JSON='.var_export($safetyRoot.'/pending.json',true).';
    public function saveSafety(array $value):void{$this->saveEnterpriseNamespace("labs_safety",$value);}
    public function currentOperator():array{return ["id"=>"recovery_admin","username"=>"alice","operator_role"=>"administrator"];}
    public function acquireAnnouncementActivityLock($exclusive=true,$timeout=5){return fopen('.var_export($safetyRoot.'/activity.lock',true).',"c+b");}
    public function acquireSettingsLock($quiesce=false){$h=fopen('.var_export($safetyRoot.'/settings.lock',true).',"c+b");flock($h,LOCK_EX);return $h;}
    public function releaseSettingsLock($h):void{flock($h,LOCK_UN);fclose($h);}
    public function releaseNativeBackupFileLock($h):void{if(is_resource($h))fclose($h);}
    public function loadSettingsFile($path):array{return json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}
    public function configurationPathMetadata($path){return lstat($path)?:null;}
    public function writeSettingsFileUnlocked($path,array $value,$backup):void{file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));}
    public function normalizeSettings(array $value):array{return $value;}
    public function getDefaultSettings():array{return ["labs_safety"=>\\SLS\\MassNotify\\LabsSafety::defaults()];}
    public function rememberSettingsFingerprint($path):void{}
}');
$initialSafety=['labs_safety'=>LabsSafety::defaults()];file_put_contents($safetyRoot.'/active.json',json_encode($initialSafety));chmod($safetyRoot.'/active.json',0600);file_put_contents($safetyRoot.'/pending.json',json_encode($initialSafety));chmod($safetyRoot.'/pending.json',0600);
$safetyFixture=new $safetyClass();
$clusterReceipt=['schema'=>1,'receipts'=>['enterprise_cluster'=>$receipt['receipt']]];
$identityReceipt=['schema'=>1,'receipts'=>['enterprise_identity'=>['revision'=>LabsSafety::revision(),'accepted_at'=>106,'actor'=>'bob']]];
$safetyFixture->saveSafety($clusterReceipt);$safetyFixture->saveSafety($identityReceipt);
foreach([$safetyRoot.'/active.json',$safetyRoot.'/pending.json'] as $path){$saved=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);check(isset($saved['labs_safety']['receipts']['enterprise_cluster'],$saved['labs_safety']['receipts']['enterprise_identity']),'concurrent Labs acknowledgments replaced one another');}

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
rejected(fn()=>EnterpriseOperationsConfig::timestamp('2026-10-01T00:00:00+99:00','Fixture deadline'),'invalid timezone offset');
rejected(fn()=>EnterpriseOperationsConfig::timestamp('2026-02-30T00:00:00Z','Fixture deadline'),'invalid calendar date');

$policy=['id'=>'approval_'.str_repeat('4',24),'name'=>'Phone review','enabled'=>true,'channels'=>['phones'],
    'approver_ids'=>['pbx:alice','pbx:bob'],'expires_seconds'=>300];
$settings=['enterprise_operations'=>array_replace($default,['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[$policy]]])];
$labsPolicy=$policy;$labsPolicy['channels']=['browser_recipient_ids','connector_ids'];$labsSettings=$settings;$labsSettings['enterprise_operations']['dual_approval']['policies']=[$labsPolicy];
check(count(EnterpriseOperationsConfig::policies($labsSettings,['browser_recipient_ids'=>['sub_'.str_repeat('7',24)]]))===1,'browser approval policy omitted');
check(count(EnterpriseOperationsConfig::policies($labsSettings,['connector_ids'=>['conn_'.str_repeat('7',24)]]))===1,'connector approval policy omitted');
check(EnterpriseOperationsConfig::policies($labsSettings,['phones'=>['1000']])===[],'Labs policy matched another channel');
$store=new EnterpriseOperationsStore($base.'/journal');$now=10000;$service=new EnterpriseApprovalService($store,static function()use(&$now):int{return $now;});
$request=['message'=>'Evacuate the east wing.','title'=>'Evacuate','phones'=>['1000'],'desktops'=>[],'is_test'=>true,'force_queue'=>false,
    'email_targets'=>['email_'.str_repeat('5',24)=>['address'=>'fixture@example.invalid']]];
$review=$service->request($settings,$request,'pbx:alice','alice-identity');$id=$review['review_id'];
check(!$review['allowed'],'review waits for another person');
$validReview=$store->transaction(static fn(array &$state):array=>$state['approvals'][$id]);
foreach([
    array_replace($validReview,['state'=>'unknown']),
    $validReview+['unexpected'=>true],
    array_replace($validReview,['approvals'=>[$policy['id']=>'not-an-approval-map']]),
    array_replace($validReview,['approval_identities'=>['pbx:alice'=>'']]),
] as $damaged){rejected(static fn()=>EnterpriseApprovalService::validateRecord($damaged),'malformed nested approval record');}
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

$phonePolicy=$policy;
$desktopPolicy=$policy;$desktopPolicy['id']='approval_'.str_repeat('8',24);$desktopPolicy['name']='Desktop review';
$desktopPolicy['channels']=['desktops'];$desktopPolicy['approver_ids']=['pbx:carol','pbx:dave'];
$disjointSettings=['enterprise_operations'=>array_replace($default,['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[$phonePolicy,$desktopPolicy]]])];
$disjointStore=new EnterpriseOperationsStore($base.'/disjoint');$disjointService=new EnterpriseApprovalService($disjointStore,static function()use(&$now):int{return $now;});
$disjointRequest=$request;$disjointRequest['desktops']=['fixture-desktop'];
$disjoint=$disjointService->request($disjointSettings,$disjointRequest,'pbx:alice','alice-identity');
$disjointService->approve($disjoint['review_id'],$disjointSettings,'pbx:bob',static fn()=>true,'bob-identity');
$disjointService->approve($disjoint['review_id'],$disjointSettings,'pbx:carol',static fn()=>true,'carol-identity');
check($disjointService->approve($disjoint['review_id'],$disjointSettings,'pbx:dave',static fn()=>true,'dave-identity')['ready'],'disjoint policies did not become ready');
rejected(fn()=>$disjointService->claim($disjoint['review_id'],$disjointSettings,'pbx:erin',static fn()=>true),'unassigned sender claimed disjoint review');
check(isset($disjointService->claim($disjoint['review_id'],$disjointSettings,'pbx:carol',static fn()=>true)['_enterprise_approval']),'authorized member of one disjoint policy could not submit');
unlink($base.'/journal/worker-state.json');rejected(fn()=>$store->transaction(static fn()=>null),'established journal disappeared');

if(!function_exists('SLS\\MassNotify\\fwrite')){
    eval('namespace SLS\\MassNotify; function fwrite($stream,$data,$length=null){$meta=\\stream_get_meta_data($stream);$limit=!empty($GLOBALS["sls_floorplan_force_short_write"])&&\\str_contains((string)($meta["uri"]??""),"/.upload-")?73:$length;if($limit===null){return \\fwrite($stream,$data);}return \\fwrite($stream,$data,\\min((int)$limit,\\strlen($data)));} function rename($from,$to){if(!empty($GLOBALS["sls_floorplan_replace_staging"])&&\\str_contains($from,"/.upload-")){\\unlink($from);\\file_put_contents($from,"substituted");\\chmod($from,0600);}return \\rename($from,$to);}');
}
$GLOBALS['sls_floorplan_force_short_write']=true;
$images=new EnterpriseFloorplanStore($base.'/images');$pixel=imagecreatetruecolor(64,64);imagefill($pixel,0,0,imagecolorallocate($pixel,22,44,66));ob_start();imagepng($pixel);$png=ob_get_clean();imagedestroy($pixel);
rejected(fn()=>$images->put($png.'<?php attacker_payload(); ?>'),'image trailing source rejected');
$saved=$images->put($png);$image=$images->read($saved['image_id']);
$GLOBALS['sls_floorplan_force_short_write']=false;
check(!str_contains($image['bytes'],'attacker_payload'),'stored image contains appended source');
check($image['type']==='image/png'&&$image['width']===64,'private normalized image');
check($images->put($png)['image_id']===$saved['image_id'],'same image deduplicated');
$otherPixel=imagecreatetruecolor(63,64);imagefill($otherPixel,0,0,imagecolorallocate($otherPixel,33,55,77));ob_start();imagepng($otherPixel);$otherPng=ob_get_clean();imagedestroy($otherPixel);
$GLOBALS['sls_floorplan_replace_staging']=true;rejected(fn()=>$images->put($otherPng),'replaced image staging');$GLOBALS['sls_floorplan_replace_staging']=false;
foreach(glob($base.'/images/img_*') as $candidate){if(basename($candidate)!==$saved['image_id']){unlink($candidate);}}
$temporary=$base.'/images/.upload-'.str_repeat('f',32);file_put_contents($temporary,$png);chmod($temporary,0600);touch($temporary,time()-90000);
check($images->usage()['pending_upload_count']===1,'interrupted upload quota is visible');
$images->put($png);
check(!file_exists($temporary)&&$images->usage()['image_count']===1,'cleanup removes old unpublished uploads and preserves published images');
rejected(fn()=>$images->put('<svg onload="alert(1)"></svg>'),'SVG script rejected');
rejected(fn()=>$images->read('../../etc/passwd'),'path traversal');
$storeRaw=static function(string $bytes)use($base):string{$id='img_'.hash('sha256',$bytes);file_put_contents($base.'/images/'.$id,$bytes);chmod($base.'/images/'.$id,0600);return $id;};
$truncated=substr($png,0,-1);$truncatedId=$storeRaw($truncated);rejected(fn()=>$images->read($truncatedId),'truncated PNG read');unlink($base.'/images/'.$truncatedId);
$badCrc=$png;$idat=strpos($badCrc,'IDAT');$badCrc[$idat+4]=chr(ord($badCrc[$idat+4])^1);$badCrcId=$storeRaw($badCrc);rejected(fn()=>$images->read($badCrcId),'invalid PNG CRC read');unlink($base.'/images/'.$badCrcId);
$huge=$png;$huge=substr_replace($huge,pack('N',100000),16,4);$huge=substr_replace($huge,hash('crc32b',substr($huge,12,17),true),29,4);$hugeId=$storeRaw($huge);rejected(fn()=>$images->read($hugeId),'oversized PNG dimensions read');unlink($base.'/images/'.$hugeId);
$pixel=imagecreatetruecolor(32,32);ob_start();imagejpeg($pixel,null,90);$jpeg=ob_get_clean();imagedestroy($pixel);
$jpegId=$storeRaw($jpeg);check($images->read($jpegId)['type']==='image/jpeg','complete JPEG read');unlink($base.'/images/'.$jpegId);
$jpegTrailing=$jpeg."hidden\xff\xd9";$jpegTrailingId=$storeRaw($jpegTrailing);rejected(fn()=>$images->read($jpegTrailingId),'JPEG bytes after first end marker');unlink($base.'/images/'.$jpegTrailingId);
file_put_contents($base.'/images/unexpected',$png);chmod($base.'/images/unexpected',0600);rejected(fn()=>$images->put($png),'unknown regular storage entry');unlink($base.'/images/unexpected');
mkdir($base.'/images/unexpected-directory',0700);rejected(fn()=>$images->usage(),'unknown storage directory');rmdir($base.'/images/unexpected-directory');
$unsafeName='img_'.str_repeat('e',64);symlink('/etc/passwd',$base.'/images/'.$unsafeName);rejected(fn()=>$images->usage(),'matching image symlink');unlink($base.'/images/'.$unsafeName);
if(function_exists('posix_mkfifo')){posix_mkfifo($base.'/images/'.$unsafeName,0600);rejected(fn()=>$images->usage(),'matching image FIFO');unlink($base.'/images/'.$unsafeName);}
$hardlinkSource=$base.'/hardlink-source';file_put_contents($hardlinkSource,$png);chmod($hardlinkSource,0600);link($hardlinkSource,$base.'/images/'.$unsafeName);rejected(fn()=>$images->usage(),'matching image hard link');unlink($base.'/images/'.$unsafeName);unlink($hardlinkSource);
chmod($base.'/images/.storage.lock',0644);rejected(fn()=>$images->put($png),'public storage lock');chmod($base.'/images/.storage.lock',0600);
file_put_contents($base.'/images/.storage.lock','unexpected');rejected(fn()=>$images->usage(),'nonempty storage lock');file_put_contents($base.'/images/.storage.lock','');
$retirePixel=imagecreatetruecolor(65,64);imagefill($retirePixel,0,0,imagecolorallocate($retirePixel,66,44,22));ob_start();imagepng($retirePixel);$retirePng=ob_get_clean();imagedestroy($retirePixel);
$retireImage=$images->put($retirePng);$backupDirectory=$base.'/config-backups';mkdir($backupDirectory,0700);
$activeReference=['enterprise_operations'=>['floorplans'=>['plans'=>[['id'=>'plan_active','name'=>'Active plan','image_id'=>$retireImage['image_id']]]]]];
$pendingReference=['enterprise_operations'=>['floorplans'=>['plans'=>[['id'=>'plan_pending','name'=>'Staged plan','image_id'=>$retireImage['image_id']]]]]];
$backupPath=$backupDirectory.'/mass-notifications-20261015-120000-abcd.config';file_put_contents($backupPath,json_encode(['enterprise_operations'=>['floorplans'=>['plans'=>[['image_id'=>$retireImage['image_id']]]]]],JSON_THROW_ON_ERROR));chmod($backupPath,0600);
$references=EnterpriseFloorplanReferences::report($retireImage['image_id'],$activeReference,$pendingReference,$backupDirectory,true);
check($references['blocking_count']===3&&$references['backups_checked'],'active, staged and retained backup references');
check(array_column($references['references'],'source')===['active_configuration','configuration_backup','staged_configuration'],'floor-plan references have deterministic source order');
check(EnterpriseFloorplanReferences::report($retireImage['image_id'],$activeReference,$pendingReference,$backupDirectory,true)['reference_hash']===$references['reference_hash'],'floor-plan reference hash is deterministic');
rejected(fn()=>$images->retire($retireImage['image_id'],static function()use($references):array{if($references['blocking_count']){throw new DomainException('referenced');}return [];}),'referenced image retirement');
check($images->read($retireImage['image_id'])['type']==='image/png','referenced image was deleted');
unlink($backupPath);$unreferenced=EnterpriseFloorplanReferences::report($retireImage['image_id'],[],null,$backupDirectory,true);check($unreferenced['blocking_count']===0,'unreferenced report');
file_put_contents($backupDirectory.'/unexpected', '{}');chmod($backupDirectory.'/unexpected',0600);rejected(fn()=>EnterpriseFloorplanReferences::report($retireImage['image_id'],[],null,$backupDirectory,true),'unexpected backup entry');unlink($backupDirectory.'/unexpected');
$audit=new EnterpriseFloorplanAudit($base.'/floorplan-audit');$request=null;
rejected(fn()=>$audit->requested(['image_id'=>[],'bytes'=>8],'pbx:alice',str_repeat('0',64)),'malformed retirement image identifier');
$retired=$images->retire($retireImage['image_id'],function(array $image)use($audit,$unreferenced,&$request):array{$request=$audit->requested($image,'pbx:alice',$unreferenced['reference_hash']);return ['request_id'=>$request['event_id']];});
$completed=$audit->completed($retired['request_id']);check($completed['outcome']==='retired'&&!file_exists($base.'/images/'.$retireImage['image_id']),'audited image retirement');
check($audit->completed($retired['request_id'])['event_id']===$completed['event_id'],'retirement completion retry');
$recent=$audit->recent();check(count($recent)===2&&$recent[0]['kind']==='retirement_completed'&&$recent[1]['kind']==='retirement_requested','retirement audit chain');
$uncertainRequest=$audit->requested(['image_id'=>$saved['image_id'],'bytes'=>strlen($image['bytes'])],'pbx:alice',str_repeat('9',64));
check($audit->uncertain($uncertainRequest['event_id'])['outcome']==='uncertain','uncertain retirement outcome');
$auditPath=$base.'/floorplan-audit/floorplan_retirements.json';$damaged=json_decode(file_get_contents($auditPath),true,16,JSON_THROW_ON_ERROR);$damaged[$request['event_id']]['actor']='pbx:mallory';file_put_contents($auditPath,json_encode((object)$damaged,JSON_THROW_ON_ERROR));chmod($auditPath,0600);rejected(fn()=>$audit->recent(),'retirement audit tamper');
unlink($base.'/images/'.$saved['image_id']);symlink('/etc/passwd',$base.'/images/'.$saved['image_id']);
rejected(fn()=>$images->read($saved['image_id']),'image symlink');
if(!interface_exists('BMO')){interface BMO{}}
require_once __DIR__.'/../slsmassnotifyserver/Slsmassnotifyserver.class.php';
$stateMethod=(new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->getMethod('enterpriseDrillDeliveryState');
foreach(['planned','awaiting_approval','queued','not_submitted','uncertain'] as $expected){
    $result=['success'=>true,'id'=>'inc_'.str_repeat('7',32),'state'=>$expected==='planned'?'planned':'open','operations'=>[['kind'=>'initial','state'=>$expected]]];
    check($stateMethod->invoke(null,$result)===$expected,'drill retains '.$expected.' instead of launched');
}
check($stateMethod->invoke(null,['success'=>false,'delivery_started'=>false,'message'=>'Rejected'])==='not_submitted',
    'A pre-delivery drill rejection was mislabeled as an uncertain external submission.');
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
rejected(fn()=>$drillFixture->enterpriseOperationsAction('approval_approve',['review_id'=>[]]),'nested approval identifier');
rejected(fn()=>$drillFixture->enterpriseOperationsAction('floorplan_overlay',['plan_id'=>[],'incident_id'=>'inc_'.str_repeat('a',32)]),'nested floor-plan identifier');
rejected(fn()=>$drillFixture->enterpriseOperationsAction('drill_start',['assignment_id'=>[]]),'nested drill assignment identifier');
$drillStore->transaction(static fn(array &$state):bool=>true);
$drillJournal=$base.'/drill-report/worker-state.json';$drillBefore=file_get_contents($drillJournal);
rejected(fn()=>$drillStore->transaction(static function(array &$state):void{$state['drills']['run_'.str_repeat('e',64)]='malformed';}),'malformed durable drill row');
check(file_get_contents($drillJournal)===$drillBefore,'Rejected malformed drill changed durable history.');
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
$olderRun='run_'.str_repeat('c',64);$newerRun='run_'.str_repeat('d',64);
$drillStore->transaction(static function(array &$state)use($assignment,$olderRun,$newerRun):void {
    // Persist the newer reviewed run before an older unfinished run.
    $state['drills']=[
        $newerRun=>['id'=>$newerRun,'assignment'=>$assignment,'state'=>'queued','created_at'=>'2026-08-01T00:00:00Z','reviews'=>[['completed'=>[true]]]],
        $olderRun=>['id'=>$olderRun,'assignment'=>$assignment,'state'=>'not_submitted','created_at'=>'2026-07-01T00:00:00Z','reviews'=>[]],
    ];
});
$report=$drillFixture->getEnterpriseDrillReport()['assignments'][0];
check($report['status']==='reviewed','latest drill status must use creation time, not object insertion order');
check(array_column($report['runs'],'id')===[$olderRun,$newerRun],'live drill history ordered by creation time');
check($drillStore->maintain(strtotime('2026-10-13T00:00:00Z'),86400)['archived']['drills']===1,'reviewed run archived while older unfinished run remains live');
$report=$drillFixture->getEnterpriseDrillReport()['assignments'][0];
check($report['status']==='reviewed'&&array_column($report['runs'],'id')===[$olderRun,$newerRun],'archival must not change latest drill evidence');
echo "Enterprise operations: safety timing, shifts/DST, dual approval, durable replay and private images passed.\n";
