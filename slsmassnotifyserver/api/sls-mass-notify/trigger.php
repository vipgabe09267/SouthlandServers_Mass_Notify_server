<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/security.php';
require_once __DIR__.'/config-crypto.php';
require_once '/var/www/html/admin/modules/slsmassnotifyserver/AutomationInputs.php';
use SLS\MassNotify\{ApiSecurity,AutomationConfig,AutomationInputs};
function slsTriggerResponse(int $status,array $body): void
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    if (in_array($status,[429,503],true)) { header('Retry-After: 5'); }
    echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST'); slsTriggerResponse(405,['ok'=>false,'error'=>'method_not_allowed']); }
$id=$_GET['rule_id'] ?? '';
if (!is_string($id) || !preg_match('/^trg_[a-f0-9]{24}$/D',$id) || array_diff(array_keys($_GET),['rule_id'])) { slsTriggerResponse(400,['ok'=>false,'error'=>'invalid_trigger_identity']); }
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json') { slsTriggerResponse(415,['ok'=>false,'error'=>'json_required']); }
$raw=file_get_contents('php://input',false,null,0,16385);
if (!is_string($raw) || strlen($raw)>16384) { slsTriggerResponse(413,['ok'=>false,'error'=>'trigger_request_too_large']); }
try {
    $path='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile($path);
    $network=ApiSecurity::requestNetwork($_SERVER,$settings);
    if (!$network['https'] || $network['error']!=='') { slsTriggerResponse(403,['ok'=>false,'error'=>'verified_https_required']); }
    $config=AutomationConfig::normalize($settings['automations'] ?? []);
    $rule=array_column($config['rules'],null,'id')[$id] ?? null;
    if (!$rule || empty($settings['enabled']) || !AutomationInputs::authenticate($rule,$raw,
        ['timestamp'=>$_SERVER['HTTP_X_SLS_TIMESTAMP'] ?? '', 'signature'=>$_SERVER['HTTP_X_SLS_SIGNATURE'] ?? ''],time())) {
        slsTriggerResponse(403,['ok'=>false,'error'=>'trigger_authentication_failed']);
    }
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) { slsTriggerResponse(400,['ok'=>false,'error'=>'trigger_request_must_be_an_object']); }
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true]; require '/etc/freepbx.conf';
    $module=\FreePBX::create()->Slsmassnotifyserver;
    slsTriggerResponse(200,($input['operation'] ?? '')==='heartbeat'
        ? $module->receiveSensorHeartbeat($id,$input) : $module->automationRequest($id,$input));
} catch (InvalidArgumentException | DomainException | JsonException $error) {
    slsTriggerResponse($error->getCode() === 429 ? 429 : 422,['ok'=>false,'error'=>$error->getCode() === 429 ? 'trigger_cooldown' : 'trigger_request_rejected','message'=>$error instanceof JsonException ? 'Invalid JSON request.' : $error->getMessage()]);
} catch (Throwable $error) {
    slsTriggerResponse(503,['ok'=>false,'error'=>'trigger_storage_or_runtime_unavailable','message'=>'Activation could not confirm completion. Preserve this request identifier and check Trigger history before retrying.']);
}
