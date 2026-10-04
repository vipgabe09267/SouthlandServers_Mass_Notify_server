<?php

declare(strict_types=1);

foreach (['nws' => 'triggerTest(', 'lightning' => 'triggerLightningTest('] as $page => $call) {
	$controller = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/page.slsmassnotifyserver_' . $page . '.php');
	$unlock = strpos($controller, 'session_write_close();');
	$csrf = strpos($controller, 'validateCsrfToken(');
	$sender = strpos($controller, '$triggerName =');
	$delivery = strpos($controller, $call);
	if ($unlock === false || $csrf === false || $sender === false || $delivery === false
		|| $csrf >= $unlock || $sender >= $unlock || $unlock >= $delivery
		|| strpos(substr($controller, max(0, $unlock - 150), 150), "(\$_POST['ajax'] ?? '') === '1'") === false) {
		throw new RuntimeException($page . ': authenticated AJAX tests must release the session before waiting for delivery.');
	}
}

if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables = []): string { return ''; } }
if (!function_exists('_')) { function _($value) { return $value; } }

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

final class UiPerformanceFixture extends \FreePBX\modules\Slsmassnotifyserver
{
	public $configuredInventoryCalls = 0;
	public $liveInventoryCalls = 0;

	public function getConfiguredPjsipExtensionNumbers()
	{
		$this->configuredInventoryCalls++;
		return ['1000', '1001'];
	}

	public function getAllPjsipExtensions()
	{
		$this->liveInventoryCalls++;
		throw new RuntimeException('Configuration normalization must not run live endpoint discovery.');
	}
}

function ui_performance_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$parent = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$fixture = (new ReflectionClass(UiPerformanceFixture::class))->newInstanceWithoutConstructor();
$invoke = static function (string $method, ...$arguments) use ($parent, $fixture) {
	$target = $parent->getMethod($method);
	$target->setAccessible(true);
	return $target->invoke($fixture, ...$arguments);
};

$recipients = $invoke('normalizeRecipientExtensions', ['1000', '9999']);
if ($recipients !== ['1000'] || $fixture->liveInventoryCalls !== 0) {
	ui_performance_fail('Recipient normalization contacted live endpoint discovery or kept an unknown extension.');
}

$groups = $invoke('normalizeAnnouncementGroups', [[
	'name' => 'Operations',
	'extensions' => ['1001', '9999'],
]]);
if (($groups[0]['extensions'] ?? []) !== ['1001'] || $fixture->liveInventoryCalls !== 0) {
	ui_performance_fail('Announcement-group normalization contacted live endpoint discovery or kept an unknown extension.');
}

$sourceLines = file($parent->getFileName());
if (!is_array($sourceLines)) {
	ui_performance_fail('Unable to inspect the module source.');
}
$methodSource = static function (string $method) use ($parent, $sourceLines): string {
	$reflection = $parent->getMethod($method);
	return implode('', array_slice(
		$sourceLines,
		$reflection->getStartLine() - 1,
		$reflection->getEndLine() - $reflection->getStartLine() + 1
	));
};

foreach (['getAvailableTones', 'getAvailablePiperVoices'] as $method) {
	if (strpos($methodSource($method), 'ensurePluginDataDir') !== false) {
		ui_performance_fail($method . ' still performs installation/repair work during a read-only listing.');
	}
}

// Exercise the real catalogue against disposable files. Hashing an ONNX file
// from this read-only UI path is an explicit failure, regardless of its size.
$voiceDirectory = sys_get_temp_dir() . '/sls-ui-voices-' . bin2hex(random_bytes(8));
mkdir($voiceDirectory, 0700);
eval('class UiVoiceCatalogueFixture { const PIPER_VOICE_DIR = ' . var_export($voiceDirectory, true) . ';
    private function getPiperVoiceDownloads() { return ["en_US-lessac-low.onnx"=>"unused", "en_US-lessac-low.onnx.json"=>"unused", "es_ES-davefx-medium.onnx"=>"unused"]; }
    private function isValidPiperVoiceFile($path) {
        if (str_ends_with($path, ".onnx")) { throw new RuntimeException("The UI catalogue hashed a speech model."); }
        return $this->isPresentPiperVoiceFile($path) && hash_file("sha256", $path) === hash("sha256", "verified-config");
    }
' . $methodSource('getAvailablePiperVoices') . $methodSource('isPresentPiperVoiceFile') . '}');
eval('class UiVoiceChecksumFixture {' . $methodSource('isPresentPiperVoiceFile') . $methodSource('isValidPiperVoiceFile') . '}');
$catalogue = new UiVoiceCatalogueFixture();
$model = $voiceDirectory . '/en_US-lessac-low.onnx';
$configuration = $model . '.json';
$available = static function () use ($catalogue, $model): bool {
    $rows = $catalogue->getAvailablePiperVoices();
    if (count($rows) !== 2 || count(array_unique(array_column($rows, 'path'))) !== 2) {
        throw new RuntimeException('The fixed voice catalogue lost choices or admitted unknown files.');
    }
    foreach ($rows as $row) { if ($row['path'] === $model) { return $row['available']; } }
    throw new RuntimeException('The saved voice selection disappeared from the catalogue.');
};
$writeModel = static function (int $size) use ($model): void {
    $handle = fopen($model, 'wb');
    try { if (!ftruncate($handle, $size)) { throw new RuntimeException('Cannot create disposable voice fixture.'); } }
    finally { fclose($handle); }
};
try {
    if ($available()) { throw new RuntimeException('A missing speech model was listed as available.'); }
    $writeModel(1000001);
    file_put_contents($configuration, 'verified-config');
    file_put_contents($voiceDirectory . '/unknown.onnx', 'unknown');
    if (!$available()) { throw new RuntimeException('Present model and verified configuration were hidden.'); }
    $verify = new ReflectionMethod(UiVoiceChecksumFixture::class, 'isValidPiperVoiceFile');
    if ($verify->invoke(new UiVoiceChecksumFixture(), $model) || $verify->invoke(new UiVoiceChecksumFixture(), $configuration)) {
        throw new RuntimeException('Full speech verification accepted corrupt model/configuration bytes.');
    }
    file_put_contents($configuration, 'corrupt-config');
    if ($available()) { throw new RuntimeException('An invalid model configuration was listed as available.'); }
    file_put_contents($configuration, 'verified-config');
    $writeModel(100);
    if ($available()) { throw new RuntimeException('A truncated speech model was listed as available.'); }
    $writeModel(1000001);
    rename($model, $voiceDirectory . '/real-model');
    symlink($voiceDirectory . '/real-model', $model);
    if ($available()) { throw new RuntimeException('A symlinked model was listed as available.'); }
    unlink($model);
    link($voiceDirectory . '/real-model', $model);
    if ($available()) { throw new RuntimeException('A multiply-linked model was listed as available.'); }
    unlink($model);
    rename($voiceDirectory . '/real-model', $model);
    unlink($configuration);
    if ($available()) { throw new RuntimeException('A model without its configuration was listed as available.'); }
} finally {
    foreach (glob($voiceDirectory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($voiceDirectory);
}

foreach (['getActiveSettings', 'getPendingSettings'] as $method) {
	$body = $methodSource($method);
	if (strpos($body, 'settingsCacheFingerprint') === false || strpos($body, 'normalizedSettingsCache') === false) {
		ui_performance_fail($method . ' is missing request-local fingerprinted normalization caching.');
	}
}

$settingsCacheProperty = $parent->getProperty('normalizedSettingsCache');
if ($settingsCacheProperty->isStatic()) {
	ui_performance_fail('Normalized settings cache must be isolated to one module instance/PHP request.');
}
$writeBody = $methodSource('writeSettingsFileUnlocked');
if (strpos($writeBody, 'unset($this->normalizedSettingsCache[$path])') === false
	|| strpos($writeBody, 'rememberSettingsFingerprint($path)') === false) {
	ui_performance_fail('Successful settings writes do not invalidate the request cache and refresh the write fingerprint.');
}
$fingerprintBody = $methodSource('settingsCacheFingerprint');
if (strpos($fingerprintBody, 'settingsFileFingerprint') === false) {
	ui_performance_fail('Settings cache entries are not guarded by current on-disk fingerprints.');
}

foreach (['getSipNotifyTargets', 'getAllPjsipExtensions', 'getRegisteredPjsipExtensions', 'getExtensionNameMap'] as $method) {
	if (strpos($methodSource($method), 'Cache') === false) {
		ui_performance_fail($method . ' is missing request-local endpoint inventory caching.');
	}
}

// Exercise inventory fallback and its request-local cache using inert host
// adapters. A failed primary probe must retain the fixed fallback deadline.
eval('namespace FreePBX\\modules; function exec($command, &$output=null, &$status=null) {
    $GLOBALS["sls_ui_probe_calls"][]=$command;
    if (str_contains($command,"--list-endpoints-json")) {$output=[];$status=124;return false;}
    if ($command!=="/usr/bin/timeout --kill-after=1 5 /usr/sbin/asterisk -rx \'pjsip show contacts\' 2>/dev/null") {
        throw new \\RuntimeException("Unexpected or unbounded inventory fallback command.");
    }
    $output=empty($GLOBALS["sls_ui_probe_failure"]) ? ["Contact: 1000/sip:fixture Avail 5"] : [];
    $status=empty($GLOBALS["sls_ui_probe_failure"]) ? 0 : 124;
    return false;
}');
$GLOBALS['sls_ui_probe_calls']=[];$GLOBALS['sls_ui_probe_failure']=false;
$registered=$invoke('getRegisteredPjsipExtensions');$probeCalls=count($GLOBALS['sls_ui_probe_calls']);
if ($registered!==['1000'] || $probeCalls<1 || $invoke('getRegisteredPjsipExtensions')!==['1000']
    || count($GLOBALS['sls_ui_probe_calls'])!==$probeCalls) {
    ui_performance_fail('Failed primary discovery did not recover through the bounded cached Asterisk fallback.');
}
$unavailable=(new ReflectionClass(UiPerformanceFixture::class))->newInstanceWithoutConstructor();
$getRegistered=$parent->getMethod('getRegisteredPjsipExtensions');$GLOBALS['sls_ui_probe_failure']=true;
if ($getRegistered->invoke($unavailable)!==[]) {
    ui_performance_fail('Failed Asterisk fallback inferred a registered extension.');
}
$probeCalls=count($GLOBALS['sls_ui_probe_calls']);$getRegistered->invoke($unavailable);
if (count($GLOBALS['sls_ui_probe_calls'])!==$probeCalls) {
    ui_performance_fail('Repeated UI reads restarted unavailable inventory probes.');
}

echo "UI performance contract tests passed.\n";
