<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables=[]): string { if (basename($path)==='hero.php') { return ''; } extract($variables); ob_start(); include $path; return ob_get_clean(); } }
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/AnnouncementEmail.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentService;
use SLS\MassNotify\IncidentStore;
use SLS\MassNotify\ApiSecurity;
$count=0;
set_error_handler(static function($level,$message,$file,$line){if(!(error_reporting() & $level)){return false;}throw new ErrorException($message,0,$level,$file,$line);});
function emailIncidentCheck($ok,string $message):void { global $count; ++$count; if (!$ok) { throw new RuntimeException($message); } }
function emailIncidentReject(callable $work,string $message):void { $rejected=false; try {$work();} catch(InvalidArgumentException $error) {$rejected=true;} emailIncidentCheck($rejected,$message); }
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$methods='';
foreach(['normalizeEmailSenderDomain','normalizeEmailSenderLocalPart','acquireNativeBackupFileLock','releaseNativeBackupFileLock'] as $name) { $method=$reflection->getMethod($name);$lines=file($method->getFileName());$methods.=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1)); }
eval('class IncidentEmailFixture {
 use \\FreePBX\\modules\\SlsIncidents;
 use \\FreePBX\\modules\\SlsAnnouncementEmail; use \\FreePBX\\modules\\SlsAnnouncementSms;
 const INCIDENT_JOB_CONTRACT=1; const HERO_IMAGE="";
 public array $settings=[]; public array $sent=[]; public string $directory; public bool $throwSend=false; public bool $failActivity=false; public bool $probeLock=false;
 private function setPrivateOwnership($path) { chmod($path,0600); }
 private function acquireAnnouncementActivityLock($exclusive=false,$timeoutSeconds=30) { if($this->failActivity){throw new \RuntimeException("fixture maintenance");} return $this->acquireNativeBackupFileLock($this->directory."/activity.lock","fixture activity",$timeoutSeconds,$exclusive?LOCK_EX:LOCK_SH); }
 public function freeze($delivery) { return $this->freezeIncidentDelivery($delivery); }
 public function submit($delivery,$message,$title,$context,$actor) { return $this->submitIncidentAnnouncement($delivery,$message,$title,$context,$actor); }
 protected function incidentStore(): \\SLS\\MassNotify\\IncidentStore { return new \\SLS\\MassNotify\\IncidentStore($this->directory); }
 private function getActiveSettings() { return $this->settings; }
 private function getPendingSettings() { return null; }
 public function getAnnouncementGroups() { return $this->settings["announcement_groups"] ?? []; }
 public function getConfiguredPjsipExtensionNumbers() { return ["1000"]; }
 private function getDesktopClients($settings) { return []; }
 private function outboundVoiceTargets($settings) { return []; }
 private function normalizeWebhookDestinations($rows,$kind) { return []; }
 private function getAvailableTones() { return []; }
 private function getExtensionNameMap() { return ["1000"=>"Fixture phone"]; }
 private function getCsrfToken() { return "fixture-csrf"; }
 public function getAnnouncementJob($id) { return ["success"=>true,"job_id"=>$id,"receipts"=>[]]; }
 public function sendSipNotifyAnnouncement($extensions,$message,$notify,$tts,$groups,$options) {
   if($this->probeLock) {
    $process=proc_open([PHP_BINARY,"-r",\' $h=fopen($argv[1],"r+");exit(flock($h,LOCK_EX|LOCK_NB)?0:17);\',$this->directory."/activity.lock"],[0=>["file","/dev/null","r"],1=>["file","/dev/null","w"],2=>["file","/dev/null","w"]],$pipes);
    if(proc_close($process)!==17){throw new \RuntimeException("Config writer entered while snapshotting incident.");}
   }
   if($this->throwSend){throw new \RuntimeException("fixture queue failure");}
   $this->sent[]=compact("extensions","message","options");
   return ["success"=>true,"queued"=>true,"job_id"=>"job_".str_pad(dechex(count($this->sent)),32,"0",STR_PAD_LEFT)];
 }
 '.$methods.'}');
$directory=sys_get_temp_dir().'/sls-incident-email-'.bin2hex(random_bytes(10));mkdir($directory,0700);
try {
 $a='email_'.str_repeat('a',24);$b='email_'.str_repeat('b',24);
 $fixture=new IncidentEmailFixture();$fixture->directory=$directory;
 $fixture->settings=['mail_from_name'=>'Fixture sender','mail_from_addr'=>'sender@example.com',
   'announcement_email'=>['enabled'=>'1','recipients'=>[
    ['id'=>$a,'name'=>'Staff <script>bad</script>','address'=>'staff@example.com','enabled'=>'1'],
    ['id'=>$b,'name'=>'Supervisor','address'=>'supervisor@example.com','enabled'=>'1']]],
   'announcement_groups'=>[['id'=>'staff_group','name'=>'Staff group','email_recipient_ids'=>[$a]]]];
 $original=$fixture->settings;
 $legacy=IncidentConfig::delivery(['extensions'=>['1000']]);
 emailIncidentCheck($legacy['email_recipient_ids']===[],'Older template gained email recipients.');
 emailIncidentCheck(IncidentConfig::hasAudience(IncidentConfig::delivery(['email_recipient_ids'=>[$a]])),'Email-only incident has no audience.');
 foreach([null,'all',['outside@example.com'],[$a,$a],['key'=>$a],array_fill(0,51,$a)] as $bad) { emailIncidentReject(fn()=>IncidentConfig::delivery(['email_recipient_ids'=>$bad]),'Malformed incident email selectors accepted.'); }
 emailIncidentReject(fn()=>IncidentConfig::delivery(['email_recipients'=>['raw@example.com']]),'Raw incident email address accepted.');
 $frozen=$fixture->freeze(['group_ids'=>['staff_group']]);
 emailIncidentCheck($frozen['email_recipient_ids']===[$a] && $frozen['group_ids']===[],'Incident email group not frozen.');
 emailIncidentCheck(preg_match('/^[a-f0-9]{64}$/D',$frozen['_identities']['email_recipient_ids'][$a])===1,'Incident email fingerprint missing.');
 emailIncidentCheck(strpos(json_encode($frozen),'staff@example.com')===false,'Incident frozen record leaked email address.');
 $context=['schema'=>1,'incident_id'=>'inc_'.str_repeat('c',32),'sequence'=>1,'kind'=>'initial','severity'=>'warning','is_test'=>false];
 $actor=['identity'=>'Fixture administrator','source'=>'freepbx'];
 $fixture->probeLock=true;
 $fixture->settings['announcement_groups'][0]['email_recipient_ids'][]=$b;
 foreach(['initial','update','all_clear','escalation'] as $kind) {
  $context['kind']=$kind;
  $result=$fixture->submit($frozen,'Fixture message','Fixture title',$context,$actor);
  emailIncidentCheck(!empty($result['success']) && end($fixture->sent)['options']['email_recipient_ids']===[$a],'Incident update gained new group recipients or lost original IDs.');
 }
 foreach(['address','sender','disabled','channel_disabled'] as $change) {
  $fixture->settings=$original;
  if($change==='address') {$fixture->settings['announcement_email']['recipients'][0]['address']='changed@example.com';}
  elseif($change==='sender') {$fixture->settings['mail_from_name']='Changed sender';}
  elseif($change==='disabled') {$fixture->settings['announcement_email']['recipients'][0]['enabled']='0';}
  else {$fixture->settings['announcement_email']['enabled']='0';}
  $before=count($fixture->sent);
  foreach(['update','all_clear','escalation'] as $kind) {
   $context['kind']=$kind;$result=$fixture->submit($frozen,'Fixture','Fixture',$context,$actor);
   emailIncidentCheck(empty($result['success']) && $result['delivery_started']===false && count($fixture->sent)===$before,'Changed email destination/sender was redirected.');
  }
 }
 $fixture->settings=$original;
 $fixture->throwSend=true;$caught=false;
 try{$fixture->submit($frozen,'Throwing queue','Title',$context,$actor);}catch(RuntimeException $error){$caught=true;}
 emailIncidentCheck($caught,'Fixture queue exception absent.');$fixture->throwSend=false;
 $probe=proc_open([PHP_BINARY,'-r','$h=fopen($argv[1],"r+");exit(flock($h,LOCK_EX|LOCK_NB)?0:17);',$directory.'/activity.lock'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
 emailIncidentCheck(proc_close($probe)===0,'Incident exception leaked activity lease to config writer.');
 $fixture->failActivity=true;$before=count($fixture->sent);$result=$fixture->submit($frozen,'Blocked','Title',$context,$actor);
 emailIncidentCheck(($result['error_code']??'')==='announcement_activity_timeout' && $result['delivery_started']===false && count($fixture->sent)===$before,'Failed activity acquisition still submitted.');$fixture->failActivity=false;
 $older=$fixture->freeze(['extensions'=>['1000']]);unset($older['email_recipient_ids'],$older['_identities']['email_recipient_ids']);
 $oldBytes=json_encode($older);
 emailIncidentCheck(!empty($fixture->submit($older,'Legacy update','Legacy title',$context,$actor)['success']),'Pre-email incident cannot be updated.');
 emailIncidentCheck(json_encode($older)===$oldBytes,'Legacy incident history was rewritten.');
 $injected=$older;$injected['email_recipient_ids']=[$a];$before=count($fixture->sent);
 emailIncidentCheck(empty($fixture->submit($injected,'Bad update','Bad title',$context,$actor)['success']) && count($fixture->sent)===$before,'Legacy history acquired email without original fingerprints.');
 $issued=ApiSecurity::issue(['name'=>'Incident sender','scopes'=>['send'],'audience'=>['unrestricted'=>false,'email_recipient_ids'=>[$a]]]);
 $fixture->settings['control_api']=['enabled'=>'1','credentials'=>[$issued['credential']]];
 $apiActor=['identity'=>'Control API '.$issued['credential']['id'],'source'=>'control_api','credential_id'=>$issued['credential']['id']];
 emailIncidentCheck(!empty($fixture->submit($frozen,'Authorized update','Title',$context,$apiActor)['success']),'Named email credential refused.');
 $fixture->settings['control_api']['credentials'][0]['audience']['email_recipient_ids']=[];$before=count($fixture->sent);
 emailIncidentCheck(empty($fixture->submit($frozen,'Removed grant','Title',$context,$apiActor)['success']) && count($fixture->sent)===$before,'Removed email grant bypassed incident recheck.');
 $fixture->settings=$original;
 // Actual incident service stores the initial and supervisor snapshots, then
 // dispatches initial/update/escalation against the actual facade above.
 $template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('d',24),'name'=>'Email workflow','title'=>'Fixture','message'=>'Fixture instructions',
   'delivery'=>['group_ids'=>['staff_group']], 'roster'=>[['id'=>'visitor','name'=>'Visitor','location'=>'Office','desktop_username'=>'']],
   'escalation'=>['enabled'=>true,'after_seconds'=>60,'delivery'=>['email_recipient_ids'=>[$b]]]]);
 $now=1800000000;$store=new IncidentStore($directory);
 $service=new IncidentService($store,static fn($id)=>$template,fn($delivery)=>$fixture->freeze($delivery),fn($d,$m,$t,$c,$a)=>$fixture->submit($d,$m,$t,$c,$a),fn($id)=>$fixture->getAnnouncementJob($id),static function()use(&$now){return $now;});
 $record=$service->start(['template_id'=>$template['id'],'request_id'=>str_repeat('1',32),'fields'=>[],'is_test'=>true],$actor);
 $fixture->settings['announcement_groups'][0]['email_recipient_ids'][]=$b;
 $service->update($record['id'],['request_id'=>str_repeat('2',32),'kind'=>'update','message'=>'Updated fixture'],$actor);
 emailIncidentCheck(end($fixture->sent)['options']['email_recipient_ids']===[$a],'Actual service expanded saved group on update.');
 $fixture->settings['announcement_email']['recipients'][1]['address']='redirected@example.com';$before=count($fixture->sent);$now+=61;
 $service->process();$after=$store->read($record['id']);$escalations=array_values(array_filter($after['operations'],static fn($op)=>$op['kind']==='escalation'));
 emailIncidentCheck(count($escalations)===1 && $escalations[0]['state']==='not_submitted' && count($fixture->sent)===$before,'Escalation redirected changed supervisor email.');
 $service->update($record['id'],['request_id'=>str_repeat('3',32),'kind'=>'all_clear','message'=>'All clear fixture'],$actor);
 emailIncidentCheck(end($fixture->sent)['options']['email_recipient_ids']===[$a],'Actual all-clear lost frozen email audience.');
 $fixture->settings=$original;
 $second=$service->start(['template_id'=>$template['id'],'request_id'=>str_repeat('4',32),'fields'=>[],'is_test'=>false],$actor);
 $now+=61;$service->process();
 emailIncidentCheck(end($fixture->sent)['options']['email_recipient_ids']===[$b],'Actual supervisor escalation lost its own frozen email audience.');
 // Every later level uses the real facade's frozen identity and revocation checks.
 $ladderTemplate=IncidentConfig::template(['id'=>'tpl_'.str_repeat('e',24),'name'=>'Email ladder','title'=>'Fixture','message'=>'Fixture instructions',
   'delivery'=>['email_recipient_ids'=>[$a]], 'roster'=>[['id'=>'visitor','name'=>'Visitor','desktop_username'=>'']],
   'escalation'=>['enabled'=>true,'steps'=>[
    ['name'=>'Supervisor','after_seconds'=>60,'delivery'=>['email_recipient_ids'=>[$b]]],
    ['name'=>'Alternate follow-up','after_seconds'=>120,'delivery'=>['email_recipient_ids'=>[$a]]]]]]);
 $ladderService=new IncidentService($store,static fn($id)=>$ladderTemplate,fn($d)=>$fixture->freeze($d),fn($d,$m,$t,$c,$a)=>$fixture->submit($d,$m,$t,$c,$a),fn($id)=>$fixture->getAnnouncementJob($id),static function()use(&$now){return $now;});
 $ladderRecord=$ladderService->start(['template_id'=>$ladderTemplate['id'],'request_id'=>str_repeat('8',32),'fields'=>[],'is_test'=>true],$actor);
 $now+=60;$ladderService->process();emailIncidentCheck(end($fixture->sent)['options']['email_recipient_ids']===[$b],'First ladder audience differs from its frozen selection.');
 $fixture->settings['announcement_email']['recipients'][0]['address']='changed-after-initial@example.com';$before=count($fixture->sent);
 $now+=60;$ladderService->process();$ladderOps=array_values(array_filter($store->read($ladderRecord['id'])['operations'],static fn($op)=>$op['kind']==='escalation'));
 emailIncidentCheck(count($ladderOps)===2 && $ladderOps[1]['escalation_step']===2 && $ladderOps[1]['state']==='not_submitted' && count($fixture->sent)===$before,'Later escalation redirected a changed email identity.');
 $fixture->settings=$original;
 $fixture->settings['announcement_email']['recipients'][1]['enabled']='0';
 $html=$fixture->renderIncidentsPage();
 emailIncidentCheck(strpos($html,"['email_recipient_ids','Announcement email recipients']")!==false,'Incident recipient editor missing email channel.');
 emailIncidentCheck(strpos($html,'staff@example.com')===false && strpos($html,'supervisor@example.com')===false,'Incident choices exposed raw addresses.');
 emailIncidentCheck(strpos($html,'<script>bad</script>')===false && strpos($html,$a)!==false && strpos($html,$b)===false,'Incident choices unsafe or included disabled email.');
 // SMS identities share the incident freeze contract without exposing numbers.
 $smsId='sms_'.str_repeat('a',24); $smsOther='sms_'.str_repeat('b',24);
 $fixture->settings=$original;
 $fixture->settings['public_pbx_host']='pbx.example.com';
 $fixture->settings['control_api']=['base_url'=>'https://pbx.example.com/api/sls-mass-notify'];
 $fixture->settings['announcement_sms']=\SLS\MassNotify\Sms\Config::normalize(['enabled'=>true,'provider'=>'twilio','from'=>'+15555550100','organization'=>'Fixture',
  'twilio_account_sid'=>'AC'.str_repeat('a',32),'twilio_key_sid'=>'SK'.str_repeat('b',32),'twilio_key_secret'=>'fixture-secret','twilio_auth_token'=>str_repeat('c',32),'segment_cost_micros'=>10000,
  'recipients'=>[['id'=>$smsId,'name'=>'SMS staff','number'=>'+15555550101','enabled'=>true,'consent'=>true,'consent_note'=>'Fixture only','consent_at'=>'2026-01-01T00:00:00Z'],
   ['id'=>$smsOther,'name'=>'SMS supervisor','number'=>'+15555550102','enabled'=>true,'consent'=>true,'consent_note'=>'Fixture only','consent_at'=>'2026-01-01T00:00:00Z']]]);
 $fixture->settings['announcement_groups']=[['id'=>'sms_group','name'=>'SMS group','sms_recipient_ids'=>[$smsId]]];
 $smsOriginal=$fixture->settings; $smsFrozen=$fixture->freeze(['group_ids'=>['sms_group']]);
 emailIncidentCheck($smsFrozen['sms_recipient_ids']===[$smsId] && !str_contains(json_encode($smsFrozen),'+15555550101'),'Incident SMS did not freeze IDs privately');
 $fixture->settings['announcement_groups'][0]['sms_recipient_ids'][]=$smsOther;
 foreach(['initial','update','all_clear','escalation'] as $kind) {
  $context['kind']=$kind; $result=$fixture->submit($smsFrozen,'SMS incident','Title',$context,$actor);
  emailIncidentCheck(!empty($result['success']) && end($fixture->sent)['options']['sms_recipient_ids']===[$smsId],'Incident SMS gained new group audience');
 }
 foreach(['number','consent','route'] as $change) {
  $fixture->settings=$smsOriginal;
  if($change==='number')$fixture->settings['announcement_sms']['recipients'][0]['number']='+15555550103';
  elseif($change==='consent')$fixture->settings['announcement_sms']['recipients'][0]['consent']=false;
  else $fixture->settings['announcement_sms']['from']='+15555550104';
  $before=count($fixture->sent); $result=$fixture->submit($smsFrozen,'SMS changed identity','Title',$context,$actor);
  emailIncidentCheck(empty($result['success']) && count($fixture->sent)===$before,'Changed incident SMS identity submitted');
 }
 echo "Incident email config, frozen identities, authorization, service and UI: $count checks passed.\n";
} finally {
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());} }
 rmdir($directory);
}
