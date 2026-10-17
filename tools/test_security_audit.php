<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/SecurityAudit.php';
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/security.php';
use SLS\MassNotify\SecurityAudit;
$root=sys_get_temp_dir().'/sls-security-audit-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700)||!chmod($root,0700)){throw new RuntimeException('Unable to create the private security-audit fixture.');}
$checks=0;
function auditCheck(bool $ok,string $message):void{global $checks;$checks++;if(!$ok){throw new RuntimeException($message);}}
function auditFixtureWrite(string $path,string $bytes):void
{
 if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($path,0600)){throw new RuntimeException('Unable to write a private security-audit fixture.');}
}
function auditFixtureCleanup(string $root,bool $strict):void
{
 $names=scandir($root);if(!is_array($names)){if($strict){throw new RuntimeException('Unable to inspect the security-audit fixture for cleanup.');}return;}
 foreach($names as $name){if($name==='.'||$name==='..'){continue;}$path=$root.'/'.$name;if((is_file($path)||is_link($path))&&!unlink($path)&&$strict){throw new RuntimeException('Unable to remove a security-audit fixture file.');}}
 if(!rmdir($root)&&$strict){throw new RuntimeException('Unable to remove the security-audit fixture.');}
}
$passed=false;
try{
 $base=['created_at'=>'2026-10-04T00:00:00Z','ip'=>'192.0.2.1','method'=>'POST','status'=>200,'ok'=>true];
 $id='api_'.str_repeat('a',24);$human=$base+['action'=>'operator_sign_in_completed','actor'=>'operator:fixture','credential_id'=>$id];
 $key=$base+['action'=>'get_status','credential_id'=>$id];$admin=$base+['action'=>'config_export_encrypted','actor'=>'web:fixture-owner'];
 auditCheck(SecurityAudit::keyUsage($key),'Historical named API key was excluded');
 auditCheck(!SecurityAudit::keyUsage($human),'Shared api_ prefix confused operator sign-in with API key use');
 auditCheck(!SecurityAudit::keyUsage($admin),'Human config export remained API usage');
 auditCheck(!SecurityAudit::keyUsage($base+['action'=>'disabled']),'Ambiguous old API checks imply key use');
 auditCheck(SecurityAudit::keyUsage($base+['action'=>'disabled','credentials_present'=>true]),'Present invalid/disabled key was excluded');
 auditCheck(!SecurityAudit::keyUsage($base+['action'=>'disabled','credentials_present'=>false]),'Absent key appears as API usage');
 auditCheck(SecurityAudit::project($key)===null,'API-key requests appear as human activity');
 auditCheck(SecurityAudit::project($base+['action'=>'unauthorized','actor'=>'api:no-key','credentials_present'=>false])===null,'API no-key probes consume human activity');
 $path=$root.'/security-audit.jsonl';$fixtureRows=json_encode($human+['password'=>'never-copy-password','token'=>'never-copy-token'],JSON_THROW_ON_ERROR)."\n".json_encode($admin,JSON_THROW_ON_ERROR)."\n".json_encode($key,JSON_THROW_ON_ERROR)."\n";auditFixtureWrite($path,$fixtureRows);
 $activity=SecurityAudit::recent($path);auditCheck(count($activity['events'])===2,'Human activity projection admitted API rows');
 auditCheck($activity['events'][1]['operator_id']===$id&&$activity['events'][1]['actor']==='operator:fixture','Migrated attribution was lost');
 auditCheck(!str_contains(json_encode($activity,JSON_THROW_ON_ERROR),'never-copy'),'Unexpected audit fields leaked to UI');
 auditFixtureWrite($root.'/security-audit-fault.json','{"active":true,"failed_records":3}');
 auditCheck(count(SecurityAudit::recent($path)['notices'])===1,'Current security audit failure was invisible');
 auditFixtureWrite($root.'/security-audit-fault.json','{"active":false,"failed_records":3}');auditCheck(!SecurityAudit::recent($path)['notices'],'Past failure counts remain an active fault');
 // Use actual source header extraction and audit producer, not body/query stubs.
 $source=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/index.php');
 if(!is_string($source)){throw new RuntimeException('Control API source could not be read.');}
 $start=strpos($source,'function request_header_value(');$end=strpos($source,'function freepbx_module(');
 if($start===false||$end===false||$end<=$start){throw new RuntimeException('Control API audit source boundaries are missing.');}
 $functions=substr($source,$start,$end-$start);if($functions===''||$functions===false){throw new RuntimeException('Control API audit source extraction is empty.');}eval($functions);
 define('CONTROL_API_RATE_FILE',$root.'/rate.json');
 foreach(['sls_storage_maintenance.py','sls_audio_state.py','sls_config_crypto.py'] as $file){
  $body=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/'.$file);if(!is_string($body)){throw new RuntimeException('Audit helper source could not be read: '.$file);}
  if($file==='sls_storage_maintenance.py'){$count=0;$body=preg_replace('/^DATA = Path\([^\n]+\)$/m','DATA = Path('.json_encode($root,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).')',$body,1,$count);if(!is_string($body)||$count!==1){throw new RuntimeException('Audit helper data path could not be isolated.');}}
  auditFixtureWrite($root.'/'.$file,$body);
 }
 define('CONTROL_API_AUDIT_HELPER',$root.'/sls_storage_maintenance.py');
 $_SERVER=['REQUEST_METHOD'=>'GET'];$_POST=['api_key'=>'inert-body-secret'];$_GET=['api_key'=>'inert-query-secret'];
 auditCheck(provided_key()==='','Body/query key changed API audit attribution');audit_control_api('127.0.0.1','disabled',403,false);
 $_SERVER['HTTP_AUTHORIZATION']='Bearer inert-header-secret';$GLOBALS['sls_control_principal']=['id'=>'legacy'];$GLOBALS['sls_request_network']=['loopback'=>true];audit_control_api('127.0.0.1','get_status',200,true);
 unset($_SERVER['HTTP_AUTHORIZATION']);$_SERVER['HTTP_X_API_KEY']='inert-revoked-key';unset($GLOBALS['sls_control_principal']);audit_control_api('192.0.2.2','unauthorized',401,false);
 $lines=file($root.'/control-api-audit.jsonl',FILE_IGNORE_NEW_LINES);if(!is_array($lines)){throw new RuntimeException('Control API audit fixture was not written.');}
 $rows=array_map(static fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),$lines);
 auditCheck(count($rows)===3,'Key-present loopback audit was omitted');
 auditCheck($rows[0]['credentials_present']===false&&$rows[0]['actor']==='api:no-key'&&!SecurityAudit::keyUsage($rows[0]),'No-key probe appears as key use');
 auditCheck($rows[1]['credentials_present']===true&&$rows[1]['credential_id']==='legacy'&&SecurityAudit::keyUsage($rows[1]),'Loopback real key usage was excluded');
 auditCheck($rows[2]['credentials_present']===true&&!isset($rows[2]['credential_id'])&&SecurityAudit::keyUsage($rows[2]),'Present invalid/revoked key lost failure attribution');
 $auditBytes=file_get_contents($root.'/control-api-audit.jsonl');auditCheck(is_string($auditBytes)&&!str_contains($auditBytes,'secret')&&!str_contains($auditBytes,'inert-revoked-key'),'Raw supplied credentials reached audit');
 auditCheck(count(SecurityAudit::recent($path)['events'])===2,'No-key API activity reached the human log');
 $passed=true;echo "$checks key-only Help projection, human activity, legacy attribution, header-only presence and fault visibility checks passed.\n";
}finally{auditFixtureCleanup($root,$passed);}
