<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

declare(strict_types=1);
ini_set('display_errors', '0');

const CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const STATUS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json';
const EVENTS_FILE = '/var/log/sls_mass_notify_events.jsonl';
const SIPNOTIFY_SCRIPT = '/usr/local/bin/sls_mass_notify/sls_notify.py';
const CONTROL_API_AUDIT_LOG = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/control-api-audit.jsonl';
const CONTROL_API_RATE_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/control-api-ratelimit.json';

function respond(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function config(): array
{
	if (!is_readable(CONFIG_FILE)) {
		respond(503, ['ok' => false, 'error' => 'config_unavailable']);
	}
	$decoded = json_decode((string)file_get_contents(CONFIG_FILE), true);
    if (!is_array($decoded)) {
        respond(503, ['ok' => false, 'error' => 'config_invalid']);
    }
    return $decoded;
}

function request_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach ([$serverKey, 'REDIRECT_' . $serverKey] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['apache_request_headers', 'getallheaders'] as $function) {
        if (!function_exists($function)) {
            continue;
        }
        $headers = $function();
        if (!is_array($headers)) {
            continue;
        }
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return trim((string)$value);
            }
        }
    }
    return '';
}

function provided_key(): string
{
    $header = request_header_value('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }
    return request_header_value('X-API-Key');
}

function client_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function cidr_match(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    [$network, $bits] = explode('/', $cidr, 2);
    if (!filter_var($network, FILTER_VALIDATE_IP)) {
        return false;
    }
    $bits = (int)$bits;
    $packedIp = @inet_pton($ip);
    $packedNetwork = @inet_pton($network);
    if (!is_string($packedIp) || !is_string($packedNetwork) || strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }
    $maxBits = strlen($packedIp) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $wholeBytes = intdiv($bits, 8);
    $remainingBits = $bits % 8;
    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }
    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

function ip_allowed(array $control, string $ip): bool
{
    if (empty($control['ip_allowlist_enabled'])) {
        return true;
    }
    $items = preg_split('/[\r\n,]+/', (string)($control['ip_allowlist'] ?? '')) ?: [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (hash_equals($item, $ip) || cidr_match($ip, $item)) {
            return true;
        }
    }
    return false;
}

function control_rate_allowed(array $control, string $ip): bool
{
    if (empty($control['rate_limit_enabled'])) {
        return true;
    }
    $limit = (int)($control['rate_limit_per_minute'] ?? 60);
    $limit = min(600, max(1, $limit));
    $bucket = gmdate('YmdHi');
    $handle = @fopen(CONTROL_API_RATE_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }
    rewind($handle);
    $decoded = json_decode((string)stream_get_contents($handle), true);
    $data = is_array($decoded) ? $decoded : [];
    $data = array_filter($data, static function ($entry) use ($bucket) {
        return is_array($entry) && (($entry['bucket'] ?? '') === $bucket);
    });
    $key = hash('sha256', $ip);
    $entry = is_array($data[$key] ?? null) ? $data[$key] : ['bucket' => $bucket, 'count' => 0];
    $entry['bucket'] = $bucket;
    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $data[$key] = $entry;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod(CONTROL_API_RATE_FILE, 0640);
    return $entry['count'] <= $limit;
}

function audit_control_api(string $ip, string $action, int $status, bool $ok): void
{
    $record = [
        'created_at' => gmdate('c'),
        'ip' => $ip,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'action' => preg_replace('/[^a-z0-9_.-]+/i', '_', $action) ?: 'unknown',
        'status' => $status,
        'ok' => $ok,
    ];
    $dir = dirname(CONTROL_API_AUDIT_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $handle = @fopen(CONTROL_API_AUDIT_LOG, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return;
    }
    rewind($handle);
    $lines = preg_split('/\R/', (string)stream_get_contents($handle), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $cutoff = time() - (30 * 86400);
    $kept = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        $created = strtotime((string)($decoded['created_at'] ?? '')) ?: 0;
        if ($created >= $cutoff) {
            $kept[] = $line;
        }
    }
    $kept[] = json_encode($record, JSON_UNESCAPED_SLASHES);
    $kept = array_slice($kept, -10000);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, implode("\n", $kept) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod(CONTROL_API_AUDIT_LOG, 0640);
}

function read_json_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function recent_events(int $limit): array
{
    $limit = min(100, max(1, $limit));
    if (!is_readable(EVENTS_FILE)) {
        return [];
    }
    $lines = file(EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);
    $events = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }
    return array_reverse($events);
}

function freepbx_module()
{
    static $module = null;
    if ($module !== null) {
        return $module;
    }
    $freepbxConfig = '/etc/freepbx.conf';
    if (!is_readable($freepbxConfig)) {
        respond(503, ['ok' => false, 'error' => 'freepbx_unavailable']);
    }
    global $amp_conf;
    $bootstrap_settings = [
        'freepbx_auth' => false,
        'skip_astman' => true,
    ];
    require_once $freepbxConfig;
    try {
        $fw = \FreePBX::Create();
        $module = $fw->Slsmassnotifyserver;
    } catch (\Throwable $e) {
        try {
            $module = \FreePBX::Slsmassnotifyserver();
        } catch (\Throwable $e2) {
            respond(503, ['ok' => false, 'error' => 'module_unavailable']);
        }
    }
    return $module;
}

function sanitize_targets($value): array
{
    $targets = [];
    foreach ((array)$value as $target) {
        $target = preg_replace('/[^0-9]/', '', (string)$target);
        if ($target !== '') {
            $targets[$target] = $target;
        }
    }
    return array_values($targets);
}

$config = config();
$control = is_array($config['control_api'] ?? null) ? $config['control_api'] : [];
$clientIp = client_ip();
$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
$loopback = in_array($clientIp, ['127.0.0.1', '::1'], true);
if (!$https && !$loopback) {
    audit_control_api($clientIp, 'https_required', 426, false);
    respond(426, ['ok' => false, 'error' => 'https_required']);
}
if (empty($control['enabled'])) {
    audit_control_api($clientIp, 'disabled', 403, false);
    respond(403, ['ok' => false, 'error' => 'control_api_disabled']);
}
if (!ip_allowed($control, $clientIp)) {
    audit_control_api($clientIp, 'blocked_ip', 403, false);
    respond(403, ['ok' => false, 'error' => 'ip_not_allowed']);
}
if (!control_rate_allowed($control, $clientIp)) {
    audit_control_api($clientIp, 'rate_limited', 429, false);
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}
$expected = trim((string)($control['api_key'] ?? ''));
$provided = provided_key();
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    audit_control_api($clientIp, 'unauthorized', 401, false);
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $resource = strtolower(trim((string)($_GET['resource'] ?? 'status')));
    if ($resource === 'events') {
        audit_control_api($clientIp, 'get_events', 200, true);
        respond(200, ['ok' => true, 'resource' => 'events', 'events' => recent_events((int)($_GET['limit'] ?? 25))]);
    }
    if ($resource === 'config') {
        try {
            $result = freepbx_module()->controlApiConfig();
            audit_control_api($clientIp, 'get_config', !empty($result['success']) ? 200 : 400, !empty($result['success']));
            respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'resource' => 'config'] + $result);
        } catch (\Throwable $exception) {
            error_log('SLS Mass Notify Control API config read failure: ' . $exception->getMessage());
            audit_control_api($clientIp, 'get_config', 500, false);
            respond(500, ['ok' => false, 'error' => 'internal_error']);
        }
    }
    audit_control_api($clientIp, 'get_status', 200, true);
    respond(200, ['ok' => true, 'resource' => 'status', 'status' => read_json_file(STATUS_FILE)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    audit_control_api($clientIp, 'method_not_allowed', 405, false);
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
if (strpos($contentType, 'application/json') !== 0) {
    audit_control_api($clientIp, 'unsupported_media_type', 415, false);
    respond(415, ['ok' => false, 'error' => 'content_type_must_be_json']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    audit_control_api($clientIp, 'request_too_large', 413, false);
    respond(413, ['ok' => false, 'error' => 'request_too_large']);
}
$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > 65536) {
    audit_control_api($clientIp, 'request_too_large', 413, false);
    respond(413, ['ok' => false, 'error' => 'request_too_large']);
}
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    audit_control_api($clientIp, 'invalid_json', 400, false);
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$action = strtolower(trim((string)($body['action'] ?? '')));
try {
    $module = freepbx_module();

    if ($action === 'send_announcement') {
        $result = $module->controlApiSendAnnouncement($body);
        audit_control_api($clientIp, $action, !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    if ($action === 'test_nws' || $action === 'trigger_nws_test') {
        $result = $module->controlApiTriggerNwsTest($body);
        audit_control_api($clientIp, $action, !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    if ($action === 'get_config') {
        $result = $module->controlApiConfig($body);
        audit_control_api($clientIp, $action, !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    if ($action === 'update_config') {
        $result = $module->controlApiUpdateConfig($body);
        audit_control_api($clientIp, $action, !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    audit_control_api($clientIp, $action ?: 'unsupported_action', 400, false);
    respond(400, ['ok' => false, 'error' => 'unsupported_action']);
} catch (\Throwable $exception) {
    error_log('SLS Mass Notify Control API failure: ' . $exception->getMessage());
    audit_control_api($clientIp, $action ?: 'internal_error', 500, false);
    respond(500, ['ok' => false, 'error' => 'internal_error']);
}
