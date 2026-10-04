<?php

declare(strict_types=1);

// Synthetic-only regression coverage. Never bootstrap FreePBX or read a live config.
namespace FreePBX\modules {
	function is_executable($path) { return false; }
	function is_readable($path) { return false; }
	function file_get_contents(...$arguments) { throw new \RuntimeException('Unexpected filesystem read.'); }
	function file_put_contents(...$arguments) { throw new \RuntimeException('Unexpected filesystem write.'); }
	function exec(...$arguments) { throw new \RuntimeException('Unexpected command execution.'); }
}

namespace {
	if (!interface_exists('BMO')) { interface BMO {} }
	if (!function_exists('_')) { function _($message) { return $message; } }
	$_SERVER['HTTP_HOST'] = 'pbx.example.test';
	$classPath = $argv[1] ?? dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
	require_once $classPath;

	final class ProtectedConfigCompatibilityFixture extends \FreePBX\modules\Slsmassnotifyserver
	{
		public function getConfiguredPjsipExtensionNumbers() { return ['1000', '1001']; }
		public function getAllPjsipExtensions() { return []; }
		public function getAvailableTones() { return []; }
		public function getAvailablePiperVoices() { return []; }
	}

	$module = (new \ReflectionClass(ProtectedConfigCompatibilityFixture::class))->newInstanceWithoutConstructor();
	$reflection = new \ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
	$checks = 0;
	$invoke = static function (string $name, ...$arguments) use ($module, $reflection) {
		$method = $reflection->getMethod($name);
		$method->setAccessible(true);
		return $method->invokeArgs($module, $arguments);
	};
	$assert = static function (bool $condition, string $message) use (&$checks): void {
		$checks++;
		if (!$condition) { throw new \RuntimeException($message); }
	};
	$accept = static function (array $settings, string $label) use ($invoke, $assert): array {
		$errors = $invoke('validateConfigSchema', $settings);
		$assert($errors === [], $label . ': import validation failed: ' . implode('; ', $errors));
		return $invoke('validateNativeBackupConfig', json_encode($settings, JSON_THROW_ON_ERROR));
	};
	$reject = static function (array $settings, string $label) use ($invoke, $assert): void {
		$assert($invoke('validateConfigSchema', $settings) !== [], $label . ': import accepted invalid config.');
		$failed = false;
		try { $invoke('validateNativeBackupConfig', json_encode($settings, JSON_THROW_ON_ERROR)); }
		catch (\RuntimeException $exception) { $failed = true; }
		$assert($failed, $label . ': protected backup/repair accepted invalid config.');
	};

	$defaults = $invoke('getDefaultSettings');
	$defaults['desktop_clients'] = [];
	$defaults['desktop_auth_key'] = base64_encode(str_repeat('T', 32));
	$defaults['control_api']['api_key'] = str_repeat('a', 32);
	$defaults['ami']['password'] = 'synthetic-test-credential';
	$assert(($defaults['xweather']['strike_type'] ?? null) === 'cloud_to_ground', 'Default schema lacks canonical strike_type.');
	$accept($defaults, 'Defaults');
	$normalizedDefaults = $invoke('normalizeSettings', $defaults);
	$accept($normalizedDefaults, 'Normalized defaults');
	$media = $defaults;
	$media['media_access'] = ['network_restricted'=>true, 'allowed_cidrs'=>['192.0.2.0/24','2001:db8::/32'], 'max_age_minutes'=>60];
	$assert($accept($media, 'Media policy')['media_access'] === $media['media_access'], 'Protected backup changed media policy.');
	$assert($invoke('normalizeSettings', $media)['media_access'] === $media['media_access'], 'Normalizer lost media policy.');
	foreach ([null, ['network_restricted'=>'1'], ['network_restricted'=>true,'allowed_cidrs'=>[]], ['max_age_minutes'=>1]] as $badMedia) {
		$media['media_access'] = $badMedia; $reject($media, 'Malformed media policy');
	}
	$assert($invoke('normalizeSettings', $normalizedDefaults) === $normalizedDefaults, 'Canonical default settings are not idempotent.');

	$geographic = $normalizedDefaults; $locationId = 'loc_' . str_repeat('a', 24); $reviewed = time();
	$position = ['latitude' => 30.25, 'longitude' => -97.75, 'reviewed_at' => $reviewed];
	$geographic['location_directory'] = \SLS\MassNotify\LocationDirectory::normalize(['nodes' => [[
		'id' => $locationId, 'type' => 'site', 'parent_id' => '', 'name' => 'Fixture site', 'position' => $position,
		'members' => ['extensions' => ['1000']]]]]);
	$geographic['announcement_groups'] = [['id' => 'grp_' . str_repeat('a', 24), 'name' => 'Geographic fixture', 'extensions' => ['1000'],
		'desktop_clients' => [], 'voice_recipient_ids' => [], 'email_recipient_ids' => [], 'sms_recipient_ids' => [],
		'location_snapshot' => ['schema' => 2, 'path' => 'Geographic area', 'created_at' => $reviewed, 'directory_revision' => str_repeat('b', 64),
			'desktop_bindings' => [], 'geographic' => ['selection' => ['west' => -98.0, 'south' => 30.0, 'east' => -97.0, 'north' => 31.0, 'max_age_days' => 30],
				'locations' => [['id' => $locationId, 'position' => $position + ['source_id' => $locationId]]]]]]];
	$geoRestored = $invoke('normalizeSettings', $accept($geographic, 'Geographic configuration'));
	$assert($geoRestored['location_directory'] === $geographic['location_directory'], 'Protected roundtrip changed geographic coordinates.');
	$assert($geoRestored['announcement_groups'] === $geographic['announcement_groups'], 'Protected roundtrip changed geographic audience metadata.');
	$geographic['location_directory']['nodes'][0]['position'] = null; $reject($geographic, 'Explicit null coordinates');

	$paging = $normalizedDefaults;
	$paging['live_paging'] = \SLS\MassNotify\LivePagingConfig::normalize(['enabled'=>'1','extension'=>'799','external_access'=>'1','groups'=>[[
		'group_id'=>'paging_fixture','name'=>'Fixture group','menu_number'=>1,'extensions'=>['1001'],'allowed_callers'=>['1000'],
		'allow_external'=>'1','external_callers'=>['+15125550123'],'pin_hash'=>password_hash('0123',PASSWORD_DEFAULT)]]]);
	$pagingRestored = $invoke('normalizeSettings', $accept($paging, 'Paging caller allowlist configuration'));
	$assert($pagingRestored['live_paging'] === $paging['live_paging'], 'Protected roundtrip changed paging caller authorization or PIN.');
	$pagingRedacted = $invoke('redactConfigSecrets', $paging);
	$assert($pagingRedacted['live_paging']['groups'][0]['external_callers'] === ['[redacted]'], 'External caller number leaked through redacted config.');
	$assert($pagingRedacted['live_paging']['groups'][0]['pin_hash'] === '[redacted]', 'Paging PIN hash leaked through redacted config.');
	$paging['live_paging']['groups'][0]['external_callers'] = []; $reject($paging, 'External paging without approved callers');

	// Named API credentials and proxy trust must survive every central-config
	// path without widening permissions or exposing a password verifier.
	$issued = \SLS\MassNotify\ApiSecurity::issue(['name' => 'Fixture automation', 'scopes' => ['send'],
		'audience' => ['unrestricted' => false, 'extensions' => ['1000']]]);
	$secured = $normalizedDefaults;
	$secured['control_api']['credentials'] = [$issued['credential']];
	$secured['api_network'] = ['trusted_proxy_cidrs' => ['192.0.2.10/32', '2001:db8::10/128']];
	$accept($secured, 'Named credentials and explicit proxies');
	$normalizedSecurity = $invoke('normalizeSettings', $secured);
	$assert($normalizedSecurity['control_api']['credentials'] === $secured['control_api']['credentials'], 'Normalization lost named credentials.');
	$assert($normalizedSecurity['api_network'] === $secured['api_network'], 'Normalization changed proxy trust.');
	$redacted = $invoke('redactConfigSecrets', $secured);
	$assert($redacted['control_api']['credentials'][0]['secret_hash'] === '[redacted]', 'Password verifier leaked through redacted config.');
	$assert($redacted['control_api']['credentials'][0]['audience'] === $issued['credential']['audience'], 'Redaction changed permission metadata.');
	foreach ([['api_network' => ['trusted_proxy_cidrs' => []]], ['control_api' => ['credentials' => []]]] as $patch) {
		$assert($invoke('validateAndNormalizeControlConfigPatch', $patch)['errors'] !== [], 'Control API can mutate credential or proxy trust policy.');
	}
	foreach (['administrator', 'unknown'] as $scope) {
		$invalid = $secured; $invalid['control_api']['credentials'][0]['scopes'] = [$scope];
		$reject($invalid, 'Unknown named-credential scope');
	}
	foreach (['0.0.0.0/0', '::/0', 'invalid-proxy', 42] as $cidr) {
		$invalid = $secured; $invalid['api_network']['trusted_proxy_cidrs'] = [$cidr];
		$reject($invalid, 'Invalid trusted proxy');
	}

	foreach (['cloud_to_ground', 'cloud_to_cloud', 'both'] as $strikeType) {
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['strike_type' => $strikeType]]);
		$assert($api['errors'] === [] && $api['patch']['xweather']['strike_type'] === $strikeType, 'API rejected a valid singleton strike type.');
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['groups' => [['strike_type' => $strikeType]]]]);
		$assert($api['errors'] === [], 'API rejected a valid group strike type.');
		$legacy = $defaults;
		$legacy['xweather'] = [
			'enabled' => '0', 'location' => 'Synthetic test location', 'radius_miles' => 12,
			'recipients' => ['1000'], 'strike_type' => $strikeType,
		];
		$migrated = $accept($legacy, 'Legacy ' . $strikeType);
		$assert(!array_key_exists('groups', $migrated['xweather']), 'Pre-validation migration changed legacy routing marker.');
		$normalized = $invoke('normalizeSettings', $migrated);
		$assert($normalized['xweather']['strike_type'] === $strikeType, 'Legacy singleton alias changed.');
		$assert($normalized['xweather']['groups'][0]['strike_type'] === $strikeType, 'Legacy singleton strike type did not migrate.');
		$assert($normalized['xweather']['groups'][0]['extensions'] === ['1000'], 'Legacy recipient route changed.');
		$accept($normalized, 'Legacy normalized ' . $strikeType);
		$assert($invoke('normalizeSettings', $normalized) === $normalized, 'Legacy normalized settings are not idempotent.');

		$current = $normalized;
		unset($current['xweather']['strike_type']);
		$current = $accept($current, 'Current missing alias ' . $strikeType);
		$assert($current['xweather']['strike_type'] === $strikeType, 'Missing singleton alias did not inherit primary group.');
		$assert($invoke('migrateConfigCompatibility', $current) === $current, 'Compatibility migration is not idempotent.');

		$empty = $legacy;
		$empty['xweather']['groups'] = [];
		$empty = $invoke('normalizeSettings', $accept($empty, 'Explicit empty groups ' . $strikeType));
		$assert($empty['xweather']['groups'] === [], 'An explicitly empty group list synthesized a route.');
		$assert($empty['xweather']['strike_type'] === $strikeType, 'Empty groups discarded a valid singleton strike type.');
		$accept($empty, 'Empty groups normalized ' . $strikeType);
	}

	$legacy = $defaults;
	unset($legacy['xweather']['strike_type'], $legacy['xweather']['groups']);
	$missing = $accept($legacy, 'Legacy without strike type');
	$assert($missing['xweather']['strike_type'] === 'cloud_to_ground', 'Legacy missing strike type did not default safely.');
	$current = $normalizedDefaults;
	$current['xweather']['groups'] = [
		['id' => 'first', 'enabled' => '0', 'location' => 'Test A', 'strike_type' => 'both'],
		['id' => 'second', 'enabled' => '0', 'location' => 'Test B', 'strike_type' => 'cloud_to_cloud'],
	];
	$current['xweather']['strike_type'] = 'cloud_to_ground';
	$roundTrip = $invoke('normalizeSettings', $accept($current, 'Groups with an older valid alias'));
	$assert($roundTrip['xweather']['strike_type'] === 'both', 'Primary group did not control rolling-upgrade alias.');
	$assert($roundTrip['xweather']['groups'][1]['strike_type'] === 'cloud_to_cloud', 'Secondary group strike type changed.');
	$accept($roundTrip, 'Multi-group roundtrip');
	$raw = json_encode($legacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	$validated = $invoke('validateNativeBackupConfig', $raw);
	$timezone = $invoke('getPbxDateTimeZone')->getName();
	$prepared = $invoke('prepareNativeRestoredScheduleState', ['raw' => $raw, 'settings' => $validated], null, $timezone, 1800000000);
	$assert($prepared['config_raw'] === $raw, 'Compatibility validation rewrote unchanged native-backup bytes.');
	$scheduled = $legacy;
	$scheduled['scheduled_announcements'] = [[
		'id' => 'synthetic_schedule', 'name' => 'Synthetic replay safety', 'enabled' => '1',
		'occurrences' => [['id' => 'synthetic_occurrence', 'run_at_utc' => '2030-01-01T12:00:00Z']],
	]];
	$raw = json_encode($scheduled, JSON_THROW_ON_ERROR);
	$validated = $invoke('validateNativeBackupConfig', $raw);
	$prepared = $invoke('prepareNativeRestoredScheduleState', ['raw' => $raw, 'settings' => $validated], null, $timezone, 1800000000);
	$replaySafe = json_decode($prepared['config_raw'], true, 512, JSON_THROW_ON_ERROR);
	$assert($replaySafe['scheduled_announcements'][0]['enabled'] === '0', 'Migration bypassed restored-schedule replay protection.');
	$assert($replaySafe['xweather']['strike_type'] === 'cloud_to_ground', 'Restore rewrite omitted compatibility migration.');
	$invoke('validateNativeBackupConfig', $prepared['config_raw']);

	foreach (['unsupported', '', ' BOTH ', null, 1, true, [], ['both']] as $invalid) {
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['strike_type' => $invalid]]);
		$assert($api['errors'] !== [], 'API accepted an invalid singleton strike type.');
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['groups' => [['strike_type' => $invalid]]]]);
		$assert($api['errors'] !== [], 'API accepted an invalid group strike type.');
		$bad = $defaults;
		$bad['xweather']['strike_type'] = $invalid;
		$reject($bad, 'Invalid singleton strike type');
		$bad = $current;
		$bad['xweather']['groups'][0]['strike_type'] = $invalid;
		$reject($bad, 'Invalid group strike type');
		unset($bad['xweather']['strike_type']);
		$reject($bad, 'Invalid group strike type with missing singleton alias');
	}
	foreach (['xweather' => ['unexpected' => 'value'], 'setup' => ['unexpected' => 'value']] as $field => $patch) {
		$bad = $defaults;
		$bad[$field] = array_replace($bad[$field], $patch);
		$reject($bad, 'Unknown nested key ' . $field);
	}
	$bad = $defaults;
	$bad['unexpected'] = 'value';
	$reject($bad, 'Unknown top-level key');
	$bad = $defaults;
	$bad['xweather']['radius_miles'] = '25';
	$reject($bad, 'Invalid integer type');
	$bad = $defaults;
	$bad['xweather']['groups'] = 'not-an-array';
	$reject($bad, 'Invalid groups type');
	$bad = $defaults;
	$bad['xweather'] = null;
	$reject($bad, 'Invalid Xweather object');
	foreach (['invalid', '', 'true', '2', null, 1, true, [], ['1']] as $invalid) {
		$bad = $defaults;
		$bad['xweather']['groups'] = [['id' => 'synthetic_group', 'enabled' => $invalid, 'location' => 'Synthetic']];
		$reject($bad, 'Invalid Lightning group enabled value');
	}
	foreach (['0', '1'] as $enabled) {
		$good = $defaults;
		$good['xweather']['groups'] = [['id' => 'synthetic_group', 'enabled' => $enabled, 'location' => 'Synthetic']];
		$accept($good, 'Canonical Lightning group enabled value');
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['groups' => [['enabled' => $enabled === '1']]]]);
		$assert($api['errors'] === [] && $api['patch']['xweather']['groups'][0]['enabled'] === $enabled, 'API boolean enabled value did not preserve its meaning.');
	}
	$group = $invoke('normalizeAnnouncementGroupsForExtensions', [['name' => 'Synthetic phones', 'extensions' => ['1000'], 'desktop_clients' => []]], ['1000'], []);
	$assert(count($group) === 1, 'Synthetic announcement group did not normalize.');
	$good = $defaults;
	$good['announcement_groups'] = $group;
	$accept($good, 'All canonical announcement-group fields');
	$api = $invoke('validateAndNormalizeControlConfigPatch', ['announcement_groups' => $group]);
	$assert($api['errors'] === [], 'API rejected canonical announcement-group fields.');
	unset($good['announcement_groups'][0]['desktop_clients']);
	$accept($good, 'Legacy phone-only announcement group');
	$bad = $good;
	$bad['announcement_groups'][0]['unexpected'] = 'value';
	$reject($bad, 'Unknown announcement-group field');
	$api = $invoke('validateAndNormalizeControlConfigPatch', ['announcement_groups' => $bad['announcement_groups']]);
	$assert($api['errors'] !== [], 'API accepted unknown announcement-group field.');
	foreach ([5, 10, 120] as $minutes) {
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['groups' => [['all_clear_minutes' => $minutes]]]]);
		$assert($api['errors'] === [] && $api['patch']['xweather']['groups'][0]['all_clear_minutes'] === $minutes, 'API rejected canonical all-clear minutes.');
	}
	foreach ([4, 121, -1, '10', 10.0, null, true, [], 'invalid'] as $minutes) {
		$api = $invoke('validateAndNormalizeControlConfigPatch', ['xweather' => ['groups' => [['all_clear_minutes' => $minutes]]]]);
		$assert($api['errors'] !== [], 'API accepted invalid all-clear minutes.');
	}

	$source = file($classPath);
	$body = static function (string $method) use ($source, $reflection): string {
		$definition = $reflection->getMethod($method);
		return implode('', array_slice($source, $definition->getStartLine() - 1, $definition->getEndLine() - $definition->getStartLine() + 1));
	};
	foreach (['importConfigUpload', 'validateConfigSchema', 'validateNativeBackupConfig', 'normalizeSettings'] as $method) {
		$assert(strpos($body($method), 'migrateConfigCompatibility') !== false, $method . ' does not use canonical compatibility migration.');
	}
	foreach (['restore', 'persistPendingSettings', 'persistAppliedSettings', 'applyPendingSettingsTransaction'] as $method) {
		$assert(strpos($body($method), 'normalizeSettings') !== false, $method . ' bypasses canonical normalization.');
	}
	foreach (['createFreePbxBackupSnapshot', 'loadNativeRestorePayload', 'commitNativeRestorePayload', 'verifyNativePostRestoreIntegration'] as $method) {
		$assert(strpos($body($method), 'validateNativeBackupConfig') !== false, $method . ' bypasses protected config validation.');
	}
	echo 'Protected config compatibility: ' . $checks . " checks passed.\n";
}
