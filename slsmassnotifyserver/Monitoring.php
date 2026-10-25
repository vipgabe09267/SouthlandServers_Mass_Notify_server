<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/DeploymentReadiness.php';

final class Monitoring
{
    public static function report(array $snapshot, ?int $now=null): array
    {
        $now=$now??time();$data=$snapshot['data']??[];$at=$data['checked_at']??null;
        $valid=($data['schema']??null)===1&&is_int($at)&&$at>0&&$at<=$now+60;
        $metrics=['snapshot_available'=>$valid?1:0];
        if(!$valid){return ['schema'=>1,'generated_at'=>gmdate('c',$now),'metrics'=>$metrics,'fresh'=>false];}
        $metrics['snapshot_age_seconds']=max(0,$now-$at);
        foreach(['effective_cpu_count','effective_memory_bytes'] as $key){
            $value=$data['compute'][$key]??null;
            if((is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0){$metrics[$key]=$value;}
        }
        foreach(['sls_data','runtime','logs','temporary'] as $label){
            foreach(['available_bytes','available_inodes'] as $key){
                $value=$data['filesystems'][$label][$key]??null;
                if(is_int($value)&&$value>=0){$metrics[$label.'_'.$key]=$value;}
            }
        }
        foreach(['pending_external','expired_external','queue_errors','pending_weather','failed_weather','uncertain_weather','expired_weather','weather_running','weather_deadline_misses','audit_failed_records'] as $key){
            $value=$data['queues'][$key]??null;
            if(is_int($value)&&$value>=0&&$value<=1000000){$metrics[$key]=$value;}
        }
        foreach(['sample_count','duration_samples','duration_p95_ms'] as $key){
            $value=$data['api'][$key]??null;
            if(is_int($value)&&$value>=0){$metrics['api_recent_'.$key]=$value;}
        }
        if(is_array($data['mail']??null)){
            $metrics['mail_evidence_available']=($data['mail']['available']??false)===true?1:0;
            $metrics['mail_evidence_fresh']=($data['mail']['fresh']??false)===true?1:0;
            $depth=$data['mail']['queue_depth']??null;
            if(is_int($depth)&&$depth>=0&&$depth<=1000){$metrics['mail_queue_depth']=$depth;}
        }
        return ['schema'=>1,'generated_at'=>gmdate('c',$now),'snapshot_at'=>gmdate('c',$at),
            'fresh'=>$now-$at<=180,'metrics'=>$metrics,'api_window_seconds'=>3600,'api_samples_complete'=>($data['api']['complete']??false)===true];
    }

    public static function prometheus(array $report): string
    {
        $output="# HELP sls_snapshot_available Whether a valid maintenance snapshot is available.\n";
        foreach($report['metrics'] as $name=>$value){
            if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$name)||(!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0){continue;}
            $output.='# TYPE sls_'.$name." gauge\n".'sls_'.$name.' '.json_encode($value,JSON_THROW_ON_ERROR)."\n";
        }
        return $output;
    }

    public static function read(string $directory): array
    {
        return self::report(\FreePBX\modules\SlsDeploymentReadiness::snapshot($directory.'/support-snapshot.json'));
    }
}
