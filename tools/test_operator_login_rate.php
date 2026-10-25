<?php
declare(strict_types=1);
namespace SLS\MassNotify {
 // Model lstat resolving an inode before a legitimate replacement, then
 // obtaining its attributes after that inode has been retired.
 function lstat(string $path) {
  $meta=\lstat($path);$probe=$GLOBALS['operatorRateRetirementProbe']??null;
  if(!$meta||!is_array($probe)||$path!==$probe['path'])return $meta;
  $lock=\fopen($path.'.lock','r+b');if(!$lock)throw new \RuntimeException('Retirement probe lock unavailable');
  try{
   if(!\flock($lock,LOCK_EX|LOCK_NB))return $meta;
   try{
    $original=\fopen($path,'rb');if(!$original)throw new \RuntimeException('Retirement probe data unavailable');
    try{
     $bytes=\stream_get_contents($original);$temporary=$path.'.replacement';
     if(!is_string($bytes)||\file_put_contents($temporary,$bytes)!==strlen($bytes)
       ||!\chmod($temporary,0640)||!\rename($temporary,$path))throw new \RuntimeException('Retirement probe replacement failed');
     $meta=\fstat($original);$GLOBALS['operatorRateRetirementProbe']['observations']++;
    }finally{\fclose($original);}
   }finally{\flock($lock,LOCK_UN);}
  }finally{\fclose($lock);}
  return $meta;
 }
}
namespace {
require dirname(__DIR__).'/slsmassnotifyserver/OperatorPortal.php';
use SLS\MassNotify\{OperatorLoginRate as Rate,OperatorPortal as Portal};
$checks=0;
function rateCheck(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function denied(callable $work,string $message):void{try{$work();}catch(Throwable $e){rateCheck($e instanceof RuntimeException || $e instanceof JsonException,$message);return;}throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/sls-operator-rate-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $path=$root.'/rate.json';$ip='192.0.2.10';$now=100000;
 $fail=static function(int $at)use($path,$ip):void{$g=Rate::begin($path,$ip,$at);rateCheck($g['retry_after']===0,'Expected password attempt denied');Rate::finish($path,$ip,$g['reservation'],true,$at);};
 for($i=0;$i<6;$i++)$fail($now);
 rateCheck(Rate::begin($path,$ip,$now+1)['retry_after']===299,'Six failures did not enforce five-minute window');
 for($i=0;$i<6;$i++)$fail($now+300);
 rateCheck(Rate::begin($path,$ip,$now+301)['retry_after']===299,'Twelve failures did not enforce ten-minute window');
 for($i=0;$i<6;$i++)$fail($now+600);
 for($i=0;$i<2;$i++)$fail($now+900);
 rateCheck(Rate::begin($path,$ip,$now+901)['retry_after']===86399,'Twenty failures did not start a 24-hour lock');
 $before=json_decode(file_get_contents($path),true);Rate::begin($path,$ip,$now+902);
 $after=json_decode(file_get_contents($path),true);
 rateCheck(array_values($before['ips'])[0]['locked_until']===array_values($after['ips'])[0]['locked_until'],'Blocked request extended daily lock');
 rateCheck(Rate::begin($path,'192.0.2.11',$now+902)['retry_after']===0,'Another IP inherited lock');
 Rate::recovered($path,$ip,$now+903);$g=Rate::begin($path,$ip,$now+903);
 rateCheck($g['retry_after']===0,'Verified recovery failed to clear daily lock');
 Rate::finish($path,$ip,$g['reservation'],false,$now+903);
 for($i=0;$i<30;$i++){$g=Rate::begin($path,$ip,$now+903);Rate::finish($path,$ip,$g['reservation'],false,$now+903);}
 rateCheck(array_values(json_decode(file_get_contents($path),true)['ips'])[0]['failures']===[],'Successful password checks counted as failed passwords');
 rateCheck(!str_contains(file_get_contents($path),$ip),'Raw IP stored in failure ledger');
 rateCheck((fileperms($path)&0777)===0640&&(fileperms($path.'.lock')&0777)===0640,'Failure storage permissions are not private');
 denied(static fn()=>Rate::begin($path,$ip,$now),'Large clock rollback accepted');
 $pending=[];for($i=0;$i<6;$i++)$pending[]=Rate::begin($path,'2001:db8::1',$now+904)['reservation'];
 rateCheck(Rate::begin($path,'2001:0db8:0:0:0:0:0:1',$now+904)['retry_after']>0,'Equivalent IPv6 or inflight checks bypassed limit');
 Rate::recovered($path,'2001:db8::1',$now+904);
 foreach($pending as$id)Rate::finish($path,'2001:db8::1',$id,true,$now+904);
 rateCheck(Rate::begin($path,'2001:db8::1',$now+905)['retry_after']===299,'Recovery erased in-flight reservations');
 denied(static fn()=>Rate::finish($path,$ip,str_repeat('a',32),true,$now+905),'Unknown reservation accepted');
 $g=Rate::begin($path,'192.0.2.99',$now+906);Rate::begin($path,'192.0.2.99',$now+966);
 denied(static fn()=>Rate::finish($path,'192.0.2.99',$g['reservation'],true,$now+966),'Expired reservation accepted');
 // Finish-only and corrupt records cannot silently reset protection.
 $saved=file_get_contents($path);file_put_contents($path,'{bad');
 denied(static fn()=>Rate::begin($path,$ip,$now+970),'Corrupt ledger accepted');rateCheck(file_get_contents($path)==='{bad','Corrupt ledger was overwritten');file_put_contents($path,$saved);
 foreach(['file','lock']as$type){$target=$type==='file'?$path:$path.'.lock';
  link($target,$root.'/hardlink');denied(static fn()=>Rate::begin($path,$ip,$now+970),'Hard link accepted');unlink($root.'/hardlink');
  chmod($target,0660);denied(static fn()=>Rate::begin($path,$ip,$now+970),'Writable file accepted');chmod($target,0640);
  rename($target,$root.'/safe');symlink($root.'/safe',$target);denied(static fn()=>Rate::begin($path,$ip,$now+970),'Symlink accepted');unlink($target);rename($root.'/safe',$target);
  $original=file_get_contents($target);$oversized=str_repeat('x',($type==='file'?524288:32)+1);file_put_contents($target,$oversized);
  denied(static fn()=>Rate::begin($path,$ip,$now+970),'Oversized storage accepted');rateCheck(file_get_contents($target)===$oversized,'Rejected oversized storage was changed');file_put_contents($target,$original);
  if(posix_geteuid()===0){chown($target,1);denied(static fn()=>Rate::begin($path,$ip,$now+970),'Unexpected owner accepted');rateCheck(file_get_contents($target)===$original,'Rejected foreign-owned storage was changed');chown($target,0);}
 }
 chmod($root,0770);denied(static fn()=>Rate::begin($path,$ip,$now+970),'Writable parent accepted');chmod($root,0700);
 // An unlocked observer can see the retired inode's zero link count. The
 // limiter must inspect the current data only while owning its permanent lease.
 $retirement=$root.'/retirement.json';$grant=Rate::begin($retirement,$ip,$now+971);Rate::finish($retirement,$ip,$grant['reservation'],false,$now+971);
 $lockIdentity=stat($retirement.'.lock');$GLOBALS['operatorRateRetirementProbe']=['path'=>$retirement,'observations'=>0];
 $retired=\SLS\MassNotify\lstat($retirement);
 rateCheck($retired['nlink']===0&&$GLOBALS['operatorRateRetirementProbe']['observations']===1,'Retired-inode control did not reproduce an atomic replacement');
 $GLOBALS['operatorRateRetirementProbe']['observations']=0;
 $grant=Rate::begin($retirement,$ip,$now+972);rateCheck($grant['retry_after']===0,'Legitimate replacement prevented login admission');
 Rate::finish($retirement,$ip,$grant['reservation'],false,$now+972);
 rateCheck($GLOBALS['operatorRateRetirementProbe']['observations']===0,'Limiter inspected replaceable data without its permanent lease');
 clearstatcache(true,$retirement.'.lock');$afterLock=stat($retirement.'.lock');
 rateCheck($lockIdentity['dev']===$afterLock['dev']&&$lockIdentity['ino']===$afterLock['ino'],'Atomic replacement changed the permanent lock');
 unset($GLOBALS['operatorRateRetirementProbe']);
 $expiry=$root.'/expiry.json';for($i=0;$i<20;$i++){$at=$now+intdiv($i,6)*300;$g=Rate::begin($expiry,$ip,$at);Rate::finish($expiry,$ip,$g['reservation'],true,$at);}
 rateCheck(Rate::begin($expiry,$ip,$now+900+86400)['retry_after']===0,'Expired daily lock survived');
 // Concurrent workers reserve before expensive password hashing.
 $concurrent=$root.'/concurrent.json';$children=[];
 for($i=0;$i<12;$i++){
  $pid=pcntl_fork();if($pid===0){try{$g=Rate::begin($concurrent,$ip,$now);if(file_put_contents($root.'/worker-'.$i,json_encode($g))===false)throw new RuntimeException('Worker result write failed');exit(0);}catch(Throwable $error){fwrite(STDERR,'Concurrent limiter worker: '.$error->getMessage()."\n");exit(1);}}if($pid<0)throw new RuntimeException('Fork failed');$children[]=$pid;
 }
 $statuses=[];foreach($children as$pid){pcntl_waitpid($pid,$status);$statuses[]=$status;}
 foreach($statuses as$status)rateCheck(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Concurrent limiter worker failed');
 $grants=[];for($i=0;$i<12;$i++){$g=json_decode(file_get_contents($root.'/worker-'.$i),true);if(!$g['retry_after'])$grants[]=$g;}
 rateCheck(count($grants)===6,'Parallel workers bypassed six-attempt budget');foreach($grants as$g)Rate::finish($concurrent,$ip,$g['reservation'],true,$now);
 rateCheck(count(array_values(json_decode(file_get_contents($concurrent),true)['ips'])[0]['failures'])===6,'Concurrent failures were lost');
 // Recovery clears old account/IP backstops, retaining the fleet-wide budget.
 $general=$root.'/general.json';for($i=0;$i<10;$i++)Portal::loginAttempt($general,$ip,'owner',$now);
 rateCheck(Portal::loginAttempt($general,$ip,'owner',$now+1)>0,'Account backstop absent');
 Portal::recoveredLogin($general,$ip,'owner',$now+1);rateCheck(Portal::loginAttempt($general,$ip,'owner',$now+1)===0,'Verified recovery did not clear short backstop');
 $state=json_decode(file_get_contents($general),true);rateCheck(($state['global']['count']??0)===11,'Recovery cleared global budget');
 echo "$checks durable password-limit, expiry, recovery, protected storage and concurrency checks passed.\n";
}finally{foreach(glob($root.'/*')as$f)unlink($f);rmdir($root);}
}
