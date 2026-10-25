<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/SubscriberConfig.php';
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/IncidentStore.php';
require_once __DIR__.'/BrowserEvents.php';

/** Recipient-bound email proofs and human responses; transport receipts are untouched. */
final class SubscriberService
{
    private EnterpriseIdentityStore $state;private IncidentStore $incidents;
    public function __construct(EnterpriseIdentityStore $state,IncidentStore $incidents){$this->state=$state;$this->incidents=$incidents;}
    /** Passive evidence can finish after feature disablement; individual revocation still applies. */
    public static function personForPassiveEvidence(array $settings,string $id): array
    {
        $config=SubscriberConfig::normalize($settings['subscriber_browser']??[]);
        foreach($config['people'] as $person){if($person['id']===$id&&$person['enabled']){return $person;}}
        throw new \DomainException('Subscriber access is unavailable.');
    }
    private static function roster(array $record,array $p): array
    {
        foreach($record['template']['roster'] as $r){if($r['id']===$p['person_id']){
                $opened=strtotime($record['opened_at']??'');if(!$opened||$p['registered_at']>=$opened||$p['name']!==$r['name']){throw new \DomainException('This subscriber identity was enrolled or changed after the incident snapshot. Start a new incident for the updated identity.');}return $r;}}
        throw new \DomainException('This person is outside the incident roster snapshot.');
    }
    public function issue(array $settings,string $subscriberId,string $incidentId,int $now): array
    {
        $c=SubscriberConfig::normalize($settings['subscriber_browser']??[]);$p=SubscriberConfig::person($settings,$subscriberId);$record=null;$r=null;
        if($incidentId!==''){
            $record=$this->incidents->read($incidentId);
            if(!$record||$record['state']!=='open'){throw new \DomainException('Invite links require an open incident.');}$r=self::roster($record,$p);
        }
        $token=bin2hex(random_bytes(32));$expires=$now+$c['link_ttl_seconds'];$proof=['subscriber_id'=>$p['id'],'identity'=>SubscriberConfig::identity($p),'incident_id'=>$incidentId,
            'person_id'=>$p['person_id'],'roster_identity'=>$r===null?'':hash('sha256',json_encode($r,JSON_THROW_ON_ERROR)),'created_at'=>$now,'expires_at'=>$expires,'delivery'=>'reserved'];
        if($incidentId===''){$proof['purpose']='enrollment';}
        $this->state->transaction('subscriber_tokens',static function(array &$rows)use($token,$proof,$now):void{foreach($rows as $key=>$row){if(max($row['expires_at']??0,($row['created_at']??0)+3600)<=$now){unset($rows[$key]);}}
            $recent=0;foreach($rows as $row){if(($row['subscriber_id']??'')===$proof['subscriber_id']&&$row['created_at']>$now-3600){$recent++;}}
            if($recent>=3){throw new \DomainException('This person has reached the three-invitation hourly limit. Retry after the hourly window.');}
            if(count($rows)>=2000){throw new \DomainException('Subscriber invitation history is full. Retry after retained invitation windows expire.');}
            // Reissuing revokes every unused prior link for this incident/person.
            foreach($rows as &$row){if($row['subscriber_id']===$proof['subscriber_id']&&$row['incident_id']===$proof['incident_id']){$row['revoked']=true;}}unset($row);$rows[hash('sha256',$token)]=$proof;});
        return ['token'=>$token,'token_hash'=>hash('sha256',$token),'url'=>$c['public_base_url'].'#verify='.$token,'expires_at'=>$expires,'person'=>$p,'incident_title'=>$record['title']??$record['template']['title']??'Browser enrollment'];
    }
    public function delivery(string $hash,string $result): void
    {
        if(!in_array($result,['accepted','unconfirmed','failed'],true)){throw new \LogicException('Invalid delivery result.');}
        $this->state->transaction('subscriber_tokens',static function(array &$rows)use($hash,$result):void{if(!isset($rows[$hash])){throw new \RuntimeException('Invitation record missing.');}$rows[$hash]['delivery']=$result;if($result!=='accepted'){$rows[$hash]['revoked']=true;}});
    }
    public function verify(array $settings,string $token,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)){throw new \DomainException('This email verification link is invalid or expired.');}
        $proof=$this->state->transaction('subscriber_tokens',static function(array &$rows)use($token,$now):?array{$k=hash('sha256',$token);$p=$rows[$k]??null;if(!$p||!empty($p['used'])||!empty($p['revoked'])||$p['delivery']!=='accepted'||$p['created_at']>$now||$p['expires_at']<=$now){return null;}$rows[$k]['used']=true;return $p;});
        if(!$proof){throw new \DomainException('This email verification link is invalid, expired or already used.');}$this->current($settings,$proof,$now);$proof['email_verified_at']=$now;return $proof;
    }
    public function current(array $settings,array $binding,int $now,bool $passive=false): array
    {
        if(!is_int($binding['expires_at']??null)||$binding['expires_at']<=$now||($binding['created_at']??PHP_INT_MAX)>$now){throw new \DomainException('This subscriber session expired. Request another invitation.');}
        $p=$passive?self::personForPassiveEvidence($settings,$binding['subscriber_id']??''):SubscriberConfig::person($settings,$binding['subscriber_id']??'');if(!hash_equals(SubscriberConfig::identity($p),$binding['identity']??'')){throw new \DomainException('Your subscriber identity changed. Request another invitation.');}
        if(($binding['purpose']??'incident')==='enrollment'){
            if(($binding['incident_id']??null)!==''||($binding['roster_identity']??null)!==''||($binding['person_id']??null)!==$p['person_id']){throw new \DomainException('Browser enrollment proof is invalid.');}
            return ['person'=>$p,'incident_id'=>'','title'=>'Browser preferences','message'=>'Manage notifications for your verified browser. This enrollment does not grant access to incident participants.',
                'state'=>'preferences','is_test'=>false,'response'=>null,'expires_at'=>$binding['expires_at']];
        }
        $record=$this->incidents->read($binding['incident_id']??'');if(!$record){throw new \DomainException('This incident is unavailable.');}$r=self::roster($record,$p);
        if($binding['person_id']!==$p['person_id']||!hash_equals(hash('sha256',json_encode($r,JSON_THROW_ON_ERROR)),$binding['roster_identity']??'')){throw new \DomainException('The invitation does not match this incident participant.');}
        return ['person'=>$p,'incident_id'=>$record['id'],'title'=>$record['title']??$record['template']['title'],'message'=>$record['message']??$record['template']['message'],
            'state'=>$record['state'],'is_test'=>$record['is_test']??false,'response'=>$record['responses'][$p['person_id']]??null,'expires_at'=>$binding['expires_at']];
    }
    public function respond(array $settings,array $binding,array $input,int $now): array
    {
        if(($binding['purpose']??'incident')!=='incident'||!preg_match('/^inc_[a-f0-9]{32}$/D',$binding['incident_id']??'')){throw new \DomainException('Open an incident-specific verification link before submitting a human response.');}
        if(array_diff(array_keys($input),['request_id','response','note'])||!preg_match('/^[a-f0-9]{32}$/D',$input['request_id']??'')||!in_array($input['response']??'',['received','safe','needs_assistance'],true)){throw new \DomainException('Select received, safe or needs help.');}
        $note=IncidentConfig::text($input['note']??'',500,'Response note',true);$current=$this->current($settings,$binding,$now);$actor=['identity'=>$current['person']['name'],'source'=>'human_browser_response'];
        $request=$input['request_id'];$fingerprint=hash('sha256',json_encode([$binding['person_id'],$input['response'],$note,$actor],JSON_THROW_ON_ERROR));
        $this->incidents->transaction($binding['incident_id'],static function(array $record)use($binding,$input,$note,$actor,$request,$fingerprint,$now):array{
            if($record['state']!=='open'){throw new \DomainException('Human responses are accepted while this incident is open.');}
            $match=null;foreach($record['template']['roster'] as $person){if($person['id']===$binding['person_id']){$match=$person;break;}}
            if(!$match||!hash_equals(hash('sha256',json_encode($match,JSON_THROW_ON_ERROR)),$binding['roster_identity'])){throw new \DomainException('Incident roster binding changed.');}
            if(isset($record['actions'][$request])){if(!hash_equals($record['actions'][$request],$fingerprint)){throw new \DomainException('This request ID belongs to another response.');}return $record;}
            if(count($record['actions'])>=3500||count($record['timeline'])+IncidentStore::pendingApprovalCount($record)>=4000){throw new \DomainException('Incident response history is full.');}$at=gmdate('c',$now);$record['actions'][$request]=$fingerprint;
            $record['responses'][$binding['person_id']]=['response'=>$input['response'],'note'=>$note,'at'=>$at,'actor'=>$actor,'source'=>'human_browser_response'];
            $record['timeline'][]=['at'=>$at,'kind'=>'human_response','actor'=>$actor,'details'=>['person_id'=>$binding['person_id'],'response'=>$input['response'],'note'=>$note]];$record['updated_at']=$at;return $record;});
        return $this->current($settings,$binding,$now);
    }
    public function enrollPush(array $settings,array $binding,array $subscription,int $now): array
    {
        $current=$this->current($settings,$binding,$now);
        if(!is_int($binding['email_verified_at']??null)||$binding['email_verified_at']>$now){throw new \DomainException('Verify the saved email address before enrolling this browser.');}
        $config=SubscriberConfig::normalize($settings['subscriber_browser']??[]);
        if(!$config['push']['enabled']){throw new \DomainException('Browser push is disabled.');}
        require_once __DIR__.'/BrowserPush.php';$subscription=BrowserPush::subscription($subscription);$id=hash('sha256',$subscription['endpoint']);$person=$current['person'];
        $this->state->transaction('browser_subscriptions',static function(array &$rows)use($id,$person,$subscription,$now):void{
            if(isset($rows[$id])&&$rows[$id]['subscriber_id']!==$person['id']){throw new \DomainException('This browser endpoint belongs to a different verified subscriber.');}
            foreach($rows as $key=>$saved){if($saved['subscriber_id']===$person['id']&&(!$saved['enabled']||!hash_equals($saved['identity'],SubscriberConfig::identity($person)))){unset($rows[$key]);}}
            $owned=array_filter($rows,static fn($row)=>$row['subscriber_id']===$person['id']);if(!isset($rows[$id])&&(count($rows)>=1000||count($owned)>=4)){throw new \DomainException('Browser enrollment allocation reached. Remove an old browser first.');}
            $rows[$id]=['subscriber_id'=>$person['id'],'identity'=>SubscriberConfig::identity($person),'subscription'=>$subscription,'created_at'=>$now,'enabled'=>true,'version'=>bin2hex(random_bytes(32))];
        });return ['success'=>true,'endpoint_id'=>$id];
    }
    public function removePush(array $settings,array $binding,string $id,int $now,bool $passive=false): void
    {
        $current=$this->current($settings,$binding,$now,$passive);if(!preg_match('/^[a-f0-9]{64}$/D',$id)){throw new \DomainException('Invalid browser endpoint identity.');}
        $this->state->transaction('browser_subscriptions',static function(array &$rows)use($current,$id):void{if(isset($rows[$id])&&$rows[$id]['subscriber_id']!==$current['person']['id']){throw new \DomainException('This endpoint belongs to another subscriber.');}unset($rows[$id]);});
    }
    public function browserPreferences(array $settings,array $binding,int $now): array
    {
        $person=$this->current($settings,$binding,$now)['person'];$out=[];
        foreach($this->state->read('browser_subscriptions') as $id=>$row){
            if($row['subscriber_id']===$person['id']&&hash_equals($row['identity'],SubscriberConfig::identity($person))){
                $out[]=['endpoint_id'=>$id,'created_at'=>$row['created_at'],'enabled'=>$row['enabled']];
            }
        }
        return $out;
    }
    public function removeAllPush(array $settings,array $binding,int $now,bool $passive=false): void
    {
        $person=$this->current($settings,$binding,$now,$passive)['person'];
        $this->state->transaction('browser_subscriptions',static function(array &$rows)use($person):void{
            foreach($rows as $id=>$row){if($row['subscriber_id']===$person['id']){unset($rows[$id]);}}
        });
    }
    public function pushTargets(array $settings,string $subscriberId): array
    {
        $person=SubscriberConfig::person($settings,$subscriberId);$rows=$this->state->read('browser_subscriptions');$targets=[];
        foreach($rows as $id=>$row){if($row['subscriber_id']===$subscriberId&&$row['enabled']&&hash_equals($row['identity'],SubscriberConfig::identity($person))){$targets[]=$id;}}return $targets;
    }
    public function pushSnapshot(array $settings,string $subscriberId): array
    {
        $person=SubscriberConfig::person($settings,$subscriberId);$rows=$this->state->read('browser_subscriptions');$targets=[];
        foreach($rows as $id=>$row){if($row['subscriber_id']===$subscriberId&&$row['enabled']&&hash_equals($row['identity'],SubscriberConfig::identity($person))){$targets[$id]=self::endpointFingerprint($row);}}return $targets;
    }
    public static function endpointFingerprint(array $row): string
    {
        return hash('sha256',json_encode($row['subscription'],JSON_THROW_ON_ERROR)
            .(isset($row['version'])?"\nversion=".$row['version']:''));
    }
    public function push(array $settings,string $subscriberId,array $event,int $now,?callable $transport=null,?array $frozenEndpointIds=null): array
    {
        $person=SubscriberConfig::person($settings,$subscriberId);$config=SubscriberConfig::normalize($settings['subscriber_browser']);
        if(!$config['push']['enabled']){throw new \DomainException('Browser push is disabled.');}
        require_once __DIR__.'/BrowserPush.php';BrowserPush::validateEvent($event,$now);$rows=$this->state->read('browser_subscriptions');$results=[];
        $endpoints=[];
        foreach($rows as $id=>$row){
            $fingerprint=self::endpointFingerprint($row);
            if($row['subscriber_id']===$subscriberId&&$row['enabled']&&hash_equals($row['identity'],SubscriberConfig::identity($person))
                &&($frozenEndpointIds===null||(array_is_list($frozenEndpointIds)?in_array($id,$frozenEndpointIds,true):isset($frozenEndpointIds[$id])&&hash_equals($frozenEndpointIds[$id],$fingerprint)))){$endpoints[$id]=$fingerprint;}
        }
        if(!$endpoints){return ['subscriber_id'=>$subscriberId,'receipts'=>[],'state'=>'no_enrolled_browser'];}
        $publication=null;
        try{$publication=(new BrowserEvents($this->state))->prepare($settings,$subscriberId,$event,$endpoints,$now);}
        catch(\RuntimeException $error){
            // Supplemental receipt history must not block an authorized alert.
            // The separate durable push claims below still prevent replay.
        }
        foreach($rows as $id=>$row){if(!isset($endpoints[$id])){continue;}
            $claimId=hash('sha256',$id.'|'.($event['event_id']??''));$fingerprint=hash('sha256',json_encode($event,JSON_THROW_ON_ERROR));
            $claim=$this->state->transaction('browser_push_claims',static function(array &$claims)use($claimId,$fingerprint,$now):array{
                foreach($claims as $key=>$claim){if(($claim['expires_at']??PHP_INT_MAX)<=$now){unset($claims[$key]);}}
                if(isset($claims[$claimId])){if(!hash_equals($claims[$claimId]['fingerprint'],$fingerprint)){throw new \DomainException('Browser event identity was reused with different details.');}return ['new'=>false,'receipt'=>$claims[$claimId]['receipt']??['state'=>'unconfirmed','detail'=>'Previous push completion is unconfirmed; no automatic replay.']];}
                if(count($claims)>=10000){throw new \DomainException('Browser push journal allocation reached.');}$claims[$claimId]=['fingerprint'=>$fingerprint,'expires_at'=>$now+86400];return ['new'=>true];
            });
            if(!$claim['new']){$results[$id]=$claim['receipt'];continue;}
            try{
                $current=$this->state->transaction('browser_subscriptions',static fn(array &$current)=>$current[$id]??null);
                if(!$current||!$current['enabled']||!hash_equals($current['identity'],SubscriberConfig::identity($person))||!hash_equals(self::endpointFingerprint($current),$endpoints[$id])){throw new \DomainException('Browser enrollment was revoked before submission.');}
                $wireEvent=$publication===null?$event:$event+['browser_receipt'=>BrowserEvents::proof($person,$publication,$id)];
                $receipt=BrowserPush::send($row['subscription'],['enabled'=>$config['push']['enabled']?'1':'0']+$config['push'],$wireEvent,$now,$transport);}
            catch(\Throwable $error){$receipt=['state'=>'unconfirmed','detail'=>'Browser push was not confirmed; no automatic replay.'];}
            $this->state->transaction('browser_push_claims',static function(array &$claims)use($claimId,$receipt):void{$claims[$claimId]['receipt']=$receipt;});
            if($receipt['state']==='revoked'){$this->state->transaction('browser_subscriptions',static function(array &$rows)use($id):void{if(isset($rows[$id])){$rows[$id]['enabled']=false;}});}$results[$id]=$receipt;
        }return ['subscriber_id'=>$subscriberId,'receipts'=>$results,'state'=>$results?'submitted':'no_enrolled_browser','software_evidence_available'=>$publication!==null];
    }
    public function revoke(string $subscriberId,?string $incidentId=null): void
    {
        $this->state->transaction('subscriber_tokens',static function(array &$rows)use($subscriberId,$incidentId):void{foreach($rows as &$r){if($r['subscriber_id']===$subscriberId&&($incidentId===null||$r['incident_id']===$incidentId)){$r['revoked']=true;}}unset($r);});
        // Sessions are revoked by rotating the subscriber version in .config;
        // management always combines the token revocation and that rotation.
    }
}
