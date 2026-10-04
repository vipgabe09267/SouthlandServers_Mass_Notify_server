<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/EnterpriseApprovalService.php';
require_once __DIR__.'/EnterpriseFloorplanStore.php';
use SLS\MassNotify\{EnterpriseOperationsConfig,EnterpriseOperationsStore,EnterpriseApprovalService,EnterpriseFloorplanStore,OperatorAccess,ApiSecurity,LocationDirectory,IncidentConfig};

trait SlsEnterpriseOperations
{
    protected function enterpriseOperationsStore(): EnterpriseOperationsStore { return new EnterpriseOperationsStore(); }
    protected function enterpriseApprovalService(): EnterpriseApprovalService { return new EnterpriseApprovalService($this->enterpriseOperationsStore()); }
    private function enterprisePerson(array $principal): string
    {
        return ($principal['id']??'')==='recovery_admin'?'pbx:'.$principal['username']:(string)$principal['id'];
    }
    private function enterpriseCanReview(array $principal,array $request): bool
    {
        return (($principal['id']??'')==='recovery_admin'&&($principal['operator_role']??'')==='administrator')
            || (OperatorAccess::may($principal,'send')&&ApiSecurity::permitsResolvedAnnouncement($principal,$request,$this->getActiveSettings()));
    }
    private function enterprisePersonIdentity(string $person,array $settings): ?string
    {
        if (str_starts_with($person,'pbx:')) {
            $username=substr($person,4);
            try {
                $user=$_SESSION['AMP_user']??null;
                $row=is_object($user)&&method_exists($user,'getAmpUser')?$user->getAmpUser($username):(new \ampuser($username))->getAmpUser($username);
                if(!is_array($row)||!$this->isCurrentPbxAdministrator($row)){return null;}
                return OperatorAccess::identity($row);
            } catch (\Throwable $error) { return null; }
        }
        $p=OperatorAccess::principal($settings,$person);
        return $p&&OperatorAccess::localIdentityCurrent($p)?($p['identity']??null):null;
    }
    private function enterprisePersonPermits(string $person,array $request,string $identity): bool
    {
        $settings=$this->getActiveSettings();$current=$this->enterprisePersonIdentity($person,$settings);
        if($current===null||!hash_equals($current,$identity)){return false;}
        if(str_starts_with($person,'pbx:')){return true;}
        $p=OperatorAccess::principal($settings,$person);
        return $p&&OperatorAccess::may($p,'send')&&ApiSecurity::permitsResolvedAnnouncement($p,$request,$settings);
    }
    protected function enterpriseApprovalPermitted(array $request): bool
    {
        $settings=$this->getActiveSettings();
        if(!EnterpriseOperationsConfig::policies($settings,$request)){return true;}
        return $this->enterpriseApprovalService()->permits($settings,$request,
            fn(string $person,array $frozen,string $identity):bool=>$this->enterprisePersonPermits($person,$frozen,$identity));
    }
    protected function enterpriseReviewAnnouncement(array $request): ?array
    {
        $settings=$this->getActiveSettings();
        if(!EnterpriseOperationsConfig::policies($settings,$request)||!empty($request['preview'])){return null;}
        $principal=$GLOBALS['sls_control_principal']??null;
        if(is_array($principal)&&!isset($principal['operator_role'])){
            $person='control:'.($principal['id']??'');$identity=$person;
            if(($principal['id']??'')!=='legacy'){$request['api_credential_id']=$principal['id'];}
        }else{
            try{$p=$this->currentOperator();$person=$this->enterprisePerson($p);$identity=$this->enterprisePersonIdentity($person,$settings);}
            catch(\Throwable $error){$person='worker:'.($request['sender']??'SLS');$identity=$person;}
        }
        $r=$this->enterpriseApprovalService()->request($settings,$request,$person,$identity);
        return ['success'=>true,'awaiting_approval'=>true,'delivery_started'=>false,'state'=>'awaiting_approval',
            'review_id'=>$r['review_id'],'expires_at'=>$r['expires_at'],
            'message'=>'Awaiting approval from two distinct authorized people. No delivery has started.'];
    }
    public function listEnterpriseApprovals(): array
    {
        $p=$this->currentOperator();$person=$this->enterprisePerson($p);$settings=$this->getActiveSettings();
        if(!EnterpriseOperationsConfig::normalize($settings['enterprise_operations']??[])['enabled']){return ['success'=>true,'reviews'=>[]];}
        $rows=[];
        foreach($this->enterpriseApprovalService()->listing() as $r){
            $assigned=$r['requested_by']===$person;
            foreach($r['policies'] as $policy){$assigned=$assigned||in_array($person,$policy['approver_ids'],true);}
            if(!$assigned||!$this->enterpriseCanReview($p,$r['request'])){continue;}
            $rows[]=['id'=>$r['id'],'state'=>$r['state'],'requested_by'=>$r['requested_by'],'expires_at'=>gmdate('c',$r['expires_at']),
                'message'=>$r['request']['message'],'title'=>$r['request']['title'],'is_test'=>($r['request']['is_test']??false)===true,
                'recipients'=>array_intersect_key($r['request'],array_flip(['phones','desktops','webhooks','voice_recipient_ids','email_recipient_ids','sms_recipient_ids'])),
                'approvals'=>$r['approvals'],'history'=>$r['history']];
        }
        return ['success'=>true,'reviews'=>$rows];
    }
    public function actEnterpriseApproval(string $action,string $id): array
    {
        if(!in_array($action,['approve','submit','reject'],true)){throw new \InvalidArgumentException('Choose approve, submit or reject.');}
        $p=$this->currentOperator();$person=$this->enterprisePerson($p);$settings=$this->getActiveSettings();
        if(!OperatorAccess::may($p,'send')){throw new \DomainException('Your current account cannot send or approve announcements.');}
        $authorize=fn(array $request):bool=>$this->enterpriseCanReview($p,$request);
        $service=$this->enterpriseApprovalService();
        if($action==='approve'){return $service->approve($id,$settings,$person,$authorize,$this->enterprisePersonIdentity($person,$settings));}
        if($action==='reject'){$request=$service->reject($id,$person);$this->recordEnterpriseIncidentApproval($request,['delivery_started'=>false,'success'=>false,'message'=>'Unsubmitted review rejected.']);return ['success'=>true,'message'=>'Unsubmitted review rejected. No announcement was cancelled.'];}
        $activity=$this->acquireAnnouncementActivityLock(false,5);$send=null;$result=null;
        if($activity===null){throw new \DomainException('Announcement settings are busy. Retry this approved request shortly.');}
        try{
            $send=(new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionLock();
            if(!$send){throw new \DomainException('Another announcement is being admitted. Retry this approved request shortly.');}
            $request=$service->claim($id,$this->getActiveSettings(),$person,$authorize);
            if(!$this->enterpriseApprovalPermitted($request)){$result=['success'=>false,'delivery_started'=>false,'message'=>'An approver was revoked or its identity changed. Submit a new review.'];}
            else{
                $request['force_queue']=true;$this->announcementAdmissionLock=$send;
                $result=$this->withOperator($p,fn():array=>$this->deliverResolvedAnnouncement($request));
            }
            $service->finish($id,$result);
            $this->recordEnterpriseIncidentApproval($request,$result);
            return $result;
        }catch(\Throwable $error){
            if(isset($request)&&$result===null){try{$service->finish($id,['success'=>false,'message'=>'Submission stopped without confirming a result. Review the delivery queue before taking another action.']);}catch(\Throwable $ignored){}}
            throw $error;
        }finally{$this->announcementAdmissionLock=null;if(is_resource($send)){\SlsAnnouncementJobStore::unlock($send);}$this->releaseNativeBackupFileLock($activity);}
    }
    private function recordEnterpriseIncidentApproval(array $request,array $result): void
    {
        $context=$request['incident_context']??null;if(!is_array($context)){return;}
        $this->incidentStore()->transaction($context['incident_id'],static function(array $record)use($context,$request,$result):array{
            foreach($record['operations'] as &$operation){
                if(($operation['sequence']??null)!==$context['sequence']||($operation['review_id']??'')!==($request['_enterprise_approval']['id']??'')){continue;}
                $operation['state']=isset($result['job_id'])?'queued':(($result['delivery_started']??null)===false?'not_submitted':'uncertain');
                if(isset($result['job_id'])){$operation['job_id']=$result['job_id'];if($record['opened_at']===''&&$operation['kind']==='initial'){$record['opened_at']=gmdate('c');}if($operation['kind']==='all_clear'){$record['state']='closed';$record['closed_at']=gmdate('c');}}
                $operation['finished_at']=gmdate('c');$record['timeline'][]=['at'=>gmdate('c'),'kind'=>'approval_submission_'.$operation['state'],'actor'=>['identity'=>'Two-person review','source'=>'approval'],'details'=>['review_id'=>$operation['review_id'],'job_id'=>$result['job_id']??'']];$record['updated_at']=gmdate('c');
            }unset($operation);return $record;
        });
    }
    protected function routeEnterpriseIncidentTemplate(array $template): array
    {
        $route=EnterpriseOperationsConfig::activeShift($this->getActiveSettings(),$template['id'],time());
        if($route!==null){$template['delivery']=$route['delivery'];}
        return $template;
    }
    public function saveEnterpriseOperations(array $input): array
    {
        $this->assertEnterpriseAdministrator();$input=IncidentConfig::object($input,['config','revision'],'Enterprise operations save');
        if(!is_string($input['revision']??null)){throw new \InvalidArgumentException('Reload the Enterprise operations editor before saving.');}
        $cfg=EnterpriseOperationsConfig::normalize($input['config']??[]);$settings=$this->getActiveSettings();
        $sites=array_column(LocationDirectory::effective($settings['location_directory']??[])['nodes'],null,'id');
        $templates=array_column($this->incidentTemplates(),null,'id');
        foreach($cfg['floorplans']['plans'] as $plan){if(($sites[$plan['site_id']]['type']??'')!=='site'){throw new \DomainException('A floor plan must belong to an existing saved site.');}(new EnterpriseFloorplanStore())->read($plan['image_id']);}
        foreach($cfg['drills']['assignments'] as $drill){
            if(!isset($templates[$drill['template_id']])){throw new \DomainException('Select an applied incident template for each drill.');}
            foreach($drill['site_ids'] as $site){if(($sites[$site]['type']??'')!=='site'){throw new \DomainException('A drill assignment site no longer exists.');}}
            foreach(array_merge([$drill['owner_id']],$drill['reviewer_ids']) as $person){if($this->enterprisePersonIdentity($person,$settings)===null){throw new \DomainException('A drill owner or reviewer is not an enabled current account.');}}
        }
        foreach($cfg['shift_routing']['routes'] as $route){foreach($route['template_ids'] as $id){if(!isset($templates[$id])){throw new \DomainException('Select applied incident templates for shift routing.');}}$this->freezeIncidentDelivery($route['delivery'],'',false);}
        foreach($cfg['dual_approval']['policies'] as $policy){foreach($policy['approver_ids'] as $person){if($this->enterprisePersonIdentity($person,$settings)===null){throw new \DomainException('Every approver must be an enabled current human account.');}}}
        $this->saveEnterpriseNamespace('enterprise_operations',$cfg,$input['revision']);
        return ['success'=>true,'message'=>'Enterprise operations saved. Disabled sections remain inactive.'];
    }
    public function uploadEnterpriseFloorplan(array $file): array
    {
        $this->assertEnterpriseAdministrator();
        if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])){throw new \InvalidArgumentException('A complete PNG/JPEG upload is required.');}
        if(!is_int($file['size']??null)||$file['size']>EnterpriseFloorplanStore::MAX_BYTES){throw new \InvalidArgumentException('Floor plan upload exceeds 5 MiB.');}
        $bytes=file_get_contents($file['tmp_name'],false,null,0,EnterpriseFloorplanStore::MAX_BYTES+1);
        return ['success'=>true]+(new EnterpriseFloorplanStore())->put($bytes);
    }
    public function getEnterpriseFloorplanImage(string $id): array
    {
        $settings=$this->getActiveSettings();$cfg=EnterpriseOperationsConfig::normalize($settings['enterprise_operations']??[]);$p=$this->currentOperator();
        if(($p['id']??'')==='recovery_admin'){return (new EnterpriseFloorplanStore())->read($id);}
        if(!$cfg['enabled']||!$cfg['floorplans']['enabled']){throw new \DomainException('Private floor plans have not been enabled.');}
        foreach($cfg['floorplans']['plans'] as $plan){if($plan['enabled']&&$plan['image_id']===$id&&(($p['operator_role']??'')==='administrator'||in_array($plan['site_id'],$p['site_ids']??[],true))){return (new EnterpriseFloorplanStore())->read($id);}}
        throw new \DomainException('This floor plan is outside your current assigned sites.');
    }
    public function getEnterpriseFloorplanOverlay(string $planId,string $incidentId): array
    {
        $settings=$this->getActiveSettings();$cfg=EnterpriseOperationsConfig::normalize($settings['enterprise_operations']??[]);$p=$this->currentOperator();
        if(!$cfg['enabled']||!$cfg['floorplans']['enabled']){throw new \DomainException('Private floor plans have not been enabled.');}
        $plan=null;foreach($cfg['floorplans']['plans'] as $row){if($row['enabled']&&$row['id']===$planId){$plan=$row;}}
        if(!$plan||(($p['operator_role']??'')!=='administrator'&&!in_array($plan['site_id'],$p['site_ids']??[],true))){throw new \DomainException('This floor plan is outside your current assigned sites.');}
        $record=$this->incidentStore()->read($incidentId);if(!$record){throw new \DomainException('Incident was not found.');}
        $allowed=$this->operatorIncidentProjection($record,$p);if(!$allowed){throw new \DomainException('This incident is outside your assigned audience.');}
        $statuses=[];
        foreach($allowed['roster'] as $person){$status=$record['responses'][$person['id']]['response']??'no_response';if($person['desktop_username']!==''){$statuses['desktop:'.$person['desktop_username']][]=$status;}if(($person['location_id']??'')!==''){$statuses['location:'.$person['location_id']][]=$status;}}
        foreach($plan['markers'] as &$marker){$values=$statuses[$marker['kind'].':'.$marker['target_id']]??[];$marker['response_counts']=array_count_values($values);$marker['status']=in_array('needs_assistance',$values,true)?'needs_assistance':(in_array('missing',$values,true)?'missing':($values&&!in_array('no_response',$values,true)?'responded':'unconfirmed'));}unset($marker);
        return ['success'=>true,'plan'=>$plan,'incident_id'=>$incidentId,'observed_at'=>gmdate('c'),'meaning'=>'Human responses are shown separately from transport receipts. Unconfirmed markers are never reported as safe.'];
    }
    public function enterpriseOperationsState(): array
    {
        $this->assertEnterpriseAdministrator();$settings=$this->getActiveSettings();
        $people=[];foreach($this->operatorDirectory()['accounts'] as $p){if(!empty($p['administrator'])){$people[]=['id'=>'pbx:'.$p['username'],'name'=>$p['username'].' · FreePBX'];}}
        foreach(OperatorAccess::normalize($settings['operator_access']??[])['accounts'] as $p){if($p['enabled']){$people[]=['id'=>$p['id'],'name'=>$p['name'].' · '.$p['username']];}}
        $cfg=EnterpriseOperationsConfig::normalize($settings['enterprise_operations']??[]);
        $incidents=[];
        if($cfg['enabled']&&$cfg['floorplans']['enabled']){
            foreach($this->listIncidents(25)['incidents']??[] as $row){$incidents[]=['id'=>$row['id'],'name'=>$row['title'].' · '.$row['state']];}
        }
        $markers=[];foreach(LocationDirectory::effective($settings['location_directory']??[])['nodes'] as $node){$markers[]=['kind'=>'location','id'=>$node['id'],'name'=>$node['name']];}
        foreach($this->locationCatalog($settings)['extensions'] as $device){$markers[]=['kind'=>'phone','id'=>$device['id'],'name'=>$device['label']];}
        foreach($settings['desktop_clients']??[] as $device){$markers[]=['kind'=>'desktop','id'=>$device['username'],'name'=>$device['name']];}
        foreach($settings['automations']['rules']??[] as $device){$markers[]=['kind'=>'sensor','id'=>$device['id'],'name'=>$device['name']];}
        return ['config'=>$cfg,'revision'=>hash('sha256',json_encode($cfg,JSON_THROW_ON_ERROR)),'sites'=>array_values(array_filter(LocationDirectory::effective($settings['location_directory']??[])['nodes'],static fn($n)=>$n['type']==='site')),
            'incident_choices'=>$incidents,'people'=>$people,'templates'=>array_map(static fn($t)=>['id'=>$t['id'],'name'=>$t['name'],'fields'=>$t['fields']],$this->incidentTemplates()),
            'audiences'=>$this->getAnnouncementGroups(),'marker_choices'=>$markers,'tones'=>$this->getAvailableTones(),'timezone'=>$settings['pbx_timezone']??'','timezones'=>\DateTimeZone::listIdentifiers(),'reviews'=>$this->listEnterpriseApprovals()['reviews'],
            'drill_report'=>$this->getEnterpriseDrillReport()['assignments']];
    }
    private function enterpriseDrillAssignment(string $id): array
    {
        $cfg=EnterpriseOperationsConfig::normalize($this->getActiveSettings()['enterprise_operations']??[]);
        if(!$cfg['enabled']||!$cfg['drills']['enabled']){throw new \DomainException('Central drill management has not been enabled.');}
        foreach($cfg['drills']['assignments'] as $row){if($row['enabled']&&$row['id']===$id){return $row;}}
        throw new \DomainException('This drill assignment is disabled or no longer exists.');
    }
    public function startEnterpriseDrill(array $input): array
    {
        $input=IncidentConfig::object($input,['assignment_id','request_id','fields','planned_at'],'Central drill start');
        $assignment=$this->enterpriseDrillAssignment($input['assignment_id']??'');$p=$this->currentOperator();$person=$this->enterprisePerson($p);
        if(($p['operator_role']??'')!=='administrator'&&$assignment['owner_id']!==$person){throw new \DomainException('Only the assigned drill owner or an administrator can launch this drill.');}
        if(!OperatorAccess::may($p,'send')){throw new \DomainException('Your current role cannot launch a drill announcement.');}
        $request=IncidentConfig::identifier($input['request_id']??'','/^[a-f0-9]{32}$/D','Drill request');$run='run_'.hash('sha256',$request);
        $start=['template_id'=>$assignment['template_id'],'request_id'=>$request,'fields'=>$input['fields']??[],'is_test'=>true];
        if(isset($input['planned_at'])){$start['planned_at']=$input['planned_at'];}
        $fingerprint=hash('sha256',json_encode([$start,$assignment,$person],JSON_THROW_ON_ERROR));
        $claim=$this->enterpriseOperationsStore()->transaction(static function(array &$state)use($run,$fingerprint,$assignment,$person):array{
            if(isset($state['drills'][$run])){if(!hash_equals($state['drills'][$run]['fingerprint'],$fingerprint)){throw new \DomainException('This drill request was already used for different details.');}return ['new'=>false,'record'=>$state['drills'][$run]];}
            $state['drills'][$run]=['id'=>$run,'assignment'=>$assignment,'owner'=>$person,'fingerprint'=>$fingerprint,'state'=>'submitting','created_at'=>gmdate('c'),'reviews'=>[]];return ['new'=>true];
        });
        if(!$claim['new']){return ['success'=>true,'run'=>$claim['record'],'message'=>'Existing drill run returned. No announcement was repeated.'];}
        try{
            $templates=$this->operatorOperationsState()['templates'];
            if(!in_array($assignment['template_id'],array_column($templates,'id'),true)){throw new \DomainException('This drill template exceeds your current permitted audience.');}
            $result=$this->withOperator($p,fn():array=>$this->startIncident($start));
        }catch(\Throwable $error){$result=['success'=>false,'message'=>$error instanceof \DomainException?$error->getMessage():'Drill launch did not confirm completion. Review incidents before taking another action.'];}
        $record=$this->enterpriseOperationsStore()->transaction(static function(array &$state)use($run,$result):array{
            $r=&$state['drills'][$run];$r['state']=self::enterpriseDrillDeliveryState($result);$r['incident_id']=$result['id']??'';$r['result']=array_intersect_key($result,array_flip(['success','message','id','state']));return $r;
        });
        return ['success'=>!empty($result['success']),'run'=>$record,'incident'=>$result];
    }
    private static function enterpriseDrillDeliveryState(array $result): string
    {
        if(empty($result['success'])||!isset($result['id'])){return 'uncertain';}
        if(($result['state']??'')==='planned'){return 'planned';}
        foreach($result['operations']??[] as $operation){
            if(($operation['kind']??'')==='initial'){
                return in_array($operation['state']??'',['awaiting_approval','queued','submitted','not_submitted','uncertain'],true)
                    ? $operation['state'] : 'uncertain';
            }
        }
        return 'uncertain';
    }
    public function reviewEnterpriseDrill(array $input): array
    {
        $input=IncidentConfig::object($input,['run_id','completed','note'],'Drill review');$run=IncidentConfig::identifier($input['run_id']??'','/^run_[a-f0-9]{64}$/D','Drill run');
        $p=$this->currentOperator();$person=$this->enterprisePerson($p);$completed=IncidentConfig::listOf($input['completed']??[],25,'Drill objective results');
        foreach($completed as $flag){IncidentConfig::flag($flag,'Objective completion');}$note=IncidentConfig::text($input['note']??'',1000,'Drill review note',true);
        return $this->enterpriseOperationsStore()->transaction(function(array &$state)use($run,$person,$p,$completed,$note):array{
            $r=&$state['drills'][$run];if(!is_array($r)){throw new \DomainException('Drill run was not found.');}$a=$this->enterpriseDrillAssignment($r['assignment']['id']);
            if(($p['operator_role']??'')!=='administrator'&&!in_array($person,$a['reviewer_ids'],true)&&$person!==$a['owner_id']){throw new \DomainException('Only this drill’s assigned owner, reviewers or an administrator may record a review.');}
            if(count($completed)!==count($r['assignment']['objectives'])){throw new \DomainException('Record one result for every objective in the original drill assignment.');}
            if(count($r['reviews'])>=100){throw new \DomainException('Drill review history reached its allocation. Export the report before further reviews.');}
            $r['reviews'][]=['at'=>gmdate('c'),'person'=>$person,'completed'=>$completed,'note'=>$note];return ['success'=>true,'run'=>$r,'message'=>'Drill review recorded. Delivery evidence remains separate.'];
        });
    }
    public function getEnterpriseDrillReport(): array
    {
        $p=$this->currentOperator();$person=$this->enterprisePerson($p);$cfg=EnterpriseOperationsConfig::normalize($this->getActiveSettings()['enterprise_operations']??[]);
        if(!$cfg['enabled']||!$cfg['drills']['enabled']){return ['success'=>true,'assignments'=>[]];}
        $runs=$this->enterpriseOperationsStore()->transaction(static fn(array &$state):array=>$state['drills']);$rows=[];
        // Project current incident admission without replaying a drill or changing its frozen report.
        foreach($runs as &$run){
            if(!in_array($run['state']??'',['planned','awaiting_approval','submitting'],true)||empty($run['incident_id'])){continue;}
            $incident=$this->incidentStore()->read($run['incident_id']);
            if($incident!==null){$run['state']=self::enterpriseDrillDeliveryState(['success'=>true]+$incident);}
        }unset($run);
        foreach($cfg['drills']['assignments'] as $a){
            if(($p['operator_role']??'')!=='administrator'&&$a['owner_id']!==$person&&!in_array($person,$a['reviewer_ids'],true)){continue;}
            $matched=array_values(array_filter($runs,static fn($run)=>$run['assignment']['id']===$a['id']));
            $a['runs']=$matched;
            $latest=$matched?end($matched):null;
            $a['status']=!$a['enabled']?'disabled':(!$latest?(strtotime($a['due_at'])<time()?'overdue':'assigned'):
                (!empty($latest['reviews'])?'reviewed':(in_array($latest['state'],['queued','submitted'],true)?'launched_unreviewed':$latest['state'])));
            $rows[]=$a;
        }
        return ['success'=>true,'assignments'=>$rows,'exported_at'=>gmdate('c'),'meaning'=>'Review completion is an attributable observation. It does not prove receipt, playback or a person’s safety.'];
    }
    public function enterpriseOperationsAction(string $action,array $input): array
    {
        if($action==='operations_save'){return $this->saveEnterpriseOperations($input);}
        if($action==='approval_list'){return $this->listEnterpriseApprovals();}
        if(in_array($action,['approval_approve','approval_submit','approval_reject'],true)){return $this->actEnterpriseApproval(substr($action,9),$input['review_id']??'');}
        if($action==='floorplan_overlay'){return $this->getEnterpriseFloorplanOverlay($input['plan_id']??'',$input['incident_id']??'');}
        if($action==='drill_start'){return $this->startEnterpriseDrill($input);}
        if($action==='drill_review'){return $this->reviewEnterpriseDrill($input);}
        if($action==='drill_report'){return $this->getEnterpriseDrillReport();}
        throw new \InvalidArgumentException('Unsupported Enterprise operations action.');
    }
}
