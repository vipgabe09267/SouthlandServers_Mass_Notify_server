<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/event-log.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/security.php';
$directory = sys_get_temp_dir() . '/sls-control-bounds-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
define('CONTROL_API_RATE_FILE', $directory . '/rate.json');
define('EVENTS_FILE', $directory . '/events.jsonl');
define('CONTROL_API_MAX_CONFIG_BODY_BYTES', 2 * 1024 * 1024);
define('CONTROL_API_MAX_ACTION_BODY_BYTES', 65536);
$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/index.php');
$helperStart = strpos($source, 'function api_state_metadata_matches(');
eval(substr($source, $helperStart, strpos($source, 'function control_rate_allowed(', $helperStart) - $helperStart));
foreach (['control_rate_allowed' => 'audit_control_api', 'recent_events' => 'freepbx_module', 'decode_control_request' => 'cidr_match', 'cidr_match'=>'api_state_metadata_matches'] as $start => $end) {
    $offset = strpos($source, 'function ' . $start . '(');
    $length = strpos($source, 'function ' . $end . '(', $offset) - $offset;
    eval(substr($source, $offset, $length));
}
$control = ['rate_limit_enabled' => '1', 'rate_limit_per_minute' => 2];
try {
    foreach (['','abc','+24','-1','32x','33','999999999999999999999',"24\n"] as $bits) {
        foreach(['198.51.100.9','192.0.2.0'] as $ip){if(cidr_match($ip,'192.0.2.0/'.$bits)){throw new RuntimeException('Malformed CIDR prefix widened the source allowlist.');}}
    }
    foreach ([['192.0.2.5','192.0.2.0/24',true],['198.51.100.5','192.0.2.0/24',false],['192.0.2.5','192.0.2.5/32',true],
        ['198.51.100.5','192.0.2.0/0',true],['2001:db8:1::5','2001:db8:1::/48',true],['2001:db8:2::5','2001:db8:1::/48',false],
        ['2001:db8::5','2001:db8::5/128',true],['2001:db8::5','::/0',true],['2001:db8::5','192.0.2.0/24',false],
        ['2001:db8::5','2001:db8::/129',false]] as [$ip,$range,$expected]) {
        if(cidr_match($ip,$range)!==$expected){throw new RuntimeException('Valid CIDR behavior or address-family isolation changed.');}
    }
    $literalPolicy=['ip_allowlist_enabled'=>'1','ip_allowlist'=>'2001:DB8:0000:0000:0000:0000:0000:0005'];
    if(!ip_allowed($literalPolicy,'2001:db8::5')||ip_allowed($literalPolicy,'2001:db8::6')||ip_allowed($literalPolicy,'192.0.2.5')){throw new RuntimeException('Literal IPv6 representation changed its allowlist identity.');}
    $clients = [];
    for ($index = 0; $index < 1000; $index++) {
        $clients[] = ['id' => 'fixture-' . $index, 'username' => 'client-' . $index,
            'password' => str_repeat('a', 32), 'name' => 'Desktop ' . $index, 'enabled' => true];
    }
    $raw = json_encode(['action' => 'update_config', 'settings' => ['desktop_client_limit' => 1000, 'desktop_clients' => $clients]]);
    $decoded = decode_control_request($raw);
    if (strlen($raw) <= 65536 || isset($decoded['error']) || count($decoded['body']['settings']['desktop_clients']) !== 1000) {
        throw new RuntimeException('The bounded Control API parser cannot accept a 1000-client configuration.');
    }
    if ((decode_control_request(json_encode(['action' => 'send_announcement', 'message' => str_repeat('a', 65536)]))['limit_bytes'] ?? 0) !== 65536) {
        throw new RuntimeException('The ordinary-action request size limit was relaxed.');
    }
    if ((decode_control_request(str_repeat(' ', CONTROL_API_MAX_CONFIG_BODY_BYTES + 1))['status'] ?? 0) !== 413) {
        throw new RuntimeException('An oversized configuration request was accepted.');
    }
    foreach (['[]', '{', '{"action":false}', '{"action":{"nested":true}}', '{"action":"send_announcement","action":"update_config"}', '{"action":"send_announcement","x":{"a":1,"\\u0061":2}}', '{"action":"send_announcement","x":1e9999}', '{"action":"update_config","value":' . str_repeat('[', 65) . '0' . str_repeat(']', 65) . '}'] as $invalid) {
        if ((decode_control_request($invalid)['status'] ?? 0) !== 400) { throw new RuntimeException('Invalid or over-nested Control API JSON was accepted.'); }
    }
    if (!control_rate_allowed($control, '192.0.2.1') || !control_rate_allowed($control, '192.0.2.1') || control_rate_allowed($control, '192.0.2.1')) {
        throw new RuntimeException('Control API rate budget was not enforced.');
    }
    $data = json_decode(file_get_contents(CONTROL_API_RATE_FILE), true);
    for ($index = 0; count($data) < 2048; $index++) {
        $data[hash('sha256', 'fixture-' . $index)] = ['bucket' => gmdate('YmdHi'), 'count' => 1];
    }
    file_put_contents(CONTROL_API_RATE_FILE, json_encode($data));
    if (control_rate_allowed($control, '192.0.2.200') || control_rate_allowed($control, '192.0.2.1')) {
        throw new RuntimeException('Address churn bypassed a full rate table.');
    }
    if (count(json_decode(file_get_contents(CONTROL_API_RATE_FILE), true)) !== 2048) { throw new RuntimeException('Rate table grew beyond its limit.'); }
    foreach ($data as &$entry) { $entry['bucket'] = '199901010000'; } unset($entry);
    file_put_contents(CONTROL_API_RATE_FILE, json_encode($data));
    if (!control_rate_allowed($control, '192.0.2.200')) { throw new RuntimeException('Expired rate buckets did not recover.'); }
    file_put_contents(CONTROL_API_RATE_FILE, str_repeat('x', 512 * 1024 + 1));
    if (control_rate_allowed($control, '192.0.2.200')) { throw new RuntimeException('Oversized rate state was accepted.'); }
    $log = fopen(EVENTS_FILE, 'wb');
    for ($index = 0; $index < 20000; $index++) { fwrite($log, json_encode(['id' => $index, 'message' => str_repeat('x', 100)]) . "\n"); }
    fclose($log);
    $events = recent_events(100);
    if (count($events) !== 100 || $events[0]['id'] !== 19999 || $events[99]['id'] !== 19900) { throw new RuntimeException('Bounded event tail lost ordering or recent records.'); }
    file_put_contents(EVENTS_FILE, '{"id":1}' . "\n" . str_repeat('x', 1024 * 1024) . "\n" . '{"id":2}' . "\n");
    if (array_column(recent_events(100), 'id') !== [2]) { throw new RuntimeException('Oversized historical line broke bounded recent-event reading.'); }
    echo "Control API address budgets, oversized state and bounded recent-event reads passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}
