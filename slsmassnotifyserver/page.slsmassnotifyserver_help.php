<?php
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_help');
$deviceTestAction = $_REQUEST['slsmassnotifyserver_action'] ?? '';
if (in_array($deviceTestAction, ['device_acceptance_state', 'record_device_acceptance'], true)) {
	$respond = static function (array $body, int $status): void {
		while (ob_get_level() > 0) { @ob_end_clean(); }
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($body, JSON_UNESCAPED_SLASHES);
		exit;
	};
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $respond(['success' => false, 'message' => 'Device-test actions require POST.'], 405); }
	if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
		$respond(['success' => false, 'message' => 'The security token expired. Reload the page before recording this observation.'], 403);
	}
	if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
	if ($deviceTestAction === 'record_device_acceptance') {
		$raw = $_POST['payload'] ?? null;
		if (!is_string($raw) || strlen($raw) > 8192) { $respond(['success' => false, 'message' => 'The device-test form is invalid or exceeds its request limit.'], 400); }
		try { $input = json_decode($raw, true, 12, JSON_THROW_ON_ERROR); }
		catch (\Throwable $error) { $respond(['success' => false, 'message' => 'The device-test form is not valid JSON. Reload and retry.'], 400); }
		if (!is_array($input) || array_is_list($input)) { $respond(['success' => false, 'message' => 'The device-test form must contain named fields.'], 400); }
		$result = $slsmassnotifyserver->recordDeviceAcceptance($input);
	} else { $result = $slsmassnotifyserver->deviceAcceptanceState(); }
	$status = !empty($result['success']) ? 200 : (($result['error_code'] ?? '') === 'permission_denied' ? 403
		: (($result['error_code'] ?? '') === 'configuration_changed' ? 409 : 400));
	$respond($result, $status);
}
echo $slsmassnotifyserver->showPage('help');
