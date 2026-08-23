<?php

declare(strict_types=1);

if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables = []): string { return ''; } }
if (!function_exists('_')) { function _($value) { return $value; } }

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

final class XweatherGroupFixture extends \FreePBX\modules\Slsmassnotifyserver
{
	public function getConfiguredPjsipExtensionNumbers()
	{
		return ['1000', '1001'];
	}

	public function getAllPjsipExtensions()
	{
		return [
			['extension' => '1000', 'name' => 'Test phone', 'registered' => true],
			['extension' => '1001', 'name' => 'Second phone', 'registered' => true],
		];
	}
}

function xweather_group_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = (new ReflectionClass(XweatherGroupFixture::class))->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeXweatherSettings');
$normalize->setAccessible(true);
$validate = $reflection->getMethod('validateXweatherGroupsInput');
$validate->setAccessible(true);

$legacy = $normalize->invoke($module, [
	'enabled' => '1',
	'location' => 'Round Rock, TX',
	'radius_miles' => 6,
	'adaptive_nws_zone_id' => 'nws_primary',
	'recipients' => ['1000'],
], 25);
if (count($legacy['groups'] ?? []) !== 1
	|| ($legacy['groups'][0]['id'] ?? '') !== 'lightning_primary'
	|| ($legacy['groups'][0]['extensions'] ?? []) !== ['1000']
	|| ($legacy['location'] ?? '') !== 'Round Rock, TX') {
	xweather_group_fail('Legacy singleton Lightning config did not migrate into one compatible trigger area.');
}

$groups = [];
for ($index = 0; $index < 6; $index++) {
	$groups[] = [
		'id' => 'area_' . $index,
		'name' => 'Area ' . $index,
		'enabled' => '1',
		'adaptive_nws_zone_id' => 'nws_' . $index,
		'location' => 'Location ' . $index,
		'radius_miles' => 10,
		'extensions' => ['1000'],
		'desktop_clients' => [],
		'email_recipients' => [],
		'all_clear' => 'none',
	];
}
$normalized = $normalize->invoke($module, ['enabled' => '1', 'groups' => $groups], 25);
if (count($normalized['groups'] ?? []) !== 5) {
	xweather_group_fail('Lightning trigger-area normalization did not enforce the five-area limit.');
}
$errors = $validate->invoke($module, $groups);
if (!array_filter($errors, static function ($message) { return stripos((string)$message, 'limited to five') !== false; })) {
	xweather_group_fail('Lightning trigger-area validation did not reject a sixth area.');
}

$view = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/lightning.php');
foreach (['xweather[groups][', 'data-lightning-desktop', 'Each enabled area uses its own Xweather query while active', 'groups_present'] as $marker) {
	if (strpos($view, $marker) === false) {
		xweather_group_fail('Lightning trigger-area UI contract is missing: ' . $marker);
	}
}
if (strpos($view, 'Storm-mode planning estimate') !== false || strpos($view, 'sls-projection') !== false) {
	xweather_group_fail('The removed Lightning storm-mode planning calculator remains in the UI.');
}
if (strpos($view, 'name="xweather[recipients][]"') !== false || strpos($view, '--desktop-all') !== false) {
	xweather_group_fail('Legacy global Lightning recipient routing remains in the group UI.');
}

foreach (['settings.php', 'setup.php', 'help.php'] as $weatherViewName) {
	$weatherView = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/' . $weatherViewName);
	if (strpos($weatherView, 'https://www.weather.gov/pimar/PubZone') === false
		|| strpos($weatherView, 'TXZ163') === false
		|| strpos($weatherView, 'Open official NWS zone maps') === false) {
		xweather_group_fail('Simple official Weather.gov zone guidance is missing from ' . $weatherViewName . '.');
	}
	if (strpos($weatherView, 'weather.gov/documentation/services-web-api') !== false
		|| strpos($weatherView, 'weather.gov/gis/publiczones') !== false) {
		xweather_group_fail('Weather.gov zone guidance still presents multiple competing links in ' . $weatherViewName . '.');
	}
}

$settings = [
	'nws_tts_volume' => 25,
	'nws_zones' => [[
		'id' => 'nws_primary',
		'name' => 'Primary Weather Area',
		'zone' => 'TXC491',
	]],
	'xweather' => [
		'enabled' => '1',
		'client_id' => 'fixture-id',
		'client_secret' => 'fixture-secret',
		'adaptive_free_tier' => '1',
		'query_interval_minutes' => 5,
		'adaptive_grace_minutes' => 60,
		'tts_volume' => 25,
		'opening_tone' => 'opening_Lightning_alert',
		'closing_tone' => '',
		'quiet_hours_enabled' => '0',
		'groups' => [[
			'id' => 'north',
			'name' => 'North Campus',
			'enabled' => '1',
			'adaptive_nws_zone_id' => 'nws_primary',
			'location' => 'North Campus',
			'radius_miles' => 10,
			'extensions' => ['1000'],
			'desktop_clients' => ['north-desk'],
			'email_recipients' => ['north@example.com'],
			'all_clear' => 'send',
		]],
	],
];
$active_settings = $settings;
$available_extensions = [['extension' => '1000', 'name' => 'Test phone', 'registered' => true]];
$available_desktop_clients = [['username' => 'north-desk', 'name' => 'North Desk', 'client_id' => 'desktop-1', 'enabled' => '1']];
$available_tones = ['opening_Lightning_alert'];
$available_system_sounds = [];
$csrf_token = 'fixture-csrf';
$cooldown_remaining = 0;
$api_usage = [
	'limit' => 15000,
	'used' => 916,
	'remaining' => 14084,
	'period_state' => 'expired',
	'snapshot_current' => false,
	'reset_at_formatted' => 'Aug 17, 2026',
	'observed_at_formatted' => 'Aug 12, 2026',
	'interval_minutes' => 5,
	'max_queries_per_day' => 288,
	'last_query_cost_tokens' => 10,
	'estimated_tokens_per_day' => 2880,
	'estimated_tokens_per_30_days' => 86400,
];
$hero_image = '';
$previousHandler = set_error_handler(static function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
	ob_start();
	include dirname(__DIR__) . '/slsmassnotifyserver/views/lightning.php';
	$rendered = (string)ob_get_clean();
} catch (Throwable $error) {
	if (ob_get_level() > 0) {
		ob_end_clean();
	}
	xweather_group_fail('Lightning trigger-area view could not render: ' . $error->getMessage());
} finally {
	restore_error_handler();
}
foreach (['North Campus', 'Previous provider period', 'Historical tokens used', 'Manage Lightning Trigger Areas', 'north-desk'] as $marker) {
	if (strpos($rendered, $marker) === false) {
		xweather_group_fail('Rendered Lightning trigger-area UI is missing: ' . $marker);
	}
}
if (strpos($rendered, 'Current account-period usage') !== false) {
	xweather_group_fail('An expired provider snapshot was rendered as current usage.');
}

echo "Lightning trigger-area configuration regressions passed.\n";
