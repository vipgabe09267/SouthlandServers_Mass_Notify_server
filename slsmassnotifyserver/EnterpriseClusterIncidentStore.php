<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentStore.php';
require_once __DIR__.'/EnterpriseClusterRuntime.php';

/** Incident intent and responses are replicated before the local transaction commits. */
final class EnterpriseClusterIncidentStore extends IncidentStore
{
    private $settings;
    public function __construct(string $directory,callable $settings)
    {
        parent::__construct($directory); $this->settings=$settings;
    }
    public function transaction(string $id,callable $operation,bool $create=false): array
    {
        return parent::transaction($id,function(?array $old) use($operation,$id): array {
            $record=$operation($old); $settings=($this->settings)();
            if ($record===$old || !EnterpriseClusterConfig::enabled($settings)) { return $record; }
            $runtime=new EnterpriseClusterRuntime($settings); if ($runtime->config()['mode']!=='notification_ha') { return $record; }
            $runtime->acquire();
            $revision=$runtime->store()->transaction(static function(array &$s) use($id): int {
                $s['snapshot_revisions']['incident:'.$id]=($s['snapshot_revisions']['incident:'.$id]??0)+1;
                return $s['snapshot_revisions']['incident:'.$id];
            });
            $uncertain=count(array_filter($record['operations']??[],static fn($operation)=>($operation['state']??'')==='uncertain'))>0;
            $state=['open'=>'running','planned'=>'queued','closed'=>'complete','missed'=>'expired'][$record['state']??'']??'failed';
            $runtime->replicate('incidents',$id,['revision'=>$revision,'intent'=>['id'=>$id,'start_fingerprint'=>$record['start_fingerprint']??'',
                'template'=>$record['template']??[],'created_at'=>$record['created_at']??''], 'state'=>$state,'uncertain'=>$uncertain,
                'schedule_id'=>'','incident_id'=>$id,'updated_at'=>time(),'receipts'=>['snapshot'=>$record]]);
            return $record;
        },$create);
    }
}
