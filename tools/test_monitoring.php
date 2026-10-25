<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/Monitoring.php';
use SLS\MassNotify\Monitoring;
function monitoringCheck(bool $condition,string $message):void {if(!$condition){throw new RuntimeException($message);}}
$now=1800000000;
$snapshot=['data'=>['schema'=>1,'checked_at'=>$now-60,'compute'=>['effective_cpu_count'=>2.5,'effective_memory_bytes'=>4294967296,'hostname'=>'SECRET_HOST'],
    'filesystems'=>['sls_data'=>['available_bytes'=>3000000,'available_inodes'=>123,'path'=>'SECRET_PATH']],
    'queues'=>['pending_weather'=>3,'failed_weather'=>-1,'message'=>'SECRET_MESSAGE'],
    'api'=>['duration_p95_ms'=>123,'duration_samples'=>15,'sample_count'=>20,'complete'=>false,'credential_usage'=>['SECRET_KEY'=>123]]]];
$report=Monitoring::report($snapshot,$now);
monitoringCheck($report['fresh']&&$report['metrics']['snapshot_age_seconds']===60&&$report['metrics']['pending_weather']===3,'Fresh aggregate metrics were lost.');
$raw=json_encode($report,JSON_THROW_ON_ERROR);
monitoringCheck(!str_contains($raw,'SECRET_')&&!isset($report['metrics']['failed_weather']),'Monitoring disclosed source data or a negative counter.');
$text=Monitoring::prometheus($report);
monitoringCheck(str_contains($text,"sls_api_recent_duration_p95_ms 123\n")&&!str_contains($text,'SECRET_'),'Prometheus output did not preserve safe scalar metrics.');
foreach([['error'=>'missing'],['data'=>['schema'=>1,'checked_at'=>$now+61]],['data'=>['schema'=>1,'checked_at'=>'yesterday']]] as $bad){
    $invalid=Monitoring::report($bad,$now);monitoringCheck(!$invalid['fresh']&&$invalid['metrics']===['snapshot_available'=>0],'Missing or invalid data looked healthy.');
}
$snapshot['data']['mail']=['available'=>true,'fresh'=>true,'queue_depth'=>27];
$withMail=Monitoring::report($snapshot,$now);
monitoringCheck($withMail['metrics']['mail_queue_depth']===27&&$withMail['metrics']['mail_evidence_available']===1,'Optional mail evidence metrics were lost.');
$snapshot['data']['checked_at']=$now-181;monitoringCheck(!Monitoring::report($snapshot,$now)['fresh'],'A stale maintenance snapshot looked current.');
monitoringCheck(!str_contains(Monitoring::prometheus(['metrics'=>["bad\nmetric"=>1,'infinity'=>INF,'boolean'=>true]]),'bad'),'Unsafe metric names or values were emitted.');
echo "Monitoring: bounded scalar projection, no secrets, freshness and Prometheus format passed.\n";
