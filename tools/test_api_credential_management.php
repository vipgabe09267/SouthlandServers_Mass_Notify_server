<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/ApiCredentialManagement.php';
$directory = sys_get_temp_dir() . '/sls-api-management-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700) || !chmod($directory, 0700)) { throw new RuntimeException('Unable to create the private credential fixture.'); }
function apiManagementFixtureRead(string $path): string { $raw=file_get_contents($path);if(!is_string($raw)){throw new RuntimeException('Credential fixture could not be read.');}return $raw; }
function apiManagementFixtureCleanup(string $directory,bool $strict):void {
 foreach(scandir($directory)?:[] as $name){if($name==='.'||$name==='..'){continue;}$path=$directory.'/'.$name;$removed=is_dir($path)&&!is_link($path)?rmdir($path):unlink($path);if(!$removed&&$strict){throw new RuntimeException('Credential fixture cleanup failed.');}}
 if(!rmdir($directory)&&$strict){throw new RuntimeException('Credential fixture directory cleanup failed.');}
}
$passed=false;
try {
$fixture = <<<'PHP'
class ApiManagementFixture {
 use \FreePBX\modules\SlsApiCredentialManagement;
 public $writes=[]; public $fail='';
 private function ensurePluginDataDir() {}
 private function acquireSettingsLock() { return true; }
 private function releaseSettingsLock($lock) {}
 private function loadSettingsFile($path) { $raw=file_get_contents($path);if(!is_string($raw))throw new RuntimeException('Fixture settings are unreadable');return json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
 private function getActiveSettings() { return $this->loadSettingsFile(self::SETTINGS_JSON); }
 private function writeSettingsFileUnlocked($path,$settings,$backup) {
   if ($path===$this->fail) throw new RuntimeException('Fixture storage failure');
   $raw=json_encode($settings,JSON_THROW_ON_ERROR);if(file_put_contents($path,$raw)!==strlen($raw))throw new RuntimeException('Fixture settings write failed');$this->writes[]=[$path,$backup];
 }
PHP;
eval($fixture . 'const SETTINGS_JSON=' . var_export($directory . '/active.json',true) . '; const PENDING_SETTINGS_JSON=' . var_export($directory . '/pending.json',true) . ';}');
$module = new ApiManagementFixture();
$active = ['control_api'=>['enabled'=>true,'api_key'=>'legacy-secret'],'protected_marker'=>'active','scheduled_announcements'=>[['id'=>'original']]];
$pending = ['control_api'=>['enabled'=>false,'api_key'=>'pending-legacy'],'protected_marker'=>'pending','scheduled_announcements'=>[['id'=>'staged']]];
$activeJson=json_encode($active,JSON_THROW_ON_ERROR);$pendingJson=json_encode($pending,JSON_THROW_ON_ERROR);
if(file_put_contents(ApiManagementFixture::SETTINGS_JSON,$activeJson)!==strlen($activeJson)||!chmod(ApiManagementFixture::SETTINGS_JSON,0600)
 ||file_put_contents(ApiManagementFixture::PENDING_SETTINGS_JSON,$pendingJson)!==strlen($pendingJson)||!chmod(ApiManagementFixture::PENDING_SETTINGS_JSON,0600)){throw new RuntimeException('Unable to prepare credential settings fixtures.');}
$definition=['name'=>'Fixture monitor','scopes'=>['read'],'audience'=>['unrestricted'=>false]];
function checkApiManagement(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
 $initialActive=apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON);$initialPending=apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON);
 foreach(['{"name":"first","name":"second","scopes":["read"],"audience":{"unrestricted":false}}','{"name":'] as $ambiguous){
   $rejected=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>$ambiguous]);
   checkApiManagement($rejected['success']===false&&$rejected['message']==='The credential permission form is invalid.'
      &&apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON)===$initialActive&&apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON)===$initialPending
      &&$module->writes===[],'Ambiguous credential JSON reached protected configuration.');
 }
 $created=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition,JSON_THROW_ON_ERROR)]);
 checkApiManagement($created['success']&&!empty($created['secret']),'Credential was not issued.');
 $newActive=json_decode(apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON),true,16,JSON_THROW_ON_ERROR);
 $newPending=json_decode(apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON),true,16,JSON_THROW_ON_ERROR);
 $rows=$newActive['control_api']['credentials'];
 checkApiManagement($newActive['immediate_config_revisions']['control_api']===$newPending['immediate_config_revisions']['control_api'],'Credential generation was not committed consistently.');
 unset($newActive['control_api']['credentials'],$newPending['control_api']['credentials'],$newActive['immediate_config_revisions'],$newPending['immediate_config_revisions']);
 checkApiManagement($active===$newActive&&$pending===$newPending,'Credential management changed unrelated active or staged settings.');
 checkApiManagement(strpos(apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON),$created['secret'])===false,'Raw bearer secret reached configuration storage.');
 checkApiManagement(!isset($created['credentials'][0]['secret_hash'])&&!isset($module->apiCredentialMetadata()[0]['secret_hash']),'Credential digest leaked in management output.');
 checkApiManagement($module->writes[0][0]===ApiManagementFixture::SETTINGS_JSON&&$module->writes[1][0]===ApiManagementFixture::PENDING_SETTINGS_JSON,'Paired credential commit did not use active then staged order.');
 $revoked=$module->manageApiCredential(['credential_action'=>'revoke','credential_id'=>$created['credential_id']]);
 checkApiManagement($revoked['success'],'Revocation failed.');
 foreach([ApiManagementFixture::SETTINGS_JSON,ApiManagementFixture::PENDING_SETTINGS_JSON] as $path){
   $settings=json_decode(apiManagementFixtureRead($path),true,16,JSON_THROW_ON_ERROR);
   checkApiManagement($settings['control_api']['credentials'][0]['revoked_at']!=='','Apply could resurrect a revoked credential.');
   checkApiManagement(\SLS\MassNotify\ApiSecurity::authenticate($settings['control_api'],$created['secret'])===null,'Revoked credential still authenticated.');
 }
 $activeBefore=apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON);$pendingBefore=apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON);$module->fail=ApiManagementFixture::PENDING_SETTINGS_JSON;
 $failed=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition,JSON_THROW_ON_ERROR)]);
 checkApiManagement(!$failed['success']&&!isset($failed['secret'])&&apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON)===$activeBefore
    &&apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON)===$pendingBefore,'Failed staged write changed active or staged bytes or disclosed a usable secret.');
 $module->fail=ApiManagementFixture::SETTINGS_JSON;
 $failed=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition,JSON_THROW_ON_ERROR)]);
 checkApiManagement(!$failed['success']&&!isset($failed['secret'])&&apiManagementFixtureRead(ApiManagementFixture::SETTINGS_JSON)===$activeBefore
    &&apiManagementFixtureRead(ApiManagementFixture::PENDING_SETTINGS_JSON)===$pendingBefore,'Failed active write changed paired bytes or falsely confirmed issuance.');
 $passed=true;
 echo "Named credential issuance, immediate revocation, staged-setting preservation and failed-write behavior passed.\n";
}finally{apiManagementFixtureCleanup($directory,$passed);}
