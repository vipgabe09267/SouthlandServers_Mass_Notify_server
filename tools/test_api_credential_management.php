<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/ApiCredentialManagement.php';
$directory = sys_get_temp_dir() . '/sls-api-management-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$fixture = <<<'PHP'
class ApiManagementFixture {
 use \FreePBX\modules\SlsApiCredentialManagement;
 public $writes=[]; public $fail='';
 private function ensurePluginDataDir() {}
 private function acquireSettingsLock() { return true; }
 private function releaseSettingsLock($lock) {}
 private function loadSettingsFile($path) { return json_decode(file_get_contents($path),true); }
 private function getActiveSettings() { return $this->loadSettingsFile(self::SETTINGS_JSON); }
 private function writeSettingsFileUnlocked($path,$settings,$backup) {
   if ($path===$this->fail) throw new RuntimeException('Fixture storage failure');
   $this->writes[]=[$path,$backup];file_put_contents($path,json_encode($settings));
 }
PHP;
eval($fixture . 'const SETTINGS_JSON=' . var_export($directory . '/active.json',true) . '; const PENDING_SETTINGS_JSON=' . var_export($directory . '/pending.json',true) . ';}');
$module = new ApiManagementFixture();
$active = ['control_api'=>['enabled'=>true,'api_key'=>'legacy-secret'],'protected_marker'=>'active','scheduled_announcements'=>[['id'=>'original']]];
$pending = ['control_api'=>['enabled'=>false,'api_key'=>'pending-legacy'],'protected_marker'=>'pending','scheduled_announcements'=>[['id'=>'staged']]];
file_put_contents(ApiManagementFixture::SETTINGS_JSON,json_encode($active));
file_put_contents(ApiManagementFixture::PENDING_SETTINGS_JSON,json_encode($pending));
$definition=['name'=>'Fixture monitor','scopes'=>['read'],'audience'=>['unrestricted'=>false]];
function checkApiManagement($condition,$message) { if(!$condition)throw new RuntimeException($message); }
try {
 $created=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition)]);
 checkApiManagement($created['success']&&!empty($created['secret']),'Credential was not issued.');
 $newActive=json_decode(file_get_contents(ApiManagementFixture::SETTINGS_JSON),true);
 $newPending=json_decode(file_get_contents(ApiManagementFixture::PENDING_SETTINGS_JSON),true);
 $rows=$newActive['control_api']['credentials'];
 unset($newActive['control_api']['credentials'],$newPending['control_api']['credentials']);
 checkApiManagement($active===$newActive&&$pending===$newPending,'Credential management changed unrelated active or staged settings.');
 checkApiManagement(strpos(file_get_contents(ApiManagementFixture::SETTINGS_JSON),$created['secret'])===false,'Raw bearer secret reached configuration storage.');
 checkApiManagement(!isset($created['credentials'][0]['secret_hash'])&&!isset($module->apiCredentialMetadata()[0]['secret_hash']),'Credential digest leaked in management output.');
 checkApiManagement($module->writes[0][0]===ApiManagementFixture::PENDING_SETTINGS_JSON,'Pending revocation protection was not saved first.');
 $revoked=$module->manageApiCredential(['credential_action'=>'revoke','credential_id'=>$created['credential_id']]);
 checkApiManagement($revoked['success'],'Revocation failed.');
 foreach([ApiManagementFixture::SETTINGS_JSON,ApiManagementFixture::PENDING_SETTINGS_JSON] as $path){
   $settings=json_decode(file_get_contents($path),true);
   checkApiManagement($settings['control_api']['credentials'][0]['revoked_at']!=='','Apply could resurrect a revoked credential.');
   checkApiManagement(\SLS\MassNotify\ApiSecurity::authenticate($settings['control_api'],$created['secret'])===null,'Revoked credential still authenticated.');
 }
 $before=file_get_contents(ApiManagementFixture::SETTINGS_JSON);$module->fail=ApiManagementFixture::PENDING_SETTINGS_JSON;
 $failed=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition)]);
 checkApiManagement(!$failed['success']&&!isset($failed['secret'])&&file_get_contents(ApiManagementFixture::SETTINGS_JSON)===$before,'Failed staged write changed active keys or disclosed a usable secret.');
 $module->fail=ApiManagementFixture::SETTINGS_JSON;
 $failed=$module->manageApiCredential(['credential_action'=>'create','credential_json'=>json_encode($definition)]);
 checkApiManagement(!$failed['success']&&!isset($failed['secret']),'Failed active write falsely confirmed issuance.');
 echo "Named credential issuance, immediate revocation, staged-setting preservation and failed-write behavior passed.\n";
}finally{foreach(glob($directory.'/*')?:[] as $path)unlink($path);rmdir($directory);}
