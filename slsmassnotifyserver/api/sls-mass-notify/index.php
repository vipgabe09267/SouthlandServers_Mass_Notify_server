<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

declare(strict_types=1);

const CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const LEGACY_CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications-' . 'settings.json';
const STATUS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json';
const EVENTS_FILE = '/var/log/sls_mass_notify_events.jsonl';
const SIPNOTIFY_SCRIPT = '/usr/local/bin/sls_mass_notify/sls_notify.py';

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
if (empty($control['enabled'])) {
    respond(403, ['ok' => false, 'error' => 'control_api_disabled']);
}
$expected = trim((string)($control['api_key'] ?? ''));
$provided = provided_key();
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $resource = strtolower(trim((string)($_GET['resource'] ?? 'status')));
    if ($resource === 'events') {
        respond(200, ['ok' => true, 'resource' => 'events', 'events' => recent_events((int)($_GET['limit'] ?? 25))]);
    }
    if ($resource === 'config') {
        $result = freepbx_module()->controlApiConfig(['include_secrets' => !empty($_GET['include_secrets'])]);
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'resource' => 'config'] + $result);
    }
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
    respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
}

if ($action === 'test_nws' || $action === 'trigger_nws_test') {
    $result = $module->controlApiTriggerNwsTest($body);
    respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
}

if ($action === 'get_config') {
    $result = $module->controlApiConfig($body);
    respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
}

if ($action === 'update_config') {
    $result = $module->controlApiUpdateConfig($body);
    respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
}

respond(400, ['ok' => false, 'error' => 'unsupported_action']);
