<?php
declare(strict_types=1);
$directory = realpath(dirname(__DIR__) . '/slsmassnotifyserver');
if (!is_string($directory)) {
    throw new RuntimeException('Candidate module directory is unavailable.');
}
require_once $directory . '/SupportDiagnostics.php';

class SupportFixture
{
    use \FreePBX\modules\SlsSupportDiagnostics;

    public const MODULE_VERSION = '0.1.2-beta';
    public const SETTINGS_JSON = '/nonexistent/config';
    public const RUNTIME_DIR = '/nonexistent/runtime';
    public const PIPER_BIN = '/nonexistent/piper';
    public const TTS_DIR = '/nonexistent/audio';
    public const PLUGIN_DATA_DIR = '/nonexistent';

    public $FreePBX;

    public function __construct()
    {
        $this->FreePBX = new class {
            public function Database()
            {
                return $this;
            }

            public function prepare($sql)
            {
                return $this;
            }

            public function execute($values): void
            {
            }

            public function fetchColumn(): string
            {
                return '17.0.5';
            }
        };
    }

    public function getDiagnosticsSummary(): array
    {
        return ['checks' => [
            ['label' => 'Central config', 'ok' => true, 'detail' => 'SECRET_CONFIG_PASSWORD'],
            ['label' => 'SECRET_DYNAMIC_LABEL', 'ok' => false, 'detail' => 'SECRET_API_TOKEN'],
        ]];
    }

    public function getDeviceOverrideInventory(): array
    {
        return [
            'success' => true,
            'message' => 'SECRET_MANAGER_CREDENTIAL',
            'devices' => [
                [
                    'extension' => 'SECRET_EXTENSION',
                    'format' => 'yealink',
                    'transport' => 'TLS',
                    'user_agent' => 'SECRET_UA',
                    'contact' => 'sip:secret@example.com',
                    'key' => 'SECRET_IDENTIFIER',
                ],
                ['format' => 'yealink_image', 'transport' => 'tcp'],
                ['format' => 'SECRET_FORMAT', 'transport' => 'SECRET_TRANSPORT'],
            ],
        ];
    }

    private function loadJsonFile($path): array
    {
        return [
            'pending_weather' => 4,
            'queue_errors' => 'SECRET_COUNTER',
            'password' => 'SECRET_PASSWORD',
            'message' => 'SECRET_MESSAGE',
        ];
    }
}

$report = (new SupportFixture())->getRedactedSupportDiagnostics();
$encoded = json_encode($report, JSON_THROW_ON_ERROR);
if (str_contains($encoded, 'SECRET_') || str_contains($encoded, 'example.com') || str_contains($encoded, '/nonexistent')) {
    throw new RuntimeException('Sensitive diagnostic field escaped the allowlist');
}
if (count($report['checks']) !== 1
    || $report['devices']['formats'] !== ['yealink' => 1, 'yealink_image' => 1, 'unknown' => 1]
    || $report['devices']['transports'] !== ['tls' => 1, 'tcp' => 1, 'unknown' => 1]
    || $report['queue_counts']['pending_weather'] !== 4
    || $report['queue_counts']['queue_errors'] !== null
    || strlen($encoded) > 131072) {
    throw new RuntimeException('Diagnostic report schema/count validation failed');
}
$page = file_get_contents($directory . (is_file($directory . '/page.main.php') ? '/page.main.php' : '/page.slsmassnotifyserver.php'));
if (!is_string($page)) {
    throw new RuntimeException('Diagnostic controller source is unavailable.');
}
$startNeedle = "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['slsmassnotifyserver_action'] ?? '') === 'diagnostic_download') {";
$nextNeedle = "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['slsmassnotifyserver_action'] ?? '') === 'preview_announcement')";
$csrfGuard = "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && !\$slsmassnotifyserver->validateCsrfToken(\$_POST['slsmassnotifyserver_csrf'] ?? '')) {";
$download = strpos($page, $startNeedle);
$end = $download === false ? false : strpos($page, $nextNeedle, $download);
$prefix = is_int($download) ? substr($page, 0, $download) : '';
$branch = is_int($download) && is_int($end) && $end > $download
    ? substr($page, $download, $end - $download)
    : '';
$unlockLine = "\tif (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }";
$buildNeedle = 'getRedactedSupportDiagnostics()';
$validBranch = static function (string $value) use ($unlockLine, $buildNeedle): bool {
    $unlock = strpos($value, $unlockLine);
    $build = strpos($value, $buildNeedle);
    $exactUnlocks = count(array_filter(
        explode("\n", $value),
        static fn(string $line): bool => $line === $unlockLine
    ));
    return $exactUnlocks === 1
        && substr_count($value, $buildNeedle) === 1
        && is_int($unlock)
        && is_int($build)
        && $unlock < $build;
};
$withoutUnlock = str_replace($unlockLine, '', $branch);
$reordered = str_replace($buildNeedle, $buildNeedle . "\n" . $unlockLine, $withoutUnlock);
if (!is_int($download)
    || !is_int($end)
    || substr_count($prefix, $csrfGuard) !== 1
    || !$validBranch($branch)
    || $validBranch($withoutUnlock)
    || $validBranch($branch . "\n" . $unlockLine)
    || $validBranch($reordered)
    || $validBranch(str_replace($unlockLine, "\t// " . $unlockLine, $branch))
    || !str_contains($branch, 'Cache-Control: private, no-store')
    || !str_contains($branch, 'Content-Disposition: attachment;')
    || !str_contains($branch, '131072')) {
    throw new RuntimeException('Diagnostic download authentication/CSRF/cache/bounds contract failed');
}
echo "Diagnostic allowlist, anonymization, strict counters, CSRF, POST and session-unlock checks passed.\n";
