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

$unicodeSettings = $call('getDefaultSettings');
$unicodeSettings['mail_from_name'] = str_repeat('é', 39) . '🙂';
$unicodeSettings['enabled'] = false;
$unicodeSettings = $call('normalizeSettings', $unicodeSettings);
if (!mb_check_encoding($unicodeSettings['mail_from_name'], 'UTF-8') || strlen($unicodeSettings['mail_from_name']) > 80
	|| $unicodeSettings['mail_from_name'] !== str_repeat('é', 39)) {
	configuration_security_fail('Mail display-name normalization split or exceeded its UTF-8 byte boundary.');
}
if ($unicodeSettings['enabled'] !== '0') {
	configuration_security_fail('Boolean false enabled Weather Alert delivery during settings normalization.');
}
$malformedScheduleErrors = $call('validateScheduledAnnouncementRecurrences', ['invalid'], [
	['id' => 'grp_fixture', 'voice_recipient_ids' => ['voice_fixture']],
]);
if (stripos(implode(' ', $malformedScheduleErrors), 'structurally invalid') === false) {
	configuration_security_fail('Malformed scheduled-announcement records did not fail closed.');
}
$invalidGroupErrors = $call('validateAnnouncementGroupFields', [
	['id'=>'grp_'.str_repeat('a',12), 'name'=>'Fixture', 'extensions'=>['1000'], 'desktop_clients'=>[]],
	['id'=>'grp_'.str_repeat('a',12), 'name'=>'Fixture two', 'extensions'=>['10!00'], 'desktop_clients'=>['BAD USER']],
]);
if (stripos(implode(' ', $invalidGroupErrors), 'duplicates') === false
	|| stripos(implode(' ', $invalidGroupErrors), 'invalid or duplicate identifier') === false) {
	configuration_security_fail('Malformed or duplicate saved-audience identities were silently canonicalized.');
}
$scheduleFlagRejected = false;
try {
	$call('normalizeScheduledAnnouncements', [[
		'id' => 'sched_fixture', 'name' => 'Fixture', 'message' => 'Fixture', 'enabled' => 'false', 'timezone' => 'UTC',
		'occurrences' => [['run_at_utc' => '2030-01-01T00:00:00Z']],
		'targets' => ['phones_all' => 'false', 'desktop_all' => 'false', 'extensions' => ['1000']],
	]]);
} catch (InvalidArgumentException $error) {
	$scheduleFlagRejected = stripos($error->getMessage(), 'enabled or disabled') !== false;
}
if (!$scheduleFlagRejected) {
	configuration_security_fail('Non-boolean schedule flags were not rejected during normalization.');
}

if ($call('hourToMinutes', '23:59') !== 1439 || $call('hourToMinutes', '24:00') !== 0
	|| $call('hourToMinutes', '29:59') !== 0 || $call('hourToMinutes', '12:60') !== 0) {
	configuration_security_fail('Quiet-hours clock parsing accepted an invalid wall-clock time.');
}

foreach ([0, 63, 4097, '512', true, [], null] as $value) {
	$result = $call('validateAndNormalizeControlConfigPatch', ['generated_media_cache_mib'=>$value]);
	if (!$result['errors']) { configuration_security_fail('Unsafe generated media limit was accepted.'); }
}
foreach ([64, 512, 4096] as $value) {
	$result = $call('validateAndNormalizeControlConfigPatch', ['generated_media_cache_mib'=>$value]);
	if ($result['errors'] || $result['patch']['generated_media_cache_mib'] !== $value) { configuration_security_fail('Valid generated media limit was changed or rejected.'); }
}
$programmePatch = $call('validateAndNormalizeControlConfigPatch', [
	'schedule_programmes' => \SLS\MassNotify\ScheduleProgrammes::defaults(),
]);
if (!empty($programmePatch['errors']) || ($programmePatch['patch']['schedule_programmes']['schema'] ?? null) !== 1) {
	configuration_security_fail('A valid shared-calendar Control API patch was rejected or changed unexpectedly.');
}
foreach ([[true], 'invalid', ['schema'=>1, 'enabled'=>'false', 'calendars'=>[]]] as $invalidProgramme) {
	$programmePatch = $call('validateAndNormalizeControlConfigPatch', ['schedule_programmes'=>$invalidProgramme]);
	if (empty($programmePatch['errors'])) {
		configuration_security_fail('A malformed shared-calendar Control API patch was accepted.');
	}
}

$boundedSettings = $call('getDefaultSettings');
$boundedSettings['generated_media_cache_mib'] = 'unbounded';
$boundedSettings['speech_synthesis_limit'] = 99;
$boundedSettings['speech_queue_limit'] = -1;
$boundedSettings['speech_queue_wait_seconds'] = 61;
$boundedSettings['generated_media_min_free_mib'] = [];
$boundedSettings['announcement_sms']['enabled'] = '0';
$boundedSettings = $call('normalizeSettings', $boundedSettings);
if (($boundedSettings['generated_media_cache_mib'] ?? null) !== 512
	|| ($boundedSettings['speech_synthesis_limit'] ?? null) !== 1
	|| ($boundedSettings['speech_queue_limit'] ?? null) !== 8
	|| ($boundedSettings['speech_queue_wait_seconds'] ?? null) !== 15
	|| ($boundedSettings['generated_media_min_free_mib'] ?? null) !== 128
	|| ($boundedSettings['announcement_sms']['enabled'] ?? null) !== false) {
	configuration_security_fail('Central settings normalization retained malformed resource or SMS values.');
}

$aggregateErrors = $call('validateAggregateSavedAudienceTargets', [
	'groups'=>['group-a'],
	'webhook_ids'=>array_map(static fn($index)=>'direct-'.$index, range(1, 6)),
], [[
	'id'=>'group-a',
	'webhook_ids'=>array_map(static fn($index)=>'group-'.$index, range(1, 5)),
]]);
if (stripos(implode(' ', $aggregateErrors), '10 webhook') === false) {
	configuration_security_fail('Saved audiences can exceed the aggregate webhook destination limit.');
}

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
$selectorErrors = $call('validateControlApiAnnouncementPayload', [
	'message' => 'Test',
	'targets' => ['10!00'],
	'options' => ['desktop_clients' => ['Bad User']],
]);
if (stripos(implode(' ', $selectorErrors), 'canonical numeric') === false
	|| stripos(implode(' ', $selectorErrors), 'canonical saved desktop') === false) {
	configuration_security_fail('The Control API still accepts selectors that normalize to a different recipient.');
}
if ($call('normalizeExtensionSelector', '1000') !== '1000'
	|| $call('normalizeExtensionSelector', '10!00') !== ''
	|| $call('normalizeExtensionSelector', ' 1000 ') !== '1000') {
	configuration_security_fail('Canonical extension normalization can still retarget malformed input.');
}
if ($call('normalizeDesktopRecordId', 'desk_ops') !== 'desk_ops'
	|| $call('normalizeDesktopRecordId', 'desk_ops!') !== '') {
	configuration_security_fail('Desktop record IDs can still be rewritten into another saved record.');
}
$desktopIdentityErrors = $call('validateDesktopClientIdentifiers', [[
	'id' => 'desk_ops!', 'client_id' => 'CLI bad', 'username' => 'Bad User', 'enabled' => 'false',
]]);
$desktopIdentityText = implode(' ', $desktopIdentityErrors);
foreach (['record ID is invalid', 'usernames must be canonical', 'client IDs must be canonical', 'enabled state'] as $expected) {
	if (stripos($desktopIdentityText, $expected) === false) {
		configuration_security_fail('Desktop identity validation missed: ' . $expected);
	}
}
$scheduledSelectorErrors = $call('validateScheduledAnnouncementRecurrences', [[
	'id' => 'sched_selector_fixture',
	'name' => 'Selector fixture',
	'message' => 'Selector fixture',
	'targets' => ['extensions' => ['10!00'], 'desktop_clients' => ['Bad User']],
	'delivery' => ['audio_mode' => 'none'],
	'occurrences' => [],
]], []);
if (stripos(implode(' ', $scheduledSelectorErrors), 'invalid or duplicate extension') === false
	|| stripos(implode(' ', $scheduledSelectorErrors), 'invalid or duplicate saved username') === false) {
	configuration_security_fail('Saved schedules silently drop malformed recipient selectors.');
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
	'mode' => 'tts',
	'zone_scope' => 'selected',
	'zone_ids' => ['primary'],
]))) {
	configuration_security_fail('A well-typed selected-zone Weather test was rejected.');
}
if (stripos(implode(' ', $call('validateControlApiNwsTestPayload', [
	'mode' => 'arbitrary',
	'zone_scope' => 'all',
])), 'mode must be') === false) {
	configuration_security_fail('Control API Weather testing accepted an unsupported delivery mode.');
}

$unknownPatch = $call('validateAndNormalizeControlConfigPatch', ['not_a_setting' => true]);
if (stripos(implode(' ', $unknownPatch['errors'] ?? []), 'Unknown Control API config field') === false) {
	configuration_security_fail('An unknown Control API config field was silently ignored.');
}
$badBooleanPatch = $call('validateAndNormalizeControlConfigPatch', ['enabled' => 'false']);
if (stripos(implode(' ', $badBooleanPatch['errors'] ?? []), 'JSON boolean') === false) {
	configuration_security_fail('A string Control API config boolean was accepted.');
}
$legacyKeyPatch = $call('validateAndNormalizeControlConfigPatch', ['control_api' => ['api_key' => str_repeat('A', 32)]]);
if (stripos(implode(' ', $legacyKeyPatch['errors'] ?? []), 'cannot be changed') === false
	|| array_key_exists('api_key', $legacyKeyPatch['patch']['control_api'] ?? [])) {
	configuration_security_fail('A named Control API credential can still replace the legacy API key.');
}
$redactedLegacyKeyPatch = $call('validateAndNormalizeControlConfigPatch', ['control_api' => ['api_key' => '[redacted]']]);
if (!empty($redactedLegacyKeyPatch['errors']) || array_key_exists('api_key', $redactedLegacyKeyPatch['patch']['control_api'] ?? [])) {
	configuration_security_fail('A redacted Control API configuration round trip no longer preserves the legacy key safely.');
}
$validPatch = $call('validateAndNormalizeControlConfigPatch', [
	'enabled' => false,
	'nws_api_base_url' => 'https://api.weather.gov',
	'control_api' => ['rate_limit_enabled' => true, 'rate_limit_per_minute' => 30, 'audit_syslog' => true],
]);
if (!empty($validPatch['errors'])
	|| ($validPatch['patch']['enabled'] ?? null) !== '0'
	|| ($validPatch['patch']['control_api']['rate_limit_enabled'] ?? null) !== '1'
	|| ($validPatch['patch']['control_api']['audit_syslog'] ?? null) !== '1') {
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
$diagnostic = $call('sanitizeTestCommandOutput', [
	'password=alpha secret:bravo token = charlie api-key=delta https://discord.com/api/webhooks/12345/private-token',
]);
foreach (['password=[redacted]', 'secret=[redacted]', 'token=[redacted]', 'api-key=[redacted]', '[redacted Discord webhook]'] as $expected) {
	if (strpos($diagnostic, $expected) === false) {
		configuration_security_fail('Command diagnostic redaction lost a field label or webhook marker: ' . $expected);
	}
}
foreach (['alpha', 'bravo', 'charlie', 'delta', 'private-token'] as $secret) {
	if (strpos($diagnostic, $secret) !== false) {
		configuration_security_fail('Command diagnostic redaction exposed a secret value.');
	}
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
		'site_id' => 'loc_' . str_repeat('b', 24),
		'enabled' => true,
		'adaptive_nws_zone_id' => 'weather_primary',
		'location' => 'North Campus',
		'radius_miles' => 10,
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => ['north@example.com'],
		'voice_recipient_ids' => ['voice_' . str_repeat('c', 24)],
		'sms_recipient_ids' => ['sms_' . str_repeat('d', 24)],
		'all_clear' => 'none',
	]]],
]);
if (!empty($validLightningPatch['errors']) || ($validLightningPatch['patch']['xweather']['groups'][0]['enabled'] ?? null) !== '1') {
	configuration_security_fail('A valid Control API Lightning group patch was not normalized safely.');
}
foreach ([
	['nws_zones' => [['site_id' => true]]],
	['xweather' => ['groups' => [['site_id' => true]]]],
	['xweather' => ['groups' => [['voice_recipient_ids' => 'voice_' . str_repeat('c', 24)]]]],
	['xweather' => ['groups' => [['sms_recipient_ids' => 'sms_' . str_repeat('d', 24)]]]],
] as $malformedGroupPatch) {
	if (empty($call('validateAndNormalizeControlConfigPatch', $malformedGroupPatch)['errors'])) {
		configuration_security_fail('The Control API accepted a malformed Weather or Lightning group field type.');
	}
}
$badWeatherSelectors = $call('validateNwsZoneGroupsInput', [[
	'id' => 'primary', 'name' => 'Primary', 'zone' => 'TXC491',
	'extensions' => ['10!00'], 'desktop_clients' => ['Bad User'], 'email_recipients' => [],
]]);
if (stripos(implode(' ', $badWeatherSelectors), 'invalid or duplicate extension') === false
	|| stripos(implode(' ', $badWeatherSelectors), 'invalid or duplicate saved username') === false) {
	configuration_security_fail('Weather zones silently canonicalize malformed recipient selectors.');
}
$badLightningSelectors = $call('validateXweatherGroupsInput', [[
	'id' => 'north', 'name' => 'North', 'enabled' => '1', 'location' => 'North',
	'extensions' => ['10!00'], 'desktop_clients' => ['Bad User'], 'email_recipients' => [],
]]);
if (stripos(implode(' ', $badLightningSelectors), 'invalid or duplicate extension') === false
	|| stripos(implode(' ', $badLightningSelectors), 'invalid or duplicate saved username') === false) {
	configuration_security_fail('Lightning groups silently canonicalize malformed recipient selectors.');
}
foreach (['id', 'name', 'site_id', 'adaptive_nws_zone_id', 'location', 'all_clear'] as $field) {
	$invalidTextGroup = [
		'id' => 'north', 'name' => 'North', 'enabled' => '1', 'location' => 'North',
		'radius_miles' => 10, 'extensions' => ['1000'], 'desktop_clients' => [], 'email_recipients' => [],
	];
	$invalidTextGroup[$field] = true;
	$invalidTextErrors = $call('validateXweatherGroupsInput', [$invalidTextGroup]);
	if (stripos(implode(' ', $invalidTextErrors), str_replace('_', ' ', $field) . ' must be text') === false) {
		configuration_security_fail('A non-text Lightning group field was silently coerced: ' . $field);
	}
}
$malformedWeatherFields = [
	'zone' => ['nested'],
	'quiet_hours_enabled' => 'true',
	'quiet_hours_start' => ['nested'],
	'quiet_critical_events' => [['nested']],
	'email_recipients' => [['nested']],
];
foreach ($malformedWeatherFields as $field => $value) {
	$group = [
		'id' => 'primary', 'name' => 'Primary', 'zone' => 'TXC491',
		'extensions' => ['1000'], 'desktop_clients' => [], 'email_recipients' => [],
	];
	$group[$field] = $value;
	if (!$call('validateNwsZoneGroupsInput', [$group])) {
		configuration_security_fail('A malformed Weather-zone field was accepted: ' . $field);
	}
}
foreach (['name' => "Bad\x01Name", 'location' => str_repeat('x', 121), 'quiet_hours_start' => ['nested'],
	'quiet_hours_enabled' => true, 'email_recipients' => [['nested']]] as $field => $value) {
	$group = [
		'id' => 'north', 'name' => 'North', 'enabled' => '1', 'location' => 'North',
		'radius_miles' => 10, 'extensions' => ['1000'], 'desktop_clients' => [], 'email_recipients' => [],
	];
	$group[$field] = $value;
	if (!$call('validateXweatherGroupsInput', [$group])) {
		configuration_security_fail('A malformed Lightning-group field was accepted: ' . $field);
	}
}

$invalidKeySettings = $call('getDefaultSettings');
$invalidKeySettings['control_api']['api_key'] = 'invalid key';
$rejected = false;
try { $call('normalizeSettings', $invalidKeySettings); }
catch (DomainException $error) { $rejected = stripos($error->getMessage(), 'not replaced automatically') !== false; }
if (!$rejected) {
	configuration_security_fail('An invalid saved Control API key was silently rotated.');
}
$emptyKeySettings = $call('getDefaultSettings');
$emptyKeySettings['control_api']['api_key'] = '';
$generatedKeySettings = $call('normalizeSettings', $emptyKeySettings);
if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/D', $generatedKeySettings['control_api']['api_key'] ?? '')) {
	configuration_security_fail('A missing first-install Control API key was not generated safely.');
}
foreach ([
	['control_api', static function (array &$settings): void { $settings['control_api']['enabled'] = 'false'; }],
	['webhook', static function (array &$settings): void { $settings['generic_webhooks'] = [[
		'id'=>'hook_fixture', 'name'=>'Fixture', 'url'=>'https://example.com/hook', 'enabled'=>'false',
	]]; }],
	['lightning group', static function (array &$settings): void { $settings['xweather']['groups'][0]['enabled'] = 'false'; }],
] as [$label, $mutate]) {
	$flagSettings = $call('getDefaultSettings');
	$mutate($flagSettings);
	$rejected = false;
	try { $call('normalizeSettings', $flagSettings); }
	catch (DomainException|InvalidArgumentException $error) { $rejected = true; }
	if (!$rejected) { configuration_security_fail("A noncanonical {$label} flag enabled or disabled a feature silently."); }
}
$badWebhookSettings = $call('getDefaultSettings');
$badWebhookSettings['generic_webhooks'] = [[
	'id'=>'bad id', 'name'=>"Invalid \xff", 'url'=>'https://example.com/hook', 'enabled'=>'1',
]];
$rejected = false;
try { $call('normalizeSettings', $badWebhookSettings); }
catch (InvalidArgumentException $error) { $rejected = true; }
if (!$rejected) {
	configuration_security_fail('Invalid webhook identity or UTF-8 was silently rewritten.');
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
if (strpos($classSource, "'/bin/systemctl',") !== false
	|| strpos($classSource, "runCommand('/bin/systemctl") !== false
	|| strpos($classSource, "runCommand('/usr/bin/systemctl reload apache2')") === false) {
	configuration_security_fail('Runtime service management does not consistently use the required canonical systemctl path.');
}
foreach ([
	"array_key_exists('apply', \$payload) && !is_bool(\$payload['apply'])",
	"(\$payload['apply'] ?? false) === true",
] as $marker) {
	if (strpos($classSource, $marker) === false) {
		configuration_security_fail("Strict Control API apply marker is missing: {$marker}");
	}
}
$controlUpdateSource = explode("\n\tprivate function validateControlApiAnnouncementPayload", explode("\tpublic function controlApiUpdateConfig", $classSource, 2)[1] ?? '', 2)[0] ?? '';
if (strpos($controlUpdateSource, "'errors' => [\$e->getMessage()]") !== false
	|| strpos($controlUpdateSource, "'errors' => [\$message]") === false) {
	configuration_security_fail('Control API configuration failures can disclose an unexpected internal exception message.');
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
$lightningClaimSource = explode("\n\tprivate function setAnnouncementCooldown", explode("\tprivate function claimLightningTestCooldown", $classSource, 2)[1] ?? '', 2)[0] ?? '';
if (strpos($lightningClaimSource, 'acquireNativeBackupFileLock(') === false
	|| strpos($lightningClaimSource, 'releaseNativeBackupFileLock($handle)') === false
	|| strpos($classSource, '@flock($handle, $lockMode | LOCK_NB)') === false) {
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
$protectedSource = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_privileged_install.py');
$cleanupPosition = strpos($protectedSource, 'self.files.cleanup_legacy_links()');
$permissionPosition = strpos($protectedSource, 'self.files.secure_tree(DATA)');
if ($cleanupPosition === false || $permissionPosition === false || $cleanupPosition > $permissionPosition) {
	configuration_security_fail('Legacy runtime links are not cleaned before the fail-closed permission scan.');
}

echo "Configuration and Control API security contract tests passed.\n";
