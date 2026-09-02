<?php

declare(strict_types=1);

function update_contract_fail(string $message): void
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

$root = dirname(__DIR__);
$moduleXml = simplexml_load_file($root . '/slsmassnotifyserver/module.xml');
if ($moduleXml === false) {
	update_contract_fail('Unable to read module.xml.');
}
$version = trim((string)$moduleXml->version);
$updateScript = file_get_contents($root . '/slsmassnotifyserver/bin/sls_mass_notify_update.sh');
$maintenanceScript = file_get_contents($root . '/slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh');
$moduleSource = file_get_contents($root . '/slsmassnotifyserver/Slsmassnotifyserver.class.php');
$settingsView = file_get_contents($root . '/slsmassnotifyserver/views/other_settings.php');
foreach ([$updateScript, $maintenanceScript, $moduleSource, $settingsView] as $source) {
	if (!is_string($source) || $source === '') {
		update_contract_fail('Unable to inspect an update-path source file.');
	}
}

if (strpos($updateScript, 'CURRENT_VERSION="${SLS_MASS_NOTIFY_CURRENT_VERSION:-' . $version . '}"') === false) {
	update_contract_fail('Updater current version does not match module.xml.');
}
foreach ([
	'readonly GITHUB_UPDATES_REPOSITORY="vipgabe09267/SouthlandServers_Mass_Notify_server"',
	'https://api.github.com/repos/{repo}/releases',
	're.fullmatch(r"sha256:[0-9a-fA-F]{64}", digest)',
	'installer_url": f"https://raw.githubusercontent.com/{repo}/{tag}/tools/install_release.sh"',
	'SLS_MASS_NOTIFY_TGZ_URL="$tgz_url" SLS_MASS_NOTIFY_SHA256="$sha256"',
] as $required) {
	if (strpos($updateScript, $required) === false) {
		update_contract_fail('Updater is missing a verified-release control: ' . $required);
	}
}
$checkOnlyPosition = strpos($updateScript, 'if [ "$CHECK_ONLY" = "1" ]');
$installPosition = strpos($updateScript, 'Automatic update installing');
if ($checkOnlyPosition === false || $installPosition === false || $checkOnlyPosition >= $installPosition) {
	update_contract_fail('Check-only mode can reach the installation path.');
}
if (strpos($updateScript, '[ "$GITHUB_UPDATES_ENABLED" = "1" ] || [ "$MANUAL_UPDATE" = "1" ] || exit 0') === false) {
	update_contract_fail('Updater no longer distinguishes automatic opt-in from an explicit manual update.');
}

foreach ([
	'write_update_progress "checking" "Checking the verified release feed."',
	'SLS_MASS_NOTIFY_MANUAL_UPDATE=1 /usr/bin/timeout 1800',
	'write_update_progress "failed"',
] as $required) {
	if (strpos($maintenanceScript, $required) === false) {
		update_contract_fail('Maintenance worker is missing manual-update progress handling.');
	}
}
if (substr_count($moduleSource, "17 */6 * * * /usr/bin/timeout 1800 /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh") !== 1) {
	update_contract_fail('Root automatic-update cron is missing or duplicated in module source.');
}

foreach ([
	'<?php if ($hasPackageUpdate) { ?>',
	'id="sls-update-progress"',
	'class="sls-operation-status"',
	'aria-live="polite"',
	'aria-atomic="true"',
	'aria-busy=',
	'prefers-reduced-motion:reduce',
	"updateProgress.setAttribute('data-state', state)",
	"maintenanceProgress.setAttribute('data-state', state)",
] as $required) {
	if (strpos($settingsView, $required) === false) {
		update_contract_fail('Update/maintenance status UI is missing: ' . $required);
	}
}

echo "Update and maintenance contract tests passed.\n";
