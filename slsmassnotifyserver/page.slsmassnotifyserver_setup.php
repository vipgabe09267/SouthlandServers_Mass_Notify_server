<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_setup_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_setup_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slsmassnotifyserver_action'] ?? '') === 'save_setup_wizard') {
	$_SESSION['slsmassnotifyserver_setup_result'] = $slsmassnotifyserver->saveSetupWizard($_POST);
	header('Location: config.php?display=slsmassnotifyserver');
	exit;
}

echo $slsmassnotifyserver->showPage('setup', [
	'save_result' => $saveResult,
]);
