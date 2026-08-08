<?php
declare(strict_types=1);

$apiPath = dirname(__DIR__) . '/slsmassnotifyserver/api/sipnotify/index.php';
$source = file_get_contents($apiPath);
if (!is_string($source)) {
	fwrite(STDERR, "Unable to read desktop API source.\n");
	exit(1);
}

$start = strpos($source, 'function announcement_display_expired');
$end = $start === false ? false : strpos($source, "\n\n\$endpoint =", $start);
if ($start === false || $end === false) {
	fwrite(STDERR, "Unable to locate announcement expiry helper.\n");
	exit(1);
}
eval(substr($source, $start, $end - $start));

function assert_same($expected, $actual, string $message): void
{
	if ($expected !== $actual) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$now = strtotime('2026-07-31T18:00:00Z');
assert_same(false, announcement_display_expired([
	'kind' => 'announcement',
	'display_timeout_seconds' => 0,
	'display_expires_at' => '2026-07-31T17:59:00Z',
], $now), 'No-expiry announcement was incorrectly filtered.');
assert_same(false, announcement_display_expired([
	'kind' => 'announcement',
	'display_timeout_seconds' => 60,
	'display_expires_at' => '2026-07-31T18:01:00Z',
], $now), 'Future announcement was incorrectly filtered.');
assert_same(true, announcement_display_expired([
	'kind' => 'announcement',
	'display_timeout_seconds' => 60,
	'display_expires_at' => '2026-07-31T18:00:00Z',
], $now), 'Expired announcement was not filtered.');
assert_same(false, announcement_display_expired([
	'kind' => 'alert',
	'display_timeout_seconds' => 60,
	'display_expires_at' => '2026-07-31T17:59:00Z',
	'expires' => '2026-07-31T17:59:00Z',
], $now), 'Weather alert was incorrectly filtered by announcement expiry.');
assert_same(false, announcement_display_expired([
	'kind' => 'announcement',
	'display_timeout_seconds' => 60,
	'display_expires_at' => 'not-a-time',
], $now), 'Malformed expiry was not handled safely.');

$streamEvents = [
	[
		'id' => 'expired-cursor',
		'kind' => 'announcement',
		'desktop_all' => true,
		'display_timeout_seconds' => 60,
		'display_expires_at' => '2026-07-31T17:59:00Z',
	],
	[
		'id' => 'next-visible',
		'kind' => 'announcement',
		'desktop_recipients' => ['desktop-a'],
		'display_timeout_seconds' => 60,
		'display_expires_at' => '2026-07-31T18:01:00Z',
	],
	[
		'id' => 'other-desktop',
		'kind' => 'announcement',
		'desktop_recipients' => ['desktop-b'],
		'display_timeout_seconds' => 0,
	],
];
$batch = desktop_stream_events_after_cursor($streamEvents, 'desktop-a', 'expired-cursor', $now);
assert_same('next-visible', $batch['last_event_id'], 'Expired SSE cursor did not advance to the next routed event.');
assert_same(1, count($batch['events']), 'Next visible SSE event was skipped after its cursor event expired.');
assert_same('next-visible', $batch['events'][0][0], 'Wrong SSE event followed the expired cursor.');

$streamEvents[] = [
	'id' => 'expired-followup',
	'kind' => 'announcement',
	'desktop_all' => true,
	'display_timeout_seconds' => 60,
	'display_expires_at' => '2026-07-31T17:59:30Z',
];
$expiredBatch = desktop_stream_events_after_cursor($streamEvents, 'desktop-a', 'next-visible', $now);
assert_same('expired-followup', $expiredBatch['last_event_id'], 'SSE cursor did not advance across an expired routed event.');
assert_same([], $expiredBatch['events'], 'Expired SSE event was emitted to the desktop.');

echo "Desktop announcement expiry regressions passed.\n";
