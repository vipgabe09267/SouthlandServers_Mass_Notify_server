<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/DeploymentAcceptance.php';
use SLS\MassNotify\DeviceAcceptance;
$checks = 0;
function device_check(bool $value, string $message): void { $GLOBALS['checks']++; if (!$value) { throw new RuntimeException($message); } }
$now = time();
$input = ['target_type' => 'phone', 'target_id' => '1000', 'tested_at' => gmdate('c', $now - 60), 'result' => 'passed',
    'model' => 'Yealink T48G', 'firmware' => 'verified fixture version', 'checks' => ['text', 'audio'],
    'notes' => 'Popup displayed and complete internal speech played.', 'confirmed' => true];
$observed = DeviceAcceptance::observation($input, $now);
device_check($observed['tested_at'] === $input['tested_at'] && !isset($observed['confirmed']), 'Observation changed time or retained a client confirmation as provenance.');
foreach ([['confirmed' => false], ['target_type' => 'other'], ['target_id' => '../unsafe'], ['tested_at' => '2026-02-31T00:00:00Z'],
    ['tested_at' => gmdate('c', $now + 61)], ['tested_at' => '2026-01-01 00:00:00'], ['model' => ''], ['notes' => ''],
    ['notes' => str_repeat('x', 601)], ['notes' => "bad\x00text"], ['model' => "\xff"], ['checks' => []], ['checks' => ['text', 'text']],
    ['checks' => ['dtmf']], ['result' => 'delivered'], ['recorded_by' => 'forged'], ['configuration_fingerprint' => str_repeat('0', 64)]] as $change) {
    try { DeviceAcceptance::observation(array_replace($input, $change), $now); device_check(false, 'Invalid observation accepted: ' . implode(',', array_keys($change))); }
    catch (DomainException $error) { device_check($error->getMessage() !== '', 'Observation failure had no actionable message.'); }
}
foreach (DeviceAcceptance::CHECKS as $type => $behavior) {
    $value = array_replace($input, ['target_type' => $type, 'target_id' => 'saved_' . $type, 'checks' => [array_key_first($behavior)]]);
    device_check(DeviceAcceptance::observation($value, $now)['target_type'] === $type, 'A supported channel could not be recorded.');
}
$settings = ['public_pbx_host' => 'fixture.example', 'private_token' => 'fixture-private-never-exported', 'nested' => ['b' => 2, 'a' => 1]];
$record = $observed + ['id' => 'dtest_' . str_repeat('a', 24), 'target_label' => '1000 fixture', 'recorded_at' => gmdate('c', $now),
    'recorded_by' => 'test-admin', 'module_version' => '0.1.5-beta', 'configuration_fingerprint' => DeviceAcceptance::fingerprint($settings, '0.1.5-beta')];
$settings['device_acceptance'] = ['schema' => 1, 'records' => [$record], 'retired_records' => 0];
$report = DeviceAcceptance::report($settings, '0.1.5-beta', $now);
device_check($report['current_counts']['passed'] === 1 && !$report['records'][0]['configuration_changed'], 'Current passing observation not projected.');
device_check(!str_contains(json_encode($report), 'fixture-private-never-exported') && !isset($report['records'][0]['configuration_fingerprint']), 'Configuration data/digest escaped into the report.');
$reordered = ['nested' => ['a' => 1, 'b' => 2], 'private_token' => $settings['private_token'], 'public_pbx_host' => $settings['public_pbx_host']];
device_check(DeviceAcceptance::fingerprint($reordered, '0.1.5-beta') === $record['configuration_fingerprint'], 'Object key order invalidated the observation.');
$changed = $settings; $changed['public_pbx_host'] = 'changed.example';
device_check(DeviceAcceptance::report($changed, '0.1.5-beta', $now)['current_counts']['review_required'] === 1, 'Changed settings silently retained qualification.');
device_check(DeviceAcceptance::report($settings, '0.1.4-beta', $now)['current_counts']['review_required'] === 1, 'Changed SLS version did not request review.');
$sameSecond = $settings;
$sameSecond['device_acceptance']['records'] = [array_replace($record, ['id' => 'dtest_' . str_repeat('f', 24)]),
    array_replace($record, ['id' => 'dtest_' . str_repeat('0', 24), 'result' => 'failed'])];
device_check(DeviceAcceptance::report($sameSecond, '0.1.5-beta', $now)['current_counts']['passed'] === 1, 'Random ID ordering retained a superseded failure recorded in the same second.');
$sameSecond['device_acceptance']['records'] = array_reverse($sameSecond['device_acceptance']['records']);
device_check(DeviceAcceptance::report($sameSecond, '0.1.5-beta', $now)['current_counts']['failed'] === 1, 'Equal timestamps hid a later recorded failure.');
$failed = array_replace($record, ['id' => 'dtest_' . str_repeat('b', 24), 'tested_at' => gmdate('c', $now - 100), 'result' => 'failed']);
$settings['device_acceptance']['records'][] = $failed;
$report = DeviceAcceptance::report($settings, '0.1.5-beta', $now);
device_check($report['current_counts']['passed'] === 1 && $report['current_counts']['failed'] === 0 && $report['records'][1]['superseded'], 'Historical failure remained a current failure after a later successful test.');
$settings['device_acceptance']['records'][0]['tested_at'] = gmdate('c', $now - 200);
$report = DeviceAcceptance::report($settings, '0.1.5-beta', $now);
device_check($report['current_counts']['failed'] === 1, 'Late entry of an older test overrode a newer observation.');
device_check(DeviceAcceptance::report($settings, '0.1.5-beta', $now - 500)['current_counts']['review_required'] === 1, 'Future observation passed with a clock mismatch.');
foreach ([['records' => [$record, $record]], ['records' => array_fill(0, 201, $record)], ['retired_records' => -1], ['records' => [['id' => 'bad']]],
    ['records' => [array_replace($record, ['recorded_at' => '2026-02-31T00:00:00+00:00'])]]] as $change) {
    try { DeviceAcceptance::normalize(array_replace(['schema' => 1, 'records' => []], $change)); device_check(false, 'Invalid imported history accepted.'); }
    catch (DomainException $error) { device_check(true, 'Invalid history rejected.'); }
}

$root = sys_get_temp_dir() . '/sls-device-tests-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void { foreach (glob($root . '/*') ?: [] as $path) { unlink($path); } rmdir($root); });
mkdir($root . '/payload', 0700); file_put_contents($root . '/payload/source.php', '<?php /* fixture build one */');
$buildOne = DeviceAcceptance::softwareIdentity($root . '/payload');
file_put_contents($root . '/payload/module.sig', 'fixture signature timestamp');
device_check(DeviceAcceptance::softwareIdentity($root . '/payload') === $buildOne, 'Local signature timestamps invalidated software acceptance.');
file_put_contents($root . '/payload/source.php', '<?php /* fixture build two */');
device_check(DeviceAcceptance::softwareIdentity($root . '/payload') !== $buildOne, 'A same-version payload change did not request review.');
symlink($root . '/payload/source.php', $root . '/payload/unsafe');
try { DeviceAcceptance::softwareIdentity($root . '/payload'); device_check(false, 'Linked payload file was accepted.'); }
catch (DomainException $error) { device_check(true, 'Unsafe payload rejected.'); }
unlink($root . '/payload/unsafe');
$handle = fopen($root . '/payload/oversized', 'w'); ftruncate($handle, 8388609); fclose($handle);
try { DeviceAcceptance::softwareIdentity($root . '/payload'); device_check(false, 'Unbounded payload was read.'); }
catch (DomainException $error) { device_check(true, 'Payload bound enforced.'); }
foreach (glob($root . '/payload/*') as $path) { unlink($path); } rmdir($root . '/payload');
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$snapshotMethod = (new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class))->getMethod('readNativeBackupFile');
$snapshotSource = implode('', array_slice(file($snapshotMethod->getFileName()), $snapshotMethod->getStartLine() - 1, $snapshotMethod->getEndLine() - $snapshotMethod->getStartLine() + 1));
eval('namespace FreePBX\\modules;
class DeviceTestFixture {
 use SlsDeploymentAcceptance;
 const NATIVE_BACKUP_MAX_CONFIG_BYTES=4194304;
 const MODULE_VERSION="0.1.5-beta"; const SETTINGS_JSON=' . var_export($root . '/active.json', true) . '; const PENDING_SETTINGS_JSON=' . var_export($root . '/pending.json', true) . ';
 public string $role="administrator"; public bool $fail=false; public int $writes=0; public int $catalogCalls=0;
 function currentOperator(){return ["operator_role"=>$this->role,"username"=>"test-admin"];}
 function getActiveSettings(){ $value=$this->loadSettingsFile(self::SETTINGS_JSON); $value["phone_device_limit"]=(int)$value["phone_device_limit"]; return $value+["normalizer_only_default"=>true]; }
 function loadSettingsFile($path){return json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
 function locationCatalog($settings){$this->catalogCalls++;return ["extensions"=>["1000"=>["label"=>"1000 fixture"]],"desktop_client_ids"=>["cli_fixture"=>["label"=>"Fixture desktop"]]];}
 function acquireSettingsLock($wait){$h=fopen(' . var_export($root . '/settings.lock', true) . ',"c+b");flock($h,LOCK_EX);return $h;}
 function releaseSettingsLock($handle){flock($handle,LOCK_UN);fclose($handle);}
 function writeSettingsFileUnlocked($path,$data,$backup){if($this->fail)throw new \\RuntimeException("private path must not escape");$this->writes++;file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));}
 function __call($name,$args){throw new \\RuntimeException("Unexpected action: ".$name);}
 ' . $snapshotSource . '}');
$base = ['public_pbx_host' => 'fixture.example', 'preserved_secret' => 'private-placeholder', 'phone_device_limit' => '25'];
$pending = $base + ['pending_owner_change' => ['keep' => true]];
file_put_contents($root . '/active.json', json_encode($base)); file_put_contents($root . '/pending.json', json_encode($pending));
$fixture = new FreePBX\modules\DeviceTestFixture(); $state = $fixture->deviceAcceptanceState();
device_check($state['success'] && $fixture->catalogCalls === 1 && $fixture->writes === 0, 'State repeats device discovery or performs a write.');
$saved = $fixture->recordDeviceAcceptance($input + ['revision' => $state['revision']]);
device_check($saved['success'] && $fixture->writes === 2, 'Actual trait failed to save both active and staged observations.');
device_check($saved['report']['current_counts']['passed'] === 1 && $fixture->deviceAcceptanceState()['report']['current_counts']['passed'] === 1, 'Recording falsely invalidated its own normalized configuration fingerprint.');
foreach (['active' => $base, 'pending' => $pending] as $name => $expected) {
    $actual = json_decode(file_get_contents($root . '/' . $name . '.json'), true); $history = $actual['device_acceptance']; unset($actual['device_acceptance']);
    device_check($actual === $expected && count($history['records']) === 1 && $history['records'][0]['recorded_by'] === 'test-admin', 'Observation changed owner settings or accepted forged provenance.');
}
$writes = $fixture->writes;
device_check($fixture->recordDeviceAcceptance($input + ['revision' => $state['revision']])['error_code'] === 'configuration_changed' && $fixture->writes === $writes, 'Stale save overwrote newer configuration/history.');
$state = $fixture->deviceAcceptanceState();
device_check(!$fixture->recordDeviceAcceptance(array_replace($input, ['target_id' => '2000', 'revision' => $state['revision']]))['success'] && $fixture->writes === $writes, 'Unconfigured destination was accepted.');
$fixture->role = 'viewer';
device_check($fixture->deviceAcceptanceState()['error_code'] === 'permission_denied' && $fixture->recordDeviceAcceptance($input)['error_code'] === 'permission_denied', 'Viewer could read/forge an acceptance record.');
$fixture->role = 'administrator'; $fixture->fail = true;
$failure = $fixture->recordDeviceAcceptance($input + ['revision' => $state['revision']]);
device_check(!$failure['success'] && !str_contains(json_encode($failure), 'private path'), 'Storage error claimed success or exposed a private exception.');
$fixture->fail = false;
unlink($root . '/pending.json');
device_check($fixture->recordDeviceAcceptance($input + ['revision' => $state['revision']])['success'] && !file_exists($root . '/pending.json'), 'Recording created a previously absent pending configuration.');
$active = json_decode(file_get_contents($root . '/active.json'), true);
$prototype = $active['device_acceptance']['records'][0]; $active['device_acceptance']['records'] = [];
for ($i = 0; $i < 200; $i++) { $active['device_acceptance']['records'][] = array_replace($prototype, ['id' => 'dtest_' . str_pad(dechex($i), 24, '0', STR_PAD_LEFT)]); }
file_put_contents($root . '/active.json', json_encode($active)); $state = $fixture->deviceAcceptanceState();
$saved = $fixture->recordDeviceAcceptance($input + ['revision' => $state['revision']]);
device_check($saved['success'] && count($saved['report']['records']) === 200 && $saved['report']['retired_records'] === 1, 'Explicit 200-observation retention was not bounded/reported.');

// Exercise the full module's real normalization, lock, raw snapshot, durable
// writer and backup in the namespace's disposable PBX data tree. The fixture
// supplies only login/device discovery; any notification call is an error.
class FullDeviceAcceptanceFixture extends FreePBX\modules\Slsmassnotifyserver {
    public function getConfiguredPjsipExtensionNumbers() { return ['1000']; }
    public function getAllPjsipExtensions() { return [['extension'=>'1000', 'name'=>'Fixture phone']]; }
    public function getAvailableTones() { return []; }
    public function getAvailablePiperVoices() { return []; }
    public function getOutboundVoiceTrunks() { return []; }
    public function sendSipNotifyAnnouncement(...$arguments) { throw new LogicException('Recording a test attempted to send a notification.'); }
}
$full = (new ReflectionClass(FullDeviceAcceptanceFixture::class))->newInstanceWithoutConstructor();
$defaults = new ReflectionMethod(FreePBX\modules\Slsmassnotifyserver::class, 'getDefaultSettings');
$fullSettings = $defaults->invoke($full); $fullSettings['public_pbx_host'] = 'fixture.example';
$fullSettings['desktop_auth_key'] = base64_encode(str_repeat('f', 32));
$fullSettings['phone_device_limit'] = 25; $fullSettings['unrelated_legacy_value'] = ['preserved' => true];
$fullSettings['incident_workflows'] = ['schema'=>1, 'templates'=>[['id'=>'tpl_'.str_repeat('b',24), 'name'=>'Legacy template',
    'title'=>'Legacy title', 'message'=>'Legacy message', 'delivery'=>['extensions'=>['1000']],
    'escalation'=>['enabled'=>false, 'after_seconds'=>300, 'delivery'=>[]]]]];
$fullActive = $full::SETTINGS_JSON; $fullPending = $full::PENDING_SETTINGS_JSON;
if (!is_dir(dirname($fullActive))) { mkdir(dirname($fullActive), 0750, true); }
file_put_contents($fullActive, json_encode($fullSettings)); chmod($fullActive, 0640);
$fullStaged = $fullSettings; $fullStaged['unrelated_pending_edit'] = ['preserved' => true];
file_put_contents($fullPending, json_encode($fullStaged)); chmod($fullPending, 0640);
$_SESSION['AMP_user'] = new class { public string $username = 'fixture-admin'; public function getAmpUser($name) { return ['sections' => ['*']]; } };
$fullState = $full->deviceAcceptanceState();
$activeReader = new ReflectionMethod(FreePBX\modules\Slsmassnotifyserver::class, 'getActiveSettings');
$fullBefore = $activeReader->invoke($full);
device_check(!empty($fullState['success']), 'Full module could not read its actual configured device catalog.');
$fullSaved = $full->recordDeviceAcceptance($input + ['revision' => $fullState['revision']]);
device_check(!empty($fullSaved['success']), 'Full normalized module rejected an unchanged stored configuration: ' . ($fullSaved['message'] ?? ''));
foreach ([$fullActive => $fullSettings, $fullPending => $fullStaged] as $path => $expected) {
    unset($expected['device_acceptance']);
    $envelope = json_decode(file_get_contents($path), true);
    device_check(FreePBX\modules\SlsConfigCrypto::isEncrypted($envelope), 'Full durable writer did not protect recorded configuration by default.');
    $actual = FreePBX\modules\SlsConfigCrypto::readFile($path); unset($actual['device_acceptance']);
    device_check($actual === $expected, 'Full durable writer changed a legacy value, default or pending edit.');
}
$fullAfter = $activeReader->invoke($full);
$changedKeys = array_keys(array_filter($fullBefore, static fn($value, $key) => $key !== 'device_acceptance' && $value !== ($fullAfter[$key] ?? null), ARRAY_FILTER_USE_BOTH));
device_check($full->deviceAcceptanceState()['report']['current_counts']['passed'] === 1, 'Full module invalidated a newly recorded observation; changed normalized fields: ' . implode(', ', $changedKeys));
unset($_SESSION['AMP_user']);

// Run the actual HTTP controller with inert methods; GET/CSRF/JSON/role/stale
// failures must never dispatch a record or load a normal page.
$page = dirname(__DIR__) . '/slsmassnotifyserver/page.slsmassnotifyserver_help.php';
foreach ([['GET', true, '{}', true, 405, 0], ['POST', false, '{}', true, 403, 0], ['POST', true, '{broken', true, 400, 0],
    ['POST', true, '[]', true, 400, 0], ['POST', true, str_repeat('x', 8193), true, 400, 0],
    ['POST', true, '{"revision":"fixture"}', false, 403, 1], ['POST', true, '{"revision":"fixture"}', true, 200, 1]] as [$method, $csrf, $payload, $allowed, $expected, $calls]) {
    $prefix = '<?php class FreePBX{static function create(){return (object)["Slsmassnotifyserver"=>new Fixture];}}
    class Fixture{function enforceOperatorPageAccess($page){} function validateCsrfToken($token){return ' . var_export($csrf, true) . ';}
    function recordDeviceAcceptance($input){$GLOBALS["calls"]++;return ' . var_export($allowed ? ['success' => true] : ['success' => false, 'error_code' => 'permission_denied', 'message' => 'Denied'], true) . ';}
    function __call($name,$args){throw new RuntimeException("Unexpected action: ".$name);}}
    $GLOBALS["calls"]=0;$_SESSION=[];$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';
    $_POST=["slsmassnotifyserver_action"=>"record_device_acceptance","payload"=>' . var_export($payload, true) . ',"slsmassnotifyserver_csrf"=>"fixture"];$_REQUEST=$_POST;
    register_shutdown_function(static function(){file_put_contents("php://stderr",json_encode([http_response_code()?:200,$GLOBALS["calls"]]));});require ' . var_export($page, true) . ';';
    $process = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $prefix); fclose($pipes[0]); $body = stream_get_contents($pipes[1]); fclose($pipes[1]); $meta = stream_get_contents($pipes[2]); fclose($pipes[2]);
    device_check(proc_close($process) === 0 && json_decode($meta, true) === [$expected, $calls] && is_array(json_decode($body, true)), 'Actual controller failed method/CSRF/payload/permission enforcement: ' . $meta);
}
echo "Device acceptance: $checks observation, provenance, history, permission, actual save and HTTP controller checks passed.\n";
