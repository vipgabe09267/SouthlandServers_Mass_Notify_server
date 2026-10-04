<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/sms/Service.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/config-crypto.php';
use SLS\MassNotify\Sms\{Config,Provider,Service};
function smsCallbackResponse(int $status,string $error=''): void
{
    http_response_code($status); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    if ($status===503) { header('Retry-After: 5'); }
    if ($status===200) { header('Content-Type: application/xml; charset=utf-8'); echo '<Response/>'; }
    else { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$error]); }
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); smsCallbackResponse(405,'method_not_allowed'); }
$raw=file_get_contents('php://input',false,null,0,65537);
if (!is_string($raw) || strlen($raw)>65536) { smsCallbackResponse(413,'sms_callback_too_large'); }
$delivery=$_GET['delivery']??'';
if (!is_string($delivery) || ($delivery!=='' && !preg_match('/^smsd_[a-f0-9]{64}$/D',$delivery))
    || array_diff(array_keys($_GET),['delivery'])) { smsCallbackResponse(400,'sms_callback_invalid_delivery'); }
$headers=['content-type'=>$_SERVER['CONTENT_TYPE']??'','x-twilio-signature'=>$_SERVER['HTTP_X_TWILIO_SIGNATURE']??'',
    'telnyx-timestamp'=>$_SERVER['HTTP_TELNYX_TIMESTAMP']??'','telnyx-signature-ed25519'=>$_SERVER['HTTP_TELNYX_SIGNATURE_ED25519']??''];
try {
    $path='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile($path);
    $config=Config::normalize($settings['announcement_sms']??[]);
    if (!in_array($config['provider'],['twilio','telnyx'],true)) { smsCallbackResponse(403,'sms_callback_unconfigured'); }
    $network=\SLS\MassNotify\ApiSecurity::requestNetwork($_SERVER,$settings);
    if ($network['error']!=='' || (!$network['https'] && !$network['loopback'])) { smsCallbackResponse(426,'https_required'); }
    $url=Service::callbackUrl($settings).($delivery!==''?'?delivery='.$delivery:'');
    Provider::callback($config,$url,$raw,$headers,time());
    // Signature checks precede the PBX bootstrap and any writable SMS storage.
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true];
    require_once '/etc/freepbx.conf';
    \FreePBX::Create()->Slsmassnotifyserver->receiveSmsCallback($delivery,$raw,$headers);
    smsCallbackResponse(200);
} catch (DomainException $error) { smsCallbackResponse(403,'sms_callback_unauthorized_or_unmatched'); }
catch (InvalidArgumentException|JsonException|TypeError $error) { smsCallbackResponse(400,'sms_callback_invalid'); }
catch (Throwable $error) { smsCallbackResponse(503,'sms_receipt_storage_unavailable'); }
