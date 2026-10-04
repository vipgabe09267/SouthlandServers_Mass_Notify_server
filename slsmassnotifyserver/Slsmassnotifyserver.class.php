<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

namespace FreePBX\modules;

require_once __DIR__ . '/AnnouncementDelivery.php';
require_once __DIR__ . '/AnnouncementPreview.php';
require_once __DIR__ . '/SpeechRules.php';
require_once __DIR__ . '/SystemRecordings.php';
require_once __DIR__ . '/StatusHealth.php';
require_once __DIR__ . '/AnnouncementAdmission.php';
require_once __DIR__ . '/ScheduledDelivery.php';
require_once __DIR__ . '/SchedulePresentation.php';
require_once __DIR__ . '/ScheduleCalendar.php';
require_once __DIR__ . '/UpdatePolicy.php';
require_once __DIR__ . '/TestProfiles.php';
require_once __DIR__ . '/SupportDiagnostics.php';
require_once __DIR__ . '/DeploymentAcceptance.php';
require_once __DIR__ . '/DesktopCapacity.php';
require_once __DIR__ . '/DesktopFleet.php';
require_once __DIR__ . '/PhoneEventService.php';
require_once __DIR__ . '/OutboundVoice.php';
require_once __DIR__ . '/AnnouncementEmail.php';
require_once __DIR__ . '/AnnouncementSms.php';
require_once __DIR__ . '/Paging.php';
require_once __DIR__ . '/LocalWebProbe.php';
require_once __DIR__ . '/AdvertisedAddress.php';
require_once __DIR__ . '/EncryptedConfig.php';
require_once __DIR__ . '/ConfigCrypto.php';
require_once __DIR__ . '/AdminAudit.php';
require_once __DIR__ . '/OperationalBackup.php';
require_once __DIR__ . '/Incidents.php';
require_once __DIR__ . '/Locations.php';
require_once __DIR__ . '/Automations.php';
require_once __DIR__ . '/Operators.php';
require_once __DIR__ . '/WeatherDeliveryReceipts.php';
require_once __DIR__ . '/WeatherChannels.php';
require_once __DIR__ . '/ApiCredentialManagement.php';
require_once __DIR__ . '/ApiPermissionGuards.php';
require_once __DIR__ . '/api/sls-mass-notify/media-policy.php';
require_once __DIR__ . '/EnterpriseAdministration.php';
require_once __DIR__ . '/EnterpriseOperations.php';
require_once __DIR__ . '/EnterpriseCluster.php';
require_once __DIR__ . '/EnterpriseIdentityManagement.php';
require_once __DIR__ . '/EnterpriseIntegrations.php';

/** Only controlled operator-safe messages from protected configuration writes. */
final class SlsConfigurationWriteException extends \RuntimeException
{
	private $replaced;
	public function __construct($message, $replaced = false)
	{
		parent::__construct($message);
		$this->replaced = (bool)$replaced;
	}
	public function settingsWereReplaced() { return $this->replaced; }
}

#[\AllowDynamicProperties]
class Slsmassnotifyserver implements \BMO
{
	use SlsAnnouncementDelivery;
	use SlsWeatherChannels;
	use SlsAnnouncementPreview;
	use SlsAnnouncementAdmission;
	use SlsScheduledDelivery;
	use SlsTestProfiles;
	use SlsSupportDiagnostics;
	use SlsDeploymentAcceptance;
	use SlsDesktopCapacity;
	use SlsPhoneEventService;
	use SlsOutboundVoice;
	use SlsAnnouncementEmail;
	use SlsAnnouncementSms;
	use SlsLivePaging;
	use SlsAdvertisedAddressEditor;
	use SlsAdminAudit;
	use SlsOperationalBackup;
	use SlsIncidents;
	use SlsLocations;
	use SlsAutomations;
	use SlsOperators;
	use SlsApiCredentialManagement;
	use SlsApiPermissionGuards;
	use SlsEnterpriseAdministration;
	use SlsEnterpriseOperations;
	use SlsEnterpriseCluster;
	use SlsEnterpriseLabs;
	use SlsEnterpriseIntegrations;
	const MODULE_VERSION = '0.1.5-beta';
	const INCIDENT_JOB_CONTRACT = 1;
	const EVENTS_LOG = '/var/log/sls_mass_notify_events.jsonl';
	const LEGACY_EVENTS_LOG = '/var/log/nws_weather_alert_events.jsonl';
	const PLUGIN_DATA_DIR = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
	const PHONE_EVENT_SERVICE_FILE = '/etc/systemd/system/sls-mass-notify-phone-events.service';
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
	const ANNOUNCEMENT_ACTIVITY_LOCK_FILE = self::PLUGIN_DATA_DIR . '/announcement-activity.lock';
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
	const UI_LOG_SCAN_BYTES = 16 * 1024 * 1024;
	const UI_LOG_LINE_BYTES = 256 * 1024;
	const UI_LOG_RESULT_BYTES = 2 * 1024 * 1024;
	const UI_LOG_SCAN_LINES = 50000;
	const CSRF_SESSION_KEY = 'slsmassnotifyserver_csrf_token';

	/** File fingerprints captured while a request builds a settings update. */
	private $settingsReadFingerprints = [];
	/** Normalized settings cached for the lifetime of the current PHP request. */
	private $normalizedSettingsCache = [];
	/** Exclusive activity leases paired with settings locks for active replacement. */
	private $settingsActivityLocks = [];
	/** Request-local endpoint inventory caches avoid repeated AMI/database discovery. */
	private $registeredPjsipExtensionsCache = null;
	private $extensionNameMapCache = null;
	private $allPjsipExtensionsCache = null;
	private $sipNotifyTargetsCache = null;
	private $deviceInventoryError = '';
	/** Read limits/errors for the current log view; never persisted to the log. */
	private $uiLogNotices = [];

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
	}

	public function install()
	{
		return $this->installUnprivilegedPhase();
	}

	/** Initialize only absent configuration while the installer owns activity. */
	public function initializeFreshInstallerConfiguration(int $phoneCapacity = 25)
	{
		$this->requireRuntimeAccount();
		$this->ensurePluginDataDir();
		// The installer already excludes notification activity. Take only the
		// settings mutex here; never reacquire its parent's exclusive activity lock.
		$lock = $this->acquireSettingsLock(false);
		try {
			clearstatcache(true, self::SETTINGS_JSON);
			$existing = @lstat(self::SETTINGS_JSON);
			if ($existing !== false) {
				if (($existing['mode'] & 0170000) !== 0100000 || $existing['nlink'] !== 1
					|| $existing['size'] > SlsConfigCrypto::MAX_FILE_BYTES || !is_readable(self::SETTINGS_JSON)) {
					throw new \RuntimeException(_('An existing configuration is unreadable, oversized, or an unsafe file. Preserve it and resolve that condition before installation.'));
				}
				// Do not open a path that appeared during initialization. The
				// installer validates its contents with the protected snapshot reader.
				return false;
			}
			clearstatcache(true, self::PENDING_SETTINGS_JSON);
			if (@lstat(self::PENDING_SETTINGS_JSON) !== false) {
				throw new \RuntimeException(_('Staged settings exist without an active configuration. Preserve both recovery files and restore the active configuration before installation.'));
			}
			if ($phoneCapacity < 25 || $phoneCapacity > 1000) {
				throw new \DomainException(_('The detected initial phone capacity must be between 25 and 1000.'));
			}
			$active = $this->getActiveSettings();
			$settings = $this->normalizeSettings($active);
			$settings['phone_device_limit'] = $phoneCapacity;
			$this->assertDesktopCapacityChange($settings, $active);
			$this->writeSettingsFileUnlocked(self::SETTINGS_JSON, $settings, false);
			$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
			return true;
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	/** The signed installer performs fixed root operations before this phase. */
	public function installUnprivilegedPhase()
	{
		$this->requireRuntimeAccount();
		$existing = $this->configurationPathMetadata(self::SETTINGS_JSON);
		if ($existing !== null) {
			// Validate before repair can change permissions or create a new file.
			$this->loadSettingsFile(self::SETTINGS_JSON);
		}
		$this->ensurePluginDataDir();
		$this->migrateLegacyTestStatus();
		if ($existing === null && $this->configurationPathMetadata(self::SETTINGS_JSON) === null) {
			$this->persistAppliedSettings($this->getActiveSettings());
		} else {
			// Reading an existing deployment must not rewrite its protected settings.
			$this->getActiveSettings();
		}
		$this->ensureBundledSystemRecordings();
		$this->ensureAmiUser();
		$this->ensureDialplan();
		$this->ensureSipNotifyTemplates();
		$this->ensureMenuPlacement();
		$this->ensureDashboardWidget();
		$this->ensureCronJob();
		$backupEnrollment = $this->ensureFreePbxBackupEnrollment();
		if (empty($backupEnrollment['success'])) {
			throw new \RuntimeException(_('FreePBX backup enrollment could not be verified.'));
		}
		return true;
	}

	private function requireRuntimeAccount()
	{
		$account = function_exists('posix_getpwnam') ? posix_getpwnam('asterisk') : false;
		$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		if (!is_array($account) || !is_int($account['uid'] ?? null) || $account['uid'] <= 0 || $uid !== $account['uid']) {
			throw new \RuntimeException(_('FreePBX integration must run as the asterisk service account. Use the signed SLS release installer or General Settings > Repair Installation; do not execute module PHP as root.'));
		}
	}

	public function uninstall()
	{
		return $this->uninstallUnprivilegedPhase();
	}

	/** Fixed root teardown is performed separately by the signed uninstaller. */
	public function uninstallUnprivilegedPhase()
	{
		$this->requireRuntimeAccount();
		$this->removeFreePbxBackupEnrollment();
		$this->removeCronJob();
		$this->removeAmiUsers();
		$this->removeDashboardWidget();
		$this->removeMenuPlacement();
		$this->removeManagedBlock('/etc/asterisk/sip_notify_custom.conf', 'SLS Mass Notifications SIP NOTIFY Templates');
		$this->removeManagedBlock('/etc/asterisk/extensions_custom.conf', 'SLS Mass Notifications Dialplan');
		$this->removeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI');
		$this->removeBundledSystemRecordings();
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('module reload res_pjsip_notify.so'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('manager reload'));
		$this->deleteInstallFailureNotification();
		return true;
	}
	public function backup()
	{
		$lock = $this->acquireSettingsLock(true);
		try {
			return [
				'settings' => $this->getActiveSettings(),
				'version' => self::MODULE_VERSION,
			];
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	public function restore($backup)
	{
		if (is_array($backup) && is_array($backup['settings'] ?? null)) {
			$errors = $this->validateConfigValueTypes($backup['settings']);
			if ($errors) { throw new \DomainException(implode(' ', $errors)); }
			$settings = $this->normalizeSettings($backup['settings']);
			$this->disableEnterpriseAfterRecovery($settings);
			foreach ($settings['operator_access']['accounts'] ?? [] as $index => $account) { $settings['operator_access']['accounts'][$index]['enabled'] = false; }
			foreach ($settings['automations']['rules'] ?? [] as $index => $rule) { $settings['automations']['rules'][$index]['enabled'] = false; }
			foreach ((array)($settings['scheduled_announcements'] ?? []) as $index => $schedule) {
				$settings['scheduled_announcements'][$index]['enabled'] = '0';
			}
			$lock = $this->acquireSettingsLock(true);
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
			$configLock = $this->acquireSettingsLock(true);
			$announcementLock = $this->acquireNativeBackupFileLock(
				self::ANNOUNCEMENT_LOCK_FILE,
				_('An active announcement did not complete in time for backup.')
			);

			$configRaw = $this->readNativeBackupFile(
				self::SETTINGS_JSON,
				SlsConfigCrypto::MAX_FILE_BYTES,
				_('The protected central configuration is unavailable for backup.')
			);
			$backupSettings = $this->validateNativeBackupConfig($configRaw);
			if (!SlsConfigCrypto::isEncrypted(json_decode($configRaw, true))) {
				$configRaw = SlsConfigCrypto::encode($backupSettings);
			}
			$configSnapshot = $snapshotDir . '/protected-config.json';
			$this->writeNativeSnapshotFile($configSnapshot, $configRaw, 0600);
			$keySnapshot = $snapshotDir . '/protected-config-key.json';
			$this->writeNativeSnapshotFile($keySnapshot, SlsConfigCrypto::backupKeyring($configRaw), 0600);

			$files = [[
				'type' => 'slsmassnotify-config',
				'filename' => basename($configSnapshot),
				'path' => $snapshotDir,
			], [
				'type' => 'slsmassnotify-config-key',
				'filename' => basename($keySnapshot),
				'path' => $snapshotDir,
			]];
			$manifestFiles = [
				'config' => $this->nativeBackupFileManifest(
					'slsmassnotify-config',
					basename($configSnapshot),
					basename(self::SETTINGS_JSON),
					$configSnapshot
				),
				'key' => $this->nativeBackupFileManifest(
					'slsmassnotify-config-key', basename($keySnapshot), 'protected-config-key.json', $keySnapshot
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

            if (file_exists(self::PLUGIN_DATA_DIR.'/sms') || is_link(self::PLUGIN_DATA_DIR.'/sms')) {
                $smsSnapshot=$snapshotDir.'/sms-ledger.jsonl';
                $this->announcementSmsStore()->exportSnapshot($smsSnapshot);
                $files[]=['type'=>'slsmassnotify-sms','filename'=>'sms-ledger.jsonl','path'=>$snapshotDir];
                $manifestFiles['sms']=$this->nativeBackupFileManifest('slsmassnotify-sms','sms-ledger.jsonl','sms-ledger.jsonl',$smsSnapshot);
            }

			$operationalSnapshot = $snapshotDir . '/operational-evidence.jsonl';
			$this->operationalBackupCommand('snapshot', $operationalSnapshot);
			$files[] = ['type' => 'slsmassnotify-operational', 'filename' => 'operational-evidence.jsonl', 'path' => $snapshotDir];
			$manifestFiles['operational'] = $this->nativeBackupFileManifest('slsmassnotify-operational', 'operational-evidence.jsonl', 'operational-evidence.jsonl', $operationalSnapshot);

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
					'operational_history' => 'archive_only_no_replay',
					'weather_automation' => 'disable_for_review',
				],
			];
			$this->auditSensitiveExport('native', $backupSettings);
			$this->updateStatusData([
				'last_native_backup_at' => gmdate('c'),
				'last_native_backup_status' => 'ok',
				'last_native_backup_message' => _('Encrypted configuration, its recovery key, scheduling state, custom tones and operational evidence were snapshotted for FreePBX Backup.'),
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
		$restoreWarnings = $this->commitNativeRestorePayload(
			$prepared['config_raw'],
			$prepared['schedule_raw'],
			$prepared['schedule_present'],
			$payload['tones'],
			$transactionId,
			$prepared['warnings'],
            $payload['sms']??null,
			$payload['operational'] ?? null
		);
		$this->updateStatusData([
			'last_native_restore_at' => gmdate('c'),
			'last_native_restore_status' => empty($restoreWarnings) ? 'ok' : 'warning',
			'last_native_restore_message' => empty($restoreWarnings)
				? _('FreePBX restored the protected Mass Notifications data. Integration repair is pending.')
				: implode(' ', $restoreWarnings),
		]);
		return [
			'success' => true,
			'warnings' => $restoreWarnings,
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

		$this->updateStatusData([
			'last_native_restore_status' => 'warning',
			'last_native_restore_message' => _('Protected data was restored; authenticated integration repair was queued.'),
		]);
		return true;
	}

	/**
	 * Verify every safety-critical postcondition before a protected repair may
	 * clear the persistent install-failure warning.
	 */
	public function verifyUnprivilegedIntegration()
	{
		$this->requireRuntimeAccount();
		return $this->verifyNativePostRestoreIntegration(false);
	}

	/** Called after the root helper and unprivileged verifier both succeed. */
	public function finalizeVerifiedUnprivilegedRestore()
	{
		$this->requireRuntimeAccount();
		return $this->finalizeNativeRestoreAfterVerifiedRepair();
	}

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
	public function myConfigPageInits() { return ['index']; }
	public function doConfigPageInit($page)
	{
		if ($page === 'index') {
			// Register before Dashboard inserts widgets containing external CDN scripts.
			$revision = substr(hash_file('sha256', __DIR__ . '/views/dashboard_assets.js'), 0, 16);
			echo '<script src="modules/slsmassnotifyserver/views/dashboard_assets.js?v=' . $revision . '"></script>';
		} elseif (is_string($page) && str_starts_with($page, 'slsmassnotifyserver')) {
			$this->enforceOperatorPageAccess($page);
		}
	}
	public function getRightNav($request) {}
	public function getActionBar($request) {}

	/** Apply staged settings as part of FreePBX's native Apply Config/reload. */
	public function genConfig()
	{
		// A release reload regenerates PBX integration from active settings. It
		// must preserve the administrator's staged changes for their own Apply.
		if (PHP_SAPI === 'cli' && getenv('SLS_MASS_NOTIFY_PRESERVE_PENDING') === '1') {
			$this->requireRuntimeAccount();
			return [];
		}
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
			case 'paging':
				return $this->renderLivePagingPage($params);
			case 'setup':
				return $this->renderSetupWizard($pendingSettings ?? $activeSettings, $activeSettings, $params['save_result'] ?? null, false);
			case 'detail':
					$event = $this->getEventById($params['id'] ?? '');
					return load_view(__DIR__ . '/views/detail.php', [
						'event' => $event,
						'weather_delivery' => $event ? \SLS\MassNotify\WeatherDeliveryReceipts::forEvent(self::PLUGIN_DATA_DIR, $event, $activeSettings) : [],
						'log_notices' => $this->getUiLogNotices('events'),
						'hero_image' => self::HERO_IMAGE,
				]);
			case 'settings':
				return load_view(__DIR__ . '/views/settings.php', [
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings),
					'weather_voice_recipients' => $this->getOutboundVoiceRecipients($pendingSettings ?? $activeSettings),
					'weather_sms_recipients' => $this->getAnnouncementSmsRecipients($pendingSettings ?? $activeSettings),
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
					'weather_voice_recipients' => $this->getOutboundVoiceRecipients($pendingSettings ?? $activeSettings),
					'weather_sms_recipients' => $this->getAnnouncementSmsRecipients($pendingSettings ?? $activeSettings),
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
					'weather_voice_recipients' => $this->getOutboundVoiceRecipients($pendingSettings ?? $activeSettings),
					'weather_sms_recipients' => $this->getAnnouncementSmsRecipients($pendingSettings ?? $activeSettings),
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'test_result' => $params['test_result'] ?? null,
					'connection_result' => $params['connection_result'] ?? null,
					'api_usage' => $this->getXweatherApiUsageSummary(),
					'area_coverage' => $this->loadStatusData()['xweather_groups'] ?? [],
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
					'outbound_voice_recipients' => $this->getOutboundVoiceRecipients($activeSettings),
					'announcement_email_recipients' => $this->getAnnouncementEmailRecipients($activeSettings),
					'announcement_sms_recipients' => $this->getAnnouncementSmsRecipients($activeSettings),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'pbx_timezone' => $this->getPbxDateTimeZone()->getName(),
					'save_result' => $params['save_result'] ?? null,
					'hero_image' => self::HERO_IMAGE,
					'csrf_token' => $csrfToken,
				]);
			case 'other_settings':
				return load_view(__DIR__ . '/views/other_settings.php', [
					'configuration_backup_reminder' => $this->getConfigurationBackupReminder(),
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'api_credentials' => $this->apiCredentialMetadata(),
					'announcement_sms_usage' => $this->getAnnouncementSmsUsage(),
					'has_pending_changes' => $pendingSettings !== null,
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
					'import_result' => $params['import_result'] ?? null,
					'available_extensions' => $this->getAllPjsipExtensions(),
					'available_voices' => $this->getAvailablePiperVoices(),
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'desktop_clients' => $this->getDesktopClients($pendingSettings ?? $activeSettings, true),
					'outbound_voice_trunks' => $this->getOutboundVoiceTrunks(),
					'outbound_voice_dids' => $this->getOutboundVoiceDids(),
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
					'log_notices' => $this->getUiLogNotices('events'),
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
			'pbx_timezone' => $this->getPbxDateTimeZone()->getName(),
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

	/** Parse only explicit setup address/capacity edits; saved credentials and routes are untouched. */
	private function readSetupConnectionPreferences(array $settings, array $input): array
	{
		if (array_key_exists('pbx_timezone', $input)) {
			$zone = $input['pbx_timezone'];
			if (!is_string($zone) || !in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) { throw new \DomainException(_('Select a valid IANA PBX timezone.')); }
			$settings['pbx_timezone'] = $zone;
		}
		if (($input['sls_address_change'] ?? '') === '1') {
			$settings = SlsAdvertisedAddress::migrate($settings, $input);
		}
		foreach (['desktop_client_limit'=>_('Desktop capacity'), 'phone_device_limit'=>_('Phone capacity')] as $field=>$label) {
			if (!array_key_exists($field, $input)) { continue; }
			if (!is_string($input[$field]) || !preg_match('/^[1-9][0-9]{0,3}$/D', $input[$field]) || (int)$input[$field] > 1000) {
				throw new \DomainException(sprintf(_('%s must be a whole number from 1 to 1000.'), $label));
			}
			$settings[$field] = (int)$input[$field];
		}
		return $settings;
	}

	public function saveSetupWizard(array $input)
	{
		$errors = [];
		if (isset($input['sls_setup_form_present']) && ($input['sls_setup_form_complete'] ?? '') !== '1') {
			return ['success'=>false, 'message'=>_('The setup form was incomplete. Reload the page before saving; no settings were changed.'), 'errors'=>[]];
		}
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
		try { $settings = $this->readSetupConnectionPreferences($settings, $input); }
		catch (\DomainException $error) { return ['success'=>false, 'message'=>$error->getMessage(), 'errors'=>[]]; }
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
			if (!empty($settings['nws_zones'][0])) {
				$settings['nws_zones'][0]['quiet_hours_enabled'] = $settings['quiet_hours_enabled'];
				$settings['nws_zones'][0]['quiet_hours_start'] = $settings['quiet_hours_start'];
				$settings['nws_zones'][0]['quiet_hours_end'] = $settings['quiet_hours_end'];
				$settings['nws_zones'][0]['quiet_critical_events'] = $settings['quiet_critical_events'];
			}
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
		$errors = array_merge($errors, $this->validateXweatherAdaptivePolicyInput($submittedXweather));
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
				foreach (['location', 'radius_miles', 'adaptive_nws_zone_id', 'all_clear', 'strike_type', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'] as $legacyField) {
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
		if (trim((string)($setupXweather['client_secret'] ?? '')) === '' && ($setupXweather['provider']??'xweather') === ($currentXweather['provider']??'xweather')) {
			$setupXweather['client_secret'] = $currentXweather['client_secret'] ?? '';
		}
		$lightningDefaultVolume = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 25, 25);
		$settings['xweather'] = $this->normalizeXweatherSettings($setupXweather, $lightningDefaultVolume);
		if ($settings['xweather']['enabled'] === '1') {
			if ($settings['xweather']['client_id'] === '' || ($settings['xweather']['provider'] !== 'tempest' && $settings['xweather']['client_secret'] === '')) {
				$errors[] = _('Enabled lightning alerts require the selected provider’s credentials.');
			}
			if ($settings['xweather']['adaptive_free_tier'] === '1' && empty($settings['enabled'])) {
				$errors[] = _('Free-tier adaptive lightning polling requires Weather Alerts to be enabled with at least one weather zone.');
			}
			$validZoneIds = array_column((array)$settings['nws_zones'], 'id');
			$enabledLightningGroups = 0;
			foreach ($settings['xweather']['groups'] as $group) {
				if (($group['enabled'] ?? '0') !== '1') continue;
				$enabledLightningGroups++;
                if (($settings['xweather']['provider']??'xweather')!=='xweather' && !preg_match('/^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$/D', (string)($group['location']??''))) { $errors[]=_('Tempest and Meteomatics need latitude,longitude coordinates for each area.'); }
				if (($group['location'] ?? '') === '' || !$this->xweatherGroupHasDeliveryDestination($group, $settings)) {
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
		$sipnotify['media_scheme'] = $this->normalizePhoneMediaScheme((string)($input['sipnotify_media_scheme'] ?? $sipnotify['media_scheme'] ?? 'https'));
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
		$mediaLimit = $input['generated_media_cache_mib'] ?? $settings['generated_media_cache_mib'] ?? 512;
		if (filter_var($mediaLimit, FILTER_VALIDATE_INT, ['options'=>['min_range'=>64, 'max_range'=>4096]]) === false) {
			return ['success'=>false, 'message'=>_('Generated-media cache target must be a whole number from 64 through 4096 MiB.')];
		}
		$settings['generated_media_cache_mib'] = (int)$mediaLimit;
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
			if (!empty($settings['pbx_timezone']) && $settings['pbx_timezone'] !== $this->getPbxDateTimeZone()->getName()) {
				$marker = self::PLUGIN_DATA_DIR . '/timezone.request';
				if (is_link($marker)) { throw new \RuntimeException(_('Timezone apply request is unsafe. Run Repair Installation.')); }
				if (!file_exists($marker)) {
					$handle = @fopen($marker, 'x');
					if (!$handle) { throw new \RuntimeException(_('Setup was saved, but the timezone could not be queued. Check protected data permissions.')); }
					fwrite($handle, "1\n"); fclose($handle); chmod($marker, 0640);
				}
			}
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
		$zonesExplicitlySubmitted = !empty($input['nws_zones_present']);
		$nwsZones = $this->normalizeNwsZoneGroups(
			$input['nws_zones'] ?? $currentSettings['nws_zones'] ?? [],
			$zonesExplicitlySubmitted ? '' : ($input['nws_zone'] ?? $currentSettings['nws_zone'] ?? ''),
			$zonesExplicitlySubmitted ? [] : ($input['alert_recipients'] ?? $currentSettings['alert_recipients'] ?? []),
			[
				'enabled' => $currentSettings['quiet_hours_enabled'] ?? '0',
				'start' => $currentSettings['quiet_hours_start'] ?? '21:00',
				'end' => $currentSettings['quiet_hours_end'] ?? '06:00',
				'critical_events' => $currentSettings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents(),
				'discord_webhook_ids' => array_column(array_values(array_filter((array)($currentSettings['discord_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); })), 'id'),
				'generic_webhook_ids' => array_column(array_values(array_filter((array)($currentSettings['generic_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); })), 'id'),
			]
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
			if (!$this->nwsZoneHasDeliveryDestination($zoneGroup)) {
				$errors[] = sprintf(_('NWS group "%s" needs at least one phone, desktop, email, Discord, or generic webhook destination.'), $zoneGroup['name'] ?? '');
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
		$errors = array_merge($errors, $this->validateNwsZoneDestinationAssignments($nwsZones, $currentSettings));

		// Shared webhook and sender settings are managed from General Settings.
		// Weather saves must not rewrite those canonical destination arrays;
		// live email recipients remain attached to the matching zone group.
		// Top-level quiet-hour keys are rolling-upgrade aliases only. The active
		// policy belongs to each normalized zone; mirror the first zone without
		// overwriting edits made in the zone manager.
		$primaryZonePolicy = $nwsZones[0] ?? [];
		$quietHoursEnabled = empty($primaryZonePolicy['quiet_hours_enabled']) ? '0' : '1';
		$quietHoursStart = $this->normalizeHour((string)($primaryZonePolicy['quiet_hours_start'] ?? $defaults['quiet_hours_start']), $defaults['quiet_hours_start']);
		$quietHoursEnd = $this->normalizeHour((string)($primaryZonePolicy['quiet_hours_end'] ?? $defaults['quiet_hours_end']), $defaults['quiet_hours_end']);
		$quietCriticalEvents = $this->normalizeCriticalEvents($primaryZonePolicy['quiet_critical_events'] ?? $defaults['quiet_critical_events']);
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
		foreach (['adaptive_gate_failure_policy' => 'standby', 'adaptive_fallback_minutes' => 30] as $field => $default) {
			if (!array_key_exists($field, $incoming)) { $incoming[$field] = $current[$field] ?? $default; }
		}
		$policyErrors = $this->validateXweatherAdaptivePolicyInput($incoming);
		if ($policyErrors) { return ['success' => false, 'message' => _('Lightning settings were not saved.'), 'errors' => $policyErrors]; }
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
			$incoming['client_secret'] = ($incoming['provider'] ?? 'xweather') === ($current['provider'] ?? 'xweather') ? ($current['client_secret'] ?? '') : '';
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
			if ($xweather['client_id'] === '' || ($xweather['provider'] !== 'tempest' && $xweather['client_secret'] === '')) {
				$errors[] = _('Enabled lightning alerts require the selected provider’s credentials. Tempest needs an API key; Xweather and Meteomatics also need the secret/password.');
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
                if ($xweather['provider']!=='xweather' && !preg_match('/^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$/D', (string)($group['location']??''))) { $errors[]=_('Tempest and Meteomatics need latitude,longitude coordinates for each area.'); }
				if (($group['location'] ?? '') === '') {
					$errors[] = sprintf(_('Lightning group "%s" requires an Xweather location.'), $label);
				}
				if (!$this->xweatherGroupHasDeliveryDestination($group, $settings)) {
					$errors[] = sprintf(_('Lightning group "%s" requires at least one phone, desktop, email, or enabled shared webhook destination.'), $label);
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
		if ((!empty($input['sls_general_form_present']) || !empty($input['desktop_clients_present'])) && empty($input['sls_general_form_complete'])) {
			return ['success' => false, 'message' => _('The server received an incomplete settings form. Enable JavaScript and reload this page. No settings were saved.'), 'errors' => []];
		}
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

		// Address migration is explicit; routine saves preserve the advertised host and ports.
		$settings['public_pbx_host'] = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? '')) ?: $this->detectPbxHost();
		if (($input['sls_address_change'] ?? '') === '1') {
			try { $settings = SlsAdvertisedAddress::migrate($settings, $input); }
			catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'errors' => []]; }
		}
		$control = is_array($input['control_api'] ?? null) ? $input['control_api'] : [];
		$currentControl = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : $defaults['control_api'];
		try {
			$credentials = \SLS\MassNotify\ApiSecurity::credentials($currentControl['credentials'] ?? []);
			if (array_key_exists('trusted_proxy_cidrs', $input)) {
				if (!is_string($input['trusted_proxy_cidrs'])) { throw new \DomainException(_('Trusted proxies must be entered as one CIDR network per line.')); }
				$settings['api_network'] = \SLS\MassNotify\ApiSecurity::network(['trusted_proxy_cidrs' => preg_split('/[\s,]+/', trim($input['trusted_proxy_cidrs']), -1, PREG_SPLIT_NO_EMPTY)]);
			}
			if (array_key_exists('media_access', $input)) {
				if (!is_array($input['media_access'])) { throw new \DomainException(_('Media access settings must be an object.')); }
				$settings['media_access'] = \SLS\MassNotify\MediaAccess::form($input['media_access']);
			}
		} catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'errors' => []]; }
		$apiKey = trim((string)($control['api_key'] ?? $currentControl['api_key'] ?? ''));
		if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/', $apiKey)) {
			$apiKey = $this->generateApiKey();
		}
		$settings['control_api'] = [
			'enabled' => empty($control['enabled']) ? '0' : '1',
			'api_key' => $apiKey,
			'credentials' => $credentials,
			'base_url' => $this->getControlApiUrl($settings),
			'ip_allowlist_enabled' => empty($control['ip_allowlist_enabled']) ? '0' : '1',
			'ip_allowlist' => $this->normalizeIpAllowlist((string)($control['ip_allowlist'] ?? $currentControl['ip_allowlist'] ?? '')),
			'rate_limit_enabled' => empty($control['rate_limit_enabled']) ? '0' : '1',
			'audit_syslog' => ($control['audit_syslog'] ?? $currentControl['audit_syslog'] ?? '0') === '1' ? '1' : '0',
			'rate_limit_per_minute' => $this->normalizeInt($control['rate_limit_per_minute'] ?? $currentControl['rate_limit_per_minute'] ?? 60, 1, 600, 60),
			'audit_retention_days' => 30,
		];
		$sipnotifySettings = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
		$sipnotifySettings['pbx_host'] = $settings['public_pbx_host'];
		$sipnotifySettings['media_scheme'] = $this->normalizePhoneMediaScheme((string)($input['sipnotify_media_scheme'] ?? $sipnotifySettings['media_scheme'] ?? 'https'));
		$formatOverrideInput = $input['sipnotify_format_overrides'] ?? (!empty($input['sipnotify_format_overrides_present']) ? [] : ($sipnotifySettings['format_overrides'] ?? []));
		$sipnotifySettings['format_overrides'] = $this->normalizeEndpointFormatOverrides($formatOverrideInput);
		$deviceOverrideInput = $input['sipnotify_device_overrides'] ?? (!empty($input['sipnotify_device_overrides_present']) ? [] : ($sipnotifySettings['device_format_overrides'] ?? []));
		$sipnotifySettings['device_format_overrides'] = $this->normalizeDeviceFormatOverrides($deviceOverrideInput);
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
		$announcementWebhookInput = is_array($input['announcement_webhooks'] ?? null)
			? $input['announcement_webhooks']
			: (!empty($input['announcement_webhooks_present']) ? [] : ($settings['announcement_webhooks'] ?? []));
		$discordInput = $this->mergeWebhookDestinationSecrets($discordInput, $settings['discord_webhooks'] ?? [], 'discord');
		$genericInput = $this->mergeWebhookDestinationSecrets($genericInput, $settings['generic_webhooks'] ?? [], 'generic');
		$announcementWebhookInput = $this->mergeWebhookDestinationSecrets($announcementWebhookInput, $settings['announcement_webhooks'] ?? [], 'announcement');
		$errors = array_merge(
			$errors,
			$this->validateWebhookDestinations($discordInput, 'discord'),
			$this->validateWebhookDestinations($genericInput, 'generic'),
			$this->validateWebhookDestinations($announcementWebhookInput, 'announcement')
		);
		$settings['discord_webhooks'] = $this->normalizeWebhookDestinations($discordInput, 'discord');
		$settings['generic_webhooks'] = $this->normalizeWebhookDestinations($genericInput, 'generic');
		$settings['announcement_webhooks'] = $this->normalizeWebhookDestinations($announcementWebhookInput, 'announcement');
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
		$settings['paging_answer_timeout'] = $this->normalizePagingAnswerTimeout($input['paging_answer_timeout'] ?? $settings['paging_answer_timeout'] ?? 5);
		if (isset($input['test_profiles_present'])) {
			$settings['test_profiles'] = $this->normalizeTestProfiles($input['test_profiles'] ?? []);
		}
		$settings['log_retention_days'] = $this->normalizeRetentionDays($input['log_retention_days'] ?? $settings['log_retention_days'] ?? 90);
		$mediaLimit = $input['generated_media_cache_mib'] ?? $settings['generated_media_cache_mib'] ?? 512;
		if (filter_var($mediaLimit, FILTER_VALIDATE_INT, ['options'=>['min_range'=>64, 'max_range'=>4096]]) === false) {
			return ['success'=>false, 'message'=>_('Generated-media cache target must be a whole number from 64 through 4096 MiB.')];
		}
		$settings['generated_media_cache_mib'] = (int)$mediaLimit;
		$updates = is_array($input['updates'] ?? null) ? $input['updates'] : [];
		try { $settings['updates'] = \SLS\MassNotify\UpdatePolicy::form($updates, (array)($settings['updates'] ?? [])); }
		catch (\DomainException $error) { return ['success'=>false, 'message'=>$error->getMessage(), 'errors'=>[$error->getMessage()]]; }
		$settings['announcement_groups'] = $settings['announcement_groups'] ?? [];
		$settings['desktop_auth_key'] = $this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? '');
		$limitInput = $input['desktop_client_limit'] ?? $settings['desktop_client_limit'] ?? 25;
		if (filter_var($limitInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
			return ['success' => false, 'message' => _('Desktop Capacity must be a whole number from 1 to 1000.'), 'errors' => []];
		}
		$settings['desktop_client_limit'] = (int)$limitInput;
		$minimumVersion = $input['desktop_minimum_version'] ?? $settings['desktop_minimum_version'] ?? '';
		if (!\SLS\MassNotify\DesktopFleet::validMinimum($minimumVersion)) {
			return ['success'=>false, 'message'=>_('Minimum desktop version must be empty or a version such as 1.2.3 or 1.2.3-beta.1.'), 'errors'=>[]];
		}
		$settings['desktop_minimum_version'] = $minimumVersion;
		try {
			if (array_key_exists('announcement_pronunciation_text', $input)) {
				$settings['announcement_pronunciation'] = \SLS\MassNotify\SpeechRules::fromText($input['announcement_pronunciation_text']);
			}
		} catch (\DomainException $error) { return ['success'=>false, 'message'=>$error->getMessage(), 'errors'=>[]]; }
		$phoneLimitInput = $input['phone_device_limit'] ?? $settings['phone_device_limit'] ?? 25;
		if (filter_var($phoneLimitInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
			return ['success' => false, 'message' => _('Phone Capacity must be a whole number from 1 to 1000 registered device contacts.'), 'errors' => []];
		}
		$settings['phone_device_limit'] = (int)$phoneLimitInput;
		// Recipient edits live in the directory. A provider form opened earlier
		// must not replace a more recent saved recipient or renewed SMS consent.
		if (($input['recipient_directory_managed'] ?? '') === '1') {
			foreach (['outbound_voice', 'announcement_email', 'announcement_sms'] as $channel) {
				$input[$channel . '_recipients_json'] = json_encode($settings[$channel]['recipients'] ?? [], JSON_THROW_ON_ERROR);
				$input[$channel . '_complete'] = '1';
			}
		}
		try { $settings['outbound_voice'] = $this->readOutboundVoiceForm($input, $settings['outbound_voice'] ?? $this->defaultOutboundVoice()); }
		catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'errors' => []]; }
		try { $settings['announcement_email'] = $this->readAnnouncementEmailForm($input, $settings['announcement_email'] ?? $this->defaultAnnouncementEmail()); }
		catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'errors' => []]; }
		try { $settings['announcement_sms'] = $this->readAnnouncementSmsForm($input, $settings['announcement_sms'] ?? $this->defaultAnnouncementSms()); }
		catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'errors' => []]; }
		$desktopClientInput = $input['desktop_clients'] ?? $settings['desktop_clients'] ?? [];
		if (!empty($input['desktop_clients_json'])) {
			$clientJson = $input['desktop_clients_json'];
			$decodedClients = is_string($clientJson) && strlen($clientJson) <= 1024 * 1024 ? json_decode($clientJson, true, 16) : null;
			if (!is_array($decodedClients) || !array_is_list($decodedClients) || count($decodedClients) > 1000) {
				return ['success' => false, 'message' => _('Desktop client data is invalid or exceeds the 1000-client limit. Reload the page before saving.'), 'errors' => []];
			}
			$desktopClientInput = $decodedClients;
		} elseif (!empty($input['desktop_clients_present']) && empty($input['desktop_clients_complete'])) {
			return ['success' => false, 'message' => _('The server received an incomplete desktop client form. Enable JavaScript and reload the page; no clients were changed.'), 'errors' => []];
		}
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
		$desktopIdentifierErrors = array_merge($this->validateDesktopClientIdentifiers($desktopClientInput),
			$this->validateDesktopCapacityConfig(['desktop_client_limit' => $settings['desktop_client_limit'], 'desktop_clients' => $desktopClientInput]));
		if (!empty($desktopIdentifierErrors)) {
			return [
				'success' => false,
				'message' => _('Desktop client settings were not saved.'),
				'errors' => $desktopIdentifierErrors,
			];
		}
		$settings['desktop_clients'] = $this->normalizeDesktopClients($desktopClientInput, $settings);
		$normalizedClientErrors = $this->validateDesktopClientIdentifiers($settings['desktop_clients']);
		if ($normalizedClientErrors) {
			return ['success' => false, 'message' => _('Desktop identifiers conflict. Reload the client list and save again.'), 'errors' => $normalizedClientErrors];
		}
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
				'message' => _('General settings were not saved. Review the specific error below; your active configuration is unchanged.'),
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
		$encoded = $this->encodeConfigExport($settings);
		$this->auditSensitiveExport('plain', $settings);
		$this->updateStatusData(['last_config_export_at' => time(), 'last_config_export_kind' => 'plain']);
		return $encoded;
	}

	private function encodeConfigExport(array $settings)
	{
		$payload = [
			'product' => 'Southland Servers Mass Notifications Server',
			'format' => 'sls-mass-notify-config-v1',
			'exported_at' => gmdate('c'),
			'settings' => $settings,
		];
		return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	public function exportEncryptedConfig($passphrase, $confirmation)
	{
		if (!is_string($passphrase) || !is_string($confirmation) || !hash_equals($passphrase, $confirmation)) {
			throw new \DomainException(_('The backup passphrases do not match. Enter the same passphrase twice.'));
		}
		$settings = $this->getActiveSettings();
		$encrypted = SlsEncryptedConfig::encrypt($this->encodeConfigExport($settings), $passphrase);
		$this->auditSensitiveExport('encrypted', $settings);
		$this->updateStatusData(['last_config_export_at' => time(), 'last_config_export_kind' => 'encrypted']);
		return $encrypted;
	}

	public function getConfigurationBackupReminder()
	{
		$status = $this->loadStatusData();
		$last = $status['last_config_export_at'] ?? 0;
		$now = time();
		$last = is_int($last) && $last > 0 && $last <= $now ? $last : 0;
		return ['due' => $last === 0 || $now - $last >= 90 * 86400,
			'last_export_at' => $last > 0 ? gmdate('c', $last) : '', 'interval_days' => 90];
	}

	public function importConfigUpload(array $upload, $passphrase = '')
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
		if ((int)($upload['size'] ?? 0) <= 0 || (int)($upload['size'] ?? 0) > SlsEncryptedConfig::MAX_FILE_BYTES) {
			$this->writeMaintenanceProgress('config', 'failed', _('The replacement configuration did not pass the size limit.'));
			return [
				'success' => false,
				'message' => _('The configuration upload must be no larger than 6 MiB, including encryption overhead.'),
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
		try {
			$raw = file_get_contents($tmpName, false, null, 0, SlsEncryptedConfig::MAX_FILE_BYTES + 1);
			if (!is_string($raw)) { throw new \RuntimeException(_('The uploaded configuration could not be read.')); }
			$decoded = SlsEncryptedConfig::decode($raw, $passphrase);
		} catch (\Throwable $error) {
			$this->writeMaintenanceProgress('config', 'failed', _('The uploaded configuration could not be validated or decrypted.'));
			return ['success' => false, 'message' => $error->getMessage(), 'errors' => []];
		}
		$settings = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : $decoded;
		$settings = $this->migrateConfigCompatibility($settings);
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
			$this->disableEnterpriseAfterRecovery($replacement);
			foreach ($replacement['operator_access']['accounts'] ?? [] as $index => $account) { $replacement['operator_access']['accounts'][$index]['enabled'] = false; }
			foreach ($replacement['automations']['rules'] ?? [] as $index => $rule) { $replacement['automations']['rules'][$index]['enabled'] = false; }
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

	/** Apply only lossless compatibility defaults before strict validation. */
	private function migrateConfigCompatibility(array $settings)
	{
		$xweather = $settings['xweather'] ?? null;
		if (is_array($xweather) && (empty($xweather) || !array_is_list($xweather))
			&& !array_key_exists('strike_type', $xweather)) {
			$primary = is_array($xweather['groups'][0] ?? null) ? $xweather['groups'][0] : [];
			// Do not normalize or discard explicit values here: schema validation
			// must still reject unknown fields, invalid types, and invalid enums.
			$xweather['strike_type'] = array_key_exists('strike_type', $primary)
				? $primary['strike_type'] : 'cloud_to_ground';
			$settings['xweather'] = $xweather;
		}
		// Keep an absent groups key distinct from [] so the existing singleton
		// migration retains legacy desktop/email routing and intentional emptiness.
		return $settings;
	}

	private function validateConfigSchema(array $settings)
	{
		$settings = $this->migrateConfigCompatibility($settings);
		$errors = $this->validateConfigValueTypes($settings);
		$known = array_merge(array_keys($this->getDefaultSettings()), ['sound_map', 'test_sound_pool', 'automations', 'operator_access']);
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
		foreach (['control_api', 'updates', 'setup', 'sipnotify', 'ami', 'xweather', 'live_paging'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an object.'), $key);
			}
		}
		foreach (['alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks', 'announcement_webhooks'] as $key) {
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
				if (($zoneGroup['zone'] ?? '') === '' || !$this->nwsZoneHasDeliveryDestination($zoneGroup)) {
					$errors[] = _('Each enabled NWS zone group must include a valid zone and at least one phone, desktop, email, Discord, or generic webhook destination.');
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
			if ($xweather['client_id'] === '' || ($xweather['provider'] !== 'tempest' && $xweather['client_secret'] === '')) {
				$errors[] = _('Enabled Xweather config requires valid credentials.');
			}
			$enabledXweatherGroups = array_values(array_filter($xweather['groups'], static function ($group) {
				return is_array($group) && ($group['enabled'] ?? '0') === '1';
			}));
			if (empty($enabledXweatherGroups)) {
				$errors[] = _('Enabled Xweather config requires at least one enabled Lightning trigger area.');
			}
			foreach ($enabledXweatherGroups as $group) {
				if (($group['location'] ?? '') === '' || !$this->xweatherGroupHasDeliveryDestination($group, $settings)) {
					$errors[] = _('Each enabled Lightning trigger area requires a location and at least one phone, desktop, email, or enabled shared webhook destination.');
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
			is_array($settings['scheduled_announcements'] ?? null) ? $settings['scheduled_announcements'] : [],
			is_array($settings['announcement_groups'] ?? null) ? $settings['announcement_groups'] : []
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
			$this->validateWebhookDestinations($settings['generic_webhooks'] ?? [], 'generic'),
			$this->validateWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement')
		);
		try { $this->announcementEmailTargets($settings); }
		catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		$errors = array_merge($errors, $this->validateNwsZoneDestinationAssignments(
			$settings['nws_zones'] ?? [],
			$settings
		));
		return array_values(array_unique($errors));
	}

	private function validateConfigValueTypes(array $settings)
	{
		$errors = $this->validateDesktopCapacityConfig($settings);
		if (isset($settings['xweather']['provider']) && !in_array($settings['xweather']['provider'], ['xweather', 'tempest', 'meteomatics'], true)) { $errors[] = _('Select Xweather, Tempest or Meteomatics for Lightning.'); }
		if (isset($settings['pbx_timezone']) && $settings['pbx_timezone'] !== '' && (!is_string($settings['pbx_timezone']) || !in_array($settings['pbx_timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true))) { $errors[] = _('PBX timezone must be a valid IANA timezone.'); }
		if (array_key_exists('generated_media_cache_mib', $settings) && (!is_int($settings['generated_media_cache_mib']) || $settings['generated_media_cache_mib'] < 64 || $settings['generated_media_cache_mib'] > 4096)) {
			$errors[] = _('Generated-media cache target must be an integer from 64 through 4096 MiB.');
		}
		try { \SLS\MassNotify\SpeechRules::normalize($settings['announcement_pronunciation'] ?? []); }
		catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		if (!\SLS\MassNotify\DesktopFleet::validMinimum($settings['desktop_minimum_version'] ?? '')) {
			$errors[] = _('Minimum desktop version must be empty or a version such as 1.2.3 or 1.2.3-beta.1.');
		}
		try {
			\SLS\MassNotify\ApiSecurity::credentials($settings['control_api']['credentials'] ?? []);
			\SLS\MassNotify\ApiSecurity::network($settings['api_network'] ?? []);
			\SLS\MassNotify\MediaAccess::normalize(array_key_exists('media_access', $settings) ? $settings['media_access'] : []);
		} catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		if (array_key_exists('operator_access', $settings)) {
			try {
				$access = \SLS\MassNotify\OperatorAccess::normalize($settings['operator_access']);
				if (array_intersect(array_column($access['accounts'], 'id'), array_column($settings['control_api']['credentials'] ?? [], 'id'))) { throw new \DomainException('Operator and Control API identifiers must be distinct.'); }
			} catch (\Throwable $error) { $errors[] = $error->getMessage(); }
		}
		if (array_key_exists('device_acceptance', $settings)) {
			try { \SLS\MassNotify\DeviceAcceptance::normalize($settings['device_acceptance']); }
			catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		}
		if (array_key_exists('automations', $settings)) {
			try { \SLS\MassNotify\AutomationConfig::normalize($settings['automations']); }
			catch (\Throwable $error) { $errors[] = $error->getMessage(); }
		}
		if (array_key_exists('location_directory', $settings)) {
			try { \SLS\MassNotify\LocationDirectory::normalize($settings['location_directory']); }
			catch (\InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
		}
		if (array_key_exists('incident_workflows', $settings)) {
			try { \SLS\MassNotify\IncidentConfig::normalize($settings['incident_workflows']); }
			catch (\InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
		}
		foreach (['labs_safety'=>\SLS\MassNotify\LabsSafety::class,'enterprise_operations'=>\SLS\MassNotify\EnterpriseOperationsConfig::class,
			'enterprise_cluster'=>\SLS\MassNotify\EnterpriseClusterConfig::class,'enterprise_identity'=>\SLS\MassNotify\EnterpriseIdentityConfig::class,
			'directory_sync'=>\SLS\MassNotify\DirectoryConfig::class,'subscriber_browser'=>\SLS\MassNotify\SubscriberConfig::class,
			'enterprise_integrations'=>\SLS\MassNotify\EnterpriseIntegrationsConfig::class] as $key=>$class) {
			if(array_key_exists($key,$settings)){try{$class::normalize($settings[$key]);}catch(\Throwable $error){$errors[]=$error->getMessage();}}
		}
		if (array_key_exists('announcement_email', $settings)) { $errors = array_merge($errors, $this->validateAnnouncementEmail($settings['announcement_email'])); }
		if (array_key_exists('announcement_sms', $settings)) { $errors = array_merge($errors, $this->validateAnnouncementSms($settings['announcement_sms'])); }
		$errors = array_merge($errors, \SLS\MassNotify\UpdatePolicy::errors(is_array($settings['updates'] ?? null) ? $settings['updates'] : []));
		if (array_key_exists('outbound_voice', $settings)) { $errors = array_merge($errors, $this->validateOutboundVoice($settings['outbound_voice'])); }
		if (array_key_exists('live_paging', $settings)) {
			$errors = array_merge($errors, $this->validateLivePagingFields($settings['live_paging']));
		}
		$stringFields = [
			'enabled', 'public_pbx_host', 'pbx_timezone', 'page_group', 'system_notification_emails', 'mail_to', 'discord_webhook_url',
			'nws_api_base_url', 'nws_zone', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end',
			'mail_from_name', 'mail_from_local_part', 'mail_from_domain', 'mail_from_addr',
			'alert_email_subject', 'alert_email_body', 'test_email_subject', 'test_email_body',
			'opening_tone', 'closing_tone', 'nws_opening_tone', 'nws_closing_tone', 'email_html_enabled',
			'piper_bin', 'piper_voice', 'nws_piper_voice', 'announcement_piper_voice',
			'announcement_timeout_mode', 'desktop_auth_key', 'desktop_minimum_version', 'sound_dir', 'asterisk_sound_prefix',
		];
		$integerFields = [
			'tts_max_seconds', 'nws_tts_volume', 'announcement_tts_volume', 'announcement_cooldown_seconds',
			'announcement_timeout_seconds', 'log_retention_days', 'generated_media_cache_mib', 'paging_answer_timeout', 'desktop_client_limit', 'phone_device_limit',
		];
		$listFields = [
			'alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients',
			'scheduled_announcements', 'discord_webhooks', 'generic_webhooks', 'announcement_webhooks', 'test_profiles',
		];
		$objectFields = ['operator_access', 'device_acceptance', 'automations', 'control_api', 'updates', 'setup', 'sipnotify', 'ami', 'xweather', 'live_paging', 'outbound_voice', 'announcement_email', 'announcement_sms', 'labs_safety', 'enterprise_operations', 'enterprise_cluster', 'enterprise_identity', 'directory_sync', 'subscriber_browser', 'enterprise_integrations'];
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
				'string' => ['enabled', 'api_key', 'base_url', 'ip_allowlist_enabled', 'ip_allowlist', 'rate_limit_enabled', 'audit_syslog'],
				'integer' => ['rate_limit_per_minute', 'audit_retention_days'], 'flag' => ['enabled', 'ip_allowlist_enabled', 'rate_limit_enabled', 'audit_syslog'], 'array' => ['credentials'],
			],
			'updates' => [
				'string' => ['github_enabled', 'repository', 'channel', 'pinned_version', 'window_start', 'window_end'], 'integer' => ['rollout_delay_hours'], 'flag' => ['github_enabled'], 'array' => [],
			],
			'setup' => [
				'string' => ['completed', 'beta_accepted', 'agpl_accepted', 'eula_accepted', 'completed_at'], 'integer' => [],
				'flag' => ['completed', 'beta_accepted', 'agpl_accepted', 'eula_accepted'], 'array' => [],
			],
			'sipnotify' => [
				'string' => ['pbx_host', 'base_url', 'media_scheme', 'media_base_url'], 'integer' => [], 'flag' => [], 'array' => ['format_overrides', 'device_format_overrides'],
			],
			'xweather' => [
				'string' => ['enabled', 'provider', 'client_id', 'client_secret', 'location', 'adaptive_free_tier', 'adaptive_gate_failure_policy', 'adaptive_nws_zone_id', 'opening_tone', 'closing_tone', 'all_clear', 'strike_type', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'],
				'integer' => ['radius_miles', 'query_interval_minutes', 'adaptive_grace_minutes', 'adaptive_fallback_minutes', 'tts_volume'],
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
				$requiresList = !($objectField === 'sipnotify' && in_array($field, ['format_overrides', 'device_format_overrides'], true));
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
		$errors = array_merge($errors, $this->validateXweatherAdaptivePolicyInput(is_array($settings['xweather'] ?? null) ? $settings['xweather'] : []));
		if (is_string($settings['xweather']['strike_type'] ?? null)
			&& !in_array($settings['xweather']['strike_type'], ['cloud_to_ground', 'cloud_to_cloud', 'both'], true)) {
			$errors[] = _('xweather.strike_type must be cloud_to_ground, cloud_to_cloud, or both.');
		}
		if (is_array($settings['nws_zones'] ?? null)) {
			foreach ($settings['nws_zones'] as $index => $zone) {
				if (!is_array($zone) || (!empty($zone) && array_is_list($zone))) {
					continue;
				}
				$zoneAllowed = [
					'id', 'name', 'site_id', 'zone', 'extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids',
					'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_critical_events',
					'discord_webhook_ids', 'generic_webhook_ids',
				];
				foreach (array_keys($zone) as $field) {
					if (!in_array($field, $zoneAllowed, true)) {
						$errors[] = sprintf(_('Unknown config key: nws_zones[%d].%s.'), $index, (string)$field);
					}
				}
				foreach (['id', 'name', 'site_id', 'zone', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'] as $field) {
					if (array_key_exists($field, $zone) && !is_string($zone[$field])) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a string.'), $index, $field);
					}
				}
				foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids', 'quiet_critical_events', 'discord_webhook_ids', 'generic_webhook_ids'] as $field) {
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
				if (is_string($zone['quiet_hours_enabled'] ?? null) && !in_array($zone['quiet_hours_enabled'], ['0', '1'], true)) {
					$errors[] = sprintf(_('nws_zones[%d].quiet_hours_enabled must be 0 or 1.'), $index);
				}
			}
		}
		if (is_array($settings['xweather']['groups'] ?? null)) {
			$errors = array_merge($errors, $this->validateXweatherGroupsInput($settings['xweather']['groups']));
		}
		if (is_array($settings['announcement_groups'] ?? null)) {
			$errors = array_merge($errors, $this->validateAnnouncementGroupFields($settings['announcement_groups']));
		}
		foreach (['desktop_clients', 'announcement_groups', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks', 'announcement_webhooks'] as $field) {
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
				if (is_array($schedule) && array_key_exists('operator_credential_id', $schedule) && (!is_string($schedule['operator_credential_id']) || !preg_match('/^api_[a-f0-9]{24}$/D', $schedule['operator_credential_id']))) { $errors[] = _('A schedule has invalid originating operator authorization.'); }
				if (is_array($schedule)) { $errors = array_merge($errors, array_map('_', \SLS\MassNotify\SchedulePresentation::latenessErrors($schedule))); }
				if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('email_recipient_ids', $schedule['targets'])) {
					$errors = array_merge($errors, $this->validateAnnouncementEmailRecipientIds($schedule['targets']['email_recipient_ids']));
				}
				if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('webhook_ids', $schedule['targets'])) {
				$errors = array_merge($errors, $this->validateAudienceWebhookIds($schedule['targets']['webhook_ids']));
			}
			if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('sms_recipient_ids', $schedule['targets'])) {
					$errors = array_merge($errors, $this->validateSmsRecipientIds($schedule['targets']['sms_recipient_ids']));
				}
				if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('voice_recipient_ids', $schedule['targets'])) {
					$errors = array_merge($errors, $this->validateVoiceRecipientIds($schedule['targets']['voice_recipient_ids']));
				}
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
		$lock = $this->acquireSettingsLock(true);
		try {
			$activeSettings = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
			if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON) !== null) {
				$settings = $this->normalizeSettings($this->loadSettingsFile(self::PENDING_SETTINGS_JSON));
			} else {
				$settings = $activeSettings;
			}
			if ($this->isSetupComplete($activeSettings)) {
				$settings['setup'] = $activeSettings['setup'];
			}
			foreach ($this->automationDependencyChecks($settings) as $check) { if ($check['state'] !== 'ok') { throw new \DomainException($check['detail']); } }
			if ($this->getPanicExtensionUsage(true,$settings)) { $this->validatePanicExtensions($settings); $this->ensureLivePagingPrompts($settings); }
			$paging = $this->normalizeLivePagingSettings($settings['live_paging'] ?? []);
			if ($paging['enabled'] === '1') {
				$this->validateLivePagingSelection($paging, $settings);
				$this->assertLivePagingExtensionAvailable($paging['extension']);
				$this->ensureLivePagingPrompts($settings);
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

		try {
			$apiEnvironment = $this->apiWeatherTestEnvironment($settings, array_keys($selectedIds ?: $availableZoneIds),
				array_values($recipients), array_values($desktopRecipients));
		} catch (\DomainException $error) {
			return ['success' => false, 'message' => $error->getMessage(), 'error' => 'api_permission_revoked', 'delivery_started' => false, 'retryable' => false];
		}
		$permissionEnvironment = '';
		foreach ($apiEnvironment as $key => $value) { $permissionEnvironment .= ' ' . $key . '=' . escapeshellarg($value); }
		$ttsMaximum = max(1, min(600, (int)($settings['tts_max_seconds'] ?? 30)));
		$testTimeout = min(960, max(180, ($ttsMaximum * 2) + 120));
		$testDeliveryId = 'test_' . bin2hex(random_bytes(16));
		$cmd = '/usr/bin/timeout --signal=TERM --kill-after=10 ' . $testTimeout
			. ' /usr/bin/env NWS_ZONE_OVERRIDE=' . escapeshellarg(implode(',', $zones))
			. ' SLS_TEST_DELIVERY_ID=' . escapeshellarg($testDeliveryId)
			. ' NWS_RECIPIENTS_OVERRIDE=' . escapeshellarg(implode(',', array_values($recipients)))
			. ' NWS_DESKTOP_CLIENTS_OVERRIDE=' . escapeshellarg(implode(',', array_values($desktopRecipients)))
			. $permissionEnvironment
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
			return $this->withTestDeliveryReport([
				'success' => false,
				'message' => sprintf(
					_('Weather test completed with one or more channel failures after attempting: %s. Successful channel submissions are not replayed.'),
					implode('; ', $requestedChannels)
				),
				'errors' => $detail !== '' ? [$detail] : [_('Review Notification Logs for the delivery stage that failed.')],
			], $testDeliveryId, array_values($recipients), $this->testDesktopPublications($output, array_values($desktopRecipients), $settings), $settings);
		}

		if (!empty($recipients) && !empty($desktopRecipients)) {
			$message = sprintf(
				_('Weather test submitted to %d phone recipient(s) and %d desktop recipient(s). Delivery receipts are shown below.'),
				count($recipients),
				count($desktopRecipients)
			);
		} elseif (!empty($recipients)) {
			$message = sprintf(
				_('Weather test submitted to %d phone recipient(s). Phone answer evidence is shown below.'),
				count($recipients)
			);
		} else {
			$message = sprintf(
				_('Weather test published to %d desktop recipient(s). Application receipts are shown below.'),
				count($desktopRecipients)
			);
		}
		$message .= ' ' . _('Manual tests do not send global or per-zone email, Discord, or generic webhook notifications.');
		return $this->withTestDeliveryReport([
			'success' => true,
			'message' => $message,
			'errors' => [],
		], $testDeliveryId, array_values($recipients), $this->testDesktopPublications($output, array_values($desktopRecipients), $settings), $settings);
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
		$testDeliveryId = 'test_' . bin2hex(random_bytes(16));
		$command = '/usr/bin/timeout 600 /usr/bin/env XWEATHER_TEST_EVENT=entry SLS_TEST_DELIVERY_ID=' . escapeshellarg($testDeliveryId) . ' XWEATHER_GROUP_IDS=' . escapeshellarg(implode(',', $selectedIds))
			. ' XWEATHER_TEST_TRIGGER_NAME=' . escapeshellarg(substr(trim((string)$triggerName), 0, 80))
			. ' /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py 2>&1';
		exec($command, $output, $exitCode);
		$result = $exitCode === 0
			? [
				'success' => true,
				'message' => sprintf(
					_('Lightning test submitted for %d selected trigger area(s). Phone answer evidence and desktop application receipts are shown below. Email, Discord, and generic webhooks were skipped.'),
					count($selectedIds)
				),
				'errors' => [],
			]
			: [
				'success' => false,
				'message' => _('Lightning test failed. No success is reported unless Asterisk completes the audio page job and accepts the SIP NOTIFY submission.'),
				'errors' => ($detail = $this->sanitizeTestCommandOutput($output)) !== '' ? [$detail] : [_('Review Notification Logs for the delivery stage that failed.')],
			];
		$phones = []; $desktops = [];
		foreach ($selectedIds as $id) {
			$phones = array_merge($phones, (array)($enabledGroups[$id]['extensions'] ?? []));
			$desktops = array_merge($desktops, (array)($enabledGroups[$id]['desktop_clients'] ?? []));
		}
		return $this->withTestDeliveryReport($result, $testDeliveryId, $phones, $this->testDesktopPublications($output, $desktops, $settings), $settings);
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
		$this->ensurePluginDataDir();
		try { $activity = $this->acquireAnnouncementActivityLock(false, 30); }
		catch (\Throwable $error) {
			return ['success' => false, 'error_code' => 'delivery_busy', 'delivery_started' => false,
				'message' => _('Protected configuration maintenance is active. No announcement was submitted. Try again when maintenance finishes.')];
		}
		$announcementLock = null;
		try {
			$settings = $this->getActiveSettings();
			if (!\SLS\MassNotify\RuntimeState::running($settings)) {
				return ['success' => false, 'error_code' => 'runtime_stopped', 'delivery_started' => false,
					'message' => _('SLS notifications are stopped. Run sudo slsconsole start to resume.')];
			}
			if (!$this->isSetupComplete($settings)) {
				return ['success' => false, 'message' => $this->getSetupRequiredMessage(),
					'cooldown_remaining' => 0, 'error_code' => 'setup_incomplete', 'delivery_started' => false];
			}
			$announcementLock = (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionLock();
			if (!$announcementLock) {
				return ['success' => false, 'message' => _('Another announcement is being admitted. Try again shortly.'),
					'cooldown_remaining' => 0, 'error_code' => 'delivery_busy', 'delivery_started' => false];
			}
			$this->announcementAdmissionLock = $announcementLock;
			$cooldown = $this->getAnnouncementCooldownState();
			if ($cooldown['remaining'] > 0 && empty($options['preview'])) {
				return ['success' => false, 'message' => sprintf(_('SIP NOTIFY announcements are on cooldown. Wait %s seconds and try again.'), $cooldown['remaining']),
					'cooldown_remaining' => $cooldown['remaining'], 'error_code' => 'cooldown', 'delivery_started' => false];
			}
			$resolved = $this->resolveAnnouncementRequest($extensions, $message, $massNotify, $ttsAudio, $groups, $options, $settings);
			if (empty($resolved['success'])) { return $resolved; }
			$review = $this->enterpriseReviewAnnouncement($resolved['request']);
			if ($review !== null) { return $review; }
			return $this->deliverResolvedAnnouncement($resolved['request']);
		} finally {
			$this->announcementAdmissionLock = null;
			if (is_resource($announcementLock)) { flock($announcementLock, LOCK_UN); fclose($announcementLock); }
			$this->releaseNativeBackupFileLock($activity);
		}
	}

	/** Resolve one protected settings snapshot without queuing or submitting it. */
	private function resolveAnnouncementRequest($extensions, $message, $massNotify, $ttsAudio, $groups, array $options, array $activeSettings): array
	{
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
			return ['success' => false, 'message' => _('Announcement messages must contain no more than 500 characters. Shorten the message before sending.'),
				'cooldown_remaining' => 0, 'error_code' => 'message_too_long', 'delivery_started' => false];
		}

		$allowedTargets = $this->getSipNotifyTargets();
		$unavailableTargets = [];
		$allowed = [];
		foreach ($allowedTargets as $target) {
			$allowed[$target['extension']] = true;
		}
				$desktopClients = [];
				$desktopUsernames = [];
				$desktopClientIds = [];
				foreach ($this->getDesktopClients($activeSettings) as $client) {
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
			$announcementWebhookLookup = [];
			foreach ($this->normalizeWebhookDestinations($activeSettings['announcement_webhooks'] ?? [], 'announcement') as $destination) {
				if (!empty($destination['enabled'])) {
					$announcementWebhookLookup[(string)$destination['id']] = (string)$destination['name'];
				}
			}
			$selectedAnnouncementWebhooks = [];
			$invalidAnnouncementWebhook = false;
			foreach ((array)($options['webhook_ids'] ?? []) as $selector) {
				$rawSelector = trim((string)$selector);
				$webhookId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $rawSelector), 0, 64);
				if ($webhookId === '' || !hash_equals($rawSelector, $webhookId) || !isset($announcementWebhookLookup[$webhookId])) {
					$invalidAnnouncementWebhook = true;
					continue;
				}
				$selectedAnnouncementWebhooks[$webhookId] = $announcementWebhookLookup[$webhookId];
			}
			if ($invalidAnnouncementWebhook || count($selectedAnnouncementWebhooks) > self::MAX_WEBHOOK_DESTINATIONS) {
				return [
					'success' => false,
					'message' => _('One or more selected Dashboard webhook destinations are invalid or no longer enabled.'),
					'cooldown_remaining' => 0,
					'error_code' => 'invalid_webhook_targets',
					'delivery_started' => false,
				];
			}

			foreach (['emails', 'email_recipients'] as $rawEmailField) {
				if (array_key_exists($rawEmailField, $options)) { return ['success' => false, 'message' => _('Choose saved email recipient IDs; raw email addresses are not accepted.'), 'delivery_started' => false]; }
			}
			$emailIds = array_key_exists('email_recipient_ids', $options) ? $options['email_recipient_ids'] : [];
			$emailErrors = $this->validateAnnouncementEmailRecipientIds($emailIds);
			if ($emailErrors) { return ['success' => false, 'message' => implode(' ', $emailErrors), 'delivery_started' => false]; }
			$selectedEmailIds = array_fill_keys($emailIds, true);
			$smsIds = array_key_exists('sms_recipient_ids', $options) ? $options['sms_recipient_ids'] : [];
			$smsErrors = $this->validateSmsRecipientIds($smsIds);
			if ($smsErrors) { return ['success' => false, 'message' => implode(' ', $smsErrors), 'delivery_started' => false]; }
			$selectedSmsIds = array_fill_keys($smsIds, true);
			foreach (['sms', 'sms_recipients', 'sms_numbers'] as $rawSmsField) {
				if (array_key_exists($rawSmsField, $options)) { return ['success' => false, 'message' => _('Choose saved SMS recipient IDs; raw numbers are not accepted.'), 'delivery_started' => false]; }
			}
			$voiceIds = $options['voice_recipient_ids'] ?? [];
			$voiceErrors = $this->validateVoiceRecipientIds($voiceIds);
			if ($voiceErrors) { return ['success' => false, 'message' => implode(' ', $voiceErrors), 'delivery_started' => false]; }
			$selectedVoiceIds = array_fill_keys($voiceIds, true);
			$selected = [];
			$groupLookup = [];
			foreach (($activeSettings['announcement_groups'] ?? []) as $group) {
				$groupLookup[$group['id']] = $group;
			}
		foreach ((array)$groups as $groupId) {
			$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId);
			if ($groupId === '' || empty($groupLookup[$groupId])) {
				continue;
			}
			if (!\SLS\MassNotify\ApiSecurity::groupDesktopBindingsValid($groupLookup[$groupId], $activeSettings)) {
				return ['success' => false, 'error_code' => 'location_desktop_identity_changed', 'delivery_started' => false,
					'message' => _('A desktop in this location audience was removed, renamed, disabled, or replaced. Create a new reviewed audience from Locations before sending.')];
			}
			$webhookErrors = $this->validateAudienceWebhookIds($groupLookup[$groupId]['webhook_ids'] ?? [], $activeSettings);
			if ($webhookErrors) { return ['success' => false, 'message' => implode(' ', $webhookErrors), 'delivery_started' => false]; }
			foreach ($groupLookup[$groupId]['webhook_ids'] ?? [] as $webhookId) { $selectedAnnouncementWebhooks[$webhookId] = $announcementWebhookLookup[$webhookId]; }
			foreach ((array)($groupLookup[$groupId]['voice_recipient_ids'] ?? []) as $voiceId) { $selectedVoiceIds[$voiceId] = true; }
			$groupEmailIds = array_key_exists('email_recipient_ids', $groupLookup[$groupId]) ? $groupLookup[$groupId]['email_recipient_ids'] : [];
			$emailErrors = $this->validateAnnouncementEmailRecipientIds($groupEmailIds);
			if ($emailErrors) { return ['success' => false, 'message' => implode(' ', $emailErrors), 'delivery_started' => false]; }
			foreach ($groupEmailIds as $emailId) { $selectedEmailIds[$emailId] = true; }
			$groupSmsIds = array_key_exists('sms_recipient_ids', $groupLookup[$groupId]) ? $groupLookup[$groupId]['sms_recipient_ids'] : [];
			$smsErrors = $this->validateSmsRecipientIds($groupSmsIds);
			if ($smsErrors) { return ['success' => false, 'message' => implode(' ', $smsErrors), 'delivery_started' => false]; }
			foreach ($groupSmsIds as $smsId) { $selectedSmsIds[$smsId] = true; }
			foreach ((array)$groupLookup[$groupId]['extensions'] as $extension) {
				if (!isset($allowed[$extension])) { $unavailableTargets[(string)$extension] = (string)$extension; }
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
				if ($extension !== '' && !isset($allowed[$extension])) { $unavailableTargets[$extension] = $extension; }
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
			$webhookRequested = !empty($selectedAnnouncementWebhooks);
			if ($desktopRequested) {
				$massNotify = true;
			}
			$audioMode = $this->normalizeAnnouncementAudioMode($options['audio_mode'] ?? ((bool)$ttsAudio ? 'tones_tts' : 'none'));
			$ttsAudio = in_array($audioMode, ['tts', 'tones_tts'], true);
			$audioEnabled = $audioMode !== 'none';
			try { $emailTargets = $selectedEmailIds ? $this->announcementEmailTargets($activeSettings) : []; }
			catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'delivery_started' => false]; }
			if (count($selectedEmailIds) > 50 || array_diff_key($selectedEmailIds, $emailTargets)) {
				return ['success' => false, 'message' => _('Select at most 50 enabled saved email recipients. Review the selection, saved groups, and Announcement Email settings.'), 'delivery_started' => false];
			}
			$voiceTargets = $this->outboundVoiceTargets($activeSettings);
			try { $smsTargets = $selectedSmsIds ? \SLS\MassNotify\Sms\Service::targets($activeSettings) : []; }
			catch (\DomainException $error) { return ['success' => false, 'message' => $error->getMessage(), 'delivery_started' => false]; }
			if (count($selectedSmsIds) > 50 || array_diff_key($selectedSmsIds, $smsTargets)) {
				return ['success' => false, 'message' => _('Select at most 50 enabled saved SMS recipients with recorded consent. Review the selection and SMS settings.'), 'delivery_started' => false];
			}
			if (array_diff_key($selectedVoiceIds, $voiceTargets)) {
				return ['success' => false, 'message' => _('A selected external voice recipient is no longer enabled. Review the selection or saved group.'), 'delivery_started' => false];
			}
			if ($selectedVoiceIds && !$audioEnabled) {
				return ['success' => false, 'message' => _('External voice recipients require TTS or tone audio. Enable announcement audio before sending.'), 'delivery_started' => false];
			}
			$toneErrors = [];
			$openingTone = $this->announcementTone($options['opening_tone'] ?? $activeSettings['opening_tone'] ?? '', 'opening', $toneErrors);
			$closingTone = $this->announcementTone($options['closing_tone'] ?? $activeSettings['closing_tone'] ?? '', 'closing', $toneErrors);
			if ($toneErrors) { return ['success'=>false, 'message'=>implode(' ', $toneErrors), 'error_code'=>'invalid_tone', 'delivery_started'=>false]; }
			$availableToneLookup = array_fill_keys($this->getAvailableTones(), true);
			foreach ([$openingTone, $closingTone] as $selectedTone) {
				if ($selectedTone !== '' && !isset($availableToneLookup[$selectedTone])) {
					return ['success' => false, 'message' => _('A selected announcement tone is unavailable.'), 'cooldown_remaining' => 0, 'error_code' => 'invalid_tone', 'delivery_started' => false];
				}
			}

			if (empty($selected) && !$desktopRequested && !$webhookRequested && !$selectedVoiceIds && !$selectedEmailIds && !$selectedSmsIds) {
				if ($unavailableTargets) {
					return ['success' => false,
						'message' => sprintf(_('None of the %d selected phone(s) is registered or available. No channels were submitted. Check phone registration before sending again.'), count($unavailableTargets)),
						'cooldown_remaining' => 0, 'error_code' => 'selected_phones_unavailable', 'delivery_started' => false,
						'unavailable_phones' => array_values($unavailableTargets)];
				}
				return [
					'success' => false,
					'message' => _('Select at least one phone, desktop app, saved email or SMS recipient, external voice recipient, or Dashboard webhook destination.'),
					'cooldown_remaining' => 0,
					'error_code' => 'no_targets',
					'delivery_started' => false,
				];
			}

			if ($audioEnabled && empty($selected) && !$selectedVoiceIds) {
				return [
					'success' => false,
					'message' => _('Select at least one extension before enabling announcement audio.'),
					'cooldown_remaining' => 0,
					'error_code' => 'no_audio_targets',
					'delivery_started' => false,
				];
			}

		if ((!empty($selected) || $desktopRequested) && !is_executable(self::VISUAL_PUSH_SCRIPT)) {
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
			$title = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($options['title'] ?? 'Announcement')));
			if ($title === '') {
				$title = 'Announcement';
			}
			$title = function_exists('mb_substr') ? mb_substr($title, 0, 80) : substr($title, 0, 80);
			$backgroundColor = $this->normalizeHexColor((string)($options['background_color'] ?? '#1f2937'), '#1f2937');
			$triggerSource = trim((string)($options['trigger_source'] ?? 'FreePBX Dashboard'));
			if ($triggerSource === '') {
				$triggerSource = 'FreePBX Dashboard';
			}

			$settings = $activeSettings;
			$timeoutMode = $this->normalizeAnnouncementTimeoutMode($settings['announcement_timeout_mode'] ?? 'none');
			$displayTimeout = $timeoutMode === 'custom'
				? $this->normalizeAnnouncementTimeoutSeconds($settings['announcement_timeout_seconds'] ?? 300)
				: 0;
			$priority = $options['priority'] ?? 'normal';
			if (!is_string($priority) || !in_array($priority, ['normal', 'urgent'], true)) {
				return ['success' => false, 'message' => _('Announcement priority must be normal or urgent.')];
			}
			// Only the internal incident facade can establish this context. Generic
			// announcement option names and request-body metadata cannot create it.
			$incidentContext = $this->currentIncidentAnnouncementContext();
			if ($incidentContext !== null) { $incidentContext = self::validateIncidentContext($incidentContext); }
			return ['success' => true, 'request' => $this->snapshotAnnouncementRequest([
                'automation_context' => $this->automationSubmissionContext ?? null,
                'incident_context' => $incidentContext, 'force_queue' => $incidentContext !== null || !empty($selectedEmailIds) || !empty($selectedSmsIds),
                'email_severity' => ($incidentContext['severity'] ?? 'information') === 'information' ? 'info' : $incidentContext['severity'],
                'email_recipient_ids' => array_keys($selectedEmailIds),
                'sms_recipient_ids' => array_keys($selectedSmsIds),
                'priority' => $priority,
                'message' => $message, 'sender' => $this->announcementSender($options),
                'only_channels' => $options['_test_channels'] ?? null,
                'is_test' => ($options['_is_test'] ?? false) === true,
                'phones' => array_values($selected),
                'desktops' => $desktopAll ? array_keys($desktopClients) : array_values($selectedDesktopClients),
                'webhooks' => array_keys($selectedAnnouncementWebhooks),
                'voice_recipient_ids' => array_keys($selectedVoiceIds),
                'audio_mode' => $audioMode, 'opening_tone' => $openingTone, 'closing_tone' => $closingTone,
                'voice' => (string)($options['piper_voice'] ?? ''),
                'volume' => $options['tts_volume'] ?? ($settings['announcement_tts_volume'] ?? 25),
                'timeout_mode' => $timeoutMode, 'display_timeout' => $displayTimeout,
                'image' => $image, 'title' => $title, 'background_color' => $backgroundColor,
                'trigger_source' => $triggerSource, 'preview' => !empty($options['preview']),
                'unavailable_phones' => array_values($unavailableTargets),
            ], $settings)];
	}

	private function dispatchAnnouncementWebhooks(array $destinationIds, $message, $title, $backgroundColor, array $fields = [], $deliveryId = '', array $expectedFingerprints = [], $deliveryTimestamp = '', ?int $submissionDeadlineAt = null, $incidentContext = null, array $authorization = [])
	{
		$configured = [];
		foreach ($this->normalizeWebhookDestinations($this->getActiveSettings()['announcement_webhooks'] ?? [], 'announcement') as $destination) {
			if (!empty($destination['enabled'])) {
				$configured[(string)$destination['id']] = $destination;
			}
		}
		$requested = [];
		$outcome = ['requested' => [], 'accepted' => [], 'failed' => []];
		foreach (array_slice(array_values(array_unique(array_map('strval', $destinationIds))), 0, self::MAX_WEBHOOK_DESTINATIONS) as $destinationId) {
			$outcome['requested'][] = $destinationId;
			$name = (string)($configured[$destinationId]['name'] ?? $destinationId);
			$expected = $expectedFingerprints[$destinationId] ?? '';
			$error = '';
			if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
				$error = 'legacy_route_snapshot_missing';
			} elseif (!isset($configured[$destinationId])) {
				$error = 'destination_unavailable';
			} elseif (!hash_equals($expected, $this->webhookDestinationFingerprint($configured[$destinationId]))) {
				$error = 'destination_changed';
			}
			if ($error !== '') {
				$outcome['failed'][] = ['id' => $destinationId, 'name' => $name, 'error' => $error,
					'status' => $error === 'legacy_route_snapshot_missing' ? 'failed' : 'cancelled', 'retryable' => false];
			} else {
				$requested[$destinationId] = $name;
			}
		}
		if (empty($requested)) {
			return $outcome;
		}
		if ($submissionDeadlineAt !== null && time() >= $submissionDeadlineAt) {
			foreach ($requested as $id => $name) {
				$outcome['failed'][] = ['id' => $id, 'name' => $name, 'error' => 'schedule_deadline_expired', 'retryable' => false];
			}
			return $outcome;
		}
		if (!is_string($deliveryId) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deliveryId)
			|| !is_string($deliveryTimestamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $deliveryTimestamp)
			|| strtotime($deliveryTimestamp) === false) {
			foreach ($requested as $id => $name) {
				$outcome['failed'][] = ['id' => $id, 'name' => $name, 'error' => 'legacy_payload_snapshot_missing', 'retryable' => false];
			}
			return $outcome;
		}
		if (!function_exists('proc_open') || !is_executable('/usr/bin/python3') || !is_executable('/usr/bin/timeout') || !is_executable(self::RUNTIME_DIR . '/sls_notification_destinations.py')) {
			foreach ($requested as $id => $name) {
				$outcome['failed'][] = ['id' => $id, 'name' => $name, 'error' => 'dispatcher_unavailable'];
			}
			return $outcome;
		}
		$environment = getenv();
		if (!is_array($environment)) {
			$environment = [];
		}
		$environment['SLS_NOTIFICATION_LIVE'] = '1';
		$environment['SLS_NOTIFICATION_TEST'] = '0';
		$environment['SLS_NOTIFICATION_DRY_RUN'] = '0';
		$environment['SLS_DESTINATION_SOURCE'] = 'dashboard';
		$environment['SLS_DESTINATION_IDS'] = implode(',', array_keys($requested));
		$environment['SLS_DESTINATION_SUBJECT'] = (string)$title;
		$environment['SLS_DESTINATION_BODY'] = (string)$message;
		$environment['SLS_DESTINATION_COLOR'] = (string)$backgroundColor;
		$environment['SLS_DESTINATION_TIME'] = $deliveryTimestamp;
		$environment['SLS_DESTINATION_EVENT_ID'] = $deliveryId;
		$validatedIncident = $incidentContext === null ? null : \SLS\MassNotify\IncidentConfig::context($incidentContext);
		$environment['SLS_DESTINATION_INCIDENT_ID'] = $validatedIncident['incident_id'] ?? '';
		$environment['SLS_DESTINATION_INCIDENT_JSON'] = $validatedIncident === null ? '' : json_encode($validatedIncident, JSON_THROW_ON_ERROR);
		$environment['SLS_DESTINATION_AUTHORIZATION_JSON'] = $authorization ? json_encode($authorization + ['webhook_ids'=>array_keys($requested)], JSON_THROW_ON_ERROR) : '';
		$environment['SLS_DESTINATION_FINGERPRINTS_JSON'] = json_encode((object)array_intersect_key($expectedFingerprints, $requested), JSON_UNESCAPED_SLASHES);
		$environment['SLS_DESTINATION_BUDGET_SECONDS'] = '6';
		$environment['SLS_DESTINATION_LATEST_START'] = $submissionDeadlineAt === null ? '' : (string)$submissionDeadlineAt;
		$environment['SLS_DESTINATION_FIELDS_JSON'] = json_encode(array_slice($fields, 0, 6), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$command = [
			'/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '10',
			'/usr/bin/python3', self::RUNTIME_DIR . '/sls_notification_destinations.py',
			self::SETTINGS_JSON, '--announcement',
		];
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pipes = [];
		$process = @proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
		if (!is_resource($process)) {
			foreach ($requested as $id => $name) {
				$outcome['failed'][] = ['id' => $id, 'name' => $name, 'error' => 'dispatcher_unavailable'];
			}
			return $outcome;
		}
		$stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1], 65536) : '';
		$stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2], 8192) : '';
		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		$exitCode = proc_close($process);
		$decoded = json_decode((string)$stdout, true);
		$results = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
		$seen = [];
		foreach ($results as $result) {
			if (!is_array($result)) {
				continue;
			}
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($result['id'] ?? '')), 0, 64);
			if ($id === '' || !isset($requested[$id]) || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			if (($result['status'] ?? '') === 'accepted') {
				$outcome['accepted'][] = ['id' => $id, 'name' => $requested[$id], 'http_status' => (int)($result['http_status'] ?? 0)];
			} else {
				$error = substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($result['error'] ?? 'delivery_failed'))), 0, 64) ?: 'delivery_failed';
				if (($result['status'] ?? '') === 'uncertain') { $error = 'delivery_unconfirmed'; }
				$outcome['failed'][] = ['id' => $id, 'name' => $requested[$id], 'error' => $error,
					'status' => in_array($result['status'] ?? '', ['cancelled', 'uncertain'], true) ? $result['status'] : 'failed',
					'http_status' => max(0, min(599, (int)($result['http_status'] ?? 0))),
					'failure_reason' => substr(preg_replace('/[^a-z0-9_]/', '', (string)($result['failure_reason'] ?? $error)), 0, 96),
					'retryable' => !in_array($error, ['legacy_route_snapshot_missing', 'destination_changed', 'destination_unavailable', 'schedule_deadline_expired'], true)];
			}
		}
		foreach ($requested as $id => $name) {
			if (!isset($seen[$id])) {
				$outcome['failed'][] = [
					'id' => $id,
					'name' => $name,
					'error' => $exitCode === 124 ? 'delivery_timeout' : ($stderr !== '' ? 'dispatcher_failed' : 'delivery_unconfirmed'),
				];
			}
		}
		return $outcome;
	}

	private function buildAnnouncementVisualPushCommand($message, array $targets, $displayTimeout, array $options = [])
	{
		$mode = ($options['mode'] ?? 'phone_only') === 'api_only' ? 'api_only' : 'phone_only';
		$command = '/usr/bin/timeout ' . (!empty($options['managed_deadline']) ? '--foreground ' : '') . '--signal=TERM --kill-after=1 90 /usr/bin/python3 -I '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT)
			. ' --announcement=' . escapeshellarg((string)$message)
			. ' --announcement-timeout-seconds ' . max(0, (int)$displayTimeout);
		if (($options['is_test'] ?? false) === true) { $command .= ' --is-test'; }
		if ($mode === 'api_only' && isset($options['schedule_deadline_at'])) {
			if (!is_int($options['schedule_deadline_at']) || $options['schedule_deadline_at'] < 1) {
				throw new \DomainException('Invalid scheduled desktop publication deadline.');
			}
			$command .= ' --latest-start ' . $options['schedule_deadline_at'];
		}
		if ($mode === 'api_only' && isset($options['incident_context'])) {
			$context = self::validateIncidentContext($options['incident_context']);
			if ($context['is_test'] !== (($options['is_test'] ?? false) === true)) {
				throw new \DomainException(_('Incident test metadata does not match the announcement.'));
			}
			$command .= ' --incident-json=' . escapeshellarg(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		}
		if (!empty($options['sender'])) {
			$command = '/usr/bin/env ' . escapeshellarg('SLS_ANNOUNCEMENT_SENDER=' . $this->sanitizeScheduleText($options['sender'], 80, true)) . ' ' . $command;
		}
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
		$outboundTargets = is_array($context['outbound_targets'] ?? null) ? $context['outbound_targets'] : [];
		$this->lastPhoneAdmission = [];
		$deadline = $context['schedule_deadline_at'] ?? null;
		if ($deadline !== null && (!is_int($deadline) || $deadline <= time())) {
			return ['success' => false, 'message' => _('The scheduled start deadline elapsed before audio preparation. No audio was submitted.'),
				'error' => 'schedule_deadline_expired', 'retryable' => false];
		}
		$settings = $this->getActiveSettings();
		$audioMode = $this->normalizeAnnouncementAudioMode($context['audio_mode'] ?? 'tones_tts');
		$includeTts = in_array($audioMode, ['tts', 'tones_tts'], true);
		$includeTones = in_array($audioMode, ['tones', 'tones_tts'], true);
		$settings['announcement_pronunciation'] = $context['pronunciation_rules'] ?? [];
		$requestedVoice = (string)($context['piper_voice'] ?? '');
		$voiceLookup = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		if ($includeTts && $requestedVoice === '') {
			return ['success' => false, 'message' => _('The original announcement voice was not recorded. No audio was submitted. Review the announcement and submit a new request.'),
				'error' => 'legacy_voice_snapshot_missing', 'retryable' => false];
		}
		if ($includeTts && (!isset($voiceLookup[$requestedVoice]) || !$this->isValidPiperVoiceFile($requestedVoice))) {
			return ['success' => false, 'message' => _('The selected announcement voice is no longer available. No audio was submitted. Restore that voice or submit a new announcement with an available voice.'),
				'error' => 'selected_voice_unavailable'];
		}
		if ($includeTts) {
			$settings['announcement_piper_voice'] = $requestedVoice;
		}
		if (array_key_exists('tts_volume', $context)) {
			$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($context['tts_volume'], $settings['announcement_tts_volume'] ?? 25);
		}
		$settings['opening_tone'] = $includeTones ? $this->normalizeToneName((string)($context['opening_tone'] ?? $settings['opening_tone'] ?? '')) : '';
		$settings['closing_tone'] = $includeTones ? $this->normalizeToneName((string)($context['closing_tone'] ?? $settings['closing_tone'] ?? '')) : '';
		// Announcement requests run as the Asterisk web account. They must not
		// recursively chown runtime trees or repair/install Piper. Those privileged
		// mutations belong to install/repair; this path only verifies usability.
		if (!is_dir(self::TTS_DIR) || is_link(self::TTS_DIR) || !is_writable(self::TTS_DIR)) {
			return ['success' => false, 'message' => _('The announcement audio workspace is unavailable. Run Repair Installation from General Settings.')];
		}
		$this->pruneTtsCache();

		if (empty($extensions) && empty($outboundTargets)) {
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
			try {
				$ttsBase = $this->generateAnnouncementTtsFile($message, $settings);
			} catch (\DomainException $error) {
				return ['success' => false, 'message' => $error->getMessage(), 'error' => 'audio_preparation_rejected'];
			}
			if ($ttsBase === '') {
				return ['success' => false, 'message' => _('Piper TTS audio could not be generated.')];
			}
		}

		$sequence = $this->buildAnnouncementAudioSequence($ttsBase, $settings);
		if ($sequence === '') {
			return ['success' => false, 'message' => _('Announcement audio sequence could not be built.')];
		}

		$audioDuration = $this->getAnnouncementSequenceDuration($sequence);
		$guard = $this->guardPreparedApiAudio($context, $extensions, $outboundTargets);
		if (!$guard['success']) { return $guard; }
		$extensions = $guard['extensions'];
		$outboundTargets = $guard['outbound_targets'];
		$queued = $this->queueAnnouncementAudioCalls($extensions, $sequence, $audioDuration, $context['priority'] ?? 'normal', $outboundTargets, $context['correlation'] ?? '', $context);
		$permissionDenied = $guard['permission_denied_targets'];
		foreach (['phones', 'voice_recipient_ids'] as $channel) {
			$permissionDenied[$channel] = array_values(array_unique(array_merge($permissionDenied[$channel], $this->lastPhoneAdmission['permission_denied_targets'][$channel] ?? [])));
		}
		if ($queued < 1) {
			return ['success' => false, 'message' => $this->lastPhoneAdmission['detail'] ?? _('Unable to queue announcement audio calls.'),
				'error' => $this->lastPhoneAdmission['failure_code'] ?? 'phone_queue_failed', 'retryable' => false,
				'permission_denied_targets' => $permissionDenied,
				'submission_uncertain' => !empty($this->lastPhoneAdmission['submission_uncertain'])];
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
			'success' => $queued === count($extensions) + count($outboundTargets) && empty($this->lastPhoneAdmission['submission_uncertain']),
			'message' => sprintf(_('Queued announcement audio to %s of %s phone destination(s). Answer and playback remain unverified.'), $queued, count($extensions) + count($outboundTargets))
				. (($this->lastPhoneAdmission['failure_code'] ?? '') === 'schedule_deadline_expired' ? ' ' . $this->lastPhoneAdmission['detail'] : ''),
			'error' => $this->lastPhoneAdmission['failure_code'] ?? '',
			'audio_sequence' => $sequence,
			'notify_delay_seconds' => 1,
			'audio_duration_seconds' => $audioDuration,
			'delivery_started' => true,
			'retryable' => false,
			'submission_uncertain' => !empty($this->lastPhoneAdmission['submission_uncertain']),
			'admission_id' => $this->lastPhoneAdmission['token'] ?? '',
			'permission_denied_targets' => $permissionDenied,
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
		exec('LC_ALL=C /usr/bin/timeout --kill-after=1 5 /usr/bin/soxi -D ' . escapeshellarg($file) . ' 2>/dev/null', $output, $exitCode);
		$duration = $exitCode === 0 ? (float)($output[0] ?? 0) : 0.0;
		return $duration > 0 && is_finite($duration) ? $duration : 0.0;
	}

	private function generateAnnouncementTtsFile($message, array $settings)
	{
		$voice = (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE);
		if (!$this->isValidPiperVoiceFile($voice) || !$this->isValidPiperVoiceFile($voice . '.json')) {
			throw new \DomainException(_('The selected speech model or its configuration failed checksum verification. Run Repair Installation before generating speech.'));
		}
		$maxSeconds = $this->normalizeTtsMaxSeconds($settings['tts_max_seconds'] ?? 30);
        $ttsText = $this->buildAnnouncementTtsText($message, $maxSeconds, $settings['announcement_pronunciation'] ?? [], (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE));
		if (!empty($settings['_tts_prompt_only'])) {
			$ttsText = trim(preg_replace('/\s+/', ' ', (string)$message));
		}
		if ($ttsText === '') {
			throw new \DomainException(_('Speech cannot be generated from an empty announcement. Enter the message before sending.'));
		}
		if (!is_executable('/usr/bin/sox') || !is_executable('/usr/bin/soxi')) {
			throw new \DomainException(_('Speech conversion and duration checks require SoX and soxi. Run Repair Installation from General Settings.'));
		}
		$baseName = 'announcement_tts_' . date('YmdHis') . '_' . bin2hex(random_bytes(16));
		$tmpDir = '/tmp/sls_announcement_' . bin2hex(random_bytes(16));
		if (!@mkdir($tmpDir, 0700)) {
			throw new \DomainException(_('Cannot create the private speech workspace in /tmp. Check free disk space and directory permissions.'));
		}
		$textFile = $tmpDir . '/message.txt';
		$tmpWav = $tmpDir . '/speech.wav';
		$outputFile = self::TTS_DIR . '/' . $baseName . '.wav';
		$complete = false;
		try {
			if (@file_put_contents($textFile, $ttsText . "\n", LOCK_EX) !== strlen($ttsText) + 1) {
				throw new \DomainException(_('Cannot write the speech input file. Check free disk space in /tmp.'));
			}
			$generationTimeout = min(900, max(25, ($maxSeconds * 2) + 30));
			if (!empty($settings['_tts_preview'])) { $generationTimeout = min(30, $generationTimeout); }
			$cmd = '/usr/bin/timeout ' . (int)$generationTimeout . ' '
				. escapeshellarg($settings['piper_bin'] ?? self::PIPER_BIN)
				. ' --model ' . escapeshellarg($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE)
				. ' --volume 1.00 --input-file ' . escapeshellarg($textFile)
				. ' --output-file ' . escapeshellarg($tmpWav) . ' 2>&1';
			$output = [];
			exec($cmd, $output, $exitCode);
			if ($exitCode === 124) {
				throw new \DomainException(sprintf(_('Speech generation exceeded its %d-second processing timeout. Check PBX load or shorten the message.'), $generationTimeout));
			}
			if ($exitCode !== 0 || !is_file($tmpWav)) {
				throw new \DomainException(sprintf(_('Piper did not produce speech audio (exit code %d). Check the selected voice and run Repair Installation.'), $exitCode));
			}
			$durationOutput = [];
			exec('LC_ALL=C /usr/bin/timeout --kill-after=1 5 /usr/bin/soxi -D ' . escapeshellarg($tmpWav) . ' 2>/dev/null', $durationOutput, $durationExit);
			$duration = $durationExit === 0 ? (float)($durationOutput[0] ?? 0) : 0.0;
			if (!is_finite($duration) || $duration <= 0) {
				throw new \DomainException(_('Piper produced audio with an unreadable or empty duration. Check the selected voice and run Repair Installation.'));
			}
			if ($duration > $maxSeconds) {
				throw new \DomainException(sprintf(_('Generated speech is %.2f seconds; the configured maximum is %d seconds. Audio was rejected. Shorten the message or increase Maximum Speech Duration in General Settings.'), $duration, $maxSeconds));
			}
			$output = [];
			exec('/usr/bin/timeout --kill-after=2 30 /usr/bin/sox -v ' . escapeshellarg($this->volumePercentToScalar($settings['announcement_tts_volume'] ?? 25, 25))
				. ' ' . escapeshellarg($tmpWav) . ' -r 8000 -c 1 -b 16 ' . escapeshellarg($outputFile) . ' 2>&1', $output, $exitCode);
			if ($exitCode !== 0 || !is_file($outputFile) || filesize($outputFile) < 44) {
				throw new \DomainException(sprintf(_('Speech conversion to telephone audio failed (exit code %d). Check free disk space and SoX availability.'), $exitCode));
			}
			@chmod($outputFile, 0644);
			@chown($outputFile, 'asterisk');
			@chgrp($outputFile, 'asterisk');
			$complete = true;
			return $baseName;
		} finally {
			@unlink($textFile);
			@unlink($tmpWav);
			@rmdir($tmpDir);
			if (!$complete) { @unlink($outputFile); }
		}
	}

    private function buildAnnouncementTtsText($message, $maxSeconds, array $pronunciation = [], string $voice = '')
    {
        $message = trim(preg_replace('/\s+/', ' ', (string)$message));
        $prefixes = ['es' => 'Aviso. ', 'fr' => 'Annonce. ', 'de' => 'Durchsage. ', 'pt' => 'Comunicado. '];
        $language = substr(basename($voice), 0, 2);
        return $message === '' ? '' : ($prefixes[$language] ?? 'Announcement. ') . \SLS\MassNotify\SpeechRules::apply($message, $pronunciation);
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

	private function announcementAudioFileMetadata($path, $allowMissing = false)
	{
		clearstatcache(true, $path);
		$metadata = @lstat($path);
		if ($metadata === false && $allowMissing) { return null; }
		if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0100000
			|| $metadata['nlink'] !== 1 || ($metadata['mode'] & 0022) || realpath($path) !== $path
			|| $metadata['size'] > 64 * 1024 * 1024) {
			throw new \DomainException(sprintf(_('Announcement audio file %s is unsafe or exceeds 64 MiB. Check its permissions and replace linked or damaged files.'), basename($path)));
		}
		return $metadata;
	}

	private function isValidAnnouncementAudioCache($path)
	{
		$metadata = $this->announcementAudioFileMetadata($path, true);
		if ($metadata === null || $metadata['size'] < 44) { return false; }
		$raw = $this->readNativeBackupFile($path, 64 * 1024 * 1024, _('The announcement audio cache could not be read safely. Retry after checking its storage.'));
		$length = strlen($raw);
		if (substr($raw, 0, 4) !== 'RIFF' || substr($raw, 8, 4) !== 'WAVE'
			|| unpack('V', substr($raw, 4, 4))[1] + 8 !== $length) { return false; }
		$offset = 12; $format = false; $data = false; $chunks = 0;
		while ($offset + 8 <= $length && ++$chunks <= 64) {
			$kind = substr($raw, $offset, 4); $size = unpack('V', substr($raw, $offset + 4, 4))[1];
			$start = $offset + 8; $end = $start + $size;
			if ($end > $length) { return false; }
			if ($kind === 'fmt ') {
				if ($format || $size < 16) { return false; }
				$pcm = unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits', substr($raw, $start, 16));
				if ($pcm !== ['format'=>1, 'channels'=>1, 'rate'=>8000, 'bytes'=>16000, 'block'=>2, 'bits'=>16]) { return false; }
				$format = true;
			} elseif ($kind === 'data') {
				if ($data || $size < 2 || $size % 2 !== 0) { return false; }
				$data = true;
			}
			$offset = $end + ($size % 2);
		}
		return $format && $data && $offset === $length;
	}

	private function combineAudioParts($baseName, array $files, $prefix)
	{
		if (!is_executable('/usr/bin/sox') || count($files) < 1) {
			return '';
		}
		foreach ($files as $file) {
			$this->announcementAudioFileMetadata($file);
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
		if ($this->isValidAnnouncementAudioCache($target) && (int)@filemtime($target) >= $sourceMtime) {
			return $name;
		}
		$tmp = $target . '.tmp.' . bin2hex(random_bytes(3)) . '.wav';
		$silence = $target . '.silence.' . bin2hex(random_bytes(3)) . '.wav';
		$silenceCmd = '/usr/bin/timeout --kill-after=2 30 /usr/bin/sox -n -r 8000 -c 1 -b 16 ' . escapeshellarg($silence) . ' trim 0.0 1.0 2>&1';
		exec($silenceCmd, $silenceOutput, $silenceExit);
		if ($silenceExit !== 0 || !is_file($silence)) {
			@unlink($silence);
			return '';
		}
		$cmd = '/usr/bin/timeout --kill-after=2 30 /usr/bin/sox';
		$cmd .= ' ' . escapeshellarg($silence);
		foreach ($files as $file) {
			$cmd .= ' ' . escapeshellarg($file);
		}
		$cmd .= ' -r 8000 -c 1 -b 16 ' . escapeshellarg($tmp) . ' 2>&1';
		exec($cmd, $output, $exitCode);
		@unlink($silence);
		if ($exitCode !== 0 || !$this->isValidAnnouncementAudioCache($tmp)) {
			@unlink($tmp);
			return '';
		}
		if (!@rename($tmp, $target)) { @unlink($tmp); return ''; }
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
		$this->announcementAudioFileMetadata($source);
		if (!is_readable($source)) {
			return '';
		}

		$volumePercent = $this->normalizeTtsVolume($volumePercent, 25);
		$quietBase = 'announcement_tone_' . $toneName . '_v' . $volumePercent;
		$quietBase = $this->normalizeToneName($quietBase);
		$target = self::TTS_DIR . '/' . $quietBase . '.wav';
		if ($this->isValidAnnouncementAudioCache($target) && filemtime($target) !== false && filemtime($source) !== false && filemtime($target) >= filemtime($source)) {
			return $quietBase;
		}

			$tmp = $target . '.tmp.' . bin2hex(random_bytes(3)) . '.wav';
		$cmd = '/usr/bin/timeout --kill-after=2 30 /usr/bin/sox -v ' . escapeshellarg($this->volumePercentToScalar($volumePercent, 25)) . ' '
			. escapeshellarg($source)
			. ' -r 8000 -c 1 -b 16 '
			. escapeshellarg($tmp)
			. ' 2>&1';
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0 || !$this->isValidAnnouncementAudioCache($tmp)) {
			@unlink($tmp);
			return '';
		}

		if (!@rename($tmp, $target)) { @unlink($tmp); return ''; }
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

	protected function requestPhoneAdmission(array $request)
	{
		$encoded = json_encode($request, JSON_UNESCAPED_SLASHES);
		$failure = ['ok' => false, 'failure_code' => 'phone_admission_unavailable', 'retryable' => false,
			'detail' => _('Phone admission could not be confirmed. Check the SLS phone-events service and local Asterisk connection. No additional call files were authorized.')];
		if (!is_string($encoded) || strlen($encoded) > 1048576) { return $failure; }
		$command = ['/usr/bin/timeout', '--signal=TERM', '--kill-after=2', '75', '/usr/bin/python3', '-I',
			self::RUNTIME_DIR . '/sls_phone_admission.py', '--request-stdin'];
		$pipes = [];
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell' => true]);
		if (!is_resource($process)) { return $failure; }
		$sent = fwrite($pipes[0], $encoded); fclose($pipes[0]);
		$raw = stream_get_contents($pipes[1], 131073); fclose($pipes[1]);
		if (!is_string($raw) || strlen($raw) > 131072) { proc_terminate($process); }
		$exit = proc_close($process);
		$result = is_string($raw) && strlen($raw) <= 131072 ? json_decode($raw, true) : null;
		if ($sent !== strlen($encoded) || !is_array($result)) { return $failure; }
		if ($exit !== 0 || empty($result['ok'])) {
			return is_string($result['failure_code'] ?? null) && is_string($result['detail'] ?? null)
				? ['ok' => false, 'failure_code' => $result['failure_code'], 'detail' => $result['detail'], 'retryable' => false] : $failure;
		}
		if (!preg_match('/^[a-f0-9]{32}$/D', (string)($result['token'] ?? '')) || !is_array($result['targets'] ?? null)
			|| !is_array($result['outbound_targets'] ?? null) || !is_array($result['unavailable'] ?? null)) { return $failure; }
		$phones = array_map('strval', (array)($request['internal'] ?? []));
		$voice = array_column((array)($request['outbound'] ?? []), 'id');
		if (array_diff($result['targets'], $phones) || array_diff($result['unavailable'], $phones) || array_diff($result['outbound_targets'], $voice)) { return $failure; }
		$rejected = $result['outbound_failures'] ?? [];
		if (!is_array($rejected) || !array_is_list($rejected) || count($rejected) > count($voice)) { return $failure; }
		$seen = [];
		foreach ($rejected as $row) {
			if (!is_array($row) || !is_string($row['id'] ?? null) || !in_array($row['id'], $voice, true)
				|| in_array($row['id'], $result['outbound_targets'], true) || isset($seen[$row['id']])
				|| !is_string($row['failure_code'] ?? null) || !preg_match('/^[a-z_]{1,100}$/D', $row['failure_code'])
				|| !is_string($row['detail'] ?? null) || strlen($row['detail']) > 1024) { return $failure; }
			$seen[$row['id']] = true;
		}
		return $result;
	}

	private function queueAnnouncementAudioCalls(array $extensions, $sequence, $audioDuration, $priority = 'normal', array $outboundTargets = [], $correlation = '', array $apiContext = [])
	{
		$settings = $this->getActiveSettings();
		$deadline = $apiContext['schedule_deadline_at'] ?? null;
		if ($deadline !== null && (!is_int($deadline) || $deadline < 1 || $deadline > 253402300799)) {
			throw new \DomainException('Invalid scheduled audio start deadline.');
		}
		$latestTick = $deadline === null ? null : hrtime(true) / 1000000000 + max(0, $deadline - time());
		$expired = function () use ($deadline, $latestTick) {
			if ($deadline === null || (time() < $deadline && hrtime(true) / 1000000000 < $latestTick)) { return false; }
			$this->lastPhoneAdmission['failure_code'] = 'schedule_deadline_expired';
			$this->lastPhoneAdmission['detail'] = _('The scheduled start deadline elapsed before this phone submission. Unstarted calls were rejected; calls already in progress were not interrupted.');
			return true;
		};
		if ($expired()) { return 0; }
		if (!in_array($priority, ['normal', 'urgent'], true)) { return 0; }
		if ($sequence === '' || !is_dir(self::ASTERISK_OUTGOING_SPOOL) || !is_dir(self::ASTERISK_SPOOL_TMP)) {
			return 0;
		}
		$pageHoldSeconds = $this->getAudioPageHoldSeconds($audioDuration);
		if ($pageHoldSeconds < 1) {
			return 0;
		}
		$callWaitSeconds = $pageHoldSeconds + 30;
		$extensions = array_values(array_unique(array_map('strval', $extensions)));
		foreach ($extensions as $extension) {
			$this->lastAudioQueueResults[$extension] = false;
			if (!preg_match('/^[0-9]{1,20}$/D', $extension)) { return 0; }
		}
		foreach ($outboundTargets as $target) {
			$this->lastOutboundQueueResults[$target['id']] = ['state' => 'rejected', 'retryable' => false];
		}
		$reservation = [
			'/usr/bin/python3', self::RUNTIME_DIR . '/sls_audio_queue.py',
			'--recipients', implode(',', $extensions), '--duration', (string)$pageHoldSeconds, '--sound', $sequence,
			'--priority', $priority,
		];
		if ($deadline !== null) { $reservation[] = '--latest-start'; $reservation[] = (string)$deadline; }
		if ($extensions) {
			$pipes = [];
			$process = proc_open($reservation, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
			if (!is_resource($process)) { return 0; }
			stream_get_contents($pipes[2]); fclose($pipes[2]);
			if (proc_close($process) !== 0) { $expired(); return 0; }
		}
		if ($expired()) { return 0; }
		$this->lastPhoneAdmission = $this->requestPhoneAdmission(['internal' => $extensions, 'outbound' => $outboundTargets,
			'service' => 'announcement', 'correlation' => (string)$correlation, 'wait_seconds' => 30,
			'duration' => $pageHoldSeconds, 'sound' => $sequence] + ($deadline === null ? [] : ['latest_start' => $deadline]));
		if (empty($this->lastPhoneAdmission['ok'])) { return 0; }
		if ($expired()) { return 0; }
		foreach ($this->lastPhoneAdmission['outbound_failures'] ?? [] as $row) {
			$this->lastOutboundQueueResults[$row['id']] = ['state' => 'rejected', 'retryable' => false,
				'failure_code' => $row['failure_code'], 'detail' => $row['detail']];
		}
		$token = $this->lastPhoneAdmission['token'];
		$targets = [];
		foreach ($this->lastPhoneAdmission['targets'] as $recipient) { $targets[] = ['id' => $recipient, 'external' => false]; }
		foreach ($this->lastPhoneAdmission['outbound_targets'] as $recipient) { $targets[] = ['id' => $recipient, 'external' => true]; }

		$queued = 0;
		foreach ($targets as $targetRow) {
			$recipient = $targetRow['id'];
			$external = $targetRow['external'];
			if ($expired()) { break; }
			// Admission can wait for capacity. Recheck a revocable credential at
			// the actual submission boundary, preserving only its frozen targets.
			if (!empty($apiContext['api_credential_id']) || !empty($apiContext['automation_context'])) {
				$voice = $external ? array_values(array_filter($outboundTargets, static function ($row) use ($recipient) { return ($row['id'] ?? '') === $recipient; })) : [];
				$permitted = $this->guardPreparedApiAudio($apiContext, $external ? [] : [(string)$recipient], $voice);
				if (!$permitted['success']) {
					$channel = $external ? 'voice_recipient_ids' : 'phones';
					$this->lastPhoneAdmission['permission_denied_targets'][$channel][] = (string)$recipient;
					$this->lastPhoneAdmission['failure_code'] = 'api_permission_revoked';
					$this->lastPhoneAdmission['detail'] = $permitted['message'];
					continue;
				}
			}
			$callFile = @tempnam(self::ASTERISK_SPOOL_TMP, 'sls_announcement_');
			if ($callFile === false || dirname($callFile) !== self::ASTERISK_SPOOL_TMP) {
				if (is_string($callFile) && $callFile !== '') {
					@unlink($callFile);
				}
				continue;
			}
			$audioContext = $external ? 'sls-outbound-voice' : 'sls-alert-audio';
			$body = "Channel: Local/{$recipient}@{$audioContext}/n\n"
				. "Account: slsphone_{$token}\n"
				. "CallerID: \"SLS Mass Notify System\" <SLS>\n"
				. "Setvar: __SLS_PHONE_TOKEN={$token}\n"
				. "Setvar: __SLS_PHONE_RECIPIENT={$recipient}\n"
				. "Setvar: SLS_SOUND={$sequence}\n"
				. "Setvar: SLS_CALLERID_NAME=SLS Mass Notify System\n"
				. "Setvar: SLS_CALLERID_NUM=SLS\n"
				. "MaxRetries: 0\n"
				. "RetryTime: 5\n"
				. "WaitTime: {$callWaitSeconds}\n"
				. ($external ? "Context: sls-outbound-playback\nExtension: s\nPriority: 1\n" : "Application: Wait\nData: {$pageHoldSeconds}\n");
			$handle = @fopen($callFile, 'wb');
			$written = is_resource($handle) && fwrite($handle, $body) === strlen($body) && fflush($handle)
				&& (!function_exists('fsync') || fsync($handle));
			if (is_resource($handle)) { fclose($handle); }
			if (!$written) {
				@unlink($callFile);
				continue;
			}
			@chown($callFile, 'asterisk');
			@chgrp($callFile, 'asterisk');
			@chmod($callFile, 0640);
			$target = self::ASTERISK_OUTGOING_SPOOL . '/' . basename($callFile) . '.call';
			if ($expired()) { @unlink($callFile); break; }
			$clusterRequest = $this->enterpriseClusterRequest ?? ['delivery_id'=>(string)$correlation,
				'message'=>(string)$sequence,'delivery_timestamp'=>(string)($apiContext['delivery_timestamp']??''),
				'schedule_deadline_at'=>$deadline];
			try {
				$submitted = \SLS\MassNotify\EnterpriseClusterIntegration::effect($settings,$clusterRequest,
					$external?'external_voice':'audio',(string)$recipient,static fn()=>@rename($callFile,$target));
			} catch (\Throwable $error) {
				$submitted=false;
				if ($external) { $this->lastOutboundQueueResults[$recipient]=['state'=>'failed','retryable'=>false,'detail'=>'Cluster authority could not authorize this call. No call file was submitted; review the witness, lease and immutable delivery identity.','failure_code'=>'cluster_delivery_fenced']; }
				else { $this->lastAudioQueueResults[$recipient]=false; }
			}
			if ($submitted) {
				$queued++;
				if ($external) {
					$this->lastOutboundQueueResults[$recipient] = ['state' => 'queued', 'retryable' => false,
						'admission_id' => $token, 'detail' => _('Queued through FreePBX; remote answer and playback are unverified.')];
				} else { $this->lastAudioQueueResults[$recipient] = true; }
				$directory = @fopen(self::ASTERISK_OUTGOING_SPOOL, 'r');
				$synced = is_resource($directory) && function_exists('fsync') && @fsync($directory);
				if (is_resource($directory)) { fclose($directory); }
				if (!$synced) {
					$this->lastPhoneAdmission['submission_uncertain'] = true;
					if ($external) { $this->lastOutboundQueueResults[$recipient]['state'] = 'submission_uncertain';
						$this->lastOutboundQueueResults[$recipient]['detail'] = _('A call file was submitted, but durable spool confirmation failed. Review its outcome before resubmitting.'); }
				}
			} else {
				@unlink($callFile);
			}
		}
		return $queued;
	}

	private function updateStatusData(array $patch)
	{
		$this->ensurePluginDataDir();
		$path = self::STATUS_JSON; $limit = 1048576;
		$parent = @lstat(dirname($path)); $account = posix_getpwnam('asterisk');
		$owners = array_unique([0, posix_geteuid(), $account['uid'] ?? -1]);
		if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022)
			|| !in_array($parent['uid'], $owners, true) || realpath(dirname($path)) !== dirname($path)) {
			throw new \RuntimeException(_('Status storage has an unsafe directory. Run Repair Installation.'));
		}
		$regular = static function ($meta) use ($owners, $limit): bool {
			return is_array($meta) && ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1
				&& in_array($meta['uid'], $owners, true) && ($meta['mode'] & 0027) === 0 && $meta['size'] <= $limit;
		};
		clearstatcache(true, $path); $before = @lstat($path);
		if ($before !== false && !$regular($before)) { throw new \RuntimeException(_('Status storage is unsafe or exceeds 1 MiB. Preserve it and run Repair Installation.')); }
		$mask = umask(0037);
		try {
			$handle = @fopen($path, $before === false ? 'x+b' : 'r+b'); $created = $before === false && is_resource($handle);
			if (!$handle && $before === false) {
				clearstatcache(true, $path); $before = @lstat($path);
				if ($regular($before)) { $handle = @fopen($path, 'r+b'); }
			}
		} finally { umask($mask); }
		if (!is_resource($handle)) { throw new \RuntimeException(_('Status storage could not be opened. Check permissions and free space.')); }
		try {
			$identity = static function () use ($path, $handle, $before, $regular): bool {
				clearstatcache(true, $path); $named = @lstat($path); $opened = fstat($handle);
				return $regular($named) && $regular($opened) && $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino']
					&& ($before === false || ($before['dev'] === $opened['dev'] && $before['ino'] === $opened['ino']));
			};
			if (!$identity()) { throw new \RuntimeException(_('Status storage changed during access. Preserve the existing file.')); }
			if ($created) { $this->setPrivateOwnership($path); }
			$deadline = hrtime(true) + 2000000000;
			while (!flock($handle, LOCK_EX | LOCK_NB)) {
				if (hrtime(true) >= $deadline) { throw new \RuntimeException(_('Status storage is busy. Its existing evidence was preserved.')); }
				usleep(10000);
			}
			if (!$identity()) { throw new \RuntimeException(_('Status storage changed while waiting for its lock.')); }
			rewind($handle); $raw = stream_get_contents($handle, $limit + 1);
			if (!is_string($raw) || strlen($raw) > $limit || ($raw === '' && !$created)) { throw new \RuntimeException(_('Status evidence is incomplete or exceeds 1 MiB. Preserve it for recovery.')); }
			$data = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			if (!is_array($data) || ($raw !== '' && (substr(ltrim($raw), 0, 1) !== '{' || ($data && array_is_list($data))))) {
				throw new \RuntimeException(_('Status evidence is invalid. Preserve it for recovery.'));
			}
			foreach ($patch as $key => $value) { $data[$key] = $value; }
			$bytes = json_encode((object)$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			if (strlen($bytes) > $limit || !$identity()) { throw new \RuntimeException(_('Status update exceeded its safe storage limit. Existing evidence was preserved.')); }
			// PHP and Python status writers coordinate on this existing inode.
			rewind($handle);
			if (!ftruncate($handle, 0)) { throw new \RuntimeException(_('Status storage could not be updated. Check free space.')); }
			for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
				$written = fwrite($handle, substr($bytes, $offset));
				if (!is_int($written) || $written < 1) { throw new \RuntimeException(_('Status storage write was incomplete. Preserve the file for recovery.')); }
			}
			if (!fflush($handle) || !fsync($handle) || !$identity()) { throw new \RuntimeException(_('Status storage completion could not be confirmed. Inspect its protected file.')); }
		} finally { fclose($handle); }
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
			'source_name' => (string)($context['sender'] ?? 'SLS Mass Notify System'),
			'sender' => (string)($context['sender'] ?? ''),
			'priority' => ($context['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal',
			'delivery_receipts' => (array)($context['delivery_receipts'] ?? []),
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
		// The maintenance worker honors active playback reservations and spool
		// references. A request must never delete another worker's audio by age.
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

	public function getAnnouncementWebhookDestinations()
	{
		$destinations = [];
		foreach ($this->normalizeWebhookDestinations($this->getActiveSettings()['announcement_webhooks'] ?? [], 'announcement') as $destination) {
			if (empty($destination['enabled'])) {
				continue;
			}
			$destinations[] = [
				'id' => (string)$destination['id'],
				'name' => (string)$destination['name'],
			];
		}
		return $destinations;
	}

	public function repairInstallation()
	{
		// Even a root-initiated restore queues the authenticated worker. No web
		// module or FreePBX bootstrap is promoted to root for a repair request.
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
				$progress = array_merge($progress, $this->maintenanceFailureDetails($decoded));
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
				$progress = array_merge($progress, $this->maintenanceFailureDetails($decoded));
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

	private function maintenanceFailureDetails(array $decoded)
	{
		$allowed = ['update_script_syntax_error', 'protected_config_validation_failed', 'worker_start_failed',
			'worker_bootstrap_failed', 'worker_module_load_failed', 'worker_runtime_failed', 'install_command_failed',
			'repair_failed', 'config_invalid', 'release_check_failed', 'release_metadata_invalid', 'download_failed',
			'release_verification_failed', 'process_failed', 'timeout', 'lock_busy', 'uninstall_failed'];
		$category = $decoded['error_category'] ?? '';
		return ['error_category' => is_string($category) && in_array($category, $allowed, true) ? $category : '',
			'exit_code' => isset($decoded['exit_code']) && is_int($decoded['exit_code'])
				&& $decoded['exit_code'] >= 0 && $decoded['exit_code'] <= 255 ? $decoded['exit_code'] : null];
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

	public function getDiagnosticsSummary($includeDevices = true)
	{
		$settings = $this->getActiveSettings();
		$checks = [];
		$checks[] = $this->diagnosticCheck(_('Central config'), is_readable(self::SETTINGS_JSON), self::SETTINGS_JSON);
		$checks[] = $this->diagnosticCheck(_('Central config loader'), is_executable('/usr/local/bin/sls_mass_notify/sls_config.py'), '/usr/local/bin/sls_mass_notify/sls_config.py');
		$checks[] = $this->diagnosticCheck(_('SIP NOTIFY sender'), is_executable(self::VISUAL_PUSH_SCRIPT), self::VISUAL_PUSH_SCRIPT);
		$checks[] = $this->diagnosticCheck(_('NWS poller'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh');
		$checks[] = $this->diagnosticCheck(_('Weather scheduler'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh');
		$checks[] = $this->diagnosticCheck(_('Weather delivery worker'), is_executable(self::RUNTIME_DIR . '/sls_weather_queue.py'), self::RUNTIME_DIR . '/sls_weather_queue.py');
		$checks[] = $this->diagnosticCheck(_('Announcement scheduler'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php');
		$announcementWorker = $this->getAnnouncementWorkerHealth();
		$phoneCollector = $this->getPhoneEventCollectorHealth();
		$checks[] = $this->diagnosticCheck(_('Phone admission and outcome collector'), $phoneCollector['ok'], $phoneCollector['message']);
		$checks[] = $this->diagnosticCheck(_('General announcement worker'), $announcementWorker['ok'],
			sprintf(_('Latest state: %s. '), $announcementWorker['latest_state']) . ($announcementWorker['failure_reason'] !== '' ? $announcementWorker['failure_reason']
				: ($announcementWorker['ok'] ? _('FreePBX/SLS bootstrap and protected job storage verified.') : _('Worker health check is missing, stale, or failed. Run protected repair.'))));
		$checks[] = $this->diagnosticCheck(_('Xweather poller'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py');
		$checks[] = $this->diagnosticCheck(_('Branded email sender'), is_executable('/usr/local/bin/sls_mass_notify/sls_branded_email.py'), '/usr/local/bin/sls_mass_notify/sls_branded_email.py');
		$checks[] = $this->diagnosticCheck(_('Branded Discord sender'), is_executable('/usr/local/bin/sls_mass_notify/sls_branded_discord.py'), '/usr/local/bin/sls_mass_notify/sls_branded_discord.py');
		$checks[] = $this->diagnosticCheck(_('Notification destination dispatcher'), is_executable('/usr/local/bin/sls_mass_notify/sls_notification_destinations.py'), '/usr/local/bin/sls_mass_notify/sls_notification_destinations.py');
		$checks[] = $this->diagnosticCheck(_('System/error email notifier'), is_executable('/usr/local/bin/sls_mass_notify/sls_system_notifications.py'), '/usr/local/bin/sls_mass_notify/sls_system_notifications.py');
		$checks[] = $this->diagnosticCheck(_('Weather zone status helper'), is_executable('/usr/local/bin/sls_mass_notify/sls_nws_status.py'), '/usr/local/bin/sls_mass_notify/sls_nws_status.py');
		$checks[] = $this->diagnosticCheck(_('Weather cross-zone delivery coordinator'), is_executable('/usr/local/bin/sls_mass_notify/sls_nws_delivery_claims.py'), '/usr/local/bin/sls_mass_notify/sls_nws_delivery_claims.py');
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
		$storage = $this->loadJsonFile(self::PLUGIN_DATA_DIR . '/storage-summary.json');
		if ($storage) {
			$queueFaults = SlsStatusHealth::queueFaults($storage);
			$checks[] = $this->diagnosticCheck(_('Storage free-space measurement'), is_int($storage['free_bytes'] ?? null) && $storage['free_bytes'] >= 0,
				sprintf(_('%s MiB free on the PBX data filesystem. This reports the measurement only; installation/capacity checks calculate SLS workspace separately, and retention requires additional provisioning.'), number_format((int)($storage['free_bytes'] ?? 0) / 1048576)));
			$checks[] = $this->diagnosticCheck(_('External delivery queue'), !$queueFaults['external'],
				sprintf(_('%d pending; %d unreadable queues; %d expired in retained history. Only failures in the last 15 minutes affect current delivery health.'), (int)($storage['pending_external'] ?? 0), (int)($storage['queue_errors'] ?? 0), (int)($storage['expired_external'] ?? 0))
				. (!empty($storage['queue_scan_incomplete']) ? ' ' . sprintf(_('Inventory incomplete after %d queue files; displayed counts are lower bounds. Inspect the maintenance log.'), (int)($storage['queue_files_scanned'] ?? 0)) : ''));
			$checks[] = $this->diagnosticCheck(_('Weather delivery queue'), !$queueFaults['weather'],
				sprintf(_('%d pending; %d running; %d failed; %d uncertain; %d expired. Oldest queued: %d seconds; oldest running: %d seconds; %d missed deadlines in the last seven days. Only failures in the last 15 minutes affect current delivery health.'),
					(int)($storage['pending_weather'] ?? 0), (int)($storage['weather_running'] ?? 0), (int)($storage['failed_weather'] ?? 0),
					(int)($storage['uncertain_weather'] ?? 0), (int)($storage['expired_weather'] ?? 0),
					(int)($storage['weather_oldest_queued_age_seconds'] ?? 0), (int)($storage['weather_oldest_running_age_seconds'] ?? 0), (int)($storage['weather_deadline_misses'] ?? 0)));
		}

		return [
			'checks' => $checks,
			'announcement_worker' => $announcementWorker,
			'phone_collector' => $phoneCollector,
			'endpoints' => $includeDevices ? $this->getDetectedEndpointFormats() : [],
			'desktop_clients' => $includeDevices ? $this->getDesktopClientDiagnostics($settings) : [],
			'control_api_audit' => $includeDevices ? $this->getControlApiAuditSummary() : [],
			'control_api_audit_notices' => $includeDevices ? $this->getUiLogNotices('audit') : [],
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
		$this->deviceInventoryError = '';
		if (!is_executable(self::VISUAL_PUSH_SCRIPT)) {
			$this->deviceInventoryError = _('Device discovery runtime is unavailable.');
			return [];
		}
		$output = [];
		$code = 1;
		exec('/usr/bin/timeout --signal=TERM --kill-after=2 20 /usr/bin/python3 ' . escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --list-endpoints-json 2>/dev/null', $output, $code);
		if ($code !== 0 || empty($output)) {
			$this->deviceInventoryError = _('Device discovery did not complete. Check Asterisk Manager connectivity and try again.');
			return [];
		}
		$decoded = json_decode(implode('', $output), true);
		if (!is_array($decoded)) {
			$this->deviceInventoryError = _('Device discovery returned an invalid response.');
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
				'devices' => array_values(array_filter((array)($info['devices'] ?? []), 'is_array')),
				'override' => !empty($info['override']),
				'unknown' => in_array('unknown', $formats, true),
			];
		}
		usort($endpoints, static function ($a, $b) {
			return strnatcasecmp((string)$a['extension'], (string)$b['extension']);
		});
		return $endpoints;
	}

	private function getDesktopClientDiagnostics(array $settings)
	{
		$lastSeen = $this->announcementDesktopPresence();
		$clients = [];
		foreach ($this->getDesktopClients($settings, false) as $client) {
			$username = (string)($client['username'] ?? '');
			$seen = is_array($lastSeen[$username] ?? null) ? $lastSeen[$username] : [];
			if (($seen['client_id'] ?? '') !== ($client['client_id'] ?? '')) { $seen = []; }
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
				'connected' => (int)($seen['connected_until'] ?? 0) >= time(),
				'last_acknowledged_event' => (string)($seen['ack_event_id'] ?? ''),
				'last_acknowledged_at' => (string)($seen['ack_at'] ?? ''),
				'state' => $state,
			] + \SLS\MassNotify\DesktopFleet::readiness($client, $seen, $settings['desktop_minimum_version'] ?? '');
		}
		return $clients;
	}

	public function getDesktopFleet(): array
	{
		$settings = $this->getActiveSettings();
		return ['schema'=>1, 'generated_at'=>gmdate('c'), 'minimum_version'=>$settings['desktop_minimum_version'] ?? '',
			'clients'=>$this->getDesktopClientDiagnostics($settings)];
	}

	private function getControlApiAuditSummary()
	{
		$this->uiLogNotices['audit'] = [];
		$events = [];
		$bytes = 2;
		foreach ($this->readUiLogLines(self::CONTROL_API_AUDIT_LOG, 'audit') as $line) {
			$decoded = $this->decodeUiLogRecord($line, 'audit');
			if ($decoded !== null) {
				if (!\SLS\MassNotify\SecurityAudit::keyUsage($decoded)) { continue; }
				$loopback = in_array((string)($decoded['ip'] ?? ''), ['127.0.0.1', '::1'], true);
				if ($loopback) {
					$decoded['ip'] = _('PBX internal');
				}
				if (!$this->admitUiLogResult($decoded, $bytes, 'audit')) { break; }
				$events[] = $decoded;
				if (count($events) >= 20) { break; }
			}
		}
		return $events;
	}

	private function getUiLogNotices($channel)
	{
		return array_values($this->uiLogNotices[$channel] ?? []);
	}

	private function noteUiLogRead($channel, $reason, $message)
	{
		$this->uiLogNotices[$channel][$reason] = $message;
	}

	/** Newest-first lines from a fixed-size snapshot, with bounded memory and I/O. */
	private function readUiLogLines($path, $channel)
	{
		clearstatcache(true, $path);
		$named = @lstat($path);
		if ($named === false && !file_exists($path)) { return; }
		if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1) {
			$this->noteUiLogRead($channel, 'unsafe', _('The log path is unsafe or is not a regular file. Run Repair Installation.'));
			return;
		}
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			$this->noteUiLogRead($channel, 'unavailable', _('The log could not be opened. Check its permissions or run Repair Installation.'));
			return;
		}
		try {
			$opened = fstat($handle);
			if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
				|| $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
				$this->noteUiLogRead($channel, 'changed', _('The log changed while it was being opened. Refresh the view.'));
				return;
			}
			// Never wait behind a stalled writer or maintenance operation in a web request.
			if (!flock($handle, LOCK_SH | LOCK_NB)) {
				$this->noteUiLogRead($channel, 'busy', _('The log is busy being updated. Refresh the view shortly.'));
				return;
			}
			$position = (int)fstat($handle)['size'];
			$floor = max(0, $position - self::UI_LOG_SCAN_BYTES);
			if ($floor > 0) {
				$this->noteUiLogRead($channel, 'window', _('This view searches only the newest 16 MiB of the log. Older entries, including older date matches, remain in the log file.'));
			}
			$pending = '';
			$discard = false;
			$scanned = 0;
			$started = hrtime(true);
			while ($position > $floor) {
				if (hrtime(true) - $started > 2000000000) {
					$this->noteUiLogRead($channel, 'processing_limit', _('This log search reached its processing limit. Older matches may remain in the log file.'));
					return;
				}
				$length = min(8192, $position - $floor);
				$position -= $length;
				if (fseek($handle, $position, SEEK_SET) !== 0 || ($chunk = fread($handle, $length)) === false || strlen($chunk) !== $length) {
					$this->noteUiLogRead($channel, 'read_failed', _('The log could not be read completely. Refresh the view; check the log file if this continues.'));
					return;
				}
				$parts = explode("\n", $chunk);
				for ($index = count($parts) - 1; $index >= 0; $index--) {
					if (!$discard) {
						if (strlen($parts[$index]) + strlen($pending) > self::UI_LOG_LINE_BYTES) {
							$discard = true;
							$pending = '';
							$this->noteUiLogRead($channel, 'oversized', _('One or more log records exceed 256 KiB and were omitted from this view. The original log is unchanged.'));
						} else { $pending = $parts[$index] . $pending; }
					}
					if ($index > 0) {
						if (++$scanned > self::UI_LOG_SCAN_LINES || hrtime(true) - $started > 2000000000) {
							$this->noteUiLogRead($channel, 'processing_limit', _('This log search reached its processing limit. Older matches may remain in the log file.'));
							return;
						}
						if (!$discard && trim($pending) !== '') { yield trim($pending); }
						$pending = '';
						$discard = false;
					}
				}
			}
			// A tail window may begin inside a record; never decode that fragment.
			if ($floor === 0 && !$discard && trim($pending) !== '') { yield trim($pending); }
		} finally { flock($handle, LOCK_UN); fclose($handle); }
	}

	private function decodeUiLogRecord($line, $channel)
	{
		$record = json_decode($line, true, 32);
		$valid = is_array($record) && isset($line[0]) && $line[0] === '{' && json_encode($record) !== false;
		$scalarFields = ['event_id', 'logged_at', 'created_at', 'type', 'status', 'event', 'severity', 'message_type',
			'trigger_source', 'trigger_extension', 'trigger_name', 'source_extension', 'source_name', 'page_group',
			'audio', 'alert_id', 'zone', 'system_name', 'mail_subject', 'mail_body', 'body', 'announcement_style',
			'desktop_all', 'notify_delay_seconds', 'background_color', 'title', 'ip', 'action'];
		if ($valid) {
			foreach ($scalarFields as $field) {
				if (isset($record[$field]) && !is_scalar($record[$field])) { $valid = false; break; }
			}
			foreach (['desktop_clients', 'audio_sequence'] as $field) {
				foreach (is_array($record[$field] ?? null) ? $record[$field] : [] as $value) {
					if (!is_scalar($value)) { $valid = false; break; }
				}
			}
			if ($valid && strlen((string)($record['event_id'] ?? '')) > 256) { $valid = false; }
		}
		if (!$valid) {
			$this->noteUiLogRead($channel, 'malformed', _('One or more malformed log records were omitted from this view. The original log is unchanged.'));
			return null;
		}
		return $record;
	}

	private function admitUiLogResult(array $record, &$bytes, $channel)
	{
		$size = strlen((string)json_encode($record)) + 1;
		if ($bytes + $size > self::UI_LOG_RESULT_BYTES) {
			$this->noteUiLogRead($channel, 'result_limit', _('The displayed log data reached its 2 MiB limit. Fewer rows are shown; narrow the filters to inspect other entries.'));
			return false;
		}
		$bytes += $size;
		return true;
	}

	private function loadJsonFile($path)
	{
		$path = (string)$path; $limit = 8 * 1024 * 1024;
		clearstatcache(true, $path); $before = @lstat($path);
		if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
			|| $before['size'] < 1 || $before['size'] > $limit || realpath($path) !== $path) { return []; }
		// Read/write opening does not block if a FIFO replaces the named file.
		$handle = @fopen($path, 'r+b');
		if (!$handle) { return []; }
		try {
			$matches = static function ($meta) use ($before): bool {
				if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000 || $meta['nlink'] !== 1) { return false; }
				foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
					if ($meta[$field] !== $before[$field]) { return false; }
				}
				return true;
			};
			if (!$matches(fstat($handle)) || !flock($handle, LOCK_SH | LOCK_NB)) { return []; }
			$raw = stream_get_contents($handle, $limit + 1);
			clearstatcache(true, $path);
			if (!is_string($raw) || strlen($raw) !== $before['size'] || !$matches(fstat($handle)) || !$matches(@lstat($path))) { return []; }
			$decoded = json_decode($raw, true, 64);
			return is_array($decoded) ? $decoded : [];
		} finally { flock($handle, LOCK_UN); fclose($handle); }
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
		$localtime = @readlink('/etc/localtime');
		if (is_string($localtime) && preg_match('#/usr/share/zoneinfo/(.+)$#', $localtime, $matches)) {
			$candidates[] = $matches[1];
		}

		if (is_readable('/etc/timezone')) { $candidates[] = trim((string)file_get_contents('/etc/timezone')); }
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
		$phoneCollector = $this->getPhoneEventCollectorHealth();
		if (!$phoneCollector['ok']) { $critical[] = $phoneCollector['message']; }
		$announcementWorker = $this->getAnnouncementWorkerHealth();
		if (!$announcementWorker['ok']) {
			$critical[] = _('General announcement worker health check is missing, stale, or failed. Run protected repair.');
		} elseif (!empty($announcementWorker['active_delivery_failure']) && $announcementWorker['failure_reason'] !== '') {
			$warnings[] = $announcementWorker['failure_reason'];
		}
		$now = time();
		$statusData = SlsStatusHealth::project($this->loadStatusData(), $settings, $now);
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
			if (in_array(strtolower((string)($statusData['last_xweather_external_status'] ?? '')), ['fault', 'uncertain'], true)) {
				$warnings[] = $this->normalizeStatusMessage($statusData['last_xweather_external_message'] ?? '', _('Lightning external delivery remains unconfirmed; review its destination receipts before retrying.'));
			}
			if (!empty($groupStatusMap)) {
				foreach ($enabledLightningGroups as $group) {
					$groupId = (string)($group['id'] ?? '');
					$groupName = trim((string)($group['name'] ?? '')) ?: $groupId;
					$groupStatus = is_array($groupStatusMap[$groupId] ?? null) ? $groupStatusMap[$groupId] : [];
					if (in_array(strtolower((string)($groupStatus['last_xweather_external_status'] ?? '')), ['fault', 'uncertain'], true)) {
						$warnings[] = sprintf('%s: %s', $groupName, $this->normalizeStatusMessage($groupStatus['last_xweather_external_message'] ?? '', _('External delivery remains unconfirmed.')));
					}
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
		$storage = $this->loadJsonFile(self::PLUGIN_DATA_DIR . '/storage-summary.json');
		if ($storage) {
			if ((int)($storage['checked_at'] ?? 0) < $now - 300) { $warnings[] = _('Storage and delivery-queue health checks are stale'); }
			if (isset($storage['free_bytes']) && (int)$storage['free_bytes'] <= 0) { $warnings[] = _('No free space is reported for PBX data; free space before sending notifications.'); }
			if (!empty($storage['queue_errors'])) { $warnings[] = _('An external delivery queue is unreadable; inspect the maintenance log'); }
			if (!empty($storage['queue_scan_incomplete'])) { $warnings[] = _('The external delivery queue scan reached its time or size limit; displayed counts may omit deliveries. Inspect the maintenance log.'); }
			if (!empty($storage['recent_expired_external'])) { $warnings[] = sprintf(_('%d external deliveries recently expired without acceptance; historical results remain in Delivery details'), (int)$storage['recent_expired_external']); }
			if (!empty($storage['security_audit_at_capacity'])) { $warnings[] = _('Authentication and administrator security audit storage reached its limit; recent security events may be missing.'); }
			if (!empty($storage['security_audit_fault_active'])) { $warnings[] = _('Security audit storage has an unresolved write failure. Review security-audit-fault.json and the maintenance log before security changes. Missing-record evidence is preserved.'); }
			if (!empty($storage['security_audit_forwarding_active'])) { $warnings[] = _('Security audit forwarding cannot confirm local system-logger acceptance. Check logger health and verify remote collection separately.'); }
			if (!empty($storage['audit_separation_recovery_required'])) { $warnings[] = _('Audit separation is incomplete. Audit append and retention remain suspended until the protected migration evidence has been reviewed and completed.'); }
			if (!empty($storage['audit_at_capacity'])) { $warnings[] = _('Control API audit storage reached its limit; recent events may be missing'); }
			if ($storage['audit_fault_active'] ?? (!empty($storage['audit_failed_records']) || !empty($storage['audit_failure_code']))) { $warnings[] = _('Control API audit storage has an unresolved write failure. Review control-api-audit-fault.json and the maintenance log. A verified successful write clears the warning while preserving missing-record counts. Do not replay accepted actions.'); }
			if (!empty($storage['media_scan_incomplete'])) { $warnings[] = _('Generated-media storage could not be fully inspected. Review the storage maintenance log; cleanup preserves files when reference or inventory checks fail.'); }
			if (!empty($storage['media_over_budget'])) { $warnings[] = _('Generated media exceeds its configured cache target. Active and recently generated files are preserved. Review queued alerts and available disk space; maintenance retries bounded cleanup automatically.'); }
			if (!empty($storage['audit_forwarding_active'])) { $warnings[] = _('Control API audit forwarding cannot confirm local system-logger acceptance. Check /dev/log and logger health; verify remote collection separately. Accepted API actions must not be replayed.'); }
			if (!empty($storage['recent_failed_weather']) || !empty($storage['recent_uncertain_weather']) || !empty($storage['recent_expired_weather'])) {
				$warnings[] = _('A recent Weather or Lightning delivery failed, expired, or has an uncertain outcome. Review Help diagnostics and Notification Logs; do not blindly replay it.');
			}
			if (!empty($storage['recent_weather_deadline_misses'])) {
				$warnings[] = sprintf(_('%d Weather or Lightning deliveries recently missed their execution deadline. Review queue ages and results in Help diagnostics.'), (int)$storage['recent_weather_deadline_misses']);
			}
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
			$enabledScheduleIds = array_column($enabledSchedules, 'id');
			$attentionStates = array_filter($this->getScheduleExecutionState(), static function ($record) use ($now, $enabledScheduleIds) {
				return in_array($record['schedule_id'] ?? '', $enabledScheduleIds, true)
					&& SlsStatusHealth::recent($record['completed_at'] ?? $record['updated_at'] ?? '', $now)
					&& in_array(strtolower((string)($record['state'] ?? '')), ['failed', 'missed', 'uncertain'], true);
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
		$this->uiLogNotices['events'] = [];
		$limit = $this->sanitizeLimit($limit);
		$type = $this->sanitizeType($type);
		$date = $this->sanitizeLogDate($date);
		$cutoff = time() - $this->normalizeRetentionDays($this->getActiveSettings()['log_retention_days'] ?? 90) * 86400;
		$buffer = [];
		$bytes = 2;
		foreach ($this->readUiLogLines(self::EVENTS_LOG, 'events') as $line) {
			$decoded = $this->decodeUiLogRecord($line, 'events');
			if ($decoded === null) { continue; }
			$loggedAt = strtotime((string)($decoded['logged_at'] ?? $decoded['created_at'] ?? ''));
			if ($loggedAt !== false && $loggedAt < $cutoff) { continue; }
			$event = $this->normalizeEvent($decoded);
			if ($type !== '' && $event['notification_type'] !== $type) {
				continue;
			}
			if ($date !== '' && substr((string)$event['display_time'], 0, 10) !== $date) {
				continue;
			}

			if (!$this->admitUiLogResult($event, $bytes, 'events')) { break; }
			$buffer[] = $event;
			if (count($buffer) >= $limit) { break; }
		}
		return $buffer;
	}

	public function getEventById($id)
	{
		$this->uiLogNotices['events'] = [];
		$id = trim((string)$id);
		if ($id === '' || strlen($id) > 256) {
			return null;
		}
		$cutoff = time() - $this->normalizeRetentionDays($this->getActiveSettings()['log_retention_days'] ?? 90) * 86400;
		foreach ($this->readUiLogLines(self::EVENTS_LOG, 'events') as $line) {
			$decoded = $this->decodeUiLogRecord($line, 'events');
			if ($decoded === null) { continue; }
			$loggedAt = strtotime((string)($decoded['logged_at'] ?? $decoded['created_at'] ?? ''));
			if ($loggedAt !== false && $loggedAt < $cutoff) { continue; }
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
		$labels = [];
		try {
			$rows = $this->FreePBX->Database()->query('SELECT displayname, filename FROM recordings LIMIT 501')->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($rows as $row) { foreach (explode('&', (string)$row['filename']) as $file) { $labels[$file] = (string)$row['displayname']; } }
		} catch (\Throwable $error) { /* Files remain available without recording metadata. */ }
		return \SLS\MassNotify\SystemRecordings::catalogue('/var/lib/asterisk/sounds', $labels);
	}

	private function importSystemSoundAsTone($selection, $prefix, array &$errors)
	{
		try { $this->ensurePluginDataDir(); return \SLS\MassNotify\SystemRecordings::import((string)$selection, '/var/lib/asterisk/sounds', self::TONES_DIR); }
		catch (\Throwable $error) { $errors[] = sprintf(_('The selected %s System Recording could not be imported: %s'), $prefix, $error->getMessage()); return ''; }
	}

	private function announcementTone($selection, $label, array &$errors): string
	{
		$selection = (string)$selection;
		return str_starts_with($selection, 'system:') ? $this->importSystemSoundAsTone($selection, $label, $errors) : $this->normalizeToneName($selection);
	}

	public function getAvailablePiperVoices()
	{
		$voices = [];
		$label = static function (string $name): string {
			$languages = ['en_US'=>'English (US)', 'es_ES'=>'Spanish', 'fr_FR'=>'French', 'de_DE'=>'German', 'pt_BR'=>'Portuguese (Brazil)'];
			$locale = substr($name, 0, 5);
			return ($languages[$locale] ?? $locale) . ' · ' . str_replace('-', ' · ', substr($name, 6));
		};
		foreach ($this->getPiperVoiceDownloads() as $file => $url) {
			if (substr($file, -5) !== '.onnx') {
				continue;
			}
			$path = self::PIPER_VOICE_DIR . '/' . $file;
			// Listing a fixed catalogue must not hash hundreds of MB on every
			// page load. The small configuration remains checksum-verified here;
			// synthesis and installation verify the complete model as well.
			$available = $this->isPresentPiperVoiceFile($path) && $this->isValidPiperVoiceFile($path . '.json');
			$voices[] = [
				'name' => $label(basename($file, '.onnx')) . ($available ? '' : ' (repair required)'),
				'path' => $path,
				'available' => $available,
			];
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

	public function previewScheduleCalendar(array $input): array
	{
		$calendar = \SLS\MassNotify\ScheduleCalendar::fromForm($input);
		$id = $input['schedule_id'] ?? '';
		if (!is_string($id) || ($id !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id))) {
			throw new \DomainException(_('The selected schedule identity is invalid.'));
		}
		$existing = []; $found = $id === ''; $timezone = $this->getPbxDateTimeZone();
		foreach ($this->getScheduledAnnouncements() as $schedule) {
			if (($schedule['id'] ?? '') === $id) {
				$found = true; $existing = array_fill_keys(array_column($schedule['occurrences'], 'run_at_utc'), true);
				if (($schedule['recurrence']['mode'] ?? '') === 'calendar') { $timezone = new \DateTimeZone($schedule['timezone']); }
				break;
			}
		}
		if (!$found) { throw new \DomainException(_('The selected schedule no longer exists.')); }
		$plan = $this->buildScheduledOccurrences($id ?: 'calendar_preview', array_values((array)($input['schedule_occurrences'] ?? [])),
			'calendar', $timezone, $existing, time() - 60, time() + self::MAX_SCHEDULE_YEARS * 366 * 86400, $calendar);
		return ['success'=>!$plan['errors'], 'errors'=>$plan['errors'], 'timezone'=>$timezone->getName(), 'occurrences'=>$plan['occurrences']];
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
		$errors = array_merge($errors, \SLS\MassNotify\SchedulePresentation::textErrors([
			'name'=>$input['schedule_name'] ?? '', 'message'=>$input['schedule_message'] ?? '',
			'delivery'=>['title'=>$input['schedule_title'] ?? 'Announcement'],
		]));
		try { $maxLateness = \SLS\MassNotify\SchedulePresentation::formLateness($input, $existing); }
		catch (\DomainException $error) { $maxLateness = 15; $errors[] = _($error->getMessage()); }
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
		if (!in_array($requestedRecurrenceMode, ['none', 'every_7_days', 'every_14_days', 'calendar'], true)) {
			$errors[] = _('Select a supported repeat interval.');
		}
		$recurrenceMode = $this->normalizeScheduleRecurrenceMode($requestedRecurrenceMode);
		$calendar = [];
		if ($recurrenceMode === 'calendar') {
			try { $calendar = \SLS\MassNotify\ScheduleCalendar::fromForm($input); }
			catch (\DomainException $error) { $errors[] = $error->getMessage(); }
			if (($existing['recurrence']['mode'] ?? '') === 'calendar') { $timezone = new \DateTimeZone($existing['timezone']); }
		}
		$occurrenceBuild = $this->buildScheduledOccurrences(
			$id,
			$occurrenceInputs,
			$recurrenceMode,
			$timezone,
			$existingRunTimes,
			time() - 60,
			time() + (self::MAX_SCHEDULE_YEARS * 366 * 86400),
			$calendar
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
		$emailIds = array_key_exists('schedule_email_recipient_ids', $input) ? $input['schedule_email_recipient_ids'] : [];
		$emailErrors = $this->validateAnnouncementEmailRecipientIds($emailIds);
		$errors = array_merge($errors, $emailErrors);
		$selectedEmailIds = $emailErrors ? [] : $emailIds;
		$knownEmailIds = array_fill_keys(array_column($this->getAnnouncementEmailRecipients($settings), 'id'), true);
		$effectiveEmailIds = $selectedEmailIds;
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			if (isset($selectedGroups[(string)($group['id'] ?? '')])) {
				$groupEmailIds = array_key_exists('email_recipient_ids', $group) ? $group['email_recipient_ids'] : [];
				$groupEmailErrors = $this->validateAnnouncementEmailRecipientIds($groupEmailIds);
				$errors = array_merge($errors, $groupEmailErrors);
				if (!$groupEmailErrors) { $effectiveEmailIds = array_merge($effectiveEmailIds, $groupEmailIds); }
			}
		}
		if (count(array_unique($effectiveEmailIds)) > 50 || array_diff($effectiveEmailIds, array_keys($knownEmailIds))) {
			$errors[] = _('Select at most 50 enabled saved email recipients. Review Announcement Email settings and selected groups.');
		}
		$smsIds = array_key_exists('schedule_sms_recipient_ids', $input) ? $input['schedule_sms_recipient_ids'] : [];
		$smsErrors = $this->validateSmsRecipientIds($smsIds);
		$errors = array_merge($errors, $smsErrors);
		$selectedSmsIds = $smsErrors ? [] : $smsIds;
		$knownSmsIds = array_fill_keys(array_column($this->getAnnouncementSmsRecipients($settings), 'id'), true);
		$effectiveSmsIds = $selectedSmsIds;
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			if (isset($selectedGroups[(string)($group['id'] ?? '')])) {
				$groupSmsIds = array_key_exists('sms_recipient_ids', $group) ? $group['sms_recipient_ids'] : [];
				$groupSmsErrors = $this->validateSmsRecipientIds($groupSmsIds);
				$errors = array_merge($errors, $groupSmsErrors);
				if (!$groupSmsErrors) { $effectiveSmsIds = array_merge($effectiveSmsIds, $groupSmsIds); }
			}
		}
		if (count(array_unique($effectiveSmsIds)) > 50 || array_diff($effectiveSmsIds, array_keys($knownSmsIds))) {
			$errors[] = _('Select at most 50 enabled saved SMS recipients. Review SMS settings and selected groups.');
		}
		$voiceIds = array_key_exists('schedule_voice_recipient_ids', $input) ? $input['schedule_voice_recipient_ids'] : [];
		$voiceErrors = $this->validateVoiceRecipientIds($voiceIds);
		$errors = array_merge($errors, $voiceErrors);
		$selectedVoiceIds = empty($voiceErrors) ? array_values(array_unique($voiceIds)) : [];
		$knownVoiceIds = array_fill_keys(array_column($this->getOutboundVoiceRecipients($settings), 'id'), true);
		$effectiveVoiceIds = $selectedVoiceIds;
		foreach ((array)($settings['announcement_groups'] ?? []) as $group) {
			if (isset($selectedGroups[(string)($group['id'] ?? '')])) {
				$groupVoiceIds = $group['voice_recipient_ids'] ?? [];
				$groupVoiceErrors = $this->validateVoiceRecipientIds($groupVoiceIds);
				$errors = array_merge($errors, $groupVoiceErrors);
				if (empty($groupVoiceErrors)) { $effectiveVoiceIds = array_merge($effectiveVoiceIds, $groupVoiceIds); }
			}
		}
		foreach (array_unique($effectiveVoiceIds) as $voiceId) {
			if (!isset($knownVoiceIds[$voiceId])) {
				$errors[] = _('A selected external voice recipient is removed or disabled. Enable outbound voice and select current saved recipients.');
			}
		}
		if ($phonesAll) {
			$selectedExtensions = [];
		}
		if ($desktopAll) {
			$selectedDesktops = [];
		}
		$selectedWebhookIds = $input['schedule_webhook_ids'] ?? [];
		$errors = array_merge($errors, $this->validateAudienceWebhookIds($selectedWebhookIds, $settings));
		foreach ($settings['announcement_groups'] ?? [] as $group) {
			if (isset($selectedGroups[$group['id']])) { $errors = array_merge($errors, $this->validateAudienceWebhookIds($group['webhook_ids'] ?? [], $settings)); }
		}
		if (!$phonesAll && !$desktopAll && empty($selectedExtensions) && empty($selectedGroups) && empty($selectedDesktops) && empty($selectedVoiceIds) && empty($selectedEmailIds) && empty($selectedSmsIds) && empty($selectedWebhookIds)) {
			$errors[] = _('Select at least one phone, announcement group, desktop, external voice, or saved email or SMS recipient.');
		}

		$audioMode = $this->normalizeAnnouncementAudioMode($input['schedule_audio_mode'] ?? 'none');
		if ($audioMode === 'none' && !empty($effectiveVoiceIds)) {
			$errors[] = _('External voice recipients require scheduled audio. Select TTS or tone audio, or remove those recipients.');
		}
		$openingTone = $this->announcementTone($input['schedule_opening_tone'] ?? '', 'opening', $errors);
		$closingTone = $this->announcementTone($input['schedule_closing_tone'] ?? '', 'closing', $errors);
		$availableTones = array_fill_keys($this->getAvailableTones(), true);
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

		$hasPhoneTarget = $phonesAll || !empty($selectedExtensions) || !empty($effectiveVoiceIds);
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
			$errors[] = _('Scheduled audio requires at least one phone or external voice target. Schedules containing only desktops or email recipients must use text-only delivery.');
		}

		if (!empty($errors)) {
			return ['success' => false, 'message' => _('The scheduled announcement was not saved.'), 'errors' => array_values(array_unique($errors))];
		}

		$now = gmdate('c');
		$schedule = [
			'id' => $id,
			'created_by' => (string)($existing['created_by'] ?? $this->announcementSender()),
			'updated_by' => $this->announcementSender(),
			'name' => $name,
			'enabled' => empty($input['schedule_enabled']) ? '0' : '1',
			'max_lateness_minutes' => $maxLateness,
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
				'voice_recipient_ids' => $selectedVoiceIds,
				'email_recipient_ids' => $selectedEmailIds,
				'sms_recipient_ids' => $selectedSmsIds,
				'webhook_ids' => $selectedWebhookIds,
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

		$origin = $this->operatorScheduleOrigin ?? ($existing['operator_credential_id'] ?? null);
		if ($origin !== null) { $schedule['operator_credential_id'] = $origin; }

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
		$settings['scheduled_announcements'] = $schedules;
        $conflicts = \SLS\MassNotify\SchedulePresentation::conflicts($settings, time());
        $warnings = [];
        foreach ($conflicts['rows'] as $conflict) {
            if ($conflict['schedule_id'] !== $id && $conflict['other_schedule_id'] !== $id) { continue; }
            $warnings[] = sprintf(_('Potential overlap: %s (%s UTC) and %s (%s UTC), %d shared configured recipient(s). Actual capacity is checked at delivery.'),
                $conflict['name'], $conflict['run_at_utc'], $conflict['other_name'], $conflict['other_run_at_utc'], $conflict['shared_recipients'])
                . ($conflict['dynamic_audience'] ? ' ' . _('An all-recipients selection may add overlap.') : '');
            if (count($warnings) >= 10) { break; }
        }
        if ($conflicts['incomplete']) { $warnings[] = _('Schedule conflict analysis reached its limit. Review other nearby dates and recipients.'); }
        return ['success' => true, 'message' => $existingIndex === null ? _('Scheduled announcement created.') : _('Scheduled announcement updated.'), 'errors' => [], 'warnings' => $warnings];
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
		return $this->runDurableScheduledAnnouncements();
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
			if (empty($extensions) && empty($desktopClients) && empty($group['voice_recipient_ids']) && empty($group['email_recipient_ids']) && empty($group['sms_recipient_ids']) && empty($group['webhook_ids'])) {
				continue;
			}
			$group['extensions'] = $extensions;
			$group['desktop_clients'] = $desktopClients;
			$groups[] = $group;
		}
		return $groups;
	}

	public function saveAnnouncementGroup($groupId, $name, $extensions, $desktopClients = [], $voiceRecipientIds = [], $emailRecipientIds = [], $smsRecipientIds = [], $webhookIds = [])
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
		$emailErrors = $this->validateAnnouncementEmailRecipientIds($emailRecipientIds);
		$knownEmailIds = array_column($this->getAnnouncementEmailRecipients($settings), 'id');
		if ($emailErrors || array_diff($emailRecipientIds, $knownEmailIds)) {
			return ['success' => false, 'message' => $emailErrors ? implode(' ', $emailErrors) : _('A saved email recipient is removed or disabled. Review Announcement Email settings.'), 'groups' => $this->getAnnouncementGroups()];
		}
		$smsErrors = $this->validateSmsRecipientIds($smsRecipientIds);
		$knownSmsIds = array_column($this->getAnnouncementSmsRecipients($settings), 'id');
		if ($smsErrors || array_diff($smsRecipientIds, $knownSmsIds)) {
			return ['success' => false, 'message' => $smsErrors ? implode(' ', $smsErrors) : _('A saved SMS recipient is removed or disabled. Review SMS settings.'), 'groups' => $this->getAnnouncementGroups()];
		}
		$voiceErrors = $this->validateVoiceRecipientIds($voiceRecipientIds);
		if ($voiceErrors || array_diff($voiceRecipientIds, array_keys($this->outboundVoiceTargets($settings)))) {
			return ['success' => false, 'message' => $voiceErrors ? implode(' ', $voiceErrors) : _('An external voice recipient is no longer enabled.'), 'groups' => $this->getAnnouncementGroups()];
		}
		$webhookErrors = $this->validateAudienceWebhookIds($webhookIds, $settings);
		if ($webhookErrors) { return ['success' => false, 'message' => implode(' ', $webhookErrors), 'groups' => $this->getAnnouncementGroups()]; }
		$groups = $settings['announcement_groups'] ?? [];
		foreach ($groups as $group) {
			if (($group['id'] ?? '') === $groupId && array_key_exists('location_snapshot', $group)) {
				return ['success' => false, 'message' => _('This is a reviewed location audience. Create a new audience from Locations to change its recipients; existing schedules and incidents keep this snapshot.'), 'groups' => $groups];
			}
		}
		$groupId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$groupId);
		$updated = false;
		$candidate = [
			'name' => $name,
			'extensions' => (array)$extensions,
			'desktop_clients' => (array)$desktopClients,
			'voice_recipient_ids' => array_values(array_unique($voiceRecipientIds)),
			'email_recipient_ids' => array_values($emailRecipientIds),
			'sms_recipient_ids' => array_values($smsRecipientIds),
			'webhook_ids' => array_values($webhookIds),
		];

		$bindings = [];
		foreach ($settings['desktop_clients'] ?? [] as $client) {
			if (in_array($client['username'], $candidate['desktop_clients'], true) && !empty($client['enabled'])) {
				$bindings[] = ['username' => $client['username'], 'client_id' => $client['client_id']];
			}
		}
		try { $candidate['desktop_bindings'] = \SLS\MassNotify\LocationDirectory::bindings($bindings, $candidate['desktop_clients']); }
		catch (\InvalidArgumentException $error) { return ['success' => false, 'message' => _('A selected desktop was disabled or removed. Review the saved audience.'), 'groups' => $groups]; }

		foreach ($groups as $index => $group) {
			if (($group['id'] ?? '') === $groupId && $groupId !== '') {
				$candidate['id'] = $groupId;
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
		$allowedExtensions = array_column($this->getAllPjsipExtensions(), 'extension');
		$normalizedCandidate = $this->normalizeAnnouncementGroupsForExtensions([$candidate], $allowedExtensions, $allowedDesktopUsernames);
		$normalized = $this->normalizeAnnouncementGroupsForExtensions($groups, $allowedExtensions, $allowedDesktopUsernames);
		if (empty($normalizedCandidate) || empty($normalized)) {
					return [
						'success' => false,
						'message' => _('Select at least one phone, desktop, external voice, or saved email recipient for the announcement group.'),
						'groups' => $this->getAnnouncementGroups(),
					];
		}

		$settings['announcement_groups'] = $normalized;
		try {
			$paging = $this->normalizeLivePagingSettings($settings['live_paging'] ?? []);
			\SLS\MassNotify\LivePagingConfig::resolvedGroups($paging, $normalized);
			$legacyPagingGroups = array_filter($paging['groups'], static function (array $row): bool {
				return !\SLS\MassNotify\LivePagingConfig::isStandalone($row);
			});
			$requiresApply = $updated && $paging['enabled'] === '1'
				&& in_array($groupId, array_column($legacyPagingGroups, 'group_id'), true);
			$pending = $this->getPendingSettings();
			$staged = $pending ?? $settings;
			// Merge only this group so a second edit cannot erase a previously
			// staged paging group or unrelated pending configuration changes.
			$stagedGroups = $staged['announcement_groups'] ?? [];
			$replacement = $normalizedCandidate[0];
			$replaced = false;
			foreach ($stagedGroups as $index => $group) {
				if (($group['id'] ?? '') === $replacement['id']) {
					$stagedGroups[$index] = $replacement;
					$replaced = true;
					break;
				}
			}
			if (!$replaced) {
				if (count($stagedGroups) >= 20) { throw new \InvalidArgumentException(_('Announcement groups are limited to 20.')); }
				$stagedGroups[] = $replacement;
			}
			$staged['announcement_groups'] = $stagedGroups;
			\SLS\MassNotify\LivePagingConfig::resolvedGroups($this->normalizeLivePagingSettings($staged['live_paging'] ?? []), $stagedGroups);
			if ($requiresApply) {
				$this->persistPendingSettings($staged);
				return [
					'success' => true,
					'apply_required' => true,
					'message' => _('Announcement group changes are staged. Use Apply Config to prepare the updated paging prompts and activate the changes. Announcement targets continue to use the active group until then.'),
					'groups' => $this->getAnnouncementGroups(),
				];
			}
			$this->persistAppliedSettings($settings);
			if ($pending !== null) { $this->persistPendingSettings($staged); }
		} catch (\Throwable $e) {
					return [
						'success' => false,
						'message' => ($e instanceof \InvalidArgumentException || $e instanceof \DomainException || $e instanceof \FreePBX\modules\SlsConfigurationWriteException) ? $e->getMessage() : _('Unable to save the announcement group. Reload settings and check protected configuration permissions.'),
						'settings_replaced' => $e instanceof \FreePBX\modules\SlsConfigurationWriteException && $e->settingsWereReplaced(),
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
		foreach ([$settings, $this->getPendingSettings() ?? []] as $configured) {
			foreach ((array)($configured['live_paging']['groups'] ?? []) as $pagingGroup) {
				if (($pagingGroup['group_id'] ?? '') === $groupId && !\SLS\MassNotify\LivePagingConfig::isStandalone($pagingGroup)) {
					return ['success' => false, 'message' => _('This group is used by Dial-in Paging. Remove it from the paging menu and apply that change before deleting the group.'), 'groups' => $this->getAnnouncementGroups()];
				}
			}
		}
		$groups = [];
		$previousGroups = (array)($settings['announcement_groups'] ?? []);
		foreach ($previousGroups as $group) {
			if (($group['id'] ?? '') !== $groupId) {
				$groups[] = $group;
			}
		}
		$settings['announcement_groups'] = $this->normalizeAnnouncementGroups($groups);
		try {
			$this->persistAppliedSettings($settings);
			$this->syncPendingAnnouncementGroups($settings['announcement_groups'], $previousGroups);
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => ($e instanceof \InvalidArgumentException || $e instanceof \DomainException || $e instanceof \FreePBX\modules\SlsConfigurationWriteException) ? $e->getMessage() : _('Unable to delete the announcement group. Reload settings and check protected configuration permissions.'),
				'settings_replaced' => $e instanceof \FreePBX\modules\SlsConfigurationWriteException && $e->settingsWereReplaced(),
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
		foreach (['style', 'image', 'title', 'background_color', 'audio_mode', 'opening_tone', 'closing_tone', 'priority'] as $key) {
			if (array_key_exists($key, $payload)) {
				$options[$key] = $payload[$key];
			}
		}
		if (array_key_exists('desktop_all', $payload) || array_key_exists('all_desktops', $payload)) {
			$options['desktop_all'] = ($payload['desktop_all'] ?? false) === true || ($payload['all_desktops'] ?? false) === true;
		}
		if (array_key_exists('voice_recipient_ids', $payload)) { $options['voice_recipient_ids'] = $payload['voice_recipient_ids']; }
		if (array_key_exists('email_recipient_ids', $payload)) { $options['email_recipient_ids'] = $payload['email_recipient_ids']; }
		if (array_key_exists('sms_recipient_ids', $payload)) { $options['sms_recipient_ids'] = $payload['sms_recipient_ids']; }
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
		if (array_intersect(array_keys($settingsPatch), ['labs_safety','enterprise_cluster','enterprise_identity','directory_sync','subscriber_browser','enterprise_integrations','enterprise_operations'])) {
			return ['success'=>false,'message'=>'Configure Enterprise Labs in the FreePBX administrator panel. Control API keys cannot activate or acknowledge experimental security features.', 'errors'=>['Enterprise Labs configuration is administrator-only.']];
		}
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
		if (array_key_exists('announcement_webhooks', $settingsPatch)) {
			$destinationErrors = array_merge($destinationErrors, $this->validateWebhookDestinations(
				$this->mergeWebhookDestinationSecrets($settingsPatch['announcement_webhooks'], $current['announcement_webhooks'] ?? [], 'announcement'),
				'announcement'
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
		if (array_key_exists('nws_zones', $settingsPatch) && $settingsPatch['nws_zones'] === []) {
			$merged['nws_zone'] = '';
			$merged['alert_recipients'] = [];
		}
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
		if (array_key_exists('nws_zones', $settingsPatch) || array_key_exists('discord_webhooks', $settingsPatch) || array_key_exists('generic_webhooks', $settingsPatch)) {
			$zoneRoutingErrors = array_merge($zoneRoutingErrors, $this->validateNwsZoneDestinationAssignments($normalized['nws_zones'] ?? [], $normalized));
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
				'message' => ($e instanceof \InvalidArgumentException || $e instanceof \DomainException || $e instanceof \FreePBX\modules\SlsConfigurationWriteException) ? $e->getMessage() : _('Unable to update config.'),
				'settings_replaced' => $e instanceof \FreePBX\modules\SlsConfigurationWriteException && $e->settingsWereReplaced(),
				'errors' => [$e->getMessage()],
			];
		}
	}

	private function validateControlApiAnnouncementPayload(array $payload)
	{
		$errors = [];
		foreach ([$payload, is_array($payload['options'] ?? null) ? $payload['options'] : []] as $fields) {
			if (array_key_exists('voice_recipient_ids', $fields)) { $errors = array_merge($errors, $this->validateVoiceRecipientIds($fields['voice_recipient_ids'])); }
			if (array_key_exists('email_recipient_ids', $fields)) { $errors = array_merge($errors, $this->validateAnnouncementEmailRecipientIds($fields['email_recipient_ids'])); }
			if (array_key_exists('sms_recipient_ids', $fields)) { $errors = array_merge($errors, $this->validateSmsRecipientIds($fields['sms_recipient_ids'])); }
			foreach (['sms', 'sms_recipients', 'sms_numbers'] as $rawSmsField) {
				if (array_key_exists($rawSmsField, $fields)) { $errors[] = _('Use sms_recipient_ids with saved destinations; raw SMS numbers are not accepted.'); }
			}
			foreach (['emails', 'email_recipients'] as $rawEmailField) {
				if (array_key_exists($rawEmailField, $fields)) { $errors[] = _('Use email_recipient_ids with saved destinations; raw email addresses are not accepted.'); }
			}
			if (array_key_exists('priority', $fields) && !in_array($fields['priority'], ['normal', 'urgent'], true)) {
				$errors[] = _('priority must be "normal" or "urgent".');
			}
		}
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
		if (array_key_exists('ok', $updateStatus) && $updateStatus['ok'] === false) {
			$status['state'] = 'error'; $status['label'] = _('Update check failed');
			$status = array_merge($status, $this->maintenanceFailureDetails($updateStatus));
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
		if (isset($settings['enterprise_identity'])) { $settings['enterprise_identity'] = \SLS\MassNotify\EnterpriseIdentityConfig::publicConfig($settings['enterprise_identity']); }
		if (isset($settings['directory_sync'])) { $settings['directory_sync'] = \SLS\MassNotify\DirectoryConfig::publicConfig($settings['directory_sync']); }
		if (isset($settings['enterprise_integrations'])) { $settings['enterprise_integrations'] = \SLS\MassNotify\EnterpriseIntegrationsConfig::redacted($settings['enterprise_integrations']); }
		foreach ($settings['enterprise_cluster']['peers'] ?? [] as $index=>$peer) { $settings['enterprise_cluster']['peers'][$index]['hmac_secret']='[redacted]'; }
		foreach ($settings['operator_access']['accounts'] ?? [] as $index => $account) {
			$settings['operator_access']['accounts'][$index]['identity'] = '[redacted]';
			if (isset($account['auth'])) { $settings['operator_access']['accounts'][$index]['auth'] = '[redacted]'; }
		}
        foreach (['twilio_key_secret','twilio_auth_token','telnyx_api_key','bulkvs_api_password'] as $secretField) {
            if (array_key_exists($secretField,$settings['announcement_sms']??[])) { $settings['announcement_sms'][$secretField]='[redacted]'; }
        }
		foreach ($settings['automations']['rules'] ?? [] as $index => $rule) {
			if (isset($rule['secret'])) { $settings['automations']['rules'][$index]['secret'] = '[redacted]'; }
		}
		foreach ((array)($settings['live_paging']['groups'] ?? []) as $index => $group) {
			if (is_array($group) && array_key_exists('pin_hash', $group)) {
				$settings['live_paging']['groups'][$index]['pin_hash'] = '[redacted]';
			}
			if (is_array($group) && isset($group['external_callers'])) {
				$settings['live_paging']['groups'][$index]['external_callers'] = array_fill(0, count((array)$group['external_callers']), '[redacted]');
			}
		}
		foreach (['desktop_api_token', 'desktop_auth_key', 'discord_webhook_url'] as $key) {
			if (array_key_exists($key, $settings)) {
				$settings[$key] = '[redacted]';
			}
		}
		foreach (['discord_webhooks', 'generic_webhooks', 'announcement_webhooks'] as $listKey) {
			if (!is_array($settings[$listKey] ?? null)) {
				continue;
			}
			foreach ($settings[$listKey] as $index => $destination) {
				foreach (['bearer_token', 'signing_secret'] as $secretField) {
					if (is_array($destination) && array_key_exists($secretField, $destination)) {
						$settings[$listKey][$index][$secretField] = '[redacted]';
					}
				}
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
		foreach ((array)($settings['control_api']['credentials'] ?? []) as $index => $credential) {
			if (is_array($credential) && array_key_exists('secret_hash', $credential)) { $settings['control_api']['credentials'][$index]['secret_hash'] = '[redacted]'; }
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
			if (in_array($key, ['discord_webhooks', 'generic_webhooks', 'announcement_webhooks'], true) && is_array($patch[$key])) {
				$current[$key] = $this->mergeWebhookDestinationSecrets(
					$patch[$key],
					$current[$key] ?? [],
					$key === 'generic_webhooks' ? 'generic' : ($key === 'announcement_webhooks' ? 'announcement' : 'discord')
				);
			} elseif (is_array($patch[$key]) && is_array($current[$key] ?? null) && in_array($key, ['control_api', 'sipnotify', 'updates', 'xweather', 'outbound_voice', 'announcement_email', 'announcement_sms'], true)) {
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
			'discord_webhooks', 'generic_webhooks', 'announcement_webhooks',
			'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_critical_events',
			'nws_api_base_url', 'nws_zone', 'nws_zones', 'alert_email_subject', 'alert_email_body',
			'test_email_subject', 'test_email_body', 'opening_tone', 'closing_tone',
			'nws_opening_tone', 'nws_closing_tone',
			'email_html_enabled', 'xweather',
			'nws_piper_voice', 'announcement_piper_voice', 'nws_tts_volume',
				'announcement_tts_volume', 'tts_max_seconds', 'announcement_timeout_mode',
				'announcement_timeout_seconds', 'log_retention_days', 'generated_media_cache_mib', 'control_api',
				'sipnotify', 'announcement_groups', 'updates', 'test_profiles', 'paging_answer_timeout', 'desktop_client_limit', 'desktop_minimum_version', 'phone_device_limit', 'outbound_voice', 'announcement_email', 'announcement_sms', 'announcement_pronunciation',
		];
	}

	private function validateAndNormalizeControlConfigPatch(array $patch)
	{
		$errors = [];
		if (array_key_exists('generated_media_cache_mib', $patch) && (!is_int($patch['generated_media_cache_mib']) || $patch['generated_media_cache_mib'] < 64 || $patch['generated_media_cache_mib'] > 4096)) {
			$errors[] = _('Generated-media cache target must be an integer from 64 through 4096 MiB.');
		}
		if (array_key_exists('announcement_pronunciation', $patch)) {
			try { $patch['announcement_pronunciation'] = \SLS\MassNotify\SpeechRules::normalize($patch['announcement_pronunciation']); }
			catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		}
		if (array_key_exists('desktop_minimum_version', $patch) && !\SLS\MassNotify\DesktopFleet::validMinimum($patch['desktop_minimum_version'])) {
			$errors[] = _('Minimum desktop version must be empty or a version such as 1.2.3 or 1.2.3-beta.1.');
		}
		if (array_key_exists('desktop_client_limit', $patch)
			&& (!is_int($patch['desktop_client_limit']) || $patch['desktop_client_limit'] < 1 || $patch['desktop_client_limit'] > 1000)) {
			$errors[] = _('Desktop capacity must be a whole number from 1 to 1000.');
		}
		if (array_key_exists('announcement_email', $patch)) {
			$emailErrors = $this->validateAnnouncementEmail($patch['announcement_email']);
			$errors = array_merge($errors, $emailErrors);
			if (!$emailErrors) {
				$normalizedEmail = $this->normalizeAnnouncementEmail($patch['announcement_email']);
				$patch['announcement_email'] = array_intersect_key($normalizedEmail, $patch['announcement_email']);
			}
		}
		if (array_key_exists('announcement_sms', $patch)) {
			$errors = array_merge($errors, $this->validateAnnouncementSms($patch['announcement_sms'], true));
		}
		if (array_key_exists('outbound_voice', $patch)) {
			$voiceErrors = $this->validateOutboundVoice($patch['outbound_voice'], true);
			$errors = array_merge($errors, $voiceErrors);
			if (empty($voiceErrors)) {
				// Allocate identities only at an explicit creation boundary. Retain
				// patch semantics: changing Enabled must not clear saved recipients.
				$normalizedVoice = $this->normalizeOutboundVoice($patch['outbound_voice'], true);
				$patch['outbound_voice'] = array_intersect_key($normalizedVoice, $patch['outbound_voice']);
			}
		}
		if (array_key_exists('phone_device_limit', $patch)
			&& (!is_int($patch['phone_device_limit']) || $patch['phone_device_limit'] < 1 || $patch['phone_device_limit'] > 1000)) {
			$errors[] = _('Phone capacity must be a whole number from 1 to 1000 registered device contacts.');
		}
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
		$integerFields = ['nws_tts_volume', 'announcement_tts_volume', 'tts_max_seconds', 'announcement_timeout_seconds', 'log_retention_days', 'generated_media_cache_mib', 'paging_answer_timeout', 'desktop_client_limit', 'phone_device_limit'];
		$stringFields = [
			'system_notification_emails', 'mail_to', 'mail_from_local_part', 'mail_from_domain', 'quiet_hours_start', 'quiet_hours_end',
			'nws_api_base_url', 'nws_zone', 'alert_email_subject', 'alert_email_body', 'test_email_subject',
			'test_email_body', 'opening_tone', 'closing_tone', 'nws_opening_tone', 'nws_closing_tone',
			'nws_piper_voice', 'announcement_piper_voice', 'announcement_timeout_mode', 'desktop_minimum_version',
		];
		$listFields = ['alert_recipients', 'quiet_critical_events', 'nws_zones', 'discord_webhooks', 'generic_webhooks', 'announcement_webhooks', 'announcement_groups', 'test_profiles'];
		$objectFields = ['xweather', 'control_api', 'sipnotify', 'updates', 'outbound_voice', 'announcement_email', 'announcement_sms'];

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
				'integer' => ['radius_miles', 'query_interval_minutes', 'adaptive_grace_minutes', 'adaptive_fallback_minutes', 'tts_volume'],
				'array' => ['recipients', 'groups'],
				'string' => ['provider', 'client_id', 'client_secret', 'location', 'adaptive_gate_failure_policy', 'adaptive_nws_zone_id', 'opening_tone', 'closing_tone', 'all_clear', 'strike_type', 'quiet_hours_start', 'quiet_hours_end'],
			],
			'control_api' => [
				'boolean' => ['enabled', 'ip_allowlist_enabled', 'rate_limit_enabled', 'audit_syslog'],
				'integer' => ['rate_limit_per_minute', 'audit_retention_days'],
				'array' => [],
				'string' => ['api_key', 'base_url', 'ip_allowlist'],
			],
			'sipnotify' => [
				'boolean' => [],
				'integer' => [],
				'array' => ['format_overrides', 'device_format_overrides'],
				'string' => ['pbx_host', 'base_url', 'media_scheme', 'media_base_url'],
			],
			'updates' => [
				'boolean' => ['github_enabled'],
				'integer' => ['rollout_delay_hours'],
				'array' => [],
				'string' => ['repository', 'channel', 'pinned_version', 'window_start', 'window_end'],
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
				$requiresList = !($objectField === 'sipnotify' && in_array($key, ['format_overrides', 'device_format_overrides'], true));
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

		if (is_string($patch['xweather']['strike_type'] ?? null)
			&& !in_array($patch['xweather']['strike_type'], ['cloud_to_ground', 'cloud_to_cloud', 'both'], true)) {
			$errors[] = _('xweather.strike_type must be cloud_to_ground, cloud_to_cloud, or both.');
		}
		if (is_array($patch['nws_zones'] ?? null) && array_is_list($patch['nws_zones'])) {
			foreach ($patch['nws_zones'] as $index => $zone) {
				if (!is_array($zone) || (!empty($zone) && array_is_list($zone))) {
					$errors[] = sprintf(_('nws_zones[%d] must be a JSON object.'), $index);
					continue;
				}
				$zoneAllowed = [
					'id', 'name', 'site_id', 'zone', 'extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids',
					'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_critical_events',
					'discord_webhook_ids', 'generic_webhook_ids',
				];
				foreach (array_keys($zone) as $key) {
					if (!in_array($key, $zoneAllowed, true)) {
						$errors[] = sprintf(_('Unknown Control API config field: nws_zones[%d].%s.'), $index, (string)$key);
					}
				}
				foreach (['id', 'name', 'zone', 'quiet_hours_start', 'quiet_hours_end'] as $key) {
					if (array_key_exists($key, $zone) && !is_string($zone[$key])) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a JSON string.'), $index, $key);
					}
				}
				if (array_key_exists('quiet_hours_enabled', $zone)) {
					if (!is_bool($zone['quiet_hours_enabled'])) {
						$errors[] = sprintf(_('nws_zones[%d].quiet_hours_enabled must be a JSON boolean.'), $index);
					} else {
						$patch['nws_zones'][$index]['quiet_hours_enabled'] = $zone['quiet_hours_enabled'] ? '1' : '0';
					}
				}
				foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids', 'quiet_critical_events', 'discord_webhook_ids', 'generic_webhook_ids'] as $key) {
					if (array_key_exists($key, $zone) && (!is_array($zone[$key]) || !array_is_list($zone[$key]))) {
						$errors[] = sprintf(_('nws_zones[%d].%s must be a JSON array.'), $index, $key);
					}
				}
			}
		}
		if (is_array($patch['announcement_groups'] ?? null)) {
			$errors = array_merge($errors, $this->validateAnnouncementGroupFields($patch['announcement_groups']));
		}
		$errors = array_merge($errors, $this->validateXweatherAdaptivePolicyInput(is_array($patch['xweather'] ?? null) ? $patch['xweather'] : []));
		if (is_array($patch['xweather']['groups'] ?? null) && array_is_list($patch['xweather']['groups'])) {
			if (count($patch['xweather']['groups']) > 5) {
				$errors[] = _('xweather.groups is limited to five entries.');
			}
			foreach ($patch['xweather']['groups'] as $index => $group) {
				if (!is_array($group) || (!empty($group) && array_is_list($group))) {
					$errors[] = sprintf(_('xweather.groups[%d] must be a JSON object.'), $index);
					continue;
				}
				$groupAllowed = [
					'id', 'name', 'site_id', 'enabled', 'adaptive_nws_zone_id', 'location', 'radius_miles',
					'extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids', 'all_clear', 'all_clear_minutes',
					'strike_type', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end',
				];
				foreach (array_keys($group) as $key) {
					if (!in_array($key, $groupAllowed, true)) {
						$errors[] = sprintf(_('Unknown Control API config field: xweather.groups[%d].%s.'), $index, (string)$key);
					}
				}
				foreach (['id', 'name', 'adaptive_nws_zone_id', 'location', 'all_clear', 'strike_type', 'quiet_hours_start', 'quiet_hours_end'] as $key) {
					if (array_key_exists($key, $group) && !is_string($group[$key])) {
						$errors[] = sprintf(_('xweather.groups[%d].%s must be a JSON string.'), $index, $key);
					}
				}
				if (is_string($group['strike_type'] ?? null)
					&& !in_array($group['strike_type'], ['cloud_to_ground', 'cloud_to_cloud', 'both'], true)) {
					$errors[] = sprintf(_('xweather.groups[%d].strike_type must be cloud_to_ground, cloud_to_cloud, or both.'), $index);
				}
				if (array_key_exists('enabled', $group)) {
					if (!is_bool($group['enabled'])) {
						$errors[] = sprintf(_('xweather.groups[%d].enabled must be a JSON boolean.'), $index);
					} else {
						$patch['xweather']['groups'][$index]['enabled'] = $group['enabled'] ? '1' : '0';
					}
				}
				if (array_key_exists('quiet_hours_enabled', $group)) {
					if (!is_bool($group['quiet_hours_enabled'])) {
						$errors[] = sprintf(_('xweather.groups[%d].quiet_hours_enabled must be a JSON boolean.'), $index);
					} else {
						$patch['xweather']['groups'][$index]['quiet_hours_enabled'] = $group['quiet_hours_enabled'] ? '1' : '0';
					}
				}
				if (array_key_exists('radius_miles', $group) && !is_int($group['radius_miles'])) {
					$errors[] = sprintf(_('xweather.groups[%d].radius_miles must be a JSON integer.'), $index);
				}
				if (array_key_exists('all_clear_minutes', $group)
					&& (!is_int($group['all_clear_minutes']) || $group['all_clear_minutes'] < 5 || $group['all_clear_minutes'] > 120)) {
					$errors[] = sprintf(_('xweather.groups[%d].all_clear_minutes must be a JSON integer between 5 and 120.'), $index);
				}
				foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients'] as $key) {
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
		$url = $settings['control_api']['base_url'] ?? '';
		$port = SlsAdvertisedAddress::savedPort(is_string($url) && stripos($url, 'https://') === 0 ? $url : '', $host, '/api/sls-mass-notify', 443);
		return SlsAdvertisedAddress::url('https', $host, $port, '/api/sls-mass-notify');
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
			'pbx_timezone' => '',
			'page_group' => '',
			'alert_recipients' => [],
			'system_notification_emails' => '',
			'mail_to' => '',
			'discord_webhook_url' => '',
			'discord_webhooks' => [],
			'generic_webhooks' => [],
			'announcement_webhooks' => [],
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
			'announcement_pronunciation' => [],
			'nws_tts_volume' => 25,
			'announcement_tts_volume' => 25,
			'announcement_cooldown_seconds' => self::ANNOUNCEMENT_COOLDOWN_SECONDS,
			'announcement_timeout_mode' => 'none',
			'announcement_timeout_seconds' => 300,
			'paging_answer_timeout' => 5,
			'live_paging' => \SLS\MassNotify\LivePagingConfig::defaults(),
			'incident_workflows' => \SLS\MassNotify\IncidentConfig::defaults(),
			'labs_safety' => \SLS\MassNotify\LabsSafety::defaults(),
			'enterprise_operations' => \SLS\MassNotify\EnterpriseOperationsConfig::defaults(),
			'enterprise_cluster' => \SLS\MassNotify\EnterpriseClusterConfig::defaults(),
			'enterprise_identity' => \SLS\MassNotify\EnterpriseIdentityConfig::defaults(),
			'directory_sync' => \SLS\MassNotify\DirectoryConfig::defaults(),
			'subscriber_browser' => \SLS\MassNotify\SubscriberConfig::defaults(),
			'enterprise_integrations' => \SLS\MassNotify\EnterpriseIntegrationsConfig::defaults(),
			'location_directory' => \SLS\MassNotify\LocationDirectory::defaults(),
			'device_acceptance' => \SLS\MassNotify\DeviceAcceptance::normalize([]),
			'test_profiles' => [],
			'log_retention_days' => 90,
			'generated_media_cache_mib' => 512,
			'desktop_auth_key' => $this->generateDesktopAuthKey(),
			'desktop_client_limit' => 25,
			'desktop_minimum_version' => '1.10.0',
			'phone_device_limit' => 25,
			'outbound_voice' => $this->defaultOutboundVoice(),
			'announcement_email' => $this->defaultAnnouncementEmail(),
			'announcement_sms' => $this->defaultAnnouncementSms(),
			'desktop_clients' => [
				$this->defaultDesktopClient('SLS Desktop App'),
			],
			'ami' => [
				'username' => 'slsmassnotify',
				'password' => $this->generateApiKey(),
				'host' => $this->detectAmiHost(),
				'port' => $this->detectAmiPort(),
			],
			'updates' => \SLS\MassNotify\UpdatePolicy::defaults(),
			'control_api' => [
				'enabled' => '0',
				'api_key' => $this->generateApiKey(),
				'credentials' => [],
				'base_url' => 'https://' . $this->detectPbxHost() . '/api/sls-mass-notify',
				'ip_allowlist_enabled' => '0',
				'ip_allowlist' => '',
				'rate_limit_enabled' => '0',
				'audit_syslog' => '0',
				'rate_limit_per_minute' => 60,
				'audit_retention_days' => 30,
			],
			'api_network' => ['trusted_proxy_cidrs' => []],
			'media_access' => \SLS\MassNotify\MediaAccess::defaults(),
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
				'adaptive_gate_failure_policy' => 'standby',
				'adaptive_fallback_minutes' => 30,
				'adaptive_nws_zone_id' => '',
				'tts_volume' => 25,
				'opening_tone' => self::DEFAULT_LIGHTNING_OPENING_TONE,
				'closing_tone' => '',
				'all_clear' => 'none',
				'strike_type' => 'cloud_to_ground',
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
				if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON) !== null) {
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
		if (method_exists($this, 'automationDependencyChecks')) { foreach ($this->automationDependencyChecks($settings) as $check) { if ($check['state'] !== 'ok') { throw new \DomainException($check['detail']); } } }
		$this->ensurePluginDataDir();
		$lock = $this->acquireSettingsLock(true);
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
				if ($this->configurationPathMetadata(self::PENDING_SETTINGS_JSON) !== null) {
					$this->loadSettingsFile(self::PENDING_SETTINGS_JSON);
				}
			}
			if (!$replaceSchedules && $this->configurationPathMetadata(self::SETTINGS_JSON) !== null) {
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
		$this->assertEnterpriseActivationSafe($settings);
		$previous = $this->configurationPathMetadata($path);
		if ($previous !== null && (($previous['mode'] & 0170000) !== 0100000 || $previous['nlink'] !== 1)) {
			throw new \FreePBX\modules\SlsConfigurationWriteException(_('The configuration destination is not a safe regular file. Run protected repair before saving settings.'));
		}
		$active = $this->loadSettingsFile(self::SETTINGS_JSON);
		if ($path !== self::SETTINGS_JSON && $previous !== null) {
			$this->loadSettingsFile($path);
		}
		$this->assertDesktopCapacityChange($settings, $active);
		if ($path === self::SETTINGS_JSON) {
			$this->assertLivePagingPromptsReady($settings);
		}
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \FreePBX\modules\SlsConfigurationWriteException(_('Unable to encode Mass Notifications settings.'));
		}
		if (strlen($json) + 1 > self::NATIVE_BACKUP_MAX_CONFIG_BYTES) {
			throw new \DomainException(sprintf(_('The configuration is %.2f MiB, exceeding the %.0f MiB runtime and backup limit. Use saved groups instead of repeating large recipient lists in schedules, then save again. No settings were written.'), (strlen($json) + 1) / 1048576, self::NATIVE_BACKUP_MAX_CONFIG_BYTES / 1048576));
		}
		$encrypted = SlsConfigCrypto::encode($settings);
		if ($backupApplied) {
			$this->backupAppliedSettings();
		}
		$tmpSettings = $path . '.tmp.' . bin2hex(random_bytes(16));
		$oldMask = umask(0077);
		try { $handle = @fopen($tmpSettings, 'x+b'); }
		finally { umask($oldMask); }
		if ($handle === false) {
			throw new \FreePBX\modules\SlsConfigurationWriteException(_('Unable to create a private configuration staging file. Check data-directory permissions and free space; existing settings were preserved.'));
		}
		try {
			$contents = $encrypted;
			$written = 0;
			while ($written < strlen($contents)) {
				$count = fwrite($handle, substr($contents, $written));
				if ($count === false || $count === 0) {
					throw new \FreePBX\modules\SlsConfigurationWriteException(_('Configuration write was incomplete. Check free space and filesystem errors; existing settings were preserved.'));
				}
				$written += $count;
			}
			$this->setPrivateOwnership($tmpSettings);
			if (!fflush($handle) || !fsync($handle)) {
				throw new \FreePBX\modules\SlsConfigurationWriteException(_('The filesystem could not synchronize the new configuration. Existing settings were preserved; check storage health.'));
			}
			$opened = fstat($handle);
			clearstatcache(true, $tmpSettings);
			$staged = @lstat($tmpSettings);
			if (!is_array($staged) || ($staged['mode'] & 0170000) !== 0100000 || $staged['nlink'] !== 1
				|| $staged['dev'] !== $opened['dev'] || $staged['ino'] !== $opened['ino']) {
				throw new \FreePBX\modules\SlsConfigurationWriteException(_('The configuration staging file changed unexpectedly. Existing settings were preserved; check protected storage permissions.'));
			}
			if (!@rename($tmpSettings, $path)) {
				throw new \FreePBX\modules\SlsConfigurationWriteException(_('Unable to replace the protected configuration. Existing settings were preserved; check data-directory permissions and filesystem errors.'));
			}
			// Replacement has happened even if directory synchronization fails.
			// Never retain an optimistic-concurrency fingerprint of the old file.
			unset($this->normalizedSettingsCache[$path]);
			$this->rememberSettingsFingerprint($path);
			$directory = @fopen(dirname($path), 'r');
			if ($directory === false) {
				throw new \FreePBX\modules\SlsConfigurationWriteException(_('Settings were replaced, but the configuration directory could not be opened for synchronization. Check storage health before making another change.'), true);
			}
			try {
				if (!fsync($directory)) {
					throw new \FreePBX\modules\SlsConfigurationWriteException(_('Settings were replaced, but the filesystem could not confirm durable directory storage. Check storage health before making another change.'), true);
				}
			} finally { fclose($directory); }
		} finally {
			fclose($handle);
			if (is_file($tmpSettings) || is_link($tmpSettings)) { @unlink($tmpSettings); }
		}
	}

	private function acquireSettingsLock($quiesceAnnouncements = false)
	{
		// Always acquire activity before settings. Workers hold shared activity
		// only while sending; independent workers do not block one another.
		$activity = $quiesceAnnouncements ? $this->acquireAnnouncementActivityLock(true, 60) : null;
		$handle = null;
		try {
			$handle = $this->acquireNativeBackupFileLock(
				self::SETTINGS_LOCK, _('Unable to lock the Mass Notifications configuration.'), 60
			);
			if (is_resource($activity)) {
				$this->settingsActivityLocks[(int)$handle] = $activity;
			}
			return $handle;
		} catch (\Throwable $error) {
			if (is_resource($handle)) {
				fclose($handle);
			}
			$this->releaseNativeBackupFileLock($activity);
			throw $error;
		}
	}

	private function releaseSettingsLock($handle)
	{
		if (is_resource($handle)) {
			$activity = $this->settingsActivityLocks[(int)$handle] ?? null;
			unset($this->settingsActivityLocks[(int)$handle]);
			flock($handle, LOCK_UN);
			fclose($handle);
			$this->releaseNativeBackupFileLock($activity);
		}
	}

	private function backupAppliedSettings()
	{
		if (!is_readable(self::SETTINGS_JSON)) {
			return;
		}
		$current = SlsConfigCrypto::encode(SlsConfigCrypto::readFile(self::SETTINGS_JSON));
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
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
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
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output, $status);
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
file_flags = os.O_RDWR | os.O_CLOEXEC | os.O_CREAT | os.O_NONBLOCK | getattr(os, 'O_NOFOLLOW', 0)
parent_fd = os.open('/', directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], file_flags, 0o640, dir_fd=parent_fd)
    try:
        metadata = os.fstat(file_fd)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
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
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output, $status);
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
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg(sprintf('%o', $mode)) . ' 2>/dev/null', $output, $status);
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
    if relative in ('event-log-recovery', 'sms', 'recovery-archives') or relative.startswith(('event-log-recovery/', 'sms/', 'recovery-archives/', '.freepbx-restore-stage-')):
        return 0o700
    if relative in ('', 'sipnotify', 'config-backups', 'piper'):
        return 0o750
    return 0o755

def file_mode(relative):
    if profile == 'web':
        return 0o644
    if relative.startswith(('event-log-recovery/', 'sms/', 'recovery-archives/', '.freepbx-restore-stage-')):
        return 0o600
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
            raise RuntimeError('runtime tree contains a symbolic link: ' + repr(child_relative)[:220])
        if profile == 'data' and child_relative == 'piper/venv':
            # The compatibility venv is deliberately root-owned and contains a
            # root-owned wrapper link into the executable runtime. Web/Asterisk
            # delivery must neither traverse nor chown that trust boundary.
            # Accept only the exact non-writable root-owned directory created by
            # install/repair; anything writable or differently owned fails closed.
            if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) & 0o022:
                raise RuntimeError('unsafe Piper compatibility directory: piper/venv must be root:root and not group/world writable')
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
        raise RuntimeError('runtime tree contains a special file: ' + repr(child_relative)[:220])

try:
    secure_directory(root_fd)
    os.fchown(root_fd, account.pw_uid, account.pw_gid)
    os.fchmod(root_fd, directory_mode(''))
except (OSError, RuntimeError) as error:
    print('SLS runtime safety: ' + str(error), file=sys.stderr)
    raise SystemExit(1)
finally:
    os.close(root_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($profile) . ' 2>&1', $output, $status);
		if ($status !== 0) {
			$reason = 'Runtime tree could not be opened or inspected; check the managed path and its parent permissions.';
			foreach ($output as $line) {
				if (strpos($line, 'SLS runtime safety: ') === 0) { $reason = $this->sanitizeScheduleText($line, 300, true); }
			}
			throw new \RuntimeException(_('Unable to secure a Mass Notifications runtime tree without following symbolic links.') . ' ' . $reason);
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
		$smsDependenciesMissing = !extension_loaded('pdo_sqlite') || !function_exists('curl_init');
		$sodiumMissing = !function_exists('sodium_crypto_pwhash') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
		$fontMissing = !is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
		if ((!empty($missing) || $mbstringMissing || $posixMissing || $opensslMissing || $sodiumMissing || $smsDependenciesMissing || $fontMissing) && is_executable('/usr/bin/apt-get')) {
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update');
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y curl wget ca-certificates gnupg python3 python3-venv python3-pip sox imagemagick fonts-dejavu-core tar php-cli php-common php-mbstring php-sqlite3 php-curl cron util-linux coreutils apache2');
		}

		$missing = array_values(array_filter($required, static function ($path) {
			return !is_executable($path);
		}));
		$mbstringMissing = !extension_loaded('mbstring') || !function_exists('mb_strlen') || !function_exists('mb_substr');
		$posixMissing = !function_exists('posix_getpwnam');
		$opensslMissing = !extension_loaded('openssl') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt');
		$smsDependenciesMissing = !extension_loaded('pdo_sqlite') || !function_exists('curl_init');
		$sodiumMissing = !function_exists('sodium_crypto_pwhash') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
		$fontMissing = !is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
		if (!empty($missing) || $mbstringMissing || $posixMissing || $opensslMissing || $sodiumMissing || $smsDependenciesMissing || $fontMissing) {
			$missingLabels = array_unique(array_merge(
				$missing,
				$mbstringMissing ? ['PHP mbstring'] : [],
				$posixMissing ? ['PHP POSIX'] : [],
				$opensslMissing ? ['PHP OpenSSL'] : [],
				$sodiumMissing ? ['PHP Sodium (encrypted backups)'] : [],
				$smsDependenciesMissing ? ['PHP PDO SQLite and cURL (SMS delivery)'] : [],
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
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg($link) . ' ' . escapeshellarg(self::SOUNDS_DIR) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('The Asterisk sound path exists but is not the expected Mass Notifications link. Remove or relocate the conflicting path, then run Repair Installation.'));
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
		$lastRun = (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionCooldown();

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
		$this->writeAnnouncementCooldownTimestamp(time());
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
		exec("/usr/bin/timeout --kill-after=1 5 /usr/sbin/asterisk -rx 'pjsip show contacts' 2>/dev/null", $output);
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
		foreach ([self::PENDING_SETTINGS_JSON, self::LEGACY_PENDING_SETTINGS_JSON, self::LEGACY_OLD_PENDING_SETTINGS_JSON] as $candidate) {
			if ($this->configurationPathMetadata($candidate) !== null) {
				$settings = $this->normalizeSettings($this->loadSettingsFile($candidate));
				break;
			}
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
		if ($this->configurationPathMetadata($path) === null) {
			return 'missing';
		}
		try {
			return hash('sha256', $this->readNativeBackupFile($path, SlsConfigCrypto::MAX_FILE_BYTES,
				_('The protected configuration could not be read safely.')));
		} catch (\Throwable $error) { return 'unreadable'; }
	}

	private function rememberSettingsFingerprint($path)
	{
		$this->settingsReadFingerprints[$path] = $this->settingsFileFingerprint($path);
	}

	/** Missing paths are safe only after their existing ancestors are searchable. */
	private function configurationPathMetadata($path)
	{
		if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > 4096) {
			throw new \RuntimeException(_('The protected configuration path is invalid.'));
		}
		$parts = explode('/', substr($path, 1));
		if (count($parts) > 64 || array_intersect($parts, ['', '.', '..'])) {
			throw new \RuntimeException(_('The protected configuration path is invalid.'));
		}
		$parent = '/';
		foreach ($parts as $index => $part) {
			clearstatcache(true, $parent);
			if (!is_readable($parent) || !is_executable($parent)) {
				throw new \RuntimeException(_('The configuration directory is inaccessible. Existing settings were preserved; check its owner and permissions.'));
			}
			$current = rtrim($parent, '/') . '/' . $part;
			clearstatcache(true, $current);
			$metadata = @lstat($current);
			if ($metadata === false) { return null; }
			if ($index === count($parts) - 1) { return $metadata; }
			if (($metadata['mode'] & 0170000) !== 0040000) {
				throw new \RuntimeException(_('The protected configuration path contains a symbolic link or an invalid directory. Existing settings were preserved.'));
			}
			$parent = $current;
		}
		throw new \RuntimeException(_('The protected configuration path is invalid.'));
	}

	private function loadSettingsFile($path)
	{
		$settings = $this->getDefaultSettings();
		$candidates = [$path];
		if ($path === self::SETTINGS_JSON) {
			$candidates[] = self::LEGACY_SETTINGS_JSON;
			$candidates[] = self::LEGACY_OLD_SETTINGS_JSON;
		}
		$present = false;
		foreach ($candidates as $candidate) {
			if ($this->configurationPathMetadata($candidate) !== null) { $path = $candidate; $present = true; break; }
		}
		if ($present) {
			$decoded = SlsConfigCrypto::readFile($path);
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
		\SLS\MassNotify\RuntimeState::running($settings);
		$settings = $this->migrateConfigCompatibility($settings);
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
		$settings['announcement_webhooks'] = $this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement');
		$settings['discord_webhook_url'] = $this->firstEnabledWebhookUrl($settings['discord_webhooks']);
		$settings['nws_api_base_url'] = $this->normalizeNwsApiBaseUrl((string)($settings['nws_api_base_url'] ?? 'https://api.weather.gov')) ?: 'https://api.weather.gov';
		$settings['nws_zone'] = $this->normalizeNwsZone((string)($settings['nws_zone'] ?? ''));
		$settings['alert_recipients'] = $this->normalizeRecipientExtensions($settings['alert_recipients'] ?? $this->getDefaultSettings()['alert_recipients']);
		$settings['nws_zones'] = $this->normalizeNwsZoneGroups(
			$settings['nws_zones'] ?? [],
			$settings['nws_zone'],
			$settings['alert_recipients'],
			[
				'enabled' => $settings['quiet_hours_enabled'] ?? '0',
				'start' => $settings['quiet_hours_start'] ?? '21:00',
				'end' => $settings['quiet_hours_end'] ?? '06:00',
				'critical_events' => $settings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents(),
				'discord_webhook_ids' => array_column(array_values(array_filter((array)($settings['discord_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); })), 'id'),
				'generic_webhook_ids' => array_column(array_values(array_filter((array)($settings['generic_webhooks'] ?? []), static function ($row) { return is_array($row) && !empty($row['enabled']); })), 'id'),
			]
		);
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
		$settings['paging_answer_timeout'] = $this->normalizePagingAnswerTimeout($settings['paging_answer_timeout'] ?? 5);
		$settings['test_profiles'] = $this->normalizeTestProfiles($settings['test_profiles'] ?? []);
		$settings['log_retention_days'] = $this->normalizeRetentionDays($settings['log_retention_days'] ?? 90);
		$settings['generated_media_cache_mib'] = $settings['generated_media_cache_mib'] ?? 512;
		unset($settings['desktop_api_token']);
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$settings['ami'] = [
			'username' => $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami'),
			// Never synthesize a different AMI secret while merely reading an existing
			// config. A transient value would configure Asterisk differently from the
			// protected file and make runtime authentication fail unpredictably.
			'password' => $this->normalizeEndpointPassword($ami['password'] ?? ''),
			'host' => $this->normalizeAmiHost($ami['host'] ?? $this->detectAmiHost()),
			'port' => $this->normalizeInt($ami['port'] ?? $this->detectAmiPort(), 1, 65535, $this->detectAmiPort()),
		];
		$updates = is_array($settings['updates'] ?? null) ? $settings['updates'] : [];
		$settings['updates'] = \SLS\MassNotify\UpdatePolicy::normalize($updates);
		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		$apiKey = trim((string)($control['api_key'] ?? ''));
		if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/', $apiKey)) {
			$apiKey = $this->generateApiKey();
		}
		$settings['control_api'] = [
			'enabled' => empty($control['enabled']) ? '0' : '1',
			'api_key' => $apiKey,
			'credentials' => \SLS\MassNotify\ApiSecurity::credentials($control['credentials'] ?? []),
			'base_url' => $this->getControlApiUrl($settings),
			'ip_allowlist_enabled' => empty($control['ip_allowlist_enabled']) ? '0' : '1',
			'ip_allowlist' => $this->normalizeIpAllowlist((string)($control['ip_allowlist'] ?? '')),
			'rate_limit_enabled' => empty($control['rate_limit_enabled']) ? '0' : '1',
			'audit_syslog' => ($control['audit_syslog'] ?? '0') === '1' ? '1' : '0',
			'rate_limit_per_minute' => $this->normalizeInt($control['rate_limit_per_minute'] ?? 60, 1, 600, 60),
			'audit_retention_days' => 30,
		];
		$settings['api_network'] = \SLS\MassNotify\ApiSecurity::network($settings['api_network'] ?? []);
		$settings['media_access'] = \SLS\MassNotify\MediaAccess::normalize(array_key_exists('media_access', $settings) ? $settings['media_access'] : []);
		$settings['desktop_auth_key'] = $this->normalizeDesktopAuthKey($settings['desktop_auth_key'] ?? '');
		$desktopClientSource = array_key_exists('desktop_clients', $settings) ? $settings['desktop_clients'] : [$this->defaultDesktopClient('SLS Desktop App')];
		$settings['desktop_client_limit'] = $this->normalizeInt($settings['desktop_client_limit'] ?? 25, 1, 1000, 25);
		$settings['phone_device_limit'] = $this->normalizeInt($settings['phone_device_limit'] ?? 25, 1, 1000, 25);
		$settings['outbound_voice'] = $this->normalizeOutboundVoice(array_key_exists('outbound_voice', $settings) ? $settings['outbound_voice'] : []);
		$settings['announcement_email'] = $this->normalizeAnnouncementEmail(array_key_exists('announcement_email', $settings) ? $settings['announcement_email'] : []);
		$settings['desktop_clients'] = $this->normalizeDesktopClients($desktopClientSource, $settings);
		$settings['announcement_groups'] = $this->normalizeAnnouncementGroups($settings['announcement_groups'] ?? []);
		$settings['live_paging'] = $this->normalizeLivePagingSettings($settings['live_paging'] ?? []);
		$settings['incident_workflows'] = \SLS\MassNotify\IncidentConfig::normalize($settings['incident_workflows'] ?? []);
		foreach (['labs_safety'=>\SLS\MassNotify\LabsSafety::class,'enterprise_operations'=>\SLS\MassNotify\EnterpriseOperationsConfig::class,
			'enterprise_cluster'=>\SLS\MassNotify\EnterpriseClusterConfig::class,'enterprise_identity'=>\SLS\MassNotify\EnterpriseIdentityConfig::class,
			'directory_sync'=>\SLS\MassNotify\DirectoryConfig::class,'subscriber_browser'=>\SLS\MassNotify\SubscriberConfig::class,
			'enterprise_integrations'=>\SLS\MassNotify\EnterpriseIntegrationsConfig::class] as $key=>$class) {
			if (array_key_exists($key,$settings)) { $settings[$key]=$class::normalize($settings[$key]); }
		}
		$settings['device_acceptance'] = \SLS\MassNotify\DeviceAcceptance::normalize($settings['device_acceptance'] ?? []);
		if (array_key_exists('operator_access', $settings)) { $settings['operator_access'] = \SLS\MassNotify\OperatorAccess::normalize($settings['operator_access']); }
		if (array_key_exists('automations', $settings)) { $settings['automations'] = \SLS\MassNotify\AutomationConfig::normalize($settings['automations']); }
		$settings['location_directory'] = \SLS\MassNotify\LocationDirectory::normalize(array_key_exists('location_directory', $settings) ? $settings['location_directory'] : []);
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
			'media_scheme' => 'https',
			'media_base_url' => 'https://' . $host . '/sls_mass_notify',
			'format_overrides' => [],
			'device_format_overrides' => [],
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
		return in_array($value, ['none', 'every_7_days', 'every_14_days', 'calendar'], true) ? $value : 'none';
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

	private function buildScheduledOccurrences($scheduleId, array $occurrenceInputs, $recurrenceMode, \DateTimeZone $timezone, array $existingRunTimes, $minimumRunAt, $maximumRunAt, array $calendar = [])
	{
		$recurrenceMode = $this->normalizeScheduleRecurrenceMode($recurrenceMode);
		if ($recurrenceMode === 'calendar') {
			return \SLS\MassNotify\ScheduleCalendar::build((string)$scheduleId, $occurrenceInputs, $calendar, $timezone,
				$existingRunTimes, (int)$minimumRunAt, (int)$maximumRunAt, self::MAX_SCHEDULE_OCCURRENCES,
				function ($local, $zone) { return $this->resolveScheduleLocalDateTime($local, $zone); });
		}
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

	private function validateScheduledAnnouncementRecurrences(array $schedules, array $groups = [])
	{
		$errors = [];
		foreach ($schedules as $schedule) {
			if (is_array($schedule)) { $errors = array_merge($errors, array_map('_', \SLS\MassNotify\SchedulePresentation::latenessErrors($schedule))); }
			if (is_array($schedule)) { $errors = array_merge($errors, \SLS\MassNotify\SchedulePresentation::textErrors($schedule)); }
			if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('email_recipient_ids', $schedule['targets'])) {
				$errors = array_merge($errors, $this->validateAnnouncementEmailRecipientIds($schedule['targets']['email_recipient_ids']));
			}
			if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('webhook_ids', $schedule['targets'])) {
				$errors = array_merge($errors, $this->validateAudienceWebhookIds($schedule['targets']['webhook_ids']));
			}
			if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('sms_recipient_ids', $schedule['targets'])) {
				$errors = array_merge($errors, $this->validateSmsRecipientIds($schedule['targets']['sms_recipient_ids']));
			}
			$hasVoiceTargets = false;
			if (is_array($schedule) && is_array($schedule['targets'] ?? null) && array_key_exists('voice_recipient_ids', $schedule['targets'])) {
				$voiceIds = $schedule['targets']['voice_recipient_ids'];
				$errors = array_merge($errors, $this->validateVoiceRecipientIds($voiceIds));
				$hasVoiceTargets = !empty($voiceIds);
			}
			foreach ($groups as $group) {
				if (is_array($group) && !empty($group['voice_recipient_ids'])
					&& in_array($group['id'] ?? '', (array)($schedule['targets']['groups'] ?? []), true)) { $hasVoiceTargets = true; }
			}
			if ($hasVoiceTargets && $this->normalizeAnnouncementAudioMode($schedule['delivery']['audio_mode'] ?? 'none') === 'none') {
				$errors[] = _('A scheduled announcement has external voice recipients without audio enabled.');
			}
			if (!is_array($schedule) || !array_key_exists('recurrence', $schedule)) {
				continue;
			}
			$recurrence = $schedule['recurrence'];
			if (!is_array($recurrence) || (!empty($recurrence) && array_is_list($recurrence))) {
				$errors[] = _('One or more scheduled-announcement recurrence settings are invalid.');
				continue;
			}
			$modeValue = strtolower(trim((string)($recurrence['mode'] ?? 'none')));
			if (!in_array($modeValue, ['none', 'every_7_days', 'every_14_days', 'calendar'], true)) {
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
			if ($modeValue === 'calendar') {
				$plan = $this->buildScheduledOccurrences((string)($schedule['id'] ?? ''), [$startsAtLocal], 'calendar', $timezone, [], 0,
					PHP_INT_MAX, is_array($recurrence['calendar'] ?? null) ? $recurrence['calendar'] : []);
				if ($plan['errors']) { $errors = array_merge($errors, $plan['errors']); }
				// JSON object key ordering must not change a calendar's validity.
				elseif ($plan['occurrences'] != ($schedule['occurrences'] ?? [])) {
					$errors[] = _('The calendar pattern does not match its protected occurrence list. Reopen and save the schedule to review its dates.');
				}
			} elseif (!$this->scheduleRecurrenceMatchesOccurrences($modeValue, $startsAtLocal, $timezone, (array)($schedule['occurrences'] ?? []))) {
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
				|| ($recurrenceMode !== 'calendar' && !$this->scheduleRecurrenceMatchesOccurrences($recurrenceMode, $recurrenceStartsAt, $timezone, $occurrences))) {
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
				'created_by' => $this->sanitizeScheduleText($rawSchedule['created_by'] ?? 'Scheduled announcement', 80, true),
				'updated_by' => $this->sanitizeScheduleText($rawSchedule['updated_by'] ?? '', 80, true),
				'name' => $name,
				'enabled' => empty($rawSchedule['enabled']) ? '0' : '1',
				'max_lateness_minutes' => array_key_exists('max_lateness_minutes', $rawSchedule) ? $rawSchedule['max_lateness_minutes'] : 15,
				'timezone' => $timezone->getName(),
				'recurrence' => [
					'mode' => $recurrenceMode,
					'starts_at_local' => $recurrenceStartsAt,
				] + ($recurrenceMode === 'calendar' ? ['calendar'=>$rawRecurrence['calendar'] ?? []] : []),
				'occurrences' => $occurrences,
				'message' => $message,
				'targets' => [
					'extensions' => array_values($extensions),
					'groups' => array_values($groups),
					'phones_all' => empty($targets['phones_all']) ? '0' : '1',
					'desktop_clients' => array_values($desktops),
					'desktop_all' => empty($targets['desktop_all']) ? '0' : '1',
					// Preserve exact saved IDs, including stale or invalid values, so
					// dispatch rejects them explicitly instead of silently retargeting.
					'voice_recipient_ids' => array_key_exists('voice_recipient_ids', $targets) ? $targets['voice_recipient_ids'] : [],
					'email_recipient_ids' => array_key_exists('email_recipient_ids', $targets) ? $targets['email_recipient_ids'] : [],
					'sms_recipient_ids' => $targets['sms_recipient_ids'] ?? [],
				] + (array_key_exists('webhook_ids', $targets) ? ['webhook_ids' => $targets['webhook_ids']] : []),
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
			] + (array_key_exists('operator_credential_id', $rawSchedule) ? ['operator_credential_id'=>$rawSchedule['operator_credential_id']] : []);
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
		$lock = $this->acquireSettingsLock(true);
		try {
			$active = $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
			if (!hash_equals($expectedFingerprint, $this->scheduledAnnouncementsFingerprint($active['scheduled_announcements'] ?? []))) {
				throw new \RuntimeException(_('Scheduled announcements changed while this request was being processed.'));
			}
			$pending = $this->getPendingSettings();
			if ($pending !== null && !hash_equals($expectedFingerprint, $this->scheduledAnnouncementsFingerprint($pending['scheduled_announcements'] ?? []))) {
				throw new \RuntimeException(_('A staged configuration contains a different schedule list. Apply or discard those staged changes before editing Scheduling.'));
			}
			if (($this->operatorScheduleOrigin ?? null) !== null) {
				$principal = \SLS\MassNotify\ApiSecurity::currentCredential($active, $this->operatorScheduleOrigin);
				if (!$principal || !\SLS\MassNotify\OperatorAccess::may($principal, 'schedule')) { throw new \DomainException(_('Your operator login or scheduling role changed before saving. No schedule was saved.')); }
				foreach ($schedules as $schedule) {
					if (($schedule['operator_credential_id'] ?? '') !== $this->operatorScheduleOrigin) { continue; }
					$targets = $schedule['targets'];
					$request = ['phones'=>$targets['extensions'], 'desktops'=>$targets['desktop_clients'], 'webhooks'=>$targets['webhook_ids'] ?? [], 'voice_recipient_ids'=>$targets['voice_recipient_ids'], 'email_recipient_ids'=>$targets['email_recipient_ids'], 'sms_recipient_ids'=>$targets['sms_recipient_ids']];
					if (!empty($targets['groups']) || !empty($targets['phones_all']) || !empty($targets['desktop_all']) || !\SLS\MassNotify\ApiSecurity::permitsResolvedAnnouncement($principal, $request, $active)) { throw new \DomainException(_('The saved schedule audience no longer fits your current assignments. No schedule was saved.')); }
				}
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
		$tmp = self::SCHEDULE_STATE_JSON . '.tmp.' . bin2hex(random_bytes(16));
		$previousMask = umask(0077);
		try { $handle = @fopen($tmp, 'x+b'); }
		finally { umask($previousMask); }
		if (!$handle) { throw new \RuntimeException(_('Unable to create scheduling execution state.')); }
		try {
			$encoded = $json . "\n";
			if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
				throw new \RuntimeException(_('Unable to write scheduling execution state.'));
			}
			$this->setPrivateOwnership($tmp);
			if (!fsync($handle)) { throw new \RuntimeException(_('Unable to synchronize scheduling execution state.')); }
			if (!@rename($tmp, self::SCHEDULE_STATE_JSON)) {
				throw new \RuntimeException(_('Unable to replace scheduling execution state.'));
			}
			$directory = @fopen(self::PLUGIN_DATA_DIR, 'r');
			if (!$directory) { throw new \RuntimeException(_('Unable to synchronize scheduling storage.')); }
			try {
				if (!fsync($directory)) { throw new \RuntimeException(_('Unable to synchronize scheduling storage.')); }
			} finally { fclose($directory); }
			\SLS\MassNotify\EnterpriseClusterIntegration::replicateScheduleStore($this->getActiveSettings(),$store);
		} finally {
			fclose($handle);
			if (is_file($tmp)) { @unlink($tmp); }
		}
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
		foreach (['job_id', 'deadline_at', 'request_fingerprint', 'job_state'] as $field) {
			if (array_key_exists($field, $current)) { $record[$field] = $current[$field]; }
		}
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
			if ($scheduleId === '' && !empty($record['job_id'])) {
				$scheduleId = (string)($record['schedule_id'] ?? '');
				$record['orphaned_schedule'] = true;
			}
			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $scheduleId) || (string)($record['schedule_id'] ?? '') !== $scheduleId) { continue; }
			unset($record['request_fingerprint']);
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
		$knownEmailIds = array_fill_keys(array_column($this->getAnnouncementEmailRecipients($settings), 'id'), true);
		$knownSmsIds = array_fill_keys(array_column($this->getAnnouncementSmsRecipients($settings), 'id'), true);
		$knownVoiceIds = array_fill_keys(array_column($this->getOutboundVoiceRecipients($settings), 'id'), true);
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
			$requestedEmailIds = array_key_exists('email_recipient_ids', $targets) ? $targets['email_recipient_ids'] : [];
			$emailTargetErrors = $this->validateAnnouncementEmailRecipientIds($requestedEmailIds);
			if ($emailTargetErrors) { $orphanedTarget = true; $requestedEmailIds = []; }
			$requestedSmsIds = array_key_exists('sms_recipient_ids', $targets) ? $targets['sms_recipient_ids'] : [];
			$smsTargetErrors = $this->validateSmsRecipientIds($requestedSmsIds);
			if ($smsTargetErrors) { $orphanedTarget = true; $requestedSmsIds = []; }
			$requestedWebhookIds = $targets['webhook_ids'] ?? [];
			if ($this->validateAudienceWebhookIds($requestedWebhookIds, $settings)) { $orphanedTarget = true; }
			elseif ($requestedWebhookIds) { $validRecipient = true; }
			$requestedVoiceIds = array_key_exists('voice_recipient_ids', $targets) ? $targets['voice_recipient_ids'] : [];
			$voiceTargetErrors = $this->validateVoiceRecipientIds($requestedVoiceIds);
			if ($voiceTargetErrors) { $orphanedTarget = true; }
			$requestedVoiceIds = empty($voiceTargetErrors) ? $requestedVoiceIds : [];
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
				$groupEmailIds = array_key_exists('email_recipient_ids', $knownGroups[$groupId]) ? $knownGroups[$groupId]['email_recipient_ids'] : [];
				if ($this->validateAnnouncementEmailRecipientIds($groupEmailIds)) { $orphanedTarget = true; }
				else { $requestedEmailIds = array_merge($requestedEmailIds, $groupEmailIds); }
				$groupSmsIds = array_key_exists('sms_recipient_ids', $knownGroups[$groupId]) ? $knownGroups[$groupId]['sms_recipient_ids'] : [];
				if ($this->validateSmsRecipientIds($groupSmsIds)) { $orphanedTarget = true; }
				else { $requestedSmsIds = array_merge($requestedSmsIds, $groupSmsIds); }
				if ($this->validateAudienceWebhookIds($knownGroups[$groupId]['webhook_ids'] ?? [], $settings)) { $orphanedTarget = true; }
				$groupVoiceIds = $knownGroups[$groupId]['voice_recipient_ids'] ?? [];
				if ($this->validateVoiceRecipientIds($groupVoiceIds)) {
					$orphanedTarget = true;
				} else {
					$requestedVoiceIds = array_merge($requestedVoiceIds, $groupVoiceIds);
				}
			}
			foreach ((array)($targets['desktop_clients'] ?? []) as $username) {
				if (isset($knownDesktops[$username])) {
					$validRecipient = true;
				} else {
					$orphanedTarget = true;
				}
			}
			foreach (array_unique($requestedVoiceIds) as $voiceId) {
				if (isset($knownVoiceIds[$voiceId])) {
					$validRecipient = true;
					$validPhoneTarget = true;
				} else {
					$orphanedTarget = true;
				}
			}
			foreach (array_unique($requestedEmailIds) as $emailId) {
				if (isset($knownEmailIds[$emailId])) { $validRecipient = true; }
				else { $orphanedTarget = true; }
			}
			foreach (array_unique($requestedSmsIds) as $smsId) {
				if (isset($knownSmsIds[$smsId])) { $validRecipient = true; }
				else { $orphanedTarget = true; }
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
				if (!empty($requestedVoiceIds)) {
					$warnings[] = sprintf(_('Scheduled announcement %s has external voice recipients without audio enabled'), $name);
				}
				continue;
			}
			if (!$validPhoneTarget) {
				$warnings[] = sprintf(_('Scheduled announcement %s has audio enabled without a phone or external voice target'), $name);
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
		$username = 'sls' . strtolower(bin2hex(random_bytes(12)));
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
			if (!is_array($client)) {
				continue;
			}
			if (count($clients) >= 1000) { throw new \DomainException(_('Desktop clients exceed the 1000-client storage limit.')); }
			$name = trim(preg_replace('/\s+/', ' ', (string)($client['name'] ?? '')));
			$clientId = $this->normalizeDesktopClientId($client['client_id'] ?? '');
			$username = $this->normalizeDesktopUsername($client['username'] ?? '');
			if ($name === '') {
				$name = 'Desktop App';
			}
			if ($username === '') {
				$username = $this->normalizeDesktopUsername('sls' . bin2hex(random_bytes(12)));
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
		if (count($clients) > 1000) {
			$errors[] = _('Desktop clients are limited to 1000.');
		}
			foreach (array_slice($clients, 0, 1000) as $client) {
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
		return 'cli_' . strtolower(bin2hex(random_bytes(12)));
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

	private function volumePercentToScalar($value, $fallback)
	{
		return number_format($this->normalizeTtsVolume($value, $fallback) / 100, 2, '.', '');
	}

	private function generateApiKey()
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	private function validateAudienceWebhookIds($ids, ?array $settings = null): array
	{
		if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAX_WEBHOOK_DESTINATIONS) {
			return [_('Select up to ten saved webhook destinations.')];
		}
		$seen = [];
		foreach ($ids as $id) {
			if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || isset($seen[$id])) {
				return [_('The webhook selection contains an invalid or duplicate destination. Select saved destinations.')];
			}
			$seen[$id] = true;
		}
		if ($settings !== null && $ids) {
			$known = array_column(array_filter($this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement'), static fn(array $row): bool => !empty($row['enabled'])), 'id');
			if (array_diff($ids, $known)) { return [_('A selected audience webhook is removed or disabled. Review the saved audience and delivery provider settings.')]; }
		}
		return [];
	}

	private function validateAnnouncementGroupFields(array $groups)
	{
		$errors = [];
		// These are the complete persisted fields emitted by the group editor and
		// normalizer. Selection aliases on announcement requests are not config keys.
		$allowed = ['id', 'name', 'extensions', 'desktop_clients', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids', 'webhook_ids', 'desktop_bindings', 'location_snapshot'];
		foreach ($groups as $index => $group) {
			if (!is_array($group) || (!empty($group) && array_is_list($group))) {
				$errors[] = sprintf(_('announcement_groups[%d] must be an object.'), $index);
				continue;
			}
			if (array_key_exists('location_snapshot', $group)) {
				try { \SLS\MassNotify\LocationDirectory::snapshot($group['location_snapshot'], (array)($group['desktop_clients'] ?? [])); }
				catch (\InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
			}
			$errors = array_merge($errors, $this->validateAudienceWebhookIds($group['webhook_ids'] ?? []));
			if (array_key_exists('desktop_bindings', $group)) {
				try { \SLS\MassNotify\LocationDirectory::bindings($group['desktop_bindings'], (array)($group['desktop_clients'] ?? [])); }
				catch (\InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
			}
			$errors = array_merge($errors, $this->validateVoiceRecipientIds($group['voice_recipient_ids'] ?? []));
			$errors = array_merge($errors, $this->validateAnnouncementEmailRecipientIds(array_key_exists('email_recipient_ids', $group) ? $group['email_recipient_ids'] : []));
			$errors = array_merge($errors, $this->validateSmsRecipientIds(array_key_exists('sms_recipient_ids', $group) ? $group['sms_recipient_ids'] : []));
			foreach (array_keys($group) as $field) {
				if (!in_array($field, $allowed, true)) {
					$errors[] = sprintf(_('Unknown config key: announcement_groups[%d].%s.'), $index, (string)$field);
				}
			}
		}
		return $errors;
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
		$usedIds = [];
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
			$locationSnapshot = array_key_exists('location_snapshot', $group)
				? \SLS\MassNotify\LocationDirectory::snapshot($group['location_snapshot'], (array)($group['desktop_clients'] ?? [])) : null;
			$extensions = [];
			foreach ((array)($group['extensions'] ?? []) as $extension) {
				$extension = preg_replace('/[^0-9]/', '', (string)$extension);
				if ($extension !== '' && ($locationSnapshot !== null || isset($group['desktop_bindings']) || isset($available[$extension]))) {
					$extensions[$extension] = $extension;
				}
			}
			$desktopClients = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '' && ($locationSnapshot !== null || isset($group['desktop_bindings']) || $availableDesktops === null || isset($availableDesktops[$username]))) {
					$desktopClients[$username] = $username;
				}
			}
			if (empty($extensions) && empty($desktopClients) && empty($group['voice_recipient_ids']) && empty($group['email_recipient_ids']) && empty($group['sms_recipient_ids']) && empty($group['webhook_ids'])) {
				continue;
			}
			$id = (string)($group['id'] ?? '');
			if (!preg_match('/^grp_[a-f0-9]{12,32}$/D', $id)) {
				$id = 'grp_' . substr(hash('sha256', strtolower($name) . '|' . implode(',', $extensions) . '|' . implode(',', $desktopClients) . '|' . implode(',', (array)($group['voice_recipient_ids'] ?? [])) . (!empty($group['email_recipient_ids']) ? '|email:' . implode(',', $group['email_recipient_ids']) : '') . (!empty($group['sms_recipient_ids']) ? '|sms:' . implode(',', $group['sms_recipient_ids']) : '') . (!empty($group['webhook_ids']) ? '|webhooks:' . implode(',', $group['webhook_ids']) : '')), 0, 12);
			}
			if (isset($usedIds[$id])) { continue; }
			$usedIds[$id] = true;
			$groups[] = [
				'id' => $id,
				'name' => $name,
				'extensions' => array_values($extensions),
				'desktop_clients' => array_values($desktopClients),
				'voice_recipient_ids' => array_values(array_unique((array)($group['voice_recipient_ids'] ?? []))),
				'email_recipient_ids' => array_key_exists('email_recipient_ids', $group) ? $group['email_recipient_ids'] : [],
				'sms_recipient_ids' => $group['sms_recipient_ids'] ?? [],
			] + (array_key_exists('webhook_ids', $group) ? ['webhook_ids' => $group['webhook_ids']] : [])
                + ($locationSnapshot !== null ? ['location_snapshot' => $locationSnapshot] : [])
                + (array_key_exists('desktop_bindings', $group) ? ['desktop_bindings' => \SLS\MassNotify\LocationDirectory::bindings($group['desktop_bindings'], array_values($desktopClients))] : []);
		}

		return $groups;
	}

	private function syncPendingAnnouncementGroups(array $groups, array $previousGroups)
	{
		$pending = $this->getPendingSettings();
		if ($pending === null) {
			return;
		}
		$before = array_column($previousGroups, null, 'id');
		$after = array_column($groups, null, 'id');
		$merged = [];
		foreach ((array)($pending['announcement_groups'] ?? []) as $group) {
			$id = $group['id'];
			if (isset($before[$id]) && !isset($after[$id])) { continue; }
			if (isset($after[$id]) && (!isset($before[$id]) || $after[$id] !== $before[$id])) { $group = $after[$id]; }
			$merged[$id] = $group;
		}
		foreach ($after as $id => $group) {
			if (!isset($before[$id]) || $group !== $before[$id]) { $merged[$id] = $group; }
		}
		$pending['announcement_groups'] = array_values($merged);
		\SLS\MassNotify\LivePagingConfig::resolvedGroups($this->normalizeLivePagingSettings($pending['live_paging'] ?? []), $pending['announcement_groups']);
		$this->persistPendingSettings($pending);
	}

	private function normalizeSipNotifySettings($value)
	{
		$defaults = $this->getDefaultSipNotifySettings();
		$value = is_array($value) ? $value : [];
		$host = $this->normalizePbxHost((string)($value['pbx_host'] ?? $defaults['pbx_host']));
		$advertisedApi = $value['base_url'] ?? '';
		$apiPort = SlsAdvertisedAddress::savedPort(is_string($advertisedApi) && stripos($advertisedApi, 'https://') === 0 ? $advertisedApi : '', $host, '/api/sipnotify', 443);
		$baseUrl = SlsAdvertisedAddress::url('https', $host, $apiPort, '/api/sipnotify');
		$mediaScheme = $this->normalizePhoneMediaScheme((string)($value['media_scheme'] ?? $defaults['media_scheme']));
		$mediaBaseUrl = $mediaScheme . '://' . $host . '/sls_mass_notify';
		// The phone needs the advertised port, not Apache's port behind NAT.
		// Preserve only a validated port on this PBX's existing media URL; never
		// accept another host, credentials, or an arbitrary hosted-media path.
		$advertisedUrl = $value['media_base_url'] ?? '';
		$portMatch = [];
		if (is_string($advertisedUrl)
			&& preg_match('#\Ahttps?://' . preg_quote($host, '#') . '(?::([0-9]{1,5}))?/sls_mass_notify/?\z#i', $advertisedUrl, $portMatch)
			&& isset($portMatch[1]) && (int)$portMatch[1] >= 1 && (int)$portMatch[1] <= 65535) {
			$mediaBaseUrl = $mediaScheme . '://' . $host . ':' . (int)$portMatch[1] . '/sls_mass_notify';
		}
		return [
			'pbx_host' => $host,
			'base_url' => $baseUrl,
			'media_scheme' => $mediaScheme,
			'media_base_url' => $mediaBaseUrl,
			'format_overrides' => $this->normalizeEndpointFormatOverrides($value['format_overrides'] ?? []),
			'device_format_overrides' => $this->normalizeDeviceFormatOverrides($value['device_format_overrides'] ?? []),
		];
	}

	private function normalizePhoneMediaScheme($value)
	{
		return strtolower(trim((string)$value)) === 'http' ? 'http' : 'https';
	}

	private function normalizeEndpointFormatOverrides($value)
	{
		$allowed = array_fill_keys([
			'yealink', 'yealink_text', 'yealink_image', 'cisco', 'poly', 'polycom', 'grandstream', 'fanvil',
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

	private function normalizeDeviceFormatOverrides($value)
	{
		$result = [];
		foreach (array_slice((array)$value, 0, 100, true) as $key => $row) {
			if (!is_array($row)) { continue; }
			$key = (string)($row['key'] ?? $key);
			$extension = (string)($row['extension'] ?? '');
			if (!preg_match('/^[a-f0-9]{32}$/', $key) || !preg_match('/^[0-9]{1,20}$/', $extension)) { continue; }
			$format = $this->normalizeEndpointFormatOverrides([$extension => $row['format'] ?? ''])[$extension] ?? '';
			if ($format === '') { continue; }
			$result[$key] = ['extension' => $extension, 'format' => $format,
				'label' => $this->sanitizeScheduleText($row['label'] ?? '', 80, true)];
		}
		ksort($result);
		return $result;
	}

	public function getDeviceOverrideInventory()
	{
		$devices = [];
		foreach ($this->getDetectedEndpointFormats() as $endpoint) {
			foreach ($endpoint['devices'] ?? [] as $device) {
				if (preg_match('/^[a-f0-9]{32}$/', (string)($device['key'] ?? ''))) {
					$devices[] = ['extension' => $endpoint['extension']] + $device;
				}
			}
		}
		return ['success' => $this->deviceInventoryError === '', 'message' => $this->deviceInventoryError,
			'devices' => array_slice($devices, 0, 1000)];
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
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_announcement_worker.php', $runtimeDir . '/sls_mass_notify_announcement_worker.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_automation_worker.php', $runtimeDir . '/sls_mass_notify_automation_worker.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_cluster_worker.php', $runtimeDir . '/sls_mass_notify_cluster_worker.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_cluster_effect.php', $runtimeDir . '/sls_mass_notify_cluster_effect.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_delivery_authorization.php', $runtimeDir . '/sls_mass_notify_delivery_authorization.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_panic.php', $runtimeDir . '/sls_mass_notify_panic.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_phone_agi.py', $runtimeDir . '/sls_mass_notify_phone_agi.py', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_weather_channels.php', $runtimeDir . '/sls_mass_notify_weather_channels.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_live_paging.php', $runtimeDir . '/sls_mass_notify_live_paging.php', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_test.sh', $runtimeDir . '/sls_mass_notify_test.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_update.sh', $runtimeDir . '/sls_mass_notify_update.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_maintenance.sh', $runtimeDir . '/sls_mass_notify_maintenance.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_uninstall.sh', $runtimeDir . '/sls_mass_notify_uninstall.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_install_piper_voices.sh', $runtimeDir . '/sls_mass_notify_install_piper_voices.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sign_sls_mass_notify_local_sig.sh', '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/slsconsole', '/usr/local/bin/slsconsole', 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/bin/sls_mass_notify', $runtimeDir, [
			'piper',
			'sls_mass_notify_nws_poll.sh',
			'sls_mass_notify_weather_poll.sh',
			'sls_mass_notify_schedule_worker.php',
			'sls_mass_notify_announcement_worker.php',
			'sls_mass_notify_automation_worker.php',
			'sls_mass_notify_cluster_worker.php',
			'sls_mass_notify_cluster_effect.php',
            'sls_mass_notify_delivery_authorization.php',
			'sls_mass_notify_panic.php',
			'sls_mass_notify_phone_agi.py',
			'sls_mass_notify_live_paging.php',
            'sls_mass_notify_weather_channels.php',
			'sls_mass_notify_test.sh',
			'sls_mass_notify_update.sh',
			'sls_mass_notify_maintenance.sh',
			'sls_mass_notify_uninstall.sh',
			'sls_mass_notify_install_piper_voices.sh',
		]);
		$this->copyRuntimeDirectory(__DIR__ . '/bin/sls_mass_notify', $runtimeDir, 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/api/sipnotify', '/var/www/html/api/sipnotify');
		$this->copyRuntimeDirectory(__DIR__ . '/api/sipnotify', '/var/www/html/api/sipnotify', 0644, true, 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify');
		$this->copyRuntimeDirectory(__DIR__ . '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify', 0644, true, 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/portal', '/var/www/html/mass-notify');
		$this->copyRuntimeDirectory(__DIR__ . '/portal', '/var/www/html/mass-notify', 0644, true, 0755);
		$this->pruneRuntimeDirectory(__DIR__ . '/assets', '/var/www/html/sls_mass_notify/assets');
		$this->copyRuntimeDirectory(__DIR__ . '/assets', '/var/www/html/sls_mass_notify/assets', 0644);
		$this->copyRuntimeDirectory(__DIR__ . '/sounds', self::SOUNDS_DIR, 0644, false);
		@unlink($runtimeDir . '/config.ini');
		$this->secureExecutableRuntimeTree();
	}

	private function ensureOperationalLogRotation()
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) { return; }
		$path = '/etc/logrotate.d/sls-mass-notify';
		if (!is_dir(dirname($path)) || is_link($path)) {
			throw new \RuntimeException('Operational log rotation directory is unavailable or unsafe. Install logrotate and retry.');
		}
		$days = $this->normalizeRetentionDays($this->getActiveSettings()['log_retention_days'] ?? 90);
		$content = "/var/log/sls_mass_notify.log {\n    daily\n    maxsize 2M\n    rotate {$days}\n    maxage {$days}\n    missingok\n    notifempty\n    compress\n    delaycompress\n    copytruncate\n    su root asterisk\n}\n";
		if (is_file($path) && file_get_contents($path) === $content) { return; }
		$temporary = tempnam(dirname($path), '.sls-logrotate-');
		if ($temporary === false) { throw new \RuntimeException('Unable to stage log rotation.'); }
		try {
			if (file_put_contents($temporary, $content) !== strlen($content)) { throw new \RuntimeException('Unable to write log rotation.'); }
			chmod($temporary, 0644); chown($temporary, 'root'); chgrp($temporary, 'root');
			if (!rename($temporary, $path)) { throw new \RuntimeException('Unable to install log rotation.'); }
		} finally { if (is_file($temporary)) { unlink($temporary); } }
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
		$legacyLinkDirectory = self::PLUGIN_DATA_DIR . '/links';
		foreach ([
			'freepbx-module-nwsalerts',
			'dashboard-announcement-section.php',
			'dashboard-announcement-view.php',
			'dedicated-sounds',
			'live-nws-poller',
			'manual-nws-test',
			'mass-notify-api',
			'visual-sip-notify-sender',
			'web-assets',
		] as $legacyLinkName) {
			$legacyLink = $legacyLinkDirectory . '/' . $legacyLinkName;
			if (is_link($legacyLink)) {
				@unlink($legacyLink);
			}
		}
		// Remove the now-empty historical container without touching an
		// unexpected file or directory entry an administrator may need to
		// inspect.
		if (is_dir($legacyLinkDirectory) && !is_link($legacyLinkDirectory)) {
			@rmdir($legacyLinkDirectory);
		}
		foreach ([
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
		if (!is_file(self::PIPER_VOICE_INSTALL_SCRIPT)) {
			throw new \RuntimeException(_('The protected Piper runtime installer is missing. Restore the module runtime before attempting speech generation.'));
		}
		$output = [];
		$exit = 0;
		exec('/usr/bin/timeout --signal=TERM --kill-after=10 900 /bin/bash '
			. escapeshellarg(self::PIPER_VOICE_INSTALL_SCRIPT) . ' --runtime-only 2>&1', $output, $exit);
		if ($exit !== 0) {
			throw new \RuntimeException(sprintf(_('Piper dependency verification or repair failed (exit code %d). Review /var/log/sls_mass_notify.log for the mismatched package or failed download before retrying.'), $exit));
		}
		$this->ensurePiperVoices();
		$this->ensurePiperWrapper();
		$this->repairPiperRuntimePermissions();
		$this->secureExecutableRuntimeTree();
		if (is_executable(self::PIPER_BIN)) {
			$this->runCommand('/usr/bin/timeout 120 /bin/bash ' . escapeshellarg(self::PIPER_VOICE_INSTALL_SCRIPT) . ' --repair-permissions-only');
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
			if (is_file($path) && !is_link($path)) {
				@chmod($path, 0755);
			}
		}
		if (is_file('/usr/local/bin/piper') && !is_link('/usr/local/bin/piper') && $this->isSlsOwnedPiperWrapper('/usr/local/bin/piper')) {
			@chmod('/usr/local/bin/piper', 0755);
		}
	}

	private function secureExecutableRuntimeTree()
	{
		if (!is_dir(self::RUNTIME_DIR)) {
			return;
		}
		$program = <<<'PY'
import os
import stat
import sys

root = sys.argv[1]
if not root.startswith('/') or '\x00' in root:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, 'O_NOFOLLOW', 0)
file_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, 'O_NOFOLLOW', 0)
root_fd = os.open(root, directory_flags)
executables = {
    'sls_mass_notify_weather_poll.sh',
    'sls_mass_notify_nws_poll.sh',
    'sls_mass_notify_schedule_worker.php',
    'sls_mass_notify_announcement_worker.php',
    'sls_mass_notify_automation_worker.php',
    'sls_mass_notify_cluster_worker.php',
    'sls_mass_notify_cluster_effect.php',
    'sls_mass_notify_incident_response.php',
    'sls_cluster_guard.py',
            'sls_mass_notify_delivery_authorization.php',
    'sls_mass_notify_panic.php',
    'sls_trigger_actions.py',
    'sls_weather_channels.py',
    'sls_lightning_providers.py',
    'sls_mass_notify_live_paging.php',
            'sls_mass_notify_weather_channels.php',
    'sls_mass_notify_phone_agi.py',
    'sls_phone_admission.py',
    'sls_phone_events.py',
    'sls_emergency_observer.py',
    'sls_phone_outcomes.py',
    'sls_outbound_routes.py',
    'sls_storage_maintenance.py',
    'sls_audit_separation.py',
    'sls_operational_backup.py',
    'sls_external_monitor.py',
    'sls_resource_capacity.py',
    'sls_piper_dependencies.py',
    'sls_piper_environment.py',
    'sls_update_policy.py',
    'sls_module_trust.py',
    'sls_release_trust.py',
    'sls_privileged_install.py',
    'sls_console.py',
    'sls_installer_recovery.py',
    'sls_install_idle.py',
    'sls_install_guard.py',
    'sls_weather_queue.py',
    'sls_audio_queue.py',
    'sls_audio_state.py',
    'sls_release_verify.py',
    'sls_mass_notify_test.sh',
    'sls_mass_notify_update.sh',
    'sls_mass_notify_maintenance.sh',
    'sls_mass_notify_uninstall.sh',
    'sls_mass_notify_install_piper_voices.sh',
    'sls_mass_notify_xweather_poll.py',
    'sls_branded_email.py',
    'sls_announcement_email.py',
    'sls_branded_discord.py',
    'sls_notification_destinations.py',
    'sls_system_notifications.py',
    'sls_nws_status.py',
    'sls_nws_delivery_claims.py',
    'sls_notify.py',
    'sls_config.py',
    'sls_config_crypto.py',
}
visited = 0

def secure(directory_fd, relative=''):
    global visited
    os.fchown(directory_fd, 0, 0)
    os.fchmod(directory_fd, 0o755)
    for name in sorted(os.listdir(directory_fd)):
        if name in ('', '.', '..') or '/' in name or '\x00' in name:
            raise RuntimeError('invalid executable-runtime entry')
        visited += 1
        if visited > 50000:
            raise RuntimeError('executable runtime exceeds the entry limit')
        child_relative = relative + '/' + name if relative else name
        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        if stat.S_ISDIR(metadata.st_mode):
            child_fd = os.open(name, directory_flags, dir_fd=directory_fd)
            try:
                secure(child_fd, child_relative)
            finally:
                os.close(child_fd)
        elif stat.S_ISREG(metadata.st_mode):
            child_fd = os.open(name, file_flags, dir_fd=directory_fd)
            try:
                verified = os.fstat(child_fd)
                if not stat.S_ISREG(verified.st_mode):
                    raise RuntimeError('executable runtime file changed type')
                os.fchown(child_fd, 0, 0)
                executable = child_relative in executables or child_relative.startswith('piper/venv/bin/')
                os.fchmod(child_fd, 0o755 if executable else 0o644)
            finally:
                os.close(child_fd)
        elif stat.S_ISLNK(metadata.st_mode):
            os.chown(name, 0, 0, dir_fd=directory_fd, follow_symlinks=False)
        else:
            raise RuntimeError('executable runtime contains a special file')

try:
    secure(root_fd)
finally:
    os.close(root_fd)
PY;
		$output = [];
		$status = 1;
		@exec('/usr/bin/python3 -I -c ' . escapeshellarg($program) . ' ' . escapeshellarg(self::RUNTIME_DIR) . ' 2>/dev/null', $output, $status);
		if ($status !== 0) {
			throw new \RuntimeException(_('Unable to secure the Mass Notifications executable runtime without following symbolic links.'));
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
			'/var/www/html/mass-notify',
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
			'en_US-lessac-medium.onnx' => $base . '/lessac/medium/en_US-lessac-medium.onnx',
			'en_US-lessac-medium.onnx.json' => $base . '/lessac/medium/en_US-lessac-medium.onnx.json',
			'en_US-amy-low.onnx' => $base . '/amy/low/en_US-amy-low.onnx',
			'en_US-amy-low.onnx.json' => $base . '/amy/low/en_US-amy-low.onnx.json',
			'en_US-ryan-low.onnx' => $base . '/ryan/low/en_US-ryan-low.onnx',
			'en_US-ryan-low.onnx.json' => $base . '/ryan/low/en_US-ryan-low.onnx.json',
			'es_ES-davefx-medium.onnx' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/es/es_ES/davefx/medium/es_ES-davefx-medium.onnx',
			'es_ES-davefx-medium.onnx.json' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/es/es_ES/davefx/medium/es_ES-davefx-medium.onnx.json',
			'fr_FR-siwis-medium.onnx' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/fr/fr_FR/siwis/medium/fr_FR-siwis-medium.onnx',
			'fr_FR-siwis-medium.onnx.json' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/fr/fr_FR/siwis/medium/fr_FR-siwis-medium.onnx.json',
			'de_DE-thorsten-low.onnx' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/de/de_DE/thorsten/low/de_DE-thorsten-low.onnx',
			'de_DE-thorsten-low.onnx.json' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/de/de_DE/thorsten/low/de_DE-thorsten-low.onnx.json',
			'pt_BR-faber-medium.onnx' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/pt/pt_BR/faber/medium/pt_BR-faber-medium.onnx',
			'pt_BR-faber-medium.onnx.json' => 'https://huggingface.co/rhasspy/piper-voices/resolve/e21c7de8d4eab79b902f0d61e662b3f21664b8d2/pt/pt_BR/faber/medium/pt_BR-faber-medium.onnx.json',
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

	private function isPresentPiperVoiceFile($path)
	{
		clearstatcache(true, $path);
		$metadata = @lstat($path);
		if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0100000
			|| $metadata['nlink'] !== 1 || !is_readable($path)) {
			return false;
		}
		$name = preg_replace('/\.download$/', '', basename((string)$path));
		return $metadata['size'] > (str_ends_with($name, '.onnx') ? 1000000 : 0);
	}

	private function isValidPiperVoiceFile($path)
	{
		if (!$this->isPresentPiperVoiceFile($path)) { return false; }
		$name = basename((string)$path);
		$name = preg_replace('/\.download$/', '', $name);
		$hashes = [
			'en_US-lessac-low.onnx' => 'f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375',
			'en_US-lessac-low.onnx.json' => '45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a',
			'en_US-lessac-medium.onnx' => '5efe09e69902187827af646e1a6e9d269dee769f9877d17b16b1b46eeaaf019f',
			'en_US-lessac-medium.onnx.json' => 'efe19c417bed055f2d69908248c6ba650fa135bc868b0e6abb3da181dab690a0',
			'en_US-amy-low.onnx' => 'a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3',
			'en_US-amy-low.onnx.json' => '2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb',
			'en_US-ryan-low.onnx' => '8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf',
			'en_US-ryan-low.onnx.json' => 'b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60',
			'es_ES-davefx-medium.onnx' => '6658b03b1a6c316ee4c265a9896abc1393353c2d9e1bca7d66c2c442e222a917',
			'es_ES-davefx-medium.onnx.json' => '0e0dda87c732f6f38771ff274a6380d9252f327dca77aa2963d5fbdf9ec54842',
			'fr_FR-siwis-medium.onnx' => '641d1ab097da2b81128c076810edb052b385decc8be3381814802a64a73baf99',
			'fr_FR-siwis-medium.onnx.json' => '39479916c2db192b5ac9764daddd0c744d83e023ad890c6976c0633ae4df8959',
			'de_DE-thorsten-low.onnx' => '9ac27fad17cec5c1a791161976a64f026f16fc058b400b1fea62565b8b2cf375',
			'de_DE-thorsten-low.onnx.json' => 'df38e892ed949f62d0c40978bc666de0b341c5f7921840ae7c72f4df8e8ffa05',
			'pt_BR-faber-medium.onnx' => '858555e3a064209c57088fe6bd70c4c3dc54d03eaa00c45d5ecaf43a33f95aa7',
			'pt_BR-faber-medium.onnx.json' => '7e694de195ae3fc36dd732c445eb04fb49b649854893cb5506b978f0d50a1d6f',
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
		$this->normalizeAmiHost($ami['host'] ?? $this->detectAmiHost());
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
					'0.0.0.0/0.0.0.0&::/0',
					'127.0.0.1/255.255.255.255&::1/128',
					'system,call,originate,reporting',
					'system,call,originate,reporting',
					1000
				);
				$this->removeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI');
			} catch (\Throwable $e) {
				$block = "[{$username}]\n"
					. "secret = {$password}\n"
					. "deny = 0.0.0.0/0.0.0.0\n"
					. "deny = ::/0\n"
					. "permit = 127.0.0.1/255.255.255.255\n"
					. "permit = ::1/128\n"
					. "read = system,call,originate,reporting\n"
					. "write = system,call,originate,reporting\n";
				$this->writeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI', $block);
			}
		} else {
			$block = "[{$username}]\n"
				. "secret = {$password}\n"
				. "deny = 0.0.0.0/0.0.0.0\n"
				. "deny = ::/0\n"
				. "permit = 127.0.0.1/255.255.255.255\n"
				. "permit = ::1/128\n"
				. "read = system,call,originate,reporting\n"
				. "write = system,call,originate,reporting\n";
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

	private function normalizeAmiHost($host)
	{
		if (!is_string($host) || !in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1'], true)) {
			throw new \DomainException(_('The SLS AMI host must be 127.0.0.1, ::1, or localhost. Use ::1 explicitly for an IPv6-only AMI listener; localhost uses IPv4 loopback.'));
		}
		return strtolower(trim($host));
	}

	private function detectAmiHost()
	{
		// This supplies a default only. A saved explicit address always wins,
		// even if the FreePBX client uses a different loopback address family.
		// localhost remains IPv4 without DNS; listener changes are never made.
		return strtolower(trim($this->getFreePbxConfigValue('ASTMANAGERHOST'))) === '::1' ? '::1' : '127.0.0.1';
	}

	private function amiEndpointDiagnostic(array $ami)
	{
		$displayHost = static function ($value) {
			if (!is_string($value)) { return 'invalid'; }
			$value = strtolower(trim($value));
			return $value === '::1' ? '[::1]' : (in_array($value, ['localhost', '127.0.0.1'], true) ? $value : 'invalid');
		};
		$host = $displayHost($ami['host'] ?? $this->detectAmiHost());
		$port = $this->normalizeInt($ami['port'] ?? $this->detectAmiPort(), 1, 65535, $this->detectAmiPort());
		$freePbxHost = $displayHost($this->getFreePbxConfigValue('ASTMANAGERHOST') ?: 'localhost');
		return sprintf(_('SLS AMI endpoint: %s:%d; FreePBX endpoint: %s:%d. Check protected ami.host/ami.port and the local AMI listener/ACL. localhost uses IPv4; IPv6-only requires ::1. Saved endpoints and listener settings are not changed automatically.'),
			$host, $port, $freePbxHost, $this->detectAmiPort());
	}

	private function detectAmiPort()
	{
		$port = (int)$this->getFreePbxConfigValue('ASTMANAGERPORT');
		return $port >= 1 && $port <= 65535 ? $port : 5038;
	}

	private function ensureDialplan()
	{
		$pagingAnswerTimeout = $this->normalizePagingAnswerTimeout($this->getActiveSettings()['paging_answer_timeout'] ?? 5);
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
				. " same => n,Set(SLS_PHONE_ALLOW=0)\n"
				. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,contact)\n"
				. " same => n,GotoIf($[\"\${SLS_PHONE_ALLOW}\"=\"1\"]?admitted)\n"
				. " same => n,Hangup()\n"
				. " same => n(admitted),Set(CHANNEL(hangup_handler_push)=sls-phone-ended,s,1)\n"
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
			. " same => n,Set(SLS_PHONE_ALLOW=0)\n"
			. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,origin)\n"
			. " same => n,GotoIf($[\"\${SLS_PHONE_ALLOW}\"!=\"1\"]?done)\n"
			. " same => n,Set(CHANNEL(hangup_handler_push)=sls-phone-ended,s,1)\n"
				. " same => n,GotoIf($[\"\${SLS_DIAL}\"=\"\"]?done)\n"
				. " same => n,NoOp(SLS Mass Notification dial string \${SLS_DIAL})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Verbose(1,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Set(CALLERID(name)=\${IF($[\"\${SLS_CALLERID_NAME}\"=\"\"]?SLS Mass Notification System:\${SLS_CALLERID_NAME})})\n"
			. " same => n,Set(CALLERID(num)=\${IF($[\"\${SLS_CALLERID_NUM}\"=\"\"]?SLS:\${SLS_CALLERID_NUM})})\n"
			. " same => n,Set(__SIP_URI_OPTIONS=intercom=true)\n"
				. " same => n,Page(\${SLS_DIAL},b(sls-alert-autoanswer^s^1(\${EXTEN}))A(\${SLS_SAFE_SOUND})inq,{$pagingAnswerTimeout})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification page completed for \${EXTEN})\n"
				. " same => n,Verbose(1,SLS Mass Notification page completed for \${EXTEN})\n"
				. " same => n(done),Hangup()\n\n"
				. "[sls-outbound-voice]\n"
				. "exten => _voice_.,1,Set(SLS_PHONE_ALLOW=0)\n"
				. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,outbound)\n"
				. " same => n,GotoIf($[\"\${SLS_PHONE_ALLOW}\"!=\"1\"]?done)\n"
				. " same => n,Set(CHANNEL(hangup_handler_push)=sls-phone-ended,s,1)\n"
				. " same => n,Set(CALLERID(name)=SLS Mass Notify System)\n"
				. " same => n,ExecIf($[\"\${SLS_OUTBOUND_CALLER_ID}\"!=\"\"]?Set(CALLERID(num)=\${SLS_OUTBOUND_CALLER_ID}))\n"
				. " same => n,ExecIf($[\"\${SLS_OUTBOUND_CALLER_ID}\"!=\"\"]?Set(TRUNKCIDOVERRIDE=\${SLS_OUTBOUND_CALLER_ID}))\n"
				. " same => n,GotoIf($[\"\${SLS_OUTBOUND_ROUTE_MODE}\"=\"trunk\"]?trunk)\n"
				. " same => n,Goto(outbound-allroutes,\${SLS_OUTBOUND_NUMBER},1)\n"
				. " same => n(trunk),Gosub(macro-dialout-trunk,s,1(\${SLS_OUTBOUND_TRUNK_ID},\${SLS_OUTBOUND_NUMBER},,off))\n"
				. " same => n(done),Hangup()\n\n"
				. "[sls-outbound-playback]\n"
				. "exten => s,1,Set(SLS_PHONE_ALLOW=0)\n"
				. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,playback)\n"
				. " same => n,GotoIf($[\"\${SLS_PHONE_ALLOW}\"!=\"1\"]?done)\n"
				. " same => n,Playback(\${SLS_SAFE_SOUND})\n"
				. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,playback-result)\n"
				. " same => n,GotoIf($[\"\${SLS_PHONE_ALLOW}\"!=\"1\" | \"\${PLAYBACKSTATUS}\"!=\"SUCCESS\"]?done)\n"
				. " same => n,GotoIf($[\"\${SLS_ACK_REQUIRED}\"!=\"1\"]?incident-response)\n"
				. " same => n,Read(SLS_ACK,\${SLS_ACK_PROMPT},1,,1,\${SLS_ACK_TIMEOUT})\n"
				. " same => n,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,ack)\n"
				. " same => n(incident-response),AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,incident-response)\n"
				. " same => n(done),Hangup()\n\n"
				. "[sls-phone-ended]\n"
				. "exten => s,1,AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,end)\n"
				. " same => n,Return()\n";
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
			. "    Options -Indexes -MultiViews\n"
			. "    AllowOverride None\n"
			. "    RewriteEngine On\n"
			. "    RewriteRule ^assets/[A-Za-z0-9_.-]+\\.(?:png|jpg|svg|ico)$ - [END]\n"
			. "    RewriteRule ^([A-Za-z0-9][A-Za-z0-9_.-]{0,179}\\.(?:png|xml))$ /api/sls-mass-notify/media.php?file=$1 [END]\n"
			. "    RewriteRule ^ - [F,END]\n"
			. "</Directory>\n"
			. "<Directory /var/www/html/mass-notify>\n"
			. "    Require all granted\n"
			. "    Options -Indexes -MultiViews\n"
			. "    AllowOverride All\n"
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

	private function copyRuntimeDirectory($source, $target, $mode = 0644, $overwrite = true, $directoryMode = null)
	{
		if (!is_dir($source) || is_link($source)) {
			throw new \RuntimeException(sprintf(_('Required packaged directory is missing or unsafe: %s'), $source));
		}
		if (is_link($target)) {
			throw new \RuntimeException(sprintf(_('Refusing to use a symbolic-link runtime directory: %s'), $target));
		}
		$createdDirectories = [];
		if ($directoryMode !== null) {
			if ($directoryMode !== 0755) { throw new \RuntimeException('Unsupported public runtime directory mode.'); }
			// mkdir's requested mode is filtered by the installer's umask. The
			// API trees need explicit search/read access for PHP-FPM immediately,
			// including when a later install step fails before fwconsole chown.
			for ($directory = $target; !is_dir($directory); $directory = dirname($directory)) {
				if (is_link($directory) || dirname($directory) === $directory) {
					throw new \RuntimeException(sprintf(_('Unsafe public runtime directory: %s'), $directory));
				}
				$createdDirectories[] = $directory;
			}
		}
		if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
			throw new \RuntimeException(sprintf(_('Unable to create runtime directory: %s'), $target));
		}
		if ($directoryMode !== null) {
			// Existing shared web parents keep their administrator-set metadata.
			foreach (array_unique(array_merge(array_reverse($createdDirectories), [$target])) as $directory) {
				if (!@chmod($directory, $directoryMode)) {
					throw new \RuntimeException(sprintf(_('Unable to secure public runtime directory permissions: %s'), $directory));
				}
			}
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
				$this->copyRuntimeDirectory($src, $dst, $mode, $overwrite, $directoryMode);
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
		$weatherCronLine = '* * * * * /usr/bin/timeout 5500 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh';
		$hasPoll = false;
		foreach ($cron->getAll() as $line) {
			$line = (string)$line;
			if (strpos($line, 'sls_mass_notify_nws_poll.sh') !== false) {
				$cron->remove($line);
				continue;
			}
			if (strpos($line, 'sls_mass_notify_weather_poll.sh') !== false) {
				if (trim($line) !== $weatherCronLine) {
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
			$cron->addLine($weatherCronLine);
		}
		$cron->addLine('* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php');
		$announcementWorkerLine = '* * * * * /usr/bin/timeout 900 /usr/bin/php /usr/local/bin/sls_mass_notify/sls_mass_notify_announcement_worker.php --reconcile';
		foreach ($cron->getAll() as $line) {
			if (strpos((string)$line, 'sls_mass_notify_announcement_worker.php') !== false) { $cron->remove($line); }
		}
		$cron->addLine($announcementWorkerLine);
		// Root maintenance/update entries are installed by the authenticated root helper.
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
		$filtered[] = '17 * * * * /usr/bin/timeout 1800 /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh';
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
			if (strpos((string)$line, 'sls_mass_notify_nws_poll.sh') !== false || strpos((string)$line, 'sls_mass_notify_weather_poll.sh') !== false || strpos((string)$line, 'sls_mass_notify_schedule_worker.php') !== false || strpos((string)$line, 'sls_mass_notify_announcement_worker.php') !== false || strpos((string)$line, 'sls_mass_notify_update.sh') !== false) {
				$cron->remove($line);
			}
		}
		// The root-owned uninstaller removes its separate maintenance/update cron entries.
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
				exec('/usr/bin/timeout --kill-after=1 2 /usr/sbin/postconf -h ' . $setting . ' 2>/dev/null', $output, $exitCode);
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

	private function normalizeAnnouncementWebhookUrl($value)
	{
		$value = trim((string)$value);
		$parts = parse_url($value);
		$host = is_array($parts) ? strtolower(rtrim((string)($parts['host'] ?? ''), '.')) : '';
		if (in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true)) {
			return $this->normalizeDiscordWebhookUrl($value);
		}
		return $this->normalizeGenericWebhookUrl($value);
	}

	private function webhookPayloadFormat(array $destination, $type = 'generic')
	{
		$format = array_key_exists('payload_format', $destination) ? $destination['payload_format'] : 'native';
		if (!is_string($format) || !in_array($format, ['native', 'slack', 'teams_workflow'], true)
			|| ($type === 'discord' && $format !== 'native')) {
			throw new \InvalidArgumentException('Webhook integration format must be native, slack, or teams_workflow. Discord destinations require native format.');
		}
		return $format;
	}

	private function webhookDestinationFingerprint(array $destination)
	{
		$format = $this->webhookPayloadFormat($destination);
		$material = (string)$destination['url'];
		if ($format !== 'native') {
			$material .= "\npayload_format=" . $format;
		}
		return hash('sha256', $material);
	}

	private function normalizeWebhookDestinations($value, $type)
	{
		$type = in_array($type, ['discord', 'announcement'], true) ? $type : 'generic';
		$isDiscord = $type !== 'generic';
		$destinations = [];
		$seenUrls = [];
		$seenIds = [];
		foreach ((array)$value as $entry) {
			if (!is_array($entry) || count($destinations) >= self::MAX_WEBHOOK_DESTINATIONS) {
				continue;
			}
			$format = $this->webhookPayloadFormat($entry, $type);
			$url = $type === 'discord'
				? $this->normalizeDiscordWebhookUrl($entry['url'] ?? $entry['webhook_url'] ?? '')
				: ($type === 'announcement'
					? $this->normalizeAnnouncementWebhookUrl($entry['url'] ?? $entry['webhook_url'] ?? '')
					: $this->normalizeGenericWebhookUrl($entry['url'] ?? $entry['webhook_url'] ?? ''));
			if ($url === '' || isset($seenUrls[$url])) {
				continue;
			}
			$seenUrls[$url] = true;
			$name = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($entry['name'] ?? '')));
			$name = substr(preg_replace('/\s+/', ' ', $name), 0, 80);
			if ($name === '') {
				$name = $isDiscord
					? ($type === 'announcement' ? sprintf('Announcement Webhook %d', count($destinations) + 1) : sprintf('Discord %d', count($destinations) + 1))
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
			$auth = [];
			foreach (['bearer_token', 'signing_secret'] as $secretField) {
				$secret = (string)($entry[$secretField] ?? '');
				if (strlen($secret) > 512 || preg_match('/[^\x21-\x7e]/', $secret)) {
					throw new \InvalidArgumentException('Webhook authentication must be at most 512 printable ASCII characters without whitespace.');
				}
				$auth[$secretField] = $type === 'discord' ? '' : $secret;
			}
			$destinations[] = [
				'id' => $id,
				'name' => $name,
				'url' => $url,
				'payload_format' => $format,
				'enabled' => array_key_exists('enabled', $entry) && empty($entry['enabled']) ? '0' : '1',
			] + $auth;
		}
		return $destinations;
	}

	private function validateWebhookDestinations($value, $type)
	{
		$type = in_array($type, ['discord', 'announcement'], true) ? $type : 'generic';
		$isDiscord = $type !== 'generic';
		$label = $type === 'announcement' ? _('Announcement webhook') : ($isDiscord ? _('Discord') : _('Generic webhook'));
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
			try {
				$this->webhookPayloadFormat($entry, $type);
			} catch (\InvalidArgumentException $error) {
				$errors[] = sprintf(_('%s destination %d has an unsupported integration format.'), $label, $index + 1);
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
				: ($type === 'announcement' ? $this->normalizeAnnouncementWebhookUrl($rawUrl) : $this->normalizeGenericWebhookUrl($rawUrl));
			if ($url === '') {
				if ($type === 'announcement') {
					$errors[] = sprintf(_('Announcement webhook destination %d must use a valid HTTPS hostname on port 443 without embedded credentials. Discord hosts must use the standard Discord webhook path. Public-address DNS validation is enforced when an announcement is sent.'), $index + 1);
				} elseif ($isDiscord) {
					$errors[] = sprintf(_('%s destination %d must use a valid Discord HTTPS webhook URL.'), $label, $index + 1);
				} else {
					$errors[] = sprintf(_('Generic webhook destination %d must use a valid HTTPS hostname on port 443 without embedded credentials. Public-address DNS validation is enforced when an alert is sent.'), $index + 1);
				}
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
			if (!array_key_exists('payload_format', $entry) && isset($existingById[$id])) {
				$entry['payload_format'] = $existingById[$id]['payload_format'];
			}
			foreach (['bearer_token', 'signing_secret'] as $secretField) {
				if (!empty($entry['clear_' . $secretField])) {
					$entry[$secretField] = '';
				} elseif (empty($entry[$secretField]) || $entry[$secretField] === '[redacted]') {
					$entry[$secretField] = (string)($existingById[$id][$secretField] ?? '');
				}
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

	private function normalizeNwsZoneGroups($value, $legacyZone = '', $legacyRecipients = [], array $legacyQuiet = [])
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
			$quietEnabled = array_key_exists('quiet_hours_enabled', $group)
				? (empty($group['quiet_hours_enabled']) ? '0' : '1')
				: (empty($legacyQuiet['enabled']) ? '0' : '1');
			$quietStart = $this->normalizeHour((string)($group['quiet_hours_start'] ?? $legacyQuiet['start'] ?? '21:00'), '21:00');
			$quietEnd = $this->normalizeHour((string)($group['quiet_hours_end'] ?? $legacyQuiet['end'] ?? '06:00'), '06:00');
			$quietCritical = $this->normalizeCriticalEvents($group['quiet_critical_events'] ?? $legacyQuiet['critical_events'] ?? $this->getDefaultQuietCriticalEvents());
			$discordIds = $this->normalizeDestinationIdList(array_key_exists('discord_webhook_ids', $group) ? $group['discord_webhook_ids'] : ($legacyQuiet['discord_webhook_ids'] ?? []));
			$genericIds = $this->normalizeDestinationIdList(array_key_exists('generic_webhook_ids', $group) ? $group['generic_webhook_ids'] : ($legacyQuiet['generic_webhook_ids'] ?? []));
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
				'site_id' => preg_match('/^loc_[a-f0-9]{24}$/D', (string)($group['site_id'] ?? '')) ? $group['site_id'] : '',
				'zone' => $zone,
				'extensions' => $extensions,
				'desktop_clients' => array_values($desktopClients),
				'email_recipients' => $emailRecipients,
				'voice_recipient_ids' => $this->normalizeDestinationIdList($group['voice_recipient_ids'] ?? []),
				'sms_recipient_ids' => $this->normalizeDestinationIdList($group['sms_recipient_ids'] ?? []),
				'quiet_hours_enabled' => $quietEnabled,
				'quiet_hours_start' => $quietStart,
				'quiet_hours_end' => $quietEnd,
				'quiet_critical_events' => $quietCritical,
				'discord_webhook_ids' => $discordIds,
				'generic_webhook_ids' => $genericIds,
			];
		}
		return $groups;
	}

	private function normalizeDestinationIdList($value)
	{
		$ids = [];
		foreach ((array)$value as $id) {
			$id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id), 0, 64);
			if ($id !== '') {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
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

	private function destinationListHasValue($value)
	{
		foreach ((array)$value as $entry) {
			if (is_scalar($entry) && trim((string)$entry) !== '') {
				return true;
			}
		}
		return false;
	}

	private function nwsZoneHasDeliveryDestination(array $group)
	{
		foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids', 'discord_webhook_ids', 'generic_webhook_ids'] as $field) {
			if ($this->destinationListHasValue($group[$field] ?? [])) {
				return true;
			}
		}
		return false;
	}

	private function hasEnabledSharedAlertWebhook(array $settings)
	{
		foreach (['discord_webhooks' => 'discord', 'generic_webhooks' => 'generic'] as $field => $type) {
			foreach ($this->normalizeWebhookDestinations($settings[$field] ?? [], $type) as $destination) {
				if (!empty($destination['enabled'])) {
					return true;
				}
			}
		}
		return false;
	}

	private function xweatherGroupHasDeliveryDestination(array $group, array $settings)
	{
		foreach (['extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids'] as $field) {
			if ($this->destinationListHasValue($group[$field] ?? [])) {
				return true;
			}
		}
		return $this->hasEnabledSharedAlertWebhook($settings);
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
			$errors = array_merge($errors, $this->validateVoiceRecipientIds($group['voice_recipient_ids'] ?? []), $this->validateSmsRecipientIds($group['sms_recipient_ids'] ?? []));
            if (array_key_exists('site_id', $group) && (!is_string($group['site_id']) || ($group['site_id'] !== '' && !preg_match('/^loc_[a-f0-9]{24}$/D', $group['site_id'])))) { $errors[] = _('Select a saved site or an independent weather route.'); }
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
			foreach (['discord_webhook_ids', 'generic_webhook_ids'] as $listField) {
				if (isset($group[$listField]) && !is_array($group[$listField])) {
					$errors[] = sprintf(_('%s %s must be an array.'), $label, str_replace('_', ' ', $listField));
				}
			}
			foreach (['quiet_hours_start' => '21:00', 'quiet_hours_end' => '06:00'] as $timeField => $fallback) {
				if (isset($group[$timeField]) && $this->normalizeHour((string)$group[$timeField], '') === '') {
					$errors[] = sprintf(_('%s has an invalid quiet-hours time.'), $label);
				}
			}
			$extensions = $this->normalizeRecipientExtensions($group['extensions'] ?? $group['recipients'] ?? []);
			$desktops = [];
			foreach ((array)($group['desktop_clients'] ?? []) as $username) {
				$username = $this->normalizeDesktopUsername($username);
				if ($username !== '') {
					$desktops[$username] = true;
				}
			}
			$deliveryGroup = $group;
			$deliveryGroup['extensions'] = $extensions;
			$deliveryGroup['desktop_clients'] = array_keys($desktops);
			if (!$this->nwsZoneHasDeliveryDestination($deliveryGroup)) {
				$errors[] = sprintf(_('%s needs at least one phone, desktop, email, Discord, or generic webhook destination.'), $label);
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

	private function validateNwsZoneDestinationAssignments($value, array $settings)
	{
		$known = ['discord_webhook_ids' => [], 'generic_webhook_ids' => []];
		foreach (['discord_webhooks' => 'discord_webhook_ids', 'generic_webhooks' => 'generic_webhook_ids'] as $source => $field) {
			foreach ((array)($settings[$source] ?? []) as $destination) {
				if (is_array($destination) && !empty($destination['enabled']) && !empty($destination['id'])) {
					$known[$field][(string)$destination['id']] = true;
				}
			}
		}
		$errors = [];
		foreach ((array)$value as $group) {
			if (!is_array($group)) continue;
			$label = (string)($group['name'] ?? $group['zone'] ?? _('Weather zone'));
			foreach ($known as $field => $lookup) {
				foreach ((array)($group[$field] ?? []) as $id) {
					if (!isset($lookup[(string)$id])) {
						$errors[] = sprintf(_('Weather zone "%s" references an unavailable notification destination: %s.'), $label, (string)$id);
					}
				}
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
			$allowedFields = ['id', 'name', 'site_id', 'enabled', 'adaptive_nws_zone_id', 'location', 'radius_miles', 'extensions', 'recipients', 'desktop_clients', 'email_recipients', 'voice_recipient_ids', 'sms_recipient_ids', 'all_clear', 'all_clear_minutes', 'strike_type', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'];
			$errors = array_merge($errors, $this->validateVoiceRecipientIds($group['voice_recipient_ids'] ?? []), $this->validateSmsRecipientIds($group['sms_recipient_ids'] ?? []));
            if (array_key_exists('site_id', $group) && (!is_string($group['site_id']) || ($group['site_id'] !== '' && !preg_match('/^loc_[a-f0-9]{24}$/D', $group['site_id'])))) { $errors[] = _('Select a saved site or an independent weather route.'); }
			foreach (array_keys($group) as $field) {
				if (!in_array($field, $allowedFields, true)) {
					$errors[] = sprintf(_('%s contains an unsupported field: %s.'), $label, (string)$field);
				}
			}
			foreach (['id', 'name', 'site_id', 'adaptive_nws_zone_id', 'location', 'all_clear'] as $stringField) {
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
			if (array_key_exists('enabled', $group) && !in_array($group['enabled'], ['0', '1'], true)) {
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
			if (isset($group['all_clear_minutes']) && (filter_var($group['all_clear_minutes'], FILTER_VALIDATE_INT) === false || (int)$group['all_clear_minutes'] < 5 || (int)$group['all_clear_minutes'] > 120)) {
				$errors[] = sprintf(_('%s all-clear observation period must be 5–120 minutes.'), $label);
			}
			if (array_key_exists('strike_type', $group)
				&& (!is_string($group['strike_type']) || !in_array($group['strike_type'], ['cloud_to_ground', 'cloud_to_cloud', 'both'], true))) {
				$errors[] = sprintf(_('%s has an invalid strike type.'), $label);
			}
			foreach (['quiet_hours_start', 'quiet_hours_end'] as $timeField) {
				if (isset($group[$timeField]) && $this->normalizeHour((string)$group[$timeField], '') === '') {
					$errors[] = sprintf(_('%s has an invalid quiet-hours time.'), $label);
				}
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

	private function validateXweatherAdaptivePolicyInput(array $value)
	{
		$errors = [];
		if (array_key_exists('adaptive_gate_failure_policy', $value)
			&& !in_array($value['adaptive_gate_failure_policy'], ['standby', 'bounded_poll'], true)) {
			$errors[] = _('Lightning Weather.gov outage policy must be standby or bounded_poll.');
		}
		if (array_key_exists('adaptive_fallback_minutes', $value)) {
			$minutes = $value['adaptive_fallback_minutes'];
			$integer = is_int($minutes) || (is_string($minutes) && preg_match('/^[0-9]{1,3}$/D', $minutes));
			if (!$integer || (int)$minutes < 15 || (int)$minutes > 120) {
				$errors[] = _('Lightning temporary outage polling must last a whole number of minutes from 15 to 120.');
			}
		}
		return $errors;
	}

	private function normalizeXweatherSettings($value, $defaultTtsVolume = 25)
	{
		$value = is_array($value) ? $value : [];
		$cleanSecret = static function ($item) {
			return substr(trim(preg_replace('/[^\x21-\x7e]/', '', (string)$item)), 0, 256);
		};
		$location = $this->normalizeXweatherLocation($value['location'] ?? '');
		$strikeType = strtolower(trim((string)($value['strike_type'] ?? 'cloud_to_ground')));
		if (!in_array($strikeType, ['cloud_to_ground', 'cloud_to_cloud', 'both'], true)) {
			$strikeType = 'cloud_to_ground';
		}
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
			'provider' => in_array($value['provider'] ?? 'xweather', ['xweather', 'tempest', 'meteomatics'], true) ? ($value['provider'] ?? 'xweather') : 'xweather',
			'client_id' => $cleanSecret($value['client_id'] ?? ''),
			'client_secret' => $cleanSecret($value['client_secret'] ?? ''),
			'location' => $location,
			'radius_miles' => !empty($groups) ? (int)$groups[0]['radius_miles'] : $this->normalizeInt($value['radius_miles'] ?? 25, 1, 62, 25),
			'query_interval_minutes' => $this->normalizeInt($value['query_interval_minutes'] ?? 5, 1, 10, 5),
			'adaptive_free_tier' => array_key_exists('adaptive_free_tier', $value) && empty($value['adaptive_free_tier']) ? '0' : '1',
			'adaptive_grace_minutes' => $this->normalizeInt($value['adaptive_grace_minutes'] ?? 60, 5, 120, 60),
			'adaptive_gate_failure_policy' => ($value['adaptive_gate_failure_policy'] ?? 'standby') === 'bounded_poll' ? 'bounded_poll' : 'standby',
			'adaptive_fallback_minutes' => $this->normalizeInt($value['adaptive_fallback_minutes'] ?? 30, 15, 120, 30),
			'adaptive_nws_zone_id' => !empty($groups) ? (string)$groups[0]['adaptive_nws_zone_id'] : substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($value['adaptive_nws_zone_id'] ?? '')), 0, 64),
			'tts_volume' => $this->normalizeTtsVolume($value['tts_volume'] ?? $defaultTtsVolume, $defaultTtsVolume),
			'opening_tone' => $normalizeLightningTone($openingTone),
			'closing_tone' => $normalizeLightningTone($closingTone),
			'all_clear' => $allClear,
			'strike_type' => !empty($groups) ? (string)$groups[0]['strike_type'] : $strikeType,
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
				'voice_recipient_ids' => [],
				'sms_recipient_ids' => [],
				'all_clear' => $legacy['all_clear'] ?? 'none',
				'strike_type' => $legacy['strike_type'] ?? 'cloud_to_ground',
				'quiet_hours_enabled' => $legacy['quiet_hours_enabled'] ?? '0',
				'quiet_hours_start' => $legacy['quiet_hours_start'] ?? '21:00',
				'quiet_hours_end' => $legacy['quiet_hours_end'] ?? '06:00',
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
			$strikeType = strtolower(trim((string)($group['strike_type'] ?? 'cloud_to_ground')));
			if (!in_array($strikeType, ['cloud_to_ground', 'cloud_to_cloud', 'both'], true)) {
				$strikeType = 'cloud_to_ground';
			}
			$groups[] = [
				'id' => $id,
				'name' => $name,
				'enabled' => empty($group['enabled']) ? '0' : '1',
				'site_id' => preg_match('/^loc_[a-f0-9]{24}$/D', (string)($group['site_id'] ?? '')) ? $group['site_id'] : '',
				'adaptive_nws_zone_id' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($group['adaptive_nws_zone_id'] ?? '')), 0, 64),
				'location' => $location,
				'radius_miles' => $this->normalizeInt($group['radius_miles'] ?? 25, 1, 62, 25),
				'extensions' => $this->normalizeRecipientExtensions($group['extensions'] ?? $group['recipients'] ?? []),
				'desktop_clients' => array_values($desktopClients),
				'email_recipients' => $this->normalizeEmailRecipientList($group['email_recipients'] ?? []),
				'voice_recipient_ids' => $this->normalizeDestinationIdList($group['voice_recipient_ids'] ?? []),
				'sms_recipient_ids' => $this->normalizeDestinationIdList($group['sms_recipient_ids'] ?? []),
				'all_clear' => $allClear,
				'all_clear_minutes' => $this->normalizeInt($group['all_clear_minutes'] ?? 10, 5, 120, 10),
				'strike_type' => $strikeType,
				'quiet_hours_enabled' => empty($group['quiet_hours_enabled']) ? '0' : '1',
				'quiet_hours_start' => $this->normalizeHour((string)($group['quiet_hours_start'] ?? $legacy['quiet_hours_start'] ?? '21:00'), '21:00'),
				'quiet_hours_end' => $this->normalizeHour((string)($group['quiet_hours_end'] ?? $legacy['quiet_hours_end'] ?? '06:00'), '06:00'),
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
		return $this->loadJsonFile(self::STATUS_JSON);
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
		$type = strtolower(trim((string)($event['type'] ?? 'other')));
		if (!in_array($type, ['nws', 'xweather', 'test', 'announcement', 'announcement_audio', 'api', 'schedule', 'scheduling', 'desktop', 'system', 'error', 'fault'], true)) {
			$type = 'other';
		}
		$typeLabels = [
			'nws' => _('Weather Alert'),
			'xweather' => _('Lightning Alert'),
			'test' => _('Manual Test'),
			'announcement' => _('Announcement'),
			'announcement_audio' => _('Announcement Audio'),
			'api' => _('API'),
			'schedule' => _('Scheduling'),
			'scheduling' => _('Scheduling'),
			'desktop' => _('Desktop'),
			'system' => _('System'),
			'error' => _('System Error'),
			'fault' => _('System Fault'),
		];
		$notificationType = $this->classifyNotificationType($event, $type);
		$notificationTypeLabels = [
			'weather' => _('Weather Alert'),
			'lightning' => _('Lightning Alert'),
			'manual_test' => _('Manual Test'),
			'dashboard' => _('Dashboard Announcement'),
			'api' => _('API Announcement'),
			'scheduling' => _('Scheduled Announcement'),
			'desktop' => _('Desktop Event'),
			'system' => _('System/Error'),
			'other' => _('Other'),
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
			'notification_type' => $notificationType,
			'notification_type_label' => $notificationTypeLabels[$notificationType] ?? _('Other'),
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

	private function classifyNotificationType(array $event, $recordType)
	{
		$recordType = strtolower(trim((string)$recordType));
		$triggerSource = strtolower(trim((string)($event['trigger_source'] ?? '')));
		$messageType = strtolower(trim((string)($event['message_type'] ?? '')));
		$eventName = strtolower(trim((string)($event['event'] ?? '')));

		// Service type wins over the word "API" in NWS/Xweather trigger names.
		// Manual Lightning tests retain their historic xweather record type, so
		// identify the test source before classifying the live service.
		if ($recordType === 'xweather') {
			return (strpos($triggerSource, 'manual') !== false || strpos($eventName, 'test') !== false)
				? 'manual_test'
				: 'lightning';
		}
		if ($recordType === 'nws') {
			return (strpos($triggerSource, 'manual') !== false || strpos($eventName, 'test') !== false)
				? 'manual_test'
				: 'weather';
		}
		if ($recordType === 'test') {
			return 'manual_test';
		}
		if ($recordType === 'api' || strpos($triggerSource, 'control api') !== false) {
			return 'api';
		}
		if (in_array($recordType, ['schedule', 'scheduling'], true) || strpos($triggerSource, 'scheduled:') === 0) {
			return 'scheduling';
		}
		if ($recordType === 'desktop' || strpos($triggerSource, 'desktop app') !== false || strpos($triggerSource, 'desktop client') !== false) {
			return 'desktop';
		}
		if (in_array($recordType, ['system', 'error', 'fault'], true)
			|| preg_match('/(?:^|\s)(?:system|error|fault)(?:\s|$)/', $messageType)) {
			return 'system';
		}
		if (in_array($recordType, ['announcement', 'announcement_audio'], true)
			|| strpos($triggerSource, 'freepbx dashboard') !== false) {
			return 'dashboard';
		}
		return 'other';
	}

	private function normalizeNativeBackupTransactionId($value)
	{
		$value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim((string)$value));
		$value = trim((string)$value, '.-');
		return substr($value !== '' ? $value : 'transaction', 0, 80);
	}

	private function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30)
	{
		return $this->acquireNativeBackupFileLock(
			self::ANNOUNCEMENT_ACTIVITY_LOCK_FILE,
			$exclusive
				? _('Active announcement delivery did not become idle in time. No protected configuration replacement was performed.')
				: _('Announcement delivery was paused by a protected configuration operation. No channels were submitted; this request was not replayed.'),
			$timeoutSeconds,
			$exclusive ? LOCK_EX : LOCK_SH
		);
	}

	private function acquireNativeBackupFileLock($path, $failureMessage, $timeoutSeconds = 60, $lockMode = LOCK_EX)
	{
		if (!in_array($lockMode, [LOCK_EX, LOCK_SH], true)) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$path = (string)$path; $parent = @lstat(dirname($path)); $account = posix_getpwnam('asterisk');
		$owners = array_unique([0, posix_geteuid(), $account['uid'] ?? -1]);
		if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022)
			|| !in_array($parent['uid'], $owners, true) || realpath(dirname($path)) !== dirname($path)) {
			throw new \RuntimeException((string)$failureMessage);
		}
		$regular = static function ($meta) use ($owners): bool {
			return is_array($meta) && ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1
				&& in_array($meta['uid'], $owners, true) && ($meta['mode'] & 0027) === 0 && $meta['size'] <= 4096;
		};
		clearstatcache(true, $path); $before = @lstat($path);
		if ($before !== false && !$regular($before)) { throw new \RuntimeException((string)$failureMessage); }
		$mask = umask(0037);
		try {
			$handle = @fopen($path, $before === false ? 'x+b' : 'r+b'); $created = $before === false && is_resource($handle);
			if (!$handle && $before === false) {
				clearstatcache(true, $path); $before = @lstat($path);
				if ($regular($before)) { $handle = @fopen($path, 'r+b'); }
			}
		} finally { umask($mask); }
		if (!is_resource($handle)) { throw new \RuntimeException((string)$failureMessage); }
		$accepted = false;
		try {
			$identity = static function () use ($path, $handle, $before, $regular): bool {
				clearstatcache(true, $path); $named = @lstat($path); $opened = fstat($handle);
				return $regular($opened) && $regular($named) && $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino']
					&& ($before === false || ($before['dev'] === $opened['dev'] && $before['ino'] === $opened['ino']));
			};
			if (!$identity()) { throw new \RuntimeException((string)$failureMessage); }
			if ($created) { $this->setPrivateOwnership($path); }
			$deadline = hrtime(true) + max(1, min(120, (int)$timeoutSeconds)) * 1000000000;
			do {
				if (!$identity()) { throw new \RuntimeException((string)$failureMessage); }
				if (@flock($handle, $lockMode | LOCK_NB)) {
					if (!$identity()) { throw new \RuntimeException((string)$failureMessage); }
					$accepted = true; return $handle;
				}
				usleep(10000);
			} while (hrtime(true) < $deadline);
			throw new \RuntimeException((string)$failureMessage);
		} finally { if (!$accepted) { fclose($handle); } }
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
		clearstatcache(true, $path); $before = @lstat($path);
		if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
			|| $before['size'] < 1 || $before['size'] > $maxBytes || realpath($path) !== $path) {
			throw new \RuntimeException((string)$failureMessage);
		}
		// Read/write opening avoids blocking if a FIFO is substituted after lstat;
		// nothing is written. Extracted FreePBX files must be writable by restore.
		$handle = @fopen($path, 'r+b');
		if (!$handle) { throw new \RuntimeException((string)$failureMessage); }
		try {
			$opened = fstat($handle);
			if (!$opened || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
				|| $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
				|| !flock($handle, LOCK_SH | LOCK_NB)) { throw new \RuntimeException((string)$failureMessage); }
			$contents = stream_get_contents($handle, $maxBytes + 1);
			$after = fstat($handle); clearstatcache(true, $path); $current = @lstat($path);
			foreach (['dev', 'ino', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
				if (!is_array($after) || !is_array($current) || $before[$field] !== $after[$field] || $after[$field] !== $current[$field]) {
					throw new \RuntimeException((string)$failureMessage);
				}
			}
			if (!is_string($contents) || strlen($contents) !== $before['size']) { throw new \RuntimeException((string)$failureMessage); }
			return $contents;
		} finally { fclose($handle); }
	}

	private function validateNativeBackupConfig($raw)
	{
		if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > SlsConfigCrypto::MAX_FILE_BYTES) {
			throw new \RuntimeException(_('The protected configuration exceeds the native backup limits.'));
		}
		$settings = SlsConfigCrypto::decode($raw);
		if (!is_array($settings) || empty($settings) || array_keys($settings) === range(0, count($settings) - 1)) {
			throw new \RuntimeException(_('The protected configuration is not a valid JSON object.'));
		}
		$settings = $this->migrateConfigCompatibility($settings);
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
		foreach (['alert_recipients', 'nws_zones', 'quiet_critical_events', 'announcement_groups', 'desktop_clients', 'scheduled_announcements', 'discord_webhooks', 'generic_webhooks', 'announcement_webhooks'] as $key) {
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
			if (!$this->nwsZoneHasDeliveryDestination($zoneGroup)) {
				$errors[] = _('One or more Weather Alert zone groups have no phone, desktop, email, Discord, or generic webhook destinations.');
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
		$errors = array_merge($errors, $this->validateScheduledAnnouncementRecurrences($schedules, (array)($settings['announcement_groups'] ?? [])));
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
			$this->validateWebhookDestinations($settings['generic_webhooks'] ?? [], 'generic'),
			$this->validateWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement')
		);
		try { $this->announcementEmailTargets($settings); }
		catch (\DomainException $error) { $errors[] = $error->getMessage(); }
		$errors = array_merge($errors, $this->validateNwsZoneDestinationAssignments(
			$settings['nws_zones'] ?? [],
			$settings
		));
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
		if (!is_string($contents) || !in_array($mode, [0600, 0640], true) || realpath(dirname($path)) !== dirname($path)) {
			throw new \RuntimeException(_('Unable to write a protected backup staging file.'));
		}
		$mask = umask(0077);
		try { $handle = @fopen($path, 'x+b'); } finally { umask($mask); }
		if (!$handle) { throw new \RuntimeException(_('A backup staging file already exists or cannot be created. Existing files were preserved.')); }
		$complete = false;
		try {
			$offset = 0;
			while ($offset < strlen($contents)) {
				$count = fwrite($handle, substr($contents, $offset));
				if ($count === false || $count === 0) { throw new \RuntimeException(_('The backup staging write was incomplete. Check available space and storage health.')); }
				$offset += $count;
			}
			if (!@chmod($path, $mode) || !fflush($handle) || !fsync($handle)) {
				throw new \RuntimeException(_('The backup staging file could not be secured and synchronized. Check storage health.'));
			}
			$opened = fstat($handle); clearstatcache(true, $path); $current = @lstat($path);
			if (!$current || $current['nlink'] !== 1 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
				throw new \RuntimeException(_('The backup staging file changed unexpectedly. Preserve the private backup directory and review storage access.'));
			}
			$directory = @fopen(dirname($path), 'r');
			if (!$directory) { throw new \RuntimeException(_('The backup directory could not be opened for synchronization.')); }
			try { if (!fsync($directory)) { throw new \RuntimeException(_('The backup directory could not be synchronized. Check storage health.')); } }
			finally { fclose($directory); }
			$complete = true;
		} finally {
			fclose($handle);
			if (!$complete) { @unlink($path); }
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
		$allowedStates = ['pending', 'claimed', 'preparing', 'queued', 'running', 'success', 'failed', 'missed', 'uncertain'];
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
			if (array_key_exists('job_id', $record)) {
				if (!is_string($record['job_id']) || !preg_match('/^job_[a-f0-9]{32}$/D', $record['job_id'])
					|| !is_string($record['request_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $record['request_fingerprint'])
					|| !is_int($record['deadline_at'] ?? null) || $record['deadline_at'] < 1
					|| !is_string($record['job_state'] ?? null) || !in_array($record['job_state'], ['prepared', 'queued', 'worker_starting', 'running', 'complete', 'failed', 'partial_or_failed', 'expired'], true)) {
					throw new \RuntimeException(_('The scheduling journal contains an invalid durable job link.'));
				}
			} elseif (in_array($state, ['preparing', 'queued', 'running'], true)) {
				throw new \RuntimeException(_('The scheduling journal is missing a durable job link.'));
			}
			if (isset($record['deadline_at']) && (!is_int($record['deadline_at']) || $record['deadline_at'] < 1)) {
				throw new \RuntimeException(_('The scheduling journal contains an invalid deadline.'));
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
					SlsConfigCrypto::MAX_FILE_BYTES
				),
				'key' => null,
				'schedule' => null,
				'tones' => [],
			],
		];
		if (($files['key'] ?? null) !== null) {
			if (!is_array($files['key'])) { throw new \RuntimeException(_('The encrypted configuration recovery-key inventory is invalid.')); }
			$normalized['files']['key'] = $this->validateNativeBackupManifestRecord(
				$files['key'], 'slsmassnotify-config-key', 'protected-config-key.json', SlsConfigCrypto::MAX_KEYRING_BYTES
			);
		}
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
        $normalized['files']['sms']=null;
        if (($files['sms']??null)!==null) {
            if (!is_array($files['sms'])) { throw new \RuntimeException(_('The SMS continuity inventory is invalid.')); }
            $normalized['files']['sms']=$this->validateNativeBackupManifestRecord($files['sms'],'slsmassnotify-sms','sms-ledger.jsonl',\SLS\MassNotify\Sms\Store::BACKUP_MAX_BYTES);
        }
		$normalized['files']['operational'] = null;
		if (($files['operational'] ?? null) !== null) {
			if (!is_array($files['operational'])) { throw new \RuntimeException(_('The operational recovery inventory is invalid.')); }
			$normalized['files']['operational'] = $this->validateNativeBackupManifestRecord($files['operational'], 'slsmassnotify-operational', 'operational-evidence.jsonl', 64 * 1024 * 1024);
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
			$normalized['files']['key'] === null ? [] : [$normalized['files']['key']],
			$normalized['files']['schedule'] === null ? [] : [$normalized['files']['schedule']],
            ($normalized['files']['sms']??null) === null ? [] : [$normalized['files']['sms']],
			($normalized['files']['operational'] ?? null) === null ? [] : [$normalized['files']['operational']],
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
			($manifest['files']['key'] ?? null) === null ? [] : [$manifest['files']['key']],
			$manifest['files']['schedule'] === null ? [] : [$manifest['files']['schedule']],
            ($manifest['files']['sms']??null) === null ? [] : [$manifest['files']['sms']],
			($manifest['files']['operational'] ?? null) === null ? [] : [$manifest['files']['operational']],
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
			SlsConfigCrypto::MAX_FILE_BYTES,
			_('The restored protected configuration is unreadable.')
		);
		if (!hash_equals($configRecord['sha256'], hash('sha256', $configRaw))) {
			throw new \RuntimeException(_('The restored configuration changed after manifest verification. No configuration was restored.'));
		}
		if (($manifest['files']['key'] ?? null) !== null) {
			$keyRecord = $manifest['files']['key'];
			$keyFile = $found[$keyRecord['type'] . "\0" . $keyRecord['archive_name']];
			$keyRaw = $this->readNativeBackupFile($keyFile['path'], SlsConfigCrypto::MAX_KEYRING_BYTES,
				_('The restored configuration recovery key is unreadable.'));
			if (!hash_equals($keyRecord['sha256'], hash('sha256', $keyRaw))) {
				throw new \RuntimeException(_('The restored configuration recovery key changed after manifest verification. No configuration was restored.'));
			}
			$configRaw = json_encode(SlsConfigCrypto::decodeBackup($configRaw, $keyRaw),
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		} elseif (SlsConfigCrypto::isEncrypted(json_decode($configRaw, true))) {
			throw new \RuntimeException(_('The encrypted native backup is missing its recovery key. No configuration was restored.'));
		}
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
			if (!hash_equals($scheduleRecord['sha256'], hash('sha256', $scheduleRaw))) {
				throw new \RuntimeException(_('The restored scheduling journal changed after manifest verification. No configuration was restored.'));
			}
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
            'sms' => ($manifest['files']['sms']??null)===null ? null : $found['slsmassnotify-sms'."\0".$manifest['files']['sms']['archive_name']],
			'operational' => ($manifest['files']['operational'] ?? null) === null ? null : $found['slsmassnotify-operational' . "\0" . $manifest['files']['operational']['archive_name']],
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
		// Job payloads are not restored by the native config backup. Preserve links
		// for diagnosis, but never reconstruct or activate their past deliveries.
		foreach ($ledger['occurrences'] as &$restoredRecord) {
			if (!is_array($restoredRecord) || empty($restoredRecord['job_id'])
				|| in_array($restoredRecord['state'] ?? '', ['success', 'failed', 'missed', 'uncertain'], true)) { continue; }
			$restoredRecord['state'] = 'uncertain';
			$restoredRecord['message'] = _('A linked announcement job was not restored with this backup. It was not recreated or replayed.');
			$restoredRecord['updated_at'] = gmdate('c', $now);
			$restoredRecord['completed_at'] = gmdate('c', $now);
			$ledgerChanged = true; $disableSchedules = true;
		}
		unset($restoredRecord);
		if ($disableSchedules && $ledgerChanged) { $warnings[] = _('Schedules were disabled because linked delivery jobs require review after restore.'); }

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
        foreach ($settings['automations']['rules'] ?? [] as $index => $rule) {
            if (!empty($rule['enabled'])) { $settings['automations']['rules'][$index]['enabled']=false; $configChanged=true; }
        }
        if (!empty($settings['automations']['rules'])) { $warnings[]=_('Automatic triggers were disabled after restore. Review enrolled identities, location assignments, recipients and script approvals before rearming them.'); }
        if ($this->disableEnterpriseAfterRecovery($settings)) { $configChanged=true; $warnings[]=_('Enterprise Labs were disabled after recovery. Review peer identity, witness history, directory grants and physical-provider permissions before re-enabling. Dangerous features require a fresh acknowledgment; bearer sessions and operational work are not restored into execution.'); }
        if (!empty($settings['announcement_sms']['enabled'])) {
            $settings['announcement_sms']['enabled']=false; $configChanged=true;
            $warnings[]=_('SMS was disabled after restore. Review restored recipient consent, provider routing and retained spending/opt-out history before enabling it. No SMS deliveries were replayed.');
        }
        if (!empty($settings['outbound_voice']['enabled'])) {
            $settings['outbound_voice']['enabled'] = '0'; $configChanged = true;
            $warnings[] = _('External voice was disabled after restore. Review approved numbers, trunk routing and the daily call allowance before enabling it. Pre-restore call usage may be absent on a replacement PBX; no external calls were replayed.');
        }
		if (!empty($settings['enabled']) || !empty($settings['xweather']['enabled'])) {
			$settings['enabled'] = '0';
			if (is_array($settings['xweather'] ?? null)) { $settings['xweather']['enabled'] = '0'; }
			$configChanged = true;
			$warnings[] = _('Weather and lightning automation were disabled after restore. Historical deduplication, storm and delivery evidence is not reactivated. Review current alerts, recipient routing and any recovery archive before enabling each service; do not assume a restored storm has cleared.');
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

	private function commitNativeRestorePayload($configRaw, $scheduleRaw, $schedulePresent, array $tones, $transactionId, array $warnings, ?array $sms = null, ?array $operational = null)
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
		$preserveRecovery = false;
		try {
			$stageConfig = $stageRoot . '/mass-notifications.config';
			$this->writeNativeSnapshotFile($stageConfig, SlsConfigCrypto::encode($this->validateNativeBackupConfig((string)$configRaw)), 0600);
            $stageSms=null;
            if ($sms!==null) {
                $stageSms=$stageRoot.'/sms-ledger.jsonl';
                $raw=$this->readNativeBackupFile($sms['path']??'',\SLS\MassNotify\Sms\Store::BACKUP_MAX_BYTES,_('The SMS continuity backup is unreadable.'));
                if (!hash_equals($sms['record']['sha256']??'',hash('sha256',$raw))) { throw new \RuntimeException(_('The SMS continuity backup changed before restore.')); }
                $this->writeNativeSnapshotFile($stageSms,$raw,0600); unset($raw);
            }
			if ($operational !== null) {
				$archived = $this->operationalBackupCommand('archive', (string)($operational['path'] ?? ''), (string)($operational['record']['sha256'] ?? ''));
				if (!preg_match('/^[a-f0-9]{64}\.jsonl$/D', (string)($archived['archive_name'] ?? ''))) { throw new \RuntimeException(_('The operational recovery archive identity is invalid.')); }
				$warnings[] = sprintf(_('Operational history was retained for review in %s/recovery-archives/%s. It was not loaded into queues, desktop inboxes or active incidents.'), self::PLUGIN_DATA_DIR, $archived['archive_name']);
			} else {
				$warnings[] = _('This older backup has no operational recovery evidence. Existing delivery history was preserved; no historical queues, receipts or storm state were recreated.');
			}
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
			$configLock = $this->acquireSettingsLock(true);
			$announcementLock = $this->acquireNativeBackupFileLock(
				self::ANNOUNCEMENT_LOCK_FILE,
				_('An active announcement did not complete in time for restore.')
			);
			$restoredSettings = json_decode((string)$configRaw, true);
			if (!is_array($restoredSettings)) { throw new \RuntimeException(_('Restored configuration is not a JSON object.')); }
			$this->assertDesktopCapacityChange($restoredSettings, $this->loadSettingsFile(self::SETTINGS_JSON));
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
            // A failed config restore must not undo reservations or opt-outs.
            // The merge adds evidence only; existing sends and costs are never rewound.
            if ($stageSms!==null) { $this->announcementSmsStore()->mergeSnapshot($stageSms); }
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
				try {
					$this->restoreNativeRestoreTargets($rollback);
				} catch (\Throwable $rollbackError) {
					$preserveRecovery = true;
					$message = sprintf(_('Native restore failed and rollback could not be verified. Recovery copies were retained at %s and %s. Preserve both directories and repair the failed files before retrying restore.'), $stageRoot, $rollbackRoot);
					try { $this->updateStatusData(['last_native_restore_status' => 'fault', 'last_native_restore_message' => $message]); }
					catch (\Throwable $statusError) { /* The retained recovery files must survive a status-write failure too. */ }
					throw new \RuntimeException($message, 0, $rollbackError);
				}
			}
			throw $e;
		} finally {
			if (is_resource($configLock)) {
				$this->releaseSettingsLock($configLock);
			}
			$this->releaseNativeBackupFileLock($announcementLock);
			$this->releaseNativeBackupFileLock($workerLock);
			if (!$preserveRecovery) {
				$this->removeNativeBackupDirectory($stageRoot);
				$this->removeNativeBackupDirectory($rollbackRoot);
			}
		}
		return $warnings;
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
				$entry['sha256'] = $sourceHash;
				$entry['bytes'] = @filesize($backupPath);
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
			clearstatcache(true, $destination);
			$restored = @lstat($destination);
			if (!is_array($restored) || ($restored['mode'] & 0170000) !== 0100000 || $restored['nlink'] !== 1
				|| $restored['size'] !== ($entry['bytes'] ?? -1)
				|| ($restored['mode'] & 0777) !== (int)$entry['mode']
				|| !hash_equals((string)($entry['sha256'] ?? ''), (string)@hash_file('sha256', $destination))) {
				$failures[] = $destination;
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
		$decoded = json_decode($this->readNativeBackupFile(
			self::FREEPBX_RESTORE_MARKER, 32768, _('The protected post-restore repair marker changed or cannot be read safely.')
		), true);
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

	private function verifyNativePostRestoreIntegration($rootChecks = true)
	{
		$runAs = $rootChecks ? '/usr/sbin/runuser -u asterisk -- ' : '';
		if (!$rootChecks) { $this->requireRuntimeAccount(); }
		$this->verifyPhoneEventCollectorService();
		$this->verifyPhoneEventCollectorReadiness();
		$settings = $this->validateNativeBackupConfig($this->readNativeBackupFile(
			self::SETTINGS_JSON,
			SlsConfigCrypto::MAX_FILE_BYTES,
			_('Post-restore verification could not read the protected configuration.')
		));
		$requiredFiles = [
			__DIR__ . '/Backup.php' => false,
			__DIR__ . '/ConfigCrypto.php' => false,
			__DIR__ . '/OperationalBackup.php' => false,
			__DIR__ . '/AdminAudit.php' => false,
			__DIR__ . '/SecurityAudit.php' => false,
			__DIR__ . '/Restore.php' => false,
			__DIR__ . '/AnnouncementDelivery.php' => false,
			__DIR__ . '/AnnouncementAdmission.php' => false,
			__DIR__ . '/ScheduledDelivery.php' => false,
			__DIR__ . '/SchedulePresentation.php' => false,
			__DIR__ . '/UpdatePolicy.php' => false,
			__DIR__ . '/TestProfiles.php' => false,
			__DIR__ . '/PhoneEventService.php' => false,
			__DIR__ . '/OutboundVoice.php' => false,
			__DIR__ . '/AnnouncementEmail.php' => false,
			__DIR__ . '/RecipientSelection.php' => false,
			self::RUNTIME_DIR . '/sls_notify.py' => true,
			self::RUNTIME_DIR . '/sls_config.py' => true,
			self::RUNTIME_DIR . '/sls_config_crypto.py' => true,
			self::RUNTIME_DIR . '/sls_config_crypto.php' => false,
			self::RUNTIME_DIR . '/sls_branded_email.py' => true,
			self::RUNTIME_DIR . '/sls_announcement_email.py' => true,
			self::RUNTIME_DIR . '/sls_branded_discord.py' => true,
			self::RUNTIME_DIR . '/sls_notification_destinations.py' => true,
			self::RUNTIME_DIR . '/sls_system_notifications.py' => true,
			self::RUNTIME_DIR . '/sls_nws_status.py' => true,
			self::RUNTIME_DIR . '/sls_nws_delivery_claims.py' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py' => true,
			self::RUNTIME_DIR . '/sls_lightning_providers.py' => false,
			self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_test.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_update.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_maintenance.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_uninstall.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_automation_worker.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_cluster_worker.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_cluster_effect.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_incident_response.php' => false,
			self::RUNTIME_DIR . '/sls_cluster_guard.py' => true,
            self::RUNTIME_DIR . '/sls_mass_notify_delivery_authorization.php' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_panic.php' => true,
			self::RUNTIME_DIR . '/sls_trigger_actions.py' => true,
			self::RUNTIME_DIR . '/sls_announcement_jobs.php' => false,
			self::RUNTIME_DIR . '/sls_api_test_guard.php' => false,
			self::RUNTIME_DIR . '/sls_storage_maintenance.py' => true,
			self::RUNTIME_DIR . '/sls_audit_separation.py' => true,
			self::RUNTIME_DIR . '/sls_operational_backup.py' => true,
			self::RUNTIME_DIR . '/sls_external_monitor.py' => true,
			self::RUNTIME_DIR . '/sls_resource_capacity.py' => true,
			self::RUNTIME_DIR . '/sls_phone_admission.py' => true,
			self::RUNTIME_DIR . '/sls_phone_events.py' => true,
			self::RUNTIME_DIR . '/sls_phone_outcomes.py' => true,
			self::RUNTIME_DIR . '/sls_outbound_routes.py' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_phone_agi.py' => true,
			self::RUNTIME_DIR . '/sls_mass_notify_live_paging.php' => true,
			self::RUNTIME_DIR . '/sls_live_paging.php' => false,
			self::RUNTIME_DIR . '/sls_piper_dependencies.py' => true,
			self::RUNTIME_DIR . '/sls_piper_environment.py' => true,
			self::RUNTIME_DIR . '/external-acknowledgement.wav' => false,
			self::RUNTIME_DIR . '/sls_update_policy.py' => true,
			self::RUNTIME_DIR . '/sls_module_trust.py' => true,
			self::RUNTIME_DIR . '/sls_privileged_install.py' => true,
			'/usr/local/bin/slsconsole' => true,
			self::RUNTIME_DIR . '/sls_console.py' => true,
			self::RUNTIME_DIR . '/sls_runtime_state.php' => false,
			self::RUNTIME_DIR . '/sls_installer_recovery.py' => true,
			self::RUNTIME_DIR . '/sls_install_idle.py' => true,
			self::RUNTIME_DIR . '/sls_install_guard.py' => true,
			self::RUNTIME_DIR . '/sls_weather_queue.py' => true,
			self::RUNTIME_DIR . '/sls_audio_queue.py' => true,
			self::RUNTIME_DIR . '/sls_audio_state.py' => true,
			self::RUNTIME_DIR . '/sls_release_verify.py' => true,
			self::RUNTIME_DIR . '/release-signing.pub' => false,
			self::RUNTIME_DIR . '/piper-requirements.txt' => false,
			self::RUNTIME_DIR . '/piper-requirements.lock' => false,
			self::RUNTIME_DIR . '/piper-packaging.lock' => false,
			self::RUNTIME_DIR . '/piper-artifacts.json' => false,
			self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh' => true,
			'/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php' => false,
			'/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php' => false,
			'/etc/apache2/conf-enabled/sls-mass-notify.conf' => false,
			'/var/www/html/api/sipnotify/index.php' => false,
			'/var/www/html/mass-notify/index.php' => false,
			'/var/www/html/api/sls-mass-notify/edge-view.php' => false,
			'/var/www/html/api/sls-mass-notify/edge.php' => false,
			'/var/www/html/api/sls-mass-notify/peer.php' => false,
			'/var/www/html/mass-notify/subscriber.php' => false,
			'/var/www/html/mass-notify/sso.php' => false,
			'/var/www/html/mass-notify/.htaccess' => false,
			'/var/www/html/api/sipnotify/.htaccess' => false,
			'/var/www/html/api/sls-mass-notify/index.php' => false,
			'/var/www/html/api/sls-mass-notify/contract.php' => false,
			'/var/www/html/api/sls-mass-notify/security.php' => false,
			'/var/www/html/api/sls-mass-notify/config-crypto.php' => false,
			'/var/www/html/api/sls-mass-notify/event-log.php' => false,
			'/var/www/html/api/sls-mass-notify/media.php' => false,
			'/var/www/html/api/sls-mass-notify/media-policy.php' => false,
			'/var/www/html/api/sls-mass-notify/sms-callback.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/Config.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/Store.php' => false,
            '/var/www/html/api/sls-mass-notify/sms/Continuity.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/Provider.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/Service.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/.htaccess' => false,
			'/var/www/html/api/sls-mass-notify/sms/vendor/twilio/RequestValidator.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/vendor/twilio/Values.php' => false,
			'/var/www/html/api/sls-mass-notify/sms/vendor/twilio/LICENSE' => false,
			'/var/www/html/api/sls-mass-notify/sms/vendor/twilio/UPSTREAM.json' => false,
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
			__DIR__ . '/bin/sls_mass_notify/sls_config_crypto.py' => self::RUNTIME_DIR . '/sls_config_crypto.py',
			__DIR__ . '/api/sls-mass-notify/config-crypto.php' => self::RUNTIME_DIR . '/sls_config_crypto.php',
			__DIR__ . '/bin/sls_mass_notify/sls_config_crypto.php' => self::RUNTIME_DIR . '/sls_config_crypto.php',
			__DIR__ . '/bin/sls_mass_notify/sls_announcement_jobs.php' => self::RUNTIME_DIR . '/sls_announcement_jobs.php',
			__DIR__ . '/bin/sls_mass_notify/sls_api_test_guard.php' => self::RUNTIME_DIR . '/sls_api_test_guard.php',
			__DIR__ . '/bin/sls_mass_notify/sls_notify.py' => self::RUNTIME_DIR . '/sls_notify.py',
			__DIR__ . '/bin/sls_mass_notify/sls_config.py' => self::RUNTIME_DIR . '/sls_config.py',
			__DIR__ . '/bin/sls_mass_notify/sls_branded_email.py' => self::RUNTIME_DIR . '/sls_branded_email.py',
			__DIR__ . '/bin/sls_mass_notify/sls_announcement_email.py' => self::RUNTIME_DIR . '/sls_announcement_email.py',
			__DIR__ . '/bin/sls_mass_notify/sls_branded_discord.py' => self::RUNTIME_DIR . '/sls_branded_discord.py',
			__DIR__ . '/bin/sls_mass_notify/sls_notification_destinations.py' => self::RUNTIME_DIR . '/sls_notification_destinations.py',
			__DIR__ . '/bin/sls_mass_notify/sls_system_notifications.py' => self::RUNTIME_DIR . '/sls_system_notifications.py',
			__DIR__ . '/bin/sls_mass_notify/sls_nws_status.py' => self::RUNTIME_DIR . '/sls_nws_status.py',
			__DIR__ . '/bin/sls_mass_notify/sls_nws_delivery_claims.py' => self::RUNTIME_DIR . '/sls_nws_delivery_claims.py',
			__DIR__ . '/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py' => self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py',
			__DIR__ . '/bin/sls_mass_notify/sls_lightning_providers.py' => self::RUNTIME_DIR . '/sls_lightning_providers.py',
			__DIR__ . '/bin/sls_mass_notify_nws_poll.sh' => self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh',
			__DIR__ . '/bin/sls_mass_notify_test.sh' => self::RUNTIME_DIR . '/sls_mass_notify_test.sh',
			__DIR__ . '/bin/sls_mass_notify_update.sh' => self::RUNTIME_DIR . '/sls_mass_notify_update.sh',
			__DIR__ . '/bin/sls_mass_notify_maintenance.sh' => self::RUNTIME_DIR . '/sls_mass_notify_maintenance.sh',
			__DIR__ . '/bin/sls_mass_notify_uninstall.sh' => self::RUNTIME_DIR . '/sls_mass_notify_uninstall.sh',
			__DIR__ . '/bin/sls_mass_notify_install_piper_voices.sh' => self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh',
			__DIR__ . '/bin/sls_mass_notify_schedule_worker.php' => self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php',
			__DIR__ . '/bin/sls_mass_notify_announcement_worker.php' => self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php',
			__DIR__ . '/bin/sls_mass_notify_automation_worker.php' => self::RUNTIME_DIR . '/sls_mass_notify_automation_worker.php',
			__DIR__ . '/bin/sls_mass_notify_cluster_worker.php' => self::RUNTIME_DIR . '/sls_mass_notify_cluster_worker.php',
			__DIR__ . '/bin/sls_mass_notify_cluster_effect.php' => self::RUNTIME_DIR . '/sls_mass_notify_cluster_effect.php',
			__DIR__ . '/bin/sls_mass_notify/sls_mass_notify_incident_response.php' => self::RUNTIME_DIR . '/sls_mass_notify_incident_response.php',
			__DIR__ . '/bin/sls_mass_notify/sls_cluster_guard.py' => self::RUNTIME_DIR . '/sls_cluster_guard.py',
            __DIR__ . '/bin/sls_mass_notify_delivery_authorization.php' => self::RUNTIME_DIR . '/sls_mass_notify_delivery_authorization.php',
			__DIR__ . '/bin/sls_mass_notify_panic.php' => self::RUNTIME_DIR . '/sls_mass_notify_panic.php',
			__DIR__ . '/bin/sls_mass_notify/sls_trigger_actions.py' => self::RUNTIME_DIR . '/sls_trigger_actions.py',
			__DIR__ . '/bin/sls_mass_notify/sls_storage_maintenance.py' => self::RUNTIME_DIR . '/sls_storage_maintenance.py',
			__DIR__ . '/bin/sls_mass_notify/sls_audit_separation.py' => self::RUNTIME_DIR . '/sls_audit_separation.py',
			__DIR__ . '/bin/sls_mass_notify/sls_operational_backup.py' => self::RUNTIME_DIR . '/sls_operational_backup.py',
			__DIR__ . '/bin/sls_mass_notify/sls_external_monitor.py' => self::RUNTIME_DIR . '/sls_external_monitor.py',
			__DIR__ . '/bin/sls_mass_notify/sls_resource_capacity.py' => self::RUNTIME_DIR . '/sls_resource_capacity.py',
			__DIR__ . '/bin/sls_mass_notify/sls_phone_admission.py' => self::RUNTIME_DIR . '/sls_phone_admission.py',
			__DIR__ . '/bin/sls_mass_notify/sls_phone_events.py' => self::RUNTIME_DIR . '/sls_phone_events.py',
			__DIR__ . '/bin/sls_mass_notify/sls_emergency_observer.py' => self::RUNTIME_DIR . '/sls_emergency_observer.py',
			__DIR__ . '/bin/sls_mass_notify/sls_phone_outcomes.py' => self::RUNTIME_DIR . '/sls_phone_outcomes.py',
			__DIR__ . '/bin/sls_mass_notify/sls_outbound_routes.py' => self::RUNTIME_DIR . '/sls_outbound_routes.py',
			__DIR__ . '/bin/sls_mass_notify_phone_agi.py' => self::RUNTIME_DIR . '/sls_mass_notify_phone_agi.py',
			__DIR__ . '/bin/sls_mass_notify/sls_live_paging.php' => self::RUNTIME_DIR . '/sls_live_paging.php',
			__DIR__ . '/bin/sls_mass_notify_live_paging.php' => self::RUNTIME_DIR . '/sls_mass_notify_live_paging.php',
            __DIR__ . '/bin/sls_mass_notify_weather_channels.php' => self::RUNTIME_DIR . '/sls_mass_notify_weather_channels.php',
            __DIR__ . '/bin/sls_mass_notify/sls_weather_channels.py' => self::RUNTIME_DIR . '/sls_weather_channels.py',
            __DIR__ . '/bin/sls_mass_notify/sls_lightning_providers.py' => self::RUNTIME_DIR . '/sls_lightning_providers.py',
			__DIR__ . '/bin/sls_mass_notify/sls_piper_dependencies.py' => self::RUNTIME_DIR . '/sls_piper_dependencies.py',
			__DIR__ . '/bin/sls_mass_notify/sls_piper_environment.py' => self::RUNTIME_DIR . '/sls_piper_environment.py',
			__DIR__ . '/bin/sls_mass_notify/external-acknowledgement.wav' => self::RUNTIME_DIR . '/external-acknowledgement.wav',
			__DIR__ . '/bin/sls_mass_notify/sls_update_policy.py' => self::RUNTIME_DIR . '/sls_update_policy.py',
			__DIR__ . '/bin/sls_mass_notify/sls_module_trust.py' => self::RUNTIME_DIR . '/sls_module_trust.py',
			__DIR__ . '/bin/sls_mass_notify/sls_privileged_install.py' => self::RUNTIME_DIR . '/sls_privileged_install.py',
			__DIR__ . '/bin/slsconsole' => '/usr/local/bin/slsconsole',
			__DIR__ . '/bin/sls_mass_notify/sls_console.py' => self::RUNTIME_DIR . '/sls_console.py',
			__DIR__ . '/bin/sls_mass_notify/sls_runtime_state.php' => self::RUNTIME_DIR . '/sls_runtime_state.php',
			__DIR__ . '/bin/sls_mass_notify/sls_installer_recovery.py' => self::RUNTIME_DIR . '/sls_installer_recovery.py',
			__DIR__ . '/bin/sls_mass_notify/sls_install_idle.py' => self::RUNTIME_DIR . '/sls_install_idle.py',
			__DIR__ . '/bin/sls_mass_notify/sls_install_guard.py' => self::RUNTIME_DIR . '/sls_install_guard.py',
			__DIR__ . '/bin/sls_mass_notify/sls_weather_queue.py' => self::RUNTIME_DIR . '/sls_weather_queue.py',
			__DIR__ . '/bin/sls_mass_notify/sls_audio_queue.py' => self::RUNTIME_DIR . '/sls_audio_queue.py',
			__DIR__ . '/bin/sls_mass_notify/sls_audio_state.py' => self::RUNTIME_DIR . '/sls_audio_state.py',
			__DIR__ . '/bin/sls_mass_notify/sls_release_verify.py' => self::RUNTIME_DIR . '/sls_release_verify.py',
			__DIR__ . '/bin/sls_mass_notify/release-signing.pub' => self::RUNTIME_DIR . '/release-signing.pub',
			__DIR__ . '/bin/sls_mass_notify/piper-requirements.txt' => self::RUNTIME_DIR . '/piper-requirements.txt',
			__DIR__ . '/bin/sls_mass_notify/piper-requirements.lock' => self::RUNTIME_DIR . '/piper-requirements.lock',
			__DIR__ . '/bin/sls_mass_notify/piper-packaging.lock' => self::RUNTIME_DIR . '/piper-packaging.lock',
			__DIR__ . '/bin/sls_mass_notify/piper-artifacts.json' => self::RUNTIME_DIR . '/piper-artifacts.json',
			__DIR__ . '/bin/sls_mass_notify_weather_poll.sh' => self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh',
			__DIR__ . '/dashboard/sections/SlsMassNotifyAnnouncement.class.php' => '/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php',
			__DIR__ . '/dashboard/views/sections/sls-mass-notify-announcement.php' => '/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php',
			__DIR__ . '/api/sipnotify/index.php' => '/var/www/html/api/sipnotify/index.php',
			__DIR__ . '/portal/index.php' => '/var/www/html/mass-notify/index.php',
			__DIR__ . '/api/sls-mass-notify/edge-view.php' => '/var/www/html/api/sls-mass-notify/edge-view.php',
			__DIR__ . '/api/sls-mass-notify/edge.php' => '/var/www/html/api/sls-mass-notify/edge.php',
			__DIR__ . '/api/sls-mass-notify/peer.php' => '/var/www/html/api/sls-mass-notify/peer.php',
			__DIR__ . '/portal/subscriber.php' => '/var/www/html/mass-notify/subscriber.php',
			__DIR__ . '/portal/sso.php' => '/var/www/html/mass-notify/sso.php',
			__DIR__ . '/portal/.htaccess' => '/var/www/html/mass-notify/.htaccess',
			__DIR__ . '/api/sipnotify/.htaccess' => '/var/www/html/api/sipnotify/.htaccess',
			__DIR__ . '/api/sls-mass-notify/index.php' => '/var/www/html/api/sls-mass-notify/index.php',
			__DIR__ . '/api/sls-mass-notify/contract.php' => '/var/www/html/api/sls-mass-notify/contract.php',
			__DIR__ . '/api/sls-mass-notify/security.php' => '/var/www/html/api/sls-mass-notify/security.php',
			__DIR__ . '/api/sls-mass-notify/config-crypto.php' => '/var/www/html/api/sls-mass-notify/config-crypto.php',
			__DIR__ . '/api/sls-mass-notify/event-log.php' => '/var/www/html/api/sls-mass-notify/event-log.php',
			__DIR__ . '/api/sls-mass-notify/media.php' => '/var/www/html/api/sls-mass-notify/media.php',
			__DIR__ . '/api/sls-mass-notify/media-policy.php' => '/var/www/html/api/sls-mass-notify/media-policy.php',
			__DIR__ . '/api/sls-mass-notify/sms-callback.php' => '/var/www/html/api/sls-mass-notify/sms-callback.php',
			__DIR__ . '/api/sls-mass-notify/trigger.php' => '/var/www/html/api/sls-mass-notify/trigger.php',
			__DIR__ . '/api/sls-mass-notify/sms/Config.php' => '/var/www/html/api/sls-mass-notify/sms/Config.php',
			__DIR__ . '/api/sls-mass-notify/sms/Store.php' => '/var/www/html/api/sls-mass-notify/sms/Store.php',
            __DIR__ . '/api/sls-mass-notify/sms/Continuity.php' => '/var/www/html/api/sls-mass-notify/sms/Continuity.php',
			__DIR__ . '/api/sls-mass-notify/sms/Provider.php' => '/var/www/html/api/sls-mass-notify/sms/Provider.php',
			__DIR__ . '/api/sls-mass-notify/sms/Service.php' => '/var/www/html/api/sls-mass-notify/sms/Service.php',
			__DIR__ . '/api/sls-mass-notify/sms/.htaccess' => '/var/www/html/api/sls-mass-notify/sms/.htaccess',
			__DIR__ . '/api/sls-mass-notify/sms/vendor/twilio/RequestValidator.php' => '/var/www/html/api/sls-mass-notify/sms/vendor/twilio/RequestValidator.php',
			__DIR__ . '/api/sls-mass-notify/sms/vendor/twilio/Values.php' => '/var/www/html/api/sls-mass-notify/sms/vendor/twilio/Values.php',
			__DIR__ . '/api/sls-mass-notify/sms/vendor/twilio/LICENSE' => '/var/www/html/api/sls-mass-notify/sms/vendor/twilio/LICENSE',
			__DIR__ . '/api/sls-mass-notify/sms/vendor/twilio/UPSTREAM.json' => '/var/www/html/api/sls-mass-notify/sms/vendor/twilio/UPSTREAM.json',
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
			'es_ES-davefx-medium.onnx' => '6658b03b1a6c316ee4c265a9896abc1393353c2d9e1bca7d66c2c442e222a917',
			'es_ES-davefx-medium.onnx.json' => '0e0dda87c732f6f38771ff274a6380d9252f327dca77aa2963d5fbdf9ec54842',
			'fr_FR-siwis-medium.onnx' => '641d1ab097da2b81128c076810edb052b385decc8be3381814802a64a73baf99',
			'fr_FR-siwis-medium.onnx.json' => '39479916c2db192b5ac9764daddd0c744d83e023ad890c6976c0633ae4df8959',
			'de_DE-thorsten-low.onnx' => '9ac27fad17cec5c1a791161976a64f026f16fc058b400b1fea62565b8b2cf375',
			'de_DE-thorsten-low.onnx.json' => 'df38e892ed949f62d0c40978bc666de0b341c5f7921840ae7c72f4df8e8ffa05',
			'pt_BR-faber-medium.onnx' => '858555e3a064209c57088fe6bd70c4c3dc54d03eaa00c45d5ecaf43a33f95aa7',
			'pt_BR-faber-medium.onnx.json' => '7e694de195ae3fc36dd732c445eb04fb49b649854893cb5506b978f0d50a1d6f',
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
				$runAs . '/usr/bin/test -w ' . escapeshellarg($spoolDir)
				. ' && ' . $runAs . '/usr/bin/test -x ' . escapeshellarg($spoolDir)
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
				$runAs . '/usr/bin/test -w ' . escapeshellarg($writableSoundDir)
				. ' && ' . $runAs . '/usr/bin/test -x ' . escapeshellarg($writableSoundDir)
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
		if (substr_count($cronText, '* * * * * /usr/bin/timeout 5500 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh') !== 1
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
					|| !preg_match('/Page\(\$\{SLS_DIAL\},b\(sls-alert-autoanswer\^s\^1\(\$\{EXTEN\}\)\)A\(\$\{SLS_SAFE_SOUND\}\)inq,[1-5]\)/', $dialplanText)
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
			$runAs . '/usr/bin/timeout 20 /usr/bin/python3 '
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
			throw new \RuntimeException(_('Post-restore verification could not complete the authenticated Asterisk Manager capability checks.') . ' ' . $this->amiEndpointDiagnostic($ami));
		}
		$notifyCapabilityOutput = [];
		$notifyCapabilityStatus = 1;
		@exec(
			$runAs . '/usr/bin/timeout 20 /usr/bin/python3 '
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
			$runAs . '/usr/bin/timeout 20 /usr/bin/python3 '
			. escapeshellarg(self::VISUAL_PUSH_SCRIPT) . ' --list-endpoints-json 2>/dev/null',
			$endpointOutput,
			$endpointStatus
		);
		$endpointJson = json_decode(implode("\n", $endpointOutput), true);
		if ($endpointStatus !== 0 || !is_array($endpointJson)) {
			throw new \RuntimeException(_('Post-restore verification could not complete an authenticated Asterisk Manager inventory request.'));
		}
		$pythonSyntaxCommand = '/usr/bin/python3 -I -c '
			. escapeshellarg('import pathlib,sys; [compile(pathlib.Path(p).read_text(encoding="utf-8"), p, "exec") for p in sys.argv[1:]]')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_notify.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_phone_admission.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_phone_events.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_phone_outcomes.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_outbound_routes.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_phone_agi.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_xweather_poll.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_notification_destinations.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_system_notifications.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_nws_status.py')
			. ' ' . escapeshellarg(self::RUNTIME_DIR . '/sls_nws_delivery_claims.py');
		$syntaxCommands = [
			'/usr/bin/php -l ' . escapeshellarg(__FILE__),
			'/usr/bin/php -l ' . escapeshellarg('/var/www/html/admin/views/menu_items.php'),
			'/usr/bin/php -l ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php'),
			'/usr/bin/php -l ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php'),
			'/usr/bin/php -l ' . escapeshellarg(self::RUNTIME_DIR . '/sls_announcement_jobs.php'),
			'/usr/bin/php -l ' . escapeshellarg(__DIR__ . '/AnnouncementDelivery.php'),
			'/usr/bin/php -l ' . escapeshellarg(__DIR__ . '/AnnouncementAdmission.php'),
			'/usr/bin/php -l ' . escapeshellarg(__DIR__ . '/ScheduledDelivery.php'),
			'/usr/bin/php -l ' . escapeshellarg(__DIR__ . '/SchedulePresentation.php'),
			'/usr/bin/php -l ' . escapeshellarg(__DIR__ . '/UpdatePolicy.php'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_nws_poll.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_weather_poll.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_update.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_maintenance.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_uninstall.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_test.sh'),
			'/bin/bash -n ' . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh'),
			$pythonSyntaxCommand,
			$runAs . '/usr/bin/timeout 20 '
				. escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_schedule_worker.php') . ' --self-test',
			$runAs . '/usr/bin/timeout 10 /usr/bin/php '
				. escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php') . ' --health-check --record-health',
		];
		if ($rootChecks) { $syntaxCommands[] = '/usr/sbin/apache2ctl configtest'; }
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
			'/api/sls-mass-notify/sms-callback.php' => ['405'],
		];
		foreach ($apiChecks as $path => $allowedCodes) {
			$targets = SlsLocalWebProbe::targets($probeHost);
			$probeDeadline = microtime(true) + 25;
			$accepted = false;
			foreach ($targets as $target) {
				$remainingSeconds = (int)ceil($probeDeadline - microtime(true));
				if ($remainingSeconds < 1) {
					break;
				}
				$curlCommand = '/usr/bin/curl -ksS --noproxy ' . escapeshellarg('*')
					. ' --connect-timeout 5 --max-time ' . min(15, $remainingSeconds) . ' -o /dev/null -w ' . escapeshellarg('%{http_code}');
				if ($target['resolve'] !== '') {
					$curlCommand .= ' --resolve ' . escapeshellarg($target['resolve']);
				}
				$probeOutput = [];
				$probeStatus = 1;
				@exec($curlCommand . ' ' . escapeshellarg($target['url'] . $path) . ' 2>/dev/null', $probeOutput, $probeStatus);
				$probeCode = trim(implode('', $probeOutput));
				if ($probeStatus === 0 && in_array($probeCode, $allowedCodes, true)) {
					$accepted = true;
					break;
				}
			}
			if (!$accepted) {
				throw new \RuntimeException(sprintf(_('Post-restore verification could not reach the protected local API route %s on the detected local Apache ports. Check Apache virtual-host routing and the application error log.'), $path));
			}
		}
		$mediaProbe = SlsLocalWebProbe::mediaAccess($settings);
		if (!$mediaProbe['ok']) { throw new \RuntimeException($mediaProbe['message']); }
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
		yield from SlsLocalWebProbe::responses(
			(string)($settings['public_pbx_host'] ?? ''), $path, $headers, $timeoutSeconds
		);
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
		$legacyAliases = [
			'nws' => 'weather',
			'xweather' => 'lightning',
			'test' => 'manual_test',
			'announcement' => 'dashboard',
			'announcement_audio' => 'dashboard',
			'schedule' => 'scheduling',
		];
		$type = $legacyAliases[$type] ?? $type;
		return in_array($type, ['weather', 'lightning', 'manual_test', 'dashboard', 'api', 'scheduling', 'desktop', 'system', 'other'], true) ? $type : '';
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
