<?php

namespace {
	if (!interface_exists('BMO')) {
		interface BMO
		{
		}
	}
	if (!function_exists('_')) {
		function _($message)
		{
			return $message;
		}
	}
	if (!class_exists('FreePBX')) {
		class FreePBX
		{
			public static function Config()
			{
				return new class {
					public function get($key)
					{
						return '';
					}
				};
			}
		}
	}
}

namespace FreePBX\modules\Backup {
	class BackupBase
	{
		protected $FreePBX;
		public $dependencies = [];
		public $configs = [];
		public $files = [];
		public $garbage = [];

		public function __construct($freepbx)
		{
			$this->FreePBX = $freepbx;
		}
		public function addDependency($dependency)
		{
			$this->dependencies[] = $dependency;
		}
		public function addConfigs($configs)
		{
			$this->configs = $configs;
		}
		public function addFile($filename, $path, $base, $type)
		{
			$this->files[] = compact('filename', 'path', 'base', 'type');
		}
		public function addGarbage($path)
		{
			$this->garbage[] = $path;
		}
	}

	class RestoreBase
	{
		protected $FreePBX;
		protected $tmpdir;
		protected $transactionId;
		private $configs;
		private $files;

		public function __construct($freepbx, array $configs, array $files, $tmpdir, $transactionId)
		{
			$this->FreePBX = $freepbx;
			$this->configs = $configs;
			$this->files = $files;
			$this->tmpdir = $tmpdir;
			$this->transactionId = $transactionId;
		}
		public function getConfigs()
		{
			return $this->configs;
		}
		public function getFiles()
		{
			return $this->files;
		}
	}
}

namespace {
	require_once __DIR__ . '/../slsmassnotifyserver/Slsmassnotifyserver.class.php';
	require_once __DIR__ . '/../slsmassnotifyserver/Backup.php';
	require_once __DIR__ . '/../slsmassnotifyserver/Restore.php';

	function slsAssert($condition, $message)
	{
		if (!$condition) {
			throw new \RuntimeException($message);
		}
	}

	function slsInvoke($object, $method, array $arguments = [])
	{
		$reflection = new \ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($object, $arguments);
	}

	$moduleReflection = new \ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
	$module = $moduleReflection->newInstanceWithoutConstructor();

	$ledgerRaw = json_encode([
		'version' => 1,
		'occurrences' => [
			'occ_past' => [
				'occurrence_id' => 'occ_past',
				'run_at_utc' => '2026-01-01T12:00:00Z',
				'state' => 'pending',
			],
		],
		'worker' => [],
	], JSON_PRETTY_PRINT) . "\n";
	$ledger = slsInvoke($module, 'validateNativeScheduleLedger', [$ledgerRaw]);
	slsAssert(($ledger['occurrences']['occ_past']['state'] ?? '') === 'pending', 'Valid schedule journal was rejected.');

	$settings = [
		'enabled' => '0',
		'setup' => ['completed' => '1'],
		'ami' => ['username' => 'slsmassnotify', 'password' => 'test-secret', 'host' => '127.0.0.1', 'port' => 5038],
		'control_api' => ['enabled' => '0', 'api_key' => 'abcdefghijklmnopqrstuvwxyz123456'],
		'sipnotify' => [],
		'desktop_clients' => [],
		'announcement_groups' => [],
		'scheduled_announcements' => [[
			'id' => 'sched_one',
			'name' => 'Restore safety test',
			'enabled' => '1',
			'occurrences' => [
				['id' => 'occ_past', 'run_at_utc' => '2026-01-01T12:00:00Z'],
				['id' => 'occ_future', 'run_at_utc' => '2030-01-01T12:00:00Z'],
			],
		]],
		'discord_webhooks' => [],
		'generic_webhooks' => [],
	];
	$configRaw = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	$validatedSettings = slsInvoke($module, 'validateNativeBackupConfig', [$configRaw]);
	$timezone = slsInvoke($module, 'getPbxDateTimeZone');
	$prepared = slsInvoke($module, 'prepareNativeRestoredScheduleState', [[
		'raw' => $configRaw,
		'settings' => $validatedSettings,
	], [
		'raw' => $ledgerRaw,
		'data' => $ledger,
	], $timezone->getName(), strtotime('2027-01-01T00:00:00Z')]);
	$preparedLedger = json_decode($prepared['schedule_raw'], true);
	slsAssert(($preparedLedger['occurrences']['occ_past']['state'] ?? '') === 'uncertain', 'Past pending occurrence was not made replay-safe.');
	slsAssert(!isset($preparedLedger['occurrences']['occ_future']), 'Future occurrence was incorrectly marked complete.');
	slsAssert($prepared['config_raw'] === $configRaw, 'Config bytes changed when no schedule disable was required.');

	$withoutLedger = slsInvoke($module, 'prepareNativeRestoredScheduleState', [[
		'raw' => $configRaw,
		'settings' => $validatedSettings,
	], null, $timezone->getName(), strtotime('2027-01-01T00:00:00Z')]);
	$disabledSettings = json_decode($withoutLedger['config_raw'], true);
	slsAssert(($disabledSettings['scheduled_announcements'][0]['enabled'] ?? '1') === '0', 'Missing journal did not disable schedules.');
	slsAssert($withoutLedger['schedule_present'] === true, 'Replay-safe replacement journal was not created.');

	$configHash = str_repeat('a', 64);
	$manifest = [
		'schema_version' => 1,
		'module' => 'slsmassnotifyserver',
		'module_version' => '0.1.0',
		'created_at' => '2026-08-15T12:00:00Z',
		'source_timezone' => 'UTC',
		'files' => [
			'config' => [
				'type' => 'slsmassnotify-config',
				'archive_name' => 'protected-config.json',
				'restore_name' => 'mass-notifications.config',
				'bytes' => 100,
				'sha256' => $configHash,
			],
			'schedule' => null,
			'tones' => [],
		],
	];
	$validatedManifest = slsInvoke($module, 'validateNativeBackupManifest', [$manifest]);
	slsAssert(($validatedManifest['files']['config']['bytes'] ?? 0) === 100, 'Valid native manifest was rejected.');
	$unsafeManifest = $manifest;
	$unsafeManifest['files']['config']['archive_name'] = '../protected-config.json';
	$unsafeRejected = false;
	try {
		slsInvoke($module, 'validateNativeBackupManifest', [$unsafeManifest]);
	} catch (\RuntimeException $e) {
		$unsafeRejected = true;
	}
	slsAssert($unsafeRejected, 'Manifest traversal filename was accepted.');

	$restoreRoot = sys_get_temp_dir() . '/sls-native-restore-test-' . bin2hex(random_bytes(4));
	$restoreFiles = $restoreRoot . '/files/private';
	slsAssert(mkdir($restoreFiles, 0700, true), 'Unable to create test restore directory.');
	$restoreConfig = $restoreFiles . '/protected-config.json';
	slsAssert(file_put_contents($restoreConfig, $configRaw, LOCK_EX) === strlen($configRaw), 'Unable to create test restore config.');
	$payloadManifest = $manifest;
	$payloadManifest['files']['config']['bytes'] = strlen($configRaw);
	$payloadManifest['files']['config']['sha256'] = hash('sha256', $configRaw);
	$payloadManifest = slsInvoke($module, 'validateNativeBackupManifest', [$payloadManifest]);
	$restoreFileObject = new class($restoreConfig) {
		private $path;
		public function __construct($path)
		{
			$this->path = $path;
		}
		public function getType()
		{
			return 'slsmassnotify-config';
		}
		public function getFilename()
		{
			return 'protected-config.json';
		}
		public function getPathname()
		{
			return $this->path;
		}
	};
	$loadedPayload = slsInvoke($module, 'loadNativeRestorePayload', [$payloadManifest, [$restoreFileObject], $restoreRoot]);
	slsAssert(($loadedPayload['config']['raw'] ?? '') === $configRaw, 'Verified extracted config was not loaded byte-for-byte.');
	file_put_contents($restoreConfig, $configRaw . " ", LOCK_EX);
	$tamperRejected = false;
	try {
		slsInvoke($module, 'loadNativeRestorePayload', [$payloadManifest, [$restoreFileObject], $restoreRoot]);
	} catch (\RuntimeException $e) {
		$tamperRejected = true;
	}
	slsAssert($tamperRejected, 'Tampered extracted config passed manifest verification.');
	unlink($restoreConfig);
	rmdir($restoreFiles);
	rmdir(dirname($restoreFiles));
	rmdir($restoreRoot);

	$fakeSnapshotModule = new class {
		public $restoreArguments = null;
		public function createFreePbxBackupSnapshot($transaction)
		{
			return [
				'manifest' => ['transaction' => $transaction],
				'files' => [['filename' => 'protected-config.json', 'path' => '/tmp/private', 'type' => 'slsmassnotify-config']],
				'garbage' => '/tmp/private',
			];
		}
		public function restoreFreePbxBackupSnapshot(...$arguments)
		{
			$this->restoreArguments = $arguments;
		}
	};
	$fakeFreePbx = new \stdClass();
	$fakeFreePbx->Slsmassnotifyserver = $fakeSnapshotModule;
	$backupAdapter = new \FreePBX\modules\Slsmassnotifyserver\Backup($fakeFreePbx);
	$backupAdapter->runBackup('job', 'tx-123');
	slsAssert($backupAdapter->dependencies === ['recordings'], 'Recordings dependency was not registered.');
	slsAssert(count($backupAdapter->files) === 1 && $backupAdapter->garbage === ['/tmp/private'], 'Backup adapter did not register snapshot lifecycle data.');

	$restoreAdapter = new \FreePBX\modules\Slsmassnotifyserver\Restore(
		$fakeFreePbx,
		['native_backup' => $manifest],
		[],
		'/tmp/restore-test',
		'tx-restore'
	);
	$restoreAdapter->runRestore();
	slsAssert(($fakeSnapshotModule->restoreArguments[3] ?? '') === 'tx-restore', 'Restore adapter did not forward its transaction ID.');

	$fakeBackupJobs = new class {
		public $data = [
			'backupList' => ['module-job' => true, 'files-only-job' => true],
			'modules_module-job' => ['core' => true, 'recordings' => true],
			'modules_files-only-job' => [],
		];
		public $changes = [];
		public function getAll($id)
		{
			return $this->data[$id] ?? [];
		}
		public function setConfig($setting, $value, $id)
		{
			$this->changes[] = [$setting, $value, $id];
			$this->data[$id][$setting] = $value;
		}
	};
	$fakeEnrollmentFreePbx = new \stdClass();
	$fakeEnrollmentFreePbx->Modules = new class {
		public function checkStatus($module)
		{
			return $module === 'backup';
		}
	};
	$fakeEnrollmentFreePbx->Backup = $fakeBackupJobs;
	$enrollmentModule = new \FreePBX\modules\Slsmassnotifyserver($fakeEnrollmentFreePbx);
	$enrollment = $enrollmentModule->ensureFreePbxBackupEnrollment();
	slsAssert(($enrollment['changed'] ?? 0) === 1, 'Module-based FreePBX backup job was not enrolled.');
	slsAssert(count($fakeBackupJobs->changes) === 1 && $fakeBackupJobs->changes[0][2] === 'modules_module-job', 'Custom-files-only job was unexpectedly modified.');
	$removeEnrollment = (new \ReflectionClass($enrollmentModule))->getMethod('removeFreePbxBackupEnrollment');
	$removeEnrollment->setAccessible(true);
	$removeEnrollment->invoke($enrollmentModule);
	slsAssert(count($fakeBackupJobs->changes) === 2
		&& $fakeBackupJobs->changes[1] === ['slsmassnotifyserver', false, 'modules_module-job'],
		'Uninstall did not remove only the stale Mass Notifications module selection.');

	$emptyBackupJobs = new class {
		public function getAll($id)
		{
			return [];
		}
	};
	$emptyJobsFreePbx = new \stdClass();
	$emptyJobsFreePbx->Modules = new class {
		public function checkStatus($module)
		{
			return $module === 'backup';
		}
	};
	$emptyJobsFreePbx->Backup = $emptyBackupJobs;
	$emptyJobsHealth = (new \FreePBX\modules\Slsmassnotifyserver($emptyJobsFreePbx))->getFreePbxBackupHealth();
	slsAssert(($emptyJobsHealth['state'] ?? '') === 'ok'
		&& ($emptyJobsHealth['native_adapter'] ?? false) === true
		&& (int)($emptyJobsHealth['jobs'] ?? -1) === 0,
		'A fresh PBX with a discoverable native adapter and no administrator-defined backup jobs was incorrectly reported as faulty.');

	echo "FreePBX native backup/restore tests passed.\n";
}
