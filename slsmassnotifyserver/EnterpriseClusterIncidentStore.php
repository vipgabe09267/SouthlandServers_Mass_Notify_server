<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentStore.php';
require_once __DIR__.'/EnterpriseClusterRuntime.php';

/** Incident mutations stage a durable cluster outbox before local commit. */
final class EnterpriseClusterIncidentStore extends IncidentStore
{
    private $settings;
    public function __construct(string $directory,callable $settings)
    {
        parent::__construct($directory); $this->settings=$settings;
    }
    public function transaction(string $id,callable $operation,bool $create=false): array
    {
        $runtime=null;$outboxKey=null;$sourceHash=null;
        try {
            $result=parent::transaction($id,function(?array $old) use($operation,$id,&$runtime,&$outboxKey,&$sourceHash): array {
                $record=$operation($old); $settings=($this->settings)();
                if ($record===$old || !EnterpriseClusterConfig::enabled($settings)) { return $record; }
                $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { $runtime=null; return $record; }
                $uncertain=count(array_filter($record['operations']??[],static fn($operation)=>($operation['state']??'')==='uncertain'))>0;
                $state=['open'=>'running','planned'=>'queued','closed'=>'complete','missed'=>'expired'][$record['state']??'']??'failed';
                $sourceHash=hash('sha256',EnterpriseClusterProtocol::canonical($record));
                // Outbox staging acquires authority before writing its prepared row.
                $outboxKey=$runtime->stageReplicaOutbox('incidents',$id,$sourceHash,['revision'=>1,'intent'=>['id'=>$id,'start_fingerprint'=>$record['start_fingerprint']??'',
                    'template'=>$record['template']??[],'created_at'=>$record['created_at']??''], 'state'=>$state,'uncertain'=>$uncertain,
                    'schedule_id'=>'','incident_id'=>$id,'updated_at'=>time(),'receipts'=>['snapshot'=>$record]]);
                return $record;
            },$create);
        } catch (\Throwable $error) {
            if ($runtime instanceof EnterpriseClusterRuntime && is_string($outboxKey) && is_string($sourceHash)) {
                try { $runtime->abandonPreparedReplicaOutbox($outboxKey,$sourceHash); }
                catch (\Throwable $cleanupError) {
                    // The next cluster cycle abandons a prepared row whose hash
                    // does not match the unchanged local incident record.
                }
            }
            throw $error;
        }
        if ($runtime instanceof EnterpriseClusterRuntime && is_string($outboxKey) && is_string($sourceHash)) {
            // The local incident is already durable. A cluster outage must leave
            // the exact outbox entry for the worker instead of returning an error
            // that could cause the caller to repeat the incident mutation.
            try {
                $runtime->commitReplicaOutbox($outboxKey,$sourceHash);
                $runtime->flushReplicaOutbox(function(string $kind,string $recordId): ?string {
                    if ($kind!=='incidents') { return null; }
                    $record=$this->read($recordId);
                    return $record===null ? null : hash('sha256',EnterpriseClusterProtocol::canonical($record));
                },['incidents']);
            } catch (\Throwable $ignored) {
                // The minute worker retries the durable prepared/committed row.
            }
        }
        return $result;
    }
}
