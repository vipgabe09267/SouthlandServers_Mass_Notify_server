#!/usr/bin/php
<?php

// Southland Servers Mass Notifications Server by the Southland Servers Group

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

umask(0027);
@set_time_limit(0);

$arguments = $argv;
array_shift($arguments);
$selfTest = false;
if ($arguments === ['--self-test']) {
	$selfTest = true;
} elseif ($arguments !== []) {
	fwrite(STDERR, "Usage: sls_mass_notify_schedule_worker.php [--self-test]\n");
	exit(64);
}

$freepbxConfig = '/etc/freepbx.conf';
$dataDirectory = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
$centralConfig = $dataDirectory . '/mass-notifications.config';

/** One lease covers incident policies and ordinary schedules during deployment. */
function sls_schedule_runner_lock(string $directory)
{
	clearstatcache(true, $directory); $parent = @lstat($directory);
	$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
	if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== $uid
		|| ($parent['mode'] & 0022) !== 0 || realpath($directory) !== $directory) {
		throw new \RuntimeException('Scheduling data directory ownership or permissions are unsafe.');
	}
	$path = $directory . '/schedule-runner.lock';
	$regular = static function ($stat) use ($uid): bool {
		return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1
			&& $stat['uid'] === $uid && ($stat['mode'] & 0022) === 0;
	};
	clearstatcache(true, $path); $before = @lstat($path);
	if ($before !== false && !$regular($before)) { throw new \RuntimeException('Scheduling runner lock is an unsafe file or link.'); }
	// c+b never truncates an existing file; O_RDWR does not wait on a FIFO
	// substituted between the path check and open. Validate before flock/read.
	$previous = umask(0077);
	try { $handle = @fopen($path, 'c+b'); } finally { umask($previous); }
	if (!is_resource($handle)) { throw new \RuntimeException('Scheduling runner lock could not be opened.'); }
	$identity = static function () use ($path, $handle, $regular, $before): bool {
		clearstatcache(true, $path); $at = @lstat($path); $opened = fstat($handle);
		return $regular($opened) && $regular($at) && $opened['dev'] === $at['dev'] && $opened['ino'] === $at['ino']
			&& ($before === false || ($opened['dev'] === $before['dev'] && $opened['ino'] === $before['ino']));
	};
	if (!$identity()) { fclose($handle); throw new \RuntimeException('Scheduling runner lock changed during access.'); }
	if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
	if (!$identity()) { fclose($handle); throw new \RuntimeException('Scheduling runner lock changed during acquisition.'); }
	return $handle;
}

$runnerLock = null;
if (!$selfTest) {
	try { $runnerLock = sls_schedule_runner_lock($dataDirectory); }
	catch (\Throwable $error) { fwrite(STDERR, 'SLS scheduling runner: ' . $error->getMessage() . "\n"); exit(1); }
	if ($runnerLock === null) {
		fwrite(STDOUT, "SLS scheduling runner is already active or paused for maintenance; no work was started.\n");
		exit(0);
	}
}
if (!is_readable($freepbxConfig)) {
	fwrite(STDERR, "FreePBX bootstrap is unavailable.\n");
	exit(1);
}

try {
	global $amp_conf;
	$bootstrap_settings = [
		'freepbx_auth' => false,
		'skip_astman' => true,
	];
	require_once $freepbxConfig;
	$module = null;
	try {
		$module = \FreePBX::Create()->Slsmassnotifyserver;
	} catch (\Throwable $primaryException) {
		// Some FreePBX builds expose the BMO only through the static accessor.
	}
	if (!is_object($module)) {
		try {
			$module = \FreePBX::Slsmassnotifyserver();
		} catch (\Throwable $fallbackException) {
			throw new \RuntimeException('Scheduling runtime is unavailable.', 0, $fallbackException);
		}
	}
	if (!is_object($module) || !method_exists($module, 'processScheduledAnnouncements')) {
		throw new \RuntimeException('Scheduling runtime is unavailable.');
	}

	if ($selfTest) {
		if (is_link($dataDirectory) || !is_dir($dataDirectory) || !is_readable($dataDirectory) || !is_writable($dataDirectory)) {
			throw new \RuntimeException('Scheduling data directory is unavailable or not writable.');
		}
		if (is_link($centralConfig) || !is_file($centralConfig) || !is_readable($centralConfig) || !is_writable($centralConfig)) {
			throw new \RuntimeException('Protected central configuration is unavailable or not writable.');
		}
		$writeProbe = @tempnam($dataDirectory, '.schedule-self-test.');
		if (!is_string($writeProbe) || $writeProbe === '') {
			throw new \RuntimeException('Scheduling data-directory write probe failed.');
		}
		@chmod($writeProbe, 0640);
		if (!@unlink($writeProbe)) {
			throw new \RuntimeException('Scheduling data-directory cleanup probe failed.');
		}
		fwrite(STDOUT, "SLS Mass Notify scheduling worker self-test passed.\n");
		exit(0);
	}

    // The disabled namespace is a no-op. An enabled HA standby may not claim
    // native schedules, incidents, or automation state before witness authority.
    if (method_exists($module, 'enterpriseClusterCycle')) { $module->enterpriseClusterCycle(); }
    $automationFailed = false;
	try { if (method_exists($module, 'processAutomationEvents')) { $automationFailed = empty($module->processAutomationEvents()['success']); } }
	catch (\Throwable $error) { $automationFailed = true; }
	$incidentFailed = false;
	try {
		if (method_exists($module, 'processIncidentWorkflows')) {
			$incidentResult = $module->processIncidentWorkflows();
			$incidentFailed = !is_array($incidentResult) || ($incidentResult['success'] ?? false) !== true;
		}
	} catch (\Throwable $error) { $incidentFailed = true; }
	// Independent schedule processing still runs if the incident ledger is
	// unavailable. Neither channel hides the other's failure.
	$result = $module->processScheduledAnnouncements();
	if ($result === false || (is_array($result) && array_key_exists('success', $result) && empty($result['success']))) {
		throw new \RuntimeException('Scheduled-announcement processing reported a failure.');
	}
	if ($automationFailed) { error_log('SLS trigger reconciliation failed; review the protected trigger journal.'); }
    if ($incidentFailed) {
        foreach (array_slice(is_array($incidentResult['errors'] ?? null) ? $incidentResult['errors'] : [], 0, 3) as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $detail = ['message' => substr($error['message'], 0, 1000)];
                if (is_string($error['incident_id'] ?? null) && preg_match('/^inc_[a-f0-9]{32}$/D', $error['incident_id'])) { $detail['incident_id'] = $error['incident_id']; }
                error_log('SLS incident policy detail: ' . json_encode($detail, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            }
        }
        throw new \RuntimeException('Incident policy processing could not confirm completion. Review the preceding policy/storage error and worker health.');
    }
} catch (\Throwable $exception) {
	error_log('SLS Mass Notify scheduling worker failed: ' . $exception->getMessage());
	fwrite(STDERR, "SLS Mass Notify scheduling worker failed. Review Notification Logs and Dashboard health.\n");
	exit(1);
}

exit(0);
