<?php

// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$saveResult = $_SESSION['slsmassnotifyserver_scheduling_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_scheduling_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$redirect = 'config.php?display=slsmassnotifyserver_scheduling';
	if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
		$_SESSION['slsmassnotifyserver_scheduling_result'] = [
			'success' => false,
			'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
			'errors' => [],
		];
		header('Location: ' . $redirect);
		exit;
	}

	$action = (string)($_POST['slsmassnotifyserver_action'] ?? '');
	try {
		if ($action === 'save_scheduled_announcement') {
			$result = $slsmassnotifyserver->saveScheduledAnnouncement($_POST);
		} elseif ($action === 'delete_scheduled_announcement') {
			$result = $slsmassnotifyserver->deleteScheduledAnnouncement($_POST['schedule_id'] ?? '');
		} elseif ($action === 'toggle_scheduled_announcement') {
			$result = $slsmassnotifyserver->toggleScheduledAnnouncement(
				$_POST['schedule_id'] ?? '',
				($_POST['schedule_enabled'] ?? '0') === '1'
			);
		} else {
			$result = [
				'success' => false,
				'message' => _('Unsupported scheduling action.'),
				'errors' => [],
			];
		}
	} catch (\Throwable $exception) {
		error_log('SLS Mass Notify scheduling request failed: ' . $exception->getMessage());
		$result = [
			'success' => false,
			'message' => _('The scheduling request could not be completed.'),
			'errors' => [_('Review Notification Logs and Dashboard health for more information.')],
		];
	}

	$_SESSION['slsmassnotifyserver_scheduling_result'] = is_array($result) ? $result : [
		'success' => false,
		'message' => _('The scheduling request returned an invalid result.'),
		'errors' => [],
	];
	header('Location: ' . $redirect);
	exit;
}

echo $slsmassnotifyserver->showPage('scheduling', [
	'save_result' => $saveResult,
]);
