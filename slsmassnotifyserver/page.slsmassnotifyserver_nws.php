<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_save_result'] ?? null;
$applyResult = $_SESSION['slsmassnotifyserver_apply_result'] ?? null;
$testResult = $_SESSION['slsmassnotifyserver_test_result'] ?? null;
unset(
	$_SESSION['slsmassnotifyserver_save_result'],
	$_SESSION['slsmassnotifyserver_apply_result'],
	$_SESSION['slsmassnotifyserver_test_result']
);

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'cooldowns') {
	header('Content-Type: application/json');
	echo json_encode([
		'success' => true,
		'cooldowns' => $slsmassnotifyserver->getCooldownState(),
	]);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['slsmassnotifyserver_action'] ?? '';
	if ($action === 'trigger_test') {
		$triggerName = 'FreePBX Dashboard';
		if (isset($_SESSION['AMP_user']->username) && $_SESSION['AMP_user']->username !== '') {
			$triggerName = (string)$_SESSION['AMP_user']->username;
		}

		$result = $slsmassnotifyserver->triggerTest('tts', '', $triggerName);
		if (($_POST['ajax'] ?? '') === '1') {
			header('Content-Type: application/json');
			$result['cooldowns'] = $slsmassnotifyserver->getCooldownState();
			echo json_encode($result);
			exit;
		}
		$_SESSION['slsmassnotifyserver_test_result'] = $result;
		header('Location: config.php?display=slsmassnotifyserver_nws');
		exit;
	}

	if ($action === 'save_settings') {
		$_SESSION['slsmassnotifyserver_save_result'] = $slsmassnotifyserver->saveSettings($_POST, $_FILES);
		header('Location: config.php?display=slsmassnotifyserver_nws');
		exit;
	}

	if ($action === 'apply_settings') {
		$_SESSION['slsmassnotifyserver_apply_result'] = $slsmassnotifyserver->applySettings();
		header('Location: config.php?display=slsmassnotifyserver_nws');
		exit;
	}
}

echo $slsmassnotifyserver->showPage('nws_alerts', [
	'save_result' => $saveResult,
	'apply_result' => $applyResult,
	'test_result' => $testResult,
]);
