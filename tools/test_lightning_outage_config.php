<?php
/** Actual policy/normalization/import methods plus isolated Lightning save facade. */
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('_')) { function _($value) { return $value; } }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
class LightningOutagePolicyFixture extends \FreePBX\modules\Slsmassnotifyserver {
    public function getConfiguredPjsipExtensionNumbers() { return ["1000"]; }
}
$module = (new ReflectionClass(LightningOutagePolicyFixture::class))->newInstanceWithoutConstructor();
$call = static function (string $name, ...$args) use ($reflection, $module) {
    $method = $reflection->getMethod($name); $method->setAccessible(true);
    return $method->invoke($module, ...$args);
};
$count = 0;
$check = static function ($condition, string $message) use (&$count): void {
    ++$count; if (!$condition) { throw new RuntimeException($message); }
};
$defaults = $call('getDefaultSettings');
// The fresh default desktop is an unprovisioned placeholder, not a backup credential.
$defaults['desktop_clients'] = [];
$normal = $call('normalizeXweatherSettings', []);
foreach ([$defaults['xweather'], $normal] as $value) {
    $check($value['adaptive_gate_failure_policy'] === 'standby' && $value['adaptive_fallback_minutes'] === 30, 'Default policy must preserve standby and 30-minute budget.');
}
foreach (['standby', 'bounded_poll'] as $policy) {
    foreach ([15, 30, 120] as $minutes) {
        $value = ['adaptive_gate_failure_policy' => $policy, 'adaptive_fallback_minutes' => $minutes];
        $check($call('validateXweatherAdaptivePolicyInput', $value) === [], 'Valid policy rejected.');
        $normalized = $call('normalizeXweatherSettings', $value);
        $check($normalized['adaptive_gate_failure_policy'] === $policy && $normalized['adaptive_fallback_minutes'] === $minutes, 'Normalization changed valid budget.');
        $patch = $call('validateAndNormalizeControlConfigPatch', ['xweather' => $value]);
        $check(empty($patch['errors']), 'Valid API patch rejected: ' . json_encode($patch['errors']));
        $import = $defaults; $import['xweather'] = array_replace($import['xweather'], $value);
        $restored = $call('validateNativeBackupConfig', json_encode($import));
        $check($restored['xweather']['adaptive_gate_failure_policy'] === $policy && $restored['xweather']['adaptive_fallback_minutes'] === $minutes, 'Native import lost policy.');
    }
}
foreach (['15', '30', '120'] as $minutes) {
    $check($call('validateXweatherAdaptivePolicyInput', ['adaptive_fallback_minutes' => $minutes]) === [], 'HTML integer value rejected.');
    $check(!empty($call('validateAndNormalizeControlConfigPatch', ['xweather' => ['adaptive_fallback_minutes' => $minutes]])['errors']), 'API accepted string instead of JSON integer.');
}
$invalid = [];
foreach (['', 'poll', 'BOUNDED_POLL', false, null, [], 1] as $value) { $invalid[] = ['adaptive_gate_failure_policy' => $value]; }
foreach ([14, 121, -1, 0, 30.0, true, null, [], '15.0', '1e2', ' 30', '30 ', '9999'] as $value) { $invalid[] = ['adaptive_fallback_minutes' => $value]; }
foreach ($invalid as $value) {
    $check(!empty($call('validateXweatherAdaptivePolicyInput', $value)), 'Malformed policy accepted.');
    $check(!empty($call('validateAndNormalizeControlConfigPatch', ['xweather' => $value])['errors']), 'Malformed API policy accepted.');
    $import = $defaults; $import['xweather'] = array_replace($import['xweather'], $value);
    $rejected = false;
    try { $call('validateNativeBackupConfig', json_encode($import, JSON_PRESERVE_ZERO_FRACTION)); }
    catch (RuntimeException $error) { $rejected = true; }
    $check($rejected, 'Native import accepted malformed policy.');
}
// Run the real save facade, replacing only storage/host adapters. The actual
// normalizers and policy validation above remain in the call path below.
$method = $reflection->getMethod('saveLightningSettings');
$lines = file($method->getFileName());
$body = implode('', array_slice($lines, $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
eval('class LightningOutageSaveFixture {
 const DEFAULT_LIGHTNING_OPENING_TONE = "opening_Lightning_alert";
 public $call; public $directory; public $toneImports = 0; public $writes = 0;
 private function getActiveSettings() { return json_decode(file_get_contents($this->directory."/active.config"), true); }
 private function getPendingSettings() { return json_decode(file_get_contents($this->directory."/pending.config"), true); }
 private function isSetupComplete($settings) { return true; }
 private function persistPendingSettings($settings) { ++$this->writes; file_put_contents($this->directory."/pending.config", json_encode($settings)); }
 private function importSystemSoundAsTone($selection, $prefix, &$errors) { ++$this->toneImports; return "opening_Lightning_alert"; }
 public function getAvailableTones() { return ["opening_Lightning_alert"]; }
 public function __call($name, $args) { return ($this->call)($name, ...$args); }
 '.$body.'}');
$directory = sys_get_temp_dir() . '/sls-lightning-outage-' . bin2hex(random_bytes(10));
mkdir($directory, 0700);
try {
    $fixture = new LightningOutageSaveFixture(); $fixture->call = $call; $fixture->directory = $directory;
    $active = $defaults; $active['public_pbx_host'] = 'active.example';
    $pending = $defaults; $pending['public_pbx_host'] = 'pending.example';
    $pending['xweather']['adaptive_gate_failure_policy'] = 'bounded_poll';
    $pending['xweather']['adaptive_fallback_minutes'] = 90;
    $pending['xweather']['client_secret'] = 'preserved-fixture-secret';
    file_put_contents($directory.'/active.config', json_encode($active));
    file_put_contents($directory.'/pending.config', json_encode($pending));
    $activeBytes = file_get_contents($directory.'/active.config');
    $before = file_get_contents($directory.'/pending.config');
    $result = $fixture->saveLightningSettings(['xweather' => ['adaptive_fallback_minutes' => 121, 'opening_tone' => 'system:fixture']]);
    $check(!$result['success'] && $fixture->writes === 0 && $fixture->toneImports === 0, 'Invalid policy caused tone/storage side effects.');
    $check(file_get_contents($directory.'/pending.config') === $before, 'Invalid save changed pending config.');
    foreach ([[], ['adaptive_gate_failure_policy' => 'standby', 'adaptive_fallback_minutes' => '15']] as $index => $input) {
        $result = $fixture->saveLightningSettings(['xweather' => $input]);
        $check($result['success'], 'Valid staged save failed: '.json_encode($result));
        $saved = json_decode(file_get_contents($directory.'/pending.config'), true);
        $unrelatedSaved = $saved; $unrelatedPending = $pending;
        unset($unrelatedSaved['xweather'], $unrelatedPending['xweather']);
        $check($unrelatedSaved === $unrelatedPending, 'Lightning save changed unrelated pending settings.');
        $check($saved['public_pbx_host'] === 'pending.example' && $saved['xweather']['client_secret'] === 'preserved-fixture-secret', 'Save lost unrelated pending settings or existing secret.');
        $check($saved['xweather']['adaptive_gate_failure_policy'] === ($index ? 'standby' : 'bounded_poll') && $saved['xweather']['adaptive_fallback_minutes'] === ($index ? 15 : 90), 'Old form reset policy or explicit form failed to change it.');
        $check(file_get_contents($directory.'/active.config') === $activeBytes, 'Staged save changed active config bytes.');
    }
} finally {
    @unlink($directory.'/active.config'); @unlink($directory.'/pending.config'); @rmdir($directory);
}
echo "Lightning outage configuration: $count actual-method checks passed; no PBX state touched.\n";
