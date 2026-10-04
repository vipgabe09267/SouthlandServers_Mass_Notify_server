<?php
declare(strict_types=1);
namespace SlsInstallerLocksFixture {
    function posix_getpwnam($name) { return ['uid'=>999]; }
    function posix_geteuid() { return $GLOBALS['fixture_uid']; }
    // Accelerate only timeout accounting. Kernel flock is real and independently
    // opened descriptors contend exactly as installer/FreePBX processes do.
    function hrtime($asNumber=false) { $GLOBALS['fixture_clock']+=31000000000;return (int)$GLOBALS['fixture_clock']; }
    function usleep($microseconds) {}
}
namespace {
interface BMO {}
require dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
function lock_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$reflection=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);$source=file($reflection->getFileName());$methods='';
foreach(['installUnprivilegedPhase','configurationPathMetadata','initializeFreshInstallerConfiguration','requireRuntimeAccount','persistAppliedSettings',
    'acquireSettingsLock','releaseSettingsLock','acquireAnnouncementActivityLock','acquireNativeBackupFileLock','releaseNativeBackupFileLock',
    'genConfig','applySettings','applyPendingSettingsTransaction'] as $name) {
    lock_check($reflection->hasMethod($name),'Missing fresh-install configuration entrypoint: '.$name);
    $method=$reflection->getMethod($name);$methods.=implode('',array_slice($source,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
}
$root=sys_get_temp_dir().'/sls-installer-locks-'.bin2hex(random_bytes(8));mkdir($root,0700);
register_shutdown_function(static function()use($root):void{foreach(glob($root.'/*') as $path){unlink($path);}rmdir($root);});
eval('namespace SlsInstallerLocksFixture; use FreePBX\modules\SlsConfigCrypto; class Module {
 use \FreePBX\modules\SlsAutomations;
 use \FreePBX\modules\SlsDesktopCapacity;
 const SETTINGS_JSON='.var_export($root.'/config',true).';
 const PENDING_SETTINGS_JSON='.var_export($root.'/pending',true).';
 const SETTINGS_LOCK='.var_export($root.'/settings.lock',true).';
 const ANNOUNCEMENT_ACTIVITY_LOCK_FILE='.var_export($root.'/activity.lock',true).';
 const NATIVE_BACKUP_MAX_CONFIG_BYTES=4194304;
 private $settingsActivityLocks=[];private $settingsReadFingerprints=[];
 public $writes=0;public $pendingReads=0;
 public $capacityCalls=0;public $resourcesSufficient=true;
 protected function checkDesktopCapacityResources($limit,$phones=25){$this->capacityCalls++;return $this->resourcesSufficient?
  ["eligible_limit"=>1000,"eligible_phone_limit"=>1000,"errors"=>[]]:["errors"=>[["message"=>"Insufficient CPU for detected phone capacity."]]];}
 private function ensurePluginDataDir(){}
 private function setPrivateOwnership($path){}
 private function getDefaultSettings(){return ["enabled"=>"0","scheduled_announcements"=>[]];}
 private function getActiveSettings(){return is_file(self::SETTINGS_JSON)?$this->loadSettingsFile(self::SETTINGS_JSON):$this->getDefaultSettings();}
 private function getPendingSettings(){ $this->pendingReads++;return is_file(self::PENDING_SETTINGS_JSON)?$this->loadSettingsFile(self::PENDING_SETTINGS_JSON):null;}
 private function normalizeSettings(array $settings){return $settings;}
 private function loadSettingsFile($path){return json_decode(file_get_contents($path),true);}
 private function writeSettingsFileUnlocked($path,array $settings,$backup){$this->writes++;file_put_contents($path,json_encode($settings));}
 private function settingsFileFingerprint($path){return is_file($path)?hash_file("sha256",$path):"absent";}
 private function rememberSettingsFingerprint($path){$this->settingsReadFingerprints[$path]=$this->settingsFileFingerprint($path);}
 private function isSetupComplete($settings){return false;}
 private function normalizeLivePagingSettings($settings){return ["enabled"=>"0"];}
 public function __call($name,$args){
  if(!in_array($name,["migrateLegacyTestStatus","ensureBundledSystemRecordings","ensureAmiUser","ensureDialplan","ensureSipNotifyTemplates","ensureMenuPlacement","ensureDashboardWidget","ensureCronJob","ensureFreePbxBackupEnrollment"],true))throw new \\LogicException("Unexpected fixture integration: ".$name);
  return $name==="ensureFreePbxBackupEnrollment"?["success"=>true]:null;
 }
 '.$methods.'}');
$GLOBALS['fixture_uid']=999;$GLOBALS['fixture_clock']=1000.0;
$module=new SlsInstallerLocksFixture\Module();
$activity=fopen($root.'/activity.lock','c+');chmod($root.'/activity.lock',0640);lock_check(flock($activity,LOCK_EX|LOCK_NB),'Could not hold installer activity guard.');
// Actual old fresh hook must contend; removing the guard would mask the bug.
try{$module->installUnprivilegedPhase();throw new LogicException('Uninitialized fresh hook bypassed parent activity lock.');}
catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'did not become idle')!==false,'Unexpected fresh-hook rejection.');}
lock_check($module->writes===0&&!is_file($root.'/config'),'Blocked hook wrote fresh configuration.');
$module->initializeFreshInstallerConfiguration();
lock_check($module->writes===1&&is_file($root.'/config'),'Narrow fresh initializer did not write once under independent settings mutex.');
$original=file_get_contents($root.'/config');
$settings=fopen($root.'/settings.lock','c+');chmod($root.'/settings.lock',0640);lock_check(flock($settings,LOCK_EX|LOCK_NB),'Initializer leaked its settings lock.');
lock_check($module->installUnprivilegedPhase()===true,'Guarded fresh install failed after protected initialization.');
lock_check($module->writes===1&&file_get_contents($root.'/config')===$original,'Guarded reinstall rewrote config.');
$pending='{"enabled":"1","scheduled_announcements":[]}';file_put_contents($root.'/pending',$pending);
putenv('SLS_MASS_NOTIFY_PRESERVE_PENDING=1');
$reads=$module->pendingReads;lock_check($module->genConfig()===[],'Installer reload did not preserve pending settings.');
lock_check($module->pendingReads===$reads&&$module->writes===1&&file_get_contents($root.'/pending')===$pending,'Installer reload touched staged settings or tried config locks.');
$GLOBALS['fixture_uid']=0;
try{$module->genConfig();throw new LogicException('Root accepted the installer reload flag.');}catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'asterisk service account')!==false,'Wrong privileged reload rejection.');}
$GLOBALS['fixture_uid']=999;putenv('SLS_MASS_NOTIFY_PRESERVE_PENDING');
try{$module->genConfig();throw new LogicException('Ordinary Apply Config bypassed installer activity lock.');}catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'Unable to apply settings')!==false,'Unexpected ordinary Apply rejection.');}
lock_check($module->writes===1&&file_get_contents($root.'/pending')===$pending,'Blocked ordinary Apply changed configuration.');
flock($settings,LOCK_UN);fclose($settings);flock($activity,LOCK_UN);fclose($activity);
lock_check($module->genConfig()===[]&&$module->writes===2&&!is_file($root.'/pending'),'Normal Apply Config no longer works after installer guards release.');
lock_check(json_decode(file_get_contents($root.'/config'),true)['enabled']==='1','Ordinary Apply lost pending value.');
$afterApply=file_get_contents($root.'/config');
lock_check($module->initializeFreshInstallerConfiguration()===false&&$module->writes===2&&file_get_contents($root.'/config')===$afterApply,'Fresh initializer rewrote an existing active configuration.');
unlink($root.'/config');file_put_contents($root.'/pending',$pending);
try{$module->initializeFreshInstallerConfiguration();throw new LogicException('Orphaned pending configuration was overwritten.');}
catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'Staged settings exist')!==false,'Wrong orphaned-pending rejection.');}
lock_check(!is_file($root.'/config')&&file_get_contents($root.'/pending')===$pending,'Orphaned pending initialization changed preserved files.');
unlink($root.'/pending');
$victim=$root.'/victim';file_put_contents($victim,'preserve existing bytes');
foreach(['fifo','symlink','hardlink','directory','oversized'] as $kind){
 if($kind==='fifo'){posix_mkfifo($root.'/config',0600);}
 elseif($kind==='symlink'){symlink($victim,$root.'/config');}
 elseif($kind==='hardlink'){link($victim,$root.'/config');}
 elseif($kind==='directory'){mkdir($root.'/config',0700);}
 else{$file=fopen($root.'/config','x+b');ftruncate($file,4194305);fclose($file);}
 try{$module->initializeFreshInstallerConfiguration();throw new LogicException('Unsafe existing config accepted: '.$kind);}
 catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'unsafe file')!==false,'Wrong existing-config rejection.');}
 if($kind==='directory'){rmdir($root.'/config');}else{unlink($root.'/config');}
 lock_check(file_get_contents($victim)==='preserve existing bytes','Unsafe initialization changed unrelated bytes.');
}
$GLOBALS['fixture_uid']=0;
try{$module->initializeFreshInstallerConfiguration();throw new LogicException('Root used fresh configuration initializer.');}
catch(RuntimeException $e){lock_check(strpos($e->getMessage(),'asterisk service account')!==false,'Wrong initializer root rejection.');}

$GLOBALS['fixture_uid']=999;
$fresh=new SlsInstallerLocksFixture\Module();
lock_check($fresh->initializeFreshInstallerConfiguration(150)===true&&$fresh->capacityCalls===1,'Detected capacity did not receive a fresh combined resource check.');
lock_check(json_decode(file_get_contents($root.'/config'),true)['phone_device_limit']===150,'Detected capacity did not reach protected initial configuration.');
$saved=file_get_contents($root.'/config');
lock_check($fresh->initializeFreshInstallerConfiguration(1000)===false&&file_get_contents($root.'/config')===$saved&&$fresh->capacityCalls===1,'Upgrade replaced a saved capacity with a newly detected value.');
unlink($root.'/config');$fresh->resourcesSufficient=false;
try{$fresh->initializeFreshInstallerConfiguration(150);throw new LogicException('Insufficient hardware allowed initial capacity.');}
catch(DomainException $e){lock_check(strpos($e->getMessage(),'Insufficient CPU')!==false,'Initial resource rejection lost its specific reason.');}
lock_check(!is_file($root.'/config'),'Rejected initial capacity wrote configuration.');
foreach([24,1001] as $invalid){try{$fresh->initializeFreshInstallerConfiguration($invalid);throw new LogicException('Invalid detected limit was accepted.');}catch(DomainException $expected){}}

echo "Real fresh-install hook, narrow initializer, parent activity/settings locks, preserved pending reload and ordinary Apply Config passed.\n";
}
