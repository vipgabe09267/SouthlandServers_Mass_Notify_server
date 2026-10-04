<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/DeploymentReadiness.php';
use FreePBX\modules\SlsDeploymentReadiness as Readiness;
$checks=0;
function check($ok,string $message): void { $GLOBALS['checks']++; if(!$ok)throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/sls-readiness-'.bin2hex(random_bytes(8));mkdir($root,0700);
register_shutdown_function(static function()use($root){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);});
$path=$root.'/health.json';$now=1800000000;
check(Readiness::snapshot($path)['error']==='missing','Missing snapshot was treated as healthy.');
file_put_contents($path,json_encode(['checked_at'=>$now,'private_extra'=>'never projected']));chmod($path,0600);
$before=[hash_file('sha256',$path),fileinode($path),filemtime($path)];
$snapshot=Readiness::snapshot($path);
$report=Readiness::heartbeat('worker','Worker',$snapshot,300,$now);
check($report['state']==='ok'&&$report['age_seconds']===0,'Fresh heartbeat failed.');
check(strpos(json_encode($report),'private_extra')===false,'Private snapshot fields were projected.');
check($before===[hash_file('sha256',$path),fileinode($path),filemtime($path)],'Snapshot reading changed state.');
foreach([['checked_at'=>$now-301],['checked_at'=>$now+61],['checked_at'=>false],['checked_at'=>'2026-01-01 00:00:00']]as $row){
 check(Readiness::heartbeat('worker','Worker',['data'=>$row],300,$now)['state']==='warning','Invalid/stale/future heartbeat passed.');
}
check(Readiness::heartbeat('worker','Worker',['data'=>['checked_at'=>gmdate('c',$now)]],300,$now)['state']==='ok','Timezone-aware heartbeat rejected.');
$lock=fopen($path,'r+');flock($lock,LOCK_EX);$start=microtime(true);
check(Readiness::snapshot($path)['error']==='busy'&&microtime(true)-$start<0.5,'Reader blocked behind writer.');fclose($lock);
file_put_contents($path,'{broken');check(Readiness::snapshot($path)['error']==='invalid','Corrupt JSON accepted.');
file_put_contents($path,str_repeat('x',65537));check(Readiness::snapshot($path)['error']==='unsafe','Oversized snapshot accepted.');
unlink($path);posix_mkfifo($path,0600);$start=microtime(true);
check(Readiness::snapshot($path)['error']==='unsafe'&&microtime(true)-$start<0.5,'FIFO blocked the reader.');unlink($path);
file_put_contents($root.'/target','{}');symlink($root.'/target',$path);
check(Readiness::snapshot($path)['error']==='unsafe','Symlink was followed.');unlink($path);link($root.'/target',$path);
check(Readiness::snapshot($path)['error']==='unsafe','Hardlink accepted.');unlink($path);
check(Readiness::clockResult("NTP=yes\nNTPSynchronized=yes",0)['state']==='ok','Synchronized clock not recognized.');
check(Readiness::clockResult("NTP=yes\nNTPSynchronized=no",0)['state']==='warning','Unsynchronized clock passed.');
check(Readiness::clockResult('NTP=yes',0)['state']==='unknown','Missing synchronization evidence passed.');
check(Readiness::clockResult('NTPSynchronized=yes',1)['state']==='unknown','Failed clock command passed.');
foreach([[31*86400,'ok'],[30*86400,'warning'],[1,'warning'],[-1,'warning']]as [$remaining,$expected]){
 $result=Readiness::certificate(['ok'=>true,'certificate_expires_at'=>$now+$remaining],$now);
 check($result['state']===$expected&&$result['seconds_remaining']===$remaining,'Certificate boundary is wrong.');
}
check(Readiness::certificate(['ok'=>true],$now)['state']==='unknown','Missing certificate expiry passed.');
check(Readiness::certificate(['ok'=>false,'certificate_expires_at'=>$now+90*86400],$now)['state']==='warning','Failed TLS verification passed on expiry alone.');
check(Readiness::capacity(['schema'=>1,'errors'=>[]])['state']==='ok','Measured capacity rejected.');
check(Readiness::capacity([])['state']==='unknown','Missing capacity evidence passed.');
check(Readiness::capacity(['errors'=>[['message'=>'Measurement unavailable.']]])['state']==='warning','Failed capacity probe passed.');
check(Readiness::capacity(['schema'=>1,'errors'=>[['message'=>'Need 200 MiB more free space.']]])['detail']==='Need 200 MiB more free space.','Actionable shortage was lost.');
foreach ([
 [['data'=>['audit_failed_records'=>2]],false,['warning','ok']],
 [['data'=>['audit_failed_records'=>2,'audit_fault_active'=>false]],false,['ok','ok']],
 [['data'=>['audit_at_capacity'=>true]],false,['warning','ok']],
 [['error'=>'busy'],true,['warning','warning']],
 [['data'=>['audit_forwarding_enabled'=>true]],true,['ok','unknown']],
 [['data'=>['audit_forwarding_enabled'=>true,'audit_forwarding_active'=>true]],true,['ok','warning']],
 [['data'=>['audit_forwarding_enabled'=>true,'audit_forwarding_last_success_at'=>$now]],true,['ok','ok']],
] as [$snapshot,$enabled,$expected]) {
 check(array_column(Readiness::audit($snapshot,$enabled),'state')===$expected,'Audit recovery or forwarding evidence misreported.');
}
require dirname(__DIR__).'/slsmassnotifyserver/StatusHealth.php';
$history=['expired_external'=>2,'failed_weather'=>2,'uncertain_weather'=>1,'expired_weather'=>1,'weather_deadline_misses'=>1];
$recent=array_combine(array_map(static function($key){return 'recent_'.$key;},array_keys($history)),array_fill(0,count($history),0));
check(\FreePBX\modules\SlsStatusHealth::queueFaults($history)==['external'=>true,'weather'=>true],'Legacy queue evidence silently cleared.');
check(\FreePBX\modules\SlsStatusHealth::queueFaults($history+$recent)==['external'=>false,'weather'=>false],'Historical failure kept a recovered queue warning active.');
foreach(array_keys($history)as $key){
 $current=$history+$recent;$current['recent_'.$key]=1;
 check(in_array(true,\FreePBX\modules\SlsStatusHealth::queueFaults($current),true),'Recent queue failure hidden.');
}
check(\FreePBX\modules\SlsStatusHealth::queueFaults($history+$recent+['queue_errors'=>1])['external'],'Unreadable queue warning cleared.');

require dirname(__DIR__).'/slsmassnotifyserver/SupportDiagnostics.php';
// The real aggregate method reads private fixtures. Clock/TLS probes are inert;
// a live AMI scan or any unplanned method invocation makes this fixture fail.
eval('namespace FreePBX\\modules;
 function exec($command,&$output,&$status){$output=["NTPSynchronized=yes","NTP=yes"];$status=0;}
 class SlsAdvertisedAddress{static function checkLocalHttps($settings){return ["ok"=>true,"certificate_expires_at"=>time()+45*86400];}}
 class SlsLocalWebProbe{static function mediaAccess($settings){return ["ok"=>true,"message"=>"Private media fixture verified."];}}
 class ReadinessFixture{use SlsSupportDiagnostics; const PLUGIN_DATA_DIR='.var_export($root,true).';const SETTINGS_JSON='.var_export($root.'/active.json',true).';const MODULE_VERSION="0.1.5-beta";
 function getDiagnosticsSummary($devices=true){if($devices!==false)throw new \\RuntimeException("AMI scan requested");return ["checks"=>[["ok"=>true,"label"=>"Runtime","detail"=>"Available"]]];}
 function checkDesktopCapacityResources($desktops,$phones){if($desktops!==25||$phones!==25)throw new \\RuntimeException("Wrong capacity");return ["schema"=>1,"errors"=>[]];}
 function automationDependencyChecks($settings){return [];}
 public array $settings=[]; public string $role="administrator";
 function currentOperator(){return ["operator_role"=>$this->role];}
 function deviceAcceptanceConfiguration($path){if($path!==self::SETTINGS_JSON)throw new \\RuntimeException("Unexpected configuration path");return ["settings"=>$this->settings];}
 function getActiveSettings(){return $this->settings;}}
');
mkdir($root.'/announcement-jobs',0700);
file_put_contents($root.'/storage-summary.json',json_encode(['checked_at'=>time()]));chmod($root.'/storage-summary.json',0600);
file_put_contents($root.'/announcement-jobs/worker-probe.json',json_encode(['checked_at'=>gmdate('c')]));chmod($root.'/announcement-jobs/worker-probe.json',0600);
$module=new \FreePBX\modules\ReadinessFixture();$report=$module->getDeploymentReadiness();
check($report['operational_ready']===true&&count($report['checks'])===9,'Real report aggregation failed.');
file_put_contents($root.'/storage-summary.json',json_encode(['checked_at'=>time()-1000]));
$before=hash_file('sha256',$root.'/storage-summary.json');$report=$module->getDeploymentReadiness();
check($report['operational_ready']===false&&$report['attention_count']===1,'Stopped maintenance was not detected independently.');
check($before===hash_file('sha256',$root.'/storage-summary.json'),'Report repaired or rewrote the missing heartbeat.');
// The aggregate/export is a separate read path from the administrator form.
$_SESSION['AMP_user']=(object)['username'=>'fixture'];
check(isset($module->getDeploymentReadiness()['device_acceptance']),'Administrator lost the acceptance export.');
$module->role='viewer';
check(!isset($module->getDeploymentReadiness()['device_acceptance']),'Viewer obtained private device observations through readiness.');
unset($_SESSION['AMP_user']);
require_once dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/security.php';
$issued=\SLS\MassNotify\ApiSecurity::issue(['name'=>'Readiness monitor','scopes'=>['read'],'audience'=>['unrestricted'=>true]]);
$module->settings['control_api']=['enabled'=>'1','credentials'=>[$issued['credential']]];
$GLOBALS['sls_control_principal']=['id'=>$issued['credential']['id']];
check(isset($module->getDeploymentReadiness()['device_acceptance']),'Current named unrestricted monitor lost acceptance observations.');
$module->settings['control_api']['credentials'][0]['enabled']=false;
check(!isset($module->getDeploymentReadiness()['device_acceptance']),'Revoked monitor retained private readiness observations.');
$GLOBALS['sls_control_principal']=['id'=>'legacy'];
check(!isset($module->getDeploymentReadiness()['device_acceptance']),'Legacy health credential obtained private observations.');
unset($GLOBALS['sls_control_principal']);

// Exercise the actual authenticated controller, without FreePBX construction.
$page=dirname(__DIR__).'/slsmassnotifyserver/page.slsmassnotifyserver.php';
foreach([['POST',true,false,200,1],['POST',false,false,403,0],['POST',true,true,503,1],['GET',true,false,200,0]]as [$method,$csrf,$failure,$expected,$calls]){
 $prefix='<?php class FreePBX{static function create(){return (object)["Slsmassnotifyserver"=>new Fixture];}}
 class Fixture{function enforceOperatorPageAccess($page){}function validateCsrfToken($value){return '.var_export($csrf,true).';}
 function getDeploymentReadiness(){$GLOBALS["calls"]++;'.($failure?'throw new RuntimeException("private failure");':'return ["schema"=>"sls-deployment-readiness-v1"];').'}
 function showPage($view,$params){return "normal page";}function __call($name,$args){throw new RuntimeException("Unexpected mutation: ".$name);}}
 $GLOBALS["calls"]=0;$_SESSION=[];$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';
 $_POST=["slsmassnotifyserver_action"=>"deployment_readiness","slsmassnotifyserver_csrf"=>"fixture"];
 $_REQUEST=$_POST;register_shutdown_function(static function(){file_put_contents("php://stderr",json_encode([http_response_code()?:200,$GLOBALS["calls"]]));});require '.var_export($page,true).';';
 $process=proc_open([PHP_BINARY],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fwrite($pipes[0],$prefix);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);fclose($pipes[1]);$meta=stream_get_contents($pipes[2]);fclose($pipes[2]);
 check(proc_close($process)===0&&json_decode($meta,true)===[$expected,$calls],'Readiness controller bypassed method/CSRF or failed: '.$meta);
 check(strpos($body,'private failure')===false,'Private exception escaped controller.');
}
echo "Deployment readiness: $checks snapshot, clock, certificate and authenticated controller checks passed.\n";
