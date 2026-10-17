<?php
declare(strict_types=1);
namespace FreePBX\modules;

/** Root-only service integration; the collector itself runs without root. */
trait SlsPhoneEventService
{
	protected function phoneCollectorFailure(string $code): array
	{
		$messages = [
			'phone_collector_helper_missing' => 'The installed phone collector helper is missing. Restore the signed runtime with SLS Repair Installation.',
			'phone_collector_helper_unsafe' => 'The phone collector helper has unsafe ownership, permissions or links. Preserve it for review; restore the verified signed runtime.',
			'phone_collector_posix_unavailable' => 'This web PHP handler lacks posix_geteuid or posix_getpwnam. Enable the POSIX extension for the actual web PHP version and reload that PHP handler.',
			'phone_collector_exec_disabled' => 'The web PHP handler disables exec. Review its disable_functions setting; CLI PHP and web PHP may use different configuration files.',
			'phone_collector_runtime_account_missing' => 'The local asterisk runtime account could not be resolved. Review the PBX account setup; do not run notification workers as an unrelated web account.',
			'phone_collector_web_uid_mismatch' => 'This web PHP handler runs as a different account from asterisk. Review the selected Apache/PHP-FPM pool identity and its PBX configuration. No privilege escalation was attempted.',
			'phone_collector_probe_binary_missing' => 'A required local timeout, Python or root-only runuser executable is unavailable. Restore that dependency before probing the collector.',
			'phone_collector_probe_timeout' => 'The collector health helper exceeded its two-second deadline. Check CPU/storage pressure and protected phone-admission storage access.',
			'phone_collector_probe_failed' => 'The local collector health helper did not return a valid result. Check journalctl -u sls-mass-notify-phone-events.service and protected runtime/data permissions.',
			'phone_collector_health_invalid' => 'The collector health response has invalid fields. Restore the matching verified module and runtime together; preserve phone admission history.',
			'phone_event_collector_state_missing' => 'The private phone admission ledger has not been created. Check whether sls-mass-notify-phone-events.service is running and authenticating to local AMI.',
			'phone_event_collector_state_permission' => 'The runtime cannot read the private phone admission ledger. Review its asterisk ownership and protected data-directory permissions without changing unrelated PBX files.',
			'phone_event_collector_never_connected' => 'No authenticated collector heartbeat has been recorded. Check the collector service journal and the saved loopback AMI login and permissions.',
			'phone_event_collector_stale' => 'The authenticated collector heartbeat is older than fifteen seconds. Check service status, AMI connectivity and CPU/storage pressure.',
			'phone_event_collector_rebooted' => 'The recorded heartbeat belongs to an earlier boot. Wait for a current authenticated AMI heartbeat; if it does not arrive, inspect the collector service journal.',
			'phone_event_collector_clock_invalid' => 'The recorded monotonic heartbeat is invalid or in the future. Inspect boot/time evidence and the collector journal; do not edit the ledger to fabricate a heartbeat.',
			'phone_config_unsafe' => 'Phone admission data has unsafe ownership, permissions or links. Preserve it and review protected data access before accepting more phone audio.',
			'phone_config_invalid' => 'Phone admission data could not be decoded or validated. Preserve the ledger for recovery and inspect the collector journal.',
			'phone_event_collector_unavailable' => 'A fresh authenticated collector heartbeat is unavailable. Check the collector service journal, its loopback AMI connection and protected admission ledger.',
		];
		if (!isset($messages[$code]) && str_starts_with($code, 'phone_state_')) {
			$code = 'phone_config_invalid';
		}
		if (!isset($messages[$code])) { $code = 'phone_collector_probe_failed'; }
		$message = _($messages[$code]) . ' ' . _('New phone audio admission is blocked; existing call outcomes may be unknown.') . ' ' . _('Code:') . ' ' . $code;
		return ['state'=>'fault', 'ok'=>false, 'failure_code'=>$code, 'message'=>$message, 'issues'=>[$message]];
	}

	protected function phoneCollectorRunHealthProbe(string $helper, string $runAs): array
	{
		$output=[]; $status=1;
		try { @exec('/usr/bin/timeout --kill-after=1 2 /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN ' . $runAs . '/usr/bin/python3 -I ' . escapeshellarg($helper) . ' --health 2>/dev/null', $output, $status); }
		catch (\Throwable $error) { return ['status'=>1, 'result'=>null]; }
		return ['status'=>$status, 'result'=>json_decode(implode("\n",$output),true)];
	}

	/** Dashboard evidence only. Audio admission uses the authoritative runtime probe. */
	public function getPhoneEventCollectorDisplayHealth(): array
	{
		if (!function_exists('posix_getpwnam')) { return $this->phoneCollectorFailure('phone_collector_posix_unavailable'); }
		$path=self::PLUGIN_DATA_DIR.'/phone-collector-display.json';
		$account=posix_getpwnam('asterisk');clearstatcache(true,$path);$before=@lstat($path);
		if (!$before || !is_array($account) || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1
			|| !in_array($before['uid'],[0,$account['uid']],true) || $before['gid']!==$account['gid']
			|| ($before['mode']&07027) || $before['size']>1024 || realpath(dirname($path))!==dirname($path)) {
			return $this->phoneCollectorFailure('phone_event_collector_state_missing');
		}
		$handle=@fopen($path,'r+b');if (!$handle) { return $this->phoneCollectorFailure('phone_event_collector_state_permission'); }
		try {
			$opened=fstat($handle);clearstatcache(true,$path);$named=@lstat($path);
			if (!is_array($opened) || !$named || $opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino']
				|| $opened['dev']!==$named['dev'] || $opened['ino']!==$named['ino']) { throw new \RuntimeException('changed'); }
			$raw=stream_get_contents($handle,1025);$after=fstat($handle);clearstatcache(true,$path);$named=@lstat($path);
			foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field){
				if(!is_array($after)||!is_array($named)||$before[$field]!==$opened[$field]
					||$opened[$field]!==$after[$field]||$after[$field]!==$named[$field]){throw new \RuntimeException('changed');}
			}
			if(!is_string($raw)||strlen($raw)!==$opened['size']){throw new \RuntimeException('changed');}
			$value=json_decode($raw,true,4,JSON_THROW_ON_ERROR);
			$boot=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
			if (!is_array($value) || ($value['schema']??null)!==1 || ($value['connected']??null)!==true
					|| $boot==='' || ($value['boot_id']??null)!==$boot
					|| !(is_int($value['heartbeat_tick']??null) || is_float($value['heartbeat_tick']??null))
				|| !is_int($value['active_batches']??null) || $value['active_batches']<0
				|| !is_int($value['reserved_contacts']??null) || $value['reserved_contacts']<0) { throw new \RuntimeException('invalid'); }
			$age=hrtime(true)/1000000000-(float)$value['heartbeat_tick'];
			if (!is_finite($age) || $age<0 || $age>15) { return $this->phoneCollectorFailure('phone_event_collector_stale'); }
			return ['state'=>'ok','ok'=>true,'display_only'=>true,'failure_code'=>'','issues'=>[],
				'message'=>_('Phone outcome collector has a current authenticated AMI heartbeat.'),
				'heartbeat_age_seconds'=>$age,'active_batches'=>$value['active_batches'],'reserved_contacts'=>$value['reserved_contacts']];
		} catch (\Throwable $error) { return $this->phoneCollectorFailure('phone_collector_health_invalid'); }
		finally { fclose($handle); }
	}

	public function getPhoneEventCollectorHealth()
	{
		$helper=self::RUNTIME_DIR.'/sls_phone_events.py';
		clearstatcache(true,$helper); $meta=@lstat($helper);
		if (!$meta) { return $this->phoneCollectorFailure('phone_collector_helper_missing'); }
		if (($meta['mode']&0170000)!==0100000 || $meta['nlink']!==1 || $meta['uid']!==0 || ($meta['mode']&0022) || !is_readable($helper)) {
			return $this->phoneCollectorFailure('phone_collector_helper_unsafe');
		}
		if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) { return $this->phoneCollectorFailure('phone_collector_posix_unavailable'); }
		if (!function_exists('exec')) { return $this->phoneCollectorFailure('phone_collector_exec_disabled'); }
		$account=posix_getpwnam('asterisk');$uid=posix_geteuid();
		if (!is_array($account) || !is_int($account['uid'] ?? null) || $account['uid']<=0) { return $this->phoneCollectorFailure('phone_collector_runtime_account_missing'); }
		if ($uid!==0 && $uid!==$account['uid']) { return $this->phoneCollectorFailure('phone_collector_web_uid_mismatch'); }
		foreach (array_merge(['/usr/bin/env','/usr/bin/timeout','/usr/bin/python3'],$uid===0 ? ['/usr/sbin/runuser'] : []) as $binary) {
			if (!is_executable($binary)) { return $this->phoneCollectorFailure('phone_collector_probe_binary_missing'); }
		}
		$probe=$this->phoneCollectorRunHealthProbe($helper,$uid===0 ? '/usr/sbin/runuser -u asterisk -- ' : '');
		$result=$probe['result'] ?? null;$status=$probe['status'] ?? 1;
		if ($status===124 || $status===137) { return $this->phoneCollectorFailure('phone_collector_probe_timeout'); }
		if (is_array($result) && ($result['ok'] ?? null)===false && is_string($result['failure_code'] ?? null)) { return $this->phoneCollectorFailure($result['failure_code']); }
		if (!is_array($result) || $status!==0) { return $this->phoneCollectorFailure('phone_collector_probe_failed'); }
		if (($result['ok'] ?? null)!==true || ($result['connected'] ?? null)!==true
				|| !(is_int($result['heartbeat_age_seconds'] ?? null) || is_float($result['heartbeat_age_seconds'] ?? null))
				|| !is_finite((float)$result['heartbeat_age_seconds'])
			|| !is_int($result['active_batches'] ?? null) || $result['active_batches']<0
			|| !is_int($result['reserved_contacts'] ?? null) || $result['reserved_contacts']<0) { return $this->phoneCollectorFailure('phone_collector_health_invalid'); }
		if ($result['heartbeat_age_seconds']<0) { return $this->phoneCollectorFailure('phone_event_collector_clock_invalid'); }
		if ($result['heartbeat_age_seconds']>15) { return $this->phoneCollectorFailure('phone_event_collector_stale'); }
		return ['state'=>'ok', 'ok'=>true, 'failure_code'=>'', 'issues'=>[], 'message'=>_('Phone outcome collector has a current authenticated AMI heartbeat.'),
			'heartbeat_age_seconds'=>(float)$result['heartbeat_age_seconds'], 'active_batches'=>$result['active_batches'], 'reserved_contacts'=>$result['reserved_contacts']];
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
		if (!function_exists('exec')) { return false; }
		$output = [];
		$status = 1;
		@exec('/usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl '
			. implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null', $output, $status);
		return $status === 0;
	}

	protected function phoneEventRuntimeProbe($filename, $flag)
	{
		if (!in_array([$filename, $flag], [['sls_phone_admission.py', '--probe-ami'], ['sls_phone_events.py', '--health']], true)
			|| !function_exists('posix_geteuid') || !function_exists('posix_getpwnam') || !function_exists('exec')) { return false; }
		$account = posix_getpwnam('asterisk');
		if (!is_array($account) || !is_int($account['uid'] ?? null) || $account['uid'] <= 0) { return false; }
		$uid = posix_geteuid();
		if ($uid !== 0 && $uid !== $account['uid']) { return false; }
		$helper=self::RUNTIME_DIR.'/'.$filename;clearstatcache(true,$helper);$meta=@lstat($helper);
		if (!$meta || ($meta['mode']&0170000)!==0100000 || $meta['nlink']!==1 || $meta['uid']!==0 || ($meta['mode']&0022)) { return false; }
		// GUI Apply Config inherits the Asterisk PHP-FPM account. runuser is
		// root-only; installation uses it to drop privilege, while GUI reloads
		// already have exactly the runtime account required by these probes.
		$runAs = $uid === 0 ? '/usr/sbin/runuser -u asterisk -- ' : '';
		$output = [];
		$status = 1;
		@exec('/usr/bin/timeout --kill-after=1 5 /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN ' . $runAs . '/usr/bin/python3 -I '
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
			throw new \RuntimeException(_('The phone runtime cannot verify AMI Ping and ShowDialPlan. Check the local AMI login, loopback endpoint and reporting permission; inspect the collector service journal.') . ' ' . $this->amiEndpointDiagnostic($ami));
		}
		$deadline = microtime(true) + 10;
		do {
			if ($this->phoneEventRuntimeProbe('sls_phone_events.py', '--health')) { return; }
			usleep(200000);
		} while (microtime(true) < $deadline);
		$health=$this->getPhoneEventCollectorHealth();
		throw new \RuntimeException(is_string($health['message'] ?? null) ? $health['message'] : _('The phone collector has no fresh authenticated AMI heartbeat. Review the collector service journal and protected data permissions before sending phone audio.'));
	}

	/** FreePBX has generated manager_additional.conf and reloaded Asterisk. */
	public function postReloadPhoneEventCollector()
	{
		// Asterisk/AMI may still be reconnecting. SLS faults must not prevent
		// the host PBX from applying configuration or completing a restart.
		$ready = false;
		try {
			$health = $this->getPhoneEventCollectorHealth();
			$ready = is_array($health) && ($health['ok'] ?? null) === true;
		} catch (\Throwable $error) {
			// Never log probe/configuration exceptions containing credentials.
		}
		if (!$ready) {
			try {
				@error_log(_('SLS Mass Notify: FreePBX reload continues while the phone receipt collector is unavailable. SLS phone audio requires a fresh authenticated AMI heartbeat. Check systemctl status sls-mass-notify-phone-events.service and journalctl -u sls-mass-notify-phone-events.service, then run SLS Repair Installation.'));
			} catch (\Throwable $error) {
				// A logging failure must not abort FreePBX reload either.
			}
		}
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
		$this->readPhoneEventServiceFile();
	}

	private function readPhoneEventServiceFile(): ?string
	{
		$path=self::PHONE_EVENT_SERVICE_FILE;clearstatcache(true,$path);$before=@lstat($path);
		if ($before===false) { return null; }
		$safe=static fn($meta):bool=>is_array($meta)&&($meta['mode']&0170000)===0100000&&$meta['nlink']===1
			&&$meta['uid']===0&&($meta['mode']&0022)===0&&$meta['size']>=0&&$meta['size']<=16384;
		if(!$safe($before)){throw new \RuntimeException(_('Refusing to replace or remove an unsafe or unrelated phone collector service file.'));}
		$handle=@fopen($path,function_exists('posix_geteuid')&&posix_geteuid()===0?'r+b':'rb');
		if(!$handle){throw new \RuntimeException(_('The phone collector service file could not be opened safely.'));}
		try{
			$opened=fstat($handle);$contents=stream_get_contents($handle,16385);$after=fstat($handle);clearstatcache(true,$path);$named=@lstat($path);
			foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field){
				if(!$safe($opened)||!$safe($after)||!$safe($named)||$before[$field]!==$opened[$field]
					||$opened[$field]!==$after[$field]||$after[$field]!==$named[$field]){
					throw new \RuntimeException(_('The phone collector service file changed while it was read.'));
				}
			}
			if(!is_string($contents)||strlen($contents)!==$opened['size']
				||!str_starts_with($contents,"# Managed by SLS Mass Notify Server\n[Unit]\n")){
				throw new \RuntimeException(_('Refusing to replace or remove an unsafe or unrelated phone collector service file.'));
			}
			return $contents;
		}finally{fclose($handle);}
	}

	protected function ensurePhoneEventCollector()
	{
		$this->validatePhoneEventServicePath();
		$contents = $this->phoneEventServiceContents();
		$path = self::PHONE_EVENT_SERVICE_FILE;
		clearstatcache(true, $path); $before = @lstat($path);
		$temporary = dirname($path) . '/.sls-phone-events-' . bin2hex(random_bytes(16));
		$handle = @fopen($temporary, 'x+b');
		if ($handle === false) { throw new \RuntimeException(_('Unable to stage the phone collector service.')); }
		$temporaryIdentity = fstat($handle);
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
			if (!@fflush($handle) || !@fsync($handle)) {
				throw new \RuntimeException(_('Unable to activate the phone collector service.'));
			}
			$opened=fstat($handle);clearstatcache(true,$temporary);$staged=@lstat($temporary);clearstatcache(true,$path);$current=@lstat($path);
			if(!is_array($temporaryIdentity)||!is_array($opened)||!is_array($staged)
				||($opened['mode']&0170000)!==0100000||$opened['nlink']!==1||$staged['nlink']!==1
				||$opened['dev']!==$temporaryIdentity['dev']||$opened['ino']!==$temporaryIdentity['ino']
				||$opened['dev']!==$staged['dev']||$opened['ino']!==$staged['ino']||$opened['size']!==strlen($contents)
				||($opened['mode']&0777)!==0644||$opened['uid']!==0||$opened['gid']!==0
				||(($before===false)!==($current===false))
					||($before!==false&&($before['dev']!==$current['dev']||$before['ino']!==$current['ino']))
					||!@rename($temporary,$path)){throw new \RuntimeException(_('Unable to activate the phone collector service.'));}
				clearstatcache(true,$path);$committed=@lstat($path);
				if(!is_array($committed)||$committed['dev']!==$temporaryIdentity['dev']||$committed['ino']!==$temporaryIdentity['ino']
					||$committed['nlink']!==1||$committed['size']!==strlen($contents)||($committed['mode']&0777)!==0644){
					throw new \RuntimeException(_('The activated phone collector service changed unexpectedly.'));
				}
				$directoryHandle=@fopen(dirname($path),'r');
				try{if(!$directoryHandle||!@fsync($directoryHandle)){throw new \RuntimeException(_('Unable to persist the phone collector service.'));}}
				finally{if($directoryHandle){fclose($directoryHandle);}}
		} finally {
			fclose($handle);
			clearstatcache(true,$temporary);$current=@lstat($temporary);
			if(is_array($current)&&is_array($temporaryIdentity)&&($current['mode']&0170000)===0100000&&$current['nlink']===1
				&&$current['dev']===$temporaryIdentity['dev']&&$current['ino']===$temporaryIdentity['ino']){@unlink($temporary);}
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
		// Installation and restore verify capabilities and heartbeat separately;
		// new audio admission stays fail-closed until the collector is healthy.
	}

	protected function verifyPhoneEventCollectorService()
	{
		$this->validatePhoneEventServicePath();
		if ($this->readPhoneEventServiceFile() !== $this->phoneEventServiceContents()
			|| !$this->phoneEventSystemctl(['is-enabled', '--quiet', 'sls-mass-notify-phone-events.service'])
			|| !$this->phoneEventSystemctl(['is-active', '--quiet', 'sls-mass-notify-phone-events.service'])) {
			throw new \RuntimeException(_('The phone outcome collector service is incomplete, disabled, or stopped. Run Repair Installation before sending phone audio.'));
		}
	}

	protected function removePhoneEventCollector()
	{
		$this->validatePhoneEventServicePath();
		$path=self::PHONE_EVENT_SERVICE_FILE;clearstatcache(true,$path);$identity=@lstat($path);
		if ($identity===false) { return; }
		if (!$this->phoneEventSystemctl(['disable', '--now', 'sls-mass-notify-phone-events.service'])
			|| $this->phoneEventSystemctl(['is-active', '--quiet', 'sls-mass-notify-phone-events.service'])) {
			throw new \RuntimeException(_('Unable to stop the phone outcome collector; runtime files were preserved.'));
		}
		clearstatcache(true,$path);$current=@lstat($path);
		if(!is_array($current)||$current['dev']!==$identity['dev']||$current['ino']!==$identity['ino']||!@unlink($path)){
			throw new \RuntimeException(_('The phone outcome collector service changed during removal and was preserved.'));
		}
		$directoryHandle=@fopen(dirname($path),'r');
		try{$synced=$directoryHandle&&@fsync($directoryHandle);}finally{if($directoryHandle){fclose($directoryHandle);}}
		if (!$synced || !$this->phoneEventSystemctl(['daemon-reload'])) {
			throw new \RuntimeException(_('Unable to remove the phone outcome collector service.'));
		}
	}
}
