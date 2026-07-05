<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$view = isset($_REQUEST['view']) ? $_REQUEST['view'] : 'main';
$saveResult = null;
$setupResult = $_SESSION['slsmassnotifyserver_setup_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_setup_result']);

function slsmassnotifyserver_json_response(array $payload)
{
	while (ob_get_level() > 0) {
		@ob_end_clean();
	}
	header('Content-Type: application/json');
	echo json_encode($payload);
	exit;
}

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'cooldowns') {
	slsmassnotifyserver_json_response([
		'success' => true,
		'cooldowns' => $slsmassnotifyserver->getCooldownState(),
	]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_setup_wizard') {
	$_SESSION['slsmassnotifyserver_setup_result'] = $slsmassnotifyserver->saveSetupWizard($_POST);
	header('Location: config.php?display=slsmassnotifyserver');
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
