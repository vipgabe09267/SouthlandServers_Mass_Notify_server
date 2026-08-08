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

	$result = $module->processScheduledAnnouncements();
	if ($result === false || (is_array($result) && array_key_exists('success', $result) && empty($result['success']))) {
		throw new \RuntimeException('Scheduled-announcement processing reported a failure.');
	}
} catch (\Throwable $exception) {
	error_log('SLS Mass Notify scheduling worker failed: ' . $exception->getMessage());
	fwrite(STDERR, "SLS Mass Notify scheduling worker failed. Review Notification Logs and Dashboard health.\n");
	exit(1);
}

exit(0);
