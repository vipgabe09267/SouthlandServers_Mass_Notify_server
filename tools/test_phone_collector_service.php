<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/PhoneEventService.php';
$directory = sys_get_temp_dir() . '/sls-phone-service-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
define('SLS_PHONE_SERVICE_FIXTURE', $directory);
class PhoneServiceFixture {
	use \FreePBX\modules\SlsPhoneEventService;
	const PHONE_EVENT_SERVICE_FILE = SLS_PHONE_SERVICE_FIXTURE . '/sls-mass-notify-phone-events.service';
	const RUNTIME_DIR = SLS_PHONE_SERVICE_FIXTURE;
	public $active = false;
	public $enabled = false;
	public $fail = '';
	public $ami = true;
	public $calls = [];
	public $probes = [];
	public $healthFailures = 0;
	public $activateOnHealth = false;
	public function getActiveSettings() { return ['ami' => ['host' => '::1', 'port' => 5038]]; }
	private function amiEndpointDiagnostic(array $ami) { return 'SLS AMI endpoint: [::1]:5038; isolated fixture.'; }
	protected function phoneEventSystemctl(array $arguments) {
		$this->calls[] = $arguments;
		if ($arguments[0] === $this->fail) return false;
		switch ($arguments[0]) {
			case 'enable': $this->enabled = true; return true;
			case 'restart': $this->active = true; return true;
			case 'disable': $this->enabled = false; $this->active = false; return true;
			case 'is-active': return $this->active;
			case 'is-enabled': return $this->enabled;
			case 'daemon-reload': return true;
		}
		throw new RuntimeException('Unexpected mocked systemctl operation.');
	}
	protected function phoneEventRuntimeProbe($filename, $flag) {
		$this->probes[] = [$filename, $flag];
		if (!in_array([$filename, $flag], [['sls_phone_admission.py', '--probe-ami'], ['sls_phone_events.py', '--health']], true)) {
			throw new RuntimeException('Unexpected runtime probe.');
		}
		if ($flag === '--health' && $this->healthFailures > 0) { $this->healthFailures--; return false; }
		if ($flag === '--health' && $this->activateOnHealth) { $this->active = true; }
		return $this->ami;
	}
	public function installFixture() { $this->ensurePhoneEventCollector(); }
	public function removeFixture() { $this->removePhoneEventCollector(); }
	public function verifyFixture() { $this->verifyPhoneEventCollectorService(); }
}
function serviceAssert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function serviceReject($callback, $message) {
	try { $callback(); } catch (RuntimeException $error) { return; }
	throw new RuntimeException($message);
}
try {
	$fixture = new PhoneServiceFixture();
	// Model FreePBX's actual ordering: Manager::add_manager updates its DB,
	// install() runs before genConfig/writeConfig and the Asterisk reload.
	$fixture->ami = false;
	$fixture->installFixture();
	serviceAssert($fixture->probes === [], 'Install probed AMI before FreePBX generated its new permissions.');
	serviceReject(function () use ($fixture) { $fixture->postReloadPhoneEventCollector(); }, 'Post-reload capability failure was ignored.');
	$fixture->ami = true;
	$fixture->active = false;
	$fixture->healthFailures = 1;
	$fixture->activateOnHealth = true;
	$restartCount = count(array_filter($fixture->calls, static function ($call) { return $call[0] === 'restart'; }));
	serviceAssert($fixture->postReloadPhoneEventCollector() === true, 'Generated and loaded AMI account was not verified.');
	serviceAssert(count(array_filter($fixture->calls, static function ($call) { return $call[0] === 'restart'; })) === $restartCount,
		'Ordinary Apply Config needlessly restarted the collector and interrupted event continuity.');
	$fixture->activateOnHealth = false;
	serviceAssert(in_array(['sls_phone_admission.py', '--probe-ami'], $fixture->probes, true)
		&& in_array(['sls_phone_events.py', '--health'], $fixture->probes, true), 'Post-reload skipped a mandatory capability or heartbeat check.');
	$xml = simplexml_load_file(dirname(__DIR__) . '/slsmassnotifyserver/module.xml');
	$hooks = $xml->xpath('/module/hooks/framework[@class="Reload"][@namespace="FreePBX"]/method[@callingMethod="postReload"][@class="Slsmassnotifyserver"][@namespace="FreePBX\\modules"]');
	serviceAssert(count($hooks) === 1 && (string)$hooks[0] === 'postReloadPhoneEventCollector', 'FreePBX postReload hook discovery does not bind the mandatory verifier.');
	$unit = file_get_contents(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	foreach (['User=asterisk', 'Group=asterisk', 'ExecStart=/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_phone_events.py', 'Restart=on-failure', 'ProtectSystem=strict', 'NoNewPrivileges=yes', 'ReadWritePaths=/var/lib/asterisk/SLS_Mass_Notifications_Plugin'] as $line) {
		serviceAssert(strpos($unit, $line) !== false, 'Collector unit protection missing: ' . $line);
	}
	serviceAssert((fileperms(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE) & 0777) === 0644, 'Collector unit mode is unsafe.');
	serviceAssert($fixture->active && $fixture->enabled, 'Post-reload did not verify enabled and active service.');
	$fixture->active = false;
	serviceReject(function () use ($fixture) { $fixture->verifyFixture(); }, 'Stopped collector passed verification.');
	$fixture->fail = 'enable';
	serviceReject(function () use ($fixture) { $fixture->installFixture(); }, 'Failed enable was ignored.');
	$fixture->fail = '';
	$fixture->ami = false;
	$fixture->installFixture();
	serviceReject(function () use ($fixture) { $fixture->postReloadPhoneEventCollector(); }, 'Failed post-reload AMI reporting probe was ignored.');
	$fixture->ami = true;
	$fixture->installFixture();
	$fixture->fail = 'disable';
	serviceReject(function () use ($fixture) { $fixture->removeFixture(); }, 'Failed collector stop was ignored.');
	serviceAssert(is_file(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE), 'Failed stop discarded recovery unit.');
	$fixture->fail = '';
	$fixture->removeFixture();
	serviceAssert(!file_exists(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE) && !$fixture->active, 'Collector removal was incomplete.');
	$fixture->removeFixture(); // No service remains; repeated uninstall is safe.
	file_put_contents(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE, "[Unit]\nDescription=Unrelated\n");
	serviceReject(function () use ($fixture) { $fixture->installFixture(); }, 'Unrelated service was overwritten.');
	serviceReject(function () use ($fixture) { $fixture->removeFixture(); }, 'Unrelated service was removed.');
	unlink(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	$target = $directory . '/unrelated'; file_put_contents($target, 'preserve');
	symlink($target, PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	serviceReject(function () use ($fixture) { $fixture->installFixture(); }, 'Symlink unit was accepted.');
	unlink(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	link($target, PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	serviceReject(function () use ($fixture) { $fixture->installFixture(); }, 'Hardlinked unit was accepted.');
	unlink(PhoneServiceFixture::PHONE_EVENT_SERVICE_FILE);
	serviceAssert(file_get_contents($target) === 'preserve', 'An unrelated file changed.');
	$helper = $directory . '/sls_phone_events.py';
	file_put_contents($helper, "print('{\"ok\":true,\"connected\":true,\"heartbeat_age_seconds\":1.5,\"active_batches\":2,\"reserved_contacts\":25}')\n");
	serviceAssert($fixture->getPhoneEventCollectorHealth()['ok'] === true, 'Fresh heartbeat was not healthy.');
	file_put_contents($helper, "print('{\"ok\":true,\"connected\":true,\"heartbeat_age_seconds\":16,\"active_batches\":2,\"reserved_contacts\":25}')\n");
	$health = $fixture->getPhoneEventCollectorHealth();
	serviceAssert($health['state'] === 'fault' && strpos($health['message'], 'blocked') !== false && strpos($health['message'], 'unknown') !== false, 'Stale heartbeat did not explain blocked admission and uncertain outcomes.');
	file_put_contents($helper, "print('{\"ok\":true,\"connected\":true,\"heartbeat_age_seconds\":0}')\n");
	serviceAssert($fixture->getPhoneEventCollectorHealth()['ok'] === false, 'Malformed health schema was accepted.');
	// Exercise the real probe as the install account and the GUI's actual
	// PHP-FPM account. Copy only source code and fake helper data into a
	// traversable isolated directory; never bootstrap FreePBX or contact AMI.
	chmod($directory, 0755);
	copy(dirname(__DIR__) . '/slsmassnotifyserver/PhoneEventService.php', $directory . '/PhoneEventService.php');
	chmod($directory . '/PhoneEventService.php', 0644);
	$account = posix_getpwnam('asterisk');
	serviceAssert(is_array($account), 'The runtime asterisk account is unavailable for the isolated probe fixture.');
	$fakePython = "import os,json\nprint(json.dumps({'ok':os.geteuid()==" . $account['uid'] . ", 'ping':True, 'show_dialplan':True, 'connected':True}))\n";
	foreach (['sls_phone_admission.py', 'sls_phone_events.py'] as $name) {
		file_put_contents($directory . '/' . $name, $fakePython); chmod($directory . '/' . $name, 0644);
	}
	$runner = <<<'PHP'
<?php
require __DIR__ . '/PhoneEventService.php';
class ActualProbeFixture {
    use \FreePBX\modules\SlsPhoneEventService;
    const RUNTIME_DIR = __DIR__;
    public function verify() {
        return $this->phoneEventRuntimeProbe('sls_phone_admission.py', '--probe-ami')
            && $this->phoneEventRuntimeProbe('sls_phone_events.py', '--health');
    }
}
echo json_encode(['ok' => (new ActualProbeFixture())->verify(), 'uid' => posix_geteuid()]);
PHP;
	file_put_contents($directory . '/probe-runner.php', $runner); chmod($directory . '/probe-runner.php', 0644);
	foreach (['root' => true, 'asterisk' => true, 'nobody' => false] as $user => $expected) {
		$output = []; $status = 1;
		$command = '/usr/bin/timeout --kill-after=1 10 '
			. ($user === 'root' ? '' : '/usr/sbin/runuser -u ' . escapeshellarg($user) . ' -- ')
			. escapeshellarg(PHP_BINARY) . ($user === 'nobody' ? ' -d disable_functions=exec' : '')
			. ' ' . escapeshellarg($directory . '/probe-runner.php');
		exec($command, $output, $status);
		$result = json_decode(implode("\n", $output), true);
		serviceAssert($status === 0 && is_array($result) && ($result['ok'] ?? null) === $expected,
			'Runtime probe privilege handling failed for ' . $user);
	}
	echo "Phone collector unit: isolated mocked install/repair/removal, failure propagation, unsafe file rejection and heartbeat health passed.\n";
} finally {
	foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
	foreach (glob($directory . '/.sls-phone-events-*') ?: [] as $path) { unlink($path); }
	rmdir($directory);
}
