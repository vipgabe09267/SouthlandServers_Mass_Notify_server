<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/AutomationConfig.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/bin/sls_mass_notify/sls_runtime_state.php';

final class AutomationService
{
    private AutomationStore $store;
    private $settings; private $announce; private $action; private $clock;
    public function __construct(AutomationStore $store, callable $settings, callable $announce, callable $action, ?callable $clock=null)
    {
        $this->store=$store; $this->settings=$settings; $this->announce=$announce; $this->action=$action; $this->clock=$clock ?? static fn()=>time();
    }
    public function challenge(string $ruleId, string $requestId): array
    {
        $current=AutomationConfig::resolve(($this->settings)(),$ruleId);
        if ($current['rule']['kind']!=='panic') { throw new \DomainException('This identity is not enrolled for panic activation.'); }
        IncidentConfig::identifier($requestId,'/^[a-f0-9]{32}$/D','Panic request identifier');
        $now=($this->clock)(); $key=hash('sha256',$ruleId.'|'.$requestId);
        return $this->store->transaction(static function(array &$state) use($current,$ruleId,$requestId,$now,$key): array {
            AutomationStore::prune($state,$now);
            if (isset($state['challenges'][$key])) { return $state['challenges'][$key]; }
            if (isset($state['events'][$key])) { throw new \DomainException('This request was already activated. Read its result instead of requesting a new challenge.'); }
            if (count($state['challenges'])>=128) { throw new \RuntimeException('Panic confirmation capacity is full. Wait for expired confirmations to clear.'); }
            $challenge=['rule_id'=>$ruleId,'request_id'=>$requestId,'confirmation'=>bin2hex(random_bytes(16)),
                'revision'=>$current['revision'],'expires_at'=>$now+60,'not_before'=>$now+2,
                'location'=>$current['location']['name'],'trigger'=>$current['rule']['name']];
            $state['challenges'][$key]=$challenge; return $challenge;
        });
    }
    public function activate(string $ruleId, array $input, string $source): array
    {
        RuntimeState::requireRunning(($this->settings)());
        $resolved=AutomationConfig::resolve(($this->settings)(),$ruleId); $rule=$resolved['rule']; $now=($this->clock)();
        $phone=$source==='panic_phone' && $rule['kind']==='panic' && ($rule['dial_extension'] ?? '')!=='';
        if ($rule['kind']!==$source && !$phone) { throw new \DomainException('The enrolled source does not permit this activation method.'); }
        IncidentConfig::object($input,['request_id','sent_at','expires_at','event','message','caller','is_test','confirmation','references','invalidate','operation'],'Trigger event');
        $requestId=IncidentConfig::identifier($input['request_id'] ?? '', '/^[A-Za-z0-9_.:-]{1,200}$/D','Event identifier');
        $sent=$input['sent_at'] ?? null; $expires=$input['expires_at'] ?? null;
        if (!is_int($sent) || !is_int($expires) || $sent>$now+15 || $sent<$now-$rule['max_age_seconds'] || $expires<=$now || $expires<=$sent) {
            throw new \DomainException('Event timestamps are missing, expired or outside this trigger’s allowed clock window.');
        }
        $test=IncidentConfig::flag($input['is_test'] ?? null,'Explicit event test flag');
        if ($test && !$rule['allow_tests']) { throw new \DomainException('This trigger does not permit test events.'); }
        $operation=$input['operation'] ?? 'alert';
        if (!in_array($operation,['alert','update','cancel'],true) || ($operation!=='alert' && $source!=='cap')) { throw new \DomainException('Only trusted CAP sources can update or cancel source events.'); }
        $event=IncidentConfig::text($input['event'] ?? '',80,'Event name');
        if (isset($rule['event']) && !hash_equals($rule['event'],$event)) { throw new \DomainException('Event name does not match this trigger’s configured event.'); }
        $message=IncidentConfig::text($input['message'] ?? '',500,'Event message',true);
        $caller=IncidentConfig::text($input['caller'] ?? '',20,'Observed caller',true);
        if ($phone && !in_array($caller,$rule['callers'],true)) { throw new \DomainException('This phone is not authorized for the selected panic activation.'); }
        if (!$phone && $source!=='emergency_call' && $caller!=='') { throw new \DomainException('Caller identity is reserved for verified PBX call observations.'); }
        if ($source==='emergency_call' && (!in_array($caller,$rule['callers'],true) || !in_array($event,$rule['numbers'],true))) { throw new \DomainException('Observed call does not match this trigger’s designated callers and numbers.'); }
        $id=hash('sha256',$ruleId.'|'.$requestId); $references=[]; $invalidate=[];
        foreach (['references','invalidate'] as $key) {
            $items=IncidentConfig::listOf($input[$key] ?? [],50,'CAP references');
            if ($items && $source!=='cap') { throw new \DomainException('Only trusted CAP updates/cancellations may supersede source events.'); }
            foreach ($items as $value) { ${$key}[]=IncidentConfig::identifier($value,'/^[a-f0-9]{64}$/D','CAP reference'); }
        }
        $expires=min($expires,$sent+$rule['max_age_seconds']);
        $context=['rule_id'=>$ruleId,'revision'=>$resolved['revision'],'event_id'=>$id,'expires_at'=>$expires];
        $fingerprint=hash('sha256',json_encode([$ruleId,$input],JSON_THROW_ON_ERROR));
        return $this->store->transaction(static function(array &$state) use($resolved,$rule,$source,$now,$id,$input,$requestId,$context,$fingerprint,$event,$message,$caller,$test,$references,$invalidate,$operation,$phone): array {
            AutomationStore::prune($state,$now);
            if (isset($state['events'][$id])) {
                if (!hash_equals($state['events'][$id]['fingerprint'],$fingerprint)) { throw new \DomainException('This event identifier was already used with different content.'); }
                return $state['events'][$id];
            }
            if ($source==='panic' || $phone) {
                $challenge=$state['challenges'][$id] ?? null;
                if (!$challenge || $challenge['expires_at']<=$now || $challenge['not_before']>$now || $challenge['revision']!==$resolved['revision']
                    || !is_string($input['confirmation'] ?? null) || !hash_equals($challenge['confirmation'],$input['confirmation'])) {
                    throw new \DomainException('Panic confirmation is missing, too early, expired, or configuration changed. Review a new confirmation before activating.');
                }
            }
            // Cancellations bypass cooldown, so a burst cannot hold an invalidated alert open.
            if (!$invalidate && ($state['cooldowns'][$rule['id']] ?? 0)>$now) { throw new \DomainException('This trigger is cooling down. The event was not queued; preserve its identifier and retry after the configured cooldown.', 429); }
            if (count($state['events'])>=500 || count($state['invalidated'])+count($invalidate)>2048) { throw new \RuntimeException('Trigger journal capacity is full. Preserve history and review the source’s event volume.'); }
            foreach ($invalidate as $reference) { $state['invalidated'][$reference]=$now+86400; }
            foreach ($state['events'] as &$previous) {
                if (array_intersect($previous['references'] ?? [],$invalidate)) { $previous['blocked']=true; if ($previous['state']==='queued') { $previous['state']='cancelled'; } }
            } unset($previous);
            $fields=[];
            foreach ($rule['fields'] as $key=>$binding) { $fields[$key]=match($binding) {
                '@location'=>$resolved['location']['name'] ?? '', '@event'=>$event, '@message'=>$message, '@caller'=>$caller, default=>$binding}; }
            if ($resolved['template'] && $operation!=='cancel') { IncidentConfig::render($resolved['template'],$fields); }
            $cancel=$operation==='cancel';
            $row=['id'=>$id,'fingerprint'=>$fingerprint,'rule_id'=>$rule['id'],'rule_name'=>$rule['name'],'source'=>$source,
                'created_at'=>$now,'state'=>$cancel ? 'cancelled' : 'queued','context'=>$context,'references'=>$references,
                'blocked'=>$cancel || (bool)array_intersect($references,array_keys($state['invalidated'])),
                'event'=>$event,'message'=>$message,'caller'=>$caller,'is_test'=>$test,'fields'=>$fields,'results'=>[]];
            $state['events'][$id]=$row; unset($state['challenges'][$id]);
            $state['cooldowns'][$rule['id']]=$now+$rule['cooldown_seconds'];
            return $row;
        });
    }
    public function permits(array $context): bool
    {
        if (!RuntimeState::running(($this->settings)())) { return false; }
        $now=($this->clock)();
        return AutomationConfig::permits(($this->settings)(),$context,$now) && $this->store->permits($context,$now);
    }
    public function process(string $id): array
    {
        if (!RuntimeState::running(($this->settings)())) { return ['state'=>'queued', 'deferred'=>true, 'message'=>'SLS notifications are stopped.']; }
        IncidentConfig::identifier($id,'/^[a-f0-9]{64}$/D','Trigger event identifier'); $now=($this->clock)();
        $row=$this->store->transaction(static function(array &$state) use($id,$now): array {
            $row=$state['events'][$id] ?? null;
            if (!$row) { throw new \DomainException('Trigger event does not exist.'); }
            if ($row['state']==='running' && ($row['claimed_at'] ?? 0)<$now-240) { $row['state']='uncertain'; $row['error']='The worker stopped after claiming this event. Actions will not be replayed automatically.'; }
            if ($row['state']!=='queued') { $state['events'][$id]=$row; return $row+['claimed'=>false]; }
            if ($row['context']['expires_at']<=$now || !empty($row['blocked'])) { $row['state']='expired'; $state['events'][$id]=$row; return $row+['claimed'=>false]; }
            $running=count(array_filter($state['events'],static fn($v)=>$v['state']==='running' && ($v['claimed_at'] ?? 0)>=$now-240));
            if ($running>=4) { return $row+['claimed'=>false]; }
            $row['state']='running'; $row['claimed_at']=$now; $state['events'][$id]=$row; return $row+['claimed'=>true];
        });
        if (!$row['claimed']) { unset($row['claimed']); return $row; } unset($row['claimed']);
        $results=[]; $state='complete';
        try {
            if (!$this->permits($row['context'])) { throw new \DomainException('Trigger authorization changed or this source event expired/superseded. No remaining actions were submitted.'); }
            $resolved=AutomationConfig::resolve(($this->settings)(),$row['rule_id']);
            if ($resolved['template']) {
                $result=($this->announce)($resolved,$row);
                $results['announcement']=$result;
                if (empty($result['success'])) { $state='failed'; }
                $this->progress($id,$results);
            }
            foreach ($resolved['actions'] as $action) {
                if (!$this->permits($row['context'])) { $results[$action['id']]=['state'=>'cancelled','detail'=>'Trigger expired, changed, or was superseded before this action.']; $state='failed'; }
                else { $results[$action['id']]=($this->action)($action,$row); if (!in_array($results[$action['id']]['state'] ?? '',['submitted','completed'],true)) { $state='failed'; } }
                $this->progress($id,$results);
            }
        } catch (\DomainException $error) { $results['guard']=['state'=>'cancelled','detail'=>$error->getMessage()]; $state='cancelled'; }
        catch (\Throwable $error) { $results['worker']=['state'=>'uncertain','detail'=>'Trigger worker could not confirm completion. Review saved action results before any manual resend.']; $state='uncertain'; }
        return $this->store->transaction(static function(array &$journal) use($id,$results,$state): array {
            $journal['events'][$id]['results']=$results; $journal['events'][$id]['state']=$state; return $journal['events'][$id];
        });
    }
    private function progress(string $id, array $results): void
    {
        $this->store->transaction(static function(array &$state) use($id,$results): void { $state['events'][$id]['results']=$results; });
    }
}
