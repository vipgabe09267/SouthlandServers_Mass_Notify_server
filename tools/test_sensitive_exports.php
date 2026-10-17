<?php
declare(strict_types=1);
/** Actual PHP exports and real isolated Python audit persistence; no PBX bootstrap. */
interface BMO {}
require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

function ensureExport(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/sls-export-audit-' . bin2hex(random_bytes(8));
$created = false;
$lock = null;
$previousLeak = getenv('SLS_AUDIT_ENV_LEAK');
$hadSession = array_key_exists('_SESSION', $GLOBALS); $previousSession = $GLOBALS['_SESSION'] ?? null;
$hadRemote = array_key_exists('REMOTE_ADDR', $_SERVER); $previousRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$hadForwarded = array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER); $previousForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
try {
    ensureExport(mkdir($root, 0700), 'Private audit fixture directory could not be created.');
    $created = true;
    ensureExport(chmod($root, 0700), 'Private audit fixture directory permissions could not be set.');
    $runtimeSource = dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify';
    $source = file_get_contents($runtimeSource . '/sls_storage_maintenance.py');
    ensureExport(is_string($source), 'Audit implementation source could not be read.');
    $source = str_replace(
        "import os\n",
        "import os\nif os.environ.get('SLS_AUDIT_ENV_LEAK'): raise SystemExit(97)\n",
        $source,
        $environmentReplacements
    );
    ensureExport($environmentReplacements === 1, 'Audit fixture could not install its environment probe.');
    $encodedRoot = json_encode($root, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $source = preg_replace('/^DATA = Path\([^\n]+\)$/m', 'DATA = Path(' . $encodedRoot . ')', $source, 1, $replaced);
    ensureExport(is_string($source) && $replaced === 1, 'Audit fixture could not isolate its data directory.');
    $maintenance = $root . '/sls_storage_maintenance.py';
    ensureExport(file_put_contents($maintenance, $source) === strlen($source) && chmod($maintenance, 0600),
        'Audit fixture could not install its maintenance helper privately.');
    foreach (['sls_audio_state.py', 'sls_config_crypto.py'] as $helper) {
        $destination = $root . '/' . $helper;
        ensureExport(copy($runtimeSource . '/' . $helper, $destination) && chmod($destination, 0600),
            'Audit dependency could not be installed privately: ' . $helper);
    }
    $reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
    $classFile = $reflection->getFileName();
    ensureExport(is_string($classFile), 'Module source path is unavailable.');
    $lines = file($classFile);
    ensureExport(is_array($lines), 'Module source could not be read.');
    $methods = '';
    foreach (['exportConfig', 'encodeConfigExport', 'exportEncryptedConfig'] as $name) {
        $method = $reflection->getMethod($name);
        $length = $method->getEndLine() - $method->getStartLine() + 1;
        $fragment = array_slice($lines, $method->getStartLine() - 1, $length);
        ensureExport(count($fragment) === $length, 'Module export method extraction was incomplete: ' . $name);
        $methods .= implode('', $fragment);
    }
    eval('namespace SlsSensitiveExportFixture; use FreePBX\\modules\\SlsEncryptedConfig;
        class Module {
            use \\FreePBX\\modules\\SlsAdminAudit;
            const RUNTIME_DIR = ' . var_export($root, true) . ';
            public $status = [];
            function updateStatusData($data){ $this->status = array_replace($this->status, $data); }
            function getActiveSettings(){ return ["control_api"=>["api_key"=>"never-log-this-secret"]]; }
            function nativeExport(){ $this->auditSensitiveExport("native", $this->getActiveSettings()); }
            ' . $methods . '}');
    $module = new SlsSensitiveExportFixture\Module();
    $_SESSION = ['AMP_user' => (object)['username' => "fixture-admin\nforged-record"]];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    ensureExport(putenv('SLS_AUDIT_ENV_LEAK=must-not-cross-audit-boundary'), 'Audit environment probe could not be set.');
    $plain = $module->exportConfig();
    $plainData = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
    ensureExport(($plainData['settings']['control_api']['api_key'] ?? null) === 'never-log-this-secret', 'Plain export changed configuration.');
    $passphrase = 'only used in this private fixture';
    $encrypted = $module->exportEncryptedConfig($passphrase, $passphrase);
    $decrypted = FreePBX\modules\SlsEncryptedConfig::decode($encrypted, $passphrase);
    ensureExport(($decrypted['settings'] ?? null) === $module->getActiveSettings(), 'Encrypted export lost configuration.');
    $module->nativeExport();
    ensureExport(!file_exists($root.'/control-api-audit.jsonl'), 'Administrator export was logged as API usage.');
    $raw = file_get_contents($root . '/security-audit.jsonl');
    ensureExport(is_string($raw) && $raw !== '', 'Security audit evidence could not be read.');
    $records = array_map(static fn(string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR), explode("\n", trim($raw)));
    ensureExport(array_column($records, 'action') === ['config_export_plain', 'config_export_encrypted', 'config_export_native'], 'Export types were conflated or omitted.');
    ensureExport(count(array_unique(array_column($records, 'event_id'))) === 3, 'Audit event identifiers were reused.');
    foreach ($records as $record) {
        ensureExport(($record['actor'] ?? null) === 'web:fixture-admin_forged-record'
            && ($record['ip'] ?? null) === '192.0.2.5'
            && is_string($record['event_id'] ?? null)
            && preg_match('/^audit_[a-f0-9]{32}$/D', $record['event_id']) === 1,
            'Export attribution or event identity was invalid.');
    }
    ensureExport(strpos($raw, 'never-log-this-secret') === false && strpos($raw, $passphrase) === false && strpos($raw, 'settings') === false,
        'Export audit exposed configuration or passphrase data.');
    $lock = fopen($root . '/security-audit.jsonl', 'r+b');
    ensureExport(is_resource($lock) && flock($lock, LOCK_EX), 'Security audit fixture lock could not be acquired.');
    foreach (['plain', 'encrypted', 'native'] as $kind) {
        $blocked = false;
        try {
            if ($kind === 'plain') { $module->exportConfig(); }
            elseif ($kind === 'encrypted') { $module->exportEncryptedConfig($passphrase, $passphrase); }
            else { $module->nativeExport(); }
        } catch (DomainException $error) {
            $blocked = strpos($error->getMessage(), 'withheld') !== false && strpos($error->getMessage(), 'audit_storage_busy') !== false;
        }
        ensureExport($blocked, 'An unaudited ' . $kind . ' export was returned.');
    }
    ensureExport(flock($lock, LOCK_UN), 'Security audit fixture lock could not be released.');
    ensureExport(fclose($lock), 'Security audit fixture descriptor could not be closed.'); $lock = null;
    ensureExport(file_get_contents($root . '/security-audit.jsonl') === $raw, 'Failed export wrote a successful audit.');
    $module->exportConfig();
    $faultBytes = file_get_contents($root . '/security-audit-fault.json');
    ensureExport(is_string($faultBytes), 'Audit recovery status could not be read.');
    $fault = json_decode($faultBytes, true, 16, JSON_THROW_ON_ERROR);
    ensureExport(($fault['active'] ?? null) === false && ($fault['failed_records'] ?? null) === 3, 'Recovered exports did not clear current health while preserving failed-record history.');
    echo "Sensitive exports: real audited plaintext/encrypted/native paths, attribution, withholding and recovery passed.\n";
} finally {
    $environmentRestored = $previousLeak === false
        ? putenv('SLS_AUDIT_ENV_LEAK')
        : putenv('SLS_AUDIT_ENV_LEAK=' . $previousLeak);
    if ($hadSession) { $GLOBALS['_SESSION'] = $previousSession; } else { unset($GLOBALS['_SESSION']); }
    if ($hadRemote) { $_SERVER['REMOTE_ADDR'] = $previousRemote; } else { unset($_SERVER['REMOTE_ADDR']); }
    if ($hadForwarded) { $_SERVER['HTTP_X_FORWARDED_FOR'] = $previousForwarded; } else { unset($_SERVER['HTTP_X_FORWARDED_FOR']); }
    if (is_resource($lock)) { @flock($lock, LOCK_UN); fclose($lock); }
    if ($created) {
        $entries = scandir($root);
        ensureExport(is_array($entries), 'Audit fixture could not be enumerated for cleanup.');
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') { ensureExport(unlink($root . '/' . $entry), 'Audit fixture cleanup failed: ' . $entry); }
        }
        ensureExport(rmdir($root), 'Audit fixture directory could not be removed.');
    }
    ensureExport($environmentRestored, 'Audit environment could not be restored.');
}
