<?php
declare(strict_types=1);
require_once __DIR__.'/AdminResponse.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';
$slsmassnotifyserver = \FreePBX::create()->Slsmassnotifyserver;
$slsmassnotifyserver->enforceOperatorPageAccess('slsmassnotifyserver_operators');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    \SLS\MassNotify\AdminResponse::requireClearBuffers();
    header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
    $csrf = $_POST['slsmassnotifyserver_csrf'] ?? null;
    if (!is_string($csrf) || !$slsmassnotifyserver->validateCsrfToken($csrf)) {
        http_response_code(403); echo json_encode(['success'=>false, 'message'=>'The security token expired. Reload Operator Access.']); exit;
    }
    try {
        $raw = $_POST['payload'] ?? null;
        $action=$_POST['slsmassnotifyserver_action']??'';
        $limit=$action==='enterprise_labs'?2097152:131072;
        if (!in_array($action,['save_access','generate_reset_link','revoke_reset_link','enterprise_labs'],true) || !is_string($raw) || strlen($raw) > $limit) { throw new \InvalidArgumentException('The operator form is invalid or exceeds its size limit.'); }
        $input = \SLS\MassNotify\ApiSecurity::jsonObject($raw, false, 16);
        if (!is_array($input) || array_is_list($input)) { throw new \InvalidArgumentException('The operator form must be an object.'); }
        if($action==='enterprise_labs'){
            if(array_diff(array_keys($input),['action','input'])||!is_string($input['action']??null)||!is_array($input['input']??null)){
                throw new \InvalidArgumentException('Submit an operator SSO action and its settings.');
            }
            $result=$slsmassnotifyserver->operatorIdentityAction($input['action'],$input['input']);
        }else{
            $result = $action==='save_access'?$slsmassnotifyserver->saveOperatorAccess($input):$slsmassnotifyserver->manageOperatorPasswordReset($action,$input);
        }
    } catch (\JsonException | \InvalidArgumentException $error) { http_response_code(400); $result = ['success'=>false, 'message'=>'The operator form is invalid. Reload before saving.']; }
    catch (\DomainException $error) { http_response_code(422); $result=['success'=>false,'message'=>$error->getMessage()]; }
    catch (\Throwable $error) { http_response_code(503); header('Retry-After: 5'); $result=['success'=>false,'message'=>'Operator settings could not confirm completion. Reload saved settings before retrying and check protected storage.']; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
echo $slsmassnotifyserver->renderOperatorsPage();
