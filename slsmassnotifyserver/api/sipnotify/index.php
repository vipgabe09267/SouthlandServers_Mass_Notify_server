<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group
declare(strict_types=1);

const EVENTS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl';
const SETTINGS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
const LEGACY_SETTINGS_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications-' . 'settings.json';
const DESKTOP_LAST_SEEN_FILE = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/desktop-last-seen.json';
const MAX_LIMIT = 100;
const DEFAULT_LIMIT = 25;
const RETENTION_MAX_EVENTS = 1000;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit;
}

function bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function basic_credentials(): array
{
    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user !== '' || $pass !== '') {
        return [$user, $pass];
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Basic\s+(.+)$/i', trim((string)$header), $matches)) {
        $decoded = base64_decode($matches[1], true);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            return explode(':', $decoded, 2);
        }
    }
    return ['', ''];
}

function endpoint_slug(): string
{
    $path = (string)($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $prefix = '/api/sipnotify';
        if (strpos($uriPath, $prefix) === 0) {
            $path = substr($uriPath, strlen($prefix));
        }
    }
    $slug = trim($path, "/ \t\n\r\0\x0B");
    if ($slug === '') {
        return 'desktop';
    }
    $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $slug));
    return trim($slug, '-') ?: 'yealink';
}

function settings_config(): array
{
    $path = is_readable(SETTINGS_FILE) ? SETTINGS_FILE : LEGACY_SETTINGS_FILE;
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

function sipnotify_config(array $settings): array
{
    return is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
}

function endpoint_config(string $slug, array $settings): ?array
{
    $config = sipnotify_config($settings);
    $endpoints = is_array($config['endpoints'] ?? null) ? $config['endpoints'] : [];
    if (empty($endpoints) && $slug === 'desktop') {
        return ['slug' => 'desktop', 'brand' => 'SLS Mass Notify Desktop App', 'enabled' => '1', 'auth_type' => 'desktop'];
    }
    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }
        if (($endpoint['slug'] ?? '') === $slug) {
            return $endpoint;
        }
    }
    return null;
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
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function authorized_desktop_username(array $settings, string $basicUser, string $basicPass): string
{
    $clients = is_array($settings['desktop_clients'] ?? null) ? $settings['desktop_clients'] : [];
    foreach ($clients as $client) {
        if (!is_array($client) || empty($client['enabled'])) {
            continue;
        }
        $username = (string)($client['username'] ?? '');
        $password = decrypt_desktop_password((string)($client['password_enc'] ?? ''), $settings);
        if ($username !== '' && $password !== '' && hash_equals($username, $basicUser) && hash_equals($password, $basicPass)) {
            return $username;
        }
    }
    return '';
}

function desktop_client_by_username(array $settings, string $username): array
{
    foreach ((array)($settings['desktop_clients'] ?? []) as $client) {
        if (is_array($client) && (string)($client['username'] ?? '') === $username) {
            return $client;
        }
    }
    return [];
}

function update_desktop_seen(array $settings, string $username): void
{
    if ($username === '') {
        return;
    }
    $client = desktop_client_by_username($settings, $username);
    $data = [];
    if (is_readable(DESKTOP_LAST_SEEN_FILE)) {
        $decoded = json_decode((string)file_get_contents(DESKTOP_LAST_SEEN_FILE), true);
        $data = is_array($decoded) ? $decoded : [];
    }
    $data[$username] = [
        'seen_at' => gmdate('c'),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'client_id' => (string)($client['client_id'] ?? ''),
        'name' => (string)($client['name'] ?? ''),
    ];
    $dir = dirname(DESKTOP_LAST_SEEN_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(DESKTOP_LAST_SEEN_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod(DESKTOP_LAST_SEEN_FILE, 0660);
}

function x(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function compact_text(string $value, int $limit = 700): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if (strlen($value) <= $limit) {
        return $value;
    }
    return substr($value, 0, $limit - 3) . '...';
}

function record_title(array $record): string
{
    $title = trim((string)($record['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    return trim((string)($record['event'] ?? 'Mass Notification')) ?: 'Mass Notification';
}

function record_message(array $record): string
{
    foreach (['body', 'text', 'description', 'message'] as $key) {
        $value = trim((string)($record[$key] ?? ''));
        if ($value !== '') {
            return compact_text($value);
        }
    }
    return record_title($record);
}

function yealink_xml(array $record): string
{
    if (!empty($record['xml']) && strpos((string)$record['xml'], 'YealinkIPPhoneImageScreen') !== false) {
        return (string)$record['xml'];
    }
    $title = record_title($record);
    $message = wordwrap(record_message($record), 32, "\n", true);
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . "<YealinkIPPhoneTextScreen Beep='yes' Timeout='0'>"
        . '<Title>' . x($title) . '</Title>'
        . '<Text>' . str_replace("\n", '&#10;', x($message)) . '</Text>'
        . '<SoftKey index="1"><Label>Dismiss</Label><URI>SoftKey:Exit</URI></SoftKey>'
        . '</YealinkIPPhoneTextScreen>';
}

function cisco_xml(array $record): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<CiscoIPPhoneText>'
        . '<Title>' . x(record_title($record)) . '</Title>'
        . '<Prompt>Mass Notification</Prompt>'
        . '<Text>' . x(record_message($record)) . '</Text>'
        . '</CiscoIPPhoneText>';
}

function snom_xml(array $record): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SnomIPPhoneText>'
        . '<Title>' . x(record_title($record)) . '</Title>'
        . '<Text>' . x(record_message($record)) . '</Text>'
        . '</SnomIPPhoneText>';
}

function polycom_xml(array $record): string
{
    return '<PolycomIPPhone><Data priority="critical"><h1>'
        . x(record_title($record))
        . '</h1><p>'
        . x(record_message($record))
        . '</p></Data></PolycomIPPhone>';
}

function grandstream_xml(array $record): string
{
    $title = record_title($record);
    $message = record_message($record);
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<xmlapp title="' . x($title) . '">'
        . '<view><section>'
        . '<text label="' . x($title) . '"></text>'
        . '<text label="' . x($message) . '"></text>'
        . '</section></view>'
        . '<Softkeys><Softkey action="QuitApp" label="Exit"/></Softkeys>'
        . '</xmlapp>';
}

function aastra_xml(array $record): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<AastraIPPhoneTextScreen destroyOnExit="yes" Beep="yes">'
        . '<Title>' . x(record_title($record)) . '</Title>'
        . '<Text>' . x(record_message($record)) . '</Text>'
        . '</AastraIPPhoneTextScreen>';
}

function generic_phone_xml(array $record): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<MassNotification>'
        . '<Title>' . x(record_title($record)) . '</Title>'
        . '<Text>' . x(record_message($record)) . '</Text>'
        . '<Priority>' . x((string)($record['priority'] ?? 'notice')) . '</Priority>'
        . '</MassNotification>';
}

function endpoint_format(string $slug): array
{
    $formats = [
        'desktop' => ['json', 'application/json'],
        'yealink' => ['yealink_xml_browser', 'text/xml'],
        'cisco' => ['cisco_ip_phone_text', 'text/xml'],
        'polycom' => ['polycom_push', 'application/x-com-polycom-spipx'],
        'snom' => ['snom_minibrowser_text', 'text/xml'],
        'grandstream' => ['grandstream_xmlapp', 'text/xml'],
        'sangoma' => ['sangoma_generic_xml', 'text/xml'],
        'fanvil' => ['fanvil_cisco_compatible_text', 'text/xml'],
        'mitel' => ['mitel_aastra_text_screen', 'text/xml'],
        'avaya' => ['avaya_generic_xml', 'text/xml'],
        'vtech' => ['vtech_generic_xml', 'text/xml'],
        'ale' => ['ale_generic_xml', 'text/xml'],
    ];
    return $formats[$slug] ?? ['generic_xml', 'text/xml'];
}

function retention_days(array $settings): int
{
    $days = (int)($settings['log_retention_days'] ?? 90);
    if ($days < 1) {
        $days = 90;
    }
    return min(365, max(1, $days));
}

function endpoint_xml(string $slug, array $record): string
{
    if ($slug === 'yealink') {
        return yealink_xml($record);
    }
    if ($slug === 'cisco') {
        return cisco_xml($record);
    }
    if ($slug === 'polycom') {
        return polycom_xml($record);
    }
    if ($slug === 'snom') {
        return snom_xml($record);
    }
    if ($slug === 'grandstream') {
        return grandstream_xml($record);
    }
    if ($slug === 'fanvil') {
        return cisco_xml($record);
    }
    if ($slug === 'mitel') {
        return aastra_xml($record);
    }
    return generic_phone_xml($record);
}

function format_record_for_endpoint(array $record, string $slug, array $endpoint): array
{
    [$format, $contentType] = endpoint_format($slug);
    $record['endpoint_slug'] = $slug;
    $record['endpoint_brand'] = (string)($endpoint['brand'] ?? '');
    $record['payload_format'] = $format;
    $record['content_type'] = $contentType;
    if ($slug !== 'desktop') {
        $record['xml'] = endpoint_xml($slug, $record);
        $record['image_url'] = '';
    }
    return $record;
}

$settings = settings_config();
$endpointSlug = endpoint_slug();
$endpoint = endpoint_config($endpointSlug, $settings);
if ($endpoint === null) {
    respond(404, ['ok' => false, 'error' => 'unknown_endpoint', 'endpoint' => $endpointSlug]);
}
if (empty($endpoint['enabled'])) {
    respond(403, ['ok' => false, 'error' => 'endpoint_disabled', 'endpoint' => $endpointSlug]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$authorized = false;
$authorizedDesktopUsername = '';
$expectedToken = trim((string)($settings['desktop_api_token'] ?? ''));
$providedToken = bearer_token();
if ($endpointSlug !== 'desktop' && ($endpoint['auth_type'] ?? '') === 'token' && $expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
    $authorized = true;
}

[$basicUser, $basicPass] = basic_credentials();
$desktopUsername = '';
if ($endpointSlug === 'desktop') {
    $desktopUsername = authorized_desktop_username($settings, $basicUser, $basicPass);
    if ($desktopUsername !== '') {
        $authorized = true;
        $authorizedDesktopUsername = $desktopUsername;
    }
}
$expectedUser = (string)($endpoint['username'] ?? '');
$expectedPass = (string)($endpoint['password'] ?? '');
if ($endpointSlug !== 'desktop' && ($endpoint['auth_type'] ?? 'basic') === 'basic' && $expectedUser !== '' && $expectedPass !== '' && hash_equals($expectedUser, $basicUser) && hash_equals($expectedPass, $basicPass)) {
    $authorized = true;
}

if (!$authorized) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}
if ($endpointSlug === 'desktop' && $authorizedDesktopUsername !== '') {
    update_desktop_seen($settings, $authorizedDesktopUsername);
}

$limit = (int)($_GET['limit'] ?? DEFAULT_LIMIT);
if ($limit < 1) {
    $limit = DEFAULT_LIMIT;
}
$limit = min($limit, MAX_LIMIT);

$events = [];
if (is_readable(EVENTS_FILE)) {
	$lines = file(EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (is_array($lines)) {
		$cutoff = time() - (retention_days($settings) * 86400);
		$retained = [];
		foreach ($lines as $line) {
			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				continue;
			}
			$created = strtotime((string)($decoded['created_at'] ?? '')) ?: time();
			if ($created >= $cutoff) {
				if ($endpointSlug === 'desktop' && $authorizedDesktopUsername !== '') {
					$desktopAll = !empty($decoded['desktop_all']);
					$desktopRecipients = is_array($decoded['desktop_recipients'] ?? null) ? $decoded['desktop_recipients'] : [];
					if (!$desktopAll && !empty($desktopRecipients) && !in_array($authorizedDesktopUsername, $desktopRecipients, true)) {
						continue;
					}
				}
				$retained[] = $decoded;
			}
		}
		if (count($retained) > RETENTION_MAX_EVENTS) {
			$retained = array_slice($retained, -RETENTION_MAX_EVENTS);
		}
		if (count($retained) !== count($lines) && is_writable(dirname(EVENTS_FILE))) {
			$encoded = array_map(static function (array $event): string {
				return json_encode($event, JSON_UNESCAPED_SLASHES);
			}, $retained);
			@file_put_contents(EVENTS_FILE, implode("\n", $encoded) . (empty($encoded) ? '' : "\n"), LOCK_EX);
		}
		$lines = array_slice($retained, -$limit);
		foreach ($lines as $line) {
			$events[] = $line;
		}
	}
}

$events = array_map(static function (array $event) use ($endpointSlug, $endpoint): array {
    return format_record_for_endpoint($event, $endpointSlug, $endpoint);
}, $events);
$latest = empty($events) ? null : $events[count($events) - 1];
if ($endpointSlug !== 'desktop' && strtolower((string)($_GET['format'] ?? '')) !== 'json') {
    if ($latest === null) {
        $latest = format_record_for_endpoint([
            'title' => 'Mass Notification',
            'message' => 'No notifications are currently available.',
            'priority' => 'notice',
        ], $endpointSlug, $endpoint);
    }
    [$format, $contentType] = endpoint_format($endpointSlug);
    header('Content-Type: ' . $contentType . '; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo (string)($latest['xml'] ?? endpoint_xml($endpointSlug, $latest));
    exit;
}
respond(200, [
    'ok' => true,
    'source' => 'localhost',
    'endpoint' => [
        'slug' => $endpointSlug,
        'brand' => (string)($endpoint['brand'] ?? ''),
    ],
    'count' => count($events),
    'latest' => $latest,
    'events' => $events,
]);
