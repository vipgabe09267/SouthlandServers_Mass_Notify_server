<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';
use SLS\MassNotify\{EnterpriseIntegrationsConfig,EnterpriseIntegrationsService,EnterpriseIntegrationsStore,EnterprisePublicWarning,IncidentConfig,AutomationConfig};

trait SlsEnterpriseIntegrations
{
    protected function enterpriseIntegrationsStoreDirectory(): string { return self::PLUGIN_DATA_DIR.'/enterprise-integrations'; }
    private function enterpriseIntegrationsService(): EnterpriseIntegrationsService
    {
        require_once __DIR__.'/EnterpriseIntegrationsService.php';
        return new EnterpriseIntegrationsService(new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()));
    }
    private function enterpriseIntegrationsConfig(?array $settings=null): array
    { return EnterpriseIntegrationsConfig::normalize(($settings??$this->getActiveSettings())['enterprise_integrations']??[]); }
    private function enabledEnterpriseIntegration(string $section): array
    {
        $settings=$this->getActiveSettings(); $config=$this->enterpriseIntegrationsConfig($settings);
        if (empty($settings['enabled']) || $config['enabled']!=='1' || $config[$section]['enabled']!=='1') { throw new \DomainException('This Enterprise Labs integration is disabled.'); }
        return $config[$section];
    }
    public function getEnterpriseIntegrationState(): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enterpriseIntegrationsConfig(); $history=[]; $health=[]; $error='';
        if (is_dir($this->enterpriseIntegrationsStoreDirectory())) {
            try {
                require_once __DIR__.'/EnterpriseIntegrationsStore.php'; $state=(new EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()))->read();
                foreach (array_slice(array_reverse($state['operations']),0,50) as $row) { $history[]=array_diff_key($row,['fingerprint'=>true]); }
                $rules=array_column(AutomationConfig::normalize($this->getActiveSettings()['automations']??[])['rules'],null,'id');
                foreach ($state['sensors'] as $id=>$row) {
                    if (!isset($rules[$id]) || $rules[$id]['kind']!=='sensor') { continue; }
                    $health[]=['rule_id'=>$id,'name'=>$rules[$id]['name'],'last_seen'=>$row['last_seen'],'is_test'=>$row['is_test'],
                        'state'=>$config['enabled']!=='1' || $config['sensors']['enabled']!=='1' || empty($rules[$id]['enabled'])?'disabled':
                            (hash_equals($row['rule_revision'],EnterpriseIntegrationsConfig::fingerprint($rules[$id])) && $row['last_seen']+ $config['sensors']['heartbeat_timeout_seconds']>time()?'healthy':'stale')];
                }
            } catch (\Throwable $exception) { $error='Protected integration history is unavailable. Check storage before submitting another provider action.'; }
        }
        return ['config'=>EnterpriseIntegrationsConfig::redacted($config),'revision'=>hash('sha256',json_encode($config,JSON_THROW_ON_ERROR)),
            'catalog'=>EnterpriseIntegrationsConfig::catalog(),'history'=>$history,'sensor_health'=>$health,'storage_error'=>$error,
            'extensions'=>array_map('strval',$this->getConfiguredPjsipExtensionNumbers()),
            'sms_recipients'=>array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['name']??$r['id']],$this->getActiveSettings()['announcement_sms']['recipients']??[]),
            'voice_recipients'=>array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['name']??$r['id']],$this->getActiveSettings()['outbound_voice']['recipients']??[]),
            'people'=>$this->integrationRosterChoices(),
            'incidents'=>array_values(array_map(static fn($row)=>['id'=>$row['id'],'name'=>$row['title']],array_filter(($this->listIncidents(50)['incidents']??[]),static fn($row)=>($row['state']??'')==='open'))),
            'qualification'=>'Labs · isolated protocol fixtures only; hardware, OAuth accounts and FEMA authority acceptance are pending.',
            'ipaws_qualification'=>'Requires an authorized COG, current certificate/key, issued WSDL-generated signed envelope and CDTE acceptance. No FEMA certification is claimed.'];
    }
    private function integrationRosterChoices(): array
    {
        $people=[]; foreach ($this->getActiveSettings()['incident_workflows']['templates']??[] as $template) {
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
        foreach (['access_control'=>['password'],'meetings'=>['zoom_access_token','google_access_token','webex_access_token'],
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
            $old=array_column($current['speakers']['devices'],null,'id');
            foreach ($merged['speakers']['devices'] as &$row) {
                if (!is_array($row)) { throw new \InvalidArgumentException('Speaker entries must be objects.'); }
                if (($row['id']??'')==='') { $row['id']='spk_'.bin2hex(random_bytes(12)); }
                unset($row['telemetry_password_configured']); $path='speakers.'.$row['id'].'.telemetry_password';
                if (in_array($path,$clear,true)) { $row['telemetry_password']=''; }
                elseif (($row['telemetry_password']??'')==='') { $row['telemetry_password']=$old[$row['id']]['telemetry_password']??''; }
            } unset($row);
        }
        if (isset($value['access_control']['doors'])) { foreach ($merged['access_control']['doors'] as &$row) { if (is_array($row) && ($row['id']??'')==='') { $row['id']='door_'.bin2hex(random_bytes(12)); } } unset($row); }
        $config=EnterpriseIntegrationsConfig::normalize($merged); $extensions=array_map('strval',$this->getConfiguredPjsipExtensionNumbers());
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
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return $this->enterpriseIntegrationsService()->meeting($config,$incident,$input,$this->integrationEffect($request,'incident_meeting',$id,['incident'=>$id,'input'=>$input,'provider'=>$config['provider']]));
    }
    public function controlIntegrationDoor(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enabledEnterpriseIntegration('access_control');
        if (($input['operation']??'')!=='read') { require_once __DIR__.'/LabsSafety.php'; \SLS\MassNotify\LabsSafety::requireReceipt($this->getActiveSettings(),'access_control_actuation'); }
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return $this->enterpriseIntegrationsService()->door($config,$input,$this->integrationEffect($request,'access_control',(string)($input['door_id']??''),$input));
    }
    public function readIntegrationSpeakerTelemetry(string $id): array
    { $this->assertEnterpriseAdministrator(); return $this->enterpriseIntegrationsService()->speakerTelemetry($this->enabledEnterpriseIntegration('speakers'),$id); }
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
    public function exportPublicWarning(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $this->enabledEnterpriseIntegration('public_warning'); require_once __DIR__.'/EnterprisePublicWarning.php';
        return EnterprisePublicWarning::author($input,time());
    }
    public function submitPublicWarning(array $input): array
    {
        $this->assertEnterpriseAdministrator(); $config=$this->enabledEnterpriseIntegration('public_warning'); require_once __DIR__.'/LabsSafety.php';
        \SLS\MassNotify\LabsSafety::requireReceipt($this->getActiveSettings(),'public_warning_origination');
        $request=EnterpriseIntegrationsConfig::requestId($input['request_id']??'');
        return $this->enterpriseIntegrationsService()->publicWarning($config,$input,$this->integrationEffect($request,'public_warning',(string)($input['warning']['identifier']??''),['warning'=>$input['warning']??[],'envelope_sha256'=>hash('sha256',(string)($input['post_envelope']??''))]));
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
        if (empty($settings['enabled']) || $config['enabled']!=='1' || $config['responses']['enabled']!=='1'
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
        $config=$this->enabledEnterpriseIntegration('sensors'); $rules=AutomationConfig::normalize($this->getActiveSettings()['automations']??[])['rules'];
        $rule=array_column($rules,null,'id')[$ruleId]??null; if (!$rule) { throw new \DomainException('Unknown enrolled sensor.'); }
        return $this->enterpriseIntegrationsService()->heartbeat($config,$rule,$input);
    }
}
