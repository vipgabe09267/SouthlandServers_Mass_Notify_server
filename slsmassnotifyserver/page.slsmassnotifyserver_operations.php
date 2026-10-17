<?php
declare(strict_types=1);
require_once __DIR__.'/AdminResponse.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_operations');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Cache-Control: private, no-store');
    header('Location: /mass-notify/', true, 303); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    \SLS\MassNotify\AdminResponse::requireClearBuffers();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    $csrf = $_POST['slsmassnotifyserver_csrf'] ?? null;
    if (!is_string($csrf) || !$slsmassnotifyserver->validateCsrfToken($csrf)) {
        http_response_code(403); echo json_encode(['success'=>false, 'message'=>'The security token expired. Reload Operations.']); exit;
    }
    try {
        $raw = $_POST['payload'] ?? null; $action = $_POST['slsmassnotifyserver_action'] ?? null;
        if (!is_string($raw) || strlen($raw) > 65536 || !is_string($action)) { throw new \InvalidArgumentException('The Operations request is missing or exceeds 64 KiB.'); }
        $input = \SLS\MassNotify\ApiSecurity::jsonObject($raw, false, 16);
        $result = $slsmassnotifyserver->operatorAction($action, $input);
    } catch (\JsonException | \InvalidArgumentException $error) { http_response_code(400); $result = ['success'=>false, 'message'=>'The Operations request is invalid. Reload and try again.']; }
    catch (\DomainException $error) { http_response_code(422); $result = ['success'=>false, 'message'=>$error->getMessage()]; }
    catch (\Throwable $error) { http_response_code(503); header('Retry-After: 5'); $result = ['success'=>false, 'message'=>'The operation could not confirm completion. Reload current state before retrying and check protected storage.']; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
header('Cache-Control: private, no-store');
echo $slsmassnotifyserver->renderOperationsPage(is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : '');
