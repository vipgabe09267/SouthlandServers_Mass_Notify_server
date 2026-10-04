<?php
declare(strict_types=1);
/** Actual PHP exports and real isolated Python audit persistence; no PBX bootstrap. */
interface BMO {}
require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

function ensureExport(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/sls-export-audit-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_storage_maintenance.py');
$source = preg_replace('/^DATA = Path\([^\n]+\)$/m', 'DATA = Path(' . json_encode($root, JSON_UNESCAPED_SLASHES) . ')', $source, 1, $replaced);
ensureExport($replaced === 1, 'Audit fixture could not isolate its data directory.');
file_put_contents($root . '/sls_storage_maintenance.py', $source); chmod($root . '/sls_storage_maintenance.py', 0600);
copy(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_audio_state.py', $root . '/sls_audio_state.py');
copy(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py', $root . '/sls_config_crypto.py');
$reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
$lines = file($reflection->getFileName()); $methods = '';
foreach (['exportConfig', 'encodeConfigExport', 'exportEncryptedConfig'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
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
$_SESSION['AMP_user'] = (object)['username' => "fixture-admin\nforged-record"];
$_SERVER['REMOTE_ADDR'] = '192.0.2.5'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
$lock = null;
try {
    $plain = $module->exportConfig();
    ensureExport(json_decode($plain, true)['settings']['control_api']['api_key'] === 'never-log-this-secret', 'Plain export changed configuration.');
    $passphrase = 'only used in this private fixture';
    $encrypted = $module->exportEncryptedConfig($passphrase, $passphrase);
    ensureExport(FreePBX\modules\SlsEncryptedConfig::decode($encrypted, $passphrase)['settings'] === $module->getActiveSettings(), 'Encrypted export lost configuration.');
    $module->nativeExport();
    ensureExport(!file_exists($root.'/control-api-audit.jsonl'), 'Administrator export was logged as API usage.');
    $raw = file_get_contents($root . '/security-audit.jsonl');
    $records = array_map(fn($line) => json_decode($line, true), explode("\n", trim($raw)));
    ensureExport(array_column($records, 'action') === ['config_export_plain', 'config_export_encrypted', 'config_export_native'], 'Export types were conflated or omitted.');
    ensureExport(count(array_unique(array_column($records, 'event_id'))) === 3, 'Audit event identifiers were reused.');
    foreach ($records as $record) {
        ensureExport($record['actor'] === 'web:fixture-admin_forged-record' && $record['ip'] === '192.0.2.5', 'Export attribution used untrusted headers or line breaks.');
    }
    ensureExport(strpos($raw, 'never-log-this-secret') === false && strpos($raw, $passphrase) === false && strpos($raw, 'settings') === false,
        'Export audit exposed configuration or passphrase data.');
    $lock = fopen($root . '/security-audit.jsonl', 'r+b'); flock($lock, LOCK_EX);
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
    flock($lock, LOCK_UN); fclose($lock); $lock = null;
    ensureExport(file_get_contents($root . '/security-audit.jsonl') === $raw, 'Failed export wrote a successful audit.');
    $module->exportConfig();
    $fault = json_decode(file_get_contents($root . '/security-audit-fault.json'), true);
    ensureExport($fault['active'] === false && $fault['failed_records'] === 3, 'Recovered exports did not clear current health while preserving failed-record history.');
    echo "Sensitive exports: real audited plaintext/encrypted/native paths, attribution, withholding and recovery passed.\n";
} finally {
    if (is_resource($lock)) { fclose($lock); }
    foreach (array_merge(glob($root . '/*'),glob($root . '/.audit*')) ?: [] as $path) { unlink($path); }
    rmdir($root);
}
