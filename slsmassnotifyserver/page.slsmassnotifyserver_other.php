<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_other');
$saveResult = $_SESSION['slsmassnotifyserver_other_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_other_apply_result'] ?? null;
$tokenResult = $_SESSION['slsmassnotifyserver_other_token_result'] ?? null;
$importResult = $_SESSION['slsmassnotifyserver_other_import_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_other_save_result'], $_SESSION['slsmassnotifyserver_other_apply_result'], $_SESSION['slsmassnotifyserver_other_token_result'], $_SESSION['slsmassnotifyserver_other_import_result']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['sls_update_status'] ?? '') === '1') {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, max-age=0');
	echo json_encode($slsmassnotifyserver->getManualUpdateProgress(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['sls_maintenance_status'] ?? '') === '1') {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, max-age=0');
	echo json_encode($slsmassnotifyserver->getMaintenanceProgress(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
	if (($_POST['slsmassnotifyserver_action'] ?? '') === 'check_bulkvs_sender') {
		http_response_code(403); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
		echo json_encode(['success'=>false, 'message'=>_('The security token expired. Reload General Settings before checking the sender.')]);
		exit;
	}
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
	if ($action === 'manage_api_credential') {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		echo json_encode($slsmassnotifyserver->manageApiCredential($_POST), JSON_UNESCAPED_SLASHES);
		exit;
	} elseif ($action === 'check_advertised_address') {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		echo json_encode($slsmassnotifyserver->checkAdvertisedAddress($_POST), JSON_UNESCAPED_SLASHES);
		exit;
	} elseif ($action === 'check_bulkvs_sender') {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		echo json_encode($slsmassnotifyserver->checkBulkvsSender(), JSON_UNESCAPED_SLASHES);
		exit;
	} elseif ($action === 'preview_device_capacity') {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		echo json_encode($slsmassnotifyserver->previewDeviceCapacity($_POST), JSON_UNESCAPED_SLASHES);
		exit;
	} elseif ($action === 'save_other_settings') {
		$_SESSION['slsmassnotifyserver_other_save_result'] = $slsmassnotifyserver->saveOtherSettings($_POST, $_FILES);
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'regenerate_control_api_key') {
		$_SESSION['slsmassnotifyserver_other_token_result'] = $slsmassnotifyserver->regenerateControlApiKey($_POST);
		header('Location: config.php?display=slsmassnotifyserver_other');
		exit;
	} elseif ($action === 'export_encrypted_config') {
		try {
			$encrypted = $slsmassnotifyserver->exportEncryptedConfig($_POST['backup_passphrase'] ?? '', $_POST['backup_passphrase_confirm'] ?? '');
		} catch (\Throwable $error) {
			$_SESSION['slsmassnotifyserver_other_save_result'] = ['success' => false, 'message' => $error->getMessage(), 'errors' => []];
			header('Location: config.php?display=slsmassnotifyserver_other');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="sls-mass-notify.encrypted.config"');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo $encrypted;
		exit;
	} elseif ($action === 'export_config') {
		try {
			$plain = $slsmassnotifyserver->exportConfig();
		} catch (\Throwable $error) {
			$_SESSION['slsmassnotifyserver_other_save_result'] = ['success' => false, 'message' => $error->getMessage(), 'errors' => []];
			header('Location: config.php?display=slsmassnotifyserver_other');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="sls-mass-notify.config"');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo $plain;
		exit;
	} elseif ($action === 'import_config') {
		$importResult = $slsmassnotifyserver->importConfigUpload($_FILES['config_upload'] ?? [], $_POST['import_passphrase'] ?? '');
		$_SESSION['slsmassnotifyserver_other_import_result'] = $importResult;
		header('Location: config.php?display=slsmassnotifyserver_other' . (!empty($importResult['success']) ? '&sls_maintenance_action=config' : ''));
		exit;
	} elseif ($action === 'repair_installation') {
		$repairResult = $slsmassnotifyserver->repairInstallation();
		$_SESSION['slsmassnotifyserver_other_save_result'] = $repairResult;
		header('Location: config.php?display=slsmassnotifyserver_other' . (!empty($repairResult['success']) ? '&sls_maintenance_action=repair' : ''));
		exit;
	} elseif ($action === 'manual_update') {
		$updateResult = $slsmassnotifyserver->requestManualUpdate();
		$_SESSION['slsmassnotifyserver_other_save_result'] = $updateResult;
		header('Location: config.php?display=slsmassnotifyserver_other' . (!empty($updateResult['success']) ? '&sls_update_queued=1' : ''));
		exit;
	} elseif ($action === 'complete_uninstall') {
		$uninstallResult = $slsmassnotifyserver->requestCompleteUninstall();
		$_SESSION['slsmassnotifyserver_other_save_result'] = $uninstallResult;
		header('Location: config.php?display=slsmassnotifyserver_other' . (!empty($uninstallResult['success']) ? '&sls_maintenance_action=uninstall' : ''));
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
	'update_monitor_active' => ($_GET['sls_update_queued'] ?? '') === '1',
	'update_progress' => $slsmassnotifyserver->getManualUpdateProgress(),
	'maintenance_monitor_action' => in_array(($_GET['sls_maintenance_action'] ?? ''), ['repair', 'uninstall', 'config'], true) ? (string)$_GET['sls_maintenance_action'] : '',
	'maintenance_progress' => $slsmassnotifyserver->getMaintenanceProgress(),
]);
