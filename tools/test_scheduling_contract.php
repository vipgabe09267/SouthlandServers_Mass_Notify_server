<?php

declare(strict_types=1);

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!function_exists('load_view')) {
	function load_view($path, array $variables = []): string
	{
		return '<div data-test-hero="1"></div>';
	}
}

function scheduling_contract_fail(string $message): void
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$resolve = $reflection->getMethod('resolveScheduleLocalDateTime');
$resolve->setAccessible(true);
$timezone = new DateTimeZone('America/Chicago');

$normal = $resolve->invoke($module, '2027-01-15T09:30', $timezone);
if (empty($normal['success']) || ($normal['local_datetime'] ?? '') !== '2027-01-15T09:30') {
	scheduling_contract_fail('Normal scheduling time was not resolved.');
}

$missing = $resolve->invoke($module, '2027-03-14T02:30', $timezone);
if (!empty($missing['success']) || stripos((string)($missing['message'] ?? ''), 'does not exist') === false) {
	scheduling_contract_fail('DST spring-forward gap was not rejected.');
}

$ambiguous = $resolve->invoke($module, '2027-11-07T01:30', $timezone);
if (!empty($ambiguous['success']) || stripos((string)($ambiguous['message'] ?? ''), 'occurs twice') === false) {
	scheduling_contract_fail('DST fall-back ambiguity was not rejected.');
}

$parseUtc = $reflection->getMethod('parseScheduleUtcTimestamp');
$parseUtc->setAccessible(true);
if ($parseUtc->invoke($module, '2027-01-15T15:30:00Z') === false) {
	scheduling_contract_fail('Canonical UTC scheduling instant was rejected.');
}
foreach (['2027-01-15T15:30:00+00:00', '2027-02-30T15:30:00Z', 'next Tuesday'] as $invalidUtc) {
	if ($parseUtc->invoke($module, $invalidUtc) !== false) {
		scheduling_contract_fail("Noncanonical UTC scheduling instant was accepted: {$invalidUtc}");
	}
}

$normalize = $reflection->getMethod('normalizeScheduledAnnouncements');
$normalize->setAccessible(true);
$fixtureScheduleDefinition = [
	'id' => 'shared-id',
	'name' => 'Fixture schedule',
	'enabled' => '1',
	'timezone' => 'America/Chicago',
	'message' => 'Fixture message',
	'occurrences' => [
		['id' => 'forged-duplicate', 'run_at_utc' => '2027-01-15T15:30:00Z'],
		['id' => 'forged-duplicate', 'run_at_utc' => '2027-01-16T15:30:00Z'],
	],
	'targets' => ['extensions' => ['1000']],
	'delivery' => ['audio_mode' => 'none'],
];
$normalized = $normalize->invoke($module, [$fixtureScheduleDefinition, array_replace($fixtureScheduleDefinition, ['name' => 'Duplicate identity'])]);
if (count($normalized) !== 1 || count($normalized[0]['occurrences'] ?? []) !== 2) {
	scheduling_contract_fail('Duplicate schedule/occurrence normalization failed.');
}
$occurrenceIds = array_column($normalized[0]['occurrences'], 'id');
if (count(array_unique($occurrenceIds)) !== 2 || in_array('forged-duplicate', $occurrenceIds, true)) {
	scheduling_contract_fail('Occurrence identities were not derived from schedule and UTC instant.');
}
$normalizedAgain = $normalize->invoke($module, [$fixtureScheduleDefinition]);
if ($normalized[0] !== $normalizedAgain[0]) {
	scheduling_contract_fail('Schedule normalization is not deterministic.');
}

$worker = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_schedule_worker.php');
$class = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$view = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/views/scheduling.php');
foreach ([
	'processScheduledAnnouncements' => $worker,
	'\\FreePBX::Slsmassnotifyserver()' => $worker,
	'is_writable($dataDirectory)' => $worker,
	'is_writable($centralConfig)' => $worker,
	'tempnam($dataDirectory' => $worker,
	"['--self-test']" => $worker,
	'SCHEDULE_GRACE_SECONDS = 900' => $class,
	"'delivery_started'" => $class,
	"'error_code'" => $class,
	"['cooldown', 'delivery_busy', 'no_targets', 'no_audio_targets']" => $class,
	'findLiveScheduledOccurrence' => $class,
	'The scheduler could not open its worker lock file.' => $class,
	'loadScheduleExecutionStore(true)' => $class,
	'persistAppliedSettings($normalized, true, true)' => $class,
	'$currentPendingFingerprint = $this->settingsFileFingerprint(self::PENDING_SETTINGS_JSON)' => $class,
	'Another request changed the staged Mass Notifications settings.' => $class,
	'/usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php' => $class,
	'data-schedule-metric="upcoming"' => $view,
	'data-rendered-state=' => $view,
	'Schedule recovery:' => $view,
	'scheduleFormSubmitting' => $view,
	'data-submitting' => $view,
] as $needle => $haystack) {
	if (!is_string($haystack) || strpos($haystack, $needle) === false) {
		scheduling_contract_fail("Missing scheduling contract marker: {$needle}");
	}
}

$render = static function (array $variables): string {
	extract($variables, EXTR_SKIP);
	ob_start();
	try {
		include dirname(__DIR__) . '/slsmassnotifyserver/views/scheduling.php';
		return (string)ob_get_clean();
	} catch (Throwable $exception) {
		ob_end_clean();
		throw $exception;
	}
};

$fixtureOccurrence = [
	'run_at_utc' => '2099-01-15T15:30:00+00:00',
	// Deliberately stale. The editor must derive 09:30 CST from run_at_utc.
	'local_datetime' => '2099-01-15T10:30',
];
$fixtureSchedules = [
	[
		'id' => 'sched_future',
		'name' => 'Enabled future schedule',
		'enabled' => true,
		'occurrences' => [$fixtureOccurrence],
		'targets' => ['phones_all' => true],
		'delivery' => ['audio_mode' => 'tones_tts', 'style' => 'standard'],
		'execution' => ['state' => 'success', 'message' => 'Prior occurrence submitted'],
	],
	[
		'id' => 'sched_disabled',
		'name' => 'Disabled future schedule',
		'enabled' => false,
		'occurrences' => [$fixtureOccurrence],
		'targets' => ['phones_all' => true],
		'delivery' => ['audio_mode' => 'none', 'style' => 'standard'],
		'execution' => ['state' => 'pending'],
	],
];

try {
	$html = $render([
		'scheduled_announcements' => $fixtureSchedules,
		'schedule_execution_state' => [],
		'available_extensions' => [],
		'announcement_groups' => [],
		'desktop_clients' => [],
		'available_voices' => [],
		'available_tones' => [],
		'settings' => [],
		'save_result' => null,
		'csrf_token' => 'fixture-csrf',
		'pbx_timezone' => 'America/Chicago',
		'hero_image' => '',
	]);
} catch (Throwable $exception) {
	scheduling_contract_fail('Scheduling view fixture failed: ' . $exception->getMessage());
}

if (!preg_match('/data-schedule-metric="upcoming"\s+data-value="1"/', $html)) {
	scheduling_contract_fail('Upcoming count must include only enabled future schedules.');
}
if (!preg_match('/data-schedule-id="sched_future"\s+data-rendered-state="pending"/', $html)) {
	scheduling_contract_fail('A future schedule with a prior success must render as Pending.');
}
if (!preg_match('/data-schedule-id="sched_disabled"\s+data-rendered-state="disabled"/', $html)) {
	scheduling_contract_fail('A disabled future schedule must render as Disabled.');
}
if (strpos($html, '"editor_datetime":"2099-01-15T09:30"') === false) {
	scheduling_contract_fail('Schedule editor time was not rebuilt from UTC in the current PBX timezone.');
}

fwrite(STDOUT, "Scheduling contract checks passed.\n");
