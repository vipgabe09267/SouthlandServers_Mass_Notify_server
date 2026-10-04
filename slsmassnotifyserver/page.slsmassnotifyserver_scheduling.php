<?php

// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_scheduling');
$saveResult = $_SESSION['slsmassnotifyserver_scheduling_result'] ?? null;
unset($_SESSION['slsmassnotifyserver_scheduling_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['slsmassnotifyserver_action'] ?? '');
	$calendarRequest = in_array($action, ['preview_schedule_calendar', 'import_schedule_calendar'], true);
	$respond = static function (array $body, int $status = 200): void {
		http_response_code($status); header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
		echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
	};
	$redirect = 'config.php?display=slsmassnotifyserver_scheduling';
	if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
		if ($calendarRequest) { $respond(['success'=>false, 'message'=>_('The security token expired. Reload Scheduling before reviewing or importing dates.')], 403); }
		$_SESSION['slsmassnotifyserver_scheduling_result'] = [
			'success' => false,
			'message' => _('The request security token is invalid or expired. Reload the page and try again.'),
			'errors' => [],
		];
		header('Location: ' . $redirect);
		exit;
	}

	if ($calendarRequest) {
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		try {
			if ($action === 'preview_schedule_calendar') { $respond($slsmassnotifyserver->previewScheduleCalendar($_POST)); }
			require_once __DIR__ . '/ScheduleCalendar.php';
			if (!is_string($_POST['calendar_text'] ?? null) || !is_string($_POST['calendar_format'] ?? null)) {
				throw new \DomainException(_('Select a calendar file before importing.'));
			}
			$respond(['success'=>true, 'exclusions'=>\SLS\MassNotify\ScheduleCalendar::import($_POST['calendar_text'], $_POST['calendar_format'])]);
		} catch (\DomainException $error) { $respond(['success'=>false, 'message'=>$error->getMessage()], 400); }
		catch (\Throwable $error) { $respond(['success'=>false, 'message'=>_('Calendar review could not complete. Check the PBX connection and PHP error log; no schedule was changed.')], 503); }
	}
	try {
		require_once __DIR__ . '/RecipientSelection.php';
		$_POST = \SLS\MassNotify\RecipientSelection::decode($_POST);
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
	} catch (\DomainException $exception) {
		$result = ['success' => false, 'message' => $exception->getMessage(), 'errors' => []];
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
