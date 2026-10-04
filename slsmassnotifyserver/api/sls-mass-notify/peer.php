<?php
declare(strict_types=1);
// Install under the existing API location. No bearer token or forwarded header
// may substitute for Apache's verified TLS client certificate environment.
ini_set('display_errors','0');
header('Content-Type: application/json'); header('Cache-Control: no-store');
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
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || ($_SERVER['HTTPS']??'')!=='on'
        || ($_SERVER['SSL_CLIENT_VERIFY']??'')!=='SUCCESS' || !is_string($_SERVER['SSL_CLIENT_CERT']??null)
        || ($_SERVER['CONTENT_TYPE']??'')!=='application/json') { throw new DomainException('Verified mutual TLS and POST application/json are required.'); }
    $length=$_SERVER['CONTENT_LENGTH']??'';
    if (!is_string($length) || !preg_match('/^[1-9][0-9]{0,6}$/D',$length) || (int)$length>\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE) { throw new DomainException('Invalid or oversized peer request.'); }
    $body=file_get_contents('php://input',false,null,0,\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE+1);
    if (!is_string($body) || strlen($body)!==(int)$length) { throw new DomainException('Peer request was truncated.'); }
    $settings=\FreePBX\modules\SlsConfigCrypto::readFile('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($settings);
    $fingerprint=openssl_x509_fingerprint($_SERVER['SSL_CLIENT_CERT'],'sha256');
    if (!is_string($fingerprint)) { throw new DomainException('Client certificate is invalid.'); }
    $reply=$runtime->handle($body,$fingerprint,true); header('Content-Length: '.strlen($reply)); echo $reply;
} catch (Throwable $error) {
    http_response_code(403); $reply='{"error":"peer_authentication_or_authority_rejected"}'; header('Content-Length: '.strlen($reply)); echo $reply;
}
