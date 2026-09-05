<?php
declare(strict_types=1);
$directory = $argv[1] ?? dirname(__DIR__) . '/slsmassnotifyserver';
require_once $directory . '/SupportDiagnostics.php';
class SupportFixture {
    use \FreePBX\modules\SlsSupportDiagnostics;
    const MODULE_VERSION = '0.1.2-beta';
    const SETTINGS_JSON = '/nonexistent/config';
    const RUNTIME_DIR = '/nonexistent/runtime';
    const PIPER_BIN = '/nonexistent/piper';
    const TTS_DIR = '/nonexistent/audio';
    const PLUGIN_DATA_DIR = '/nonexistent';
    public $FreePBX;
    public function __construct() {
        $this->FreePBX = new class {
            public function Database() { return $this; }
            public function prepare($sql) { return $this; }
            public function execute($values) {}
            public function fetchColumn() { return '17.0.5'; }
        };
    }
    public function getDiagnosticsSummary() { return ['checks' => [
        ['label'=>'Central config', 'ok'=>true, 'detail'=>'SECRET_CONFIG_PASSWORD'],
        ['label'=>'SECRET_DYNAMIC_LABEL', 'ok'=>false, 'detail'=>'SECRET_API_TOKEN'],
    ]]; }
    public function getDeviceOverrideInventory() { return ['success'=>true, 'message'=>'SECRET_MANAGER_CREDENTIAL', 'devices'=>[
        ['extension'=>'SECRET_EXTENSION', 'format'=>'yealink', 'transport'=>'TLS', 'user_agent'=>'SECRET_UA', 'contact'=>'sip:secret@example.com', 'key'=>'SECRET_IDENTIFIER'],
        ['format'=>'SECRET_FORMAT', 'transport'=>'SECRET_TRANSPORT'],
    ]]; }
    private function loadJsonFile($path) { return ['pending_weather'=>4, 'queue_errors'=>'SECRET_COUNTER', 'password'=>'SECRET_PASSWORD', 'message'=>'SECRET_MESSAGE']; }
}
$report = (new SupportFixture())->getRedactedSupportDiagnostics();
$encoded = json_encode($report, JSON_THROW_ON_ERROR);
if (str_contains($encoded, 'SECRET_') || str_contains($encoded, 'example.com') || str_contains($encoded, '/nonexistent')) {
    throw new RuntimeException('Sensitive diagnostic field escaped the allowlist');
}
if (count($report['checks']) !== 1 || $report['devices']['formats'] !== ['yealink'=>1, 'unknown'=>1]
    || $report['devices']['transports'] !== ['tls'=>1, 'unknown'=>1] || $report['queue_counts']['pending_weather'] !== 4
    || $report['queue_counts']['queue_errors'] !== null || strlen($encoded) > 131072) {
    throw new RuntimeException('Diagnostic report schema/count validation failed');
}
$page = file_get_contents($directory . (is_file($directory . '/page.main.php') ? '/page.main.php' : '/page.slsmassnotifyserver.php'));
$csrf = strpos($page, 'validateCsrfToken'); $download = strpos($page, "=== 'diagnostic_download'");
$unlock = strpos($page, 'session_write_close();', $download); $build = strpos($page, 'getRedactedSupportDiagnostics()', $download);
if ($csrf === false || $download === false || $csrf >= $download || $unlock >= $build
    || !str_contains(substr($page, $download-90, 90), "=== 'POST'") || !str_contains($page, 'Cache-Control: private, no-store')
    || !str_contains($page, 'Content-Disposition: attachment;') || !str_contains($page, '131072')) {
    throw new RuntimeException('Diagnostic download authentication/CSRF/cache/bounds contract failed');
}
echo "Diagnostic allowlist, anonymization, strict counters, CSRF, POST and session-unlock checks passed.\n";
