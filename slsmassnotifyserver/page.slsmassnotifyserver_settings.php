<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_apply_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_save_result'], $_SESSION['slsmassnotifyserver_apply_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['slsmassnotifyserver_action'] ?? '';
	if ($action === 'save_settings') {
		$_SESSION['slsmassnotifyserver_save_result'] = $slsmassnotifyserver->saveSettings($_POST, $_FILES);
		header('Location: config.php?display=slsmassnotifyserver_settings');
		exit;
	} elseif ($action === 'apply_settings') {
		$_SESSION['slsmassnotifyserver_apply_result'] = $slsmassnotifyserver->applySettings();
		header('Location: config.php?display=slsmassnotifyserver_settings');
		exit;
	}
}

echo $slsmassnotifyserver->showPage('settings', [
	'save_result' => $saveResult,
	'apply_result' => $applyResult,
]);
