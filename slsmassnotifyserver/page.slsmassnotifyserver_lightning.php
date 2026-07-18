<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_lightning_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_lightning_apply_result'] ?? null;
$testResult = $_SESSION['slsmassnotifyserver_lightning_test_result'] ?? null;
$connectionResult = $_SESSION['slsmassnotifyserver_lightning_connection_result'] ?? null;
unset(
	$_SESSION['slsmassnotifyserver_lightning_save_result'],
	$_SESSION['slsmassnotifyserver_lightning_apply_result'],
	$_SESSION['slsmassnotifyserver_lightning_test_result'],
	$_SESSION['slsmassnotifyserver_lightning_connection_result']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
	$_SESSION['slsmassnotifyserver_lightning_save_result'] = [
		'success' => false,
		'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
		'errors' => [],
	];
	header('Location: config.php?display=slsmassnotifyserver_lightning');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['slsmassnotifyserver_action'] ?? '';
	if ($action === 'save_lightning_settings') {
		$_SESSION['slsmassnotifyserver_lightning_save_result'] = $slsmassnotifyserver->saveLightningSettings($_POST);
	} elseif ($action === 'apply_settings') {
		$_SESSION['slsmassnotifyserver_lightning_apply_result'] = $slsmassnotifyserver->applySettings();
	} elseif ($action === 'test_lightning') {
		$triggerName = isset($_SESSION['AMP_user']->username) && $_SESSION['AMP_user']->username !== ''
			? (string)$_SESSION['AMP_user']->username
			: 'FreePBX Dashboard';
		$_SESSION['slsmassnotifyserver_lightning_test_result'] = $slsmassnotifyserver->triggerLightningTest($triggerName);
	} elseif ($action === 'verify_lightning_connection') {
		$_SESSION['slsmassnotifyserver_lightning_connection_result'] = $slsmassnotifyserver->verifyLightningConnection();
	}
	header('Location: config.php?display=slsmassnotifyserver_lightning');
	exit;
}

echo $slsmassnotifyserver->showPage('lightning', [
	'save_result' => $saveResult,
	'apply_result' => $applyResult,
	'test_result' => $testResult,
	'connection_result' => $connectionResult,
]);
