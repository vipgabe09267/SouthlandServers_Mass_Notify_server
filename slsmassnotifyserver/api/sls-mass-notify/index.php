<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

declare(strict_types=1);
ini_set('display_errors', '0');
// Older installations alias the complete API prefix to a PHP shim, including
// child paths. Dispatch exact dedicated endpoints before Control API
// authentication; each endpoint enforces its own protocol and authority.
$slsRequestPath = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2)[0];
if ($slsRequestPath === '/api/sls-mass-notify/sms-callback.php') {
    require __DIR__ . '/sms-callback.php';
    exit;
}
// Legacy prefix aliases can route the internally rewritten media request here.
// The media handler enforces its own central policy and safe filename boundary.
if ($slsRequestPath === '/api/sls-mass-notify/media.php' || str_starts_with($slsRequestPath, '/sls_mass_notify/')) {
    require __DIR__ . '/media.php';
    exit;
}
if ($slsRequestPath === '/api/sls-mass-notify/trigger.php') { require __DIR__ . '/trigger.php'; exit; }
// These are fixed protocol routes, never an input-derived PHP filename. The
// cluster/edge handlers enforce disabled state and their own signed authority.
$slsDedicatedRoutes = [
    '/api/sls-mass-notify/peer.php' => 'peer.php',
    '/api/sls-mass-notify/edge.php' => 'edge.php',
    '/api/sls-mass-notify/edge-view.php' => 'edge-view.php',
];
if (isset($slsDedicatedRoutes[$slsRequestPath])) {
    require __DIR__ . '/' . $slsDedicatedRoutes[$slsRequestPath];
    exit;
}
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/event-log.php';
require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/config-crypto.php';

const CONFIG_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const STATUS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json';
const EVENTS_FILE = '/var/log/sls_mass_notify_events.jsonl';
const CONTROL_API_AUDIT_HELPER = '/usr/local/bin/sls_mass_notify/sls_storage_maintenance.py';
const CONTROL_API_RATE_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/control-api-ratelimit.json';
const CONTROL_API_MAX_CONFIG_BODY_BYTES = 2 * 1024 * 1024;
const CONTROL_API_MAX_ACTION_BODY_BYTES = 65536;

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
    try { return \FreePBX\modules\SlsConfigCrypto::readFile(CONFIG_FILE); }
    catch (Throwable $error) { respond(503, ['ok' => false, 'error' => 'config_unavailable']); }
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
    return (string)($GLOBALS['sls_request_network']['ip'] ?? trim((string)($_SERVER['REMOTE_ADDR'] ?? '')));
}

function decode_control_request(string $raw): array
{
    if (strlen($raw) > CONTROL_API_MAX_CONFIG_BODY_BYTES) {
        return ['status' => 413, 'error' => 'request_too_large', 'limit_bytes' => CONTROL_API_MAX_CONFIG_BODY_BYTES,
            'message' => 'The JSON request exceeds 2 MiB. Reduce the configuration payload before retrying.'];
    }
    $body = json_decode($raw, true, 64);
    if (!is_array($body) || array_is_list($body)) {
        return ['status' => 400, 'error' => 'json_object_required'];
    }
    if (!is_string($body['action'] ?? null)) {
        return ['status' => 400, 'error' => 'action_must_be_string'];
    }
    if (strtolower(trim($body['action'])) !== 'update_config' && strlen($raw) > CONTROL_API_MAX_ACTION_BODY_BYTES) {
        return ['status' => 413, 'error' => 'request_too_large', 'limit_bytes' => CONTROL_API_MAX_ACTION_BODY_BYTES,
            'message' => 'This action accepts at most 64 KiB of JSON. Only update_config accepts a larger client configuration.'];
    }
    return ['body' => $body];
}

function control_get_resource(array $query): array
{
    $resource = $query['resource'] ?? 'status';
    if (!is_string($resource) || strlen($resource) > 32) { return ['error' => 'invalid_resource']; }
    $resource = strtolower(trim($resource));
    if (!in_array($resource, ['status', 'capabilities', 'audiences', 'delivery', 'events', 'readiness', 'desktop_fleet', 'config', 'incidents', 'incident', 'incident_report','enterprise_capabilities','incident_templates'], true)) {
        return ['error' => 'unknown_resource'];
    }
    foreach (['job_id' => 64, 'incident_id' => 64, 'cursor' => $resource === 'events' ? 768 : 160, 'revision' => 160] as $field => $limit) {
        if (isset($query[$field]) && (!is_string($query[$field]) || strlen($query[$field]) > $limit)) { return ['error' => 'invalid_' . $field]; }
    }
    foreach (['limit', 'job_offset'] as $field) {
        if (isset($query[$field]) && (!is_string($query[$field]) || !preg_match('/^[0-9]{1,3}$/D', $query[$field]))) {
            return ['error' => 'invalid_pagination'];
        }
    }
    if ($resource === 'delivery' && !preg_match('/^job_[a-f0-9]{32}$/D', $query['job_id'] ?? '')) { return ['error' => 'invalid_job_id']; }
    if (in_array($resource, ['incident', 'incident_report'], true) && !preg_match('/^inc_[a-f0-9]{32}$/D', $query['incident_id'] ?? '')) {
        return ['error' => 'invalid_incident_id'];
    }
    return ['resource' => $resource];
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

function api_state_metadata_matches(array $left, array $right): bool
{
    return ($left['mode'] & 0170000) === 0100000 && ($right['mode'] & 0170000) === 0100000
        && $left['nlink'] === 1 && $right['nlink'] === 1
        && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
}

function api_state_open(string $path, bool $create = false, bool $exclusive = false)
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    if ($exclusive && $before !== false) { throw new RuntimeException('state_temporary_exists'); }
    if ($before === false && $create) {
        $mask = umask(0027);
        try { $handle = @fopen($path, 'x+b'); } finally { umask($mask); }
        if ($handle === false && !$exclusive) { return api_state_open($path); }
        if ($handle === false) { throw new RuntimeException('state_open_failed'); }
    } else {
        if (!is_array($before)) { return null; }
        if (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) {
            throw new RuntimeException('state_unsafe');
        }
        // Linux opens O_RDWR FIFOs without waiting for another endpoint. A
        // substituted special file is rejected by fstat before any read/write.
        $handle = @fopen($path, 'r+b');
        if ($handle === false) { throw new RuntimeException('state_open_failed'); }
    }
    $metadata = fstat($handle);
    clearstatcache(true, $path);
    $current = @lstat($path);
    if (!is_array($metadata) || !is_array($current)
        || !api_state_metadata_matches($metadata, $current)
        || ($before !== false && !api_state_metadata_matches($before, $metadata))) {
        fclose($handle);
        throw new RuntimeException('state_changed_or_unsafe');
    }
    return $handle;
}

function api_state_parent_matches(string $directory, $handle): bool
{
    clearstatcache(true, $directory);
    $current = @lstat($directory);
    $opened = fstat($handle);
    return is_array($current) && is_array($opened)
        && ($current['mode'] & 0170000) === 0040000
        && $current['dev'] === $opened['dev'] && $current['ino'] === $opened['ino'];
}

function api_state_lock($handle, float $deadline): bool
{
    do {
        $blocked = 0;
        if (flock($handle, LOCK_EX | LOCK_NB, $blocked)) { return true; }
        if (!$blocked || microtime(true) >= $deadline) { return false; }
        usleep(2000);
    } while (microtime(true) < $deadline);
    return false;
}

function api_state_transaction(string $path, int $maximum, callable $update, int $waitMilliseconds = 250): bool
{
    $GLOBALS['sls_api_state_error'] = '';
    $directory = dirname($path);
    $parent = $lock = $legacy = $temporaryHandle = null;
    $temporary = '';
    $temporaryIdentity = null;
    $deadline = microtime(true) + min(250, max(0, $waitMilliseconds)) / 1000;
    try {
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false
            || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('state_path_invalid');
        }
        $component = '';
        foreach (explode('/', trim($directory, '/')) as $part) {
            $component .= '/' . $part;
            clearstatcache(true, $component);
            $metadata = @lstat($component);
            if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('state_directory_unsafe');
            }
        }
        $parent = @fopen($directory, 'r');
        if ($parent === false || !api_state_parent_matches($directory, $parent)) {
            throw new RuntimeException('state_directory_unavailable');
        }
        $lock = api_state_open($path . '.lock', true);
        if (!api_state_lock($lock, $deadline)) { throw new RuntimeException('state_locked'); }
        clearstatcache(true, $path . '.lock');
        $lockNow = @lstat($path . '.lock');
        if (!is_array($lockNow) || !api_state_metadata_matches(fstat($lock), $lockNow)
            || !api_state_parent_matches($directory, $parent)) {
            throw new RuntimeException('state_changed_or_unsafe');
        }
        $legacy = api_state_open($path);
        $data = [];
        $original = null;
        if (is_resource($legacy)) {
            // Existing releases lock the data inode. During upgrade, defer to
            // any active old writer as well as serializing new sidecar users.
            if (!api_state_lock($legacy, $deadline)) { throw new RuntimeException('state_locked'); }
            $original = fstat($legacy);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($current) || !api_state_metadata_matches($original, $current)) {
                throw new RuntimeException('state_changed_or_unsafe');
            }
            if ($original['size'] > $maximum) { throw new RuntimeException('state_oversized'); }
            $raw = stream_get_contents($legacy, $maximum + 1);
            if (!is_string($raw) || strlen($raw) > $maximum) { throw new RuntimeException('state_oversized'); }
            $data = json_decode($raw, true, 32);
            if (!is_array($data) || ($data !== [] && array_is_list($data)) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('state_invalid');
            }
        }
        $result = $update($data);
        if (empty($result['write'])) { return !empty($result['allowed']); }
        $encoded = json_encode((object)$result['data'], JSON_UNESCAPED_SLASHES) . "\n";
        if (json_last_error() !== JSON_ERROR_NONE || strlen($encoded) > $maximum) {
            throw new RuntimeException('state_oversized');
        }
        if (!api_state_parent_matches($directory, $parent)) {
            throw new RuntimeException('state_directory_changed');
        }
        $temporary = $directory . '/.sls-api-state-' . bin2hex(random_bytes(16));
        $temporaryHandle = api_state_open($temporary, true, true);
        $temporaryIdentity = fstat($temporaryHandle);
        $written = 0;
        while ($written < strlen($encoded)) {
            $amount = @fwrite($temporaryHandle, substr($encoded, $written));
            if ($amount === false || $amount === 0) { throw new RuntimeException('state_write_failed'); }
            $written += $amount;
        }
        if (!fflush($temporaryHandle) || !function_exists('fsync') || !@fsync($temporaryHandle)) {
            throw new RuntimeException('state_sync_failed');
        }
        clearstatcache(true, $path);
        $current = @lstat($path);
        clearstatcache(true, $temporary);
        $temporaryNow = @lstat($temporary);
        if (($original === null ? $current !== false : (!is_array($current)
                || !api_state_metadata_matches($original, $current) || $current['size'] !== $original['size']))
            || !is_array($temporaryNow) || !api_state_metadata_matches(fstat($temporaryHandle), $temporaryNow)
            || !api_state_parent_matches($directory, $parent)) {
            throw new RuntimeException('state_changed_or_unsafe');
        }
        if (!@rename($temporary, $path)) { throw new RuntimeException('state_replace_failed'); }
        $temporary = '';
        if (!@fsync($parent)) { throw new RuntimeException('state_directory_sync_failed'); }
        return !empty($result['allowed']);
    } catch (Throwable $exception) {
        $reason = $exception instanceof RuntimeException && preg_match('/^state_[a-z_]+$/', $exception->getMessage())
            ? $exception->getMessage() : 'state_storage_failed';
        $GLOBALS['sls_api_state_error'] = $reason;
        return false;
    } finally {
        if (is_resource($temporaryHandle)) { fclose($temporaryHandle); }
        if ($temporary !== '' && is_resource($parent) && api_state_parent_matches($directory, $parent)) {
            clearstatcache(true, $temporary);
            $metadata = @lstat($temporary);
            if (is_array($metadata) && is_array($temporaryIdentity) && api_state_metadata_matches($temporaryIdentity, $metadata)) {
                @unlink($temporary);
            }
        }
        if (is_resource($legacy)) { fclose($legacy); }
        if (is_resource($lock)) { fclose($lock); }
        if (is_resource($parent)) { fclose($parent); }
    }
}

function api_rate_entries_valid(array $data, int $maximum, bool $desktop): bool
{
    if (count($data) > $maximum) { return false; }
    foreach ($data as $key => $entry) {
        if (!is_string($key) || !preg_match($desktop ? '/^(ok:|failed:)?[a-f0-9]{64}$/' : '/^[a-f0-9]{64}$/', $key)
            || !is_array($entry) || !is_string($entry['bucket'] ?? null)
            || !preg_match('/^[0-9]{12}$/', $entry['bucket'])
            || !is_int($entry['count'] ?? null) || $entry['count'] < 0) {
            return false;
        }
    }
    return true;
}

function control_rate_allowed(array $control, string $ip): bool
{
    $GLOBALS['sls_api_state_error'] = '';
    if (empty($control['rate_limit_enabled'])) {
        return true;
    }
    $limit = (int)($control['rate_limit_per_minute'] ?? 60);
    $limit = min(600, max(1, $limit));
    $bucket = gmdate('YmdHi');
    return api_state_transaction(CONTROL_API_RATE_FILE, 512 * 1024, static function (array $data) use ($bucket, $limit, $ip): array {
        if (!api_rate_entries_valid($data, 2048, false)) { throw new RuntimeException('state_invalid'); }
        $data = array_filter($data, static function (array $entry) use ($bucket): bool { return $entry['bucket'] === $bucket; });
        $key = hash('sha256', $ip);
        if (!isset($data[$key]) && count($data) >= 2048) {
            return ['allowed' => false, 'write' => false];
        }
        $entry = $data[$key] ?? ['bucket' => $bucket, 'count' => 0];
        $entry['count'] = min($limit, $entry['count']) + 1;
        $data[$key] = $entry;
        return ['allowed' => $entry['count'] <= $limit, 'write' => true, 'data' => $data];
    });
}

function audit_control_api(string $ip, string $action, int $status, bool $ok): void
{
    $action = substr(preg_replace('/[^a-z0-9_.-]+/i', '_', $action) ?: 'unknown', 0, 80);
    // Header presence is metadata only. Never copy a key into either journal.
    $credentialsPresent = provided_key() !== '';
    $recordData = [
        'created_at' => gmdate('c'), 'ip' => substr($ip, 0, 64),
        'method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12),
        'action' => $action, 'status' => $status, 'ok' => $ok, 'credentials_present' => $credentialsPresent,
    ];
    if (!$credentialsPresent) { $recordData['actor'] = 'api:no-key'; }
    if ($credentialsPresent && isset($GLOBALS['sls_control_principal']['id'])) { $recordData['credential_id'] = (string)$GLOBALS['sls_control_principal']['id']; }
    $error = 'audit_helper_unavailable';
    $process = null;
    $pipes = [];
    try {
        $recordData['event_id'] = 'audit_' . bin2hex(random_bytes(16));
        $record = json_encode($recordData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        // PHP's fopen cannot request O_NOFOLLOW/O_NONBLOCK. The existing
        // protected runtime performs descriptor-safe bounded append and fsync.
        if (function_exists('proc_open') && is_file(CONTROL_API_AUDIT_HELPER)
            && is_executable('/usr/bin/python3') && is_executable('/usr/bin/timeout')) {
            $command = ['/usr/bin/timeout', '--signal=KILL', '2', '/usr/bin/python3', '-I',
                CONTROL_API_AUDIT_HELPER, '--append-control-audit'];
            if (($GLOBALS['control']['audit_syslog'] ?? '0') === '1') { $command[] = '--syslog'; }
            $process = @proc_open($command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($process)) {
                $written = @fwrite($pipes[0], $record);
                fclose($pipes[0]); unset($pipes[0]);
                $output = stream_get_contents($pipes[1], 4097);
                fclose($pipes[1]); unset($pipes[1]);
                $exit = proc_close($process); $process = null;
                $result = is_string($output) && strlen($output) <= 4096 ? json_decode($output, true) : null;
                if ($written === strlen($record) && $exit === 0 && is_array($result) && ($result['ok'] ?? null) === true) {
                    return;
                }
                $error = is_array($result) && preg_match('/^audit_[a-z_]{1,58}$/', (string)($result['error_code'] ?? ''))
                    ? $result['error_code'] : ($exit === 124 || $exit === 137 ? 'audit_helper_timeout' : 'audit_helper_failed');
            }
        }
    } catch (Throwable $exception) {
        $error = 'audit_helper_failed';
    } finally {
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($process)) { proc_close($process); }
    }
    // Delivery/configuration may already have happened: changing its response
    // to a retryable failure could duplicate effects. Expose lost audit evidence
    // independently, without logging credentials, content or request addresses.
    if (!headers_sent()) {
        header('X-SLS-Audit-Status: unavailable');
        header('X-SLS-Audit-Error: ' . $error);
    }
    error_log('SLS Control API audit storage or forwarding could not be confirmed: ' . $error . '. The action response is unchanged; review storage diagnostics.');
}

function read_json_file(string $path): array
{
    $handle = null;
    try {
        $handle = api_state_open($path);
        if ($handle === null) { return []; }
        if (!flock($handle, LOCK_SH | LOCK_NB)) { throw new RuntimeException('status_busy'); }
        $metadata = fstat($handle);
        if ($metadata['size'] > 1048576) { throw new RuntimeException('status_oversized'); }
        $raw = stream_get_contents($handle, 1048577);
        $value = is_string($raw) && strlen($raw) <= 1048576 ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) { throw new RuntimeException('status_invalid'); }
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || !api_state_metadata_matches($metadata, $current) || $current['size'] !== $metadata['size']) {
            throw new RuntimeException('status_changed');
        }
        return $value;
    } finally { if (is_resource($handle)) { fclose($handle); } }
}

function recent_events(int $limit): array
{
    return \SLS\MassNotify\EventLog::page(EVENTS_FILE, $limit)['events'];
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

$config = config();
$control = is_array($config['control_api'] ?? null) ? $config['control_api'] : [];
$GLOBALS['sls_request_network'] = \SLS\MassNotify\ApiSecurity::requestNetwork($_SERVER, $config);
$clientIp = client_ip();
$https = $GLOBALS['sls_request_network']['https'];
$loopback = $GLOBALS['sls_request_network']['loopback'];
if ($GLOBALS['sls_request_network']['error'] !== '') {
    $status = $GLOBALS['sls_request_network']['error'] === 'invalid_proxy_configuration' ? 503 : 400;
    audit_control_api($clientIp, 'proxy_configuration', $status, false);
    respond($status, ['ok' => false, 'error' => $GLOBALS['sls_request_network']['error']]);
}
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
    if (!empty($GLOBALS['sls_api_state_error'])) {
        audit_control_api($clientIp, 'rate_storage_unavailable', 503, false);
        header('Retry-After: 2');
        respond(503, ['ok' => false, 'error' => 'rate_limit_storage_unavailable',
            'reason' => $GLOBALS['sls_api_state_error'], 'retryable' => true]);
    }
    audit_control_api($clientIp, 'rate_limited', 429, false);
    header('Retry-After: ' . (60 - time() % 60));
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}
$provided = provided_key();
$principal = \SLS\MassNotify\ApiSecurity::authenticate($control, $provided);
unset($provided);
if ($principal === null) {
    audit_control_api($clientIp, 'unauthorized', 401, false);
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}
$GLOBALS['sls_control_principal'] = $principal;

function require_permission(string $scope, string $action, bool $unrestricted = false): void
{
    $principal = $GLOBALS['sls_control_principal'] ?? [];
    if (!\SLS\MassNotify\ApiSecurity::permits($principal, $scope)
        || ($unrestricted && ($principal['audience']['unrestricted'] ?? false) !== true)) {
        audit_control_api(client_ip(), $action, 403, false);
        respond(403, ['ok' => false, 'error' => 'permission_denied', 'required_scope' => $scope]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = control_get_resource($_GET);
    if (isset($query['error'])) { audit_control_api($clientIp, 'invalid_query', 400, false); respond(400, ['ok' => false, 'error' => $query['error']]); }
    $resource = $query['resource'];
    require_permission('read', 'get_' . $resource);
    if (in_array($resource, ['enterprise_capabilities','incident_templates'], true)) {
        if (($principal['id'] ?? '') === 'legacy') {
            audit_control_api($clientIp, 'get_' . $resource, 403, false);
            respond(403, ['ok'=>false,'error'=>'named_credential_required']);
        }
        try {
            $result = $resource === 'enterprise_capabilities' ? \SLS\MassNotify\ControlContract::enterpriseCapabilities($principal, $config)
                : freepbx_module()->enterpriseControlTemplates($principal);
        } catch (DomainException $error) {
            audit_control_api($clientIp, 'get_' . $resource, 403, false);
            respond(403, ['ok'=>false,'error'=>'permission_denied','message'=>$error->getMessage()]);
        } catch (Throwable $error) {
            audit_control_api($clientIp, 'get_' . $resource, 503, false);
            respond(503, ['ok'=>false,'error'=>'enterprise_resource_unavailable','retryable'=>true]);
        }
        audit_control_api($clientIp, 'get_' . $resource, 200, true);
        respond(200, ['ok'=>true,'resource'=>$resource] + $result);
    }
    if ($resource === 'capabilities' || $resource === 'audiences') {
        $result = $resource === 'capabilities' ? \SLS\MassNotify\ControlContract::capabilities($principal)
            : \SLS\MassNotify\ControlContract::audiences($principal, $config);
        audit_control_api($clientIp, 'get_' . $resource, 200, true);
        respond(200, ['ok' => true, 'resource' => $resource] + $result);
    }
    if (in_array($resource, ['incidents', 'incident', 'incident_report'], true)) {
        require_permission('read', 'get_' . $resource, true);
        $module = freepbx_module();
        $id = is_string($_GET['incident_id'] ?? null) ? $_GET['incident_id'] : '';
        foreach (['cursor', 'revision'] as $field) {
            if (isset($_GET[$field]) && (!is_string($_GET[$field]) || strlen($_GET[$field]) > 160)) {
                respond(400, ['ok' => false, 'error' => 'invalid_pagination', 'message' => 'Incident pagination fields must be bounded strings.']);
            }
        }
        foreach (['limit', 'job_offset'] as $field) {
            if (isset($_GET[$field]) && (!is_string($_GET[$field]) || !preg_match('/^[0-9]{1,3}$/D', $_GET[$field]))) {
                respond(400, ['ok' => false, 'error' => 'invalid_pagination', 'message' => 'Incident pagination counts must be nonnegative integers.']);
            }
        }
        if (isset($_GET['archived']) && !in_array($_GET['archived'], ['0', '1'], true)) {
            respond(400, ['ok' => false, 'error' => 'invalid_incident_archive_filter']);
        }
        $result = $resource === 'incidents' ? $module->listIncidents((int)($_GET['limit'] ?? 50), $_GET['cursor'] ?? '', ($_GET['archived'] ?? '') === '1')
            : ($resource === 'incident_report' ? $module->exportIncidentReport($id, (int)($_GET['job_offset'] ?? 0), $_GET['revision'] ?? '') : $module->getIncident($id));
        $status = ($result['error_code'] ?? '') === 'incident_storage_unavailable' ? 503 : (!empty($result['success']) ? 200 : 400);
        if ($status === 503) { header('Retry-After: 5'); }
        audit_control_api($clientIp, 'get_' . $resource, $status, !empty($result['success']));
        respond($status, ['ok' => !empty($result['success']), 'resource' => $resource] + $result);
    }
    if ($resource === 'delivery') {
        $result = freepbx_module()->getAnnouncementJob((string)($_GET['job_id'] ?? ''));
        if (($result['error_code'] ?? '') === 'permission_denied') {
            audit_control_api($clientIp, 'get_delivery', 403, false);
            respond(403, ['ok' => false, 'error' => 'permission_denied']);
        }
        audit_control_api($clientIp, 'get_delivery', 200, !empty($result['success']));
        respond(200, ['ok' => !empty($result['success']), 'resource' => 'delivery'] + $result);
    }
    if ($resource === 'events') {
        require_permission('read', 'get_events', true);
        if ((isset($_GET['limit']) && (!is_string($_GET['limit']) || !preg_match('/^[0-9]{1,3}$/D', $_GET['limit'])))
            || (isset($_GET['cursor']) && !is_string($_GET['cursor']))) {
            respond(400, ['ok'=>false, 'error'=>'invalid_event_pagination']);
        }
        try { $page = \SLS\MassNotify\EventLog::page(EVENTS_FILE, (int)($_GET['limit'] ?? 25), $_GET['cursor'] ?? ''); }
        catch (InvalidArgumentException $error) { respond(400, ['ok'=>false, 'error'=>'invalid_event_cursor']); }
        catch (Throwable $error) {
            $changed = $error->getMessage() === 'event_log_changed';
            if (!$changed) { header('Retry-After: 2'); }
            respond($changed ? 409 : 503, ['ok'=>false, 'error'=>$changed ? 'event_log_changed' : 'event_log_unavailable',
                'message'=>$changed ? 'The log was rotated or compacted. Start a new page without a cursor.' : 'The log is busy or unavailable. Retry shortly; check log permissions if this persists.',
                'retryable'=>!$changed]);
        }
        audit_control_api($clientIp, 'get_events', 200, true);
        respond(200, ['ok'=>true, 'resource'=>'events'] + $page);
    }
    if ($resource === 'readiness') {
        require_permission('read', 'get_readiness', true);
        try { $report = freepbx_module()->getDeploymentReadiness(); }
        catch (Throwable $error) {
            audit_control_api($clientIp, 'get_readiness', 503, false);
            respond(503, ['ok'=>false, 'error'=>'readiness_unavailable', 'message'=>'The PBX readiness report could not be generated. Check the module diagnostics and PHP error log.']);
        }
        audit_control_api($clientIp, 'get_readiness', 200, true);
        respond(200, ['ok'=>true, 'resource'=>'readiness'] + $report);
    }
    if ($resource === 'desktop_fleet') {
        require_permission('read', 'get_desktop_fleet', true);
        try { $fleet = freepbx_module()->getDesktopFleet(); }
        catch (Throwable $error) { respond(503, ['ok'=>false, 'error'=>'fleet_inventory_unavailable', 'retryable'=>true]); }
        audit_control_api($clientIp, 'get_desktop_fleet', 200, true);
        respond(200, ['ok'=>true, 'resource'=>'desktop_fleet'] + $fleet);
    }
    if ($resource === 'config') {
        require_permission('config', 'get_config', true);
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
    if (($principal['audience']['unrestricted'] ?? false) !== true) {
        // Operational status.json can contain recipient names and incident text.
        audit_control_api($clientIp, 'get_status', 200, true);
        respond(200, ['ok' => true, 'resource' => 'status', 'status' => ['control_api_enabled' => true]]);
    }
    try { $status = read_json_file(STATUS_FILE); }
    catch (Throwable $error) {
        audit_control_api($clientIp, 'get_status', 503, false);
        header('Retry-After: 2'); respond(503, ['ok' => false, 'error' => 'status_unavailable', 'retryable' => true]);
    }
    audit_control_api($clientIp, 'get_status', 200, true);
    respond(200, ['ok' => true, 'resource' => 'status', 'status' => $status]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    audit_control_api($clientIp, 'method_not_allowed', 405, false);
    header('Allow: GET, POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    audit_control_api($clientIp, 'unsupported_media_type', 415, false);
    respond(415, ['ok' => false, 'error' => 'content_type_must_be_json']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > CONTROL_API_MAX_CONFIG_BODY_BYTES) {
    audit_control_api($clientIp, 'request_too_large', 413, false);
    respond(413, ['ok' => false, 'error' => 'request_too_large', 'limit_bytes' => CONTROL_API_MAX_CONFIG_BODY_BYTES,
        'message' => 'The JSON request exceeds 2 MiB. Reduce the configuration payload before retrying.']);
}
$rawBody = (string)file_get_contents('php://input', false, null, 0, CONTROL_API_MAX_CONFIG_BODY_BYTES + 1);
$decodedRequest = decode_control_request($rawBody);
if (isset($decodedRequest['error'])) {
    $requestStatus = $decodedRequest['status'];
    unset($decodedRequest['status']);
    audit_control_api($clientIp, $decodedRequest['error'], $requestStatus, false);
    respond($requestStatus, ['ok' => false] + $decodedRequest);
}
$body = $decodedRequest['body'];

$action = strtolower(trim((string)($body['action'] ?? '')));
$scope = ['retry_announcement' => 'send', 'send_announcement' => 'send', 'preview_announcement' => 'send', 'test_nws' => 'test',
    'trigger_nws_test' => 'test', 'get_config' => 'config', 'update_config' => 'config',
    'start_incident' => 'send', 'update_incident' => 'send', 'incident_roll_call' => 'config', 'incident_checklist' => 'config', 'enterprise_template_start'=>'send'][$action] ?? '';
if ($scope === '') { audit_control_api($clientIp, 'unsupported_action', 400, false); respond(400, ['ok' => false, 'error' => 'unsupported_action']); }
$incidentAction = in_array($action, ['start_incident', 'update_incident', 'incident_roll_call', 'incident_checklist'], true);
require_permission($scope, $action, $scope === 'config' || $incidentAction);
if (($incidentAction || $action === 'enterprise_template_start') && $principal['id'] === 'legacy') {
    audit_control_api($clientIp, $action, 403, false);
    respond(403, ['ok' => false, 'error' => 'named_credential_required',
        'message' => 'Incident workflows require a named, revocable API credential.']);
}
if ($scope === 'test' && !\SLS\MassNotify\ApiSecurity::permitsTest($principal, $body, $config)) {
    audit_control_api($clientIp, $action, 403, false);
    respond(403, ['ok' => false, 'error' => 'audience_not_permitted']);
}
if ($action === 'update_config') {
    $patch = $body['settings'] ?? $body['config'] ?? [];
    if (is_array($patch) && (array_key_exists('api_network', $patch)
        || (is_array($patch['control_api'] ?? null) && (array_key_exists('credentials', $patch['control_api'])
            || ($principal['id'] !== 'legacy' && array_key_exists('api_key', $patch['control_api'])))))) {
        audit_control_api($clientIp, $action, 403, false);
        respond(403, ['ok' => false, 'error' => 'administrator_only_setting']);
    }
}
if ($action === 'send_announcement' || $action === 'preview_announcement') {
    $body['action'] = $action;
    try { $body = \SLS\MassNotify\ControlContract::announcementInput($body); }
    catch (InvalidArgumentException $error) {
        audit_control_api($clientIp, $action, 400, false);
        respond(400, ['ok' => false, 'error' => 'invalid_announcement_fields', 'message' => $error->getMessage()]);
    }
}
try {
    $module = freepbx_module();
    if ($action === 'enterprise_template_start') {
        $input = $body; unset($input['action']);
        try { $result = $module->startEnterpriseControlTemplate($input, $principal); }
        catch (DomainException $error) {
            audit_control_api($clientIp, $action, 403, false);
            respond(403, ['ok'=>false,'error'=>'permission_denied','message'=>$error->getMessage()]);
        } catch (InvalidArgumentException $error) {
            audit_control_api($clientIp, $action, 400, false);
            respond(400, ['ok'=>false,'error'=>'invalid_template_launch','message'=>$error->getMessage()]);
        }
        $status = ($result['error_code'] ?? '') === 'incident_storage_unavailable' ? 503 : (!empty($result['success']) ? 200 : 400);
        if ($status === 503) { header('Retry-After: 5'); }
        audit_control_api($clientIp, $action, $status, !empty($result['success']));
        respond($status, ['ok'=>!empty($result['success']),'action'=>$action] + $result);
    }
    if ($incidentAction) {
        $input = $body; unset($input['action']);
        $id = is_string($input['incident_id'] ?? null) ? $input['incident_id'] : ''; unset($input['incident_id']);
        $actor = ['identity' => 'Control API ' . $principal['id'], 'source' => $principal['id'] === 'legacy' ? 'control_api_legacy' : 'control_api'];
        if ($principal['id'] !== 'legacy') { $actor['credential_id'] = $principal['id']; }
        if ($action === 'start_incident') { $result = $module->startIncident($input, $actor); }
        elseif ($action === 'update_incident') { $result = $module->sendIncidentUpdate($id, $input, $actor); }
        elseif ($action === 'incident_roll_call') { $result = $module->recordIncidentRollCall($id, $input, $actor); }
        else { $result = $module->recordIncidentChecklist($id, $input, $actor); }
        $status = ($result['error_code'] ?? '') === 'incident_storage_unavailable' ? 503 : (!empty($result['success']) ? 200 : 400);
        if ($status === 503) { header('Retry-After: 5'); }
        audit_control_api($clientIp, $action, $status, !empty($result['success']));
        respond($status, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    if ($action === 'retry_announcement') {
        if (!is_string($body['job_id'] ?? null) || count(array_diff(array_keys($body), ['action', 'job_id'])) > 0) {
            respond(400, ['ok' => false, 'error' => 'job_id_required']);
        }
        $result = $module->retryFailedAnnouncementJob($body['job_id'], ['trigger_source' => 'Control API']);
        if (($result['error_code'] ?? '') === 'permission_denied') { audit_control_api($clientIp, $action, 403, false); respond(403, ['ok' => false, 'error' => 'permission_denied']); }
        audit_control_api($clientIp, $action, !empty($result['success']) ? 200 : 400, !empty($result['success']));
        respond(!empty($result['success']) ? 200 : 400, ['ok' => !empty($result['success']), 'action' => $action] + $result);
    }

    if ($action === 'send_announcement' || $action === 'preview_announcement') {
        $result = $module->controlApiSendAnnouncement($body);
        if (($result['error_code'] ?? '') === 'permission_denied') { audit_control_api($clientIp, $action, 403, false); respond(403, ['ok' => false, 'error' => 'audience_not_permitted']); }
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
