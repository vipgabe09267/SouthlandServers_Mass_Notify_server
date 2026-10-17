<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/WeatherDeliveryReceipts.php';
use SLS\MassNotify\WeatherDeliveryReceipts;
$root=sys_get_temp_dir().'/sls-weather-receipts-'.bin2hex(random_bytes(8));if(!mkdir($root,0700)){throw new RuntimeException('Unable to create private weather-receipt fixture.');}$count=0;
function receiptCheck(bool $value,string $message):void{global $count;$count++;if(!$value)throw new RuntimeException($message);}
$path=$root.'/external-deliveries-nws_fixture.json';
$record=['payload'=>['source'=>'nws','event_id'=>'alert-fixture','body'=>'private body'], 'source_validity'=>['zone'=>'TXZ163','group_id'=>'nws_fixture'],
 'routing_snapshot'=>['webhook_ids'=>['discord:discord_fixture'],'email_recipients'=>['private@example.org'],'webhook_fingerprints'=>['discord:discord_fixture'=>'secret']],
 'destination_receipts'=>['discord:discord_fixture'=>['status'=>'accepted','http_status'=>204,'error'=>'','recorded_at'=>1000]], 'terminal_status'=>'complete','completed_at'=>1001];
$write=static function(array $record)use($path):void{$json=json_encode(['version'=>1,'deliveries'=>['fixture'=>$record]],JSON_THROW_ON_ERROR);receiptCheck(file_put_contents($path,$json,LOCK_EX)===strlen($json),'Unable to write receipt fixture.');receiptCheck(chmod($path,0600),'Unable to protect receipt fixture.');};
$event=['type'=>'nws','alert_id'=>'alert-fixture','zone'=>'TXZ163'];
try{
 $write($record);$out=WeatherDeliveryReceipts::forEvent($root,$event,['nws_zones'=>[['id'=>'nws_fixture','name'=>'Main site']]]);
 receiptCheck(count($out['rows'])===2 && $out['rows'][0]['status']==='accepted' && $out['rows'][0]['http_status']===204 && $out['rows'][0]['group']==='Main site','Confirmed Discord result missing.');
 receiptCheck($out['rows'][1]['status']==='not_recorded','Historical mail acceptance was invented.');
 receiptCheck(!str_contains(json_encode($out),'private@example.org')&&!str_contains(json_encode($out),'secret')&&!str_contains(json_encode($out),'private body'),'Receipt projection exposed protected data.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,$event+['unused'=>1])['warnings'],'Valid receipt storage raised a warning.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,array_replace($event,['alert_id'=>'other']))['rows'],'Another announcement received these receipts.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,array_replace($event,['zone'=>'TXZ999']))['rows'],'Another weather zone received these receipts.');
 receiptCheck(chmod($path,0644),'Unable to create public receipt fixture.');
 $public=WeatherDeliveryReceipts::forEvent($root,$event);receiptCheck(!$public['rows']&&$public['warnings'],'Publicly readable destination evidence was accepted.');
 receiptCheck(chmod($path,0400),'Unable to create read-only receipt fixture.');
 receiptCheck(count(WeatherDeliveryReceipts::forEvent($root,$event)['rows'])===2,'A protected read-only receipt could not be projected.');
 receiptCheck(chmod($path,0600),'Unable to restore writable receipt fixture.');
 $changed=$record;$changed['destination_receipts']['discord:discord_fixture']=['status'=>'failed','http_status'=>429,'error'=>'http_rate_limited','recorded_at'=>1000];$changed['terminal_status']='expired';$write($changed);
 $row=WeatherDeliveryReceipts::forEvent($root,$event)['rows'][0];receiptCheck($row['status']==='failed'&&$row['http_status']===429&&$row['queue_state']==='expired','Expired delivery lost its failed transport evidence.');
 $changed['destination_receipts']['discord:discord_fixture']['error']='https://secret.invalid/token';$write($changed);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['rows'][0]['error']==='','Unsafe transport message escaped the projection.');
 $changed['routing_snapshot']['webhook_ids']='invalid';$write($changed);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Malformed destination evidence caused an unhandled UI error.');
 $write($record);$handle=fopen($path,'r+b');receiptCheck(is_resource($handle)&&flock($handle,LOCK_EX),'Unable to lock receipt fixture.');$start=microtime(true);$busy=WeatherDeliveryReceipts::forEvent($root,$event);
 receiptCheck(!$busy['rows']&&$busy['warnings']&&microtime(true)-$start<0.5,'Busy receipt storage blocked the UI or presented unconfirmed evidence.');receiptCheck(fclose($handle),'Unable to close receipt fixture lock.');
 receiptCheck(count(WeatherDeliveryReceipts::forEvent($root,$event)['rows'])===2,'Receipt storage did not recover after its writer released the lock.');
 receiptCheck(link($path,$root.'/linked-copy'),'Unable to create receipt hard-link fixture.');receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Hard-linked receipt evidence was accepted.');receiptCheck(unlink($root.'/linked-copy'),'Unable to remove receipt hard-link fixture.');
 for($i=0;$i<600;$i++)receiptCheck(file_put_contents($root.'/unrelated-'.$i,'',LOCK_EX)===0,'Unable to create bounded-history fixture.');$start=microtime(true);
 receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[]&&microtime(true)-$start<0.5,'Large history directory was not bounded or reported.');
 foreach(glob($root.'/unrelated-*')as$file)unlink($file);
 receiptCheck(unlink($path)&&symlink('/etc/passwd',$path),'Unable to create receipt symlink fixture.');$unsafe=WeatherDeliveryReceipts::forEvent($root,$event);receiptCheck(!$unsafe['rows']&&$unsafe['warnings'],'Unsafe receipt link was accepted.');receiptCheck(unlink($path),'Unable to remove receipt symlink fixture.');
 receiptCheck(function_exists('posix_mkfifo')&&posix_mkfifo($path,0600),'Unable to create receipt FIFO fixture.');receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'FIFO receipt history did not fail before opening.');receiptCheck(unlink($path),'Unable to remove receipt FIFO fixture.');
 receiptCheck(file_put_contents($path,'{bad',LOCK_EX)===4&&chmod($path,0600),'Unable to create malformed receipt fixture.');receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Broken history was silently presented as complete.');
 receiptCheck(mkdir($root.'/announcement-jobs',0700),'Unable to create announcement-job fixture directory.');
 $bulk=[];
 for($i=0;$i<3;$i++){
   $job='job_'.str_repeat((string)($i+1),32);$bulk[$job]=$record;$bulk[$job]['channel_job_id']=$job;
   $bulk[$job]['routing_snapshot']=['webhook_ids'=>[],'email_recipients'=>[]];$bulk[$job]['destination_receipts']=[];
   $jobPath=$root.'/announcement-jobs/'.$job.'.json';$jobJson=json_encode(['id'=>$job,'request'=>['weather_context'=>['source_validity'=>$record['source_validity']]],'receipts'=>array_fill(0,80,['channel'=>'sms','state'=>'accepted'])],JSON_THROW_ON_ERROR);
   receiptCheck(file_put_contents($jobPath,$jobJson,LOCK_EX)===strlen($jobJson)&&chmod($jobPath,0600),'Unable to create protected announcement-job fixture.');
 }
 $bulkJson=json_encode(['version'=>1,'deliveries'=>$bulk],JSON_THROW_ON_ERROR);receiptCheck(file_put_contents($path,$bulkJson,LOCK_EX)===strlen($bulkJson)&&chmod($path,0600),'Unable to write bulk receipt fixture.');
 $out=WeatherDeliveryReceipts::forEvent($root,$event);
 receiptCheck(count($out['rows'])===100&&$out['warnings']!==[],'Combined SMS/call history exceeded its 100-row projection limit.');
 foreach(glob($root.'/announcement-jobs/*')as$file)unlink($file);rmdir($root.'/announcement-jobs');
 echo "$count weather delivery history checks passed.\n";
}finally{if(is_dir($root.'/announcement-jobs')){foreach(glob($root.'/announcement-jobs/*')as$file)unlink($file);rmdir($root.'/announcement-jobs');}foreach(glob($root.'/*')as$file)unlink($file);rmdir($root);}
