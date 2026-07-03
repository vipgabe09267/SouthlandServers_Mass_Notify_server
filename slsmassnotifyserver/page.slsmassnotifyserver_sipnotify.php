<?php
// Southland Servers Mass Notification Plugin

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_sipnotify_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_sipnotify_apply_result'] ?? null;
$tokenResult = $_SESSION['slsmassnotifyserver_sipnotify_token_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_sipnotify_save_result'], $_SESSION['slsmassnotifyserver_sipnotify_apply_result'], $_SESSION['slsmassnotifyserver_sipnotify_token_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['slsmassnotifyserver_action'] ?? '';
	if ($action === 'save_sipnotify_settings') {
		$_SESSION['slsmassnotifyserver_sipnotify_save_result'] = $slsmassnotifyserver->saveSipNotifySettings($_POST, $_FILES);
		header('Location: config.php?display=slsmassnotifyserver_sipnotify');
		exit;
	} elseif ($action === 'apply_settings') {
		$_SESSION['slsmassnotifyserver_sipnotify_apply_result'] = $slsmassnotifyserver->applySettings();
		header('Location: config.php?display=slsmassnotifyserver_sipnotify');
		exit;
	} elseif ($action === 'regenerate_api_token') {
		$_SESSION['slsmassnotifyserver_sipnotify_token_result'] = $slsmassnotifyserver->regenerateSipNotifyApiToken();
		header('Location: config.php?display=slsmassnotifyserver_sipnotify');
		exit;
	}
}

echo $slsmassnotifyserver->showPage('sipnotify_settings', [
	'save_result' => $saveResult,
	'apply_result' => $applyResult,
	'token_result' => $tokenResult,
]);
