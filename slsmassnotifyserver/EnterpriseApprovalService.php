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
    private static function approvedPeople(array $review,array $policy,?callable $current=null,array $request=[]): int
    {
        $identities=[];
        foreach (array_keys($review['approvals'][$policy['id']] ?? []) as $person) {
            $identity=$review['approval_identities'][$person] ?? null;
            if (in_array($person,$policy['approver_ids'],true) && is_string($identity) && $identity!==''
                && ($current===null || $current($person,$request,$identity))) { $identities[$identity]=true; }
        }
        return count($identities);
    }
    private function now(array &$state): int
    {
        $now=($this->clock)();if($now<$state['clock']){throw new \DomainException('The PBX clock moved backwards. Approval is blocked until it catches up.');}$state['clock']=$now;return $now;
    }
    public function request(array $settings,array $request,string $person,?string $identity=null): array
    {
        EnterpriseOperationsConfig::requester($person);
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
            self::validateRecord($state['approvals'][$id]);
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
            $identity=$identity??$person;
            foreach ($r['approvals'] as $approved) {
                foreach (array_keys($approved) as $other) {
                    $saved=$r['approval_identities'][$other] ?? null;
                    if ($other!==$person && is_string($saved) && hash_equals($saved,$identity)) {
                        throw new \DomainException('This login identity has already approved through another account. A different authorized person must approve.');
                    }
                }
            }
            $accepted=false;
            foreach($policies as $p){if(in_array($person,$p['approver_ids'],true)&&!isset($r['approvals'][$p['id']][$person])){$r['approvals'][$p['id']][$person]=gmdate('c',$now);$accepted=true;}}
            if($accepted){$r['approval_identities'][$person]=$identity??$person;$r['history'][]=['at'=>gmdate('c',$now),'action'=>'approved','person'=>$person];}
            else{throw new \DomainException('You have already approved this request or are not one of its authorized approvers.');}
            $ready=true;foreach($policies as $p){if(self::approvedPeople($r,$p)<2){$ready=false;}}
            self::validateRecord($r);
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
            $submitterAssigned=false;
            foreach($policies as $p){
                if(self::approvedPeople($r,$p)<2){throw new \DomainException('Two distinct authorized people must approve each policy before submission.');}
                $submitterAssigned=$submitterAssigned||in_array($person,$p['approver_ids'],true);
            }
            if(!$submitterAssigned){throw new \DomainException('The submitting person must be an authorized reviewer for at least one applicable policy.');}
            $r['state']='submitting';$r['claimed_at']=$now;$r['history'][]=['at'=>gmdate('c',$now),'action'=>'submission_claimed','person'=>$person];
            self::validateRecord($r);
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
            foreach($policies as $p){if(self::approvedPeople($r,$p,$personCurrent,$request)<2){return false;}}
            return true;
        });
    }
    public function finish(string $id,array $result): array
    {
        self::id($id);$job=$result['job_id']??'';$accepted=is_string($job)&&preg_match('/^job_[a-f0-9]{32}$/D',$job);
        $notSubmitted=!$accepted&&($result['delivery_started']??null)===false;
        $normalized=['success'=>$accepted];
        if($accepted){$normalized['job_id']=$job;}
        elseif($notSubmitted){$normalized['delivery_started']=false;}
        $error=$result['error_code']??null;if(is_string($error)&&preg_match('/^[a-z][a-z0-9_]{0,95}$/D',$error)){$normalized['error_code']=$error;}
        $message=$result['message']??null;
        if(is_string($message)&&strlen($message)<=2048&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$message)){$normalized['message']=$message;}
        else{$normalized['message']=$accepted?'Reviewed announcement accepted as a durable job.':($notSubmitted?'Reviewed announcement was not submitted.':'Reviewed announcement result could not be confirmed. Inspect delivery history before retrying.');}
        $this->store->transaction(function(array &$state)use($id,$normalized,$accepted,$notSubmitted):void{
            $r=&$state['approvals'][$id];if(!is_array($r)){throw new \DomainException('Approval submission is no longer in progress.');}
            self::validateRecord($r);if($r['state']!=='submitting'){throw new \DomainException('Approval submission is no longer in progress.');}
            $r['state']=$accepted?'submitted':($notSubmitted?'not_submitted':'uncertain');
            $r['result']=array_intersect_key($normalized,array_flip(['success','job_id','error_code','message']));$r['finished_at']=$this->now($state);
            self::validateRecord($r);
        });
        return $normalized;
    }
    public function reject(string $id,string $person): array
    {
        self::id($id);return $this->store->transaction(function(array &$state)use($id,$person):array{
            $r=&$state['approvals'][$id];if(!is_array($r)){throw new \DomainException('Only an unsubmitted review can be rejected.');}
            self::validateRecord($r);if($r['state']!=='awaiting_approval'){throw new \DomainException('Only an unsubmitted review can be rejected.');}
            $allowed=$r['requested_by']===$person;foreach($r['policies'] as $p){$allowed=$allowed||in_array($person,$p['approver_ids'],true);}if(!$allowed){throw new \DomainException('This review is outside your approval assignments.');}
            $r['state']='rejected';$r['history'][]=['at'=>gmdate('c',$this->now($state)),'action'=>'rejected','person'=>$person];
            self::validateRecord($r);
            return $r['request']+['_enterprise_approval'=>['id'=>$id,'fingerprint'=>$r['fingerprint']]];
        });
    }
    public function listing(): array { return $this->store->transaction(static function(array &$state):array{foreach($state['approvals'] as $id=>$row){if(!is_string($id)||!is_array($row)||($row['id']??null)!==$id){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}self::validateRecord($row);}return array_values($state['approvals']);}); }
    private static function id(string $id): void { IncidentConfig::identifier($id,'/^review_[a-f0-9]{32}$/D','Approval request'); }
    private static function validTime($value): bool
    {
        return is_string($value)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$/D',$value)
            && ($time=strtotime($value))!==false&&gmdate('c',$time)===$value;
    }
    private static function boundedValue($value,int $depth=0,int &$nodes=0): void
    {
        if(++$nodes>20000||$depth>20){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}
        if(is_string($value)){if(strlen($value)>1100000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}return;}
        if(is_float($value)&&!is_finite($value)){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}
        if(!is_array($value)){if(is_null($value)||is_bool($value)||is_int($value)||is_float($value)){return;}throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}
        if(count($value)>2500){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}
        foreach($value as $key=>$item){if(!is_int($key)&&(!is_string($key)||strlen($key)>128||preg_match('/[\x00-\x1f\x7f]/',$key))){throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');}self::boundedValue($item,$depth+1,$nodes);}
    }
    public static function validateRecord(array $r): void
    {
        $fail=static function():void{throw new \RuntimeException('Approval history is damaged. Preserve it for recovery.');};
        $state=$r['state']??null;$base=['schema','id','state','created_at','expires_at','requested_by','policy_revision','policies','request','fingerprint','approvals','approval_identities','history'];
        $expected=$base;
        if($state==='submitting'){$expected[]='claimed_at';}
        elseif(in_array($state,['submitted','not_submitted','uncertain'],true)){$expected=array_merge($expected,['claimed_at','result','finished_at']);}
        elseif(!in_array($state,['awaiting_approval','rejected'],true)){$fail();}
        sort($expected,SORT_STRING);$actual=array_keys($r);sort($actual,SORT_STRING);
        if($actual!==$expected||($r['schema']??null)!==1||!is_string($r['id']??null)||!preg_match('/^review_[a-f0-9]{32}$/D',$r['id'])
            ||!is_int($r['created_at']??null)||$r['created_at']<1||!is_int($r['expires_at']??null)||$r['expires_at']<=$r['created_at']||$r['expires_at']>$r['created_at']+900
            ||!is_string($r['requested_by']??null)||!is_string($r['policy_revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['policy_revision'])
            ||!is_array($r['policies']??null)||!array_is_list($r['policies'])||!$r['policies']||count($r['policies'])>25
            ||!is_array($r['request']??null)||!is_string($r['fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['fingerprint'])
            ||!is_array($r['approvals']??null)||array_is_list($r['approvals'])&&$r['approvals']!==[]
            ||!is_array($r['approval_identities']??null)||array_is_list($r['approval_identities'])&&$r['approval_identities']!==[]
            ||!is_array($r['history']??null)||!array_is_list($r['history'])||!$r['history']||count($r['history'])>256){$fail();}
        try{EnterpriseOperationsConfig::requester($r['requested_by']);}catch(\Throwable $error){$fail();}
        $policies=[];$people=[];
        foreach($r['policies'] as $policy){
            if(!is_array($policy)||array_is_list($policy)||count($policy)!==6||array_diff(array_keys($policy),['id','name','enabled','channels','approver_ids','expires_seconds'])
                ||!is_string($policy['id']??null)||!preg_match('/^approval_[a-f0-9]{24}$/D',$policy['id'])||isset($policies[$policy['id']])
                ||!is_string($policy['name']??null)||$policy['name']===''||strlen($policy['name'])>80||preg_match('/[\x00-\x1f\x7f]/',$policy['name'])||($policy['enabled']??null)!==true
                ||!is_array($policy['channels']??null)||!array_is_list($policy['channels'])||!$policy['channels']||count($policy['channels'])>8||count(array_unique($policy['channels']))!==count($policy['channels'])
                ||!is_array($policy['approver_ids']??null)||!array_is_list($policy['approver_ids'])||count($policy['approver_ids'])<2||count($policy['approver_ids'])>100||count(array_unique($policy['approver_ids']))!==count($policy['approver_ids'])
                ||!is_int($policy['expires_seconds']??null)||$policy['expires_seconds']<60||$policy['expires_seconds']>900){$fail();}
            foreach($policy['channels'] as $channel){if(!is_string($channel)||!preg_match('/^(?:phones|desktops|webhooks|voice_recipient_ids|email_recipient_ids|sms_recipient_ids|browser_recipient_ids|connector_ids)$/D',$channel)){$fail();}}
            foreach($policy['approver_ids'] as $person){try{EnterpriseOperationsConfig::actor($person);}catch(\Throwable $error){$fail();}$people[$person]=true;}
            $policies[$policy['id']]=$policy;
        }
        if(!hash_equals($r['policy_revision'],self::revision($r['policies']))||!hash_equals($r['fingerprint'],self::fingerprint($r['request']))){$fail();}
        $nodes=0;self::boundedValue($r['request'],0,$nodes);
        if(strlen(json_encode($r['request'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>1500000){$fail();}
        $approvedPeople=[];
        foreach($r['approvals'] as $policyId=>$approvals){
            if(!is_string($policyId)||!isset($policies[$policyId])||!is_array($approvals)||array_is_list($approvals)&&$approvals!==[]||count($approvals)>100){$fail();}
            foreach($approvals as $person=>$at){if(!is_string($person)||!in_array($person,$policies[$policyId]['approver_ids'],true)||!self::validTime($at)){$fail();}$approvedPeople[$person]=true;}
        }
        foreach($r['approval_identities'] as $person=>$identity){
            if(!is_string($person)||(!isset($approvedPeople[$person])&&$person!==$r['requested_by'])||!is_string($identity)||$identity===''||strlen($identity)>256||preg_match('/[\x00-\x1f\x7f]/',$identity)){$fail();}
        }
        foreach($approvedPeople as $person=>$_){if(!isset($r['approval_identities'][$person])){$fail();}}
        foreach($r['history'] as $index=>$row){
            if(!is_array($row)||count($row)!==3||array_diff(array_keys($row),['at','action','person'])||!self::validTime($row['at']??null)
                ||!in_array($row['action']??null,['requested','approved','submission_claimed','rejected'],true)||!is_string($row['person']??null)){$fail();}
            try{
                if($index===0){EnterpriseOperationsConfig::requester($row['person']);}
                else{EnterpriseOperationsConfig::actor($row['person']);}
            }catch(\Throwable $error){$fail();}
            if($index===0&&($row['action']!=='requested'||$row['person']!==$r['requested_by'])){$fail();}
        }
        if(isset($r['claimed_at'])&&(!is_int($r['claimed_at'])||$r['claimed_at']<$r['created_at']||$r['claimed_at']>=$r['expires_at'])){$fail();}
        if(isset($r['finished_at'])&&(!is_int($r['finished_at'])||$r['finished_at']<$r['claimed_at'])){$fail();}
        if(isset($r['result'])){
            if(!is_array($r['result'])||array_is_list($r['result'])&&$r['result']!==[]||array_diff(array_keys($r['result']),['success','job_id','error_code','message'])){$fail();}
            if(array_key_exists('success',$r['result'])&&!is_bool($r['result']['success'])){$fail();}
            if(array_key_exists('job_id',$r['result'])&&(!is_string($r['result']['job_id'])||!preg_match('/^job_[a-f0-9]{32}$/D',$r['result']['job_id']))){$fail();}
            if(array_key_exists('error_code',$r['result'])&&(!is_string($r['result']['error_code'])||!preg_match('/^[a-z][a-z0-9_]{0,95}$/D',$r['result']['error_code']))){$fail();}
            if(array_key_exists('message',$r['result'])&&(!is_string($r['result']['message'])||strlen($r['result']['message'])>2048||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$r['result']['message']))){$fail();}
        }
        $last=$r['history'][count($r['history'])-1]['action'];
        if($state==='awaiting_approval'&&in_array($last,['submission_claimed','rejected'],true)){$fail();}
        if($state==='rejected'&&$last!=='rejected'){$fail();}
        if(in_array($state,['submitting','submitted','not_submitted','uncertain'],true)&&$last!=='submission_claimed'){$fail();}
        if($state==='submitted'&&!isset($r['result']['job_id'])){$fail();}
    }
    private static function validate(array $r): void
    {
        self::validateRecord($r);
    }
}
