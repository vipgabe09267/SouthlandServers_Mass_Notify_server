<?php
namespace FreePBX\modules;

/** Root-only service integration; the collector itself runs without root. */
trait SlsPhoneEventService
{
	public function getPhoneEventCollectorHealth()
	{
		$failure = ['state' => 'fault', 'ok' => false, 'message' => _('Phone outcome collection is unavailable. New phone audio admission is blocked, and existing delivery outcomes may be unknown. Check the collector service and run Repair Installation.')];
		$helper = self::RUNTIME_DIR . '/sls_phone_events.py';
		if (!is_readable($helper) || is_link($helper)) { return $failure; }
		$output = [];
		$status = 1;
		@exec('/usr/bin/timeout --kill-after=1 2 /usr/bin/python3 -I ' . escapeshellarg($helper) . ' --health 2>/dev/null', $output, $status);
		$result = json_decode(implode("\n", $output), true);
		if ($status !== 0 || !is_array($result) || ($result['ok'] ?? null) !== true || ($result['connected'] ?? null) !== true
			|| !is_numeric($result['heartbeat_age_seconds'] ?? null) || $result['heartbeat_age_seconds'] < 0 || $result['heartbeat_age_seconds'] > 15
			|| !is_int($result['active_batches'] ?? null) || $result['active_batches'] < 0
			|| !is_int($result['reserved_contacts'] ?? null) || $result['reserved_contacts'] < 0) { return $failure; }
		return ['state' => 'ok', 'ok' => true, 'message' => _('Phone outcome collector has a current authenticated AMI heartbeat.'),
			'heartbeat_age_seconds' => (float)$result['heartbeat_age_seconds'], 'active_batches' => $result['active_batches'], 'reserved_contacts' => $result['reserved_contacts']];
	}

	protected function phoneEventServiceContents()
	{
		return <<<'UNIT'
# Managed by SLS Mass Notify Server
[Unit]
Description=SLS Mass Notify phone outcome collector
After=network.target asterisk.service freepbx.service
StartLimitIntervalSec=0

[Service]
Type=simple
User=asterisk
Group=asterisk
UMask=0027
ExecStart=/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_phone_events.py
Restart=on-failure
RestartSec=5
TimeoutStopSec=10
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/var/lib/asterisk/SLS_Mass_Notifications_Plugin
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
RestrictSUIDSGID=yes
LockPersonality=yes

[Install]
WantedBy=multi-user.target

UNIT;
	}

	protected function phoneEventSystemctl(array $arguments)
	{
		$output = [];
		$status = 1;
		@exec('/usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl '
			. implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null', $output, $status);
		return $status === 0;
	}

	protected function phoneEventRuntimeProbe($filename, $flag)
	{
		if (!in_array([$filename, $flag], [['sls_phone_admission.py', '--probe-ami'], ['sls_phone_events.py', '--health']], true)
			|| !function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) { return false; }
		$account = posix_getpwnam('asterisk');
		if (!is_array($account) || !is_int($account['uid'] ?? null) || $account['uid'] <= 0) { return false; }
		$uid = posix_geteuid();
		if ($uid !== 0 && $uid !== $account['uid']) { return false; }
		// GUI Apply Config inherits the Asterisk PHP-FPM account. runuser is
		// root-only; installation uses it to drop privilege, while GUI reloads
		// already have exactly the runtime account required by these probes.
		$runAs = $uid === 0 ? '/usr/sbin/runuser -u asterisk -- ' : '';
		$output = [];
		$status = 1;
		@exec('/usr/bin/timeout --kill-after=1 5 ' . $runAs . '/usr/bin/python3 -I '
			. escapeshellarg(self::RUNTIME_DIR . '/' . $filename) . ' ' . escapeshellarg($flag) . ' 2>/dev/null', $output, $status);
		$result = json_decode(implode("\n", $output), true);
		if ($status !== 0 || !is_array($result) || ($result['ok'] ?? null) !== true) { return false; }
		return $flag === '--health' ? ($result['connected'] ?? null) === true
			: ($result['ping'] ?? null) === true && ($result['show_dialplan'] ?? null) === true;
	}

	protected function verifyPhoneEventCollectorReadiness()
	{
		if (!$this->phoneEventRuntimeProbe('sls_phone_admission.py', '--probe-ami')) {
			$settings = $this->getActiveSettings();
			$ami = is_array($settings['ami'] ?? null) ? $settings['ami'] : [];
			throw new \RuntimeException(_('The phone runtime cannot verify AMI Ping and ShowDialPlan. Check the local AMI connection and reporting permission, then run Repair Installation.') . ' ' . $this->amiEndpointDiagnostic($ami));
		}
		$deadline = microtime(true) + 10;
		do {
			if ($this->phoneEventRuntimeProbe('sls_phone_events.py', '--health')) { return; }
			usleep(200000);
		} while (microtime(true) < $deadline);
		throw new \RuntimeException(_('The phone collector has no fresh authenticated AMI heartbeat. Review the collector service journal and protected data permissions before sending phone audio.'));
	}

	/** FreePBX has generated manager_additional.conf and reloaded Asterisk. */
	public function postReloadPhoneEventCollector()
	{
		// A newly created AMI account cannot authenticate until Apply Config.
		// Allow the collector's bounded restart backoff to reconnect before
		// requiring active service state. Capability failures still propagate
		// through FreePBX's postReload hook and fail activation verification.
		$this->verifyPhoneEventCollectorReadiness();
		$this->verifyPhoneEventCollectorService();
		return true;
	}

	protected function validatePhoneEventServicePath()
	{
		$path = self::PHONE_EVENT_SERVICE_FILE;
		$directory = dirname($path);
		$parent = @lstat($directory);
		if (!is_array($parent) || realpath($directory) !== $directory || ($parent['mode'] & 0170000) !== 0040000
			|| $parent['uid'] !== 0 || ($parent['mode'] & 0022) !== 0) {
			throw new \RuntimeException(_('The systemd service directory must be a root-owned directory without writable group or public permissions.'));
		}
		clearstatcache(true, $path);
		$metadata = @lstat($path);
		if ($metadata !== false && (($metadata['mode'] & 0170000) !== 0100000 || $metadata['nlink'] !== 1
			|| $metadata['uid'] !== 0 || ($metadata['mode'] & 0022) !== 0
			|| @file_get_contents($path, false, null, 0, strlen("# Managed by SLS Mass Notify Server\n[Unit]\n")) !== "# Managed by SLS Mass Notify Server\n[Unit]\n")) {
			throw new \RuntimeException(_('Refusing to replace or remove an unsafe or unrelated phone collector service file.'));
		}
	}

	protected function ensurePhoneEventCollector()
	{
		$this->validatePhoneEventServicePath();
		$contents = $this->phoneEventServiceContents();
		$path = self::PHONE_EVENT_SERVICE_FILE;
		$temporary = dirname($path) . '/.sls-phone-events-' . bin2hex(random_bytes(16));
		$handle = @fopen($temporary, 'x+b');
		if ($handle === false) { throw new \RuntimeException(_('Unable to stage the phone collector service.')); }
		try {
			if (!@chmod($temporary, 0644) || !@chown($temporary, 0) || !@chgrp($temporary, 0)) {
				throw new \RuntimeException(_('Unable to secure the phone collector service.'));
			}
			$offset = 0;
			while ($offset < strlen($contents)) {
				$written = @fwrite($handle, substr($contents, $offset));
				if (!is_int($written) || $written <= 0) { throw new \RuntimeException(_('Unable to write the phone collector service.')); }
				$offset += $written;
			}
			if (!@fflush($handle) || !@fsync($handle) || !@rename($temporary, $path)) {
				throw new \RuntimeException(_('Unable to activate the phone collector service.'));
			}
		} finally {
			fclose($handle);
			if (is_file($temporary)) { @unlink($temporary); }
		}
		foreach ([['daemon-reload'], ['enable', 'sls-mass-notify-phone-events.service'], ['restart', 'sls-mass-notify-phone-events.service']] as $arguments) {
			if (!$this->phoneEventSystemctl($arguments)) {
				throw new \RuntimeException(_('Unable to enable or start the phone outcome collector. Review systemctl status sls-mass-notify-phone-events.service and the journal, then run Repair Installation.'));
			}
		}
		if (!$this->phoneEventSystemctl(['is-enabled', '--quiet', 'sls-mass-notify-phone-events.service'])) {
			throw new \RuntimeException(_('The phone outcome collector was not enabled. Run Repair Installation before sending phone audio.'));
		}
		// Manager::add_manager changes the database only. Asterisk's existing
		// permissions remain loaded until FreePBX generates its configuration.
		// postReloadPhoneEventCollector performs the mandatory runtime checks;
		// new audio admission stays fail-closed until the collector is healthy.
	}

	protected function verifyPhoneEventCollectorService()
	{
		$this->validatePhoneEventServicePath();
		if (@file_get_contents(self::PHONE_EVENT_SERVICE_FILE) !== $this->phoneEventServiceContents()
			|| !$this->phoneEventSystemctl(['is-enabled', '--quiet', 'sls-mass-notify-phone-events.service'])
			|| !$this->phoneEventSystemctl(['is-active', '--quiet', 'sls-mass-notify-phone-events.service'])) {
			throw new \RuntimeException(_('The phone outcome collector service is incomplete, disabled, or stopped. Run Repair Installation before sending phone audio.'));
		}
	}

	protected function removePhoneEventCollector()
	{
		$this->validatePhoneEventServicePath();
		if (!file_exists(self::PHONE_EVENT_SERVICE_FILE)) { return; }
		if (!$this->phoneEventSystemctl(['disable', '--now', 'sls-mass-notify-phone-events.service'])
			|| $this->phoneEventSystemctl(['is-active', '--quiet', 'sls-mass-notify-phone-events.service'])) {
			throw new \RuntimeException(_('Unable to stop the phone outcome collector; runtime files were preserved.'));
		}
		if (!@unlink(self::PHONE_EVENT_SERVICE_FILE) || !$this->phoneEventSystemctl(['daemon-reload'])) {
			throw new \RuntimeException(_('Unable to remove the phone outcome collector service.'));
		}
	}
}
