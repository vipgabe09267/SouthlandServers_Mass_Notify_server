<?php
declare(strict_types=1);

if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

final class VoicemailSettingsModuleFixture extends \FreePBX\modules\Slsmassnotifyserver
{
    public function getConfiguredPjsipExtensionNumbers() { return ['1000', '1001']; }
    public function getAllPjsipExtensions() { return []; }
    public function getAvailableTones() { return ['opening_NWS_alert', 'opening_Lightning_alert']; }
    public function getAvailablePiperVoices() {
        return [['path' => self::PIPER_AMY_VOICE], ['path' => self::PIPER_VOICE]];
    }
}

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = (new ReflectionClass(VoicemailSettingsModuleFixture::class))->newInstanceWithoutConstructor();
$invoke = static function (string $name, ...$arguments) use ($reflection, $module) {
    return $reflection->getMethod($name)->invokeArgs($module, $arguments);
};
$invokeArgs = static function (string $name, array &$arguments) use ($reflection, $module) {
    return $reflection->getMethod($name)->invokeArgs($module, $arguments);
};
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
};
$defaults = $invoke('getDefaultSettings');
$defaults['desktop_clients'] = [];
$defaults['desktop_auth_key'] = base64_encode(str_repeat('V', 32));
$defaults['control_api']['api_key'] = str_repeat('a', 32);
$defaults['ami']['password'] = 'synthetic-voicemail-fixture-password';
foreach ([$defaults['nws_voicemail_enabled'], $defaults['xweather']['voicemail_enabled'], $defaults['live_paging']['voicemail_enabled']] as $value) {
    $check($value === '1', 'A service voicemail default is not enabled.');
}

$saved = $defaults;
$saved['nws_voicemail_enabled'] = '0';
$saved['xweather']['voicemail_enabled'] = '0';
$saved['live_paging']['voicemail_enabled'] = '0';
$normalized = $invoke('normalizeSettings', $saved);
foreach ([$normalized['nws_voicemail_enabled'], $normalized['xweather']['voicemail_enabled'], $normalized['live_paging']['voicemail_enabled']] as $value) {
    $check($value === '0', 'Normalization lost an explicitly disabled voicemail preference.');
}
$check($invoke('validateConfigSchema', $saved) === [], 'Canonical disabled voicemail settings are not importable.');
$restored = $invoke('validateNativeBackupConfig', json_encode($saved, JSON_THROW_ON_ERROR));
foreach ([$restored['nws_voicemail_enabled'], $restored['xweather']['voicemail_enabled'], $restored['live_paging']['voicemail_enabled']] as $value) {
    $check($value === '0', 'Native configuration import re-enabled a disabled voicemail preference.');
}
$legacy = $saved;
unset($legacy['nws_voicemail_enabled'], $legacy['xweather']['voicemail_enabled'], $legacy['live_paging']['voicemail_enabled']);
$migrated = $invoke('normalizeSettings', $invoke('validateNativeBackupConfig', json_encode($legacy, JSON_THROW_ON_ERROR)));
foreach ([$migrated['nws_voicemail_enabled'], $migrated['xweather']['voicemail_enabled'], $migrated['live_paging']['voicemail_enabled']] as $value) {
    $check($value === '1', 'Absent legacy voicemail preference did not default on.');
}

foreach (['weather', 'lightning', 'live'] as $service) {
    foreach (['yes', '', 2, [], null] as $value) {
        $bad = $saved;
        if ($service === 'weather') { $bad['nws_voicemail_enabled'] = $value; }
        else { $bad[$service === 'live' ? 'live_paging' : 'xweather']['voicemail_enabled'] = $value; }
        $check($invoke('validateConfigSchema', $bad) !== [], 'Malformed ' . $service . ' voicemail policy imported.');
    }
}
foreach ([false, true] as $value) {
    $patch = $invoke('validateAndNormalizeControlConfigPatch', ['nws_voicemail_enabled' => $value, 'xweather' => ['voicemail_enabled' => $value]]);
    $check($patch['errors'] === [], 'Valid API voicemail booleans were rejected.');
    $merged = $invoke('mergeControlConfigPatch', $saved, $patch['patch']);
    $expected = $saved;
    $expected['nws_voicemail_enabled'] = $value ? '1' : '0';
    $expected['xweather']['voicemail_enabled'] = $value ? '1' : '0';
    $check($merged === $expected, 'Voicemail API patch wiped unrelated settings or nested Lightning values.');
}
foreach (['0', '1', 0, 1, null, [], 'false'] as $value) {
    foreach ([['nws_voicemail_enabled' => $value], ['xweather' => ['voicemail_enabled' => $value]]] as $patch) {
        $check($invoke('validateAndNormalizeControlConfigPatch', $patch)['errors'] !== [], 'API accepted a non-boolean voicemail preference.');
    }
}

// Execute the production save methods with only storage/host adapters replaced.
$methods = '';
foreach (['saveSettings', 'saveLightningSettings'] as $name) {
    $method = $reflection->getMethod($name);
    $lines = file($method->getFileName());
    $methods .= implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
preg_match_all('/self::([A-Z][A-Z_0-9]*)/', $methods, $constantNames);
$constants = '';
foreach (array_unique($constantNames[1]) as $name) {
    $constants .= 'const ' . $name . ' = ' . var_export($reflection->getConstant($name), true) . ';';
}
eval('class VoicemailSettingsSaveFixture {
    use \FreePBX\modules\SlsLivePaging;
    ' . $constants . '
	    public $active; public $pending; public $writes = 0; public $invoke; public $invokeArgs;
    private function getActiveSettings() { return $this->active; }
    private function getPendingSettings() { return $this->pending; }
    private function isSetupComplete($settings) { return true; }
    private function persistPendingSettings($settings) { ++$this->writes; $this->pending = $settings; }
    public function getConfiguredPjsipExtensionNumbers() { return ["1000", "1001"]; }
    public function getAvailableTones() { return ["opening_NWS_alert", "opening_Lightning_alert"]; }
    public function getAvailablePiperVoices() { return [["path"=>\FreePBX\modules\Slsmassnotifyserver::PIPER_AMY_VOICE], ["path"=>\FreePBX\modules\Slsmassnotifyserver::PIPER_VOICE]]; }
    public function getDesktopClients($settings) { return []; }
    private function importSystemSoundAsTone($selection, $prefix, &$errors) { throw new RuntimeException("Unexpected recording import"); }
	    private function resolvePiperVoiceInput(array $input, string $field, $current, string $fallback, array $known, array $ready, array &$errors, string $label): string {
	        $arguments = [$input, $field, $current, $fallback, $known, $ready, &$errors, $label];
	        return ($this->invokeArgs)("resolvePiperVoiceInput", $arguments);
	    }
    public function __call($name, $arguments) { return ($this->invoke)($name, ...$arguments); }
    ' . $methods . '
}');
$fixture = new VoicemailSettingsSaveFixture();
$fixture->invoke = $invoke;
$fixture->invokeArgs = $invokeArgs;
$fixture->active = $defaults;
$fixture->active['public_pbx_host'] = 'active.example.test';
$fixture->pending = $saved;
$fixture->pending['public_pbx_host'] = 'pending.example.test';
$fixture->pending['xweather']['client_secret'] = 'preserve-fixture-lightning-secret';
$fixture->pending['announcement_pronunciation'] = [['phrase' => 'SLS', 'spoken' => 'Southland Servers']];
$fixture->pending['nws_zones'] = [];
$fixture->pending['enabled'] = '0';
$fixture->pending['xweather']['enabled'] = '0';
$fixture->pending = $invoke('normalizeSettings', $fixture->pending);
$activeBefore = $fixture->active;

foreach (['0', null, '1', '0'] as $value) {
    $before = $fixture->pending;
    $input = ['enabled' => '0'];
    if ($value !== null) { $input['nws_voicemail_enabled'] = $value; }
    $result = $fixture->saveSettings($input);
    $check(!empty($result['success']), 'Weather voicemail save failed: ' . json_encode($result));
    $check($fixture->pending['nws_voicemail_enabled'] === ($value ?? $before['nws_voicemail_enabled']), 'Weather save lost explicit or omitted voicemail state.');
    foreach (['public_pbx_host', 'control_api', 'desktop_auth_key', 'live_paging', 'xweather', 'announcement_pronunciation'] as $key) {
        $check($fixture->pending[$key] === $before[$key], 'Weather voicemail save changed unrelated ' . $key . '.');
    }
}
foreach (['0', null, '1', '0'] as $value) {
    $before = $fixture->pending;
    $input = ['xweather' => $before['xweather']];
    if ($value === null) { unset($input['xweather']['voicemail_enabled']); }
    else { $input['xweather']['voicemail_enabled'] = $value; }
    $result = $fixture->saveLightningSettings($input);
    $check(!empty($result['success']), 'Disabled Lightning voicemail save failed: ' . json_encode($result));
    $check($fixture->pending['xweather']['voicemail_enabled'] === ($value ?? $before['xweather']['voicemail_enabled']), 'Disabled Lightning save lost explicit or omitted voicemail state.');
    $after = $fixture->pending;
    unset($after['xweather'], $before['xweather']);
    $check($after === $before, 'Lightning voicemail save changed unrelated settings.');
    $check($fixture->pending['xweather']['client_secret'] === 'preserve-fixture-lightning-secret', 'Lightning voicemail save lost saved credentials.');
}
foreach (['0', null, '1', '0'] as $value) {
    $before = $fixture->pending;
    $input = $before['live_paging'];
    if ($value === null) { unset($input['voicemail_enabled']); }
    else { $input['voicemail_enabled'] = $value; }
    $result = $fixture->saveLivePagingSettings($input);
    $check(!empty($result['success']), 'Live voicemail save failed: ' . json_encode($result));
    $check($fixture->pending['live_paging']['voicemail_enabled'] === ($value ?? $before['live_paging']['voicemail_enabled']), 'Live paging save lost explicit or omitted voicemail state.');
    $after = $fixture->pending;
    unset($after['live_paging'], $before['live_paging']);
    $changed = array_keys(array_filter($after, static function ($entry, $key) use ($before): bool { return !array_key_exists($key, $before) || $before[$key] !== $entry; }, ARRAY_FILTER_USE_BOTH));
    $check($after === $before, 'Live paging voicemail save changed unrelated settings: ' . implode(', ', $changed));
}
foreach (['weather', 'lightning', 'live'] as $service) {
    foreach (['yes', 'false', []] as $value) {
        $before = $fixture->pending;
        $writes = $fixture->writes;
        if ($service === 'weather') {
            $result = $fixture->saveSettings(['enabled' => '0', 'nws_voicemail_enabled' => $value]);
        } elseif ($service === 'lightning') {
            $result = $fixture->saveLightningSettings(['xweather' => ['enabled' => '0', 'voicemail_enabled' => $value]]);
        } else {
            $input = $before['live_paging'];
            $input['voicemail_enabled'] = $value;
            $result = $fixture->saveLivePagingSettings($input);
        }
        $check(empty($result['success']), 'Malformed ' . $service . ' voicemail form flag was saved.');
        $check($fixture->pending === $before && $fixture->writes === $writes, 'Rejected ' . $service . ' voicemail save mutated staged settings.');
    }
}
$check($fixture->active === $activeBefore, 'Staged voicemail edits changed active settings.');

echo "Voicemail settings: $checks actual-method checks passed for defaults, imports, API merge, independent staged saves and omitted disabled preferences.\n";
