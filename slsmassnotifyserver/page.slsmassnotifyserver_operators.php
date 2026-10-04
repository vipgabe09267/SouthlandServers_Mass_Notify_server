<?php
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_operators');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
    if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['success'=>false, 'message'=>'The security token expired. Reload Operator Access.']); exit;
    }
    try {
        $raw = $_POST['payload'] ?? null;
        $action=$_POST['slsmassnotifyserver_action']??'';
        if (!in_array($action,['save_access','generate_reset_link','revoke_reset_link'],true) || !is_string($raw) || strlen($raw) > 131072) { throw new \InvalidArgumentException('The operator form is invalid or exceeds 128 KiB.'); }
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) { throw new \InvalidArgumentException('The operator form must be an object.'); }
        $result = $action==='save_access'?$slsmassnotifyserver->saveOperatorAccess($input):$slsmassnotifyserver->manageOperatorPasswordReset($action,$input);
    } catch (\JsonException | \InvalidArgumentException $error) { http_response_code(400); $result = ['success'=>false, 'message'=>'The operator form is invalid. Reload before saving.']; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
echo $slsmassnotifyserver->renderOperatorsPage();
