<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_setup_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_setup_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
	$_SESSION['slsmassnotifyserver_setup_result'] = [
		'success' => false,
		'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
		'errors' => [],
	];
	header('Location: config.php?display=slsmassnotifyserver');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_setup_wizard') {
	$setupSaveResult = $slsmassnotifyserver->saveSetupWizard($_POST);
	$_SESSION['slsmassnotifyserver_setup_result'] = $setupSaveResult;
	header('Location: ' . (!empty($setupSaveResult['success']) ? 'index.php' : 'config.php?display=slsmassnotifyserver'));
	exit;
}

echo $slsmassnotifyserver->showPage('setup', [
	'save_result' => $saveResult,
]);
