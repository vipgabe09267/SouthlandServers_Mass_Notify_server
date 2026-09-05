<?php
declare(strict_types=1);
$path = getenv('SLS_DESKTOP_API_SOURCE') ?: dirname(__DIR__) . '/slsmassnotifyserver/api/sipnotify/index.php';
$source = file_get_contents($path);
$start = strpos($source, 'function announcement_display_expired');
$end = strpos($source, "\n\n\$endpoint =", $start);
eval(substr($source, $start, $end - $start));
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$baseline = desktop_stream_events_after_cursor([], 'alice', '');
$event = ['id' => 'first', 'desktop_recipients' => ['alice'], 'kind' => 'announcement'];
$batch = desktop_stream_events_after_cursor([$event], 'alice', $baseline['last_event_id']);
check(count($batch['events']) === 1, 'First targeted event was skipped');
$batch = desktop_stream_events_after_cursor([$event], 'alice', 'rotated-id');
check(count($batch['events']) === 1 && $batch['cursor_gap'], 'Missing history must replay authorized records and signal the gap');
check(desktop_stream_events_after_cursor([$event], 'bob', '@sls:empty')['events'] === [], 'Other client received event');
check(desktop_stream_events_after_cursor([$event], 'alice', 'first')['events'] === [], 'Already emitted event replayed');
check(strpos($source, 'if (!desktop_auth_attempt_allowed(') < strpos($source, '$client = authorized_desktop_client('), 'Throttle must precede credentials');
check(strpos($source, 'credentials_revoked') !== false, 'Open streams must support revocation');
echo "Desktop first-event, cursor-gap, targeting and auth-order checks passed.\n";
