<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=0;
$cleanEnvironment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
function operatorHttpCheck(bool $value,string $message):void{global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
$fixture= <<<'PHP'
$request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$_SERVER['REQUEST_METHOD']='POST';$_POST=$request['post'];
function _($text){return $text;}
class OperatorHttpFixture {
 public function enforceOperatorPageAccess($page){if(empty($GLOBALS['request']['allowed'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Role denied']);exit;}}
 public function validateCsrfToken($token){return is_string($token)&&hash_equals('fixture-csrf',$token);}
 public function operatorAction($action,$input){if($action==='domain_failure')throw new DomainException('Reviewed input conflict.');if($action==='runtime_failure')throw new RuntimeException('private fixture detail');return ['success'=>true,'called'=>'action','action'=>$action,'input'=>$input];}
 public function saveOperatorAccess($input){return ['success'=>true,'called'=>'save','input'=>$input];}
 public function manageOperatorPasswordReset($action,$input){return ['success'=>true,'called'=>'reset','action'=>$action,'input'=>$input];}
 public function __call($name,$args){$input=$args[0]??null;if(is_array($input)&&($input['fixture_failure']??'')==='domain')throw new DomainException('Reviewed incident conflict.');if(is_array($input)&&($input['fixture_failure']??'')==='runtime')throw new RuntimeException('private incident detail');return ['success'=>true,'called'=>$name,'input'=>$input];}
}
class FreePBX {public static function create(){return(object)['Slsmassnotifyserver'=>new OperatorHttpFixture()];}}
register_shutdown_function(static function(){fwrite(STDERR,':STATUS:'.(http_response_code()?:200));});
PHP;
$invoke=static function(string $page,array $post,bool $allowed=true)use($fixture,$root,$cleanEnvironment):array{
 $code=$fixture.'require '.var_export($root.'/slsmassnotifyserver/page.slsmassnotifyserver_'.$page.'.php',true).';';
 $p=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2s','10s','/usr/bin/php','-n','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$cleanEnvironment,['bypass_shell'=>true]);
 if(!is_resource($p)){throw new RuntimeException('Unable to start the isolated operator controller fixture.');}
 $request=json_encode(['post'=>$post,'allowed'=>$allowed],JSON_THROW_ON_ERROR);$written=0;
 while($written<strlen($request)){$count=fwrite($pipes[0],substr($request,$written));if(!is_int($count)||$count<1){break;}$written+=$count;}
 $inputClosed=fclose($pipes[0]);
 if($written!==strlen($request)||!$inputClosed){proc_terminate($p);proc_close($p);throw new RuntimeException('Unable to write the operator controller fixture request.');}
 $out=stream_get_contents($pipes[1],1048577);$outClosed=fclose($pipes[1]);$err=stream_get_contents($pipes[2],1048577);$errClosed=fclose($pipes[2]);
 if(!is_string($out)||strlen($out)>1048576||!$outClosed||!is_string($err)||strlen($err)>1048576||!$errClosed){proc_terminate($p);proc_close($p);throw new RuntimeException('Unable to read the bounded operator controller fixture response.');}
 $exit=proc_close($p);
 operatorHttpCheck($exit===0&&preg_match('/^:STATUS:([0-9]{3})$/D',$err,$match)===1,'Unexpected controller error (exit '.$exit.'): '.$err);
 return [(int)$match[1],json_decode($out,true,16,JSON_THROW_ON_ERROR)];
};
foreach(['operations'=>'preview','operators'=>'save_access']as$page=>$action){
 $post=['slsmassnotifyserver_action'=>$action,'payload'=>'{"fixture":"reviewed"}'];
 [$status,$out]=$invoke($page,$post);operatorHttpCheck($status===403&&!isset($out['called']),'Missing CSRF reached an operation.');
 $post['slsmassnotifyserver_csrf']='fixture-csrf';[$status,$out]=$invoke($page,$post,false);operatorHttpCheck($status===403&&!isset($out['called']),'Role rejection reached an operation.');
 [$status,$out]=$invoke($page,$post);operatorHttpCheck($status===200&&$out['input']['fixture']==='reviewed','Valid controller request was altered.');
 foreach(['{bad','[]','null',str_repeat('x',131073),['bad']]as$raw){[$status,$out]=$invoke($page,array_replace($post,['payload'=>$raw]));operatorHttpCheck($status===400&&!isset($out['called']),'Invalid payload reached an operation.');}
 foreach ([['slsmassnotifyserver_csrf'=>[]], ['slsmassnotifyserver_action'=>[]]] as $malformed) { [$status,$out]=$invoke($page,array_replace($post,$malformed));operatorHttpCheck(in_array($status,[400,403],true)&&!isset($out['called']),'Malformed controller field reached an operation.'); }
}
foreach ([['domain_failure',422,'Reviewed input conflict.'],['runtime_failure',503,'The operation could not confirm completion.']] as [$action,$expected,$message]) {
 $post=['slsmassnotifyserver_action'=>$action,'slsmassnotifyserver_csrf'=>'fixture-csrf','payload'=>'{}'];
 [$status,$out]=$invoke('operations',$post);operatorHttpCheck($status===$expected&&str_contains($out['message'],$message)&&!str_contains($out['message'],'private fixture detail'),'Operations failure response was missing, unbounded, or disclosed internals.');
}
foreach(['generate_reset_link','revoke_reset_link']as$action){
 $post=['slsmassnotifyserver_action'=>$action,'slsmassnotifyserver_csrf'=>'fixture-csrf','payload'=>json_encode(['id'=>'api_'.str_repeat('a',24)])];
 [$status,$out]=$invoke('operators',$post);operatorHttpCheck($status===200&&$out['called']==='reset'&&$out['action']===$action,'Administrator reset action was not dispatched.');
 [$status,$out]=$invoke('operators',array_replace($post,['slsmassnotifyserver_csrf'=>'invalid']));operatorHttpCheck($status===403&&!isset($out['called']),'Reset action bypassed CSRF.');
 [$status,$out]=$invoke('operators',$post,false);operatorHttpCheck($status===403&&!isset($out['called']),'Reset action bypassed role guard.');
}
foreach(['approval_list','drill_report']as$action){
 $post=['slsmassnotifyserver_action'=>$action,'slsmassnotifyserver_csrf'=>'fixture-csrf','payload'=>'{}'];
 [$status,$out]=$invoke('operations',$post);operatorHttpCheck($status===200&&$out['called']==='action'&&$out['action']===$action&&$out['input']===[],'An empty enterprise object request was rejected.');
 [$status,$out]=$invoke('operations',array_replace($post,['slsmassnotifyserver_csrf'=>'invalid']));operatorHttpCheck($status===403&&!isset($out['called']),'Empty enterprise action bypassed CSRF.');
}
foreach ([
 ['start_incident','{"fixture":"reviewed"}',200,'startIncident'],
 ['start_incident','{"fixture":"first","fixture":"second"}',400,''],
 ['start_incident','{"fixture_failure":"domain"}',422,''],
 ['start_incident','{"fixture_failure":"runtime"}',503,''],
] as [$action,$payload,$expected,$called]) {
 $post=['slsmassnotifyserver_action'=>$action,'slsmassnotifyserver_csrf'=>'fixture-csrf','payload'=>$payload];
 [$status,$out]=$invoke('incidents',$post);operatorHttpCheck($status===$expected&&($called===''?!isset($out['called']):($out['called']??'')===$called)&&!str_contains($out['message']??'','private incident detail'),'Incident controller boundary failed or disclosed runtime details.');
}
foreach ([['slsmassnotifyserver_csrf'=>[]],['slsmassnotifyserver_action'=>[]]] as $malformed) {
 $post=array_replace(['slsmassnotifyserver_action'=>'start_incident','slsmassnotifyserver_csrf'=>'fixture-csrf','payload'=>'{}'],$malformed);
 [$status,$out]=$invoke('incidents',$post);operatorHttpCheck(in_array($status,[400,403],true)&&!isset($out['called']),'Malformed incident controller field reached a service method.');
}
echo "$checks operator controller CSRF, role guard and request-bound checks passed.\n";
