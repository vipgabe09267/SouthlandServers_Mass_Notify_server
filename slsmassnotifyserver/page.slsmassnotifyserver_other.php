<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_other_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_other_apply_result'] ?? null;
$tokenResult = $_SESSION['slsmassnotifyserver_other_token_result'] ?? null;
$importResult = $_SESSION['slsmassnotifyserver_other_import_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_other_save_result'], $_SESSION['slsmassnotifyserver_other_apply_result'], $_SESSION['slsmassnotifyserver_other_token_result'], $_SESSION['slsmassnotifyserver_other_import_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
	$_SESSION['slsmassnotifyserver_other_save_result'] = [
		'success' => false,
		'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
		'errors' => [],
	];
	header('Location: config.php?display=slsmassnotifyserver_other');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['slsmassnotifyserver_action'] ?? '';
	if ($action === 'save_other_settings') {
		$_SESSION['slsmassnotifyserver_other_save_result'] = $slsmassnotifyserver->saveOtherSettings($_POST, $_FILES);
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'regenerate_control_api_key') {
		$_SESSION['slsmassnotifyserver_other_token_result'] = $slsmassnotifyserver->regenerateControlApiKey($_POST);
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'export_config') {
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="sls-mass-notify.config"');
		header('Cache-Control: no-store');
		echo $slsmassnotifyserver->exportConfig();
		exit;
	} elseif ($action === 'import_config') {
		$_SESSION['slsmassnotifyserver_other_import_result'] = $slsmassnotifyserver->importConfigUpload($_FILES['config_upload'] ?? []);
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'repair_installation') {
		$_SESSION['slsmassnotifyserver_other_save_result'] = $slsmassnotifyserver->repairInstallation();
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'manual_update') {
		$_SESSION['slsmassnotifyserver_other_save_result'] = $slsmassnotifyserver->requestManualUpdate();
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'complete_uninstall') {
		$_SESSION['slsmassnotifyserver_other_save_result'] = $slsmassnotifyserver->requestCompleteUninstall();
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'apply_settings') {
		$_SESSION['slsmassnotifyserver_other_apply_result'] = $slsmassnotifyserver->applySettings();
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	}
}

echo $slsmassnotifyserver->showPage('other_settings', [
	'save_result' => $saveResult,
	'apply_result' => $applyResult,
	'token_result' => $tokenResult,
	'import_result' => $importResult,
]);
