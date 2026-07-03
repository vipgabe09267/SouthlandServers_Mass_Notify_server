<?php

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$testResult = $_SESSION['slsmassnotifyserver_test_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_test_result']);

if (($_REQUEST['slsmassnotifyserver_action'] ?? '') === 'cooldowns') {
	header('Content-Type: application/json');
	echo json_encode([
		'success' => true,
		'cooldowns' => $slsmassnotifyserver->getCooldownState(),
	]);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'trigger_test') {
	$triggerName = 'FreePBX Dashboard';
	if (isset($_SESSION['AMP_user']->username) && $_SESSION['AMP_user']->username !== '') {
		$triggerName = (string)$_SESSION['AMP_user']->username;
	}

	$result = $slsmassnotifyserver->triggerTest(
		'tts',
		'',
		$triggerName
	);
	if (($_POST['ajax'] ?? '') === '1') {
		header('Content-Type: application/json');
		$result['cooldowns'] = $slsmassnotifyserver->getCooldownState();
		echo json_encode($result);
		exit;
	}
	$_SESSION['slsmassnotifyserver_test_result'] = $result;
	header('Location: config.php?display=slsmassnotifyserver_testing');
	exit;
}

echo $slsmassnotifyserver->showPage('testing', [
	'test_result' => $testResult,
]);
