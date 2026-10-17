<?php
declare(strict_types=1);
/** Exercise the module-to-runtime boundary with real private archive files. */
require dirname(__DIR__) . '/slsmassnotifyserver/OperationalBackup.php';

function backupCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function backupCleanup(string $root, bool $strict): void
{
    if (!is_dir($root) || is_link($root)) { return; }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $directory = $file->isDir() && !$file->isLink();
        $removed = $strict
            ? ($directory ? rmdir($file->getPathname()) : unlink($file->getPathname()))
            : ($directory ? @rmdir($file->getPathname()) : @unlink($file->getPathname()));
        if (!$removed && $strict) { throw new RuntimeException('Unable to remove an operational-backup fixture entry.'); }
    }
    $removed = $strict ? rmdir($root) : @rmdir($root);
    if (!$removed && $strict) { throw new RuntimeException('Unable to remove the operational-backup fixture directory.'); }
}

$root = sys_get_temp_dir() . '/sls-operational-bridge-' . bin2hex(random_bytes(8));
$passed = false;
try {
    backupCheck(mkdir($root, 0700) && chmod($root, 0700), 'Unable to create the private operational-backup fixture.');
    backupCheck(mkdir($root . '/data', 0700) && mkdir($root . '/runtime', 0700), 'Unable to create operational-backup fixture directories.');
    $source = dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_operational_backup.py';
    $runtime = $root . '/runtime/sls_operational_backup.py';
    backupCheck(copy($source, $runtime) && file_get_contents($runtime) === file_get_contents($source), 'Unable to copy the candidate operational-backup runtime.');
    eval('class OperationalBackupFixture {
        use \\FreePBX\\modules\\SlsOperationalBackup;
        const RUNTIME_DIR=' . var_export($root . '/runtime', true) . ';
        const PLUGIN_DATA_DIR=' . var_export($root . '/data', true) . ';
        function command($action,$file,$sha=null){return $this->operationalBackupCommand($action,$file,$sha);}
    }');
    $module = new OperationalBackupFixture();
    $weather = $root . '/data/weather-delivery.json';
    $initial = '{"pending":"historical evidence"}';
    backupCheck(file_put_contents($weather, $initial, LOCK_EX) === strlen($initial) && chmod($weather, 0600), 'Unable to create protected delivery evidence.');
    $report = $module->command('snapshot', $root . '/evidence.jsonl');
    backupCheck($report['files'] === 1, 'Snapshot bridge omitted delivery evidence.');
    $hash = hash_file('sha256', $root . '/evidence.jsonl');
    backupCheck(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'Snapshot manifest hash is unavailable.');
    $module->command('verify', $root . '/evidence.jsonl', $hash);
    $current = '{"current":"preserve me"}';
    backupCheck(file_put_contents($weather, $current, LOCK_EX) === strlen($current), 'Unable to replace current delivery evidence.');
    $archived = $module->command('archive', $root . '/evidence.jsonl', $hash);
    backupCheck(file_get_contents($weather) === $current
        && is_string($archived['archive_name'] ?? null)
        && is_file($root . '/data/recovery-archives/' . $archived['archive_name']),
        'Module restore activated old operational state or lost its evidence.');
    $failed = false;
    try { $module->command('archive', $root . '/evidence.jsonl', str_repeat('0', 64)); }
    catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'changed after manifest verification'); }
    backupCheck($failed, 'The runtime accepted evidence with a mismatched manifest.');
    $probe = <<<'PY'
import json
import os
print(json.dumps({'ok': True, 'clean_environment': 'AWS_SECRET_ACCESS_KEY' not in os.environ and 'HTTP_PROXY' not in os.environ}))
PY;
    backupCheck(file_put_contents($runtime, $probe, LOCK_EX) === strlen($probe), 'Unable to install the environment-isolation probe.');
    putenv('AWS_SECRET_ACCESS_KEY=private-fixture');
    putenv('HTTP_PROXY=http://private-fixture.invalid');
    try { $environment = $module->command('snapshot', $root . '/environment.jsonl'); }
    finally { putenv('AWS_SECRET_ACCESS_KEY'); putenv('HTTP_PROXY'); }
    backupCheck(($environment['clean_environment'] ?? null) === true, 'Operational backup inherited unrelated process credentials or proxy settings.');
    $passed = true;
} finally {
    backupCleanup($root, $passed);
}
echo "Operational backup bridge: real snapshot, manifest verification, evidence-only restore and mismatch errors passed.\n";
