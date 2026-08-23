<?php

declare(strict_types=1);

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

echo "UI performance contract tests passed.\n";
