<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
declare(strict_types=1);
require_once dirname(__DIR__) . '/sls-mass-notify/security.php';
require_once dirname(__DIR__) . '/sls-mass-notify/config-crypto.php';

const EVENTS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl';
const SETTINGS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const DESKTOP_LAST_SEEN_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/desktop-last-seen.json';
const DESKTOP_AUTH_RATE_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/desktop-auth-ratelimit.json';
const DESKTOP_ACK_DIRECTORY = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/acknowledgements';
const DEFAULT_LIMIT = 25;
const MAX_LIMIT = 100;
const RETENTION_MAX_EVENTS = 1000;

function desktop_response_headers(?int $now = null): array
{
    return [
        'Date: ' . gmdate('D, d M Y H:i:s', $now ?? time()) . ' GMT',
        'Cache-Control: private, no-store, max-age=0, must-revalidate',
        'Vary: Authorization',
    ];
}

header('Content-Type: application/json; charset=utf-8');
foreach (desktop_response_headers() as $responseHeader) {
    header($responseHeader);
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo ($encoded === false ? '{"ok":false,"error":"encoding_failed"}' : $encoded) . "\n";
    exit;
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

function basic_credentials(): array
{
    $username = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($username !== '' || $password !== '') {
        return [$username, $password];
    }

    $header = request_header_value('Authorization');
    if (!preg_match('/^Basic\s+([^\s]+)$/i', $header, $matches)) {
        return ['', ''];
    }
    $decoded = base64_decode($matches[1], true);
    if (!is_string($decoded) || strpos($decoded, ':') === false) {
        return ['', ''];
    }
    return explode(':', $decoded, 2);
}

function endpoint_slug(): string
{
    $path = (string)($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if (strpos($uriPath, '/api/sipnotify') === 0) {
            $path = substr($uriPath, strlen('/api/sipnotify'));
        }
    }
    $slug = strtolower(trim($path, "/ \t\n\r\0\x0B"));
    return $slug === '' ? 'desktop' : $slug;
}

function desktop_incident_module()
{
    if (!is_readable('/etc/freepbx.conf')) { respond(503, ['ok' => false, 'error' => 'incident_backend_unavailable']); }
    global $amp_conf;
    $bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
    require_once '/etc/freepbx.conf';
    try { return \FreePBX::Create()->Slsmassnotifyserver; }
    catch (\Throwable $error) { respond(503, ['ok' => false, 'error' => 'incident_backend_unavailable']); }
}

function settings_config(): array
{
    try { return \FreePBX\modules\SlsConfigCrypto::readFile(SETTINGS_FILE); }
    catch (Throwable $error) { respond(503, ['ok' => false, 'error' => 'config_unavailable']); }
}

function decrypt_desktop_password(string $encoded, array $settings): string
{
    if (!function_exists('openssl_decrypt') || strpos($encoded, 'v1:') !== 0) {
        return '';
    }
    $key = base64_decode((string)($settings['desktop_auth_key'] ?? ''), true);
    $raw = base64_decode(substr($encoded, 3), true);
    if (!is_string($key) || strlen($key) !== 32 || !is_string($raw) || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return is_string($plain) ? $plain : '';
}

function authorized_desktop_client(array $settings, string $providedUser, string $providedPass): array
{
    if ($providedUser === '' || $providedPass === '') {
        return [];
    }
    foreach ((array)($settings['desktop_clients'] ?? []) as $client) {
        if (!is_array($client) || empty($client['enabled'])) {
            continue;
        }
        $username = (string)($client['username'] ?? '');
        if ($username === '' || !hash_equals($username, $providedUser)) {
            continue;
        }
        // The configured fleet can contain 1,000 encrypted credentials. Only
        // decrypt the enabled account named by this request.
        $password = decrypt_desktop_password((string)($client['password_enc'] ?? ''), $settings);
        if ($password !== '' && hash_equals($password, $providedPass)) {
            return $client;
        }
    }
    return [];
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

function desktop_authenticated_capacity(array $settings): int
{
    // Preserve already enrolled clients if a legacy configuration's capacity
    // field is absent or lower than its fleet. This is limiter storage sizing,
    // not permission to enroll additional clients beyond resource admission.
    return min(1000, max(25, (int)($settings['desktop_client_limit'] ?? 25), count((array)($settings['desktop_clients'] ?? []))));
}

function desktop_rate_retry_after(?int $now = null): int
{
    return 60 - (($now ?? time()) % 60);
}

function desktop_auth_attempt_allowed(string $ip, string $username, bool $authenticated = false, ?int $now = null, bool $checkOnly = false, int $authenticatedCapacity = 1000): bool
{
    $bucket = gmdate('YmdHi', $now ?? time());
    return api_state_transaction(DESKTOP_AUTH_RATE_FILE, 524288, static function (array $data) use ($ip, $username, $authenticated, $checkOnly, $authenticatedCapacity, $bucket): array {
        if (!api_rate_entries_valid($data, 3048, true)) { throw new RuntimeException('state_invalid'); }
        $data = array_filter($data, static function (array $entry) use ($bucket): bool { return $entry['bucket'] === $bucket; });
        $legacy = false;
        foreach (array_keys($data) as $key) { $legacy = $legacy || strpos($key, ':') === false; }
        $allowed = true;
        if ($legacy) {
            // Older releases use bare hashes. Keep their current-minute budget
            // during upgrade; expired entries then yield to the new namespaces.
            foreach (['ip:' . $ip => 120, 'account:' . strtolower(substr($username, 0, 80)) => 60] as $identity => $limit) {
                $key = hash('sha256', $identity);
                if ($checkOnly) {
                    $allowed = $allowed && ($data[$key]['count'] ?? 0) < $limit;
                } elseif (!isset($data[$key]) && count($data) >= 2048) {
                    $allowed = false;
                } else {
                    $entry = $data[$key] ?? ['bucket' => $bucket, 'count' => 0];
                    $entry['count'] = min($limit, $entry['count']) + 1;
                    $data[$key] = $entry;
                    $allowed = $allowed && $entry['count'] <= $limit;
                }
            }
            return ['allowed' => $allowed, 'write' => !$checkOnly, 'data' => $data];
        }
        $namespaceCounts = ['ok:' => 0, 'failed:' => 0];
        foreach (array_keys($data) as $storedKey) {
            $namespaceCounts[strpos($storedKey, 'ok:') === 0 ? 'ok:' : 'failed:']++;
        }
        // Successful clients behind one NAT have independent user budgets.
        $identities = $authenticated
            ? ['authenticated:' . $username => 120]
            : ['failed-ip:' . $ip => 120, 'failed-pair:' . $ip . ':' . substr($username, 0, 80) => 20];
        foreach ($identities as $identity => $limit) {
            $namespace = $authenticated ? 'ok:' : 'failed:';
            $entryKey = $namespace . hash('sha256', $identity);
            if ($checkOnly) {
                $allowed = $allowed && ($data[$entryKey]['count'] ?? 0) < $limit;
                continue;
            }
            if (!isset($data[$entryKey])) {
                if ($namespaceCounts[$namespace] >= ($authenticated ? min(1000, max(1, $authenticatedCapacity)) : 2048)) {
                    $allowed = false;
                    continue;
                }
                $namespaceCounts[$namespace]++;
            }
            $entry = $data[$entryKey] ?? ['bucket' => $bucket, 'count' => 0];
            $entry['count'] = min($limit, $entry['count']) + 1;
            $data[$entryKey] = $entry;
            $allowed = $allowed && $entry['count'] <= $limit;
        }
        return ['allowed' => $allowed, 'write' => !$checkOnly, 'data' => $data];
    });
}

function desktop_auth_failure_budget_available(string $ip, string $username, ?int $now = null): bool
{
    // Check failures before password verification so 429 is an actual guessing
    // limit, rather than merely a different response to a verified bad password.
    // A malicious client sharing the exact source IP can still exhaust that
    // source's failure budget; there is intentionally no cross-IP account lock.
    return desktop_auth_attempt_allowed($ip, $username, false, $now, true);
}

function desktop_client_report(): array
{
    $report = [];
    $version = $_SERVER['HTTP_X_SLS_CLIENT_VERSION'] ?? '';
    if (is_string($version) && strlen($version) <= 64
        && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version)) {
        $report['version'] = $version;
    }
    foreach (['HTTP_X_SLS_PAYLOAD_SCHEMA'=>'payload_schema', 'HTTP_X_SLS_SSE_PROTOCOL'=>'sse_protocol'] as $header=>$key) {
        $value = $_SERVER[$header] ?? '';
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/D', $value)) { $report[$key] = (int)$value; }
    }
    if ($report) { $report['reported_at'] = gmdate('c'); }
    return $report;
}

function update_desktop_seen(array $client, array $presence = [], array $settings = []): void
{
    $username = (string)($client['username'] ?? '');
    if ($username === '' || strlen($username) > 80) { return; }
    $savedError = $GLOBALS['sls_api_state_error'] ?? '';
    $ok = api_state_transaction(DESKTOP_LAST_SEEN_FILE, 1024 * 1024, static function (array $data) use ($client, $presence, $settings, $username): array {
        if (isset($settings['desktop_clients']) && is_array($settings['desktop_clients'])) {
            $configured = [];
            foreach ($settings['desktop_clients'] as $row) {
                if (is_array($row) && !empty($row['enabled']) && is_string($row['username'] ?? null)) {
                    $configured[$row['username']] = true;
                }
            }
            $data = array_intersect_key($data, $configured);
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || strlen($key) > 80 || !is_array($value)) {
                throw new RuntimeException('state_invalid');
            }
        }
        $previous = is_array($data[$username] ?? null) ? $data[$username] : [];
        // A reused username must never inherit another device's receipts or
        // reported version. Probes without metadata retain same-device evidence.
        if (($previous['client_id'] ?? '') !== ($client['client_id'] ?? '')) { $previous = []; }
        unset($data[$username]);
        $data = array_slice($data, -999, null, true);
        $data[$username] = array_merge($previous, [
            'seen_at' => gmdate('c'),
            'ip' => substr((string)($GLOBALS['sls_request_network']['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'client_id' => substr((string)($client['client_id'] ?? ''), 0, 128),
            'name' => substr((string)($client['name'] ?? ''), 0, 200),
        ], array_intersect_key($presence, array_flip(['ack_event_id', 'ack_at', 'connected_until'])));
        $report = desktop_client_report();
        if ($report) { $data[$username]['client_report'] = $report; }
        return ['allowed' => true, 'write' => true, 'data' => $data];
    }, 0);
    if (!$ok && !headers_sent()) {
        header('X-SLS-Presence-Status: deferred');
    }
    // Presence is advisory. A busy or damaged presence file must never prevent
    // polling, acknowledgement persistence, SSE heartbeats or notification data.
    $GLOBALS['sls_api_state_error'] = $savedError;
}

function retention_days(array $settings): int
{
    return min(365, max(1, (int)($settings['log_retention_days'] ?? 90)));
}

function atomic_replace_journal(string $eventsFile, string $contents, ?callable $replaceFile = null, ?array $expectedMetadata = null): bool
{
    // Shared with acknowledgement persistence; its single JSON object is not
    // subject to the per-event JSONL line limit.
    if (strlen($contents) > 67108864) { return false; }
    $directory = realpath(dirname($eventsFile));
    if (!is_string($directory) || $directory !== dirname($eventsFile)) { return false; }
    clearstatcache(true, $eventsFile);
    $before = @lstat($eventsFile);
    if (is_array($before) && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) { return false; }
    if ($expectedMetadata !== null && (!is_array($before) || $before['dev'] !== $expectedMetadata['dev'] || $before['ino'] !== $expectedMetadata['ino'])) { return false; }
    $directoryHandle = @fopen($directory, 'r');
    if ($directoryHandle === false) { return false; }
    $temporary = $directory . '/.sipnotify_events.' . bin2hex(random_bytes(16));
    $temporaryHandle = null;
    $committed = false;
    try {
        $previousMask = umask(0027);
        try { $temporaryHandle = @fopen($temporary, 'x+b'); } finally { umask($previousMask); }
        if ($temporaryHandle === false || !desktop_journal_identity($temporaryHandle, $temporary)) { return false; }
        $length = strlen($contents);
        for ($offset = 0; $offset < $length;) {
            $written = @fwrite($temporaryHandle, substr($contents, $offset, 65536));
            if (!is_int($written) || $written <= 0) { return false; }
            $offset += $written;
        }
        if (!@fflush($temporaryHandle) || (function_exists('fsync') && !@fsync($temporaryHandle))
            || !desktop_journal_identity($temporaryHandle, $temporary)) { return false; }
        clearstatcache(true, $eventsFile);
        $current = @lstat($eventsFile);
        if (is_array($before)) {
            if (!is_array($current) || ($current['mode'] & 0170000) !== 0100000 || $current['nlink'] !== 1
                || $current['dev'] !== $before['dev'] || $current['ino'] !== $before['ino']) { return false; }
        } elseif ($current !== false) { return false; }
        $replaceFile = $replaceFile ?? static function (string $source, string $destination): bool { return @rename($source, $destination); };
        if (!$replaceFile($temporary, $eventsFile)) { return false; }
        $committed = true;
        if (function_exists('fsync') && !@fsync($directoryHandle)) { return false; }
        return true;
    } finally {
        if (!$committed && is_resource($temporaryHandle) && desktop_journal_identity($temporaryHandle, $temporary)) { @unlink($temporary); }
        if (is_resource($temporaryHandle)) { fclose($temporaryHandle); }
        fclose($directoryHandle);
    }
}

function desktop_journal_identity($handle, string $path): bool
{
    clearstatcache(true, $path);
    $opened = @fstat($handle); $named = @lstat($path);
    return is_array($opened) && is_array($named)
        && ($opened['mode'] & 0170000) === 0100000 && ($named['mode'] & 0170000) === 0100000
        && $opened['nlink'] === 1 && $named['nlink'] === 1
        && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino'];
}

function desktop_journal_open(string $path, string $error)
{
    clearstatcache(true, $path);
    if (realpath(dirname($path)) !== dirname($path)) { throw new RuntimeException($error); }
    $before = @lstat($path);
    if (is_array($before) && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) { throw new RuntimeException($error); }
    // O_RDWR makes opening a raced-in FIFO nonblocking before fstat rejects it.
    $previousMask = umask(0027);
    try { $handle = @fopen($path, 'c+b'); } finally { umask($previousMask); }
    if ($handle === false) { throw new RuntimeException($error); }
    if (!desktop_journal_identity($handle, $path)) { fclose($handle); throw new RuntimeException($error); }
    return $handle;
}

function desktop_journal_lock($handle, float $deadline): void
{
    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) { return; }
        if (microtime(true) >= $deadline) { throw new RuntimeException('journal_locked'); }
        usleep(10000);
    } while (true);
}

function desktop_journal_memory(int $additional): void
{
    $setting = trim((string)ini_get('memory_limit'));
    if ($setting === '' || $setting === '-1') { return; }
    $limit = (int)$setting;
    $unit = strtolower(substr($setting, -1));
    if ($unit === 'g') { $limit *= 1073741824; }
    elseif ($unit === 'm') { $limit *= 1048576; }
    elseif ($unit === 'k') { $limit *= 1024; }
    if ($limit > 0 && memory_get_usage(true) + $additional + 8388608 > $limit) { throw new RuntimeException('journal_memory_limit'); }
}

function desktop_journal_failure(string $error): void
{
    if (headers_sent()) {
        // A journal failure after an SSE handshake must not append bare JSON.
        // Closing after this control event lets the client reconnect normally.
        echo "event: reconnect\ndata: " . json_encode(['ok' => false, 'error' => $error, 'retryable' => true], JSON_UNESCAPED_SLASHES) . "\n\n";
        if (function_exists('desktop_stream_flush')) { desktop_stream_flush(); }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 2');
    respond(503, ['ok' => false, 'error' => $error, 'retryable' => true]);
}

function retained_events(array $settings): array
{
    static $cachedFingerprint = null;
    static $cachedEvents = [];
    static $cachedAt = 0;
    $eventsFile = EVENTS_FILE;
    $lockFile = $eventsFile . '.lock';
    $lockHandle = null; $handle = null;
    try {
        clearstatcache(true, $eventsFile);
        $metadata = @lstat($eventsFile);
        if (is_array($metadata) && (($metadata['mode'] & 0170000) !== 0100000 || $metadata['nlink'] !== 1)) { throw new RuntimeException('journal_unavailable'); }
        if (is_array($metadata) && $metadata['size'] > 67108864) { throw new RuntimeException('journal_too_large'); }
        $fingerprint = is_array($metadata) ? implode(':', [$metadata['dev'], $metadata['ino'], $metadata['size'], $metadata['mtime'], $metadata['ctime'], retention_days($settings)]) : '';
        if ($fingerprint !== '' && $fingerprint === $cachedFingerprint && time() - $cachedAt < 15) { return $cachedEvents; }
        // Release the previous snapshot before allocating a changed journal.
        $cachedEvents = []; $cachedFingerprint = null;
        $deadline = microtime(true) + 0.25;
        $lockHandle = desktop_journal_open($lockFile, 'journal_lock_unavailable');
        desktop_journal_lock($lockHandle, $deadline);
        if (!desktop_journal_identity($lockHandle, $lockFile)) { throw new RuntimeException('journal_lock_unavailable'); }
        $handle = desktop_journal_open($eventsFile, 'journal_unavailable');
        desktop_journal_lock($handle, $deadline);
        if (!desktop_journal_identity($handle, $eventsFile)) { throw new RuntimeException('journal_unavailable'); }
        $metadata = fstat($handle);
        if ($metadata['size'] > 67108864) { throw new RuntimeException('journal_too_large'); }
        $cutoff = time() - retention_days($settings) * 86400;
        $events = []; $lines = []; $sequence = 0; $bytes = 0; $physicalLines = 0; $changed = false;
        $readDeadline = microtime(true) + 5.0;
        while (!feof($handle)) {
            $line = fgets($handle, 262146);
            if ($line === false) { if (!feof($handle)) { throw new RuntimeException('journal_read_failed'); } break; }
            $bytes += strlen($line); $physicalLines++;
            if ($bytes > 67108864 || $physicalLines > 100000 || microtime(true) > $readDeadline) { throw new RuntimeException('journal_scan_limit'); }
            if (strlen($line) > 262144) { throw new RuntimeException('journal_line_too_large'); }
            if (trim($line) === '') { $changed = true; continue; }
            desktop_journal_memory(max(4194304, strlen($line) * 32));
            $event = json_decode($line, true, 64);
            if (!is_array($event) || json_last_error() !== JSON_ERROR_NONE || substr(ltrim($line), 0, 1) !== '{') { throw new RuntimeException('journal_corrupt'); }
            if (isset($event['created_at']) && !is_string($event['created_at'])) { throw new RuntimeException('journal_corrupt'); }
            $created = strtotime((string)($event['created_at'] ?? ''));
            if ($created !== false && $created < $cutoff) { $changed = true; continue; }
            $events[$sequence] = $event; $lines[$sequence] = rtrim($line, "\r\n") . "\n"; $sequence++;
            if (substr($line, -1) !== "\n") { $changed = true; }
            if (count($events) > RETENTION_MAX_EVENTS) { unset($events[$sequence - RETENTION_MAX_EVENTS - 1], $lines[$sequence - RETENTION_MAX_EVENTS - 1]); $changed = true; }
        }
        if (!desktop_journal_identity($handle, $eventsFile) || !desktop_journal_identity($lockHandle, $lockFile)) { throw new RuntimeException('journal_unavailable'); }
        if ($changed) {
            desktop_journal_memory(array_sum(array_map('strlen', $lines)) + 1048576);
            $normalized = implode('', $lines);
            if (!atomic_replace_journal($eventsFile, $normalized, null, $metadata)) { throw new RuntimeException('journal_persist_failed'); }
            unset($normalized);
        }
        $events = array_values($events);
        clearstatcache(true, $eventsFile);
        $metadata = @lstat($eventsFile);
        $cachedFingerprint = is_array($metadata) ? implode(':', [$metadata['dev'], $metadata['ino'], $metadata['size'], $metadata['mtime'], $metadata['ctime'], retention_days($settings)]) : null;
        $cachedEvents = $events; $cachedAt = time();
        return $events;
    } catch (RuntimeException $error) {
        desktop_journal_failure($error->getMessage());
        return [];
    } finally {
        if (is_resource($handle)) { @flock($handle, LOCK_UN); fclose($handle); }
        if (is_resource($lockHandle)) { @flock($lockHandle, LOCK_UN); fclose($lockHandle); }
    }
}

function desktop_stream_slot(string $username)
{
    $directory = dirname(EVENTS_FILE) . '/stream-slots';
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750))
        || realpath($directory) !== $directory) {
        respond(503, ['ok' => false, 'error' => 'stream_capacity_unavailable']);
    }
    $handles = [];
    foreach (['client-' . hash('sha256', $username) => 2, 'global' => 32] as $prefix => $limit) {
        $claimed = false;
        for ($slot = 0; $slot < $limit; $slot++) {
            $path = $directory . '/' . $prefix . '-' . $slot . '.lock';
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (is_array($before) && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) {
                continue;
            }
            $mask = umask(0027);
            try { $handle = @fopen($path, 'c+b'); } finally { umask($mask); }
            if ($handle !== false && desktop_journal_identity($handle, $path)
                && flock($handle, LOCK_EX | LOCK_NB) && desktop_journal_identity($handle, $path)) {
                $handles[] = $handle;
                $claimed = true;
                break;
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (!$claimed) {
            foreach ($handles as $handle) {
                fclose($handle);
            }
            header('Retry-After: 15');
            respond(429, [
                'ok' => false,
                'error' => 'stream_capacity_reached',
                'fallback' => [
                    'transport' => 'json_poll',
                    'path' => '/api/sipnotify/desktop',
                    'cursor_parameter' => 'last_event_id',
                    'poll_interval_seconds' => 5,
                ],
            ]);
        }
    }
    return $handles;
}

function desktop_stream_release_slots(array &$handles): void
{
    foreach ($handles as $handle) {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
    $handles = [];
}

function desktop_stream_flush(): void
{
    // Apache/FastCGI can hold small writes even after PHP flush(). Pad every
    // delivery/heartbeat batch, not only authentication, with a valid comment.
    // This also makes a disconnected client visible on the next heartbeat.
    echo ':' . str_repeat(' ', 8192) . "\n\n";
    @flush();
}

function announcement_display_expired(array $event, ?int $now = null): bool
{
    if (strtolower(trim((string)($event['kind'] ?? ''))) !== 'announcement') {
        return false;
    }
    $timeoutSeconds = min(86400, max(0, (int)($event['display_timeout_seconds'] ?? 0)));
    if ($timeoutSeconds === 0) {
        return false;
    }
    $expiresAt = trim((string)($event['display_expires_at'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }
    $expiresTimestamp = strtotime($expiresAt);
    if ($expiresTimestamp === false) {
        return false;
    }
    return $expiresTimestamp <= ($now ?? time());
}

function desktop_event_display_expired(array $event, ?int $now = null): bool
{
    if (strtolower(trim((string)($event['kind'] ?? ''))) !== 'alert') {
        return announcement_display_expired($event, $now);
    }
    if (strtolower(trim((string)($event['message_type'] ?? ''))) === 'cancel') {
        return true;
    }
    $expiresAt = trim((string)($event['expires'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }
    $expiresTimestamp = strtotime($expiresAt);
    return $expiresTimestamp !== false && $expiresTimestamp <= ($now ?? time());
}

function desktop_event_routed_to_client(array $event, string $username): bool
{
    // Legacy records without explicit desktop routing remain denied so one
    // desktop cannot read another client's history.
    if (!array_key_exists('desktop_all', $event) && !array_key_exists('desktop_recipients', $event)) {
        return false;
    }
    $recipients = is_array($event['desktop_recipients'] ?? null) ? $event['desktop_recipients'] : [];
    return !empty($event['desktop_all']) || in_array($username, $recipients, true);
}

function desktop_event_public_payload(array $event, array $settings): array
{
    // Old retained records predate the explicit flag. Only boolean true marks
    // a test; wording and truthy strings must never change alert semantics.
    $event['is_test'] = ($event['is_test'] ?? false) === true;
    // Only the unguessable, generated announcement image is public. Preserve
    // phone XML/URLs and all plain text, and never rewrite arbitrary URLs.
    $host = $settings['public_pbx_host'] ?? ($settings['sipnotify']['pbx_host'] ?? '');
    $url = $event['image_url'] ?? '';
    if (!is_string($host) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/D', $host)
        || !is_string($url)) { return $event; }
    $host = strtolower($host);
    $parts = [];
    if (!preg_match('#\Ahttps?://' . preg_quote($host, '#')
        . '(?::([0-9]{1,5}))?(/sls_mass_notify/announcement_[0-9]{14}_[a-f0-9]{6,32}\.png)\z#iD', $url, $parts)
        || ($parts[1] !== '' && ((int)$parts[1] < 1 || (int)$parts[1] > 65535))) { return $event; }

    $authority = $host;
    $sip = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
    foreach (['base_url' => '/api/sipnotify', 'media_base_url' => '/sls_mass_notify'] as $key => $path) {
        $configured = $sip[$key] ?? '';
        $matches = [];
        if (is_string($configured) && preg_match('#\Ahttps://' . preg_quote($host, '#')
            . '(?::([0-9]{1,5}))?' . preg_quote($path, '#') . '/?\z#iD', $configured, $matches)
            && (!isset($matches[1]) || ((int)$matches[1] >= 1 && (int)$matches[1] <= 65535))) {
            $authority = $host . (isset($matches[1]) ? ':' . (int)$matches[1] : '');
            break;
        }
    }
    // Host retains the external port through ordinary port forwarding. Neither
    // SERVER_PORT (the backend listener) nor untrusted proxy headers do that.
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    $matches = [];
    if (is_string($requestHost) && preg_match('#\A' . preg_quote($host, '#')
        . '(?::([0-9]{1,5}))?\z#iD', $requestHost, $matches)
        && (!isset($matches[1]) || ((int)$matches[1] >= 1 && (int)$matches[1] <= 65535))) {
        $authority = $host . (isset($matches[1]) ? ':' . (int)$matches[1] : '');
    }
    $event['image_url'] = 'https://' . $authority . $parts[2];
    return $event;
}

function desktop_stream_events_after_cursor(
    array $events,
    string $username,
    string $lastEventId,
    ?int $now = null
): array {
    $routedEvents = [];
    $latestAlertIds = [];
    foreach ($events as $event) {
        if (!is_array($event) || !desktop_event_routed_to_client($event, $username)) {
            continue;
        }
        $eventId = trim((string)($event['id'] ?? $event['event_id'] ?? ''));
        if ($eventId !== '') {
            $routedEvents[] = [$eventId, $event];
            $chainKey = trim((string)($event['chain_key'] ?? ''));
            if (($event['kind'] ?? '') === 'alert' && $chainKey !== '') {
                $latestAlertIds[$chainKey] = $eventId;
            }
        }
    }

    $nextEvents = [];
    if ($lastEventId === '') {
        $lastEventId = '@sls:empty';
        if (!empty($routedEvents)) {
            $lastEventId = (string)$routedEvents[count($routedEvents) - 1][0];
        }
        return ['last_event_id' => $lastEventId, 'events' => $nextEvents];
    }

    $lastIndex = -1;
    foreach ($routedEvents as $index => $routedEvent) {
        if (hash_equals($lastEventId, (string)$routedEvent[0])) {
            $lastIndex = (int)$index;
        }
    }
    $cursorGap = $lastIndex < 0 && $lastEventId !== '@sls:empty';

    foreach (array_slice($routedEvents, $lastIndex + 1) as $routedEvent) {
        [$eventId, $event] = $routedEvent;
        // Advance across every routed journal record, including expired and
        // superseded alerts. Otherwise an expired cursor can disappear from the
        // visible set and cause the following live event to be skipped.
        $lastEventId = (string)$eventId;
        $chainKey = trim((string)($event['chain_key'] ?? ''));
        if (desktop_event_display_expired($event, $now)
            || (($event['kind'] ?? '') === 'alert' && $chainKey !== ''
                && ($latestAlertIds[$chainKey] ?? $eventId) !== $eventId)) {
            continue;
        }
        $nextEvents[] = [$eventId, $event];
    }

    return ['last_event_id' => $lastEventId, 'events' => $nextEvents, 'cursor_gap' => $cursorGap];
}

function desktop_poll_events_after_cursor(array $events, string $username, string $lastEventId, int $limit, ?int $now = null): array
{
    $batch = desktop_stream_events_after_cursor($events, $username, $lastEventId, $now);
    $limit = min(MAX_LIMIT, max(1, $limit));
    $hasMore = count($batch['events']) > $limit;
    $selected = array_slice($batch['events'], 0, $limit);
    // If this page is full, resume after the last returned record. Advancing to
    // the journal tail here would silently skip the rest of the backlog.
    if ($hasMore) {
        $batch['last_event_id'] = $selected[count($selected) - 1][0];
    }
    return [
        'events' => array_column($selected, 1),
        'last_event_id' => (string)$batch['last_event_id'],
        'cursor_gap' => !empty($batch['cursor_gap']),
        'has_more' => $hasMore,
        'eligible_count' => count($batch['events']),
        'window_truncated' => false,
    ];
}

function desktop_poll_snapshot(array $events, string $username, int $limit, ?int $now = null): array
{
    $batch = desktop_stream_events_after_cursor($events, $username, '@sls:empty', $now);
    $limit = min(MAX_LIMIT, max(1, $limit));
    return [
        'events' => array_slice(array_column($batch['events'], 1), -$limit),
        'last_event_id' => (string)$batch['last_event_id'],
        'cursor_gap' => false,
        // A snapshot returns the newest window, so advancing its tail cursor
        // cannot recover omitted earlier records. Keep has_more reserved for
        // forward pagination and expose snapshot truncation separately.
        'has_more' => false,
        'eligible_count' => count($batch['events']),
        'window_truncated' => count($batch['events']) > $limit,
    ];
}

function record_desktop_acknowledgement(array $settings, array $client, array $event, ?int $now = null): bool
{
    $username = (string)($client['username'] ?? '');
    $clientId = (string)($client['client_id'] ?? '');
    $eventId = (string)($event['id'] ?? $event['event_id'] ?? '');
    if ($username === '' || $eventId === '' || strlen($eventId) > 1024) {
        return false;
    }
    $directory = DESKTOP_ACK_DIRECTORY;
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750) && !is_dir($directory))) {
        return false;
    }
    $directoryMetadata = @lstat($directory);
    if (!$directoryMetadata || ($directoryMetadata['mode'] & 0170000) !== 0040000
        || ($directoryMetadata['mode'] & 0022) || realpath($directory) !== $directory) {
        return false;
    }
    $path = $directory . '/' . hash('sha256', $clientId !== '' ? 'id:' . $clientId : 'user:' . $username) . '.json';
    $lockPath = $path . '.lock';
    if (is_link($path) || is_link($lockPath)) {
        return false;
    }
    $lockHandle = @fopen($lockPath, 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        return false;
    }
    try {
        $metadata = fstat($lockHandle);
        $pathMetadata = @lstat($lockPath);
        if (!is_array($metadata) || !is_array($pathMetadata)
            || ($metadata['mode'] & 0170000) !== 0100000 || $metadata['nlink'] !== 1
            || $metadata['ino'] !== $pathMetadata['ino'] || $metadata['dev'] !== $pathMetadata['dev']) {
            return false;
        }
        @chmod($lockPath, 0640);
        $acknowledgements = [];
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            $metadata = @lstat($path);
            if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0100000
                || $metadata['nlink'] !== 1 || $metadata['size'] > 2097152) {
                return false;
            }
            $raw = @file_get_contents($path);
            $stored = is_string($raw) ? json_decode($raw, true) : null;
            // Preserve unreadable/corrupt history for diagnosis instead of
            // silently replacing it with a success-shaped empty store.
            if (!is_array($stored) || ($stored['schema'] ?? null) !== 1
                || !is_array($stored['acknowledgements'] ?? null)) {
                return false;
            }
            $acknowledgements = $stored['acknowledgements'];
            foreach ($acknowledgements as &$entry) {
                if (is_array($entry)) {
                    $entry['username'] = $entry['username'] ?? (string)($stored['username'] ?? '');
                    $entry['client_id'] = $entry['client_id'] ?? (string)($stored['client_id'] ?? '');
                }
            }
            unset($entry);
        }
        $now = $now ?? time();
        $cutoff = $now - retention_days($settings) * 86400;
        $acknowledgements = array_filter($acknowledgements, static function ($entry) use ($cutoff): bool {
            return is_array($entry) && strtotime((string)($entry['ack_at'] ?? '')) >= $cutoff;
        });
        $key = hash('sha256', $eventId);
        if (isset($acknowledgements[$key]) && (!is_array($acknowledgements[$key])
            || ($acknowledgements[$key]['event_id'] ?? null) !== $eventId
            || ($acknowledgements[$key]['username'] ?? null) !== $username
            || ($acknowledgements[$key]['client_id'] ?? null) !== $clientId)) {
            return false;
        }
        if (!isset($acknowledgements[$key])) {
            $acknowledgements[$key] = [
                'event_id' => $eventId,
                'username' => $username,
                'client_id' => $clientId,
                'kind' => (string)($event['kind'] ?? ''),
                'event_created_at' => (string)($event['created_at'] ?? ''),
                'ack_at' => gmdate('c', $now),
                // This is a client software receipt, never human confirmation.
                'receipt_type' => 'client_acknowledgement',
            ];
        }
        $acknowledgements = array_slice($acknowledgements, -RETENTION_MAX_EVENTS, null, true);
        $encoded = json_encode([
            'schema' => 1,
            'client_id' => $clientId,
            'username' => $username,
            'acknowledgements' => $acknowledgements,
        ], JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && atomic_replace_journal($path, $encoded . "\n");
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

$endpoint = endpoint_slug();
if (!in_array($endpoint, ['desktop', 'desktop/stream', 'desktop/ack', 'desktop/incident', 'desktop/incident/respond'], true)) {
    respond(404, ['ok' => false, 'error' => 'unknown_endpoint']);
}
$settings = settings_config();
$GLOBALS['sls_request_network'] = \SLS\MassNotify\ApiSecurity::requestNetwork($_SERVER, $settings);
$network = $GLOBALS['sls_request_network'];
if ($network['error'] !== '') {
    respond($network['error'] === 'invalid_proxy_configuration' ? 503 : 400, ['ok' => false, 'error' => $network['error']]);
}
$remoteIp = $network['ip'];
$https = $network['https'];
if (!$https && !$network['loopback']) {
    respond(426, ['ok' => false, 'error' => 'https_required']);
}
if ((string)($_SERVER['REQUEST_METHOD'] ?? '') !== (in_array($endpoint, ['desktop/ack', 'desktop/incident/respond'], true) ? 'POST' : 'GET')) {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

[$basicUser, $basicPass] = basic_credentials();
if (!desktop_auth_failure_budget_available($remoteIp, $basicUser)) {
    if (!empty($GLOBALS['sls_api_state_error'])) {
        header('Retry-After: 2');
        respond(503, ['ok' => false, 'error' => 'rate_limit_storage_unavailable',
            'reason' => $GLOBALS['sls_api_state_error'], 'retryable' => true]);
    }
    $retryAfter = desktop_rate_retry_after();
    header('Retry-After: ' . $retryAfter);
    respond(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after_seconds' => $retryAfter]);
}
$client = authorized_desktop_client($settings, $basicUser, $basicPass);
if (!desktop_auth_attempt_allowed($remoteIp, $basicUser, !empty($client), null, false, desktop_authenticated_capacity($settings))) {
    if (!empty($GLOBALS['sls_api_state_error'])) {
        header('Retry-After: 2');
        respond(503, ['ok' => false, 'error' => 'rate_limit_storage_unavailable',
            'reason' => $GLOBALS['sls_api_state_error'], 'retryable' => true]);
    }
    $retryAfter = desktop_rate_retry_after();
    header('Retry-After: ' . $retryAfter);
    respond(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after_seconds' => $retryAfter]);
}
if (empty($client)) {
    header('WWW-Authenticate: Basic realm="SLS Mass Notify Desktop"');
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

update_desktop_seen($client, [], $settings);
$username = (string)$client['username'];
if ($endpoint === 'desktop/incident' || $endpoint === 'desktop/incident/respond') {
    $id = is_string($_GET['incident_id'] ?? null) ? $_GET['incident_id'] : '';
    $input = [];
    if ($endpoint === 'desktop/incident/respond') {
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) { respond(415, ['ok' => false, 'error' => 'content_type_must_be_json']); }
        $raw = (string)file_get_contents('php://input', false, null, 0, 8193);
        if (strlen($raw) > 8192) { respond(413, ['ok' => false, 'error' => 'request_too_large']); }
        $input = json_decode($raw, true, 16);
        if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['incident_id', 'request_id', 'response', 'note'])) {
            respond(400, ['ok' => false, 'error' => 'invalid_incident_response']);
        }
        $id = is_string($input['incident_id'] ?? null) ? $input['incident_id'] : ''; unset($input['incident_id']);
    }
    if (!preg_match('/^inc_[a-f0-9]{32}$/D', $id)) { respond(400, ['ok' => false, 'error' => 'invalid_incident_id']); }
    try {
        // These values come exclusively from the row that passed Basic auth.
        // A password rotation or username reassignment during PBX bootstrap
        // must not turn this request into another desktop's authority.
        $authenticatedIdentity = ['client_id' => (string)($client['client_id'] ?? ''),
            'credential_fingerprint' => hash('sha256', (string)($client['password_enc'] ?? ''))];
        $module = desktop_incident_module();
        $result = $endpoint === 'desktop/incident' ? $module->getIncidentForDesktop($id, $username, $authenticatedIdentity)
            : $module->respondToIncident($id, $username, $input, $authenticatedIdentity);
        $status = ($result['error_code'] ?? '') === 'incident_storage_unavailable' ? 503 : (!empty($result['success']) ? 200 : 400);
        if ($status === 503) { header('Retry-After: 5'); }
        respond($status, ['ok' => !empty($result['success'])] + $result);
    } catch (\Throwable $error) {
        header('Retry-After: 5'); respond(503, ['ok' => false, 'error' => 'incident_backend_unavailable', 'retryable' => true]);
    }
}
if ($endpoint === 'desktop/ack') {
    $raw = (string)file_get_contents('php://input', false, null, 0, 4097);
    $ack = strlen($raw) <= 4096 ? json_decode($raw, true) : null;
    $eventId = is_array($ack) && is_string($ack['event_id'] ?? null) ? $ack['event_id'] : '';
    foreach (retained_events($settings) as $event) {
        if ($eventId !== '' && hash_equals((string)($event['id'] ?? $event['event_id'] ?? ''), $eventId)
            && desktop_event_routed_to_client($event, $username)) {
            if (!record_desktop_acknowledgement($settings, $client, $event)) {
                header('Retry-After: 5');
                respond(503, ['ok' => false, 'error' => 'acknowledgement_persist_failed', 'retryable' => true]);
            }
            update_desktop_seen($client, ['ack_event_id' => $eventId, 'ack_at' => gmdate('c')], $settings);
            respond(200, ['ok' => true, 'event_id' => $eventId, 'receipt_type' => 'client_acknowledgement']);
        }
    }
    respond(400, ['ok' => false, 'error' => 'unknown_or_unauthorized_event']);
}
$streamRequested = $endpoint === 'desktop/stream'
    || (string)($_GET['stream'] ?? '') === '1'
    || stripos(request_header_value('Accept'), 'text/event-stream') !== false;

if ($streamRequested) {
    $streamSlots = desktop_stream_slot($username);
    register_shutdown_function(static function () use (&$streamSlots): void {
        desktop_stream_release_slots($streamSlots);
    });
    @set_time_limit(0);
	ignore_user_abort(false);
	@ini_set('zlib.output_compression', '0');
	@ini_set('output_buffering', '0');
	if (function_exists('apache_setenv')) {
		@apache_setenv('no-gzip', '1');
	}
	ob_implicit_flush(true);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) {
            respond(503, ['ok' => false, 'error' => 'stream_output_buffer_unavailable']);
        }
    }
    $lastEventId = trim(request_header_value('Last-Event-ID'));
    if ($lastEventId === '') {
        $lastEventId = trim((string)($_GET['last_event_id'] ?? ''));
    }
	$sessionId = bin2hex(random_bytes(16));
    // Establish the baseline before the handshake; later arrivals, including
    // the first targeted record ever written, must be emitted by the loop.
    if ($lastEventId === '') {
        $baseline = desktop_stream_events_after_cursor(retained_events($settings), $username, '');
        $lastEventId = $baseline['last_event_id'];
    }
    echo "retry: 1000\n";
    echo 'id: ' . str_replace(["\r", "\n"], '', $lastEventId) . "\n";
    echo "event: authenticated\n";
    echo 'data: ' . json_encode(['ok' => true, 'transport' => 'live_sse', 'protocol_version' => 2, 'ack_path' => '/api/sipnotify/desktop/ack', 'session_id' => $sessionId, 'client_id' => (string)($client['client_id'] ?? ''), 'name' => (string)($client['name'] ?? ''), 'heartbeat_seconds' => 15, 'connected_at' => gmdate('c')], JSON_UNESCAPED_SLASHES) . "\n\n";
    desktop_stream_flush();
    $started = time();
	$streamSeconds = min(300, max(1, (int)($_GET['stream_seconds'] ?? 300)));
    $lastKeepalive = 0;
    $lastAuthCheck = 0;
    while (!connection_aborted() && time() - $started < $streamSeconds) {
        if (time() - $lastAuthCheck >= 5) {
            $settings = settings_config();
            if (empty(authorized_desktop_client($settings, $basicUser, $basicPass))) {
                echo "event: revoked\ndata: {\"ok\":false,\"error\":\"credentials_revoked\"}\n\n";
                desktop_stream_flush();
                break;
            }
            $lastAuthCheck = time();
        }
		$streamBatch = desktop_stream_events_after_cursor(
			retained_events($settings),
			$username,
			$lastEventId
		);
        if (!empty($streamBatch['cursor_gap'])) {
            echo "event: cursor_reset\ndata: {\"reason\":\"history_gap\",\"replay\":true}\n\n";
        }
		$hasOutput = !empty($streamBatch['cursor_gap']);
		foreach ($streamBatch['events'] as $streamEvent) {
			[$eventId, $event] = $streamEvent;
			$event = desktop_event_public_payload($event, $settings);
			$hasOutput = true;
			echo 'id: ' . str_replace(["\r", "\n"], '', (string)$eventId) . "\n";
			echo "event: notification\n";
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n\n";
		}
		$lastEventId = (string)$streamBatch['last_event_id'];
        if (time() - $lastKeepalive >= 15) {
            update_desktop_seen($client, ['connected_until' => time() + 30], $settings);
            echo ': keepalive ' . time() . "\n\n";
            $lastKeepalive = time();
            $hasOutput = true;
        }
        if ($hasOutput) { desktop_stream_flush(); }
        usleep(500000);
    }
    echo 'event: reconnect' . "\n" . 'data: ' . json_encode(['ok' => true, 'session_id' => $sessionId], JSON_UNESCAPED_SLASHES) . "\n\n";
    desktop_stream_flush();
    desktop_stream_release_slots($streamSlots);
    exit;
}

$limit = min(MAX_LIMIT, max(1, (int)($_GET['limit'] ?? DEFAULT_LIMIT)));
$resumeCursor = trim(request_header_value('Last-Event-ID'));
$cursorRequested = $resumeCursor !== '' || array_key_exists('last_event_id', $_GET);
if ($resumeCursor === '') {
    $resumeCursor = trim((string)($_GET['last_event_id'] ?? ''));
}
$journalEvents = retained_events($settings);
if ($cursorRequested) {
    $pollBatch = desktop_poll_events_after_cursor($journalEvents, $username, $resumeCursor, $limit);
    $events = $pollBatch['events'];
} else {
    // Preserve the legacy JSON history response while applying the same expiry,
    // cancellation, and routing rules as resumable SSE and JSON requests.
    $pollBatch = desktop_poll_snapshot($journalEvents, $username, $limit);
    $events = $pollBatch['events'];
}
$events = array_map(static function (array $event) use ($settings): array {
    return desktop_event_public_payload($event, $settings);
}, $events);
$latest = empty($events) ? null : $events[count($events) - 1];

$publicHost = trim((string)($settings['public_pbx_host'] ?? ''));
respond(200, [
    'ok' => true,
    'source' => $publicHost !== '' ? $publicHost : 'localhost',
    'client' => [
        'client_id' => (string)($client['client_id'] ?? ''),
        'name' => (string)($client['name'] ?? ''),
    ],
    'count' => count($events),
    'latest' => $latest,
    'events' => $events,
    'transport' => 'json_poll',
    'last_event_id' => (string)$pollBatch['last_event_id'],
    'cursor_gap' => (bool)$pollBatch['cursor_gap'],
    'has_more' => (bool)$pollBatch['has_more'],
    'eligible_count' => (int)$pollBatch['eligible_count'],
    'window_truncated' => (bool)$pollBatch['window_truncated'],
    'limit' => $limit,
    'cursor_parameter' => 'last_event_id',
    'poll_interval_seconds' => 5,
]);
