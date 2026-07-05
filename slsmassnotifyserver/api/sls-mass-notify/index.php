<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

declare(strict_types=1);

const CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const LEGACY_CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications-' . 'settings.json';
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
    $path = is_readable(CONFIG_FILE) ? CONFIG_FILE : LEGACY_CONFIG_FILE;
    if (!is_readable($path)) {
        respond(503, ['ok' => false, 'error' => 'config_unavailable']);
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function provided_key(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }
    return trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function client_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function ipv4_cidr_match(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    [$network, $bits] = explode('/', $cidr, 2);
    if (!filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return ((ip2long($ip) & $mask) === (ip2long($network) & $mask));
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
        if (hash_equals($item, $ip) || ipv4_cidr_match($ip, $item)) {
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
    $data = [];
    if (is_readable(CONTROL_API_RATE_FILE)) {
        $decoded = json_decode((string)file_get_contents(CONTROL_API_RATE_FILE), true);
        $data = is_array($decoded) ? $decoded : [];
    }
    $data = array_filter($data, static function ($entry) use ($bucket) {
        return is_array($entry) && (($entry['bucket'] ?? '') === $bucket);
    });
    $key = hash('sha256', $ip);
    $entry = is_array($data[$key] ?? null) ? $data[$key] : ['bucket' => $bucket, 'count' => 0];
    $entry['bucket'] = $bucket;
    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $data[$key] = $entry;
    @file_put_contents(CONTROL_API_RATE_FILE, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod(CONTROL_API_RATE_FILE, 0660);
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
        @mkdir($dir, 0755, true);
    }
    $lines = is_readable(CONTROL_API_AUDIT_LOG) ? (file(CONTROL_API_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
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
    @file_put_contents(CONTROL_API_AUDIT_LOG, implode("\n", $kept) . "\n", LOCK_EX);
    @chmod(CONTROL_API_AUDIT_LOG, 0660);
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
        $result = freepbx_module()->controlApiConfig(['include_secrets' => !empty($_GET['include_secrets'])]);
        audit_control_api($clientIp, 'get_config', !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'resource' => 'config'] + $result);
    }
    audit_control_api($clientIp, 'get_status', 200, true);
    respond(200, ['ok' => true, 'resource' => 'status', 'status' => read_json_file(STATUS_FILE)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$action = strtolower(trim((string)($body['action'] ?? '')));
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
