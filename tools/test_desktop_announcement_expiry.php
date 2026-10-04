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

assert_same(true, desktop_event_display_expired([
	'kind' => 'alert', 'expires' => '2026-07-31T17:59:00Z',
], $now), 'Expired weather alert was not filtered.');
assert_same(false, desktop_event_display_expired([
	'kind' => 'alert', 'expires' => '2026-07-31T18:01:00Z',
], $now), 'Unexpired weather alert was incorrectly filtered.');
assert_same(true, desktop_event_display_expired([
	'kind' => 'alert', 'message_type' => 'Cancel', 'expires' => '2026-07-31T18:01:00Z',
], $now), 'Cancellation record was presented as an active weather alert.');

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

$weatherEvents = [
	['id' => 'old-weather', 'kind' => 'alert', 'chain_key' => 'storm-1', 'desktop_all' => true, 'expires' => '2026-07-31T18:05:00Z'],
	['id' => 'new-weather', 'kind' => 'alert', 'chain_key' => 'storm-1', 'desktop_all' => true, 'expires' => '2026-07-31T18:10:00Z'],
	['id' => 'expired-weather', 'kind' => 'alert', 'desktop_all' => true, 'expires' => '2026-07-31T17:59:00Z'],
];
$weatherBatch = desktop_stream_events_after_cursor($weatherEvents, 'desktop-a', 'missing-cursor', $now);
assert_same(['new-weather'], array_column($weatherBatch['events'], 0), 'Weather replay included expired or superseded instructions.');
assert_same('expired-weather', $weatherBatch['last_event_id'], 'Weather expiry did not advance the cursor.');
$weatherEvents[] = ['id' => 'cancel-weather', 'kind' => 'alert', 'chain_key' => 'storm-1', 'message_type' => 'Cancel', 'desktop_all' => true];
$cancelledBatch = desktop_stream_events_after_cursor($weatherEvents, 'desktop-a', '@sls:empty', $now);
assert_same([], $cancelledBatch['events'], 'Cancelled weather chain replayed an earlier alert.');
assert_same('cancel-weather', $cancelledBatch['last_event_id'], 'Cancellation did not advance the cursor.');

// A newer record routed only to another client must not reveal or suppress the
// record this client is authorized to read.
$weatherEvents[1]['desktop_all'] = false;
$weatherEvents[1]['desktop_recipients'] = ['desktop-b'];
$isolatedBatch = desktop_stream_events_after_cursor(array_slice($weatherEvents, 0, 2), 'desktop-a', '@sls:empty', $now);
assert_same(['old-weather'], array_column($isolatedBatch['events'], 0), 'Another client\'s update changed this client\'s replay.');

echo "Desktop announcement/weather expiry, cancellation and cursor regressions passed.\n";
