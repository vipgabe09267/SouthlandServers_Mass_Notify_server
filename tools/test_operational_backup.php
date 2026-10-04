<?php
declare(strict_types=1);
/** Exercise the module-to-runtime boundary with real private archive files. */
require dirname(__DIR__) . '/slsmassnotifyserver/OperationalBackup.php';
$root = sys_get_temp_dir() . '/sls-operational-bridge-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/data', 0700); mkdir($root . '/runtime', 0700);
copy(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_operational_backup.py', $root . '/runtime/sls_operational_backup.py');
eval('class OperationalBackupFixture {
    use \\FreePBX\\modules\\SlsOperationalBackup;
    const RUNTIME_DIR=' . var_export($root . '/runtime', true) . ';
    const PLUGIN_DATA_DIR=' . var_export($root . '/data', true) . ';
    function command($action,$file,$sha=null){return $this->operationalBackupCommand($action,$file,$sha);}
}');
$module = new OperationalBackupFixture();
try {
    file_put_contents($root . '/data/weather-delivery.json', '{"pending":"historical evidence"}');
    chmod($root . '/data/weather-delivery.json', 0600);
    $report = $module->command('snapshot', $root . '/evidence.jsonl');
    if ($report['files'] !== 1) { throw new RuntimeException('Snapshot bridge omitted delivery evidence.'); }
    $hash = hash_file('sha256', $root . '/evidence.jsonl');
    $module->command('verify', $root . '/evidence.jsonl', $hash);
    file_put_contents($root . '/data/weather-delivery.json', '{"current":"preserve me"}');
    $archived = $module->command('archive', $root . '/evidence.jsonl', $hash);
    if (file_get_contents($root . '/data/weather-delivery.json') !== '{"current":"preserve me"}'
        || !is_file($root . '/data/recovery-archives/' . $archived['archive_name'])) {
        throw new RuntimeException('Module restore activated old operational state or lost its evidence.');
    }
    $failed = false;
    try { $module->command('archive', $root . '/evidence.jsonl', str_repeat('0', 64)); }
    catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'changed after manifest verification'); }
    if (!$failed) { throw new RuntimeException('The runtime accepted evidence with a mismatched manifest.'); }
    echo "Operational backup bridge: real snapshot, manifest verification, evidence-only restore and mismatch errors passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
