<?php
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_operations');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Cache-Control: private, no-store');
    header('Location: /mass-notify/', true, 303); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    if (!$slsmassnotifyserver->validateCsrfToken($_POST['slsmassnotifyserver_csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['success'=>false, 'message'=>'The security token expired. Reload Operations.']); exit;
    }
    try {
        $raw = $_POST['payload'] ?? null; $action = $_POST['slsmassnotifyserver_action'] ?? null;
        if (!is_string($raw) || strlen($raw) > 65536 || !is_string($action)) { throw new \InvalidArgumentException('The Operations request is missing or exceeds 64 KiB.'); }
        if (!(json_decode($raw, false, 16, JSON_THROW_ON_ERROR) instanceof \stdClass)) { throw new \InvalidArgumentException('Operations requires an object request.'); }
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $result = $slsmassnotifyserver->operatorAction($action, $input);
    } catch (\JsonException | \InvalidArgumentException $error) { http_response_code(400); $result = ['success'=>false, 'message'=>'The Operations request is invalid. Reload and try again.']; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
header('Cache-Control: private, no-store');
echo $slsmassnotifyserver->renderOperationsPage(is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '');
