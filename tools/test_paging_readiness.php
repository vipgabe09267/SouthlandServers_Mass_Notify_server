<?php
declare(strict_types=1);

// Render and execute the real view's display-only readiness calculations.
function load_view(string $path, array $variables = []): string { return ''; }
$paging = ['groups'=>[['group_id'=>'paging_fixture','name'=>'Staff','menu_number'=>1,'extensions'=>['1000','1001','1002'],
    'notify_extensions'=>[],'allowed_callers'=>['1000'],'require_pin'=>'0']], 'allowed_callers'=>[]];
$available_extensions = [['extension'=>'1000','name'=>'Office']]; $announcement_groups=[];
$phone_inventory = ['available'=>true,'observed_at'=>gmdate('c'),'expires_at'=>time()+90,'endpoints'=>[
    '1000'=>['contacts'=>[['format'=>'yealink','contact_uri'=>'sip:private@example.invalid','device_key'=>'private-key'],['format'=>'cisco']]],
    '1001'=>['contacts'=>[['format'=>'unknown','user_agent'=>'<script>private</script>']]],
    '1002'=>['contacts'=>[]]]];
ob_start(); include dirname(__DIR__).'/slsmassnotifyserver/views/paging.php'; $view=(string)ob_get_clean();
if (str_contains($view,'private@example.invalid') || str_contains($view,'private-key') || str_contains($view,'<script>private</script>')) {
    throw new RuntimeException('Paging readiness exposed raw contact identity or device metadata.');
}
if (!preg_match('/var readinessInventory = (.*?);\s*var labels/s', $view, $match)) { throw new RuntimeException('Cached readiness projection is missing.'); }
$inventory = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
if ($inventory['endpoints']['1000']['contacts'] !== 2 || count($inventory['endpoints']['1000']['formats']) !== 2) {
    throw new RuntimeException('Mixed-device readiness lost registered contacts.');
}
$start=strpos($view,'function readinessForRecipients(values) {');
if($start===false){throw new RuntimeException('Paging readiness calculation start is missing.');}
$end=strpos($view,'function renderReadiness(', $start);
if($end===false||$end<=$start){throw new RuntimeException('Paging readiness calculation end is missing.');}
$program='const assert=require("assert"); const vm=require("vm"); let readinessInventory='.json_encode($inventory, JSON_THROW_ON_ERROR).';'
    .'let now=Date.now(); Date.now=()=>now;'.substr($view,$start,$end-$start).<<<'JS'
const check=readinessForRecipients(['1000','1001','1002','1000']);
assert.deepStrictEqual(check,{available:true,configured:3,registered:2,contacts:3,formats:['Cisco','Yealink'],unknown:1,unavailable:['1002']});
assert.strictEqual(readinessForRecipients([]).contacts,0);
readinessInventory.endpoints['1003']={contacts:2,formats:['panasonic','polycom']};
assert.deepStrictEqual(readinessForRecipients(['1003']).formats,['Panasonic','Polycom']);
assert.strictEqual(readinessForRecipients(['1003']).unknown,0);
now+=91000;
let expired=readinessForRecipients(['1000']);
assert.strictEqual(expired.available,false); assert.strictEqual(expired.registered,0); assert.deepStrictEqual(expired.unavailable,[]);
readinessInventory.available=false; readinessInventory.expires_at=now/1000+90;
assert.strictEqual(readinessForRecipients(['1000']).available,false);
readinessInventory.available=true; readinessInventory.endpoints['1000'].contacts=0;
assert.deepStrictEqual(readinessForRecipients(['1000']).unavailable,['1000']);
JS;
if(preg_match_all('#<script[^>]*>(.*?)</script>#s', $view, $scripts)<1){throw new RuntimeException('Paging view scripts are missing.');}
foreach ($scripts[1] as $script) { if (trim($script)!=='') { $program.='new vm.Script('.json_encode($script, JSON_THROW_ON_ERROR).');'; } }
$environment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
$process=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2s','10s','/usr/bin/node','-e',$program],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment,['bypass_shell'=>true]);
if (!is_resource($process)) { throw new RuntimeException('Node fixture could not start.'); }
$stdout=stream_get_contents($pipes[1],8193);$stderr=stream_get_contents($pipes[2],8193);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
if(!is_string($stdout)||strlen($stdout)>8192||!is_string($stderr)||strlen($stderr)>8192){throw new RuntimeException('Node fixture output was unavailable or exceeded its bound.');}$output=$stdout.$stderr;
if ($exit!==0) { throw new RuntimeException($output); }
$controller = <<<'PHP'
$request=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$_SERVER['REQUEST_METHOD']=$request['__method']??'POST';unset($request['__method']);$_POST=$request;$_SESSION=[];
function _($text){return $text;}
class PagingControllerFixture {
    public function enforceOperatorPageAccess($page):void{}
    public function validateCsrfToken($token):bool{return is_string($token)&&hash_equals('fixture-csrf',$token);}
    public function generateLivePagingPin($length):array{return (is_int($length)||is_string($length))?['success'=>true,'pin'=>'1234']:['success'=>false,'message'=>'Invalid PIN length.'];}
    public function saveLivePagingSettings(array $input,?string $revision=null):array{return ['success'=>true,'called'=>'save','revision'=>$revision];}
    public function showPage($page,$data):string{return '';}
}
class FreePBX {public static function create():object{return(object)['Slsmassnotifyserver'=>new PagingControllerFixture];}}
register_shutdown_function(static function(){fwrite(STDERR,':STATUS:'.(http_response_code()?:200));});
PHP;
$controller.='require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/page.slsmassnotifyserver_paging.php',true).';';
$invoke=static function(array $post)use($controller):array{
    $environment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
    $process=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2s','10s',PHP_BINARY,'-n','-r',$controller],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$environment,['bypass_shell'=>true]);
    if(!is_resource($process)){throw new RuntimeException('Paging controller fixture could not start.');}
    $request=json_encode($post,JSON_THROW_ON_ERROR);$written=fwrite($pipes[0],$request);fclose($pipes[0]);
    $out=stream_get_contents($pipes[1],8193);fclose($pipes[1]);$err=stream_get_contents($pipes[2],4097);fclose($pipes[2]);$exit=proc_close($process);
    if($written!==strlen($request)||$exit!==0||!is_string($out)||strlen($out)>8192||!is_string($err)||strlen($err)>4096||preg_match('/^:STATUS:([0-9]{3})$/D',$err,$match)!==1){throw new RuntimeException('Paging controller fixture failed: '.(is_string($err)?$err:'unavailable output'));}
    return[(int)$match[1],$out===''?null:json_decode($out,true,16,JSON_THROW_ON_ERROR)];
};
$base=['ajax'=>'1','slsmassnotifyserver_action'=>'generate_paging_pin','slsmassnotifyserver_csrf'=>'fixture-csrf','pin_length'=>'4'];
[$status,$answer]=$invoke(array_replace($base,['slsmassnotifyserver_csrf'=>[]]));if($status!==403||$answer['success']){throw new RuntimeException('Array CSRF reached paging PIN generation.');}
[$status,$answer]=$invoke(array_replace($base,['slsmassnotifyserver_action'=>[]]));if($status!==200||$answer['success']){throw new RuntimeException('Array paging action was accepted.');}
[$status,$answer]=$invoke($base);if($status!==200||!$answer['success']||$answer['pin']!=='1234'){throw new RuntimeException('Valid paging PIN request failed.');}
$save=['ajax'=>'1','slsmassnotifyserver_action'=>'save_live_paging','slsmassnotifyserver_csrf'=>'fixture-csrf','sls_paging_form_complete'=>'1','paging_revision'=>str_repeat('a',64),'live_paging'=>[]];
[$status,$answer]=$invoke($save);if($status!==200||!$answer['success']||$answer['called']!=='save'){throw new RuntimeException('Valid paging save request failed.');}
[$status,$answer]=$invoke(['__method'=>'PUT']);if($status!==405||$answer!==null){throw new RuntimeException('Unsupported paging method rendered or returned a body.');}
echo "Paging readiness: cached mixed contacts, scope counts, stale/empty evidence, private projection and actual view JavaScript passed.\n";
