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
	function fsync($handle) {
		$uri = (string)(stream_get_meta_data($handle)['uri'] ?? '');
		if (($GLOBALS['native_sync_path'] ?? '') !== '' && $uri === $GLOBALS['native_sync_path']
			&& ($GLOBALS['native_sync_path_skip'] ?? 0) > 0) {
			$GLOBALS['native_sync_path_skip']--;
			return \fsync($handle);
		}
		return empty($GLOBALS['native_sync_failure'])
			&& (($GLOBALS['native_sync_path'] ?? '') === '' || $uri !== $GLOBALS['native_sync_path'])
			&& \fsync($handle);
	}
    function rename($from, $to) {
		if (str_contains($from, '/.freepbx-restore-stage-')) {
			$GLOBALS['native_activation_order'][] = basename($to);
			if (!empty($GLOBALS['native_fail_config_activation']) && str_ends_with($to, '/mass-notifications.config')) { return false; }
		}
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
	foreach (['commitNativeRestorePayload', 'backupNativeRestoreTargets', 'restoreNativeRestoreTargets',
		'writeNativeSnapshotFile', 'syncNativeDirectory', 'setNativeFileMetadata', 'nativeRestoreTargetLimit',
		'activateNativeRestoreFile', 'removeNativeRestoreTarget', 'readNativeBackupFile', 'removeNativeBackupDirectory',
		'unlinkNamedFileIfIdentity'] as $name) {
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
			const NATIVE_BACKUP_MAX_CONFIG_BYTES=2097152;
			const NATIVE_BACKUP_MAX_LEDGER_BYTES=10485760;
			const NATIVE_BACKUP_MAX_TONE_BYTES=20971520;
			const NATIVE_BACKUP_MAX_TONES_BYTES=209715200;
            public $status=[];
            function snapshotRead($path,$max=1024){return $this->readNativeBackupFile($path,$max,"fixture read refused");}
            function snapshotWrite($path,$body){return $this->writeNativeSnapshotFile($path,$body,0600);}
			function cleanup($path){return $this->removeNativeBackupDirectory($path);}
            function run(){ $this->commitNativeRestorePayload("{\"enabled\":\"0\"}\n", null, false, [], "fixture", []); }
            function ensurePluginDataDir(){}
            function validateNativeBackupConfig($raw){return json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
            function normalizeNativeBackupTransactionId($id){return $id;}
			function nativeRestoreMarkerContents($id,$warnings,$state="committed"){return json_encode(["version"=>2,"transaction_id"=>$id,"restored_at"=>gmdate("c"),"commit_state"=>$state])."\n";}
			function writePrivateAtomicFile($path,$body,$maximum){
				if(!empty($GLOBALS["native_final_marker_failure"]))throw new \RuntimeException("fixture final marker failure");
				$tmp=$path.".fixture.".bin2hex(random_bytes(4));file_put_contents($tmp,$body);chmod($tmp,0640);rename($tmp,$path);return true;
			}
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
		mkdir($root . '/unapproved', 0700);
		mkdir($root . '/unapproved/slsmassnotify-backup-outside', 0700);
		file_put_contents($root . '/unapproved/slsmassnotify-backup-outside/evidence', 'keep');
		$module->cleanup($root . '/unapproved/slsmassnotify-backup-outside');
		recoveryCheck(is_file($root . '/unapproved/slsmassnotify-backup-outside/evidence'),
			'Cleanup escaped its approved immediate parent.');
		$approvedCleanup = $root . '/slsmassnotify-backup-approved';
		mkdir($approvedCleanup, 0700); file_put_contents($approvedCleanup . '/evidence', 'remove');
		$module->cleanup($approvedCleanup);
		recoveryCheck(!file_exists($approvedCleanup), 'Cleanup did not remove an approved private workspace.');
		foreach (['none', 'rename', 'corrupt', 'status', 'directory_sync'] as $fault) {
			$GLOBALS['native_activation_order'] = [];
			$GLOBALS['native_fail_config_activation'] = true;
			$GLOBALS['native_final_marker_failure'] = false;
            $GLOBALS['rollback_fault'] = $fault === 'status' ? 'rename' : $fault;
            $GLOBALS['status_fault'] = $fault === 'status';
			$GLOBALS['native_sync_path'] = $fault === 'directory_sync' ? $root . '/live' : '';
			$GLOBALS['native_sync_path_skip'] = $fault === 'directory_sync' ? 1 : 0;
            file_put_contents($root . '/live/mass-notifications.config', $original); chmod($root . '/live/mass-notifications.config', 0640);
            $error = null;
            try { $module->run(); } catch (RuntimeException $caught) { $error = $caught; }
            recoveryCheck($error !== null, 'Failed restore reported success.');
			recoveryCheck(($GLOBALS['native_activation_order'][0] ?? '') === 'freepbx-restore-pending.json',
				'Restore changed live data before activating its recovery marker (' . $fault . ': '
				. json_encode($GLOBALS['native_activation_order']) . ').');
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
		$GLOBALS['native_activation_order'] = [];
		$GLOBALS['native_fail_config_activation'] = false;
		$GLOBALS['native_final_marker_failure'] = true;
		$GLOBALS['native_sync_path'] = '';
		$GLOBALS['rollback_fault'] = '';
		file_put_contents($root . '/live/mass-notifications.config', $original); chmod($root . '/live/mass-notifications.config', 0640);
		$error = null;
		try { $module->run(); } catch (RuntimeException $caught) { $error = $caught; }
		recoveryCheck($error !== null && file_get_contents($root . '/live/mass-notifications.config') === $original,
			'Failure to commit the final restore marker did not roll back the activated configuration.');
		recoveryCheck(!file_exists($root . '/live/freepbx-restore-pending.json'),
			'Rollback retained a preparing marker that did not exist before restore.');
		$GLOBALS['native_activation_order'] = [];
		$GLOBALS['native_final_marker_failure'] = false;
		$module->run();
		$committedMarker = json_decode((string)file_get_contents($root . '/live/freepbx-restore-pending.json'), true);
		recoveryCheck(($GLOBALS['native_activation_order'][0] ?? '') === 'freepbx-restore-pending.json'
			&& ($committedMarker['commit_state'] ?? '') === 'committed',
			'A successful restore did not durably transition its first marker from preparing to committed.');
		unset($GLOBALS['native_sync_path']);
		echo "Native restore: marker ordering, rollback verification, cleanup containment, corruption, rename, status and directory-sync faults passed.\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
