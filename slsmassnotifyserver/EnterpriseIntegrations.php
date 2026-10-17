<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';
require_once __DIR__.'/EnterpriseAnnouncementChannels.php';
use SLS\MassNotify\{EnterpriseIntegrationsConfig,EnterpriseIntegrationsService,EnterpriseIntegrationsStore,EnterprisePublicWarning,IncidentConfig,AutomationConfig};

trait SlsEnterpriseIntegrations
{
    use SlsEnterpriseAnnouncementChannels;
    protected function enterpriseIntegrationsStoreDirectory(): string { return self::PLUGIN_DATA_DIR.'/enterprise-integrations'; }
    private function enterpriseIntegrationsService(): EnterpriseIntegrationsService
    {
        require_once __DIR__.'/EnterpriseIntegrationsService.php';
        return new EnterpriseIntegrationsService(new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()));
    }
    private function enterpriseIntegrationsConfig(?array $settings=null): array
    { return EnterpriseIntegrationsConfig::normalize(($settings??$this->getActiveSettings())['enterprise_integrations']??[]); }
    private function enabledEnterpriseIntegration(string $section,bool $requiresRunning=true): array
    {
        $settings=$this->getActiveSettings(); $config=$this->enterpriseIntegrationsConfig($settings);
        if ($config['enabled']!=='1' || $config[$section]['enabled']!=='1') { throw new \DomainException('This Enterprise Labs integration is disabled.'); }
        if($requiresRunning){\SLS\MassNotify\RuntimeState::requireRunning($settings);}
        return $config[$section];
    }
    public function getEnterpriseIntegrationState(): array
    {
        $this->assertEnterpriseAdministrator();$settings=$this->getActiveSettings();$config=$this->enterpriseIntegrationsConfig($settings); $history=[]; $health=[]; $error='';$usage=[];
        $journalDirectory=$this->enterpriseIntegrationsStoreDirectory();
        $journalFence=dirname($journalDirectory).'/.'.basename($journalDirectory).'-required';
        if (is_dir($journalDirectory)||is_link($journalDirectory)||file_exists($journalFence)||is_link($journalFence)) {
            try {
                require_once __DIR__.'/EnterpriseIntegrationsStore.php';$store=new EnterpriseIntegrationsStore($journalDirectory);$state=$store->read();
                $usage=$store->usage();
                foreach (array_slice(array_reverse($state['operations']),0,50) as $row) { $history[]=array_diff_key($row,['fingerprint'=>true]); }
                $rules=array_column(AutomationConfig::normalize($settings['automations']??[])['rules'],null,'id');
                foreach ($state['sensors'] as $id=>$row) {
                    if (!isset($rules[$id]) || $rules[$id]['kind']!=='sensor') { continue; }
                    $health[]=['rule_id'=>$id,'name'=>$rules[$id]['name'],'last_seen'=>$row['last_seen'],'is_test'=>$row['is_test'],
                        'state'=>$config['enabled']!=='1' || $config['sensors']['enabled']!=='1' || empty($rules[$id]['enabled'])?'disabled':
                            (hash_equals($row['rule_revision'],EnterpriseIntegrationsConfig::fingerprint($rules[$id])) && $row['last_seen']+ $config['sensors']['heartbeat_timeout_seconds']>time()?'healthy':'stale')];
                }
            } catch (\Throwable $exception) { $error='Protected integration history is unavailable. Check storage before submitting another provider action.'; }
        }
        return ['config'=>EnterpriseIntegrationsConfig::redacted($config),'revision'=>hash('sha256',json_encode($config,JSON_THROW_ON_ERROR)),
            'catalog'=>EnterpriseIntegrationsConfig::catalog(),'history'=>$history,'sensor_health'=>$health,'storage_error'=>$error,'journal_usage'=>$usage,
            'extensions'=>array_map('strval',$this->getConfiguredPjsipExtensionNumbers()),
            'sms_recipients'=>array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['name']??$r['id']],$settings['announcement_sms']['recipients']??[]),
            'voice_recipients'=>array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['name']??$r['id']],$settings['outbound_voice']['recipients']??[]),
            'people'=>$this->integrationRosterChoices($settings),
            'browser_people'=>array_map(static fn($person)=>['id'=>$person['id'],'name'=>$person['name']],array_values(array_filter(($settings['subscriber_browser']['people']??[]),static fn($person)=>!empty($person['enabled'])))),
            'runtime_dependencies'=>['curl'=>extension_loaded('curl'),'openssl'=>extension_loaded('openssl'),'dom'=>extension_loaded('dom')],
            'incidents'=>array_values(array_map(static fn($row)=>['id'=>$row['id'],'name'=>$row['title']],array_filter(($this->listIncidents(50)['incidents']??[]),static fn($row)=>($row['state']??'')==='open'))),
            'qualification'=>'Labs · isolated protocol fixtures only; hardware, OAuth accounts and FEMA authority acceptance are pending.',
            'ipaws_qualification'=>'Requires an authorized COG, current certificate/key, issued WSDL-generated signed envelope and CDTE acceptance. No FEMA certification is claimed.'];
    }
    private function integrationRosterChoices(?array $settings=null): array
    {
        $settings??=$this->getActiveSettings();$people=[]; foreach ($settings['incident_workflows']['templates']??[] as $template) {
            foreach ($template['roster']??[] as $person) { $people[$person['id']]=['id'=>$person['id'],'name'=>$person['name']]; }
        }
        return array_values($people);
    }
    public function saveEnterpriseIntegrations(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $input=IncidentConfig::object($input,['revision','config','clear_secrets'],'Integration configuration');
        $current=$this->enterpriseIntegrationsConfig();
        if (!is_string($input['revision']??null) || !hash_equals(hash('sha256',json_encode($current,JSON_THROW_ON_ERROR)),$input['revision'])) { throw new \DomainException('Integration settings changed. Reload before saving.'); }
        $value=$input['config']??[]; if (!is_array($value)) { throw new \InvalidArgumentException('Integration settings must be an object.'); }
        $clear=IncidentConfig::listOf($input['clear_secrets']??[],120,'Clear provider credentials');
        $merged=array_replace($current,$value);
        foreach (['access_control'=>['password'],'meetings'=>['zoom_access_token','google_access_token','webex_access_token','oauth_client_secret','oauth_refresh_token'],
            'public_warning'=>['certificate_pem','private_key_pem','private_key_password']] as $section=>$fields) {
            if (!is_array($merged[$section]??null)) { throw new \InvalidArgumentException('Integration settings must contain provider objects.'); }
            $merged[$section]=array_replace($current[$section],$merged[$section]);
            foreach ($fields as $field) {
                unset($merged[$section][$field.'_configured']); $path=$section.'.'.$field;
                if (in_array($path,$clear,true)) { $merged[$section][$field]=''; }
                elseif (($merged[$section][$field]??'')==='') { $merged[$section][$field]=$current[$section][$field]; }
            }
        }
        if (isset($value['speakers']['devices'])) {
            if (!is_array($value['speakers'])||!is_array($value['speakers']['devices'])||!array_is_list($value['speakers']['devices'])) { throw new \InvalidArgumentException('Speaker profiles must be a list of objects.'); }
            $old=array_column($current['speakers']['devices'],null,'id');
            foreach ($merged['speakers']['devices'] as &$row) {
                if (!is_array($row)) { throw new \InvalidArgumentException('Speaker entries must be objects.'); }
                $rowId=$row['id']??'';
                if (!is_string($rowId)) { throw new \InvalidArgumentException('Speaker identifiers must be text.'); }
                if ($rowId==='') { $rowId='spk_'.bin2hex(random_bytes(12));$row['id']=$rowId; }
                unset($row['telemetry_password_configured']); $path='speakers.'.$row['id'].'.telemetry_password';
                if (in_array($path,$clear,true)) { $row['telemetry_password']=''; }
                elseif (($row['telemetry_password']??'')==='') { $row['telemetry_password']=$old[$rowId]['telemetry_password']??''; }
            } unset($row);
        }
        if(isset($value['connectors']['destinations'])){
            if(!is_array($value['connectors'])||!is_array($value['connectors']['destinations'])||!array_is_list($value['connectors']['destinations'])){throw new \InvalidArgumentException('Connector destinations must be a list of objects.');}
            $old=array_column($current['connectors']['destinations'],null,'id');foreach($merged['connectors']['destinations'] as &$row){
                if(!is_array($row)){throw new \InvalidArgumentException('Connector entries must be objects.');}$rowId=$row['id']??'';
                if(!is_string($rowId)){throw new \InvalidArgumentException('Connector identifiers must be text.');}if($rowId===''){$rowId='conn_'.bin2hex(random_bytes(12));$row['id']=$rowId;}
                unset($row['token_configured']);if(in_array('connectors.'.$rowId.'.token',$clear,true)){$row['token']='';}elseif(($row['token']??'')===''){$row['token']=$old[$rowId]['token']??'';}
            }unset($row);
        }
        if (isset($value['access_control']['doors'])) {
            if(!is_array($value['access_control'])||!is_array($value['access_control']['doors'])||!array_is_list($value['access_control']['doors'])){throw new \InvalidArgumentException('Permitted doors must be a list of objects.');}
            foreach ($merged['access_control']['doors'] as &$row) { if (!is_array($row)) { throw new \InvalidArgumentException('Permitted door entries must be objects.'); } if (($row['id']??'')==='') { $row['id']='door_'.bin2hex(random_bytes(12)); } } unset($row);
        }
        $config=EnterpriseIntegrationsConfig::normalize($merged);
        if($config['enabled']==='1'){
            foreach(['meetings','access_control','connectors'] as $section){if($config[$section]['enabled']==='1'&&(!extension_loaded('curl')||!extension_loaded('openssl'))){throw new \DomainException('Enable PHP cURL and OpenSSL in the runtime serving FreePBX before enabling '.$section.'.');}}
            if($config['public_warning']['enabled']==='1'&&(!extension_loaded('dom')||!extension_loaded('openssl')||($config['public_warning']['ipaws_enabled']==='1'&&!extension_loaded('curl')))){throw new \DomainException('CAP authoring requires PHP DOM and OpenSSL; IPAWS transmission also requires cURL in the runtime serving FreePBX.');}
        }
        $extensions=array_map('strval',$this->getConfiguredPjsipExtensionNumbers());
        foreach ($config['speakers']['devices'] as $speaker) { if (!in_array($speaker['extension'],$extensions,true)) { throw new \DomainException('Enroll speakers as existing PBX PJSIP extensions before selecting them in SLS.'); } }
        $settings=$this->getActiveSettings(); $sms=array_column($settings['announcement_sms']['recipients']??[],null,'id'); $voice=array_column($settings['outbound_voice']['recipients']??[],null,'id');
        foreach ($config['responses']['bindings'] as $row) {
            if (($row['sms_recipient_id']!=='' && !isset($sms[$row['sms_recipient_id']])) || ($row['voice_recipient_id']!=='' && !isset($voice[$row['voice_recipient_id']]))) { throw new \DomainException('Response participants must use currently saved SMS and voice recipients.'); }
        }
        $this->saveEnterpriseNamespace('enterprise_integrations',$config,$input['revision']);
        return ['success'=>true,'message'=>'Integration settings saved to the protected central config. Saving contacted no provider.','state'=>$this->getEnterpriseIntegrationState()];
    }
    public function renderEnterpriseIntegrations(): string
    { return load_view(__DIR__.'/views/integrations.php',['state'=>$this->getEnterpriseIntegrationState(),'csrf_token'=>$this->getCsrfToken()]); }
    public function maintainEnterpriseIntegrationJournal(array $input): array
    {
        $this->assertEnterpriseAdministrator();$input=IncidentConfig::object($input,['retention_days','evidence_retention_days'],'Integration archival');
        $days=$input['retention_days']??7;if(!is_int($days)||$days<1||$days>365){throw new \InvalidArgumentException('Retention must be 1–365 days.');}
        $evidenceDays=$input['evidence_retention_days']??30;if(!is_int($evidenceDays)||$evidenceDays<1||$evidenceDays>365){throw new \InvalidArgumentException('Full-evidence retention must be 1–365 days.');}
        return (new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()))->maintain(time(),$days*86400,$evidenceDays*86400);
    }
    public function resolveEnterpriseIntegrationUncertainty(array $input): array
    {
        $principal=$this->assertEnterpriseAdministrator();$input=IncidentConfig::object($input,['request_id','resolution','evidence_reference'],'Integration uncertainty resolution');
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');$resolution=$input['resolution']??'';$evidence=IncidentConfig::text($input['evidence_reference']??'',256,'Evidence reference');
        if(!is_string($resolution)){throw new \InvalidArgumentException('Choose the reviewed provider outcome.');}
        $resolver=hash('sha256',json_encode([$principal['id']??'',$principal['username']??''],JSON_THROW_ON_ERROR));
        $row=(new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()))->resolveUncertain($request,$resolution,$evidence,$resolver,time());
        return ['success'=>true,'message'=>'The reviewed uncertainty was closed without replaying the provider operation.','operation'=>array_diff_key($row,['fingerprint'=>true,'resolved_by'=>true]),'state'=>$this->getEnterpriseIntegrationState()];
    }
    public function getEnterpriseContinuityReport(): array
    {
        $this->assertEnterpriseAdministrator();require_once __DIR__.'/EnterpriseContinuity.php';
        return ['success'=>true,'report'=>\SLS\MassNotify\EnterpriseContinuity::report($this->getActiveSettings(), self::MODULE_VERSION)];
    }
    public function exportEnterpriseIntegrationReport(): array
    {
        $this->assertEnterpriseAdministrator();$store=new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory());$state=$store->read();
        $report=['schema'=>2,'generated_at'=>gmdate('c'),'operations'=>array_replace($store->archiveReport('operations'),$state['operations']),
            'responses'=>array_replace($store->archiveReport('responses'),$state['responses']),
            'operation_replay_tombstones'=>$store->tombstoneReport('operations'),'response_replay_tombstones'=>$store->tombstoneReport('responses')];
        foreach($report['operations'] as &$operation){unset($operation['fingerprint']);}unset($operation);
        foreach($report['responses'] as &$response){unset($response['fingerprint']);}unset($response);
        return ['success'=>true,'report'=>$report];
    }
    private function integrationEffect(string $request,string $channel,string $target,array $fingerprint): callable
    {
        $settings=$this->getActiveSettings();
        return static function(callable $send,int $createdAt) use($settings,$request,$channel,$target,$fingerprint): array {
            require_once __DIR__.'/EnterpriseClusterIntegration.php';
            return \SLS\MassNotify\EnterpriseClusterIntegration::effect($settings,['delivery_id'=>'announcement-'.$request,'created_at'=>$createdAt,'expires_at'=>$createdAt+300,'integration'=>$fingerprint],$channel,$target,$send);
        };
    }
    public function createIncidentMeeting(string $id,array $input): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enabledEnterpriseIntegration('meetings'); $incident=$this->incidentStore()->read($id);
        if (!$incident) { throw new \DomainException('Incident does not exist.'); }
        if(($config['oauth_refresh_enabled']??'0')==='1'&&($config['oauth_expires_at']??0)>0&&$config['oauth_expires_at']<=time()+60){$this->refreshEnterpriseMeetingToken();$config=$this->enabledEnterpriseIntegration('meetings');}
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return ['success'=>true]+$this->enterpriseIntegrationsService()->meeting($config,$incident,$input,$this->integrationEffect($request,'incident_meeting',$id,['incident'=>$id,'input'=>$input,'provider'=>$config['provider']]));
    }
    public function refreshEnterpriseMeetingToken(): array
    {
        $this->assertEnterpriseAdministrator();$config=$this->enterpriseIntegrationsConfig();$revision=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));$id=hash('sha256',json_encode($config['meetings'],JSON_THROW_ON_ERROR));
        $state=$this->enterpriseLabsStore();$state->transaction('oauth_refresh',static function(array &$rows)use($id):void{
            if(isset($rows[$id])){throw new \DomainException('This OAuth refresh was already attempted. Review provider authorization and save replacement credentials before trying again.');}
            if(count($rows)>=1000){throw new \DomainException('OAuth refresh audit allocation reached. Review its private history.');}$rows[$id]=['state'=>'claimed','created_at'=>time()];
        });
        require_once __DIR__.'/EnterpriseIntegrationsProvider.php';$config['meetings']=(new \SLS\MassNotify\EnterpriseIntegrationsProvider())->refreshMeeting($config['meetings'],time());
        $this->saveEnterpriseNamespace('enterprise_integrations',$config,$revision);
        $state->transaction('oauth_refresh',static function(array &$rows)use($id):void{$rows[$id]['state']='saved';$rows[$id]['finished_at']=time();});
        return ['success'=>true,'message'=>'Provider tokens renewed and saved to encrypted configuration.','expires_at'=>gmdate('c',$config['meetings']['oauth_expires_at'])];
    }
    public function revokeEnterpriseMeetingToken(): array
    {
        $this->assertEnterpriseAdministrator();$config=$this->enterpriseIntegrationsConfig();$revision=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));$provider=$config['meetings'];
        $config['meetings']['enabled']='0';$config['meetings']['oauth_refresh_enabled']='0';$config['meetings']['oauth_refresh_token']='';$config['meetings']['oauth_expires_at']=0;
        foreach(['zoom_access_token','google_access_token','webex_access_token'] as $field){$config['meetings'][$field]='';}
        $this->saveEnterpriseNamespace('enterprise_integrations',$config,$revision);
        require_once __DIR__.'/EnterpriseIntegrationsProvider.php';try{$result=(new \SLS\MassNotify\EnterpriseIntegrationsProvider())->revokeMeeting($provider);}catch(\Throwable $error){$result=['revoked'=>false,'provider_contacted'=>true];}
        return ['success'=>true,'message'=>'Local meeting access disabled and tokens removed.','provider_revocation'=>$result];
    }
    public function controlIntegrationDoor(array $input): array
    {
        $this->assertEnterpriseAdministrator();$input=IncidentConfig::object($input,['request_id','door_id','operation'],'Door operation');
        if(!is_string($input['door_id']??null)||!is_string($input['operation']??null)){throw new \InvalidArgumentException('Door identifier and operation must be text.');}
        $config=$this->enabledEnterpriseIntegration('access_control',$input['operation']!=='read');
        if (($input['operation']??'')!=='read') { require_once __DIR__.'/LabsSafety.php'; \SLS\MassNotify\LabsSafety::requireReceipt($this->getActiveSettings(),'access_control_actuation'); }
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return ['success'=>true]+$this->enterpriseIntegrationsService()->door($config,$input,$this->integrationEffect($request,'access_control',(string)($input['door_id']??''),$input));
    }
    public function readIntegrationSpeakerTelemetry(string $id): array
    { $this->assertEnterpriseAdministrator(); return ['success'=>true]+$this->enterpriseIntegrationsService()->speakerTelemetry($this->enabledEnterpriseIntegration('speakers',false),$id); }
    public function sendIntegrationSpeakerAnnouncement(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enabledEnterpriseIntegration('speakers');
        $input=IncidentConfig::object($input,['speaker_ids','message','title','is_test'],'Speaker announcement'); $devices=array_column($config['devices'],null,'id'); $extensions=[];
        foreach (IncidentConfig::listOf($input['speaker_ids']??[],100,'SIP speaker selection') as $id) {
            EnterpriseIntegrationsConfig::id($id,'spk'); $speaker=$devices[$id]??null;
            if (!$speaker || $speaker['enabled']!=='1') { throw new \DomainException('A selected SIP speaker is disabled or unavailable.'); } $extensions[]=$speaker['extension'];
        }
        if (!$extensions) { throw new \InvalidArgumentException('Choose an enabled saved SIP speaker.'); }
        $test=IncidentConfig::flag($input['is_test']??true,'Speaker drill flag'); $message=IncidentConfig::text($input['message']??'',500,'Speaker message');
        return $this->sendSipNotifyAnnouncement(array_values(array_unique($extensions)),$message,false,true,[],['audio_mode'=>'tts',
            'title'=>IncidentConfig::text($input['title']??'Speaker announcement',80,'Speaker title'),'trigger_source'=>'Enterprise SIP speaker','_is_test'=>$test]);
    }
    public function sendIntegrationLabsAnnouncement(array $input): array
    {
        $this->assertEnterpriseAdministrator();
        $input=IncidentConfig::object($input,['browser_recipient_ids','connector_ids','message','title','is_test'],'Labs announcement');
        return $this->controlApiSendAnnouncement(['targets'=>[],'message'=>IncidentConfig::text($input['message']??'',1500,'Announcement message'),
            'title'=>IncidentConfig::text($input['title']??'Labs delivery test',100,'Announcement title'),
            'browser_recipient_ids'=>IncidentConfig::listOf($input['browser_recipient_ids']??[],50,'Browser recipients'),
            'connector_ids'=>IncidentConfig::listOf($input['connector_ids']??[],20,'Connectors'),
            'options'=>['audio_mode'=>'none','_is_test'=>IncidentConfig::flag($input['is_test']??true,'Test flag'),'force_queue'=>true]]);
    }
    public function validatePublicWarningAcceptance(array $input): array
    {
        $this->assertEnterpriseAdministrator();$config=$this->enabledEnterpriseIntegration('public_warning',false);
        $input=IncidentConfig::object($input,['warning','post_envelope'],'Public warning acceptance');
        require_once __DIR__.'/EnterprisePublicWarning.php';
        $warning=$input['warning']??null;$envelope=$input['post_envelope']??null;
        if(!is_array($warning)||array_is_list($warning)||!is_string($envelope)){throw new \InvalidArgumentException('Public warning acceptance requires a warning object and signed envelope text.');}
        $draft=EnterprisePublicWarning::author($warning,time());
        if(!in_array($warning['status']??'', ['Test','Exercise'],true)){throw new \DomainException('Acceptance validation requires a Test or Exercise warning.');}
        EnterprisePublicWarning::verifyPreparedEnvelope($envelope,$draft['xml'],$config,time());
        return ['success'=>true,'message'=>'Local CAP and signed-envelope checks passed. Nothing was transmitted. FEMA CDTE and channel acceptance remain pending.',
            'cap_sha256'=>hash('sha256',$draft['xml']),'envelope_sha256'=>hash('sha256',$envelope),'authority_qualification'=>'pending_external_acceptance'];
    }
    public function exportPublicWarning(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $this->enabledEnterpriseIntegration('public_warning',false); require_once __DIR__.'/EnterprisePublicWarning.php';
        return EnterprisePublicWarning::author($input,time());
    }
    public function submitPublicWarning(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enabledEnterpriseIntegration('public_warning'); require_once __DIR__.'/LabsSafety.php';
        \SLS\MassNotify\LabsSafety::requireReceipt($this->getActiveSettings(),'public_warning_origination');
        $input=IncidentConfig::object($input,['request_id','warning','post_envelope'],'IPAWS submission');$warning=$input['warning']??null;$envelope=$input['post_envelope']??null;
        if(!is_array($warning)||array_is_list($warning)||!is_string($envelope)){throw new \InvalidArgumentException('IPAWS submission requires a warning object and signed envelope text.');}
        $identifier=$warning['identifier']??null;if(!is_string($identifier)){throw new \InvalidArgumentException('IPAWS warning identifier must be text.');}
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return ['success'=>true]+$this->enterpriseIntegrationsService()->publicWarning($config,$input,$this->integrationEffect($request,'public_warning',$identifier,['warning'=>$warning,'envelope_sha256'=>hash('sha256',$envelope)]));
    }
    /** Additive frozen metadata. Normal announcement bodies/phone audio remain unchanged when off. */
    protected function snapshotEnterpriseIntegrationResponses(array $request,array $settings): array
    {
        $config=$this->enterpriseIntegrationsConfig($settings); if ($config['enabled']!=='1') { return $request; }
        if ($config['speakers']['enabled']==='1') {
            foreach ($config['speakers']['devices'] as $speaker) {
                if ($speaker['enabled']==='1' && in_array($speaker['extension'],$request['extensions']??[],true)) {
                    $request['speaker_profiles'][$speaker['extension']]=array_intersect_key($speaker,array_flip(['id','brand','model','extension']));
                }
            }
        }
        $incidentId=$request['incident_context']['incident_id']??'';
        if ($config['responses']['enabled']!=='1' || $incidentId==='') { return $request; }
        $incident=$this->incidentStore()->read($incidentId); if (!$incident || $incident['state']!=='open') { throw new \DomainException('Incident response enrollment requires an open incident.'); }
        $people=array_column($incident['template']['roster'],null,'id'); $service=$this->enterpriseIntegrationsService();
        $sms=\SLS\MassNotify\Sms\Config::normalize($settings['announcement_sms']??[]); $smsRoute=empty($request['sms_targets'])?'':\SLS\MassNotify\Sms\Config::route($sms,\SLS\MassNotify\Sms\Service::callbackUrl($settings));
        foreach ($config['responses']['bindings'] as $binding) {
            $person=$people[$binding['person_id']]??null; if (!$person) { continue; } $id=$binding['sms_recipient_id'];
            if ($id!=='' && isset($request['sms_targets'][$id])) {
                $target=&$request['sms_targets'][$id];
                $row=$service->bindResponse($config['responses'],$incident,$person,'sms',$target+['provider'=>$sms['provider']],$smsRoute);
                $target['body'].=EnterpriseIntegrationsService::smsPrompt($row);
                $type=$target['message_type']??'SMS'; if ($type==='SMS' && \SLS\MassNotify\Sms\Config::units($target['body'],$sms['from'],$type)['segments']>$sms['max_segments']) { throw new \DomainException('Incident response instructions exceed the SMS segment limit. Shorten the message before sending.'); }
                $target['body_hash']=\SLS\MassNotify\Sms\Config::messageHash($target['body'],$type,$target['media']??[]); $target['incident_response_binding']=$row['id']; unset($target);
            }
            if(!isset($request['outbound_targets'])){$request['outbound_targets']=[];}
            if(!is_array($request['outbound_targets'])){throw new \RuntimeException('Frozen outbound voice targets are invalid.');}
            foreach ($request['outbound_targets'] as &$target) {
                if ($binding['voice_recipient_id']!==$target['id']) { continue; }
                $row=$service->bindResponse($config['responses'],$incident,$person,'voice',$target,$this->outboundVoiceFingerprint($target));
                $target['incident_response']=['binding_id'=>$row['id'],'token'=>$row['token'],'incident_id'=>$incidentId,'person_id'=>$person['id'],'recipient_id'=>$target['id']];
            } unset($target);
        }
        return $request;
    }
    private function integrationResponseCurrent(array $binding): bool
    {
        $settings=$this->getActiveSettings(); $config=$this->enterpriseIntegrationsConfig($settings);
        if ($config['enabled']!=='1' || $config['responses']['enabled']!=='1'
            || !hash_equals($binding['config_fingerprint'],EnterpriseIntegrationsConfig::fingerprint($config['responses']))) { return false; }
        $incident=$this->incidentStore()->read($binding['incident_id']); if (!$incident || $incident['state']!=='open') { return false; }
        $person=array_column($incident['template']['roster'],null,'id')[$binding['person_id']]??null;
        if (!$person || !hash_equals($binding['person_fingerprint'],EnterpriseIntegrationsConfig::fingerprint($person))) { return false; }
        $field=$binding['channel']==='sms'?'sms_recipient_ids':'voice_recipient_ids'; if (!in_array($binding['recipient_id'],$incident['template']['delivery'][$field]??[],true)) { return false; }
        if ($binding['channel']==='sms') {
            $sms=\SLS\MassNotify\Sms\Config::normalize($settings['announcement_sms']??[]); $target=\SLS\MassNotify\Sms\Service::targets($settings)[$binding['recipient_id']]??null;
            if (!$target || $sms['provider']!==$binding['provider']) { return false; }
            $route=\SLS\MassNotify\Sms\Config::route($sms,\SLS\MassNotify\Sms\Service::callbackUrl($settings));
        } else { $target=$this->outboundVoiceTargets($settings)[$binding['recipient_id']]??null; if (!$target) { return false; } $route=$this->outboundVoiceFingerprint($target); }
        return hash_equals($binding['route'],$route) && hash_equals($binding['number_hash'],hash('sha256',$target['number']));
    }
    public function receiveIncidentSmsResponse(array $event): array
    {
        $config=$this->enterpriseIntegrationsConfig(); if ($config['enabled']!=='1' || $config['responses']['enabled']!=='1') { return ['state'=>'ignored']; }
        return $this->enterpriseIntegrationsService()->smsResponse($config['responses'],$event,fn($row)=>$this->integrationResponseCurrent($row),
            function($id,$input,$actor): array { return $this->incidentService()->respond($id,$input,$actor); });
    }
    /** Invoked only by the authenticated local AGI after exact admission/answer proof. */
    public function receiveIncidentVoiceResponse(array $input): array
    {
        if (PHP_SAPI!=='cli') { throw new \DomainException('Incident voice evidence is accepted only by the local PBX runtime.'); }
        $config=$this->enterpriseIntegrationsConfig(); if ($config['enabled']!=='1' || $config['responses']['enabled']!=='1') { return ['state'=>'disabled']; }
        return $this->enterpriseIntegrationsService()->voiceResponse($config['responses'],$input,fn($row)=>$this->integrationResponseCurrent($row),
            function($id,$response,$actor): array { return $this->incidentService()->respond($id,$response,$actor); });
    }
    public function receiveSensorHeartbeat(string $ruleId,array $input): array
    {
        $config=$this->enabledEnterpriseIntegration('sensors',false); $rules=AutomationConfig::normalize($this->getActiveSettings()['automations']??[])['rules'];
        $rule=array_column($rules,null,'id')[$ruleId]??null; if (!$rule) { throw new \DomainException('Unknown enrolled sensor.'); }
        return $this->enterpriseIntegrationsService()->heartbeat($config,$rule,$input);
    }
}
