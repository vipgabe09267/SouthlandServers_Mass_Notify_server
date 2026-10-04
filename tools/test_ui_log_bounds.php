<?php
declare(strict_types=1);

// No FreePBX construction, production paths, commands, or notification calls.
if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('load_view')) { function load_view($path, array $variables = []): string { return ''; } }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function log_check($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function log_private($object, string $method, array $arguments = []) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $arguments);
}

$root = sys_get_temp_dir() . '/sls-ui-log-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
});
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
// Execute the production reader bodies with only their fixed paths and settings
// source substituted. This avoids even reading the real protected configuration.
$fixture = 'class UiLogFixture { private $uiLogNotices = []; public $retentionDays = 90;'
    . 'const EVENTS_LOG = ' . var_export($root . '/events.jsonl', true) . ';'
    . 'const CONTROL_API_AUDIT_LOG = ' . var_export($root . '/audit.jsonl', true) . ';'
    . 'private function getActiveSettings() { return ["log_retention_days" => $this->retentionDays]; }';
foreach (['DEFAULT_LIMIT', 'MAX_LIMIT', 'UI_LOG_SCAN_BYTES', 'UI_LOG_LINE_BYTES', 'UI_LOG_RESULT_BYTES', 'UI_LOG_SCAN_LINES'] as $name) {
    $fixture .= 'const ' . $name . ' = ' . (int)$reflection->getConstant($name) . ';';
}
foreach (['getEvents', 'getEventById', 'getControlApiAuditSummary', 'readUiLogLines', 'decodeUiLogRecord',
    'admitUiLogResult', 'getUiLogNotices', 'noteUiLogRead', 'normalizeRetentionDays', 'normalizeEvent',
    'classifyNotificationType', 'sanitizeType', 'sanitizeLimit', 'sanitizeLogDate'] as $name) {
    $method = $reflection->getMethod($name);
    $fixture .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
eval($fixture . '}');
unset($fixture, $source);
$module = new UiLogFixture();
$eventPath = UiLogFixture::EVENTS_LOG;
$auditPath = UiLogFixture::CONTROL_API_AUDIT_LOG;
$event = static function (string $id, array $extra = []): string {
    return json_encode($extra + ['event_id' => $id, 'logged_at' => gmdate('c'), 'type' => 'announcement',
        'event' => 'Ordinary event ' . $id, 'body' => 'Full notification message', 'trigger_source' => 'FreePBX Dashboard']) . "\n";
};
$notices = static function (string $channel = 'events') use ($module): string {
    return implode(' ', log_private($module, 'getUiLogNotices', [$channel]));
};

// Latest-first order, row caps, legacy type/date filtering, full details and retention.
$raw = $event('expired', ['logged_at' => gmdate('c', time() - 100 * 86400)]);
for ($i = 0; $i < 30; $i++) { $raw .= $event((string)$i, ['type' => $i % 2 ? 'nws' : 'announcement']); }
file_put_contents($eventPath, $raw);
$before = [hash_file('sha256', $eventPath), filemtime($eventPath), fileinode($eventPath)];
$rows = $module->getEvents(5);
log_check(array_column($rows, 'event_id') === ['29', '28', '27', '26', '25'], 'Newest-first row limit changed');
log_check(count($module->getEvents(500)) === 30, 'Expired entries appeared or normal entries were lost');
log_check(array_column($module->getEvents(3, 'nws', date('Y-m-d')), 'event_id') === ['29', '27', '25'], 'Type/date filters changed');
log_check($module->getEvents(500, '', '2000-01-01') === [], 'Date filtering returned other dates');
log_check($module->getEventById('28')['body'] === 'Full notification message', 'Detail view lost the full message');
log_check($module->getEventById('expired') === null, 'Detail lookup bypassed retention');
log_check($before === [hash_file('sha256', $eventPath), filemtime($eventPath), fileinode($eventPath)], 'Reading mutated the event log');
log_check($notices() === '', 'Normal bounded logs produced a warning');

// Exact backward chunk boundaries, CRLF and a valid final line without newline.
$lines = ['{"one":"' . str_repeat('a', 12000) . '"}', '{"two":"' . str_repeat('b', 17000) . '"}', '{"three":true}'];
file_put_contents($eventPath, implode("\r\n", $lines));
$actual = iterator_to_array(log_private($module, 'readUiLogLines', [$eventPath, 'events']), false);
log_check($actual === array_reverse($lines), 'Backward chunks lost or merged complete records');

// A huge record cannot hide its smaller neighbors or exhaust PHP memory.
file_put_contents($eventPath, $event('before') . '{"body":"' . str_repeat('x', 1024 * 1024) . '"}' . "\n" . $event('after'));
$rows = $module->getEvents(500);
log_check(array_column($rows, 'event_id') === ['after', 'before'], 'Oversized record swallowed neighboring records');
log_check(strpos($notices(), '256 KiB') !== false, 'Oversized record skip was silent');
$exact = '{"body":"' . str_repeat('a', UiLogFixture::UI_LOG_LINE_BYTES - 11) . '"}';
log_check(strlen($exact) === UiLogFixture::UI_LOG_LINE_BYTES, 'Boundary fixture length is wrong');
file_put_contents($eventPath, $exact . "\n");
log_check(count($module->getEvents(500)) === 1, 'Exactly-at-limit record was rejected');

// Invalid JSON, nested display values and unfinished writes are skipped honestly.
file_put_contents($eventPath, $event('valid') . "[]\n{broken\n"
    . $event('bad-title', ['event' => ['unsafe']]) . $event('bad-targets', ['desktop_clients' => [['unsafe']]])
    . $event('bad-id', ['event_id' => ['unsafe']])
    . '{"status":1e999}' . "\n"
    . '{"unfinished":');
log_check(array_column($module->getEvents(500), 'event_id') === ['valid'], 'Malformed record reached display normalization');
log_check(strpos($notices(), 'malformed') !== false, 'Malformed record omission was silent');

// Aggregate result payload is capped even when each individual record is valid.
$handle = fopen($eventPath, 'wb');
for ($i = 0; $i < 20; $i++) { fwrite($handle, $event('large-' . $i, ['body' => str_repeat('z', 200000)])); }
fclose($handle);
$rows = $module->getEvents(500);
log_check(count($rows) > 1 && count($rows) < 20, 'Aggregate response cap was not enforced');
log_check(strlen(json_encode($rows)) <= UiLogFixture::UI_LOG_RESULT_BYTES, 'Event response exceeded its byte cap');
log_check(strpos($notices(), '2 MiB') !== false, 'Response cap was silent');
log_check(strlen($module->getEventById('large-0')['body']) === 200000, 'Bounded details incorrectly truncated valid message');

// A gigabyte sparse log proves scan volume depends on the tail cap, not file size.
$handle = fopen($eventPath, 'wb');
fwrite($handle, $event('outside-window'));
fseek($handle, 1024 * 1024 * 1024, SEEK_SET);
fwrite($handle, "\n" . $event('newest'));
fclose($handle);
$ioBytes = static function (): int {
    preg_match('/^rchar: (\d+)$/m', file_get_contents('/proc/self/io'), $match);
    return (int)($match[1] ?? 0);
};
$beforeIo = $ioBytes();
$beforeMemory = memory_get_usage(true);
$rows = $module->getEvents(500);
$readBytes = $ioBytes() - $beforeIo;
log_check(array_column($rows, 'event_id') === ['newest'], 'Tail window searched old records or lost the newest event');
log_check($readBytes >= UiLogFixture::UI_LOG_SCAN_BYTES && $readBytes < UiLogFixture::UI_LOG_SCAN_BYTES + 4096, 'Reader exceeded its fixed I/O window: ' . $readBytes);
log_check(memory_get_usage(true) - $beforeMemory < 4 * 1024 * 1024, 'Sparse log caused unbounded memory growth');
log_check(strpos($notices(), '16 MiB') !== false, 'Older-date search scope was not disclosed');
log_check($module->getEventById('outside-window') === null && strpos($notices(), '16 MiB') !== false, 'Bounded detail miss was not explained');

// Millions of tiny/empty records must not bypass the byte cap to monopolize a worker.
file_put_contents($eventPath, $event('old-match', ['type' => 'nws']) . str_repeat("{}\n", UiLogFixture::UI_LOG_SCAN_LINES + 1));
log_check($module->getEvents(500, 'nws') === [], 'Record scan count was unbounded');
log_check(strpos($notices(), 'processing limit') !== false, 'Processing cap was not explained');

// Locks fail promptly, release on early return, and never block the PBX writer.
file_put_contents($eventPath, $event('locked'));
$lock = fopen($eventPath, 'rb');
flock($lock, LOCK_EX);
$start = microtime(true);
log_check($module->getEvents() === [] && microtime(true) - $start < 0.5, 'UI waited for an exclusive writer lock');
log_check(strpos($notices(), 'busy') !== false, 'Busy log was presented as no events without explanation');
flock($lock, LOCK_UN);
log_check($module->getEventById('locked') !== null, 'Lock release did not restore detail reading');
log_check(flock($lock, LOCK_EX | LOCK_NB), 'Early detail return retained its shared log lock');
flock($lock, LOCK_UN); fclose($lock);

// Refuse unsafe objects before opening; a FIFO would otherwise hang fopen(rb).
unlink($eventPath);
posix_mkfifo($eventPath, 0600);
$start = microtime(true);
log_check($module->getEvents() === [] && microtime(true) - $start < 0.5, 'FIFO log was opened or blocked the view');
log_check(strpos($notices(), 'unsafe') !== false, 'Unsafe path lacked a useful error');
unlink($eventPath);
file_put_contents($root . '/secret', 'PRIVATE-CONTENTS');
symlink($root . '/secret', $eventPath);
log_check($module->getEvents() === [] && strpos($notices(), 'PRIVATE-CONTENTS') === false, 'Symlink log disclosed its target');
unlink($eventPath);
link($root . '/secret', $eventPath);
log_check($module->getEvents() === [], 'Hard-linked log was accepted');
unlink($eventPath);
log_check($module->getEvents() === [] && $notices() === '', 'Absent initial log produced a read failure');

// Key usage uses bounded tail reads; no-key checks and human activity never crowd it out.
$handle = fopen($auditPath, 'wb');
fseek($handle, 1024 * 1024 * 1024, SEEK_SET); fwrite($handle, "\n");
for ($i = 0; $i < 25; $i++) { fwrite($handle, json_encode(['created_at' => 'audit-' . $i, 'ip' => '192.0.2.1', 'action' => 'announce', 'status' => 200, 'credentials_present'=>true]) . "\n"); }
for ($i = 0; $i < 100; $i++) { fwrite($handle, json_encode(['ip' => '127.0.0.1', 'action' => 'get_status']) . "\n"); }
fwrite($handle, json_encode(['created_at' => 'internal', 'ip' => '::1', 'action' => 'announce', 'status' => 202, 'credential_id'=>'legacy']) . "\n");
fwrite($handle,json_encode(['created_at'=>'human','ip'=>'192.0.2.7','action'=>'operator_sign_in_completed','actor'=>'operator:fixture','credential_id'=>'api_'.str_repeat('a',24),'status'=>200])."\n");
fwrite($handle,json_encode(['created_at'=>'key-health','ip'=>'127.0.0.1','action'=>'get_status','credentials_present'=>true,'status'=>200])."\n");
fclose($handle);
$audit = log_private($module, 'getControlApiAuditSummary');
log_check(count($audit) === 20 && $audit[0]['ip'] === 'PBX internal' && $audit[0]['created_at']==='key-health' && $audit[1]['created_at']==='internal' && $audit[2]['created_at'] === 'audit-24', 'Audit ordering, local filtering, or 20-row cap changed');
log_check(strpos($notices('audit'), '16 MiB') !== false, 'Audit tail window was not disclosed');

// All three existing views surface escaped notices, including a missing detail.
$log_notices = ['<script>fixture notice</script>'];
$events = []; $status_summary = []; $selected_type = ''; $selected_limit = 100; $selected_date = ''; $hero_image = '';
ob_start(); include dirname(__DIR__) . '/slsmassnotifyserver/views/main.php'; $main = ob_get_clean();
$event = null;
ob_start(); include dirname(__DIR__) . '/slsmassnotifyserver/views/detail.php'; $detail = ob_get_clean();
$diagnostics = ['control_api_audit_notices' => $log_notices];
ob_start(); include dirname(__DIR__) . '/slsmassnotifyserver/views/help.php'; $help = ob_get_clean();
foreach ([$main, $detail, $help] as $view) {
    log_check(strpos($view, '&lt;script&gt;fixture notice&lt;/script&gt;') !== false && strpos($view, '<script>fixture notice</script>') === false, 'A log view omitted or failed to escape read notices');
}
echo "UI log bounds passed: order/filter/detail/retention, 16 MiB I/O, 256 KiB records, 2 MiB responses, locks, unsafe paths, and escaped notices.\n";
