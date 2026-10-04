<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/AutomationService.php';
require_once __DIR__.'/../slsmassnotifyserver/AutomationInputs.php';
require_once __DIR__.'/../slsmassnotifyserver/DeliveryAuthorization.php';
use SLS\MassNotify\{AutomationConfig,AutomationService,AutomationStore,AutomationInputs};
$checks=0;
function check($value,string $label): void { global $checks; $checks++; if (!$value) { throw new RuntimeException($label); } }
function rejects(callable $work,string $label): void { try { $work(); } catch (InvalidArgumentException|DomainException|RuntimeException $e) { check(true,$label); return; } throw new RuntimeException('Expected rejection: '.$label); }
$root=sys_get_temp_dir().'/sls-automations-'.bin2hex(random_bytes(8)); mkdir($root,0700);
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Fixture screen','enabled'=>true,'kind'=>'brightsign_udp','host'=>'192.168.1.20','port'=>5000,'message'=>'evacuation']);
$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Fixture sensor','enabled'=>true,'kind'=>'sensor','secret'=>str_repeat('c',64),'event'=>'smoke','action_ids'=>[$action['id']],'allow_tests'=>true,'cooldown_seconds'=>10]);
$settings=['enabled'=>'1','automations'=>['schema'=>1,'rules'=>[$rule],'actions'=>[$action]]];
$time=time(); $calls=[]; $store=new AutomationStore($root);
$service=new AutomationService($store,static function()use(&$settings){return $settings;},static function(){throw new RuntimeException('Unexpected notification');},static function($action,$row)use(&$calls){$calls[]=$row['id'];return ['state'=>'submitted'];},static function()use(&$time){return $time;});
$input=['request_id'=>'event-1','sent_at'=>$time,'expires_at'=>$time+300,'event'=>'smoke','message'=>'Smoke sensor active','is_test'=>true];
$event=$service->activate($rule['id'],$input,'sensor'); check($event['state']==='queued','durable queue');
check($service->activate($rule['id'],$input,'sensor')['id']===$event['id'],'duplicate idempotent before execution');
rejects(fn()=>$service->activate($rule['id'],array_replace($input,['message'=>'changed']),'sensor'),'same identifier different content');
rejects(fn()=>$service->activate($rule['id'],array_replace($input,['request_id'=>'event-2']),'sensor'),'cooldown');
check($service->process($event['id'])['state']==='complete','action completes');
$service->process($event['id']); check(count($calls)===1,'duplicate execution never replays action');
$time+=11; $input['request_id']='event-3';$input['sent_at']=$time;$input['expires_at']=$time+300;
$event=$service->activate($rule['id'],$input,'sensor');$settings['automations']['rules'][0]['enabled']=false;
check($service->process($event['id'])['state']==='cancelled' && count($calls)===1,'revocation before worker prevents submission');
$settings['automations']['rules'][0]['enabled']=true;$time+=11;
$input['request_id']='event-4';$input['sent_at']=$time;$input['expires_at']=$time+30;$event=$service->activate($rule['id'],$input,'sensor');$time+=31;
check($service->process($event['id'])['state']==='expired' && count($calls)===1,'expiry prevents late action');
foreach ([['sent_at'=>$time+61],['sent_at'=>$time-601],['expires_at'=>$time],['event'=>'wrong'],['is_test'=>'false'],['is_test'=>null],['references'=>[str_repeat('a',64)]],['operation'=>'cancel']] as $bad) {
    rejects(fn()=>$service->activate($rule['id'],array_replace($input,['sent_at'=>$time,'expires_at'=>$time+300,'request_id'=>bin2hex(random_bytes(8))],$bad),'sensor'),'invalid source or payload');
}
$raw='{"operation":"activate"}';$stamp=(string)$time;$sig=hash_hmac('sha256',$rule['id'].'.'.$stamp.'.'.$raw,$rule['secret']);
check(AutomationInputs::authenticate($rule,$raw,['timestamp'=>$stamp,'signature'=>$sig],$time),'signed bytes authenticate');
check(!AutomationInputs::authenticate($rule,$raw.' ',['timestamp'=>$stamp,'signature'=>$sig],$time),'whitespace tamper');
check(!AutomationInputs::authenticate($rule,$raw,['timestamp'=>$stamp,'signature'=>$sig],$time+61),'stale signature');
check(!AutomationInputs::authenticate(array_replace($rule,['id'=>'trg_'.str_repeat('d',24)]),$raw,['timestamp'=>$stamp,'signature'=>$sig],$time),'cross identity signature');
foreach (['127.0.0.1','169.254.169.254','224.0.0.1','192.168.1.255','0.0.0.0','8.8.8.8','localhost'] as $host) { rejects(fn()=>AutomationConfig::action(array_replace($action,['host'=>$host])),'unsafe device host'); }
rejects(fn()=>AutomationConfig::action(array_replace($action,['path'=>'/tmp/file.sh'])),'mixed action fields');
rejects(fn()=>AutomationConfig::rule(array_replace($rule,['secret'=>'weak'])),'weak enrollment');
rejects(fn()=>AutomationConfig::rule(array_replace($rule,['allow_tests'=>'1'])),'typed flags');
rejects(fn()=>AutomationConfig::normalize(['rules'=>[$rule,$rule],'actions'=>[$action]]),'duplicate identities');

$panic=AutomationConfig::rule(['id'=>'trg_'.str_repeat('d',24),'name'=>'Desk panic','enabled'=>true,'kind'=>'panic','secret'=>str_repeat('e',64),'location_id'=>'loc_'.str_repeat('f',24),'action_ids'=>[$action['id']],'allow_tests'=>true]);
$settings['automations']['rules'][]=$panic;$settings['location_directory']['nodes']=[['id'=>$panic['location_id'],'name'=>'Office','type'=>'site']];
$implicit=$settings;unset($implicit['location_directory']);$implicit['automations']['rules'][1]['location_id']=\SLS\MassNotify\LocationDirectory::DEFAULT_SITE_ID;
check(AutomationConfig::resolve($implicit,$panic['id'])['location']['name']==='Default site','implicit default site is usable without a config rewrite');
$request=str_repeat('1',32);$challenge=$service->challenge($panic['id'],$request);
check($service->challenge($panic['id'],$request)===$challenge,'confirmation retry stable');
$panicInput=['request_id'=>$request,'sent_at'=>$time,'expires_at'=>$time+300,'event'=>'panic','message'=>'','is_test'=>true,'confirmation'=>$challenge['confirmation']];
rejects(fn()=>$service->activate($panic['id'],$panicInput,'panic'),'immediate accidental confirmation');
$time+=2;$event=$service->activate($panic['id'],$panicInput,'panic');check($event['state']==='queued','deliberate confirmed activation');
check(!isset($store->read()['challenges'][$event['id']]),'confirmation consumed');
check($service->activate($panic['id'],$panicInput,'panic')['id']===$event['id'],'confirmed duplicate does not need new challenge');
$settings['location_directory']['nodes'][0]['name']='Different room';check($service->process($event['id'])['state']==='cancelled','location re-enrollment change revokes queued action');

$cap=AutomationConfig::rule(['id'=>'trg_'.str_repeat('2',24),'name'=>'CAP feed','enabled'=>true,'kind'=>'cap','feed_url'=>'https://alerts.example.org/cap.xml','sender'=>'alerts@example.org','event'=>'Test event','action_ids'=>[$action['id']],'allow_tests'=>true]);
$settings['automations']['rules'][]=$cap;$sent=gmdate('Y-m-d\TH:i:s\Z',$time);$expires=gmdate('Y-m-d\TH:i:s\Z',$time+300);
$xml='<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><identifier>a1</identifier><sender>alerts@example.org</sender><sent>'.$sent.'</sent><status>Test</status><msgType>Alert</msgType><scope>Public</scope><info><event>Test event</event><headline>Reviewed headline</headline><expires>'.$expires.'</expires></info></alert>';
$messages=AutomationInputs::cap($xml,$cap);check(count($messages)===1 && $messages[0]['is_test']===true,'CAP typed test and absolute expiry');
$event=$service->activate($cap['id'],$messages[0],'cap');
$cancel=str_replace(['<identifier>a1</identifier>','<msgType>Alert</msgType>','<info>'],['<identifier>a2</identifier>','<msgType>Cancel</msgType><references>alerts@example.org,a1,'.$sent.'</references>','<info>'],$xml);
$cancelEvent=AutomationInputs::cap($cancel,$cap)[0];
check($service->activate($cap['id'],$cancelEvent,'cap')['state']==='cancelled','CAP cancellation bypasses cooldown without notification');
check($service->process($event['id'])['state']==='cancelled','CAP cancellation suppresses original queued event');
check(AutomationInputs::cap(str_replace('alerts@example.org','wrong@example.org',$xml),$cap)===[],'CAP sender isolation');
check(AutomationInputs::cap(str_replace('<scope>Public','<scope>Private',$xml),$cap)===[],'CAP scope');
rejects(fn()=>AutomationInputs::cap('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]>'.$xml,$cap),'XXE declaration');
rejects(fn()=>AutomationInputs::cap(str_replace($sent,substr($sent,0,-1),$xml),$cap),'timezone-less CAP');
rejects(fn()=>AutomationInputs::cap(str_replace('<sender>alerts@example.org</sender>','<sender>alerts@example.org</sender><sender>alerts@example.org</sender>',$xml),$cap),'CAP duplicate critical field');
foreach (['http://example.org/cap','https://127.0.0.1/cap','https://user:secret@example.org/cap','https://example.org:8443/cap'] as $url) { rejects(fn()=>AutomationInputs::fetch($url),'CAP unsafe fetch URL'); }
// Uncertain claims are terminal: never replay a script after a killed worker.
$store->transaction(static function(array &$state) use($event,$time): void { $state['events'][$event['id']]['state']='running';$state['events'][$event['id']]['claimed_at']=$time-241; });
check($service->process($event['id'])['state']==='uncertain','interrupted claimed action is uncertain');
check(count($calls)===1,'no unintended test notifications or extra actions');
foreach (['127.0.0.1','10.0.0.1','169.254.1.1','100.64.0.1','100.127.255.254','192.0.2.8','198.18.2.5','198.51.100.8','203.0.113.4','224.0.0.1','240.0.0.1','255.255.255.255','::1','8.8.8.8.evil'] as $ip) {
    check(!AutomationInputs::publicAddress($ip), 'Reserved CAP target was accepted.');
}
check(AutomationInputs::publicAddress('8.8.8.8') && AutomationInputs::publicAddress('1.1.1.1'), 'Public IPv4 target was rejected.');
$time+=20; $input=array_replace($input,['request_id'=>'transmission-check','sent_at'=>$time,'expires_at'=>$time+300]);
$event=$service->activate($rule['id'],$input,'sensor');
$gate=['automation_context'=>$event['context'],'webhook_ids'=>['fixture_hook']];
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root.'/missing',$time), 'A missing journal allowed transmission');
check(\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'Current durable trigger was rejected at transmission');
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time+301), 'Expired trigger reached transmission');
$settings['automations']['rules'][0]['enabled']=false;
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'Disabled trigger reached transmission');
$settings['automations']['rules'][0]['enabled']=true;
$store->transaction(static function(array &$state)use($event):void{$state['events'][$event['id']]['blocked']=true;});
check(!\SLS\MassNotify\DeliveryAuthorization::permits($settings,$gate,$root,$time), 'Blocked durable trigger reached transmission');
$path=$root.'/private.config';file_put_contents($path,json_encode($settings));chmod($path,0600);
check(\SLS\MassNotify\DeliveryAuthorization::settings($path)===$settings, 'Read-only authorization changed config');
link($path,$root.'/linked.config');rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Hard-linked config reached transmission');unlink($root.'/linked.config');
chmod($path,0666);rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Writable config reached transmission');
$protectedBytes=file_get_contents($path);
foreach([0644,0641,0660] as $mode){
    chmod($path,$mode);
    rejects(fn()=>\SLS\MassNotify\DeliveryAuthorization::settings($path),'Public-readable, executable or group-writable config reached transmission');
    check(file_get_contents($path)===$protectedBytes,'Rejected delivery authorization modified configuration bytes.');
}
chmod($path,0640);
check(\SLS\MassNotify\DeliveryAuthorization::settings($path)===$settings,'Private group-readable configuration was rejected at transmission');
// Administration must be checked before reading private trigger state or inspecting a script.
require_once __DIR__.'/../slsmassnotifyserver/Automations.php';
class AutomationAccessFixture {
    use \FreePBX\modules\SlsAutomations;
    const PLUGIN_DATA_DIR='/nonexistent-sls-automation-access-fixture';
    public string $role='viewer'; public int $reads=0;
    public function currentOperator():array{return ['operator_role'=>$this->role];}
    public function getActiveSettings():array{$this->reads++;return ['enabled'=>'1'];}
    public function getPendingSettings():?array{$this->reads++;return null;}
    protected function automationStoreDirectory():string{return self::PLUGIN_DATA_DIR;}
}
$adminFixture=new AutomationAccessFixture;
foreach(['viewer','sender','scheduler','warden']as$role){
    $adminFixture->role=$role;
    rejects(fn()=>$adminFixture->getAutomationState(),'Limited role read private trigger configuration');
    rejects(fn()=>$adminFixture->saveAutomationItem([]),'Limited role changed trigger configuration');
    rejects(fn()=>$adminFixture->inspectAutomationScript('/tmp/forbidden.sh'),'Limited role inspected a script');
}
check($adminFixture->reads===0,'Denied role performed configuration discovery before authorization.');
$adminFixture->role='administrator';
check($adminFixture->getAutomationState()['config']===['schema'=>1,'rules'=>[],'actions'=>[]],'Administrator could not review trigger configuration.');
// Read the real directory scanner: ordinary retained history fits, while a
// crowded or unsafe journal stays bounded before any record is consumed.
$scanMethod=new ReflectionMethod(AutomationAccessFixture::class,'automationObservationPaths');
$scanDirectory=$root.'/observations';mkdir($scanDirectory,0700);
for($i=0;$i<550;$i++){file_put_contents($scanDirectory.'/job_'.sprintf('%032x',$i).'.json','{}');}
$scan=$scanMethod->invoke($adminFixture,$scanDirectory);
check(!$scan['limited']&&$scan['inspected']===550&&count($scan['paths'])===550,'Supported emergency history was truncated.');
for($i=0;$i<700;$i++){file_put_contents($scanDirectory.'/unrelated-'.$i,'ignored');}
$started=hrtime(true);$scan=$scanMethod->invoke($adminFixture,$scanDirectory);
check($scan['limited']&&$scan['inspected']<=1024&&count($scan['paths'])<=1024,'Crowded emergency history was fully enumerated.');
check(hrtime(true)-$started<2000000000,'Emergency history scanner exceeded its bounded deadline.');
$unsafeDirectory=$root.'/unsafe-observations';mkdir($unsafeDirectory,0700);
$unsafe=$unsafeDirectory.'/job_'.str_repeat('a',32).'.json';
symlink($scanDirectory.'/job_'.sprintf('%032x',0).'.json',$unsafe);
rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation symlink');unlink($unsafe);
link($scanDirectory.'/job_'.sprintf('%032x',0).'.json',$unsafe);
rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation shared inode');unlink($unsafe);
check(posix_mkfifo($unsafe,0600),'Emergency FIFO fixture could not be created');
$started=hrtime(true);rejects(fn()=>$scanMethod->invoke($adminFixture,$unsafeDirectory),'Emergency observation FIFO');
check(hrtime(true)-$started<500000000,'Emergency observation FIFO blocked its scanner');unlink($unsafe);
rejects(fn()=>$scanMethod->invoke($adminFixture,$root.'/missing-observations'),'Missing emergency observation storage');
// The actual PBX page must invoke its role gate before either GET or POST work.
foreach(['GET','POST']as$method){
    $code='require_once '.var_export(__DIR__.'/../slsmassnotifyserver/Operators.php',true).';'
        .'class AutomationPageFixture {use \\FreePBX\\modules\\SlsOperators;'
        .'function getActiveSettings(){return ["operator_access"=>["enabled"=>true]];}'
        .'function currentOperator(){return ["operator_role"=>"sender"];}'
        .'function __call($name,$args){throw new RuntimeException("Unauthorized controller dispatched ".$name);}}'
        .'class FreePBX{static function create(){return (object)["Slsmassnotifyserver"=>new AutomationPageFixture];}}'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_POST=[];'
        .'register_shutdown_function(function(){fwrite(STDERR,(string)(http_response_code()?:200));});'
        .'require '.var_export(__DIR__.'/../slsmassnotifyserver/page.slsmassnotifyserver_automations.php',true).';';
    $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Role controller fixture could not start');fclose($pipes[0]);
    $body=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0&&$status==='403'&&str_contains($body,'current SLS role'),'Trigger page bypassed its SLS role gate.');
}
echo "Automation configuration/auth/journal/CAP/transmission: $checks checks passed\n";
