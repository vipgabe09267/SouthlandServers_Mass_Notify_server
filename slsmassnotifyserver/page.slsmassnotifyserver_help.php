<?php
require_once __DIR__.'/AdminResponse.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';
// Southland Servers Mass Notifications Server by the Southland Servers Group

$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_help');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$submittedAction = $requestMethod === 'POST' ? ($_POST['slsmassnotifyserver_action'] ?? null) : ($_GET['slsmassnotifyserver_action'] ?? null);
$deviceTestAction = is_string($submittedAction) ? $submittedAction : '';
if (in_array($deviceTestAction, ['device_acceptance_state', 'record_device_acceptance', 'installation_profile'], true)) {
	$respond = static function (array $body, int $status): void {
		\SLS\MassNotify\AdminResponse::requireClearBuffers();
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	};
	if ($requestMethod !== 'POST') { $respond(['success' => false, 'message' => 'Device-test actions require POST.'], 405); }
	if (!is_string($_POST['slsmassnotifyserver_csrf'] ?? null)
		|| !$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'])) {
		$respond(['success' => false, 'message' => 'The security token expired. Reload the page before recording this observation.'], 403);
	}
	if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
	if ($deviceTestAction === 'installation_profile') { $result=$slsmassnotifyserver->getInstallationProfile(); $respond($result, !empty($result['success']) ? 200 : 403); }
	if ($deviceTestAction === 'record_device_acceptance') {
		$raw = $_POST['payload'] ?? null;
		if (!is_string($raw) || strlen($raw) > 8192) { $respond(['success' => false, 'message' => 'The device-test form is invalid or exceeds its request limit.'], 400); }
		try { $input = \SLS\MassNotify\ApiSecurity::jsonObject($raw, false, 12); }
		catch (\JsonException | \InvalidArgumentException $error) { $respond(['success' => false, 'message' => 'The device-test form is not valid JSON. Reload and retry.'], 400); }
		if (!is_array($input) || array_is_list($input)) { $respond(['success' => false, 'message' => 'The device-test form must contain named fields.'], 400); }
		$result = $slsmassnotifyserver->recordDeviceAcceptance($input);
	} else { $result = $slsmassnotifyserver->deviceAcceptanceState(); }
	$status = !empty($result['success']) ? 200 : (($result['error_code'] ?? '') === 'permission_denied' ? 403
		: (($result['error_code'] ?? '') === 'configuration_changed' ? 409 : 400));
	$respond($result, $status);
}
echo $slsmassnotifyserver->showPage('help');
