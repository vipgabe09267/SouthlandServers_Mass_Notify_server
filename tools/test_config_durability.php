<?php
declare(strict_types=1);

namespace SlsConfigDurabilityFixture {
    final class SlsConfigCrypto {
        public static function encode(array $settings): string {
            return \FreePBX\modules\SlsConfigCrypto::encodeWithKeyring($settings, $GLOBALS['durability_keyring']);
        }
        public static function decode(string $raw): array {
            return \FreePBX\modules\SlsConfigCrypto::decodeWithKeyring($raw, $GLOBALS['durability_keyring'], true);
        }
    }
    function fsync($handle): bool {
        $directory = (fstat($handle)['mode'] & 0170000) === 0040000;
        $GLOBALS['config_fixture_calls'][] = $directory ? 'sync_directory' : 'sync_file';
        if (($GLOBALS['config_fixture_failure'] ?? '') === ($directory ? 'directory' : 'file')) { return false; }
        return \fsync($handle);
    }
    function fwrite($handle, $contents) {
        if (($GLOBALS['config_fixture_failure'] ?? '') === 'write') { return 0; }
        // Exercise short writes instead of assuming a single write is complete.
        return \fwrite($handle, substr($contents, 0, 7));
    }
    function rename($from, $to): bool {
        $GLOBALS['config_fixture_calls'][] = 'rename';
        return \rename($from, $to);
    }
    function random_bytes($length): string {
        return $GLOBALS['config_fixture_random'] ?? \random_bytes($length);
    }
}

namespace {
    if (!interface_exists('BMO')) { interface BMO {} }
    require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    $directory = sys_get_temp_dir() . '/sls-config-durability-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) { throw new RuntimeException('Unable to create fixture directory.'); }
    $keyId = bin2hex(random_bytes(16));
    $GLOBALS['durability_keyring'] = ['format' => \FreePBX\modules\SlsConfigCrypto::KEYRING_FORMAT, 'active' => $keyId,
        'keys' => [$keyId => ['created_at' => time(), 'key' => base64_encode(random_bytes(32))]]];
    $reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
    $method = $reflection->getMethod('writeSettingsFileUnlocked');
    $source = file($method->getFileName());
    $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $body = str_replace('private function writeSettingsFileUnlocked', 'public function writeSettingsFileUnlocked', $body);
    $presenceMethod = $reflection->getMethod('configurationPathMetadata');
    $presenceBody = implode('', array_slice($source, $presenceMethod->getStartLine() - 1,
        $presenceMethod->getEndLine() - $presenceMethod->getStartLine() + 1));
    $activationMethod = $reflection->getMethod('assertEnterpriseActivationSafe');
    $activationSource = file($activationMethod->getFileName());
    $activationBody = implode('', array_slice($activationSource, $activationMethod->getStartLine() - 1,
        $activationMethod->getEndLine() - $activationMethod->getStartLine() + 1));
    $activationBody = str_replace('LabsSafety::', '\\SLS\\MassNotify\\LabsSafety::', $activationBody);
    eval('namespace SlsConfigDurabilityFixture; class Writer {
        const SETTINGS_JSON = ' . var_export($directory . '/active.config', true) . ';
        const NATIVE_BACKUP_MAX_CONFIG_BYTES = 1024;
        public $normalizedSettingsCache = [];
        public $fingerprints = [];
        public $backups = 0;
        private function assertDesktopCapacityChange($new, $old) {}
        private function assertLivePagingPromptsReady($settings) {}
        private function loadSettingsFile($path) { return SlsConfigCrypto::decode(file_get_contents($path)); }
        private function backupAppliedSettings() { $this->backups++; }
        private function setPrivateOwnership($path) { chmod($path, 0640); }
        private function rememberSettingsFingerprint($path) { $this->fingerprints[$path] = hash_file("sha256", $path); }
        ' . $activationBody . $presenceBody . $body . '}');
    $writer = new \SlsConfigDurabilityFixture\Writer();
    $apiMethod = $reflection->getMethod('controlApiUpdateConfig');
    $apiBody = implode('', array_slice($source, $apiMethod->getStartLine() - 1, $apiMethod->getEndLine() - $apiMethod->getStartLine() + 1));
    eval('class ConfigDurabilityApiFixture {
        private function getActiveSettings() { return []; }
        private function getPendingSettings() { return null; }
        private function isSetupComplete($settings) { return true; }
        private function validateAndNormalizeControlConfigPatch($settings) { return ["patch" => $settings, "errors" => []]; }
        private function mergeControlConfigPatch($current, $patch) { return array_replace($current, $patch); }
        private function normalizeSettings($settings) { return $settings; }
        private function validateConfigSchema($settings) { return []; }
        private function persistAppliedSettings($settings, $replace, $clear) {
            throw new \\FreePBX\\modules\\SlsConfigurationWriteException("Settings were replaced; directory synchronization failed.", true);
        }
        ' . $apiBody . '}');
    $path = $writer::SETTINGS_JSON;
    $assert = static function ($condition, $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    };
    $reset = static function () use ($path): string {
        $GLOBALS['config_fixture_calls'] = [];
        $GLOBALS['config_fixture_failure'] = '';
        unset($GLOBALS['config_fixture_random']);
        $old = "{\"original\":true}\n";
        file_put_contents($path, $old);
        return $old;
    };
    try {
        $api = new ConfigDurabilityApiFixture();
        $result = $api->controlApiUpdateConfig(['settings' => ['tts_max_seconds' => 120], 'apply' => true]);
        $assert($result['success'] === false && $result['settings_replaced'] === true
            && strpos($result['message'], 'Settings were replaced') === 0, 'Control API hid the post-replacement storage status.');
        $reset();
        $writer->normalizedSettingsCache[$path] = ['old'];
        $writer->writeSettingsFileUnlocked($path, ['new' => 'complete message'], false);
        $assert(\SlsConfigDurabilityFixture\SlsConfigCrypto::decode(file_get_contents($path)) === ['new' => 'complete message'], 'Short writes lost encrypted configuration bytes.');
        $assert(strpos(file_get_contents($path), 'complete message') === false, 'The configuration writer retained cleartext settings.');
        $assert($GLOBALS['config_fixture_calls'] === ['sync_file', 'rename', 'sync_directory'], 'Configuration was not synchronized before and after replacement.');
        $assert((fileperms($path) & 0777) === 0640, 'Configuration replacement did not retain protected permissions.');
        $assert(!isset($writer->normalizedSettingsCache[$path]), 'Configuration cache retained old settings.');

        foreach (['write', 'file'] as $failure) {
            $old = $reset();
            $GLOBALS['config_fixture_failure'] = $failure;
            try { $writer->writeSettingsFileUnlocked($path, ['new' => true], false); throw new LogicException('Expected failure was not raised.'); }
            catch (RuntimeException $error) { $assert(strpos($error->getMessage(), 'preserved') !== false, 'Pre-commit error did not explain preserved settings.'); }
            $assert(file_get_contents($path) === $old, 'Failed staging replaced the existing configuration.');
            $assert(glob($path . '.tmp.*') === [], 'Failed staging left temporary sensitive contents.');
        }

        $reset();
        $GLOBALS['config_fixture_failure'] = 'directory';
        try { $writer->writeSettingsFileUnlocked($path, ['committed' => true], false); throw new LogicException('Missing post-commit error.'); }
        catch (RuntimeException $error) { $assert(strpos($error->getMessage(), 'Settings were replaced') !== false, 'Post-commit error incorrectly claimed old settings were preserved.'); }
        $assert(\SlsConfigDurabilityFixture\SlsConfigCrypto::decode(file_get_contents($path)) === ['committed' => true], 'Post-commit fixture did not replace encrypted settings.');
        $assert($writer->fingerprints[$path] === hash_file('sha256', $path), 'Post-commit failure retained a stale fingerprint.');

        $old = $reset();
        try { $writer->writeSettingsFileUnlocked($path, ['oversized' => str_repeat('x', 1100)], true); throw new LogicException('Oversized configuration was accepted.'); }
        catch (DomainException $error) { $assert(strpos($error->getMessage(), 'No settings were written') !== false, 'Oversized configuration error was unclear.'); }
        $assert(file_get_contents($path) === $old && $writer->backups === 0, 'Oversized rejection modified config or backups.');

        $old = $reset();
        $GLOBALS['config_fixture_random'] = str_repeat('a', 16);
        $collision = $path . '.tmp.' . bin2hex($GLOBALS['config_fixture_random']);
        file_put_contents($collision, 'unrelated file');
        try { $writer->writeSettingsFileUnlocked($path, ['new' => true], false); throw new LogicException('Existing staging path was overwritten.'); }
        catch (RuntimeException $error) { $assert(strpos($error->getMessage(), 'staging file') !== false, 'Staging collision lacked a specific error.'); }
        $assert(file_get_contents($collision) === 'unrelated file' && file_get_contents($path) === $old, 'Exclusive staging modified an existing file.');
        unlink($collision);

        $reset();
        $target = $directory . '/unrelated';
        file_put_contents($target, 'unrelated file');
        unlink($path);
        symlink($target, $path);
        try { $writer->writeSettingsFileUnlocked($path, ['new' => true], false); throw new LogicException('Configuration symlink accepted.'); }
        catch (RuntimeException $error) { $assert(strpos($error->getMessage(), 'safe regular file') !== false, 'Unsafe configuration error was unclear.'); }
        $assert(file_get_contents($target) === 'unrelated file', 'Configuration symlink changed another file.');
        unlink($path);
        link($target, $path);
        try { $writer->writeSettingsFileUnlocked($path, ['new' => true], false); throw new LogicException('Configuration hardlink accepted.'); }
        catch (RuntimeException $error) { $assert(strpos($error->getMessage(), 'safe regular file') !== false, 'Hardlink rejection was unclear.'); }
        echo "Protected configuration: complete short writes, durable replacement ordering, failure preservation, private staging, size bounds and link rejection passed.\n";
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($directory);
    }
}
