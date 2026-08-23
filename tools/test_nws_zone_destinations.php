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

final class NwsZoneDestinationFixture extends \FreePBX\modules\Slsmassnotifyserver
{
	public function getConfiguredPjsipExtensionNumbers()
	{
		return ['1000'];
	}

	public function getAllPjsipExtensions()
	{
		return [
			['extension' => '1000', 'name' => 'Test phone', 'registered' => true],
		];
	}
}

function zone_destination_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$parent = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$fixture = (new ReflectionClass(NwsZoneDestinationFixture::class))->newInstanceWithoutConstructor();
$call = static function (string $method, ...$arguments) use ($parent, $fixture) {
	$target = $parent->getMethod($method);
	$target->setAccessible(true);
	return $target->invoke($fixture, ...$arguments);
};

$groups = $call('normalizeNwsZoneGroups', [[
	'id' => 'central',
	'name' => 'Central Operations',
	'zone' => 'txc491',
	'extensions' => ['1000', '9999'],
	'desktop_clients' => ['Desk.One', 'desk.one', '../desk-two'],
	'email_recipients' => ['Weather@example.com', 'weather@example.com', 'invalid'],
]]);
if (count($groups) !== 1
	|| ($groups[0]['zone'] ?? '') !== 'TXC491'
	|| ($groups[0]['extensions'] ?? []) !== ['1000']
	|| ($groups[0]['desktop_clients'] ?? []) !== ['desk.one', '..desk-two']
	|| ($groups[0]['email_recipients'] ?? []) !== ['weather@example.com']) {
	zone_destination_fail('Weather-zone destination normalization failed: ' . json_encode($groups));
}

$valid = $call('validateNwsZoneGroupsInput', [[
	'name' => 'Desktop only',
	'zone' => 'TXC491',
	'extensions' => [],
	'desktop_clients' => ['desk.one'],
	'email_recipients' => ['weather@example.com'],
]]);
if (!empty($valid)) {
	zone_destination_fail('A desktop-only Weather zone was rejected: ' . implode(' ', $valid));
}

$invalid = $call('validateNwsZoneGroupsInput', [[
	'name' => 'No direct target',
	'zone' => 'TXC491',
	'extensions' => [],
	'desktop_clients' => [],
	'email_recipients' => ['only-email@example.com'],
]]);
if (stripos(implode(' ', $invalid), 'extension or desktop') === false) {
	zone_destination_fail('An email-only Weather zone was not rejected visibly.');
}

$invalidEmail = $call('validateNwsZoneGroupsInput', [[
	'name' => 'Bad email',
	'zone' => 'TXC491',
	'extensions' => ['1000'],
	'desktop_clients' => [],
	'email_recipients' => ['not-an-address'],
]]);
if (stripos(implode(' ', $invalidEmail), 'valid address') === false) {
	zone_destination_fail('An invalid zone-specific email address was not rejected.');
}

$duplicateIdInput = [
	[
		'id' => 'duplicate_zone',
		'name' => 'First',
		'zone' => 'TXC491',
		'extensions' => ['1000'],
		'desktop_clients' => [],
	],
	[
		'id' => 'duplicate_zone',
		'name' => 'Second',
		'zone' => 'TXC493',
		'extensions' => ['1000'],
		'desktop_clients' => [],
	],
];
$duplicateIdErrors = $call('validateNwsZoneGroupsInput', $duplicateIdInput);
if (stripos(implode(' ', $duplicateIdErrors), 'unique ID') === false) {
	zone_destination_fail('Duplicate Weather zone IDs were not rejected.');
}
$deduplicatedGroups = $call('normalizeNwsZoneGroups', $duplicateIdInput);
if (($deduplicatedGroups[0]['id'] ?? '') !== 'duplicate_zone'
	|| ($deduplicatedGroups[1]['id'] ?? '') !== 'duplicate_zone_2') {
	zone_destination_fail('Legacy duplicate Weather zone IDs were not migrated to unique runtime IDs.');
}

$globalEmailRecipients = [];
for ($index = 0; $index < 49; $index++) {
	$globalEmailRecipients[] = 'global' . $index . '@example.com';
}
$capacityErrors = $call('validateNwsZoneEmailCapacity', [[
	'name' => 'Service-specific recipients',
	'zone' => 'TXC491',
	'extensions' => ['1000'],
	'desktop_clients' => [],
	'email_recipients' => ['zone-one@example.com', 'zone-two@example.com'],
]], implode(' ', $globalEmailRecipients));
if (!empty($capacityErrors)) {
	zone_destination_fail('System/error recipients were incorrectly counted against the Weather-zone limit.');
}
$oversizedZoneRecipients = [];
for ($index = 0; $index < 51; $index++) {
	$oversizedZoneRecipients[] = 'zone' . $index . '@example.com';
}
$capacityErrors = $call('validateNwsZoneEmailCapacity', [[
	'name' => 'Too many zone recipients',
	'zone' => 'TXC491',
	'extensions' => ['1000'],
	'desktop_clients' => [],
	'email_recipients' => $oversizedZoneRecipients,
]], 'system@example.com');
if (stripos(implode(' ', $capacityErrors), '50-recipient') === false) {
	zone_destination_fail('The per-zone 50-recipient limit was not enforced visibly.');
}

$migratedGroups = $call('migrateNwsZoneDesktopUsernames', [[
	'name' => 'Rename-safe routing',
	'zone' => 'TXC491',
	'extensions' => [],
	'desktop_clients' => ['old.name', 'unchanged', 'new.name'],
]], ['old.name' => 'new.name']);
if (($migratedGroups[0]['desktop_clients'] ?? []) !== ['new.name', 'unchanged']) {
	zone_destination_fail('A stable desktop-record username change did not migrate/deduplicate Weather zone routing.');
}

$view = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/settings.php');
foreach (['data-zone-desktop', 'data-zone-email', '[desktop_clients][]', '[email_recipients]'] as $marker) {
	if (strpos($view, $marker) === false) {
		zone_destination_fail("Weather-zone destination UI marker is missing: {$marker}");
	}
}
foreach (['disabled — uncheck to remove this assignment', 'missing — uncheck to remove'] as $marker) {
	if (strpos($view, $marker) === false) {
		zone_destination_fail("Disabled or missing desktop unassignment UI marker is missing: {$marker}");
	}
}

$worker = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh');
foreach (['NWS_DESKTOP_CLIENTS_OVERRIDE', 'NWS_EMAIL_RECIPIENTS_OVERRIDE'] as $marker) {
	if (strpos($worker, $marker) === false) {
		zone_destination_fail("Weather worker routing marker is missing: {$marker}");
	}
}

$coreWorker = (string)file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh');
foreach ([
	'if [ "${NWS_RECIPIENTS_OVERRIDE+x}" = "x" ]; then',
	'if [ "${NWS_DESKTOP_CLIENTS_OVERRIDE+x}" = "x" ]; then',
	'if [ "${NWS_EMAIL_RECIPIENTS_OVERRIDE+x}" = "x" ]; then',
] as $marker) {
	if (strpos($coreWorker, $marker) === false) {
		zone_destination_fail("Explicit-empty zone override reset is missing: {$marker}");
	}
}

echo "NWS zone destination PHP contract tests passed.\n";
