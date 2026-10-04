#!/usr/bin/php
<?php
declare(strict_types=1);
// Runtime account only. Prints identifiers, never credentials or arbitrary shell code.
require_once '/var/www/html/admin/modules/slsmassnotifyserver/ApiPermissionGuards.php';
require_once __DIR__ . '/sls_config_crypto.php';
try {
    if ($argc !== 2 || strlen($argv[1]) > 4096) { throw new RuntimeException('Invalid configuration path.'); }
    $path = $argv[1]; $before = @lstat($path);
    if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || $before['size'] > 8388608) { throw new RuntimeException('Unsafe configuration storage.'); }
    $handle = @fopen($path, 'rb'); if (!$handle) { throw new RuntimeException('Configuration is unreadable.'); }
    try {
        $opened = fstat($handle);
        if (!$opened || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || $opened['nlink'] !== 1 || ($opened['mode'] & 0170000) !== 0100000) { throw new RuntimeException('Configuration changed while opening.'); }
        $raw = stream_get_contents($handle, 8388609);
    } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 8388608) { throw new RuntimeException('Configuration exceeds its read limit.'); }
    $settings = \FreePBX\modules\SlsConfigCrypto::decode($raw);
    $rawContext = getenv('SLS_API_TEST_CONTEXT');
    if (!is_string($rawContext) || strlen($rawContext) > 131072) { throw new RuntimeException('Invalid API test context size.'); }
    $context = json_decode($rawContext, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($settings) || !is_array($context)) { throw new RuntimeException('Invalid API test configuration.'); }
    $allowed = \SLS\MassNotify\ApiPermissionGuards::weather($settings, $context);
    echo implode(',', $allowed['phones']) . "\n" . implode(',', $allowed['desktops']) . "\n" . $allowed['removed_count'] . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Weather test API authorization could not be verified. No recipients were authorized; check the credential, zone permissions and protected configuration.\n");
    exit(1);
}
