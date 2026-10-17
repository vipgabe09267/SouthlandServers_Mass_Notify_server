<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    $module='/var/www/html/admin/modules/slsmassnotifyserver';
    if (!is_file($module.'/EnterpriseClusterEdge.php')) {
        $source=dirname(__DIR__,2);
        if (basename($source)!=='slsmassnotifyserver' || basename(dirname(__DIR__))!=='api'
            || !is_file($source.'/module.xml')) { throw new RuntimeException('Installed edge module is unavailable.'); }
        $module=$source;
    }
    require_once $module.'/EnterpriseClusterEdge.php';
    require_once $module.'/ConfigCrypto.php';
    require_once __DIR__.'/security.php';
    if (($_SERVER['HTTPS']??'')!=='on') { throw new DomainException('Edge devices require HTTPS.'); }
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
    if (!is_string($authorization) || !preg_match('/^Bearer ([A-Za-z0-9._~-]{32,256})$/D',$authorization,$m)) { throw new DomainException('Device authentication is required.'); }
    $deviceId=$_GET['device_id']??''; $action=$_GET['action']??'poll';
    if (!is_string($deviceId) || !is_string($action)) { throw new DomainException('Edge query fields must be strings.'); }
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    $edge=new \SLS\MassNotify\EnterpriseClusterEdge(new \SLS\MassNotify\EnterpriseClusterRuntime($settings));
    $device=$edge->authenticate($deviceId,$m[1]);
    if ($action==='poll' && ($_SERVER['REQUEST_METHOD']??'')==='GET') { $result=['events'=>$edge->poll($device)]; }
    elseif ($action==='receipt' && ($_SERVER['REQUEST_METHOD']??'')==='POST') {
        $raw=file_get_contents('php://input',false,null,0,2049);
        if (!is_string($raw) || strlen($raw)>2048) { throw new DomainException('Receipt exceeds its limit.'); }
        $input=\SLS\MassNotify\ApiSecurity::jsonObject($raw,false,8);
        $result=$edge->receipt($device,$input);
    } elseif ($action==='media' && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        $eventId=$_GET['event_id']??''; $assetId=$_GET['asset_id']??'';
        if (!is_string($eventId) || !is_string($assetId)) { throw new DomainException('Edge media fields must be strings.'); }
        $asset=$edge->media($device,$eventId,$assetId);
        header('Content-Type: audio/wav'); header('Content-Length: '.strlen($asset['bytes'])); echo $asset['bytes']; exit;
    } else { throw new DomainException('Unsupported edge operation.'); }
    header('Content-Type: application/json'); $body=json_encode($result,JSON_THROW_ON_ERROR); header('Content-Length: '.strlen($body)); echo $body;
} catch (Throwable $error) { http_response_code(403); header('Content-Type: application/json'); echo '{"error":"edge_device_or_event_unavailable"}'; }
