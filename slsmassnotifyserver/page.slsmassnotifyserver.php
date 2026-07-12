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
	if (in_array($csrfAction, ['send_announcement', 'save_announcement_group', 'delete_announcement_group'], true)) {
		slsmassnotifyserver_json_response($csrfResult, 403);
	}
	$_SESSION['slsmassnotifyserver_setup_result'] = $csrfResult;
	header('Location: config.php?display=slsmassnotifyserver');
	exit;
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
		!empty($_POST['announcement_tts_audio']),
		$_POST['announcement_groups'] ?? [],
		[
			'phones_all' => !empty($_POST['announcement_all_phones']),
			'desktop_all' => !empty($_POST['announcement_all_desktops']),
			'desktop_clients' => $_POST['announcement_desktop_clients'] ?? [],
			'style' => !empty($_POST['announcement_colored']) ? 'colored' : 'standard',
			'image' => !empty($_POST['announcement_colored']),
			'title' => $_POST['announcement_title'] ?? 'Announcement',
			'background_color' => $_POST['announcement_background_color'] ?? '#1f2937',
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
