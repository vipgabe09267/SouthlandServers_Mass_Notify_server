<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/EnterpriseAdministration.php';
use SLS\MassNotify\{EnterpriseLabsConfig as Manager,EnterpriseClusterConfig,DirectoryConfig,SubscriberConfig,
    EnterpriseIntegrationsConfig,EnterpriseOperationsConfig,LabsSafety};
use FreePBX\modules\SlsConfigCrypto;

$root=sys_get_temp_dir().'/sls-feature-manager-'.bin2hex(random_bytes(8));mkdir($root,0700);
define('FEATURE_MANAGER_FIXTURE',$root);
register_shutdown_function(static function()use($root):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        $entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    }rmdir($root);
});
function fmcheck(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
function fmreject(callable $work,string $message):void{try{$work();}catch(DomainException|InvalidArgumentException $error){return;}throw new RuntimeException($message);}
class FeatureManagerFixture
{
    use \FreePBX\modules\SlsEnterpriseAdministration;
    const SETTINGS_JSON=FEATURE_MANAGER_FIXTURE.'/active.config';
    const PENDING_SETTINGS_JSON=FEATURE_MANAGER_FIXTURE.'/pending.config';
    public bool $admin=true;public bool $busy=false;public bool $failPending=false;public ?Closure $race=null;
    public array $order=[];public array $extensions=['1000'];
    public function currentOperator():array{return ['id'=>$this->admin?'recovery_admin':'operator','operator_role'=>$this->admin?'administrator':'operator','username'=>'fixture'];}
    public function getActiveSettings():array{return $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));}
    private function loadSettingsFile($path):array{return SlsConfigCrypto::readFile($path);}
    private function normalizeSettings($settings):array{
        foreach(['enterprise_labs'=>Manager::class,'enterprise_cluster'=>EnterpriseClusterConfig::class,'directory_sync'=>DirectoryConfig::class,
            'subscriber_browser'=>SubscriberConfig::class,'enterprise_integrations'=>EnterpriseIntegrationsConfig::class,
            'enterprise_operations'=>EnterpriseOperationsConfig::class,'labs_safety'=>LabsSafety::class] as $key=>$class){$settings[$key]=$class::normalize($settings[$key]??[]);}
        return Manager::disableWhenOff($settings);
    }
    private function getDefaultSettings():array{return ['enterprise_labs'=>Manager::defaults()];}
    private function acquireAnnouncementActivityLock(...$unused){$this->order[]='activity';return $this->busy?null:fopen(FEATURE_MANAGER_FIXTURE.'/activity.lock','c+b');}
    private function releaseNativeBackupFileLock($lock):void{fclose($lock);}
    private function acquireSettingsLock(...$unused){$this->order[]='settings';if($this->race){$race=$this->race;$this->race=null;$race();}return fopen(FEATURE_MANAGER_FIXTURE.'/settings.lock','c+b');}
    private function releaseSettingsLock($lock):void{fclose($lock);}
    private function configurationPathMetadata($path){return file_exists($path)?lstat($path):null;}
    private function writeSettingsFileUnlocked($path,$settings,$backup):void{
        if($this->failPending&&$path===self::PENDING_SETTINGS_JSON){$this->failPending=false;throw new RuntimeException('Fixture pending write failed.');}
        file_put_contents($path,SlsConfigCrypto::encode($settings));chmod($path,0640);
    }
    private function rememberSettingsFingerprint($path):void{}
    public function getConfiguredPjsipExtensionNumbers():array{return $this->extensions;}
    public function rawSave(array $changes):void{$this->saveEnterpriseNamespaces($changes);}
}
$module=new FeatureManagerFixture();
$settings=['unrelated'=>'active draft','enterprise_labs'=>Manager::defaults(),'enterprise_cluster'=>EnterpriseClusterConfig::defaults(),
    'directory_sync'=>DirectoryConfig::defaults(),'subscriber_browser'=>SubscriberConfig::defaults(),
    'enterprise_integrations'=>EnterpriseIntegrationsConfig::defaults(),'enterprise_operations'=>EnterpriseOperationsConfig::defaults(),
    'labs_safety'=>['schema'=>1,'receipts'=>[LabsSafety::PAGE_FEATURE=>['revision'=>LabsSafety::revision(),'accepted_at'=>time()-10,'actor'=>'fixture']]],
    'enterprise_identity'=>['enabled'=>true,'independent'=>'operator SSO'],'live_paging'=>['enabled'=>'1'],
    'desktop_clients'=>[['username'=>'display_fixture','enabled'=>'1']]];
$settings['enterprise_integrations']['meetings']['zoom_access_token']='active-token-kept';
$pending=$settings;$pending['unrelated']='pending draft';$pending['enterprise_integrations']['meetings']['zoom_access_token']='pending-token-kept';
foreach([FeatureManagerFixture::SETTINGS_JSON=>$settings,FeatureManagerFixture::PENDING_SETTINGS_JSON=>$pending] as $path=>$value){file_put_contents($path,SlsConfigCrypto::encode($value));chmod($path,0640);}
$state=$module->enterpriseAdministratorAction('labs_features_state',[])['manager'];
fmcheck(!$state['config']['enabled']&&count($state['catalog'])===22&&!array_filter($state['config']['features']),'Feature manager did not start disabled.');
fmcheck(!str_contains(json_encode($state),'active-token-kept'),'Feature manager exposed a saved secret.');
$save=static function(array $features,bool $enabled=true,array $extra=[])use($module):array{
    $state=$module->enterpriseAdministratorAction('labs_features_state',[])['manager'];
    return $module->enterpriseAdministratorAction('labs_features_save',array_replace(['revision'=>$state['revision'],'enabled'=>$enabled,
        'features'=>array_replace($state['config']['features'],$features),'warning_revision'=>LabsSafety::revision(),'agree'=>true],$extra));
};
$module->admin=false;fmreject(fn()=>$module->enterpriseAdministratorAction('labs_features_state',[]),'Restricted operator accessed Feature manager.');$module->admin=true;
foreach([['agree'=>false],['warning_revision'=>str_repeat('0',64)],['enabled'=>'1'],['extra'=>'unsupported'],['features'=>['unknown'=>true]]] as $invalid){
    $before=file_get_contents(FeatureManagerFixture::SETTINGS_JSON);fmreject(fn()=>$save([],true,$invalid),'Malformed manager save was accepted.');
    fmcheck(file_get_contents(FeatureManagerFixture::SETTINGS_JSON)===$before,'Rejected request changed active settings.');
}
$numeric=$state['config']['features'];$numeric['mail_evidence']=1;
fmreject(fn()=>$save([],true,['features'=>$numeric]),'Numeric manager selections were accepted as booleans.');
$unack=$settings;$unack['labs_safety']=LabsSafety::defaults();
file_put_contents(FeatureManagerFixture::SETTINGS_JSON,SlsConfigCrypto::encode($unack));
fmreject(fn()=>$module->enterpriseAdministratorAction('labs_features_state',[]),'Feature manager bypassed the timed page acknowledgment.');
fmreject(fn()=>$module->enterpriseAdministratorAction('labs_features_save',['revision'=>$state['revision'],'enabled'=>true,
    'features'=>$state['config']['features'],'warning_revision'=>LabsSafety::revision(),'agree'=>true]),'Manager save bypassed the timed page acknowledgment.');
file_put_contents(FeatureManagerFixture::SETTINGS_JSON,SlsConfigCrypto::encode($settings));
fmreject(fn()=>$module->rawSave(['enterprise_labs'=>['enabled'=>true]]),'An ordinary namespace save bypassed footer agreement.');
$on=$save([]);fmcheck($on['manager']['config']['enabled']&&!array_filter($on['manager']['config']['features']),'Master could not be enabled independently.');
fmcheck($module->order[0]==='activity'&&in_array('settings',$module->order,true),'Manager save did not preserve lock ordering.');
$enabled=$save(['meetings'=>true,'mail_evidence'=>true,'public_warning'=>true,'browser_display'=>true]);
fmcheck($enabled['manager']['config']['features']['meetings']&&$module->getActiveSettings()['enterprise_integrations']['enabled']==='1','Manager did not derive the integration parent.');
$active=SlsConfigCrypto::readFile(FeatureManagerFixture::SETTINGS_JSON);$staged=SlsConfigCrypto::readFile(FeatureManagerFixture::PENDING_SETTINGS_JSON);
fmcheck($active['enterprise_integrations']['meetings']['zoom_access_token']==='active-token-kept'
    &&$staged['enterprise_integrations']['meetings']['zoom_access_token']==='pending-token-kept'
    &&$active['unrelated']==='active draft'&&$staged['unrelated']==='pending draft','Manager overwrote credentials or a staged draft.');
fmcheck(count(array_intersect(Manager::SECTIONS,array_keys($active['immediate_config_revisions'])))===6,'Manager failed to revise all six active/pending namespaces.');
fmreject(fn()=>$save(['speakers'=>true]),'An unconfigured speaker adapter was enabled.');
fmreject(fn()=>$save(['ipaws'=>true]),'An unconfigured public-warning provider was enabled.');
$old=$module->enterpriseAdministratorAction('labs_features_state',[])['manager'];
$module->race=static function():void{$value=SlsConfigCrypto::readFile(FeatureManagerFixture::SETTINGS_JSON);$value['enterprise_integrations']['meetings']['zoom_access_token']='concurrently-rotated-token';file_put_contents(FeatureManagerFixture::SETTINGS_JSON,SlsConfigCrypto::encode($value));};
fmreject(fn()=>$module->enterpriseAdministratorAction('labs_features_save',['revision'=>$old['revision'],'enabled'=>true,'features'=>$old['config']['features'],
    'warning_revision'=>LabsSafety::revision(),'agree'=>true]),'A stale manager overwrote a credential rotation.');
fmcheck($module->getActiveSettings()['enterprise_integrations']['meetings']['zoom_access_token']==='concurrently-rotated-token','Concurrent credential update was lost.');
$module->busy=true;fmreject(fn()=>$save([]),'Manager changed settings without the exclusive activity lease.');$module->busy=false;
$beforeActive=file_get_contents(FeatureManagerFixture::SETTINGS_JSON);$beforePending=file_get_contents(FeatureManagerFixture::PENDING_SETTINGS_JSON);
$module->failPending=true;
try{$save(['mail_evidence'=>false]);throw new LogicException('Expected pending-write refusal.');}catch(RuntimeException $error){fmcheck($error->getMessage()!=='Expected pending-write refusal.','Second-write failure was hidden.');}
fmcheck(file_get_contents(FeatureManagerFixture::SETTINGS_JSON)===$beforeActive&&file_get_contents(FeatureManagerFixture::PENDING_SETTINGS_JSON)===$beforePending,'Partial manager write did not restore protected bytes.');
$off=$save(array_fill_keys(array_keys($state['config']['features']),false),false);
fmcheck(!$off['manager']['config']['enabled']&&!array_filter($off['manager']['config']['features']),'Master off retained an active feature.');
$after=$module->getActiveSettings();fmcheck($after['enterprise_integrations']['enabled']==='0'&&$after['enterprise_integrations']['meetings']['enabled']==='0'
    &&$after['enterprise_integrations']['meetings']['zoom_access_token']==='concurrently-rotated-token','Master disable discarded credentials or left an adapter enabled.');
fmcheck($after['enterprise_identity']['enabled']&&$after['live_paging']['enabled']==='1','Master disable changed operator SSO or ordinary paging.');
$blocked=$after['enterprise_integrations'];$blocked['enabled']='1';$blocked['mail_evidence']['enabled']='1';
fmreject(fn()=>$module->rawSave(['enterprise_integrations'=>$blocked]),'An individual section bypassed master disable.');
$blocked['enabled']=1;$blocked['mail_evidence']['enabled']=1;
fmreject(fn()=>$module->rawSave(['enterprise_integrations'=>$blocked]),'Numeric integration flags bypassed master disable.');
fmreject(fn()=>$module->enterpriseAdministratorAction('integrations_cap_export',[]),'An effect route ignored the disabled master.');
fmreject(fn()=>$module->enterpriseAdministratorAction('approval_approve',[]),'An approval grant ignored the disabled master.');
fmreject(fn()=>$module->enterpriseAdministratorAction('cluster_control',['stopped'=>false]),'Cluster resume ignored the disabled master.');
foreach ([FeatureManagerFixture::SETTINGS_JSON,FeatureManagerFixture::PENDING_SETTINGS_JSON] as $path) {
    $value=SlsConfigCrypto::readFile($path);
    $value['enterprise_integrations']['speakers']['devices']=[['id'=>'spk_'.str_repeat('a',24),'name'=>'Fixture speaker',
        'enabled'=>'1','brand'=>'algo','model'=>'8180 G2','extension'=>'1000']];
    file_put_contents($path,SlsConfigCrypto::encode($value));
}
fmcheck($save(['speakers'=>true])['manager']['config']['features']['speakers'],'A configured PJSIP speaker could not be enabled.');
$module->extensions=[];$before=file_get_contents(FeatureManagerFixture::SETTINGS_JSON);
fmreject(fn()=>$save([]),'A revoked PJSIP extension could be re-enabled through Feature manager.');
fmcheck(file_get_contents(FeatureManagerFixture::SETTINGS_JSON)===$before,'A rejected PJSIP profile changed settings.');
$module->extensions=['1000'];$save(array_fill_keys(array_keys($state['config']['features']),false),false);
$legacy=$settings;unset($legacy['enterprise_labs']);$legacy['enterprise_integrations']['enabled']='1';$legacy['enterprise_integrations']['meetings']['enabled']='1';
$masked=Manager::disableWhenOff($legacy);fmcheck($masked['enterprise_integrations']['enabled']==='0'&&$masked['enterprise_integrations']['meetings']['zoom_access_token']==='active-token-kept','Legacy missing-master settings executed or lost data.');
require_once dirname(__DIR__).'/slsmassnotifyserver/BrowserPush.php';
$dependency=$after;$dependency['subscriber_browser']['public_base_url']='https://pbx.example.test/mass-notify/subscriber.php';
$dependency['subscriber_browser']['people']=[['id'=>'sub_'.str_repeat('a',24),'person_id'=>'fixture_person','name'=>'Fixture Person',
    'email'=>'person@example.test','enabled'=>true,'version'=>str_repeat('b',64),'registered_at'=>time()-10]];
$dependency['subscriber_browser']['push']['private_key_pem']=\SLS\MassNotify\BrowserPush::generateKey()['private_key_pem'];
$dependency['subscriber_browser']['push']['subject']='mailto:admin@example.test';
$features=array_fill_keys(array_keys($state['config']['features']),false);$features['browser_push']=true;
$derived=Manager::changes($dependency,true,$features);
fmcheck($derived['subscriber_browser']['enabled']&&$derived['subscriber_browser']['push']['enabled'],'Browser push did not derive its subscriber parent.');
echo "Enterprise feature manager: administrator/receipt/footer gates, strict selections, default off, master-only activation, derived parents, configuration prerequisites, stale-save refusal, active/pending rollback, secret preservation, runtime disable and independent SSO/paging passed.\n";
