<?php
declare(strict_types=1);
/** Parse the actual admin entry pages; substitute only the PBX action receiver. */
if (getenv('SLS_TEST_NAMESPACE') !== 'entered') { throw new RuntimeException('Use the isolated fixture runner.'); }
$module = dirname(__DIR__) . '/slsmassnotifyserver';
$directory = sys_get_temp_dir() . '/sls-admin-request-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$routes = [
    'automations' => ['save', 16], 'locations' => ['save_location', 12],
    'operators' => ['save_access', 16], 'operations' => ['fixture_action', 16],
    'incidents' => ['get_incident', 24], 'enterprise' => ['fixture_action', 32],
    'help' => ['record_device_acceptance', 12],
];
$checks = 0;
$failures = [];
try {
    foreach ($routes as $route => [$action, $depth]) {
        $marker = $directory . '/invoked';
        $page = $module . '/page.slsmassnotifyserver_' . $route . '.php';
        $harness = <<<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('_')) { function _($value) { return $value; } }
final class PrivateModuleReceiver {
    public function enforceOperatorPageAccess($page): void {}
    public function assertEnterprisePageAdministrator(): void {}
    public function validateCsrfToken($value): bool { return $value === 'private-fixture-token'; }
    public function __call($name, $args) { file_put_contents(MARKER, $name); return ['success' => true]; }
}
final class FreePBX {
    public static function create() { return (object)['Slsmassnotifyserver' => new PrivateModuleReceiver()]; }
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['payload' => file_get_contents(PAYLOAD), 'slsmassnotifyserver_csrf' => 'private-fixture-token',
    'slsmassnotifyserver_action' => ACTION, 'action' => ACTION];
$_REQUEST = $_POST;
require PAGE;
PHP;
        foreach (['MARKER' => $marker, 'PAYLOAD' => $directory . '/payload.json',
                  'ACTION' => $action, 'PAGE' => $page] as $name => $value) {
            $harness = preg_replace('/\b' . $name . '\b/', var_export($value, true), $harness);
        }
        file_put_contents($directory . '/harness.php', $harness);
        $deep = ['value' => false];
        for ($i = 0; $i < $depth; $i++) { $deep = ['child' => $deep]; }
        $positive = $route === 'incidents'
            ? json_encode(['incident_id' => 'inc_' . str_repeat('a', 32)], JSON_THROW_ON_ERROR)
            : '{"value":false}';
        foreach ([$positive => true,
                  '{"value":false,"value":true}' => false,
                  '{"child":{"value":false,"value":true}}' => false,
                  '{"value":1e999}' => false,
                  json_encode($deep, JSON_THROW_ON_ERROR) => false] as $raw => $allowed) {
            @unlink($marker);
            file_put_contents($directory . '/payload.json', $raw);
            $pipes = [];
            $process = proc_open(['/usr/bin/timeout', '5', '/usr/bin/php', $directory . '/harness.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start private request fixture.'); }
            fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]); $status = proc_close($process);
            $checks++;
            if ($status !== 0) { throw new RuntimeException($route . ' request crashed: ' . $error); }
            if (is_file($marker) !== $allowed) {
                $failures[] = $route . ' delegated an invalid request or rejected the positive control: ' . substr($raw, 0, 100);
            }
        }
    }
    if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
    echo $checks . " real-page request checks passed; production PBX actions were replaced with a private receiver.\n";
} finally {
    foreach (scandir($directory) as $name) {
        if ($name !== '.' && $name !== '..') { unlink($directory . '/' . $name); }
    }
    rmdir($directory);
}
