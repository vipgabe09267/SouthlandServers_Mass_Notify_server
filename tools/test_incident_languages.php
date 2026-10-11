<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/Incidents.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/EnterpriseOperations.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/OperatorAccess.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentLanguages;
use SLS\MassNotify\IncidentService;
use SLS\MassNotify\IncidentStore;
function language_check($value,string $message):void { if(!$value)throw new RuntimeException($message); }
function language_reject(callable $work):void { try{$work();}catch(InvalidArgumentException|RuntimeException $error){return;}throw new RuntimeException('Invalid language input accepted.'); }
$root=sys_get_temp_dir().'/sls-incident-languages-'.bin2hex(random_bytes(8));mkdir($root,0700);
register_shutdown_function(static function()use($root):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);});
$request=static fn(int $n):string=>str_pad(dechex($n),32,'0',STR_PAD_LEFT);
$voice='voice_'.str_repeat('a',24);$email='email_'.str_repeat('b',24);$sms='sms_'.str_repeat('c',24);
$template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Reviewed evacuation','title'=>'Leave {{location}}','message'=>'Leave {{location}} safely.',
    'fields'=>[['key'=>'location','label'=>'Affected location']],'severity'=>'critical',
    'delivery'=>['extensions'=>['1000','1001','1002'],'desktop_clients'=>['english','spanish','japanese'],'voice_recipient_ids'=>[$voice],
        'email_recipient_ids'=>[$email],'sms_recipient_ids'=>[$sms],'webhook_ids'=>['whole-site'],'audio_mode'=>'tts'],
    'roster'=>[['id'=>'person_spanish','name'=>'Spanish reader','desktop_username'=>'spanish']],
    'language_variants'=>[
        ['locale'=>'es','label'=>'Spanish','title'=>'Salga de {{location}}','message'=>'Salga de {{location}}.','review_note'=>'Fixture reviewed wording','reviewed'=>true],
        ['locale'=>'fr','label'=>'French','title'=>'Quittez {{location}}','message'=>'Quittez {{location}}.','review_note'=>'Fixture reviewed wording','reviewed'=>true],
        ['locale'=>'ja','label'=>'Japanese text','title'=>'{{location}} から退避','message'=>'{{location}} から退避してください。','review_note'=>'Fixture reviewed wording','reviewed'=>true],
    ]]);
$preferences=[['channel'=>'extensions','recipient_id'=>'1001','locale'=>'es'],['channel'=>'extensions','recipient_id'=>'1002','locale'=>'fr'],
    ['channel'=>'desktop_clients','recipient_id'=>'spanish','locale'=>'es'],['channel'=>'desktop_clients','recipient_id'=>'japanese','locale'=>'ja'],
    ['channel'=>'voice_recipient_ids','recipient_id'=>$voice,'locale'=>'es'],['channel'=>'email_recipient_ids','recipient_id'=>$email,'locale'=>'es'],
    ['channel'=>'sms_recipient_ids','recipient_id'=>$sms,'locale'=>'es'],['channel'=>'webhook_ids','recipient_id'=>'whole-site','locale'=>'it']];
$freeze=static function(array $delivery,string $locale=''):array{
    $delivery['_identities']=[];
    foreach(IncidentLanguages::CHANNELS as$key){foreach($delivery[$key]as$id)$delivery['_identities'][$key][$id]=hash('sha256',$id);}
    if(in_array($delivery['audio_mode'],['tts','tones_tts'],true))$delivery['_speech']=['locale'=>$locale,'path'=>'fixture:'.($locale ?: 'en'),'external_path'=>'fixture:'.($locale ?: 'en')];
    return $delivery;
};
$speech=static function(array $delivery,string $locale):array{unset($delivery['_speech']);if(in_array($delivery['audio_mode'],['tts','tones_tts'],true))$delivery['_speech']=['locale'=>$locale,'path'=>'fixture:'.($locale ?: 'en'),'external_path'=>'fixture:'.($locale ?: 'en')];return $delivery;};
$frozen=$freeze($template['delivery']);$planned=IncidentLanguages::plan($template,$frozen,$preferences,['location'=>'North'],'',false,$speech);
language_check(array_column($planned,'locale')===['','es','fr','ja'],'Recipients did not partition into reviewed languages with fallback.');
foreach(IncidentLanguages::CHANNELS as$key){$actual=[];foreach($planned as$batch)$actual=array_merge($actual,$batch['delivery'][$key]);$expected=$frozen[$key];sort($actual);sort($expected);language_check($actual===$expected,'A recipient was added, lost or duplicated: '.$key);}
language_check($planned[1]['delivery']['_speech']['locale']==='es'&&$planned[2]['delivery']['_speech']['locale']==='fr','Speech voice locale did not follow reviewed wording.');
language_check($planned[3]['delivery']['audio_mode']==='none'&&!isset($planned[3]['delivery']['_speech']),'Text-only Japanese recipient inherited an unsupported speech voice.');
language_check($planned[0]['delivery']['webhook_ids']===['whole-site'],'Unavailable Italian wording did not fall back.');
language_check(IncidentLanguages::plan($template,$frozen,[],['location'=>'North'],'',false,$speech)===[],'Default changed legacy one-message delivery.');
language_reject(static fn()=>IncidentConfig::recipientLanguages([$preferences[0],$preferences[0]]));
language_reject(static fn()=>IncidentConfig::recipientLanguages([['channel'=>'group_ids','recipient_id'=>'all','locale'=>'es']]));
language_reject(static fn()=>IncidentConfig::recipientLanguages([['channel'=>'extensions','recipient_id'=>'1000;System(bad)','locale'=>'es']]));
$context=['schema'=>1,'incident_id'=>'inc_'.str_repeat('a',32),'sequence'=>1,'kind'=>'initial','severity'=>'critical','is_test'=>false,
    'operation_request_id'=>$request(1),'language_variant'=>'es','language_batch'=>1,'language_batches'=>2,'expires_at'=>'2026-10-09T10:00:00+00:00'];
language_reject(static fn()=>IncidentConfig::context(array_replace($context,['expires_at'=>'2026-02-30T10:00:00+00:00'])));
language_reject(static fn()=>IncidentConfig::context(array_replace($context,['language_batch'=>3])));

class LanguageScopeFixture {
    use \FreePBX\modules\SlsIncidents;
    public IncidentStore $store;
    protected function incidentStore():IncidentStore{return $this->store;}
    public function scope(array $context,array $proof):bool{$this->incidentSubmissionContext=$context;$this->incidentLanguageSubmissionProof=$proof;return $this->incidentLanguageBatchMayBypassCooldown();}
    public function approve(array $request,array $result):bool{return $this->recordIncidentLanguageApproval($request,$result);}
    public function maySubmit(array $request):bool{return $this->incidentLanguageApprovalMaySubmit($request);}
}
$store=new IncidentStore($root.'/main');$fixture=new LanguageScopeFixture();$fixture->store=$store;$now=time();$sent=[];$mode='normal';$actor=['identity'=>'Fixture operator','source'=>'test'];
$service=new IncidentService($store,static fn(string $id):array=>$template,$freeze,
    function(array $delivery,string $message,string $title,array $context,array $actor)use(&$sent,&$mode,$fixture):array{
        IncidentConfig::context($context);$proof=compact('delivery','message','title','actor');
        if($mode==='normal'&&$context['language_batch']>1){language_check($fixture->scope($context,$proof),'Verified same-operation batch could not bypass its own cooldown.');$forged=$proof;$forged['message']='Injected';language_check(!$fixture->scope($context,$forged),'Changed message bypassed cooldown.');}
        $sent[]=compact('delivery','message','title','context','actor');$n=count($sent);
        if($mode==='uncertain'&&$context['language_batch']===2)throw new RuntimeException('Fixture interrupted publication');
        if($mode==='first_refused'&&$context['language_batch']===1)return ['success'=>false,'delivery_started'=>false,'message'=>'Cooldown'];
        if($mode==='first_refused'&&$context['language_batch']===2)language_check(!$fixture->scope($context,$proof),'Refused first batch created a cooldown bypass.');
        if($mode==='approval')return ['success'=>true,'awaiting_approval'=>true,'review_id'=>'review_'.str_pad(dechex($n),32,'0',STR_PAD_LEFT),'message'=>'Awaiting independent review'];
        return ['success'=>true,'job_id'=>'job_'.str_pad(dechex($n),32,'0',STR_PAD_LEFT)];
    },static fn(string $id):array=>['success'=>true,'job_id'=>$id,'receipts'=>[['channel'=>'desktop','target'=>'fixture','state'=>'received']]],
    static function()use(&$now):int{return $now;},
    static function(array $template,array $delivery,array $fields,string $fallback,bool $drill)use(&$preferences,$speech):array{return IncidentLanguages::plan($template,$delivery,$preferences,$fields,$fallback,$drill,$speech);});
$start=['template_id'=>$template['id'],'request_id'=>$request(1),'fields'=>['location'=>'North'],'is_test'=>false];
$incident=$service->start($start,$actor);$id=$incident['id'];language_check(count($sent)===4&&$incident['operations'][$request(1)]['state']==='queued','Language cohort jobs were not grouped in one accepted operation.');
language_check(count(array_unique(array_column(array_column($sent,'context'),'expires_at')))===1,'Language expiry changed between publications.');
language_check(array_column(array_column($sent,'context'),'sequence')===[1,1,1,1],'Language cohorts became unrelated incident operations.');
$service->start($start,$actor);language_check(count($sent)===4,'Duplicate start resent language jobs.');
$read=$service->get($id);foreach($read['operations'][$request(1)]['language_jobs']as$batch)language_check(isset($batch['delivery_result']),'A language cohort lost its own receipt result.');
$preferences=[];$template['language_variants'][0]['message']='Changed {{location}}';$now++;
$translated=[['locale'=>'es','message'=>'Manténgase fuera.','review_note'=>'Fixture review','reviewed'=>true]];
$update=$service->update($id,['request_id'=>$request(2),'message'=>'Remain outside.','language_messages'=>$translated],$actor);
$new=array_slice($sent,4);language_check(count($new)===4,'Saved preferences changed an existing incident audience.');
language_check($new[1]['message']==='Manténgase fuera.'&&$new[1]['delivery']['_speech']['locale']==='es','Reviewed update did not retain matching Spanish voice.');
language_check($new[2]['message']==='Remain outside.'&&$new[2]['delivery']['_speech']['locale']==='','Missing update translation reused old French wording or voice.');
language_reject(fn()=>$service->update($id,['request_id'=>$request(3),'message'=>'Test','language_messages'=>[array_replace($translated[0],['reviewed'=>false])]],$actor));
language_reject(fn()=>$service->update($id,['request_id'=>$request(3),'message'=>'Test','language_messages'=>[array_replace($translated[0],['locale'=>'it'])]],$actor));
$mode='uncertain';$now++;$clear=['request_id'=>$request(3),'kind'=>'all_clear','message'=>'All clear.'];$before=count($sent);
$uncertain=$service->update($id,$clear,$actor);language_check($uncertain['state']==='open'&&$uncertain['operations'][$request(3)]['state']==='uncertain','Partial all-clear closed the incident.');
language_check(count($sent)===$before+2,'Unknown result did not stop later cohorts.');$service->update($id,$clear,$actor);language_check(count($sent)===$before+2,'Uncertain all-clear automatically replayed.');
$mode='normal';$now++;$complete=$service->update($id,['request_id'=>$request(4),'kind'=>'all_clear','message'=>'All clear.'],$actor);language_check($complete['state']==='closed','All accepted language jobs did not close explicit all-clear.');
$page=$service->report($id);language_check(!$page['complete']&&$page['next_job_offset']!==null,'Language receipt pagination was not bounded.');$next=$service->report($id,$page['next_job_offset'],$page['revision']);language_check($next['complete'],'Language report lost later child jobs.');

$preferences=[['channel'=>'extensions','recipient_id'=>'1001','locale'=>'es']];$mode='normal';$now++;$other=$service->start(array_replace($start,['request_id'=>$request(10)]),$actor);
$mode='approval';$now++;$before=count($sent);$approved=$service->update($other['id'],['request_id'=>$request(11),'kind'=>'all_clear','message'=>'Reviewed all-clear.'],$actor);
language_check($approved['state']==='open'&&$approved['operations'][$request(11)]['state']==='awaiting_approval','Language approvals closed the incident before review.');
$reviews=array_slice($sent,$before);
foreach($reviews as$index=>$row){$saved=$store->read($other['id'])['operations'][$request(11)]['language_jobs'][$index];$review=['incident_context'=>$row['context'],'_enterprise_approval'=>['id'=>$saved['review_id']]];
    language_check($fixture->maySubmit($review),'Current reviewed language batch was fenced.');
    $expired=$review;$expired['incident_context']['expires_at']='2020-01-01T00:00:00+00:00';language_check(!$fixture->maySubmit($expired),'Expired approval was allowed to extend an incident deadline.');
    if($index===0){$bad=$review;$bad['incident_context']['sequence']++;language_reject(fn()=>$fixture->approve($bad,['job_id'=>'job_'.str_repeat('e',32)]));}
    $fixture->approve($review,['job_id'=>'job_'.str_pad(dechex(100+$index),32,'0',STR_PAD_LEFT)]);
    language_check(($store->read($other['id'])['state']==='closed')===($index===count($reviews)-1),'All-clear approval aggregation closed at the wrong time.');
    language_reject(fn()=>$fixture->approve($review,['job_id'=>'job_'.str_repeat('e',32)]));
    language_check(!$fixture->maySubmit($review),'Consumed or closed incident approval could submit again.');
}

// Execute the real review controller and durable review service. Only the
// final delivery adapter and local PBX identities are inert fixtures.
define('LANGUAGE_CONTROLLER_DATA',$root.'/controller-jobs');
class LanguageApprovalController extends LanguageScopeFixture {
    use \FreePBX\modules\SlsEnterpriseOperations;
    public const PLUGIN_DATA_DIR=LANGUAGE_CONTROLLER_DATA;
    public \SLS\MassNotify\EnterpriseApprovalService $reviews;
    public array $settings=[];public int $deliveries=0;private $announcementAdmissionLock;
    public function currentOperator():array{return ['id'=>'recovery_admin','username'=>'alice','operator_role'=>'administrator','audience'=>['unrestricted'=>true]];}
    public function getActiveSettings():array{return $this->settings;}
    protected function enterpriseApprovalService():\SLS\MassNotify\EnterpriseApprovalService{return $this->reviews;}
    protected function enterpriseApprovalPermitted(array $request):bool{return true;}
    private function acquireAnnouncementActivityLock(...$arguments){return fopen('php://temp','w+b');}
    private function releaseNativeBackupFileLock($handle):void{if(is_resource($handle))fclose($handle);}
    private function withOperator(array $principal,callable $work):array{return $work();}
    private function deliverResolvedAnnouncement(array $request):array{$this->deliveries++;return ['success'=>true,'job_id'=>'job_'.str_repeat('f',32)];}
}
$mode='approval';$before=count($sent);$pending=$service->start(array_replace($start,['request_id'=>$request(100)]),$actor);$rows=array_slice($sent,$before);
$controller=new LanguageApprovalController();$controller->store=$store;
$controller->settings=['enterprise_operations'=>['enabled'=>true,'dual_approval'=>['enabled'=>true,'policies'=>[
    ['id'=>'approval_'.str_repeat('d',24),'name'=>'Fixture review','enabled'=>true,'channels'=>['phones'],'approver_ids'=>['pbx:alice','pbx:bob'],'expires_seconds'=>900]]]]];
$controller->reviews=new \SLS\MassNotify\EnterpriseApprovalService(new \SLS\MassNotify\EnterpriseOperationsStore($root.'/controller-review-store'));
foreach(array_slice($rows,0,2)as$index=>$row){
    $context=$row['context'];
    if($index===1){$context['expires_at']=gmdate('c',time()-100);$store->transaction($pending['id'],static function(array $record)use($request):array{$record['operations'][$request(100)]['claimed_at']=gmdate('c',time()-700);return $record;});}
    $reviewRequest=['incident_context'=>$context,'phones'=>$row['delivery']['extensions'],'message'=>$row['message'],'title'=>$row['title']];
    $review=$controller->reviews->request($controller->settings,$reviewRequest,'pbx:sender');
    $controller->reviews->approve($review['review_id'],$controller->settings,'pbx:alice',static fn():bool=>true);
    $controller->reviews->approve($review['review_id'],$controller->settings,'pbx:bob',static fn():bool=>true);
    $store->transaction($pending['id'],static function(array $record)use($request,$index,$review):array{$record['operations'][$request(100)]['language_jobs'][$index]['review_id']=$review['review_id'];return $record;});
    $result=$controller->actEnterpriseApproval('submit',$review['review_id']);
    language_check($index===0 ? isset($result['job_id']) : ($result['delivery_started']??null)===false,'Review controller did not honor the frozen language deadline.');
}
language_check($controller->deliveries===1&&$store->read($pending['id'])['state']==='open','Expired approval reached the delivery adapter or closed an incident.');
echo "Incident language routing, immutable batches, receipts, scoped cooldown and approval tests passed.\n";
