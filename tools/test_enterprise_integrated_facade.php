<?php
declare(strict_types=1);
if(!interface_exists('BMO')){interface BMO{}}
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/EnterpriseIntegrationsService.php';
use SLS\MassNotify\{EnterpriseIntegrationsConfig as Config,IncidentConfig,IncidentStore,IncidentService,AutomationService,AutomationStore,AutomationConfig,AutomationInputs,LabsSafety};
function verify(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function refused(callable $call,string $part=''):void{try{$call();}catch(Throwable $error){verify($part===''||str_contains($error->getMessage(),$part),'Wrong refusal: '.$error->getMessage());return;}throw new RuntimeException('Expected refusal '.$part);}
$dashboardModule=(new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class))->newInstanceWithoutConstructor();
$registeredPages=$dashboardModule->myConfigPageInits();
$expectedPages=['index'];
$moduleXml=simplexml_load_file(dirname(__DIR__).'/slsmassnotifyserver/module.xml');
foreach($moduleXml->menuitems->children() as $menuPage){
 if((string)$menuPage['href']===''){$expectedPages[]=$menuPage->getName();}
}
sort($registeredPages,SORT_STRING);sort($expectedPages,SORT_STRING);
verify($registeredPages===$expectedPages,'Beta warning hooks must cover the Dashboard and every local SLS admin page, without unrelated FreePBX or external portal pages');
ob_start();$dashboardModule->doConfigPageInit('index');$dashboardScript=ob_get_clean();
verify(preg_match('~^<script src="modules/slsmassnotifyserver/views/dashboard_assets\.js\?v=[a-f0-9]{16}"></script>$~D',$dashboardScript)===1,'Dashboard hook must emit its small versioned local script before widget requests');
ob_start();$dashboardModule->doConfigPageInit('unrelated');verify(ob_get_clean()==='','Unrelated FreePBX pages must remain unchanged');
// Execute the real global hook with inert authorization/render collaborators.
// CLI ordinarily suppresses the browser notice, so replace only those boundary
// methods to prove dispatch and ordering without bootstrapping a live PBX.
class IntegratedBetaHookFixture extends \FreePBX\modules\Slsmassnotifyserver {
 public array $hookCalls=[];
 public function enforceOperatorPageAccess(string $page):void{$this->hookCalls[]='authorize:'.$page;}
 public function renderBetaNotice():string{$this->hookCalls[]='notice';return '<template id="fixture-beta-notice"></template>';}
}
$betaHook=(new ReflectionClass(IntegratedBetaHookFixture::class))->newInstanceWithoutConstructor();
ob_start();$betaHook->doConfigPageInit('index');$betaDashboard=ob_get_clean();
verify($betaHook->hookCalls===['notice']&&str_starts_with($betaDashboard,$dashboardScript)
 &&str_ends_with($betaDashboard,'<template id="fixture-beta-notice"></template>'),'Dashboard must preserve its early asset hook and render exactly one beta notice');
foreach(array_diff($expectedPages,['index']) as $page){
 $betaHook->hookCalls=[];ob_start();$betaHook->doConfigPageInit($page);$rendered=ob_get_clean();
 verify($betaHook->hookCalls===['authorize:'.$page,'notice'],'Local SLS page authorization must precede beta rendering');
 verify($rendered==='<template id="fixture-beta-notice"></template>','SLS page must render one warning without loading Dashboard chart assets');
}
$betaHook->hookCalls=[];ob_start();$betaHook->doConfigPageInit('unrelated');$unrelated=ob_get_clean();
verify($unrelated===''&&$betaHook->hookCalls===[],'Unrelated PBX pages must perform no SLS authorization or beta rendering work');
$directory=sys_get_temp_dir().'/sls-integrated-labs-'.bin2hex(random_bytes(8));mkdir($directory,0700);define('INTEGRATED_LABS_FIXTURE',$directory);
ini_set('session.save_path',$directory);ini_set('session.use_cookies','0');session_id('isolated-labs-'.bin2hex(random_bytes(8)));session_start();
register_shutdown_function(static function()use($directory){if(session_status()===PHP_SESSION_ACTIVE)session_write_close();foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($directory);});
$main=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);$actual='';foreach(['getCsrfToken','validateCsrfToken','automationRequest']as$name){$method=$main->getMethod($name);$actual.=implode('',array_slice(file($method->getFileName()),$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));}
$fixture=<<<'PHP'
class IntegratedLabsFixture {
 use \FreePBX\modules\SlsEnterpriseIntegrations;
 use \FreePBX\modules\SlsEnterpriseAdministration;
 use \FreePBX\modules\SlsAnnouncementDelivery;
 use \FreePBX\modules\SlsAnnouncementSms;
 use \FreePBX\modules\SlsOutboundVoice;
 const PLUGIN_DATA_DIR=INTEGRATED_LABS_FIXTURE;
 const SETTINGS_JSON=INTEGRATED_LABS_FIXTURE.'/active.json';const PENDING_SETTINGS_JSON=INTEGRATED_LABS_FIXTURE.'/pending.json';
 const CSRF_SESSION_KEY='isolated_enterprise_csrf';const PIPER_VOICE='/fixture/model';
 public array $principal=['id'=>'recovery_admin','username'=>'fixture-admin','operator_role'=>'administrator'];
 public array $lockOrder=[];public bool $race=false;public array $submissions=[];public $incidents;public $automations;public $provider=null;
 public function currentOperator():array{return $this->principal;}
 public function getActiveSettings():array{return $this->loadSettingsFile(self::SETTINGS_JSON);}
 private function loadSettingsFile($path):array{return json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
 private function normalizeSettings($value):array{$value['enterprise_integrations']=Config::normalize($value['enterprise_integrations']??[]);$value['labs_safety']=LabsSafety::normalize($value['labs_safety']??[]);return $value;}
 private function getDefaultSettings():array{return ['enterprise_integrations'=>Config::defaults()];}
 private function acquireAnnouncementActivityLock(...$unused){$this->lockOrder[]='activity';return fopen(self::PLUGIN_DATA_DIR.'/activity.lock','c+b');}
 private function releaseNativeBackupFileLock($handle):void{fclose($handle);}
 private function acquireSettingsLock(...$unused){$this->lockOrder[]='settings';if($this->race){$this->race=false;$settings=$this->getActiveSettings();$settings['enterprise_integrations']['sensors']['heartbeat_timeout_seconds']=181;file_put_contents(self::SETTINGS_JSON,json_encode($settings));}return fopen(self::PLUGIN_DATA_DIR.'/settings.lock','c+b');}
 private function releaseSettingsLock($handle):void{fclose($handle);}
 private function writeSettingsFileUnlocked($path,$value,$backup):void{file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));chmod($path,0600);}
 private function rememberSettingsFingerprint($path):void{}
 private function configurationPathMetadata($path){return file_exists($path)?lstat($path):null;}
 public function getConfiguredPjsipExtensionNumbers():array{return ['1000'];}
 public function getDesktopClients($settings):array{return [];}
 private function normalizeWebhookDestinations($rows,$kind):array{return [];}
 public function incidentStore():IncidentStore{return new IncidentStore(self::PLUGIN_DATA_DIR.'/incidents');}
 private function enterpriseIntegrationsService():\SLS\MassNotify\EnterpriseIntegrationsService{return new \SLS\MassNotify\EnterpriseIntegrationsService(new \SLS\MassNotify\EnterpriseIntegrationsStore($this->enterpriseIntegrationsStoreDirectory()),$this->provider);}
 private function incidentService():IncidentService{return $this->incidents;}
 public function listIncidents(int $limit=50):array{return $this->incidents->listing($limit);}
 private function automationService():AutomationService{return $this->automations;}
 private function automationStore():AutomationStore{return new AutomationStore(self::PLUGIN_DATA_DIR.'/automations');}
 private function startAutomationWorker($id):void{$this->submissions[]=$id;}
 public function freeze(array $request):array{return $this->snapshotAnnouncementRequest($request,$this->getActiveSettings());}
}
PHP;
eval('use SLS\\MassNotify\\{EnterpriseIntegrationsConfig as Config,IncidentConfig,IncidentStore,IncidentService,AutomationService,AutomationStore,AutomationConfig,AutomationInputs,LabsSafety};'.substr($fixture,0,strrpos($fixture,'}')).$actual.'}');
$module=new IntegratedLabsFixture();$smsId='sms_'.str_repeat('a',24);$voiceId='voice_'.str_repeat('b',24);$now=time();
$sms=\SLS\MassNotify\Sms\Config::normalize(['enabled'=>true,'provider'=>'twilio','from'=>'+15555550101','organization'=>'Fixture','twilio_account_sid'=>'AC'.str_repeat('a',32),'twilio_auth_token'=>str_repeat('c',32),'twilio_key_sid'=>'SK'.str_repeat('d',32),'twilio_key_secret'=>'fixture','segment_cost_micros'=>10000,'max_segments'=>6,'recipients'=>[['id'=>$smsId,'name'=>'Fixture person','number'=>'+15555550102','enabled'=>true,'consent'=>true,'consent_note'=>'Isolated fixture','consent_at'=>gmdate('c',$now-60)]]]);
$config=Config::defaults();$template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('d',24),'name'=>'Fixture incident','title'=>'Fixture incident','message'=>'Fixture message','delivery'=>['audio_mode'=>'tts','extensions'=>['1000'],'sms_recipient_ids'=>[$smsId],'voice_recipient_ids'=>[$voiceId]],'roster'=>[['id'=>'person_a','name'=>'Fixture person']]]);
$settings=['enabled'=>'0','runtime_enabled'=>true,'enterprise_integrations'=>$config,'labs_safety'=>LabsSafety::defaults(),'public_pbx_host'=>'pbx.example.org','control_api'=>['base_url'=>'https://pbx.example.org:8443/api/sls-mass-notify'],'announcement_sms'=>$sms,'outbound_voice'=>['enabled'=>'1','recipients'=>[['id'=>$voiceId,'name'=>'Fixture person','number'=>'+15555550102','enabled'=>'1']]],'incident_workflows'=>['schema'=>1,'templates'=>[$template]],'unrelated'=>'active-preserved'];file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($settings));$pending=$settings;$pending['unrelated']='pending-preserved';file_put_contents(IntegratedLabsFixture::PENDING_SETTINGS_JSON,json_encode($pending));
$module->incidents=new IncidentService($module->incidentStore(),static fn($id)=>$template,static fn($delivery)=>$delivery,static fn(...$unused)=>['success'=>true,'job_id'=>'job_'.str_repeat('e',32)],static fn($id)=>['state'=>'queued']);
$incident=$module->incidents->start(['template_id'=>$template['id'],'request_id'=>str_repeat('e',32),'fields'=>[],'is_test'=>true],['identity'=>'Fixture administrator','source'=>'fixture']);
$token=$module->getCsrfToken();verify(strlen($token)===64&&$module->validateCsrfToken($token)&&!$module->validateCsrfToken('bad'),'Actual mainclass CSRF token validation');
$actions=['integrations_save'=>[],'integrations_meeting'=>[],'integrations_door'=>[],'integrations_speaker_send'=>[],'integrations_cap_export'=>[],'integrations_ipaws'=>[],'integrations_speaker_telemetry'=>[]];
foreach(['sls_control_principal','sls_operator_portal_principal']as$key){$GLOBALS[$key]=['id'=>'fixture'];foreach($actions as$action=>$input)refused(fn()=>$module->enterpriseAdministratorAction($action,$input),'FreePBX administrator');unset($GLOBALS[$key]);}
$module->principal['id']='portal_admin';foreach($actions as$action=>$input)refused(fn()=>$module->enterpriseAdministratorAction($action,$input),'current FreePBX');$module->principal['id']='recovery_admin';
$state=$module->getEnterpriseIntegrationState();$module->race=true;refused(fn()=>$module->enterpriseAdministratorAction('integrations_save',['revision'=>$state['revision'],'config'=>$state['config']]),'changed');verify($module->getActiveSettings()['enterprise_integrations']['sensors']['heartbeat_timeout_seconds']===181,'Stale save overwrote concurrent edit');
$state=$module->getEnterpriseIntegrationState();$module->enterpriseAdministratorAction('integrations_save',['revision'=>$state['revision'],'config'=>$state['config']]);verify(array_slice($module->lockOrder,-2)===['activity','settings'],'Settings lock order');verify($module->getActiveSettings()['unrelated']==='active-preserved'&&json_decode(file_get_contents(IntegratedLabsFixture::PENDING_SETTINGS_JSON),true)['unrelated']==='pending-preserved','Namespace save overwrote unrelated active/pending config');
$state=$module->getEnterpriseIntegrationState();$danger=$state['config'];$danger['enabled']='1';$danger['access_control']['enabled']='1';$danger['access_control']['doors']=[['id'=>'door_'.str_repeat('e',24),'name'=>'Fixture door','token'=>'fixture-token','allow_read'=>true,'allow_lock'=>true,'allow_unlock'=>false]];refused(fn()=>$module->saveEnterpriseIntegrations(['revision'=>$state['revision'],'config'=>$danger]),'dangerous-feature');
$cap=$state['config'];$cap['enabled']='1';$cap['public_warning']['enabled']='1';$module->saveEnterpriseIntegrations(['revision'=>$state['revision'],'config'=>$cap]);$state=$module->getEnterpriseIntegrationState();$cap['public_warning']['ipaws_enabled']='1';refused(fn()=>$module->saveEnterpriseIntegrations(['revision'=>$state['revision'],'config'=>$cap]),'dangerous-feature');
$identityGate=new ReflectionMethod($module,'assertEnterpriseActivationSafe');$identityGate->setAccessible(true);
refused(fn()=>$identityGate->invoke($module,['enterprise_identity'=>['enabled'=>true]]),'dangerous-feature');
$receipt=['revision'=>\SLS\MassNotify\LabsSafety::revision(),'accepted_at'=>time(),'actor'=>'pbx:fixture'];
refused(fn()=>$identityGate->invoke($module,['enterprise_identity'=>['enabled'=>true],'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$receipt]]]),'dangerous-feature');
$identityGate->invoke($module,['enterprise_identity'=>['enabled'=>true],'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_identity'=>$receipt]]]);
$pageSession=[];$pageChallenge=LabsSafety::begin($pageSession,LabsSafety::PAGE_FEATURE,'fixture-admin',session_id(),$now,1000000000);
$pageInput=['feature'=>LabsSafety::PAGE_FEATURE,'challenge'=>$pageChallenge['challenge'],'agree'=>true];
refused(fn()=>LabsSafety::accept($pageSession,$pageInput,'fixture-admin',session_id(),$now+4,5000000000),'five full');
refused(fn()=>LabsSafety::accept($pageSession,array_replace($pageInput,['feature'=>'enterprise_cluster']),'fixture-admin',session_id(),$now+6,7000000000),'another session');
$pageAccepted=LabsSafety::accept($pageSession,$pageInput,'fixture-admin',session_id(),$now+6,7000000000);
$pageSettings=['labs_safety'=>['schema'=>1,'receipts'=>[LabsSafety::PAGE_FEATURE=>array_replace($pageAccepted['receipt'],['accepted_at'=>$now])]]];
foreach(LabsSafety::FEATURES as $feature){verify(LabsSafety::hasReceipt($pageSettings,$feature),'Page acknowledgment did not cover the Labs warning.');}
refused(fn()=>LabsSafety::accept($pageSession,$pageInput,'fixture-admin',session_id(),$now+6,7000000000),'another session');
verify(!LabsSafety::hasReceipt(['labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$receipt]]],LabsSafety::PAGE_FEATURE),'A legacy feature acknowledgment bypassed the page warning.');
$oldPage=$pageSettings;$oldPage['labs_safety']['receipts'][LabsSafety::PAGE_FEATURE]['revision']=str_repeat('a',64);
verify(!LabsSafety::hasReceipt($oldPage,'enterprise_identity'),'An outdated page notice authorized activation.');
$identityGate->invoke($module,$pageSettings+['enterprise_identity'=>['enabled'=>true]]);
$session=[];$challenge=LabsSafety::begin($session,'access_control_actuation','fixture-admin',session_id(),$now,1000000000);refused(fn()=>LabsSafety::accept($session,['feature'=>'access_control_actuation','challenge'=>$challenge['challenge'],'agree'=>true],'fixture-admin',session_id(),$now+1,2000000000),'five full');refused(fn()=>LabsSafety::accept($session,['feature'=>'access_control_actuation','challenge'=>$challenge['challenge'],'agree'=>true],'other',session_id(),$now+6,7000000000),'another session');
$request=['extensions'=>['1000'],'desktops'=>[],'webhooks'=>[],'audio_mode'=>'none','voice'=>'/fixture/model','title'=>'Fixture','message'=>'Message','is_test'=>true,'sms_recipient_ids'=>[$smsId],'voice_recipient_ids'=>[$voiceId],'incident_context'=>['incident_id'=>$incident['id']]];
$off=$module->freeze($request);verify(!isset($off['outbound_targets'][0]['incident_response'])&&!str_contains($off['sms_targets'][$smsId]['body'],'Reply SLS'),'Disabled integration changed human reply flow');
$enabled=$module->getActiveSettings();$enabled['enterprise_integrations']['enabled']='1';$enabled['enterprise_integrations']['responses']['enabled']='1';$enabled['enterprise_integrations']['responses']['bindings']=[['person_id'=>'person_a','sms_recipient_id'=>$smsId,'voice_recipient_id'=>$voiceId]];$enabled['enterprise_integrations']['speakers']['enabled']='1';$enabled['enterprise_integrations']['speakers']['devices']=[['id'=>'spk_'.str_repeat('f',24),'name'=>'Fixture speaker','enabled'=>'1','brand'=>'algo','model'=>'8180','extension'=>'1000']];file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));
$frozen=$module->freeze($request);verify(isset($frozen['speaker_profiles']['1000'])&&isset($frozen['outbound_targets'][0]['incident_response'])&&str_contains($frozen['sms_targets'][$smsId]['body'],'Reply SLS'),'Actual snapshot hook lost saved speaker or response metadata');$binding=$frozen['outbound_targets'][0]['incident_response'];verify($binding['person_id']==='person_a'&&$binding['incident_id']===$incident['id'],'Frozen participant/incident binding');
$journal=(new \SLS\MassNotify\EnterpriseIntegrationsStore($directory.'/enterprise-integrations'))->read();$smsBinding=array_values(array_filter($journal['bindings'],static fn($row)=>$row['channel']==='sms'))[0];$reply=['inbound'=>true,'opt_out'=>false,'provider'=>'twilio','body'=>'SLS '.$smsBinding['token'].' SAFE','event_id'=>'SMfixture','from'=>'+15555550102'];verify($module->receiveIncidentSmsResponse($reply)['state']==='recorded','Actual facade rejected bound incident response');verify($module->incidents->get($incident['id'])['responses']['person_a']['response']==='safe','Actual incident response was not persisted');$enabled['announcement_sms']['recipients'][0]['enabled']=false;file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));refused(fn()=>$module->receiveIncidentSmsResponse(array_replace($reply,['event_id'=>'SMreassigned'])),'unique');
// Execute the actual mainclass trigger facade: external operation is activate, then removed before service defaults to alert.
$action=AutomationConfig::action(['id'=>'act_'.str_repeat('a',24),'name'=>'Fixture action','enabled'=>true,'kind'=>'brightsign_udp','host'=>'192.168.1.20','port'=>5000,'message'=>'fixture']);$rule=AutomationConfig::rule(['id'=>'trg_'.str_repeat('b',24),'name'=>'Fixture sensor','enabled'=>true,'kind'=>'sensor','secret'=>str_repeat('c',64),'event'=>'isolated-contact','action_ids'=>[$action['id']],'allow_tests'=>true]);$enabled['automations']=['schema'=>1,'rules'=>[$rule],'actions'=>[$action]];file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));$module->automations=new AutomationService(new AutomationStore($directory.'/automations'),fn()=>$module->getActiveSettings(),static function(){throw new RuntimeException('No live notification permitted');},static function(){throw new RuntimeException('No live device action permitted');});$sensor=['operation'=>'activate','request_id'=>str_repeat('1',32),'sent_at'=>$now,'expires_at'=>$now+300,'event'=>'isolated-contact','message'=>'Fixture contact','is_test'=>true];$raw=json_encode($sensor);verify(AutomationInputs::authenticate($rule,$raw,['timestamp'=>(string)$now,'signature'=>hash_hmac('sha256',$rule['id'].'.'.$now.'.'.$raw,$rule['secret'])],$now),'Facade enrollment signature');verify($module->automationRequest($rule['id'],$sensor)['state']==='queued','Actual sensor activation facade');verify(count($module->submissions)===1,'Sensor facade dispatch count');
// Weather is independent of ordinary effects and inbound evidence. All
// provider requests below terminate in an inert adapter, never in cURL.
$enabled['runtime_enabled']=false;$enabled['announcement_sms']['recipients'][0]['enabled']=true;
$enabled['enterprise_integrations']['sensors']['enabled']='1';file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));
$help=array_replace($reply,['event_id'=>'SMpaused','body'=>'SLS '.$smsBinding['token'].' HELP']);
verify($module->receiveIncidentSmsResponse($help)['state']==='recorded'&&$module->incidents->get($incident['id'])['responses']['person_a']['response']==='needs_assistance','Paused runtime or disabled Weather rejected a currently authorized SMS human response');
$voiceReply=['binding_id'=>$binding['binding_id'],'token'=>$binding['token'],'recipient_id'=>$binding['recipient_id'],'event_id'=>'fixture-paused-voice','digit'=>'1'];
verify($module->receiveIncidentVoiceResponse($voiceReply)['state']==='recorded'&&$module->incidents->get($incident['id'])['responses']['person_a']['response']==='received','Paused runtime or disabled Weather rejected bound keypad evidence');
$status=$module->automationRequest($rule['id'],['operation'=>'status','event_id'=>$module->submissions[0]]);verify($status['state']==='queued','Paused runtime or disabled Weather hid enrolled trigger status');
$heartbeat=['operation'=>'heartbeat','request_id'=>str_repeat('7',32),'sent_at'=>time(),'expires_at'=>time()+60,'is_test'=>true];
verify($module->receiveSensorHeartbeat($rule['id'],$heartbeat)['state']==='healthy','Paused runtime or disabled Weather rejected authenticated sensor-health evidence');
refused(fn()=>$module->automationRequest($rule['id'],array_replace($sensor,['request_id'=>str_repeat('8',32)])),'stopped');

$module->provider=new class extends \SLS\MassNotify\EnterpriseIntegrationsProvider {
 public array $calls=[];
 protected function request(string $url,string $body,array $headers,?array $device=null):array {
  $this->calls[]=compact('url','body','headers');
  if(str_starts_with($url,'https://api.zoom.us/'))return ['status'=>201,'error'=>0,'body'=>'{"id":"fixture-meeting","join_url":"https://zoom.us/j/fixture"}'];
  return ['status'=>200,'error'=>0,'body'=>str_contains($body,'tdc:GetDoorState')?'{"DoorState":{"DoorPhysicalState":"Closed"}}':'{}'];
 }
};
$enabled['enterprise_integrations']['meetings']['enabled']='1';$enabled['enterprise_integrations']['meetings']['zoom_access_token']='fixture-token';
$enabled['enterprise_integrations']['access_control']=array_replace($enabled['enterprise_integrations']['access_control'],['enabled'=>'1','origin'=>'https://door.example.test','pinned_ipv4'=>'192.168.1.20','username'=>'fixture','password'=>'fixture',
 'doors'=>[['id'=>'door_'.str_repeat('e',24),'name'=>'Fixture door','token'=>'fixture-token','allow_read'=>true,'allow_lock'=>true,'allow_unlock'=>false]]]);
$enabled['labs_safety']=$pageSettings['labs_safety'];$enabled['runtime_enabled']=true;file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));
$meeting=['request_id'=>str_repeat('9',32),'start_at'=>time()+60,'duration_minutes'=>30];
verify($module->createIncidentMeeting($incident['id'],$meeting)['state']==='accepted','Weather-disabled actual meeting facade rejected an enabled provider');
$door=['request_id'=>str_repeat('a',32),'door_id'=>'door_'.str_repeat('e',24),'operation'=>'lock'];
verify($module->controlIntegrationDoor($door)['state']==='accepted'&&count($module->provider->calls)===2,'Weather-disabled actual door facade did not submit exactly one approved command');
$enabled['runtime_enabled']=false;file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));
refused(fn()=>$module->createIncidentMeeting($incident['id'],array_replace($meeting,['request_id'=>str_repeat('b',32)])),'stopped');
refused(fn()=>$module->controlIntegrationDoor(array_replace($door,['request_id'=>str_repeat('c',32)])),'stopped');
refused(fn()=>$module->sendIntegrationSpeakerAnnouncement([]),'stopped');
refused(fn()=>$module->submitPublicWarning([]),'stopped');verify(count($module->provider->calls)===2,'Stopped provider action reached its transport');
verify($module->controlIntegrationDoor(array_replace($door,['operation'=>'read']))['state']==='observed'&&count($module->provider->calls)===3,'Paused runtime blocked administrator read-only controller telemetry');
$warning=['identifier'=>'fixture-local-test','sender'=>'fixture@example.org','sent'=>gmdate('Y-m-d\TH:i:s\Z'),'expires'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'event'=>'Fixture exercise','headline'=>'Fixture only','description'=>'No transmission','area_description'=>'Fixture triangle','polygon'=>'40,-90 41,-90 40,-89 40,-90'];
verify($module->exportPublicWarning($warning)['status']==='Test'&&count($module->provider->calls)===3,'Paused runtime blocked local CAP export or contacted a provider');
$enabled['enterprise_integrations']['enabled']='0';file_put_contents(IntegratedLabsFixture::SETTINGS_JSON,json_encode($enabled));
verify($module->receiveIncidentSmsResponse(array_replace($help,['event_id'=>'SMdisabled']))['state']==='ignored','Disabled response feature still accepted participant input');
refused(fn()=>$module->controlIntegrationDoor(array_replace($door,['operation'=>'read'])),'disabled');
echo "Actual mainclass facade fixtures passed: administrator/API/portal boundaries, CSRF, stale revision race, danger gates, frozen metadata, persisted incident reply/revocation and sensor operation contract; Weather-independent effects and paused-runtime evidence.\n";
