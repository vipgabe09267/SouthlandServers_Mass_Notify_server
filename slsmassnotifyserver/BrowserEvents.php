<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/SubscriberConfig.php';
require_once __DIR__.'/BrowserPush.php';

/** Short-lived recipient details and separate, capability-bound software evidence. */
final class BrowserEvents
{
    private EnterpriseIdentityStore $state;
    private array $summaries=[];
    public function __construct(EnterpriseIdentityStore $state) { $this->state=$state; }
    public static function key(string $subscriberId,string $eventId): string { return hash('sha256',$subscriberId."\0".$eventId); }
    private static function ledger(string $key): string { return 'browser_events_'.substr($key,0,1); }

    public static function validateRow(string $key,array $row): void
    {
        $fields=['subscriber_id','identity','event','fingerprint','created_at','expires_at','endpoints','receipts'];
        if(array_diff(array_keys($row),$fields)||array_diff($fields,array_keys($row))
            ||!preg_match('/^sub_[a-f0-9]{24}$/D',$row['subscriber_id']??'')||!preg_match('/^[a-f0-9]{64}$/D',$row['identity']??'')
            ||!is_array($row['event']??null)||!is_int($row['created_at']??null)||$row['created_at']<1
            ||!is_int($row['expires_at']??null)||$row['expires_at']!==($row['event']['expires_at']??0)+86400
            ||!is_array($row['endpoints']??null)||!$row['endpoints']||array_is_list($row['endpoints'])||count($row['endpoints'])>4
            ||!is_array($row['receipts']??null)||($row['receipts']&&array_is_list($row['receipts']))
            ||!hash_equals(self::key($row['subscriber_id'],$row['event']['event_id']??''),$key)
            ||!is_string($row['fingerprint']??null)||!hash_equals(hash('sha256',json_encode($row['event'],JSON_THROW_ON_ERROR)),$row['fingerprint'])) {
            throw new \RuntimeException('Browser event history is invalid. Preserve it for recovery.');
        }
        BrowserPush::validateEvent($row['event'],$row['created_at']);
        foreach($row['endpoints'] as $id=>$fingerprint){
            if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)){
                throw new \RuntimeException('Browser event endpoint binding is invalid.');
            }
        }
        foreach($row['receipts'] as $id=>$receipt){
            if(!isset($row['endpoints'][$id])||!is_array($receipt)||array_diff(array_keys($receipt),['received','displayed','clicked'])){throw new \RuntimeException('Browser software receipt is invalid.');}
            foreach($receipt as $at){if(!is_int($at)||$at<$row['created_at']||$at>$row['event']['expires_at']){throw new \RuntimeException('Browser software receipt time is invalid.');}}
        }
    }

    public function prepare(array $settings,string $subscriberId,array $event,array $endpoints,int $now): array
    {
        $person=SubscriberConfig::person($settings,$subscriberId);BrowserPush::validateEvent($event,$now);
        $key=self::key($subscriberId,$event['event_id']);
        $row=['subscriber_id'=>$subscriberId,'identity'=>SubscriberConfig::identity($person),'event'=>$event,
            'fingerprint'=>hash('sha256',json_encode($event,JSON_THROW_ON_ERROR)),'created_at'=>$now,'expires_at'=>$event['expires_at']+86400,
            'endpoints'=>$endpoints,'receipts'=>[]];
        self::validateRow($key,$row);
        return $this->state->transaction(self::ledger($key),static function(array &$rows)use($key,$row,$now):array{
            foreach($rows as $id=>$saved){if($saved['expires_at']<=$now){unset($rows[$id]);}}
            if(isset($rows[$key])){
                $saved=$rows[$key];
                if(!hash_equals($saved['identity'],$row['identity'])||!hash_equals($saved['fingerprint'],$row['fingerprint'])
                    ||array_diff_assoc($row['endpoints'],$saved['endpoints'])){throw new \DomainException('Browser event identity was reused for different content or enrollments.');}
                return $saved;
            }
            if(count($rows)>=1000){throw new \RuntimeException('Supplemental browser event history reached its bounded allocation.');}
            $rows[$key]=$row;return $row;
        });
    }

    public static function proof(array $person,array $row,string $endpoint): array
    {
        $key=self::key($person['id'],$row['event']['event_id']);
        if(!isset($row['endpoints'][$endpoint])){throw new \LogicException('Browser endpoint is outside the frozen publication.');}
        return ['subscriber_id'=>$person['id'],'event_key'=>$key,'endpoint_id'=>$endpoint,'expires_at'=>$row['event']['expires_at'],
            'token'=>self::token($person,$key,$endpoint,$row['event']['expires_at'])];
    }
    private static function token(array $person,string $key,string $endpoint,int $expires): string
    {return hash_hmac('sha256','SLS_BROWSER_RECEIPT_V1|'.$person['id'].'|'.$key.'|'.$endpoint.'|'.$expires,$person['version']);}

    public function details(array $settings,string $subscriberId,string $eventId,int $now): array
    {
        $person=SubscriberConfig::person($settings,$subscriberId);$key=self::key($subscriberId,$eventId);
        $row=$this->state->read(self::ledger($key))[$key]??null;
        if(!$row||$row['event']['expires_at']<=$now||!hash_equals($row['identity'],SubscriberConfig::identity($person))){throw new \DomainException('This browser announcement is expired or unavailable to your current identity.');}
        return $row['event'];
    }

    public function receipt(array $settings,array $input,int $now,bool $passive=false): array
    {
        if(array_diff(array_keys($input),['subscriber_id','event_key','endpoint_id','expires_at','token','stage'])||count($input)!==6
            ||!in_array($input['stage']??null,['received','displayed','clicked'],true)) {throw new \DomainException('Invalid browser software receipt.');}
        foreach(['event_key','endpoint_id','token'] as $key){if(!is_string($input[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$input[$key])){throw new \DomainException('Invalid browser software receipt.');}}
        $config=SubscriberConfig::normalize($settings['subscriber_browser']??[]);
        if(!$passive&&(!$config['enabled']||!$config['push']['enabled'])){throw new \DomainException('Browser notifications are disabled.');}
        if(!is_string($input['subscriber_id']??null)||!preg_match('/^sub_[a-f0-9]{24}$/D',$input['subscriber_id'])
            ||!is_int($input['expires_at']??null)||$input['expires_at']<=$now||$input['expires_at']>$now+600){throw new \DomainException('Browser receipt proof is invalid or expired.');}
        $person=$passive?SubscriberService::personForPassiveEvidence($settings,$input['subscriber_id']):SubscriberConfig::person($settings,$input['subscriber_id']);
        // Reject forged capabilities before opening or parsing an event ledger.
        if(!hash_equals(self::token($person,$input['event_key'],$input['endpoint_id'],$input['expires_at']),$input['token'])){
            throw new \DomainException('Browser receipt proof is invalid or expired.');
        }
        $key=$input['event_key'];$row=$this->state->read(self::ledger($key))[$key]??null;
        if(!$row||$row['subscriber_id']!==$person['id']||$row['created_at']>$now||$row['event']['expires_at']!==$input['expires_at']){throw new \DomainException('Browser receipt proof is expired or unavailable.');}
        $id=$input['endpoint_id'];
        if(!hash_equals($row['identity'],SubscriberConfig::identity($person))||!isset($row['endpoints'][$id])){throw new \DomainException('Browser receipt identity changed.');}
        $endpoint=$this->state->read('browser_subscriptions')[$id]??null;
        if(!$endpoint||!$endpoint['enabled']||$endpoint['subscriber_id']!==$person['id']||!hash_equals($endpoint['identity'],$row['identity'])
            ||!hash_equals($row['endpoints'][$id],SubscriberService::endpointFingerprint($endpoint))
            ||!hash_equals(self::proof($person,$row,$id)['token'],$input['token'])){throw new \DomainException('Browser receipt endpoint was revoked or its proof is invalid.');}
        $this->state->transaction(self::ledger($key),static function(array &$rows)use($key,$row,$input,$id,$now):void{
            if(!isset($rows[$key])||$rows[$key]['fingerprint']!==$row['fingerprint']||$rows[$key]['event']['expires_at']<=$now){throw new \DomainException('Browser receipt publication changed.');}
            $rows[$key]['receipts'][$id][$input['stage']]??=$now;
        });
        return ['ok'=>true,'event_id'=>$row['event']['event_id'],'receipt_type'=>'browser_software_'.$input['stage']];
    }

    public function summary(string $subscriberId,string $eventId): ?array
    {
        if(!preg_match('/^sub_[a-f0-9]{24}$/D',$subscriberId)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$eventId)){throw new \DomainException('Invalid browser delivery identity.');}
        $key=self::key($subscriberId,$eventId);$ledger=self::ledger($key);
        if(!isset($this->summaries[$ledger])){
            $summaries=[];
            foreach($this->state->read($ledger) as $id=>$row){
                $summary=['endpoints'=>count($row['endpoints']),'received'=>0,'displayed'=>0,'clicked'=>0];
                foreach($row['receipts'] as $receipt){foreach(['received','displayed','clicked'] as $stage){if(isset($receipt[$stage])){$summary[$stage]++;}}}
                $summaries[$id]=$summary;
            }
            $this->summaries[$ledger]=$summaries;
        }
        return $this->summaries[$ledger][$key]??null;
    }
}
