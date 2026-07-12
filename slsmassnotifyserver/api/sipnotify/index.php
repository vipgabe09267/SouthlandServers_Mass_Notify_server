<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
declare(strict_types=1);

const EVENTS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl';
const SETTINGS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const DESKTOP_LAST_SEEN_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/desktop-last-seen.json';
const DESKTOP_AUTH_RATE_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/desktop-auth-ratelimit.json';
const DEFAULT_LIMIT = 25;
const MAX_LIMIT = 100;
const RETENTION_MAX_EVENTS = 1000;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
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

function settings_config(): array
{
	if (!is_readable(SETTINGS_FILE)) {
		respond(503, ['ok' => false, 'error' => 'config_unavailable']);
	}
	$raw = file_get_contents(SETTINGS_FILE);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        respond(503, ['ok' => false, 'error' => 'config_invalid']);
    }
    return $decoded;
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
        $password = decrypt_desktop_password((string)($client['password_enc'] ?? ''), $settings);
        if ($username !== '' && $password !== '' && hash_equals($username, $providedUser) && hash_equals($password, $providedPass)) {
            return $client;
        }
    }
    return [];
}

function desktop_auth_failure_allowed(string $ip): bool
{
    $handle = @fopen(DESKTOP_AUTH_RATE_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }
    rewind($handle);
    $decoded = json_decode((string)stream_get_contents($handle), true);
    $data = is_array($decoded) ? $decoded : [];
    $bucket = gmdate('YmdHi');
    $data = array_filter($data, static function ($entry) use ($bucket): bool {
        return is_array($entry) && (string)($entry['bucket'] ?? '') === $bucket;
    });
    $key = hash('sha256', $ip !== '' ? $ip : 'unknown');
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
    @chmod(DESKTOP_AUTH_RATE_FILE, 0640);
    return $entry['count'] <= 10;
}

function update_desktop_seen(array $client): void
{
    $username = (string)($client['username'] ?? '');
    if ($username === '') {
        return;
    }
    $handle = @fopen(DESKTOP_LAST_SEEN_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return;
    }
    rewind($handle);
    $decoded = json_decode((string)stream_get_contents($handle), true);
    $data = is_array($decoded) ? $decoded : [];
    $data[$username] = [
        'seen_at' => gmdate('c'),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'client_id' => (string)($client['client_id'] ?? ''),
        'name' => (string)($client['name'] ?? ''),
    ];
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod(DESKTOP_LAST_SEEN_FILE, 0640);
}

function retention_days(array $settings): int
{
    return min(365, max(1, (int)($settings['log_retention_days'] ?? 90)));
}

function retained_events(array $settings): array
{
    $handle = @fopen(EVENTS_FILE, 'c+');
    if ($handle === false) {
        respond(503, ['ok' => false, 'error' => 'journal_unavailable']);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        respond(503, ['ok' => false, 'error' => 'journal_locked']);
    }
    rewind($handle);
    $raw = (string)stream_get_contents($handle);
    $cutoff = time() - (retention_days($settings) * 86400);
    $events = [];
    foreach (preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            continue;
        }
        $created = strtotime((string)($event['created_at'] ?? ''));
        if ($created !== false && $created < $cutoff) {
            continue;
        }
        $events[] = $event;
    }
    $events = array_slice($events, -RETENTION_MAX_EVENTS);
    $normalized = implode("\n", array_map(static function (array $event): string {
        return (string)json_encode($event, JSON_UNESCAPED_SLASHES);
    }, $events));
    $normalized .= $normalized === '' ? '' : "\n";
    if ($normalized !== $raw) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $normalized);
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod(EVENTS_FILE, 0640);
    return $events;
}

$endpoint = endpoint_slug();
if ($endpoint !== 'desktop') {
    respond(404, ['ok' => false, 'error' => 'unknown_endpoint']);
}
$remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
if (!$https && !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    respond(426, ['ok' => false, 'error' => 'https_required']);
}
if ((string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$settings = settings_config();
[$basicUser, $basicPass] = basic_credentials();
$client = authorized_desktop_client($settings, $basicUser, $basicPass);
if (empty($client)) {
    if (!desktop_auth_failure_allowed((string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
        respond(429, ['ok' => false, 'error' => 'rate_limited']);
    }
    header('WWW-Authenticate: Basic realm="SLS Mass Notify Desktop"');
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

update_desktop_seen($client);
$username = (string)$client['username'];
$limit = min(MAX_LIMIT, max(1, (int)($_GET['limit'] ?? DEFAULT_LIMIT)));
$events = [];
foreach (retained_events($settings) as $event) {
    // New records always contain explicit desktop routing. Legacy records are
    // denied by default so one desktop cannot read another client's history.
    if (!array_key_exists('desktop_all', $event) && !array_key_exists('desktop_recipients', $event)) {
        continue;
    }
    $desktopAll = !empty($event['desktop_all']);
    $recipients = is_array($event['desktop_recipients'] ?? null) ? $event['desktop_recipients'] : [];
    if (!$desktopAll && !in_array($username, $recipients, true)) {
        continue;
    }
    $events[] = $event;
}
$events = array_slice($events, -$limit);
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
]);
