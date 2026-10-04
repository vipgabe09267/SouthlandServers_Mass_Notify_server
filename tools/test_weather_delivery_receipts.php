<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/WeatherDeliveryReceipts.php';
use SLS\MassNotify\WeatherDeliveryReceipts;
$root=sys_get_temp_dir().'/sls-weather-receipts-'.bin2hex(random_bytes(8));mkdir($root,0700);$count=0;
function receiptCheck(bool $value,string $message):void{global $count;$count++;if(!$value)throw new RuntimeException($message);}
$path=$root.'/external-deliveries-nws_fixture.json';
$record=['payload'=>['source'=>'nws','event_id'=>'alert-fixture','body'=>'private body'], 'source_validity'=>['zone'=>'TXZ163','group_id'=>'nws_fixture'],
 'routing_snapshot'=>['webhook_ids'=>['discord:discord_fixture'],'email_recipients'=>['private@example.org'],'webhook_fingerprints'=>['discord:discord_fixture'=>'secret']],
 'destination_receipts'=>['discord:discord_fixture'=>['status'=>'accepted','http_status'=>204,'error'=>'','recorded_at'=>1000]], 'terminal_status'=>'complete','completed_at'=>1001];
$write=static function(array $record)use($path):void{file_put_contents($path,json_encode(['version'=>1,'deliveries'=>['fixture'=>$record]],JSON_THROW_ON_ERROR));chmod($path,0600);};
$event=['type'=>'nws','alert_id'=>'alert-fixture','zone'=>'TXZ163'];
try{
 $write($record);$out=WeatherDeliveryReceipts::forEvent($root,$event,['nws_zones'=>[['id'=>'nws_fixture','name'=>'Main site']]]);
 receiptCheck(count($out['rows'])===2 && $out['rows'][0]['status']==='accepted' && $out['rows'][0]['http_status']===204 && $out['rows'][0]['group']==='Main site','Confirmed Discord result missing.');
 receiptCheck($out['rows'][1]['status']==='not_recorded','Historical mail acceptance was invented.');
 receiptCheck(!str_contains(json_encode($out),'private@example.org')&&!str_contains(json_encode($out),'secret')&&!str_contains(json_encode($out),'private body'),'Receipt projection exposed protected data.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,$event+['unused'=>1])['warnings'],'Valid receipt storage raised a warning.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,array_replace($event,['alert_id'=>'other']))['rows'],'Another announcement received these receipts.');
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,array_replace($event,['zone'=>'TXZ999']))['rows'],'Another weather zone received these receipts.');
 $changed=$record;$changed['destination_receipts']['discord:discord_fixture']=['status'=>'failed','http_status'=>429,'error'=>'http_rate_limited','recorded_at'=>1000];$changed['terminal_status']='expired';$write($changed);
 $row=WeatherDeliveryReceipts::forEvent($root,$event)['rows'][0];receiptCheck($row['status']==='failed'&&$row['http_status']===429&&$row['queue_state']==='expired','Expired delivery lost its failed transport evidence.');
 $changed['destination_receipts']['discord:discord_fixture']['error']='https://secret.invalid/token';$write($changed);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['rows'][0]['error']==='','Unsafe transport message escaped the projection.');
 $changed['routing_snapshot']['webhook_ids']='invalid';$write($changed);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Malformed destination evidence caused an unhandled UI error.');
 $write($record);$handle=fopen($path,'r+b');flock($handle,LOCK_EX);$start=microtime(true);
 receiptCheck(!WeatherDeliveryReceipts::forEvent($root,$event)['rows']&&WeatherDeliveryReceipts::forEvent($root,$event)['warnings']&&microtime(true)-$start<0.5,'Busy receipt storage blocked the UI or presented unconfirmed evidence.');fclose($handle);
 receiptCheck(count(WeatherDeliveryReceipts::forEvent($root,$event)['rows'])===2,'Receipt storage did not recover after its writer released the lock.');
 link($path,$root.'/linked-copy');receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Hard-linked receipt evidence was accepted.');unlink($root.'/linked-copy');
 for($i=0;$i<600;$i++)file_put_contents($root.'/unrelated-'.$i,'');$start=microtime(true);
 receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[]&&microtime(true)-$start<0.5,'Large history directory was not bounded or reported.');
 foreach(glob($root.'/unrelated-*')as$file)unlink($file);
 unlink($path);symlink('/etc/passwd',$path);receiptCheck(!WeatherDeliveryReceipts::forEvent($root,$event)['rows']&&WeatherDeliveryReceipts::forEvent($root,$event)['warnings'],'Unsafe receipt link was accepted.');unlink($path);
 posix_mkfifo($path,0600);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'FIFO receipt history did not fail before opening.');unlink($path);
 file_put_contents($path,'{bad');chmod($path,0600);receiptCheck(WeatherDeliveryReceipts::forEvent($root,$event)['warnings']!==[],'Broken history was silently presented as complete.');
 echo "$count weather delivery history checks passed.\n";
}finally{foreach(glob($root.'/*')as$file)unlink($file);rmdir($root);}
