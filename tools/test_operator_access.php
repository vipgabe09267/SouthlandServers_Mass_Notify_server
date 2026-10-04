<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\OperatorAccess as Access;
use SLS\MassNotify\ApiSecurity;
use SLS\MassNotify\LocationDirectory as Directory;
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentStore;
class FreePBX {} // No host bootstrap or database.
class ampuser {
    public static array $rows=[];
    public function __construct(public string $username, public string $source='database') {}
    public function getAmpUser($username) { return self::$rows[$this->source.':'.$username] ?? false; }
}
$n=0;
function operatorCheck(bool $ok,string $message):void { global $n; $n++; if(!$ok)throw new RuntimeException($message); }
function operatorReject(callable $f):void { try{$f();}catch(DomainException|InvalidArgumentException $e){operatorCheck(true,'Rejected');return;}throw new RuntimeException('Malformed operator policy accepted.'); }
$login=['username'=>'alex','mode'=>'database','password_sha1'=>str_repeat('a',40),'sections'=>['slsmassnotifyserver_operations']];
ampuser::$rows['database:alex']=$login;
ampuser::$rows['database:owner']=['username'=>'owner','mode'=>'database','password_sha1'=>str_repeat('b',40),'sections'=>['*']];
$site=Directory::node(['id'=>'loc_'.str_repeat('a',24),'name'=>'Main site','type'=>'site','members'=>[]]);
$room=Directory::node(['id'=>'loc_'.str_repeat('b',24),'name'=>'Office','type'=>'room','parent_id'=>'loc_'.str_repeat('d',24),'members'=>['extensions'=>['1000'],'desktop_client_ids'=>['cli_alex']]]);
$building=Directory::node(['id'=>'loc_'.str_repeat('c',24),'name'=>'Building','type'=>'building','parent_id'=>$site['id']]);
$floor=Directory::node(['id'=>'loc_'.str_repeat('d',24),'name'=>'Floor','type'=>'floor','parent_id'=>$building['id']]);
$other=Directory::node(['id'=>'loc_'.str_repeat('e',24),'name'=>'Other site','type'=>'site','members'=>['extensions'=>['2000'],'desktop_client_ids'=>['cli_other']]]);
$row=['id'=>'api_'.str_repeat('f',24),'username'=>'alex','source'=>'database','identity'=>Access::identity($login),'name'=>'Alex','role'=>'sender','enabled'=>true,'site_ids'=>[$site['id']],'group_ids'=>[]];
$settings=['enabled'=>'1','operator_access'=>['schema'=>1,'enabled'=>true,'accounts'=>[$row]],'location_directory'=>Directory::normalize(['nodes'=>[$site,$building,$floor,$room,$other]]),
    'desktop_clients'=>[['client_id'=>'cli_alex','username'=>'alex_pc','enabled'=>'1','name'=>'Alex desktop'],['client_id'=>'cli_other','username'=>'other_pc','enabled'=>'1','name'=>'Private desktop']], 'announcement_groups'=>[], 'control_api'=>['enabled'=>'0','credentials'=>[]]];
$p=Access::principal($settings,$row['id']);
operatorCheck($p!==null && Access::localIdentityCurrent($p),'Current PBX login rejected.');
operatorCheck(ApiSecurity::currentCredential($settings,$row['id'])!==null,'Local roles wrongly depend on enabling the network Control API.');
$allowed=ApiSecurity::allowedAudience($p,$settings);
operatorCheck($allowed['phones']===['1000'] && $allowed['desktops']===['alex_pc'],'Site scope crossed another site.');
operatorCheck(Access::delivery($p,['extensions'=>['1000']],$settings),'Authorized phone rejected.');
operatorCheck(!Access::delivery($p,['extensions'=>['2000']],$settings),'Off-site phone permitted.');
operatorCheck(!Access::delivery($p,['desktop_clients'=>['other_pc']],$settings),'Off-site desktop permitted.');
operatorCheck(!Access::delivery($p,['extensions'=>['1000'],'group_ids'=>['grp_'.str_repeat('a',24)]],$settings),'Unresolved group bypassed review.');
foreach (Access::ROLES as $role) {
    $copy=$settings;$copy['operator_access']['accounts'][0]['role']=$role;
    if($role==='administrator')$copy['operator_access']['accounts'][0]['site_ids']=[];
    $principal=Access::principal($copy,$row['id']);
    operatorCheck(Access::may($principal,'send')===in_array($role,['sender','administrator'],true),'Send role leaked.');
    operatorCheck(Access::may($principal,'schedule')===in_array($role,['scheduler','administrator'],true),'Scheduler role leaked.');
    operatorCheck(Access::may($principal,'roll_call')===in_array($role,['warden','administrator'],true),'Warden role leaked.');
}
foreach ([['role'=>'superadmin'],['source'=>'remote'],['identity'=>'bad'],['enabled'=>'1'],['site_ids'=>[$site['id'],$site['id']]],['group_ids'=>['anything']],['unknown'=>true],['role'=>'administrator'],['site_ids'=>[]]] as $patch) {
    operatorReject(fn()=>Access::normalize(['accounts'=>[array_replace($row,$patch)]]));
}
$copy=$settings;$copy['operator_access']['enabled']=false;operatorCheck(ApiSecurity::currentCredential($copy,$row['id'])===null,'Disabled feature retained job authority.');
$copy=$settings;$copy['operator_access']['accounts'][0]['enabled']=false;operatorCheck(ApiSecurity::currentCredential($copy,$row['id'])===null,'Disabled account retained job authority.');
$key=ApiSecurity::issue(['name'=>'Fixture','scopes'=>['send'],'audience'=>['unrestricted'=>true]]);$key['credential']['id']=$row['id'];
$copy=$settings;$copy['control_api']=['enabled'=>'1','credentials'=>[$key['credential']]];
operatorCheck(ApiSecurity::currentCredential($copy,$row['id'])===null,'Operator ID collision fell through to a network key.');
operatorCheck(ApiSecurity::authenticate($settings['control_api'],$row['id'])===null,'Synthetic role identifier became a network credential.');
foreach ([['password_sha1'=>str_repeat('c',40)],['sections'=>[]],['username'=>'replaced']] as $patch) {
    ampuser::$rows['database:alex']=array_replace($login,$patch);operatorCheck(ApiSecurity::currentCredential($settings,$row['id'])===null,'Changed PBX identity retained delivery authority.');
}
unset(ampuser::$rows['database:alex']);operatorCheck(ApiSecurity::currentCredential($settings,$row['id'])===null,'Removed PBX login retained delivery authority.');ampuser::$rows['database:alex']=$login;
$identities=['desktop_clients'=>['alex_pc'=>hash('sha256',"cli_alex\0alex_pc")]];
operatorCheck(Access::person($p,['desktop_username'=>'alex_pc'],$settings,$identities),'Frozen participant identity rejected.');
operatorCheck(!Access::person($p,['desktop_username'=>'alex_pc'],$settings,['desktop_clients'=>['alex_pc'=>str_repeat('a',64)]]),'Reused desktop authorized a person response.');
operatorCheck(Access::person($p,['location_id'=>$room['id']],$settings,[]),'Physical roster assignment rejected.');
operatorCheck(!Access::person($p,['location_id'=>$other['id']],$settings,[]),'Warden crossed a site boundary.');
foreach([$building['id'],$floor['id'],$room['id']] as $location){
    $scoped=$p;$scoped['site_ids']=[];$scoped['location_ids']=[$location];
    operatorCheck(Access::person($scoped,['location_id'=>$room['id']],$settings,[]),'Building/floor/room roster grant was ignored.');
    operatorCheck(!Access::person($scoped,['location_id'=>$other['id']],$settings,[]),'Location-scoped warden crossed a site boundary.');
    if($location!==$building['id'])operatorCheck(!Access::person($scoped,['location_id'=>$building['id']],$settings,[]),'Location grant authorized an ancestor.');
}
$copy=$settings;$copy['location_directory']=Directory::defaults();operatorCheck(!Access::person($p,['location_id'=>$room['id']],$copy,[]),'Deleted site retained roster authority.');
$root=sys_get_temp_dir().'/sls-operators-'.bin2hex(random_bytes(8));mkdir($root,0700);
define('SLS_TEST_OP_DIRECTORY',$root);
class OperatorFixture {
    public $FreePBX;
    const PLUGIN_DATA_DIR=SLS_TEST_OP_DIRECTORY;
    const SETTINGS_JSON=self::PLUGIN_DATA_DIR.'/mass-notifications.config';
    const PENDING_SETTINGS_JSON=self::PLUGIN_DATA_DIR.'/mass-notifications.pending.config';
    use \FreePBX\modules\SlsOperators;
    use \FreePBX\modules\SlsIncidents;
    use \FreePBX\modules\SlsLocations;
    public function getAllPjsipExtensions(){return [['extension'=>'1000','name'=>'Office'],['extension'=>'2000','name'=>'Other']];}
    public function getOutboundVoiceRecipients($settings){return [];}
    public function getAnnouncementEmailRecipients($settings){return [];}
    public function getAnnouncementSmsRecipients($settings){return [];}
    public function getPbxDateTimeZone(){return new DateTimeZone('UTC');}
    public array $settings=[], $sent=[], $written=[];
    public function getActiveSettings(){return $this->settings;}
    public function getPendingSettings(){return null;}
    public function operatorDirectory():array {$this->currentOperator();return ['accounts'=>[['username'=>'alex','source'=>'database','identity'=>\SLS\MassNotify\OperatorAccess::identity(ampuser::$rows['database:alex']),'eligible'=>true]],'warnings'=>[]];}
    public function getConfiguredPjsipExtensionNumbers(){return ['1000','2000'];}
    public function getAnnouncementGroups(){return $this->settings['announcement_groups']??[];}
    public function getDesktopClients($settings){return $settings['desktop_clients']??[];}
    public function outboundVoiceTargets($settings){return [];}
    public function normalizeWebhookDestinations($settings,$kind){return [];}
    public function getAvailableTones(){return [];}
    public function getAvailableSystemSounds(){return [['value'=>'system:custom/test.wav','label'=>'Saved test recording']];}
    public array $recordingImports=[];
    private function announcementTone($selection,$label,array &$errors):string{$this->recordingImports[]=$selection;return 'recording_fixture';}
    private function acquireAnnouncementActivityLock(...$args){return null;}
    private function releaseNativeBackupFileLock($lease){}
    public function sendSipNotifyAnnouncement(...$args){$this->sent[]=['args'=>$args,'principal'=>$GLOBALS['sls_control_principal']??null];return ['success'=>true];}
    public function saveScheduledAnnouncement($input){$this->sent[]=['schedule'=>$input,'origin'=>$this->operatorScheduleOrigin];return ['success'=>true];}
    protected function submitIncidentAnnouncement(array $delivery,string $message,string $title,array $context,array $actor):array{return ['success'=>true,'job_id'=>'job_'.str_repeat('a',32)];}
    private function acquireSettingsLock(){return 'fixture-lock';}
    private function releaseSettingsLock($lock){}
    private function loadSettingsFile($path){return $this->written[$path]??$this->settings;}
    private function writeSettingsFileUnlocked($path,$settings,$active){$this->written[$path]=$settings;if($active)$this->settings=$settings;}
    public function accessRevision(){return $this->operatorRevision($this->settings);}
    public function project($record){return $this->operatorIncidentProjection($record,$this->currentOperator());}
}
eval('class LocalOperatorFixture extends OperatorFixture { const PLUGIN_DATA_DIR='.var_export($root,true).'; const SETTINGS_JSON= self::PLUGIN_DATA_DIR."/mass-notifications.config"; const PENDING_SETTINGS_JSON=self::PLUGIN_DATA_DIR."/mass-notifications.pending.config"; }');
try {
    $m=new LocalOperatorFixture();$m->settings=$settings;$_SESSION['AMP_user']=new ampuser('alex');
    operatorCheck(!$m->operatorPageAllowed('slsmassnotifyserver_other') && $m->operatorPageAllowed('slsmassnotifyserver_operations'),'Restricted user accessed configuration.');
    $GLOBALS['sls_control_principal']=['id'=>'original'];
    operatorCheck($m->operatorAction('send',['delivery'=>['extensions'=>['1000']],'title'=>'Notice','message'=>'Fixture'])['success'],'Scoped announcement failed.');
    operatorCheck($m->sent[0]['principal']['id']===$row['id'] && $GLOBALS['sls_control_principal']['id']==='original','Actor lost durable authority or leaked global principal.');
    operatorCheck(!$m->operatorAction('send',['delivery'=>['extensions'=>['2000']],'title'=>'Notice','message'=>'Fixture'])['success'] && count($m->sent)===1,'Off-site action reached delivery.');
    $offsite=$m->operatorAction('preview',['delivery'=>['extensions'=>['2000'],'opening_tone'=>'system:custom/test.wav'],'title'=>'Notice','message'=>'Fixture']);
    operatorCheck(!$offsite['success'] && $m->recordingImports===[],'Unauthorized operator imported a System Recording.');
    $selected=$m->operatorAction('preview',['delivery'=>['extensions'=>['1000'],'opening_tone'=>'system:custom/test.wav'],'title'=>'Notice','message'=>'Fixture']);
    operatorCheck($selected['success'] && $m->recordingImports===['system:custom/test.wav'] && $m->sent[1]['args'][5]['opening_tone']==='recording_fixture','Authorized System Recording did not freeze before submission.');
    array_pop($m->sent);
    operatorCheck(count($m->operatorOperationsState()['recordings'])===1,'Operations omitted the System Recording catalogue.');
    operatorCheck(!$m->operatorAction('save_schedule',['delivery'=>['extensions'=>['1000']],'title'=>'Notice','message'=>'Fixture'])['success'],'Sender created a schedule.');
    $m->settings['operator_access']['accounts'][0]['role']='scheduler';
    operatorCheck(!$m->operatorAction('send',['delivery'=>['extensions'=>['1000']],'title'=>'Notice','message'=>'Fixture'])['success'],'Scheduler sent immediate alert.');
    operatorCheck($m->operatorAction('save_schedule',['delivery'=>['extensions'=>['1000']],'title'=>'Notice','message'=>'Fixture','name'=>'Saved','occurrences'=>['2026-12-01T08:00']])['success'] && $m->sent[1]['origin']===$row['id'],'Schedule lost its originating operator.');
    unset($GLOBALS['sls_control_principal']);
    $m->settings['operator_access']['accounts'][0]['role']='warden';
    $template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('a',24),'name'=>'Fixture','title'=>'Notice','message'=>'Fixture message','delivery'=>['desktop_clients'=>['alex_pc','other_pc']],
        'roster'=>[['id'=>'alex_person','name'=>'Alex','location'=>'Office','location_id'=>$room['id'],'desktop_username'=>'alex_pc'],['id'=>'private_person','name'=>'Private name','location'=>'Secret place','location_id'=>$other['id'],'desktop_username'=>'other_pc']]]);
    $m->settings['incident_workflows']=['schema'=>1,'templates'=>[$template]];
    $incident=$m->startIncident(['template_id'=>$template['id'],'request_id'=>str_repeat('c',32),'is_test'=>true]);operatorCheck($incident['success'],'Isolated incident fixture failed: '.json_encode($incident));
    $listed=$m->operatorOperationsState();operatorCheck(count($listed['incidents'])===1 && count($listed['incidents'][0]['roster'])===1 && !str_contains(json_encode($listed),'Private name'),'Real incident history failed to load its scoped roster.');
    $projection=$m->project($incident);operatorCheck(count($projection['roster'])===1 && $projection['response_counts']['expected']===1 && !str_contains(json_encode($projection),'Private name') && !$projection['can_update'],'Warden projection exposed another site.');
    $m->settings['operator_access']['accounts'][0]['role']='sender';
    $ladder=IncidentConfig::template(['id'=>'tpl_'.str_repeat('b',24),'name'=>'Scoped ladder','title'=>'Notice','message'=>'Fixture',
        'delivery'=>['extensions'=>['1000']], 'roster'=>[$template['roster'][0]], 'escalation'=>['enabled'=>true,'steps'=>[
            ['name'=>'Local follow-up','after_seconds'=>60,'delivery'=>['extensions'=>['1000']]],
            ['name'=>'Off-site follow-up','after_seconds'=>120,'delivery'=>['extensions'=>['2000']]]]]]);
    $m->settings['incident_workflows']['templates'][]=$ladder;
    operatorCheck(!in_array($ladder['id'],array_column($m->operatorOperationsState()['templates'],'id'),true),'A later off-site escalation bypassed template scope.');
    operatorCheck(!$m->operatorAction('start_incident',['template_id'=>$ladder['id'],'request_id'=>str_repeat('f',32),'fields'=>[],'is_test'=>true])['success'],'Scoped sender launched an unauthorized later step.');
    $projected=$m->project(array_replace($incident,['template'=>$ladder]));
    operatorCheck($projected && !$projected['can_update'],'Later-step permission was ignored when projecting incident update authority.');
    $m->settings['operator_access']['accounts'][0]['role']='warden';
    $before=(new IncidentStore($root.'/incidents'))->read($incident['id']);
    operatorCheck(!$m->operatorAction('roll_call',['incident_id'=>$incident['id'],'person_id'=>'private_person','request_id'=>str_repeat('d',32),'response'=>'safe'])['success'],'Warden recorded off-site response.');
    operatorCheck((new IncidentStore($root.'/incidents'))->read($incident['id'])===$before,'Rejected roster action changed durable record.');
    $input=['incident_id'=>$incident['id'],'person_id'=>'alex_person','request_id'=>str_repeat('e',32),'response'=>'safe','note'=>'Verified in fixture'];
    $result=$m->operatorAction('roll_call',$input);operatorCheck($result['success'] && $result['incident']['response_counts']['safe']===1,'Assigned warden response failed.');
    operatorCheck($m->operatorAction('roll_call',$input)===$result,'Duplicate response was not idempotent.');
    $m->settings['operator_access']['accounts'][0]['enabled']=false;operatorCheck(!$m->operatorAction('roll_call',$input)['success'],'Revoked warden retained response authority.');
    $_SESSION['AMP_user']=new ampuser('owner');operatorCheck($m->operatorPageAllowed('slsmassnotifyserver_operators'),'Full PBX administrator lost recovery access.');
    ampuser::$rows['usermanager:um_admin']=['username'=>'um_admin','id'=>42,'mode'=>'usermanager','password_sha1'=>str_repeat('b',40),'sections'=>[]];
    $um = new class { public bool $admin=true; public bool $login=true; public function getCombinedGlobalSettingByID($id,$name) { return $id===42 && ($name==='pbx_admin' ? $this->admin : $this->login); } };
    $m->FreePBX=(object)['Userman'=>$um]; $_SESSION['AMP_user']=new ampuser('um_admin','usermanager');
    operatorCheck($m->operatorPageAllowed('slsmassnotifyserver_operators') && $m->operatorPageAllowed('slsmassnotifyserver_operations'),'Inherited User Management administrator denied');
    $um->admin=false;operatorCheck(!$m->operatorPageAllowed('slsmassnotifyserver_operators'),'Revoked administrator retained access');
    ampuser::$rows['usermanager:um_admin']['sections']=['*'];$um->login=false;
    operatorCheck(!$m->operatorPageAllowed('slsmassnotifyserver_operations'),'Disabled User Management login retained wildcard administrator access');
    $um->admin=true;$um->login=false;operatorCheck(!$m->operatorPageAllowed('slsmassnotifyserver_operations'),'Revoked PBX login retained access');
    $_SESSION['AMP_user']=new ampuser('owner');
    touch(LocalOperatorFixture::PENDING_SETTINGS_JSON);
    $m->written[LocalOperatorFixture::PENDING_SETTINGS_JSON]=['unrelated'=>'pending-value'];
    $save=['revision'=>$m->accessRevision(),'enabled'=>true,'accounts'=>[array_diff_key($row,['identity'=>true]) + ['rebind'=>false]]];
    $saved=$m->saveOperatorAccess($save);operatorCheck($saved['success'],'Valid administrator role save failed: '.($saved['message']??''));
    operatorCheck($m->written[LocalOperatorFixture::PENDING_SETTINGS_JSON]['unrelated']==='pending-value' && $m->written[LocalOperatorFixture::PENDING_SETTINGS_JSON]['operator_access']===$m->settings['operator_access'],'Role save lost staged configuration or failed to mirror permissions.');
    operatorCheck(!$m->saveOperatorAccess($save)['success'],'Stale role revision overwrote a new policy.');
    $_SESSION['AMP_user']=new ampuser('alex');operatorCheck(!$m->saveOperatorAccess($save)['success'],'Sender changed module-wide permissions.');
    // Dedicated account creation/reset uses the same protected write path.
    $_SESSION['AMP_user']=new ampuser('owner');
    $m->settings['desktop_auth_key']=base64_encode(str_repeat('q',32));
    $m->written[LocalOperatorFixture::SETTINGS_JSON]=$m->settings;
    $new=['id'=>'','username'=>'portal_person','source'=>'portal','name'=>'Person','role'=>'sender','enabled'=>true,
        'site_ids'=>[],'location_ids'=>[$room['id']],'group_ids'=>[],'personal_members'=>['extensions'=>['1000']],
        'actions'=>['view','send','schedule'],'channels'=>['extensions'],'password'=>'initial dedicated fixture passphrase 721','reset_totp'=>false];
    $saved=$m->saveOperatorAccess(['revision'=>$m->accessRevision(),'enabled'=>false,'portal_enabled'=>true,'accounts'=>[$new]]);
    operatorCheck($saved['success'],'Dedicated account creation failed: '.($saved['message']??''));
    $account=$m->settings['operator_access']['accounts'][0]; $id=$account['id'];
    operatorCheck(\SLS\MassNotify\OperatorAuth::verify($new['password'],$account['auth']['password_hash']),'Saved password hash does not verify.');
    operatorCheck(!str_contains(json_encode($m->settings),$new['password']),'Plaintext initial password was stored.');
    operatorCheck(\SLS\MassNotify\ApiSecurity::currentCredential($m->settings,$id)===null,'Unenrolled account gained delivery authority.');
    $changed=$m->operatorAuthUpdate($id,$account['identity'],'password','','','personal dedicated fixture passphrase 826');
    $secret=\SLS\MassNotify\OperatorAuth::secret();
    $enrolled=$m->operatorAuthUpdate($id,$changed['account']['identity'],'enroll',\SLS\MassNotify\OperatorAuth::code($secret,intdiv(time(),30)),$secret);
    operatorCheck(count($enrolled['recovery_codes'])===10 && \SLS\MassNotify\OperatorAuth::ready($enrolled['account']),'Real account enrollment failed.');
    $identity=$enrolled['account']['identity'];
    operatorReject(fn()=>$m->operatorAuthUpdate($id,$identity,'totp',\SLS\MassNotify\OperatorAuth::code($secret,intdiv(time(),30))));
    $editorRevision=$m->accessRevision();
    $used=$m->operatorAuthUpdate($id,$identity,'totp',$enrolled['recovery_codes'][0]);
    operatorCheck(count($used['account']['auth']['recovery_hashes'])===9,'Recovery code was not consumed.');
    operatorCheck($m->accessRevision()===$editorRevision,'A normal sign-in invalidated the administrator editor.');
    operatorReject(fn()=>$m->operatorAuthUpdate($id,$identity,'totp',$enrolled['recovery_codes'][0]));
    $state=$m->operatorAccessState();$projected=json_encode($state['access']['accounts']);
    foreach ($state['devices'] as $channel=>$choices) {
        operatorCheck(array_is_list($choices),'Account editor device choices are keyed by recipient ID: '.$channel);
        operatorCheck(array_is_list(json_decode(json_encode($choices),true)),'JSON device choices are not a list: '.$channel);
    }
    operatorCheck(in_array('1000',array_column($state['devices']['extensions'],'id'),true),'Device-choice projection changed the numeric extension identity.');
    operatorCheck(!str_contains($projected,$secret)&&!str_contains($projected,$used['account']['auth']['password_hash'])&&!isset($state['access']['accounts'][0]['auth']),'Editor exposed MFA/password material.');
    $public=$state['access']['accounts'][0];
    foreach(['identity_current','totp_enrolled','recovery_remaining','password_change_required','password_recovery'] as $key)unset($public[$key]);
    operatorCheck($m->saveOperatorAccess(['revision'=>$m->accessRevision(),'enabled'=>false,'portal_enabled'=>false,'accounts'=>[$public]])['success'],'Portal disable failed.');
    operatorCheck($m->portalAccount('portal_person')===null && \SLS\MassNotify\ApiSecurity::currentCredential($m->settings,$id)===null,'Disabled portal retained login or queued authority.');
    operatorReject(fn()=>$m->operatorAuthUpdate($id,$identity,'totp',$enrolled['recovery_codes'][1]));
    operatorCheck($m->saveOperatorAccess(['revision'=>$m->accessRevision(),'enabled'=>false,'portal_enabled'=>true,'accounts'=>[$public]])['success'],'Portal re-enable failed.');
    operatorCheck(!hash_equals($identity,$m->portalAccount('portal_person')['identity']),'Re-enabling the portal revived an old session.');
    $public['reset_totp']=true;
    operatorCheck(!$m->saveOperatorAccess(['revision'=>$m->accessRevision(),'enabled'=>false,'accounts'=>[$public]])['success'],'Authenticator reset without a new password was allowed.');
    $public['password']='reset dedicated fixture passphrase 941';
    operatorCheck($m->saveOperatorAccess(['revision'=>$m->accessRevision(),'enabled'=>false,'accounts'=>[$public]])['success'],'Reviewed login reset failed.');
    operatorCheck(\SLS\MassNotify\ApiSecurity::currentCredential($m->settings,$id)===null,'Reset login retained queued authority before enrollment.');
    operatorReject(fn()=>$m->operatorAuthUpdate($id,$identity,'totp',$enrolled['recovery_codes'][1]));
    operatorCheck(!str_contains(json_encode($m->settings),'reset dedicated fixture passphrase 941'),'Reset password was stored in cleartext.');
    echo "$n operator access checks passed.\n";
} finally {
    unset($GLOBALS['sls_control_principal']);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
