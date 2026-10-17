<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterRuntime.php';
require_once __DIR__.'/bin/sls_mass_notify/sls_runtime_state.php';

/** One shared boundary for existing PHP transports and the isolated CLI guard. */
final class EnterpriseClusterIntegration
{
    public static function intent(array $settings,array $request,string $channel,string $target): array
    {
        $c=EnterpriseClusterConfig::normalize($settings['enterprise_cluster'] ?? []);
        $id=$request['delivery_id'] ?? $request['correlation'] ?? '';
        if (!is_string($id) || $id==='') { throw new \DomainException('Cluster delivery requires an immutable delivery identity; this transport was fenced.'); }
        EnterpriseClusterConfig::id($id);
        $createdValue=$request['created_at']??null;
        if (is_int($createdValue)) {
            $created=$createdValue;
        } else {
            $timestamp=$request['delivery_timestamp']??null;
            if (!is_string($timestamp)) { throw new \DomainException('Cluster delivery requires a text creation timestamp.'); }
            $created=EnterpriseClusterRuntime::timestamp($timestamp);
        }
        if (!$created || $created<1) { throw new \DomainException('Cluster delivery requires the original immutable creation time.'); }
        $expires=$request['expires_at'] ?? $request['schedule_deadline_at'] ?? $created+900;
        if (!is_int($expires)) { throw new \DomainException('Cluster delivery expiry is invalid.'); }
        $expires=min($expires,$created+900);
        $target=preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D',$target) ? $target : 'target-'.hash('sha256',$target);
        $cost=$request['estimated_cost_cents']??0;
        if ($channel==='external_voice') {
            $cost=$c['voice_reservation_cents'];
            if ($cost<1) { throw new \DomainException('Cluster outbound voice requires a positive configured worst-case call charge reservation.'); }
        }
        $scheduleContext=$request['schedule_context']??[]; $incidentContext=$request['incident_context']??[];
        if (!is_array($scheduleContext) || !is_array($incidentContext)) { throw new \DomainException('Cluster delivery context must be structured data.'); }
        return EnterpriseClusterRuntime::intent(['delivery_id'=>$id,'channel'=>$channel,'target'=>$target,'site_id'=>$c['site_id'],
            'created_at'=>$created,'expires_at'=>$expires,'content_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($request)),
            'estimated_cost_cents'=>$cost,
            'schedule_id'=>$scheduleContext['schedule_id']??'', 'incident_id'=>$incidentContext['incident_id']??'']);
    }
    public static function effect(array $settings,array $request,string $channel,string $target,callable $work)
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return $work(); }
        $runtime=new EnterpriseClusterRuntime($settings);
        $intent=self::intent($settings,$request,$channel,$target);
        return $runtime->executeEffect($intent,$work);
    }
    public static function requireAuthority(array $settings): void
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings);
        $runtime->authorize();
    }
    public static function authority(array $settings,?EnterpriseClusterRuntime $runtime=null): array
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return ['ok'=>true,'enabled'=>false,'claim'=>null]; }
        $runtime ??= new EnterpriseClusterRuntime($settings);
        return $runtime->authorize();
    }
    public static function scheduleDelta(array $store,string $id): array
    {
        EnterpriseClusterConfig::id($id);
        $occurrences=$store['occurrences']??null;
        if (!is_array($occurrences)) { throw new \DomainException('Schedule replica store is invalid.'); }
        $occurrence=$occurrences[$id]??null;
        if (!is_array($occurrence) || ($occurrence['occurrence_id']??$id)!==$id) { throw new \DomainException('Invalid changed schedule occurrence.'); }
        $identity=['schedule_id'=>$occurrence['schedule_id']??'','occurrence_id'=>$id,'run_at_utc'=>$occurrence['run_at_utc']??''];
        foreach ($identity as $value) { if (!is_string($value) || strlen($value)>128 || preg_match('/[\x00-\x1f\x7f]/',$value)) { throw new \DomainException('Invalid schedule replica identity.'); } }
        EnterpriseClusterConfig::id($identity['schedule_id']);
        $sourceState=$occurrence['state']??'failed';
        if (!is_string($sourceState)) { throw new \DomainException('Schedule replica state must be text.'); }
        $state=['success'=>'complete','missed'=>'expired','preparing'=>'prepared','claimed'=>'running','pending'=>'queued'][$sourceState]??$sourceState;
        if (!in_array($state,['prepared','queued','running','complete','failed','uncertain','cancelled','expired'],true)) { $state='running'; }
        $record=['revision'=>1,'intent'=>$identity,'state'=>$state,'uncertain'=>($occurrence['state']??'')==='uncertain',
            'schedule_id'=>$identity['schedule_id'],'incident_id'=>'','updated_at'=>time(),'receipts'=>['snapshot'=>$occurrence]];
        EnterpriseClusterRuntime::record($record);
        return ['id'=>$id,'source_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($occurrence)),'record'=>$record];
    }
    /** Stage exactly one changed occurrence before its local ledger replacement. */
    public static function stageScheduleDelta(array $settings,array $store,string $id): ?array
    {
        if (!EnterpriseClusterConfig::enabled($settings)) { return null; }
        $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return null; }
        $delta=self::scheduleDelta($store,$id);
        $delta['key']=$runtime->stageReplicaOutbox('schedules',$delta['id'],$delta['source_sha256'],$delta['record']);
        $delta['runtime']=$runtime;
        return $delta;
    }
    /** Complete or defer one already committed local schedule mutation. */
    public static function commitScheduleDelta(?array $ticket,array $store): void
    {
        if ($ticket===null) { return; }
        $runtime=$ticket['runtime']??null;
        if (!$runtime instanceof EnterpriseClusterRuntime || !is_string($ticket['key']??null)
            || !is_string($ticket['source_sha256']??null)) { throw new \LogicException('Invalid schedule replication ticket.'); }
        try {
            $runtime->commitReplicaOutbox($ticket['key'],$ticket['source_sha256']);
            $runtime->flushReplicaOutbox(static function(string $kind,string $id) use($store): ?string {
                $record=$kind==='schedules'?($store['occurrences'][$id]??null):null;
                return is_array($record)?hash('sha256',EnterpriseClusterProtocol::canonical($record)):null;
            },['schedules']);
        } catch (\Throwable $ignored) {
            // Local schedule state already committed; the minute worker retries
            // the durable outbox rather than making the caller repeat it.
        }
    }
    /** Remove an exact prepared delta when its corresponding local write failed. */
    public static function abandonScheduleDelta(?array $ticket): void
    {
        if ($ticket===null) { return; }
        $runtime=$ticket['runtime']??null;
        if (!$runtime instanceof EnterpriseClusterRuntime || !is_string($ticket['key']??null)
            || !is_string($ticket['source_sha256']??null)) { throw new \LogicException('Invalid schedule replication ticket.'); }
        $runtime->abandonPreparedReplicaOutbox($ticket['key'],$ticket['source_sha256']);
    }
    public static function prepare(array $settings,array $request): void
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings);
        if ($runtime->config()['mode']!=='notification_ha') { return; }
        $intent=self::intent($settings,$request,'job','all');
        $revision=$runtime->reserveReplicaRevision('jobs',$intent['delivery_id']);
        $immutable=['schema'=>1,'id'=>$intent['delivery_id'],'created_at'=>gmdate('c',$intent['created_at']),'request'=>$request,
            'request_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($request))];
        $runtime->replicate('jobs',$intent['delivery_id'],['revision'=>$revision,'intent'=>$immutable,'state'=>'prepared','uncertain'=>false,
            'schedule_id'=>$intent['schedule_id'],'incident_id'=>$intent['incident_id'],'updated_at'=>$intent['created_at'],'receipts'=>[]]);
    }
    private static function jobReplicaRecord(array $job): array
    {
        $id=EnterpriseClusterConfig::id($job['id']??'');
        $request=$job['request']??null; if (!is_array($request)) { throw new \DomainException('Replica job has no immutable request.'); }
        $state=$job['state']??'failed'; if ($state==='worker_starting') { $state='queued'; }
        $receipts=$job['receipts']??[];
        if (!is_array($receipts)) { throw new \DomainException('Replica job receipts must be structured data.'); }
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) { throw new \DomainException('Replica job receipts must be structured data.'); }
        }
        $result=$job['result']??[];
        if (!is_array($result)) { throw new \DomainException('Replica job result must be structured data.'); }
        $uncertain=!empty($result['submission_uncertain'])
            || count(array_filter($receipts,static fn($r)=>($r['state']??'')==='uncertain'))>0;
        $scheduleContext=$request['schedule_context']??[]; $incidentContext=$request['incident_context']??[];
        if (!is_array($scheduleContext) || !is_array($incidentContext)) { throw new \DomainException('Replica job context must be structured data.'); }
        $immutable=['schema'=>1,'id'=>$id,'created_at'=>$job['created_at']??'','request'=>$request,
            'request_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($request))];
        $record=['revision'=>1,'intent'=>$immutable,
            'state'=>$state,'uncertain'=>$uncertain,'schedule_id'=>$scheduleContext['schedule_id']??'',
            'incident_id'=>$incidentContext['incident_id']??'','updated_at'=>time(),'receipts'=>$receipts];
        EnterpriseClusterRuntime::record($record);
        EnterpriseClusterRuntime::jobReplicaIntent($immutable,$id);
        return ['id'=>$id,'state'=>$state,'record'=>$record];
    }
    public static function replicateJob(array $settings,array $job): void
    {
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return; }
        $prepared=self::jobReplicaRecord($job);
        $id=$prepared['id']; $state=$prepared['state']; $record=$prepared['record'];
        $record['revision']=$runtime->reserveReplicaRevision('jobs',$id);
        $runtime->replicate('jobs',$id,$record);
        if (in_array($state,['complete','failed','uncertain','cancelled','expired'],true)) { $runtime->retireReplicaSource('jobs',$id); }
    }
    /** Escaped metadata, never an executable, secret, address, or supplied path. */
    public static function command(array $request,string $command,array $settings=[]): string
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return $command; }
        $timestamp=$request['delivery_timestamp']??null;
        if (!is_string($timestamp)) { throw new \DomainException('Cluster command metadata requires a text creation timestamp.'); }
        $created=EnterpriseClusterRuntime::timestamp($timestamp);
        $deadline=$request['schedule_deadline_at']??PHP_INT_MAX;
        $deliveryId=$request['delivery_id']??null;
        if (!$created || $created<1 || !is_int($deadline) || !is_string($deliveryId)) { throw new \DomainException('Cluster command metadata requires an immutable delivery ID, creation time and integer expiry.'); }
        EnterpriseClusterConfig::id($deliveryId);
        $env=['SLS_CLUSTER_DELIVERY_ID'=>$deliveryId,'SLS_CLUSTER_CREATED_AT'=>(string)$created,
            'SLS_CLUSTER_EXPIRES_AT'=>(string)min($deadline,$created+900)];
        $prefix=[];
        foreach ($env as $key=>$value) { $prefix[]=$key.'='.escapeshellarg($value); }
        return implode(' ',$prefix).' '.$command;
    }
}
