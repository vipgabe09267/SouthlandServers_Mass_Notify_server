<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Slsmassnotifyserver implements \BMO
{
	const MODULE_VERSION = '0.1.0';
	const EVENTS_LOG = '/var/log/sls_mass_notify_events.jsonl';
	const LEGACY_EVENTS_LOG = '/var/log/nws_weather_alert_events.jsonl';
	const PLUGIN_DATA_DIR = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
	const SETTINGS_JSON = self::PLUGIN_DATA_DIR . '/mass-notifications.config';
	const PENDING_SETTINGS_JSON = self::PLUGIN_DATA_DIR . '/mass-notifications.pending.config';
	const SETTINGS_LOCK = self::PLUGIN_DATA_DIR . '/mass-notifications.config.lock';
	const SETTINGS_SHELL = self::PLUGIN_DATA_DIR . '/mass-notifications.conf';
	const LEGACY_SETTINGS_JSON = self::PLUGIN_DATA_DIR . '/mass-notifications-' . 'settings.json';
	const LEGACY_PENDING_SETTINGS_JSON = self::PLUGIN_DATA_DIR . '/mass-notifications-' . 'settings.pending.json';
	const LEGACY_OLD_SETTINGS_JSON = '/var/lib/asterisk/slsmassnotifyserver-settings.json';
	const LEGACY_OLD_PENDING_SETTINGS_JSON = '/var/lib/asterisk/slsmassnotifyserver-settings.pending.json';
	const LEGACY_SETTINGS_SHELL = '/var/lib/asterisk/slsmassnotifyserver.conf';
	const STATUS_JSON = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json';
	const RUNTIME_DIR = '/usr/local/bin/sls_mass_notify';
	const SOUNDS_DIR = self::PLUGIN_DATA_DIR . '/sounds';
	const TONES_DIR = self::SOUNDS_DIR . '/tones';
	const TTS_DIR = self::SOUNDS_DIR . '/tts';
	const PIPER_DATA_DIR = self::PLUGIN_DATA_DIR . '/piper';
	const PIPER_VOICE_DIR = self::PIPER_DATA_DIR . '/voices';
	const PIPER_RUNTIME_DIR = self::RUNTIME_DIR . '/piper';
	const PIPER_BIN = self::PIPER_RUNTIME_DIR . '/venv/bin/piper';
	const PIPER_VOICE = self::PIPER_VOICE_DIR . '/en_US-lessac-low.onnx';
	const PIPER_AMY_VOICE = self::PIPER_VOICE_DIR . '/en_US-amy-low.onnx';
	const DEFAULT_ANNOUNCEMENT_OPENING_TONE = 'opening_Paging_Tone_Opening';
	const DEFAULT_ANNOUNCEMENT_CLOSING_TONE = 'closing_Paging_Tone_Closing';
	const DEFAULT_NWS_OPENING_TONE = 'opening_NWS_alert';
	const DEFAULT_LIGHTNING_OPENING_TONE = 'opening_Lightning_alert';
	const ASTERISK_SOUND_PREFIX = 'SLS_Mass_Notifications_Plugin';
	const ASTERISK_OUTGOING_SPOOL = '/var/spool/asterisk/outgoing';
	const ASTERISK_SPOOL_TMP = '/var/spool/asterisk/tmp';
	const TEST_SCRIPT = self::RUNTIME_DIR . '/sls_mass_notify_test.sh';
	const VISUAL_PUSH_SCRIPT = self::RUNTIME_DIR . '/sls_notify.py';
	const PIPER_VOICE_INSTALL_SCRIPT = self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh';
	const TEST_COOLDOWN_FILE = self::PLUGIN_DATA_DIR . '/test-cooldown.ts';
	const LIGHTNING_TEST_COOLDOWN_FILE = self::PLUGIN_DATA_DIR . '/lightning-test-cooldown.ts';
	const XWEATHER_WORKER_LOCK_FILE = self::PLUGIN_DATA_DIR . '/xweather-poll.lock';
	const ANNOUNCEMENT_COOLDOWN_FILE = self::PLUGIN_DATA_DIR . '/announcement-cooldown.ts';
	const ANNOUNCEMENT_LOCK_FILE = self::PLUGIN_DATA_DIR . '/announcement-send.lock';
	const SCHEDULE_STATE_JSON = self::PLUGIN_DATA_DIR . '/schedule-executions.json';
	const SCHEDULE_LOCK_FILE = self::PLUGIN_DATA_DIR . '/schedule-worker.lock';
	const FREEPBX_RESTORE_MARKER = self::PLUGIN_DATA_DIR . '/freepbx-restore-pending.json';
	const CONTROL_API_AUDIT_LOG = self::PLUGIN_DATA_DIR . '/control-api-audit.jsonl';
	const CONTROL_API_RATE_FILE = self::PLUGIN_DATA_DIR . '/control-api-ratelimit.json';
	const REPAIR_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/repair.request';
	const UPDATE_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/update.request';
	const UPDATE_PROGRESS_FILE = self::PLUGIN_DATA_DIR . '/update-progress.json';
	const UNINSTALL_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/uninstall.request';
	const MAINTENANCE_PROGRESS_FILE = '/run/asterisk/sls-mass-notify-maintenance-progress.json';
	const INSTALL_FAILURE_JSON = self::PLUGIN_DATA_DIR . '/install-failure.json';
	const TEST_COOLDOWN_SECONDS = 60;
	const ANNOUNCEMENT_COOLDOWN_SECONDS = 60;
	const MIN_ANNOUNCEMENT_COOLDOWN_SECONDS = 5;
	const MAX_ANNOUNCEMENT_COOLDOWN_SECONDS = 600;
	const SCHEDULE_GRACE_SECONDS = 900;
	const MAX_SCHEDULES = 100;
	const MAX_SCHEDULE_OCCURRENCES = 366;
	const MAX_SCHEDULE_YEARS = 5;
	const NATIVE_BACKUP_SCHEMA_VERSION = 1;
	const NATIVE_BACKUP_MAX_CONFIG_BYTES = 2 * 1024 * 1024;
	const NATIVE_BACKUP_MAX_LEDGER_BYTES = 10 * 1024 * 1024;
	const NATIVE_BACKUP_MAX_TONES = 100;
	const NATIVE_BACKUP_MAX_TONE_BYTES = 20 * 1024 * 1024;
	const NATIVE_BACKUP_MAX_TONES_BYTES = 200 * 1024 * 1024;
	const MAX_ANNOUNCEMENT_TIMEOUT_SECONDS = 86400;
	const MAX_WEBHOOK_DESTINATIONS = 10;
	const HERO_IMAGE = 'modules/slsmassnotifyserver/assets/SLS_Mass_Notif_Plugin.png';
	const MAX_LIMIT = 500;
	const DEFAULT_LIMIT = 100;
	const CSRF_SESSION_KEY = 'slsmassnotifyserver_csrf_token';

	/** File fingerprints captured while a request builds a settings update. */
	private $settingsReadFingerprints = [];
	/** Normalized settings cached for the lifetime of the current PHP request. */
	private $normalizedSettingsCache = [];
	/** Request-local endpoint inventory caches avoid repeated AMI/database discovery. */
	private $registeredPjsipExtensionsCache = null;
	private $extensionNameMapCache = null;
	private $allPjsipExtensionsCache = null;
	private $sipNotifyTargetsCache = null;

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
	}

	public function install()
	{
		if (is_link(self::SETTINGS_JSON)) {
			throw new \RuntimeException(_('Protected central configuration must not be a symbolic link.'));
		}
		$this->ensurePluginDataDir();
		$this->migrateLegacyTestStatus();
		$this->ensureSystemDependencies();
		if (!is_readable(self::SETTINGS_JSON)) {
			$this->persistAppliedSettings($this->getActiveSettings());
		} else {
			// Updates and repair operations must never rewrite an existing central config.
			$this->getActiveSettings();
			$this->setPrivateOwnership(self::SETTINGS_JSON);
		}
		$this->installRuntimeFiles();
		$this->ensureBundledSystemRecordings();
		$this->ensurePiperRuntime();
		$this->ensureAmiUser();
		$this->ensureDialplan();
		$this->ensureSipNotifyTemplates();
		$this->ensureApacheConfig();
		$this->ensureMenuPlacement();
		$this->ensureDashboardWidget();
		$this->ensureCronJob();
		$backupEnrollment = $this->ensureFreePbxBackupEnrollment();
		if (empty($backupEnrollment['success'])) {
			throw new \RuntimeException(_('FreePBX backup enrollment could not be verified.'));
		}
		$this->cleanupLegacyRuntimeArtifacts();
		if (getenv('SLS_MASS_NOTIFY_DEFER_SIGNING') !== '1') {
			$this->signLocalModulesIfAvailable(true);
		}
		// Failure state is cleared only by the release installer, the protected
		// maintenance verifier, or the post-restore verifier after every required
		// integration postcondition has passed. A successful install() return alone
		// is deliberately insufficient.
	}

	public function uninstall()
	{
		$this->removeFreePbxBackupEnrollment();
		$this->removeCronJob();
		$this->removeAmiUsers();
		$this->removeDashboardWidget();
		$this->removeMenuPlacement();
		$this->removePiperWrapper();
		$this->removeApacheConfig();
		$this->removeManagedBlock('/etc/asterisk/sip_notify_custom.conf', 'SLS Mass Notifications SIP NOTIFY Templates');
		$this->removeManagedBlock('/etc/asterisk/extensions_custom.conf', 'SLS Mass Notifications Dialplan');
		$this->removeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI');
		$this->removeBundledSystemRecordings();
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('module reload res_pjsip_notify.so'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('manager reload'));
		$this->repairPostUninstallSignatures();
		$this->removeRuntimeIntegrationFiles();
		$this->deleteInstallFailureNotification();
	}
	public function backup()
	{
		return [
			'settings' => $this->getActiveSettings(),
			'version' => self::MODULE_VERSION,
		];
	}

	public function restore($backup)
	{
		if (is_array($backup) && is_array($backup['settings'] ?? null)) {
			$settings = $this->normalizeSettings($backup['settings']);
			foreach ((array)($settings['scheduled_announcements'] ?? []) as $index => $schedule) {
				$settings['scheduled_announcements'][$index]['enabled'] = '0';
			}
			$lock = $this->acquireSettingsLock();
			try {
				$this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $settings, true);
				if (is_file(self::PENDING_SETTINGS_JSON) && !@unlink(self::PENDING_SETTINGS_JSON)) {
					throw new \RuntimeException(_('The restored configuration was written, but stale staged settings could not be removed safely.'));
				}
				$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
				$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
			} finally {
				$this->releaseSettingsLock($lock);
			}
		}
	}

	/**
	 * Build an immutable, private snapshot for FreePBX 17's module backup API.
	 * The backup adapter registers the returned files only after every file has
	 * been validated and hashed.
	 */
	public function createFreePbxBackupSnapshot($transactionId)
	{
		if (is_link(self::PLUGIN_DATA_DIR) || is_link(self::TONES_DIR)) {
			throw new \RuntimeException(_('The protected backup directories must not be symbolic links.'));
		}
		$this->ensurePluginDataDir();
		$transactionId = $this->normalizeNativeBackupTransactionId($transactionId);
		$snapshotDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
			. DIRECTORY_SEPARATOR . 'slsmassnotify-backup-' . $transactionId . '-' . bin2hex(random_bytes(4));
		if (!@mkdir($snapshotDir, 0700, true) || is_link($snapshotDir)) {
			throw new \RuntimeException(_('Unable to create the protected Mass Notifications backup snapshot.'));
		}

		$workerLock = null;
		$announcementLock = null;
		$configLock = null;
		try {
			$workerLock = $this->acquireNativeBackupFileLock(
				self::SCHEDULE_LOCK_FILE,
				_('The scheduled-announcement worker did not become idle in time for backup.')
			);
			$announcementLock = $this->acquireNativeBackupFileLock(
				self::ANNOUNCEMENT_LOCK_FILE,
				_('An active announcement did not complete in time for backup.')
			);
			$configLock = $this->acquireSettingsLock();

			$configRaw = $this->readNativeBackupFile(
				self::SETTINGS_JSON,
				self::NATIVE_BACKUP_MAX_CONFIG_BYTES,
				_('The protected central configuration is unavailable for backup.')
			);
			$this->validateNativeBackupConfig($configRaw);
			$configSnapshot = $snapshotDir . '/protected-config.json';
			$this->writeNativeSnapshotFile($configSnapshot, $configRaw, 0600);

			$files = [[
				'type' => 'slsmassnotify-config',
				'filename' => basename($configSnapshot),
				'path' => $snapshotDir,
			]];
			$manifestFiles = [
				'config' => $this->nativeBackupFileManifest(
					'slsmassnotify-config',
					basename($configSnapshot),
					basename(self::SETTINGS_JSON),
					$configSnapshot
				),
				'schedule' => null,
				'tones' => [],
			];

			if (file_exists(self::SCHEDULE_STATE_JSON)) {
				$ledgerRaw = $this->readNativeBackupFile(
					self::SCHEDULE_STATE_JSON,
					self::NATIVE_BACKUP_MAX_LEDGER_BYTES,
					_('The scheduled-announcement execution journal is unavailable for backup.')
				);
				$this->validateNativeScheduleLedger($ledgerRaw);
				$ledgerSnapshot = $snapshotDir . '/schedule-executions.json';
				$this->writeNativeSnapshotFile($ledgerSnapshot, $ledgerRaw, 0600);
				$files[] = [
					'type' => 'slsmassnotify-schedule',
					'filename' => basename($ledgerSnapshot),
					'path' => $snapshotDir,
				];
				$manifestFiles['schedule'] = $this->nativeBackupFileManifest(
					'slsmassnotify-schedule',
					basename($ledgerSnapshot),
					basename(self::SCHEDULE_STATE_JSON),
					$ledgerSnapshot
				);
			}

			$bundledTones = array_fill_keys([
				self::DEFAULT_ANNOUNCEMENT_OPENING_TONE,
				self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE,
				self::DEFAULT_NWS_OPENING_TONE,
				self::DEFAULT_LIGHTNING_OPENING_TONE,
			], true);
			$totalToneBytes = 0;
			foreach (glob(self::TONES_DIR . '/*.wav') ?: [] as $tonePath) {
				$toneName = basename($tonePath, '.wav');
				if (isset($bundledTones[$toneName])) {
					continue;
				}
				if (count($manifestFiles['tones']) >= self::NATIVE_BACKUP_MAX_TONES) {
					throw new \RuntimeException(_('Too many custom tones are present for a protected backup.'));
				}
				$this->assertNativeToneName($toneName);
				$toneRaw = $this->readNativeBackupFile(
					$tonePath,
					self::NATIVE_BACKUP_MAX_TONE_BYTES,
					_('A custom announcement tone is unavailable for backup.')
				);
				$this->validateNativeWaveContents($toneRaw, $toneName);
				$totalToneBytes += strlen($toneRaw);
				if ($totalToneBytes > self::NATIVE_BACKUP_MAX_TONES_BYTES) {
					throw new \RuntimeException(_('Custom announcement tones exceed the protected backup size limit.'));
				}
				$archiveName = 'tone--' . $toneName . '.wav';
				$toneSnapshot = $snapshotDir . '/' . $archiveName;
				$this->writeNativeSnapshotFile($toneSnapshot, $toneRaw, 0600);
				$files[] = [
					'type' => 'slsmassnotify-tone',
					'filename' => $archiveName,
					'path' => $snapshotDir,
				];
				$manifestFiles['tones'][] = $this->nativeBackupFileManifest(
					'slsmassnotify-tone',
					$archiveName,
					$toneName . '.wav',
					$toneSnapshot
				);
			}

			$manifest = [
				'schema_version' => self::NATIVE_BACKUP_SCHEMA_VERSION,
				'module' => 'slsmassnotifyserver',
				'module_version' => self::MODULE_VERSION,
				'created_at' => gmdate('c'),
				'source_timezone' => $this->getPbxDateTimeZone()->getName(),
				'files' => $manifestFiles,
				'restore_policy' => [
					'past_occurrences' => 'mark_uncertain',
					'missing_ledger' => 'disable_schedules',
					'timezone_change' => 'disable_schedules',
				],
			];
			$this->updateStatusData([
				'last_native_backup_at' => gmdate('c'),
				'last_native_backup_status' => 'ok',
				'last_native_backup_message' => _('Protected configuration, scheduling state, and custom tones were snapshotted for FreePBX Backup.'),
			]);
			return [
				'manifest' => $manifest,
				'files' => $files,
				'garbage' => $snapshotDir,
			];
		} catch (\Throwable $e) {
			$this->removeNativeBackupDirectory($snapshotDir);
			$this->updateStatusData([
				'last_native_backup_at' => gmdate('c'),
				'last_native_backup_status' => 'fault',
				'last_native_backup_message' => $this->sanitizeScheduleText($e->getMessage(), 300, true),
			]);
			throw $e;
		} finally {
			if (is_resource($configLock)) {
				$this->releaseSettingsLock($configLock);
			}
			$this->releaseNativeBackupFileLock($announcementLock);
			$this->releaseNativeBackupFileLock($workerLock);
		}
	}

	/** Restore a fully validated native FreePBX module snapshot. */
	public function restoreFreePbxBackupSnapshot(array $manifest, array $files, $backupTmpDir, $transactionId)
	{
		if (is_link(self::PLUGIN_DATA_DIR) || is_link(self::TONES_DIR)) {
			throw new \RuntimeException(_('The protected restore directories must not be symbolic links.'));
		}
		$this->ensurePluginDataDir();
		$transactionId = $this->normalizeNativeBackupTransactionId($transactionId);
		$validatedManifest = $this->validateNativeBackupManifest($manifest);
		$payload = $this->loadNativeRestorePayload($validatedManifest, $files, $backupTmpDir);
		$prepared = $this->prepareNativeRestoredScheduleState(
			$payload['config'],
			$payload['schedule'],
			(string)($validatedManifest['source_timezone'] ?? ''),
			time()
		);
		$this->commitNativeRestorePayload(
			$prepared['config_raw'],
			$prepared['schedule_raw'],
			$prepared['schedule_present'],
			$payload['tones'],
			$transactionId,
			$prepared['warnings']
		);
		$this->updateStatusData([
			'last_native_restore_at' => gmdate('c'),
			'last_native_restore_status' => empty($prepared['warnings']) ? 'ok' : 'warning',
			'last_native_restore_message' => empty($prepared['warnings'])
				? _('FreePBX restored the protected Mass Notifications data. Integration repair is pending.')
				: implode(' ', $prepared['warnings']),
		]);
		return [
			'success' => true,
			'warnings' => $prepared['warnings'],
		];
	}

	/** Repair generated integration only after all FreePBX modules are restored. */
	public function postRestoreHook($transactionId, $backupInfo = [])
	{
		$transactionId = $this->normalizeNativeBackupTransactionId($transactionId);
		$marker = $this->loadNativeRestoreMarker();
		if (!is_array($marker) || !hash_equals((string)($marker['transaction_id'] ?? ''), $transactionId)) {
			return true;
		}
		$result = $this->repairInstallation();
		if (empty($result['success'])) {
			$this->updateStatusData([
				'last_native_restore_status' => 'fault',
				'last_native_restore_message' => _('Protected data was restored, but FreePBX integration repair failed.'),
			]);
			throw new \RuntimeException(_('Mass Notifications restore integration repair failed.'));
		}

		$effectiveUid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
		if ($effectiveUid === 0) {
			try {
				$this->verifyProtectedRepairIntegration();
			} catch (\Throwable $e) {
				$this->updateStatusData([
					'last_native_restore_status' => 'fault',
					'last_native_restore_message' => $this->sanitizeScheduleText($e->getMessage(), 300, true),
				]);
				throw $e;
			}
		} else {
			$this->updateStatusData([
				'last_native_restore_status' => 'warning',
				'last_native_restore_message' => _('Protected data was restored; root integration repair was queued.'),
			]);
		}
		return true;
	}

	/**
	 * Verify every safety-critical postcondition before a protected repair may
	 * clear the persistent install-failure warning.
	 */
	public function verifyProtectedRepairIntegration()
	{
		$effectiveUid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
		if ($effectiveUid !== 0) {
			throw new \RuntimeException(_('Protected repair verification must run as root.'));
		}
		$verified = $this->verifyNativePostRestoreIntegration();
		$this->finalizeNativeRestoreAfterVerifiedRepair();
		return $verified;
	}

	/**
	 * Complete a queued native restore only after the shared root verifier has
	 * accepted every protected integration postcondition. Generic repairs have
	 * no restore marker and therefore leave restore status untouched.
	 */
	private function finalizeNativeRestoreAfterVerifiedRepair()
	{
		$marker = $this->loadNativeRestoreMarker();
		if ($marker === null) {
			return false;
		}
		clearstatcache(true, self::FREEPBX_RESTORE_MARKER);
		if (is_link(self::FREEPBX_RESTORE_MARKER) || !is_file(self::FREEPBX_RESTORE_MARKER)) {
			throw new \RuntimeException(_('The verified post-restore repair marker changed before finalization.'));
		}
		if (!@unlink(self::FREEPBX_RESTORE_MARKER)) {
			throw new \RuntimeException(_('The verified post-restore repair marker could not be removed safely.'));
		}
		// A prior installer failure is no longer relevant only after a complete,
		// root-owned repair has succeeded and the pending restore is finalized.
		$this->clearSuccessfulInstallFailureState();
		$this->updateStatusData([
			'last_native_restore_status' => 'ok',
			'last_native_restore_message' => _('FreePBX restore and Mass Notifications integration repair completed.'),
		]);
		return true;
	}

	/**
	 * Add this module to existing module-based FreePBX backup jobs without
	 * replacing any job option, custom-file list, or administrator hook.
	 */
	public function ensureFreePbxBackupEnrollment()
	{
		$result = [
			'success' => true,
			'available' => false,
			'jobs' => 0,
			'enrolled' => 0,
			'changed' => 0,
			'error' => '',
		];
		try {
			if (!$this->FreePBX->Modules->checkStatus('backup')) {
				return $result;
			}
			$backup = $this->FreePBX->Backup;
			$result['available'] = true;
			foreach (array_keys((array)$backup->getAll('backupList')) as $jobId) {
				$jobId = (string)$jobId;
				if ($jobId === '' || strlen($jobId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $jobId)) {
					continue;
				}
				$selected = $backup->getAll('modules_' . $jobId);
				// A custom-files-only job is intentionally left unchanged.
				if (!$this->freePbxBackupJobHasEnabledModules($selected)) {
					continue;
				}
				$result['jobs']++;
				if (!$this->freePbxBackupJobIncludesThisModule($selected)) {
					$backup->setConfig('slsmassnotifyserver', true, 'modules_' . $jobId);
					$result['changed']++;
				}
				$verifiedSelection = $backup->getAll('modules_' . $jobId);
				if (!$this->freePbxBackupJobIncludesThisModule($verifiedSelection)) {
					throw new \RuntimeException(_('FreePBX did not retain the Mass Notifications backup-job enrollment.'));
				}
				$result['enrolled']++;
			}
		} catch (\Throwable $e) {
			$result['success'] = false;
			$result['error'] = $this->sanitizeScheduleText($e->getMessage(), 240, true);
		}
		return $result;
	}

	private function freePbxBackupSelectionIsEnabled($value)
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int)$value === 1;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	private function freePbxBackupJobHasEnabledModules($selected)
	{
		if (!is_array($selected)) {
			return false;
		}
		foreach ($selected as $value) {
			if ($this->freePbxBackupSelectionIsEnabled($value)) {
				return true;
			}
		}
		return false;
	}

	private function freePbxBackupJobIncludesThisModule($selected)
	{
		return is_array($selected)
			&& array_key_exists('slsmassnotifyserver', $selected)
			&& $this->freePbxBackupSelectionIsEnabled($selected['slsmassnotifyserver']);
	}

	private function removeFreePbxBackupEnrollment()
	{
		try {
			if (!$this->FreePBX->Modules->checkStatus('backup')) {
				return;
			}
			$backup = $this->FreePBX->Backup;
			foreach (array_keys((array)$backup->getAll('backupList')) as $jobId) {
				$jobId = (string)$jobId;
				if ($jobId === '' || strlen($jobId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $jobId)) {
					continue;
				}
				$selected = $backup->getAll('modules_' . $jobId);
				if ($this->freePbxBackupJobIncludesThisModule($selected)) {
					$backup->setConfig('slsmassnotifyserver', false, 'modules_' . $jobId);
				}
			}
		} catch (\Throwable $e) {
			// Backup-job cleanup is best-effort during uninstall and must not leave
			// FreePBX core or the Dashboard in a partially removed state.
		}
	}

	public function getFreePbxBackupHealth()
	{
		$health = [
			'state' => 'warning',
			'native_adapter' => is_readable(__DIR__ . '/Backup.php') && is_readable(__DIR__ . '/Restore.php'),
			'backup_module' => false,
			'jobs' => 0,
			'enrolled' => 0,
			'automatic_reinstall' => false,
			'message' => _('Install the Mass Notifications module before restoring a FreePBX backup on a replacement PBX.'),
		];
		try {
			if (!$this->FreePBX->Modules->checkStatus('backup')) {
				$health['message'] = _('The FreePBX Backup module is not enabled.');
				return $health;
			}
			$health['backup_module'] = true;
			$backup = $this->FreePBX->Backup;
			foreach (array_keys((array)$backup->getAll('backupList')) as $jobId) {
				$selected = $backup->getAll('modules_' . (string)$jobId);
				if (!$this->freePbxBackupJobHasEnabledModules($selected)) {
					continue;
				}
				$health['jobs']++;
				if ($this->freePbxBackupJobIncludesThisModule($selected)) {
					$health['enrolled']++;
				}
			}
			if ($health['native_adapter'] && $health['jobs'] > 0 && $health['jobs'] === $health['enrolled']) {
				$health['state'] = 'ok';
				$health['message'] = _('Native FreePBX backup is configured. A replacement PBX still requires this custom module to be installed before restore.');
			} elseif ($health['native_adapter'] && $health['jobs'] === 0) {
				// A new FreePBX system may legitimately have no backup jobs yet. The
				// adapter is discoverable and new module-based jobs select available
				// module adapters by default, so absence of an administrator-defined
				// schedule/storage policy is not a Mass Notifications fault.
				$health['state'] = 'ok';
				$health['message'] = _('Native FreePBX backup is ready. No module-based backup job exists yet; create one in Backup & Restore when you are ready to choose its schedule and storage.');
			} elseif ($health['native_adapter']) {
				$health['message'] = _('One or more FreePBX backup jobs do not include Mass Notifications.');
			}
		} catch (\Throwable $e) {
			$health['message'] = _('FreePBX backup enrollment could not be inspected.');
		}
		return $health;
	}
	public function doConfigPageInit($page) {}
	public function getRightNav($request) {}
	public function getActionBar($request) {}

	/** Apply staged settings as part of FreePBX's native Apply Config/reload. */
	public function genConfig()
	{
		if ($this->getPendingSettings() === null) {
			return [];
		}
		$result = $this->applySettings();
		if (empty($result['success'])) {
			throw new \RuntimeException((string)($result['message'] ?? _('Unable to apply Mass Notifications settings.')));
		}
		return [];
	}

	/**
	 * FreePBX discovers native Apply Config participants by the presence of
	 * writeConfig(). All module settings are written atomically by genConfig(),
	 * so there is no additional generated file content to persist here.
	 */
	public function writeConfig($config)
	{
		return true;
	}

	public function getCsrfToken()
	{
		if (session_status() === PHP_SESSION_NONE) {
			@session_start();
		}
		$token = (string)($_SESSION[self::CSRF_SESSION_KEY] ?? '');
		if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::CSRF_SESSION_KEY] = $token;
		}
		return $token;
	}

	public function validateCsrfToken($token)
	{
		$expected = $this->getCsrfToken();
		$provided = trim((string)$token);
		return $provided !== '' && hash_equals($expected, $provided);
	}

	public function showPage($page = 'main', $params = [])
	{
		$activeSettings = $this->getActiveSettings();
		$pendingSettings = $this->getPendingSettings();
		$csrfToken = $this->getCsrfToken();
		if ($page !== 'setup' && !$this->isSetupComplete($activeSettings)) {
			return load_view(__DIR__ . '/views/setup_required.php', [
				'requested_page' => $page,
				'save_result' => $params['save_result'] ?? null,
				'setup_modal' => $this->renderSetupWizard($pendingSettings ?? $activeSettings, $activeSettings, $params['setup_result'] ?? null, true),
				'hero_image' => self::HERO_IMAGE,
			]);
		}

		switch ($page) {
			case 'setup':
				return $this->renderSetupWizard($pendingSettings ?? $activeSettings, $activeSettings, $params['save_result'] ?? null, false);
			case 'detail':
					return load_view(__DIR__ . '/views/detail.php', [
						'event' => $this->getEventById($params['id'] ?? ''),
						'hero_image' => self::HERO_IMAGE,
				]);
			case 'settings':
				return load_view(__DIR__ . '/views/settings.php', [
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings),
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'has_pending_changes' => $pendingSettings !== null,
					'events_map' => $this->getSupportedNwsEvents(),
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'test_result' => $params['test_result'] ?? null,
						'hero_image' => self::HERO_IMAGE,
						'csrf_token' => $csrfToken,
				]);
			case 'nws_alerts':
				$cooldown = $this->getTestCooldownState();
				return load_view(__DIR__ . '/views/settings.php', [
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings),
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'has_pending_changes' => $pendingSettings !== null,
					'events_map' => $this->getSupportedNwsEvents(),
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'test_result' => $params['test_result'] ?? null,
					'cooldown_remaining' => $cooldown['remaining'],
					'settings_display' => 'slsmassnotifyserver_nws',
					'show_test_section' => true,
						'hero_image' => self::HERO_IMAGE,
						'csrf_token' => $csrfToken,
				]);
			case 'lightning':
				$lightningCooldown = $this->getLightningTestCooldownState();
				return load_view(__DIR__ . '/views/lightning.php', [
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'has_pending_changes' => $pendingSettings !== null,
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings),
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'test_result' => $params['test_result'] ?? null,
					'connection_result' => $params['connection_result'] ?? null,
					'api_usage' => $this->getXweatherApiUsageSummary(),
					'cooldown_remaining' => $lightningCooldown['remaining'],
					'hero_image' => self::HERO_IMAGE,
					'csrf_token' => $csrfToken,
				]);
			case 'scheduling':
				return load_view(__DIR__ . '/views/scheduling.php', [
					'settings' => $activeSettings,
					'schedules' => $this->getScheduledAnnouncements(true),
					'schedule_execution_state' => $this->getScheduleExecutionState(),
					'available_extensions' => $this->getAllPjsipExtensions(),
					'announcement_groups' => $this->getAnnouncementGroups(),
					'desktop_clients' => $this->getDesktopClients($activeSettings),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_tones' => $this->getAvailableTones(),
					'pbx_timezone' => $this->getPbxDateTimeZone()->getName(),
					'save_result' => $params['save_result'] ?? null,
					'hero_image' => self::HERO_IMAGE,
					'csrf_token' => $csrfToken,
				]);
			case 'other_settings':
				return load_view(__DIR__ . '/views/other_settings.php', [
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'has_pending_changes' => $pendingSettings !== null,
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'import_result' => $params['import_result'] ?? null,
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings, true),
					'control_api_url' => $this->getControlApiUrl($pendingSettings ?? $activeSettings),
						'package_version' => self::MODULE_VERSION,
						'package_update_status' => $this->getPackageUpdateStatus(),
						'update_progress' => $params['update_progress'] ?? $this->getManualUpdateProgress(),
						'update_monitor_active' => !empty($params['update_monitor_active']),
						'maintenance_progress' => $params['maintenance_progress'] ?? $this->getMaintenanceProgress(),
						'maintenance_monitor_action' => (string)($params['maintenance_monitor_action'] ?? ''),
						'hero_image' => self::HERO_IMAGE,
						'csrf_token' => $csrfToken,
				]);
			case 'help':
				return load_view(__DIR__ . '/views/help.php', [
					'settings' => $activeSettings,
					'control_api_url' => $this->getControlApiUrl($activeSettings),
					'diagnostics' => $this->getDiagnosticsSummary(),
					'hero_image' => self::HERO_IMAGE,
				]);
			case 'main':
			default:
				$type = $this->sanitizeType($params['log_type'] ?? '');
				$limit = $this->sanitizeLimit($params['limit'] ?? self::DEFAULT_LIMIT);
				$date = $this->sanitizeLogDate($params['log_date'] ?? '');
				return load_view(__DIR__ . '/views/main.php', [
					'events' => $this->getEvents($limit, $type, $date),
					'status_summary' => $this->getStatusSummary(),
					'announcement_targets' => $this->getSipNotifyTargets(),
					'announcement_cooldown_remaining' => $this->getAnnouncementCooldownState()['remaining'],
					'log_path' => self::EVENTS_LOG,
					'selected_type' => $type,
					'selected_limit' => $limit,
					'selected_date' => $date,
					'hero_image' => self::HERO_IMAGE,
				]);
		}
	}

	private function isSetupComplete(array $settings = null)
	{
		$settings = $settings ?? $this->getActiveSettings();
		$setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
		return !empty($setup['completed'])
			&& !empty($setup['beta_accepted'])
			&& !empty($setup['agpl_accepted'])
			&& !empty($setup['eula_accepted']);
	}

	public function isSetupWizardComplete()
	{
		return $this->isSetupComplete($this->getActiveSettings());
	}

	public function getSetupRequiredMessage()
	{
		return _('Setup wizard must be completed before Mass Notifications can be used.');
	}

	public function getSetupWizardModalHtml($dismissible = true)
	{
		$activeSettings = $this->getActiveSettings();
		return $this->renderSetupWizard($this->getPendingSettings() ?? $activeSettings, $activeSettings, null, !empty($dismissible));
	}

	private function renderSetupWizard(array $settings, array $activeSettings, $saveResult = null, $dismissible = false)
	{
		return load_view(__DIR__ . '/views/setup.php', [
			'settings' => $settings,
			'active_settings' => $activeSettings,
			'save_result' => $saveResult,
			'available_extensions' => $this->getAllPjsipExtensions(),
			'available_desktop_clients' => $this->getDesktopClients($settings),
			'available_voices' => $this->getAvailablePiperVoices(),
			'available_tones' => $this->getAvailableTones(),
			'available_system_sounds' => $this->getAvailableSystemSounds(),
			'project_url' => 'https://southlandservers.xyz/projects',
			'discord_url' => 'https://southlandservers.xyz/discord',
			'github_url' => 'https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server',
			'eula_text' => $this->getEulaText(),
			'hero_image' => self::HERO_IMAGE,
			'csrf_token' => $this->getCsrfToken(),
			'dismissible' => $dismissible,
		]);
	}

	private function getEulaText()
	{
		$path = __DIR__ . '/EULA.md';
		if (is_readable($path)) {
			return (string)file_get_contents($path);
		}
		return 'Southland Servers Mass Notifications Server is provided as-is, without warranty, and at your own risk.';
	}

	public function saveSetupWizard(array $input)
	{
		$errors = [];
		// Setup replaces any stale staged settings. Capture their revision now so
		// a concurrent authenticated save cannot be deleted after this request has
		// finished building its active configuration.
		$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
		foreach ([
			'beta_agree' => _('You must acknowledge the beta and non-production warning.'),
			'agpl_agree' => _('You must accept the AGPL-3.0 license notice.'),
			'eula_agree' => _('You must accept the EULA.'),
		] as $field => $message) {
			if (empty($input[$field])) {
				$errors[] = $message;
			}
		}

		$settings = $this->getActiveSettings();
		$settings['enabled'] = empty($input['enabled']) ? '0' : '1';
		// CLI installation can only see the machine's local hostname. During
		// first-run setup, prefer the authenticated browser host so generated
		// Yealink image URLs are reachable from the same network as the admin.
		$currentPbxHost = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? ''));
		$browserPbxHost = $this->normalizePbxHost((string)($_SERVER['HTTP_HOST'] ?? ''));
		if (!$this->isSetupComplete($settings) && $browserPbxHost !== '' && !in_array($browserPbxHost, ['localhost', '127.0.0.1'], true)) {
			$currentMailDomain = $this->normalizeEmailSenderDomain((string)($settings['mail_from_domain'] ?? ''));
			$installerMailDomain = $this->normalizeEmailSenderDomain($currentPbxHost);
			$browserMailDomain = $this->normalizeEmailSenderDomain($browserPbxHost);
			if ($browserMailDomain !== '' && ($currentMailDomain === '' || in_array($currentMailDomain, ['localhost.localdomain', $installerMailDomain], true))) {
				$settings['mail_from_domain'] = $browserMailDomain;
				$settings['mail_from_addr'] = ($this->normalizeEmailSenderLocalPart($settings['mail_from_local_part'] ?? '') ?: 'no-reply') . '@' . $browserMailDomain;
			}
			$currentPbxHost = $browserPbxHost;
		}
		$settings['public_pbx_host'] = $currentPbxHost ?: $this->detectPbxHost();
		if ($settings['enabled'] === '1') {
			$settings['nws_api_base_url'] = $this->normalizeNwsApiBaseUrl((string)($input['nws_api_base_url'] ?? $settings['nws_api_base_url'] ?? 'https://api.weather.gov')) ?: 'https://api.weather.gov';
			$settings['nws_zone'] = $this->normalizeNwsZone((string)($input['nws_zone'] ?? ''));
			$settings['alert_recipients'] = $this->normalizeRecipientExtensions($input['alert_recipients'] ?? []);
			// The setup modal edits the primary Weather zone only. It can also be
			// opened after first-run, so preserve every additional zone and the
			// primary zone's stable ID/name instead of rebuilding the list.
			$existingNwsZones = $this->normalizeNwsZoneGroups(
				$settings['nws_zones'] ?? [],
				$settings['nws_zone'],
				$settings['alert_recipients']
			);
			$primaryNwsZone = $existingNwsZones[0] ?? [
				'id' => '',
				'name' => _('Primary Weather Zone'),
				'desktop_clients' => [],
				'email_recipients' => [],
			];
			$primaryNwsZone['zone'] = $settings['nws_zone'];
			$primaryNwsZone['extensions'] = $settings['alert_recipients'];
			if (empty($existingNwsZones)) {
				$existingNwsZones[] = $primaryNwsZone;
			} else {
				$existingNwsZones[0] = $primaryNwsZone;
			}
			$settings['nws_zones'] = $existingNwsZones;
			$desktopRecipients = [];
			$knownDesktopUsernames = [];
			foreach ($this->getDesktopClients($settings) as $desktopClient) {
				if (!empty($desktopClient['enabled'])) {
					$knownDesktopUsernames[(string)$desktopClient['username']] = true;
				}
			}
			foreach ((array)($input['nws_desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '' && isset($knownDesktopUsernames[$username])) {
					$desktopRecipients[$username] = $username;
				}
			}
			$emailRecipientErrors = $this->validateEmailRecipientsInput($input['nws_email_recipients'] ?? []);
			$errors = array_merge($errors, $emailRecipientErrors);
			$zoneEmails = $this->normalizeEmailRecipientList($input['nws_email_recipients'] ?? []);
			if (!empty($settings['nws_zones'])) {
				$settings['nws_zones'][0]['desktop_clients'] = array_values($desktopRecipients);
				$settings['nws_zones'][0]['email_recipients'] = $zoneEmails;
			}
			$errors = array_merge($errors, $this->validateNwsZoneEmailCapacity(
				$settings['nws_zones'],
				$settings['mail_to'] ?? ''
			));
			if ($settings['nws_zone'] === '') {
				$errors[] = _('Enter a valid NWS zone/county such as TXZ163.');
			}
			if (empty($settings['alert_recipients']) && empty($desktopRecipients)) {
				$errors[] = _('Select at least one NWS recipient extension or desktop client.');
			}
			$settings['quiet_hours_enabled'] = empty($input['quiet_hours_enabled']) ? '0' : '1';
			$settings['quiet_hours_start'] = $this->normalizeHour((string)($input['quiet_hours_start'] ?? $settings['quiet_hours_start'] ?? '21:00'), '21:00');
			$settings['quiet_hours_end'] = $this->normalizeHour((string)($input['quiet_hours_end'] ?? $settings['quiet_hours_end'] ?? '06:00'), '06:00');
			$settings['quiet_critical_events'] = $this->normalizeCriticalEvents($input['quiet_critical_events'] ?? $settings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents());
		}

		$availableToneLookup = array_fill_keys($this->getAvailableTones(), true);
		$toneProfiles = [
			'opening_tone' => ['prefix' => 'opening', 'default' => self::DEFAULT_ANNOUNCEMENT_OPENING_TONE],
			'closing_tone' => ['prefix' => 'closing', 'default' => self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE],
			'nws_opening_tone' => ['prefix' => 'opening', 'default' => self::DEFAULT_NWS_OPENING_TONE],
			'nws_closing_tone' => ['prefix' => 'closing', 'default' => ''],
		];
		foreach ($toneProfiles as $field => $profile) {
			if ($settings['enabled'] !== '1' && strpos($field, 'nws_') === 0) {
				continue;
			}
			$selection = (string)($input[$field] ?? $settings[$field] ?? $profile['default']);
			if (strpos($selection, 'system:') === 0) {
				$selection = $this->importSystemSoundAsTone($selection, $profile['prefix'], $errors);
				if ($selection !== '') {
					$availableToneLookup[$selection] = true;
				}
			}
			$tone = $this->normalizeToneName($selection);
			if ($tone !== '' && !isset($availableToneLookup[$tone])) {
				$errors[] = sprintf(_('Selected %s is not available.'), str_replace('_', ' ', $field));
				$tone = $profile['default'];
			}
			$settings[$field] = $tone;
		}

		$currentXweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
		$currentXweather = $this->normalizeXweatherSettings($currentXweather, $settings['nws_tts_volume'] ?? 25);
		$submittedXweather = is_array($input['xweather'] ?? null) ? $input['xweather'] : [];
		if (empty($submittedXweather['enabled'])) {
			// Hidden optional fields are disabled in the browser. Preserve their saved values when Lightning is declined.
			$setupXweather = $currentXweather;
			$setupXweather['enabled'] = '0';
		} else {
			$setupXweather = array_replace($currentXweather, $submittedXweather);
			$setupXweather['enabled'] = '1';
			if (!array_key_exists('groups', $submittedXweather)) {
				$groups = (array)($currentXweather['groups'] ?? []);
				if (empty($groups)) {
					$groups[] = [
						'id' => 'lightning_primary',
						'name' => _('Primary Lightning Zone'),
						'enabled' => '1',
						'adaptive_nws_zone_id' => '',
						'location' => '',
						'radius_miles' => 25,
						'extensions' => [],
						'desktop_clients' => [],
						'email_recipients' => [],
						'all_clear' => 'none',
					];
				}
				foreach (['location', 'radius_miles', 'adaptive_nws_zone_id', 'all_clear'] as $legacyField) {
					if (array_key_exists($legacyField, $submittedXweather)) {
						$groups[0][$legacyField] = $submittedXweather[$legacyField];
					}
				}
				if (array_key_exists('recipients', $submittedXweather)) {
					$groups[0]['extensions'] = $submittedXweather['recipients'];
				}
				$groups[0]['enabled'] = '1';
				$setupXweather['groups'] = $groups;
			}
		}
		if (!empty($setupXweather['groups']) && trim((string)($setupXweather['groups'][0]['adaptive_nws_zone_id'] ?? '')) === '' && !empty($settings['nws_zones'][0]['id'])) {
			$setupXweather['groups'][0]['adaptive_nws_zone_id'] = $settings['nws_zones'][0]['id'];
		}
		foreach (['opening', 'closing'] as $prefix) {
			$selection = (string)($setupXweather[$prefix . '_tone'] ?? ($prefix === 'opening' ? self::DEFAULT_LIGHTNING_OPENING_TONE : ''));
			if (strpos($selection, 'system:') === 0) {
				$imported = $this->importSystemSoundAsTone($selection, $prefix, $errors);
				if ($imported !== '') {
					$setupXweather[$prefix . '_tone'] = $imported;
				}
			}
		}
		if (trim((string)($setupXweather['client_secret'] ?? '')) === '') {
			$setupXweather['client_secret'] = $currentXweather['client_secret'] ?? '';
		}
		$lightningDefaultVolume = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 25, 25);
		$settings['xweather'] = $this->normalizeXweatherSettings($setupXweather, $lightningDefaultVolume);
		if ($settings['xweather']['enabled'] === '1') {
			if ($settings['xweather']['client_id'] === '' || $settings['xweather']['client_secret'] === '') {
				$errors[] = _('Enabled lightning alerts require Xweather credentials.');
			}
			if ($settings['xweather']['adaptive_free_tier'] === '1' && empty($settings['enabled'])) {
				$errors[] = _('Free-tier adaptive lightning polling requires Weather Alerts to be enabled with at least one weather zone.');
			}
			$validZoneIds = array_column((array)$settings['nws_zones'], 'id');
			$enabledLightningGroups = 0;
			foreach ($settings['xweather']['groups'] as $group) {
				if (($group['enabled'] ?? '0') !== '1') continue;
				$enabledLightningGroups++;
				if (($group['location'] ?? '') === '' || (empty($group['extensions']) && empty($group['desktop_clients']))) {
					$errors[] = sprintf(_('Lightning group "%s" requires a location and at least one phone or desktop recipient.'), $group['name'] ?? '');
				}
				if ($settings['xweather']['adaptive_free_tier'] === '1' && !in_array($group['adaptive_nws_zone_id'], $validZoneIds, true)) {
					$errors[] = sprintf(_('Select a valid Weather Alert trigger zone for Lightning group "%s".'), $group['name'] ?? '');
				}
			}
			if ($enabledLightningGroups === 0) {
				$errors[] = _('Enable at least one Lightning alert group.');
			}
		}
		$settings['email_html_enabled'] = '1';

		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		// Setup owns only the enable switch and derived URL. Keep the existing
		// allowlist, rate-limit, audit, and credential policy on a wizard rerun.
		$control['enabled'] = empty($input['control_api_enabled']) ? '0' : '1';
		$control['api_key'] = $this->normalizeEndpointPassword($control['api_key'] ?? '') ?: $this->generateApiKey();
		$control['base_url'] = $this->getControlApiUrl($settings);
		$settings['control_api'] = $control;

		$sipnotify = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
		$sipnotify['pbx_host'] = $settings['public_pbx_host'];
		$sipnotify['media_scheme'] = $this->normalizePhoneMediaScheme((string)($input['sipnotify_media_scheme'] ?? $sipnotify['media_scheme'] ?? 'http'));
		$settings['sipnotify'] = $this->normalizeSipNotifySettings($sipnotify);

		$voices = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		$announcementVoice = (string)($input['announcement_piper_voice'] ?? $settings['announcement_piper_voice'] ?? self::PIPER_VOICE);
		$settings['announcement_piper_voice'] = isset($voices[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		if ($settings['enabled'] === '1') {
			$nwsVoice = (string)($input['nws_piper_voice'] ?? $settings['nws_piper_voice'] ?? self::PIPER_AMY_VOICE);
			$settings['nws_piper_voice'] = isset($voices[$nwsVoice]) ? $nwsVoice : (isset($voices[self::PIPER_AMY_VOICE]) ? self::PIPER_AMY_VOICE : self::PIPER_VOICE);
		}
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($input['announcement_tts_volume'] ?? $settings['announcement_tts_volume'] ?? 25, 25);
		if ($settings['enabled'] === '1') {
			$settings['nws_tts_volume'] = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 25, 25);
		}
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($input['tts_max_seconds'] ?? $settings['tts_max_seconds'] ?? 30);
		$settings['announcement_cooldown_seconds'] = $this->normalizeAnnouncementCooldownSeconds($input['announcement_cooldown_seconds'] ?? $settings['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
		$settings['log_retention_days'] = $this->normalizeRetentionDays($input['log_retention_days'] ?? $settings['log_retention_days'] ?? 90);
		$settings['setup'] = [
			'completed' => empty($errors) ? '1' : '0',
			'beta_accepted' => empty($input['beta_agree']) ? '0' : '1',
			'agpl_accepted' => empty($input['agpl_agree']) ? '0' : '1',
			'eula_accepted' => empty($input['eula_agree']) ? '0' : '1',
			'completed_at' => empty($errors) ? gmdate('c') : '',
		];

		if (!empty($errors)) {
			return [
				'success' => false,
				'message' => _('Setup wizard was not completed.'),
				'errors' => $errors,
			];
		}

		try {
			$this->persistAppliedSettings($this->normalizeSettings($settings), true, true);
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to complete setup wizard.'),
				'errors' => [$e->getMessage()],
			];
		}

		return [
			'success' => true,
			'message' => _('Setup wizard completed. Mass Notifications configuration is now active.'),
			'errors' => [],
		];
	}

	public function saveSettings(array $input, array $files = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'errors' => [$this->getSetupRequiredMessage()],
			];
		}

		$defaults = $this->getDefaultSettings();
		$currentSettings = $this->getPendingSettings() ?? $this->getActiveSettings();
		$errors = [];
		$availableToneLookup = array_fill_keys($this->getAvailableTones(), true);
		foreach (['opening', 'closing'] as $prefix) {
			$field = 'nws_' . $prefix . '_tone';
			$selection = (string)($input[$field] ?? '');
			if (strpos($selection, 'system:') === 0) {
				$importedTone = $this->importSystemSoundAsTone($selection, $prefix, $errors);
				if ($importedTone !== '') {
					$input[$field] = $importedTone;
					$availableToneLookup[$importedTone] = true;
				}
			}
		}
		$enabled = empty($input['enabled']) ? '0' : '1';
		$errors = array_merge($errors, $this->validateNwsZoneGroupsInput($input['nws_zones'] ?? []));
		$nwsZones = $this->normalizeNwsZoneGroups(
			$input['nws_zones'] ?? $currentSettings['nws_zones'] ?? [],
			$input['nws_zone'] ?? $currentSettings['nws_zone'] ?? '',
			$input['alert_recipients'] ?? $currentSettings['alert_recipients'] ?? []
		);
		if ($enabled === '1' && empty($nwsZones)) {
			$errors[] = _('Create at least one NWS zone group.');
		}
		$knownDesktopUsernames = [];
		foreach ($this->getDesktopClients($currentSettings) as $desktopClient) {
			$knownDesktopUsernames[(string)$desktopClient['username']] = !empty($desktopClient['enabled']);
		}
		foreach ($nwsZones as $zoneGroup) {
			if (($zoneGroup['zone'] ?? '') === '') {
				$errors[] = sprintf(_('NWS group "%s" needs a valid zone such as TXZ163.'), $zoneGroup['name'] ?? '');
			}
			if (empty($zoneGroup['extensions']) && empty($zoneGroup['desktop_clients'])) {
				$errors[] = sprintf(_('NWS group "%s" needs at least one recipient extension or desktop client.'), $zoneGroup['name'] ?? '');
			}
			foreach ((array)($zoneGroup['desktop_clients'] ?? []) as $username) {
				if (!array_key_exists($username, $knownDesktopUsernames)) {
					$errors[] = sprintf(_('NWS group "%s" references an unknown desktop client: %s.'), $zoneGroup['name'] ?? '', $username);
				} elseif (!$knownDesktopUsernames[$username]) {
					$errors[] = sprintf(_('Enable desktop client %s before assigning it to NWS group "%s".'), $username, $zoneGroup['name'] ?? '');
				}
			}
		}
		$errors = array_merge($errors, $this->validateNwsZoneEmailCapacity(
			$nwsZones,
			$currentSettings['mail_to'] ?? ''
		));

		// Shared webhook and sender settings are managed from General Settings.
		// Weather saves must not rewrite those canonical destination arrays;
		// live email recipients remain attached to the matching zone group.
		$quietHoursEnabled = empty($input['quiet_hours_enabled']) ? '0' : '1';
		$quietHoursStart = $this->normalizeHour((string)($input['quiet_hours_start'] ?? $defaults['quiet_hours_start']), $defaults['quiet_hours_start']);
		$quietHoursEnd = $this->normalizeHour((string)($input['quiet_hours_end'] ?? $defaults['quiet_hours_end']), $defaults['quiet_hours_end']);
		$quietCriticalEvents = $this->normalizeCriticalEvents($input['quiet_critical_events'] ?? $defaults['quiet_critical_events']);
		$alertEmailSubject = trim((string)($input['alert_email_subject'] ?? $currentSettings['alert_email_subject'] ?? $defaults['alert_email_subject']));
		$alertEmailBody = trim((string)($input['alert_email_body'] ?? $currentSettings['alert_email_body'] ?? $defaults['alert_email_body']));
		$testEmailSubject = trim((string)($input['test_email_subject'] ?? $currentSettings['test_email_subject'] ?? $defaults['test_email_subject']));
		$testEmailBody = trim((string)($input['test_email_body'] ?? $currentSettings['test_email_body'] ?? $defaults['test_email_body']));
		$nwsApiBaseUrl = $this->normalizeNwsApiBaseUrl((string)($input['nws_api_base_url'] ?? $defaults['nws_api_base_url']));
		$nwsZone = !empty($nwsZones) ? (string)$nwsZones[0]['zone'] : '';
		$alertRecipients = !empty($nwsZones) ? (array)$nwsZones[0]['extensions'] : [];

		if ($nwsApiBaseUrl === '') {
			$errors[] = _('NWS API base URL must be a valid HTTPS URL.');
			$nwsApiBaseUrl = $defaults['nws_api_base_url'];
		}
		if ($alertEmailSubject === '') {
			$alertEmailSubject = $defaults['alert_email_subject'];
		}
		if ($alertEmailBody === '') {
			$alertEmailBody = $defaults['alert_email_body'];
		}
		if ($testEmailSubject === '') {
			$testEmailSubject = $defaults['test_email_subject'];
		}
		if ($testEmailBody === '') {
			$testEmailBody = $defaults['test_email_body'];
		}

		$openingTone = $this->normalizeToneName((string)($input['nws_opening_tone'] ?? $currentSettings['nws_opening_tone'] ?? $defaults['nws_opening_tone']));
		$closingTone = $this->normalizeToneName((string)($input['nws_closing_tone'] ?? $currentSettings['nws_closing_tone'] ?? $defaults['nws_closing_tone']));
		foreach (['nws_opening_tone' => $openingTone, 'nws_closing_tone' => $closingTone] as $label => $tone) {
			if ($tone !== '' && !isset($availableToneLookup[$tone])) {
				$errors[] = sprintf(_('Selected %s is not available.'), str_replace('_', ' ', $label));
			}
		}
		$openingTone = $openingTone === '' || isset($availableToneLookup[$openingTone]) ? $openingTone : $defaults['nws_opening_tone'];
		$closingTone = $closingTone === '' || isset($availableToneLookup[$closingTone]) ? $closingTone : $defaults['nws_closing_tone'];

		// NWS settings are one section of the central config. Preserve every unrelated
		// key, especially desktop credentials, groups, API keys, and PBX hostname.
		$settings = $currentSettings;
		$settings['enabled'] = $enabled;
		$settings['page_group'] = '';
		$settings['alert_recipients'] = $alertRecipients;
		$settings['quiet_hours_enabled'] = $quietHoursEnabled;
		$settings['quiet_hours_start'] = $quietHoursStart;
		$settings['quiet_hours_end'] = $quietHoursEnd;
		$settings['quiet_critical_events'] = $quietCriticalEvents;
		$settings['nws_api_base_url'] = $nwsApiBaseUrl;
		$settings['nws_zone'] = $nwsZone;
		$settings['nws_zones'] = $nwsZones;
		$settings['alert_email_subject'] = $alertEmailSubject;
		$settings['alert_email_body'] = $alertEmailBody;
		$settings['test_email_subject'] = $testEmailSubject;
		$settings['test_email_body'] = $testEmailBody;
		$settings['nws_opening_tone'] = $openingTone;
		$settings['nws_closing_tone'] = $closingTone;
		$settings['email_html_enabled'] = '1';
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($input['tts_max_seconds'] ?? $currentSettings['tts_max_seconds'] ?? 30);
		$settings['piper_bin'] = self::PIPER_BIN;
		$voiceLookup = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		$nwsVoice = (string)($input['nws_piper_voice'] ?? $currentSettings['nws_piper_voice'] ?? self::PIPER_AMY_VOICE);
		$settings['nws_piper_voice'] = isset($voiceLookup[$nwsVoice]) ? $nwsVoice : (isset($voiceLookup[self::PIPER_AMY_VOICE]) ? self::PIPER_AMY_VOICE : self::PIPER_VOICE);
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $currentSettings['nws_tts_volume'] ?? 25, 25);

		if (!empty($errors)) {
			return [
				'success' => false,
				'message' => _('Settings were saved with warnings.'),
				'errors' => $errors,
			];
		}

		try {
			$this->persistPendingSettings($settings);
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Settings were saved with warnings.'),
				'errors' => [$e->getMessage()],
			];
		}

		return [
			'success' => true,
			'message' => _('Changes saved.'),
			'errors' => [],
		];
	}

	public function saveLightningSettings(array $input)
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return ['success' => false, 'message' => $this->getSetupRequiredMessage(), 'errors' => [$this->getSetupRequiredMessage()]];
		}

		$settings = $this->getPendingSettings() ?? $this->getActiveSettings();
		$current = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
		$incoming = is_array($input['xweather'] ?? null) ? $input['xweather'] : [];
		if (!empty($incoming['groups_present']) && !array_key_exists('groups', $incoming)) {
			$incoming['groups'] = [];
		}
		unset($incoming['groups_present']);
		$errors = [];
		foreach (['opening', 'closing'] as $prefix) {
			$selection = (string)($incoming[$prefix . '_tone'] ?? ($prefix === 'opening' ? self::DEFAULT_LIGHTNING_OPENING_TONE : ''));
			if (strpos($selection, 'system:') === 0) {
				$imported = $this->importSystemSoundAsTone($selection, $prefix, $errors);
				if ($imported !== '') {
					$incoming[$prefix . '_tone'] = $imported;
				}
			}
		}
		if (trim((string)($incoming['client_secret'] ?? '')) === '') {
			$incoming['client_secret'] = $current['client_secret'] ?? '';
		}
		$errors = array_merge($errors, $this->validateXweatherGroupsInput($incoming['groups'] ?? []));
		$xweather = $this->normalizeXweatherSettings($incoming, $settings['nws_tts_volume'] ?? 25);
		$availableTones = array_fill_keys($this->getAvailableTones(), true);
		foreach (['opening_tone', 'closing_tone'] as $toneKey) {
			$tone = (string)($xweather[$toneKey] ?? ($toneKey === 'opening_tone' ? self::DEFAULT_LIGHTNING_OPENING_TONE : ''));
			if ($tone !== '' && $tone !== 'use_default' && !isset($availableTones[$tone])) {
				$errors[] = sprintf(_('Selected lightning %s is unavailable.'), str_replace('_', ' ', $toneKey));
			}
		}
		if ($xweather['enabled'] === '1') {
			if ($xweather['client_id'] === '' || $xweather['client_secret'] === '') {
				$errors[] = _('Enabled lightning alerts require an Xweather client ID and client secret.');
			}
			if ($xweather['adaptive_free_tier'] === '1' && empty($settings['enabled'])) {
				$errors[] = _('Free-tier adaptive lightning polling requires Weather Alerts to be enabled with at least one weather zone.');
			}
			$validZoneIds = array_column((array)($settings['nws_zones'] ?? []), 'id');
			$enabledGroups = 0;
			foreach ($xweather['groups'] as $group) {
				if (($group['enabled'] ?? '0') !== '1') {
					continue;
				}
				$enabledGroups++;
				$label = (string)($group['name'] ?? _('Lightning group'));
				if (($group['location'] ?? '') === '') {
					$errors[] = sprintf(_('Lightning group "%s" requires an Xweather location.'), $label);
				}
				if (empty($group['extensions']) && empty($group['desktop_clients'])) {
					$errors[] = sprintf(_('Lightning group "%s" requires at least one phone or desktop recipient.'), $label);
				}
				if ($xweather['adaptive_free_tier'] === '1' && !in_array($group['adaptive_nws_zone_id'], $validZoneIds, true)) {
					$errors[] = sprintf(_('Select a valid Weather Alert trigger zone for Lightning group "%s".'), $label);
				}
			}
			if ($enabledGroups === 0) {
				$errors[] = _('Enable at least one Lightning alert group.');
			}
		}
		$errors = array_merge(
			$errors,
			$this->validateXweatherGroupDesktopAssignments($xweather['groups'], $settings),
			$this->validateXweatherGroupEmailCapacity($xweather['groups'], $settings['mail_to'] ?? '')
		);
		if (!empty($errors)) {
			return ['success' => false, 'message' => _('Lightning settings were not saved.'), 'errors' => $errors];
		}

		$settings['xweather'] = $xweather;
		$settings['email_html_enabled'] = '1';
		try {
			$this->persistPendingSettings($settings);
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => _('Unable to save lightning settings.'), 'errors' => [$e->getMessage()]];
		}
		return ['success' => true, 'message' => _('Changes saved.'), 'errors' => []];
	}

	public function saveOtherSettings(array $input, array $files = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'errors' => [$this->getSetupRequiredMessage()],
			];
		}

		$settings = $this->getPendingSettings() ?? $this->getActiveSettings();
		$defaults = $this->getDefaultSettings();
		$voices = $this->getAvailablePiperVoices();
		$voiceLookup = array_fill_keys(array_column($voices, 'path'), true);
		$errors = [];
		$availableToneLookup = array_fill_keys($this->getAvailableTones(), true);
		foreach (['opening', 'closing'] as $prefix) {
			$selection = (string)($input[$prefix . '_tone'] ?? '');
			if (strpos($selection, 'system:') === 0) {
				$importedTone = $this->importSystemSoundAsTone($selection, $prefix, $errors);
				if ($importedTone !== '') {
					$input[$prefix . '_tone'] = $importedTone;
					$availableToneLookup[$importedTone] = true;
				}
			}
		}

		$openingTone = $this->normalizeToneName((string)($input['opening_tone'] ?? $settings['opening_tone'] ?? $defaults['opening_tone']));
		$closingTone = $this->normalizeToneName((string)($input['closing_tone'] ?? $settings['closing_tone'] ?? $defaults['closing_tone']));
		if ($openingTone !== '' && !isset($availableToneLookup[$openingTone])) {
			$errors[] = _('Selected opening tone is not available.');
			$openingTone = $settings['opening_tone'] ?? $defaults['opening_tone'];
		}
		if ($closingTone !== '' && !isset($availableToneLookup[$closingTone])) {
			$errors[] = _('Selected closing tone is not available.');
			$closingTone = $settings['closing_tone'] ?? $defaults['closing_tone'];
		}
		$settings['opening_tone'] = $openingTone === '' || isset($availableToneLookup[$openingTone]) ? $openingTone : $defaults['opening_tone'];
		$settings['closing_tone'] = $closingTone === '' || isset($availableToneLookup[$closingTone]) ? $closingTone : $defaults['closing_tone'];

		// Public PBX Hostname is automatically detected and cannot be edited here.
		$settings['public_pbx_host'] = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? '')) ?: $this->detectPbxHost();
		$control = is_array($input['control_api'] ?? null) ? $input['control_api'] : [];
		$currentControl = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : $defaults['control_api'];
		$apiKey = trim((string)($control['api_key'] ?? $currentControl['api_key'] ?? ''));
		if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/', $apiKey)) {
			$apiKey = $this->generateApiKey();
		}
		$settings['control_api'] = [
			'enabled' => empty($control['enabled']) ? '0' : '1',
			'api_key' => $apiKey,
			'base_url' => $this->getControlApiUrl($settings),
			'ip_allowlist_enabled' => empty($control['ip_allowlist_enabled']) ? '0' : '1',
			'ip_allowlist' => $this->normalizeIpAllowlist((string)($control['ip_allowlist'] ?? $currentControl['ip_allowlist'] ?? '')),
			'rate_limit_enabled' => empty($control['rate_limit_enabled']) ? '0' : '1',
			'rate_limit_per_minute' => $this->normalizeInt($control['rate_limit_per_minute'] ?? $currentControl['rate_limit_per_minute'] ?? 60, 1, 600, 60),
			'audit_retention_days' => 30,
		];
		$sipnotifySettings = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
		$sipnotifySettings['pbx_host'] = $settings['public_pbx_host'];
		$sipnotifySettings['media_scheme'] = $this->normalizePhoneMediaScheme((string)($input['sipnotify_media_scheme'] ?? $sipnotifySettings['media_scheme'] ?? 'http'));
		$formatOverrideInput = $input['sipnotify_format_overrides'] ?? (!empty($input['sipnotify_format_overrides_present']) ? [] : ($sipnotifySettings['format_overrides'] ?? []));
		$sipnotifySettings['format_overrides'] = $this->normalizeEndpointFormatOverrides($formatOverrideInput);
		$settings['sipnotify'] = $this->normalizeSipNotifySettings($sipnotifySettings);
		$systemMailInput = $input['system_notification_recipients']
			?? (!empty($input['system_notification_recipients_present'])
				? []
				: ($input['mail_recipients']
					?? (!empty($input['mail_recipients_present'])
						? []
						: ($input['system_notification_emails'] ?? $input['mail_to'] ?? $settings['system_notification_emails'] ?? $settings['mail_to'] ?? ''))));
		$errors = array_merge($errors, $this->validateEmailRecipientsInput($systemMailInput));
		if (is_array($systemMailInput)) {
			$systemMailInput = implode(' ', array_map('strval', $systemMailInput));
		}
		$settings['system_notification_emails'] = $this->normalizeEmails((string)$systemMailInput);
		// Keep the former field synchronized for older fault-reporting helpers and
		// restored configurations. Live Weather and Lightning delivery uses only
		// the recipient list stored on the matching zone or trigger area.
		$settings['mail_to'] = $settings['system_notification_emails'];
		$mailFromLocalPartInput = trim((string)($input['mail_from_local_part'] ?? $settings['mail_from_local_part'] ?? 'no-reply'));
		$mailFromLocalPart = $this->normalizeEmailSenderLocalPart($mailFromLocalPartInput);
		if ($mailFromLocalPart === '') {
			$errors[] = _('Email sender local part must use letters, numbers, dots, underscores, plus signs, or hyphens without leading, trailing, or repeated dots.');
		} else {
			$settings['mail_from_local_part'] = $mailFromLocalPart;
		}
		$mailFromDomainInput = trim((string)($input['mail_from_domain'] ?? $settings['mail_from_domain'] ?? ''));
		$mailFromDomain = $this->normalizeEmailSenderDomain($mailFromDomainInput);
		if ($mailFromDomain === '') {
			$errors[] = _('Email sender domain must be a valid DNS hostname, such as example.com.');
		} else {
			$settings['mail_from_domain'] = $mailFromDomain;
			$settings['mail_from_addr'] = ($mailFromLocalPart ?: 'no-reply') . '@' . $mailFromDomain;
			if (strlen($settings['mail_from_addr']) > 254) {
				$errors[] = _('The complete email sender address cannot exceed 254 characters.');
			}
		}
		$discordInput = $input['discord_webhooks'] ?? null;
		if (!is_array($discordInput)) {
			$discordInput = !empty($input['discord_webhooks_present']) ? [] : ($settings['discord_webhooks'] ?? []);
			if (empty($discordInput) && !empty($settings['discord_webhook_url'])) {
				$discordInput = [['name' => _('Primary Discord'), 'url' => $settings['discord_webhook_url'], 'enabled' => '1']];
			}
		}
		$genericInput = is_array($input['generic_webhooks'] ?? null)
			? $input['generic_webhooks']
			: (!empty($input['generic_webhooks_present']) ? [] : ($settings['generic_webhooks'] ?? []));
		$discordInput = $this->mergeWebhookDestinationSecrets($discordInput, $settings['discord_webhooks'] ?? [], 'discord');
		$genericInput = $this->mergeWebhookDestinationSecrets($genericInput, $settings['generic_webhooks'] ?? [], 'generic');
		$errors = array_merge(
			$errors,
			$this->validateWebhookDestinations($discordInput, 'discord'),
			$this->validateWebhookDestinations($genericInput, 'generic')
		);
		$settings['discord_webhooks'] = $this->normalizeWebhookDestinations($discordInput, 'discord');
		$settings['generic_webhooks'] = $this->normalizeWebhookDestinations($genericInput, 'generic');
		$settings['discord_webhook_url'] = $this->firstEnabledWebhookUrl($settings['discord_webhooks']);
		$settings['email_html_enabled'] = '1';

		$announcementVoice = (string)($input['announcement_piper_voice'] ?? $settings['announcement_piper_voice'] ?? self::PIPER_VOICE);
		$nwsVoice = (string)($input['nws_piper_voice'] ?? $settings['nws_piper_voice'] ?? self::PIPER_AMY_VOICE);
		$settings['announcement_piper_voice'] = isset($voiceLookup[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		$settings['nws_piper_voice'] = isset($voiceLookup[$nwsVoice]) ? $nwsVoice : (isset($voiceLookup[self::PIPER_AMY_VOICE]) ? self::PIPER_AMY_VOICE : self::PIPER_VOICE);
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($input['announcement_tts_volume'] ?? $settings['announcement_tts_volume'] ?? 25, 25);
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 25, 25);
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($input['tts_max_seconds'] ?? $settings['tts_max_seconds'] ?? 30);
		$settings['announcement_cooldown_seconds'] = $this->normalizeAnnouncementCooldownSeconds($input['announcement_cooldown_seconds'] ?? $settings['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
		$settings['announcement_timeout_mode'] = $this->normalizeAnnouncementTimeoutMode($input['announcement_timeout_mode'] ?? $settings['announcement_timeout_mode'] ?? 'none');
		$settings['announcement_timeout_seconds'] = $this->normalizeAnnouncementTimeoutSeconds($input['announcement_timeout_seconds'] ?? $settings['announcement_timeout_seconds'] ?? 300);
		$settings['log_retention_days'] = $this->normalizeRetentionDays($input['log_retention_days'] ?? $settings['log_retention_days'] ?? 90);
		$updates = is_array($input['updates'] ?? null) ? $input['updates'] : [];
		$settings['updates'] = [
			'github_enabled' => empty($updates['github_enabled']) ? '0' : '1',
			'repository' => 'vipgabe09267/SouthlandServers_Mass_Notify_server',
			'channel' => 'beta',
		];
		$settings['announcement_groups'] = $settings['announcement_groups'] ?? [];
		$settings['desktop_auth_key'] = $this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? '');
		$desktopClientInput = $input['desktop_clients'] ?? $settings['desktop_clients'] ?? [];
		$existingClientIds = [];
		$existingClientUsernames = [];
		foreach ((array)($settings['desktop_clients'] ?? []) as $existingClient) {
			$existingId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($existingClient['id'] ?? ''));
			if ($existingId !== '') {
				$existingClientIds[$existingId] = (string)($existingClient['client_id'] ?? '');
				$existingClientUsernames[$existingId] = $this->normalizeDesktopUsername($existingClient['username'] ?? '');
			}
		}
		foreach ((array)$desktopClientInput as $index => $desktopClient) {
			if (!is_array($desktopClient)) {
				continue;
			}
			$desktopId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($desktopClient['id'] ?? ''));
			if ($desktopId !== '' && isset($existingClientIds[$desktopId])) {
				$desktopClientInput[$index]['client_id'] = $existingClientIds[$desktopId];
			} else {
				$desktopClientInput[$index]['client_id'] = '';
			}
		}
		$desktopIdentifierErrors = $this->validateDesktopClientIdentifiers($desktopClientInput);
		if (!empty($desktopIdentifierErrors)) {
			return [
				'success' => false,
				'message' => _('Desktop client settings were not saved.'),
				'errors' => $desktopIdentifierErrors,
			];
		}
		$settings['desktop_clients'] = $this->normalizeDesktopClients($desktopClientInput, $settings);
		$newClientUsernames = [];
		foreach ($settings['desktop_clients'] as $desktopClient) {
			$desktopId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($desktopClient['id'] ?? ''));
			if ($desktopId !== '') {
				$newClientUsernames[$desktopId] = (string)($desktopClient['username'] ?? '');
			}
		}
		$usernameMigrations = [];
		foreach ($existingClientUsernames as $desktopId => $oldUsername) {
			$newUsername = $newClientUsernames[$desktopId] ?? '';
			if ($oldUsername !== '' && $newUsername !== '' && $oldUsername !== $newUsername) {
				$usernameMigrations[$oldUsername] = $newUsername;
			}
		}
		if (!empty($usernameMigrations)) {
			$settings['nws_zones'] = $this->migrateNwsZoneDesktopUsernames(
				$settings['nws_zones'] ?? [],
				$usernameMigrations
			);
			$xweatherForMigration = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
			$xweatherForMigration['groups'] = $this->migrateXweatherGroupDesktopUsernames(
				$xweatherForMigration['groups'] ?? [],
				$usernameMigrations
			);
			$settings['xweather'] = $xweatherForMigration;
		}
		$errors = array_merge($errors, $this->validateNwsZoneDesktopAssignments(
			$settings['nws_zones'] ?? [],
			$settings
		));
		$errors = array_merge($errors, $this->validateXweatherGroupDesktopAssignments(
			$settings['xweather']['groups'] ?? [],
			$settings
		));
		if (!empty($errors)) {
			return [
				'success' => false,
				'message' => _('General settings were not saved.'),
				'errors' => array_values(array_unique($errors)),
			];
		}

		try {
			$this->persistPendingSettings($settings);
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Other settings were saved with warnings.'),
				'errors' => [$e->getMessage()],
			];
		}

			return [
				'success' => true,
				'message' => _('Changes saved.'),
				'errors' => [],
			];
		}

	public function regenerateControlApiKey(array $input = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'errors' => [$this->getSetupRequiredMessage()],
			];
		}

		$settings = $this->getPendingSettings() ?? $this->getActiveSettings();
		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		$control['api_key'] = $this->generateApiKey();
		$settings['control_api'] = $control;
		try {
			$this->persistPendingSettings($this->normalizeSettings($settings));
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to regenerate the Control API key.'),
				'errors' => [$e->getMessage()],
			];
		}
		return [
			'success' => true,
			'message' => _('Control API key regenerated.'),
			'errors' => [],
		];
	}

	public function exportConfig()
	{
		$settings = $this->getActiveSettings();
		$payload = [
			'product' => 'Southland Servers Mass Notifications Server',
			'format' => 'sls-mass-notify-config-v1',
			'exported_at' => gmdate('c'),
			'settings' => $settings,
		];
		return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	public function importConfigUpload(array $upload)
	{
		$this->writeMaintenanceProgress('config', 'running', _('Validating the replacement configuration.'));
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$this->writeMaintenanceProgress('config', 'failed', _('No valid configuration upload was received.'));
			return [
				'success' => false,
				'message' => _('Upload a Mass Notifications .config file first.'),
				'errors' => [],
			];
		}
		if ((int)($upload['size'] ?? 0) <= 0 || (int)($upload['size'] ?? 0) > 1024 * 1024) {
			$this->writeMaintenanceProgress('config', 'failed', _('The replacement configuration did not pass the size limit.'));
			return [
				'success' => false,
				'message' => _('Config import must be smaller than 1 MB.'),
				'errors' => [],
			];
		}
		$tmpName = (string)($upload['tmp_name'] ?? '');
		if ($tmpName === '' || !is_uploaded_file($tmpName)) {
			$this->writeMaintenanceProgress('config', 'failed', _('The uploaded configuration could not be read safely.'));
			return [
				'success' => false,
				'message' => _('Unable to read uploaded config file.'),
				'errors' => [],
			];
		}
		$decoded = json_decode((string)file_get_contents($tmpName), true);
		if (!is_array($decoded)) {
			$this->writeMaintenanceProgress('config', 'failed', _('The uploaded configuration is not valid JSON.'));
			return [
				'success' => false,
				'message' => _('Uploaded config is not valid JSON.'),
				'errors' => [],
			];
		}
		$settings = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : $decoded;
		$schemaErrors = $this->validateConfigSchema($settings);
		if (!empty($schemaErrors)) {
			$this->writeMaintenanceProgress('config', 'failed', _('The uploaded configuration failed validation.'));
			return [
				'success' => false,
				'message' => _('Uploaded config failed validation.'),
				'errors' => $schemaErrors,
			];
		}
		try {
			if (!array_key_exists('mail_from_domain', $settings)) {
				$legacyMailFrom = trim((string)($settings['mail_from_addr'] ?? ''));
				if (filter_var($legacyMailFrom, FILTER_VALIDATE_EMAIL)) {
					$legacyDomain = $this->normalizeEmailSenderDomain(substr($legacyMailFrom, strrpos($legacyMailFrom, '@') + 1));
					if ($legacyDomain !== '') {
						$settings['mail_from_domain'] = $legacyDomain;
					}
				}
			}
			if (!array_key_exists('system_notification_emails', $settings)) {
				$legacyMailRecipients = (string)($settings['mail_to'] ?? '');
				// Preserve the former live-alert route in each imported service
				// group, but leave system/error mail opt-in.
				$settings['system_notification_emails'] = '';
				$settings['_legacy_live_email_recipients'] = $legacyMailRecipients;
			}
			$replacement = $this->normalizeSettings(array_replace($this->getDefaultSettings(), $settings));
			foreach ((array)($replacement['scheduled_announcements'] ?? []) as $index => $schedule) {
				$replacement['scheduled_announcements'][$index]['enabled'] = '0';
			}
			$this->persistPendingSettings($replacement, true);
		} catch (\Throwable $e) {
			$this->writeMaintenanceProgress('config', 'failed', _('The replacement configuration could not be staged.'));
			return [
				'success' => false,
				'message' => _('Unable to import Mass Notifications config.'),
				'errors' => [$e->getMessage()],
			];
		}
		$this->writeMaintenanceProgress('config', 'complete', _('Replacement configuration validated and staged. Imported schedules were disabled for review. Apply Config to make it live.'));
		return [
			'success' => true,
			'message' => _('Mass Notifications config imported. Scheduled announcements were disabled to prevent an unintended replay; review them before enabling. Apply Config to make it live.'),
			'errors' => [],
		];
	}

	private function validateConfigSchema(array $settings)
	{
		$errors = $this->validateConfigValueTypes($settings);
		$known = array_merge(array_keys($this->getDefaultSettings()), ['sound_map', 'test_sound_pool']);
		$knownLookup = array_fill_keys($known, true);
		$recognized = 0;
		foreach (array_keys($settings) as $key) {
			if (isset($knownLookup[$key])) {
				$recognized++;
			} else {
				$errors[] = sprintf(_('Unknown config key: %s.'), (string)$key);
			}
		}
		if ($recognized < 3) {
			$errors[] = _('Config does not look like a Mass Notifications .config file.');
		}
		foreach (['enabled', 'setup', 'ami', 'control_api', 'sipnotify'] as $requiredKey) {
			if (!array_key_exists($requiredKey, $settings)) {
				$errors[] = sprintf(_('Config is missing required key: %s.'), $requiredKey);
			}
		}
		foreach (['control_api', 'updates', 'setup', 'sipnotify', 'ami', 'xweather'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an object.'), $key);
			}
		}
		foreach (['alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an array.'), $key);
			}
		}
		if (!empty($errors)) {
			return array_values(array_unique($errors));
		}
		if (is_array($settings['desktop_clients'] ?? null)) {
			$errors = array_merge($errors, $this->validateDesktopClientIdentifiers($settings['desktop_clients']));
			if (!empty($settings['desktop_clients'])) {
				$key = base64_decode((string)($settings['desktop_auth_key'] ?? ''), true);
				if (!is_string($key) || strlen($key) !== 32) {
					$errors[] = _('Config with desktop clients must include its valid desktop encryption key.');
				} else {
					foreach ($settings['desktop_clients'] as $client) {
						if (!is_array($client) || empty($client['password_enc']) || $this->decryptDesktopPassword((string)$client['password_enc'], $settings) === '') {
							$errors[] = _('One or more desktop client credentials cannot be decrypted with this config.');
							break;
						}
					}
				}
			}
		}
		$errors = array_merge($errors, $this->validateNwsZoneDesktopAssignments(
			$settings['nws_zones'] ?? [],
			$settings
		));
		$errors = array_merge($errors, $this->validateNwsZoneEmailCapacity(
			$settings['nws_zones'] ?? [],
			$settings['mail_to'] ?? ''
		));
		if (!empty($settings['enabled'])) {
			$errors = array_merge($errors, $this->validateNwsZoneGroupsInput($settings['nws_zones'] ?? []));
			$zoneGroups = $this->normalizeNwsZoneGroups($settings['nws_zones'] ?? [], $settings['nws_zone'] ?? '', $settings['alert_recipients'] ?? []);
			if (empty($zoneGroups)) {
				$errors[] = _('Enabled NWS config must include at least one zone group.');
			}
			foreach ($zoneGroups as $zoneGroup) {
				if (($zoneGroup['zone'] ?? '') === '' || (empty($zoneGroup['extensions']) && empty($zoneGroup['desktop_clients']))) {
					$errors[] = _('Each enabled NWS zone group must include a valid zone and at least one recipient extension or desktop client.');
				}
			}
		}
		$xweather = $this->normalizeXweatherSettings($settings['xweather'] ?? [], $settings['nws_tts_volume'] ?? 25);
		$errors = array_merge(
			$errors,
			$this->validateXweatherGroupDesktopAssignments($xweather['groups'], $settings),
			$this->validateXweatherGroupEmailCapacity($xweather['groups'], $settings['mail_to'] ?? '')
		);
		if ($xweather['enabled'] === '1') {
			if ($xweather['client_id'] === '' || $xweather['client_secret'] === '') {
				$errors[] = _('Enabled Xweather config requires valid credentials.');
			}
			$enabledXweatherGroups = array_values(array_filter($xweather['groups'], static function ($group) {
				return is_array($group) && ($group['enabled'] ?? '0') === '1';
			}));
			if (empty($enabledXweatherGroups)) {
				$errors[] = _('Enabled Xweather config requires at least one enabled Lightning trigger area.');
			}
			foreach ($enabledXweatherGroups as $group) {
				if (($group['location'] ?? '') === '' || (empty($group['extensions']) && empty($group['desktop_clients']))) {
					$errors[] = _('Each enabled Lightning trigger area requires a location and at least one phone or desktop recipient.');
				}
			}
		}
		if (!empty($settings['control_api']['enabled']) && !preg_match('/^[A-Za-z0-9_-]{24,128}$/', (string)($settings['control_api']['api_key'] ?? ''))) {
			$errors[] = _('Enabled Control API config must include a valid API key.');
		}
		if (is_string($settings['nws_zone'] ?? null) && $this->normalizeNwsZone($settings['nws_zone']) !== $settings['nws_zone'] && trim($settings['nws_zone']) !== '') {
			$errors[] = _('NWS zone must be a valid NWS county or zone code such as TXZ163.');
		}
		if (is_string($settings['nws_api_base_url'] ?? null) && $settings['nws_api_base_url'] !== 'https://api.weather.gov') {
			$errors[] = _('NWS API base URL must be exactly https://api.weather.gov.');
		}
		$errors = array_merge($errors, $this->validateScheduledAnnouncementRecurrences(
			is_array($settings['scheduled_announcements'] ?? null) ? $settings['scheduled_announcements'] : []
		));
		if (array_key_exists('mail_from_domain', $settings)) {
			$mailFromDomain = $this->normalizeEmailSenderDomain((string)$settings['mail_from_domain']);
			$mailFromLocalPart = $this->normalizeEmailSenderLocalPart((string)($settings['mail_from_local_part'] ?? 'no-reply'));
			if ($mailFromDomain === '' || $mailFromDomain !== strtolower(trim((string)$settings['mail_from_domain'], "@ \t\n\r\0\x0B."))) {
				$errors[] = _('Email sender domain must be a canonical DNS hostname such as example.com.');
			} elseif ($mailFromLocalPart === '') {
				$errors[] = _('Email sender name is invalid.');
			} elseif (array_key_exists('mail_from_addr', $settings) && strtolower(trim((string)$settings['mail_from_addr'])) !== $mailFromLocalPart . '@' . $mailFromDomain) {
				$errors[] = _('Email sender address must match the configured sender name and domain.');
			}
		} elseif (isset($settings['mail_from_addr']) && !filter_var((string)$settings['mail_from_addr'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = _('Legacy email sender address is invalid.');
		}
		$errors = array_merge(
			$errors,
			$this->validateWebhookDestinations($settings['discord_webhooks'] ?? [], 'discord'),
			$this->validateWebhookDestinations($settings['generic_webhooks'] ?? [], 'generic')
		);
		return array_values(array_unique($errors));
	}

	private function validateConfigValueTypes(array $settings)
	{
		$errors = [];
		$stringFields = [
			'enabled', 'public_pbx_host', 'page_group', 'system_notification_emails', 'mail_to', 'discord_webhook_url',
			'nws_api_base_url', 'nws_zone', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end',
			'mail_from_name', 'mail_from_local_part', 'mail_from_domain', 'mail_from_addr',
			'alert_email_subject', 'alert_email_body', 'test_email_subject', 'test_email_body',
			'opening_tone', 'closing_tone', 'nws_opening_tone', 'nws_closing_tone', 'email_html_enabled',
			'piper_bin', 'piper_voice', 'nws_piper_voice', 'announcement_piper_voice',
			'announcement_timeout_mode', 'desktop_auth_key', 'sound_dir', 'asterisk_sound_prefix',
		];
		$integerFields = [
			'tts_max_seconds', 'nws_tts_volume', 'announcement_tts_volume', 'announcement_cooldown_seconds',
			'announcement_timeout_seconds', 'log_retention_days',
		];
		$listFields = [
			'alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients',
			'scheduled_announcements', 'discord_webhooks', 'generic_webhooks',
		];
		$objectFields = ['control_api', 'updates', 'setup', 'sipnotify', 'ami', 'xweather'];
		foreach ($stringFields as $field) {
			if (array_key_exists($field, $settings) && !is_string($settings[$field])) {
				$errors[] = sprintf(_('%s must be a string.'), $field);
			}
		}
		foreach ($integerFields as $field) {
			if (array_key_exists($field, $settings) && !is_int($settings[$field])) {
				$errors[] = sprintf(_('%s must be an integer.'), $field);
			}
		}
		foreach ($listFields as $field) {
			if (array_key_exists($field, $settings) && (!is_array($settings[$field]) || !array_is_list($settings[$field]))) {
				$errors[] = sprintf(_('%s must be an array.'), $field);
			}
		}
		foreach ($objectFields as $field) {
			if (array_key_exists($field, $settings)
				&& (!is_array($settings[$field]) || (!empty($settings[$field]) && array_is_list($settings[$field])))) {
				$errors[] = sprintf(_('%s must be an object.'), $field);
			}
		}
		foreach (['enabled', 'quiet_hours_enabled', 'email_html_enabled'] as $field) {
			if (is_string($settings[$field] ?? null) && !in_array($settings[$field], ['0', '1'], true)) {
				$errors[] = sprintf(_('%s must be 0 or 1.'), $field);
			}
		}

		$nestedSchemas = [
			'ami' => [
				'string' => ['username', 'password', 'host'], 'integer' => ['port'], 'flag' => [], 'array' => [],
			],
			'control_api' => [
				'string' => ['enabled', 'api_key', 'base_url', 'ip_allowlist_enabled', 'ip_allowlist', 'rate_limit_enabled'],
				'integer' => ['rate_limit_per_minute', 'audit_retention_days'], 'flag' => ['enabled', 'ip_allowlist_enabled', 'rate_limit_enabled'], 'array' => [],
			],
			'updates' => [
				'string' => ['github_enabled', 'repository', 'channel'], 'integer' => [], 'flag' => ['github_enabled'], 'array' => [],
			],
			'setup' => [
				'string' => ['completed', 'beta_accepted', 'agpl_accepted', 'eula_accepted', 'completed_at'], 'integer' => [],
				'flag' => ['completed', 'beta_accepted', 'agpl_accepted', 'eula_accepted'], 'array' => [],
			],
			'sipnotify' => [
				'string' => ['pbx_host', 'base_url', 'media_scheme', 'media_base_url'], 'integer' => [], 'flag' => [], 'array' => ['format_overrides'],
			],
			'xweather' => [
				'string' => ['enabled', 'client_id', 'client_secret', 'location', 'adaptive_free_tier', 'adaptive_nws_zone_id', 'opening_tone', 'closing_tone', 'all_clear', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'],
				'integer' => ['radius_miles', 'query_interval_minutes', 'adaptive_grace_minutes', 'tts_volume'],
				'flag' => ['enabled', 'adaptive_free_tier', 'quiet_hours_enabled'], 'array' => ['recipients', 'groups'],
			],
		];
		foreach ($nestedSchemas as $objectField => $schema) {
			if (!is_array($settings[$objectField] ?? null) || (!empty($settings[$objectField]) && array_is_list($settings[$objectField]))) {
				continue;
			}
			$nestedAllowed = array_fill_keys(array_merge($schema['string'], $schema['integer'], $schema['array']), true);
			foreach (array_keys($settings[$objectField]) as $field) {
				if (!isset($nestedAllowed[$field])) {
					$errors[] = sprintf(_('Unknown config key: %s.%s.'), $objectField, (string)$field);
				}
			}
			foreach ($schema['string'] as $field) {
				if (array_key_exists($field, $settings[$objectField]) && !is_string($settings[$objectField][$field])) {
					$errors[] = sprintf(_('%s.%s must be a string.'), $objectField, $field);
				}
			}
			foreach ($schema['integer'] as $field) {
				if (array_key_exists($field, $settings[$objectField]) && !is_int($settings[$objectField][$field])) {
					$errors[] = sprintf(_('%s.%s must be an integer.'), $objectField, $field);
				}
			}
			foreach ($schema['array'] as $field) {
				$requiresList = !($objectField === 'sipnotify' && $field === 'format_overrides');
				if (array_key_exists($field, $settings[$objectField])
					&& (!is_array($settings[$objectField][$field]) || ($requiresList && !array_is_list($settings[$objectField][$field])))) {
					$errors[] = sprintf(_('%s.%s must be an array.'), $objectField, $field);
				}
			}
			foreach ($schema['flag'] as $field) {
				if (is_string($settings[$objectField][$field] ?? null) && !in_array($settings[$objectField][$field], ['0', '1'], true)) {
					$errors[] = sprintf(_('%s.%s must be 0 or 1.'), $objectField, $field);
				}
			}
		}
		if (is_array($settings['nws_zones'] ?? null)) {
			foreach ($settings['nws_zones'] as $index => $zone) {
				if (!is_array($zone) || (!empty($zone) && array_is_list($zone))) {
					continue;
				}
				$zoneAllowed = ['id', 'name', 'zone', 'extensions', 'recipients', 'desktop_clients', 'email_recipients'];
				foreach (array_keys($zone) as $field) {
					if (!in_array($field, $zoneAllowed, true)) {
						$errors[] = sprintf(_('Unknown config key: nws_zones[%d].%s.'), $index, (string)$field);
					}
				}
				foreach (['id', 'name', 'zone'] as $field) {
					if (array_key_exists($field, $zone) && !is_string($zone[$field])) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a string.'), $index, $field);
					}
				}
				foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients'] as $field) {
					if (array_key_exists($field, $zone) && (!is_array($zone[$field]) || !array_is_list($zone[$field]))) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be an array.'), $index, $field);
					} elseif (is_array($zone[$field] ?? null)) {
						foreach ($zone[$field] as $entry) {
							if (!is_string($entry)) {
								$errors[] = sprintf(_('nws_zones[%d].%s entries must be strings.'), $index, $field);
								break;
							}
						}
					}
				}
			}
		}
		if (is_array($settings['xweather']['groups'] ?? null)) {
			$errors = array_merge($errors, $this->validateXweatherGroupsInput($settings['xweather']['groups']));
		}
		foreach (['desktop_clients', 'announcement_groups', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks'] as $field) {
			if (!is_array($settings[$field] ?? null)) {
				continue;
			}
			foreach ($settings[$field] as $index => $entry) {
				if (!is_array($entry) || (!empty($entry) && array_is_list($entry))) {
					$errors[] = sprintf(_('%s[%d] must be an object.'), $field, $index);
				}
			}
		}
		if (is_array($settings['scheduled_announcements'] ?? null)) {
			foreach ($settings['scheduled_announcements'] as $index => $schedule) {
				if (!is_array($schedule) || !array_key_exists('recurrence', $schedule)) {
					continue;
				}
				$recurrence = $schedule['recurrence'];
				if (!is_array($recurrence) || (!empty($recurrence) && array_is_list($recurrence))) {
					$errors[] = sprintf(_('scheduled_announcements[%d].recurrence must be an object.'), $index);
					continue;
				}
				foreach (array_keys($recurrence) as $recurrenceField) {
					if (!in_array($recurrenceField, ['mode', 'starts_at_local'], true)) {
						$errors[] = sprintf(_('Unknown config key: scheduled_announcements[%d].recurrence.%s.'), $index, (string)$recurrenceField);
					}
				}
				foreach (['mode', 'starts_at_local'] as $recurrenceField) {
					if (array_key_exists($recurrenceField, $recurrence) && !is_string($recurrence[$recurrenceField])) {
						$errors[] = sprintf(_('scheduled_announcements[%d].recurrence.%s must be a string.'), $index, $recurrenceField);
					}
				}
			}
		}
		foreach (['alert_recipients', 'quiet_critical_events'] as $field) {
			if (!is_array($settings[$field] ?? null)) {
				continue;
			}
			foreach ($settings[$field] as $entry) {
				if (!is_string($entry)) {
					$errors[] = sprintf(_('%s entries must be strings.'), $field);
					break;
				}
			}
		}
		return array_values(array_unique($errors));
	}

	public function applySettings()
	{
		try {
			$this->applyPendingSettingsTransaction();
			return [
				'success' => true,
				'message' => _('Changes applied to the live Mass Notification scripts.'),
				'errors' => [],
			];
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to apply settings.'),
				'errors' => [$e->getMessage()],
			];
		}
	}

	private function applyPendingSettingsTransaction()
	{
		$this->ensurePluginDataDir();
		$lock = $this->acquireSettingsLock();
		try {
			$activeSettings = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
			if (is_readable(self::PENDING_SETTINGS_JSON)) {
				$settings = $this->normalizeSettings($this->loadSettingsFile(self::PENDING_SETTINGS_JSON));
			} else {
				$settings = $activeSettings;
			}
			if ($this->isSetupComplete($activeSettings)) {
				$settings['setup'] = $activeSettings['setup'];
			}
			$this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $this->normalizeSettings($settings), true);
			if (is_file(self::PENDING_SETTINGS_JSON) && !@unlink(self::PENDING_SETTINGS_JSON)) {
				throw new \RuntimeException(_('Changes were applied, but the staged settings file could not be removed safely.'));
			}
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	public function triggerTest($mode = 'tts', $sound = '', $triggerName = 'FreePBX Dashboard', array $zoneIds = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
			];
		}

		$cooldown = $this->getTestCooldownState();
		if ($cooldown['remaining'] > 0) {
			return [
				'success' => false,
				'message' => sprintf(_('Manual testing is on cooldown. Wait %s seconds and try again.'), $cooldown['remaining']),
			];
		}

		$settings = $this->getActiveSettings();
		if (($settings['enabled'] ?? '0') !== '1') {
			return ['success' => false, 'message' => _('Enable Weather Alerts before running a delivery test.'), 'errors' => []];
		}
		$groups = $this->normalizeNwsZoneGroups($settings['nws_zones'] ?? [], $settings['nws_zone'] ?? '', $settings['alert_recipients'] ?? []);
		$selectedIds = [];
		$invalidSelectedIds = [];
		foreach ($zoneIds as $value) {
			$value = trim((string)$value);
			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)) {
				$invalidSelectedIds[] = _('invalid zone identifier');
				continue;
			}
			$selectedIds[$value] = true;
		}
		$availableZoneIds = array_fill_keys(array_map(static function ($group) {
			return (string)($group['id'] ?? '');
		}, $groups), true);
		$unknownSelectedIds = array_values(array_diff(array_keys($selectedIds), array_keys($availableZoneIds)));
		if (!empty($invalidSelectedIds) || !empty($unknownSelectedIds)) {
			return [
				'success' => false,
				'message' => _('The Weather test request contains an unknown or invalid zone selection. Reload the page and select configured zones only.'),
				'errors' => [],
			];
		}
		$recipients = [];
		$desktopRecipients = [];
		$unavailableDesktopRecipients = [];
		$zones = [];
		$enabledDesktopClients = [];
		foreach ($this->getDesktopClients($settings) as $desktopClient) {
			$username = (string)($desktopClient['username'] ?? '');
			if ($username !== '' && !empty($desktopClient['enabled'])) {
				$enabledDesktopClients[$username] = true;
			}
		}
		foreach ($groups as $group) {
			if (!empty($selectedIds) && !isset($selectedIds[$group['id']])) {
				continue;
			}
			$zones[] = (string)$group['zone'];
			foreach ((array)$group['extensions'] as $extension) {
				$recipients[$extension] = $extension;
			}
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username === '') {
					continue;
				}
				if (isset($enabledDesktopClients[$username])) {
					$desktopRecipients[$username] = $username;
				} else {
					$unavailableDesktopRecipients[$username] = $username;
				}
			}
		}
		if (!empty($unavailableDesktopRecipients)) {
			return [
				'success' => false,
				'message' => sprintf(
					_('The selected Weather zones reference unavailable or disabled desktop clients: %s. Update the zone group before testing.'),
					implode(', ', array_values($unavailableDesktopRecipients))
				),
				'errors' => [],
			];
		}
		if (empty($recipients) && empty($desktopRecipients)) {
			return [
				'success' => false,
				'message' => _('The selected Weather zones have no phone or enabled desktop channels to test. Per-zone email recipients are intentionally not contacted by manual tests.'),
				'errors' => [],
			];
		}

		$ttsMaximum = max(1, min(600, (int)($settings['tts_max_seconds'] ?? 30)));
		$testTimeout = min(960, max(180, ($ttsMaximum * 2) + 120));
		$cmd = '/usr/bin/timeout --signal=TERM --kill-after=10 ' . $testTimeout
			. ' /usr/bin/env NWS_ZONE_OVERRIDE=' . escapeshellarg(implode(',', $zones))
			. ' NWS_RECIPIENTS_OVERRIDE=' . escapeshellarg(implode(',', array_values($recipients)))
			. ' NWS_DESKTOP_CLIENTS_OVERRIDE=' . escapeshellarg(implode(',', array_values($desktopRecipients)))
			. ' ' . escapeshellarg(self::TEST_SCRIPT)
			. ' '
			. escapeshellarg('GUI')
			. ' '
			. escapeshellarg($triggerName)
			. ' 2>&1';

		exec($cmd, $output, $exitCode);

		if ($exitCode !== 0) {
			$detail = $this->sanitizeTestCommandOutput($output);
			$requestedChannels = [];
			if (!empty($recipients)) {
				$requestedChannels[] = _('phone audio and SIP NOTIFY submission');
			}
			if (!empty($desktopRecipients)) {
				$requestedChannels[] = _('targeted live desktop publication');
			}
			return [
				'success' => false,
				'message' => sprintf(
					_('Weather test failed before every requested local channel was accepted: %s.'),
					implode('; ', $requestedChannels)
				),
				'errors' => $detail !== '' ? [$detail] : [_('Review Notification Logs for the delivery stage that failed.')],
			];
		}

		if (!empty($recipients) && !empty($desktopRecipients)) {
			$message = sprintf(
				_('Weather test submitted locally for %d phone recipient(s) and %d desktop recipient(s). Asterisk picked up the audio jobs and accepted SIP NOTIFY; the targeted desktop event was published. Handset answer, visual display, and desktop-app display are not confirmed.'),
				count($recipients),
				count($desktopRecipients)
			);
		} elseif (!empty($recipients)) {
			$message = sprintf(
				_('Weather test submitted locally for %d phone recipient(s). Asterisk picked up the audio jobs and accepted SIP NOTIFY. Handset answer and visual display are not confirmed.'),
				count($recipients)
			);
		} else {
			$message = sprintf(
				_('Weather test published to %d targeted desktop recipient(s). Desktop-app display is not confirmed.'),
				count($desktopRecipients)
			);
		}
		$message .= ' ' . _('Manual tests do not send global or per-zone email, Discord, or generic webhook notifications.');
		return [
			'success' => true,
			'message' => $message,
			'errors' => [],
		];
	}

	public function verifyLightningConnection()
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return ['success' => false, 'message' => $this->getSetupRequiredMessage(), 'errors' => []];
		}
		$settings = $this->getActiveSettings();
		$xweather = $this->normalizeXweatherSettings($settings['xweather'] ?? [], $settings['nws_tts_volume'] ?? 25);
		$enabledGroups = array_values(array_filter((array)($xweather['groups'] ?? []), static function ($group) {
			return is_array($group) && ($group['enabled'] ?? '0') === '1';
		}));
		if ($xweather['client_id'] === '' || $xweather['client_secret'] === '' || empty($enabledGroups)) {
			return ['success' => false, 'message' => _('Apply valid Xweather credentials and at least one enabled Lightning trigger area before verifying the connection.'), 'errors' => []];
		}
		foreach ($enabledGroups as $group) {
			if (trim((string)($group['location'] ?? '')) === '') {
				return ['success' => false, 'message' => _('Every enabled Lightning trigger area needs an Xweather location before verification.'), 'errors' => []];
			}
		}
		$worker = '/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py';
		if (!is_executable($worker)) {
			return ['success' => false, 'message' => _('The Xweather validation worker is unavailable.'), 'errors' => []];
		}
		$groupIds = array_values(array_filter(array_map(static function ($group) {
			return preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? ''));
		}, $enabledGroups)));
		$command = '/usr/bin/timeout 180 /usr/bin/env XWEATHER_VERIFY_ONLY=1 XWEATHER_GROUP_IDS=' . escapeshellarg(implode(',', $groupIds))
			. ' /usr/bin/python3 ' . escapeshellarg($worker) . ' >/dev/null 2>&1';
		exec($command, $output, $exitCode);
		return $exitCode === 0
			? ['success' => true, 'message' => sprintf(_('Xweather credentials were accepted and live API validation completed for %d enabled Lightning trigger area(s).'), count($enabledGroups)), 'errors' => []]
			: ['success' => false, 'message' => _('Xweather rejected the credentials or the live API query could not be completed. Check Dashboard health and the notification log for the sanitized error.'), 'errors' => []];
	}

	public function getXweatherApiUsageSummary()
	{
		return $this->buildXweatherApiUsageSummary(
			$this->loadStatusData(),
			$this->getActiveSettings(),
			time()
		);
	}

	private function buildXweatherApiUsageSummary(array $status, array $settings, $now = null)
	{
		$now = $now === null ? time() : max(0, (int)$now);
		$limit = max(0, (int)($status['xweather_rate_limit_period'] ?? 0));
		$remaining = max(0, (int)($status['xweather_rate_remaining_period'] ?? 0));
		$interval = max(1, min(10, (int)($settings['xweather']['query_interval_minutes'] ?? 5)));
		$queryCost = max(0, (int)($status['xweather_last_query_cost_tokens'] ?? 0));
		$resetAt = trim((string)($status['xweather_rate_reset_period'] ?? ''));
		$resetTimestamp = max(0, (int)($status['xweather_rate_reset_epoch'] ?? 0));
		if ($resetTimestamp <= 0 && $resetAt !== '') {
			$parsedReset = strtotime($resetAt);
			$resetTimestamp = $parsedReset === false ? 0 : max(0, (int)$parsedReset);
		}
		$observedAt = trim((string)($status['xweather_rate_observed_at'] ?? ''));
		$observedTimestamp = $observedAt === '' ? false : strtotime($observedAt);
		if ($limit <= 0) {
			$periodState = 'unavailable';
		} elseif ($resetTimestamp <= 0) {
			$periodState = 'unknown';
		} elseif ($resetTimestamp <= $now) {
			$periodState = 'expired';
		} else {
			$periodState = 'current';
		}
		$queriesPerDay = (int)ceil(1440 / $interval);
		$estimatedDaily = $queryCost > 0 ? $queriesPerDay * $queryCost : 0;
		$estimatedThirtyDay = $estimatedDaily * 30;
		$estimatedDaysRemaining = ($periodState === 'current' && $queryCost > 0 && $queriesPerDay > 0)
			? round($remaining / ($queriesPerDay * $queryCost), 1)
			: null;
		return [
			'limit' => $limit,
			'remaining' => $remaining,
			'used' => $limit > 0 ? max(0, $limit - $remaining) : 0,
			'reset_at' => $resetAt,
			'reset_at_formatted' => $resetAt === '' ? '' : $this->formatStatusTimestamp($resetAt),
			'observed_at' => $observedAt,
			'observed_at_formatted' => $observedAt === '' ? '' : $this->formatStatusTimestamp($observedAt),
			'snapshot_age_seconds' => $observedTimestamp === false ? null : max(0, $now - (int)$observedTimestamp),
			'period_state' => $periodState,
			'snapshot_current' => $periodState === 'current',
			'interval_minutes' => $interval,
			'max_queries_per_day' => $queriesPerDay,
			'last_query_cost_tokens' => $queryCost,
			'estimated_tokens_per_day' => $estimatedDaily,
			'estimated_tokens_per_30_days' => $estimatedThirtyDay,
			'estimated_days_remaining' => $estimatedDaysRemaining,
			'free_tier_month_sustainable' => $limit > 0 && $estimatedThirtyDay > 0 && $estimatedThirtyDay <= $limit,
		];
	}

	public function triggerLightningTest($triggerName = 'FreePBX Dashboard', array $requestedGroupIds = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return ['success' => false, 'message' => $this->getSetupRequiredMessage()];
		}
		$settings = $this->getActiveSettings();
		$xweather = $this->normalizeXweatherSettings($settings['xweather'] ?? [], $settings['nws_tts_volume'] ?? 25);
		$enabledGroups = [];
		foreach ((array)($xweather['groups'] ?? []) as $group) {
			if (!is_array($group) || ($group['enabled'] ?? '0') !== '1') {
				continue;
			}
			$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? ''));
			if ($groupId !== '') {
				$enabledGroups[$groupId] = $group;
			}
		}
		$selectedIds = [];
		foreach ($requestedGroupIds as $requestedId) {
			$requestedId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$requestedId);
			if ($requestedId !== '' && isset($enabledGroups[$requestedId])) {
				$selectedIds[$requestedId] = $requestedId;
			}
		}
		if (empty($selectedIds) && empty($requestedGroupIds) && !empty($enabledGroups)) {
			$firstId = (string)array_key_first($enabledGroups);
			$selectedIds[$firstId] = $firstId;
		}
		if (empty($selectedIds)) {
			return ['success' => false, 'message' => _('Select at least one enabled, applied Lightning trigger area before testing.'), 'errors' => []];
		}
		if (!is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py')) {
			return ['success' => false, 'message' => _('The lightning alert worker is unavailable.')];
		}
		$cooldown = $this->claimLightningTestCooldown();
		if (empty($cooldown['claimed'])) {
			if (!empty($cooldown['remaining'])) {
				return ['success' => false, 'message' => sprintf(_('Manual testing is on cooldown. Wait %s seconds and try again.'), $cooldown['remaining'])];
			}
			return ['success' => false, 'message' => _('Unable to claim the protected Lightning test cooldown. Check Dashboard health and try again.')];
		}
		$command = '/usr/bin/timeout 600 /usr/bin/env XWEATHER_TEST_EVENT=entry XWEATHER_GROUP_IDS=' . escapeshellarg(implode(',', $selectedIds))
			. ' XWEATHER_TEST_TRIGGER_NAME=' . escapeshellarg(substr(trim((string)$triggerName), 0, 80))
			. ' /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py 2>&1';
		exec($command, $output, $exitCode);
		return $exitCode === 0
			? [
				'success' => true,
				'message' => sprintf(
					_('Lightning test completed for %d selected trigger area(s). Configured phone routes passed the Asterisk page-job and SIP NOTIFY submission checks, and configured desktop routes were published. Handset and desktop-app display are not confirmed. Email, Discord, and generic webhooks were skipped.'),
					count($selectedIds)
				),
				'errors' => [],
			]
			: [
				'success' => false,
				'message' => _('Lightning test failed. No success is reported unless Asterisk completes the audio page job and accepts the SIP NOTIFY submission.'),
				'errors' => ($detail = $this->sanitizeTestCommandOutput($output)) !== '' ? [$detail] : [_('Review Notification Logs for the delivery stage that failed.')],
			];
	}

	private function sanitizeTestCommandOutput(array $lines)
	{
		$text = trim(implode(' ', array_slice($lines, -8)));
		$text = preg_replace('/\s+/', ' ', $text);
		$text = preg_replace('#https://discord(?:app)?\.com/api/webhooks/[^\s]+#i', '[redacted Discord webhook]', $text);
		$text = preg_replace('/(?:password|secret|token|api[_ -]?key)\s*[=:]\s*\S+/i', '$1=[redacted]', $text);
		return mb_substr(trim((string)$text), 0, 500);
	}

	public function sendSipNotifyAnnouncement($extensions, $message, $massNotify = true, $ttsAudio = false, $groups = [], array $options = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'cooldown_remaining' => 0,
				'error_code' => 'setup_incomplete',
				'delivery_started' => false,
			];
		}
		$this->ensurePluginDataDir();
		$announcementLock = @fopen(self::ANNOUNCEMENT_LOCK_FILE, 'c');
		if ($announcementLock === false || !flock($announcementLock, LOCK_EX | LOCK_NB)) {
			if (is_resource($announcementLock)) {
				fclose($announcementLock);
			}
			return [
				'success' => false,
				'message' => _('Another announcement is already being delivered. Wait for it to finish and try again.'),
				'cooldown_remaining' => 0,
				'error_code' => 'delivery_busy',
				'delivery_started' => false,
			];
		}
		$this->setOwnership(self::ANNOUNCEMENT_LOCK_FILE);

		$cooldown = $this->getAnnouncementCooldownState();
		if ($cooldown['remaining'] > 0) {
			return [
				'success' => false,
				'message' => sprintf(_('SIP NOTIFY announcements are on cooldown. Wait %s seconds and try again.'), $cooldown['remaining']),
				'cooldown_remaining' => $cooldown['remaining'],
				'error_code' => 'cooldown',
				'delivery_started' => false,
			];
		}

		$message = trim((string)$message);
		$message = preg_replace('/[^\P{C}\r\n\t]/u', '', $message);
		if ($message === '') {
			return [
				'success' => false,
				'message' => _('Enter an announcement message before sending.'),
				'cooldown_remaining' => 0,
				'error_code' => 'invalid_message',
				'delivery_started' => false,
			];
		}
		$length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
		if ($length > 500) {
			$message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
		}

		$allowedTargets = $this->getSipNotifyTargets();
		$allowed = [];
		foreach ($allowedTargets as $target) {
			$allowed[$target['extension']] = true;
		}
				$desktopClients = [];
				$desktopUsernames = [];
				$desktopClientIds = [];
				foreach ($this->getDesktopClients($this->getActiveSettings()) as $client) {
					if (!empty($client['enabled'])) {
						$desktopClients[$client['username']] = $client;
						$desktopUsernames[$this->normalizeDesktopUsername($client['username'] ?? '')] = $client['username'];
						$desktopClientIds[$this->normalizeDesktopClientId($client['client_id'] ?? '')] = $client['username'];
					}
				}
			$desktopAll = !empty($options['desktop_all']);
			$selectedDesktopClients = [];
				foreach ((array)($options['desktop_clients'] ?? []) as $selector) {
					$selector = strtolower(trim((string)$selector));
					$usernameKey = $this->normalizeDesktopUsername($selector);
					$clientIdKey = $this->normalizeDesktopClientId($selector);
					$username = $desktopUsernames[$usernameKey] ?? $desktopClientIds[$clientIdKey] ?? '';
					if ($username !== '') {
						$selectedDesktopClients[$username] = $username;
					}
				}

			$selected = [];
			$groupLookup = [];
			foreach ($this->getAnnouncementGroups() as $group) {
				$groupLookup[$group['id']] = $group;
			}
		foreach ((array)$groups as $groupId) {
			$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId);
			if ($groupId === '' || empty($groupLookup[$groupId])) {
				continue;
			}
			foreach ((array)$groupLookup[$groupId]['extensions'] as $extension) {
				if (isset($allowed[$extension])) {
					$selected[$extension] = $extension;
				}
			}
			foreach ((array)($groupLookup[$groupId]['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '' && isset($desktopClients[$username])) {
					$selectedDesktopClients[$username] = $username;
				}
			}
		}
			foreach ((array)$extensions as $extension) {
				$extension = preg_replace('/[^0-9]/', '', (string)$extension);
				if ($extension !== '' && isset($allowed[$extension])) {
					$selected[$extension] = $extension;
				}
			}
			if (!empty($options['phones_all'])) {
				foreach (array_keys($allowed) as $extension) {
					$selected[$extension] = $extension;
				}
			}
			$massNotify = (bool)$massNotify;
			$desktopRequested = $desktopAll || !empty($selectedDesktopClients);
			if ($desktopRequested) {
				$massNotify = true;
			}
			$audioMode = $this->normalizeAnnouncementAudioMode($options['audio_mode'] ?? ((bool)$ttsAudio ? 'tones_tts' : 'none'));
			$ttsAudio = in_array($audioMode, ['tts', 'tones_tts'], true);
			$audioEnabled = $audioMode !== 'none';
			$availableToneLookup = array_fill_keys($this->getAvailableTones(), true);
			$openingTone = $this->normalizeToneName((string)($options['opening_tone'] ?? $this->getActiveSettings()['opening_tone'] ?? ''));
			$closingTone = $this->normalizeToneName((string)($options['closing_tone'] ?? $this->getActiveSettings()['closing_tone'] ?? ''));
			foreach ([$openingTone, $closingTone] as $selectedTone) {
				if ($selectedTone !== '' && !isset($availableToneLookup[$selectedTone])) {
					return ['success' => false, 'message' => _('A selected announcement tone is unavailable.'), 'cooldown_remaining' => 0, 'error_code' => 'invalid_tone', 'delivery_started' => false];
				}
			}

			if (empty($selected) && !$desktopRequested) {
				return [
					'success' => false,
					'message' => _('Select at least one extension or one SLS Mass Notify App target.'),
					'cooldown_remaining' => 0,
					'error_code' => 'no_targets',
					'delivery_started' => false,
				];
			}

			if ($audioEnabled && empty($selected)) {
				return [
					'success' => false,
					'message' => _('Select at least one extension before enabling announcement audio.'),
					'cooldown_remaining' => 0,
					'error_code' => 'no_audio_targets',
					'delivery_started' => false,
				];
			}

		if (!is_executable(self::VISUAL_PUSH_SCRIPT)) {
			return [
				'success' => false,
				'message' => _('The SIP NOTIFY sender script is missing or not executable.'),
				'cooldown_remaining' => 0,
				'error_code' => 'sender_unavailable',
				'delivery_started' => false,
			];
		}

			$style = strtolower(trim((string)($options['style'] ?? 'standard')));
			$image = in_array($style, ['colored', 'image', 'nws'], true) || !empty($options['image']);
			$title = trim((string)($options['title'] ?? 'Announcement'));
			if ($title === '') {
				$title = 'Announcement';
			}
			$title = function_exists('mb_substr') ? mb_substr($title, 0, 80) : substr($title, 0, 80);
			$backgroundColor = $this->normalizeHexColor((string)($options['background_color'] ?? '#1f2937'), '#1f2937');
			$triggerSource = trim((string)($options['trigger_source'] ?? 'FreePBX Dashboard'));
			if ($triggerSource === '') {
				$triggerSource = 'FreePBX Dashboard';
			}

			$settings = $this->getActiveSettings();
			$timeoutMode = $this->normalizeAnnouncementTimeoutMode($settings['announcement_timeout_mode'] ?? 'none');
			$displayTimeout = $timeoutMode === 'custom'
				? $this->normalizeAnnouncementTimeoutSeconds($settings['announcement_timeout_seconds'] ?? 300)
				: 0;
			$desktopPublished = false;
			$deliveryCooldownStarted = false;
			$startDeliveryCooldown = function () use (&$deliveryCooldownStarted) {
				if (!$deliveryCooldownStarted) {
					$this->setAnnouncementCooldown();
					$deliveryCooldownStarted = true;
				}
			};
			$publishDesktop = function ($timeoutSeconds) use (
				$message,
				$image,
				$title,
				$backgroundColor,
				$desktopAll,
				$selectedDesktopClients
			) {
				$command = $this->buildAnnouncementVisualPushCommand($message, [], $timeoutSeconds, [
					'mode' => 'api_only',
					'image' => $image,
					'title' => $title,
					'background_color' => $backgroundColor,
					'desktop_all' => $desktopAll,
					'desktop_clients' => array_values($selectedDesktopClients),
				]);
				return $this->executeAnnouncementVisualPushCommand($command);
			};
			$desktopNeedsAudioDuration = $desktopRequested && $timeoutMode === 'audio' && $audioEnabled;
			if ($desktopRequested && !$desktopNeedsAudioDuration) {
				$desktopResult = $publishDesktop($displayTimeout);
				if (empty($desktopResult['success'])) {
					$failureCooldown = $this->getAnnouncementCooldownState();
					return [
						'success' => false,
						'message' => $this->announcementVisualFailureMessage(
							_('The Desktop App announcement could not be published.'),
							$desktopResult
						),
						'cooldown_remaining' => $failureCooldown['remaining'],
						'error_code' => 'desktop_publish_failed',
						'delivery_started' => false,
						'desktop_published' => false,
						'partial_delivery' => false,
					];
				}
				$desktopPublished = true;
				$startDeliveryCooldown();
			}

			$audioMessage = '';
			$notifyDelay = 0;
			$audioDuration = 0;
			$notifyStatus = 'submitted';
			if ($audioEnabled) {
				$audioResult = $this->sendAnnouncementTtsAudio(array_values($selected), $message, [
					'trigger_source' => $triggerSource,
					'announcement_style' => $style,
					'desktop_all' => $desktopAll,
					'desktop_clients' => array_values($selectedDesktopClients),
					'audio_mode' => $audioMode,
					'opening_tone' => $openingTone,
					'closing_tone' => $closingTone,
					'piper_voice' => (string)($options['piper_voice'] ?? ''),
					'tts_volume' => $options['tts_volume'] ?? null,
				]);
				if (empty($audioResult['success'])) {
					$messagePrefix = $desktopPublished
						? _('The Desktop App announcement was published, but announcement audio failed and phone SIP NOTIFY was not submitted.')
						: _('Announcement TTS audio failed; SIP NOTIFY was not submitted to Asterisk.');
					return [
						'success' => false,
						'message' => $messagePrefix . ' ' . (string)($audioResult['message'] ?? ''),
						'cooldown_remaining' => $desktopPublished
							? $this->getAnnouncementCooldownState()['remaining']
							: 0,
						'error_code' => $desktopPublished ? 'audio_failed_after_desktop' : 'audio_failed',
						'delivery_started' => $desktopPublished || !empty($audioResult['delivery_started']),
						'desktop_published' => $desktopPublished,
						'partial_delivery' => $desktopPublished,
					];
				}
				$startDeliveryCooldown();
				$audioMessage = $ttsAudio ? _(' with TTS audio') : _(' with tone audio');
				$notifyDelay = (int)($audioResult['notify_delay_seconds'] ?? 0);
				$audioDuration = max(0, (int)ceil((float)($audioResult['audio_duration_seconds'] ?? 0)));
			}
			if ($timeoutMode === 'audio' && $audioDuration > 0) {
				$displayTimeout = max(1, $audioDuration - max(0, $notifyDelay));
			}

			if ($desktopNeedsAudioDuration) {
				$startDeliveryCooldown();
				$desktopResult = $publishDesktop($displayTimeout);
				if (empty($desktopResult['success'])) {
					$failureCooldown = $this->getAnnouncementCooldownState();
					return [
						'success' => false,
						'message' => $this->announcementVisualFailureMessage(
							_('Announcement audio was queued, but the Desktop App announcement could not be published; phone SIP NOTIFY was not submitted.'),
							$desktopResult
						),
						'cooldown_remaining' => $failureCooldown['remaining'],
						'error_code' => 'desktop_publish_failed_after_audio',
						'delivery_started' => true,
						'desktop_published' => false,
						'partial_delivery' => true,
					];
				}
				$desktopPublished = true;
			}

			if (!empty($selected)) {
				$startDeliveryCooldown();
				if ($notifyDelay > 0) {
					sleep($notifyDelay);
				}
				$phoneCommand = $this->buildAnnouncementVisualPushCommand(
					$message,
					array_values($selected),
					$displayTimeout,
					[
						'mode' => 'phone_only',
						'image' => $image,
						'title' => $title,
						'background_color' => $backgroundColor,
					]
				);
				$phoneResult = $this->executeAnnouncementVisualPushCommand($phoneCommand);
				if (empty($phoneResult['success'])) {
					if ($desktopPublished && $notifyDelay > 0) {
						$failurePrefix = _('The Desktop App announcement was published and announcement audio was queued, but the SIP NOTIFY announcement could not be submitted to Asterisk.');
					} elseif ($desktopPublished) {
						$failurePrefix = _('The Desktop App announcement was published, but the SIP NOTIFY announcement could not be submitted to Asterisk.');
					} else {
						$failurePrefix = $notifyDelay > 0
							? _('Announcement audio was queued, but the SIP NOTIFY announcement could not be submitted to Asterisk.')
							: _('The SIP NOTIFY announcement could not be submitted to Asterisk.');
					}
					$failureCooldown = $this->getAnnouncementCooldownState();
					return [
						'success' => false,
						'message' => $this->announcementVisualFailureMessage($failurePrefix, $phoneResult),
						'cooldown_remaining' => $failureCooldown['remaining'],
						'error_code' => $desktopPublished
							? 'notify_failed_after_desktop'
							: ($notifyDelay > 0 ? 'notify_failed_after_audio' : 'notify_failed'),
						'delivery_started' => true,
						'desktop_published' => $desktopPublished,
						'partial_delivery' => $desktopPublished || $notifyDelay > 0,
					];
				}
				if ($notifyDelay > 0) {
					$audioMessage .= sprintf(_('; text notification submitted to Asterisk after %s seconds'), $notifyDelay);
				}
			}

		$this->appendAnnouncementNotifyLog($message, [
			'status' => $notifyStatus,
			'trigger_source' => $triggerSource,
			'announcement_style' => $style,
			'phones' => array_values($selected),
			'desktop_all' => $desktopAll,
			'desktop_clients' => array_values($selectedDesktopClients),
			'mass_notify' => $desktopPublished,
			'tts_audio' => $ttsAudio,
			'audio_mode' => $audioMode,
			'image' => $image,
			'title' => $title,
			'background_color' => $backgroundColor,
			'notify_delay_seconds' => $notifyDelay,
			'display_timeout_seconds' => $displayTimeout,
		]);
		$this->setAnnouncementCooldown();

			$successMessage = empty($selected)
				? _('Announcement published to the selected SLS Mass Notify App clients. Desktop-app display is not confirmed.')
				: sprintf(
					_('Announcement submitted to Asterisk for %s extension(s)%s%s. Handset acceptance is not confirmed.'),
					count($selected),
					$desktopPublished ? _(' and SLS Mass Notify App') : '',
					$audioMessage
				);
			return [
				'success' => true,
				'message' => $successMessage,
				'cooldown_remaining' => $this->normalizeAnnouncementCooldownSeconds($this->getActiveSettings()['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS),
				'error_code' => '',
				'delivery_started' => true,
				'desktop_published' => $desktopPublished,
				'partial_delivery' => false,
				'display_timeout_seconds' => $displayTimeout,
			];
		}

	private function buildAnnouncementVisualPushCommand($message, array $targets, $displayTimeout, array $options = [])
	{
		$mode = ($options['mode'] ?? 'phone_only') === 'api_only' ? 'api_only' : 'phone_only';
		$command = '/usr/bin/timeout --signal=TERM 90 /usr/bin/python3 '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT)
			. ' --announcement ' . escapeshellarg((string)$message)
			. ' --announcement-timeout-seconds ' . max(0, (int)$displayTimeout);
		if (!empty($options['image'])) {
			$command .= ' --announcement-image'
				. ' --announcement-title ' . escapeshellarg((string)($options['title'] ?? 'Announcement'))
				. ' --announcement-bg-color ' . escapeshellarg((string)($options['background_color'] ?? '#1f2937'));
		}
		if ($mode === 'api_only') {
			$command .= ' --api-only';
			if (!empty($options['desktop_all'])) {
				$command .= ' --desktop-all';
			} elseif (!empty($options['desktop_clients'])) {
				$command .= ' --desktop-targets ' . escapeshellarg(implode(',', array_values($options['desktop_clients'])));
			}
			return $command;
		}

		return $command
			. ' --targets ' . escapeshellarg(implode(',', array_values($targets)))
			. ' --no-api';
	}

	protected function executeAnnouncementVisualPushCommand($command)
	{
		$output = [];
		$exitCode = 0;
		exec((string)$command . ' 2>&1', $output, $exitCode);
		return [
			'success' => $exitCode === 0,
			'exit_code' => (int)$exitCode,
			'output' => $output,
		];
	}

	private function announcementVisualFailureMessage($prefix, array $result)
	{
		$message = trim((string)$prefix);
		if ((int)($result['exit_code'] ?? 0) === 124) {
			$message .= ' ' . _('The sender exceeded its 90-second safety timeout.');
		}
		$detail = $this->sanitizeTestCommandOutput((array)($result['output'] ?? []));
		return trim($message . ($detail !== '' ? ' ' . $detail : ''));
	}

	private function sendAnnouncementTtsAudio(array $extensions, $message, array $context = [])
	{
		$settings = $this->getActiveSettings();
		$requestedVoice = (string)($context['piper_voice'] ?? '');
		$voiceLookup = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		if ($requestedVoice !== '' && isset($voiceLookup[$requestedVoice])) {
			$settings['announcement_piper_voice'] = $requestedVoice;
		}
		if (array_key_exists('tts_volume', $context)) {
			$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($context['tts_volume'], $settings['announcement_tts_volume'] ?? 25);
		}
		$audioMode = $this->normalizeAnnouncementAudioMode($context['audio_mode'] ?? 'tones_tts');
		$includeTts = in_array($audioMode, ['tts', 'tones_tts'], true);
		$includeTones = in_array($audioMode, ['tones', 'tones_tts'], true);
		$settings['opening_tone'] = $includeTones ? $this->normalizeToneName((string)($context['opening_tone'] ?? $settings['opening_tone'] ?? '')) : '';
		$settings['closing_tone'] = $includeTones ? $this->normalizeToneName((string)($context['closing_tone'] ?? $settings['closing_tone'] ?? '')) : '';
		$this->ensurePluginDataDir();
		$this->ensureRuntimePermissions();
		if ($includeTts && (!is_executable($settings['piper_bin'] ?? self::PIPER_BIN)
			|| !$this->isValidPiperVoiceFile($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE)
		)) {
			$this->ensurePiperRuntime();
			$settings = $this->getActiveSettings();
			if ($requestedVoice !== '' && isset($voiceLookup[$requestedVoice])) {
				$settings['announcement_piper_voice'] = $requestedVoice;
			}
			if (array_key_exists('tts_volume', $context)) {
				$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($context['tts_volume'], $settings['announcement_tts_volume'] ?? 25);
			}
			$settings['opening_tone'] = $includeTones ? $this->normalizeToneName((string)($context['opening_tone'] ?? $settings['opening_tone'] ?? '')) : '';
			$settings['closing_tone'] = $includeTones ? $this->normalizeToneName((string)($context['closing_tone'] ?? $settings['closing_tone'] ?? '')) : '';
		}
		$this->pruneTtsCache();

		if (empty($extensions)) {
			return ['success' => false, 'message' => _('No announcement audio recipients were selected.')];
		}
		if ($includeTts && !is_executable($settings['piper_bin'] ?? self::PIPER_BIN)) {
			return ['success' => false, 'message' => _('Piper TTS binary is missing or not executable.')];
		}
		$announcementVoice = $settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE;
		if ($includeTts && !$this->isValidPiperVoiceFile($announcementVoice)) {
			return ['success' => false, 'message' => _('Piper TTS voice model is missing or not readable. The installer could not download the selected Piper voice; check internet access from the PBX and rerun module install or download the voice files into the module piper/voices folder.')];
		}

		$ttsBase = '';
		if ($includeTts) {
			$ttsBase = $this->generateAnnouncementTtsFile($message, $settings);
			if ($ttsBase === '') {
				return ['success' => false, 'message' => _('Piper TTS audio could not be generated.')];
			}
		}

		$sequence = $this->buildAnnouncementAudioSequence($ttsBase, $settings);
		if ($sequence === '') {
			return ['success' => false, 'message' => _('Announcement audio sequence could not be built.')];
		}

		$audioDuration = $this->getAnnouncementSequenceDuration($sequence);
		$queued = $this->queueAnnouncementAudioCalls($extensions, $sequence, $audioDuration);
		if ($queued < 1) {
			return ['success' => false, 'message' => _('Unable to queue announcement audio calls.')];
		}

		$this->updateStatusData([
			'last_delivery_at' => date('c'),
			'last_delivery_status' => 'queued',
			'last_delivery_source' => 'announcement',
			'last_delivery_event' => 'Announcement',
			'last_delivery_audio' => $includeTts ? 'Piper TTS' : 'Tones only',
			'last_delivery_message' => sprintf('Queued announcement %s audio to %s extension(s)', $includeTts ? 'TTS' : 'tone', $queued),
			'last_delivery_page_group' => implode(',', $extensions),
			'last_delivery_alert_id' => '',
		]);
		$this->appendAnnouncementAudioLog($message, $sequence, $extensions, $context);

		return [
			'success' => true,
			'message' => sprintf(_('Queued announcement audio to %s extension(s).'), $queued),
			'audio_sequence' => $sequence,
			'notify_delay_seconds' => 1,
			'audio_duration_seconds' => $audioDuration,
			'delivery_started' => true,
		];
	}

	private function getAnnouncementSequenceDuration($sequence)
	{
		$sequence = trim((string)$sequence);
		$prefix = self::ASTERISK_SOUND_PREFIX . '/';
		if (strpos($sequence, $prefix) !== 0 || strpos($sequence, '&') !== false) {
			return 0.0;
		}
		$relative = substr($sequence, strlen($prefix));
		if (!preg_match('#^[A-Za-z0-9_/-]+$#', $relative)) {
			return 0.0;
		}
		$file = self::SOUNDS_DIR . '/' . $relative . '.wav';
		if (!is_readable($file) || !is_executable('/usr/bin/soxi')) {
			return 0.0;
		}
		$output = [];
		$exitCode = 0;
		exec('LC_ALL=C /usr/bin/soxi -D ' . escapeshellarg($file) . ' 2>/dev/null', $output, $exitCode);
		$duration = $exitCode === 0 ? (float)($output[0] ?? 0) : 0.0;
		return $duration > 0 && is_finite($duration) ? $duration : 0.0;
	}

	private function generateAnnouncementTtsFile($message, array $settings)
	{
		$maxSeconds = $this->normalizeTtsMaxSeconds($settings['tts_max_seconds'] ?? 30);
		$ttsText = $this->buildAnnouncementTtsText($message, $maxSeconds);
		if ($ttsText === '') {
			return '';
		}

		$baseName = 'announcement_tts_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
		$tmpFile = tempnam('/tmp', 'sls_announcement_tts_');
		$textFile = tempnam('/tmp', 'sls_announcement_text_');
		if ($tmpFile === false || $textFile === false) {
			return '';
		}
		$tmpWav = $tmpFile . '.wav';
		$outputFile = self::TTS_DIR . '/' . $baseName . '.wav';
		@unlink($tmpFile);
		file_put_contents($textFile, $ttsText . "\n");

		$generationTimeout = min(900, max(25, ($maxSeconds * 2) + 30));
			$cmd = '/usr/bin/timeout ' . (int)$generationTimeout . ' '
				. escapeshellarg($settings['piper_bin'] ?? self::PIPER_BIN)
				. ' --model '
				. escapeshellarg($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE)
				. ' --volume '
				. escapeshellarg('1.00')
			. ' --input-file '
			. escapeshellarg($textFile)
			. ' --output-file '
			. escapeshellarg($tmpWav)
			. ' 2>&1';
		exec($cmd, $output, $exitCode);
		@unlink($textFile);
		if ($exitCode !== 0 || !is_file($tmpWav)) {
			@unlink($tmpWav);
			return '';
		}

			if (is_executable('/usr/bin/sox')) {
				$cmd = '/usr/bin/sox -v '
					. escapeshellarg($this->volumePercentToScalar($settings['announcement_tts_volume'] ?? 25, 25))
					. ' '
					. escapeshellarg($tmpWav)
					. ' -r 8000 -c 1 -b 16 '
				. escapeshellarg($outputFile)
				. ' 2>&1';
			exec($cmd, $output, $exitCode);
			@unlink($tmpWav);
			if ($exitCode !== 0 || !is_file($outputFile)) {
				@unlink($outputFile);
				return '';
			}
		} else {
			@rename($tmpWav, $outputFile);
		}

		if (is_executable('/usr/bin/soxi') && is_executable('/usr/bin/sox')) {
			$durationOutput = [];
			exec('/usr/bin/soxi -D ' . escapeshellarg($outputFile) . ' 2>/dev/null', $durationOutput, $durationExit);
			$duration = $durationExit === 0 ? (float)($durationOutput[0] ?? 0) : 0.0;
			if ($duration > $maxSeconds) {
					$trimmed = $outputFile . '.trimmed.wav';
				exec('/usr/bin/sox ' . escapeshellarg($outputFile) . ' ' . escapeshellarg($trimmed) . ' trim 0 ' . escapeshellarg((string)$maxSeconds) . ' 2>&1', $trimOutput, $trimExit);
				if ($trimExit === 0 && is_file($trimmed)) {
					@rename($trimmed, $outputFile);
				} else {
					@unlink($trimmed);
				}
			}
		}

		@chmod($outputFile, 0644);
		@chown($outputFile, 'asterisk');
		@chgrp($outputFile, 'asterisk');
		return $baseName;
	}

	private function buildAnnouncementTtsText($message, $maxSeconds)
	{
		$message = trim(preg_replace('/\s+/', ' ', (string)$message));
		if ($message === '') {
			return '';
		}
		$wordLimit = max(18, min(1200, max(1, (int)$maxSeconds) * 2));
		$words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (count($words) > $wordLimit) {
			$message = implode(' ', array_slice($words, 0, $wordLimit));
			$message = rtrim($message, " \t\n\r\0\x0B,;:") . '.';
		}
		return 'Announcement. ' . $message;
	}

	private function buildAnnouncementAudioSequence($ttsBase, array $settings)
	{
		$parts = [];
		$files = [];
		$openingTone = $this->normalizeToneName((string)($settings['opening_tone'] ?? self::DEFAULT_ANNOUNCEMENT_OPENING_TONE));
		$closingTone = $this->normalizeToneName((string)($settings['closing_tone'] ?? self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE));

		if ($openingTone !== '' && is_readable(self::TONES_DIR . '/' . $openingTone . '.wav')) {
			$quietOpeningTone = $this->createQuietAnnouncementTone($openingTone, $settings['announcement_tts_volume'] ?? 25);
			if ($quietOpeningTone !== '' && is_readable(self::TTS_DIR . '/' . $quietOpeningTone . '.wav')) {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tts/' . $quietOpeningTone;
				$files[] = self::TTS_DIR . '/' . $quietOpeningTone . '.wav';
			} else {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tones/' . $openingTone;
				$files[] = self::TONES_DIR . '/' . $openingTone . '.wav';
			}
		}
		if ($ttsBase !== '' && is_readable(self::TTS_DIR . '/' . $ttsBase . '.wav')) {
			$parts[] = self::ASTERISK_SOUND_PREFIX . '/tts/' . $ttsBase;
			$files[] = self::TTS_DIR . '/' . $ttsBase . '.wav';
		}
		if ($closingTone !== '' && is_readable(self::TONES_DIR . '/' . $closingTone . '.wav')) {
			$quietClosingTone = $this->createQuietAnnouncementTone($closingTone, $settings['announcement_tts_volume'] ?? 25);
			if ($quietClosingTone !== '' && is_readable(self::TTS_DIR . '/' . $quietClosingTone . '.wav')) {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tts/' . $quietClosingTone;
				$files[] = self::TTS_DIR . '/' . $quietClosingTone . '.wav';
			} else {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tones/' . $closingTone;
				$files[] = self::TONES_DIR . '/' . $closingTone . '.wav';
			}
		}

		$sequenceKey = $ttsBase !== '' ? $ttsBase : substr(hash('sha256', implode('|', $files)), 0, 16);
		$combined = $this->combineAudioParts($sequenceKey, $files, 'announcement_sequence');
		if ($combined !== '') {
			return self::ASTERISK_SOUND_PREFIX . '/tts/' . $combined;
		}

		if (count($parts) === 1) {
			$sequence = (string)$parts[0];
			return preg_match('/^[A-Za-z0-9_\/,-]+$/', $sequence) ? $sequence : '';
		}
		return '';
	}

	private function combineAudioParts($baseName, array $files, $prefix)
	{
		if (!is_executable('/usr/bin/sox') || count($files) < 1) {
			return '';
		}
		foreach ($files as $file) {
			if (!is_readable($file)) {
				return '';
			}
		}
		$name = $this->normalizeToneName($prefix . '_v2_' . $baseName);
		if ($name === '') {
			return '';
		}
		$target = self::TTS_DIR . '/' . $name . '.wav';
		$sourceMtime = 0;
		foreach ($files as $file) {
			$sourceMtime = max($sourceMtime, (int)@filemtime($file));
		}
		if (is_readable($target) && (int)@filemtime($target) >= $sourceMtime) {
			return $name;
		}
		$tmp = $target . '.tmp.' . bin2hex(random_bytes(3)) . '.wav';
		$silence = $target . '.silence.' . bin2hex(random_bytes(3)) . '.wav';
		$silenceCmd = '/usr/bin/sox -n -r 8000 -c 1 -b 16 ' . escapeshellarg($silence) . ' trim 0.0 1.0 2>&1';
		exec($silenceCmd, $silenceOutput, $silenceExit);
		if ($silenceExit !== 0 || !is_file($silence)) {
			@unlink($silence);
			return '';
		}
		$cmd = '/usr/bin/sox';
		$cmd .= ' ' . escapeshellarg($silence);
		foreach ($files as $file) {
			$cmd .= ' ' . escapeshellarg($file);
		}
		$cmd .= ' -r 8000 -c 1 -b 16 ' . escapeshellarg($tmp) . ' 2>&1';
		exec($cmd, $output, $exitCode);
		@unlink($silence);
		if ($exitCode !== 0 || !is_file($tmp)) {
			@unlink($tmp);
			return '';
		}
		@rename($tmp, $target);
		@chmod($target, 0644);
		@chown($target, 'asterisk');
		@chgrp($target, 'asterisk');
		return is_readable($target) ? $name : '';
	}

	private function createQuietAnnouncementTone($toneName, $volumePercent = 25)
	{
		$toneName = $this->normalizeToneName($toneName);
		if ($toneName === '' || !is_executable('/usr/bin/sox')) {
			return '';
		}

		$source = self::TONES_DIR . '/' . $toneName . '.wav';
		if (!is_readable($source)) {
			return '';
		}

		$volumePercent = $this->normalizeTtsVolume($volumePercent, 25);
		$quietBase = 'announcement_tone_' . $toneName . '_v' . $volumePercent;
		$quietBase = $this->normalizeToneName($quietBase);
		$target = self::TTS_DIR . '/' . $quietBase . '.wav';
		if (is_readable($target) && filemtime($target) !== false && filemtime($source) !== false && filemtime($target) >= filemtime($source)) {
			return $quietBase;
		}

			$tmp = $target . '.tmp.' . bin2hex(random_bytes(3)) . '.wav';
		$cmd = '/usr/bin/sox -v ' . escapeshellarg($this->volumePercentToScalar($volumePercent, 25)) . ' '
			. escapeshellarg($source)
			. ' -r 8000 -c 1 -b 16 '
			. escapeshellarg($tmp)
			. ' 2>&1';
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0 || !is_file($tmp)) {
			@unlink($tmp);
			return '';
		}

		@rename($tmp, $target);
		@chmod($target, 0644);
		@chown($target, 'asterisk');
		@chgrp($target, 'asterisk');
		return is_readable($target) ? $quietBase : '';
	}

	private function getAudioPageHoldSeconds($duration)
	{
		$duration = (float)$duration;
		if (!is_finite($duration) || $duration <= 0 || $duration > 1767) {
			return 0;
		}
		// Page destroys its ConfBridge when the originating Local channel
		// leaves. Keep that origin alive for the complete WAV plus a bounded
		// teardown margin. The participant dial window is deliberately not part
		// of this hold; including it leaves promptly answered phones in silence.
		return (int)ceil($duration) + 2;
	}

	private function queueAnnouncementAudioCalls(array $extensions, $sequence, $audioDuration)
	{
		if ($sequence === '' || !is_dir(self::ASTERISK_OUTGOING_SPOOL) || !is_dir(self::ASTERISK_SPOOL_TMP)) {
			return 0;
		}
		$pageHoldSeconds = $this->getAudioPageHoldSeconds($audioDuration);
		if ($pageHoldSeconds < 1) {
			return 0;
		}
		$callWaitSeconds = $pageHoldSeconds + 30;

		$queued = 0;
		foreach ($extensions as $extension) {
			$recipient = preg_replace('/[^0-9]/', '', (string)$extension);
			if ($recipient === '') {
				continue;
			}
			$callFile = @tempnam(self::ASTERISK_SPOOL_TMP, 'sls_announcement_');
			if ($callFile === false || dirname($callFile) !== self::ASTERISK_SPOOL_TMP) {
				if (is_string($callFile) && $callFile !== '') {
					@unlink($callFile);
				}
				continue;
			}
			$body = "Channel: Local/{$recipient}@sls-alert-audio\n"
				. "CallerID: \"SLS Mass Notify System\" <SLS>\n"
				. "Setvar: SLS_SOUND={$sequence}\n"
				. "Setvar: SLS_CALLERID_NAME=SLS Mass Notify System\n"
				. "Setvar: SLS_CALLERID_NUM=SLS\n"
				. "MaxRetries: 0\n"
				. "RetryTime: 5\n"
				. "WaitTime: {$callWaitSeconds}\n"
				. "Application: Wait\n"
				. "Data: {$pageHoldSeconds}\n";
			if (file_put_contents($callFile, $body, LOCK_EX) === false) {
				@unlink($callFile);
				continue;
			}
			@chown($callFile, 'asterisk');
			@chgrp($callFile, 'asterisk');
			@chmod($callFile, 0640);
			$target = self::ASTERISK_OUTGOING_SPOOL . '/' . basename($callFile) . '.call';
			if (@rename($callFile, $target)) {
				$queued++;
			} else {
				@unlink($callFile);
			}
		}
		return $queued;
	}

	private function updateStatusData(array $patch)
	{
		$this->ensurePluginDataDir();
		$handle = @fopen(self::STATUS_JSON, 'c+');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			if (is_resource($handle)) {
				fclose($handle);
			}
			return;
		}
		rewind($handle);
		$decoded = json_decode((string)stream_get_contents($handle), true);
		$data = is_array($decoded) ? $decoded : [];
		foreach ($patch as $key => $value) {
			$data[$key] = $value;
		}
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json !== false) {
			rewind($handle);
			ftruncate($handle, 0);
			fwrite($handle, $json . "\n");
			fflush($handle);
		}
		flock($handle, LOCK_UN);
		fclose($handle);
		$this->setOwnership(self::STATUS_JSON);
	}

	private function appendAnnouncementAudioLog($message, $sequence, array $extensions, array $context = [])
	{
		$style = $this->normalizeAnnouncementStyleLabel((string)($context['announcement_style'] ?? 'standard'));
		$payload = $this->buildAnnouncementLogPayload('announcement_audio', $message, $context + [
			'event_id_prefix' => 'announcement-audio',
			'status' => 'queued',
			'event' => $style . ' Announcement Audio',
			'message_type' => 'Audio Page',
			'audio' => in_array(($context['audio_mode'] ?? 'tones_tts'), ['tts', 'tones_tts'], true) ? 'Piper TTS' : 'Tones only',
			'page_group' => implode(',', $extensions),
			'audio_sequence' => array_values(array_filter(explode('&', $sequence))),
		]);
		file_put_contents(self::EVENTS_LOG, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
		$this->setOwnership(self::EVENTS_LOG);
	}

	private function appendAnnouncementNotifyLog($message, array $context = [])
	{
		$style = $this->normalizeAnnouncementStyleLabel((string)($context['announcement_style'] ?? 'standard'));
		$phones = array_values(array_filter(array_map('strval', (array)($context['phones'] ?? []))));
		$desktopClients = array_values(array_filter(array_map('strval', (array)($context['desktop_clients'] ?? []))));
		$desktopTarget = !empty($context['desktop_all']) ? 'all desktops' : implode(',', $desktopClients);
		$targets = [];
		if (!empty($phones)) {
			$targets[] = 'phones:' . implode(',', $phones);
		}
		if ($desktopTarget !== '') {
			$targets[] = 'desktops:' . $desktopTarget;
		}
		$payload = $this->buildAnnouncementLogPayload('announcement', $message, $context + [
			'event_id_prefix' => 'announcement-notify',
			'event' => $style . ' Announcement',
			'message_type' => !empty($context['image']) ? 'SIP NOTIFY Image/Text' : 'SIP NOTIFY Text',
			'audio' => !empty($context['tts_audio']) ? 'Piper TTS queued separately' : (($context['audio_mode'] ?? 'none') === 'tones' ? 'Tone audio queued separately' : 'None'),
			'page_group' => implode(' | ', $targets),
			'audio_sequence' => [],
		]);
		file_put_contents(self::EVENTS_LOG, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
		$this->setOwnership(self::EVENTS_LOG);
	}

	private function buildAnnouncementLogPayload($type, $message, array $context = [])
	{
		$eventIdPrefix = preg_replace('/[^a-z0-9_-]/i', '', (string)($context['event_id_prefix'] ?? 'announcement'));
		if ($eventIdPrefix === '') {
			$eventIdPrefix = 'announcement';
		}
		return [
			'event_id' => $eventIdPrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
			'logged_at' => date('c'),
			'type' => $type,
			'status' => trim((string)($context['status'] ?? 'triggered')),
			'system_name' => 'SLS Mass Notify System',
			'source_name' => 'SLS Mass Notify System',
			'trigger_source' => trim((string)($context['trigger_source'] ?? 'FreePBX Dashboard')),
			'page_group' => trim((string)($context['page_group'] ?? '')),
			'event' => trim((string)($context['event'] ?? 'Announcement')),
			'severity' => 'Notice',
			'message_type' => trim((string)($context['message_type'] ?? 'Announcement')),
			'audio' => trim((string)($context['audio'] ?? '')),
			'audio_sequence' => is_array($context['audio_sequence'] ?? null) ? array_values($context['audio_sequence']) : [],
			'body' => $message,
			'announcement_style' => strtolower(trim((string)($context['announcement_style'] ?? 'standard'))),
			'desktop_all' => !empty($context['desktop_all']),
			'desktop_clients' => array_values((array)($context['desktop_clients'] ?? [])),
			'notify_delay_seconds' => (int)($context['notify_delay_seconds'] ?? 0),
			'background_color' => trim((string)($context['background_color'] ?? '')),
			'title' => trim((string)($context['title'] ?? 'Announcement')),
		];
	}

	private function normalizeAnnouncementStyleLabel($style)
	{
		$style = strtolower(trim((string)$style));
		if (in_array($style, ['colored', 'image', 'nws'], true)) {
			return 'Colored';
		}
		return 'General';
	}

	private function normalizeAnnouncementAudioMode($mode)
	{
		$mode = strtolower(trim((string)$mode));
		return in_array($mode, ['none', 'tones', 'tts', 'tones_tts'], true) ? $mode : 'none';
	}

	private function pruneTtsCache()
	{
		foreach (glob(self::TTS_DIR . '/*.wav') ?: [] as $path) {
			if (is_file($path) && filemtime($path) !== false && filemtime($path) < (time() - 15 * 60)) {
				@unlink($path);
			}
		}
	}

	public function getCooldownState()
	{
		return [
			'test' => $this->getTestCooldownState(),
			'lightning_test' => $this->getLightningTestCooldownState(),
			'announcement' => $this->getAnnouncementCooldownState(),
		];
	}

	public function getAnnouncementDashboardState()
	{
		$settings = $this->getActiveSettings();
		return [
			'quiet_hours_active' => $this->settingsQuietHoursActive($settings),
			'opening_tone' => (string)($settings['opening_tone'] ?? ''),
			'closing_tone' => (string)($settings['closing_tone'] ?? ''),
		];
	}

	public function repairInstallation()
	{
		$effectiveUid = function_exists('posix_geteuid') ? (int)posix_geteuid() : -1;
		if ($effectiveUid !== 0) {
			$this->ensurePluginDataDir();
			$temporary = self::REPAIR_REQUEST_FILE . '.tmp.' . bin2hex(random_bytes(4));
			if (@file_put_contents($temporary, gmdate('c') . "\n", LOCK_EX) === false) {
				return [
					'success' => false,
					'message' => _('Installation repair could not be queued.'),
					'errors' => [_('Unable to write the protected maintenance request marker.')],
				];
			}
			$this->setPrivateOwnership($temporary);
			if (!@rename($temporary, self::REPAIR_REQUEST_FILE)) {
				@unlink($temporary);
				return [
					'success' => false,
					'message' => _('Installation repair could not be queued.'),
					'errors' => [_('Unable to activate the maintenance request marker.')],
				];
			}
			$this->setPrivateOwnership(self::REPAIR_REQUEST_FILE);
			$this->writeMaintenanceProgress('repair', 'queued', _('Repair queued. Waiting for the protected maintenance worker.'));
			return [
				'success' => true,
				'message' => _('Installation repair was queued. The protected maintenance worker will run it within one minute.'),
				'errors' => [],
			];
		}
		try {
			$this->writeMaintenanceProgress('repair', 'running', _('Repairing runtime files and FreePBX integration.'));
			$this->install();
			$this->runCommand('/usr/sbin/fwconsole reload');
			$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
			$this->writeMaintenanceProgress('repair', 'complete', _('Installation repair completed successfully.'));
			return [
				'success' => true,
				'message' => _('Installation repair completed. Runtime files, permissions, dialplan, dashboard widget, cron, and signatures were refreshed.'),
				'errors' => [],
			];
		} catch (\Throwable $e) {
			$this->writeMaintenanceProgress('repair', 'failed', _('Installation repair failed. Review Notification Logs for details.'));
			return [
				'success' => false,
				'message' => _('Installation repair failed.'),
				'errors' => [$e->getMessage()],
			];
		}
	}

	/** Restore only files that FreePBX Dashboard/Framework upgrades can replace. */
	public function repairUpdateSensitiveIntegration()
	{
		$this->ensureMenuPlacement();
		$this->ensureDashboardWidget();
		return true;
	}

	public function requestManualUpdate()
	{
		if (($this->getPackageUpdateStatus()['state'] ?? 'latest') !== 'update') {
			return [
				'success' => false,
				'message' => _('No newer Mass Notify release is currently available.'),
				'errors' => [],
			];
		}
		$result = $this->queueMaintenanceAction(
			self::UPDATE_REQUEST_FILE,
			_('Manual update was queued. The protected maintenance worker will check GitHub and install a newer verified release within one minute.')
		);
		if (!empty($result['success'])) {
			$this->writeManualUpdateProgress('queued', _('Update queued. Waiting for the protected maintenance worker.'));
		}
		return $result;
	}

	public function getManualUpdateProgress()
	{
		$progress = [
			'state' => 'idle',
			'message' => '',
			'updated_at' => '',
		];
		if (is_readable(self::UPDATE_PROGRESS_FILE)) {
			$decoded = json_decode((string)file_get_contents(self::UPDATE_PROGRESS_FILE), true);
			if (is_array($decoded)) {
				$state = strtolower(trim((string)($decoded['state'] ?? 'idle')));
				if (in_array($state, ['idle', 'queued', 'checking', 'installing', 'complete', 'failed'], true)) {
					$progress['state'] = $state;
				}
				$progress['message'] = mb_substr(trim((string)($decoded['message'] ?? '')), 0, 300);
				$progress['updated_at'] = trim((string)($decoded['updated_at'] ?? ''));
			}
		}
		if (is_file(self::UPDATE_REQUEST_FILE)) {
			$progress['state'] = 'queued';
			if ($progress['message'] === '') {
				$progress['message'] = _('Update queued. Waiting for the protected maintenance worker.');
			}
		}
		$progress['package'] = $this->getPackageUpdateStatus();
		return $progress;
	}

	private function writeManualUpdateProgress($state, $message)
	{
		if (!in_array($state, ['idle', 'queued', 'checking', 'installing', 'complete', 'failed'], true)) {
			return false;
		}
		$payload = json_encode([
			'state' => $state,
			'message' => mb_substr(trim((string)$message), 0, 300),
			'updated_at' => gmdate('c'),
		], JSON_UNESCAPED_SLASHES);
		if ($payload === false) {
			return false;
		}
		$temporary = self::UPDATE_PROGRESS_FILE . '.tmp.' . bin2hex(random_bytes(4));
		if (@file_put_contents($temporary, $payload . "\n", LOCK_EX) === false) {
			return false;
		}
		$this->setPrivateOwnership($temporary);
		if (!@rename($temporary, self::UPDATE_PROGRESS_FILE)) {
			@unlink($temporary);
			return false;
		}
		$this->setPrivateOwnership(self::UPDATE_PROGRESS_FILE);
		return true;
	}

	public function requestCompleteUninstall()
	{
		$result = $this->queueMaintenanceAction(
			self::UNINSTALL_REQUEST_FILE,
			_('Complete uninstall was queued. The module, runtime files, APIs, logs, and central configuration will be removed within one minute.')
		);
		if (!empty($result['success'])) {
			$this->writeMaintenanceProgress('uninstall', 'queued', _('Complete uninstall queued. Waiting for the protected maintenance worker.'));
		}
		return $result;
	}

	public function getMaintenanceProgress()
	{
		$progress = [
			'action' => '',
			'state' => 'idle',
			'message' => '',
			'updated_at' => '',
		];
		if (is_readable(self::MAINTENANCE_PROGRESS_FILE)) {
			$decoded = json_decode((string)file_get_contents(self::MAINTENANCE_PROGRESS_FILE), true);
			if (is_array($decoded)) {
				$action = strtolower(trim((string)($decoded['action'] ?? '')));
				$state = strtolower(trim((string)($decoded['state'] ?? 'idle')));
				if (in_array($action, ['repair', 'uninstall', 'config'], true)) {
					$progress['action'] = $action;
				}
				if (in_array($state, ['idle', 'queued', 'running', 'complete', 'failed'], true)) {
					$progress['state'] = $state;
				}
				$progress['message'] = mb_substr(trim((string)($decoded['message'] ?? '')), 0, 300);
				$progress['updated_at'] = trim((string)($decoded['updated_at'] ?? ''));
			}
		}
		if (is_file(self::REPAIR_REQUEST_FILE)) {
			$progress = array_replace($progress, [
				'action' => 'repair',
				'state' => 'queued',
				'message' => _('Repair queued. Waiting for the protected maintenance worker.'),
			]);
		} elseif (is_file(self::UNINSTALL_REQUEST_FILE)) {
			$progress = array_replace($progress, [
				'action' => 'uninstall',
				'state' => 'queued',
				'message' => _('Complete uninstall queued. Waiting for the protected maintenance worker.'),
			]);
		}
		return $progress;
	}

	private function writeMaintenanceProgress($action, $state, $message)
	{
		if (!in_array($action, ['repair', 'uninstall', 'config'], true)
			|| !in_array($state, ['idle', 'queued', 'running', 'complete', 'failed'], true)) {
			return false;
		}
		$directory = dirname(self::MAINTENANCE_PROGRESS_FILE);
		if (!is_dir($directory) || !is_writable($directory)) {
			return false;
		}
		$payload = json_encode([
			'action' => $action,
			'state' => $state,
			'message' => mb_substr(trim((string)$message), 0, 300),
			'updated_at' => gmdate('c'),
		], JSON_UNESCAPED_SLASHES);
		if ($payload === false) {
			return false;
		}
		$temporary = self::MAINTENANCE_PROGRESS_FILE . '.tmp.' . bin2hex(random_bytes(4));
		if (@file_put_contents($temporary, $payload . "\n", LOCK_EX) === false) {
			return false;
		}
		$this->setPrivateOwnership($temporary);
		if (!@rename($temporary, self::MAINTENANCE_PROGRESS_FILE)) {
			@unlink($temporary);
			return false;
		}
		$this->setPrivateOwnership(self::MAINTENANCE_PROGRESS_FILE);
		return true;
	}

	private function queueMaintenanceAction($path, $successMessage)
	{
		$this->ensurePluginDataDir();
		$temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
		if (@file_put_contents($temporary, gmdate('c') . "\n", LOCK_EX) === false) {
			return ['success' => false, 'message' => _('The maintenance action could not be queued.'), 'errors' => [_('Unable to write the protected request marker.')]];
		}
		$this->setPrivateOwnership($temporary);
		if (!@rename($temporary, $path)) {
			@unlink($temporary);
			return ['success' => false, 'message' => _('The maintenance action could not be queued.'), 'errors' => [_('Unable to activate the protected request marker.')]];
		}
		$this->setPrivateOwnership($path);
		return ['success' => true, 'message' => $successMessage, 'errors' => []];
	}

	public function getDiagnosticsSummary()
	{
		$settings = $this->getActiveSettings();
		$checks = [];
		$checks[] = $this->diagnosticCheck(_('Central config'), is_readable(self::SETTINGS_JSON), self::SETTINGS_JSON);
		$checks[] = $this->diagnosticCheck(_('Central config loader'), is_executable('/usr/local/bin/sls_mass_notify/sls_config.py'), '/usr/local/bin/sls_mass_notify/sls_config.py');
		$checks[] = $this->diagnosticCheck(_('SIP NOTIFY sender'), is_executable(self::VISUAL_PUSH_SCRIPT), self::VISUAL_PUSH_SCRIPT);
		$checks[] = $this->diagnosticCheck(_('NWS poller'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh');
		$checks[] = $this->diagnosticCheck(_('Weather scheduler'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh');
		$checks[] = $this->diagnosticCheck(_('Announcement scheduler'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php');
		$checks[] = $this->diagnosticCheck(_('Xweather poller'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py');
		$checks[] = $this->diagnosticCheck(_('Branded email sender'), is_executable('/usr/local/bin/sls_mass_notify/sls_branded_email.py'), '/usr/local/bin/sls_mass_notify/sls_branded_email.py');
		$checks[] = $this->diagnosticCheck(_('Branded Discord sender'), is_executable('/usr/local/bin/sls_mass_notify/sls_branded_discord.py'), '/usr/local/bin/sls_mass_notify/sls_branded_discord.py');
		$checks[] = $this->diagnosticCheck(_('Notification destination dispatcher'), is_executable('/usr/local/bin/sls_mass_notify/sls_notification_destinations.py'), '/usr/local/bin/sls_mass_notify/sls_notification_destinations.py');
		$checks[] = $this->diagnosticCheck(_('System/error email notifier'), is_executable('/usr/local/bin/sls_mass_notify/sls_system_notifications.py'), '/usr/local/bin/sls_mass_notify/sls_system_notifications.py');
		$checks[] = $this->diagnosticCheck(_('Weather zone status helper'), is_executable('/usr/local/bin/sls_mass_notify/sls_nws_status.py'), '/usr/local/bin/sls_mass_notify/sls_nws_status.py');
		$checks[] = $this->diagnosticCheck(_('Maintenance worker'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh');
		$checks[] = $this->diagnosticCheck(_('Piper binary'), is_executable($settings['piper_bin'] ?? self::PIPER_BIN), (string)($settings['piper_bin'] ?? self::PIPER_BIN));
		$checks[] = $this->diagnosticCheck(_('Executable runtime ownership'), @fileowner(self::RUNTIME_DIR) === 0 && @fileowner(self::PIPER_BIN) === 0, 'root:root');
		$checks[] = $this->diagnosticCheck(_('Piper voice'), is_readable($settings['piper_voice'] ?? self::PIPER_VOICE), (string)($settings['piper_voice'] ?? self::PIPER_VOICE));
		$checks[] = $this->diagnosticCheck(_('Notification log'), is_writable(self::EVENTS_LOG) || (is_writable(dirname(self::EVENTS_LOG)) && !file_exists(self::EVENTS_LOG)), self::EVENTS_LOG);
		$checks[] = $this->diagnosticCheck(_('Desktop journal'), is_writable(self::PLUGIN_DATA_DIR . '/sipnotify') || is_writable(self::PLUGIN_DATA_DIR), self::PLUGIN_DATA_DIR . '/sipnotify/sipnotify_events.jsonl');
		$mailConfigured = $this->hasConfiguredNotificationEmailRecipients($settings);
		$checks[] = $this->diagnosticCheck(_('Local email transport'), !$mailConfigured || is_executable('/usr/sbin/sendmail'), $mailConfigured ? '/usr/sbin/sendmail' : _('Not required until an email recipient is configured'));
		$controlEnabled = !empty($settings['control_api']['enabled']);
		$controlKeyValid = preg_match('/^[A-Za-z0-9_-]{24,128}$/', (string)($settings['control_api']['api_key'] ?? '')) === 1;
		$checks[] = $this->diagnosticCheck(_('Control API'), !$controlEnabled || $controlKeyValid, $controlEnabled ? _('Enabled') : _('Disabled (optional)'));

		return [
			'checks' => $checks,
			'endpoints' => $this->getDetectedEndpointFormats(),
			'desktop_clients' => $this->getDesktopClientDiagnostics($settings),
			'control_api_audit' => $this->getControlApiAuditSummary(),
		];
	}

	private function diagnosticCheck($label, $ok, $detail = '')
	{
		return [
			'label' => (string)$label,
			'ok' => (bool)$ok,
			'state' => $ok ? 'ok' : 'warning',
			'detail' => (string)$detail,
		];
	}

	private function getDetectedEndpointFormats()
	{
		if (!is_executable(self::VISUAL_PUSH_SCRIPT)) {
			return [];
		}
		$output = [];
		$code = 1;
		exec('/usr/bin/python3 ' . escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --list-endpoints-json 2>/dev/null', $output, $code);
		if ($code !== 0 || empty($output)) {
			return [];
		}
		$decoded = json_decode(implode('', $output), true);
		if (!is_array($decoded)) {
			return [];
		}
		$endpoints = [];
		foreach ($decoded as $extension => $info) {
			if (!is_array($info)) {
				continue;
			}
			$format = (string)($info['format'] ?? 'unknown');
			$formats = array_values(array_filter(array_map('strval', (array)($info['formats'] ?? [$format]))));
			$endpoints[] = [
				'extension' => (string)$extension,
				'format' => $format,
				'formats' => $formats,
				'user_agent' => (string)($info['user_agent'] ?? ''),
				'contacts' => (int)($info['contacts'] ?? 1),
				'override' => !empty($info['override']),
				'unknown' => empty(array_diff($formats, ['unknown'])),
			];
		}
		usort($endpoints, static function ($a, $b) {
			return strnatcasecmp((string)$a['extension'], (string)$b['extension']);
		});
		return $endpoints;
	}

	private function getDesktopClientDiagnostics(array $settings)
	{
		$lastSeen = $this->loadJsonFile(self::PLUGIN_DATA_DIR . '/desktop-last-seen.json');
		$clients = [];
		foreach ($this->getDesktopClients($settings, false) as $client) {
			$username = (string)($client['username'] ?? '');
			$seen = is_array($lastSeen[$username] ?? null) ? $lastSeen[$username] : [];
			$seenAt = (string)($seen['seen_at'] ?? '');
			$age = $seenAt !== '' ? time() - (strtotime($seenAt) ?: 0) : null;
			$state = $age === null ? 'never' : ($age <= 12 * 3600 ? 'recent' : ($age <= 24 * 3600 ? 'stale' : 'old'));
			$clients[] = [
				'name' => (string)($client['name'] ?? 'Desktop App'),
				'client_id' => (string)($client['client_id'] ?? ''),
				'username' => $username,
				'enabled' => !empty($client['enabled']),
				'last_seen_at' => $seenAt,
				'last_seen_ip' => (string)($seen['ip'] ?? ''),
				'state' => $state,
			];
		}
		return $clients;
	}

	private function getControlApiAuditSummary()
	{
		if (!is_readable(self::CONTROL_API_AUDIT_LOG)) {
			return [];
		}
		$lines = array_slice(file(self::CONTROL_API_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -20);
		$events = [];
		foreach ($lines as $line) {
			$decoded = json_decode($line, true);
			if (is_array($decoded)) {
				$loopback = in_array((string)($decoded['ip'] ?? ''), ['127.0.0.1', '::1'], true);
				if ($loopback && in_array((string)($decoded['action'] ?? ''), ['disabled', 'unauthorized', 'get_config', 'get_status'], true)) {
					// Local health/validation probes are not remote Control API usage.
					continue;
				}
				if ($loopback) {
					$decoded['ip'] = _('PBX internal');
				}
				$events[] = $decoded;
			}
		}
		return array_reverse($events);
	}

	private function loadJsonFile($path)
	{
		if (!is_readable($path)) {
			return [];
		}
		$decoded = json_decode((string)file_get_contents($path), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function migrateLegacyTestStatus()
	{
		$status = $this->loadStatusData();
		$faultMessage = (string)($status['last_fault_message'] ?? '');
		$legacyNwsTestFault = in_array(trim($faultMessage), [
			'No NWS alert recipient extensions configured',
			'Unable to queue test calls to configured NWS recipients',
			'Piper TTS test audio was not generated',
			'Piper TTS test audio sequence was not generated',
			'Manual NWS test SIP NOTIFY submission to Asterisk failed',
			'One or more manual NWS test calls did not complete',
		], true);
		if ($legacyNwsTestFault) {
			$legacyTimestamp = (string)($status['last_fault_at'] ?? '');
			$this->updateStatusData([
				'last_test_at' => $legacyTimestamp !== '' ? $legacyTimestamp : gmdate('c'),
				'last_test_status' => 'fault',
				'last_test_stage' => (string)($status['last_fault_stage'] ?? ''),
				'last_test_message' => $this->sanitizeScheduleText($faultMessage, 240, true),
				'last_fault_at' => '',
				'last_fault_stage' => '',
				'last_fault_message' => '',
				'last_fault_event' => '',
				'last_fault_alert_id' => '',
				'fault_email_sent_at' => '',
			]);
			$faultState = self::PLUGIN_DATA_DIR . '/fault.state';
			$expectedFaultKey = (string)($status['last_fault_stage'] ?? '') . '|' . $faultMessage;
			if (is_file($faultState) && trim((string)@file_get_contents($faultState)) === $expectedFaultKey) {
				@unlink($faultState);
			}
			$faultMessage = '';
		}
		$deliveryMessage = (string)($status['last_xweather_delivery_message'] ?? '');
		$legacyFault = preg_match('/sls_xweather_[A-Za-z0-9_-]+\.call\s*:\s*Expired/i', $faultMessage) === 1;
		$legacyDelivery = preg_match('/sls_xweather_[A-Za-z0-9_-]+\.call\s*:\s*Expired/i', $deliveryMessage) === 1;
		if (!$legacyFault && !$legacyDelivery) {
			return;
		}
		$message = $legacyDelivery ? $deliveryMessage : $faultMessage;
		$legacyTimestamp = $legacyDelivery
			? (string)($status['last_xweather_delivery_at'] ?? '')
			: (string)($status['last_fault_at'] ?? '');
		$this->updateStatusData([
			'last_xweather_test_at' => $legacyTimestamp !== '' ? $legacyTimestamp : gmdate('c'),
			'last_xweather_test_status' => 'fault',
			'last_xweather_test_message' => $this->sanitizeScheduleText($message, 240, true),
			'last_xweather_delivery_at' => $legacyDelivery ? '' : (string)($status['last_xweather_delivery_at'] ?? ''),
			'last_xweather_delivery_status' => $legacyDelivery ? '' : (string)($status['last_xweather_delivery_status'] ?? ''),
			'last_xweather_delivery_message' => $legacyDelivery ? '' : $deliveryMessage,
			'last_fault_at' => $legacyFault ? '' : (string)($status['last_fault_at'] ?? ''),
			'last_fault_stage' => $legacyFault ? '' : (string)($status['last_fault_stage'] ?? ''),
			'last_fault_message' => $legacyFault ? '' : $faultMessage,
			'last_fault_event' => $legacyFault ? '' : (string)($status['last_fault_event'] ?? ''),
			'last_fault_alert_id' => $legacyFault ? '' : (string)($status['last_fault_alert_id'] ?? ''),
		]);
	}

	private function settingsQuietHoursActive(array $settings)
	{
		if (($settings['quiet_hours_enabled'] ?? '0') !== '1') {
			return false;
		}

		$now = $this->hourToMinutes((new \DateTimeImmutable('now', $this->getPbxDateTimeZone()))->format('H:i'));
		$start = $this->hourToMinutes((string)($settings['quiet_hours_start'] ?? '21:00'));
		$end = $this->hourToMinutes((string)($settings['quiet_hours_end'] ?? '06:00'));
		if ($start === $end) {
			return false;
		}
		if ($start < $end) {
			return $now >= $start && $now < $end;
		}
		return $now >= $start || $now < $end;
	}

	private function getPbxDateTimeZone()
	{
		$candidates = [];
		if (is_readable('/etc/timezone')) {
			$candidates[] = trim((string)file_get_contents('/etc/timezone'));
		}

		$localtime = @readlink('/etc/localtime');
		if (is_string($localtime) && preg_match('#/usr/share/zoneinfo/(.+)$#', $localtime, $matches)) {
			$candidates[] = $matches[1];
		}

		$candidates[] = date_default_timezone_get();
		foreach ($candidates as $timezone) {
			$timezone = trim((string)$timezone);
			if ($timezone === '') {
				continue;
			}
			try {
				return new \DateTimeZone($timezone);
			} catch (\Throwable $e) {
				continue;
			}
		}

		return new \DateTimeZone('UTC');
	}

	private function getFreePbxConfiguredTimeZoneName()
	{
		try {
			$value = trim((string)$this->FreePBX->Config()->get('PHPTIMEZONE'));
			if ($value !== '') {
				new \DateTimeZone($value);
				return $value;
			}
		} catch (\Throwable $e) {
			// The operating-system timezone remains authoritative when FreePBX does
			// not expose a valid configured timezone.
		}
		return '';
	}

	private function hourToMinutes($value)
	{
		$value = trim((string)$value);
		if (!preg_match('/^([0-2][0-9]):([0-5][0-9])$/', $value, $matches)) {
			return 0;
		}
		return ((int)$matches[1] * 60) + (int)$matches[2];
	}

	public function getSipNotifyTargets()
	{
		if (is_array($this->sipNotifyTargetsCache)) {
			return $this->sipNotifyTargetsCache;
		}
		$registeredExtensions = $this->getRegisteredPjsipExtensions();
		$nameMap = $this->getExtensionNameMap();
		$targets = [];

		foreach ($registeredExtensions as $extension) {
			if ($extension === '') {
				continue;
			}
			$targets[$extension] = [
				'extension' => $extension,
				'name' => $nameMap[$extension] ?? '',
				'registered' => true,
			];
		}

		ksort($targets, SORT_NATURAL);
		$this->sipNotifyTargetsCache = array_values($targets);
		return $this->sipNotifyTargetsCache;
	}

	/** Return configured PJSIP device numbers without contacting Asterisk/AMI. */
	public function getConfiguredPjsipExtensionNumbers()
	{
		return array_values(array_keys($this->getExtensionNameMap()));
	}

	public function getAllPjsipExtensions()
	{
		if (is_array($this->allPjsipExtensionsCache)) {
			return $this->allPjsipExtensionsCache;
		}
		$nameMap = $this->getExtensionNameMap();
		$registered = array_fill_keys($this->getRegisteredPjsipExtensions(), true);
		$targets = [];
		foreach ($nameMap as $extension => $name) {
			$targets[$extension] = [
				'extension' => $extension,
				'name' => $name,
				'registered' => isset($registered[$extension]),
			];
		}
		ksort($targets, SORT_NATURAL);
		$this->allPjsipExtensionsCache = array_values($targets);
		return $this->allPjsipExtensionsCache;
	}

	public function dashboardService()
	{
		$status = [
			'title' => _('Mass Notifications Module'),
			'order' => 4,
		];
		try {
			$settings = $this->getActiveSettings();
		} catch (\Throwable $e) {
			return [array_merge($status, \FreePBX::Dashboard()->genStatusIcon('error', _('Central Mass Notifications config is invalid or unreadable.')))];
		}

		$critical = [];
		$warnings = [];
		$statusData = $this->loadStatusData();
		$now = time();
		$nwsEnabled = ($settings['enabled'] ?? '0') === '1';
		$xweatherEnabled = !empty($settings['xweather']['enabled']);
		$enabledSchedules = array_values(array_filter((array)($settings['scheduled_announcements'] ?? []), static function ($schedule) {
			return is_array($schedule) && !empty($schedule['enabled']);
		}));
		if (!$this->isSetupComplete($settings)) {
			$critical[] = _('Setup wizard is not complete');
		}
		$installFailure = $this->loadJsonFile(self::INSTALL_FAILURE_JSON);
		if ((int)($installFailure['version'] ?? 0) === 1 && trim((string)($installFailure['failed_at'] ?? '')) !== '') {
			$installStage = $this->sanitizeScheduleText($installFailure['stage'] ?? _('unknown stage'), 80, true);
			$installSolution = $this->sanitizeScheduleText($installFailure['solution'] ?? '', 400, true);
			$critical[] = sprintf(
				_('The last installation or repair failed during %s. Possible solution: %s'),
				$installStage !== '' ? $installStage : _('an unknown stage'),
				$installSolution !== '' ? $installSolution : _('review /tmp/slsmassnotifyserver-install.log and rerun Repair Installation')
			);
		}

		if (!is_executable(self::TEST_SCRIPT)) {
			$critical[] = _('Test alert script is missing or not executable');
		}
		if (!empty($enabledSchedules)) {
			if (!is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php')) {
				$critical[] = _('Scheduled-announcement worker is missing or not executable');
			}
			$scheduleCronLines = [];
			$canonicalScheduleCron = '* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php';
			foreach ($this->FreePBX->Cron()->getAll() as $cronLine) {
				if (strpos((string)$cronLine, 'sls_mass_notify_schedule_worker.php') !== false) {
					$scheduleCronLines[] = trim((string)$cronLine);
				}
			}
			if (count($scheduleCronLines) === 0) {
				$critical[] = _('Scheduled-announcement cron job is missing');
			} elseif (count($scheduleCronLines) !== 1 || $scheduleCronLines[0] !== $canonicalScheduleCron) {
				$critical[] = _('Scheduled-announcement cron job is duplicated or does not match the protected one-minute schedule');
			}

			$pbxTimeZone = $this->getPbxDateTimeZone()->getName();
			$freePbxTimeZone = $this->getFreePbxConfiguredTimeZoneName();
			if ($freePbxTimeZone !== '' && $freePbxTimeZone !== $pbxTimeZone) {
				$warnings[] = sprintf(_('FreePBX timezone %s differs from the PBX operating-system timezone %s used by Scheduling'), $freePbxTimeZone, $pbxTimeZone);
			}
			$scheduleJournal = $this->loadScheduleExecutionStore(false);
			if (strtolower((string)($scheduleJournal['worker']['status'] ?? '')) === 'fault') {
				$critical[] = _('Scheduled-announcement execution journal is unreadable or invalid; delivery is stopped to prevent duplicate pages');
			}
		}

		if (!is_dir(self::SOUNDS_DIR)) {
			$critical[] = _('Custom alert sounds directory is missing');
		}

		if (!is_executable($settings['piper_bin'] ?? self::PIPER_BIN)) {
			$critical[] = _('Piper TTS binary is missing or not executable');
		}
		if ($this->hasConfiguredNotificationEmailRecipients($settings) && !is_executable('/usr/sbin/sendmail')) {
			$critical[] = _('Notification email recipients are configured, but the local sendmail transport is unavailable');
		}

		foreach ([$settings['nws_piper_voice'] ?? '', $settings['announcement_piper_voice'] ?? ''] as $voice) {
			if ($voice === '' || !is_readable($voice)) {
				$critical[] = _('A configured Piper TTS voice model is missing or unreadable');
			}
		}

		$openingTone = $this->normalizeToneName((string)($settings['opening_tone'] ?? self::DEFAULT_ANNOUNCEMENT_OPENING_TONE));
		$closingTone = $this->normalizeToneName((string)($settings['closing_tone'] ?? self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE));
		if ($openingTone !== '' && !is_readable(self::TONES_DIR . '/' . $openingTone . '.wav')) {
			$critical[] = _('Opening tone is missing or not readable');
		}
		if ($closingTone !== '' && !is_readable(self::TONES_DIR . '/' . $closingTone . '.wav')) {
			$critical[] = _('Regular announcement closing tone is missing or not readable');
		}
		$nwsOpeningTone = $this->normalizeToneName((string)($settings['nws_opening_tone'] ?? self::DEFAULT_NWS_OPENING_TONE));
		$nwsClosingTone = $this->normalizeToneName((string)($settings['nws_closing_tone'] ?? ''));
		if ($nwsOpeningTone !== '' && !is_readable(self::TONES_DIR . '/' . $nwsOpeningTone . '.wav')) {
			$critical[] = _('Weather Alert opening tone is missing or not readable');
		}
		if ($nwsClosingTone !== '' && !is_readable(self::TONES_DIR . '/' . $nwsClosingTone . '.wav')) {
			$critical[] = _('Weather Alert closing tone is missing or not readable');
		}

		if (!is_writable(self::PLUGIN_DATA_DIR . '/sipnotify')) {
			$critical[] = _('Desktop notification journal directory is not writable');
		}
		if (!is_readable(self::SETTINGS_JSON)) {
			$critical[] = _('Protected central configuration is not readable');
		} else {
			$configMode = @fileperms(self::SETTINGS_JSON);
			$configOwner = @fileowner(self::SETTINGS_JSON);
			$asteriskUser = function_exists('posix_getpwnam') ? @posix_getpwnam('asterisk') : false;
			if ($configMode !== false && (($configMode & 0007) !== 0 || ($configMode & 0020) !== 0)) {
				$critical[] = _('Protected central configuration permissions are too broad');
			}
			if (is_array($asteriskUser) && $configOwner !== false && (int)$configOwner !== (int)$asteriskUser['uid']) {
				$warnings[] = _('Protected central configuration is not owned by Asterisk');
			}
		}
		$amiSettings = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/', (string)($amiSettings['password'] ?? ''))) {
			$critical[] = _('Protected central configuration contains an invalid AMI credential; paging contact discovery cannot authenticate');
		}
		if (!is_readable('/var/www/html/api/sipnotify/index.php')) {
			$critical[] = _('Desktop live-login API route is missing');
		}
		if (!is_readable('/etc/apache2/conf-enabled/sls-mass-notify.conf')) {
			$critical[] = _('Mass Notify Apache API integration is not enabled');
		}
		if (is_readable(self::PENDING_SETTINGS_JSON)) {
			$warnings[] = _('Mass Notifications has saved changes waiting to be applied');
		}
		$backupHealth = $this->getFreePbxBackupHealth();
		if (($backupHealth['state'] ?? 'warning') !== 'ok' && trim((string)($backupHealth['message'] ?? '')) !== '') {
			$warnings[] = trim((string)$backupHealth['message']);
		}

		if ($nwsEnabled) {
			$zoneGroups = (array)($settings['nws_zones'] ?? []);
			$enabledDesktopUsernames = [];
			foreach ($this->getDesktopClients($settings) as $desktopClient) {
				if (!empty($desktopClient['enabled'])) {
					$enabledDesktopUsernames[(string)$desktopClient['username']] = true;
				}
			}
			if (empty($zoneGroups)) {
				$warnings[] = _('Weather Alerts is enabled but no U.S. weather.gov zone group is configured');
			}
			foreach ($zoneGroups as $zoneGroup) {
				$zoneName = trim((string)($zoneGroup['name'] ?? $zoneGroup['zone'] ?? _('unnamed group')));
				if ($this->normalizeNwsZone((string)($zoneGroup['zone'] ?? '')) === '') {
					$warnings[] = sprintf(_('Weather group %s has an invalid weather.gov zone'), $zoneName);
				}
				$activeZoneDesktops = array_filter((array)($zoneGroup['desktop_clients'] ?? []), static function ($username) use ($enabledDesktopUsernames) {
					return isset($enabledDesktopUsernames[(string)$username]);
				});
				if (empty($zoneGroup['extensions']) && empty($activeZoneDesktops)) {
					$warnings[] = sprintf(_('Weather group %s has no phone or desktop recipients'), $zoneName);
				}
				if (count($activeZoneDesktops) !== count((array)($zoneGroup['desktop_clients'] ?? []))) {
					$warnings[] = sprintf(_('Weather group %s references a missing or disabled desktop client'), $zoneName);
				}
			}
			if (parse_url((string)($settings['nws_api_base_url'] ?? ''), PHP_URL_HOST) !== 'api.weather.gov') {
				$warnings[] = _('Weather Alerts API must use api.weather.gov');
			}
			if (!is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh')) {
				$critical[] = _('NWS polling script is missing or not executable');
			}
			$pollTimestamp = $this->parseTimestamp($statusData['last_poll_at'] ?? '');
			$pollState = strtolower(trim((string)($statusData['last_poll_status'] ?? '')));
			if ($pollTimestamp === null) {
				$warnings[] = _('NWS polling has not reported status yet');
			} elseif (($now - $pollTimestamp) > 600) {
				$warnings[] = _('NWS polling status is stale');
			} elseif ($pollState === 'fault') {
				$warnings[] = $this->normalizeStatusMessage($statusData['last_poll_message'] ?? '', _('NWS polling reported a fault'));
			}
		}
		if ($xweatherEnabled) {
			$xweather = $this->normalizeXweatherSettings($settings['xweather'] ?? [], $settings['nws_tts_volume'] ?? 25);
			if (trim((string)($xweather['client_id'] ?? '')) === '' || trim((string)($xweather['client_secret'] ?? '')) === '') {
				$warnings[] = _('Lightning Alerts is enabled but Xweather API credentials are incomplete');
			}
			$enabledLightningGroups = array_values(array_filter((array)($xweather['groups'] ?? []), static function ($group) {
				return is_array($group) && ($group['enabled'] ?? '0') === '1';
			}));
			if (empty($enabledLightningGroups)) {
				$warnings[] = _('Lightning Alerts is enabled but no trigger area is enabled');
			}
			$enabledDesktopUsernames = [];
			foreach ($this->getDesktopClients($settings) as $desktopClient) {
				if (!empty($desktopClient['enabled'])) {
					$enabledDesktopUsernames[(string)$desktopClient['username']] = true;
				}
			}
			$validZoneIds = array_column((array)($settings['nws_zones'] ?? []), 'id');
			foreach ($enabledLightningGroups as $group) {
				$groupName = trim((string)($group['name'] ?? '')) ?: _('unnamed area');
				if (trim((string)($group['location'] ?? '')) === '') {
					$warnings[] = sprintf(_('Lightning trigger area %s has no Xweather location'), $groupName);
				}
				$activeDesktops = array_filter((array)($group['desktop_clients'] ?? []), static function ($username) use ($enabledDesktopUsernames) {
					return isset($enabledDesktopUsernames[(string)$username]);
				});
				if (empty($group['extensions']) && empty($activeDesktops)) {
					$warnings[] = sprintf(_('Lightning trigger area %s has no phone or enabled desktop recipients'), $groupName);
				}
				if (count($activeDesktops) !== count((array)($group['desktop_clients'] ?? []))) {
					$warnings[] = sprintf(_('Lightning trigger area %s references a missing or disabled desktop client'), $groupName);
				}
				if (($xweather['adaptive_free_tier'] ?? '1') === '1'
					&& !in_array((string)($group['adaptive_nws_zone_id'] ?? ''), $validZoneIds, true)) {
					$warnings[] = sprintf(_('Lightning trigger area %s does not have a valid Weather Alert trigger zone'), $groupName);
				}
			}
			$queryInterval = (int)($xweather['query_interval_minutes'] ?? 5);
			if ($queryInterval < 1 || $queryInterval > 10) {
				$warnings[] = _('Lightning Alerts API query period must be between 1 and 10 minutes');
			}
			if (($xweather['adaptive_free_tier'] ?? '1') === '1' && !$nwsEnabled) {
				$warnings[] = _('Free-tier adaptive lightning polling is waiting because Weather Alerts is disabled');
			}
			foreach (['opening_tone', 'closing_tone'] as $toneKey) {
				$tone = (string)($xweather[$toneKey] ?? '');
				if ($tone === 'use_default') {
					$tone = (string)($settings[$toneKey] ?? '');
				}
				if ($tone !== '' && !is_readable(self::TONES_DIR . '/' . $this->normalizeToneName($tone) . '.wav')) {
					$warnings[] = sprintf(_('Lightning Alerts selected %s is missing'), str_replace('_', ' ', $toneKey));
				}
			}
			if (!is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py')) {
				$critical[] = _('Xweather lightning poller is missing or not executable');
			}
			$groupStatusMap = is_array($statusData['xweather_groups'] ?? null) ? $statusData['xweather_groups'] : [];
			if (!empty($groupStatusMap)) {
				foreach ($enabledLightningGroups as $group) {
					$groupId = (string)($group['id'] ?? '');
					$groupName = trim((string)($group['name'] ?? '')) ?: $groupId;
					$groupStatus = is_array($groupStatusMap[$groupId] ?? null) ? $groupStatusMap[$groupId] : [];
					$xweatherPollTimestamp = $this->parseTimestamp($groupStatus['last_xweather_poll_at'] ?? '');
					$pollState = strtolower(trim((string)($groupStatus['last_xweather_poll_status'] ?? '')));
					if ($xweatherPollTimestamp === null) {
						$warnings[] = sprintf(_('Lightning trigger area %s has not reported polling status yet'), $groupName);
					} elseif (($now - $xweatherPollTimestamp) > (($queryInterval * 60) + 120)) {
						$warnings[] = sprintf(_('Lightning trigger area %s polling status is stale'), $groupName);
					} elseif (in_array($pollState, ['fault', 'quota_guard'], true)) {
						$warnings[] = sprintf('%s: %s', $groupName, $this->normalizeStatusMessage($groupStatus['last_xweather_poll_message'] ?? '', _('Xweather polling needs attention')));
					}
					if (strtolower(trim((string)($groupStatus['last_xweather_delivery_status'] ?? ''))) === 'fault') {
						$warnings[] = sprintf('%s: %s', $groupName, $this->normalizeStatusMessage($groupStatus['last_xweather_delivery_message'] ?? '', _('Lightning alert delivery reported a fault')));
					}
				}
			} else {
				$xweatherPollTimestamp = $this->parseTimestamp($statusData['last_xweather_poll_at'] ?? '');
				if ($xweatherPollTimestamp === null) {
					$warnings[] = _('Xweather lightning polling has not reported status yet');
				} elseif (($now - $xweatherPollTimestamp) > (($queryInterval * 60) + 120)) {
					$warnings[] = _('Xweather lightning polling status is stale');
				} elseif (in_array(strtolower(trim((string)($statusData['last_xweather_poll_status'] ?? ''))), ['fault', 'quota_guard'], true)) {
					$warnings[] = $this->normalizeStatusMessage($statusData['last_xweather_poll_message'] ?? '', _('Xweather lightning polling needs attention'));
				}
				if (strtolower(trim((string)($statusData['last_xweather_delivery_status'] ?? ''))) === 'fault') {
					$warnings[] = $this->normalizeStatusMessage($statusData['last_xweather_delivery_message'] ?? '', _('Lightning alert delivery reported a fault'));
				}
			}
			$usageSummary = $this->buildXweatherApiUsageSummary($statusData, $settings, $now);
			$rateLimit = (int)($usageSummary['limit'] ?? 0);
			$rateRemaining = (int)($usageSummary['remaining'] ?? 0);
			if (!empty($usageSummary['snapshot_current']) && $rateLimit > 0 && $rateRemaining <= max(10, (int)floor($rateLimit * 0.1))) {
				$warnings[] = sprintf(_('Xweather API quota is low: %d of %d usage tokens remain'), $rateRemaining, $rateLimit);
			}
			$queryCost = max(0, (int)($statusData['xweather_last_query_cost_tokens'] ?? 0));
			$resetAt = strtotime((string)($statusData['xweather_rate_reset_period'] ?? '')) ?: 0;
			$intervalMinutes = max(1, min(10, (int)($xweather['query_interval_minutes'] ?? 5)));
			$estimatedDailyCost = $queryCost * (int)ceil(1440 / $intervalMinutes);
			$daysUntilReset = $resetAt > $now ? (($resetAt - $now) / 86400) : 0;
			$estimatedDailyCost *= max(1, count($enabledLightningGroups));
			if (!empty($usageSummary['snapshot_current']) && ($xweather['adaptive_free_tier'] ?? '1') !== '1' && $estimatedDailyCost > 0 && $daysUntilReset > 0 && $rateRemaining < ($estimatedDailyCost * $daysUntilReset)) {
				$warnings[] = sprintf(_('Xweather quota may not last to the account reset at the current %d-minute query period'), $intervalMinutes);
			}
		}
		if (strtolower(trim((string)($statusData['last_delivery_status'] ?? ''))) === 'fault') {
			$warnings[] = $this->normalizeStatusMessage($statusData['last_delivery_message'] ?? '', _('Mass Notify delivery reported a fault'));
		}
		if (!empty($enabledSchedules)) {
			$warnings = array_merge($warnings, $this->getScheduledAnnouncementHealthWarnings($settings));
			$scheduleWorkerAt = $this->parseTimestamp($statusData['last_schedule_worker_at'] ?? '');
			$scheduleWorkerStatus = strtolower(trim((string)($statusData['last_schedule_worker_status'] ?? '')));
			if ($scheduleWorkerAt === null) {
				$warnings[] = _('Scheduled announcements are enabled, but the scheduler has not reported a run yet');
			} elseif (($now - $scheduleWorkerAt) > 180) {
				$warnings[] = _('Scheduled-announcement worker status is stale');
			} elseif ($scheduleWorkerStatus === 'fault') {
				$warnings[] = $this->normalizeStatusMessage($statusData['last_schedule_worker_message'] ?? '', _('Scheduled-announcement worker reported a fault'));
			}
			$attentionStates = array_filter($this->getScheduleExecutionState(), static function ($record) {
				return in_array(strtolower((string)($record['state'] ?? '')), ['failed', 'missed', 'uncertain'], true);
			});
			if (!empty($attentionStates)) {
				$warnings[] = sprintf(_('%d scheduled announcement(s) need review'), count($attentionStates));
			}
		}

		if (!empty($statusData['last_fault_at'])) {
			$warnings[] = $this->buildFaultMessage($statusData);
		}
		$updateStatus = $this->getPackageUpdateStatus();
		if (($updateStatus['state'] ?? '') === 'update') {
			$warnings[] = (string)($updateStatus['label'] ?? _('Update available'));
		}

		$critical = array_values(array_unique(array_filter(array_map('trim', $critical))));
		$warnings = array_values(array_unique(array_filter(array_map('trim', $warnings))));
		if (!empty($critical)) {
			$status = array_merge($status, \FreePBX::Dashboard()->genStatusIcon('error', implode(' | ', $critical)));
		} elseif (!empty($warnings)) {
			$status = array_merge($status, \FreePBX::Dashboard()->genStatusIcon('warning', implode(' | ', $warnings)));
		} else {
			$okMessage = $nwsEnabled ? _('Mass Notifications services and Weather Alert polling look healthy') : _('Mass Notifications services look healthy; Weather Alerts are disabled');
			$deliveryState = strtolower(trim((string)($statusData['last_delivery_status'] ?? '')));
			if ($deliveryState === 'queued' && !empty($statusData['last_delivery_event'])) {
				$okMessage = sprintf(
					_('Healthy. Last Asterisk submission queued: %s using %s'),
					(string)$statusData['last_delivery_event'],
					(string)($statusData['last_delivery_audio'] ?? _('unknown audio'))
				);
			}
			$status = array_merge($status, \FreePBX::Dashboard()->genStatusIcon('ok', $okMessage));
		}

		return [$status];
	}

	public function getEvents($limit = self::DEFAULT_LIMIT, $type = '', $date = '')
	{
		$limit = $this->sanitizeLimit($limit);
		$type = $this->sanitizeType($type);
		$date = $this->sanitizeLogDate($date);
		$this->pruneEventLog();

		if (!is_readable(self::EVENTS_LOG)) {
			return [];
		}

		$buffer = [];
		$file = new \SplFileObject(self::EVENTS_LOG, 'r');

		while (!$file->eof()) {
			$line = trim((string)$file->fgets());
			if ($line === '') {
				continue;
			}

			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				continue;
			}

			$event = $this->normalizeEvent($decoded);
			if ($type !== '' && $event['type'] !== $type) {
				continue;
			}
			if ($date !== '' && substr((string)$event['display_time'], 0, 10) !== $date) {
				continue;
			}

			$buffer[] = $event;
			if (count($buffer) > $limit) {
				array_shift($buffer);
			}
		}

		return array_reverse($buffer);
	}

	public function getEventById($id)
	{
		$id = trim((string)$id);
		if ($id === '' || !is_readable(self::EVENTS_LOG)) {
			return null;
		}

		$file = new \SplFileObject(self::EVENTS_LOG, 'r');
		while (!$file->eof()) {
			$line = trim((string)$file->fgets());
			if ($line === '') {
				continue;
			}

			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				continue;
			}

			$event = $this->normalizeEvent($decoded);
			if ($event['event_id'] === $id) {
				return $event;
			}
		}

		return null;
	}

	public function getAvailableTones()
	{
		$tones = [];
		foreach (glob(self::TONES_DIR . '/*.wav') ?: [] as $path) {
			$name = basename($path, '.wav');
			if ($this->normalizeToneName($name) === $name) {
				$tones[] = $name;
			}
		}
		sort($tones, SORT_NATURAL | SORT_FLAG_CASE);
		return $tones;
	}

	public function getAvailableSystemSounds()
	{
		$root = realpath('/var/lib/asterisk/sounds');
		if ($root === false) {
			return [];
		}
		$root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$sounds = [];
		$seen = [];
		$customDirectories = array_merge(glob($root . 'custom', GLOB_ONLYDIR) ?: [], glob($root . '*/custom', GLOB_ONLYDIR) ?: []);
		foreach ($customDirectories as $customDirectory) {
			foreach (new \DirectoryIterator($customDirectory) as $file) {
				if (count($sounds) >= 500 || $file->isDot() || !$file->isFile() || !$file->isReadable()) {
					continue;
				}
				$relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)));
				if (!preg_match('#^(?:[A-Za-z0-9_-]+/)?custom/[A-Za-z0-9_. -]+\.(wav|ulaw|gsm|sln|sln16)$#i', $relative)) {
					continue;
				}
				$label = preg_replace('/\.(wav|ulaw|gsm|sln|sln16)$/i', '', $relative);
				$key = strtolower((string)$label);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$sounds[] = ['value' => 'system:' . $relative, 'label' => $label];
			}
		}
		usort($sounds, static function ($left, $right) {
			return strnatcasecmp((string)$left['label'], (string)$right['label']);
		});
		return $sounds;
	}

	private function importSystemSoundAsTone($selection, $prefix, array &$errors)
	{
		$relative = substr((string)$selection, strlen('system:'));
		if (!preg_match('#^(?:[A-Za-z0-9_-]+/)?custom/[A-Za-z0-9_. -]+\.(wav|ulaw|gsm|sln|sln16)$#i', $relative)) {
			$errors[] = sprintf(_('The selected %s system recording is invalid.'), $prefix);
			return '';
		}
		$root = realpath('/var/lib/asterisk/sounds');
		$source = realpath('/var/lib/asterisk/sounds/' . $relative);
		if ($root === false || $source === false || strpos($source, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0 || !is_readable($source)) {
			$errors[] = sprintf(_('The selected %s system recording is not readable.'), $prefix);
			return '';
		}
		if ((int)@filesize($source) <= 0 || (int)@filesize($source) > 20 * 1024 * 1024 || !is_executable('/usr/bin/sox')) {
			$errors[] = sprintf(_('The selected %s system recording cannot be converted.'), $prefix);
			return '';
		}
		$this->ensurePluginDataDir();
		$name = $this->normalizeToneName($prefix . '_system_' . pathinfo($source, PATHINFO_FILENAME) . '_' . substr(hash('sha256', $relative), 0, 8));
		$target = self::TONES_DIR . '/' . $name . '.wav';
		$tmp = $target . '.tmp.' . bin2hex(random_bytes(4)) . '.wav';
		$command = '/usr/bin/timeout 30 /usr/bin/sox ' . escapeshellarg($source)
			. ' -r 8000 -c 1 -b 16 ' . escapeshellarg($tmp) . ' 2>&1';
		exec($command, $output, $exitCode);
		if ($exitCode !== 0 || !is_file($tmp) || (int)@filesize($tmp) < 44 || !@rename($tmp, $target)) {
			@unlink($tmp);
			$errors[] = sprintf(_('Unable to import the selected %s system recording.'), $prefix);
			return '';
		}
		@chmod($target, 0644);
		@chown($target, 'asterisk');
		@chgrp($target, 'asterisk');
		return $name;
	}

	public function getAvailablePiperVoices()
	{
		$voices = [];
		$seen = [];
		foreach (glob(self::PIPER_VOICE_DIR . '/*.onnx') ?: [] as $path) {
			if (!is_readable($path)) {
				continue;
			}
			$name = basename($path, '.onnx');
			$voices[] = [
				'name' => $name,
				'path' => $path,
				'available' => true,
			];
			$seen[$path] = true;
		}
		foreach ($this->getPiperVoiceDownloads() as $file => $url) {
			if (substr($file, -5) !== '.onnx') {
				continue;
			}
			$path = self::PIPER_VOICE_DIR . '/' . $file;
			if (isset($seen[$path])) {
				continue;
			}
			$voices[] = [
				'name' => basename($file, '.onnx') . (is_readable($path) ? '' : ' (download pending)'),
				'path' => $path,
				'available' => is_readable($path),
			];
			$seen[$path] = true;
		}
		usort($voices, static function ($a, $b) {
			return strnatcasecmp($a['name'], $b['name']);
		});
		return $voices;
	}

	public function getAnnouncementGroups()
	{
		$settings = $this->getActiveSettings();
		return $settings['announcement_groups'] ?? [];
	}

	public function getScheduledAnnouncements($includeExecution = false)
	{
		$schedules = $this->getActiveSettings()['scheduled_announcements'] ?? [];
		if (!$includeExecution) {
			return $schedules;
		}
		$execution = $this->getScheduleExecutionState();
		foreach ($schedules as $index => $schedule) {
			$id = (string)($schedule['id'] ?? '');
			if ($id !== '' && isset($execution[$id])) {
				$schedules[$index]['execution'] = $execution[$id];
			}
		}
		return $schedules;
	}

	public function saveScheduledAnnouncement(array $input)
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return ['success' => false, 'message' => $this->getSetupRequiredMessage(), 'errors' => []];
		}

		$settings = $this->getActiveSettings();
		$schedules = $settings['scheduled_announcements'] ?? [];
		$originalSchedules = $schedules;
		$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['schedule_id'] ?? '')), 0, 64);
		$existingIndex = null;
		$existing = [];
		foreach ($schedules as $index => $schedule) {
			if ($id !== '' && hash_equals((string)($schedule['id'] ?? ''), $id)) {
				$existingIndex = $index;
				$existing = $schedule;
				break;
			}
		}
		if ($id !== '' && $existingIndex === null) {
			return ['success' => false, 'message' => _('The selected schedule no longer exists.'), 'errors' => []];
		}
		if ($id === '') {
			if (count($schedules) >= self::MAX_SCHEDULES) {
				return ['success' => false, 'message' => sprintf(_('Scheduling is limited to %d saved announcements.'), self::MAX_SCHEDULES), 'errors' => []];
			}
			$id = 'sched_' . bin2hex(random_bytes(10));
		}

		$errors = [];
		$name = $this->sanitizeScheduleText($input['schedule_name'] ?? '', 80, true);
		$message = $this->sanitizeScheduleText($input['schedule_message'] ?? '', 500, false);
		if ($name === '') {
			$errors[] = _('Enter a schedule name.');
		}
		if ($message === '') {
			$errors[] = _('Enter an announcement message.');
		}

		$timezone = $this->getPbxDateTimeZone();
		$existingRunTimes = [];
		foreach ((array)($existing['occurrences'] ?? []) as $existingOccurrence) {
			$existingTimestamp = strtotime((string)($existingOccurrence['run_at_utc'] ?? ''));
			if ($existingTimestamp !== false) {
				$existingRunTimes[gmdate('Y-m-d\TH:i:s\Z', $existingTimestamp)] = true;
			}
		}
		$occurrenceInputs = array_values((array)($input['schedule_occurrences'] ?? []));
		$requestedRecurrenceMode = strtolower(trim((string)($input['schedule_recurrence_mode'] ?? 'none')));
		if (!in_array($requestedRecurrenceMode, ['none', 'every_7_days', 'every_14_days'], true)) {
			$errors[] = _('Select a supported repeat interval.');
		}
		$recurrenceMode = $this->normalizeScheduleRecurrenceMode($requestedRecurrenceMode);
		$occurrenceBuild = $this->buildScheduledOccurrences(
			$id,
			$occurrenceInputs,
			$recurrenceMode,
			$timezone,
			$existingRunTimes,
			time() - 60,
			time() + (self::MAX_SCHEDULE_YEARS * 366 * 86400)
		);
		$occurrences = $occurrenceBuild['occurrences'];
		$recurrence = $occurrenceBuild['recurrence'];
		$errors = array_merge($errors, $occurrenceBuild['errors']);

		$knownExtensions = [];
		foreach ($this->getAllPjsipExtensions() as $extension) {
			$number = preg_replace('/[^0-9]/', '', (string)($extension['extension'] ?? ''));
			if ($number !== '') {
				$knownExtensions[$number] = true;
			}
		}
		$selectedExtensions = [];
		foreach ((array)($input['schedule_extensions'] ?? []) as $extension) {
			$number = preg_replace('/[^0-9]/', '', (string)$extension);
			if ($number !== '' && isset($knownExtensions[$number])) {
				$selectedExtensions[$number] = $number;
			}
		}

		$knownGroups = [];
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			$groupId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? '')), 0, 64);
			if ($groupId !== '') {
				$knownGroups[$groupId] = true;
			}
		}
		$selectedGroups = [];
		foreach ((array)($input['schedule_groups'] ?? []) as $groupId) {
			$groupId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId), 0, 64);
			if ($groupId !== '' && isset($knownGroups[$groupId])) {
				$selectedGroups[$groupId] = $groupId;
			}
		}

		$knownDesktops = [];
		foreach ($this->getDesktopClients($settings) as $client) {
			if (!empty($client['enabled'])) {
				$username = $this->normalizeDesktopUsername($client['username'] ?? '');
				if ($username !== '') {
					$knownDesktops[$username] = true;
				}
			}
		}
		$selectedDesktops = [];
		foreach ((array)($input['schedule_desktop_clients'] ?? []) as $username) {
			$username = $this->normalizeDesktopUsername($username);
			if ($username !== '' && isset($knownDesktops[$username])) {
				$selectedDesktops[$username] = $username;
			}
		}
		$phonesAll = !empty($input['schedule_all_phones']);
		$desktopAll = !empty($input['schedule_all_desktops']);
		if ($phonesAll) {
			$selectedExtensions = [];
		}
		if ($desktopAll) {
			$selectedDesktops = [];
		}
		if (!$phonesAll && !$desktopAll && empty($selectedExtensions) && empty($selectedGroups) && empty($selectedDesktops)) {
			$errors[] = _('Select at least one phone, announcement group, or desktop recipient.');
		}

		$audioMode = $this->normalizeAnnouncementAudioMode($input['schedule_audio_mode'] ?? 'none');
		$availableTones = array_fill_keys($this->getAvailableTones(), true);
		$openingTone = $this->normalizeToneName($input['schedule_opening_tone'] ?? '');
		$closingTone = $this->normalizeToneName($input['schedule_closing_tone'] ?? '');
		if (!in_array($audioMode, ['tones', 'tones_tts'], true)) {
			$openingTone = '';
			$closingTone = '';
		} else {
			foreach ([$openingTone, $closingTone] as $tone) {
				if ($tone !== '' && !isset($availableTones[$tone])) {
					$errors[] = _('A selected scheduled-announcement tone is unavailable.');
				}
			}
			if ($openingTone === '' && $closingTone === '') {
				$errors[] = _('Select at least one opening or closing tone when scheduled tone audio is enabled.');
			}
		}
		$voice = trim((string)($input['schedule_voice'] ?? ''));
		$voiceLookup = [];
		foreach ($this->getAvailablePiperVoices() as $availableVoice) {
			$voicePath = (string)($availableVoice['path'] ?? '');
			if (!empty($availableVoice['available']) && $voicePath !== '' && is_readable($voicePath)) {
				$voiceLookup[$voicePath] = true;
			}
		}
		if (in_array($audioMode, ['tts', 'tones_tts'], true) && !isset($voiceLookup[$voice])) {
			$errors[] = _('Select an available Piper voice for scheduled TTS.');
		}
		if (!in_array($audioMode, ['tts', 'tones_tts'], true)) {
			$voice = '';
		}

		$hasPhoneTarget = $phonesAll || !empty($selectedExtensions);
		if (!$hasPhoneTarget && !empty($selectedGroups)) {
			foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
				$groupId = (string)($group['id'] ?? '');
				if (isset($selectedGroups[$groupId]) && !empty($group['extensions'])) {
					$hasPhoneTarget = true;
					break;
				}
			}
		}
		if ($audioMode !== 'none' && !$hasPhoneTarget) {
			$errors[] = _('Scheduled audio requires at least one phone target. Desktop-only schedules must use text-only delivery.');
		}

		if (!empty($errors)) {
			return ['success' => false, 'message' => _('The scheduled announcement was not saved.'), 'errors' => array_values(array_unique($errors))];
		}

		$now = gmdate('c');
		$schedule = [
			'id' => $id,
			'name' => $name,
			'enabled' => empty($input['schedule_enabled']) ? '0' : '1',
			'timezone' => $timezone->getName(),
			'recurrence' => $recurrence,
			'occurrences' => $occurrences,
			'message' => $message,
			'targets' => [
				'extensions' => array_values($selectedExtensions),
				'groups' => array_values($selectedGroups),
				'phones_all' => $phonesAll ? '1' : '0',
				'desktop_clients' => array_values($selectedDesktops),
				'desktop_all' => $desktopAll ? '1' : '0',
			],
			'delivery' => [
				'audio_mode' => $audioMode,
				'voice' => $voice,
				'tts_volume' => $this->normalizeTtsVolume($input['schedule_tts_volume'] ?? 25, 25),
				'opening_tone' => $openingTone,
				'closing_tone' => $closingTone,
				'style' => !empty($input['schedule_colored']) ? 'colored' : 'standard',
				'title' => $this->sanitizeScheduleText($input['schedule_title'] ?? 'Announcement', 80, true) ?: 'Announcement',
				'background_color' => $this->normalizeHexColor($input['schedule_background_color'] ?? '#1f2937', '#1f2937'),
			],
			'created_at' => (string)($existing['created_at'] ?? $now),
			'updated_at' => $now,
		];

		if ($existingIndex === null) {
			$schedules[] = $schedule;
		} else {
			$schedules[$existingIndex] = $schedule;
		}
		try {
			$this->persistScheduledAnnouncements($schedules, $originalSchedules);
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => $this->sanitizeScheduleText($e->getMessage(), 300, true), 'errors' => []];
		}
		return ['success' => true, 'message' => $existingIndex === null ? _('Scheduled announcement created.') : _('Scheduled announcement updated.'), 'errors' => []];
	}

	public function deleteScheduledAnnouncement($scheduleId)
	{
		$scheduleId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$scheduleId), 0, 64);
		$schedules = $this->getActiveSettings()['scheduled_announcements'] ?? [];
		$originalSchedules = $schedules;
		$filtered = array_values(array_filter($schedules, static function ($schedule) use ($scheduleId) {
			return (string)($schedule['id'] ?? '') !== $scheduleId;
		}));
		if ($scheduleId === '' || count($filtered) === count($schedules)) {
			return ['success' => false, 'message' => _('The selected schedule no longer exists.'), 'errors' => []];
		}
		try {
			$this->persistScheduledAnnouncements($filtered, $originalSchedules);
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => $this->sanitizeScheduleText($e->getMessage(), 300, true), 'errors' => []];
		}
		return ['success' => true, 'message' => _('Scheduled announcement deleted.'), 'errors' => []];
	}

	public function toggleScheduledAnnouncement($scheduleId, $enabled)
	{
		$scheduleId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$scheduleId), 0, 64);
		$schedules = $this->getActiveSettings()['scheduled_announcements'] ?? [];
		$originalSchedules = $schedules;
		$found = false;
		foreach ($schedules as $index => $schedule) {
			if ($scheduleId !== '' && hash_equals((string)($schedule['id'] ?? ''), $scheduleId)) {
				$schedules[$index]['enabled'] = $enabled ? '1' : '0';
				$schedules[$index]['updated_at'] = gmdate('c');
				$found = true;
				break;
			}
		}
		if (!$found) {
			return ['success' => false, 'message' => _('The selected schedule no longer exists.'), 'errors' => []];
		}
		try {
			$this->persistScheduledAnnouncements($schedules, $originalSchedules);
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => $this->sanitizeScheduleText($e->getMessage(), 300, true), 'errors' => []];
		}
		return ['success' => true, 'message' => $enabled ? _('Scheduled announcement enabled.') : _('Scheduled announcement disabled.'), 'errors' => []];
	}

	public function processScheduledAnnouncements()
	{
		$this->ensurePluginDataDir();
		$workerLock = @fopen(self::SCHEDULE_LOCK_FILE, 'c');
		if ($workerLock === false) {
			$this->updateStatusData([
				'last_schedule_worker_at' => gmdate('c'),
				'last_schedule_worker_status' => 'fault',
				'last_schedule_worker_message' => _('The scheduler could not open its worker lock file.'),
			]);
			throw new \RuntimeException(_('The scheduler could not open its worker lock file.'));
		}
		if (!flock($workerLock, LOCK_EX | LOCK_NB)) {
			fclose($workerLock);
			return ['success' => true, 'message' => _('Another scheduling worker is already active.')];
		}
		$this->setOwnership(self::SCHEDULE_LOCK_FILE);

		$processed = 0;
		$attention = 0;
		try {
			// A corrupt existing execution journal must fail closed. Treating it as an
			// empty journal could replay an announcement whose successful result was
			// already recorded before the file was damaged.
			$store = $this->loadScheduleExecutionStore(true);
			$store['occurrences'] = is_array($store['occurrences'] ?? null) ? $store['occurrences'] : [];
			$schedules = $this->getActiveSettings()['scheduled_announcements'] ?? [];
			$due = [];
			$knownOccurrenceIds = [];
			$scanNow = time();
			foreach ($schedules as $schedule) {
				foreach ((array)($schedule['occurrences'] ?? []) as $occurrence) {
					$occurrenceId = (string)($occurrence['id'] ?? '');
					$runAt = $this->parseScheduleUtcTimestamp((string)($occurrence['run_at_utc'] ?? ''));
					if ($occurrenceId === '' || $runAt === false) {
						continue;
					}
					$knownOccurrenceIds[$occurrenceId] = true;
					if (empty($schedule['enabled'])) {
						continue;
					}
					$current = is_array($store['occurrences'][$occurrenceId] ?? null) ? $store['occurrences'][$occurrenceId] : [];
					$currentState = strtolower((string)($current['state'] ?? 'pending'));
					if (in_array($currentState, ['success', 'failed', 'missed', 'uncertain'], true)) {
						continue;
					}
					if ($runAt <= $scanNow) {
						$due[] = ['run_at' => $runAt, 'schedule' => $schedule, 'occurrence' => $occurrence];
					}
				}
			}
			usort($due, static function ($left, $right) {
				return ((int)$left['run_at']) <=> ((int)$right['run_at']);
			});

			foreach ($due as $item) {
				$schedule = $item['schedule'];
				$occurrence = $item['occurrence'];
				$occurrenceId = (string)$occurrence['id'];
				$runAt = (int)$item['run_at'];
				$currentNow = time();
				$current = is_array($store['occurrences'][$occurrenceId] ?? null) ? $store['occurrences'][$occurrenceId] : [];
				$currentState = strtolower((string)($current['state'] ?? 'pending'));
				if (in_array($currentState, ['success', 'failed', 'missed', 'uncertain'], true)) {
					continue;
				}
				if ($currentState === 'claimed') {
					$claimedAt = strtotime((string)($current['claimed_at'] ?? '')) ?: 0;
					if ($claimedAt > 0 && ($currentNow - $claimedAt) <= 300) {
						continue;
					}
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'uncertain', _('The previous worker stopped after claiming this delivery; it was not replayed to prevent a duplicate announcement.'), $current);
					$this->writeScheduleExecutionStore($store);
					$attention++;
					continue;
				}
				if (($currentNow - $runAt) > self::SCHEDULE_GRACE_SECONDS) {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'missed', _('The PBX was unable to start this announcement within the protected 15-minute delivery window. Add a new future date and time to re-arm it.'), $current);
					$this->writeScheduleExecutionStore($store);
					$attention++;
					continue;
				}

				// Coordinate with schedule edits and re-read the live definition immediately
				// before claiming it. Once claimed, later UI edits apply to future runs and
				// cannot safely cancel a delivery that may already be entering Asterisk.
				$configLock = $this->acquireSettingsLock();
				try {
					$liveMatch = $this->findLiveScheduledOccurrence(
						(string)($schedule['id'] ?? ''),
						$occurrenceId,
						(string)($occurrence['run_at_utc'] ?? '')
					);
					if ($liveMatch === null) {
						continue;
					}
					$schedule = $liveMatch['schedule'];
					$occurrence = $liveMatch['occurrence'];
					$current['attempts'] = max(0, (int)($current['attempts'] ?? 0)) + 1;
					$current['claimed_at'] = gmdate('c');
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'claimed', _('Delivery is being submitted.'), $current);
					$this->writeScheduleExecutionStore($store);
				} finally {
					$this->releaseSettingsLock($configLock);
				}

				$targets = is_array($schedule['targets'] ?? null) ? $schedule['targets'] : [];
				$delivery = is_array($schedule['delivery'] ?? null) ? $schedule['delivery'] : [];
				$audioMode = $this->normalizeAnnouncementAudioMode($delivery['audio_mode'] ?? 'none');
				$result = $this->sendSipNotifyAnnouncement(
					(array)($targets['extensions'] ?? []),
					(string)($schedule['message'] ?? ''),
					false,
					in_array($audioMode, ['tts', 'tones_tts'], true),
					(array)($targets['groups'] ?? []),
					[
						'phones_all' => !empty($targets['phones_all']),
						'desktop_all' => !empty($targets['desktop_all']),
						'desktop_clients' => (array)($targets['desktop_clients'] ?? []),
						'style' => (string)($delivery['style'] ?? 'standard'),
						'title' => (string)($delivery['title'] ?? 'Announcement'),
						'background_color' => (string)($delivery['background_color'] ?? '#1f2937'),
						'audio_mode' => $audioMode,
						'opening_tone' => (string)($delivery['opening_tone'] ?? ''),
						'closing_tone' => (string)($delivery['closing_tone'] ?? ''),
						'piper_voice' => (string)($delivery['voice'] ?? ''),
						'tts_volume' => $delivery['tts_volume'] ?? 25,
						'trigger_source' => 'Scheduled: ' . (string)($schedule['name'] ?? 'Announcement'),
					]
				);
				$processed++;
				if (!empty($result['success'])) {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'success', (string)($result['message'] ?? _('Announcement submitted.')), $current);
					$this->writeScheduleExecutionStore($store);
					continue;
				}

				$errorCode = strtolower((string)($result['error_code'] ?? ''));
				$deliveryStarted = !empty($result['delivery_started']);
				$message = $this->sanitizeScheduleText($result['message'] ?? _('Scheduled announcement failed.'), 300, true);
				if ($deliveryStarted) {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'uncertain', $message . ' ' . _('It was not replayed because delivery may have started.'), $current);
					$attention++;
				} elseif (in_array($errorCode, ['cooldown', 'delivery_busy', 'no_targets', 'no_audio_targets'], true) && ($currentNow - $runAt) <= self::SCHEDULE_GRACE_SECONDS) {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'pending', $message . ' ' . _('The scheduler will retry within the delivery window.'), $current);
				} elseif ((int)$current['attempts'] < 3 && ($currentNow - $runAt) <= self::SCHEDULE_GRACE_SECONDS && in_array($errorCode, ['audio_failed', 'sender_unavailable'], true)) {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'pending', $message . ' ' . _('The scheduler will retry automatically.'), $current);
				} else {
					$store['occurrences'][$occurrenceId] = $this->scheduleExecutionRecord($schedule, $occurrence, 'failed', $message . ' ' . _('Add a new future date and time to re-arm this schedule.'), $current);
					$attention++;
				}
				$this->writeScheduleExecutionStore($store);
			}

			$cutoff = time() - (90 * 86400);
			$storeChanged = false;
			foreach ($store['occurrences'] as $occurrenceId => $record) {
				$recordTime = strtotime((string)($record['run_at_utc'] ?? $record['updated_at'] ?? '')) ?: 0;
				if (!isset($knownOccurrenceIds[$occurrenceId]) && $recordTime > 0 && $recordTime < $cutoff) {
					unset($store['occurrences'][$occurrenceId]);
					$storeChanged = true;
				}
			}
			if ($storeChanged) {
				$this->writeScheduleExecutionStore($store);
			}
			$workerMessage = sprintf(_('Scheduler checked due announcements; %d delivery attempt(s), %d item(s) need attention.'), $processed, $attention);
			$this->updateStatusData([
				'last_schedule_worker_at' => gmdate('c'),
				'last_schedule_worker_status' => $attention > 0 ? 'warning' : 'ok',
				'last_schedule_worker_message' => $workerMessage,
			]);
			return ['success' => true, 'processed' => $processed, 'attention' => $attention];
		} catch (\Throwable $e) {
			$this->updateStatusData([
				'last_schedule_worker_at' => gmdate('c'),
				'last_schedule_worker_status' => 'fault',
				'last_schedule_worker_message' => $this->sanitizeScheduleText($e->getMessage(), 300, true),
			]);
			throw $e;
		} finally {
			flock($workerLock, LOCK_UN);
			fclose($workerLock);
		}
	}

	public function getAvailableAnnouncementGroups()
	{
		$allowed = [];
		foreach ($this->getSipNotifyTargets() as $target) {
			$allowed[$target['extension']] = true;
		}
		$allowedDesktops = [];
		foreach ($this->getDesktopClients($this->getActiveSettings()) as $client) {
			if (!empty($client['enabled'])) {
				$allowedDesktops[$client['username']] = true;
			}
		}

		$groups = [];
		foreach ($this->getAnnouncementGroups() as $group) {
			$extensions = [];
			foreach ((array)($group['extensions'] ?? []) as $extension) {
				if (isset($allowed[$extension])) {
					$extensions[] = $extension;
				}
			}
			$desktopClients = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '' && isset($allowedDesktops[$username])) {
					$desktopClients[] = $username;
				}
			}
			if (empty($extensions) && empty($desktopClients)) {
				continue;
			}
			$group['extensions'] = $extensions;
			$group['desktop_clients'] = $desktopClients;
			$groups[] = $group;
		}
		return $groups;
	}

	public function saveAnnouncementGroup($groupId, $name, $extensions, $desktopClients = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'groups' => $this->getAnnouncementGroups(),
			];
		}

		$name = trim((string)$name);
		if ($name === '') {
					return [
						'success' => false,
						'message' => _('Enter an announcement group name.'),
						'groups' => $this->getAnnouncementGroups(),
					];
		}

		$settings = $this->getActiveSettings();
		$groups = $settings['announcement_groups'] ?? [];
		$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId);
		$updated = false;
		$candidate = [
			'name' => $name,
			'extensions' => (array)$extensions,
			'desktop_clients' => (array)$desktopClients,
		];

		foreach ($groups as $index => $group) {
			if (($group['id'] ?? '') === $groupId && $groupId !== '') {
				$groups[$index] = $candidate;
				$updated = true;
				break;
			}
		}
		if (!$updated) {
			if (count($groups) >= 20) {
						return [
							'success' => false,
							'message' => _('Announcement groups are limited to 20.'),
							'groups' => $this->getAnnouncementGroups(),
						];
			}
			$groups[] = $candidate;
		}

		$allowedDesktopUsernames = array_column($this->getDesktopClients($settings), 'username');
		$normalized = $this->normalizeAnnouncementGroupsForExtensions($groups, array_column($this->getAllPjsipExtensions(), 'extension'), $allowedDesktopUsernames);
		if (empty($normalized)) {
					return [
						'success' => false,
						'message' => _('Select at least one extension or desktop client for the announcement group.'),
						'groups' => $this->getAnnouncementGroups(),
					];
		}

		$settings['announcement_groups'] = $normalized;
		try {
			$this->persistAppliedSettings($settings);
			$this->syncPendingAnnouncementGroups($normalized);
		} catch (\Throwable $e) {
					return [
						'success' => false,
						'message' => _('Unable to save announcement group.'),
						'groups' => $this->getAnnouncementGroups(),
					];
		}

		return [
			'success' => true,
			'message' => _('Announcement group saved.'),
			'groups' => $normalized,
		];
	}

	public function deleteAnnouncementGroup($groupId)
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'groups' => $this->getAnnouncementGroups(),
			];
		}

		$settings = $this->getActiveSettings();
		$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId);
		$groups = [];
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			if (($group['id'] ?? '') !== $groupId) {
				$groups[] = $group;
			}
		}
		$settings['announcement_groups'] = $this->normalizeAnnouncementGroups($groups);
		try {
			$this->persistAppliedSettings($settings);
			$this->syncPendingAnnouncementGroups($settings['announcement_groups']);
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to delete announcement group.'),
				'groups' => $this->getAnnouncementGroups(),
			];
		}

		return [
			'success' => true,
			'message' => _('Announcement group deleted.'),
			'groups' => $this->getAnnouncementGroups(),
		];
	}

	public function controlApiSendAnnouncement(array $payload)
	{
		$payloadErrors = $this->validateControlApiAnnouncementPayload($payload);
		if (!empty($payloadErrors)) {
			return [
				'success' => false,
				'message' => _('Control API announcement failed validation.'),
				'errors' => $payloadErrors,
			];
		}
		$message = trim((string)($payload['message'] ?? $payload['body'] ?? $payload['text'] ?? ''));
		$targets = $this->normalizeRecipientExtensions($payload['targets'] ?? $payload['extensions'] ?? []);
		$groups = $this->normalizeControlGroupSelectors($payload['groups'] ?? $payload['announcement_groups'] ?? []);
		$options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
		$options['trigger_source'] = 'Control API';
		foreach (['style', 'image', 'title', 'background_color', 'audio_mode', 'opening_tone', 'closing_tone'] as $key) {
			if (array_key_exists($key, $payload)) {
				$options[$key] = $payload[$key];
			}
		}
		if (array_key_exists('desktop_all', $payload) || array_key_exists('all_desktops', $payload)) {
			$options['desktop_all'] = ($payload['desktop_all'] ?? false) === true || ($payload['all_desktops'] ?? false) === true;
		}
		if (array_key_exists('desktop_clients', $payload) || array_key_exists('desktop_targets', $payload)) {
			$options['desktop_clients'] = $payload['desktop_clients'] ?? $payload['desktop_targets'];
		}
		if (array_key_exists('phones_all', $payload) || array_key_exists('all_phones', $payload)) {
			$options['phones_all'] = ($payload['phones_all'] ?? false) === true || ($payload['all_phones'] ?? false) === true;
		}

		return $this->sendSipNotifyAnnouncement(
			$targets,
			$message,
			($payload['desktop'] ?? false) === true || !empty($options['desktop_all']) || !empty($options['desktop_clients']),
			($payload['tts'] ?? false) === true || in_array(($options['audio_mode'] ?? ''), ['tts', 'tones_tts'], true),
			$groups,
			$options
		);
	}

	public function controlApiTriggerNwsTest(array $payload = [])
	{
		$payloadErrors = $this->validateControlApiNwsTestPayload($payload);
		if (!empty($payloadErrors)) {
			return [
				'success' => false,
				'message' => _('Control API Weather test failed validation.'),
				'errors' => $payloadErrors,
			];
		}
		$mode = trim((string)($payload['mode'] ?? 'tts'));
		$triggerName = trim((string)($payload['trigger_name'] ?? 'Control API'));
		if ($triggerName === '') {
			$triggerName = 'Control API';
		}
		$triggerName = function_exists('mb_substr') ? mb_substr($triggerName, 0, 80) : substr($triggerName, 0, 80);
		$zoneScope = strtolower(trim((string)($payload['zone_scope'] ?? 'all')));
		$zoneIds = $zoneScope === 'selected' ? (array)($payload['zone_ids'] ?? $payload['zones'] ?? []) : [];
		return $this->triggerTest($mode, '', $triggerName, $zoneIds);
	}

	public function controlApiConfig(array $payload = [])
	{
		// The control credential authorizes management actions, but it must not be
		// usable to extract every other credential stored by the module.
		$settings = $this->redactConfigSecrets($this->getActiveSettings());
		return [
			'success' => true,
			'config' => $settings,
			'secrets_included' => false,
			'pending' => $this->getPendingSettings() !== null,
		];
	}

	public function controlApiUpdateConfig(array $payload)
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'errors' => [$this->getSetupRequiredMessage()],
			];
		}

		if (array_key_exists('apply', $payload) && !is_bool($payload['apply'])) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => [_('apply must be a JSON boolean.')],
			];
		}
		$settingsPatch = is_array($payload['settings'] ?? null) ? $payload['settings'] : (is_array($payload['config'] ?? null) ? $payload['config'] : []);
		if (empty($settingsPatch)) {
			return [
				'success' => false,
				'message' => _('No config settings were provided.'),
				'errors' => [_('Provide a settings object to update.')],
			];
		}
		$patchValidation = $this->validateAndNormalizeControlConfigPatch($settingsPatch);
		if (!empty($patchValidation['errors'])) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => $patchValidation['errors'],
			];
		}
		$settingsPatch = $patchValidation['patch'];
		$current = $this->getPendingSettings() ?? $this->getActiveSettings();
		if (array_key_exists('system_notification_emails', $settingsPatch)) {
			if (!is_string($settingsPatch['system_notification_emails'])) {
				return [
					'success' => false,
					'message' => _('Control API config changes failed validation.'),
					'errors' => [_('System notification email recipients must be supplied as a string of addresses.')],
				];
			}
			$mailErrors = $this->validateEmailRecipientsInput($settingsPatch['system_notification_emails']);
			if (!empty($mailErrors)) {
				return [
					'success' => false,
					'message' => _('Control API config changes failed validation.'),
					'errors' => $mailErrors,
				];
			}
		}
		if (array_key_exists('discord_webhook_url', $settingsPatch)) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => [_('Use the discord_webhooks destination array instead of the legacy Discord webhook field.')],
			];
		}
		if (array_key_exists('mail_from_domain', $settingsPatch)) {
			if (!is_string($settingsPatch['mail_from_domain']) || $this->normalizeEmailSenderDomain($settingsPatch['mail_from_domain']) === '') {
				return [
					'success' => false,
					'message' => _('Control API config changes failed validation.'),
					'errors' => [_('Email sender domain must be a valid DNS hostname, such as example.com.')],
				];
			}
		}
		if (array_key_exists('mail_from_local_part', $settingsPatch)
			&& (!is_string($settingsPatch['mail_from_local_part']) || $this->normalizeEmailSenderLocalPart($settingsPatch['mail_from_local_part']) === '')) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => [_('Email sender name is invalid.')],
			];
		}
		if (array_key_exists('nws_zones', $settingsPatch)) {
			$zoneErrors = $this->validateNwsZoneGroupsInput($settingsPatch['nws_zones']);
			if (!empty($zoneErrors)) {
				return [
					'success' => false,
					'message' => _('Control API config changes failed validation.'),
					'errors' => $zoneErrors,
				];
			}
		}
		$destinationErrors = [];
		if (array_key_exists('discord_webhooks', $settingsPatch)) {
			$destinationErrors = array_merge($destinationErrors, $this->validateWebhookDestinations(
				$this->mergeWebhookDestinationSecrets($settingsPatch['discord_webhooks'], $current['discord_webhooks'] ?? [], 'discord'),
				'discord'
			));
		}
		if (array_key_exists('generic_webhooks', $settingsPatch)) {
			$destinationErrors = array_merge($destinationErrors, $this->validateWebhookDestinations(
				$this->mergeWebhookDestinationSecrets($settingsPatch['generic_webhooks'], $current['generic_webhooks'] ?? [], 'generic'),
				'generic'
			));
		}
		if (!empty($destinationErrors)) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => array_values(array_unique($destinationErrors)),
			];
		}

		$merged = $this->mergeControlConfigPatch($current, $settingsPatch);
		$normalized = $this->normalizeSettings($merged);
		$zoneRoutingErrors = [];
		if (array_key_exists('nws_zones', $settingsPatch) || array_key_exists('desktop_clients', $settingsPatch)) {
			$zoneRoutingErrors = array_merge(
				$zoneRoutingErrors,
				$this->validateNwsZoneDesktopAssignments($normalized['nws_zones'] ?? [], $normalized)
			);
		}
		if (array_key_exists('nws_zones', $settingsPatch) || array_key_exists('mail_to', $settingsPatch)) {
			$zoneRoutingErrors = array_merge(
				$zoneRoutingErrors,
				$this->validateNwsZoneEmailCapacity($normalized['nws_zones'] ?? [], $normalized['mail_to'] ?? '')
			);
		}
		if (!empty($zoneRoutingErrors)) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => array_values(array_unique($zoneRoutingErrors)),
			];
		}
		$schemaErrors = $this->validateConfigSchema($normalized);
		if (!empty($schemaErrors)) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => $schemaErrors,
			];
		}
		try {
			if (($payload['apply'] ?? false) === true) {
				$this->persistAppliedSettings($normalized, true, true);
				return [
					'success' => true,
					'message' => _('Control API config changes applied.'),
					'pending' => false,
				];
			}
			$this->persistPendingSettings($normalized);
			return [
				'success' => true,
				'message' => _('Control API config changes saved. Apply config to make them live.'),
				'pending' => true,
			];
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to update config.'),
				'errors' => [$e->getMessage()],
			];
		}
	}

	private function validateControlApiAnnouncementPayload(array $payload)
	{
		$errors = [];
		foreach (['message', 'body', 'text', 'style', 'title', 'background_color', 'audio_mode', 'opening_tone', 'closing_tone'] as $field) {
			if (array_key_exists($field, $payload) && !is_string($payload[$field])) {
				$errors[] = sprintf(_('%s must be a JSON string.'), $field);
			}
		}
		foreach (['desktop_all', 'all_desktops', 'phones_all', 'all_phones', 'desktop', 'tts', 'image'] as $field) {
			if (array_key_exists($field, $payload) && !is_bool($payload[$field])) {
				$errors[] = sprintf(_('%s must be a JSON boolean.'), $field);
			}
		}
		foreach (['targets', 'extensions', 'groups', 'announcement_groups', 'desktop_clients', 'desktop_targets'] as $field) {
			if (!array_key_exists($field, $payload)) {
				continue;
			}
			if (!is_array($payload[$field]) || !array_is_list($payload[$field])) {
				$errors[] = sprintf(_('%s must be a JSON array.'), $field);
				continue;
			}
			foreach ($payload[$field] as $selector) {
				if (!is_string($selector) && !is_int($selector)) {
					$errors[] = sprintf(_('%s entries must be strings or integers.'), $field);
					break;
				}
			}
		}
		if (array_key_exists('options', $payload)) {
			if (!is_array($payload['options']) || (!empty($payload['options']) && array_is_list($payload['options']))) {
				$errors[] = _('options must be a JSON object.');
			} else {
				foreach (['desktop_all', 'phones_all', 'image'] as $field) {
					if (array_key_exists($field, $payload['options']) && !is_bool($payload['options'][$field])) {
						$errors[] = sprintf(_('options.%s must be a JSON boolean.'), $field);
					}
				}
				if (array_key_exists('desktop_clients', $payload['options'])
					&& (!is_array($payload['options']['desktop_clients']) || !array_is_list($payload['options']['desktop_clients']))) {
					$errors[] = _('options.desktop_clients must be a JSON array.');
				}
				foreach (['style', 'title', 'background_color', 'audio_mode', 'opening_tone', 'closing_tone'] as $field) {
					if (array_key_exists($field, $payload['options']) && !is_string($payload['options'][$field])) {
						$errors[] = sprintf(_('options.%s must be a JSON string.'), $field);
					}
				}
			}
		}
		return array_values(array_unique($errors));
	}

	private function validateControlApiNwsTestPayload(array $payload)
	{
		$errors = [];
		foreach (['mode', 'trigger_name', 'zone_scope'] as $field) {
			if (array_key_exists($field, $payload) && !is_string($payload[$field])) {
				$errors[] = sprintf(_('%s must be a JSON string.'), $field);
			}
		}
		$zoneScope = is_string($payload['zone_scope'] ?? null) ? strtolower(trim($payload['zone_scope'])) : 'all';
		if (!in_array($zoneScope, ['all', 'selected'], true)) {
			$errors[] = _('zone_scope must be "all" or "selected".');
		}
		$zoneIds = $payload['zone_ids'] ?? $payload['zones'] ?? [];
		if (!is_array($zoneIds) || !array_is_list($zoneIds)) {
			$errors[] = _('zone_ids must be a JSON array.');
			$zoneIds = [];
		} else {
			foreach ($zoneIds as $zoneId) {
				if (!is_string($zoneId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $zoneId)) {
					$errors[] = _('zone_ids entries must be valid configured zone ID strings.');
					break;
				}
			}
		}
		if ($zoneScope === 'selected' && empty($zoneIds)) {
			$errors[] = _('Select at least one zone_id when zone_scope is selected.');
		}
		if ($zoneScope === 'all' && !empty($zoneIds)) {
			$errors[] = _('Do not supply zone_ids when zone_scope is all.');
		}
		return array_values(array_unique($errors));
	}

	private function normalizeControlGroupSelectors($groups)
	{
		$known = [];
		foreach ($this->getAnnouncementGroups() as $group) {
			$id = (string)($group['id'] ?? '');
			$name = strtolower(trim((string)($group['name'] ?? '')));
			if ($id !== '') {
				$known[$id] = $id;
			}
			if ($name !== '' && $id !== '') {
				$known[$name] = $id;
			}
		}
		$selected = [];
		foreach ((array)$groups as $group) {
			$key = trim((string)$group);
			$lookup = strtolower($key);
			if (isset($known[$key])) {
				$selected[$known[$key]] = $known[$key];
			} elseif (isset($known[$lookup])) {
				$selected[$known[$lookup]] = $known[$lookup];
			}
		}
		return array_values($selected);
	}

	private function normalizeHexColor($value, $fallback)
	{
		$value = trim((string)$value);
		if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
			return strtolower($value);
		}
		return $fallback;
	}

	private function getPackageUpdateStatus()
	{
		$status = [
			'state' => 'latest',
			'label' => 'LATEST',
			'latest_version' => self::MODULE_VERSION,
			'last_checked' => '',
			'message' => '',
		];
		$updateStatus = [];
		foreach ([self::STATUS_JSON, self::PLUGIN_DATA_DIR . '/update-status.json'] as $path) {
			if (!is_readable($path)) {
				continue;
			}
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded)) {
				$updateStatus = array_merge($updateStatus, $decoded);
			}
		}
		$latest = trim((string)($updateStatus['latest_version'] ?? $updateStatus['available_version'] ?? ''));
		$latestNormalized = $this->normalizeVersionString($latest);
		$currentNormalized = $this->normalizeVersionString(self::MODULE_VERSION);
		$hasNewerVersion = $latestNormalized !== '' && version_compare($latestNormalized, $currentNormalized, '>');
		$flaggedWithoutVersion = !empty($updateStatus['update_available']) && $latestNormalized === '';
		if ($hasNewerVersion || $flaggedWithoutVersion) {
			$status['state'] = 'update';
			$status['latest_version'] = $latest !== '' ? $latest : '';
			$status['label'] = $latest !== '' ? sprintf(_('Update available: %s'), $latest) : _('Update available');
		}
		if (!empty($updateStatus['checked_at'])) {
			$status['last_checked'] = (string)$updateStatus['checked_at'];
		} elseif (!empty($updateStatus['last_checked'])) {
			$status['last_checked'] = (string)$updateStatus['last_checked'];
		}
		if (!empty($updateStatus['message'])) {
			$status['message'] = (string)$updateStatus['message'];
		}
		return $status;
	}

	private function normalizeVersionString($version)
	{
		$version = trim((string)$version);
		$version = preg_replace('/^slsmassnotifyserver[-_]/', '', $version);
		$version = preg_replace('/^[vV]/', '', $version);
		return trim((string)$version);
	}

	private function redactConfigSecrets(array $settings)
	{
		foreach (['desktop_api_token', 'desktop_auth_key', 'discord_webhook_url'] as $key) {
			if (array_key_exists($key, $settings)) {
				$settings[$key] = '[redacted]';
			}
		}
		foreach (['discord_webhooks', 'generic_webhooks'] as $listKey) {
			if (!is_array($settings[$listKey] ?? null)) {
				continue;
			}
			foreach ($settings[$listKey] as $index => $destination) {
				if (is_array($destination) && array_key_exists('url', $destination)) {
					$settings[$listKey][$index]['url'] = '[redacted]';
				}
			}
		}
		if (is_array($settings['desktop_clients'] ?? null)) {
			foreach ($settings['desktop_clients'] as $index => $client) {
				if (is_array($client) && array_key_exists('password_enc', $client)) {
					$settings['desktop_clients'][$index]['password_enc'] = '[redacted]';
				}
			}
		}
		if (is_array($settings['control_api'] ?? null) && array_key_exists('api_key', $settings['control_api'])) {
			$settings['control_api']['api_key'] = '[redacted]';
		}
		if (is_array($settings['ami'] ?? null) && array_key_exists('password', $settings['ami'])) {
			$settings['ami']['password'] = '[redacted]';
		}
		if (is_array($settings['xweather'] ?? null)) {
			foreach (['client_id', 'client_secret'] as $credentialField) {
				if (array_key_exists($credentialField, $settings['xweather'])) {
					$settings['xweather'][$credentialField] = '[redacted]';
				}
			}
		}
		return $settings;
	}

	private function mergeControlConfigPatch(array $current, array $patch)
	{
		$patch = $this->removeRedactedPlaceholders($patch);
		$allowed = $this->getControlConfigAllowedFields();
		foreach ($allowed as $key) {
			if (!array_key_exists($key, $patch)) {
				continue;
			}
			if (in_array($key, ['discord_webhooks', 'generic_webhooks'], true) && is_array($patch[$key])) {
				$current[$key] = $this->mergeWebhookDestinationSecrets(
					$patch[$key],
					$current[$key] ?? [],
					$key === 'discord_webhooks' ? 'discord' : 'generic'
				);
			} elseif (is_array($patch[$key]) && is_array($current[$key] ?? null) && in_array($key, ['control_api', 'sipnotify', 'updates', 'xweather'], true)) {
				$current[$key] = array_replace($current[$key], $patch[$key]);
			} else {
				$current[$key] = $patch[$key];
			}
		}
		return $current;
	}

	private function getControlConfigAllowedFields()
	{
		return [
			'enabled', 'alert_recipients', 'system_notification_emails', 'mail_to', 'mail_from_local_part', 'mail_from_domain',
			'discord_webhooks', 'generic_webhooks',
			'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_critical_events',
			'nws_api_base_url', 'nws_zone', 'nws_zones', 'alert_email_subject', 'alert_email_body',
			'test_email_subject', 'test_email_body', 'opening_tone', 'closing_tone',
			'nws_opening_tone', 'nws_closing_tone',
			'email_html_enabled', 'xweather',
			'nws_piper_voice', 'announcement_piper_voice', 'nws_tts_volume',
				'announcement_tts_volume', 'tts_max_seconds', 'announcement_timeout_mode',
				'announcement_timeout_seconds', 'log_retention_days', 'control_api',
				'sipnotify', 'announcement_groups', 'updates',
		];
	}

	private function validateAndNormalizeControlConfigPatch(array $patch)
	{
		$errors = [];
		if (!empty($patch) && array_is_list($patch)) {
			return ['patch' => $patch, 'errors' => [_('settings must be a JSON object, not an array.')]];
		}
		if (array_key_exists('mail_to', $patch)) {
			$errors[] = _('mail_to is a legacy live-alert field and is no longer writable. Use nws_zones[].email_recipients or xweather.groups[].email_recipients for live alerts, and system_notification_emails for system/error notices.');
		}
		$allowed = array_fill_keys($this->getControlConfigAllowedFields(), true);
		foreach (array_keys($patch) as $key) {
			if (!isset($allowed[$key])) {
				$errors[] = sprintf(_('Unknown Control API config field: %s.'), (string)$key);
			}
		}

		$booleanFields = ['enabled', 'quiet_hours_enabled', 'email_html_enabled'];
		$integerFields = ['nws_tts_volume', 'announcement_tts_volume', 'tts_max_seconds', 'announcement_timeout_seconds', 'log_retention_days'];
		$stringFields = [
			'system_notification_emails', 'mail_to', 'mail_from_local_part', 'mail_from_domain', 'quiet_hours_start', 'quiet_hours_end',
			'nws_api_base_url', 'nws_zone', 'alert_email_subject', 'alert_email_body', 'test_email_subject',
			'test_email_body', 'opening_tone', 'closing_tone', 'nws_opening_tone', 'nws_closing_tone',
			'nws_piper_voice', 'announcement_piper_voice', 'announcement_timeout_mode',
		];
		$listFields = ['alert_recipients', 'quiet_critical_events', 'nws_zones', 'discord_webhooks', 'generic_webhooks', 'announcement_groups'];
		$objectFields = ['xweather', 'control_api', 'sipnotify', 'updates'];

		foreach ($booleanFields as $field) {
			if (!array_key_exists($field, $patch)) {
				continue;
			}
			if (!is_bool($patch[$field])) {
				$errors[] = sprintf(_('%s must be a JSON boolean.'), $field);
			} else {
				$patch[$field] = $patch[$field] ? '1' : '0';
			}
		}
		foreach ($integerFields as $field) {
			if (array_key_exists($field, $patch) && !is_int($patch[$field])) {
				$errors[] = sprintf(_('%s must be a JSON integer.'), $field);
			}
		}
		foreach ($stringFields as $field) {
			if (array_key_exists($field, $patch) && !is_string($patch[$field])) {
				$errors[] = sprintf(_('%s must be a JSON string.'), $field);
			}
		}
		foreach ($listFields as $field) {
			if (array_key_exists($field, $patch) && (!is_array($patch[$field]) || !array_is_list($patch[$field]))) {
				$errors[] = sprintf(_('%s must be a JSON array.'), $field);
			}
		}
		foreach ($objectFields as $field) {
			if (array_key_exists($field, $patch)
				&& (!is_array($patch[$field]) || (!empty($patch[$field]) && array_is_list($patch[$field])))) {
				$errors[] = sprintf(_('%s must be a JSON object.'), $field);
			}
		}
		if (array_key_exists('nws_api_base_url', $patch)
			&& $patch['nws_api_base_url'] !== 'https://api.weather.gov') {
			$errors[] = _('nws_api_base_url must be exactly https://api.weather.gov.');
		}

		$nestedSchemas = [
			'xweather' => [
				'boolean' => ['enabled', 'adaptive_free_tier', 'quiet_hours_enabled'],
				'integer' => ['radius_miles', 'query_interval_minutes', 'adaptive_grace_minutes', 'tts_volume'],
				'array' => ['recipients', 'groups'],
				'string' => ['client_id', 'client_secret', 'location', 'adaptive_nws_zone_id', 'opening_tone', 'closing_tone', 'all_clear', 'quiet_hours_start', 'quiet_hours_end'],
			],
			'control_api' => [
				'boolean' => ['enabled', 'ip_allowlist_enabled', 'rate_limit_enabled'],
				'integer' => ['rate_limit_per_minute', 'audit_retention_days'],
				'array' => [],
				'string' => ['api_key', 'base_url', 'ip_allowlist'],
			],
			'sipnotify' => [
				'boolean' => [],
				'integer' => [],
				'array' => ['format_overrides'],
				'string' => ['pbx_host', 'base_url', 'media_scheme', 'media_base_url'],
			],
			'updates' => [
				'boolean' => ['github_enabled'],
				'integer' => [],
				'array' => [],
				'string' => ['repository', 'channel'],
			],
		];
		foreach ($nestedSchemas as $objectField => $schema) {
			if (!is_array($patch[$objectField] ?? null) || (!empty($patch[$objectField]) && array_is_list($patch[$objectField]))) {
				continue;
			}
			$nestedAllowed = array_fill_keys(array_merge($schema['boolean'], $schema['integer'], $schema['array'], $schema['string']), true);
			foreach (array_keys($patch[$objectField]) as $key) {
				if (!isset($nestedAllowed[$key])) {
					$errors[] = sprintf(_('Unknown Control API config field: %s.%s.'), $objectField, (string)$key);
				}
			}
			foreach ($schema['boolean'] as $key) {
				if (!array_key_exists($key, $patch[$objectField])) {
					continue;
				}
				if (!is_bool($patch[$objectField][$key])) {
					$errors[] = sprintf(_('%s.%s must be a JSON boolean.'), $objectField, $key);
				} else {
					$patch[$objectField][$key] = $patch[$objectField][$key] ? '1' : '0';
				}
			}
			foreach ($schema['integer'] as $key) {
				if (array_key_exists($key, $patch[$objectField]) && !is_int($patch[$objectField][$key])) {
					$errors[] = sprintf(_('%s.%s must be a JSON integer.'), $objectField, $key);
				}
			}
			foreach ($schema['array'] as $key) {
				$requiresList = !($objectField === 'sipnotify' && $key === 'format_overrides');
				if (array_key_exists($key, $patch[$objectField])
					&& (!is_array($patch[$objectField][$key]) || ($requiresList && !array_is_list($patch[$objectField][$key])))) {
					$errors[] = sprintf(_('%s.%s must be a JSON array.'), $objectField, $key);
				}
			}
			foreach ($schema['string'] as $key) {
				if (array_key_exists($key, $patch[$objectField]) && !is_string($patch[$objectField][$key])) {
					$errors[] = sprintf(_('%s.%s must be a JSON string.'), $objectField, $key);
				}
			}
		}

		if (is_array($patch['nws_zones'] ?? null) && array_is_list($patch['nws_zones'])) {
			foreach ($patch['nws_zones'] as $index => $zone) {
				if (!is_array($zone) || (!empty($zone) && array_is_list($zone))) {
					$errors[] = sprintf(_('nws_zones[%d] must be a JSON object.'), $index);
					continue;
				}
				$zoneAllowed = ['id', 'name', 'zone', 'extensions', 'recipients', 'desktop_clients', 'email_recipients'];
				foreach (array_keys($zone) as $key) {
					if (!in_array($key, $zoneAllowed, true)) {
						$errors[] = sprintf(_('Unknown Control API config field: nws_zones[%d].%s.'), $index, (string)$key);
					}
				}
				foreach (['id', 'name', 'zone'] as $key) {
					if (array_key_exists($key, $zone) && !is_string($zone[$key])) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a JSON string.'), $index, $key);
					}
				}
				foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients'] as $key) {
					if (array_key_exists($key, $zone) && (!is_array($zone[$key]) || !array_is_list($zone[$key]))) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a JSON array.'), $index, $key);
					}
				}
			}
		}
		if (is_array($patch['xweather']['groups'] ?? null) && array_is_list($patch['xweather']['groups'])) {
			if (count($patch['xweather']['groups']) > 5) {
				$errors[] = _('xweather.groups is limited to five entries.');
			}
			foreach ($patch['xweather']['groups'] as $index => $group) {
				if (!is_array($group) || (!empty($group) && array_is_list($group))) {
					$errors[] = sprintf(_('xweather.groups[%d] must be a JSON object.'), $index);
					continue;
				}
				$groupAllowed = ['id', 'name', 'enabled', 'adaptive_nws_zone_id', 'location', 'radius_miles', 'extensions', 'desktop_clients', 'email_recipients', 'all_clear'];
				foreach (array_keys($group) as $key) {
					if (!in_array($key, $groupAllowed, true)) {
						$errors[] = sprintf(_('Unknown Control API config field: xweather.groups[%d].%s.'), $index, (string)$key);
					}
				}
				foreach (['id', 'name', 'adaptive_nws_zone_id', 'location', 'all_clear'] as $key) {
					if (array_key_exists($key, $group) && !is_string($group[$key])) {
						$errors[] = sprintf(_('xweather.groups[%d].%s must be a JSON string.'), $index, $key);
					}
				}
				if (array_key_exists('enabled', $group)) {
					if (!is_bool($group['enabled'])) {
						$errors[] = sprintf(_('xweather.groups[%d].enabled must be a JSON boolean.'), $index);
					} else {
						$patch['xweather']['groups'][$index]['enabled'] = $group['enabled'] ? '1' : '0';
					}
				}
				if (array_key_exists('radius_miles', $group) && !is_int($group['radius_miles'])) {
					$errors[] = sprintf(_('xweather.groups[%d].radius_miles must be a JSON integer.'), $index);
				}
				foreach (['extensions', 'desktop_clients', 'email_recipients'] as $key) {
					if (array_key_exists($key, $group) && (!is_array($group[$key]) || !array_is_list($group[$key]))) {
						$errors[] = sprintf(_('xweather.groups[%d].%s must be a JSON array.'), $index, $key);
					} elseif (is_array($group[$key] ?? null)) {
						foreach ($group[$key] as $entry) {
							if (!is_string($entry)) {
								$errors[] = sprintf(_('xweather.groups[%d].%s entries must be JSON strings.'), $index, $key);
								break;
							}
						}
					}
				}
			}
		}
		return ['patch' => $patch, 'errors' => array_values(array_unique($errors))];
	}

	private function removeRedactedPlaceholders(array $value)
	{
		foreach ($value as $key => $item) {
			if ($item === '[redacted]') {
				unset($value[$key]);
				continue;
			}
			if (is_array($item)) {
				$value[$key] = $this->removeRedactedPlaceholders($item);
			}
		}
		return $value;
	}

	private function getControlApiUrl(array $settings)
	{
		$host = $this->getPublicPbxHost($settings);
		return 'https://' . $host . '/api/sls-mass-notify';
	}

	private function getPublicPbxHost(array $settings = null)
	{
		$settings = $settings ?? [];
		$host = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? ''));
		if ($host === '' && is_array($settings['sipnotify'] ?? null)) {
			$host = $this->normalizePbxHost((string)($settings['sipnotify']['pbx_host'] ?? ''));
		}
		return $host ?: $this->detectPbxHost();
	}

	private function getDefaultSettings()
	{
		$defaultHost = $this->detectPbxHost();
		$defaultMailFromDomain = $this->detectPostfixSenderDomain($defaultHost);
		$defaultMailFromLocalPart = 'no-reply';
		$defaultMailFromAddr = $defaultMailFromLocalPart . '@' . $defaultMailFromDomain;
		return [
			'enabled' => '0',
			'public_pbx_host' => $defaultHost,
			'page_group' => '',
			'alert_recipients' => [],
			'system_notification_emails' => '',
			'mail_to' => '',
			'discord_webhook_url' => '',
			'discord_webhooks' => [],
			'generic_webhooks' => [],
			'nws_api_base_url' => 'https://api.weather.gov',
			'nws_zone' => '',
			'nws_zones' => [],
			'quiet_hours_enabled' => '1',
			'quiet_hours_start' => '21:00',
			'quiet_hours_end' => '06:00',
			'quiet_critical_events' => $this->getDefaultQuietCriticalEvents(),
			'mail_from_name' => 'SLS Mass Notification System',
			'mail_from_local_part' => $defaultMailFromLocalPart,
			'mail_from_domain' => $defaultMailFromDomain,
			'mail_from_addr' => $defaultMailFromAddr,
			'alert_email_subject' => 'Southland Servers Group PBX: EAS alert triggered - {{event}}',
			'alert_email_body' => "An EAS alert triggered the configured NWS recipients.\n\nSource Name: {{source_name}}\nTrigger Source: {{trigger_source}}\nEvent: {{event}}\nSeverity: {{severity}}\nMessage Type: {{message_type}}\nAudio: {{audio}}\nAlert ID: {{alert_id}}\nZone: {{zone}}\nTime: {{time}}",
			'test_email_subject' => 'Southland Servers Mass Notifications Server: NWS test triggered',
			'test_email_body' => "An NWS test was triggered.\n\nSource Name: {{source_name}}\nTrigger Source: {{trigger_source}}\nTrigger Extension: {{trigger_extension}}\nTrigger Name: {{trigger_name}}\nNWS Recipients: {{page_group}}\nAudio Sequence: {{audio_sequence}}\nTime: {{time}}",
			'opening_tone' => self::DEFAULT_ANNOUNCEMENT_OPENING_TONE,
			'closing_tone' => self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE,
			'nws_opening_tone' => self::DEFAULT_NWS_OPENING_TONE,
			'nws_closing_tone' => '',
			'email_html_enabled' => '1',
			'tts_max_seconds' => 30,
			'piper_bin' => self::PIPER_BIN,
			'piper_voice' => self::PIPER_VOICE,
			'nws_piper_voice' => self::PIPER_AMY_VOICE,
			'announcement_piper_voice' => self::PIPER_VOICE,
			'nws_tts_volume' => 25,
			'announcement_tts_volume' => 25,
			'announcement_cooldown_seconds' => self::ANNOUNCEMENT_COOLDOWN_SECONDS,
			'announcement_timeout_mode' => 'none',
			'announcement_timeout_seconds' => 300,
			'log_retention_days' => 90,
			'desktop_auth_key' => $this->generateDesktopAuthKey(),
			'desktop_clients' => [
				$this->defaultDesktopClient('SLS Desktop App'),
			],
			'ami' => [
				'username' => 'slsmassnotify',
				'password' => $this->generateApiKey(),
				'host' => '127.0.0.1',
				'port' => $this->detectAmiPort(),
			],
			'updates' => [
				'github_enabled' => '0',
				'repository' => 'vipgabe09267/SouthlandServers_Mass_Notify_server',
				'channel' => 'beta',
			],
			'control_api' => [
				'enabled' => '0',
				'api_key' => $this->generateApiKey(),
				'base_url' => 'https://' . $this->detectPbxHost() . '/api/sls-mass-notify',
				'ip_allowlist_enabled' => '0',
				'ip_allowlist' => '',
				'rate_limit_enabled' => '0',
				'rate_limit_per_minute' => 60,
				'audit_retention_days' => 30,
			],
			'setup' => [
				'completed' => '0',
				'beta_accepted' => '0',
				'agpl_accepted' => '0',
				'eula_accepted' => '0',
				'completed_at' => '',
			],
			'announcement_groups' => [],
			'scheduled_announcements' => [],
			'xweather' => [
				'enabled' => '0',
				'client_id' => '',
				'client_secret' => '',
				'location' => '',
				'radius_miles' => 25,
				'query_interval_minutes' => 5,
				'adaptive_free_tier' => '1',
				'adaptive_grace_minutes' => 60,
				'adaptive_nws_zone_id' => '',
				'tts_volume' => 25,
				'opening_tone' => self::DEFAULT_LIGHTNING_OPENING_TONE,
				'closing_tone' => '',
				'all_clear' => 'none',
				'quiet_hours_enabled' => '0',
				'quiet_hours_start' => '21:00',
				'quiet_hours_end' => '06:00',
				'recipients' => [],
				'groups' => [],
			],
			'sipnotify' => $this->getDefaultSipNotifySettings(),
			'sound_dir' => self::SOUNDS_DIR,
			'asterisk_sound_prefix' => self::ASTERISK_SOUND_PREFIX,
		];
	}

	private function getStatusSummary()
	{
		$status = $this->loadStatusData();

		return [
			'poll' => [
				'label' => _('NWS Polling'),
				'state' => $this->normalizeStatusState($status['last_poll_status'] ?? ''),
				'time' => $this->formatStatusTimestamp($status['last_poll_at'] ?? ''),
				'message' => $this->normalizeStatusMessage($status['last_poll_message'] ?? '', _('No poll has been recorded yet.')),
				'details' => $this->formatStatusTimestamp($status['last_poll_ok_at'] ?? '', _('Last successful poll: %s')),
			],
			'delivery' => [
				'label' => _('Alert Delivery'),
				'state' => $this->normalizeStatusState($status['last_delivery_status'] ?? ''),
				'time' => $this->formatStatusTimestamp($status['last_delivery_at'] ?? ''),
				'message' => $this->buildDeliveryMessage($status),
				'details' => $this->buildDeliveryDetails($status),
			],
			'fault' => [
				'label' => _('Fault Detection'),
				'state' => $this->normalizeFaultState($status['last_fault_at'] ?? '', $status['fault_email_sent_at'] ?? ''),
				'time' => $this->formatStatusTimestamp($status['last_fault_at'] ?? ''),
				'message' => $this->buildFaultMessage($status),
				'details' => $this->formatStatusTimestamp($status['fault_email_sent_at'] ?? '', _('Fault email sent: %s')),
			],
		];
	}

	private function getSupportedNwsEvents()
	{
		return [
			'Tornado Warning',
			'Tornado Watch',
			'Tornado Emergency',
			'Severe Thunderstorm Warning',
			'Severe Thunderstorm Watch',
			'Flash Flood Emergency',
			'Flash Flood Warning',
			'Flash Flood Watch',
			'Flood Warning',
			'Flood Watch',
			'Red Flag Warning',
			'Fire Weather Watch',
			'Winter Storm Warning',
			'Winter Storm Watch',
			'Ice Storm Warning',
			'High Wind Warning',
			'High Wind Watch',
			'Heat Advisory',
			'Excessive Heat Warning',
			'Extreme Heat Warning',
			'Extreme Heat Watch',
			'Dust Storm Warning',
			'Hurricane Warning',
			'Hurricane Watch',
			'Tropical Storm Warning',
			'Tropical Storm Watch',
			'Storm Surge Warning',
			'Tsunami Warning',
			'Earthquake Warning',
			'Civil Danger Warning',
			'Hazardous Materials Warning',
			'Nuclear Power Plant Warning',
			'Law Enforcement Warning',
			'Evacuation Warning',
			'Evacuation Immediate',
		];
	}

	private function getDefaultQuietCriticalEvents()
	{
		return [
			'Tornado Warning',
			'Tornado Emergency',
			'Flash Flood Emergency',
			'Flash Flood Warning',
			'Evacuation Warning',
			'Evacuation Immediate',
		];
	}

	private function persistPendingSettings(array $settings, $replaceSchedules = false)
	{
		$this->ensurePluginDataDir();
		$lock = $this->acquireSettingsLock();
		try {
			$currentPendingFingerprint = $this->settingsFileFingerprint(self::PENDING_SETTINGS_JSON);
			$expectedPendingFingerprint = $this->settingsReadFingerprints[self::PENDING_SETTINGS_JSON] ?? null;
			if ($expectedPendingFingerprint !== null && !hash_equals($expectedPendingFingerprint, $currentPendingFingerprint)) {
				throw new \RuntimeException(_('Another request changed the staged Mass Notifications settings. Reload this page and try again.'));
			}
			if ($currentPendingFingerprint === 'missing') {
				$currentActiveFingerprint = $this->settingsFileFingerprint(self::SETTINGS_JSON);
				$expectedActiveFingerprint = $this->settingsReadFingerprints[self::SETTINGS_JSON] ?? null;
				if ($expectedActiveFingerprint !== null && !hash_equals($expectedActiveFingerprint, $currentActiveFingerprint)) {
					throw new \RuntimeException(_('Another request changed the active Mass Notifications settings. Reload this page and try again.'));
				}
			}
			if (!$replaceSchedules) {
				if (is_readable(self::PENDING_SETTINGS_JSON)) {
					$latestSettings = $this->normalizeSettings($this->loadSettingsFile(self::PENDING_SETTINGS_JSON));
				} else {
					$latestSettings = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
				}
				$settings['scheduled_announcements'] = $latestSettings['scheduled_announcements'] ?? [];
			}
			$this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON, $this->normalizeSettings($settings), false);
			$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
		} finally {
			$this->releaseSettingsLock($lock);
		}
		if (function_exists('needreload')) {
			needreload();
		}
	}

	private function persistAppliedSettings(array $settings, $replaceSchedules = false, $clearPending = false)
	{
		$this->ensurePluginDataDir();
		$lock = $this->acquireSettingsLock();
		try {
			$currentFingerprint = $this->settingsFileFingerprint(self::SETTINGS_JSON);
			$expectedFingerprint = $this->settingsReadFingerprints[self::SETTINGS_JSON] ?? null;
			if ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, $currentFingerprint)) {
				throw new \RuntimeException(_('Another request changed the active Mass Notifications settings. Reload this page and try again.'));
			}
			if ($clearPending) {
				$currentPendingFingerprint = $this->settingsFileFingerprint(self::PENDING_SETTINGS_JSON);
				$expectedPendingFingerprint = $this->settingsReadFingerprints[self::PENDING_SETTINGS_JSON] ?? null;
				if ($expectedPendingFingerprint !== null && !hash_equals($expectedPendingFingerprint, $currentPendingFingerprint)) {
					throw new \RuntimeException(_('Another request changed the staged Mass Notifications settings. Reload this page and try again.'));
				}
			}
			if (!$replaceSchedules && is_readable(self::SETTINGS_JSON)) {
				$latestActive = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
				$settings['scheduled_announcements'] = $latestActive['scheduled_announcements'] ?? [];
			}
			$this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $this->normalizeSettings($settings), true);
			if ($clearPending && is_file(self::PENDING_SETTINGS_JSON) && !@unlink(self::PENDING_SETTINGS_JSON)) {
				throw new \RuntimeException(_('Changes were applied, but the staged settings file could not be removed safely.'));
			}
			$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
			if ($clearPending) {
				$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
			}
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	private function writeSettingsFileUnlocked($path, array $settings, $backupApplied)
	{
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException(_('Unable to encode Mass Notifications settings.'));
		}
		if ($backupApplied) {
			$this->backupAppliedSettings();
		}
		$tmpSettings = $path . '.tmp.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmpSettings, $json . "\n", LOCK_EX) === false) {
			@unlink($tmpSettings);
			throw new \RuntimeException(sprintf(_('Unable to write %s.'), $path));
		}
		$this->setPrivateOwnership($tmpSettings);
		if (!@rename($tmpSettings, $path)) {
			@unlink($tmpSettings);
			throw new \RuntimeException(sprintf(_('Unable to replace %s.'), $path));
		}
		$this->setPrivateOwnership($path);
		// A successful in-request write must never leave a normalized snapshot or
		// optimistic-concurrency fingerprint pointing at the previous contents.
		unset($this->normalizedSettingsCache[$path]);
		$this->rememberSettingsFingerprint($path);
	}

	private function acquireSettingsLock()
	{
		$handle = @fopen(self::SETTINGS_LOCK, 'c+');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			if (is_resource($handle)) {
				fclose($handle);
			}
			throw new \RuntimeException(_('Unable to lock the Mass Notifications configuration.'));
		}
		$this->setPrivateOwnership(self::SETTINGS_LOCK);
		return $handle;
	}

	private function releaseSettingsLock($handle)
	{
		if (is_resource($handle)) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private function backupAppliedSettings()
	{
		if (!is_readable(self::SETTINGS_JSON)) {
			return;
		}
		$current = (string)file_get_contents(self::SETTINGS_JSON);
		if ($current === '' || !is_array(json_decode($current, true))) {
			return;
		}
		$backupDir = self::PLUGIN_DATA_DIR . '/config-backups';
		try {
			$this->ensureOwnedDirectory($backupDir, 0750);
		} catch (\Throwable $exception) {
			return;
		}
		$backup = $backupDir . '/mass-notifications-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.config';
		if (@file_put_contents($backup, $current, LOCK_EX) !== false) {
			$this->setPrivateOwnership($backup);
		}
		$backups = glob($backupDir . '/mass-notifications-*.config') ?: [];
		usort($backups, static function ($left, $right) {
			return ((int)@filemtime($right)) <=> ((int)@filemtime($left));
		});
		foreach (array_slice($backups, 20) as $oldBackup) {
			@unlink($oldBackup);
		}
	}

	private function setOwnership($file)
	{
		$this->setPrivateOwnership($file);
	}

	private function setPrivateOwnership($file)
	{
		$file = (string)$file;
		if ($file === '' || $file[0] !== '/' || !is_executable('/usr/bin/python3')) {
			throw new \RuntimeException(_('Unable to secure a protected Mass Notifications file.'));
		}
		$program = <<<'PY'
import os
import pwd
import stat
import sys

path = sys.argv[1]
if not path.startswith('/') or '\x00' in path:
    raise SystemExit(2)
parts = [part for part in path.split('/') if part]
if not parts:
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, 'O_NOFOLLOW', 0)
directory_flags = flags | os.O_DIRECTORY
parent_fd = os.open('/', directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    try:
        metadata = os.fstat(file_fd)
        if not stat.S_ISREG(metadata.st_mode):
            raise SystemExit(3)
        account = pwd.getpwnam('asterisk')
        os.fchmod(file_fd, 0o640)
        os.fchown(file_fd, account.pw_uid, account.pw_gid)
        verified = os.fstat(file_fd)
        if stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid:
            raise SystemExit(4)
    finally:
        os.close(file_fd)
finally:
    os.close(parent_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('Unable to secure a protected Mass Notifications file without following symbolic links.'));
		}
	}

	private function ensurePrivateFile($file)
	{
		$file = (string)$file;
		if ($file === '' || $file[0] !== '/' || !is_executable('/usr/bin/python3')) {
			throw new \RuntimeException(_('Unable to create a protected Mass Notifications file.'));
		}
		$program = <<<'PY'
import os
import pwd
import stat
import sys

path = sys.argv[1]
if not path.startswith('/') or '\x00' in path:
    raise SystemExit(2)
parts = [part for part in path.split('/') if part]
if not parts:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
file_flags = os.O_RDWR | os.O_CLOEXEC | os.O_CREAT | getattr(os, 'O_NOFOLLOW', 0)
parent_fd = os.open('/', directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], file_flags, 0o640, dir_fd=parent_fd)
    try:
        metadata = os.fstat(file_fd)
        if not stat.S_ISREG(metadata.st_mode):
            raise SystemExit(3)
        account = pwd.getpwnam('asterisk')
        os.fchmod(file_fd, 0o640)
        os.fchown(file_fd, account.pw_uid, account.pw_gid)
    finally:
        os.close(file_fd)
finally:
    os.close(parent_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('Unable to create a protected Mass Notifications file without following symbolic links.'));
		}
	}

	private function ensureOwnedDirectory($directory, $mode)
	{
		$directory = (string)$directory;
		$mode = (int)$mode;
		if ($directory === '' || $directory[0] !== '/' || !in_array($mode, [0750, 0755], true) || !is_executable('/usr/bin/python3')) {
			throw new \RuntimeException(_('Unable to secure a Mass Notifications data directory.'));
		}
		$program = <<<'PY'
import errno
import os
import pwd
import stat
import sys

path = sys.argv[1]
mode = int(sys.argv[2], 8)
if not path.startswith('/') or '\x00' in path or mode not in (0o750, 0o755):
    raise SystemExit(2)
parts = [part for part in path.split('/') if part]
if not parts:
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
parent_fd = os.open('/', flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    try:
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    except FileNotFoundError:
        os.mkdir(parts[-1], mode, dir_fd=parent_fd)
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    try:
        metadata = os.fstat(directory_fd)
        if not stat.S_ISDIR(metadata.st_mode):
            raise SystemExit(3)
        account = pwd.getpwnam('asterisk')
        os.fchmod(directory_fd, mode)
        os.fchown(directory_fd, account.pw_uid, account.pw_gid)
        verified = os.fstat(directory_fd)
        if stat.S_IMODE(verified.st_mode) != mode or verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid:
            raise SystemExit(4)
    finally:
        os.close(directory_fd)
finally:
    os.close(parent_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg(sprintf('%o', $mode)) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('Unable to secure a Mass Notifications data directory without following symbolic links.'));
		}
	}

	private function secureManagedRuntimeTree($root, $profile)
	{
		$root = (string)$root;
		$profile = (string)$profile;
		if ($root === '' || $root[0] !== '/' || !in_array($profile, ['data', 'web'], true) || !is_executable('/usr/bin/python3')) {
			throw new \RuntimeException(_('Unable to secure a Mass Notifications runtime tree.'));
		}
		$program = <<<'PY'
import os
import pwd
import stat
import sys

root = sys.argv[1]
profile = sys.argv[2]
if not root.startswith('/') or '\x00' in root or profile not in ('data', 'web'):
    raise SystemExit(2)
parts = [part for part in root.split('/') if part]
if not parts:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
file_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, 'O_NOFOLLOW', 0)
root_fd = os.open('/', directory_flags)
try:
    for component in parts:
        next_fd = os.open(component, directory_flags, dir_fd=root_fd)
        os.close(root_fd)
        root_fd = next_fd
except BaseException:
    os.close(root_fd)
    raise

account = pwd.getpwnam('asterisk')
visited = 0

def directory_mode(relative):
    if profile == 'web':
        return 0o755
    if relative in ('', 'sipnotify', 'config-backups', 'piper'):
        return 0o750
    return 0o755

def file_mode(relative):
    if profile == 'web':
        return 0o644
    if relative.startswith('sounds/') or relative.startswith('piper/voices/'):
        return 0o644
    return 0o640

def secure_directory(directory_fd, relative=''):
    global visited
    for name in sorted(os.listdir(directory_fd)):
        if name in ('', '.', '..') or '/' in name or '\x00' in name:
            raise RuntimeError('invalid runtime-tree entry')
        visited += 1
        if visited > 25000:
            raise RuntimeError('runtime tree exceeds the entry limit')
        child_relative = relative + '/' + name if relative else name
        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        if stat.S_ISLNK(metadata.st_mode):
            continue
        if profile == 'data' and child_relative == 'piper/venv':
            # The compatibility venv is deliberately root-owned and contains a
            # root-owned wrapper link into the executable runtime. Web/Asterisk
            # delivery must neither traverse nor chown that trust boundary.
            # Accept only the exact non-writable root-owned directory created by
            # install/repair; anything writable or differently owned fails closed.
            if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) & 0o022:
                raise RuntimeError('unsafe Piper compatibility directory')
            continue
        if stat.S_ISDIR(metadata.st_mode):
            child_fd = os.open(name, directory_flags, dir_fd=directory_fd)
            try:
                secure_directory(child_fd, child_relative)
                os.fchown(child_fd, account.pw_uid, account.pw_gid)
                os.fchmod(child_fd, directory_mode(child_relative))
            finally:
                os.close(child_fd)
            continue
        if stat.S_ISREG(metadata.st_mode):
            child_fd = os.open(name, file_flags, dir_fd=directory_fd)
            try:
                verified = os.fstat(child_fd)
                if not stat.S_ISREG(verified.st_mode):
                    raise RuntimeError('runtime file changed type')
                os.fchown(child_fd, account.pw_uid, account.pw_gid)
                os.fchmod(child_fd, file_mode(child_relative))
            finally:
                os.close(child_fd)
            continue
        raise RuntimeError('runtime tree contains a special file')

try:
    secure_directory(root_fd)
    os.fchown(root_fd, account.pw_uid, account.pw_gid)
    os.fchmod(root_fd, directory_mode(''))
finally:
    os.close(root_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($profile) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('Unable to secure a Mass Notifications runtime tree without following symbolic links.'));
		}
	}

	private function ensurePluginDataDir()
	{
		$this->ensureOwnedDirectory(self::PLUGIN_DATA_DIR, 0750);
		$this->ensureOwnedDirectory(self::SOUNDS_DIR, 0755);
		$this->ensureOwnedDirectory(self::TONES_DIR, 0755);
		$this->ensureOwnedDirectory(self::TTS_DIR, 0755);
		$this->ensureOwnedDirectory(self::PIPER_DATA_DIR, 0750);
		$this->ensureOwnedDirectory(self::PIPER_VOICE_DIR, 0755);
		$this->ensureOwnedDirectory('/var/lib/asterisk/sounds/en', 0755);
		$this->ensureAsteriskSoundLink('/var/lib/asterisk/sounds/en/' . self::ASTERISK_SOUND_PREFIX);
		$this->ensureAsteriskSoundLink('/var/lib/asterisk/sounds/' . self::ASTERISK_SOUND_PREFIX);
		$this->ensureDefaultTones();
	}

	private function ensureSystemDependencies()
	{
		$required = [
			'/usr/bin/php',
			'/usr/bin/python3',
			'/usr/bin/sox',
			'/usr/bin/soxi',
			'/usr/bin/convert',
			'/usr/bin/identify',
			'/usr/bin/curl',
			'/usr/bin/wget',
			'/usr/bin/gpg',
			'/usr/bin/tar',
			'/usr/bin/timeout',
			'/usr/bin/flock',
			'/usr/bin/readlink',
			'/usr/bin/crontab',
			'/usr/bin/systemctl',
			'/bin/systemctl',
			'/usr/sbin/runuser',
			'/usr/sbin/asterisk',
			'/usr/sbin/fwconsole',
			'/usr/sbin/a2enconf',
			'/usr/sbin/a2disconf',
		];
		$missing = array_values(array_filter($required, static function ($path) {
			return !is_executable($path);
		}));
		$mbstringMissing = !extension_loaded('mbstring') || !function_exists('mb_strlen') || !function_exists('mb_substr');
		$posixMissing = !function_exists('posix_getpwnam');
		$opensslMissing = !extension_loaded('openssl') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt');
		$fontMissing = !is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
		if ((!empty($missing) || $mbstringMissing || $posixMissing || $opensslMissing || $fontMissing) && is_executable('/usr/bin/apt-get')) {
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update');
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y curl wget ca-certificates gnupg python3 python3-venv python3-pip sox imagemagick fonts-dejavu-core tar php-cli php-common php-mbstring cron util-linux coreutils apache2');
		}

		$missing = array_values(array_filter($required, static function ($path) {
			return !is_executable($path);
		}));
		$mbstringMissing = !extension_loaded('mbstring') || !function_exists('mb_strlen') || !function_exists('mb_substr');
		$posixMissing = !function_exists('posix_getpwnam');
		$opensslMissing = !extension_loaded('openssl') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt');
		$fontMissing = !is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
		if (!empty($missing) || $mbstringMissing || $posixMissing || $opensslMissing || $fontMissing) {
			$missingLabels = array_unique(array_merge(
				$missing,
				$mbstringMissing ? ['PHP mbstring'] : [],
				$posixMissing ? ['PHP POSIX'] : [],
				$opensslMissing ? ['PHP OpenSSL'] : [],
				$fontMissing ? ['DejaVu Sans Bold font'] : []
			));
			$message = 'Required runtime dependencies are missing: ' . implode(', ', $missingLabels);
			$this->updateStatusData([
				'last_fault_at' => date('c'),
				'last_fault_stage' => 'dependencies',
				'last_fault_message' => $message,
			]);
			throw new \RuntimeException(_($message));
		}
	}

	private function ensureAsteriskSoundLink($link)
	{
		$link = (string)$link;
		if ($link === '' || $link[0] !== '/' || !is_executable('/usr/bin/python3')) {
			throw new \RuntimeException(_('Unable to create the Asterisk sound link.'));
		}
		$program = <<<'PY'
import os
import stat
import sys

path = sys.argv[1]
target = sys.argv[2]
if not path.startswith('/') or '\x00' in path or not target.startswith('/') or '\x00' in target:
    raise SystemExit(2)
parts = [part for part in path.split('/') if part]
if not parts:
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
parent_fd = os.open('/', flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    name = parts[-1]
    try:
        existing = os.readlink(name, dir_fd=parent_fd)
    except FileNotFoundError:
        try:
            os.lstat(name, dir_fd=parent_fd)
        except FileNotFoundError:
            os.symlink(target, name, dir_fd=parent_fd)
            existing = os.readlink(name, dir_fd=parent_fd)
        else:
            raise SystemExit(3)
    except OSError:
        raise SystemExit(3)
    if existing != target:
        raise SystemExit(4)
finally:
    os.close(parent_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -c ' . escapeshellarg($program) . ' ' . escapeshellarg($link) . ' ' . escapeshellarg(self::SOUNDS_DIR) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('The Asterisk sound path exists but is not the expected Mass Notifications link. Remove or relocate the conflicting path, then run Repair Installation.'));
		}
	}

	private function ensureDefaultTones()
	{
		if (!is_executable('/usr/bin/sox')) {
			return;
		}
		$tones = [];
		foreach ($tones as $path => $synth) {
			if (is_file($path)) {
				continue;
			}
			$cmd = '/usr/bin/sox -n -r 8000 -c 1 -b 16 ' . escapeshellarg($path) . ' synth ' . $synth . ' 2>/dev/null';
			exec($cmd);
			if (is_file($path)) {
				@chmod($path, 0644);
				@chown($path, 'asterisk');
				@chgrp($path, 'asterisk');
			}
		}
	}

	private function getTestCooldownState()
	{
		$lastRun = 0;
		if (is_readable(self::TEST_COOLDOWN_FILE)) {
			$lastRun = (int)trim((string)file_get_contents(self::TEST_COOLDOWN_FILE));
		}

		$remaining = max(0, self::TEST_COOLDOWN_SECONDS - (time() - $lastRun));

		return [
			'last_run' => $lastRun,
			'remaining' => $remaining,
		];
	}

	public function getLightningTestCooldownState()
	{
		$lastRun = 0;
		if (is_readable(self::LIGHTNING_TEST_COOLDOWN_FILE)) {
			$lastRun = (int)trim((string)file_get_contents(self::LIGHTNING_TEST_COOLDOWN_FILE));
		}

		return [
			'last_run' => $lastRun,
			'remaining' => max(0, self::TEST_COOLDOWN_SECONDS - (time() - $lastRun)),
		];
	}

	private function getAnnouncementCooldownState()
	{
		$lastRun = 0;
		if (is_readable(self::ANNOUNCEMENT_COOLDOWN_FILE)) {
			$lastRun = (int)trim((string)file_get_contents(self::ANNOUNCEMENT_COOLDOWN_FILE));
		}

		$duration = $this->normalizeAnnouncementCooldownSeconds($this->getActiveSettings()['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
		$remaining = max(0, $duration - (time() - $lastRun));

		return [
			'last_run' => $lastRun,
			'remaining' => $remaining,
			'duration' => $duration,
		];
	}

	private function setTestCooldown()
	{
		file_put_contents(self::TEST_COOLDOWN_FILE, (string)time() . "\n", LOCK_EX);
		$this->setOwnership(self::TEST_COOLDOWN_FILE);
	}

	private function setLightningTestCooldown()
	{
		file_put_contents(self::LIGHTNING_TEST_COOLDOWN_FILE, (string)time() . "\n", LOCK_EX);
		$this->setOwnership(self::LIGHTNING_TEST_COOLDOWN_FILE);
	}

	private function claimLightningTestCooldown()
	{
		$this->ensurePluginDataDir();
		$handle = @fopen(self::LIGHTNING_TEST_COOLDOWN_FILE, 'c+');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			if (is_resource($handle)) {
				fclose($handle);
			}
			return ['claimed' => false, 'remaining' => 0];
		}
		$now = time();
		rewind($handle);
		$lastRun = (int)trim((string)stream_get_contents($handle));
		$remaining = max(0, self::TEST_COOLDOWN_SECONDS - ($now - $lastRun));
		if ($remaining === 0) {
			ftruncate($handle, 0);
			rewind($handle);
			if (fwrite($handle, (string)$now . "\n") === false || !fflush($handle)) {
				flock($handle, LOCK_UN);
				fclose($handle);
				return ['claimed' => false, 'remaining' => 0];
			}
		}
		flock($handle, LOCK_UN);
		fclose($handle);
		$this->setOwnership(self::LIGHTNING_TEST_COOLDOWN_FILE);
		return ['claimed' => $remaining === 0, 'remaining' => $remaining];
	}

	private function setAnnouncementCooldown()
	{
		file_put_contents(self::ANNOUNCEMENT_COOLDOWN_FILE, (string)time() . "\n", LOCK_EX);
		$this->setOwnership(self::ANNOUNCEMENT_COOLDOWN_FILE);
	}

	private function getRegisteredPjsipExtensions()
	{
		if (is_array($this->registeredPjsipExtensionsCache)) {
			return $this->registeredPjsipExtensionsCache;
		}
		if (is_executable(self::VISUAL_PUSH_SCRIPT)) {
			$output = [];
			$exitCode = 0;
			exec('/usr/bin/timeout 15 /usr/bin/python3 ' . escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --list-endpoints-json 2>/dev/null', $output, $exitCode);
			if ($exitCode === 0) {
				$inventory = json_decode(implode("\n", $output), true);
				if (is_array($inventory)) {
					$registered = [];
					foreach (array_keys($inventory) as $extension) {
						$extension = preg_replace('/[^0-9]/', '', (string)$extension);
						if ($extension !== '') {
							$registered[$extension] = $extension;
						}
					}
					if (!empty($registered)) {
						$this->registeredPjsipExtensionsCache = array_values($registered);
						return $this->registeredPjsipExtensionsCache;
					}
				}
			}
		}

		$output = [];
		exec("asterisk -rx 'pjsip show contacts' 2>/dev/null", $output);
		$registered = [];
		foreach ($output as $line) {
			if (!preg_match('/Contact:\s+([0-9]+)\/.*\s(Avail|Available|NonQual|Reachable|Unknown)\s+[-0-9na.]+$/i', $line, $matches)) {
				continue;
			}
			$registered[$matches[1]] = $matches[1];
		}
		$this->registeredPjsipExtensionsCache = array_values($registered);
		return $this->registeredPjsipExtensionsCache;
	}

	private function getExtensionNameMap()
	{
		if (is_array($this->extensionNameMapCache)) {
			return $this->extensionNameMapCache;
		}
		$stmt = $this->FreePBX->Database()->prepare(
			"SELECT d.id AS extension, COALESCE(NULLIF(u.name, ''), d.description, '') AS name
			FROM devices d
			LEFT JOIN users u ON u.extension = d.id
			WHERE d.tech = 'pjsip'"
		);
		$stmt->execute();

		$names = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$extension = preg_replace('/[^0-9]/', '', (string)($row['extension'] ?? ''));
			if ($extension !== '') {
				$names[$extension] = trim((string)($row['name'] ?? ''));
			}
		}
		$this->extensionNameMapCache = $names;
		return $this->extensionNameMapCache;
	}

	private function getActiveSettings()
	{
		$fingerprint = $this->settingsCacheFingerprint(self::SETTINGS_JSON);
		$cached = $this->normalizedSettingsCache[self::SETTINGS_JSON] ?? null;
		if (is_array($cached)
			&& hash_equals((string)($cached['fingerprint'] ?? ''), $fingerprint)
			&& is_array($cached['settings'] ?? null)) {
			$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
			return $cached['settings'];
		}
		$settings = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
		$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
		$this->normalizedSettingsCache[self::SETTINGS_JSON] = [
			'fingerprint' => $fingerprint,
			'settings' => $settings,
		];
		return $settings;
	}

	private function getPendingSettings()
	{
		$fingerprint = $this->settingsCacheFingerprint(self::PENDING_SETTINGS_JSON);
		$cached = $this->normalizedSettingsCache[self::PENDING_SETTINGS_JSON] ?? null;
		if (is_array($cached)
			&& hash_equals((string)($cached['fingerprint'] ?? ''), $fingerprint)
			&& array_key_exists('settings', $cached)) {
			$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
			return $cached['settings'];
		}
		$settings = null;
		if (!is_readable(self::PENDING_SETTINGS_JSON) && is_readable(self::LEGACY_PENDING_SETTINGS_JSON)) {
			$settings = $this->normalizeSettings($this->loadSettingsFile(self::LEGACY_PENDING_SETTINGS_JSON));
		} elseif (!is_readable(self::PENDING_SETTINGS_JSON) && is_readable(self::LEGACY_OLD_PENDING_SETTINGS_JSON)) {
			$settings = $this->normalizeSettings($this->loadSettingsFile(self::LEGACY_OLD_PENDING_SETTINGS_JSON));
		} elseif (is_readable(self::PENDING_SETTINGS_JSON)) {
			$settings = $this->normalizeSettings($this->loadSettingsFile(self::PENDING_SETTINGS_JSON));
		}
		$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
		$this->normalizedSettingsCache[self::PENDING_SETTINGS_JSON] = [
			'fingerprint' => $fingerprint,
			'settings' => $settings,
		];
		return $settings;
	}

	private function settingsCacheFingerprint($path)
	{
		$candidates = [$path];
		if ($path === self::SETTINGS_JSON) {
			$candidates[] = self::LEGACY_SETTINGS_JSON;
			$candidates[] = self::LEGACY_OLD_SETTINGS_JSON;
		} elseif ($path === self::PENDING_SETTINGS_JSON) {
			$candidates[] = self::LEGACY_PENDING_SETTINGS_JSON;
			$candidates[] = self::LEGACY_OLD_PENDING_SETTINGS_JSON;
		}
		$parts = [];
		foreach ($candidates as $candidate) {
			$parts[] = $candidate . ':' . $this->settingsFileFingerprint($candidate);
		}
		return hash('sha256', implode("\n", $parts));
	}

	private function settingsFileFingerprint($path)
	{
		if (!is_file($path) || !is_readable($path)) {
			return 'missing';
		}
		$fingerprint = @hash_file('sha256', $path);
		return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : 'unreadable';
	}

	private function rememberSettingsFingerprint($path)
	{
		$this->settingsReadFingerprints[$path] = $this->settingsFileFingerprint($path);
	}

	private function loadSettingsFile($path)
	{
		$settings = $this->getDefaultSettings();
		if (!is_readable($path) && $path === self::SETTINGS_JSON && is_readable(self::LEGACY_SETTINGS_JSON)) {
			$path = self::LEGACY_SETTINGS_JSON;
		}
		if (!is_readable($path) && $path === self::SETTINGS_JSON && is_readable(self::LEGACY_OLD_SETTINGS_JSON)) {
			$path = self::LEGACY_OLD_SETTINGS_JSON;
		}
		if (is_readable($path)) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (!is_array($decoded)) {
				throw new \RuntimeException(sprintf(_('Mass Notifications config is invalid JSON: %s.'), $path));
			}
			if (!array_key_exists('nws_opening_tone', $decoded)) {
				$settings['_legacy_nws_opening_tone'] = (string)($decoded['opening_tone'] ?? self::DEFAULT_NWS_OPENING_TONE);
			}
			if (!array_key_exists('nws_closing_tone', $decoded)) {
				$settings['_legacy_nws_closing_tone'] = (string)($decoded['closing_tone'] ?? '');
			}
			if (!array_key_exists('mail_from_domain', $decoded)) {
				$legacyMailFrom = trim((string)($decoded['mail_from_addr'] ?? ''));
				if (filter_var($legacyMailFrom, FILTER_VALIDATE_EMAIL)) {
					$legacyDomain = substr($legacyMailFrom, strrpos($legacyMailFrom, '@') + 1);
					$legacyDomain = $this->normalizeEmailSenderDomain($legacyDomain);
					if ($legacyDomain !== '') {
						$settings['mail_from_domain'] = $legacyDomain;
					}
				}
			}
			if (!array_key_exists('system_notification_emails', $decoded)) {
				// Older releases used mail_to for live service alerts. Preserve those
				// routes during the one-time migration while leaving the new
				// system/error recipient list opt-in.
				$legacyMailRecipients = (string)($decoded['mail_to'] ?? '');
				$settings['system_notification_emails'] = '';
				$settings['_legacy_live_email_recipients'] = $legacyMailRecipients;
			}
			$settings = array_replace($settings, $decoded);
		}
		return $settings;
	}

	private function normalizeSettings(array $settings)
	{
		$hasSystemNotificationEmails = array_key_exists('system_notification_emails', $settings);
		$legacyLiveEmailRecipients = $this->normalizeEmailRecipientList($settings['_legacy_live_email_recipients'] ?? []);
		if (!$hasSystemNotificationEmails) {
			$legacyLiveEmailRecipients = $this->mergeServiceEmailRecipients(
				$legacyLiveEmailRecipients,
				$settings['mail_to'] ?? ''
			);
		}
		unset($settings['_legacy_live_email_recipients']);
		$legacyLightningDesktopBroadcast = is_array($settings['xweather'] ?? null)
			&& !array_key_exists('groups', $settings['xweather']);
		$settings['enabled'] = $settings['enabled'] === '0' ? '0' : '1';
		$settings['page_group'] = '';
		$configuredHost = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? ''));
		if ($configuredHost === '' && is_array($settings['sipnotify'] ?? null)) {
			$configuredHost = $this->normalizePbxHost((string)($settings['sipnotify']['pbx_host'] ?? ''));
		}
		$settings['public_pbx_host'] = $configuredHost ?: $this->detectPbxHost();
		$systemNotificationEmails = $hasSystemNotificationEmails
			? (string)$settings['system_notification_emails']
			: '';
		$settings['system_notification_emails'] = $this->normalizeEmails($systemNotificationEmails);
		// Compatibility alias for older installed helpers. Weather and Lightning
		// delivery selects addresses from the matching zone/area configuration.
		$settings['mail_to'] = $settings['system_notification_emails'];
		$discordWebhooks = is_array($settings['discord_webhooks'] ?? null) ? $settings['discord_webhooks'] : [];
		if (empty($discordWebhooks) && trim((string)($settings['discord_webhook_url'] ?? '')) !== '') {
			$discordWebhooks = [[
				'name' => 'Primary Discord',
				'url' => (string)$settings['discord_webhook_url'],
				'enabled' => '1',
			]];
		}
		$settings['discord_webhooks'] = $this->normalizeWebhookDestinations($discordWebhooks, 'discord');
		$settings['generic_webhooks'] = $this->normalizeWebhookDestinations($settings['generic_webhooks'] ?? [], 'generic');
		$settings['discord_webhook_url'] = $this->firstEnabledWebhookUrl($settings['discord_webhooks']);
		$settings['nws_api_base_url'] = $this->normalizeNwsApiBaseUrl((string)($settings['nws_api_base_url'] ?? 'https://api.weather.gov')) ?: 'https://api.weather.gov';
		$settings['nws_zone'] = $this->normalizeNwsZone((string)($settings['nws_zone'] ?? ''));
		$settings['alert_recipients'] = $this->normalizeRecipientExtensions($settings['alert_recipients'] ?? $this->getDefaultSettings()['alert_recipients']);
		$settings['nws_zones'] = $this->normalizeNwsZoneGroups($settings['nws_zones'] ?? [], $settings['nws_zone'], $settings['alert_recipients']);
		if (!empty($legacyLiveEmailRecipients)) {
			foreach ($settings['nws_zones'] as $zoneIndex => $zoneGroup) {
				$settings['nws_zones'][$zoneIndex]['email_recipients'] = $this->mergeServiceEmailRecipients(
					$zoneGroup['email_recipients'] ?? [],
					$legacyLiveEmailRecipients
				);
			}
		}
		if (!empty($settings['nws_zones'])) {
			$settings['nws_zone'] = (string)$settings['nws_zones'][0]['zone'];
			$settings['alert_recipients'] = (array)$settings['nws_zones'][0]['extensions'];
		}
		$settings['quiet_hours_enabled'] = ($settings['quiet_hours_enabled'] ?? '0') === '1' ? '1' : '0';
		$settings['quiet_hours_start'] = $this->normalizeHour((string)($settings['quiet_hours_start'] ?? ''), $this->getDefaultSettings()['quiet_hours_start']);
		$settings['quiet_hours_end'] = $this->normalizeHour((string)($settings['quiet_hours_end'] ?? ''), $this->getDefaultSettings()['quiet_hours_end']);
		$settings['quiet_critical_events'] = $this->normalizeCriticalEvents($settings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents());
		$mailFromName = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($settings['mail_from_name'] ?? '')));
		$settings['mail_from_name'] = $mailFromName !== '' ? substr($mailFromName, 0, 80) : $this->getDefaultSettings()['mail_from_name'];
		$mailFromLocalPart = $this->normalizeEmailSenderLocalPart((string)($settings['mail_from_local_part'] ?? ''));
		if ($mailFromLocalPart === '') {
			$legacyMailFrom = trim((string)($settings['mail_from_addr'] ?? ''));
			if (filter_var($legacyMailFrom, FILTER_VALIDATE_EMAIL)) {
				$mailFromLocalPart = $this->normalizeEmailSenderLocalPart(substr($legacyMailFrom, 0, strrpos($legacyMailFrom, '@')));
			}
		}
		$settings['mail_from_local_part'] = $mailFromLocalPart ?: 'no-reply';
		$mailFromDomain = $this->normalizeEmailSenderDomain((string)($settings['mail_from_domain'] ?? ''));
		if ($mailFromDomain === '') {
			$legacyMailFrom = trim((string)($settings['mail_from_addr'] ?? ''));
			if (filter_var($legacyMailFrom, FILTER_VALIDATE_EMAIL)) {
				$mailFromDomain = $this->normalizeEmailSenderDomain(substr($legacyMailFrom, strrpos($legacyMailFrom, '@') + 1));
			}
		}
		if ($mailFromDomain === '') {
			$mailFromDomain = $this->getDefaultSettings()['mail_from_domain'];
		}
		$settings['mail_from_domain'] = $mailFromDomain;
		$settings['mail_from_addr'] = $settings['mail_from_local_part'] . '@' . $mailFromDomain;
		$settings['alert_email_subject'] = trim((string)$settings['alert_email_subject']);
		$settings['alert_email_body'] = trim((string)$settings['alert_email_body']);
		$settings['test_email_subject'] = trim((string)$settings['test_email_subject']);
		$settings['test_email_body'] = trim((string)$settings['test_email_body']);
		$settings['alert_email_body'] = str_replace("Source Extension: {{source_extension}}\n", '', $settings['alert_email_body']);
		$settings['alert_email_body'] = str_replace("Source Extension: {{source_extension}}\r\n", '', $settings['alert_email_body']);
		$settings['test_email_body'] = str_replace("Source Extension: {{source_extension}}\n", '', $settings['test_email_body']);
		$settings['test_email_body'] = str_replace("Source Extension: {{source_extension}}\r\n", '', $settings['test_email_body']);
		$settings['alert_email_body'] = str_replace('An EAS alert triggered the paging group {{page_group}}.', 'An EAS alert triggered the configured NWS recipients.', $settings['alert_email_body']);
		$settings['test_email_subject'] = str_replace('EAS paging test triggered', 'NWS test triggered', $settings['test_email_subject']);
		$settings['test_email_body'] = str_replace('An EAS paging test was triggered.', 'An NWS test was triggered.', $settings['test_email_body']);
		$settings['test_email_body'] = str_replace('Paging Group: {{page_group}}', 'NWS Recipients: {{page_group}}', $settings['test_email_body']);
		$availableTones = array_fill_keys($this->getAvailableTones(), true);
		$legacyNwsOpeningTone = array_key_exists('_legacy_nws_opening_tone', $settings)
			? (string)$settings['_legacy_nws_opening_tone']
			: (string)($settings['nws_opening_tone'] ?? self::DEFAULT_NWS_OPENING_TONE);
		$legacyNwsClosingTone = array_key_exists('_legacy_nws_closing_tone', $settings)
			? (string)$settings['_legacy_nws_closing_tone']
			: (string)($settings['nws_closing_tone'] ?? '');
		$settings['opening_tone'] = $this->normalizeToneName((string)(array_key_exists('_legacy_nws_opening_tone', $settings) ? self::DEFAULT_ANNOUNCEMENT_OPENING_TONE : ($settings['opening_tone'] ?? self::DEFAULT_ANNOUNCEMENT_OPENING_TONE)));
		$settings['closing_tone'] = $this->normalizeToneName((string)(array_key_exists('_legacy_nws_closing_tone', $settings) ? self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE : ($settings['closing_tone'] ?? self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE)));
		$settings['nws_opening_tone'] = $this->normalizeToneName($legacyNwsOpeningTone);
		$settings['nws_closing_tone'] = $this->normalizeToneName($legacyNwsClosingTone);
		if ($settings['opening_tone'] !== '' && !isset($availableTones[$settings['opening_tone']])) {
			$settings['opening_tone'] = self::DEFAULT_ANNOUNCEMENT_OPENING_TONE;
		}
		if ($settings['closing_tone'] !== '' && !isset($availableTones[$settings['closing_tone']])) {
			$settings['closing_tone'] = self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE;
		}
		if ($settings['nws_opening_tone'] !== '' && !isset($availableTones[$settings['nws_opening_tone']])) {
			$settings['nws_opening_tone'] = self::DEFAULT_NWS_OPENING_TONE;
		}
		if ($settings['nws_closing_tone'] !== '' && !isset($availableTones[$settings['nws_closing_tone']])) {
			$settings['nws_closing_tone'] = '';
		}
		unset($settings['_legacy_nws_opening_tone'], $settings['_legacy_nws_closing_tone']);
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($settings['tts_max_seconds'] ?? 30);
		$settings['email_html_enabled'] = '1';
		$settings['piper_bin'] = self::PIPER_BIN;
		$voices = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		$nwsVoice = (string)($settings['nws_piper_voice'] ?? self::PIPER_AMY_VOICE);
		$announcementVoice = (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE);
		$settings['nws_piper_voice'] = isset($voices[$nwsVoice]) ? $nwsVoice : (isset($voices[self::PIPER_AMY_VOICE]) ? self::PIPER_AMY_VOICE : self::PIPER_VOICE);
		$settings['announcement_piper_voice'] = isset($voices[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($settings['nws_tts_volume'] ?? 25, 25);
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($settings['announcement_tts_volume'] ?? 25, 25);
		$settings['announcement_cooldown_seconds'] = $this->normalizeAnnouncementCooldownSeconds($settings['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
		$settings['announcement_timeout_mode'] = $this->normalizeAnnouncementTimeoutMode($settings['announcement_timeout_mode'] ?? 'none');
		$settings['announcement_timeout_seconds'] = $this->normalizeAnnouncementTimeoutSeconds($settings['announcement_timeout_seconds'] ?? 300);
		$settings['log_retention_days'] = $this->normalizeRetentionDays($settings['log_retention_days'] ?? 90);
		unset($settings['desktop_api_token']);
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$settings['ami'] = [
			'username' => $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami'),
			// Never synthesize a different AMI secret while merely reading an existing
			// config. A transient value would configure Asterisk differently from the
			// protected file and make runtime authentication fail unpredictably.
			'password' => $this->normalizeEndpointPassword($ami['password'] ?? ''),
			'host' => '127.0.0.1',
			'port' => $this->normalizeInt($ami['port'] ?? $this->detectAmiPort(), 1, 65535, $this->detectAmiPort()),
		];
		$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
		$settings['updates'] = [
			'github_enabled' => empty($updates['github_enabled']) ? '0' : '1',
			'repository' => 'vipgabe09267/SouthlandServers_Mass_Notify_server',
			'channel' => 'beta',
		];
		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		$apiKey = trim((string)($control['api_key'] ?? ''));
		if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/', $apiKey)) {
			$apiKey = $this->generateApiKey();
		}
		$settings['control_api'] = [
			'enabled' => empty($control['enabled']) ? '0' : '1',
			'api_key' => $apiKey,
			'base_url' => $this->getControlApiUrl($settings),
			'ip_allowlist_enabled' => empty($control['ip_allowlist_enabled']) ? '0' : '1',
			'ip_allowlist' => $this->normalizeIpAllowlist((string)($control['ip_allowlist'] ?? '')),
			'rate_limit_enabled' => empty($control['rate_limit_enabled']) ? '0' : '1',
			'rate_limit_per_minute' => $this->normalizeInt($control['rate_limit_per_minute'] ?? 60, 1, 600, 60),
			'audit_retention_days' => 30,
		];
		$settings['desktop_auth_key'] = $this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? '');
		$desktopClientSource = array_key_exists('desktop_clients', $settings) ? $settings['desktop_clients'] : [$this->defaultDesktopClient('SLS Desktop App')];
		$settings['desktop_clients'] = $this->normalizeDesktopClients($desktopClientSource, $settings);
		$settings['announcement_groups'] = $this->normalizeAnnouncementGroups($settings['announcement_groups'] ?? []);
		$settings['scheduled_announcements'] = $this->normalizeScheduledAnnouncements($settings['scheduled_announcements'] ?? []);
		$settings['xweather'] = $this->normalizeXweatherSettings($settings['xweather'] ?? [], $settings['nws_tts_volume'] ?? 25);
		if (!empty($legacyLiveEmailRecipients)) {
			foreach ($settings['xweather']['groups'] as $groupIndex => $group) {
				$settings['xweather']['groups'][$groupIndex]['email_recipients'] = $this->mergeServiceEmailRecipients(
					$group['email_recipients'] ?? [],
					$legacyLiveEmailRecipients
				);
			}
		}
		if ($legacyLightningDesktopBroadcast && !empty($settings['xweather']['groups'][0])) {
			$legacyDesktopTargets = [];
			foreach ((array)$settings['desktop_clients'] as $desktopClient) {
				if (is_array($desktopClient) && !empty($desktopClient['enabled'])) {
					$username = $this->normalizeDesktopUsername($desktopClient['username'] ?? '');
					if ($username !== '') {
						$legacyDesktopTargets[$username] = $username;
					}
				}
			}
			$settings['xweather']['groups'][0]['desktop_clients'] = array_values($legacyDesktopTargets);
		}
		$setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
		$settings['setup'] = [
			'completed' => empty($setup['completed']) ? '0' : '1',
			'beta_accepted' => empty($setup['beta_accepted']) ? '0' : '1',
			'agpl_accepted' => empty($setup['agpl_accepted']) ? '0' : '1',
			'eula_accepted' => empty($setup['eula_accepted']) ? '0' : '1',
			'completed_at' => trim((string)($setup['completed_at'] ?? '')),
		];
		unset($settings['sound_map'], $settings['test_sound_pool']);
		$sipnotify = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
		$sipnotify['pbx_host'] = $settings['public_pbx_host'];
		$settings['sipnotify'] = $this->normalizeSipNotifySettings($sipnotify);
		$settings['control_api']['base_url'] = $this->getControlApiUrl($settings);
		return $settings;
	}

	private function getDefaultSipNotifySettings()
	{
		$host = $this->detectPbxHost();
		return [
			'pbx_host' => $host,
			'base_url' => 'https://' . $host . '/api/sipnotify',
			'media_scheme' => 'http',
			'media_base_url' => 'http://' . $host . '/sls_mass_notify',
			'format_overrides' => [],
		];
	}

	private function normalizeAnnouncementCooldownSeconds($value)
	{
		$seconds = (int)$value;
		if ($seconds < self::MIN_ANNOUNCEMENT_COOLDOWN_SECONDS) {
			$seconds = self::ANNOUNCEMENT_COOLDOWN_SECONDS;
		}
		return min(self::MAX_ANNOUNCEMENT_COOLDOWN_SECONDS, max(self::MIN_ANNOUNCEMENT_COOLDOWN_SECONDS, $seconds));
	}

	private function normalizeAnnouncementTimeoutMode($value)
	{
		$value = strtolower(trim((string)$value));
		return in_array($value, ['none', 'audio', 'custom'], true) ? $value : 'none';
	}

	private function normalizeAnnouncementTimeoutSeconds($value)
	{
		$seconds = (int)$value;
		if ($seconds < 1) {
			$seconds = 300;
		}
		return min(self::MAX_ANNOUNCEMENT_TIMEOUT_SECONDS, max(1, $seconds));
	}

	private function normalizeScheduleRecurrenceMode($value)
	{
		$value = strtolower(trim((string)$value));
		return in_array($value, ['none', 'every_7_days', 'every_14_days'], true) ? $value : 'none';
	}

	private function scheduleRecurrenceIntervalDays($mode)
	{
		$mode = $this->normalizeScheduleRecurrenceMode($mode);
		if ($mode === 'every_7_days') {
			return 7;
		}
		if ($mode === 'every_14_days') {
			return 14;
		}
		return 0;
	}

	private function buildScheduledOccurrences($scheduleId, array $occurrenceInputs, $recurrenceMode, \DateTimeZone $timezone, array $existingRunTimes, $minimumRunAt, $maximumRunAt)
	{
		$recurrenceMode = $this->normalizeScheduleRecurrenceMode($recurrenceMode);
		$intervalDays = $this->scheduleRecurrenceIntervalDays($recurrenceMode);
		$minimumRunAt = (int)$minimumRunAt;
		$maximumRunAt = (int)$maximumRunAt;
		$errors = [];
		$occurrences = [];
		$recurrence = ['mode' => $recurrenceMode, 'starts_at_local' => ''];

		if ($intervalDays > 0 && count($occurrenceInputs) !== 1) {
			$errors[] = _('A repeating schedule must have exactly one starting date and time.');
		}
		if ($intervalDays === 0 && count($occurrenceInputs) > self::MAX_SCHEDULE_OCCURRENCES) {
			$errors[] = sprintf(_('A schedule is limited to %d calendar dates.'), self::MAX_SCHEDULE_OCCURRENCES);
		}

		$inputs = array_slice($occurrenceInputs, 0, $intervalDays > 0 ? 1 : self::MAX_SCHEDULE_OCCURRENCES);
		if ($intervalDays > 0 && isset($inputs[0])) {
			$start = str_replace(' ', 'T', trim((string)$inputs[0]));
			$calendarCursor = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $start, new \DateTimeZone('UTC'));
			$calendarErrors = \DateTimeImmutable::getLastErrors();
			if (!$calendarCursor instanceof \DateTimeImmutable
				|| (is_array($calendarErrors) && (((int)($calendarErrors['warning_count'] ?? 0)) > 0 || ((int)($calendarErrors['error_count'] ?? 0)) > 0))
				|| $calendarCursor->format('Y-m-d\TH:i') !== $start) {
				$errors[] = _('Enter a valid starting date and time for the repeating schedule.');
				$inputs = [];
			} else {
				$recurrence['starts_at_local'] = $start;
				$inputs = [];
				for ($index = 0; $index < self::MAX_SCHEDULE_OCCURRENCES; $index++) {
					$localValue = $calendarCursor->format('Y-m-d\TH:i');
					$resolved = $this->resolveScheduleLocalDateTime($localValue, $timezone);
					if (empty($resolved['success'])) {
						$errors[] = sprintf(
							_('The repeating schedule reaches an unsafe daylight-saving time: %s'),
							(string)($resolved['message'] ?? _('invalid local time'))
						);
						break;
					}
					if ((int)$resolved['timestamp'] > $maximumRunAt) {
						break;
					}
					$inputs[] = $localValue;
					$calendarCursor = $calendarCursor->modify('+' . $intervalDays . ' days');
				}
			}
		}

		$seenRunTimes = [];
		foreach ($inputs as $occurrenceInput) {
			$resolved = $this->resolveScheduleLocalDateTime((string)$occurrenceInput, $timezone);
			if (empty($resolved['success'])) {
				$errors[] = (string)($resolved['message'] ?? _('A scheduled date or time is invalid.'));
				continue;
			}
			$timestamp = (int)$resolved['timestamp'];
			$runAtUtc = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
			if ($timestamp < $minimumRunAt && !isset($existingRunTimes[$runAtUtc])) {
				$errors[] = _('Scheduled dates and times must be current or in the future.');
				continue;
			}
			if ($timestamp > $maximumRunAt) {
				$errors[] = sprintf(_('Scheduled dates cannot be more than %d years in the future.'), self::MAX_SCHEDULE_YEARS);
				continue;
			}
			if (isset($seenRunTimes[$runAtUtc])) {
				continue;
			}
			$seenRunTimes[$runAtUtc] = true;
			$occurrences[] = [
				'id' => 'occ_' . substr(hash('sha256', $scheduleId . '|' . $runAtUtc), 0, 20),
				'local_datetime' => (string)$resolved['local_datetime'],
				'run_at_utc' => $runAtUtc,
			];
		}
		if (empty($occurrences)) {
			$errors[] = $intervalDays > 0
				? _('Add a valid future starting date and time for the repeating schedule.')
				: _('Add at least one valid calendar date and time.');
		}
		usort($occurrences, static function ($left, $right) {
			return strcmp((string)$left['run_at_utc'], (string)$right['run_at_utc']);
		});
		return [
			'occurrences' => $occurrences,
			'recurrence' => $recurrence,
			'errors' => array_values(array_unique($errors)),
		];
	}

	private function scheduleRecurrenceMatchesOccurrences($mode, $startsAtLocal, \DateTimeZone $timezone, array $occurrences)
	{
		$intervalDays = $this->scheduleRecurrenceIntervalDays($mode);
		$startsAtLocal = str_replace(' ', 'T', trim((string)$startsAtLocal));
		if ($intervalDays < 1 || empty($occurrences)) {
			return false;
		}
		$cursor = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsAtLocal, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$cursor instanceof \DateTimeImmutable
			|| (is_array($errors) && (((int)($errors['warning_count'] ?? 0)) > 0 || ((int)($errors['error_count'] ?? 0)) > 0))
			|| $cursor->format('Y-m-d\TH:i') !== $startsAtLocal) {
			return false;
		}
		foreach ($occurrences as $occurrence) {
			if (!is_array($occurrence)) {
				return false;
			}
			$resolved = $this->resolveScheduleLocalDateTime($cursor->format('Y-m-d\TH:i'), $timezone);
			$actualTimestamp = $this->parseScheduleUtcTimestamp((string)($occurrence['run_at_utc'] ?? ''));
			if (empty($resolved['success']) || $actualTimestamp === false || (int)$resolved['timestamp'] !== (int)$actualTimestamp) {
				return false;
			}
			$cursor = $cursor->modify('+' . $intervalDays . ' days');
		}
		return true;
	}

	private function validateScheduledAnnouncementRecurrences(array $schedules)
	{
		$errors = [];
		foreach ($schedules as $schedule) {
			if (!is_array($schedule) || !array_key_exists('recurrence', $schedule)) {
				continue;
			}
			$recurrence = $schedule['recurrence'];
			if (!is_array($recurrence) || (!empty($recurrence) && array_is_list($recurrence))) {
				$errors[] = _('One or more scheduled-announcement recurrence settings are invalid.');
				continue;
			}
			$modeValue = strtolower(trim((string)($recurrence['mode'] ?? 'none')));
			if (!in_array($modeValue, ['none', 'every_7_days', 'every_14_days'], true)) {
				$errors[] = _('A scheduled announcement uses an unsupported repeat interval.');
				continue;
			}
			$startsAtLocal = trim((string)($recurrence['starts_at_local'] ?? ''));
			if ($modeValue === 'none') {
				if ($startsAtLocal !== '') {
					$errors[] = _('A one-time schedule contains unexpected recurrence data.');
				}
				continue;
			}
			try {
				$timezone = new \DateTimeZone(trim((string)($schedule['timezone'] ?? '')) ?: $this->getPbxDateTimeZone()->getName());
			} catch (\Throwable $e) {
				$errors[] = _('A repeating schedule contains an invalid timezone.');
				continue;
			}
			if (!$this->scheduleRecurrenceMatchesOccurrences($modeValue, $startsAtLocal, $timezone, (array)($schedule['occurrences'] ?? []))) {
				$errors[] = _('A repeating schedule does not match its protected occurrence list.');
			}
		}
		return array_values(array_unique($errors));
	}

	private function normalizeScheduledAnnouncements($value)
	{
		$schedules = [];
		$seenScheduleIds = [];
		foreach (array_slice(array_values((array)$value), 0, self::MAX_SCHEDULES) as $rawSchedule) {
			if (!is_array($rawSchedule)) {
				continue;
			}
			$name = $this->sanitizeScheduleText($rawSchedule['name'] ?? '', 80, true);
			$message = $this->sanitizeScheduleText($rawSchedule['message'] ?? '', 500, false);
			if ($name === '' || $message === '') {
				continue;
			}
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($rawSchedule['id'] ?? '')), 0, 64);
			if ($id === '') {
				$id = 'sched_' . substr(hash('sha256', $name . '|' . $message), 0, 20);
			}
			if (isset($seenScheduleIds[$id])) {
				continue;
			}
			$seenScheduleIds[$id] = true;
			try {
				$timezone = new \DateTimeZone(trim((string)($rawSchedule['timezone'] ?? '')) ?: $this->getPbxDateTimeZone()->getName());
			} catch (\Throwable $e) {
				$timezone = $this->getPbxDateTimeZone();
			}
			$occurrences = [];
			$seen = [];
			foreach (array_slice(array_values((array)($rawSchedule['occurrences'] ?? [])), 0, self::MAX_SCHEDULE_OCCURRENCES) as $rawOccurrence) {
				if (!is_array($rawOccurrence)) {
					continue;
				}
				$runAtValue = trim((string)($rawOccurrence['run_at_utc'] ?? $rawOccurrence['run_at'] ?? ''));
				$timestamp = $this->parseScheduleUtcTimestamp($runAtValue);
				if ($timestamp === false) {
					$resolved = $this->resolveScheduleLocalDateTime((string)($rawOccurrence['local_datetime'] ?? ''), $timezone);
					if (empty($resolved['success'])) {
						continue;
					}
					$timestamp = (int)$resolved['timestamp'];
				}
				$runAtUtc = gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp);
				if (isset($seen[$runAtUtc])) {
					continue;
				}
				$seen[$runAtUtc] = true;
				$localDateTime = (new \DateTimeImmutable('@' . (int)$timestamp))->setTimezone($timezone)->format('Y-m-d\TH:i');
				// Execution state is keyed by occurrence ID. Always derive it from the
				// normalized schedule identity and instant so imported configuration
				// cannot make two schedules suppress one another with duplicate IDs.
				$occurrenceId = 'occ_' . substr(hash('sha256', $id . '|' . $runAtUtc), 0, 20);
				$occurrences[] = [
					'id' => $occurrenceId,
					'local_datetime' => $localDateTime,
					'run_at_utc' => $runAtUtc,
				];
			}
			if (empty($occurrences)) {
				continue;
			}
			usort($occurrences, static function ($left, $right) {
				return strcmp((string)$left['run_at_utc'], (string)$right['run_at_utc']);
			});
			$rawRecurrence = is_array($rawSchedule['recurrence'] ?? null) ? $rawSchedule['recurrence'] : [];
			$recurrenceMode = $this->normalizeScheduleRecurrenceMode($rawRecurrence['mode'] ?? 'none');
			$recurrenceStartsAt = str_replace(' ', 'T', substr(trim((string)($rawRecurrence['starts_at_local'] ?? '')), 0, 16));
			if ($recurrenceMode === 'none'
				|| !$this->scheduleRecurrenceMatchesOccurrences($recurrenceMode, $recurrenceStartsAt, $timezone, $occurrences)) {
				$recurrenceMode = 'none';
				$recurrenceStartsAt = '';
			}

			$targets = is_array($rawSchedule['targets'] ?? null) ? $rawSchedule['targets'] : [];
			$extensions = [];
			foreach ((array)($targets['extensions'] ?? []) as $extension) {
				$extension = preg_replace('/[^0-9]/', '', (string)$extension);
				if ($extension !== '') {
					$extensions[$extension] = $extension;
				}
			}
			$groups = [];
			foreach ((array)($targets['groups'] ?? []) as $groupId) {
				$groupId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId), 0, 64);
				if ($groupId !== '') {
					$groups[$groupId] = $groupId;
				}
			}
			$desktops = [];
			foreach ((array)($targets['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$desktops[$username] = $username;
				}
			}

			$delivery = is_array($rawSchedule['delivery'] ?? null) ? $rawSchedule['delivery'] : [];
			$audioMode = $this->normalizeAnnouncementAudioMode($delivery['audio_mode'] ?? 'none');
			$createdAt = trim((string)($rawSchedule['created_at'] ?? ''));
			if (strtotime($createdAt) === false) {
				$createdAt = (string)($occurrences[0]['run_at_utc'] ?? '1970-01-01T00:00:00Z');
			}
			$updatedAt = trim((string)($rawSchedule['updated_at'] ?? ''));
			if (strtotime($updatedAt) === false) {
				$updatedAt = $createdAt;
			}
			$schedules[] = [
				'id' => $id,
				'name' => $name,
				'enabled' => empty($rawSchedule['enabled']) ? '0' : '1',
				'timezone' => $timezone->getName(),
				'recurrence' => [
					'mode' => $recurrenceMode,
					'starts_at_local' => $recurrenceStartsAt,
				],
				'occurrences' => $occurrences,
				'message' => $message,
				'targets' => [
					'extensions' => array_values($extensions),
					'groups' => array_values($groups),
					'phones_all' => empty($targets['phones_all']) ? '0' : '1',
					'desktop_clients' => array_values($desktops),
					'desktop_all' => empty($targets['desktop_all']) ? '0' : '1',
				],
				'delivery' => [
					'audio_mode' => $audioMode,
					'voice' => in_array($audioMode, ['tts', 'tones_tts'], true) ? substr(trim((string)($delivery['voice'] ?? $delivery['piper_voice'] ?? '')), 0, 255) : '',
					'tts_volume' => $this->normalizeTtsVolume($delivery['tts_volume'] ?? 25, 25),
					'opening_tone' => in_array($audioMode, ['tones', 'tones_tts'], true) ? $this->normalizeToneName($delivery['opening_tone'] ?? '') : '',
					'closing_tone' => in_array($audioMode, ['tones', 'tones_tts'], true) ? $this->normalizeToneName($delivery['closing_tone'] ?? '') : '',
					'style' => strtolower((string)($delivery['style'] ?? 'standard')) === 'colored' ? 'colored' : 'standard',
					'title' => $this->sanitizeScheduleText($delivery['title'] ?? 'Announcement', 80, true) ?: 'Announcement',
					'background_color' => $this->normalizeHexColor($delivery['background_color'] ?? '#1f2937', '#1f2937'),
				],
				'created_at' => $createdAt,
				'updated_at' => $updatedAt,
			];
		}
		return $schedules;
	}

	private function sanitizeScheduleText($value, $limit, $singleLine)
	{
		$value = (string)$value;
		$filtered = preg_replace('/[^\P{C}\r\n\t]/u', '', $value);
		$value = is_string($filtered) ? $filtered : '';
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		if ($singleLine) {
			$value = preg_replace('/\s+/u', ' ', $value);
		} else {
			$value = preg_replace('/[ \t]+/u', ' ', $value);
			$value = preg_replace('/\n{3,}/', "\n\n", $value);
		}
		$value = trim((string)$value);
		return function_exists('mb_substr') ? mb_substr($value, 0, (int)$limit) : substr($value, 0, (int)$limit);
	}

	private function parseScheduleUtcTimestamp($value)
	{
		$value = trim((string)$value);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$date instanceof \DateTimeImmutable || (is_array($errors) && (((int)($errors['warning_count'] ?? 0)) > 0 || ((int)($errors['error_count'] ?? 0)) > 0))) {
			return false;
		}
		if ($date->format('Y-m-d\TH:i:s\Z') !== $value) {
			return false;
		}
		return $date->getTimestamp();
	}

	private function resolveScheduleLocalDateTime($value, \DateTimeZone $timezone)
	{
		$value = str_replace(' ', 'T', trim((string)$value));
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $value, $matches)) {
			return ['success' => false, 'message' => sprintf(_('Invalid scheduled date or time: %s.'), $this->sanitizeScheduleText($value, 40, true))];
		}
		$year = (int)$matches[1];
		$month = (int)$matches[2];
		$day = (int)$matches[3];
		$hour = (int)$matches[4];
		$minute = (int)$matches[5];
		if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $year < 2000) {
			return ['success' => false, 'message' => sprintf(_('Invalid scheduled date or time: %s.'), $this->sanitizeScheduleText($value, 40, true))];
		}
		$naiveTimestamp = gmmktime($hour, $minute, 0, $month, $day, $year);
		$offsets = [];
		$transitions = $timezone->getTransitions($naiveTimestamp - 172800, $naiveTimestamp + 172800);
		if (is_array($transitions)) {
			foreach ($transitions as $transition) {
				$offsets[(int)($transition['offset'] ?? 0)] = true;
			}
		}
		$probe = (new \DateTimeImmutable('@' . $naiveTimestamp))->setTimezone($timezone);
		$offsets[$timezone->getOffset($probe)] = true;
		$candidates = [];
		foreach (array_keys($offsets) as $offset) {
			$candidate = $naiveTimestamp - (int)$offset;
			$formatted = (new \DateTimeImmutable('@' . $candidate))->setTimezone($timezone)->format('Y-m-d\TH:i');
			if ($formatted === $value) {
				$candidates[$candidate] = true;
			}
		}
		if (count($candidates) === 0) {
			return ['success' => false, 'message' => sprintf(_('%s does not exist in the PBX timezone because of a daylight-saving time change.'), $value)];
		}
		if (count($candidates) > 1) {
			return ['success' => false, 'message' => sprintf(_('%s occurs twice in the PBX timezone. Choose a different time to avoid an ambiguous delivery.'), $value)];
		}
		$timestamp = (int)array_key_first($candidates);
		return ['success' => true, 'timestamp' => $timestamp, 'local_datetime' => $value];
	}

	private function persistScheduledAnnouncements(array $schedules, array $expectedSchedules)
	{
		$schedules = $this->normalizeScheduledAnnouncements($schedules);
		$expectedFingerprint = $this->scheduledAnnouncementsFingerprint($expectedSchedules);
		$lock = $this->acquireSettingsLock();
		try {
			$active = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
			if (!hash_equals($expectedFingerprint, $this->scheduledAnnouncementsFingerprint($active['scheduled_announcements'] ?? []))) {
				throw new \RuntimeException(_('Scheduled announcements changed while this request was being processed.'));
			}
			$pending = $this->getPendingSettings();
			if ($pending !== null && !hash_equals($expectedFingerprint, $this->scheduledAnnouncementsFingerprint($pending['scheduled_announcements'] ?? []))) {
				throw new \RuntimeException(_('A staged configuration contains a different schedule list. Apply or discard those staged changes before editing Scheduling.'));
			}
			$active['scheduled_announcements'] = $schedules;
			if ($pending !== null) {
				$pending['scheduled_announcements'] = $schedules;
			}
			$this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $this->normalizeSettings($active), true);
			if ($pending !== null) {
				$this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON, $this->normalizeSettings($pending), false);
			}
			$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
			$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	private function scheduledAnnouncementsFingerprint(array $schedules)
	{
		$normalized = $this->normalizeScheduledAnnouncements($schedules);
		$encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			throw new \RuntimeException(_('Unable to compare scheduled-announcement configuration.'));
		}
		return hash('sha256', $encoded);
	}

	private function findLiveScheduledOccurrence($scheduleId, $occurrenceId, $runAtUtc)
	{
		$settings = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
		foreach ((array)($settings['scheduled_announcements'] ?? []) as $schedule) {
			if (empty($schedule['enabled']) || !hash_equals((string)($schedule['id'] ?? ''), (string)$scheduleId)) {
				continue;
			}
			foreach ((array)($schedule['occurrences'] ?? []) as $occurrence) {
				if (
					hash_equals((string)($occurrence['id'] ?? ''), (string)$occurrenceId)
					&& hash_equals((string)($occurrence['run_at_utc'] ?? ''), (string)$runAtUtc)
				) {
					return ['schedule' => $schedule, 'occurrence' => $occurrence];
				}
			}
		}
		return null;
	}

	private function loadScheduleExecutionStore($failClosed = false)
	{
		if (!file_exists(self::SCHEDULE_STATE_JSON)) {
			return ['version' => 1, 'occurrences' => [], 'worker' => []];
		}
		if (!is_readable(self::SCHEDULE_STATE_JSON)) {
			if ($failClosed) {
				throw new \RuntimeException(_('The scheduled-announcement execution journal is unreadable; automatic delivery was stopped to prevent a duplicate.'));
			}
			return ['version' => 1, 'occurrences' => [], 'worker' => ['status' => 'fault']];
		}
		$contents = (string)file_get_contents(self::SCHEDULE_STATE_JSON);
		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			if ($failClosed) {
				throw new \RuntimeException(_('The scheduled-announcement execution journal is invalid; automatic delivery was stopped to prevent a duplicate.'));
			}
			return ['version' => 1, 'occurrences' => [], 'worker' => ['status' => 'fault']];
		}
		$decoded['version'] = 1;
		$decoded['occurrences'] = is_array($decoded['occurrences'] ?? null) ? $decoded['occurrences'] : [];
		$decoded['worker'] = is_array($decoded['worker'] ?? null) ? $decoded['worker'] : [];
		return $decoded;
	}

	private function writeScheduleExecutionStore(array $store)
	{
		$this->ensurePluginDataDir();
		$store['version'] = 1;
		$store['updated_at'] = gmdate('c');
		$json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException(_('Unable to encode scheduling execution state.'));
		}
		$tmp = self::SCHEDULE_STATE_JSON . '.tmp.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
			@unlink($tmp);
			throw new \RuntimeException(_('Unable to write scheduling execution state.'));
		}
		$this->setPrivateOwnership($tmp);
		if (!@rename($tmp, self::SCHEDULE_STATE_JSON)) {
			@unlink($tmp);
			throw new \RuntimeException(_('Unable to replace scheduling execution state.'));
		}
		$this->setPrivateOwnership(self::SCHEDULE_STATE_JSON);
	}

	private function scheduleExecutionRecord(array $schedule, array $occurrence, $state, $message, array $current = [])
	{
		$record = [
			'schedule_id' => (string)($schedule['id'] ?? ''),
			'schedule_name' => (string)($schedule['name'] ?? ''),
			'occurrence_id' => (string)($occurrence['id'] ?? ''),
			'run_at_utc' => (string)($occurrence['run_at_utc'] ?? ''),
			'state' => (string)$state,
			'message' => $this->sanitizeScheduleText($message, 400, true),
			'attempts' => max(0, (int)($current['attempts'] ?? 0)),
			'claimed_at' => (string)($current['claimed_at'] ?? ''),
			'updated_at' => gmdate('c'),
		];
		if (in_array($state, ['success', 'failed', 'missed', 'uncertain'], true)) {
			$record['completed_at'] = gmdate('c');
		}
		return $record;
	}

	public function getScheduleExecutionState()
	{
		$currentOccurrences = [];
		foreach ((array)($this->getActiveSettings()['scheduled_announcements'] ?? []) as $schedule) {
			$scheduleId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($schedule['id'] ?? '')), 0, 64);
			if ($scheduleId === '') {
				continue;
			}
			foreach ((array)($schedule['occurrences'] ?? []) as $occurrence) {
				$occurrenceId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($occurrence['id'] ?? '')), 0, 64);
				if ($occurrenceId !== '') {
					$currentOccurrences[$occurrenceId] = $scheduleId;
				}
			}
		}
		$latest = [];
		$attention = [];
		foreach ((array)($this->loadScheduleExecutionStore()['occurrences'] ?? []) as $occurrenceId => $record) {
			if (!is_array($record)) {
				continue;
			}
			$occurrenceId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$occurrenceId), 0, 64);
			$scheduleId = $currentOccurrences[$occurrenceId] ?? '';
			if ($scheduleId === '' || (string)($record['schedule_id'] ?? '') !== $scheduleId) {
				continue;
			}
			$currentTime = strtotime((string)($record['updated_at'] ?? $record['run_at_utc'] ?? '')) ?: 0;
			$previousTime = strtotime((string)($latest[$scheduleId]['updated_at'] ?? $latest[$scheduleId]['run_at_utc'] ?? '')) ?: 0;
			if (!isset($latest[$scheduleId]) || $currentTime >= $previousTime) {
				$latest[$scheduleId] = $record;
			}
			if (in_array(strtolower((string)($record['state'] ?? '')), ['failed', 'missed', 'uncertain'], true)) {
				$previousAttentionTime = strtotime((string)($attention[$scheduleId]['updated_at'] ?? $attention[$scheduleId]['run_at_utc'] ?? '')) ?: 0;
				if (!isset($attention[$scheduleId]) || $currentTime >= $previousAttentionTime) {
					$attention[$scheduleId] = $record;
				}
			}
		}
		foreach ($attention as $scheduleId => $record) {
			$record['attention'] = true;
			$latest[$scheduleId] = $record;
		}
		return $latest;
	}

	private function getScheduledAnnouncementHealthWarnings(array $settings)
	{
		$warnings = [];
		$knownExtensions = [];
		foreach ($this->getAllPjsipExtensions() as $extension) {
			$number = preg_replace('/[^0-9]/', '', (string)($extension['extension'] ?? ''));
			if ($number !== '') {
				$knownExtensions[$number] = true;
			}
		}
		$knownGroups = [];
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			$id = (string)($group['id'] ?? '');
			if ($id !== '') {
				$knownGroups[$id] = $group;
			}
		}
		$knownDesktops = [];
		foreach ($this->getDesktopClients($settings) as $client) {
			if (!empty($client['enabled'])) {
				$knownDesktops[(string)($client['username'] ?? '')] = true;
			}
		}
		$now = time();
		foreach ((array)($settings['scheduled_announcements'] ?? []) as $schedule) {
			if (!is_array($schedule) || empty($schedule['enabled'])) {
				continue;
			}
			$name = $this->sanitizeScheduleText($schedule['name'] ?? _('unnamed schedule'), 80, true) ?: _('unnamed schedule');
			$hasCurrentOccurrence = false;
			foreach ((array)($schedule['occurrences'] ?? []) as $occurrence) {
				$timestamp = $this->parseScheduleUtcTimestamp((string)($occurrence['run_at_utc'] ?? ''));
				if ($timestamp !== false && $timestamp >= ($now - self::SCHEDULE_GRACE_SECONDS)) {
					$hasCurrentOccurrence = true;
					break;
				}
			}
			if (!$hasCurrentOccurrence) {
				$warnings[] = sprintf(_('Scheduled announcement %s is enabled but has no current or future date'), $name);
			}

			$targets = is_array($schedule['targets'] ?? null) ? $schedule['targets'] : [];
			$validRecipient = !empty($targets['phones_all']) || !empty($targets['desktop_all']);
			$validPhoneTarget = !empty($targets['phones_all']);
			$orphanedTarget = false;
			foreach ((array)($targets['extensions'] ?? []) as $extension) {
				$extension = preg_replace('/[^0-9]/', '', (string)$extension);
				if (isset($knownExtensions[$extension])) {
					$validRecipient = true;
					$validPhoneTarget = true;
				} else {
					$orphanedTarget = true;
				}
			}
			foreach ((array)($targets['groups'] ?? []) as $groupId) {
				if (!isset($knownGroups[$groupId])) {
					$orphanedTarget = true;
					continue;
				}
				$validRecipient = true;
				if (!empty($knownGroups[$groupId]['extensions'])) {
					$validPhoneTarget = true;
				}
			}
			foreach ((array)($targets['desktop_clients'] ?? []) as $username) {
				if (isset($knownDesktops[$username])) {
					$validRecipient = true;
				} else {
					$orphanedTarget = true;
				}
			}
			if ($orphanedTarget) {
				$warnings[] = sprintf(_('Scheduled announcement %s references a removed or disabled recipient'), $name);
			}
			if (!$validRecipient) {
				$warnings[] = sprintf(_('Scheduled announcement %s has no resolvable recipient'), $name);
			}

			$delivery = is_array($schedule['delivery'] ?? null) ? $schedule['delivery'] : [];
			$audioMode = $this->normalizeAnnouncementAudioMode($delivery['audio_mode'] ?? 'none');
			if ($audioMode === 'none') {
				continue;
			}
			if (!$validPhoneTarget) {
				$warnings[] = sprintf(_('Scheduled announcement %s has phone audio enabled without a phone target'), $name);
			}
			if (in_array($audioMode, ['tts', 'tones_tts'], true)) {
				$voice = (string)($delivery['voice'] ?? '');
				if ($voice === '' || !is_readable($voice)) {
					$warnings[] = sprintf(_('Scheduled announcement %s uses an unavailable Piper voice'), $name);
				}
			}
			if (in_array($audioMode, ['tones', 'tones_tts'], true)) {
				$readableTone = false;
				foreach (['opening_tone', 'closing_tone'] as $toneKey) {
					$tone = $this->normalizeToneName((string)($delivery[$toneKey] ?? ''));
					if ($tone !== '' && is_readable(self::TONES_DIR . '/' . $tone . '.wav')) {
						$readableTone = true;
					} elseif ($tone !== '') {
						$warnings[] = sprintf(_('Scheduled announcement %s uses an unavailable tone'), $name);
					}
				}
				if (!$readableTone) {
					$warnings[] = sprintf(_('Scheduled announcement %s has tone audio enabled without a readable tone'), $name);
				}
			}
		}
		return array_values(array_unique($warnings));
	}

	private function generateDesktopAuthKey()
	{
		return base64_encode(random_bytes(32));
	}

	private function normalizeDesktopAuthKey($value)
	{
		$value = trim((string)$value);
		$decoded = base64_decode($value, true);
		if (is_string($decoded) && strlen($decoded) === 32) {
			return $value;
		}
		return $this->generateDesktopAuthKey();
	}

	private function defaultDesktopClient($name = 'Desktop App')
	{
		$username = 'sls' . strtolower(bin2hex(random_bytes(3)));
		return [
			'id' => 'desk_' . bin2hex(random_bytes(6)),
			'client_id' => $this->generateDesktopClientId(),
			'name' => $name,
			'enabled' => '1',
			'username' => $username,
			'password' => $this->generateEndpointPassword(),
		];
	}

	public function getDesktopClients(array $settings = null, $includePlaintext = false)
	{
		$settings = $settings ?? $this->getActiveSettings();
		$clients = $this->normalizeDesktopClients($settings['desktop_clients'] ?? [], $settings);
		if ($includePlaintext) {
			foreach ($clients as $index => $client) {
				$clients[$index]['password'] = $this->decryptDesktopPassword((string)($client['password_enc'] ?? ''), $settings);
			}
		}
		return $clients;
	}

	private function normalizeDesktopClients($value, array $settings)
	{
		$clients = [];
		foreach ((array)$value as $client) {
			if (!is_array($client) || count($clients) >= 50) {
				continue;
			}
			$name = trim(preg_replace('/\s+/', ' ', (string)($client['name'] ?? '')));
			$clientId = $this->normalizeDesktopClientId($client['client_id'] ?? '');
			$username = $this->normalizeDesktopUsername($client['username'] ?? '');
			if ($name === '') {
				$name = 'Desktop App';
			}
			if ($username === '') {
				$username = $this->normalizeDesktopUsername('sls' . bin2hex(random_bytes(3)));
			}
			if ($clientId === '') {
				$legacyOwner = trim(preg_replace('/\s+/', ' ', (string)($client['owner'] ?? '')));
				$clientId = $this->normalizeDesktopClientId($legacyOwner);
			}
			if ($clientId === '') {
				$clientId = $this->generateDesktopClientId();
			}
			$password = trim((string)($client['password'] ?? ''));
			$passwordEnc = (string)($client['password_enc'] ?? '');
			if ($password !== '' && $password !== '[redacted]') {
				$passwordEnc = $this->encryptDesktopPassword($password, $settings);
			}
			if ($passwordEnc === '' || $this->decryptDesktopPassword($passwordEnc, $settings) === '') {
				$passwordEnc = $this->encryptDesktopPassword($this->generateEndpointPassword(), $settings);
			}
			$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($client['id'] ?? ''));
			if ($id === '') {
				$id = 'desk_' . substr(hash('sha256', strtolower($username) . '|' . $name), 0, 12);
			}
			$clients[] = [
				'id' => $id,
				'client_id' => $clientId,
				'name' => substr($name, 0, 80),
				'enabled' => empty($client['enabled']) ? '0' : '1',
				'username' => $username,
				'password_enc' => $passwordEnc,
			];
		}
		return $clients;
	}

	private function validateDesktopClientIdentifiers($value)
	{
		$errors = [];
		$usernames = [];
		$clientIds = [];
		$clients = array_values(array_filter((array)$value, 'is_array'));
		if (count($clients) > 50) {
			$errors[] = _('Desktop clients are limited to 50.');
		}
			foreach (array_slice($clients, 0, 50) as $client) {
			$username = $this->normalizeDesktopUsername($client['username'] ?? '');
			$clientId = $this->normalizeDesktopClientId($client['client_id'] ?? '');
			if ($username !== '') {
				if (isset($usernames[$username])) {
					$errors[] = sprintf(_('Desktop username must be unique: %s.'), $username);
				}
				$usernames[$username] = true;
			}
			if ($clientId !== '') {
				if (isset($clientIds[$clientId])) {
					$errors[] = sprintf(_('Desktop client ID must be unique: %s.'), $clientId);
				}
				$clientIds[$clientId] = true;
				}
			}
			foreach (array_keys($usernames) as $username) {
				if (isset($clientIds[$username])) {
					$errors[] = sprintf(_('Desktop username and client ID namespaces must not overlap: %s.'), $username);
				}
			}
			return array_values(array_unique($errors));
	}

	private function generateDesktopClientId()
	{
		return 'cli_' . strtolower(bin2hex(random_bytes(3)));
	}

	private function normalizeDesktopClientId($value)
	{
		$value = strtolower(trim((string)$value));
		$value = preg_replace('/[^a-z0-9_-]+/', '', $value);
		return substr($value, 0, 32);
	}

	private function normalizeDesktopUsername($value)
	{
		$value = strtolower(trim((string)$value));
		$value = preg_replace('/[^a-z0-9_.-]+/', '', $value);
		return substr($value, 0, 48);
	}

	private function encryptDesktopPassword($password, array $settings)
	{
		$key = base64_decode($this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? ''), true);
		if (!is_string($key) || strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
			return '';
		}
		$iv = random_bytes(12);
		$tag = '';
		$cipher = openssl_encrypt((string)$password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if (!is_string($cipher)) {
			return '';
		}
		return 'v1:' . base64_encode($iv . $tag . $cipher);
	}

	private function decryptDesktopPassword($encoded, array $settings)
	{
		$encoded = trim((string)$encoded);
		if (strpos($encoded, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
			return '';
		}
		$raw = base64_decode(substr($encoded, 3), true);
		$key = base64_decode($this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? ''), true);
		if (!is_string($raw) || strlen($raw) < 29 || !is_string($key) || strlen($key) !== 32) {
			return '';
		}
		$iv = substr($raw, 0, 12);
		$tag = substr($raw, 12, 16);
		$cipher = substr($raw, 28);
		$plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		return is_string($plain) ? $plain : '';
	}

	private function normalizeTtsVolume($value, $fallback)
	{
		$volume = (int)$value;
		if ($volume < 1 || $volume > 200) {
			$volume = (int)$fallback;
		}
		return min(200, max(1, $volume));
	}

	private function normalizeInt($value, $min, $max, $fallback)
	{
		$number = (int)$value;
		if ($number < (int)$min || $number > (int)$max) {
			$number = (int)$fallback;
		}
		return min((int)$max, max((int)$min, $number));
	}

	private function normalizeIpAllowlist($value)
	{
		$items = preg_split('/[\r\n,]+/', (string)$value) ?: [];
		$allowed = [];
		foreach ($items as $item) {
			$item = trim($item);
			if ($item === '') {
				continue;
			}
			if (filter_var($item, FILTER_VALIDATE_IP)) {
				$allowed[$item] = $item;
				continue;
			}
			if (strpos($item, '/') !== false) {
				[$network, $bits] = explode('/', $item, 2);
				$packed = @inet_pton($network);
				$maxBits = is_string($packed) ? strlen($packed) * 8 : -1;
				if ($maxBits > 0 && preg_match('/^\d+$/', $bits) && (int)$bits <= $maxBits) {
					$allowed[$network . '/' . (int)$bits] = $network . '/' . (int)$bits;
				}
			}
		}
		return implode("\n", array_values($allowed));
	}

	private function normalizeTtsMaxSeconds($value)
	{
		$seconds = (int)$value;
		if ($seconds < 1) {
			$seconds = 30;
		}
		return min(600, max(1, $seconds));
	}

	private function normalizeRetentionDays($value)
	{
		$days = (int)$value;
		if ($days < 1) {
			$days = 90;
		}
		return min(365, max(1, $days));
	}

	private function pruneEventLog()
	{
		if (!is_readable(self::EVENTS_LOG) || !is_writable(self::EVENTS_LOG)) {
			return;
		}
		$retentionDays = $this->normalizeRetentionDays($this->getActiveSettings()['log_retention_days'] ?? 90);
		$cutoff = time() - ($retentionDays * 86400);
		$handle = @fopen(self::EVENTS_LOG, 'r+');
		if ($handle === false || !flock($handle, LOCK_EX)) {
			if (is_resource($handle)) {
				fclose($handle);
			}
			return;
		}
		$lines = [];
		while (($line = fgets($handle)) !== false) {
			$line = trim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}
		$retained = [];
		foreach ($lines as $line) {
			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				continue;
			}
			$loggedAt = strtotime((string)($decoded['logged_at'] ?? $decoded['created_at'] ?? '')) ?: time();
			if ($loggedAt >= $cutoff) {
				$retained[] = json_encode($decoded, JSON_UNESCAPED_SLASHES);
			}
		}
		if (count($retained) !== count($lines)) {
			rewind($handle);
			ftruncate($handle, 0);
			fwrite($handle, implode("\n", $retained) . (empty($retained) ? '' : "\n"));
			fflush($handle);
		}
		flock($handle, LOCK_UN);
		fclose($handle);
		$this->setOwnership(self::EVENTS_LOG);
	}

	private function volumePercentToScalar($value, $fallback)
	{
		return number_format($this->normalizeTtsVolume($value, $fallback) / 100, 2, '.', '');
	}

	private function generateApiKey()
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	private function normalizeAnnouncementGroups($value)
	{
		return $this->normalizeAnnouncementGroupsForExtensions($value, $this->getConfiguredPjsipExtensionNumbers(), null);
	}

	private function normalizeAnnouncementGroupsForExtensions($value, array $allowedExtensions, array $allowedDesktopUsernames = null)
	{
		$available = array_fill_keys($allowedExtensions, true);
		$availableDesktops = $allowedDesktopUsernames === null ? null : array_fill_keys($allowedDesktopUsernames, true);
		$groups = [];
		foreach ((array)$value as $group) {
			if (!is_array($group) || count($groups) >= 20) {
				continue;
			}
			$name = trim((string)($group['name'] ?? ''));
			$name = preg_replace('/[^\P{C}\t]/u', '', $name);
			$name = preg_replace('/\s+/', ' ', $name);
			$name = substr($name, 0, 64);
			if ($name === '') {
				continue;
			}
			$extensions = [];
			foreach ((array)($group['extensions'] ?? []) as $extension) {
				$extension = preg_replace('/[^0-9]/', '', (string)$extension);
				if ($extension !== '' && isset($available[$extension])) {
					$extensions[$extension] = $extension;
				}
			}
			$desktopClients = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '' && ($availableDesktops === null || isset($availableDesktops[$username]))) {
					$desktopClients[$username] = $username;
				}
			}
			if (empty($extensions) && empty($desktopClients)) {
				continue;
			}
			$groups[] = [
				'id' => 'grp_' . substr(hash('sha256', strtolower($name) . '|' . implode(',', $extensions) . '|' . implode(',', $desktopClients)), 0, 12),
				'name' => $name,
				'extensions' => array_values($extensions),
				'desktop_clients' => array_values($desktopClients),
			];
		}

		return $groups;
	}

	private function syncPendingAnnouncementGroups(array $groups)
	{
		$pending = $this->getPendingSettings();
		if ($pending === null) {
			return;
		}
		$pending['announcement_groups'] = $groups;
		$this->persistPendingSettings($pending);
	}

	private function normalizeSipNotifySettings($value)
	{
		$defaults = $this->getDefaultSipNotifySettings();
		$value = is_array($value) ? $value : [];
		$host = $this->normalizePbxHost((string)($value['pbx_host'] ?? $defaults['pbx_host']));
		$baseUrl = 'https://' . $host . '/api/sipnotify';
		$mediaScheme = $this->normalizePhoneMediaScheme((string)($value['media_scheme'] ?? $defaults['media_scheme']));
		return [
			'pbx_host' => $host,
			'base_url' => $baseUrl,
			'media_scheme' => $mediaScheme,
			'media_base_url' => $mediaScheme . '://' . $host . '/sls_mass_notify',
			'format_overrides' => $this->normalizeEndpointFormatOverrides($value['format_overrides'] ?? []),
		];
	}

	private function normalizePhoneMediaScheme($value)
	{
		return strtolower(trim((string)$value)) === 'https' ? 'https' : 'http';
	}

	private function normalizeEndpointFormatOverrides($value)
	{
		$allowed = array_fill_keys([
			'yealink', 'yealink_text', 'cisco', 'poly', 'polycom', 'grandstream', 'fanvil',
			'snom', 'aastra', 'mitel', 'sangoma', 'avaya', 'vtech', 'ale',
			'panasonic',
		], true);
		$aliases = [
			'polycom' => 'poly',
			'poly-com' => 'poly',
			'mitel' => 'aastra',
			'yealink_xml' => 'yealink',
			'yealink-text' => 'yealink_text',
			'cisco_xml' => 'cisco',
		];
		$items = [];
		if (is_string($value)) {
			foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $line) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}
				if (strpos($line, '=') !== false) {
					[$extension, $format] = array_map('trim', explode('=', $line, 2));
				} elseif (strpos($line, ':') !== false) {
					[$extension, $format] = array_map('trim', explode(':', $line, 2));
				} else {
					continue;
				}
				$items[$extension] = $format;
			}
		} elseif (is_array($value)) {
			$items = $value;
		}
		$normalized = [];
		foreach ($items as $extension => $format) {
			if (is_array($format)) {
				$extension = $format['extension'] ?? $extension;
				$format = $format['format'] ?? '';
			}
			$extension = preg_replace('/[^0-9]/', '', (string)$extension);
			$format = strtolower(trim((string)$format));
			$format = preg_replace('/[^a-z0-9_-]+/', '', $format);
			$format = $aliases[$format] ?? $format;
			if ($extension === '' || !isset($allowed[$format])) {
				continue;
			}
			$normalized[$extension] = $format;
		}
		ksort($normalized, SORT_NATURAL);
		return $normalized;
	}

	private function normalizeEndpointUsername($value, $slug)
	{
		$value = strtolower(trim((string)$value));
		$value = preg_replace('/[^a-z0-9_.-]+/', '_', $value);
		return $value !== '' ? substr($value, 0, 64) : 'sipnotify_' . $slug;
	}

	private function normalizeEndpointPassword($value)
	{
		$value = trim((string)$value);
		if ($value === '') {
			return '';
		}
		return substr(preg_replace('/[^\x21-\x7e]/', '', $value), 0, 128);
	}

	private function generateEndpointPassword()
	{
		return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
	}

	private function installRuntimeFiles()
	{
		$runtimeDir = self::RUNTIME_DIR;
		if (!is_dir($runtimeDir)) {
			@mkdir($runtimeDir, 0755, true);
		}
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_nws_poll.sh', $runtimeDir . '/sls_mass_notify_nws_poll.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_weather_poll.sh', $runtimeDir . '/sls_mass_notify_weather_poll.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_schedule_worker.php', $runtimeDir . '/sls_mass_notify_schedule_worker.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_test.sh', $runtimeDir . '/sls_mass_notify_test.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_update.sh', $runtimeDir . '/sls_mass_notify_update.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_maintenance.sh', $runtimeDir . '/sls_mass_notify_maintenance.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_uninstall.sh', $runtimeDir . '/sls_mass_notify_uninstall.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_install_piper_voices.sh', $runtimeDir . '/sls_mass_notify_install_piper_voices.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sign_sls_mass_notify_local_sig.sh', '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh', 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/bin/sls_mass_notify', $runtimeDir, [
			'piper',
			'sls_mass_notify_nws_poll.sh',
			'sls_mass_notify_weather_poll.sh',
			'sls_mass_notify_schedule_worker.php',
			'sls_mass_notify_test.sh',
			'sls_mass_notify_update.sh',
			'sls_mass_notify_maintenance.sh',
			'sls_mass_notify_uninstall.sh',
			'sls_mass_notify_install_piper_voices.sh',
		]);
		$this->copyRuntimeDirectory(__DIR__ . '/bin/sls_mass_notify', $runtimeDir, 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/api/sipnotify', '/var/www/html/api/sipnotify');
		$this->copyRuntimeDirectory(__DIR__ . '/api/sipnotify', '/var/www/html/api/sipnotify', 0644);
		$this->pruneRuntimeDirectory(__DIR__ . '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify');
		$this->copyRuntimeDirectory(__DIR__ . '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify', 0644);
		$this->pruneRuntimeDirectory(__DIR__ . '/assets', '/var/www/html/sls_mass_notify/assets');
		$this->copyRuntimeDirectory(__DIR__ . '/assets', '/var/www/html/sls_mass_notify/assets', 0644);
		$this->copyRuntimeDirectory(__DIR__ . '/sounds', self::SOUNDS_DIR, 0644, false);
		@unlink($runtimeDir . '/config.ini');
		$this->ensureRuntimePermissions();
		$this->secureExecutableRuntimeTree();
	}

	private function ensureBundledSystemRecordings()
	{
		$customDir = '/var/lib/asterisk/sounds/en/custom';
		if (!is_dir($customDir)) {
			@mkdir($customDir, 0755, true);
		}
		$this->ensurePluginDataDir();
		$recordings = [
			[
				'source' => __DIR__ . '/sounds/tones/' . self::DEFAULT_ANNOUNCEMENT_OPENING_TONE . '.wav',
				'custom' => $customDir . '/SLS_Mass_Notify_Paging_Tone_Opening.wav',
				'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_OPENING_TONE . '.wav',
				'name' => 'SLS Mass Notify - Paging Tone Opening',
				'filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Opening',
				'description' => 'Default Southland Servers regular announcement opening tone.',
			],
			[
				'source' => __DIR__ . '/sounds/tones/' . self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE . '.wav',
				'custom' => $customDir . '/SLS_Mass_Notify_Paging_Tone_Closing.wav',
				'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE . '.wav',
				'name' => 'SLS Mass Notify - Paging Tone Closing',
				'filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Closing',
				'description' => 'Default Southland Servers regular announcement closing tone.',
			],
			[
				'source' => __DIR__ . '/sounds/system-recordings/NWS_alert.wav',
				'custom' => $customDir . '/SLS_Mass_Notify_NWS_Alert.wav',
				'tone' => self::TONES_DIR . '/' . self::DEFAULT_NWS_OPENING_TONE . '.wav',
				'name' => 'SLS Mass Notify - NWS Alert',
				'filename' => 'custom/SLS_Mass_Notify_NWS_Alert',
				'description' => 'Default Southland Servers NWS alert opening tone.',
			],
			[
				'source' => __DIR__ . '/sounds/system-recordings/Lightning_alert.wav',
				'custom' => $customDir . '/SLS_Mass_Notify_Lightning_Alert.wav',
				'tone' => self::TONES_DIR . '/' . self::DEFAULT_LIGHTNING_OPENING_TONE . '.wav',
				'name' => 'SLS Mass Notify - Lightning Alert',
				'filename' => 'custom/SLS_Mass_Notify_Lightning_Alert',
				'description' => 'Default Southland Servers cloud-to-ground lightning warning opening tone.',
			],
		];
		try {
			$lookup = $this->FreePBX->Database()->prepare('SELECT displayname, description FROM recordings WHERE filename = ? LIMIT 1');
			foreach ($recordings as $recording) {
				$lookup->execute([$recording['filename']]);
				$existing = $lookup->fetch(\PDO::FETCH_ASSOC);
				if (is_array($existing) && (
					!hash_equals((string)$recording['name'], (string)($existing['displayname'] ?? ''))
					|| !hash_equals((string)$recording['description'], (string)($existing['description'] ?? ''))
				)) {
					throw new \RuntimeException(sprintf(_('A user-owned System Recording already uses the reserved SLS filename: %s'), $recording['filename']));
				}
			}
		} catch (\RuntimeException $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new \RuntimeException(_('FreePBX System Recordings could not be checked safely before installing bundled tones.'));
		}
		foreach ($recordings as $recording) {
			if (!is_readable($recording['source']) || !is_executable('/usr/bin/sox')) {
				throw new \RuntimeException(sprintf(_('Bundled System Recording is unavailable: %s'), basename($recording['source'])));
			}
			foreach ([$recording['custom'], $recording['tone']] as $target) {
				$tmp = $target . '.tmp.' . bin2hex(random_bytes(4)) . '.wav';
				$command = '/usr/bin/timeout 30 /usr/bin/sox ' . escapeshellarg($recording['source'])
					. ' -r 8000 -c 1 -b 16 ' . escapeshellarg($tmp) . ' 2>&1';
				exec($command, $output, $exitCode);
				if ($exitCode !== 0 || !is_file($tmp) || (int)@filesize($tmp) < 44) {
					@unlink($tmp);
					throw new \RuntimeException(sprintf(_('Unable to install bundled System Recording: %s'), $recording['name']));
				}
				if ($target === $recording['custom'] && is_file($target)) {
					$existingHash = @hash_file('sha256', $target);
					$candidateHash = @hash_file('sha256', $tmp);
					if (!is_string($existingHash) || !is_string($candidateHash) || !hash_equals($existingHash, $candidateHash)) {
						@unlink($tmp);
						throw new \RuntimeException(sprintf(_('A user-owned audio file already uses the reserved SLS System Recording path: %s'), $target));
					}
				}
				if (!@rename($tmp, $target)) {
					@unlink($tmp);
					throw new \RuntimeException(sprintf(_('Unable to install bundled System Recording: %s'), $recording['name']));
				}
				@chmod($target, 0644);
				@chown($target, 'asterisk');
				@chgrp($target, 'asterisk');
			}
		}

		try {
			$recordingsModule = $this->FreePBX->Recordings;
			$lookup = $this->FreePBX->Database()->prepare('SELECT id FROM recordings WHERE filename = ? LIMIT 1');
			foreach ($recordings as $recording) {
				$lookup->execute([$recording['filename']]);
				if ($lookup->fetchColumn() === false) {
					$recordingsModule->addRecording($recording['name'], $recording['description'], $recording['filename'], 0, '', 'en');
				}
			}
		} catch (\Throwable $exception) {
			// The audio remains installed and selectable if the optional Recordings module is unavailable.
		}
		$this->cleanupLegacyBundledSystemRecordings();
	}

	private function cleanupLegacyBundledSystemRecordings()
	{
		$legacy = [
			['filename' => 'custom/Paging_Tone_Opening', 'path' => '/var/lib/asterisk/sounds/en/custom/Paging_Tone_Opening.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_OPENING_TONE . '.wav', 'name' => 'Paging Tone Opening', 'description' => 'Default Southland Servers regular announcement opening tone.'],
			['filename' => 'custom/Paging_Tone_Closing', 'path' => '/var/lib/asterisk/sounds/en/custom/Paging_Tone_Closing.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE . '.wav', 'name' => 'Paging Tone Closing', 'description' => 'Default Southland Servers regular announcement closing tone.'],
			['filename' => 'custom/NWS_alert', 'path' => '/var/lib/asterisk/sounds/en/custom/NWS_alert.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_NWS_OPENING_TONE . '.wav', 'name' => 'NWS Alert', 'description' => 'Default Southland Servers NWS alert opening tone.'],
			['filename' => 'custom/Lightning_alert', 'path' => '/var/lib/asterisk/sounds/en/custom/Lightning_alert.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_LIGHTNING_OPENING_TONE . '.wav', 'name' => 'Lightning Alert', 'description' => 'Default Southland Servers cloud-to-ground lightning warning opening tone.'],
		];
		try {
			$lookup = $this->FreePBX->Database()->prepare('SELECT displayname, description FROM recordings WHERE filename = ? LIMIT 1');
			$delete = $this->FreePBX->Database()->prepare('DELETE FROM recordings WHERE filename = ?');
			foreach ($legacy as $recording) {
				$fileExists = is_file($recording['path']) && !is_link($recording['path']);
				$toneHash = is_file($recording['tone']) ? @hash_file('sha256', $recording['tone']) : false;
				$fileHash = $fileExists ? @hash_file('sha256', $recording['path']) : false;
				$fileOwned = is_string($toneHash) && $toneHash !== '' && is_string($fileHash) && hash_equals($toneHash, $fileHash);
				$lookup->execute([$recording['filename']]);
				$row = $lookup->fetch(\PDO::FETCH_ASSOC);
				$rowOwned = is_array($row)
					&& hash_equals($recording['name'], (string)($row['displayname'] ?? ''))
					&& hash_equals($recording['description'], (string)($row['description'] ?? ''));
				if (!$rowOwned || ($fileExists && !$fileOwned)) {
					continue;
				}
				$delete->execute([$recording['filename']]);
				if ($fileOwned && (!is_array($row) || $rowOwned)) {
					@unlink($recording['path']);
				}
			}
		} catch (\Throwable $exception) {
			// Legacy names are left untouched whenever ownership cannot be proven.
		}
	}

	private function removeBundledSystemRecordings()
	{
		$recordings = [
			['filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Opening', 'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Opening.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_OPENING_TONE . '.wav', 'name' => 'SLS Mass Notify - Paging Tone Opening', 'description' => 'Default Southland Servers regular announcement opening tone.'],
			['filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Closing', 'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Closing.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE . '.wav', 'name' => 'SLS Mass Notify - Paging Tone Closing', 'description' => 'Default Southland Servers regular announcement closing tone.'],
			['filename' => 'custom/SLS_Mass_Notify_NWS_Alert', 'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_NWS_Alert.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_NWS_OPENING_TONE . '.wav', 'name' => 'SLS Mass Notify - NWS Alert', 'description' => 'Default Southland Servers NWS alert opening tone.'],
			['filename' => 'custom/SLS_Mass_Notify_Lightning_Alert', 'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Lightning_Alert.wav', 'tone' => self::TONES_DIR . '/' . self::DEFAULT_LIGHTNING_OPENING_TONE . '.wav', 'name' => 'SLS Mass Notify - Lightning Alert', 'description' => 'Default Southland Servers cloud-to-ground lightning warning opening tone.'],
		];
		try {
			$lookup = $this->FreePBX->Database()->prepare('SELECT displayname, description FROM recordings WHERE filename = ? LIMIT 1');
			$delete = $this->FreePBX->Database()->prepare('DELETE FROM recordings WHERE filename = ?');
			foreach ($recordings as $recording) {
				$fileExists = is_file($recording['path']) && !is_link($recording['path']);
				$toneHash = is_file($recording['tone']) ? @hash_file('sha256', $recording['tone']) : false;
				$fileHash = $fileExists ? @hash_file('sha256', $recording['path']) : false;
				$fileOwned = is_string($toneHash) && $toneHash !== '' && is_string($fileHash) && hash_equals($toneHash, $fileHash);
				$lookup->execute([$recording['filename']]);
				$row = $lookup->fetch(\PDO::FETCH_ASSOC);
				$rowOwned = is_array($row)
					&& hash_equals($recording['name'], (string)($row['displayname'] ?? ''))
					&& hash_equals($recording['description'], (string)($row['description'] ?? ''));
				if ($rowOwned && (!$fileExists || $fileOwned)) {
					$delete->execute([$recording['filename']]);
				}
				if ($fileOwned && (!is_array($row) || $rowOwned)) {
					@unlink($recording['path']);
				}
			}
		} catch (\Throwable $exception) {
			// The standalone uninstaller repeats this bounded cleanup.
		}
	}

	private function cleanupLegacyRuntimeArtifacts()
	{
		foreach ([
			self::PLUGIN_DATA_DIR . '/links/freepbx-module-nwsalerts',
			self::SETTINGS_SHELL,
			self::LEGACY_SETTINGS_JSON,
			self::LEGACY_PENDING_SETTINGS_JSON,
			self::LEGACY_SETTINGS_SHELL,
			self::LEGACY_OLD_SETTINGS_JSON,
			self::LEGACY_OLD_PENDING_SETTINGS_JSON,
			'/usr/local/bin/sls_mass_notify/config.ini',
			'/usr/local/bin/sls_mass_notify/__pycache__',
			'/usr/local/bin/nwsalerts_ensure_menu_patch.sh',
			'/var/tmp/nws_last_clear.ts',
		] as $path) {
			if (is_dir($path)) {
				$this->runCommand('/bin/rm -rf ' . escapeshellarg($path));
			} elseif (is_link($path) || is_file($path)) {
				@unlink($path);
			}
		}
	}

	private function ensureRuntimePermissions()
	{
		foreach ([
			'/var/log/sls_mass_notify.log',
			'/var/log/sls_mass_notify_events.jsonl',
			'/var/log/sls_mass_notify_push.log',
		] as $logFile) {
			$this->ensurePrivateFile($logFile);
		}

		$this->ensureOwnedDirectory('/var/www/html/sls_mass_notify', 0755);
		$this->ensureOwnedDirectory(self::PLUGIN_DATA_DIR, 0750);
		$this->ensureOwnedDirectory(self::PLUGIN_DATA_DIR . '/sipnotify', 0750);
		$this->ensureOwnedDirectory(self::PLUGIN_DATA_DIR . '/config-backups', 0750);
		$this->ensureOwnedDirectory(self::SOUNDS_DIR, 0755);
		$this->ensureOwnedDirectory(self::TONES_DIR, 0755);
		$this->ensureOwnedDirectory(self::TTS_DIR, 0755);
		$this->ensureOwnedDirectory(self::PIPER_DATA_DIR, 0750);
		$this->ensureOwnedDirectory(self::PIPER_VOICE_DIR, 0755);
		$journal = self::PLUGIN_DATA_DIR . '/sipnotify/sipnotify_events.jsonl';
		$this->ensurePrivateFile($journal);
		$this->secureManagedRuntimeTree(self::PLUGIN_DATA_DIR, 'data');
		$this->secureManagedRuntimeTree('/var/www/html/sls_mass_notify', 'web');
		$this->setPrivateOwnership(self::SETTINGS_JSON);
		$this->repairPiperRuntimePermissions();
		$this->secureExecutableRuntimeTree();
	}

	private function ensureSipNotifyTemplates()
	{
		$block = "[sls-mass-notify-xml]\n"
			. "Event=xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-yealink]\n"
			. "Event=xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-yealink-legacy]\n"
			. "Event=Yealink-xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-yealink-lower]\n"
			. "Event=yealink-xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
				. "[sls-mass-notify-cisco]\n"
				. "Event=XML-Service\n"
				. "Content-Type=text/xml\n"
				. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-poly]\n"
			. "Event=xml\n"
			. "Content-Type=application/x-com-polycom-spipx\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-snom]\n"
			. "Event=xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-grandstream]\n"
			. "Event=xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-aastra]\n"
			. "Event=aastra-xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n\n"
			. "[sls-mass-notify-panasonic]\n"
			. "Event=xml\n"
			. "Content-Type=text/xml\n"
			. "Content=\${XML_BODY}\n";
		$this->writeManagedBlock('/etc/asterisk/sip_notify_custom.conf', 'SLS Mass Notifications SIP NOTIFY Templates', $block);
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('module reload res_pjsip_notify.so'));
	}

	private function ensurePiperRuntime()
	{
		if (!is_dir(self::PIPER_VOICE_DIR)) {
			@mkdir(self::PIPER_VOICE_DIR, 0755, true);
		}
		if (!is_executable(self::PIPER_BIN) && is_executable('/usr/bin/python3')) {
			if (!is_dir(self::PIPER_RUNTIME_DIR . '/venv')) {
				$this->runCommand('/usr/bin/python3 -m venv ' . escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv'));
				if (!is_executable(self::PIPER_RUNTIME_DIR . '/venv/bin/pip') && is_executable('/usr/bin/apt-get')) {
					$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update');
					$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y python3-venv python3-pip');
					$this->runCommand('/usr/bin/python3 -m venv ' . escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv'));
				}
			}
			if (is_executable(self::PIPER_RUNTIME_DIR . '/venv/bin/pip')) {
				$this->runCommand(escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv/bin/pip') . " install --upgrade 'pip==26.1.2' 'setuptools==83.0.0' 'wheel==0.47.0'");
				$this->runCommand(escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv/bin/pip') . " install 'piper-tts==1.4.2'");
				$this->repairPiperRuntimePermissions();
			}
		}
		$this->ensurePiperVoices();
		$this->ensurePiperWrapper();
		$this->runCommand('/bin/chown -R asterisk:asterisk ' . escapeshellarg(self::PIPER_VOICE_DIR));
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PIPER_VOICE_DIR) . ' -type d -exec chmod 755 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PIPER_VOICE_DIR) . ' -type f -exec chmod 644 {} +');
		$this->repairPiperRuntimePermissions();
		$this->secureExecutableRuntimeTree();
		if (is_executable(self::PIPER_BIN)) {
			$this->runCommand('/bin/rm -rf ' . escapeshellarg(self::PIPER_DATA_DIR . '/venv'));
			$this->runCommand('/bin/mkdir -p ' . escapeshellarg(self::PIPER_DATA_DIR . '/venv/bin'));
			$this->runCommand('/bin/ln -s ' . escapeshellarg('/usr/local/bin/piper') . ' ' . escapeshellarg(self::PIPER_DATA_DIR . '/venv/bin/piper'));
		}
	}

	private function ensurePiperWrapper()
	{
		$wrapper = '/usr/local/bin/piper';
		if ((file_exists($wrapper) || is_link($wrapper)) && !$this->isSlsOwnedPiperWrapper($wrapper)) {
			throw new \RuntimeException(_('/usr/local/bin/piper belongs to another application. SLS Mass Notify refused to overwrite it; move or rename it explicitly before installing this compatibility wrapper.'));
		}
		$this->repairPiperRuntimePermissions();
		if (!file_exists(self::PIPER_BIN)) {
			return;
		}
		if (is_link($wrapper)) {
			@unlink($wrapper);
		}
		$script = "#!/bin/sh\n"
			. "PIPER_BIN=" . escapeshellarg(self::PIPER_BIN) . "\n"
			. "PIPER_PY=" . escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv/bin/python') . "\n"
			. "if [ -x \"\$PIPER_BIN\" ]; then\n"
			. "  exec \"\$PIPER_BIN\" \"\$@\"\n"
			. "fi\n"
			. "if [ -x \"\$PIPER_PY\" ] && [ -r \"\$PIPER_BIN\" ]; then\n"
			. "  exec \"\$PIPER_PY\" \"\$PIPER_BIN\" \"\$@\"\n"
			. "fi\n"
			. "echo \"Piper TTS binary is not installed or not executable: \$PIPER_BIN\" >&2\n"
			. "exit 126\n";
		@file_put_contents($wrapper, $script, LOCK_EX);
		@chmod($wrapper, 0755);
		@chown($wrapper, 'root');
		@chgrp($wrapper, 'root');
	}

	private function isSlsOwnedPiperWrapper($wrapper)
	{
		if (is_link($wrapper)) {
			$target = (string)@readlink($wrapper);
			return in_array($target, [self::PIPER_BIN, self::PIPER_DATA_DIR . '/venv/bin/piper'], true);
		}
		if (!is_file($wrapper)) {
			return false;
		}
		$contents = (string)@file_get_contents($wrapper);
		return strpos($contents, '/usr/local/bin/sls_mass_notify/piper/') !== false
			|| strpos($contents, '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/') !== false;
	}

	private function repairPiperRuntimePermissions()
	{
		foreach ([self::PIPER_BIN, self::PIPER_RUNTIME_DIR . '/venv/bin/python', self::PIPER_RUNTIME_DIR . '/venv/bin/python3'] as $path) {
			if (file_exists($path)) {
				@chmod($path, 0755);
			}
		}
		if ((file_exists('/usr/local/bin/piper') || is_link('/usr/local/bin/piper')) && $this->isSlsOwnedPiperWrapper('/usr/local/bin/piper')) {
			@chmod('/usr/local/bin/piper', 0755);
		}
	}

	private function secureExecutableRuntimeTree()
	{
		if (!is_dir(self::RUNTIME_DIR)) {
			return;
		}
		$this->runCommand('/bin/chown -R root:root ' . escapeshellarg(self::RUNTIME_DIR));
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::RUNTIME_DIR) . ' -type d -exec chmod 755 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::RUNTIME_DIR) . ' -type f -exec chmod 644 {} +');
		if (is_dir(self::PIPER_RUNTIME_DIR . '/venv/bin')) {
			$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PIPER_RUNTIME_DIR . '/venv/bin') . ' -type f -exec chmod 755 {} +');
		}
		foreach (['sls_mass_notify_weather_poll.sh', 'sls_mass_notify_nws_poll.sh', 'sls_mass_notify_schedule_worker.php', 'sls_mass_notify_test.sh', 'sls_mass_notify_update.sh', 'sls_mass_notify_maintenance.sh', 'sls_mass_notify_uninstall.sh', 'sls_mass_notify_install_piper_voices.sh', 'sls_mass_notify_xweather_poll.py', 'sls_branded_email.py', 'sls_branded_discord.py', 'sls_notification_destinations.py', 'sls_system_notifications.py', 'sls_nws_status.py', 'sls_notify.py', 'sls_config.py'] as $file) {
			$path = self::RUNTIME_DIR . '/' . $file;
			if (is_file($path)) {
				@chmod($path, 0755);
			}
		}
		$this->repairPiperRuntimePermissions();
	}

	private function removePiperWrapper()
	{
		$wrapper = '/usr/local/bin/piper';
		if (is_link($wrapper) && readlink($wrapper) === self::PIPER_BIN) {
			@unlink($wrapper);
		} elseif (is_file($wrapper) && strpos((string)@file_get_contents($wrapper), self::PIPER_BIN) !== false) {
			@unlink($wrapper);
		}
	}

	private function removeRuntimeIntegrationFiles()
	{
		$preservePiperRuntime = getenv('SLS_MASS_NOTIFY_PRESERVE_PIPER_RUNTIME') === '1';
		foreach ([
			self::RUNTIME_DIR,
			'/var/www/html/api/sipnotify',
			'/var/www/html/api/sls-mass-notify',
			'/var/www/html/sls_mass_notify',
		] as $path) {
			if (is_dir($path) && !is_link($path)) {
				if ($path === self::RUNTIME_DIR && $preservePiperRuntime) {
					foreach (scandir($path) ?: [] as $entry) {
						if ($entry === '.' || $entry === '..' || $entry === 'piper') {
							continue;
						}
						$this->runCommand('/bin/rm -rf ' . escapeshellarg($path . '/' . $entry));
					}
				} else {
					$this->runCommand('/bin/rm -rf ' . escapeshellarg($path));
				}
			}
		}
		foreach ([
			'/var/lib/asterisk/sounds/' . self::ASTERISK_SOUND_PREFIX,
			'/var/lib/asterisk/sounds/en/' . self::ASTERISK_SOUND_PREFIX,
		] as $link) {
			if (is_link($link) && readlink($link) === self::SOUNDS_DIR) {
				@unlink($link);
			}
		}
		$signer = '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh';
		if (is_file($signer)) {
			@unlink($signer);
		}
	}

	private function ensurePiperVoices()
	{
		if (is_executable(self::PIPER_VOICE_INSTALL_SCRIPT)) {
			@exec('/usr/bin/timeout 1800 ' . escapeshellarg(self::PIPER_VOICE_INSTALL_SCRIPT) . ' >/dev/null 2>&1', $output, $exitCode);
		}

		$failures = $this->getMissingPiperVoiceFiles();
		foreach ($failures as $file) {
			$url = $this->getPiperVoiceDownloads()[$file] ?? '';
			if ($url === '') {
				continue;
			}
			$target = self::PIPER_VOICE_DIR . '/' . $file;
			if ($this->isValidPiperVoiceFile($target)) {
				continue;
			}
			if (!$this->downloadPiperVoiceFile($url, $target)) {
				continue;
			}
		}
		$failures = $this->getMissingPiperVoiceFiles();
		if (!empty($failures)) {
			$this->updateStatusData([
				'last_fault_at' => date('c'),
				'last_fault_stage' => 'piper_voice_download',
				'last_fault_message' => 'Unable to download Piper voice file(s): ' . implode(', ', $failures),
			]);
			throw new \RuntimeException(sprintf(
				_('Required Piper voice files could not be installed: %s'),
				implode(', ', $failures)
			));
		} else {
			$this->updateStatusData([
				'last_fault_at' => '',
				'last_fault_stage' => '',
				'last_fault_message' => '',
				'last_piper_voice_install_at' => date('c'),
				'last_piper_voice_install_status' => 'ok',
			]);
		}
	}

	private function getMissingPiperVoiceFiles()
	{
		$missing = [];
		foreach (array_keys($this->getPiperVoiceDownloads()) as $file) {
			$target = self::PIPER_VOICE_DIR . '/' . $file;
			if (!$this->isValidPiperVoiceFile($target)) {
				$missing[] = $file;
			}
		}
		return $missing;
	}

	private function getPiperVoiceDownloads()
	{
		$base = 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/en/en_US';
		return [
			'en_US-lessac-low.onnx' => $base . '/lessac/low/en_US-lessac-low.onnx',
			'en_US-lessac-low.onnx.json' => $base . '/lessac/low/en_US-lessac-low.onnx.json',
			'en_US-amy-low.onnx' => $base . '/amy/low/en_US-amy-low.onnx',
			'en_US-amy-low.onnx.json' => $base . '/amy/low/en_US-amy-low.onnx.json',
			'en_US-ryan-low.onnx' => $base . '/ryan/low/en_US-ryan-low.onnx',
			'en_US-ryan-low.onnx.json' => $base . '/ryan/low/en_US-ryan-low.onnx.json',
		];
	}

	private function downloadPiperVoiceFile($url, $target)
	{
		$dir = dirname($target);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$tmp = $target . '.download';
		@unlink($tmp);
		$command = '';
		if (is_executable('/usr/bin/curl')) {
			$command = '/usr/bin/curl -fL --retry 3 --connect-timeout 20 --max-time 900 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
		} elseif (is_executable('/usr/bin/wget')) {
			$command = '/usr/bin/wget -q --timeout=900 --tries=3 -O ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
		}
		if ($command === '') {
			return false;
		}
		exec($command . ' >/dev/null 2>&1', $output, $exitCode);
		if ($exitCode !== 0 || !$this->isValidPiperVoiceFile($tmp)) {
			@unlink($tmp);
			return false;
		}
		@rename($tmp, $target);
		@chmod($target, 0644);
		@chown($target, 'asterisk');
		@chgrp($target, 'asterisk');
		return true;
	}

	private function isValidPiperVoiceFile($path)
	{
		if (!is_readable($path)) {
			return false;
		}
		$name = basename((string)$path);
		$name = preg_replace('/\.download$/', '', $name);
		$hashes = [
			'en_US-lessac-low.onnx' => 'f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375',
			'en_US-lessac-low.onnx.json' => '45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a',
			'en_US-amy-low.onnx' => 'a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3',
			'en_US-amy-low.onnx.json' => '2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb',
			'en_US-ryan-low.onnx' => '8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf',
			'en_US-ryan-low.onnx.json' => 'b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60',
		];
		if (!isset($hashes[$name]) || !hash_equals($hashes[$name], (string)hash_file('sha256', $path))) {
			return false;
		}
		if (substr($name, -5) === '.onnx') {
			return filesize($path) !== false && filesize($path) > 1000000;
		}
		if (substr($name, -10) === '.onnx.json') {
			$decoded = json_decode((string)file_get_contents($path), true);
			return is_array($decoded) && !empty($decoded);
		}
		return false;
	}

	private function ensureAmiUser()
	{
		$configuredManagerHost = strtolower(trim((string)$this->getFreePbxConfigValue('ASTMANAGERHOST')));
		if ($configuredManagerHost === '') {
			$configuredManagerHost = 'localhost';
		}
		if (!in_array($configuredManagerHost, ['localhost', '127.0.0.1', '::1'], true)) {
			throw new \RuntimeException(_('SLS Mass Notify requires the FreePBX Asterisk Manager host to use loopback. Review ASTMANAGERHOST before installation.'));
		}
		$settings = $this->getActiveSettings();
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$username = $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami');
		$password = $this->normalizeEndpointPassword($ami['password'] ?? '');
		if ($password === '') {
			throw new \RuntimeException(_('Protected central configuration has an invalid AMI credential. Repair or restore the configuration before installing runtime integration.'));
		}
		$manager = null;
		try {
			$manager = \FreePBX::Manager();
		} catch (\Throwable $e) {
			$manager = null;
		}
		if ($manager !== null) {
			try {
				if ($manager->isExist_manager('sls_mass_notify', true)) {
					$manager->del_manager('sls_mass_notify', true);
				}
				if ($manager->isExist_manager($username, true)) {
					$manager->del_manager($username, true);
				}
				$manager->add_manager(
					$username,
					$password,
					'0.0.0.0/0.0.0.0',
					'127.0.0.1/255.255.255.255',
					'system,call,originate',
					'system,call,originate',
					1000
				);
				$this->removeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI');
			} catch (\Throwable $e) {
				$block = "[{$username}]\n"
					. "secret = {$password}\n"
					. "deny = 0.0.0.0/0.0.0.0\n"
					. "permit = 127.0.0.1/255.255.255.255\n"
					. "read = system,call,originate\n"
					. "write = system,call,originate\n";
				$this->writeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI', $block);
			}
		} else {
			$block = "[{$username}]\n"
				. "secret = {$password}\n"
				. "deny = 0.0.0.0/0.0.0.0\n"
				. "permit = 127.0.0.1/255.255.255.255\n"
				. "read = system,call,originate\n"
				. "write = system,call,originate\n";
			$this->writeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI', $block);
		}
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('manager reload'));
	}

	private function getFreePbxConfigValue($key)
	{
		try {
			return (string)\FreePBX::Config()->get((string)$key);
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function detectAmiPort()
	{
		$port = (int)$this->getFreePbxConfigValue('ASTMANAGERPORT');
		return $port >= 1 && $port <= 65535 ? $port : 5038;
	}

	private function ensureDialplan()
	{
		$path = '/etc/asterisk/extensions_custom.conf';
		$current = is_readable($path) ? (string)file_get_contents($path) : '';
		if (strpos($current, '[nws-alert-audio]') !== false) {
			$current = str_replace('[nws-alert-audio]', '[sls-alert-audio]', $current);
			$current = str_replace('[nws-play-alert]', '[sls-alert-play]', $current);
			$current = str_replace('U(nws-play-alert^${NWS_SAFE_SOUND})', 'U(sls-alert-play^${SLS_SAFE_SOUND})', $current);
			$current = str_replace('NoOp(NWS direct alert audio to ${EXTEN})', 'NoOp(SLS Mass Notification direct alert audio to ${EXTEN})', $current);
			$current = str_replace('NoOp(Playing NWS alert audio ${ARG1})', 'NoOp(Playing SLS Mass Notification alert audio ${ARG1})', $current);
			$current = str_replace('?NWS System:${NWS_CALLERID_NAME}', '?SLS Mass Notification System:${NWS_CALLERID_NAME}', $current);
			$current = str_replace('?NWS:${NWS_CALLERID_NUM}', '?SLS:${NWS_CALLERID_NUM}', $current);
			file_put_contents($path, $current, LOCK_EX);
		}
		$current = $this->removeUnmanagedDialplanContext($current, 'sls-alert-audio');
		$current = $this->removeUnmanagedDialplanContext($current, 'sls-alert-play');
		$current = $this->removeUnmanagedDialplanContext($current, 'sls-alert-autoanswer');
		file_put_contents($path, trim($current) === '' ? '' : rtrim($current) . "\n", LOCK_EX);
			$block = "[sls-alert-autoanswer]\n"
				. "exten => s,1,NoOp(SLS Mass Notification PJSIP auto-answer headers)\n"
				. " same => n,Set(SLS_AUTOANSWER_CONTACT=\${CHANNEL(contact)})\n"
				. " same => n,Set(SLS_AUTOANSWER_DEVICE=\${DB(DEVICE/\${ARG1}/dial)})\n"
				. " same => n,Set(SLS_AUTOANSWER_AOR=\${CUT(SLS_AUTOANSWER_DEVICE,/,2)})\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_AOR}\"=\"\"]?Set(SLS_AUTOANSWER_AOR=\${ARG1}))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_CONTACT}\"=\"\"]?Set(SLS_AUTOANSWER_CONTACTS=\${PJSIP_AOR(\${SLS_AUTOANSWER_AOR},contact)}))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_CONTACT}\"=\"\"]?Set(SLS_AUTOANSWER_CONTACT=\${CUT(SLS_AUTOANSWER_CONTACTS,\\,,1)}))\n"
				. " same => n,Set(SLS_AUTOANSWER_UA=\${TOLOWER(\${PJSIP_CONTACT(\${SLS_AUTOANSWER_CONTACT},user_agent)})})\n"
				. " same => n,Set(SLS_ALERT_INFO=Ring Answer)\n"
				. " same => n,Set(SLS_CALL_INFO=<sip:sls-mass-notify>\\;answer-after=0)\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:7}\"=\"yealink\"]?Set(SLS_ALERT_INFO=Intercom))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:9}\"=\"panasonic\"]?Set(SLS_ALERT_INFO=Intercom))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:4}\"=\"poly\"]?Set(SLS_ALERT_INFO=info=Auto Answer))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:5}\"=\"mitel\"]?Set(SLS_CALL_INFO=<sip:broadworks.net>\\;answer-after=0))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:9}\"=\"openstage\"]?Set(SLS_ALERT_INFO=<http://example.com>\\;info=alert-autoanswer))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:6}\"=\"digium\"]?Set(SLS_ALERT_INFO=ring-answer))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:9}\"=\"sangoma p\"]?Set(SLS_ALERT_INFO=intercom))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:9}\"=\"sangoma s\"]?Set(SLS_ALERT_INFO=intercom))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:23}\"=\"sangoma macos softphone\"]?Set(SLS_ALERT_INFO=Direct-Intercom))\n"
				. " same => n,ExecIf($[\"\${SLS_AUTOANSWER_UA:0:25}\"=\"sangoma windows softphone\"]?Set(SLS_ALERT_INFO=Direct-Intercom))\n"
				. " same => n,NoOp(SLS Mass Notification auto-answer user-agent \${SLS_AUTOANSWER_UA} alert-info \${SLS_ALERT_INFO})\n"
				. " same => n,Set(PJSIP_HEADER(add,Alert-Info)=\${SLS_ALERT_INFO})\n"
				. " same => n,Set(PJSIP_HEADER(add,Call-Info)=\${SLS_CALL_INFO})\n"
				. " same => n,Set(PJSIP_HEADER(add,Answer-Mode)=Auto)\n"
				. " same => n,Set(PJSIP_HEADER(add,X-AutoAnswer)=true)\n"
				. " same => n,Return()\n\n"
				. "[sls-alert-audio]\n"
				. "exten => _X!,1,NoOp(SLS Mass Notification audio to \${EXTEN})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification page initiated for \${EXTEN} sound \${SLS_SOUND})\n"
				. " same => n,Verbose(1,SLS Mass Notification page initiated for \${EXTEN} sound \${SLS_SOUND})\n"
				. " same => n,Set(SLS_SAFE_SOUND=\${FILTER(0-9A-Za-z_/-,\${SLS_SOUND})})\n"
			. " same => n,GotoIf($[\"\${SLS_SAFE_SOUND}\"=\"\"]?done)\n"
			. " same => n,Set(__SLS_SAFE_SOUND=\${SLS_SAFE_SOUND})\n"
			. " same => n,Set(SLS_DIAL=\${PJSIP_DIAL_CONTACTS(\${EXTEN})})\n"
			. " same => n,Set(SLS_DEVICE_DIAL=\${DB(DEVICE/\${EXTEN}/dial)})\n"
			. " same => n,Set(SLS_DEVICE_AOR=\${CUT(SLS_DEVICE_DIAL,/,2)})\n"
			. " same => n,ExecIf($[\"\${SLS_DEVICE_AOR}\"=\"\"]?Set(SLS_DEVICE_AOR=\${EXTEN}))\n"
			. " same => n,ExecIf($[\"\${SLS_DIAL}\"=\"\"]?Set(SLS_DIAL=\${PJSIP_DIAL_CONTACTS(\${SLS_DEVICE_AOR})}))\n"
			. " same => n,ExecIf($[\"\${SLS_DIAL}\"=\"\"]?Set(SLS_DIAL=\${SLS_DEVICE_DIAL}))\n"
			. " same => n,ExecIf($[\"\${SLS_DIAL}\"=\"\"]?Set(SLS_DIAL=PJSIP/\${EXTEN}))\n"
				. " same => n,GotoIf($[\"\${SLS_DIAL}\"=\"\"]?done)\n"
				. " same => n,NoOp(SLS Mass Notification dial string \${SLS_DIAL})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Verbose(1,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Set(CALLERID(name)=\${IF($[\"\${SLS_CALLERID_NAME}\"=\"\"]?SLS Mass Notification System:\${SLS_CALLERID_NAME})})\n"
			. " same => n,Set(CALLERID(num)=\${IF($[\"\${SLS_CALLERID_NUM}\"=\"\"]?SLS:\${SLS_CALLERID_NUM})})\n"
			. " same => n,Set(__SIP_URI_OPTIONS=intercom=true)\n"
				. " same => n,Page(\${SLS_DIAL},b(sls-alert-autoanswer^s^1(\${EXTEN}))A(\${SLS_SAFE_SOUND})inq,5)\n"
				. " same => n,Log(NOTICE,SLS Mass Notification page completed for \${EXTEN})\n"
				. " same => n,Verbose(1,SLS Mass Notification page completed for \${EXTEN})\n"
				. " same => n(done),Hangup()\n";
		$this->writeManagedBlock('/etc/asterisk/extensions_custom.conf', 'SLS Mass Notifications Dialplan', $block);
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
	}

	private function removeUnmanagedDialplanContext($content, $context)
	{
		$start = '; BEGIN SLS Mass Notifications Dialplan';
		$end = '; END SLS Mass Notifications Dialplan';
		$contextHeader = '[' . $context . ']';
		$lines = preg_split('/\R/', (string)$content);
		$output = [];
		$inManagedBlock = false;
		$skipContext = false;

		foreach ($lines as $line) {
			if (trim($line) === $start) {
				$inManagedBlock = true;
				$skipContext = false;
				$output[] = $line;
				continue;
			}
			if (trim($line) === $end) {
				$inManagedBlock = false;
				$skipContext = false;
				$output[] = $line;
				continue;
			}
			if (!$inManagedBlock && trim($line) === $contextHeader) {
				$skipContext = true;
				continue;
			}
			if ($skipContext && preg_match('/^\s*\[[^\]]+\]\s*$/', $line)) {
				$skipContext = false;
			}
			if (!$skipContext) {
				$output[] = $line;
			}
		}

		return rtrim(implode("\n", $output)) . "\n";
	}

	private function ensureApacheConfig()
	{
		$block = "# Southland Servers Mass Notifications Server\n"
			. "<Directory /var/www/html/api/sipnotify>\n"
			. "    Require all granted\n"
			. "    Options -Indexes\n"
			. "    AllowOverride All\n"
			. "    SetEnvIfNoCase Authorization \"^(.*)$\" HTTP_AUTHORIZATION=$1\n"
			. "</Directory>\n"
			. "<Directory /var/www/html/api/sls-mass-notify>\n"
			. "    Require all granted\n"
			. "    Options -Indexes\n"
			. "    AllowOverride All\n"
			. "    SetEnvIfNoCase Authorization \"^(.*)$\" HTTP_AUTHORIZATION=$1\n"
			. "</Directory>\n"
			. "<Directory /var/www/html/sls_mass_notify>\n"
			. "    Require all granted\n"
			. "    Options -Indexes\n"
			. "</Directory>\n";
		$path = '/etc/apache2/conf-available/sls-mass-notify.conf';
		if (is_dir('/etc/apache2/conf-available')) {
			file_put_contents($path, $block, LOCK_EX);
			@chmod($path, 0644);
			$this->runCommand('/usr/sbin/a2enconf sls-mass-notify');
			$this->runCommand('/bin/systemctl reload apache2');
		}
	}

	private function ensureDashboardWidget()
	{
		$overview = '/var/www/html/admin/modules/dashboard/sections/Overview.class.php';
		$backup = self::PLUGIN_DATA_DIR . '/backups/dashboard/Overview.class.php';
		if (is_readable($backup)) {
			@copy($backup, $overview);
			@unlink($backup);
		} elseif (is_readable($overview)) {
			$current = (string)file_get_contents($overview);
			$clean = $this->removeLegacyDashboardOverviewPatch($current);
			if ($clean !== $current) {
				file_put_contents($overview, $clean, LOCK_EX);
			}
		}
		@chmod($overview, 0644);
		@chown($overview, 'asterisk');
		@chgrp($overview, 'asterisk');
		$this->copyRuntimeFile(__DIR__ . '/dashboard/sections/SlsMassNotifyAnnouncement.class.php', '/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php', 0644);
		$this->copyRuntimeFile(__DIR__ . '/dashboard/views/sections/sls-mass-notify-announcement.php', '/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php', 0644);
		@unlink('/var/www/html/admin/modules/dashboard/sections/NwsAlertsAnnouncement.class.php');
		@unlink('/var/www/html/admin/modules/dashboard/views/sections/slsmassnotifyserver-announcement.php');
		$this->refreshDashboardHookIndex();
	}

	private function refreshDashboardHookIndex()
	{
		$hooksFile = '/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php';
		if (!is_readable($hooksFile)) {
			throw new \RuntimeException(_('FreePBX Dashboard hook loader is missing or unreadable.'));
		}
		require_once $hooksFile;
		if (!class_exists('DashboardHooks')) {
			throw new \RuntimeException(_('FreePBX Dashboard hook loader could not be initialized.'));
		}

		$dashboard = \FreePBX::Dashboard();
		$visualOrder = $dashboard->getConfig('visualorder');
		$hooks = \DashboardHooks::genHooks(is_array($visualOrder) ? $visualOrder : []);
		$found = false;
		foreach ((array)$hooks as $page) {
			foreach ((array)($page['entries'] ?? []) as $entry) {
				if (($entry['rawname'] ?? '') === 'SlsMassNotifyAnnouncement'
					&& ($entry['section'] ?? '') === 'sls_mass_notify_announcement') {
					$found = true;
					break 2;
				}
			}
		}
		if (!$found) {
			throw new \RuntimeException(_('FreePBX Dashboard did not discover the Mass Notify announcement panel.'));
		}
		$dashboard->setConfig('allhooks', $hooks);
	}

	private function removeDashboardWidget()
	{
		$overview = '/var/www/html/admin/modules/dashboard/sections/Overview.class.php';
		if (is_readable($overview)) {
			$current = (string)file_get_contents($overview);
			file_put_contents($overview, $this->removeLegacyDashboardOverviewPatch($current), LOCK_EX);
		}
		@unlink('/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php');
		@unlink('/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php');
		@unlink('/var/lib/asterisk/bin/sls_mass_notify');
		@unlink('/var/lib/asterisk/bin/sls_mass_notify_test.sh');
		$this->refreshDashboardHookIndexAfterRemoval();
	}

	private function refreshDashboardHookIndexAfterRemoval()
	{
		$hooksFile = '/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php';
		if (!is_readable($hooksFile)) {
			throw new \RuntimeException(_('FreePBX Dashboard hook loader is missing after Mass Notify removal.'));
		}
		require_once $hooksFile;
		if (!class_exists('DashboardHooks')) {
			throw new \RuntimeException(_('FreePBX Dashboard hook loader could not be initialized after Mass Notify removal.'));
		}
		$dashboard = \FreePBX::Dashboard();
		$visualOrder = $dashboard->getConfig('visualorder');
		$hooks = \DashboardHooks::genHooks(is_array($visualOrder) ? $visualOrder : []);
		foreach ((array)$hooks as $page) {
			foreach ((array)($page['entries'] ?? []) as $entry) {
				if (($entry['rawname'] ?? '') === 'SlsMassNotifyAnnouncement') {
					throw new \RuntimeException(_('FreePBX Dashboard still discovers the removed Mass Notify announcement panel.'));
				}
			}
		}
		$dashboard->setConfig('allhooks', $hooks);
	}

	private function removeAmiUsers()
	{
		$settings = $this->getActiveSettings();
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$candidates = array_values(array_unique(array_filter([
			(string)($ami['username'] ?? ''),
			'slsmassnotify',
			'sls_mass_notify',
			'nws_push',
		])));
		try {
			$manager = \FreePBX::Manager();
			foreach ($candidates as $username) {
				if ($manager->isExist_manager($username, true)) {
					$manager->del_manager($username, true);
				}
			}
		} catch (\Throwable $e) {
			// The standalone uninstaller repeats this operation and reports failure.
		}
		@unlink('/etc/asterisk/slsmassnotify');
	}

	private function removeApacheConfig()
	{
		$this->runCommand('/usr/sbin/a2disconf sls-mass-notify');
		@unlink('/etc/apache2/conf-enabled/sls-mass-notify.conf');
		@unlink('/etc/apache2/conf-available/sls-mass-notify.conf');
		@unlink('/var/lib/apache2/conf/enabled_by_admin/sls-mass-notify');
		@unlink('/var/lib/apache2/conf/disabled_by_admin/sls-mass-notify');
		$this->runCommand('/usr/bin/systemctl reload apache2');
	}

	private function removeLegacyDashboardOverviewPatch($content)
	{
		$content = preg_replace(
			'/\n\s*\$final\[\$i\]\s*=\s*\$this->checkSlsMassNotify\(\);\s*\$final\[\$i\]\[\'title\'\]\s*=\s*_\("Mass Notifications (?:Plugin|Module)"\);\s*\$i\+\+;\s*/s',
			"\n",
			(string)$content
		);
		$content = preg_replace(
			'/\n\s*private function checkSlsMassNotify\(\)\s*\{.*?(?=\n\s*private function genAlertGlyphicon\()/s',
			"\n",
			(string)$content
		);
		return (string)$content;
	}

	private function ensureMenuPlacement()
	{
		$path = '/var/www/html/admin/views/menu_items.php';
		if (!is_readable($path) || !is_writable($path)) {
			return;
		}
		$current = (string)file_get_contents($path);
		$current = $this->removeMenuPlacementBlock($current);
		$needles = [
			"\telse if (\$a == 'other')\n\t\treturn 1;\n",
			"\telse if (\$a == 'other')\n\t\treturn true;\n",
		];
		$insert = "\t// SLS Mass Notifications menu placement: keep Mass Notify after UCP/User Panel.\n"
			. "\telse if (in_array(\$a, ['mass notifications', 'mass notify'], true) && \$b == 'other')\n"
			. "\t\treturn -1;\n"
			. "\telse if (\$a == 'other' && in_array(\$b, ['mass notifications', 'mass notify'], true))\n"
			. "\t\treturn 1;\n"
			. "\telse if (in_array(\$a, ['mass notifications', 'mass notify'], true) && in_array(\$b, ['user panel', 'ucp'], true))\n"
			. "\t\treturn 1;\n"
			. "\telse if (in_array(\$a, ['user panel', 'ucp'], true) && in_array(\$b, ['mass notifications', 'mass notify'], true))\n"
			. "\t\treturn -1;\n"
			. "\telse if (in_array(\$a, ['mass notifications', 'mass notify'], true))\n"
			. "\t\treturn 1;\n"
			. "\telse if (in_array(\$b, ['mass notifications', 'mass notify'], true))\n"
			. "\t\treturn -1;\n";
		$needle = null;
		foreach ($needles as $candidate) {
			if (strpos($current, $candidate) !== false) {
				$needle = $candidate;
				break;
			}
		}
		if ($needle === null) {
			return;
		}
		file_put_contents($path, str_replace($needle, $insert . $needle, $current), LOCK_EX);
		@chmod($path, 0644);
	}

	private function removeMenuPlacement()
	{
		$path = '/var/www/html/admin/views/menu_items.php';
		if (!is_readable($path) || !is_writable($path)) {
			return;
		}
		$current = (string)file_get_contents($path);
		$updated = $this->removeMenuPlacementBlock($current);
		if ($updated !== $current) {
			file_put_contents($path, $updated, LOCK_EX);
			@chmod($path, 0644);
		}
	}

	private function removeMenuPlacementBlock($content)
	{
		$content = preg_replace(
			"/\t\/\/ SLS Mass Notifications menu placement:.*?(?=\telse if \\(\\\$a == 'other'\\)\n\t\treturn (?:1|true);\n)/s",
			'',
			$content
		);
		$legacy = [
			"\telse if (\$a == 'mass notifications' && \$b == 'other')\n\t\treturn -1;\n",
			"\telse if (\$a == 'other' && \$b == 'mass notifications')\n\t\treturn 1;\n",
			"\telse if (\$a == 'mass notifications' && \$b == 'user panel')\n\t\treturn 1;\n",
			"\telse if (\$a == 'user panel' && \$b == 'mass notifications')\n\t\treturn -1;\n",
			"\telse if (\$a == 'mass notifications')\n\t\treturn 1;\n",
			"\telse if (\$b == 'mass notifications')\n\t\treturn -1;\n",
			"\telse if (\$a == 'mass notify' && \$b == 'other')\n\t\treturn -1;\n",
			"\telse if (\$a == 'other' && \$b == 'mass notify')\n\t\treturn 1;\n",
			"\telse if (\$a == 'mass notify' && \$b == 'user panel')\n\t\treturn 1;\n",
			"\telse if (\$a == 'user panel' && \$b == 'mass notify')\n\t\treturn -1;\n",
			"\telse if (\$a == 'mass notify')\n\t\treturn 1;\n",
			"\telse if (\$b == 'mass notify')\n\t\treturn -1;\n",
		];
		return str_replace($legacy, '', (string)$content);
	}

	private function writeManagedBlock($path, $name, $block)
	{
		$prefix = strpos((string)$path, '/etc/apache') === 0 ? '#' : ';';
		$start = $prefix . ' BEGIN ' . $name;
		$end = $prefix . ' END ' . $name;
		$legacyStart = ';-- BEGIN ' . $name . ' --';
		$legacyEnd = ';-- END ' . $name . ' --';
		$current = is_readable($path) ? (string)file_get_contents($path) : '';
		$current = preg_replace('/^' . preg_quote($legacyStart, '/') . '\R?/m', '', $current);
		$current = preg_replace('/^' . preg_quote($legacyEnd, '/') . '\R?/m', '', (string)$current);
		$managed = $start . "\n" . rtrim($block) . "\n" . $end . "\n";
		$pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . "\\n?/s";
		$legacyPattern = '/' . preg_quote($legacyStart, '/') . '.*?' . preg_quote($legacyEnd, '/') . "\\n?/s";
		if (preg_match($pattern, $current)) {
			$current = preg_replace($pattern, $managed, $current);
		} elseif (preg_match($legacyPattern, $current)) {
			$current = preg_replace($legacyPattern, $managed, $current);
		} else {
			$current = rtrim($current) . "\n\n" . $managed;
		}
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		file_put_contents($path, $current, LOCK_EX);
		@chmod($path, 0644);
	}

	private function removeManagedBlock($path, $name)
	{
		if (!is_readable($path)) {
			return;
		}
		$prefix = strpos((string)$path, '/etc/apache') === 0 ? '#' : ';';
		$start = $prefix . ' BEGIN ' . $name;
		$end = $prefix . ' END ' . $name;
		$legacyStart = ';-- BEGIN ' . $name . ' --';
		$legacyEnd = ';-- END ' . $name . ' --';
		$current = (string)file_get_contents($path);
		$current = preg_replace('/^' . preg_quote($legacyStart, '/') . '\R?/m', '', $current);
		$current = preg_replace('/^' . preg_quote($legacyEnd, '/') . '\R?/m', '', (string)$current);
		$pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . "\\n?/s";
		$legacyPattern = '/' . preg_quote($legacyStart, '/') . '.*?' . preg_quote($legacyEnd, '/') . "\\n?/s";
		$updated = preg_replace($pattern, '', $current);
		$updated = preg_replace($legacyPattern, '', (string)$updated);
		if ($updated !== null && $updated !== $current) {
			file_put_contents($path, trim($updated) === '' ? '' : rtrim($updated) . "\n", LOCK_EX);
			@chmod($path, 0644);
		}
	}

	private function signLocalModulesIfAvailable($required = true)
	{
		if (getenv('SLS_MASS_NOTIFY_DEFER_SIGNING') === '1') {
			return;
		}
		$signer = '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh';
		clearstatcache(true, $signer);
		$signerMode = @fileperms($signer);
		$signerOwner = @fileowner($signer);
		$signerGroup = @filegroup($signer);
		$signerIsSafe = is_file($signer)
			&& !is_link($signer)
			&& is_executable($signer)
			&& $signerOwner === 0
			&& $signerGroup === 0
			&& $signerMode !== false
			&& ($signerMode & 0777) === 0755;
		if (!$signerIsSafe) {
			if ($required) {
				throw new \RuntimeException(_('The protected PBX-local module signer is missing or unsafe.'));
			}
			return;
		}
		$moduleRawName = basename(__DIR__);
		$modules = [$moduleRawName];
		if (is_dir('/var/www/html/admin/modules/dashboard')) {
			$modules[] = 'dashboard';
		}
		// Menu placement modifies a framework-owned view, so cover that managed
		// integration with the same trusted local signature.
		if (is_dir('/var/www/html/admin/modules/framework')) {
			$modules[] = 'framework';
		}
		foreach ($modules as $module) {
			$output = [];
			$exitCode = 0;
			@exec(
				'/usr/bin/timeout --signal=TERM 360 '
					. escapeshellarg($signer) . ' ' . escapeshellarg($module) . ' 2>&1',
				$output,
				$exitCode
			);
			if ($exitCode !== 0 && $required) {
				$detail = trim(implode(' ', array_slice($output, -3)));
				$detail = preg_replace('/\s+/', ' ', $detail);
				throw new \RuntimeException(sprintf(
					_('Unable to create a trusted local signature for %s.%s'),
					$module,
					$detail !== '' ? ' ' . substr($detail, 0, 500) : ''
				));
			}
		}
	}

	private function repairPostUninstallSignatures()
	{
		// Never run nested fwconsole module transactions from a module uninstall
		// hook. The standalone uninstaller restores stock modules after this
		// transaction; native Module Admin removal can safely retain local trusted
		// signatures for the two integration-owned files it just restored.
		$this->signLocalModulesIfAvailable(false);
	}

	private function runCommand($command)
	{
		if ($command === '') {
			return;
		}
		@exec($command . ' >/dev/null 2>&1');
	}

	private function copyRuntimeFile($source, $target, $mode = 0644, $overwrite = true)
	{
		if (!is_file($source) || !is_readable($source) || is_link($source)) {
			throw new \RuntimeException(sprintf(_('Required packaged file is missing or unsafe: %s'), $source));
		}
		if (is_link($target)) {
			throw new \RuntimeException(sprintf(_('Refusing to replace a symbolic-link runtime target: %s'), $target));
		}
		if (!$overwrite && file_exists($target)) {
			return;
		}
		$dir = dirname($target);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException(sprintf(_('Unable to create runtime directory: %s'), $dir));
		}
		if (!@copy($source, $target) || !is_file($target)) {
			throw new \RuntimeException(sprintf(_('Unable to install runtime file: %s'), $target));
		}
		if (!@chmod($target, $mode)) {
			throw new \RuntimeException(sprintf(_('Unable to secure runtime file permissions: %s'), $target));
		}
	}

	private function copyRuntimeDirectory($source, $target, $mode = 0644, $overwrite = true)
	{
		if (!is_dir($source) || is_link($source)) {
			throw new \RuntimeException(sprintf(_('Required packaged directory is missing or unsafe: %s'), $source));
		}
		if (is_link($target)) {
			throw new \RuntimeException(sprintf(_('Refusing to use a symbolic-link runtime directory: %s'), $target));
		}
		if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
			throw new \RuntimeException(sprintf(_('Unable to create runtime directory: %s'), $target));
		}
		$entries = scandir($source);
		if ($entries === false) {
			throw new \RuntimeException(sprintf(_('Unable to read packaged runtime directory: %s'), $source));
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === '__pycache__' || substr($entry, -4) === '.pyc') {
				continue;
			}
			$src = $source . '/' . $entry;
			$dst = $target . '/' . $entry;
			if (is_dir($src)) {
				$this->copyRuntimeDirectory($src, $dst, $mode, $overwrite);
			} else {
				$this->copyRuntimeFile($src, $dst, $mode, $overwrite);
			}
		}
	}

	private function pruneRuntimeDirectory($source, $target, array $preserve = [])
	{
		if (!is_dir($target)) {
			return;
		}
		if (!is_dir($source) || is_link($source) || is_link($target)) {
			throw new \RuntimeException(sprintf(_('Unable to safely reconcile runtime directory: %s'), $target));
		}
		$sourceEntries = array_fill_keys(array_values(array_filter(scandir($source) ?: [], static function ($entry) {
			return $entry !== '.' && $entry !== '..' && $entry !== '__pycache__' && substr($entry, -4) !== '.pyc';
		})), true);
		$preserveEntries = array_fill_keys($preserve, true);
		foreach (scandir($target) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || isset($preserveEntries[$entry])) {
				continue;
			}
			$targetPath = $target . '/' . $entry;
			if (!isset($sourceEntries[$entry])) {
				if (is_dir($targetPath) && !is_link($targetPath)) {
					$this->runCommand('/bin/rm -rf ' . escapeshellarg($targetPath));
				} else {
					@unlink($targetPath);
				}
				continue;
			}
			$sourcePath = $source . '/' . $entry;
			if (is_dir($sourcePath) && is_dir($targetPath) && !is_link($targetPath)) {
				$this->pruneRuntimeDirectory($sourcePath, $targetPath);
			}
		}
	}

	private function ensureCronJob()
	{
		$this->removeLegacyNwsCronJob();
		$cron = $this->FreePBX->Cron();
		$hasPoll = false;
		foreach ($cron->getAll() as $line) {
			$line = (string)$line;
			if (strpos($line, 'sls_mass_notify_nws_poll.sh') !== false) {
				$cron->remove($line);
				continue;
			}
			if (strpos($line, 'sls_mass_notify_weather_poll.sh') !== false) {
				if (strpos((string)$line, '/usr/bin/timeout 1200') === false || strpos((string)$line, '* * * * *') === false) {
					$cron->remove($line);
					continue;
				}
				$hasPoll = true;
			}
			if (strpos($line, 'sls_mass_notify_update.sh') !== false) {
				$cron->remove($line);
			}
			if (strpos($line, 'sls_mass_notify_schedule_worker.php') !== false) {
				// Remove every old/canonical copy, then add exactly one protected line.
				$cron->remove($line);
			}
		}
		if (!$hasPoll) {
			$cron->addLine('* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh');
		}
		$cron->addLine('* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php');
		$this->ensureRootUpdateCron();
	}

	private function removeLegacyNwsCronJob()
	{
		$current = [];
		exec('/usr/bin/crontab -l 2>/dev/null', $current);
		$filtered = [];
		$changed = false;
		foreach ($current as $line) {
			if (strpos((string)$line, '/usr/local/bin/nws_weather_alert.sh') !== false
				|| strpos((string)$line, 'nwsalerts_ensure_menu_patch.sh') !== false
				|| strpos((string)$line, 'sls_mass_notify_update.sh') !== false
				|| strpos((string)$line, 'sls_mass_notify_maintenance.sh') !== false) {
				$changed = true;
				continue;
			}
			$filtered[] = $line;
		}
		if (!$changed) {
			return;
		}
		$tmp = tempnam(sys_get_temp_dir(), 'sls-root-cron.');
		if ($tmp === false) {
			return;
		}
		file_put_contents($tmp, implode("\n", $filtered) . "\n");
		$this->runCommand('/usr/bin/crontab ' . escapeshellarg($tmp));
		@unlink($tmp);
	}

	private function ensureRootUpdateCron()
	{
		$current = [];
		exec('/usr/bin/crontab -l 2>/dev/null', $current);
		$filtered = [];
		foreach ($current as $line) {
			if (strpos((string)$line, 'sls_mass_notify_update.sh') !== false) {
				continue;
			}
			$filtered[] = $line;
		}
		$filtered[] = '* * * * * /usr/bin/timeout 900 /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh';
		$filtered[] = '17 */6 * * * /usr/bin/timeout 1800 /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh';
		$tmp = tempnam(sys_get_temp_dir(), 'sls-root-cron.');
		if ($tmp === false) {
			return;
		}
		file_put_contents($tmp, implode("\n", $filtered) . "\n");
		@chmod($tmp, 0600);
		$this->runCommand('/usr/bin/crontab ' . escapeshellarg($tmp));
		@unlink($tmp);
	}

	private function removeCronJob()
	{
		$cron = $this->FreePBX->Cron();
		foreach ($cron->getAll() as $line) {
			if (strpos((string)$line, 'sls_mass_notify_nws_poll.sh') !== false || strpos((string)$line, 'sls_mass_notify_weather_poll.sh') !== false || strpos((string)$line, 'sls_mass_notify_schedule_worker.php') !== false || strpos((string)$line, 'sls_mass_notify_update.sh') !== false) {
				$cron->remove($line);
			}
		}
		$current = [];
		exec('/usr/bin/crontab -l 2>/dev/null', $current);
		$filtered = array_values(array_filter($current, static function ($line) {
			return strpos((string)$line, 'sls_mass_notify_update.sh') === false
				&& strpos((string)$line, 'sls_mass_notify_maintenance.sh') === false
				&& strpos((string)$line, 'nwsalerts_ensure_menu_patch.sh') === false;
		}));
		if ($filtered !== $current) {
			$tmp = tempnam(sys_get_temp_dir(), 'sls-root-cron.');
			if ($tmp !== false) {
				file_put_contents($tmp, implode("\n", $filtered) . "\n");
				@chmod($tmp, 0600);
				$this->runCommand('/usr/bin/crontab ' . escapeshellarg($tmp));
				@unlink($tmp);
			}
		}
	}

	private function detectPbxHost()
	{
		$candidates = [
			$_SERVER['HTTP_HOST'] ?? '',
			$_SERVER['SERVER_NAME'] ?? '',
			gethostname() ?: '',
			'localhost',
		];
		foreach ($candidates as $candidate) {
			$host = $this->normalizePbxHost((string)$candidate);
			if ($host !== '') {
				return $host;
			}
		}
		return 'localhost';
	}

	private function detectPostfixSenderDomain($fallbackHost = '')
	{
		static $detectedDomain = null;
		if (is_string($detectedDomain) && $detectedDomain !== '') {
			return $detectedDomain;
		}
		$candidates = [];
		if (is_executable('/usr/sbin/postconf')) {
			foreach (['myorigin', 'myhostname', 'mydomain'] as $setting) {
				$output = [];
				$exitCode = 1;
				exec('/usr/sbin/postconf -h ' . $setting . ' 2>/dev/null', $output, $exitCode);
				if ($exitCode !== 0) {
					continue;
				}
				$value = trim(implode('', $output));
				if ($value === '/etc/mailname' && is_readable('/etc/mailname')) {
					$value = trim((string)file_get_contents('/etc/mailname'));
				}
				if ($value !== '' && $value[0] !== '$') {
					$candidates[] = $value;
				}
			}
		}
		$candidates[] = $fallbackHost;
		$candidates[] = $this->detectPbxHost();
		foreach ($candidates as $candidate) {
			$domain = $this->normalizeEmailSenderDomain($candidate);
			if ($domain !== '') {
				$detectedDomain = $domain;
				return $detectedDomain;
			}
		}
		$detectedDomain = 'localhost.localdomain';
		return $detectedDomain;
	}

	private function normalizePbxHost($value)
	{
		$value = trim((string)$value);
		$value = preg_replace('#^https?://#i', '', $value);
		$value = preg_replace('#/.*$#', '', $value);
		$value = preg_replace('/:\d+$/', '', $value);
		$value = strtolower($value);
		if ($value === '' || !preg_match('/^[a-z0-9.-]+$/', $value)) {
			return '';
		}
		return $value;
	}

	private function normalizeEmailSenderDomain($value)
	{
		$value = strtolower(trim((string)$value));
		if (strpos($value, '@') === 0) {
			$value = substr($value, 1);
		}
		$value = rtrim($value, '.');
		if ($value === '' || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP)) {
			return '';
		}
		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
		if (!preg_match('/^(?:' . $label . '\\.)+' . $label . '$/D', $value)) {
			return '';
		}
		return $value;
	}

	private function normalizeEmailSenderLocalPart($value)
	{
		$value = strtolower(trim((string)$value));
		if ($value === '' || strlen($value) > 64 || strpos($value, '..') !== false) {
			return '';
		}
		return preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/D', $value) ? $value : '';
	}

	private function normalizeGenericWebhookUrl($value)
	{
		$value = trim((string)$value);
		if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) {
			return '';
		}
		$parts = parse_url($value);
		if (!is_array($parts)
			|| strtolower((string)($parts['scheme'] ?? '')) !== 'https'
			|| empty($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['fragment'])
			|| (isset($parts['port']) && (int)$parts['port'] !== 443)) {
			return '';
		}
		$host = strtolower(rtrim((string)$parts['host'], '.'));
		if ($this->normalizeEmailSenderDomain($host) === '' || substr($host, -6) === '.local') {
			return '';
		}
		return $value;
	}

	private function normalizeWebhookDestinations($value, $type)
	{
		$type = $type === 'discord' ? 'discord' : 'generic';
		$destinations = [];
		$seenUrls = [];
		$seenIds = [];
		foreach ((array)$value as $entry) {
			if (!is_array($entry) || count($destinations) >= self::MAX_WEBHOOK_DESTINATIONS) {
				continue;
			}
			$url = $type === 'discord'
				? $this->normalizeDiscordWebhookUrl($entry['url'] ?? $entry['webhook_url'] ?? '')
				: $this->normalizeGenericWebhookUrl($entry['url'] ?? $entry['webhook_url'] ?? '');
			if ($url === '' || isset($seenUrls[$url])) {
				continue;
			}
			$seenUrls[$url] = true;
			$name = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($entry['name'] ?? '')));
			$name = substr(preg_replace('/\s+/', ' ', $name), 0, 80);
			if ($name === '') {
				$name = $type === 'discord'
					? sprintf('Discord %d', count($destinations) + 1)
					: sprintf('Webhook %d', count($destinations) + 1);
			}
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($entry['id'] ?? '')), 0, 64);
			if ($id === '' || isset($seenIds[$id])) {
				$id = $type . '_' . substr(hash('sha256', $name . '|' . $url), 0, 16);
				$suffix = 2;
				while (isset($seenIds[$id])) {
					$id = substr($type . '_' . substr(hash('sha256', $url), 0, 12) . '_' . $suffix, 0, 64);
					$suffix++;
				}
			}
			$seenIds[$id] = true;
			$destinations[] = [
				'id' => $id,
				'name' => $name,
				'url' => $url,
				'enabled' => array_key_exists('enabled', $entry) && empty($entry['enabled']) ? '0' : '1',
			];
		}
		return $destinations;
	}

	private function validateWebhookDestinations($value, $type)
	{
		$type = $type === 'discord' ? 'discord' : 'generic';
		$label = $type === 'discord' ? _('Discord') : _('Generic webhook');
		$errors = [];
		$rows = is_array($value) ? $value : [];
		if (count($rows) > self::MAX_WEBHOOK_DESTINATIONS) {
			$errors[] = sprintf(_('%s destinations are limited to %d.'), $label, self::MAX_WEBHOOK_DESTINATIONS);
		}
		$seenUrls = [];
		$seenIds = [];
		foreach (array_slice($rows, 0, self::MAX_WEBHOOK_DESTINATIONS + 1) as $index => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf(_('%s destination %d is invalid.'), $label, $index + 1);
				continue;
			}
			$rawUrl = trim((string)($entry['url'] ?? $entry['webhook_url'] ?? ''));
			$rawName = trim((string)($entry['name'] ?? ''));
			$rawId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($entry['id'] ?? '')), 0, 64);
			if ($rawUrl === '' && $rawName === '') {
				continue;
			}
			if ($rawId !== '' && isset($seenIds[$rawId])) {
				$errors[] = sprintf(_('%s destination %d duplicates another destination ID.'), $label, $index + 1);
			}
			if ($rawId !== '') {
				$seenIds[$rawId] = true;
			}
			$url = $type === 'discord'
				? $this->normalizeDiscordWebhookUrl($rawUrl)
				: $this->normalizeGenericWebhookUrl($rawUrl);
			if ($url === '') {
				$errors[] = $type === 'discord'
					? sprintf(_('Discord destination %d must use a valid Discord HTTPS webhook URL.'), $index + 1)
					: sprintf(_('Generic webhook destination %d must use a valid HTTPS hostname on port 443 without embedded credentials. Public-address DNS validation is enforced when an alert is sent.'), $index + 1);
				continue;
			}
			if (isset($seenUrls[$url])) {
				$errors[] = sprintf(_('%s destination %d duplicates another URL.'), $label, $index + 1);
			}
			$seenUrls[$url] = true;
		}
		return array_values(array_unique($errors));
	}

	private function mergeWebhookDestinationSecrets($incoming, $existing, $type)
	{
		$existingById = [];
		foreach ($this->normalizeWebhookDestinations($existing, $type) as $destination) {
			$existingById[(string)$destination['id']] = $destination;
		}
		$merged = [];
		foreach ((array)$incoming as $entry) {
			if (!is_array($entry)) {
				$merged[] = $entry;
				continue;
			}
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($entry['id'] ?? '')), 0, 64);
			$url = trim((string)($entry['url'] ?? $entry['webhook_url'] ?? ''));
			if (($url === '' || $url === '[redacted]') && $id !== '' && isset($existingById[$id])) {
				$entry['url'] = (string)$existingById[$id]['url'];
			}
			$merged[] = $entry;
		}
		return $merged;
	}

	private function firstEnabledWebhookUrl(array $destinations)
	{
		foreach ($destinations as $destination) {
			if (is_array($destination) && !empty($destination['enabled']) && !empty($destination['url'])) {
				return (string)$destination['url'];
			}
		}
		return '';
	}

	private function normalizeNwsApiBaseUrl($value)
	{
		$value = rtrim(trim((string)$value), '/');
		return hash_equals('https://api.weather.gov', $value) ? $value : '';
	}

	private function normalizeNwsZone($value)
	{
		$value = strtoupper(trim((string)$value));
		return preg_match('/^[A-Z]{2}[CZ][0-9]{3}$/', $value) ? $value : '';
	}

	private function normalizeNwsZoneGroups($value, $legacyZone = '', $legacyRecipients = [])
	{
		$input = is_array($value) ? $value : [];
		if (empty($input)) {
			$zone = $this->normalizeNwsZone($legacyZone);
			$extensions = $this->normalizeRecipientExtensions($legacyRecipients);
			if ($zone !== '' || !empty($extensions)) {
				$input[] = [
					'name' => 'Primary Weather Zone',
					'zone' => $zone,
					'extensions' => $extensions,
					'desktop_clients' => [],
					'email_recipients' => [],
				];
			}
		}
		$groups = [];
		$usedIds = [];
		foreach ($input as $group) {
			if (!is_array($group) || count($groups) >= 5) {
				continue;
			}
			$zone = $this->normalizeNwsZone($group['zone'] ?? '');
			$name = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($group['name'] ?? '')));
			$name = substr(preg_replace('/\s+/', ' ', $name), 0, 64);
			$extensions = $this->normalizeRecipientExtensions($group['extensions'] ?? $group['recipients'] ?? []);
			$desktopClients = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$desktopClients[$username] = $username;
				}
			}
			$emailRecipients = $this->normalizeEmailRecipientList($group['email_recipients'] ?? []);
			if ($zone === '' && $name === '' && empty($extensions) && empty($desktopClients) && empty($emailRecipients)) {
				continue;
			}
			if ($name === '') {
				$name = $zone !== '' ? $zone : sprintf('Weather Zone %d', count($groups) + 1);
			}
			$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? ''));
			if ($id === '') {
				$id = 'nws_' . substr(hash('sha256', strtolower($name) . '|' . $zone), 0, 12);
			}
			$id = substr($id, 0, 64);
			$baseId = $id;
			$suffix = 2;
			while (isset($usedIds[$id])) {
				$suffixText = '_' . $suffix++;
				$id = substr($baseId, 0, 64 - strlen($suffixText)) . $suffixText;
			}
			$usedIds[$id] = true;
			$groups[] = [
				'id' => $id,
				'name' => $name,
				'zone' => $zone,
				'extensions' => $extensions,
				'desktop_clients' => array_values($desktopClients),
				'email_recipients' => $emailRecipients,
			];
		}
		return $groups;
	}

	private function normalizeEmailRecipientList($value)
	{
		if (is_array($value)) {
			$value = implode(' ', array_map('strval', $value));
		}
		$normalized = $this->normalizeEmails((string)$value);
		return $normalized === '' ? [] : preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
	}

	private function mergeServiceEmailRecipients($configured, $legacy)
	{
		$merged = [];
		foreach (array_merge(
			$this->normalizeEmailRecipientList($configured),
			$this->normalizeEmailRecipientList($legacy)
		) as $recipient) {
			$key = strtolower((string)$recipient);
			if ($key !== '' && !isset($merged[$key])) {
				$merged[$key] = (string)$recipient;
			}
			if (count($merged) >= 50) {
				break;
			}
		}
		return array_values($merged);
	}

	private function validateNwsZoneGroupsInput($value)
	{
		if (!is_array($value)) {
			return [_('Weather Alert zone groups must be supplied as an array.')];
		}
		$errors = [];
		$seenIds = [];
		if (count($value) > 5) {
			$errors[] = _('Weather Alert zone groups are limited to five.');
		}
		foreach (array_slice(array_values($value), 0, 5) as $index => $group) {
			$label = sprintf(_('Weather zone %d'), $index + 1);
			if (!is_array($group)) {
				$errors[] = sprintf(_('%s must be an object.'), $label);
				continue;
			}
			if ($this->normalizeNwsZone($group['zone'] ?? '') === '') {
				$errors[] = sprintf(_('%s needs a valid weather.gov county or forecast zone.'), $label);
			}
			$normalizedZone = $this->normalizeNwsZone($group['zone'] ?? '');
			$normalizedName = trim(preg_replace('/\s+/', ' ', (string)($group['name'] ?? '')));
			$groupId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? '')), 0, 64);
			if ($groupId === '') {
				$groupId = 'nws_' . substr(hash('sha256', strtolower($normalizedName ?: $normalizedZone) . '|' . $normalizedZone), 0, 12);
			}
			if (isset($seenIds[$groupId])) {
				$errors[] = sprintf(_('%s duplicates Weather zone ID %s. Give each group a unique ID.'), $label, $groupId);
			} else {
				$seenIds[$groupId] = true;
			}
			if (isset($group['extensions']) && !is_array($group['extensions'])) {
				$errors[] = sprintf(_('%s extension recipients must be an array.'), $label);
			}
			if (isset($group['desktop_clients']) && !is_array($group['desktop_clients'])) {
				$errors[] = sprintf(_('%s desktop recipients must be an array.'), $label);
			}
			$extensions = $this->normalizeRecipientExtensions($group['extensions'] ?? $group['recipients'] ?? []);
			$desktops = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$desktops[$username] = true;
				}
			}
			if (empty($extensions) && empty($desktops)) {
				$errors[] = sprintf(_('%s needs at least one recipient extension or desktop client.'), $label);
			}
			$errors = array_merge($errors, array_map(static function ($message) use ($label) {
				return $label . ': ' . $message;
			}, $this->validateEmailRecipientsInput($group['email_recipients'] ?? [])));
		}
		return array_values(array_unique($errors));
	}

	private function validateNwsZoneDesktopAssignments($value, array $settings)
	{
		$knownDesktopUsernames = [];
		foreach ($this->getDesktopClients($settings) as $desktopClient) {
			$knownDesktopUsernames[(string)($desktopClient['username'] ?? '')] = !empty($desktopClient['enabled']);
		}
		$errors = [];
		foreach ((is_array($value) ? $value : []) as $zoneGroup) {
			if (!is_array($zoneGroup)) {
				continue;
			}
			$label = trim((string)($zoneGroup['name'] ?? $zoneGroup['zone'] ?? _('Weather zone'))) ?: _('Weather zone');
			foreach ((array)($zoneGroup['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username === '') {
					continue;
				}
				if (!array_key_exists($username, $knownDesktopUsernames)) {
					$errors[] = sprintf(_('Weather zone "%s" references an unknown desktop client: %s.'), $label, $username);
				} elseif (!$knownDesktopUsernames[$username]) {
					$errors[] = sprintf(_('Enable desktop client %s before assigning it to Weather zone "%s".'), $username, $label);
				}
			}
		}
		return array_values(array_unique($errors));
	}

	private function migrateNwsZoneDesktopUsernames($value, array $usernameMigrations)
	{
		$groups = is_array($value) ? $value : [];
		foreach ($groups as $zoneIndex => $zoneGroup) {
			if (!is_array($zoneGroup)) {
				continue;
			}
			$migratedSelectors = [];
			foreach ((array)($zoneGroup['desktop_clients'] ?? []) as $username) {
				$username = $usernameMigrations[(string)$username] ?? (string)$username;
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$migratedSelectors[$username] = $username;
				}
			}
			$groups[$zoneIndex]['desktop_clients'] = array_values($migratedSelectors);
		}
		return $groups;
	}

	private function validateNwsZoneEmailCapacity($value, $globalRecipients)
	{
		// Retain the second argument for compatibility with older callers. System
		// notification recipients are deliberately separate from service alerts.
		$errors = [];
		foreach ((is_array($value) ? $value : []) as $zoneGroup) {
			if (!is_array($zoneGroup)) {
				continue;
			}
			$combined = [];
			$zoneRecipients = $this->normalizeEmailRecipientList($zoneGroup['email_recipients'] ?? []);
			foreach ($zoneRecipients as $recipient) {
				$recipient = trim((string)$recipient);
				if ($recipient !== '') {
					$combined[strtolower($recipient)] = true;
				}
			}
			if (count($combined) > 50) {
				$errors[] = sprintf(
					_('Weather zone "%s" exceeds the 50-recipient email limit.'),
					(string)($zoneGroup['name'] ?? $zoneGroup['zone'] ?? _('Weather zone'))
				);
			}
		}
		return array_values(array_unique($errors));
	}

	private function validateXweatherGroupsInput($value)
	{
		if (!is_array($value) || !array_is_list($value)) {
			return [_('Lightning alert groups must be supplied as an array.')];
		}
		$errors = [];
		$seenIds = [];
		if (count($value) > 5) {
			$errors[] = _('Lightning alert groups are limited to five.');
		}
		foreach (array_slice($value, 0, 5) as $index => $group) {
			$label = sprintf(_('Lightning group %d'), $index + 1);
			if (!is_array($group) || (!empty($group) && array_is_list($group))) {
				$errors[] = sprintf(_('%s must be an object.'), $label);
				continue;
			}
			$allowedFields = ['id', 'name', 'enabled', 'adaptive_nws_zone_id', 'location', 'radius_miles', 'extensions', 'recipients', 'desktop_clients', 'email_recipients', 'all_clear'];
			foreach (array_keys($group) as $field) {
				if (!in_array($field, $allowedFields, true)) {
					$errors[] = sprintf(_('%s contains an unsupported field: %s.'), $label, (string)$field);
				}
			}
			foreach (['id', 'name', 'adaptive_nws_zone_id', 'location', 'all_clear'] as $stringField) {
				if (array_key_exists($stringField, $group) && !is_scalar($group[$stringField])) {
					$errors[] = sprintf(_('%s %s must be text.'), $label, str_replace('_', ' ', $stringField));
				}
			}
			$idRaw = (string)($group['id'] ?? '');
			if ($idRaw !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $idRaw)) {
				$errors[] = sprintf(_('%s has an invalid stable ID.'), $label);
			}
			$id = $idRaw !== '' ? $idRaw : 'generated:' . hash('sha256', strtolower(trim((string)($group['name'] ?? ''))) . '|' . strtolower(trim((string)($group['location'] ?? ''))) . '|' . $index);
			if (isset($seenIds[$id])) {
				$errors[] = sprintf(_('%s duplicates another Lightning group ID.'), $label);
			}
			$seenIds[$id] = true;
			foreach (['extensions', 'recipients', 'desktop_clients'] as $listField) {
				if (isset($group[$listField]) && (!is_array($group[$listField]) || !array_is_list($group[$listField]))) {
					$errors[] = sprintf(_('%s %s must be an array.'), $label, str_replace('_', ' ', $listField));
				} elseif (is_array($group[$listField] ?? null)) {
					foreach ($group[$listField] as $entry) {
						if (!is_scalar($entry)) {
							$errors[] = sprintf(_('%s %s entries must be text.'), $label, str_replace('_', ' ', $listField));
							break;
						}
					}
				}
			}
			if (isset($group['enabled']) && !is_scalar($group['enabled'])) {
				$errors[] = sprintf(_('%s enabled state is invalid.'), $label);
			}
			$radiusRaw = $group['radius_miles'] ?? 25;
			if (filter_var($radiusRaw, FILTER_VALIDATE_INT) === false || (int)$radiusRaw < 1 || (int)$radiusRaw > 62) {
				$errors[] = sprintf(_('%s radius must be between 1 and 62 miles.'), $label);
			}
			$adaptiveId = (string)($group['adaptive_nws_zone_id'] ?? '');
			if ($adaptiveId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $adaptiveId)) {
				$errors[] = sprintf(_('%s has an invalid Weather trigger selection.'), $label);
			}
			if (isset($group['all_clear']) && !in_array((string)$group['all_clear'], ['none', 'send'], true)) {
				$errors[] = sprintf(_('%s has an invalid all-clear action.'), $label);
			}
			$errors = array_merge($errors, array_map(static function ($message) use ($label) {
				return $label . ': ' . $message;
			}, $this->validateEmailRecipientsInput($group['email_recipients'] ?? [])));
		}
		return array_values(array_unique($errors));
	}

	private function validateXweatherGroupDesktopAssignments($value, array $settings)
	{
		$known = [];
		foreach ($this->getDesktopClients($settings) as $client) {
			$username = $this->normalizeDesktopUsername($client['username'] ?? '');
			if ($username !== '') {
				$known[$username] = !empty($client['enabled']);
			}
		}
		$errors = [];
		foreach ((is_array($value) ? $value : []) as $group) {
			if (!is_array($group)) {
				continue;
			}
			$label = trim((string)($group['name'] ?? _('Lightning group'))) ?: _('Lightning group');
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username === '') {
					continue;
				}
				if (!array_key_exists($username, $known)) {
					$errors[] = sprintf(_('Lightning group "%s" references an unknown desktop client: %s.'), $label, $username);
				} elseif (!$known[$username]) {
					$errors[] = sprintf(_('Enable desktop client %s before assigning it to Lightning group "%s".'), $username, $label);
				}
			}
		}
		return array_values(array_unique($errors));
	}

	private function validateXweatherGroupEmailCapacity($value, $globalRecipients)
	{
		// Retain the second argument for compatibility with older callers. System
		// notification recipients are deliberately separate from service alerts.
		$errors = [];
		foreach ((is_array($value) ? $value : []) as $group) {
			if (!is_array($group)) {
				continue;
			}
			$combined = [];
			foreach ($this->normalizeEmailRecipientList($group['email_recipients'] ?? []) as $recipient) {
				$combined[strtolower((string)$recipient)] = true;
			}
			if (count($combined) > 50) {
				$errors[] = sprintf(_('Lightning group "%s" exceeds the 50-recipient email limit.'), (string)($group['name'] ?? _('Lightning group')));
			}
		}
		return array_values(array_unique($errors));
	}

	private function migrateXweatherGroupDesktopUsernames($value, array $usernameMigrations)
	{
		$groups = is_array($value) ? $value : [];
		foreach ($groups as $index => $group) {
			if (!is_array($group)) {
				continue;
			}
			$migrated = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $usernameMigrations[(string)$username] ?? (string)$username;
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$migrated[$username] = $username;
				}
			}
			$groups[$index]['desktop_clients'] = array_values($migrated);
		}
		return $groups;
	}

	private function hasConfiguredNotificationEmailRecipients(array $settings)
	{
		if (!empty($this->normalizeEmailRecipientList($settings['mail_to'] ?? ''))) {
			return true;
		}
		foreach ((array)($settings['nws_zones'] ?? []) as $zoneGroup) {
			if (is_array($zoneGroup)
				&& !empty($this->normalizeEmailRecipientList($zoneGroup['email_recipients'] ?? []))) {
				return true;
			}
		}
		foreach ((array)($settings['xweather']['groups'] ?? []) as $group) {
			if (is_array($group)
				&& !empty($this->normalizeEmailRecipientList($group['email_recipients'] ?? []))) {
				return true;
			}
		}
		return false;
	}

	private function normalizeXweatherSettings($value, $defaultTtsVolume = 25)
	{
		$value = is_array($value) ? $value : [];
		$cleanSecret = static function ($item) {
			return substr(trim(preg_replace('/[^\x21-\x7e]/', '', (string)$item)), 0, 256);
		};
		$location = $this->normalizeXweatherLocation($value['location'] ?? '');
		$allClear = strtolower(trim((string)($value['all_clear'] ?? 'none')));
		if (!in_array($allClear, ['none', 'send'], true)) {
			$allClear = 'none';
		}
		$normalizeLightningTone = function ($tone) {
			$tone = trim((string)$tone);
			return $this->normalizeToneName($tone);
		};
		$openingTone = (string)($value['opening_tone'] ?? self::DEFAULT_LIGHTNING_OPENING_TONE);
		$closingTone = (string)($value['closing_tone'] ?? '');
		// Migrate the pre-0.0.7 shared Weather-tone sentinel without changing the
		// protected configuration file. Lightning now owns independent defaults.
		if ($openingTone === 'use_default') {
			$openingTone = self::DEFAULT_LIGHTNING_OPENING_TONE;
		}
		if ($closingTone === 'use_default') {
			$closingTone = '';
		}
		$groups = $this->normalizeXweatherGroups(
			array_key_exists('groups', $value) ? $value['groups'] : null,
			$value
		);
		if (!empty($groups)) {
			// Retain the first group's singleton aliases for safe rolling upgrades.
			// New runtime and UI code use groups exclusively, but older maintenance
			// helpers must never lose the pre-group location or phone selection.
			$location = (string)$groups[0]['location'];
			$allClear = (string)$groups[0]['all_clear'];
		}
		return [
			'enabled' => empty($value['enabled']) ? '0' : '1',
			'client_id' => $cleanSecret($value['client_id'] ?? ''),
			'client_secret' => $cleanSecret($value['client_secret'] ?? ''),
			'location' => $location,
			'radius_miles' => !empty($groups) ? (int)$groups[0]['radius_miles'] : $this->normalizeInt($value['radius_miles'] ?? 25, 1, 62, 25),
			'query_interval_minutes' => $this->normalizeInt($value['query_interval_minutes'] ?? 5, 1, 10, 5),
			'adaptive_free_tier' => array_key_exists('adaptive_free_tier', $value) && empty($value['adaptive_free_tier']) ? '0' : '1',
			'adaptive_grace_minutes' => $this->normalizeInt($value['adaptive_grace_minutes'] ?? 60, 5, 120, 60),
			'adaptive_nws_zone_id' => !empty($groups) ? (string)$groups[0]['adaptive_nws_zone_id'] : substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($value['adaptive_nws_zone_id'] ?? '')), 0, 64),
			'tts_volume' => $this->normalizeTtsVolume($value['tts_volume'] ?? $defaultTtsVolume, $defaultTtsVolume),
			'opening_tone' => $normalizeLightningTone($openingTone),
			'closing_tone' => $normalizeLightningTone($closingTone),
			'all_clear' => $allClear,
			'quiet_hours_enabled' => empty($value['quiet_hours_enabled']) ? '0' : '1',
			'quiet_hours_start' => $this->normalizeHour((string)($value['quiet_hours_start'] ?? '21:00'), '21:00'),
			'quiet_hours_end' => $this->normalizeHour((string)($value['quiet_hours_end'] ?? '06:00'), '06:00'),
			'recipients' => !empty($groups) ? (array)$groups[0]['extensions'] : $this->normalizeRecipientExtensions($value['recipients'] ?? []),
			'groups' => $groups,
		];
	}

	private function normalizeXweatherLocation($value)
	{
		$location = trim(preg_replace('/[^\P{C}\t]/u', '', (string)$value));
		return substr(preg_replace('/\s+/', ' ', $location), 0, 120);
	}

	private function normalizeXweatherGroups($value, array $legacy = [])
	{
		// A missing groups key means this is a singleton configuration from an
		// earlier release. An explicitly empty list remains empty so administrators
		// can disable the service and remove every group intentionally.
		if ($value === null) {
			$value = [[
				'id' => 'lightning_primary',
				'name' => 'Primary Lightning Zone',
				'enabled' => empty($legacy['enabled']) ? '0' : '1',
				'adaptive_nws_zone_id' => $legacy['adaptive_nws_zone_id'] ?? '',
				'location' => $legacy['location'] ?? '',
				'radius_miles' => $legacy['radius_miles'] ?? 25,
				'extensions' => $legacy['recipients'] ?? [],
				'desktop_clients' => [],
				'email_recipients' => [],
				'all_clear' => $legacy['all_clear'] ?? 'none',
			]];
		}
		$input = is_array($value) ? array_values($value) : [];
		$groups = [];
		$usedIds = [];
		foreach ($input as $index => $group) {
			if (!is_array($group) || count($groups) >= 5) {
				continue;
			}
			$name = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($group['name'] ?? '')));
			$name = substr(preg_replace('/\s+/', ' ', $name), 0, 64);
			$location = $this->normalizeXweatherLocation($group['location'] ?? '');
			if ($name === '') {
				$name = $location !== '' ? $location : sprintf('Lightning Zone %d', count($groups) + 1);
			}
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['id'] ?? '')), 0, 64);
			if ($id === '') {
				$id = 'lightning_' . substr(hash('sha256', strtolower($name) . '|' . strtolower($location) . '|' . $index), 0, 12);
			}
			$baseId = $id;
			$suffix = 2;
			while (isset($usedIds[$id])) {
				$suffixText = '_' . $suffix++;
				$id = substr($baseId, 0, 64 - strlen($suffixText)) . $suffixText;
			}
			$usedIds[$id] = true;
			$desktopClients = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$desktopClients[$username] = $username;
				}
			}
			$allClear = strtolower(trim((string)($group['all_clear'] ?? 'none')));
			if (!in_array($allClear, ['none', 'send'], true)) {
				$allClear = 'none';
			}
			$groups[] = [
				'id' => $id,
				'name' => $name,
				'enabled' => empty($group['enabled']) ? '0' : '1',
				'adaptive_nws_zone_id' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['adaptive_nws_zone_id'] ?? '')), 0, 64),
				'location' => $location,
				'radius_miles' => $this->normalizeInt($group['radius_miles'] ?? 25, 1, 62, 25),
				'extensions' => $this->normalizeRecipientExtensions($group['extensions'] ?? $group['recipients'] ?? []),
				'desktop_clients' => array_values($desktopClients),
				'email_recipients' => $this->normalizeEmailRecipientList($group['email_recipients'] ?? []),
				'all_clear' => $allClear,
			];
		}
		return $groups;
	}

	private function normalizeToneName($value)
	{
		$value = trim((string)$value);
		$value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
		$value = trim($value, '_-');
		return substr($value, 0, 64);
	}

	private function loadStatusData()
	{
		$status = [];
		if (is_readable(self::STATUS_JSON)) {
			$decoded = json_decode((string)file_get_contents(self::STATUS_JSON), true);
			if (is_array($decoded)) {
				$status = $decoded;
			}
		}
		return $status;
	}

	private function normalizeStatusState($state)
	{
		$state = strtolower(trim((string)$state));
		if (in_array($state, ['ok', 'queued', 'submitted', 'accepted', 'healthy'], true)) {
			return 'ok';
		}
		if (in_array($state, ['warning', 'warn', 'notice'], true)) {
			return 'notice';
		}
		if (in_array($state, ['fault', 'failed', 'error', 'partial_failure'], true)) {
			return 'fault';
		}
		if (in_array($state, ['skipped', 'cooldown'], true)) {
			return 'notice';
		}
		return 'unknown';
	}

	private function normalizeFaultState($faultAt, $faultEmailAt)
	{
		if (trim((string)$faultAt) === '') {
			return 'ok';
		}
		return trim((string)$faultEmailAt) === '' ? 'notice' : 'fault';
	}

	private function formatStatusTimestamp($value, $template = '')
	{
		$value = trim((string)$value);
		if ($value === '') {
			return '';
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return $template !== '' ? sprintf($template, $value) : $value;
		}

		$formatted = date('M j, Y g:i:s A T', $timestamp);
		return $template !== '' ? sprintf($template, $formatted) : $formatted;
	}

	private function parseTimestamp($value)
	{
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		$timestamp = strtotime($value);
		return $timestamp === false ? null : $timestamp;
	}

	private function normalizeStatusMessage($value, $fallback)
	{
		$value = trim((string)$value);
		return $value !== '' ? $value : $fallback;
	}

	private function buildDeliveryMessage(array $status)
	{
		$base = trim((string)($status['last_delivery_message'] ?? ''));
		$event = trim((string)($status['last_delivery_event'] ?? ''));
		$audio = trim((string)($status['last_delivery_audio'] ?? ''));
		$source = strtoupper(trim((string)($status['last_delivery_source'] ?? '')));

		if ($base !== '') {
			return $base;
		}
		if ($event !== '') {
			$parts = [];
			if ($source !== '') {
				$parts[] = $source;
			}
			$parts[] = $event;
			if ($audio !== '') {
				$parts[] = sprintf(_('audio %s'), $audio);
			}
			return implode(' | ', $parts);
		}
		return _('No delivery has been recorded yet.');
	}

	private function buildDeliveryDetails(array $status)
	{
		$group = trim((string)($status['last_delivery_page_group'] ?? ''));
		$alertId = trim((string)($status['last_delivery_alert_id'] ?? ''));
			$parts = [];
			if ($group !== '') {
				$parts[] = sprintf(_('NWS recipients %s'), $group);
			}
		if ($alertId !== '') {
			$parts[] = sprintf(_('Alert ID %s'), $alertId);
		}
		return implode(' | ', $parts);
	}

	private function buildFaultMessage(array $status)
	{
		$faultAt = trim((string)($status['last_fault_at'] ?? ''));
		if ($faultAt === '') {
			return _('No faults have been recorded.');
		}

		$stage = trim((string)($status['last_fault_stage'] ?? ''));
		$message = trim((string)($status['last_fault_message'] ?? ''));
		if ($stage !== '' && $message !== '') {
			return sprintf('%s: %s', strtoupper($stage), $message);
		}
		if ($message !== '') {
			return $message;
		}
		return _('A fault was recorded. Open the log for detail.');
	}

	private function normalizeEmails($value)
	{
		$parts = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		$emails = [];
		foreach ($parts ?: [] as $candidate) {
			$candidate = trim((string)$candidate);
			if ($this->isValidNotificationEmailAddress($candidate)) {
				$emails[strtolower($candidate)] = $candidate;
			}
		}
		return implode(' ', array_values($emails));
	}

	private function validateEmailRecipientsInput($value)
	{
		$candidates = is_array($value)
			? $value
			: (preg_split('/[\s,;]+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
		$errors = [];
		$nonEmpty = [];
		foreach ($candidates as $index => $candidate) {
			$candidate = trim((string)$candidate);
			if ($candidate === '') {
				continue;
			}
			$nonEmpty[] = $candidate;
			if (!$this->isValidNotificationEmailAddress($candidate)) {
				$errors[] = sprintf(_('Email recipient %d is not a valid address.'), $index + 1);
			}
		}
		if (count($nonEmpty) > 50) {
			$errors[] = _('Notification email recipients are limited to 50 addresses.');
		}
		return array_values(array_unique($errors));
	}

	private function isValidNotificationEmailAddress($value)
	{
		$value = trim((string)$value);
		if ($value === '' || strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		$separator = strrpos($value, '@');
		if ($separator === false) {
			return false;
		}
		$localPart = substr($value, 0, $separator);
		$domain = substr($value, $separator + 1);
		if ($localPart === '' || strlen($localPart) > 64
			|| $localPart[0] === '.' || substr($localPart, -1) === '.'
			|| strpos($localPart, '..') !== false
			|| $this->normalizeEmailSenderDomain($domain) === ''
			|| !preg_match('/[A-Za-z]{2,63}$/D', $domain)) {
			return false;
		}
		return true;
	}

	private function normalizeDiscordWebhookUrl($value)
	{
		$value = trim((string)$value);
		if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) {
			return '';
		}
		$parts = parse_url($value);
		$host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
		if (!is_array($parts)
			|| strtolower((string)($parts['scheme'] ?? '')) !== 'https'
			|| !in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true)
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
			|| (isset($parts['port']) && (int)$parts['port'] !== 443)
			|| !preg_match('#^/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+$#D', (string)($parts['path'] ?? ''))) {
			return '';
		}
		return $value;
	}

	private function normalizeHour($value, $fallback)
	{
		$value = trim((string)$value);
		if (preg_match('/^(?:[01][0-9]|2[0-3]):00$/', $value)) {
			return $value;
		}
		return $fallback;
	}

	private function normalizeCriticalEvents($value)
	{
		$allowed = array_fill_keys($this->getSupportedNwsEvents(), true);
		$events = [];
		foreach ((array)$value as $event) {
			foreach (preg_split('/\s*,\s*/', (string)$event, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
				$candidate = trim((string)$candidate);
				if ($candidate !== '' && isset($allowed[$candidate])) {
					$events[$candidate] = $candidate;
				}
			}
		}
		if (empty($events)) {
			foreach ($this->getDefaultQuietCriticalEvents() as $event) {
				$events[$event] = $event;
			}
		}
		return array_values($events);
	}

	private function normalizeRecipientExtensions($value)
	{
		$available = array_fill_keys($this->getConfiguredPjsipExtensionNumbers(), true);

		$extensions = [];
		foreach ((array)$value as $extension) {
			$extension = preg_replace('/[^0-9]/', '', (string)$extension);
			if ($extension !== '' && isset($available[$extension])) {
				$extensions[$extension] = $extension;
			}
		}
		return array_values($extensions);
	}

	private function normalizeEvent(array $event)
	{
		$type = $this->sanitizeType($event['type'] ?? 'other');
		$typeLabels = [
			'nws' => _('Weather Alert'),
			'xweather' => _('Lightning Alert'),
			'test' => _('Manual Test'),
			'announcement' => _('Announcement'),
			'announcement_audio' => _('Announcement Audio'),
		];
		$loggedAt = trim((string)($event['logged_at'] ?? ''));
		$timestamp = $loggedAt !== '' ? strtotime($loggedAt) : false;

		if ($timestamp === false) {
			$timestamp = time();
			$loggedAt = date('c', $timestamp);
		}

		$audioSequence = $event['audio_sequence'] ?? [];
		if (!is_array($audioSequence)) {
			$audioSequence = [];
		}

		$triggerName = trim((string)($event['trigger_name'] ?? ''));
		$triggerExtension = trim((string)($event['trigger_extension'] ?? ''));
		$triggeredBy = trim($triggerName . ($triggerExtension !== '' ? ' (' . $triggerExtension . ')' : ''));
		if ($triggeredBy === '') {
			$triggeredBy = trim((string)($event['trigger_source'] ?? 'Unknown'));
		}

		return [
			'event_id' => trim((string)($event['event_id'] ?? '')),
			'logged_at' => $loggedAt,
			'display_time' => date('Y-m-d H:i:s T', $timestamp),
			'type' => $type,
			'type_label' => $typeLabels[$type] ?? _('Other'),
			'status' => trim((string)($event['status'] ?? '')),
			'event' => trim((string)($event['event'] ?? '')),
			'severity' => trim((string)($event['severity'] ?? '')),
			'message_type' => trim((string)($event['message_type'] ?? '')),
			'trigger_source' => trim((string)($event['trigger_source'] ?? '')),
			'trigger_extension' => $triggerExtension,
			'trigger_name' => $triggerName,
			'triggered_by' => $triggeredBy,
			'source_extension' => trim((string)($event['source_extension'] ?? '')),
			'source_name' => trim((string)($event['source_name'] ?? '')),
			'page_group' => trim((string)($event['page_group'] ?? '')),
			'audio' => trim((string)($event['audio'] ?? '')),
			'audio_sequence' => $audioSequence,
			'alert_id' => trim((string)($event['alert_id'] ?? '')),
			'zone' => trim((string)($event['zone'] ?? '')),
			'system_name' => trim((string)($event['system_name'] ?? '')),
			'mail_subject' => trim((string)($event['mail_subject'] ?? '')),
			'mail_body' => trim((string)($event['mail_body'] ?? '')),
			'body' => trim((string)($event['body'] ?? '')),
			'announcement_style' => trim((string)($event['announcement_style'] ?? '')),
			'desktop_all' => !empty($event['desktop_all']),
			'desktop_clients' => is_array($event['desktop_clients'] ?? null) ? array_values($event['desktop_clients']) : [],
			'notify_delay_seconds' => (int)($event['notify_delay_seconds'] ?? 0),
			'background_color' => trim((string)($event['background_color'] ?? '')),
			'title' => trim((string)($event['title'] ?? '')),
		];
	}

	private function normalizeNativeBackupTransactionId($value)
	{
		$value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string)$value));
		$value = trim((string)$value, '.-');
		return substr($value !== '' ? $value : 'transaction', 0, 80);
	}

	private function acquireNativeBackupFileLock($path, $failureMessage, $timeoutSeconds = 60)
	{
		if (is_link($path)) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$handle = @fopen($path, 'c+');
		if ($handle === false) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$this->setPrivateOwnership($path);
		$deadline = microtime(true) + max(1, min(120, (int)$timeoutSeconds));
		do {
			if (@flock($handle, LOCK_EX | LOCK_NB)) {
				return $handle;
			}
			usleep(100000);
		} while (microtime(true) < $deadline);
		fclose($handle);
		throw new \RuntimeException((string)$failureMessage);
	}

	private function releaseNativeBackupFileLock($handle)
	{
		if (is_resource($handle)) {
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}
	}

	private function readNativeBackupFile($path, $maxBytes, $failureMessage)
	{
		$maxBytes = max(1, (int)$maxBytes);
		if (is_link($path) || !is_file($path) || !is_readable($path)) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$bytes = @filesize($path);
		if (!is_int($bytes) || $bytes < 1 || $bytes > $maxBytes) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$contents = @file_get_contents($path);
		if (!is_string($contents) || strlen($contents) !== $bytes) {
			throw new \RuntimeException((string)$failureMessage);
		}
		return $contents;
	}

	private function validateNativeBackupConfig($raw)
	{
		if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::NATIVE_BACKUP_MAX_CONFIG_BYTES) {
			throw new \RuntimeException(_('The protected configuration exceeds the native backup limits.'));
		}
		$settings = json_decode($raw, true);
		if (!is_array($settings) || empty($settings) || array_keys($settings) === range(0, count($settings) - 1)) {
			throw new \RuntimeException(_('The protected configuration is not a valid JSON object.'));
		}
		// Native restore must remain independent of restore order. In particular,
		// endpoint records may not exist yet when this module is restored, so this
		// intentionally validates structure and secrets without resolving live
		// extensions, SIP contacts, recordings, or network destinations.
		$errors = $this->validateConfigValueTypes($settings);
		$known = array_fill_keys(array_merge(array_keys($this->getDefaultSettings()), ['sound_map', 'test_sound_pool']), true);
		foreach (array_keys($settings) as $key) {
			if (!isset($known[$key])) {
				$errors[] = sprintf(_('Unknown config key: %s.'), (string)$key);
			}
		}
		foreach (['enabled', 'setup', 'ami', 'control_api', 'sipnotify'] as $requiredKey) {
			if (!array_key_exists($requiredKey, $settings)) {
				$errors[] = sprintf(_('Config is missing required key: %s.'), $requiredKey);
			}
		}
		foreach (['control_api', 'updates', 'setup', 'sipnotify', 'ami', 'xweather'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an object.'), $key);
			}
		}
		foreach (['alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an array.'), $key);
			}
		}
		if (!empty($errors)) {
			$summary = implode(' ', array_slice(array_map(static function ($error) {
				return trim((string)$error);
			}, array_values(array_unique($errors))), 0, 3));
			throw new \RuntimeException(sprintf(
				_('The protected configuration failed validation: %s'),
				$this->sanitizeScheduleText($summary, 500, true)
			));
		}
		if (is_array($settings['desktop_clients'] ?? null)) {
			$errors = array_merge($errors, $this->validateDesktopClientIdentifiers($settings['desktop_clients']));
			if (!empty($settings['desktop_clients'])) {
				$key = base64_decode((string)($settings['desktop_auth_key'] ?? ''), true);
				if (!is_string($key) || strlen($key) !== 32) {
					$errors[] = _('Config with desktop clients must include its valid desktop encryption key.');
				} else {
					foreach ($settings['desktop_clients'] as $client) {
						if (!is_array($client) || empty($client['password_enc']) || $this->decryptDesktopPassword((string)$client['password_enc'], $settings) === '') {
							$errors[] = _('One or more desktop client credentials cannot be decrypted with this config.');
							break;
						}
					}
				}
			}
		}
		$errors = array_merge($errors, $this->validateNwsZoneDesktopAssignments(
			$settings['nws_zones'] ?? [],
			$settings
		));
		if (!empty($settings['control_api']['enabled']) && !preg_match('/^[A-Za-z0-9_-]{24,128}$/', (string)($settings['control_api']['api_key'] ?? ''))) {
			$errors[] = _('Enabled Control API config must include a valid API key.');
		}
		if (isset($settings['nws_zone']) && trim((string)$settings['nws_zone']) !== ''
			&& !preg_match('/^[A-Z]{2}[CZ][0-9]{3}$/i', trim((string)$settings['nws_zone']))) {
			$errors[] = _('NWS zone must be a valid county or forecast-zone code.');
		}
		foreach ((array)($settings['nws_zones'] ?? []) as $zoneGroup) {
			$zoneExtensions = is_array($zoneGroup)
				? ($zoneGroup['extensions'] ?? $zoneGroup['recipients'] ?? null)
				: null;
			if (!is_array($zoneGroup)
				|| !preg_match('/^[A-Z]{2}[CZ][0-9]{3}$/i', trim((string)($zoneGroup['zone'] ?? '')))
				|| !is_array($zoneExtensions)) {
				$errors[] = _('One or more Weather Alert zone groups are structurally invalid.');
				break;
			}
			foreach ($zoneExtensions as $extension) {
				if (!preg_match('/^[0-9]{1,32}$/', (string)$extension)) {
					$errors[] = _('One or more Weather Alert recipient extensions are invalid.');
					break 2;
				}
			}
			$zoneDesktops = $zoneGroup['desktop_clients'] ?? [];
			if (!is_array($zoneDesktops)) {
				$errors[] = _('One or more Weather Alert desktop recipient lists are invalid.');
				break;
			}
			foreach ($zoneDesktops as $username) {
				if ($this->normalizeDesktopUsername($username) === '') {
					$errors[] = _('One or more Weather Alert desktop recipients are invalid.');
					break 2;
				}
			}
			$zoneEmails = $zoneGroup['email_recipients'] ?? [];
			if (!is_array($zoneEmails)) {
				$errors[] = _('One or more Weather Alert email recipient lists are invalid.');
				break;
			}
			$zoneEmailErrors = $this->validateEmailRecipientsInput($zoneEmails);
			if (!empty($zoneEmailErrors)) {
				$errors[] = _('One or more Weather Alert email recipients are invalid.');
				break;
			}
			if (empty($zoneExtensions) && empty($zoneDesktops)) {
				$errors[] = _('One or more Weather Alert zone groups have no phone or desktop recipients.');
				break;
			}
		}
		$errors = array_merge($errors, $this->validateNwsZoneEmailCapacity(
			$settings['nws_zones'] ?? [],
			$settings['mail_to'] ?? ''
		));
		if (is_string($settings['nws_api_base_url'] ?? null) && $settings['nws_api_base_url'] !== 'https://api.weather.gov') {
			$errors[] = _('NWS API base URL must be exactly https://api.weather.gov.');
		}
		$schedules = is_array($settings['scheduled_announcements'] ?? null) ? $settings['scheduled_announcements'] : [];
		if (count($schedules) > self::MAX_SCHEDULES) {
			$errors[] = _('The configuration contains too many scheduled announcements.');
		}
		foreach ($schedules as $schedule) {
			if (!is_array($schedule)
				|| !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($schedule['id'] ?? ''))
				|| !is_array($schedule['occurrences'] ?? null)
				|| count($schedule['occurrences']) > self::MAX_SCHEDULE_OCCURRENCES) {
				$errors[] = _('One or more scheduled announcements are structurally invalid.');
				break;
			}
			foreach ($schedule['occurrences'] as $occurrence) {
				if (!is_array($occurrence)
					|| !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($occurrence['id'] ?? ''))
					|| $this->parseScheduleUtcTimestamp((string)($occurrence['run_at_utc'] ?? '')) === false) {
					$errors[] = _('One or more scheduled-announcement occurrences are invalid.');
					break 2;
				}
			}
		}
		$errors = array_merge($errors, $this->validateScheduledAnnouncementRecurrences($schedules));
		if (array_key_exists('mail_from_domain', $settings)) {
			$mailFromDomain = $this->normalizeEmailSenderDomain((string)$settings['mail_from_domain']);
			$mailFromLocalPart = $this->normalizeEmailSenderLocalPart((string)($settings['mail_from_local_part'] ?? 'no-reply'));
			if ($mailFromDomain === '' || $mailFromDomain !== strtolower(trim((string)$settings['mail_from_domain'], "@ \t\n\r\0\x0B.")) || $mailFromLocalPart === '') {
				$errors[] = _('The configured email sender identity is invalid.');
			}
		} elseif (isset($settings['mail_from_addr']) && !filter_var((string)$settings['mail_from_addr'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = _('Legacy email sender address is invalid.');
		}
		$errors = array_merge(
			$errors,
			$this->validateWebhookDestinations($settings['discord_webhooks'] ?? [], 'discord'),
			$this->validateWebhookDestinations($settings['generic_webhooks'] ?? [], 'generic')
		);
		if (!empty($errors)) {
			$summary = implode(' ', array_slice(array_map(static function ($error) {
				return trim((string)$error);
			}, $errors), 0, 3));
			throw new \RuntimeException(sprintf(
				_('The protected configuration failed validation: %s'),
				$this->sanitizeScheduleText($summary, 500, true)
			));
		}
		return $settings;
	}

	private function writeNativeSnapshotFile($path, $contents, $mode)
	{
		if (is_link($path) || !is_string($contents) || @file_put_contents($path, $contents, LOCK_EX) === false) {
			@unlink($path);
			throw new \RuntimeException(_('Unable to write a protected backup staging file.'));
		}
		if (!@chmod($path, (int)$mode)) {
			@unlink($path);
			throw new \RuntimeException(_('Unable to secure a protected backup staging file.'));
		}
	}

	private function nativeBackupFileManifest($type, $archiveName, $restoreName, $path)
	{
		$bytes = @filesize($path);
		$hash = @hash_file('sha256', $path);
		if (!is_int($bytes) || $bytes < 1 || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
			throw new \RuntimeException(_('Unable to hash a protected backup staging file.'));
		}
		return [
			'type' => (string)$type,
			'archive_name' => (string)$archiveName,
			'restore_name' => (string)$restoreName,
			'bytes' => $bytes,
			'sha256' => $hash,
		];
	}

	private function validateNativeScheduleLedger($raw)
	{
		if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::NATIVE_BACKUP_MAX_LEDGER_BYTES) {
			throw new \RuntimeException(_('The scheduling journal exceeds the native backup limits.'));
		}
		$ledger = json_decode($raw, true);
		if (!is_array($ledger) || (int)($ledger['version'] ?? 1) !== 1 || !is_array($ledger['occurrences'] ?? [])) {
			throw new \RuntimeException(_('The scheduling journal is invalid.'));
		}
		$occurrences = $ledger['occurrences'] ?? [];
		if (count($occurrences) > (self::MAX_SCHEDULES * self::MAX_SCHEDULE_OCCURRENCES)) {
			throw new \RuntimeException(_('The scheduling journal contains too many occurrences.'));
		}
		$allowedStates = ['pending', 'claimed', 'success', 'failed', 'missed', 'uncertain'];
		foreach ($occurrences as $occurrenceId => $record) {
			$occurrenceId = (string)$occurrenceId;
			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $occurrenceId) || !is_array($record)) {
				throw new \RuntimeException(_('The scheduling journal contains an invalid occurrence record.'));
			}
			$recordId = trim((string)($record['occurrence_id'] ?? $occurrenceId));
			if (!hash_equals($occurrenceId, $recordId)) {
				throw new \RuntimeException(_('The scheduling journal occurrence identifiers do not match.'));
			}
			$state = strtolower(trim((string)($record['state'] ?? 'pending')));
			if (!in_array($state, $allowedStates, true)) {
				throw new \RuntimeException(_('The scheduling journal contains an invalid delivery state.'));
			}
			$runAt = trim((string)($record['run_at_utc'] ?? ''));
			if ($runAt !== '' && $this->parseScheduleUtcTimestamp($runAt) === false) {
				throw new \RuntimeException(_('The scheduling journal contains an invalid UTC timestamp.'));
			}
			foreach (['schedule_id', 'schedule_name', 'message', 'claimed_at', 'completed_at', 'updated_at'] as $field) {
				if (isset($record[$field]) && !is_scalar($record[$field])) {
					throw new \RuntimeException(_('The scheduling journal contains an invalid field type.'));
				}
			}
		}
		$ledger['version'] = 1;
		$ledger['occurrences'] = $occurrences;
		$ledger['worker'] = is_array($ledger['worker'] ?? null) ? $ledger['worker'] : [];
		return $ledger;
	}

	private function assertNativeToneName($toneName)
	{
		$toneName = (string)$toneName;
		if ($toneName === '' || strlen($toneName) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $toneName)) {
			throw new \RuntimeException(_('A custom announcement tone has an unsafe filename.'));
		}
		if (!hash_equals($toneName, $this->normalizeToneName($toneName))) {
			throw new \RuntimeException(_('A custom announcement tone filename cannot be normalized safely.'));
		}
	}

	private function validateNativeWaveContents($contents, $toneName = '')
	{
		if (!is_string($contents) || strlen($contents) < 44 || substr($contents, 0, 4) !== 'RIFF' || substr($contents, 8, 4) !== 'WAVE') {
			throw new \RuntimeException(sprintf(
				_('Custom tone %s is not a valid RIFF/WAVE file.'),
				$this->sanitizeScheduleText($toneName !== '' ? $toneName : _('unknown'), 128, true)
			));
		}
	}

	private function removeNativeBackupDirectory($path)
	{
		$path = rtrim((string)$path, DIRECTORY_SEPARATOR);
		$base = basename($path);
		if ($path === '' || !preg_match('/^(slsmassnotify-backup-|\.freepbx-restore-stage-|slsmassnotify-rollback-)/', $base)) {
			return;
		}
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $entry) {
				$entryPath = $entry->getPathname();
				if ($entry->isLink() || $entry->isFile()) {
					@unlink($entryPath);
				} elseif ($entry->isDir()) {
					@rmdir($entryPath);
				}
			}
		} catch (\Throwable $e) {
			return;
		}
		@rmdir($path);
	}

	private function validateNativeBackupManifest(array $manifest)
	{
		if ((int)($manifest['schema_version'] ?? 0) !== self::NATIVE_BACKUP_SCHEMA_VERSION) {
			throw new \RuntimeException(_('This Mass Notifications backup schema is not supported by the installed module.'));
		}
		if (!hash_equals('slsmassnotifyserver', (string)($manifest['module'] ?? ''))) {
			throw new \RuntimeException(_('The native backup manifest belongs to a different module.'));
		}
		$moduleVersion = trim((string)($manifest['module_version'] ?? ''));
		if (strlen($moduleVersion) > 64 || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?$/', $moduleVersion)) {
			throw new \RuntimeException(_('The native backup manifest has an invalid module version.'));
		}
		$createdAt = trim((string)($manifest['created_at'] ?? ''));
		if ($createdAt === '' || strtotime($createdAt) === false) {
			throw new \RuntimeException(_('The native backup manifest has an invalid creation time.'));
		}
		$sourceTimezone = trim((string)($manifest['source_timezone'] ?? ''));
		try {
			new \DateTimeZone($sourceTimezone);
		} catch (\Throwable $e) {
			throw new \RuntimeException(_('The native backup manifest has an invalid source timezone.'));
		}
		$files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
		if (!is_array($files['config'] ?? null) || !array_key_exists('schedule', $files) || !is_array($files['tones'] ?? null)) {
			throw new \RuntimeException(_('The native backup manifest is missing its protected file inventory.'));
		}

		$normalized = [
			'schema_version' => self::NATIVE_BACKUP_SCHEMA_VERSION,
			'module' => 'slsmassnotifyserver',
			'module_version' => $moduleVersion,
			'created_at' => $createdAt,
			'source_timezone' => $sourceTimezone,
			'files' => [
				'config' => $this->validateNativeBackupManifestRecord(
					$files['config'],
					'slsmassnotify-config',
					basename(self::SETTINGS_JSON),
					self::NATIVE_BACKUP_MAX_CONFIG_BYTES
				),
				'schedule' => null,
				'tones' => [],
			],
		];
		if ($files['schedule'] !== null) {
			if (!is_array($files['schedule'])) {
				throw new \RuntimeException(_('The native backup scheduling inventory is invalid.'));
			}
			$normalized['files']['schedule'] = $this->validateNativeBackupManifestRecord(
				$files['schedule'],
				'slsmassnotify-schedule',
				basename(self::SCHEDULE_STATE_JSON),
				self::NATIVE_BACKUP_MAX_LEDGER_BYTES
			);
		}
		if (count($files['tones']) > self::NATIVE_BACKUP_MAX_TONES) {
			throw new \RuntimeException(_('The native backup contains too many custom tones.'));
		}
		$totalToneBytes = 0;
		$seenToneNames = [];
		foreach ($files['tones'] as $record) {
			if (!is_array($record)) {
				throw new \RuntimeException(_('The native backup contains an invalid custom-tone record.'));
			}
			$restoreName = (string)($record['restore_name'] ?? '');
			if (strtolower(pathinfo($restoreName, PATHINFO_EXTENSION)) !== 'wav') {
				throw new \RuntimeException(_('The native backup contains an unsafe custom-tone filename.'));
			}
			$toneName = basename($restoreName, '.wav');
			$this->assertNativeToneName($toneName);
			if (!hash_equals($toneName . '.wav', $restoreName) || isset($seenToneNames[$toneName])) {
				throw new \RuntimeException(_('The native backup contains an unsafe custom-tone restore path.'));
			}
			$seenToneNames[$toneName] = true;
			$normalizedRecord = $this->validateNativeBackupManifestRecord(
				$record,
				'slsmassnotify-tone',
				$restoreName,
				self::NATIVE_BACKUP_MAX_TONE_BYTES
			);
			$totalToneBytes += (int)$normalizedRecord['bytes'];
			if ($totalToneBytes > self::NATIVE_BACKUP_MAX_TONES_BYTES) {
				throw new \RuntimeException(_('The native backup custom tones exceed the restore size limit.'));
			}
			$normalized['files']['tones'][] = $normalizedRecord;
		}
		$seen = [];
		foreach (array_merge(
			[$normalized['files']['config']],
			$normalized['files']['schedule'] === null ? [] : [$normalized['files']['schedule']],
			$normalized['files']['tones']
		) as $record) {
			$key = $record['type'] . "\0" . $record['archive_name'];
			if (isset($seen[$key])) {
				throw new \RuntimeException(_('The native backup manifest contains duplicate archive entries.'));
			}
			$seen[$key] = true;
		}
		return $normalized;
	}

	private function validateNativeBackupManifestRecord(array $record, $requiredType, $requiredRestoreName, $maxBytes)
	{
		$type = trim((string)($record['type'] ?? ''));
		$archiveName = trim((string)($record['archive_name'] ?? ''));
		$restoreName = trim((string)($record['restore_name'] ?? ''));
		$bytes = filter_var($record['bytes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int)$maxBytes]]);
		$hash = strtolower(trim((string)($record['sha256'] ?? '')));
		if (!hash_equals((string)$requiredType, $type)
			|| !hash_equals((string)$requiredRestoreName, $restoreName)
			|| $bytes === false
			|| !preg_match('/^[a-f0-9]{64}$/', $hash)
			|| strlen($archiveName) > 180
			|| !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $archiveName)
			|| !hash_equals($archiveName, basename($archiveName))
		) {
			throw new \RuntimeException(_('The native backup manifest contains an invalid file record.'));
		}
		return [
			'type' => $type,
			'archive_name' => $archiveName,
			'restore_name' => $restoreName,
			'bytes' => (int)$bytes,
			'sha256' => $hash,
		];
	}

	private function loadNativeRestorePayload(array $manifest, array $files, $backupTmpDir)
	{
		$filesCandidate = rtrim((string)$backupTmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'files';
		$filesRoot = @realpath($filesCandidate);
		if (is_link($filesCandidate) || !is_string($filesRoot) || $filesRoot === '' || !is_dir($filesRoot)) {
			throw new \RuntimeException(_('FreePBX did not provide a safe extracted-file directory for this restore.'));
		}
		$expected = [];
		$records = array_merge(
			[$manifest['files']['config']],
			$manifest['files']['schedule'] === null ? [] : [$manifest['files']['schedule']],
			$manifest['files']['tones']
		);
		foreach ($records as $record) {
			$expected[$record['type'] . "\0" . $record['archive_name']] = $record;
		}
		$found = [];
		foreach ($files as $file) {
			if (!is_object($file) || !method_exists($file, 'getType') || !method_exists($file, 'getFilename') || !method_exists($file, 'getPathname')) {
				throw new \RuntimeException(_('FreePBX returned an invalid native backup file object.'));
			}
			$type = (string)$file->getType();
			$archiveName = (string)$file->getFilename();
			$key = $type . "\0" . $archiveName;
			if (!isset($expected[$key]) || isset($found[$key])) {
				throw new \RuntimeException(_('The extracted native backup file inventory does not match its recorded manifest.'));
			}
			$sourcePath = (string)$file->getPathname();
			$realSource = @realpath($sourcePath);
			if (!is_string($realSource) || $realSource === '' || is_link($sourcePath) || !is_file($realSource) || !is_readable($realSource)) {
				throw new \RuntimeException(_('An extracted native backup file is unavailable or unsafe.'));
			}
			if ($realSource !== $filesRoot && strpos($realSource, $filesRoot . DIRECTORY_SEPARATOR) !== 0) {
				throw new \RuntimeException(_('An extracted native backup file escaped the FreePBX restore directory.'));
			}
			$currentParent = dirname($sourcePath);
			while ($currentParent !== $filesRoot && $currentParent !== dirname($currentParent)) {
				if (is_link($currentParent)) {
					throw new \RuntimeException(_('An extracted native backup path contains an unsafe symbolic-link directory.'));
				}
				$currentParent = dirname($currentParent);
			}
			if ($currentParent !== $filesRoot) {
				throw new \RuntimeException(_('An extracted native backup path could not be anchored safely.'));
			}
			$record = $expected[$key];
			$bytes = @filesize($realSource);
			$hash = @hash_file('sha256', $realSource);
			if (!is_int($bytes) || $bytes !== (int)$record['bytes'] || !is_string($hash) || !hash_equals($record['sha256'], strtolower($hash))) {
				throw new \RuntimeException(_('An extracted native backup file failed its size or SHA-256 check.'));
			}
			$found[$key] = ['record' => $record, 'path' => $realSource];
		}
		if (count($found) !== count($expected)) {
			throw new \RuntimeException(_('One or more protected files are missing from the FreePBX restore.'));
		}

		$configRecord = $manifest['files']['config'];
		$configFile = $found[$configRecord['type'] . "\0" . $configRecord['archive_name']];
		$configRaw = $this->readNativeBackupFile(
			$configFile['path'],
			self::NATIVE_BACKUP_MAX_CONFIG_BYTES,
			_('The restored protected configuration is unreadable.')
		);
		$configSettings = $this->validateNativeBackupConfig($configRaw);

		$schedule = null;
		$schedulePresent = $manifest['files']['schedule'] !== null;
		if ($schedulePresent) {
			$scheduleRecord = $manifest['files']['schedule'];
			$scheduleFile = $found[$scheduleRecord['type'] . "\0" . $scheduleRecord['archive_name']];
			$scheduleRaw = $this->readNativeBackupFile(
				$scheduleFile['path'],
				self::NATIVE_BACKUP_MAX_LEDGER_BYTES,
				_('The restored scheduling journal is unreadable.')
			);
			$schedule = ['raw' => $scheduleRaw, 'data' => $this->validateNativeScheduleLedger($scheduleRaw)];
		}

		$tones = [];
		foreach ($manifest['files']['tones'] as $toneRecord) {
			$toneFile = $found[$toneRecord['type'] . "\0" . $toneRecord['archive_name']];
			$header = @file_get_contents($toneFile['path'], false, null, 0, 44);
			$this->validateNativeWaveContents(is_string($header) ? $header : '', basename($toneRecord['restore_name'], '.wav'));
			$tones[] = [
				'name' => basename($toneRecord['restore_name'], '.wav'),
				'path' => $toneFile['path'],
				'bytes' => (int)$toneRecord['bytes'],
				'sha256' => $toneRecord['sha256'],
			];
		}
		return [
			'config' => ['raw' => $configRaw, 'settings' => $configSettings],
			'schedule' => $schedule,
			'schedule_present' => $schedulePresent,
			'tones' => $tones,
		];
	}

	private function prepareNativeRestoredScheduleState(array $config, $schedule, $sourceTimezone, $now)
	{
		if (!is_string($config['raw'] ?? null) || !is_array($config['settings'] ?? null)) {
			throw new \RuntimeException(_('The restored protected configuration payload is incomplete.'));
		}
		$settings = $config['settings'];
		$schedules = is_array($settings['scheduled_announcements'] ?? null) ? $settings['scheduled_announcements'] : [];
		$ledgerPresent = is_array($schedule) && is_array($schedule['data'] ?? null) && is_string($schedule['raw'] ?? null);
		$ledger = $ledgerPresent ? $schedule['data'] : ['version' => 1, 'occurrences' => [], 'worker' => []];
		$ledger['version'] = 1;
		$ledger['occurrences'] = is_array($ledger['occurrences'] ?? null) ? $ledger['occurrences'] : [];
		$ledger['worker'] = is_array($ledger['worker'] ?? null) ? $ledger['worker'] : [];
		$warnings = [];
		$configChanged = false;
		$ledgerChanged = false;
		$disableSchedules = false;
		$currentTimezone = $this->getPbxDateTimeZone()->getName();
		if (!$ledgerPresent && !empty($schedules)) {
			$disableSchedules = true;
			$warnings[] = _('Scheduled announcements were disabled because the backup did not contain an execution journal.');
		}
		if (!hash_equals((string)$sourceTimezone, $currentTimezone) && !empty($schedules)) {
			$disableSchedules = true;
			$warnings[] = sprintf(
				_('Scheduled announcements were disabled because the PBX timezone changed from %s to %s.'),
				$this->sanitizeScheduleText($sourceTimezone, 80, true),
				$this->sanitizeScheduleText($currentTimezone, 80, true)
			);
		}

		$now = max(0, (int)$now);
		foreach ($schedules as $scheduleIndex => &$scheduledAnnouncement) {
			if (!is_array($scheduledAnnouncement)) {
				$disableSchedules = true;
				continue;
			}
			$scheduleId = trim((string)($scheduledAnnouncement['id'] ?? ''));
			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $scheduleId)) {
				$disableSchedules = true;
			}
			$occurrences = is_array($scheduledAnnouncement['occurrences'] ?? null) ? $scheduledAnnouncement['occurrences'] : [];
			foreach ($occurrences as $occurrence) {
				if (!is_array($occurrence)) {
					$disableSchedules = true;
					continue;
				}
				$occurrenceId = trim((string)($occurrence['id'] ?? ''));
				$runAtUtc = trim((string)($occurrence['run_at_utc'] ?? ''));
				$runAt = $this->parseScheduleUtcTimestamp($runAtUtc);
				if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $occurrenceId) || $runAt === false) {
					$disableSchedules = true;
					continue;
				}
				if ($runAt > $now) {
					continue;
				}
				$current = is_array($ledger['occurrences'][$occurrenceId] ?? null) ? $ledger['occurrences'][$occurrenceId] : [];
				$currentState = strtolower(trim((string)($current['state'] ?? 'pending')));
				if (in_array($currentState, ['success', 'failed', 'missed', 'uncertain'], true)) {
					continue;
				}
				$ledger['occurrences'][$occurrenceId] = [
					'schedule_id' => $scheduleId,
					'schedule_name' => $this->sanitizeScheduleText($scheduledAnnouncement['name'] ?? '', 80, true),
					'occurrence_id' => $occurrenceId,
					'run_at_utc' => $runAtUtc,
					'state' => 'uncertain',
					'message' => _('This occurrence was already due when the backup was restored and was not replayed.'),
					'attempts' => max(0, (int)($current['attempts'] ?? 0)),
					'claimed_at' => (string)($current['claimed_at'] ?? ''),
					'updated_at' => gmdate('c', $now),
					'completed_at' => gmdate('c', $now),
				];
				$ledgerChanged = true;
			}
		}
		unset($scheduledAnnouncement);

		if ($disableSchedules) {
			foreach ($schedules as &$scheduledAnnouncement) {
				if (is_array($scheduledAnnouncement) && !empty($scheduledAnnouncement['enabled'])) {
					$scheduledAnnouncement['enabled'] = '0';
					$configChanged = true;
				}
			}
			unset($scheduledAnnouncement);
			if (!empty($schedules) && empty($warnings)) {
				$warnings[] = _('Scheduled announcements were disabled because their restored state could not be verified safely.');
			}
		}
		if ($configChanged) {
			$settings['scheduled_announcements'] = $schedules;
			$configRaw = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if (!is_string($configRaw)) {
				throw new \RuntimeException(_('Unable to encode the replay-safe restored configuration.'));
			}
			$configRaw .= "\n";
			$this->validateNativeBackupConfig($configRaw);
		} else {
			$configRaw = $config['raw'];
		}

		$schedulePresent = $ledgerPresent || !empty($schedules);
		if ($schedulePresent && (!$ledgerPresent || $ledgerChanged)) {
			$ledger['updated_at'] = gmdate('c', $now);
			$scheduleRaw = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if (!is_string($scheduleRaw)) {
				throw new \RuntimeException(_('Unable to encode the replay-safe scheduling journal.'));
			}
			$scheduleRaw .= "\n";
			$this->validateNativeScheduleLedger($scheduleRaw);
		} else {
			$scheduleRaw = $ledgerPresent ? $schedule['raw'] : null;
		}
		return [
			'config_raw' => $configRaw,
			'schedule_raw' => $scheduleRaw,
			'schedule_present' => $schedulePresent,
			'warnings' => array_values(array_unique($warnings)),
		];
	}

	private function commitNativeRestorePayload($configRaw, $scheduleRaw, $schedulePresent, array $tones, $transactionId, array $warnings)
	{
		$this->ensurePluginDataDir();
		if (is_link(self::PLUGIN_DATA_DIR) || is_link(self::TONES_DIR)) {
			throw new \RuntimeException(_('The protected restore directories must not be symbolic links.'));
		}
		$this->validateNativeBackupConfig((string)$configRaw);
		if ($schedulePresent) {
			$this->validateNativeScheduleLedger((string)$scheduleRaw);
		}
		$transactionId = $this->normalizeNativeBackupTransactionId($transactionId);
		$stageRoot = self::PLUGIN_DATA_DIR . '/.freepbx-restore-stage-' . $transactionId . '-' . bin2hex(random_bytes(4));
		$rollbackRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
			. DIRECTORY_SEPARATOR . 'slsmassnotify-rollback-' . $transactionId . '-' . bin2hex(random_bytes(4));
		if (!@mkdir($stageRoot, 0700, true) || is_link($stageRoot) || !@mkdir($rollbackRoot, 0700, true) || is_link($rollbackRoot)) {
			$this->removeNativeBackupDirectory($stageRoot);
			$this->removeNativeBackupDirectory($rollbackRoot);
			throw new \RuntimeException(_('Unable to create protected restore staging directories.'));
		}

		$workerLock = null;
		$announcementLock = null;
		$configLock = null;
		$rollback = [];
		$committing = false;
		try {
			$stageConfig = $stageRoot . '/mass-notifications.config';
			$this->writeNativeSnapshotFile($stageConfig, (string)$configRaw, 0600);
			$stageSchedule = null;
			if ($schedulePresent) {
				$stageSchedule = $stageRoot . '/schedule-executions.json';
				$this->writeNativeSnapshotFile($stageSchedule, (string)$scheduleRaw, 0600);
			}
			$markerContents = $this->nativeRestoreMarkerContents($transactionId, $warnings);
			$stageMarker = $stageRoot . '/freepbx-restore-pending.json';
			$this->writeNativeSnapshotFile($stageMarker, $markerContents, 0600);

			$stagedTones = [];
			foreach ($tones as $tone) {
				$name = (string)($tone['name'] ?? '');
				$this->assertNativeToneName($name);
				if (isset($stagedTones[$name]) || is_link((string)($tone['path'] ?? '')) || !is_file((string)($tone['path'] ?? ''))) {
					throw new \RuntimeException(_('A restored custom tone is duplicated or unavailable.'));
				}
				$stageTone = $stageRoot . '/tone--' . $name . '.wav';
				if (!@copy((string)$tone['path'], $stageTone) || !@chmod($stageTone, 0600)) {
					throw new \RuntimeException(_('Unable to stage a restored custom tone.'));
				}
				$bytes = @filesize($stageTone);
				$hash = @hash_file('sha256', $stageTone);
				if (!is_int($bytes) || $bytes !== (int)($tone['bytes'] ?? -1)
					|| !is_string($hash) || !hash_equals((string)($tone['sha256'] ?? ''), strtolower($hash))) {
					throw new \RuntimeException(_('A restored custom tone changed while it was being staged.'));
				}
				$header = @file_get_contents($stageTone, false, null, 0, 44);
				$this->validateNativeWaveContents(is_string($header) ? $header : '', $name);
				$stagedTones[$name] = $stageTone;
			}

			$workerLock = $this->acquireNativeBackupFileLock(
				self::SCHEDULE_LOCK_FILE,
				_('The scheduled-announcement worker did not become idle in time for restore.')
			);
			$announcementLock = $this->acquireNativeBackupFileLock(
				self::ANNOUNCEMENT_LOCK_FILE,
				_('An active announcement did not complete in time for restore.')
			);
			$configLock = $this->acquireSettingsLock();
			$bundledTones = array_fill_keys([
				self::DEFAULT_ANNOUNCEMENT_OPENING_TONE,
				self::DEFAULT_ANNOUNCEMENT_CLOSING_TONE,
				self::DEFAULT_NWS_OPENING_TONE,
				self::DEFAULT_LIGHTNING_OPENING_TONE,
			], true);
			$currentCustomTones = [];
			foreach (glob(self::TONES_DIR . '/*.wav') ?: [] as $currentTone) {
				$name = basename($currentTone, '.wav');
				if (!isset($bundledTones[$name])) {
					$this->assertNativeToneName($name);
					$currentCustomTones[$name] = $currentTone;
				}
			}
			$destinations = [
				self::SETTINGS_JSON,
				self::PENDING_SETTINGS_JSON,
				self::SCHEDULE_STATE_JSON,
				self::FREEPBX_RESTORE_MARKER,
			];
			foreach (array_unique(array_merge(array_keys($currentCustomTones), array_keys($stagedTones))) as $toneName) {
				$destinations[] = self::TONES_DIR . '/' . $toneName . '.wav';
			}
			$rollback = $this->backupNativeRestoreTargets($destinations, $rollbackRoot);
			$committing = true;
			$this->backupAppliedSettings();

			foreach ($currentCustomTones as $name => $currentTone) {
				if (!isset($stagedTones[$name]) && is_file($currentTone) && !@unlink($currentTone)) {
					throw new \RuntimeException(_('Unable to remove a custom tone that is absent from the restored backup.'));
				}
			}
			foreach ($stagedTones as $name => $stageTone) {
				$destination = self::TONES_DIR . '/' . $name . '.wav';
				if (!@rename($stageTone, $destination)) {
					throw new \RuntimeException(_('Unable to activate a restored custom tone.'));
				}
				@chmod($destination, 0644);
				@chown($destination, 'asterisk');
				@chgrp($destination, 'asterisk');
			}
			if ($schedulePresent) {
				if (!@rename($stageSchedule, self::SCHEDULE_STATE_JSON)) {
					throw new \RuntimeException(_('Unable to activate the restored scheduling journal.'));
				}
				$this->setPrivateOwnership(self::SCHEDULE_STATE_JSON);
			} elseif (is_file(self::SCHEDULE_STATE_JSON) && !@unlink(self::SCHEDULE_STATE_JSON)) {
				throw new \RuntimeException(_('Unable to remove stale scheduling state during restore.'));
			}
			if (is_file(self::PENDING_SETTINGS_JSON) && !@unlink(self::PENDING_SETTINGS_JSON)) {
				throw new \RuntimeException(_('Unable to remove stale staged settings during restore.'));
			}
			if (!@rename($stageConfig, self::SETTINGS_JSON)) {
				throw new \RuntimeException(_('Unable to activate the restored protected configuration.'));
			}
			$this->setPrivateOwnership(self::SETTINGS_JSON);
			if (!@rename($stageMarker, self::FREEPBX_RESTORE_MARKER)) {
				throw new \RuntimeException(_('Unable to activate the protected post-restore repair marker.'));
			}
			$this->setPrivateOwnership(self::FREEPBX_RESTORE_MARKER);
			$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
			$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);
			$committing = false;
		} catch (\Throwable $e) {
			if ($committing && !empty($rollback)) {
				$this->restoreNativeRestoreTargets($rollback);
			}
			throw $e;
		} finally {
			if (is_resource($configLock)) {
				$this->releaseSettingsLock($configLock);
			}
			$this->releaseNativeBackupFileLock($announcementLock);
			$this->releaseNativeBackupFileLock($workerLock);
			$this->removeNativeBackupDirectory($stageRoot);
			$this->removeNativeBackupDirectory($rollbackRoot);
		}
	}

	private function nativeRestoreMarkerContents($transactionId, array $warnings)
	{
		$cleanWarnings = [];
		foreach (array_slice($warnings, 0, 10) as $warning) {
			$cleanWarnings[] = $this->sanitizeScheduleText($warning, 300, true);
		}
		$encoded = json_encode([
			'version' => 1,
			'transaction_id' => $this->normalizeNativeBackupTransactionId($transactionId),
			'restored_at' => gmdate('c'),
			'integration_repair' => 'pending',
			'warnings' => $cleanWarnings,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (!is_string($encoded)) {
			throw new \RuntimeException(_('Unable to encode the protected post-restore repair marker.'));
		}
		return $encoded . "\n";
	}

	private function backupNativeRestoreTargets(array $destinations, $rollbackRoot)
	{
		$rollback = [];
		$seen = [];
		foreach ($destinations as $destination) {
			$destination = (string)$destination;
			if ($destination === '' || isset($seen[$destination])) {
				continue;
			}
			$seen[$destination] = true;
			if (is_link($destination)) {
				throw new \RuntimeException(_('A protected restore destination is an unsafe symbolic link.'));
			}
			$entry = [
				'destination' => $destination,
				'existed' => false,
				'backup' => '',
				'mode' => 0,
			];
			if (file_exists($destination)) {
				if (!is_file($destination) || !is_readable($destination)) {
					throw new \RuntimeException(_('A protected restore destination is not a readable regular file.'));
				}
				$backupPath = rtrim((string)$rollbackRoot, DIRECTORY_SEPARATOR)
					. DIRECTORY_SEPARATOR . sprintf('%04d.rollback', count($rollback));
				if (!@copy($destination, $backupPath) || !@chmod($backupPath, 0600)) {
					throw new \RuntimeException(_('Unable to create a protected pre-restore rollback copy.'));
				}
				$sourceHash = @hash_file('sha256', $destination);
				$backupHash = @hash_file('sha256', $backupPath);
				if (!is_string($sourceHash) || !is_string($backupHash) || !hash_equals($sourceHash, $backupHash)) {
					throw new \RuntimeException(_('A protected pre-restore rollback copy failed verification.'));
				}
				$entry['existed'] = true;
				$entry['backup'] = $backupPath;
				$entry['mode'] = (int)(@fileperms($destination) & 0777);
			}
			$rollback[] = $entry;
		}
		return $rollback;
	}

	private function restoreNativeRestoreTargets(array $rollback)
	{
		$failures = [];
		foreach (array_reverse($rollback) as $entry) {
			$destination = (string)($entry['destination'] ?? '');
			if ($destination === '') {
				continue;
			}
			if (is_link($destination)) {
				$failures[] = $destination;
				continue;
			}
			if (empty($entry['existed'])) {
				if (file_exists($destination) && (!is_file($destination) || !@unlink($destination))) {
					$failures[] = $destination;
				}
				continue;
			}
			$backupPath = (string)($entry['backup'] ?? '');
			if (!is_file($backupPath) || is_link($backupPath) || !is_readable($backupPath)) {
				$failures[] = $destination;
				continue;
			}
			$temporary = dirname($destination) . '/.' . basename($destination) . '.restore-rollback-' . bin2hex(random_bytes(3));
			if (!@copy($backupPath, $temporary)
				|| !@chmod($temporary, max(0600, (int)($entry['mode'] ?? 0600)))
				|| !@rename($temporary, $destination)) {
				@unlink($temporary);
				$failures[] = $destination;
				continue;
			}
			if (strpos($destination, self::TONES_DIR . '/') === 0) {
				@chmod($destination, (int)($entry['mode'] ?? 0644));
				@chown($destination, 'asterisk');
				@chgrp($destination, 'asterisk');
			} else {
				$this->setPrivateOwnership($destination);
			}
		}
		if (!empty($failures)) {
			$this->updateStatusData([
				'last_native_restore_status' => 'fault',
				'last_native_restore_message' => _('A native restore failed and one or more protected rollback files could not be replaced.'),
			]);
			throw new \RuntimeException(_('The native restore rollback did not complete safely.'));
		}
	}

	private function loadNativeRestoreMarker()
	{
		if (!file_exists(self::FREEPBX_RESTORE_MARKER)) {
			return null;
		}
		if (is_link(self::FREEPBX_RESTORE_MARKER) || !is_file(self::FREEPBX_RESTORE_MARKER) || !is_readable(self::FREEPBX_RESTORE_MARKER)) {
			throw new \RuntimeException(_('The protected post-restore repair marker is unsafe or unreadable.'));
		}
		$bytes = @filesize(self::FREEPBX_RESTORE_MARKER);
		if (!is_int($bytes) || $bytes < 2 || $bytes > 32768) {
			throw new \RuntimeException(_('The protected post-restore repair marker has an invalid size.'));
		}
		$decoded = json_decode((string)@file_get_contents(self::FREEPBX_RESTORE_MARKER), true);
		if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1
			|| trim((string)($decoded['transaction_id'] ?? '')) === ''
			|| !hash_equals(
				(string)$decoded['transaction_id'],
				$this->normalizeNativeBackupTransactionId($decoded['transaction_id'])
			)
			|| strtotime((string)($decoded['restored_at'] ?? '')) === false
		) {
			throw new \RuntimeException(_('The protected post-restore repair marker is invalid.'));
		}
		return $decoded;
	}

	private function verifyNativePostRestoreIntegration()
	{
		$settings = $this->validateNativeBackupConfig($this->readNativeBackupFile(
			self::SETTINGS_JSON,
			self::NATIVE_BACKUP_MAX_CONFIG_BYTES,
			_('Post-restore verification could not read the protected configuration.')
		));
		$requiredFiles = [
			__DIR__ . '/Backup.php' => false,
			__DIR__ . '/Restore.php' => false,
			self::RUNTIME_DIR . '/sls_notify.py' => true,
			self::RUNTIME_DIR . '/sls_config.py' => true,
			self::RUNTIME_DIR . '/sls_branded_email.py' => true,
			self::RUNTIME_DIR . '/sls_branded_discord.py' => true,
			self::RUNTIME_DIR . '/sls_notification_destinations.py' => true,
			self::RUNTIME_DIR . '/sls_system_notifications.py' => true,
			self::RUNTIME_DIR . '/sls_nws_status.py' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_test.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_update.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_maintenance.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_uninstall.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh' => true,
			'/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php' => false,
			'/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php' => false,
			'/etc/apache2/conf-enabled/sls-mass-notify.conf' => false,
			'/var/www/html/api/sipnotify/index.php' => false,
			'/var/www/html/api/sipnotify/.htaccess' => false,
			'/var/www/html/api/sls-mass-notify/index.php' => false,
			'/var/www/html/api/sls-mass-notify/.htaccess' => false,
		];
		foreach ($requiredFiles as $path => $mustExecute) {
			if (!is_file($path) || !is_readable($path) || ($mustExecute && !is_executable($path))) {
				throw new \RuntimeException(_('Post-restore verification found an incomplete runtime or FreePBX integration file.'));
			}
		}
		$backupEnrollment = $this->ensureFreePbxBackupEnrollment();
		if (empty($backupEnrollment['success'])
			|| (!empty($backupEnrollment['available'])
				&& (int)$backupEnrollment['jobs'] !== (int)$backupEnrollment['enrolled'])) {
			throw new \RuntimeException(_('Post-restore verification could not confirm FreePBX backup-job enrollment.'));
		}
		$parityFiles = [
			__DIR__ . '/bin/sls_mass_notify/sls_notify.py' => self::RUNTIME_DIR . '/sls_notify.py',
			__DIR__ . '/bin/sls_mass_notify/sls_config.py' => self::RUNTIME_DIR . '/sls_config.py',
			__DIR__ . '/bin/sls_mass_notify/sls_branded_email.py' => self::RUNTIME_DIR . '/sls_branded_email.py',
			__DIR__ . '/bin/sls_mass_notify/sls_branded_discord.py' => self::RUNTIME_DIR . '/sls_branded_discord.py',
			__DIR__ . '/bin/sls_mass_notify/sls_notification_destinations.py' => self::RUNTIME_DIR . '/sls_notification_destinations.py',
			__DIR__ . '/bin/sls_mass_notify/sls_system_notifications.py' => self::RUNTIME_DIR . '/sls_system_notifications.py',
			__DIR__ . '/bin/sls_mass_notify/sls_nws_status.py' => self::RUNTIME_DIR . '/sls_nws_status.py',
			__DIR__ . '/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py' => self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py',
			__DIR__ . '/bin/sls_mass_notify_nws_poll.sh' => self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh',
			__DIR__ . '/bin/sls_mass_notify_test.sh' => self::RUNTIME_DIR . '/sls_mass_notify_test.sh',
			__DIR__ . '/bin/sls_mass_notify_update.sh' => self::RUNTIME_DIR . '/sls_mass_notify_update.sh',
			__DIR__ . '/bin/sls_mass_notify_maintenance.sh' => self::RUNTIME_DIR . '/sls_mass_notify_maintenance.sh',
			__DIR__ . '/bin/sls_mass_notify_uninstall.sh' => self::RUNTIME_DIR . '/sls_mass_notify_uninstall.sh',
			__DIR__ . '/bin/sls_mass_notify_install_piper_voices.sh' => self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh',
			__DIR__ . '/bin/sls_mass_notify_schedule_worker.php' => self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php',
			__DIR__ . '/bin/sls_mass_notify_weather_poll.sh' => self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh',
			__DIR__ . '/dashboard/sections/SlsMassNotifyAnnouncement.class.php' => '/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php',
			__DIR__ . '/dashboard/views/sections/sls-mass-notify-announcement.php' => '/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php',
			__DIR__ . '/api/sipnotify/index.php' => '/var/www/html/api/sipnotify/index.php',
			__DIR__ . '/api/sipnotify/.htaccess' => '/var/www/html/api/sipnotify/.htaccess',
			__DIR__ . '/api/sls-mass-notify/index.php' => '/var/www/html/api/sls-mass-notify/index.php',
			__DIR__ . '/api/sls-mass-notify/.htaccess' => '/var/www/html/api/sls-mass-notify/.htaccess',
		];
		foreach ($parityFiles as $source => $destination) {
			$sourceHash = is_file($source) && !is_link($source) ? @hash_file('sha256', $source) : false;
			$destinationHash = is_file($destination) && !is_link($destination) ? @hash_file('sha256', $destination) : false;
			if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
				throw new \RuntimeException(_('Post-restore verification found runtime or Dashboard files that do not match the installed module payload.'));
			}
		}
		$menuContents = is_readable('/var/www/html/admin/views/menu_items.php')
			? (string)@file_get_contents('/var/www/html/admin/views/menu_items.php')
			: '';
		if (strpos($menuContents, 'SLS Mass Notifications menu placement: keep Mass Notify after UCP/User Panel.') === false) {
			throw new \RuntimeException(_('Post-restore verification found incomplete FreePBX menu integration.'));
		}
		$dashboardHooks = (array)\FreePBX::Dashboard()->getConfig('allhooks');
		$dashboardFound = false;
		foreach ($dashboardHooks as $page) {
			foreach ((array)($page['entries'] ?? []) as $entry) {
				if (($entry['rawname'] ?? '') === 'SlsMassNotifyAnnouncement'
					&& ($entry['section'] ?? '') === 'sls_mass_notify_announcement') {
					$dashboardFound = true;
					break 2;
				}
			}
		}
		if (!$dashboardFound) {
			throw new \RuntimeException(_('Post-restore verification could not find the Mass Notify Dashboard hook.'));
		}
		$managedBlocks = [
			'/etc/asterisk/extensions_custom.conf' => '; BEGIN SLS Mass Notifications Dialplan',
			'/etc/asterisk/sip_notify_custom.conf' => '; BEGIN SLS Mass Notifications SIP NOTIFY Templates',
		];
		foreach ($managedBlocks as $path => $marker) {
			$contents = is_readable($path) ? (string)@file_get_contents($path) : '';
			if ($contents === '' || strpos($contents, $marker) === false) {
				throw new \RuntimeException(_('Post-restore verification found an incomplete Asterisk integration block.'));
			}
		}
		if (!is_executable(self::PIPER_BIN) || !is_executable('/usr/local/bin/piper')) {
			throw new \RuntimeException(_('Post-restore verification found an incomplete Piper runtime.'));
		}
		$voiceHashes = [
			'en_US-lessac-low.onnx' => 'f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375',
			'en_US-lessac-low.onnx.json' => '45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a',
			'en_US-amy-low.onnx' => 'a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3',
			'en_US-amy-low.onnx.json' => '2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb',
			'en_US-ryan-low.onnx' => '8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf',
			'en_US-ryan-low.onnx.json' => 'b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60',
		];
		foreach ($voiceHashes as $file => $expectedHash) {
			$path = self::PIPER_VOICE_DIR . '/' . $file;
			if (!is_file($path) || is_link($path) || !is_readable($path)
				|| !hash_equals($expectedHash, (string)@hash_file('sha256', $path))) {
				throw new \RuntimeException(_('Post-restore verification found a missing or invalid Piper voice file.'));
			}
		}
		foreach ([
			self::TONES_DIR . '/opening_Paging_Tone_Opening.wav',
			self::TONES_DIR . '/closing_Paging_Tone_Closing.wav',
			self::TONES_DIR . '/opening_NWS_alert.wav',
			self::TONES_DIR . '/opening_Lightning_alert.wav',
		] as $tonePath) {
			if (!is_file($tonePath) || is_link($tonePath) || !is_readable($tonePath)) {
				throw new \RuntimeException(_('Post-restore verification found a missing bundled paging tone.'));
			}
		}
		$expectedSoundTarget = realpath(self::SOUNDS_DIR);
		if (!is_string($expectedSoundTarget) || $expectedSoundTarget === '') {
			throw new \RuntimeException(_('Post-restore verification could not resolve the protected sound directory.'));
		}
		foreach ([
			'/var/lib/asterisk/sounds/' . self::ASTERISK_SOUND_PREFIX,
			'/var/lib/asterisk/sounds/en/' . self::ASTERISK_SOUND_PREFIX,
		] as $soundLink) {
			$resolved = is_link($soundLink) ? realpath($soundLink) : false;
			if (!is_string($resolved) || !hash_equals($expectedSoundTarget, $resolved)) {
				throw new \RuntimeException(_('Post-restore verification found an invalid Asterisk sound link.'));
			}
		}
		$spoolDevices = [];
		foreach ([self::ASTERISK_SPOOL_TMP, self::ASTERISK_OUTGOING_SPOOL, '/var/spool/asterisk/outgoing_done'] as $spoolDir) {
			if (!is_dir($spoolDir)) {
				throw new \RuntimeException(_('Post-restore verification found a missing or unsafe Asterisk call-file spool directory.'));
			}
			$accessOutput = [];
			$accessStatus = 1;
			@exec(
				'/usr/sbin/runuser -u asterisk -- /usr/bin/test -w ' . escapeshellarg($spoolDir)
				. ' && /usr/sbin/runuser -u asterisk -- /usr/bin/test -x ' . escapeshellarg($spoolDir)
				. ' 2>&1',
				$accessOutput,
				$accessStatus
			);
			$spoolStat = @stat($spoolDir);
			if ($accessStatus !== 0 || !is_array($spoolStat) || !isset($spoolStat['dev'])) {
				throw new \RuntimeException(_('Post-restore verification found unusable Asterisk call-file spool permissions.'));
			}
			$spoolDevices[] = (string)$spoolStat['dev'];
		}
		if (count(array_unique($spoolDevices)) !== 1) {
			throw new \RuntimeException(_('Post-restore verification found Asterisk call-file spool directories on different filesystems.'));
		}
		foreach ([self::TONES_DIR, self::TTS_DIR] as $writableSoundDir) {
			$accessStatus = 1;
			@exec(
				'/usr/sbin/runuser -u asterisk -- /usr/bin/test -w ' . escapeshellarg($writableSoundDir)
				. ' && /usr/sbin/runuser -u asterisk -- /usr/bin/test -x ' . escapeshellarg($writableSoundDir)
				. ' 2>&1',
				$accessOutput,
				$accessStatus
			);
			if ($accessStatus !== 0) {
				throw new \RuntimeException(_('Post-restore verification found protected sound directories that Asterisk cannot use.'));
			}
		}
		$systemRecordings = [
			[
				'filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Opening',
				'name' => 'SLS Mass Notify - Paging Tone Opening',
				'description' => 'Default Southland Servers regular announcement opening tone.',
				'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Opening.wav',
				'tone' => self::TONES_DIR . '/opening_Paging_Tone_Opening.wav',
			],
			[
				'filename' => 'custom/SLS_Mass_Notify_Paging_Tone_Closing',
				'name' => 'SLS Mass Notify - Paging Tone Closing',
				'description' => 'Default Southland Servers regular announcement closing tone.',
				'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Closing.wav',
				'tone' => self::TONES_DIR . '/closing_Paging_Tone_Closing.wav',
			],
			[
				'filename' => 'custom/SLS_Mass_Notify_NWS_Alert',
				'name' => 'SLS Mass Notify - NWS Alert',
				'description' => 'Default Southland Servers NWS alert opening tone.',
				'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_NWS_Alert.wav',
				'tone' => self::TONES_DIR . '/opening_NWS_alert.wav',
			],
			[
				'filename' => 'custom/SLS_Mass_Notify_Lightning_Alert',
				'name' => 'SLS Mass Notify - Lightning Alert',
				'description' => 'Default Southland Servers cloud-to-ground lightning warning opening tone.',
				'path' => '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Lightning_Alert.wav',
				'tone' => self::TONES_DIR . '/opening_Lightning_alert.wav',
			],
		];
		try {
			$recordingLookup = $this->FreePBX->Database()->prepare(
				'SELECT displayname, description FROM recordings WHERE filename = ? LIMIT 1'
			);
			foreach ($systemRecordings as $recording) {
				$recordingLookup->execute([$recording['filename']]);
				$row = $recordingLookup->fetch(\PDO::FETCH_ASSOC);
				if (!is_array($row)
					|| !hash_equals($recording['name'], (string)($row['displayname'] ?? ''))
					|| !hash_equals($recording['description'], (string)($row['description'] ?? ''))
					|| !is_file($recording['path']) || is_link($recording['path']) || !is_readable($recording['path'])
					|| !is_file($recording['tone']) || is_link($recording['tone']) || !is_readable($recording['tone'])) {
					throw new \RuntimeException(_('Post-restore verification found an incomplete bundled System Recording.'));
				}
				$customHash = @hash_file('sha256', $recording['path']);
				$toneHash = @hash_file('sha256', $recording['tone']);
				if (!is_string($customHash) || !is_string($toneHash) || !hash_equals($customHash, $toneHash)) {
					throw new \RuntimeException(_('Post-restore verification found mismatched bundled System Recording audio.'));
				}
				$formatOutput = [];
				$formatStatus = 1;
				@exec(
					'/usr/bin/soxi -r ' . escapeshellarg($recording['path'])
					. ' && /usr/bin/soxi -c ' . escapeshellarg($recording['path'])
					. ' && /usr/bin/soxi -b ' . escapeshellarg($recording['path']) . ' 2>&1',
					$formatOutput,
					$formatStatus
				);
				if ($formatStatus !== 0 || array_values($formatOutput) !== ['8000', '1', '16']) {
					throw new \RuntimeException(_('Post-restore verification found a bundled System Recording with an invalid Asterisk WAV format.'));
				}
			}
		} catch (\RuntimeException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new \RuntimeException(_('Post-restore verification could not inspect bundled FreePBX System Recordings.'));
		}
		$cronLines = [];
		foreach ((array)$this->FreePBX->Cron()->getAll() as $line) {
			$cronLines[] = (string)$line;
		}
		$cronText = implode("\n", $cronLines);
		if (substr_count($cronText, '* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh') !== 1
			|| substr_count($cronText, '* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php') !== 1) {
			throw new \RuntimeException(_('Post-restore verification found an incomplete scheduler integration.'));
		}
		$commands = [
			'/usr/sbin/fwconsole reload',
			'/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan show sls-alert-audio'),
		];
		foreach ($commands as $command) {
			$output = [];
			$status = 1;
			@exec($command . ' 2>&1', $output, $status);
			if ($status !== 0) {
				throw new \RuntimeException(_('Post-restore FreePBX or Asterisk reload verification failed.'));
			}
			if (strpos($command, 'dialplan show') !== false) {
				$dialplanText = implode("\n", $output);
				if (stripos($dialplanText, "context 'sls-alert-audio'") === false
					|| strpos($dialplanText, 'Page(${SLS_DIAL},b(sls-alert-autoanswer^s^1(${EXTEN}))A(${SLS_SAFE_SOUND})inq,5)') === false
					|| strpos($dialplanText, 'Dial(${SLS_DIAL}') !== false) {
					throw new \RuntimeException(_('Post-restore verification found an invalid multi-contact paging context.'));
				}
			}
		}
		$autoanswerOutput = [];
		$autoanswerStatus = 1;
		@exec('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan show s@sls-alert-autoanswer') . ' 2>&1', $autoanswerOutput, $autoanswerStatus);
		$autoanswerText = implode("\n", $autoanswerOutput);
		if ($autoanswerStatus !== 0
			|| strpos($autoanswerText, 'PJSIP_HEADER(add,Alert-Info)') === false
			|| strpos($autoanswerText, 'PJSIP_HEADER(add,Call-Info)') === false
			|| strpos($autoanswerText, '${SLS_AUTOANSWER_UA:0:7}"="yealink"]?Set(SLS_ALERT_INFO=Intercom)') === false) {
			throw new \RuntimeException(_('Post-restore verification found an incomplete auto-answer header context.'));
		}
		foreach ([
			['function', 'PJSIP_HEADER'],
			['function', 'PJSIP_DIAL_CONTACTS'],
			['application', 'Page'],
			['application', 'ConfBridge'],
		] as $capability) {
			$capabilityOutput = [];
			$capabilityStatus = 1;
			$command = 'core show ' . $capability[0] . ' ' . $capability[1];
			@exec('/usr/sbin/asterisk -rx ' . escapeshellarg($command) . ' 2>&1', $capabilityOutput, $capabilityStatus);
			$capabilityText = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', implode("\n", $capabilityOutput));
			$heading = "Info about " . $capability[0] . " '" . $capability[1] . "'";
			if ($capabilityStatus !== 0 || stripos((string)$capabilityText, $heading) === false) {
				throw new \RuntimeException(sprintf(_('Post-restore verification could not find Asterisk %s %s.'), $capability[0], $capability[1]));
			}
		}
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$amiUsername = $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami');
		$amiOutput = [];
		$amiStatus = 1;
		@exec(
			'/usr/sbin/asterisk -rx ' . escapeshellarg('manager show user ' . $amiUsername) . ' 2>&1',
			$amiOutput,
			$amiStatus
		);
		$amiText = strtolower(implode("\n", $amiOutput));
		if ($amiStatus !== 0
			|| strpos($amiText, 'username: ' . strtolower($amiUsername)) === false
			|| !preg_match('/read perm:\s*[^\n]*system[^\n]*call[^\n]*originate/', $amiText)
			|| !preg_match('/write perm:\s*[^\n]*system[^\n]*call[^\n]*originate/', $amiText)) {
			throw new \RuntimeException(_('Post-restore verification could not authenticate the protected Asterisk Manager integration.'));
		}
		$amiHealthOutput = [];
		$amiHealthStatus = 1;
		@exec(
			'/usr/sbin/runuser -u asterisk -- /usr/bin/timeout 20 /usr/bin/python3 '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --ami-health-json 2>/dev/null',
			$amiHealthOutput,
			$amiHealthStatus
		);
		$amiHealth = json_decode(implode("\n", $amiHealthOutput), true);
		if ($amiHealthStatus !== 0 || !is_array($amiHealth)
			|| ($amiHealth['status'] ?? '') !== 'ok'
			|| ($amiHealth['ami'] ?? '') !== 'authenticated'
			|| ($amiHealth['ping'] ?? '') !== 'pong'
			|| !in_array(($amiHealth['pjsip_show_contacts'] ?? ''), ['authorized', 'authorized_empty'], true)
			|| ($amiHealth['pjsip_notify'] ?? '') !== 'authorized') {
			throw new \RuntimeException(_('Post-restore verification could not complete the authenticated Asterisk Manager capability checks.'));
		}
		$notifyCapabilityOutput = [];
		$notifyCapabilityStatus = 1;
		@exec(
			'/usr/sbin/runuser -u asterisk -- /usr/bin/timeout 20 /usr/bin/python3 '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --notify-capabilities-json 2>/dev/null',
			$notifyCapabilityOutput,
			$notifyCapabilityStatus
		);
		$notifyCapabilities = json_decode(implode("\n", $notifyCapabilityOutput), true);
		$notifyRoutingMode = is_array($notifyCapabilities) ? (string)($notifyCapabilities['routing_mode'] ?? '') : '';
		if ($notifyCapabilityStatus !== 0 || !is_array($notifyCapabilities)
			|| ($notifyCapabilities['endpoint_target'] ?? null) !== true
			|| !in_array($notifyRoutingMode, ['endpoint_fanout', 'contact_uri'], true)
			|| ($notifyRoutingMode === 'contact_uri' && (
				($notifyCapabilities['uri_target'] ?? null) !== true
				|| ($notifyCapabilities['default_outbound_endpoint_available'] ?? null) !== true
				|| ($notifyCapabilities['contact_uri_usable'] ?? null) !== true
			))) {
			throw new \RuntimeException(_('Post-restore verification found unusable Asterisk SIP NOTIFY routing capabilities.'));
		}
		$endpointOutput = [];
		$endpointStatus = 1;
		@exec(
			'/usr/sbin/runuser -u asterisk -- /usr/bin/timeout 20 /usr/bin/python3 '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --list-endpoints-json 2>/dev/null',
			$endpointOutput,
			$endpointStatus
		);
		$endpointJson = json_decode(implode("\n", $endpointOutput), true);
		if ($endpointStatus !== 0 || !is_array($endpointJson)) {
			throw new \RuntimeException(_('Post-restore verification could not complete an authenticated Asterisk Manager inventory request.'));
		}
		$pythonSyntaxCommand = '/usr/bin/python3 -c '
			. escapeshellarg('import pathlib,sys; [compile(pathlib.Path(p).read_text(encoding="utf-8"), p, "exec") for p in sys.argv[1:]]')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_notify.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_notification_destinations.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_system_notifications.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_nws_status.py');
		$syntaxCommands = [
			'/usr/bin/php -l ' . escapeshellarg(__FILE__),
			'/usr/bin/php -l ' . escapeshellarg('/var/www/html/admin/views/menu_items.php'),
			'/usr/bin/php -l ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh'),
			$pythonSyntaxCommand,
			'/usr/sbin/apache2ctl configtest',
			'/usr/sbin/runuser -u asterisk -- /usr/bin/timeout 20 '
				. escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php') . ' --self-test',
		];
		foreach ($syntaxCommands as $command) {
			$output = [];
			$status = 1;
			@exec($command . ' 2>&1', $output, $status);
			if ($status !== 0) {
				throw new \RuntimeException(_('Post-restore repair verification found an invalid runtime, scheduler, or Apache integration.'));
			}
		}
		$probeHost = strtolower(trim((string)($settings['public_pbx_host'] ?? '')));
		$apiChecks = [
			'/api/sipnotify/desktop' => ['401', '429'],
			'/api/sipnotify/desktop/stream' => ['401', '429'],
			'/api/sls-mass-notify/' => ['401', '403', '405', '429'],
		];
		foreach ($apiChecks as $path => $allowedCodes) {
			$targets = [
				['url' => 'https://127.0.0.1' . $path, 'resolve' => ''],
				['url' => 'http://127.0.0.1' . $path, 'resolve' => ''],
			];
			if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $probeHost)) {
				$targets[] = ['url' => 'https://' . $probeHost . $path, 'resolve' => $probeHost . ':443:127.0.0.1'];
				$targets[] = ['url' => 'http://' . $probeHost . $path, 'resolve' => $probeHost . ':80:127.0.0.1'];
			}
			$accepted = false;
			foreach ($targets as $target) {
				$curlCommand = '/usr/bin/curl -ksS --noproxy ' . escapeshellarg('*')
					. ' --connect-timeout 5 --max-time 15 -o /dev/null -w ' . escapeshellarg('%{http_code}');
				if ($target['resolve'] !== '') {
					$curlCommand .= ' --resolve ' . escapeshellarg($target['resolve']);
				}
				$probeOutput = [];
				$probeStatus = 1;
				@exec($curlCommand . ' ' . escapeshellarg($target['url']) . ' 2>/dev/null', $probeOutput, $probeStatus);
				$probeCode = trim(implode('', $probeOutput));
				if ($probeStatus === 0 && in_array($probeCode, $allowedCodes, true)) {
					$accepted = true;
					break;
				}
			}
			if (!$accepted) {
				throw new \RuntimeException(sprintf(_('Post-restore verification could not reach the protected local API route: %s.'), $path));
			}
		}
		$this->verifyNativeLocalApiAuthentication($settings);
		foreach (['slsmassnotifyserver', 'dashboard', 'framework'] as $module) {
			if (!$this->FreePBX->Modules->checkStatus($module)) {
				throw new \RuntimeException(sprintf(_('Required FreePBX module is not enabled: %s.'), $module));
			}
			$verification = \FreePBX::GPG()->verifyModule($module);
			if (!is_array($verification) || (int)($verification['status'] ?? 0) !== 129
				|| !isset($verification['details']) || !is_array($verification['details'])
				|| count($verification['details']) !== 0) {
				throw new \RuntimeException(_('Post-restore local module signature verification failed.'));
			}
		}
		return true;
	}

	/**
	 * Exercise protected local HTTP authentication without exposing credentials
	 * in a process argument or depending on public DNS/network reachability.
	 */
	private function verifyNativeLocalApiAuthentication(array $settings)
	{
		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		if (!empty($control['enabled'])) {
			$apiKey = trim((string)($control['api_key'] ?? ''));
			if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/', $apiKey)) {
				throw new \RuntimeException(_('Post-restore verification found invalid Control API credentials.'));
			}
			$controlVerified = false;
			$controlLocallyUntestable = false;
			foreach ($this->nativeLocalApiProbeResponses(
				$settings,
				'/api/sls-mass-notify/?resource=status',
				['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
				8
			) as $response) {
				$decoded = json_decode((string)$response['body'], true);
				if ((int)$response['status'] === 200 && is_array($decoded)
					&& !empty($decoded['ok']) && ($decoded['resource'] ?? '') === 'status') {
					$controlVerified = true;
					break;
				}
				$error = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
				if (in_array($error, ['ip_not_allowed', 'rate_limited'], true)) {
					// These controls run before credential comparison, so loopback cannot
					// prove authentication under the current policy. Route/auth rejection
					// behavior was already checked by the unauthenticated probes above.
					$controlLocallyUntestable = true;
					break;
				}
			}
			if (!$controlVerified && !$controlLocallyUntestable) {
				throw new \RuntimeException(_('Post-restore verification could not authenticate to the protected local Control API.'));
			}
		}

		$desktopClient = null;
		foreach ((array)($settings['desktop_clients'] ?? []) as $client) {
			if (is_array($client) && !empty($client['enabled'])) {
				$desktopClient = $client;
				break;
			}
		}
		if ($desktopClient === null) {
			return;
		}
		$username = $this->normalizeDesktopUsername($desktopClient['username'] ?? '');
		$password = $this->decryptDesktopPassword((string)($desktopClient['password_enc'] ?? ''), $settings);
		if ($username === '' || $password === '') {
			throw new \RuntimeException(_('Post-restore verification could not decrypt an enabled Desktop client credential.'));
		}
		$desktopVerified = false;
		foreach ($this->nativeLocalApiProbeResponses(
			$settings,
			'/api/sipnotify/desktop/stream?stream_seconds=1',
			[
				'Authorization: Basic ' . base64_encode($username . ':' . $password),
				'Accept: text/event-stream',
			],
			8
		) as $response) {
			$headers = implode("\n", (array)$response['headers']);
			$body = (string)$response['body'];
			if ((int)$response['status'] === 200
				&& stripos($headers, 'Content-Type: text/event-stream') !== false
				&& strpos($body, 'event: authenticated') !== false
				&& strpos($body, '"transport":"live_sse"') !== false) {
				$desktopVerified = true;
				break;
			}
		}
		if (!$desktopVerified) {
			throw new \RuntimeException(_('Post-restore verification could not complete the Desktop live authentication handshake.'));
		}
	}

	private function nativeLocalApiProbeResponses(array $settings, $path, array $headers, $timeoutSeconds)
	{
		$path = (string)$path;
		if (!preg_match('#^/[A-Za-z0-9/_?&=.-]{1,511}$#D', $path)) {
			throw new \RuntimeException(_('Post-restore verification rejected an invalid local API probe path.'));
		}
		$headerText = '';
		foreach ($headers as $header) {
			$header = trim((string)$header);
			if ($header === '' || strpos($header, "\r") !== false || strpos($header, "\n") !== false) {
				throw new \RuntimeException(_('Post-restore verification rejected an invalid local API probe header.'));
			}
			$headerText .= $header . "\r\n";
		}
		$hosts = ['127.0.0.1'];
		$publicHost = strtolower(trim((string)($settings['public_pbx_host'] ?? '')));
		if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $publicHost)
			&& !in_array($publicHost, ['127.0.0.1', 'localhost'], true)) {
			$hosts[] = $publicHost;
		}
		$timeoutSeconds = max(2, min(10, (int)$timeoutSeconds));
		foreach ($hosts as $host) {
			$hostHeader = $host === '127.0.0.1' ? '' : 'Host: ' . $host . "\r\n";
			$context = stream_context_create([
				'http' => [
					'method' => 'GET',
					'header' => $hostHeader . $headerText . "Connection: close\r\n",
					'timeout' => $timeoutSeconds,
					'ignore_errors' => true,
					'follow_location' => 0,
					'max_redirects' => 0,
				],
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
					'peer_name' => $host,
					'SNI_enabled' => true,
				],
			]);
			foreach (['http', 'https'] as $scheme) {
				$http_response_header = [];
				$body = @file_get_contents(
					$scheme . '://127.0.0.1' . $path,
					false,
					$context,
					0,
					262144
				);
				$responseHeaders = is_array($http_response_header) ? $http_response_header : [];
				$status = 0;
				foreach ($responseHeaders as $responseHeader) {
					if (preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#i', (string)$responseHeader, $matches)) {
						$status = (int)$matches[1];
					}
				}
				yield [
					'status' => $status,
					'headers' => $responseHeaders,
					'body' => is_string($body) ? $body : '',
				];
			}
		}
	}

	private function clearSuccessfulInstallFailureState()
	{
		if (is_file(self::INSTALL_FAILURE_JSON) && !is_link(self::INSTALL_FAILURE_JSON)) {
			@unlink(self::INSTALL_FAILURE_JSON);
		}
		$this->deleteInstallFailureNotification();
	}

	private function deleteInstallFailureNotification()
	{
		try {
			if (class_exists('\\FreePBX')) {
				$notifications = \FreePBX::Notifications();
				if (is_object($notifications) && method_exists($notifications, 'delete')) {
					$notifications->delete('slsmassnotifyserver', 'INSTALLFAILED');
				}
			}
		} catch (\Throwable $e) {
			// Notification cleanup must not turn an otherwise successful install or
			// uninstall into a partial module operation.
		}
	}

	private function sanitizeLimit($limit)
	{
		$limit = (int)$limit;
		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}
		return min($limit, self::MAX_LIMIT);
	}

	private function sanitizeType($type)
	{
		$type = strtolower(trim((string)$type));
		return in_array($type, ['nws', 'xweather', 'test', 'announcement', 'announcement_audio'], true) ? $type : '';
	}

	private function sanitizeLogDate($date)
	{
		$date = trim((string)$date);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
			return '';
		}
		return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? $date : '';
	}
}
