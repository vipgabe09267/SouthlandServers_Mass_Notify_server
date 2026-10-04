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
        $created=is_int($request['created_at']??null) ? $request['created_at'] : strtotime((string)($request['delivery_timestamp']??''));
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
        return EnterpriseClusterRuntime::intent(['delivery_id'=>$id,'channel'=>$channel,'target'=>$target,'site_id'=>$c['site_id'],
            'created_at'=>$created,'expires_at'=>$expires,'content_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($request)),
            'estimated_cost_cents'=>$cost,
            'schedule_id'=>$request['schedule_context']['schedule_id']??'', 'incident_id'=>$request['incident_context']['incident_id']??'']);
    }
    public static function effect(array $settings,array $request,string $channel,string $target,callable $work)
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return $work(); }
        $runtime=new EnterpriseClusterRuntime($settings);
        $intent=self::intent($settings,$request,$channel,$target);
        if ($runtime->config()['mode']==='notification_ha') {
            $id=EnterpriseClusterRuntime::effectId($intent);
            $runtime->replicate('events',$id,['revision'=>1,'intent'=>$request,'state'=>'prepared','uncertain'=>false,
                'schedule_id'=>$intent['schedule_id'],'incident_id'=>$intent['incident_id'],'updated_at'=>$intent['created_at'],'receipts'=>[]]);
        }
        return $runtime->executeEffect($intent,$work);
    }
    public static function requireAuthority(array $settings): void
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings);
        if ($runtime->config()['role']==='witness') { throw new \DomainException('Witness-only runtime cannot process notification jobs.'); }
        if ($runtime->config()['mode']==='notification_ha') { $runtime->acquire(); }
    }
    public static function authority(array $settings,?EnterpriseClusterRuntime $runtime=null): array
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return ['ok'=>true,'enabled'=>false,'claim'=>null]; }
        $runtime ??= new EnterpriseClusterRuntime($settings);
        if ($runtime->config()['role']==='witness') { throw new \DomainException('Witness-only runtime cannot process notification jobs.'); }
        if ($runtime->config()['mode']==='notification_ha') { $runtime->acquire(); }
        return ['ok'=>true,'enabled'=>true,'claim'=>null];
    }
    public static function replicateScheduleStore(array $settings,array $store): void
    {
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return; }
        foreach ($store['occurrences']??[] as $id=>$occurrence) {
            if (!is_array($occurrence)) { throw new \DomainException('Invalid schedule replica.'); }
            $identity=['schedule_id'=>$occurrence['schedule_id']??'','occurrence_id'=>$occurrence['occurrence_id']??$id,'run_at_utc'=>$occurrence['run_at_utc']??''];
            $revision=$runtime->store()->transaction(static function(array &$s) use($id): int {
                $s['snapshot_revisions']['schedule:'.$id]=($s['snapshot_revisions']['schedule:'.$id]??0)+1;
                return $s['snapshot_revisions']['schedule:'.$id];
            });
            $state=['success'=>'complete','missed'=>'expired','preparing'=>'prepared','claimed'=>'running','pending'=>'queued'][$occurrence['state']??'']??($occurrence['state']??'failed');
            if (!in_array($state,['prepared','queued','running','complete','failed','uncertain','cancelled','expired'],true)) { $state='running'; }
            $runtime->replicate('schedules',(string)$id,['revision'=>$revision,'intent'=>$identity,'state'=>$state,
                'uncertain'=>($occurrence['state']??'')==='uncertain','schedule_id'=>$identity['schedule_id'],'incident_id'=>'',
                'updated_at'=>time(),'receipts'=>['snapshot'=>$occurrence]]);
        }
    }
    public static function prepare(array $settings,array $request): void
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings);
        if ($runtime->config()['mode']!=='notification_ha') { return; }
        $intent=self::intent($settings,$request,'job','all');
        $runtime->replicate('jobs',$intent['delivery_id'],['revision'=>1,'intent'=>$request,'state'=>'prepared','uncertain'=>false,
            'schedule_id'=>$intent['schedule_id'],'incident_id'=>$intent['incident_id'],'updated_at'=>$intent['created_at'],'receipts'=>[]]);
    }
    public static function replicateJob(array $settings,array $job): void
    {
        if (!EnterpriseClusterConfig::enabled($settings)) { return; }
        $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return; }
        $id=EnterpriseClusterConfig::id($job['id']??'');
        $request=$job['request']??null; if (!is_array($request)) { throw new \DomainException('Replica job has no immutable request.'); }
        $revision=$runtime->store()->transaction(static function(array &$s) use($id): int {
            $s['snapshot_revisions']['job:'.$id]=($s['snapshot_revisions']['job:'.$id]??0)+1;
            return $s['snapshot_revisions']['job:'.$id];
        });
        $state=$job['state']??'failed'; if ($state==='worker_starting') { $state='queued'; }
        $uncertain=!empty($job['result']['submission_uncertain']) || count(array_filter($job['receipts']??[],static fn($r)=>($r['state']??'')==='uncertain'))>0;
        $runtime->replicate('jobs',$id,['revision'=>$revision,'intent'=>['id'=>$id,'created_at'=>$job['created_at']??'','request'=>$request],
            'state'=>$state,'uncertain'=>$uncertain,'schedule_id'=>$request['schedule_context']['schedule_id']??'',
            'incident_id'=>$request['incident_context']['incident_id']??'','updated_at'=>time(),'receipts'=>$job['receipts']??[]]);
    }
    /** Escaped metadata, never an executable, secret, address, or supplied path. */
    public static function command(array $request,string $command,array $settings=[]): string
    {
        RuntimeState::requireRunning($settings);
        if (!EnterpriseClusterConfig::enabled($settings)) { return $command; }
        $created=strtotime((string)($request['delivery_timestamp']??''));
        $env=['SLS_CLUSTER_DELIVERY_ID'=>(string)($request['delivery_id']??''),'SLS_CLUSTER_CREATED_AT'=>(string)($created?:0),
            'SLS_CLUSTER_EXPIRES_AT'=>(string)min($request['schedule_deadline_at']??PHP_INT_MAX,($created?:0)+900)];
        $prefix=[];
        foreach ($env as $key=>$value) { $prefix[]=$key.'='.escapeshellarg($value); }
        return implode(' ',$prefix).' '.$command;
    }
}
