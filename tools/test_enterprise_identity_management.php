<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentityManagement.php';
require_once __DIR__.'/../slsmassnotifyserver/ConfigCrypto.php';
use SLS\MassNotify\{EnterpriseIdentityConfig,DirectoryConfig,SubscriberConfig,IncidentConfig,IncidentStore,IncidentService,OperatorAuth,DirectoryConfig as DC};
use FreePBX\modules\SlsConfigCrypto;
function emcheck($ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function emrevision($module,string $key):string{$class=match($key){'enterprise_identity'=>EnterpriseIdentityConfig::class,'directory_sync'=>DirectoryConfig::class,'subscriber_browser'=>SubscriberConfig::class};return hash('sha256',json_encode($class::normalize($module->getActiveSettings()[$key]??[]),JSON_THROW_ON_ERROR));}
$dir=sys_get_temp_dir().'/sls-identity-management-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('SLS_IDENTITY_FIXTURE',$dir);
register_shutdown_function(static function()use($dir){$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($i as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);});
class EnterpriseIdentityFixture
{
 use \FreePBX\modules\SlsEnterpriseLabs;
 const PLUGIN_DATA_DIR=SLS_IDENTITY_FIXTURE;
 const SETTINGS_JSON=SLS_IDENTITY_FIXTURE.'/active.config';const PENDING_SETTINGS_JSON=SLS_IDENTITY_FIXTURE.'/pending.config';
 public bool $admin=true;public bool $busy=false;public ?Closure $race=null;public array $order=[];public array $sent=[];
 protected function assertEnterpriseAdministrator():void{if(!$this->admin){throw new DomainException('PBX administrator required.');}}
 private function acquireAnnouncementActivityLock(...$args){$this->order[]='activity';if($this->busy){return null;}$h=fopen(self::PLUGIN_DATA_DIR.'/activity.lock','c+b');flock($h,LOCK_EX);return $h;}
 private function releaseNativeBackupFileLock($h):void{fclose($h);}
 private function acquireSettingsLock(...$args){$this->order[]='settings';if($this->race){$race=$this->race;$this->race=null;$race($this);}$h=fopen(self::PLUGIN_DATA_DIR.'/settings.lock','c+b');flock($h,LOCK_EX);return $h;}
 private function releaseSettingsLock($h):void{fclose($h);}
 public function getActiveSettings():array{return $this->loadSettingsFile(self::SETTINGS_JSON);}
 private function loadSettingsFile($p):array{return SlsConfigCrypto::readFile($p);}
 private function normalizeSettings($s):array{foreach(['enterprise_identity'=>EnterpriseIdentityConfig::class,'directory_sync'=>DirectoryConfig::class,'subscriber_browser'=>SubscriberConfig::class,'incident_workflows'=>IncidentConfig::class] as $k=>$c){$s[$k]=$c::normalize($s[$k]??[]);}return $s;}
 protected function assertEnterpriseActivationSafe(array $s):void{}
 private function rememberSettingsFingerprint($p):void{}
 private function writeSettingsFileUnlocked($p,$s,$backup):void{$bytes=SlsConfigCrypto::encode($s);file_put_contents($p,$bytes);chmod($p,0640);}
 protected function incidentStore():IncidentStore{return new IncidentStore(self::PLUGIN_DATA_DIR.'/incidents');}
 protected function sendOperatorRecoveryEmail($settings,$recipient,$subject,$body):string{$this->sent[]=compact('recipient','subject','body');return 'accepted';}
}
$module=new EnterpriseIdentityFixture();$template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('e',24),'name'=>'Template','title'=>'Active title','message'=>'Active message','delivery'=>['extensions'=>['1000']],'roster'=>[['id'=>'manual','name'=>'Manual Person']]]);
$source=['id'=>'dir_'.str_repeat('e',24),'type'=>'csv','name'=>'CSV source','enabled'=>true,'template_ids'=>[$template['id']],'sync_subscribers'=>true,'records'=>[]];
$settings=['unrelated'=>'preserve active','desktop_auth_key'=>base64_encode(str_repeat('k',32)),'enterprise_identity'=>EnterpriseIdentityConfig::defaults(),'directory_sync'=>DirectoryConfig::normalize(['enabled'=>true,'sources'=>[$source]]),'subscriber_browser'=>SubscriberConfig::defaults(),'incident_workflows'=>['schema'=>1,'templates'=>[$template]],'operator_access'=>['schema'=>1,'portal_enabled'=>true,'enabled'=>false,'accounts'=>[]]];
$account=['id'=>'api_'.str_repeat('e',24),'username'=>'fixture-owner','name'=>'Fixture Owner','email'=>'owner@example.test','source'=>'portal','role'=>'administrator','enabled'=>true,'site_ids'=>[],'group_ids'=>[],'personal_members'=>[]];
$account['auth']=['password_hash'=>OperatorAuth::dummyHash(),'version'=>str_repeat('e',64),'force_password_change'=>false,'totp_secret_enc'=>OperatorAuth::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$account['id'],$settings),'totp_last_counter'=>-1,'recovery_hashes'=>[]];$account['identity']=OperatorAuth::identity($account);$settings['operator_access']['accounts'][]=$account;
file_put_contents(EnterpriseIdentityFixture::SETTINGS_JSON,SlsConfigCrypto::encode($settings));chmod(EnterpriseIdentityFixture::SETTINGS_JSON,0640);$pending=$settings;$pending['unrelated']='preserve pending';$pending['incident_workflows']['templates'][0]['title']='Pending edited title';$pending['incident_workflows']['templates'][0]['roster'][]=['id'=>'pending-manual','name'=>'Pending Manual Person','location'=>'','desktop_username'=>''];file_put_contents(EnterpriseIdentityFixture::PENDING_SETTINGS_JSON,SlsConfigCrypto::encode($pending));chmod(EnterpriseIdentityFixture::PENDING_SETTINGS_JSON,0640);
$module->admin=false;$denied=$module->enterpriseLabsAction('directory_preview',['source_id'=>$source['id'],'csv'=>"external_id,name\n1,Person One\n"]);emcheck(!$denied['success'],'Non-admin directory access accepted');$module->admin=true;
$preview=$module->enterpriseLabsAction('directory_preview',['source_id'=>$source['id'],'csv'=>"external_id,name,email\n1,Person One,person@example.test\n"]);emcheck($preview['success'],'Dry run failed: '.($preview['message']??''));emcheck(count($module->getActiveSettings()['incident_workflows']['templates'][0]['roster'])===1,'Dry run wrote roster');
$apply=$module->enterpriseLabsAction('directory_apply',['plan_id'=>$preview['plan_id']]);emcheck($apply['success'],'Apply failed: '.($apply['message']??''));emcheck(array_slice($module->order,-2)===['activity','settings'],'Config mutation lock order incorrect');$active=$module->getActiveSettings();$staged=SlsConfigCrypto::readFile(EnterpriseIdentityFixture::PENDING_SETTINGS_JSON);
emcheck($active['unrelated']==='preserve active'&&$staged['unrelated']==='preserve pending'&&$staged['incident_workflows']['templates'][0]['title']==='Pending edited title','Pending or unrelated edit overwritten');emcheck(count($active['incident_workflows']['templates'][0]['roster'])===2&&count($staged['incident_workflows']['templates'][0]['roster'])===3,'Import omitted pending manual roster');emcheck(!$module->enterpriseLabsAction('directory_apply',['plan_id'=>$preview['plan_id']])['success'],'Applied directory plan replay accepted');
$preview=$module->enterpriseLabsAction('directory_preview',['source_id'=>$source['id'],'csv'=>"external_id,name,email\n1,Person One,person@example.test\n"]);$changed=$active;$changed['incident_workflows']['templates'][0]['title']='Concurrent administrator title';file_put_contents(EnterpriseIdentityFixture::SETTINGS_JSON,SlsConfigCrypto::encode($changed));emcheck(!$module->enterpriseLabsAction('directory_apply',['plan_id'=>$preview['plan_id']])['success'],'Stale preview overwrote concurrent administrator edit');
$public=$module->getActiveSettings()['subscriber_browser'];$person=$public['people'][0];$public['enabled']=true;$public['public_base_url']='https://pbx.example.test/mass-notify/subscriber.php';$save=$module->enterpriseLabsAction('subscriber_save',['config'=>$public,'revision'=>emrevision($module,'subscriber_browser')]);emcheck($save['success']&&$save['config']['people'][0]['version']===$person['version'],'Unchanged subscriber enrollment rotated unexpectedly');
$public=$save['config'];$public['people'][0]['email']='changed@example.test';$public['people'][0]['registered_at']=1;$public['people'][0]['version']=str_repeat('0',64);$save=$module->enterpriseLabsAction('subscriber_save',['config'=>$public,'revision'=>emrevision($module,'subscriber_browser')]);emcheck($save['success']&&$save['config']['people'][0]['registered_at']>1&&$save['config']['people'][0]['version']!==str_repeat('0',64),'Client selected identity version or backdated enrollment: '.json_encode($save));
$provider=['id'=>'idp_'.str_repeat('e',24),'vendor'=>'okta','protocol'=>'oidc','enabled'=>true,'name'=>'Fixture IdP','issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php','discovery_url'=>'https://idp.example.test/discovery','client_id'=>'fixture-client','client_secret'=>'never-display-secret','token_auth'=>'client_secret_basic','endpoint_hosts'=>['idp.example.test']];
$identity=['schema'=>1,'enabled'=>true,'providers'=>[$provider],'grants'=>[['provider_id'=>$provider['id'],'subject'=>'stable-42','operator_account_id'=>$account['id'],'enabled'=>true]]];$save=$module->enterpriseLabsAction('identity_save',['config'=>$identity,'revision'=>emrevision($module,'enterprise_identity')]);emcheck($save['success']&&!str_contains(json_encode($save),'never-display-secret'),'Identity setting response leaked private secret');$resave=$module->enterpriseLabsAction('identity_save',['config'=>$save['config'],'revision'=>emrevision($module,'enterprise_identity')]);emcheck($resave['success']&&$module->getActiveSettings()['enterprise_identity']['providers'][0]['client_secret']==='never-display-secret','Blank redacted secret was not preserved');
$disable=$module->getActiveSettings()['directory_sync'];$disable['sources'][0]['enabled']=false;emcheck($module->enterpriseLabsAction('directory_save',['config'=>$disable,'revision'=>emrevision($module,'directory_sync')])['success'],'Disabling directory source failed');$deprovision=$module->enterpriseLabsAction('directory_preview',['source_id'=>$source['id'],'deprovision_all'=>true]);emcheck($deprovision['success'],'Disabled source deprovision dry run failed');emcheck($module->enterpriseLabsAction('directory_apply',['plan_id'=>$deprovision['plan_id']])['success'],'Explicit deprovision failed');$active=$module->getActiveSettings();emcheck(count($active['incident_workflows']['templates'][0]['roster'])===1,'Deprovision did not preserve only the manual active row');$staged=SlsConfigCrypto::readFile(EnterpriseIdentityFixture::PENDING_SETTINGS_JSON);emcheck(count($staged['incident_workflows']['templates'][0]['roster'])===2,'Deprovision removed pending local row');emcheck(!$module->sent,'Settings or directory actions sent an invitation');
// Every full namespace save rechecks the actual file while holding the settings lock.
foreach(['identity_save'=>'enterprise_identity','directory_save'=>'directory_sync','subscriber_save'=>'subscriber_browser'] as $action=>$key){
 $old=$module->getActiveSettings()[$key];$revision=emrevision($module,$key);
 $module->race=static function($module)use($key):void{$new=$module->getActiveSettings();
  if($key==='enterprise_identity'){$new[$key]['grants'][0]['enabled']=false;$new[$key]['providers'][0]['client_secret']='rotated-current-secret';}
  elseif($key==='directory_sync'){$new[$key]['enabled']=false;}
  else{$new[$key]['people'][0]['enabled']=false;$new[$key]['people'][0]['version']=str_repeat('c',64);}
  file_put_contents(EnterpriseIdentityFixture::SETTINGS_JSON,SlsConfigCrypto::encode($new));};
 $result=$module->enterpriseLabsAction($action,['config'=>$old,'revision'=>$revision]);
 emcheck(!$result['success']&&str_contains($result['message'],'changed'),'Stale namespace save was not rejected under the lock: '.$key);
 $new=$module->getActiveSettings();emcheck($new[$key]!==$old,'Stale namespace save overwrote a concurrent grant, source policy or revocation');
 emcheck(!$module->enterpriseLabsAction($action,['config'=>$old])['success'],'Full namespace save omitted its revision');
}
$before=$module->getActiveSettings();$module->busy=true;$order=count($module->order);
$blocked=$module->enterpriseLabsAction('subscriber_save',['config'=>$before['subscriber_browser'],'revision'=>emrevision($module,'subscriber_browser')]);
emcheck(!$blocked['success']&&$module->getActiveSettings()===$before&&array_slice($module->order,$order)===['activity'],'A missing exclusive activity lease reached settings or mutation');$module->busy=false;
echo "Enterprise management: PBX admin gate, ordered locks, stale/replayed plans, pending edits, stable enrollment, redacted secrets and explicit deprovision passed.\n";
