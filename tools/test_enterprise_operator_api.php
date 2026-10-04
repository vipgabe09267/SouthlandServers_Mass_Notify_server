<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/Operators.php';
require_once __DIR__.'/../slsmassnotifyserver/Incidents.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseOperations.php';
require_once __DIR__.'/../slsmassnotifyserver/api/sls-mass-notify/contract.php';
use SLS\MassNotify\{ApiSecurity,ControlContract,IncidentConfig,EnterpriseOperationsConfig,EnterpriseOperationsStore,EnterpriseFloorplanStore};

$base=sys_get_temp_dir().'/sls-enterprise-operator-api-'.bin2hex(random_bytes(8));mkdir($base,0700);
define('ENTERPRISE_OPERATOR_API_FIXTURE',$base);
$checks=0;
function checkEnterpriseOperator(bool $ok,string $message):void{global $checks;$checks++;if(!$ok){throw new RuntimeException($message);}}
function rejectEnterpriseOperator(callable $work,string $message):void{try{$work();}catch(DomainException|InvalidArgumentException $expected){checkEnterpriseOperator(true,$message);return;}throw new RuntimeException($message);}
class EnterpriseOperatorLoginFixture {public function getAmpUser($username){return ['username'=>$username,'mode'=>'database','sections'=>['*'],'password_sha1'=>sha1('isolated identity '.$username)];}}
class EnterpriseOperatorApiFixture
{
    use \FreePBX\modules\SlsOperators;
    use \FreePBX\modules\SlsIncidents;
    use \FreePBX\modules\SlsEnterpriseOperations;
    const PLUGIN_DATA_DIR=ENTERPRISE_OPERATOR_API_FIXTURE;
    public array $settings=[],$submissions=[];
    public bool $reviewSubmissions=false;
    public ?array $human=null;
    public function getActiveSettings(){return $this->settings;}
    public function currentOperator():array{return $this->human??throw new DomainException('A human login is required.');}
    protected function enterpriseOperationsStore():EnterpriseOperationsStore{return new EnterpriseOperationsStore(self::PLUGIN_DATA_DIR.'/operations');}
    public function getConfiguredPjsipExtensionNumbers(){return ['1000','2000'];}
    public function getAnnouncementGroups(){return $this->settings['announcement_groups']??[];}
    public function getDesktopClients($settings){return $settings['desktop_clients']??[];}
    public function outboundVoiceTargets($settings){return [];}
    public function normalizeWebhookDestinations($settings,$kind){return [];}
    public function getAvailableTones(){return [];}
    public function getAvailableSystemSounds(){return [];}
    public function getPbxDateTimeZone(){return new DateTimeZone('UTC');}
    public function locationCatalog($settings){return ['extensions'=>[],'desktop_client_ids'=>[],'voice_recipient_ids'=>[],'email_recipient_ids'=>[],'sms_recipient_ids'=>[],'webhook_ids'=>[]];}
    private function acquireAnnouncementActivityLock(...$args){return null;}
    private function releaseNativeBackupFileLock($lock){}
    protected function submitIncidentAnnouncement(array $delivery,string $message,string $title,array $context,array $actor):array
    {$this->submissions[]=['delivery'=>$delivery,'actor'=>$actor,'principal'=>$GLOBALS['sls_control_principal']??null];if($this->reviewSubmissions){return $this->enterpriseReviewAnnouncement(['message'=>$message,'title'=>$title,'phones'=>$delivery['extensions'],
        'is_test'=>$context['is_test'],'incident_context'=>$context])??['success'=>false,'delivery_started'=>false];}return ['success'=>true,'job_id'=>'job_'.str_repeat('a',32)];}
}
try{
    $fixture=new EnterpriseOperatorApiFixture();
    $issued=ApiSecurity::issue(['name'=>'Scoped fixture','scopes'=>['read','send'],'audience'=>['unrestricted'=>false,'extensions'=>['1000']]]);
    $readOnly=ApiSecurity::issue(['name'=>'Read fixture','scopes'=>['read'],'audience'=>['unrestricted'=>false,'extensions'=>['1000']]]);
    $template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Permitted fixture','title'=>'Fixture','message'=>'Message {{place}}',
        'fields'=>[['key'=>'place','label'=>'Place']], 'delivery'=>['extensions'=>['1000'],'audio_mode'=>'none'],
        'roster'=>[['id'=>'private_person','name'=>'Private roster name','location'=>'Private room','desktop_username'=>'']]]);
    $offsite=IncidentConfig::template(['id'=>'tpl_'.str_repeat('b',24),'name'=>'Off-site ladder','title'=>'Off-site title','message'=>'No',
        'delivery'=>['extensions'=>['1000']], 'roster'=>$template['roster'], 'escalation'=>['enabled'=>true,'steps'=>[
            ['name'=>'Local','after_seconds'=>60,'delivery'=>['extensions'=>['1000']]],
            ['name'=>'Off-site','after_seconds'=>120,'delivery'=>['extensions'=>['2000']]]]]]);
    $shifted=IncidentConfig::template(['id'=>'tpl_'.str_repeat('c',24),'name'=>'Shifted fixture','title'=>'Shift','message'=>'No','delivery'=>['extensions'=>['1000']]]);
    $operations=EnterpriseOperationsConfig::defaults();$operations['enabled']=true;
    $fixture->settings=['control_api'=>['enabled'=>true,'credentials'=>[$issued['credential'],$readOnly['credential']]],
        'incident_workflows'=>['schema'=>1,'templates'=>[$template,$offsite,$shifted]],'enterprise_operations'=>$operations];
    $principal=ApiSecurity::authenticate($fixture->settings['control_api'],$issued['secret']);$GLOBALS['sls_control_principal']=$principal;
    $listing=$fixture->enterpriseControlTemplates($principal);
    checkEnterpriseOperator(array_column($listing['templates'],'id')===[$template['id'],$shifted['id']],'A later off-site escalation was advertised.');
    checkEnterpriseOperator(!str_contains(json_encode($listing),'Private roster name')&&!isset($listing['templates'][0]['delivery']),'Template discovery exposed a private roster or delivery snapshot.');
    $route=['id'=>'shift_'.str_repeat('d',24),'name'=>'Current shift','enabled'=>true,'template_ids'=>[$shifted['id']],
        'timezone'=>'UTC','days'=>[1,2,3,4,5,6,7],'start'=>gmdate('H:i'),'end'=>gmdate('H:i',time()+120),'delivery'=>['extensions'=>['2000']]];
    $fixture->settings['enterprise_operations']['shift_routing']=['enabled'=>true,'routes'=>[$route]];
    checkEnterpriseOperator(array_column($fixture->enterpriseControlTemplates($principal)['templates'],'id')===[$template['id']],'Discovery ignored the effective on-duty audience.');
    rejectEnterpriseOperator(fn()=>$fixture->startEnterpriseControlTemplate(['template_id'=>$shifted['id'],'request_id'=>str_repeat('1',32)],$principal),'A scoped key launched an off-site shift.');
    rejectEnterpriseOperator(fn()=>$fixture->startEnterpriseControlTemplate(['template_id'=>$offsite['id'],'request_id'=>str_repeat('2',32)],$principal),'A scoped key launched an off-site escalation.');
    rejectEnterpriseOperator(fn()=>$fixture->startEnterpriseControlTemplate(['template_id'=>$template['id'],'actor'=>'pbx:owner'],$principal),'Caller-supplied human actor was accepted.');
    $input=['template_id'=>$template['id'],'request_id'=>str_repeat('3',32),'fields'=>['place'=>'Isolated room'],'is_test'=>true];
    $launched=$fixture->startEnterpriseControlTemplate($input,$principal);
    checkEnterpriseOperator($launched['success']&&!str_contains(json_encode($launched),'Private roster name')&&!isset($launched['template'],$launched['responses'],$launched['timeline']),'Scoped launch exposed a raw incident.');
    checkEnterpriseOperator($fixture->submissions[0]['principal']['id']===$principal['id']&&$fixture->submissions[0]['actor']['credential_id']===$principal['id'],'Launch lost its named revocable actor.');
    $duplicate=$fixture->startEnterpriseControlTemplate($input,$principal);
    checkEnterpriseOperator($duplicate['id']===$launched['id']&&count($fixture->submissions)===1,'Duplicate API request submitted another announcement.');
    $fixture->settings['enterprise_operations']['enabled']=false;
    checkEnterpriseOperator($fixture->enterpriseControlTemplates($principal)['templates']===[],'Disabled enterprise operations advertised templates.');
    rejectEnterpriseOperator(fn()=>$fixture->startEnterpriseControlTemplate($input,$principal),'Disabled enterprise operations accepted a launch.');
    $fixture->settings['enterprise_operations']['enabled']=true;
    $ro=ApiSecurity::authenticate($fixture->settings['control_api'],$readOnly['secret']);$GLOBALS['sls_control_principal']=$ro;
    checkEnterpriseOperator(count($fixture->enterpriseControlTemplates($ro)['templates'])===1,'Read-only scoped metadata required a send grant.');
    rejectEnterpriseOperator(fn()=>$fixture->startEnterpriseControlTemplate($input,$ro),'Read-only key launched an incident.');
    checkEnterpriseOperator(!$fixture->operatorAction('approval_approve',['review_id'=>'review_'.str_repeat('a',32)])['success'],'API identity impersonated a human approver.');
    checkEnterpriseOperator(!$fixture->operatorAction('drill_review',['run_id'=>'run_'.str_repeat('a',64),'completed'=>[]])['success'],'API identity impersonated a drill reviewer.');
    $fixture->settings['control_api']['credentials'][1]['revoked_at']=gmdate('c');
    rejectEnterpriseOperator(fn()=>$fixture->enterpriseControlTemplates($ro),'Revoked key retained template metadata.');
    $GLOBALS['sls_control_principal']=['id'=>'legacy','scopes'=>['read','send'],'audience'=>['unrestricted'=>true]];
    rejectEnterpriseOperator(fn()=>$fixture->enterpriseControlTemplates($GLOBALS['sls_control_principal']),'Legacy key received enterprise metadata.');
    $contract=ControlContract::capabilities($principal);
    checkEnterpriseOperator($contract['api_version']===1&&$contract['notification_payload_schema']===1&&$contract['desktop_sse_protocol']===2,'Additive discovery changed existing protocol versions.');
    checkEnterpriseOperator(in_array('incident_templates',$contract['resources'],true)&&in_array('enterprise_template_start',array_column($contract['actions'],'action'),true),'Scoped named discovery omitted the additive contract.');
    checkEnterpriseOperator(!in_array('enterprise_template_start',array_column(ControlContract::capabilities($GLOBALS['sls_control_principal'])['actions'],'action'),true),'Legacy discovery advertised an enterprise launch.');
    $fixture->settings['enterprise_identity']=['enabled'=>true,'providers'=>[['client_secret'=>'secret-value']]];
    $fixture->settings['enterprise_integrations']=['enabled'=>'1'];
    $features=ControlContract::enterpriseCapabilities($principal,$fixture->settings);
    checkEnterpriseOperator(!str_contains(json_encode($features),'secret-value'),'Enterprise feature discovery exposed configuration secrets.');
    checkEnterpriseOperator($features['features']['integrations']===true,'Normalized integration enabled string was incorrectly reported as disabled.');
    unset($GLOBALS['sls_control_principal']);

    // Real operations services use human sessions and retain their own audit.
    $_SESSION['AMP_user']=new EnterpriseOperatorLoginFixture();
    $fixture->human=['id'=>'recovery_admin','username'=>'alice','name'=>'Alice','operator_role'=>'administrator','audience'=>['unrestricted'=>true],'site_ids'=>[]];
    $policy=['id'=>'approval_'.str_repeat('a',24),'name'=>'Fixture approval','enabled'=>true,'channels'=>['phones'],'approver_ids'=>['pbx:alice','pbx:bob'],'expires_seconds'=>300];
    $fixture->settings['enterprise_operations']['dual_approval']=['enabled'=>true,'policies'=>[$policy]];
    $service=new \SLS\MassNotify\EnterpriseApprovalService(new EnterpriseOperationsStore($base.'/operations'));
    $review=$service->request($fixture->settings,['phones'=>['1000'],'message'=>'Approved fixture','title'=>'Fixture','is_test'=>true],'pbx:alice',\SLS\MassNotify\OperatorAccess::identity($_SESSION['AMP_user']->getAmpUser('alice')));
    $rows=$fixture->operatorAction('approval_list',[]);checkEnterpriseOperator($rows['success']&&count($rows['reviews'])===1,'Portal did not dispatch a real assigned approval list.');
    checkEnterpriseOperator(!$fixture->operatorAction('approval_approve',['review_id'=>$review['review_id']])['success'],'Requester supplied the second distinct approval.');
    $fixture->human['username']='bob';
    checkEnterpriseOperator($fixture->operatorAction('approval_approve',['review_id'=>$review['review_id']])['ready'],'Second human could not approve through the portal action.');
    checkEnterpriseOperator(!$fixture->operatorAction('approval_list',['operator_id'=>'pbx:alice'])['success'],'Portal accepted an impersonation field.');
    checkEnterpriseOperator(!$fixture->operatorAction('operations_save',['config'=>[]])['success'],'Portal exposed the administrator Labs settings action.');

    $fixture->reviewSubmissions=true;$GLOBALS['sls_control_principal']=$principal;
    $pending=$fixture->startEnterpriseControlTemplate(['template_id'=>$template['id'],'request_id'=>str_repeat('5',32),'fields'=>['place'=>'Pending review'],'is_test'=>true],$principal);
    $pendingOperation=array_values($pending['operations'])[0];
    checkEnterpriseOperator($pendingOperation['state']==='awaiting_approval'&&isset($pendingOperation['review_id'])&&(!isset($pendingOperation['job_id'])||$pendingOperation['job_id']===''),'API launch lost pending approval state.');
    unset($GLOBALS['sls_control_principal']);
    $update=['incident_id'=>$pending['id'],'request_id'=>str_repeat('6',32),'kind'=>'update','message'=>'Isolated follow-up'];
    checkEnterpriseOperator(!$fixture->operatorAction('update_incident',$update)['success'],'Pending review permitted another incident update.');
    checkEnterpriseOperator($fixture->operatorAction('approval_reject',['review_id'=>$pendingOperation['review_id']])['success'],'Assigned human could not reject an unsubmitted API-origin review.');
    $updated=$fixture->operatorAction('update_incident',$update);
    checkEnterpriseOperator($updated['success'],'Rejected review permanently blocked subsequent incident updates.');
    $record=(new \SLS\MassNotify\IncidentStore($base.'/incidents'))->read($pending['id']);
    checkEnterpriseOperator($record['operations'][str_repeat('5',32)]['state']==='not_submitted','Reject did not clear only its pending incident operation.');
    $fixture->reviewSubmissions=false;

    $assignment=['id'=>'drill_'.str_repeat('a',24),'name'=>'Assigned exercise','enabled'=>true,'template_id'=>$template['id'],
        'site_ids'=>[],'owner_id'=>'pbx:alice','reviewer_ids'=>['pbx:bob'],'due_at'=>gmdate('c',time()+3600),'objectives'=>['Observe test alert','Record a human observation']];
    $fixture->settings['enterprise_operations']['drills']=['enabled'=>true,'assignments'=>[$assignment]];
    $fixture->human['username']='alice';
    $run=$fixture->operatorAction('drill_start',['assignment_id'=>$assignment['id'],'request_id'=>str_repeat('4',32),'fields'=>['place'=>'Isolated drill']]);
    checkEnterpriseOperator($run['success']&&$run['incident']['is_test']&&!isset($run['incident']['template'],$run['run']['fingerprint']),'Portal drill launch returned frozen configuration or lost test status.');
    $fixture->human['username']='bob';
    $reviewed=$fixture->operatorAction('drill_review',['run_id'=>$run['run']['id'],'completed'=>[true,false],'note'=>'Isolated observation']);
    checkEnterpriseOperator($reviewed['success']&&$reviewed['run']['reviews'][0]['person']==='pbx:bob','Portal drill review lost attributable human identity.');
    $fixture->human['username']='mallory';$fixture->human['id']='api_'.str_repeat('e',24);$fixture->human['operator_role']='viewer';
    checkEnterpriseOperator($fixture->operatorAction('drill_report',[])['assignments']===[],'Unassigned operator read another person’s drill history.');
    checkEnterpriseOperator(!$fixture->operatorAction('drill_review',['run_id'=>$run['run']['id'],'completed'=>[true,true]])['success'],'Unassigned operator changed a drill review.');

    $canvas=imagecreatetruecolor(2,2);ob_start();imagepng($canvas);$png=ob_get_clean();imagedestroy($canvas);
    $image=(new EnterpriseFloorplanStore())->put($png);
    $plan=['id'=>'plan_'.str_repeat('a',24),'name'=>'Private fixture plan','enabled'=>true,'site_id'=>'site_a','image_id'=>$image['image_id'],'markers'=>[]];
    $other=$plan;$other['id']='plan_'.str_repeat('b',24);$other['name']='Hidden other site plan';$other['site_id']='site_b';$other['image_id']='img_'.str_repeat('b',64);
    $fixture->settings['enterprise_operations']['floorplans']=['enabled'=>true,'plans'=>[$plan,$other]];
    $fixture->human['site_ids']=['site_a'];$fixture->human['audience']=['unrestricted'=>false,'extensions'=>['1000']];
    $state=$fixture->operatorOperationsState();
    checkEnterpriseOperator(count($state['enterprise']['floorplans'])===1&&!str_contains(json_encode($state),'Hidden other site plan'),'Operator state exposed another site’s plan.');
    checkEnterpriseOperator(str_starts_with($fixture->operatorAction('floorplan_image',['image_id'=>$image['image_id']])['image'],'data:image/png;base64,'),'Authorized private image did not use the existing portal action.');
    checkEnterpriseOperator(!$fixture->operatorAction('floorplan_image',['image_id'=>$other['image_id']])['success'],'Operator read another site’s private image.');
    checkEnterpriseOperator(!$fixture->operatorAction('floorplan_overlay',['plan_id'=>$other['id'],'incident_id'=>$launched['id']])['success'],'Operator read another site’s private overlay.');
    $overlay=$fixture->operatorAction('floorplan_overlay',['plan_id'=>$plan['id'],'incident_id'=>$launched['id']]);
    checkEnterpriseOperator($overlay['success']&&!str_contains(json_encode($overlay),'Private roster name'),'Private floor plan overlay lost incident projection.');
    $fixture->settings['enterprise_operations']['floorplans']['enabled']=false;
    checkEnterpriseOperator(!$fixture->operatorAction('floorplan_image',['image_id'=>$image['image_id']])['success'],'Disabled floorplan section remained readable.');
    $fixture->settings['enterprise_operations']['enabled']=false;
    checkEnterpriseOperator(!$fixture->operatorAction('floorplan_image',['image_id'=>$image['image_id']])['success']&&!$fixture->operatorOperationsState()['enterprise']['enabled'],'Disabled enterprise routes remained active.');
    echo "$checks enterprise operator/API audience, human identity, replay, disabled-feature and private-state checks passed.\n";
}finally{
    unset($GLOBALS['sls_control_principal']);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($base);
}
