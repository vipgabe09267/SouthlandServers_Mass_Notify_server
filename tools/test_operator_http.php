<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=0;
function operatorHttpCheck(bool $value,string $message):void{global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
$fixture= <<<'PHP'
$request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$_SERVER['REQUEST_METHOD']='POST';$_POST=$request['post'];
class OperatorHttpFixture {
 public function enforceOperatorPageAccess($page){if(empty($GLOBALS['request']['allowed'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Role denied']);exit;}}
 public function validateCsrfToken($token){return is_string($token)&&hash_equals('fixture-csrf',$token);}
 public function operatorAction($action,$input){return ['success'=>true,'called'=>'action','action'=>$action,'input'=>$input];}
 public function saveOperatorAccess($input){return ['success'=>true,'called'=>'save','input'=>$input];}
 public function manageOperatorPasswordReset($action,$input){return ['success'=>true,'called'=>'reset','action'=>$action,'input'=>$input];}
}
class FreePBX {public static function create(){return(object)['Slsmassnotifyserver'=>new OperatorHttpFixture()];}}
register_shutdown_function(static function(){fwrite(STDERR,':STATUS:'.(http_response_code()?:200));});
PHP;
$invoke=static function(string $page,array $post,bool $allowed=true)use($fixture,$root):array{
 $code=$fixture.'require '.var_export($root.'/slsmassnotifyserver/page.slsmassnotifyserver_'.$page.'.php',true).';';
 $p=proc_open([PHP_BINARY,'-n','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fwrite($pipes[0],json_encode(['post'=>$post,'allowed'=>$allowed],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 operatorHttpCheck(proc_close($p)===0&&preg_match('/^:STATUS:([0-9]{3})$/D',$err,$match)===1,'Unexpected controller error: '.$err);
 return [(int)$match[1],json_decode($out,true,16,JSON_THROW_ON_ERROR)];
};
foreach(['operations'=>'preview','operators'=>'save_access']as$page=>$action){
 $post=['slsmassnotifyserver_action'=>$action,'payload'=>'{"fixture":"reviewed"}'];
 [$status,$out]=$invoke($page,$post);operatorHttpCheck($status===403&&!isset($out['called']),'Missing CSRF reached an operation.');
 $post['slsmassnotifyserver_csrf']='fixture-csrf';[$status,$out]=$invoke($page,$post,false);operatorHttpCheck($status===403&&!isset($out['called']),'Role rejection reached an operation.');
 [$status,$out]=$invoke($page,$post);operatorHttpCheck($status===200&&$out['input']['fixture']==='reviewed','Valid controller request was altered.');
 foreach(['{bad','[]','null',str_repeat('x',131073),['bad']]as$raw){[$status,$out]=$invoke($page,array_replace($post,['payload'=>$raw]));operatorHttpCheck($status===400&&!isset($out['called']),'Invalid payload reached an operation.');}
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
echo "$checks operator controller CSRF, role guard and request-bound checks passed.\n";
