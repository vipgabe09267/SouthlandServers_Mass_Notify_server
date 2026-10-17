<?php
declare(strict_types=1);
// Install under the existing API location. No bearer token or forwarded header
// may substitute for Apache's verified TLS client certificate environment.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
    $module='/var/www/html/admin/modules/slsmassnotifyserver';
    if (!is_file($module.'/EnterpriseClusterRuntime.php')) {
        $source=dirname(__DIR__,2);
        if (basename($source)!=='slsmassnotifyserver' || basename(dirname(__DIR__))!=='api'
            || !is_file($source.'/module.xml')) { throw new RuntimeException('Installed cluster module is unavailable.'); }
        $module=$source;
    }
    require_once $module.'/EnterpriseClusterRuntime.php';
    require_once $module.'/ConfigCrypto.php';
    $contentType=$_SERVER['CONTENT_TYPE']??'';
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || ($_SERVER['HTTPS']??'')!=='on'
        || ($_SERVER['SSL_CLIENT_VERIFY']??'')!=='SUCCESS' || !is_string($_SERVER['SSL_CLIENT_CERT']??null)
        || !is_string($contentType) || strtolower(trim(explode(';',$contentType,2)[0]))!=='application/json') { throw new DomainException('Verified mutual TLS and POST application/json are required.'); }
    $length=$_SERVER['CONTENT_LENGTH']??'';
    if (!is_string($length) || !preg_match('/^[1-9][0-9]{0,6}$/D',$length) || (int)$length>\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE) { throw new DomainException('Invalid or oversized peer request.'); }
    $body=file_get_contents('php://input',false,null,0,\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE+1);
    if (!is_string($body) || strlen($body)!==(int)$length) { throw new DomainException('Peer request was truncated.'); }
    $configPath='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config';
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile($configPath);
    $mirrorValidator=static function(array $candidate,array $shared) {
        $bootstrap='/etc/freepbx.conf';
        if (!is_file($bootstrap)) { throw new RuntimeException('FreePBX configuration validation is unavailable.'); }
        require_once $bootstrap;
        $freepbx=\FreePBX::create();
        $service=$freepbx->Slsmassnotifyserver??null;
        if (!is_object($service) || !method_exists($service,'validateEnterpriseMirrorCandidate')) {
            throw new RuntimeException('SLS mirror configuration validation is unavailable.');
        }
        return $service->validateEnterpriseMirrorCandidate($candidate,$shared);
    };
    // The runtime rereads this fixed encrypted file under its private settings
    // lock immediately before every authority-changing admission.
    $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($settings,\SLS\MassNotify\EnterpriseClusterStore::DIRECTORY,null,null,null,$mirrorValidator);
    $fingerprint=openssl_x509_fingerprint($_SERVER['SSL_CLIENT_CERT'],'sha256');
    if (!is_string($fingerprint)) { throw new DomainException('Client certificate is invalid.'); }
    $reply=$runtime->handle($body,$fingerprint,true); header('Content-Length: '.strlen($reply)); echo $reply;
} catch (Throwable $error) {
    http_response_code(403); $reply='{"error":"peer_authentication_or_authority_rejected"}'; header('Content-Length: '.strlen($reply)); echo $reply;
}
