<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Slsmassnotifyserver implements \BMO
{
	const MODULE_VERSION = '0.0.6-beta';
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
	const ASTERISK_SOUND_PREFIX = 'SLS_Mass_Notifications_Plugin';
	const ASTERISK_OUTGOING_SPOOL = '/var/spool/asterisk/outgoing';
	const TEST_SCRIPT = self::RUNTIME_DIR . '/sls_mass_notify_test.sh';
	const VISUAL_PUSH_SCRIPT = self::RUNTIME_DIR . '/sls_notify.py';
	const PIPER_VOICE_INSTALL_SCRIPT = self::RUNTIME_DIR . '/sls_mass_notify_install_piper_voices.sh';
	const TEST_COOLDOWN_FILE = self::PLUGIN_DATA_DIR . '/test-cooldown.ts';
	const ANNOUNCEMENT_COOLDOWN_FILE = self::PLUGIN_DATA_DIR . '/announcement-cooldown.ts';
	const CONTROL_API_AUDIT_LOG = self::PLUGIN_DATA_DIR . '/control-api-audit.jsonl';
	const CONTROL_API_RATE_FILE = self::PLUGIN_DATA_DIR . '/control-api-ratelimit.json';
	const REPAIR_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/repair.request';
	const UPDATE_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/update.request';
	const UNINSTALL_REQUEST_FILE = self::PLUGIN_DATA_DIR . '/uninstall.request';
	const TEST_COOLDOWN_SECONDS = 60;
	const ANNOUNCEMENT_COOLDOWN_SECONDS = 60;
	const MIN_ANNOUNCEMENT_COOLDOWN_SECONDS = 5;
	const MAX_ANNOUNCEMENT_COOLDOWN_SECONDS = 600;
	const HERO_IMAGE = 'modules/slsmassnotifyserver/assets/SLS_Mass_Notif_Plugin.png';
	const MAX_LIMIT = 500;
	const DEFAULT_LIMIT = 100;
	const CSRF_SESSION_KEY = 'slsmassnotifyserver_csrf_token';

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
	}

	public function install()
	{
		$this->ensurePluginDataDir();
		$this->ensureSystemDependencies();
		if (!is_readable(self::SETTINGS_JSON)) {
			$this->persistAppliedSettings($this->getActiveSettings());
		} else {
			// Updates and repair operations must never rewrite an existing central config.
			$this->getActiveSettings();
			$this->setPrivateOwnership(self::SETTINGS_JSON);
		}
		$this->installRuntimeFiles();
		$this->ensurePiperRuntime();
		$this->ensureAmiUser();
		$this->ensureDialplan();
		$this->ensureSipNotifyTemplates();
		$this->ensureApacheConfig();
		$this->ensureMenuPlacement();
		$this->ensureDashboardWidget();
		$this->ensureCronJob();
		$this->cleanupLegacyRuntimeArtifacts();
		$this->signLocalModulesIfAvailable();
	}

	public function uninstall()
	{
		$this->removeCronJob();
		$this->removeAmiUsers();
		$this->removeDashboardWidget();
		$this->removeMenuPlacement();
		$this->removePiperWrapper();
		$this->removeApacheConfig();
		$this->removeManagedBlock('/etc/asterisk/sip_notify_custom.conf', 'SLS Mass Notifications SIP NOTIFY Templates');
		$this->removeManagedBlock('/etc/asterisk/extensions_custom.conf', 'SLS Mass Notifications Dialplan');
		$this->removeManagedBlock('/etc/asterisk/manager_custom.conf', 'SLS Mass Notifications AMI');
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('module reload res_pjsip_notify.so'));
		$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('manager reload'));
		$this->repairPostUninstallSignatures();
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
			$this->persistAppliedSettings($this->normalizeSettings($backup['settings']));
		}
	}
	public function doConfigPageInit($page) {}
	public function getRightNav($request) {}
	public function getActionBar($request) {}

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
					'available_extensions' => $this->getAllPjsipExtensions(),
					'settings' => $pendingSettings ?? $activeSettings,
					'active_settings' => $activeSettings,
					'has_pending_changes' => $pendingSettings !== null,
					'events_map' => $this->getSupportedNwsEvents(),
					'save_result' => $params['save_result'] ?? null,
					'apply_result' => $params['apply_result'] ?? null,
						'hero_image' => self::HERO_IMAGE,
						'csrf_token' => $csrfToken,
				]);
			case 'nws_alerts':
				$cooldown = $this->getTestCooldownState();
				return load_view(__DIR__ . '/views/settings.php', [
					'available_tones' => $this->getAvailableTones(),
					'available_system_sounds' => $this->getAvailableSystemSounds(),
					'available_extensions' => $this->getAllPjsipExtensions(),
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
				return load_view(__DIR__ . '/views/main.php', [
					'events' => $this->getEvents($limit, $type),
					'status_summary' => $this->getStatusSummary(),
					'announcement_targets' => $this->getSipNotifyTargets(),
					'announcement_cooldown_remaining' => $this->getAnnouncementCooldownState()['remaining'],
					'log_path' => self::EVENTS_LOG,
					'selected_type' => $type,
					'selected_limit' => $limit,
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
			'available_voices' => $this->getAvailablePiperVoices(),
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
		$settings['public_pbx_host'] = $this->normalizePbxHost((string)($input['public_pbx_host'] ?? $settings['public_pbx_host'] ?? '')) ?: $this->detectPbxHost();
		$settings['nws_api_base_url'] = $this->normalizeNwsApiBaseUrl((string)($input['nws_api_base_url'] ?? $settings['nws_api_base_url'] ?? 'https://api.weather.gov')) ?: 'https://api.weather.gov';
		$settings['nws_zone'] = $this->normalizeNwsZone((string)($input['nws_zone'] ?? $settings['nws_zone'] ?? ''));
		$settings['alert_recipients'] = $this->normalizeRecipientExtensions($input['alert_recipients'] ?? []);
		if ($settings['enabled'] === '1') {
			if ($settings['nws_zone'] === '') {
				$errors[] = _('Enter a valid NWS zone/county such as TXC491.');
			}
			if (empty($settings['alert_recipients'])) {
				$errors[] = _('Select at least one NWS recipient extension.');
			}
		}

		$settings['quiet_hours_enabled'] = empty($input['quiet_hours_enabled']) ? '0' : '1';
		$settings['quiet_hours_start'] = $this->normalizeHour((string)($input['quiet_hours_start'] ?? $settings['quiet_hours_start'] ?? '21:00'), '21:00');
		$settings['quiet_hours_end'] = $this->normalizeHour((string)($input['quiet_hours_end'] ?? $settings['quiet_hours_end'] ?? '06:00'), '06:00');
		$settings['quiet_critical_events'] = $this->normalizeCriticalEvents($input['quiet_critical_events'] ?? $settings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents());

		$control = is_array($settings['control_api'] ?? null) ? $settings['control_api'] : [];
		$settings['control_api'] = [
			'enabled' => empty($input['control_api_enabled']) ? '0' : '1',
			'api_key' => $this->normalizeEndpointPassword($control['api_key'] ?? '') ?: $this->generateApiKey(),
			'base_url' => $this->getControlApiUrl($settings),
		];

		$sipnotify = is_array($settings['sipnotify'] ?? null) ? $settings['sipnotify'] : [];
		$sipnotify['pbx_host'] = $settings['public_pbx_host'];
		$sipnotify['media_scheme'] = $this->normalizePhoneMediaScheme((string)($input['sipnotify_media_scheme'] ?? $sipnotify['media_scheme'] ?? 'http'));
		$settings['sipnotify'] = $this->normalizeSipNotifySettings($sipnotify);

		$voices = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		$announcementVoice = (string)($input['announcement_piper_voice'] ?? $settings['announcement_piper_voice'] ?? self::PIPER_VOICE);
		$nwsVoice = (string)($input['nws_piper_voice'] ?? $settings['nws_piper_voice'] ?? self::PIPER_VOICE);
		$settings['announcement_piper_voice'] = isset($voices[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		$settings['nws_piper_voice'] = isset($voices[$nwsVoice]) ? $nwsVoice : self::PIPER_VOICE;
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($input['announcement_tts_volume'] ?? $settings['announcement_tts_volume'] ?? 50, 50);
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 85, 85);
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
			$this->persistAppliedSettings($this->normalizeSettings($settings));
			if (is_file(self::PENDING_SETTINGS_JSON)) {
				@unlink(self::PENDING_SETTINGS_JSON);
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
			$selection = (string)($input[$prefix . '_tone'] ?? '');
			if (strpos($selection, 'system:') === 0) {
				$importedTone = $this->importSystemSoundAsTone($selection, $prefix, $errors);
				if ($importedTone !== '') {
					$input[$prefix . '_tone'] = $importedTone;
					$availableToneLookup[$importedTone] = true;
				}
			}
		}
		$enabled = empty($input['enabled']) ? '0' : '1';
		$postedRecipients = array_key_exists('alert_recipients', $input) ? $input['alert_recipients'] : [];
		$alertRecipients = $this->normalizeRecipientExtensions($postedRecipients);
		if ($enabled === '1' && empty($alertRecipients)) {
			$errors[] = _('Select at least one NWS alert recipient extension.');
		}

		$mailTo = $this->normalizeEmails((string)($input['mail_to'] ?? ''));
		$discordWebhookUrl = $this->normalizeDiscordWebhookUrl((string)($input['discord_webhook_url'] ?? ''));
		$quietHoursEnabled = empty($input['quiet_hours_enabled']) ? '0' : '1';
		$quietHoursStart = $this->normalizeHour((string)($input['quiet_hours_start'] ?? $defaults['quiet_hours_start']), $defaults['quiet_hours_start']);
		$quietHoursEnd = $this->normalizeHour((string)($input['quiet_hours_end'] ?? $defaults['quiet_hours_end']), $defaults['quiet_hours_end']);
		$quietCriticalEvents = $this->normalizeCriticalEvents($input['quiet_critical_events'] ?? $defaults['quiet_critical_events']);
		$alertEmailSubject = trim((string)($input['alert_email_subject'] ?? $defaults['alert_email_subject']));
		$alertEmailBody = trim((string)($input['alert_email_body'] ?? $defaults['alert_email_body']));
		$testEmailSubject = trim((string)($input['test_email_subject'] ?? $defaults['test_email_subject']));
		$testEmailBody = trim((string)($input['test_email_body'] ?? $defaults['test_email_body']));
		$nwsApiBaseUrl = $this->normalizeNwsApiBaseUrl((string)($input['nws_api_base_url'] ?? $defaults['nws_api_base_url']));
		$nwsZone = $this->normalizeNwsZone((string)($input['nws_zone'] ?? $defaults['nws_zone']));

		if ($nwsApiBaseUrl === '') {
			$errors[] = _('NWS API base URL must be a valid HTTPS URL.');
			$nwsApiBaseUrl = $defaults['nws_api_base_url'];
		}
		if ($enabled === '1' && $nwsZone === '') {
			$errors[] = _('NWS zone/county must look like TXC491 or TXZ163.');
			$nwsZone = $defaults['nws_zone'];
		}

		if ($discordWebhookUrl !== '' && !preg_match('#^https://(?:discord(?:app)?\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+$#', $discordWebhookUrl)) {
			$errors[] = _('Discord webhook URL must be a valid Discord webhook URL.');
			$discordWebhookUrl = '';
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

		$openingTone = $this->normalizeToneName((string)($input['opening_tone'] ?? $currentSettings['opening_tone'] ?? $defaults['opening_tone']));
		$closingTone = $this->normalizeToneName((string)($input['closing_tone'] ?? $currentSettings['closing_tone'] ?? $defaults['closing_tone']));
		foreach (['opening_tone' => $openingTone, 'closing_tone' => $closingTone] as $label => $tone) {
			if ($tone === '' || !isset($availableToneLookup[$tone])) {
				$errors[] = sprintf(_('Selected %s is not available.'), str_replace('_', ' ', $label));
			}
		}
		$openingTone = isset($availableToneLookup[$openingTone]) ? $openingTone : $defaults['opening_tone'];
		$closingTone = isset($availableToneLookup[$closingTone]) ? $closingTone : $defaults['closing_tone'];

		// NWS settings are one section of the central config. Preserve every unrelated
		// key, especially desktop credentials, groups, API keys, and PBX hostname.
		$settings = $currentSettings;
		$settings['enabled'] = $enabled;
		$settings['page_group'] = '';
		$settings['alert_recipients'] = $alertRecipients;
		$settings['mail_to'] = $mailTo;
		$settings['discord_webhook_url'] = $discordWebhookUrl;
		$settings['quiet_hours_enabled'] = $quietHoursEnabled;
		$settings['quiet_hours_start'] = $quietHoursStart;
		$settings['quiet_hours_end'] = $quietHoursEnd;
		$settings['quiet_critical_events'] = $quietCriticalEvents;
		$settings['nws_api_base_url'] = $nwsApiBaseUrl;
		$settings['nws_zone'] = $nwsZone;
		$settings['alert_email_subject'] = $alertEmailSubject;
		$settings['alert_email_body'] = $alertEmailBody;
		$settings['test_email_subject'] = $testEmailSubject;
		$settings['test_email_body'] = $testEmailBody;
		$settings['opening_tone'] = $openingTone;
		$settings['closing_tone'] = $closingTone;
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($input['tts_max_seconds'] ?? $currentSettings['tts_max_seconds'] ?? 30);
		$settings['piper_bin'] = self::PIPER_BIN;
		$settings['piper_voice'] = $currentSettings['nws_piper_voice'] ?? self::PIPER_VOICE;
		$settings['nws_piper_voice'] = $currentSettings['nws_piper_voice'] ?? self::PIPER_VOICE;

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
			'message' => _('Settings saved. Apply changes to update the live alert scripts.'),
			'errors' => [],
		];
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
		if ($openingTone === '' || !isset($availableToneLookup[$openingTone])) {
			$errors[] = _('Selected opening tone is not available.');
			$openingTone = $settings['opening_tone'] ?? $defaults['opening_tone'];
		}
		if ($closingTone === '' || !isset($availableToneLookup[$closingTone])) {
			$errors[] = _('Selected closing tone is not available.');
			$closingTone = $settings['closing_tone'] ?? $defaults['closing_tone'];
		}
		$settings['opening_tone'] = isset($availableToneLookup[$openingTone]) ? $openingTone : $defaults['opening_tone'];
		$settings['closing_tone'] = isset($availableToneLookup[$closingTone]) ? $closingTone : $defaults['closing_tone'];

		$settings['public_pbx_host'] = $this->normalizePbxHost((string)($input['public_pbx_host'] ?? $settings['public_pbx_host'] ?? '')) ?: $this->detectPbxHost();
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
		$sipnotifySettings['format_overrides'] = $this->normalizeEndpointFormatOverrides($input['sipnotify_format_overrides'] ?? ($sipnotifySettings['format_overrides'] ?? []));
		$settings['sipnotify'] = $this->normalizeSipNotifySettings($sipnotifySettings);

		$announcementVoice = (string)($input['announcement_piper_voice'] ?? $settings['announcement_piper_voice'] ?? self::PIPER_VOICE);
		$nwsVoice = (string)($input['nws_piper_voice'] ?? $settings['nws_piper_voice'] ?? self::PIPER_VOICE);
		$settings['announcement_piper_voice'] = isset($voiceLookup[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		$settings['nws_piper_voice'] = isset($voiceLookup[$nwsVoice]) ? $nwsVoice : self::PIPER_VOICE;
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($input['announcement_tts_volume'] ?? $settings['announcement_tts_volume'] ?? 50, 50);
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($input['nws_tts_volume'] ?? $settings['nws_tts_volume'] ?? 85, 85);
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($input['tts_max_seconds'] ?? $settings['tts_max_seconds'] ?? 30);
		$settings['announcement_cooldown_seconds'] = $this->normalizeAnnouncementCooldownSeconds($input['announcement_cooldown_seconds'] ?? $settings['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
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
		foreach ((array)($settings['desktop_clients'] ?? []) as $existingClient) {
			$existingId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($existingClient['id'] ?? ''));
			if ($existingId !== '') {
				$existingClientIds[$existingId] = (string)($existingClient['client_id'] ?? '');
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
				'success' => empty($errors),
				'message' => empty($errors) ? _('Other settings saved. Apply changes to update the live Mass Notifications configuration.') : _('Other settings were saved with warnings. Apply changes to update the live Mass Notifications configuration.'),
				'errors' => $errors,
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
			'message' => _('Control API key regenerated. Apply changes to update the live Mass Notifications configuration.'),
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
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return [
				'success' => false,
				'message' => _('Upload a Mass Notifications .config file first.'),
				'errors' => [],
			];
		}
		if ((int)($upload['size'] ?? 0) <= 0 || (int)($upload['size'] ?? 0) > 1024 * 1024) {
			return [
				'success' => false,
				'message' => _('Config import must be smaller than 1 MB.'),
				'errors' => [],
			];
		}
		$tmpName = (string)($upload['tmp_name'] ?? '');
		if ($tmpName === '' || !is_uploaded_file($tmpName)) {
			return [
				'success' => false,
				'message' => _('Unable to read uploaded config file.'),
				'errors' => [],
			];
		}
		$decoded = json_decode((string)file_get_contents($tmpName), true);
		if (!is_array($decoded)) {
			return [
				'success' => false,
				'message' => _('Uploaded config is not valid JSON.'),
				'errors' => [],
			];
		}
		$settings = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : $decoded;
		$schemaErrors = $this->validateConfigSchema($settings);
		if (!empty($schemaErrors)) {
			return [
				'success' => false,
				'message' => _('Uploaded config failed validation.'),
				'errors' => $schemaErrors,
			];
		}
		try {
			$this->persistPendingSettings($this->normalizeSettings(array_replace($this->getDefaultSettings(), $settings)));
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Unable to import Mass Notifications config.'),
				'errors' => [$e->getMessage()],
			];
		}
		return [
			'success' => true,
			'message' => _('Mass Notifications config imported. Apply Config to make it live.'),
			'errors' => [],
		];
	}

	private function validateConfigSchema(array $settings)
	{
		$errors = [];
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
		foreach (['control_api', 'updates', 'setup', 'sipnotify', 'ami'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an object.'), $key);
			}
		}
		foreach (['alert_recipients', 'quiet_critical_events', 'announcement_groups', 'desktop_clients'] as $key) {
			if (isset($settings[$key]) && !is_array($settings[$key])) {
				$errors[] = sprintf(_('%s must be an array.'), $key);
			}
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
		if (!empty($settings['enabled'])) {
			if ($this->normalizeNwsZone((string)($settings['nws_zone'] ?? '')) === '') {
				$errors[] = _('Enabled NWS config must include a valid zone.');
			}
			if (empty($this->normalizeRecipientExtensions($settings['alert_recipients'] ?? []))) {
				$errors[] = _('Enabled NWS config must include at least one recipient extension.');
			}
		}
		if (!empty($settings['control_api']['enabled']) && !preg_match('/^[A-Za-z0-9_-]{24,128}$/', (string)($settings['control_api']['api_key'] ?? ''))) {
			$errors[] = _('Enabled Control API config must include a valid API key.');
		}
		if (isset($settings['nws_zone']) && $this->normalizeNwsZone((string)$settings['nws_zone']) !== (string)$settings['nws_zone'] && trim((string)$settings['nws_zone']) !== '') {
			$errors[] = _('NWS zone must be a valid NWS county or zone code such as TXC491.');
		}
		return array_values(array_unique($errors));
	}

	public function applySettings()
	{
		$settings = $this->getPendingSettings();
		if ($settings === null) {
			$settings = $this->getActiveSettings();
		}
		$activeSettings = $this->getActiveSettings();
		if ($this->isSetupComplete($activeSettings)) {
			$settings['setup'] = $activeSettings['setup'];
		}

		try {
			$this->persistAppliedSettings($settings);
			if (is_file(self::PENDING_SETTINGS_JSON)) {
				@unlink(self::PENDING_SETTINGS_JSON);
			}
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

	public function triggerTest($mode = 'tts', $sound = '', $triggerName = 'FreePBX Dashboard')
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

		$cmd = escapeshellcmd(self::TEST_SCRIPT)
			. ' '
			. escapeshellarg('GUI')
			. ' '
			. escapeshellarg($triggerName)
			. ' > /dev/null 2>&1 &';

		exec($cmd, $output, $exitCode);

		if ($exitCode !== 0) {
			return [
				'success' => false,
				'message' => _('The test command could not be started.'),
			];
		}

		return [
			'success' => true,
			'message' => _('Piper TTS test started.'),
		];
	}

	public function sendSipNotifyAnnouncement($extensions, $message, $massNotify = true, $ttsAudio = false, $groups = [], array $options = [])
	{
		if (!$this->isSetupComplete($this->getActiveSettings())) {
			return [
				'success' => false,
				'message' => $this->getSetupRequiredMessage(),
				'cooldown_remaining' => 0,
			];
		}

		$cooldown = $this->getAnnouncementCooldownState();
		if ($cooldown['remaining'] > 0) {
			return [
				'success' => false,
				'message' => sprintf(_('SIP NOTIFY announcements are on cooldown. Wait %s seconds and try again.'), $cooldown['remaining']),
				'cooldown_remaining' => $cooldown['remaining'],
			];
		}

		$message = trim((string)$message);
		$message = preg_replace('/[^\P{C}\r\n\t]/u', '', $message);
		if ($message === '') {
			return [
				'success' => false,
				'message' => _('Enter an announcement message before sending.'),
				'cooldown_remaining' => 0,
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
			if ($desktopAll || !empty($selectedDesktopClients)) {
				$massNotify = true;
			}
			$ttsAudio = (bool)$ttsAudio;

			if (empty($selected) && !$massNotify) {
				return [
					'success' => false,
					'message' => _('Select at least one extension or enable SLS Mass Notify App.'),
					'cooldown_remaining' => 0,
				];
			}

			if ($ttsAudio && empty($selected)) {
				return [
					'success' => false,
					'message' => _('Select at least one extension before enabling TTS audio.'),
					'cooldown_remaining' => 0,
				];
			}

		if (!is_executable(self::VISUAL_PUSH_SCRIPT)) {
			return [
				'success' => false,
				'message' => _('The SIP NOTIFY sender script is missing or not executable.'),
				'cooldown_remaining' => 0,
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

			$audioMessage = '';
			$notifyDelay = 0;
			$notifyStatus = 'sent';
			if ($ttsAudio) {
				$audioResult = $this->sendAnnouncementTtsAudio(array_values($selected), $message, [
					'trigger_source' => $triggerSource,
					'announcement_style' => $style,
					'desktop_all' => $desktopAll,
					'desktop_clients' => array_values($selectedDesktopClients),
				]);
				if (empty($audioResult['success'])) {
					return [
						'success' => false,
					'message' => _('Announcement TTS audio failed; SIP NOTIFY was not sent.') . ' ' . (string)($audioResult['message'] ?? ''),
					'cooldown_remaining' => 0,
				];
			}
			$audioMessage = _(' with TTS audio');
				$notifyDelay = (int)($audioResult['notify_delay_seconds'] ?? 0);
			}

				$cmd = '/usr/bin/python3 '
					. escapeshellarg(self::VISUAL_PUSH_SCRIPT)
					. ' --announcement '
				. escapeshellarg($message)
				. ' --targets '
				. escapeshellarg(implode(',', array_values($selected)));
			if ($image) {
				$cmd .= ' --announcement-image'
					. ' --announcement-title ' . escapeshellarg($title)
					. ' --announcement-bg-color ' . escapeshellarg($backgroundColor);
			}
			if (!$massNotify) {
				$cmd .= ' --no-api';
			}
			if ($massNotify && empty($selected)) {
				$cmd .= ' --api-only';
			}
			if ($desktopAll) {
				$cmd .= ' --desktop-all';
			} elseif (!empty($selectedDesktopClients)) {
				$cmd .= ' --desktop-targets ' . escapeshellarg(implode(',', array_values($selectedDesktopClients)));
			}

		if ($notifyDelay > 0) {
			if (!$this->scheduleDelayedAnnouncementNotify($cmd, $notifyDelay)) {
				return [
					'success' => false,
					'message' => _('Announcement audio was queued, but the delayed SIP NOTIFY could not be scheduled.'),
					'cooldown_remaining' => 0,
				];
			}
			$notifyStatus = 'scheduled';
			$audioMessage .= sprintf(_('; text notification scheduled after %s seconds'), $notifyDelay);
		} else {
			$output = [];
			exec($cmd . ' 2>&1', $output, $exitCode);
			if ($exitCode !== 0) {
				return [
					'success' => false,
					'message' => _('The SIP NOTIFY announcement could not be sent.') . ' ' . trim(implode(' ', $output)),
					'cooldown_remaining' => 0,
				];
			}
		}

		$this->appendAnnouncementNotifyLog($message, [
			'status' => $notifyStatus,
			'trigger_source' => $triggerSource,
			'announcement_style' => $style,
			'phones' => array_values($selected),
			'desktop_all' => $desktopAll,
			'desktop_clients' => array_values($selectedDesktopClients),
			'mass_notify' => $massNotify,
			'tts_audio' => $ttsAudio,
			'image' => $image,
			'title' => $title,
			'background_color' => $backgroundColor,
			'notify_delay_seconds' => $notifyDelay,
		]);
		$this->setAnnouncementCooldown();

			return [
				'success' => true,
				'message' => sprintf(
					_('Announcement sent to %s extension(s)%s%s.'),
					count($selected),
					$massNotify ? _(' and SLS Mass Notify App') : '',
					$audioMessage
				),
				'cooldown_remaining' => $this->normalizeAnnouncementCooldownSeconds($this->getActiveSettings()['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS),
			];
		}

	private function sendAnnouncementTtsAudio(array $extensions, $message, array $context = [])
	{
		$settings = $this->getActiveSettings();
		$this->ensurePluginDataDir();
		$this->ensureRuntimePermissions();
		if (!is_executable($settings['piper_bin'] ?? self::PIPER_BIN)
			|| !$this->isValidPiperVoiceFile($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE)
		) {
			$this->ensurePiperRuntime();
			$settings = $this->getActiveSettings();
		}
		$this->pruneTtsCache();

		if (empty($extensions)) {
			return ['success' => false, 'message' => _('No announcement audio recipients were selected.')];
		}
		if (!is_executable($settings['piper_bin'] ?? self::PIPER_BIN)) {
			return ['success' => false, 'message' => _('Piper TTS binary is missing or not executable.')];
		}
		$announcementVoice = $settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE;
		if (!$this->isValidPiperVoiceFile($announcementVoice)) {
			return ['success' => false, 'message' => _('Piper TTS voice model is missing or not readable. The installer could not download the selected Piper voice; check internet access from the PBX and rerun module install or download the voice files into the plugin piper/voices folder.')];
		}

		$ttsBase = $this->generateAnnouncementTtsFile($message, $settings);
		if ($ttsBase === '') {
			return ['success' => false, 'message' => _('Piper TTS audio could not be generated.')];
		}

		$sequence = $this->buildAnnouncementAudioSequence($ttsBase, $settings);
		if ($sequence === '') {
			return ['success' => false, 'message' => _('Announcement audio sequence could not be built.')];
		}

		$queued = $this->queueAnnouncementAudioCalls($extensions, $sequence);
		if ($queued < 1) {
			return ['success' => false, 'message' => _('Unable to queue announcement audio calls.')];
		}

		$this->updateStatusData([
			'last_delivery_at' => date('c'),
			'last_delivery_status' => 'queued',
			'last_delivery_source' => 'announcement',
			'last_delivery_event' => 'Announcement',
			'last_delivery_audio' => 'Piper TTS',
			'last_delivery_message' => sprintf('Queued announcement TTS audio to %s extension(s)', $queued),
			'last_delivery_page_group' => implode(',', $extensions),
			'last_delivery_alert_id' => '',
		]);
		$this->appendAnnouncementAudioLog($message, $sequence, $extensions, $context);

		return [
			'success' => true,
			'message' => sprintf(_('Queued announcement TTS audio to %s extension(s).'), $queued),
			'audio_sequence' => $sequence,
			'notify_delay_seconds' => 1,
		];
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
					. escapeshellarg($this->volumePercentToScalar($settings['announcement_tts_volume'] ?? 50, 50))
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
		$openingTone = $this->normalizeToneName((string)($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening'));
		$closingTone = $this->normalizeToneName((string)($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing'));

		if ($openingTone !== '' && is_readable(self::TONES_DIR . '/' . $openingTone . '.wav')) {
			$quietOpeningTone = $this->createQuietAnnouncementTone($openingTone, $settings['announcement_tts_volume'] ?? 50);
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
			$quietClosingTone = $this->createQuietAnnouncementTone($closingTone, $settings['announcement_tts_volume'] ?? 50);
			if ($quietClosingTone !== '' && is_readable(self::TTS_DIR . '/' . $quietClosingTone . '.wav')) {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tts/' . $quietClosingTone;
				$files[] = self::TTS_DIR . '/' . $quietClosingTone . '.wav';
			} else {
				$parts[] = self::ASTERISK_SOUND_PREFIX . '/tones/' . $closingTone;
				$files[] = self::TONES_DIR . '/' . $closingTone . '.wav';
			}
		}

		$combined = $this->combineAudioParts($ttsBase, $files, 'announcement_sequence');
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

	private function createQuietAnnouncementTone($toneName, $volumePercent = 50)
	{
		$toneName = $this->normalizeToneName($toneName);
		if ($toneName === '' || !is_executable('/usr/bin/sox')) {
			return '';
		}

		$source = self::TONES_DIR . '/' . $toneName . '.wav';
		if (!is_readable($source)) {
			return '';
		}

		$volumePercent = $this->normalizeTtsVolume($volumePercent, 50);
		$quietBase = 'announcement_tone_' . $toneName . '_v' . $volumePercent;
		$quietBase = $this->normalizeToneName($quietBase);
		$target = self::TTS_DIR . '/' . $quietBase . '.wav';
		if (is_readable($target) && filemtime($target) !== false && filemtime($source) !== false && filemtime($target) >= filemtime($source)) {
			return $quietBase;
		}

			$tmp = $target . '.tmp.' . bin2hex(random_bytes(3)) . '.wav';
		$cmd = '/usr/bin/sox -v ' . escapeshellarg($this->volumePercentToScalar($volumePercent, 50)) . ' '
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

	private function queueAnnouncementAudioCalls(array $extensions, $sequence)
	{
		if ($sequence === '' || !is_dir(self::ASTERISK_OUTGOING_SPOOL)) {
			return 0;
		}

		$queued = 0;
		foreach ($extensions as $extension) {
			$recipient = preg_replace('/[^0-9]/', '', (string)$extension);
			if ($recipient === '') {
				continue;
			}
			$callFile = tempnam('/tmp', 'sls_announcement_');
			if ($callFile === false) {
				continue;
			}
			$body = "Channel: Local/{$recipient}@sls-alert-audio\n"
				. "CallerID: \"SLS Mass Notify System\" <SLS>\n"
				. "Setvar: SLS_SOUND={$sequence}\n"
				. "Setvar: SLS_CALLERID_NAME=SLS Mass Notify System\n"
				. "Setvar: SLS_CALLERID_NUM=SLS\n"
				. "MaxRetries: 0\n"
				. "RetryTime: 5\n"
				. "WaitTime: 180\n"
				. "Application: Wait\n"
				. "Data: 1\n";
			file_put_contents($callFile, $body);
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

	private function scheduleDelayedAnnouncementNotify($command, $delaySeconds)
	{
		$delaySeconds = min(30, max(1, (int)$delaySeconds));
		$command = trim((string)$command);
		if ($command === '') {
			return false;
		}

		$shell = 'sleep ' . $delaySeconds . '; ' . $command . ' >> /var/log/sls_mass_notify_push.log 2>&1';
		exec('/bin/sh -c ' . escapeshellarg($shell) . ' >/dev/null 2>&1 &', $output, $exitCode);
		return $exitCode === 0;
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
			'status' => 'triggered',
			'event' => $style . ' Announcement Audio',
			'message_type' => 'Audio Page',
			'audio' => 'Piper TTS',
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
			'audio' => !empty($context['tts_audio']) ? 'Piper TTS queued separately' : 'None',
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

	private function pruneTtsCache()
	{
		foreach (glob(self::TTS_DIR . '/*.wav') ?: [] as $path) {
			if (is_file($path) && filemtime($path) !== false && filemtime($path) < (time() - 7 * 86400)) {
				@unlink($path);
			}
		}
	}

	public function getCooldownState()
	{
		return [
			'test' => $this->getTestCooldownState(),
			'announcement' => $this->getAnnouncementCooldownState(),
		];
	}

	public function getAnnouncementDashboardState()
	{
		$settings = $this->getActiveSettings();
		return [
			'quiet_hours_active' => $this->settingsQuietHoursActive($settings),
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
			return [
				'success' => true,
				'message' => _('Installation repair was queued. The protected maintenance worker will run it within one minute.'),
				'errors' => [],
			];
		}
		try {
			$this->install();
			$this->runCommand('/usr/sbin/fwconsole reload');
			$this->runCommand('/usr/sbin/asterisk -rx ' . escapeshellarg('dialplan reload'));
			$this->signLocalModulesIfAvailable();
			return [
				'success' => true,
				'message' => _('Installation repair completed. Runtime files, permissions, dialplan, dashboard widget, cron, and signatures were refreshed.'),
				'errors' => [],
			];
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'message' => _('Installation repair failed.'),
				'errors' => [$e->getMessage()],
			];
		}
	}

	public function requestManualUpdate()
	{
		return $this->queueMaintenanceAction(
			self::UPDATE_REQUEST_FILE,
			_('Manual update was queued. The protected maintenance worker will check GitHub and install a newer verified beta release within one minute.')
		);
	}

	public function requestCompleteUninstall()
	{
		return $this->queueMaintenanceAction(
			self::UNINSTALL_REQUEST_FILE,
			_('Complete uninstall was queued. The module, runtime files, APIs, logs, and central configuration will be removed within one minute.')
		);
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
		$checks[] = $this->diagnosticCheck(_('Maintenance worker'), is_executable('/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh'), '/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh');
		$checks[] = $this->diagnosticCheck(_('Piper binary'), is_executable($settings['piper_bin'] ?? self::PIPER_BIN), (string)($settings['piper_bin'] ?? self::PIPER_BIN));
		$checks[] = $this->diagnosticCheck(_('Executable runtime ownership'), @fileowner(self::RUNTIME_DIR) === 0 && @fileowner(self::PIPER_BIN) === 0, 'root:root');
		$checks[] = $this->diagnosticCheck(_('Piper voice'), is_readable($settings['piper_voice'] ?? self::PIPER_VOICE), (string)($settings['piper_voice'] ?? self::PIPER_VOICE));
		$checks[] = $this->diagnosticCheck(_('Notification log'), is_writable(self::EVENTS_LOG) || (is_writable(dirname(self::EVENTS_LOG)) && !file_exists(self::EVENTS_LOG)), self::EVENTS_LOG);
		$checks[] = $this->diagnosticCheck(_('Desktop journal'), is_writable(self::PLUGIN_DATA_DIR . '/sipnotify') || is_writable(self::PLUGIN_DATA_DIR), self::PLUGIN_DATA_DIR . '/sipnotify/sipnotify_events.jsonl');
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
		return array_values($targets);
	}

	public function getAllPjsipExtensions()
	{
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
		return array_values($targets);
	}

	public function dashboardService()
	{
		$status = [
			'title' => _('Mass Notifications Plugin'),
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
		if (!$this->isSetupComplete($settings)) {
			$critical[] = _('Setup wizard is not complete');
		}

		if (!is_executable(self::TEST_SCRIPT)) {
			$critical[] = _('Test alert script is missing or not executable');
		}

		if (!is_dir(self::SOUNDS_DIR)) {
			$critical[] = _('Custom alert sounds directory is missing');
		}

		if (!is_executable($settings['piper_bin'] ?? self::PIPER_BIN)) {
			$critical[] = _('Piper TTS binary is missing or not executable');
		}

		foreach ([$settings['nws_piper_voice'] ?? '', $settings['announcement_piper_voice'] ?? ''] as $voice) {
			if ($voice === '' || !is_readable($voice)) {
				$critical[] = _('A configured Piper TTS voice model is missing or unreadable');
			}
		}

		$openingTone = $this->normalizeToneName((string)($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening'));
		$closingTone = $this->normalizeToneName((string)($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing'));
		if ($openingTone === '' || !is_readable(self::TONES_DIR . '/' . $openingTone . '.wav')) {
			$critical[] = _('Opening tone is missing or not readable');
		}
		if ($closingTone === '' || !is_readable(self::TONES_DIR . '/' . $closingTone . '.wav')) {
			$critical[] = _('Closing tone is missing or not readable');
		}

		if (!is_writable(self::PLUGIN_DATA_DIR . '/sipnotify')) {
			$critical[] = _('Desktop notification journal directory is not writable');
		}

		if ($nwsEnabled) {
			if (empty($settings['alert_recipients'] ?? [])) {
				$warnings[] = _('NWS alert recipients are not configured');
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
			$okMessage = $nwsEnabled ? _('Mass Notifications services and NWS polling look healthy') : _('Mass Notifications services look healthy; NWS alerts are disabled');
			$deliveryState = strtolower(trim((string)($statusData['last_delivery_status'] ?? '')));
			if ($deliveryState === 'queued' && !empty($statusData['last_delivery_event'])) {
				$okMessage = sprintf(
					_('Healthy. Last delivery: %s using %s'),
					(string)$statusData['last_delivery_event'],
					(string)($statusData['last_delivery_audio'] ?? _('unknown audio'))
				);
			}
			$status = array_merge($status, \FreePBX::Dashboard()->genStatusIcon('ok', $okMessage));
		}

		return [$status];
	}

	public function getEvents($limit = self::DEFAULT_LIMIT, $type = '')
	{
		$limit = $this->sanitizeLimit($limit);
		$type = $this->sanitizeType($type);
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
		$this->ensurePluginDataDir();
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
		$this->ensurePluginDataDir();
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
		$message = trim((string)($payload['message'] ?? $payload['body'] ?? $payload['text'] ?? ''));
		$targets = $this->normalizeRecipientExtensions($payload['targets'] ?? $payload['extensions'] ?? []);
		$groups = $this->normalizeControlGroupSelectors($payload['groups'] ?? $payload['announcement_groups'] ?? []);
		$options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
		$options['trigger_source'] = 'Control API';
		foreach (['style', 'image', 'title', 'background_color'] as $key) {
			if (array_key_exists($key, $payload)) {
				$options[$key] = $payload[$key];
			}
		}
		if (array_key_exists('desktop_all', $payload) || array_key_exists('all_desktops', $payload)) {
			$options['desktop_all'] = !empty($payload['desktop_all']) || !empty($payload['all_desktops']);
		}
		if (array_key_exists('desktop_clients', $payload) || array_key_exists('desktop_targets', $payload)) {
			$options['desktop_clients'] = $payload['desktop_clients'] ?? $payload['desktop_targets'];
		}
		if (array_key_exists('phones_all', $payload) || array_key_exists('all_phones', $payload)) {
			$options['phones_all'] = !empty($payload['phones_all']) || !empty($payload['all_phones']);
		}

		return $this->sendSipNotifyAnnouncement(
			$targets,
			$message,
			!empty($payload['desktop']) || !empty($options['desktop_all']) || !empty($options['desktop_clients']),
			!empty($payload['tts']),
			$groups,
			$options
		);
	}

	public function controlApiTriggerNwsTest(array $payload = [])
	{
		$mode = trim((string)($payload['mode'] ?? 'tts'));
		$triggerName = trim((string)($payload['trigger_name'] ?? 'Control API'));
		if ($triggerName === '') {
			$triggerName = 'Control API';
		}
		$triggerName = function_exists('mb_substr') ? mb_substr($triggerName, 0, 80) : substr($triggerName, 0, 80);
		return $this->triggerTest($mode, '', $triggerName);
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

		$settingsPatch = is_array($payload['settings'] ?? null) ? $payload['settings'] : (is_array($payload['config'] ?? null) ? $payload['config'] : []);
		if (empty($settingsPatch)) {
			return [
				'success' => false,
				'message' => _('No config settings were provided.'),
				'errors' => [_('Provide a settings object to update.')],
			];
		}

		$current = $this->getPendingSettings() ?? $this->getActiveSettings();
		$merged = $this->mergeControlConfigPatch($current, $settingsPatch);
		$normalized = $this->normalizeSettings($merged);
		$schemaErrors = $this->validateConfigSchema($normalized);
		if (!empty($schemaErrors)) {
			return [
				'success' => false,
				'message' => _('Control API config changes failed validation.'),
				'errors' => $schemaErrors,
			];
		}
		try {
			if (!empty($payload['apply'])) {
				$this->persistAppliedSettings($normalized);
				if (is_file(self::PENDING_SETTINGS_JSON)) {
					@unlink(self::PENDING_SETTINGS_JSON);
				}
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
		return $settings;
	}

	private function mergeControlConfigPatch(array $current, array $patch)
	{
		$patch = $this->removeRedactedPlaceholders($patch);
		$allowed = [
			'enabled', 'alert_recipients', 'mail_to', 'discord_webhook_url',
			'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_critical_events',
			'nws_api_base_url', 'nws_zone', 'alert_email_subject', 'alert_email_body',
			'test_email_subject', 'test_email_body', 'opening_tone', 'closing_tone',
			'public_pbx_host',
			'nws_piper_voice', 'announcement_piper_voice', 'nws_tts_volume',
			'announcement_tts_volume', 'tts_max_seconds', 'log_retention_days', 'control_api',
			'sipnotify', 'announcement_groups', 'updates',
		];
		foreach ($allowed as $key) {
			if (!array_key_exists($key, $patch)) {
				continue;
			}
			if (is_array($patch[$key]) && is_array($current[$key] ?? null) && in_array($key, ['control_api', 'sipnotify', 'updates'], true)) {
				$current[$key] = array_replace($current[$key], $patch[$key]);
			} else {
				$current[$key] = $patch[$key];
			}
		}
		return $current;
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
		$defaultMailFromAddr = 'no-reply@' . $defaultHost;
		return [
			'enabled' => '0',
			'public_pbx_host' => $defaultHost,
			'page_group' => '',
			'alert_recipients' => [],
			'mail_to' => '',
			'discord_webhook_url' => '',
			'nws_api_base_url' => 'https://api.weather.gov',
			'nws_zone' => '',
			'quiet_hours_enabled' => '1',
			'quiet_hours_start' => '21:00',
			'quiet_hours_end' => '06:00',
			'quiet_critical_events' => $this->getDefaultQuietCriticalEvents(),
			'mail_from_name' => 'SLS Mass Notification System',
			'mail_from_addr' => $defaultMailFromAddr,
			'alert_email_subject' => 'Southland Servers Group PBX: EAS alert triggered - {{event}}',
			'alert_email_body' => "An EAS alert triggered the configured NWS recipients.\n\nSource Name: {{source_name}}\nTrigger Source: {{trigger_source}}\nEvent: {{event}}\nSeverity: {{severity}}\nMessage Type: {{message_type}}\nAudio: {{audio}}\nAlert ID: {{alert_id}}\nZone: {{zone}}\nTime: {{time}}",
			'test_email_subject' => 'Southland Servers Mass Notifications Server: NWS test triggered',
			'test_email_body' => "An NWS test was triggered.\n\nSource Name: {{source_name}}\nTrigger Source: {{trigger_source}}\nTrigger Extension: {{trigger_extension}}\nTrigger Name: {{trigger_name}}\nNWS Recipients: {{page_group}}\nAudio Sequence: {{audio_sequence}}\nTime: {{time}}",
			'opening_tone' => 'opening_Paging_Tone_Opening',
			'closing_tone' => 'closing_Paging_Tone_Closing',
			'tts_max_seconds' => 30,
			'piper_bin' => self::PIPER_BIN,
			'piper_voice' => self::PIPER_VOICE,
			'nws_piper_voice' => self::PIPER_VOICE,
			'announcement_piper_voice' => self::PIPER_VOICE,
			'nws_tts_volume' => 85,
			'announcement_tts_volume' => 50,
			'announcement_cooldown_seconds' => self::ANNOUNCEMENT_COOLDOWN_SECONDS,
			'log_retention_days' => 90,
			'desktop_auth_key' => $this->generateDesktopAuthKey(),
			'desktop_clients' => [
				$this->defaultDesktopClient('SLS Desktop App'),
			],
			'ami' => [
				'username' => 'slsmassnotify',
				'password' => $this->generateApiKey(),
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

	private function persistPendingSettings(array $settings)
	{
		$this->ensurePluginDataDir();
		$settings = $this->normalizeSettings($settings);
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException(_('Unable to encode Mass Notifications settings.'));
		}

		$lock = $this->acquireSettingsLock();
		try {
			$tmpSettings = self::PENDING_SETTINGS_JSON . '.tmp.' . bin2hex(random_bytes(4));
			if (file_put_contents($tmpSettings, $json . "\n", LOCK_EX) === false) {
				@unlink($tmpSettings);
				throw new \RuntimeException(sprintf(_('Unable to write %s.'), self::PENDING_SETTINGS_JSON));
			}
			$this->setPrivateOwnership($tmpSettings);
			if (!@rename($tmpSettings, self::PENDING_SETTINGS_JSON)) {
				@unlink($tmpSettings);
				throw new \RuntimeException(sprintf(_('Unable to replace %s.'), self::PENDING_SETTINGS_JSON));
			}
			$this->setPrivateOwnership(self::PENDING_SETTINGS_JSON);
		} finally {
			$this->releaseSettingsLock($lock);
		}
	}

	private function persistAppliedSettings(array $settings)
	{
		$this->ensurePluginDataDir();
		$settings = $this->normalizeSettings($settings);
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException(_('Unable to encode Mass Notifications settings.'));
		}

		$lock = $this->acquireSettingsLock();
		try {
			$this->backupAppliedSettings();
			$tmpSettings = self::SETTINGS_JSON . '.tmp.' . bin2hex(random_bytes(4));
			if (file_put_contents($tmpSettings, $json . "\n", LOCK_EX) === false) {
				@unlink($tmpSettings);
				throw new \RuntimeException(sprintf(_('Unable to write %s.'), self::SETTINGS_JSON));
			}
			$this->setPrivateOwnership($tmpSettings);
			if (!@rename($tmpSettings, self::SETTINGS_JSON)) {
				@unlink($tmpSettings);
				throw new \RuntimeException(sprintf(_('Unable to replace %s.'), self::SETTINGS_JSON));
			}
			$this->setPrivateOwnership(self::SETTINGS_JSON);
		} finally {
			$this->releaseSettingsLock($lock);
		}
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
		if (!is_dir($backupDir) && !@mkdir($backupDir, 0750, true)) {
			return;
		}
		@chmod($backupDir, 0750);
		@chown($backupDir, 'asterisk');
		@chgrp($backupDir, 'asterisk');
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
		@chmod($file, 0640);
		@chown($file, 'asterisk');
		@chgrp($file, 'asterisk');
	}

	private function setPrivateOwnership($file)
	{
		@chmod($file, 0640);
		@chown($file, 'asterisk');
		@chgrp($file, 'asterisk');
	}

	private function ensurePluginDataDir()
	{
		if (!is_dir(self::PLUGIN_DATA_DIR)) {
			@mkdir(self::PLUGIN_DATA_DIR, 0750, true);
		}
		if (!is_dir(self::SOUNDS_DIR)) {
			@mkdir(self::SOUNDS_DIR, 0755, true);
		}
		foreach ([self::TONES_DIR, self::TTS_DIR, self::PIPER_DATA_DIR, self::PIPER_VOICE_DIR] as $dir) {
			if (!is_dir($dir)) {
				@mkdir($dir, $dir === self::PIPER_DATA_DIR ? 0750 : 0755, true);
			}
		}
		$this->ensureAsteriskSoundLink('/var/lib/asterisk/sounds/en/' . self::ASTERISK_SOUND_PREFIX);
		$this->ensureAsteriskSoundLink('/var/lib/asterisk/sounds/' . self::ASTERISK_SOUND_PREFIX);
		@chmod(self::PLUGIN_DATA_DIR, 0750);
		@chmod(self::SOUNDS_DIR, 0755);
		@chmod(self::TONES_DIR, 0755);
		@chmod(self::TTS_DIR, 0755);
		@chmod(self::PIPER_DATA_DIR, 0750);
		@chown(self::PLUGIN_DATA_DIR, 'asterisk');
		@chgrp(self::PLUGIN_DATA_DIR, 'asterisk');
		foreach ([self::SOUNDS_DIR, self::TONES_DIR, self::TTS_DIR, self::PIPER_DATA_DIR, self::PIPER_VOICE_DIR] as $dir) {
			@chown($dir, 'asterisk');
			@chgrp($dir, 'asterisk');
		}
		foreach (['/var/lib/asterisk/sounds/en/' . self::ASTERISK_SOUND_PREFIX, '/var/lib/asterisk/sounds/' . self::ASTERISK_SOUND_PREFIX] as $link) {
			@chown($link, 'asterisk');
			@chgrp($link, 'asterisk');
		}
		$this->ensureDefaultTones();
	}

	private function ensureSystemDependencies()
	{
		$required = [
			'/usr/bin/python3',
			'/usr/bin/sox',
			'/usr/bin/convert',
			'/usr/bin/curl',
			'/usr/bin/gpg',
		];
		$missing = array_values(array_filter($required, static function ($path) {
			return !is_executable($path);
		}));
		if (!empty($missing) && is_executable('/usr/bin/apt-get')) {
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update');
			$this->runCommand('DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y curl wget ca-certificates gnupg python3 python3-venv python3-pip sox imagemagick fonts-dejavu-core tar');
		}

		$missing = array_values(array_filter($required, static function ($path) {
			return !is_executable($path);
		}));
		if (!empty($missing)) {
			$this->updateStatusData([
				'last_fault_at' => date('c'),
				'last_fault_stage' => 'dependencies',
				'last_fault_message' => 'Required runtime dependencies are missing: ' . implode(', ', array_unique($missing)),
			]);
		}
	}

	private function ensureAsteriskSoundLink($link)
	{
		$parent = dirname($link);
		if (!is_dir($parent)) {
			@mkdir($parent, 0755, true);
		}
		if (is_link($link)) {
			if (readlink($link) !== self::SOUNDS_DIR) {
				@unlink($link);
			}
		} elseif (file_exists($link)) {
			@rename($link, $link . '.legacy-' . date('YmdHis'));
		}
		if (!file_exists($link)) {
			@symlink(self::SOUNDS_DIR, $link);
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

	private function setAnnouncementCooldown()
	{
		file_put_contents(self::ANNOUNCEMENT_COOLDOWN_FILE, (string)time() . "\n", LOCK_EX);
		$this->setOwnership(self::ANNOUNCEMENT_COOLDOWN_FILE);
	}

	private function getRegisteredPjsipExtensions()
	{
		$output = [];
		exec("asterisk -rx 'pjsip show contacts' 2>/dev/null", $output);
		$registered = [];
		foreach ($output as $line) {
			if (!preg_match('/Contact:\s+([0-9]+)\/.*\s([A-Za-z]+)\s+[-0-9na.]+$/', $line, $matches)) {
				continue;
			}
			$status = $matches[2];
			if (in_array($status, ['Avail', 'NonQual'], true)) {
				$registered[$matches[1]] = $matches[1];
			}
		}
		return array_values($registered);
	}

	private function getExtensionNameMap()
	{
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
		return $names;
	}

	private function getActiveSettings()
	{
		return $this->normalizeSettings($this->loadSettingsFile(self::SETTINGS_JSON));
	}

	private function getPendingSettings()
	{
		if (!is_readable(self::PENDING_SETTINGS_JSON) && is_readable(self::LEGACY_PENDING_SETTINGS_JSON)) {
			return $this->normalizeSettings($this->loadSettingsFile(self::LEGACY_PENDING_SETTINGS_JSON));
		}
		if (!is_readable(self::PENDING_SETTINGS_JSON) && is_readable(self::LEGACY_OLD_PENDING_SETTINGS_JSON)) {
			return $this->normalizeSettings($this->loadSettingsFile(self::LEGACY_OLD_PENDING_SETTINGS_JSON));
		}
		if (!is_readable(self::PENDING_SETTINGS_JSON)) {
			return null;
		}

		return $this->normalizeSettings($this->loadSettingsFile(self::PENDING_SETTINGS_JSON));
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
			$settings = array_replace($settings, $decoded);
		}
		return $settings;
	}

	private function normalizeSettings(array $settings)
	{
		$settings['enabled'] = $settings['enabled'] === '0' ? '0' : '1';
		$settings['page_group'] = '';
		$configuredHost = $this->normalizePbxHost((string)($settings['public_pbx_host'] ?? ''));
		if ($configuredHost === '' && is_array($settings['sipnotify'] ?? null)) {
			$configuredHost = $this->normalizePbxHost((string)($settings['sipnotify']['pbx_host'] ?? ''));
		}
		$settings['public_pbx_host'] = $configuredHost ?: $this->detectPbxHost();
		$settings['mail_to'] = $this->normalizeEmails((string)$settings['mail_to']);
		$settings['discord_webhook_url'] = $this->normalizeDiscordWebhookUrl((string)($settings['discord_webhook_url'] ?? ''));
		$settings['nws_api_base_url'] = $this->normalizeNwsApiBaseUrl((string)($settings['nws_api_base_url'] ?? 'https://api.weather.gov')) ?: 'https://api.weather.gov';
		$settings['nws_zone'] = $this->normalizeNwsZone((string)($settings['nws_zone'] ?? ''));
		$settings['quiet_hours_enabled'] = ($settings['quiet_hours_enabled'] ?? '0') === '1' ? '1' : '0';
		$settings['quiet_hours_start'] = $this->normalizeHour((string)($settings['quiet_hours_start'] ?? ''), $this->getDefaultSettings()['quiet_hours_start']);
		$settings['quiet_hours_end'] = $this->normalizeHour((string)($settings['quiet_hours_end'] ?? ''), $this->getDefaultSettings()['quiet_hours_end']);
		$settings['quiet_critical_events'] = $this->normalizeCriticalEvents($settings['quiet_critical_events'] ?? $this->getDefaultQuietCriticalEvents());
		$mailFromName = trim(preg_replace('/[^\P{C}\t]/u', '', (string)($settings['mail_from_name'] ?? '')));
		$settings['mail_from_name'] = $mailFromName !== '' ? substr($mailFromName, 0, 80) : $this->getDefaultSettings()['mail_from_name'];
		$mailFromAddress = trim((string)($settings['mail_from_addr'] ?? ''));
		$settings['mail_from_addr'] = filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL) ? $mailFromAddress : $this->getDefaultSettings()['mail_from_addr'];
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
		$settings['alert_recipients'] = $this->normalizeRecipientExtensions($settings['alert_recipients'] ?? $this->getDefaultSettings()['alert_recipients']);
		$availableTones = array_fill_keys($this->getAvailableTones(), true);
		$settings['opening_tone'] = $this->normalizeToneName((string)($settings['opening_tone'] ?? 'opening_Paging_Tone_Opening'));
		$settings['closing_tone'] = $this->normalizeToneName((string)($settings['closing_tone'] ?? 'closing_Paging_Tone_Closing'));
		if (!isset($availableTones[$settings['opening_tone']])) {
			$settings['opening_tone'] = 'opening_Paging_Tone_Opening';
		}
		if (!isset($availableTones[$settings['closing_tone']])) {
			$settings['closing_tone'] = 'closing_Paging_Tone_Closing';
		}
		$settings['tts_max_seconds'] = $this->normalizeTtsMaxSeconds($settings['tts_max_seconds'] ?? 30);
		$settings['piper_bin'] = self::PIPER_BIN;
		$voices = array_fill_keys(array_column($this->getAvailablePiperVoices(), 'path'), true);
		$nwsVoice = (string)($settings['nws_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE);
		$announcementVoice = (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE);
		$settings['nws_piper_voice'] = isset($voices[$nwsVoice]) ? $nwsVoice : self::PIPER_VOICE;
		$settings['announcement_piper_voice'] = isset($voices[$announcementVoice]) ? $announcementVoice : self::PIPER_VOICE;
		$settings['piper_voice'] = $settings['nws_piper_voice'];
		$settings['nws_tts_volume'] = $this->normalizeTtsVolume($settings['nws_tts_volume'] ?? 85, 85);
		$settings['announcement_tts_volume'] = $this->normalizeTtsVolume($settings['announcement_tts_volume'] ?? 50, 50);
		$settings['announcement_cooldown_seconds'] = $this->normalizeAnnouncementCooldownSeconds($settings['announcement_cooldown_seconds'] ?? self::ANNOUNCEMENT_COOLDOWN_SECONDS);
		$settings['log_retention_days'] = $this->normalizeRetentionDays($settings['log_retention_days'] ?? 90);
		unset($settings['desktop_api_token']);
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$settings['ami'] = [
			'username' => $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami'),
			'password' => $this->normalizeEndpointPassword($ami['password'] ?? '') ?: $this->generateApiKey(),
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
		return $this->normalizeAnnouncementGroupsForExtensions($value, array_column($this->getAllPjsipExtensions(), 'extension'), null);
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
			'generic', 'unknown',
		], true);
		$aliases = [
			'polycom' => 'poly',
			'poly-com' => 'poly',
			'mitel' => 'aastra',
			'generic_xml' => 'generic',
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
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_test.sh', $runtimeDir . '/sls_mass_notify_test.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_update.sh', $runtimeDir . '/sls_mass_notify_update.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_maintenance.sh', $runtimeDir . '/sls_mass_notify_maintenance.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_uninstall.sh', $runtimeDir . '/sls_mass_notify_uninstall.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sls_mass_notify_install_piper_voices.sh', $runtimeDir . '/sls_mass_notify_install_piper_voices.sh', 0755);
		$this->copyRuntimeFile(__DIR__ . '/bin/sign_sls_mass_notify_local_sig.sh', '/usr/local/sbin/sign_sls_mass_notify_local_sig.sh', 0755);
		$this->copyRuntimeDirectory(__DIR__ . '/bin/sls_mass_notify', $runtimeDir, 0755);
		$this->copyRuntimeDirectory(__DIR__ . '/api/sipnotify', '/var/www/html/api/sipnotify', 0644);
		$this->copyRuntimeDirectory(__DIR__ . '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify', 0644);
		$this->copyRuntimeDirectory(__DIR__ . '/assets', '/var/www/html/sls_mass_notify/assets', 0644);
		$this->copyRuntimeDirectory(__DIR__ . '/sounds', self::SOUNDS_DIR, 0644, false);
		@unlink($runtimeDir . '/config.ini');
		$this->ensureRuntimePermissions();
		$this->secureExecutableRuntimeTree();
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
			self::PIPER_DATA_DIR . '/venv',
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
			if (!is_file($logFile)) {
				@touch($logFile);
			}
			@chmod($logFile, 0640);
			@chown($logFile, 'asterisk');
			@chgrp($logFile, 'asterisk');
		}

		foreach ([
			'/var/www/html/sls_mass_notify',
			self::PLUGIN_DATA_DIR,
			self::PLUGIN_DATA_DIR . '/sipnotify',
			self::PLUGIN_DATA_DIR . '/config-backups',
			self::SOUNDS_DIR,
			self::TONES_DIR,
			self::TTS_DIR,
			self::PIPER_DATA_DIR,
			self::PIPER_VOICE_DIR,
		] as $dir) {
			if (!is_dir($dir)) {
				@mkdir($dir, 0755, true);
			}
		}

		$pluginDirs = [
			self::SOUNDS_DIR,
			self::TONES_DIR,
			self::TTS_DIR,
			self::PIPER_VOICE_DIR,
		];
		foreach ($pluginDirs as $dir) {
			@chown($dir, 'asterisk');
			@chgrp($dir, 'asterisk');
			@chmod($dir, 0755);
		}
		$journal = self::PLUGIN_DATA_DIR . '/sipnotify/sipnotify_events.jsonl';
		if (!is_file($journal)) {
			@touch($journal);
		}
		@chown($journal, 'asterisk');
		@chgrp($journal, 'asterisk');
		@chmod($journal, 0640);

		$this->runCommand('/bin/chown -R asterisk:asterisk ' . escapeshellarg('/var/www/html/sls_mass_notify'));
		$this->runCommand('/bin/chown -R asterisk:asterisk ' . escapeshellarg(self::PLUGIN_DATA_DIR));
		@chmod(self::PLUGIN_DATA_DIR, 0750);
		@chmod(self::PLUGIN_DATA_DIR . '/sipnotify', 0750);
		@chmod(self::PLUGIN_DATA_DIR . '/config-backups', 0750);
		@chmod(self::PIPER_DATA_DIR, 0750);
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PLUGIN_DATA_DIR . '/sipnotify') . ' -type f -exec chmod 640 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PLUGIN_DATA_DIR . '/config-backups') . ' -type f -exec chmod 640 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::SOUNDS_DIR) . ' -type f -name ' . escapeshellarg('*.wav') . ' -exec chmod 664 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg(self::PIPER_VOICE_DIR) . ' -type f -exec chmod 644 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg('/var/www/html/sls_mass_notify') . ' -type d -exec chmod 755 {} +');
		$this->runCommand('/usr/bin/find ' . escapeshellarg('/var/www/html/sls_mass_notify') . ' -type f -exec chmod 644 {} +');
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
		}
	}

	private function ensurePiperWrapper()
	{
		$this->repairPiperRuntimePermissions();
		if (!file_exists(self::PIPER_BIN)) {
			return;
		}
		$wrapper = '/usr/local/bin/piper';
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

	private function repairPiperRuntimePermissions()
	{
		foreach ([self::PIPER_BIN, self::PIPER_RUNTIME_DIR . '/venv/bin/python', self::PIPER_RUNTIME_DIR . '/venv/bin/python3'] as $path) {
			if (file_exists($path)) {
				@chmod($path, 0755);
			}
		}
		if (file_exists('/usr/local/bin/piper')) {
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
		foreach (['sls_mass_notify_nws_poll.sh', 'sls_mass_notify_test.sh', 'sls_mass_notify_update.sh', 'sls_mass_notify_maintenance.sh', 'sls_mass_notify_uninstall.sh', 'sls_mass_notify_install_piper_voices.sh', 'sls_notify.py', 'sls_config.py'] as $file) {
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
		$settings = $this->getActiveSettings();
		$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
		$username = $this->normalizeEndpointUsername($ami['username'] ?? 'slsmassnotify', 'ami');
		$password = $this->normalizeEndpointPassword($ami['password'] ?? '') ?: $this->generateApiKey();
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
		file_put_contents($path, trim($current) === '' ? '' : rtrim($current) . "\n", LOCK_EX);
			$block = "[sls-alert-audio]\n"
				. "exten => _X!,1,NoOp(SLS Mass Notification audio to \${EXTEN})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification page initiated for \${EXTEN} sound \${SLS_SOUND})\n"
				. " same => n,Verbose(1,SLS Mass Notification page initiated for \${EXTEN} sound \${SLS_SOUND})\n"
				. " same => n,Set(SLS_SAFE_SOUND=\${FILTER(0-9A-Za-z_/-,\${SLS_SOUND})})\n"
			. " same => n,GotoIf($[\"\${SLS_SAFE_SOUND}\"=\"\"]?done)\n"
			. " same => n,Set(__SLS_SAFE_SOUND=\${SLS_SAFE_SOUND})\n"
			. " same => n,Set(SLS_DIAL=\${DB(DEVICE/\${EXTEN}/dial)})\n"
			. " same => n,ExecIf($[\"\${SLS_DIAL}\"=\"\"]?Set(SLS_DIAL=\${PJSIP_DIAL_CONTACTS(\${EXTEN})}))\n"
			. " same => n,ExecIf($[\"\${SLS_DIAL}\"=\"\"]?Set(SLS_DIAL=PJSIP/\${EXTEN}))\n"
				. " same => n,GotoIf($[\"\${SLS_DIAL}\"=\"\"]?done)\n"
				. " same => n,NoOp(SLS Mass Notification dial string \${SLS_DIAL})\n"
				. " same => n,Log(NOTICE,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Verbose(1,SLS Mass Notification dialing \${SLS_DIAL} for \${EXTEN})\n"
				. " same => n,Set(CALLERID(name)=\${IF($[\"\${SLS_CALLERID_NAME}\"=\"\"]?SLS Mass Notification System:\${SLS_CALLERID_NAME})})\n"
			. " same => n,Set(CALLERID(num)=\${IF($[\"\${SLS_CALLERID_NUM}\"=\"\"]?SLS:\${SLS_CALLERID_NUM})})\n"
			. " same => n,Set(_ALERTINFO=Ring Answer)\n"
			. " same => n,Set(_CALLINFO=<uri>\\;answer-after=0)\n"
			. " same => n,Set(_SIPURI=intercom=true)\n"
				. " same => n,Gosub(macro-autoanswer,s,1(\${EXTEN}))\n"
				. " same => n,Dial(\${SLS_DIAL},30,b(autoanswer^s^1(\${ALERTINFO},\${CALLINFO}))A(\${SLS_SAFE_SOUND}))\n"
				. " same => n,Log(NOTICE,SLS Mass Notification page completed for \${EXTEN} dialstatus \${DIALSTATUS})\n"
				. " same => n,Verbose(1,SLS Mass Notification page completed for \${EXTEN} dialstatus \${DIALSTATUS})\n"
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
			'/\n\s*\$final\[\$i\]\s*=\s*\$this->checkSlsMassNotify\(\);\s*\$final\[\$i\]\[\'title\'\]\s*=\s*_\("Mass Notifications Plugin"\);\s*\$i\+\+;\s*/s',
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
		$needle = "\telse if (\$a == 'other')\n\t\treturn 1;\n";
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
		if (strpos($current, $needle) === false) {
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
			"/\t\/\/ SLS Mass Notifications menu placement:.*?(?=\telse if \\(\\\$a == 'other'\\)\n\t\treturn 1;\n)/s",
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

	private function signLocalModulesIfAvailable()
	{
		$candidates = [
			'/usr/local/sbin/sign_sls_mass_notify_local_sig.sh',
			'/home/localadmin/sign_sls_mass_notify_local_sig.sh',
		];
		$signer = '';
		foreach ($candidates as $candidate) {
			if (is_executable($candidate)) {
				$signer = $candidate;
				break;
			}
		}
		if ($signer === '') {
			return;
		}
		$moduleRawName = basename(__DIR__);
		$this->runCommand(escapeshellarg($signer) . ' ' . escapeshellarg($moduleRawName));
			if (is_dir('/var/www/html/admin/modules/dashboard')) {
				$this->runCommand(escapeshellarg($signer) . ' dashboard');
			}
			// Menu placement modifies a framework-owned view, so cover that managed
			// integration with the same trusted local signature.
			if (is_dir('/var/www/html/admin/modules/framework')) {
				$this->runCommand(escapeshellarg($signer) . ' framework');
			}
	}

	private function repairPostUninstallSignatures()
	{
		// Never run nested fwconsole module transactions from a module uninstall
		// hook. The standalone uninstaller restores stock modules after this
		// transaction; native Module Admin removal can safely retain local trusted
		// signatures for the two integration-owned files it just restored.
		$this->signLocalModulesIfAvailable();
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
		if (!is_readable($source)) {
			return;
		}
		if (!$overwrite && file_exists($target)) {
			return;
		}
		$dir = dirname($target);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		@copy($source, $target);
		@chmod($target, $mode);
	}

	private function copyRuntimeDirectory($source, $target, $mode = 0644, $overwrite = true)
	{
		if (!is_dir($source)) {
			return;
		}
		if (!is_dir($target)) {
			@mkdir($target, 0755, true);
		}
		foreach (scandir($source) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
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

	private function ensureCronJob()
	{
		$this->removeLegacyNwsCronJob();
		$cron = $this->FreePBX->Cron();
		$hasPoll = false;
		foreach ($cron->getAll() as $line) {
			$line = (string)$line;
			if (strpos((string)$line, 'sls_mass_notify_nws_poll.sh') !== false) {
				if (strpos((string)$line, '/usr/bin/timeout 55') === false || strpos((string)$line, '* * * * *') === false) {
					$cron->remove($line);
					continue;
				}
				$hasPoll = true;
			}
			if (strpos($line, 'sls_mass_notify_update.sh') !== false) {
				$cron->remove($line);
			}
		}
		if (!$hasPoll) {
			$cron->addLine('* * * * * /usr/bin/timeout 55 /usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh');
		}
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
			if (strpos((string)$line, 'sls_mass_notify_nws_poll.sh') !== false || strpos((string)$line, 'sls_mass_notify_update.sh') !== false) {
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

	private function normalizeNwsApiBaseUrl($value)
	{
		$value = rtrim(trim((string)$value), '/');
		if (!preg_match('#^https://[A-Za-z0-9.-]+(?:/[A-Za-z0-9._~/-]*)?$#', $value)) {
			return '';
		}
		return $value;
	}

	private function normalizeNwsZone($value)
	{
		$value = strtoupper(trim((string)$value));
		return preg_match('/^[A-Z]{2}[CZ][0-9]{3}$/', $value) ? $value : '';
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
		if (in_array($state, ['ok', 'queued', 'healthy'], true)) {
			return 'ok';
		}
		if (in_array($state, ['warning', 'warn', 'notice'], true)) {
			return 'notice';
		}
		if (in_array($state, ['fault', 'failed', 'error'], true)) {
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
		return implode(' ', array_values(array_unique($parts ?: [])));
	}

	private function normalizeDiscordWebhookUrl($value)
	{
		return trim((string)$value);
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
		$available = [];
		foreach ($this->getAllPjsipExtensions() as $extension) {
			$available[$extension['extension']] = true;
		}

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
			'type_label' => strtoupper($type),
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
		return in_array($type, ['nws', 'test', 'announcement', 'announcement_audio'], true) ? $type : '';
	}
}
