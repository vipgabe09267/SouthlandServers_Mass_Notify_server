<?php

declare(strict_types=1);

if (!interface_exists('BMO')) {
	interface BMO {}
}
if (!function_exists('load_view')) {
	function load_view($path, array $variables = []): string
	{
		return '';
	}
}
if (!function_exists('_')) {
	function _($value)
	{
		return $value;
	}
}

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

final class ConfigurationSecurityFixture extends \FreePBX\modules\Slsmassnotifyserver
{
	public function getConfiguredPjsipExtensionNumbers()
	{
		return ['1000'];
	}

	public function getAllPjsipExtensions()
	{
		return [['extension' => '1000', 'name' => 'Test phone', 'registered' => true]];
	}

	public function getDesktopClients(array $settings = null, $includePlaintext = false)
	{
		return array_values(array_filter((array)($settings['desktop_clients'] ?? []), 'is_array'));
	}
}

function configuration_security_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$parent = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$fixture = (new ReflectionClass(ConfigurationSecurityFixture::class))->newInstanceWithoutConstructor();
$call = static function (string $method, ...$arguments) use ($parent, $fixture) {
	$target = $parent->getMethod($method);
	$target->setAccessible(true);
	return $target->invoke($fixture, ...$arguments);
};

$announcementErrors = $call('validateControlApiAnnouncementPayload', [
	'message' => 'Test',
	'all_phones' => 'false',
	'tts' => 1,
]);
if (count($announcementErrors) < 2 || stripos(implode(' ', $announcementErrors), 'JSON boolean') === false) {
	configuration_security_fail('Control API announcement boolean coercion was not rejected.');
}
if (!empty($call('validateControlApiAnnouncementPayload', [
	'message' => 'Test',
	'all_phones' => false,
	'all_desktops' => true,
	'tts' => false,
	'targets' => ['1000'],
]))) {
	configuration_security_fail('A well-typed Control API announcement was rejected.');
}

$selectedEmpty = $call('validateControlApiNwsTestPayload', [
	'zone_scope' => 'selected',
	'zone_ids' => [],
]);
if (stripos(implode(' ', $selectedEmpty), 'at least one') === false) {
	configuration_security_fail('Selected Weather scope accepted an empty zone list.');
}
$allWithIds = $call('validateControlApiNwsTestPayload', [
	'zone_scope' => 'all',
	'zone_ids' => ['primary'],
]);
if (stripos(implode(' ', $allWithIds), 'Do not supply') === false) {
	configuration_security_fail('All-zone Weather scope accepted contradictory zone IDs.');
}
if (!empty($call('validateControlApiNwsTestPayload', [
	'zone_scope' => 'selected',
	'zone_ids' => ['primary'],
]))) {
	configuration_security_fail('A well-typed selected-zone Weather test was rejected.');
}

$unknownPatch = $call('validateAndNormalizeControlConfigPatch', ['not_a_setting' => true]);
if (stripos(implode(' ', $unknownPatch['errors'] ?? []), 'Unknown Control API config field') === false) {
	configuration_security_fail('An unknown Control API config field was silently ignored.');
}
$badBooleanPatch = $call('validateAndNormalizeControlConfigPatch', ['enabled' => 'false']);
if (stripos(implode(' ', $badBooleanPatch['errors'] ?? []), 'JSON boolean') === false) {
	configuration_security_fail('A string Control API config boolean was accepted.');
}
$validPatch = $call('validateAndNormalizeControlConfigPatch', [
	'enabled' => false,
	'nws_api_base_url' => 'https://api.weather.gov',
	'control_api' => ['rate_limit_enabled' => true, 'rate_limit_per_minute' => 30],
]);
if (!empty($validPatch['errors'])
	|| ($validPatch['patch']['enabled'] ?? null) !== '0'
	|| ($validPatch['patch']['control_api']['rate_limit_enabled'] ?? null) !== '1') {
	configuration_security_fail('A valid Control API config patch was not normalized safely.');
}
$badNwsEndpoint = $call('validateAndNormalizeControlConfigPatch', [
	'nws_api_base_url' => 'https://example.com',
]);
if (stripos(implode(' ', $badNwsEndpoint['errors'] ?? []), 'exactly https://api.weather.gov') === false) {
	configuration_security_fail('A non-weather.gov NWS API endpoint was accepted.');
}
if ($call('normalizeNwsApiBaseUrl', 'https://api.weather.gov/') !== 'https://api.weather.gov'
	|| $call('normalizeNwsApiBaseUrl', 'https://example.com') !== '') {
	configuration_security_fail('The canonical NWS endpoint allowlist is not enforced.');
}

$redacted = $call('redactConfigSecrets', [
	'xweather' => ['client_id' => 'identifier', 'client_secret' => 'secret'],
]);
if (($redacted['xweather']['client_id'] ?? '') !== '[redacted]'
	|| ($redacted['xweather']['client_secret'] ?? '') !== '[redacted]') {
	configuration_security_fail('The Control API exposed an Xweather credential.');
}

$typeErrors = $call('validateConfigValueTypes', [
	'enabled' => ['not' => 'scalar'],
	'nws_api_base_url' => 7,
	'nws_zones' => [[
		'zone' => 'TXC491',
		'extensions' => ['1000'],
		'desktop_clients' => [['nested']],
	]],
]);
if (count($typeErrors) < 3) {
	configuration_security_fail('Portable/native config scalar and nested-list validation is incomplete.');
}
$validZoneTypeErrors = $call('validateConfigValueTypes', [
	'nws_zones' => [[
		'id' => 'primary',
		'name' => 'Primary',
		'zone' => 'TXC491',
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => [],
		'quiet_hours_enabled' => '1',
		'quiet_hours_start' => '22:00',
		'quiet_hours_end' => '06:00',
		'quiet_critical_events' => ['Tornado Warning'],
		'discord_webhook_ids' => [],
		'generic_webhook_ids' => [],
	]],
]);
if (!empty($validZoneTypeErrors)) {
	configuration_security_fail('A normalized Weather-zone quiet-hours policy was rejected as an overbroad protected configuration: ' . implode(' ', $validZoneTypeErrors));
}
$validZonePatch = $call('validateAndNormalizeControlConfigPatch', [
	'nws_zones' => [[
		'id' => 'primary',
		'name' => 'Primary',
		'zone' => 'TXC491',
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => [],
		'quiet_hours_enabled' => true,
		'quiet_hours_start' => '22:00',
		'quiet_hours_end' => '06:00',
		'quiet_critical_events' => ['Tornado Warning'],
		'discord_webhook_ids' => [],
		'generic_webhook_ids' => [],
	]],
]);
if (!empty($validZonePatch['errors'])
	|| ($validZonePatch['patch']['nws_zones'][0]['quiet_hours_enabled'] ?? null) !== '1') {
	configuration_security_fail('A valid Control API Weather-zone quiet-hours policy was rejected.');
}
$assignmentErrors = $call('validateNwsZoneDesktopAssignments', [[
	'name' => 'Operations',
	'zone' => 'TXC491',
	'extensions' => [],
	'desktop_clients' => ['missing-client', 'disabled-client'],
]], [
	'desktop_clients' => [[
		'username' => 'disabled-client',
		'enabled' => '0',
	]],
]);
$assignmentText = implode(' ', $assignmentErrors);
if (stripos($assignmentText, 'unknown desktop client') === false || stripos($assignmentText, 'Enable desktop client') === false) {
	configuration_security_fail('Config import did not reject unknown and disabled Weather-zone desktops.');
}
$lightningAssignmentErrors = $call('validateXweatherGroupDesktopAssignments', [[
	'name' => 'North Campus',
	'desktop_clients' => ['missing-client', 'disabled-client'],
]], [
	'desktop_clients' => [[
		'username' => 'disabled-client',
		'enabled' => '0',
	]],
]);
$lightningAssignmentText = implode(' ', $lightningAssignmentErrors);
if (stripos($lightningAssignmentText, 'unknown desktop client') === false || stripos($lightningAssignmentText, 'Enable desktop client') === false) {
	configuration_security_fail('Config import did not reject unknown and disabled Lightning-area desktops.');
}

$badLightningPatch = $call('validateAndNormalizeControlConfigPatch', [
	'xweather' => ['groups' => [[
		'id' => 'north',
		'name' => 'North Campus',
		'enabled' => 'true',
		'adaptive_nws_zone_id' => 'weather_primary',
		'location' => 'North Campus',
		'radius_miles' => 10,
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => [],
		'all_clear' => 'none',
	]]],
]);
if (stripos(implode(' ', $badLightningPatch['errors'] ?? []), 'JSON boolean') === false) {
	configuration_security_fail('Control API accepted a string Lightning group enable flag.');
}
$validLightningPatch = $call('validateAndNormalizeControlConfigPatch', [
	'xweather' => ['groups' => [[
		'id' => 'north',
		'name' => 'North Campus',
		'enabled' => true,
		'adaptive_nws_zone_id' => 'weather_primary',
		'location' => 'North Campus',
		'radius_miles' => 10,
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => ['north@example.com'],
		'all_clear' => 'none',
	]]],
]);
if (!empty($validLightningPatch['errors']) || ($validLightningPatch['patch']['xweather']['groups'][0]['enabled'] ?? null) !== '1') {
	configuration_security_fail('A valid Control API Lightning group patch was not normalized safely.');
}

if (!$call('hasConfiguredNotificationEmailRecipients', [
	'mail_to' => '',
	'nws_zones' => [['email_recipients' => ['zone@example.com']]],
])) {
	configuration_security_fail('Dashboard health ignored zone-specific email transport requirements.');
}
if (!$call('hasConfiguredNotificationEmailRecipients', [
	'mail_to' => '',
	'xweather' => ['groups' => [['email_recipients' => ['lightning@example.com']]]],
])) {
	configuration_security_fail('Dashboard health ignored Lightning-area email transport requirements.');
}

$classSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
foreach ([
	"array_key_exists('apply', \$payload) && !is_bool(\$payload['apply'])",
	"(\$payload['apply'] ?? false) === true",
] as $marker) {
	if (strpos($classSource, $marker) === false) {
		configuration_security_fail("Strict Control API apply marker is missing: {$marker}");
	}
}
$apiSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/index.php');
foreach (['array_is_list($body)', 'action_must_be_string'] as $marker) {
	if (strpos($apiSource, $marker) === false) {
		configuration_security_fail("Control API JSON-object validation marker is missing: {$marker}");
	}
}
$setupSource = explode("\n\tpublic function saveSettings", explode("\tpublic function saveSetupWizard", $classSource, 2)[1] ?? '', 2)[0] ?? '';
foreach (['$existingNwsZones', '$existingNwsZones[0] = $primaryNwsZone', '$settings[\'control_api\'] = $control'] as $marker) {
	if (strpos($setupSource, $marker) === false) {
		configuration_security_fail("Setup rerun preservation marker is missing: {$marker}");
	}
}
$generalSource = explode("\n\tpublic function regenerateControlApiKey", explode("\tpublic function saveOtherSettings", $classSource, 2)[1] ?? '', 2)[0] ?? '';
if (strpos($generalSource, 'validateNwsZoneDesktopAssignments') === false) {
	configuration_security_fail('General Settings can still silently disable/delete a referenced desktop client.');
}
if (strpos($classSource, 'claimLightningTestCooldown()') === false || strpos($classSource, "flock(\$handle, LOCK_EX)") === false) {
	configuration_security_fail('Lightning manual-test cooldown is not an atomic claim.');
}

$weatherView = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/settings.php');
foreach (['disabled — uncheck to remove this assignment', 'missing — uncheck to remove'] as $marker) {
	if (strpos($weatherView, $marker) === false) {
		configuration_security_fail("Weather desktop unassignment UI marker is missing: {$marker}");
	}
}
$xweatherWorker = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py');
foreach (['XWEATHER_LOCK_FILE', 'LOCK_EX | fcntl.LOCK_NB'] as $marker) {
	if (strpos($xweatherWorker, $marker) === false) {
		configuration_security_fail("Xweather worker serialization marker is missing: {$marker}");
	}
}
foreach (["child_relative == 'piper/venv'", "metadata.st_uid != 0", "stat.S_IMODE(metadata.st_mode) & 0o022", "raise RuntimeError('runtime tree contains a symbolic link: '", "SLS runtime safety: "] as $marker) {
	if (strpos($classSource, $marker) === false) {
		configuration_security_fail("The web-safe root-owned Piper compatibility boundary is missing: {$marker}");
	}
}

foreach (['dashboard-announcement-section.php', 'dedicated-sounds', 'mass-notify-api', 'visual-sip-notify-sender'] as $legacyLinkMarker) {
	if (strpos($classSource, "'" . $legacyLinkMarker . "'") === false) {
		configuration_security_fail('Known legacy runtime-link migration is incomplete: ' . $legacyLinkMarker);
	}
}
$cleanupPosition = strpos($classSource, '$this->cleanupLegacyRuntimeArtifacts();');
$permissionPosition = strpos($classSource, '$this->ensureRuntimePermissions();');
if ($cleanupPosition === false || $permissionPosition === false || $cleanupPosition > $permissionPosition) {
	configuration_security_fail('Legacy runtime links are not cleaned before the fail-closed permission scan.');
}

echo "Configuration and Control API security contract tests passed.\n";
