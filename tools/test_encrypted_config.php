<?php
namespace SlsEncryptedConfigFixture {
    function is_uploaded_file($path) { return $path === ($GLOBALS['encrypted_fixture_upload'] ?? null); }
}
namespace {
    interface BMO {}
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    use FreePBX\modules\SlsEncryptedConfig as Crypto;
    $checks = 0;
    function check($value, $message) { $GLOBALS['checks']++; if (!$value) { throw new RuntimeException($message); } }
    function rejected(callable $operation, $message) {
        try { $operation(); } catch (DomainException $error) { check($error->getMessage() !== '', 'Failure has no explanation.'); return; }
        throw new RuntimeException($message);
    }
    $settings = ['enabled' => '1', 'setup' => ['complete' => '1'],
        'control_api' => ['api_key' => 'fixture-private-credential'],
        'mail_from_domain' => 'fixture.invalid', 'system_notification_emails' => '',
        'scheduled_announcements' => [['id' => 'fixture', 'enabled' => '1']]];
    $plain = json_encode(['product' => 'Southland Servers Mass Notifications Server',
        'format' => 'sls-mass-notify-config-v1', 'exported_at' => '2026-09-22T00:00:00Z', 'settings' => $settings]);
    $password = 'four separate fixture words';
    $sealed = Crypto::encrypt($plain, $password); $again = Crypto::encrypt($plain, $password);
    check($sealed !== $again, 'Salt/nonce reused between exports.');
    check(strpos($sealed, 'fixture-private-credential') === false && strpos($sealed, $password) === false, 'Export contains plaintext secrets.');
    check(Crypto::decode($sealed, $password) === json_decode($plain, true), 'Encrypted round trip changed configuration.');
    check(Crypto::decode($plain) === json_decode($plain, true), 'Legacy export compatibility broken.');
    check(Crypto::decode(json_encode($settings)) === $settings, 'Bare central configuration compatibility broken.');
    rejected(fn() => Crypto::decode($sealed, 'incorrect fixture words'), 'Wrong password was accepted.');
    foreach (['salt', 'nonce', 'ciphertext'] as $field) {
        $bad = json_decode($sealed, true);
        $encoded = $field === 'salt' ? $bad['kdf']['salt'] : $bad[$field];
        $bytes = base64_decode($encoded); $bytes[0] = chr(ord($bytes[0]) ^ 1);
        if ($field === 'salt') { $bad['kdf']['salt'] = base64_encode($bytes); }
        else { $bad[$field] = base64_encode($bytes); }
        rejected(fn() => Crypto::decode(json_encode($bad), $password), 'Altered encrypted field was accepted.');
    }
    $bad = json_decode($sealed, true); $bad['kdf']['memlimit'] = PHP_INT_MAX;
    $start = microtime(true);
    rejected(fn() => Crypto::decode(json_encode($bad), $password), 'Untrusted work factor accepted.');
    check(microtime(true) - $start < 0.1, 'Invalid work factor reached expensive derivation.');
    foreach (['', 'short', [], str_repeat('x', 1025)] as $invalid) { rejected(fn() => Crypto::encrypt($plain, $invalid), 'Invalid passphrase accepted.'); }
    foreach (['[]', '{broken', '{"format":"unknown-backup"}', str_repeat('x', Crypto::MAX_FILE_BYTES + 1)] as $invalid) {
        rejected(fn() => Crypto::decode($invalid, $password), 'Malformed or oversized upload accepted.');
    }
    $bad = json_decode($sealed, true); $bad['ciphertext'] .= '\n';
    rejected(fn() => Crypto::decode(json_encode($bad), $password), 'Noncanonical Base64 accepted.');
    // Exercise the actual module import/export methods without any PBX access.
    $class = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
    $lines = file($class->getFileName()); $methods = '';
    foreach (['exportConfig', 'encodeConfigExport', 'exportEncryptedConfig', 'importConfigUpload'] as $name) {
        $method = $class->getMethod($name);
        $methods .= implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
    eval('namespace SlsEncryptedConfigFixture; use FreePBX\\modules\\SlsEncryptedConfig;
        class Module {
            use \\FreePBX\\modules\\SlsEnterpriseAdministration;
            public $staged = null; public $progress = []; public $plain; public $audit = []; public $status = [];
            function updateStatusData($data){ $this->status = array_replace($this->status, $data); }
            function getActiveSettings(){ return json_decode($this->plain, true)["settings"]; }
            function auditSensitiveExport($kind,$settings){ $this->audit[] = $kind; }
            function writeMaintenanceProgress($action,$state,$message){ $this->progress[] = [$action,$state,$message]; }
            function migrateConfigCompatibility($settings){ return $settings; }
            function validateConfigSchema($settings){ return isset($settings["control_api"]) ? [] : ["missing fixture control API"]; }
            function getDefaultSettings(){ return []; }
            function normalizeSettings($settings){ return $settings; }
            function persistPendingSettings($settings,$replace){ $this->staged = $settings; }
            ' . $methods . '}');
    $module = new SlsEncryptedConfigFixture\Module(); $module->plain = $plain;
    rejected(fn() => $module->exportEncryptedConfig($password, 'different'), 'Mismatched confirmation accepted.');
    check(Crypto::decode($module->exportEncryptedConfig($password, $password), $password)['settings'] === $settings, 'Public export path lost settings.');
    check($module->audit === ['encrypted'], 'Encrypted export was logged as plaintext or invalid attempts were counted as completed exports.');
    check($module->status['last_config_export_kind'] === 'encrypted' && abs(time() - $module->status['last_config_export_at']) <= 2, 'Successful encrypted export did not update the backup reminder.');
    check(json_decode($module->exportConfig(), true)['settings'] === $settings && $module->audit === ['encrypted', 'plain'], 'Plain export omitted its audit or changed settings.');
    check($module->status['last_config_export_kind'] === 'plain', 'Successful plain export did not update its reminder metadata.');
    $file = tempnam(sys_get_temp_dir(), 'sls-encrypted-fixture-');
    $GLOBALS['encrypted_fixture_upload'] = $file;
    try {
        file_put_contents($file, $sealed);
        $upload = ['error' => UPLOAD_ERR_OK, 'size' => strlen($sealed), 'tmp_name' => $file];
        $result = $module->importConfigUpload($upload, 'incorrect fixture words');
        check(!$result['success'] && $module->staged === null, 'Failed authentication staged settings.');
        check(strpos(json_encode($module->progress), $password) === false, 'Passphrase retained in progress state.');
        $result = $module->importConfigUpload($upload, $password);
        $expected = $settings; $expected['scheduled_announcements'][0]['enabled'] = '0';
        check($result['success'] && $module->staged === $expected, 'Authenticated import lost config or re-enabled schedules.');
        $module->staged = null; file_put_contents($file, $plain); $upload['size'] = strlen($plain);
        check($module->importConfigUpload($upload)['success'] && $module->staged === $expected, 'Legacy import no longer stages safely.');
        $module->staged = null; $upload['tmp_name'] = '/not-an-upload';
        check(!$module->importConfigUpload($upload)['success'] && $module->staged === null, 'Non-upload file was accepted.');
    } finally { unlink($file); }
    echo "Encrypted configuration: $checks authenticated export/import, tamper, resource-limit and replay-prevention checks passed.\n";
}
