<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/SubscriberConfig.php';
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/IncidentStore.php';

/** Recipient-bound email proofs and human responses; transport receipts are untouched. */
final class SubscriberService
{
    private EnterpriseIdentityStore $state;private IncidentStore $incidents;
    public function __construct(EnterpriseIdentityStore $state,IncidentStore $incidents){$this->state=$state;$this->incidents=$incidents;}
    private static function roster(array $record,array $p): array
    {
        foreach($record['template']['roster'] as $r){if($r['id']===$p['person_id']){
                $opened=strtotime($record['opened_at']??'');if(!$opened||$p['registered_at']>=$opened||$p['name']!==$r['name']){throw new \DomainException('This subscriber identity was enrolled or changed after the incident snapshot. Start a new incident for the updated identity.');}return $r;}}
        throw new \DomainException('This person is outside the incident roster snapshot.');
    }
    public function issue(array $settings,string $subscriberId,string $incidentId,int $now): array
    {
        $c=SubscriberConfig::normalize($settings['subscriber_browser']??[]);$p=SubscriberConfig::person($settings,$subscriberId);$record=$this->incidents->read($incidentId);
        if(!$record||$record['state']!=='open'){throw new \DomainException('Invite links require an open incident.');}$r=self::roster($record,$p);
        $token=bin2hex(random_bytes(32));$expires=$now+$c['link_ttl_seconds'];$proof=['subscriber_id'=>$p['id'],'identity'=>SubscriberConfig::identity($p),'incident_id'=>$incidentId,
            'person_id'=>$p['person_id'],'roster_identity'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR)),'created_at'=>$now,'expires_at'=>$expires,'delivery'=>'reserved'];
        $this->state->transaction('subscriber_tokens',static function(array &$rows)use($token,$proof,$now):void{foreach($rows as $key=>$row){if(($row['expires_at']??0)<=$now){unset($rows[$key]);}}
            $recent=0;foreach($rows as $row){if(($row['subscriber_id']??'')===$proof['subscriber_id']&&$row['created_at']>$now-3600){$recent++;}}
            if($recent>=3||count($rows)>=2000){throw new \DomainException('Subscriber invitation limit reached. Retry after existing links expire.');}
            // Reissuing revokes every unused prior link for this incident/person.
            foreach($rows as &$row){if($row['subscriber_id']===$proof['subscriber_id']&&$row['incident_id']===$proof['incident_id']){$row['revoked']=true;}}unset($row);$rows[hash('sha256',$token)]=$proof;});
        return ['token'=>$token,'token_hash'=>hash('sha256',$token),'url'=>$c['public_base_url'].'#verify='.$token,'expires_at'=>$expires,'person'=>$p,'incident_title'=>$record['title']??$record['template']['title']];
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
    public function current(array $settings,array $binding,int $now): array
    {
        if(!is_int($binding['expires_at']??null)||$binding['expires_at']<=$now||($binding['created_at']??PHP_INT_MAX)>$now){throw new \DomainException('This subscriber session expired. Request another invitation.');}
        $p=SubscriberConfig::person($settings,$binding['subscriber_id']??'');if(!hash_equals(SubscriberConfig::identity($p),$binding['identity']??'')){throw new \DomainException('Your subscriber identity changed. Request another invitation.');}
        $record=$this->incidents->read($binding['incident_id']??'');if(!$record){throw new \DomainException('This incident is unavailable.');}$r=self::roster($record,$p);
        if($binding['person_id']!==$p['person_id']||!hash_equals(hash('sha256',json_encode($r,JSON_THROW_ON_ERROR)),$binding['roster_identity']??'')){throw new \DomainException('The invitation does not match this incident participant.');}
        return ['person'=>$p,'incident_id'=>$record['id'],'title'=>$record['title']??$record['template']['title'],'message'=>$record['message']??$record['template']['message'],
            'state'=>$record['state'],'is_test'=>$record['is_test']??false,'response'=>$record['responses'][$p['person_id']]??null,'expires_at'=>$binding['expires_at']];
    }
    public function respond(array $settings,array $binding,array $input,int $now): array
    {
        if(array_diff(array_keys($input),['request_id','response','note'])||!preg_match('/^[a-f0-9]{32}$/D',$input['request_id']??'')||!in_array($input['response']??'',['received','safe','needs_assistance'],true)){throw new \DomainException('Select received, safe or needs help.');}
        $note=IncidentConfig::text($input['note']??'',500,'Response note',true);$current=$this->current($settings,$binding,$now);$actor=['identity'=>$current['person']['name'],'source'=>'human_browser_response'];
        $request=$input['request_id'];$fingerprint=hash('sha256',json_encode([$binding['person_id'],$input['response'],$note,$actor],JSON_THROW_ON_ERROR));
        $this->incidents->transaction($binding['incident_id'],static function(array $record)use($binding,$input,$note,$actor,$request,$fingerprint,$now):array{
            if($record['state']!=='open'){throw new \DomainException('Human responses are accepted while this incident is open.');}
            $match=null;foreach($record['template']['roster'] as $person){if($person['id']===$binding['person_id']){$match=$person;break;}}
            if(!$match||!hash_equals(hash('sha256',json_encode($match,JSON_THROW_ON_ERROR)),$binding['roster_identity'])){throw new \DomainException('Incident roster binding changed.');}
            if(isset($record['actions'][$request])){if(!hash_equals($record['actions'][$request],$fingerprint)){throw new \DomainException('This request ID belongs to another response.');}return $record;}
            if(count($record['actions'])>=3500||count($record['timeline'])>=3999){throw new \DomainException('Incident response history is full.');}$at=gmdate('c',$now);$record['actions'][$request]=$fingerprint;
            $record['responses'][$binding['person_id']]=['response'=>$input['response'],'note'=>$note,'at'=>$at,'actor'=>$actor,'source'=>'human_browser_response'];
            $record['timeline'][]=['at'=>$at,'kind'=>'human_response','actor'=>$actor,'details'=>['person_id'=>$binding['person_id'],'response'=>$input['response'],'note'=>$note]];$record['updated_at']=$at;return $record;});
        return $this->current($settings,$binding,$now);
    }
    public function revoke(string $subscriberId,?string $incidentId=null): void
    {
        $this->state->transaction('subscriber_tokens',static function(array &$rows)use($subscriberId,$incidentId):void{foreach($rows as &$r){if($r['subscriber_id']===$subscriberId&&($incidentId===null||$r['incident_id']===$incidentId)){$r['revoked']=true;}}unset($r);});
        // Sessions are revoked by rotating the subscriber version in .config;
        // management always combines the token revocation and that rotation.
    }
}
