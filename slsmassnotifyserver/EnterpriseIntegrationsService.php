<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIntegrationsStore.php';
require_once __DIR__.'/EnterpriseIntegrationsProvider.php';
require_once __DIR__.'/EnterprisePublicWarning.php';

final class EnterpriseIntegrationsService
{
    private EnterpriseIntegrationsStore $store; private EnterpriseIntegrationsProvider $provider; private $clock;
    public function __construct(EnterpriseIntegrationsStore $store,?EnterpriseIntegrationsProvider $provider=null,?callable $clock=null)
    { $this->store=$store; $this->provider=$provider??new EnterpriseIntegrationsProvider(); $this->clock=$clock??static fn(): int=>time(); }
    private function now(): int { return (int)($this->clock)(); }
    public static function fingerprint(array $value): string { return EnterpriseIntegrationsConfig::fingerprint($value); }
    public static function requestId($value): string { return IncidentConfig::identifier($value,'/^[a-f0-9]{32}$/D','Integration request identifier'); }
    /** Claim before side effects; interrupted requests remain uncertain and are never retried. */
    private function perform(string $request,string $kind,array $input,callable $operation): array
    {
        self::requestId($request); $fingerprint=self::fingerprint($input); $now=$this->now();
        $claim=$this->store->transaction(static function(array &$state) use($request,$kind,$fingerprint,$now): array {
            if (isset($state['operations'][$request])) {
                $row=$state['operations'][$request]; if (!hash_equals($row['fingerprint'],$fingerprint) || $row['kind']!==$kind) { throw new \DomainException('This request identifier belongs to different integration details.'); }
                if ($row['state']==='submitting') { $row['state']='uncertain'; $row['detail']='The operation was claimed but completion is unconfirmed. No automatic replay is allowed.'; }
                return ['claimed'=>false,'row'=>$row];
            }
            $row=['request_id'=>$request,'kind'=>$kind,'fingerprint'=>$fingerprint,'state'=>'submitting','created_at'=>$now];
            $state['operations'][$request]=$row; return ['claimed'=>true,'row'=>$row];
        });
        if (!$claim['claimed']) { return $claim['row']; }
        try { $outcome=$operation($claim['row']); }
        catch (\DomainException $error) { $outcome=['state'=>'failed','detail'=>$error->getMessage()]; }
        catch (\Throwable $error) { $outcome=['state'=>'uncertain','detail'=>'Provider completion is unconfirmed. Review the provider before another request. No automatic replay is allowed.']; }
        return $this->store->transaction(static function(array &$state) use($request,$outcome,$now): array {
            $state['operations'][$request]=array_replace($state['operations'][$request],$outcome,['finished_at'=>$now]); return $state['operations'][$request];
        });
    }
    public function meeting(array $config,array $incident,array $input,?callable $effect=null): array
    {
        $input=IncidentConfig::object($input,['request_id','duration_minutes','start_at'],'Incident meeting');
        if (($config['enabled']??'0')!=='1' || ($incident['state']??'')!=='open') { throw new \DomainException('Meeting creation requires an enabled provider and an open incident.'); }
        $duration=$input['duration_minutes']??30; if (!is_int($duration) || $duration<5 || $duration>240) { throw new \InvalidArgumentException('Meeting duration must be 5–240 minutes.'); }
        $start=$input['start_at']??null; if (!is_int($start) || $start<$this->now() || $start>$this->now()+86400) { throw new \InvalidArgumentException('Choose a meeting start within 24 hours.'); }
        $request=self::requestId($input['request_id']??''); $title=IncidentConfig::text($incident['title']??'',100,'Meeting title');
        $fingerprint=['incident_id'=>$incident['id'],'config'=>self::fingerprint($config),'start'=>$start,'duration'=>$duration];
        return $this->perform($request,'meeting',$fingerprint,function(array $claim) use($config,$title,$start,$duration,$effect): array {
            $send=fn()=> $this->provider->meeting($config,$title,$start,$duration); return $effect?$effect($send,$claim['created_at']):$send();
        });
    }
    public function door(array $config,array $input,?callable $effect=null): array
    {
        $input=IncidentConfig::object($input,['request_id','door_id','operation'],'Door operation'); $id=EnterpriseIntegrationsConfig::id($input['door_id']??'','door');
        $door=array_column($config['doors'],null,'id')[$id]??null; $operation=$input['operation']??'';
        if (($config['enabled']??'0')!=='1' || !$door || !in_array($operation,['read','lock','unlock'],true) || empty($door['allow_'.$operation])) { throw new \DomainException('This door operation is disabled or outside its saved permission.'); }
        if ($operation==='read') { return $this->provider->door($config,$door,$operation); }
        $request=self::requestId($input['request_id']??'');
        return $this->perform($request,'door',['config'=>self::fingerprint($config),'door'=>$door,'operation'=>$operation],function(array $claim) use($config,$door,$operation,$effect): array {
            $send=fn()=> $this->provider->door($config,$door,$operation); return $effect?$effect($send,$claim['created_at']):$send();
        });
    }
    public function speakerTelemetry(array $config,string $id): array
    {
        EnterpriseIntegrationsConfig::id($id,'spk'); $speaker=array_column($config['devices'],null,'id')[$id]??null;
        if (($config['enabled']??'0')!=='1' || !$speaker) { throw new \DomainException('SIP speaker telemetry is disabled.'); }
        return $this->provider->speakerTelemetry($speaker);
    }
    public function publicWarning(array $config,array $input,?callable $effect=null): array
    {
        $input=IncidentConfig::object($input,['request_id','warning','post_envelope'],'IPAWS submission');
        if (($config['enabled']??'0')!=='1' || ($config['ipaws_enabled']??'0')!=='1' || empty($config['authority_confirmed'])) { throw new \DomainException('IPAWS submission is disabled or lacks authorized COG configuration.'); }
        $request=self::requestId($input['request_id']??''); $warning=EnterprisePublicWarning::author($input['warning']??[],$this->now());
        $envelope=$input['post_envelope']??''; if (!is_string($envelope)) { throw new \InvalidArgumentException('A WSDL-generated signed postCAP envelope is required.'); }
        EnterprisePublicWarning::verifyPreparedEnvelope($envelope,$warning['xml'],$config,$this->now());
        return $this->perform($request,'ipaws',['config'=>self::fingerprint($config),'warning_hash'=>$warning['sha256'],'envelope_hash'=>hash('sha256',$envelope)],function(array $claim) use($config,$envelope,$effect): array {
            $send=fn()=> $this->provider->ipaws($config,$envelope); return $effect?$effect($send,$claim['created_at']):$send();
        });
    }
    public function bindResponse(array $config,array $incident,array $person,string $channel,array $target,string $route): array
    {
        if (($config['enabled']??'0')!=='1') { return []; }
        if (!in_array($channel,['sms','voice'],true) || ($incident['state']??'')!=='open' || !isset($person['id'])
            || !preg_match('/^\+[1-9][0-9]{1,14}$/D',$target['number']??'')) { throw new \DomainException('Human response binding lacks an open incident and saved participant.'); }
        $provider=$channel==='voice'?'pbx':($target['provider']??'');
        if (!in_array($provider,['pbx','twilio','telnyx'],true)) { throw new \DomainException('This provider has no authenticated incident response contract.'); }
        $id=hash('sha256',$incident['id'].'|'.$person['id'].'|'.$channel.'|'.$target['id']); $now=$this->now();
        $identity=['incident_id'=>$incident['id'],'person_id'=>$person['id'],'person_fingerprint'=>self::fingerprint($person),'channel'=>$channel,
            'recipient_id'=>$target['id'],'number_hash'=>hash('sha256',$target['number']),'provider'=>$provider,'route'=>$route,'config_fingerprint'=>self::fingerprint($config)];
        return $this->store->transaction(static function(array &$state) use($id,$identity,$now,$config): array {
            if (isset($state['bindings'][$id])) {
                $existing=$state['bindings'][$id]; if (!hash_equals($existing['identity_fingerprint'],self::fingerprint($identity))) { throw new \DomainException('This incident reply binding changed. Retire it and review a new incident before enrolling another identity.'); } return $existing;
            }
            do { $token=strtoupper(bin2hex(random_bytes(6))); } while (in_array($token,array_column($state['bindings'],'token'),true));
            return $state['bindings'][$id]=['id'=>$id,'token'=>$token,'identity_fingerprint'=>self::fingerprint($identity),'created_at'=>$now,'expires_at'=>$now+$config['ttl_seconds']]+$identity;
        });
    }
    public static function smsPrompt(array $binding): string
    { return $binding?' Reply SLS '.$binding['token'].' SAFE, HELP, or RECEIVED.':''; }
    public function smsResponse(array $config,array $event,callable $current,callable $respond): array
    {
        if (($config['enabled']??'0')!=='1' || empty($event['inbound']) || !empty($event['opt_out'])) { return ['state'=>'ignored']; }
        $body=$event['body']??'';
        if (!is_string($body) || !preg_match('/^SLS ([A-F0-9]{12}) (SAFE|HELP|RECEIVED)$/Di',trim($body),$match)) { return ['state'=>'ignored']; }
        $rows=array_values(array_filter($this->store->read()['bindings'],fn($row)=>$row['channel']==='sms' && $row['token']===strtoupper($match[1])
            && $row['provider']===($event['provider']??'') && $row['number_hash']===hash('sha256',$event['from']??'') && $row['expires_at']>$this->now()));
        if (count($rows)!==1 || !$current($rows[0])) { throw new \DomainException('SMS reply has no unique current provider, participant and incident binding.'); }
        return $this->humanResponse($rows[0],(string)($event['event_id']??''),['SAFE'=>'safe','HELP'=>'needs_assistance','RECEIVED'=>'received'][strtoupper($match[2])],$respond);
    }
    public function voiceResponse(array $config,array $input,callable $current,callable $respond): array
    {
        if (($config['enabled']??'0')!=='1') { return ['state'=>'ignored']; }
        $input=IncidentConfig::object($input,['binding_id','token','recipient_id','event_id','digit'],'Incident keypad response');
        $id=IncidentConfig::identifier($input['binding_id']??'','/^[a-f0-9]{64}$/D','Response binding'); $row=$this->store->read()['bindings'][$id]??null;
        if (!$row || $row['channel']!=='voice' || $row['expires_at']<=$this->now() || !is_string($input['token']??null)
            || !hash_equals($row['token'],$input['token']) || $row['recipient_id']!==($input['recipient_id']??'') || !$current($row)) { throw new \DomainException('Keypad response lacks a current call, participant and incident binding.'); }
        $response=['1'=>'received','2'=>'safe','3'=>'needs_assistance'][$input['digit']??'']??null;
        if ($response===null) { return ['state'=>'ignored']; }
        return $this->humanResponse($row,(string)($input['event_id']??''),$response,$respond);
    }
    private function humanResponse(array $binding,string $event,string $response,callable $respond): array
    {
        if ($event==='' || strlen($event)>200 || preg_match('/[\x00-\x1f]/',$event)) { throw new \InvalidArgumentException('Human response needs a verified stable event identity.'); }
        $id=hash('sha256',$binding['provider'].'|'.$event); $fingerprint=self::fingerprint([$binding['id'],$response]); $now=$this->now();
        $claim=$this->store->transaction(static function(array &$state) use($id,$fingerprint,$now): array {
            if (isset($state['responses'][$id])) { if (!hash_equals($state['responses'][$id]['fingerprint'],$fingerprint)) { throw new \DomainException('This provider event was already bound to different response details.'); } return $state['responses'][$id]; }
            return $state['responses'][$id]=['fingerprint'=>$fingerprint,'state'=>'pending','created_at'=>$now];
        });
        if ($claim['state']==='complete') { return ['state'=>'duplicate']; }
        // IncidentService provides its own durable idempotency; pending commits may retry safely.
        $respond($binding['incident_id'],['request_id'=>substr($id,0,32),'person_id'=>$binding['person_id'],'response'=>$response,'note'=>'Explicit participant reply'],
            ['identity'=>'Participant '.$binding['person_id'],'source'=>$binding['channel']==='sms'?'verified_sms_human':'bound_call_dtmf_human']);
        $this->store->transaction(static function(array &$state) use($id): void { $state['responses'][$id]['state']='complete'; });
        return ['state'=>'recorded','incident_id'=>$binding['incident_id'],'person_id'=>$binding['person_id'],'response'=>$response];
    }
    /** Caller must have checked the existing exact-body HMAC and verified HTTPS. */
    public function heartbeat(array $config,array $rule,array $input): array
    {
        $input=IncidentConfig::object($input,['operation','request_id','sent_at','expires_at','is_test'],'Sensor heartbeat'); $now=$this->now();
        if (($config['enabled']??'0')!=='1' || ($rule['kind']??'')!=='sensor' || empty($rule['enabled']) || ($input['operation']??'')!=='heartbeat'
            || !is_int($input['sent_at']??null) || abs($input['sent_at']-$now)>60 || !is_int($input['expires_at']??null)
            || $input['expires_at']<=$now || $input['expires_at']>$input['sent_at']+300 || !is_bool($input['is_test']??null)) { throw new \DomainException('Heartbeat source is disabled, expired or invalid.'); }
        $request=self::requestId($input['request_id']??''); $fingerprint=self::fingerprint($input);
        return $this->store->transaction(static function(array &$state) use($rule,$input,$request,$fingerprint,$now): array {
            $previous=$state['sensors'][$rule['id']]??null;
            if ($previous && $previous['request_id']===$request) {
                if (!hash_equals($previous['fingerprint'],$fingerprint)) { throw new \DomainException('Heartbeat identity was reused with different details.'); } return ['ok'=>true,'state'=>'duplicate','last_seen'=>$previous['last_seen']];
            }
            if ($previous && $input['sent_at']<=$previous['sent_at']) { throw new \DomainException('Out-of-order/replayed heartbeat cannot renew source health.'); }
            $state['sensors'][$rule['id']]=['request_id'=>$request,'fingerprint'=>$fingerprint,'sent_at'=>$input['sent_at'],'last_seen'=>$now,'is_test'=>$input['is_test'],'rule_revision'=>self::fingerprint($rule)];
            return ['ok'=>true,'state'=>'healthy','last_seen'=>$now];
        });
    }
}
