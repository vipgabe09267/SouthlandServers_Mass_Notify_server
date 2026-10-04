<?php
declare(strict_types=1);
namespace SlsNativeRecoveryFixture {
    final class SlsConfigCrypto {
        public static function encode(array $settings): string {
            return \FreePBX\modules\SlsConfigCrypto::encodeWithKeyring($settings, $GLOBALS['native_crypto_keyring']);
        }
    }
    function sys_get_temp_dir() { return $GLOBALS['native_recovery_fixture']; }
    function fopen($path, $mode) {
        if (($GLOBALS['native_swap_fifo'] ?? '') === $path && $mode === 'r+b') {
            unset($GLOBALS['native_swap_fifo']); unlink($path); posix_mkfifo($path, 0600);
        }
        return \fopen($path, $mode);
    }
    function fwrite($handle, $body) {
        if (!empty($GLOBALS['native_short_write'])) { return \fwrite($handle, substr($body, 0, 3)); }
        return \fwrite($handle, $body);
    }
    function fsync($handle) { return empty($GLOBALS['native_sync_failure']) && \fsync($handle); }
    function rename($from, $to) {
        if (str_contains($from, '/.freepbx-restore-stage-') && str_ends_with($from, '/freepbx-restore-pending.json')) { return false; }
        if (str_contains($from, '.restore-rollback-')) {
            if (($GLOBALS['rollback_fault'] ?? '') === 'rename') { return false; }
            if (($GLOBALS['rollback_fault'] ?? '') === 'corrupt') { file_put_contents($from, 'changed-during-rollback'); }
        }
        return \rename($from, $to);
    }
}
namespace {
    interface BMO {}
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    function recoveryCheck(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $root = sys_get_temp_dir() . '/sls-native-recovery-' . bin2hex(random_bytes(8));
    mkdir($root, 0700); mkdir($root . '/live', 0700); mkdir($root . '/live/tones', 0700);
    $GLOBALS['native_recovery_fixture'] = $root;
    $keyId = bin2hex(random_bytes(16));
    $GLOBALS['native_crypto_keyring'] = ['format' => \FreePBX\modules\SlsConfigCrypto::KEYRING_FORMAT, 'active' => $keyId,
        'keys' => [$keyId => ['created_at' => time(), 'key' => base64_encode(random_bytes(32))]]];
    $reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
    $lines = file($reflection->getFileName()); $methods = '';
    foreach (['commitNativeRestorePayload', 'backupNativeRestoreTargets', 'restoreNativeRestoreTargets', 'writeNativeSnapshotFile', 'readNativeBackupFile', 'removeNativeBackupDirectory'] as $name) {
        $method = $reflection->getMethod($name);
        $methods .= implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
    eval('namespace SlsNativeRecoveryFixture;
        class Module {
            const PLUGIN_DATA_DIR=' . var_export($root . '/live', true) . ';
            const TONES_DIR=self::PLUGIN_DATA_DIR . "/tones";
            const SETTINGS_JSON=self::PLUGIN_DATA_DIR . "/mass-notifications.config";
            const PENDING_SETTINGS_JSON=self::PLUGIN_DATA_DIR . "/mass-notifications.pending.config";
            const SCHEDULE_STATE_JSON=self::PLUGIN_DATA_DIR . "/schedule-executions.json";
            const FREEPBX_RESTORE_MARKER=self::PLUGIN_DATA_DIR . "/freepbx-restore-pending.json";
            const SCHEDULE_LOCK_FILE=self::PLUGIN_DATA_DIR . "/schedule.lock";
            const ANNOUNCEMENT_LOCK_FILE=self::PLUGIN_DATA_DIR . "/announcement.lock";
            const DEFAULT_ANNOUNCEMENT_OPENING_TONE="open";
            const DEFAULT_ANNOUNCEMENT_CLOSING_TONE="close";
            const DEFAULT_NWS_OPENING_TONE="weather";
            const DEFAULT_LIGHTNING_OPENING_TONE="lightning";
            public $status=[];
            function snapshotRead($path,$max=1024){return $this->readNativeBackupFile($path,$max,"fixture read refused");}
            function snapshotWrite($path,$body){return $this->writeNativeSnapshotFile($path,$body,0600);}
            function run(){ $this->commitNativeRestorePayload("{\"enabled\":\"0\"}\n", null, false, [], "fixture", []); }
            function ensurePluginDataDir(){}
            function validateNativeBackupConfig($raw){return json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
            function normalizeNativeBackupTransactionId($id){return $id;}
            function nativeRestoreMarkerContents($id,$warnings){return "{}\n";}
            function acquireNativeBackupFileLock($path,$message){$h=fopen($path,"c+b");flock($h,LOCK_EX);return $h;}
            function acquireSettingsLock($exclusive){return $this->acquireNativeBackupFileLock(self::PLUGIN_DATA_DIR . "/settings.lock", "");}
            function releaseNativeBackupFileLock($h){if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
            function releaseSettingsLock($h){$this->releaseNativeBackupFileLock($h);}
            function assertDesktopCapacityChange($a,$b){}
            function loadSettingsFile($path){return is_file($path)?json_decode(file_get_contents($path),true):[];}
            function backupAppliedSettings(){}
            function setPrivateOwnership($path){chmod($path,0640);}
            function rememberSettingsFingerprint($path){}
            function updateStatusData($data){$this->status=$data;if(!empty($GLOBALS["status_fault"]))throw new \\RuntimeException("fixture status write failed");}
            ' . $methods . '}');
    $module = new SlsNativeRecoveryFixture\Module();
    $original = "{\"enabled\":\"1\"}\n";
    try {
        $file = $root . '/io-fixture';
        $GLOBALS['native_short_write'] = true;
        $module->snapshotWrite($file, 'complete bytes despite short writes');
        unset($GLOBALS['native_short_write']);
        recoveryCheck($module->snapshotRead($file) === 'complete bytes despite short writes', 'Snapshot lost bytes during short writes.');
        $refused = false;
        try { $module->snapshotWrite($file, 'must not overwrite'); } catch (RuntimeException $error) { $refused = true; }
        recoveryCheck($refused && $module->snapshotRead($file) === 'complete bytes despite short writes', 'Snapshot replaced an existing file.');
        link($file, $root . '/hardlink');
        $refused = false;
        try { $module->snapshotRead($file); } catch (RuntimeException $error) { $refused = true; }
        recoveryCheck($refused, 'Native snapshot accepted a hard-linked source.'); unlink($root . '/hardlink');
        $GLOBALS['native_swap_fifo'] = $file; $refused = false;
        try { $module->snapshotRead($file); } catch (RuntimeException $error) { $refused = true; }
        recoveryCheck($refused, 'Native snapshot accepted a FIFO substituted after its initial check.'); unlink($file);
        $GLOBALS['native_sync_failure'] = true; $refused = false;
        try { $module->snapshotWrite($file, 'not synchronized'); } catch (RuntimeException $error) { $refused = true; }
        unset($GLOBALS['native_sync_failure']);
        recoveryCheck($refused && !file_exists($file), 'Unsynchronized snapshot was retained as successful.');
        foreach (['none', 'rename', 'corrupt', 'status'] as $fault) {
            $GLOBALS['rollback_fault'] = $fault === 'status' ? 'rename' : $fault;
            $GLOBALS['status_fault'] = $fault === 'status';
            file_put_contents($root . '/live/mass-notifications.config', $original); chmod($root . '/live/mass-notifications.config', 0640);
            $error = null;
            try { $module->run(); } catch (RuntimeException $caught) { $error = $caught; }
            recoveryCheck($error !== null, 'Failed restore reported success.');
            $rollback = glob($root . '/slsmassnotify-rollback-fixture-*');
            $stage = glob($root . '/live/.freepbx-restore-stage-fixture-*');
            if ($fault === 'none') {
                recoveryCheck(!$rollback && !$stage, 'Verified rollback left obsolete recovery material.');
                recoveryCheck(file_get_contents($root . '/live/mass-notifications.config') === $original, 'Successful rollback did not recover original bytes.');
            } else {
                recoveryCheck(count($rollback) === 1 && count($stage) === 1, 'Incomplete rollback deleted a recovery directory.');
                recoveryCheck(strpos($error->getMessage(), $rollback[0]) !== false && strpos($error->getMessage(), $stage[0]) !== false,
                    'Recovery locations were omitted from the actionable error.');
                recoveryCheck(file_get_contents($rollback[0] . '/0000.rollback') === $original, 'Original recovery copy was lost or altered.');
            }
            foreach (array_merge($rollback, $stage) as $directory) {
                foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
                rmdir($directory);
            }
        }
        echo "Native restore: failed activation, verified rollback, failed rename, undetected copy corruption and status-write faults preserve recovery correctly.\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
