<?php
declare(strict_types=1);
$path = getenv('SLS_DESKTOP_API_SOURCE') ?: dirname(__DIR__) . '/slsmassnotifyserver/api/sipnotify/index.php';
$source = file_get_contents($path);
if (!is_string($source)) { throw new RuntimeException('Desktop API source is unreadable'); }
$identityStart = strpos($source, 'function desktop_timestamp');
$identityEnd = $identityStart === false ? false : strpos($source, "\n\nfunction desktop_private_directory_open", $identityStart);
if ($identityStart === false || $identityEnd === false) { throw new RuntimeException('Desktop identity helpers are missing'); }
eval(substr($source, $identityStart, $identityEnd - $identityStart));
if (!defined('MAX_EVENT_ID_BYTES')) { define('MAX_EVENT_ID_BYTES', 2048); }
$start = strpos($source, 'function announcement_display_expired');
if ($start === false) { throw new RuntimeException('Desktop helper start marker is missing'); }
$end = strpos($source, "\n\n\$endpoint =", $start);
if ($end === false || $end <= $start) { throw new RuntimeException('Desktop helper end marker is missing'); }
$snippet = substr($source, $start, $end - $start);
if ($snippet === '') { throw new RuntimeException('Desktop helper extraction is empty'); }
eval($snippet);
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$baseline = desktop_stream_events_after_cursor([], 'alice', '');
$event = ['id' => 'first', 'desktop_recipients' => ['alice'], 'kind' => 'announcement'];
$batch = desktop_stream_events_after_cursor([$event], 'alice', $baseline['last_event_id']);
check(count($batch['events']) === 1, 'First targeted event was skipped');
$batch = desktop_stream_events_after_cursor([$event], 'alice', 'rotated-id');
check($batch['events'] === [] && $batch['cursor_gap'] && $batch['last_event_id'] === 'first', 'Missing history must re-baseline without replaying missed records');
check(desktop_stream_events_after_cursor([$event], 'bob', '@sls:empty')['events'] === [], 'Other client received event');
check(desktop_stream_events_after_cursor([$event], 'alice', 'first')['events'] === [], 'Already emitted event replayed');
$credentialPosition = strpos($source, '$client = authorized_desktop_client(');
$attemptPosition = strpos($source, 'if (!desktop_auth_attempt_allowed(');
$failureBudgetPosition = strpos($source, 'if (!desktop_auth_failure_budget_available(');
check($credentialPosition !== false && $attemptPosition !== false && $credentialPosition < $attemptPosition, 'Valid credentials must be separated from failed authentication budgets');
check($failureBudgetPosition !== false && $credentialPosition !== false && $failureBudgetPosition < $credentialPosition, 'Exhausted source failure budgets must block password guessing before credential verification');
check(strpos($source, 'credentials_revoked') !== false, 'Open streams must support revocation');
echo "Desktop first-event, cursor-gap, targeting and authentication-budget checks passed.\n";
