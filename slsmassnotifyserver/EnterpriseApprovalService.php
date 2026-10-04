<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseOperationsConfig.php';
require_once __DIR__.'/EnterpriseOperationsStore.php';

/** Review binds exact content and recipient identities; uncertain submissions never replay. */
final class EnterpriseApprovalService
{
    private EnterpriseOperationsStore $store;
    private $clock;
    public function __construct(EnterpriseOperationsStore $store, ?callable $clock=null) { $this->store=$store;$this->clock=$clock??static fn():int=>time(); }
    public static function fingerprint(array $request): string
    {
        // Queue IDs and attempt timestamps are assigned after approval. Keep
        // frozen recipient fingerprints, wording, media and incident context.
        foreach (['_enterprise_approval','delivery_id','delivery_timestamp','retry_of','retry_operator','only_channels','schedule_context',
            'email_expires_at','email_attempt_id','sms_expires_at','sms_attempt_id','force_queue'] as $field) { unset($request[$field]); }
        foreach ($request['email_targets'] ?? [] as $id=>$target) { unset($request['email_targets'][$id]['message_id']); }
        return hash('sha256',json_encode($request,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function revision(array $policies): string { return hash('sha256',json_encode($policies,JSON_THROW_ON_ERROR)); }
    private function now(array &$state): int
    {
        $now=($this->clock)();if($now<$state['clock']){throw new \DomainException('The PBX clock moved backwards. Approval is blocked until it catches up.');}$state['clock']=$now;return $now;
    }
    public function request(array $settings,array $request,string $person,?string $identity=null): array
    {
        $policies=EnterpriseOperationsConfig::policies($settings,$request);
        if (!$policies || !empty($request['preview'])) { return ['allowed'=>true]; }
        return $this->store->transaction(function(array &$state)use($policies,$request,$person,$identity):array{
            $now=$this->now($state);$id='review_'.bin2hex(random_bytes(16));
            $approvals=[];
            foreach($policies as $p){if(in_array($person,$p['approver_ids'],true)){$approvals[$p['id']]=[$person=>gmdate('c',$now)];}}
            $state['approvals'][$id]=['schema'=>1,'id'=>$id,'state'=>'awaiting_approval','created_at'=>$now,
                'expires_at'=>$now+min(array_column($policies,'expires_seconds')),'requested_by'=>$person,
                'policy_revision'=>self::revision($policies),'policies'=>$policies,'request'=>$request,
                'fingerprint'=>self::fingerprint($request),'approvals'=>$approvals,'approval_identities'=>[$person=>$identity??$person],
                'history'=>[['at'=>gmdate('c',$now),'action'=>'requested','person'=>$person]]];
            return ['allowed'=>false,'review_id'=>$id,'expires_at'=>gmdate('c',$state['approvals'][$id]['expires_at'])];
        });
    }
    public function approve(string $id,array $settings,string $person,callable $authorize,?string $identity=null): array
    {
        self::id($id);EnterpriseOperationsConfig::actor($person);
        return $this->store->transaction(function(array &$state)use($id,$settings,$person,$authorize,$identity):array{
            $now=$this->now($state);$r=&$state['approvals'][$id];
            if(!is_array($r)){throw new \DomainException('Approval request was not found.');}
            self::validate($r);
            if($r['state']!=='awaiting_approval' || $now>=$r['expires_at']){throw new \DomainException('This approval request expired or has already been submitted.');}
            $policies=EnterpriseOperationsConfig::policies($settings,$r['request']);
            if(!hash_equals($r['policy_revision'],self::revision($policies))){throw new \DomainException('Approval policy changed. Submit a new reviewed request.');}
            if(!$authorize($r['request'])){throw new \DomainException('The frozen audience exceeds your current send permissions.');}
            $accepted=false;
            foreach($policies as $p){if(in_array($person,$p['approver_ids'],true)&&!isset($r['approvals'][$p['id']][$person])){$r['approvals'][$p['id']][$person]=gmdate('c',$now);$accepted=true;}}
            if($accepted){$r['approval_identities'][$person]=$identity??$person;$r['history'][]=['at'=>gmdate('c',$now),'action'=>'approved','person'=>$person];}
            else{throw new \DomainException('You have already approved this request or are not one of its authorized approvers.');}
            $ready=true;foreach($policies as $p){if(count($r['approvals'][$p['id']]??[])<2){$ready=false;}}
            return ['success'=>true,'review_id'=>$id,'ready'=>$ready,'message'=>$ready?'Two distinct people approved every applicable policy. Ready to submit.':'Approval recorded. Another authorized person must approve.'];
        });
    }
    public function claim(string $id,array $settings,string $person,callable $authorize): array
    {
        self::id($id);EnterpriseOperationsConfig::actor($person);
        return $this->store->transaction(function(array &$state)use($id,$settings,$person,$authorize):array{
            $now=$this->now($state);$r=&$state['approvals'][$id];if(!is_array($r)){throw new \DomainException('Approval request was not found.');}self::validate($r);
            if($r['state']!=='awaiting_approval'||$now>=$r['expires_at']){throw new \DomainException('This request expired, was rejected or has already been submitted.');}
            $policies=EnterpriseOperationsConfig::policies($settings,$r['request']);
            if(!hash_equals($r['policy_revision'],self::revision($policies))||!$authorize($r['request'])){throw new \DomainException('The approval policy or audience permissions changed. No announcement was submitted.');}
            foreach($policies as $p){if(count(array_intersect(array_keys($r['approvals'][$p['id']]??[]),$p['approver_ids']))<2||!in_array($person,$p['approver_ids'],true)){throw new \DomainException('Two distinct authorized people must approve each policy before submission.');}}
            $r['state']='submitting';$r['claimed_at']=$now;$r['history'][]=['at'=>gmdate('c',$now),'action'=>'submission_claimed','person'=>$person];
            return $r['request']+['_enterprise_approval'=>['id'=>$id,'fingerprint'=>$r['fingerprint']]];
        });
    }
    public function permits(array $settings,array $request,callable $personCurrent): bool
    {
        $policies=EnterpriseOperationsConfig::policies($settings,$request);if(!$policies){return true;}
        $ticket=$request['_enterprise_approval']??null;
        if(!is_array($ticket)||!is_string($ticket['id']??null)||!is_string($ticket['fingerprint']??null)){return false;}
        self::id($ticket['id']);
        return $this->store->transaction(function(array &$state)use($ticket,$request,$policies,$personCurrent):bool{
            $now=$this->now($state);$r=$state['approvals'][$ticket['id']]??null;if(!is_array($r)){return false;}self::validate($r);
            if(!in_array($r['state'],['submitting','submitted'],true)||$now>=$r['expires_at']||!hash_equals($r['policy_revision'],self::revision($policies))
                ||!hash_equals($r['fingerprint'],$ticket['fingerprint'])||!hash_equals($r['fingerprint'],self::fingerprint($request))){return false;}
            foreach($policies as $p){$count=0;foreach(array_keys($r['approvals'][$p['id']]??[]) as $person){if(in_array($person,$p['approver_ids'],true)&&$personCurrent($person,$request,$r['approval_identities'][$person]??'')){$count++;}}if($count<2){return false;}}
            return true;
        });
    }
    public function finish(string $id,array $result): void
    {
        self::id($id);$this->store->transaction(function(array &$state)use($id,$result):void{
            $r=&$state['approvals'][$id];if(!is_array($r)||$r['state']!=='submitting'){throw new \DomainException('Approval submission is no longer in progress.');}
            $r['state']=is_string($result['job_id']??null)&&preg_match('/^job_[a-f0-9]{32}$/D',$result['job_id'])?'submitted':(($result['delivery_started']??null)===false?'not_submitted':'uncertain');
            $r['result']=array_intersect_key($result,array_flip(['success','job_id','error_code','message']));$r['finished_at']=$this->now($state);
        });
    }
    public function reject(string $id,string $person): array
    {
        self::id($id);return $this->store->transaction(function(array &$state)use($id,$person):array{
            $r=&$state['approvals'][$id];if(!is_array($r)||$r['state']!=='awaiting_approval'){throw new \DomainException('Only an unsubmitted review can be rejected.');}
            $allowed=$r['requested_by']===$person;foreach($r['policies'] as $p){$allowed=$allowed||in_array($person,$p['approver_ids'],true);}if(!$allowed){throw new \DomainException('This review is outside your approval assignments.');}
            self::validate($r);$r['state']='rejected';$r['history'][]=['at'=>gmdate('c',$this->now($state)),'action'=>'rejected','person'=>$person];
            return $r['request']+['_enterprise_approval'=>['id'=>$id,'fingerprint'=>$r['fingerprint']]];
        });
    }
    public function listing(): array { return $this->store->transaction(static function(array &$state):array{return array_values($state['approvals']);}); }
    private static function id(string $id): void { IncidentConfig::identifier($id,'/^review_[a-f0-9]{32}$/D','Approval request'); }
    private static function validate(array $r): void
    {
        if(($r['schema']??null)!==1||!is_array($r['request']??null)||!is_array($r['policies']??null)||!is_array($r['approvals']??null)
            ||!is_int($r['expires_at']??null)||!is_string($r['fingerprint']??null)||!hash_equals($r['fingerprint'],self::fingerprint($r['request']))){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}
    }
}
