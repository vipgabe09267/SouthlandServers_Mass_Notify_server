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

function destination_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

final class NotificationDestinationFixture extends \FreePBX\modules\Slsmassnotifyserver
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

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = (new ReflectionClass(NotificationDestinationFixture::class))->newInstanceWithoutConstructor();
$call = static function (string $method, ...$arguments) use ($reflection, $module) {
	$target = $reflection->getMethod($method);
	$target->setAccessible(true);
	return $target->invoke($module, ...$arguments);
};

foreach ([
	'no-reply' => 'no-reply',
	'Alerts.Team+PBX' => 'alerts.team+pbx',
	'a' => 'a',
] as $input => $expected) {
	if ($call('normalizeEmailSenderLocalPart', $input) !== $expected) {
		destination_fail("Valid sender local part was rejected: {$input}");
	}
}
foreach (['', '.alerts', 'alerts.', 'alerts..team', 'alerts@example.com', "alerts\r\nBcc:x@y.tld", str_repeat('a', 65)] as $input) {
	if ($call('normalizeEmailSenderLocalPart', $input) !== '') {
		destination_fail("Invalid sender local part was accepted: {$input}");
	}
}

foreach (['alerts@example.com', 'ops.team+pbx@sub.example.net'] as $input) {
	if (!$call('isValidNotificationEmailAddress', $input)) {
		destination_fail("Valid notification recipient was rejected: {$input}");
	}
}
foreach (['a@[127.0.0.1]', 'a@localhost', '.a@example.com', 'a..b@example.com', 'a@example.123'] as $input) {
	if ($call('isValidNotificationEmailAddress', $input)) {
		destination_fail("Runtime-incompatible notification recipient was accepted: {$input}");
	}
}

$discord = 'https://discord.com/api/webhooks/123456789/token._~-Value';
if ($call('normalizeDiscordWebhookUrl', $discord) !== $discord) {
	destination_fail('A valid Discord webhook was rejected.');
}
foreach ([
	'http://discord.com/api/webhooks/1/token',
	'https://evil.example/api/webhooks/1/token',
	'https://discord.com/api/webhooks/1/token?leak=1',
	'https://user:pass@discord.com/api/webhooks/1/token',
] as $input) {
	if ($call('normalizeDiscordWebhookUrl', $input) !== '') {
		destination_fail("An invalid Discord webhook was accepted: {$input}");
	}
}

$generic = 'https://hooks.example.com/v1/events?tenant=operations';
if ($call('normalizeGenericWebhookUrl', $generic) !== $generic) {
	destination_fail('A valid generic webhook was rejected.');
}
foreach ([
	'http://hooks.example.com/v1/events',
	'https://127.0.0.1/hook',
	'https://hooks.local/hook',
	'https://user:pass@hooks.example.com/hook',
	'https://hooks.example.com:8443/hook',
	'https://hooks.example.com/hook#fragment',
] as $input) {
	if ($call('normalizeGenericWebhookUrl', $input) !== '') {
		destination_fail("An invalid generic webhook was accepted: {$input}");
	}
}

$normalized = $call('normalizeWebhookDestinations', [
	['id' => 'shared', 'name' => 'Primary', 'url' => $discord, 'enabled' => '1'],
	['id' => 'shared', 'name' => 'Secondary', 'url' => 'https://discord.com/api/webhooks/987654321/another-token', 'enabled' => '0'],
], 'discord');
if (count($normalized) !== 2 || $normalized[0]['id'] === $normalized[1]['id'] || $normalized[1]['enabled'] !== '0') {
	destination_fail('Destination normalization did not preserve rows with unique stable IDs and enabled state.');
}

$validation = $call('validateWebhookDestinations', [
	['id' => 'duplicate', 'name' => 'One', 'url' => $discord],
	['id' => 'duplicate', 'name' => 'Two', 'url' => 'https://discord.com/api/webhooks/987654321/another-token'],
], 'discord');
if (count($validation) !== 1 || stripos(implode(' ', $validation), 'destination ID') === false) {
	destination_fail('Duplicate destination IDs were not rejected visibly.');
}

$merged = $call('mergeWebhookDestinationSecrets', [
	['id' => 'saved', 'name' => 'Renamed', 'url' => '[redacted]', 'enabled' => '1'],
], [
	['id' => 'saved', 'name' => 'Original', 'url' => $discord, 'enabled' => '1'],
], 'discord');
if (($merged[0]['url'] ?? '') !== $discord || ($merged[0]['name'] ?? '') !== 'Renamed') {
	destination_fail('A redacted nested destination update did not preserve the stored secret by ID.');
}

$redacted = $call('redactConfigSecrets', [
	'discord_webhook_url' => $discord,
	'discord_webhooks' => [['id' => 'd', 'url' => $discord]],
	'generic_webhooks' => [['id' => 'g', 'url' => $generic]],
]);
if (($redacted['discord_webhook_url'] ?? '') !== '[redacted]'
	|| ($redacted['discord_webhooks'][0]['url'] ?? '') !== '[redacted]'
	|| ($redacted['generic_webhooks'][0]['url'] ?? '') !== '[redacted]') {
	destination_fail('Control API redaction exposed a nested webhook URL.');
}

$view = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/other_settings.php');
foreach (['system_notification_recipients_present', 'system_notification_recipients[]', 'System and Error Notifications', 'Weather and Lightning email recipients are selected within each zone or trigger area.', 'discord_webhooks_present', 'generic_webhooks_present', 'value="" autocomplete="new-password"'] as $marker) {
	if (strpos($view, $marker) === false) {
		destination_fail("Notification destination UI secret-preservation contract is missing: {$marker}");
	}
}
foreach (['name="mail_recipients[]"', 'Shared Alert Destinations', 'Live Weather and Lightning alerts are submitted to every enabled destination.'] as $obsoleteMarker) {
	if (strpos($view, $obsoleteMarker) !== false) {
		destination_fail("General Settings still presents the legacy global live-alert email model: {$obsoleteMarker}");
	}
}

$legacyPatch = $call('validateAndNormalizeControlConfigPatch', ['mail_to' => 'legacy@example.com']);
if (empty($legacyPatch['errors']) || stripos(implode(' ', $legacyPatch['errors']), 'legacy live-alert field') === false) {
	destination_fail('Legacy Control API mail_to input was not rejected with safe migration guidance.');
}
$separateWeatherCapacity = $call('validateNwsZoneEmailCapacity', [[
	'name' => 'Test zone',
	'email_recipients' => ['weather@example.com'],
]], implode(' ', array_map(static fn (int $index): string => "system{$index}@example.com", range(1, 50))));
if (!empty($separateWeatherCapacity)) {
	destination_fail('System/error recipients were incorrectly counted against a Weather zone email list.');
}
$separateLightningCapacity = $call('validateXweatherGroupEmailCapacity', [[
	'name' => 'Test area',
	'email_recipients' => ['lightning@example.com'],
]], implode(' ', array_map(static fn (int $index): string => "system{$index}@example.com", range(1, 50))));
if (!empty($separateLightningCapacity)) {
	destination_fail('System/error recipients were incorrectly counted against a Lightning area email list.');
}
$migratedServiceRecipients = $call(
	'mergeServiceEmailRecipients',
	['zone@example.com', 'duplicate@example.com'],
	['legacy@example.com', 'DUPLICATE@example.com']
);
if ($migratedServiceRecipients !== ['zone@example.com', 'duplicate@example.com', 'legacy@example.com']) {
	destination_fail('Legacy global live-alert recipients were not migrated into service routes safely.');
}

$legacySettings = $call('getDefaultSettings');
unset($legacySettings['system_notification_emails']);
$legacySettings['mail_to'] = 'Legacy@Example.com legacy@example.com';
$legacySettings['nws_zones'] = [[
	'id' => 'weather', 'name' => 'Weather', 'zone' => 'TXZ163', 'extensions' => ['1000'],
	'desktop_clients' => [], 'email_recipients' => ['zone@example.com'],
]];
$legacySettings['xweather']['groups'] = [[
	'id' => 'lightning', 'name' => 'Lightning', 'enabled' => '1', 'adaptive_nws_zone_id' => 'weather',
	'location' => 'Austin, TX', 'radius_miles' => 10, 'extensions' => ['1000'],
	'desktop_clients' => [], 'email_recipients' => ['area@example.com'], 'all_clear' => 'none',
]];
$legacyNormalized = $call('normalizeSettings', $legacySettings);
if (($legacyNormalized['system_notification_emails'] ?? null) !== ''
	|| ($legacyNormalized['mail_to'] ?? null) !== ''
	|| ($legacyNormalized['nws_zones'][0]['email_recipients'] ?? []) !== ['zone@example.com', 'legacy@example.com']
	|| ($legacyNormalized['xweather']['groups'][0]['email_recipients'] ?? []) !== ['area@example.com', 'legacy@example.com']) {
	destination_fail('Direct legacy normalization did not preserve live routes while leaving system/error mail opt-in.');
}
$legacyNormalizedTwice = $call('normalizeSettings', $legacyNormalized);
if (($legacyNormalizedTwice['nws_zones'][0]['email_recipients'] ?? []) !== ['zone@example.com', 'legacy@example.com']
	|| ($legacyNormalizedTwice['xweather']['groups'][0]['email_recipients'] ?? []) !== ['area@example.com', 'legacy@example.com']) {
	destination_fail('Legacy email migration was not idempotent.');
}
$explicitSettings = $legacySettings;
$explicitSettings['system_notification_emails'] = '';
$explicitSettings['nws_zones'][0]['email_recipients'] = ['zone@example.com'];
$explicitSettings['xweather']['groups'][0]['email_recipients'] = ['area@example.com'];
$explicitNormalized = $call('normalizeSettings', $explicitSettings);
if (($explicitNormalized['nws_zones'][0]['email_recipients'] ?? []) !== ['zone@example.com']
	|| ($explicitNormalized['xweather']['groups'][0]['email_recipients'] ?? []) !== ['area@example.com']) {
	destination_fail('An explicit canonical system field failed to disable legacy live-route migration.');
}

$classSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$saveOther = explode("\n\tpublic function regenerateControlApiKey", explode("\tpublic function saveOtherSettings", $classSource, 2)[1] ?? '', 2)[0] ?? '';
$validationStop = strpos($saveOther, "'message' => _('General settings were not saved.')");
$pendingWrite = strpos($saveOther, '$this->persistPendingSettings($settings)');
if ($validationStop === false || $pendingWrite === false || $validationStop >= $pendingWrite) {
	destination_fail('General Settings does not fail closed before staging invalid destinations.');
}
$controlUpdate = explode("\n\tpublic function controlApi", explode("\tpublic function controlApiUpdateConfig", $classSource, 2)[1] ?? '', 2)[0] ?? '';
foreach ([
	"array_key_exists('system_notification_emails', \$settingsPatch)",
	'validateEmailRecipientsInput($settingsPatch[\'system_notification_emails\'])',
	"array_key_exists('discord_webhook_url', \$settingsPatch)",
	'Use the discord_webhooks destination array',
] as $marker) {
	if (strpos($controlUpdate, $marker) === false) {
		destination_fail("Control API destination fail-closed marker is missing: {$marker}");
	}
}
foreach ([
	"'system_notification_emails' => ''",
	"!array_key_exists('system_notification_emails', \$decoded)",
	"\$settings['_legacy_live_email_recipients'] = \$legacyMailRecipients",
	"\$settings['mail_to'] = \$settings['system_notification_emails']",
] as $marker) {
	if (strpos($classSource, $marker) === false) {
		destination_fail("System notification migration marker is missing: {$marker}");
	}
}
if (strpos($classSource, "['ok', 'queued', 'submitted', 'accepted', 'healthy']") === false
	|| strpos($classSource, "['fault', 'failed', 'error', 'partial_failure']") === false) {
	destination_fail('Dashboard status normalization does not recognize bounded external-delivery results.');
}

echo "Notification destination PHP contract tests passed.\n";
