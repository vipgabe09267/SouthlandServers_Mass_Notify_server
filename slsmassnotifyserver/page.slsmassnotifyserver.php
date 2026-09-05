<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$view = isset($_REQUEST['view']) ? $_REQUEST['view'] : 'main';
$saveResult = null;
$setupResult = $_SESSION['slsmassnotifyserver_setup_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_setup_result']);

function slsmassnotifyserver_json_response(array $payload, $status = 200)
{
	while (ob_get_level() > 0) {
		@ob_end_clean();
	}
	http_response_code((int)$status);
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($payload);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
	$csrfResult = [
		'success' => false,
		'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
		'cooldown_remaining' => 0,
	];
	$csrfAction = (string)($_POST['slsmassnotifyserver_action'] ?? '');
	if (in_array($csrfAction, ['send_announcement', 'retry_announcement', 'run_test_profile', 'save_announcement_group', 'delete_announcement_group'], true)) {
		slsmassnotifyserver_json_response($csrfResult, 403);
	}
	$_SESSION['slsmassnotifyserver_setup_result'] = $csrfResult;
	header('Location: config.php?display=slsmassnotifyserver');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'diagnostic_download') {
	// FreePBX authenticates this module page; the POST security token was checked
	// above. Release its session before the bounded, read-only AMI discovery.
	if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
	try {
		$report = json_encode($slsmassnotifyserver->getRedactedSupportDiagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if (strlen($report) > 131072) { throw new \RuntimeException('Diagnostic report limit'); }
	} catch (\Throwable $error) {
		slsmassnotifyserver_json_response(['success' => false, 'message' => _('The diagnostic report could not be generated. No configuration was exported.')], 503);
	}
	while (ob_get_level() > 0) { @ob_end_clean(); }
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="sls-diagnostics-' . gmdate('Ymd\\THis\\Z') . '.json"');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	echo $report, "\n";
	exit;
}

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'announcement_job') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->getAnnouncementJob($_GET['job_id'] ?? ''));
}

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'device_inventory') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->getDeviceOverrideInventory());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'retry_announcement') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->retryFailedAnnouncementJob($_POST['job_id'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'run_test_profile') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->runTestProfile($_POST['profile_id'] ?? ''));
}

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'cooldowns') {
	slsmassnotifyserver_json_response([
		'success' => true,
		'cooldowns' => $slsmassnotifyserver->getCooldownState(),
	]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_setup_wizard') {
	$setupSaveResult = $slsmassnotifyserver->saveSetupWizard($_POST);
	$_SESSION['slsmassnotifyserver_setup_result'] = $setupSaveResult;
	header('Location: ' . (!empty($setupSaveResult['success']) ? 'index.php' : 'config.php?display=slsmassnotifyserver'));
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'send_announcement') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->sendSipNotifyAnnouncement(
		$_POST['announcement_extensions'] ?? [],
		$_POST['announcement_body'] ?? '',
		!empty($_POST['announcement_mass_notify']),
		in_array(($_POST['announcement_audio_mode'] ?? 'none'), ['tts', 'tones_tts'], true),
		$_POST['announcement_groups'] ?? [],
		[
			'phones_all' => !empty($_POST['announcement_all_phones']),
			'preview' => !empty($_POST['announcement_preview']),
			'desktop_all' => !empty($_POST['announcement_all_desktops']),
			'desktop_clients' => $_POST['announcement_desktop_clients'] ?? [],
			'webhook_ids' => $_POST['announcement_webhooks'] ?? [],
			'style' => !empty($_POST['announcement_colored']) ? 'colored' : 'standard',
			'image' => !empty($_POST['announcement_colored']),
			'title' => $_POST['announcement_title'] ?? 'Announcement',
			'background_color' => $_POST['announcement_background_color'] ?? '#1f2937',
			'audio_mode' => $_POST['announcement_audio_mode'] ?? 'none',
			'priority' => $_POST['announcement_priority'] ?? 'normal',
			'opening_tone' => $_POST['announcement_opening_tone'] ?? '',
			'closing_tone' => $_POST['announcement_closing_tone'] ?? '',
		]
	));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_announcement_group') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->saveAnnouncementGroup(
		$_POST['group_id'] ?? '',
		$_POST['group_name'] ?? '',
		$_POST['group_extensions'] ?? [],
		$_POST['group_desktop_clients'] ?? []
	));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'delete_announcement_group') {
	slsmassnotifyserver_json_response($slsmassnotifyserver->deleteAnnouncementGroup($_POST['group_id'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_settings') {
	$saveResult = $slsmassnotifyserver->saveSettings($_POST, $_FILES);
	$view = 'settings';
}

echo $slsmassnotifyserver->showPage($view, [
	'id' => $_REQUEST['id'] ?? '',
	'limit' => $_REQUEST['limit'] ?? null,
	'log_type' => $_REQUEST['log_type'] ?? '',
	'save_result' => $saveResult,
	'setup_result' => $setupResult,
]);
