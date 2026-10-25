<?php
declare(strict_types=1);
ini_set('display_errors','0');
$moduleRoot='/var/www/html/admin/modules/slsmassnotifyserver';
require_once $moduleRoot.'/OperatorPortal.php';
require_once $moduleRoot.'/EnterpriseLabsConfig.php';
use SLS\MassNotify\{OperatorPortal,LivePagingSession,ApiSecurity,EnterpriseLabsConfig};
$nonce=base64_encode(random_bytes(18));OperatorPortal::headers($nonce);header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' blob:; font-src 'self'; connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
$content='';
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'||$_GET){http_response_code(405);header('Allow: GET');throw new DomainException('Open the browser display with GET and no URL credentials.');}
    $settings=LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    $network=ApiSecurity::requestNetwork($_SERVER,$settings);
    if(!$network['https']||$network['error']!==''){http_response_code(403);throw new DomainException('Browser display requires HTTPS.');}
    $config=$settings['enterprise_integrations']??[];
    if(!EnterpriseLabsConfig::enabled($settings)||($config['enabled']??'0')!=='1'||($config['browser_display']['enabled']??'0')!=='1'){
        http_response_code(404);throw new DomainException('Browser display has not been enabled.');
    }
    $content='<section id="sls-display" aria-labelledby="sls-display-title"><div class="portal-login-heading"><h1 id="sls-display-title">SLS Browser Display</h1><p><i class="fa fa-flask" aria-hidden="true"></i> Labs · Live announcements</p></div>
      <form id="display-login" class="portal-login"><label>Dedicated desktop username<input name="username" autocomplete="username" maxlength="80" required></label><label>Desktop password<input name="password" type="password" autocomplete="current-password" maxlength="512" required></label><button type="submit" class="btn btn-primary">Connect display</button><p>Use a dedicated desktop credential assigned only to this screen’s site or audience. Credentials remain in this page’s memory and are cleared when you disconnect or close it.</p></form>
      <div class="portal-actions"><button type="button" id="display-fullscreen" hidden>Full screen</button><button type="button" id="display-disconnect" hidden>Disconnect</button></div>
      <p id="display-status" role="status" aria-live="polite">Disconnected</p><div id="display-alerts" aria-live="polite"></div>
      <p>Disconnected announcements are skipped. Alerts expire within ten minutes. Software receipts do not mean a person read the screen.</p></section>
      <style nonce="'.$nonce.'">#sls-display{max-width:1500px;margin:auto}#display-alerts{display:grid;gap:24px}#display-alerts article{padding:clamp(24px,4vw,64px);border:2px solid #7dd3fc;border-radius:16px;background:#111c2f;color:#f8fafc;overflow-wrap:anywhere}#display-alerts h2{font-size:clamp(28px,4vw,64px);line-height:1.2}#display-alerts p{font-size:clamp(22px,2.8vw,44px);line-height:1.5;white-space:pre-wrap}#display-alerts .display-meta{font-size:16px;color:#cbd5e1}#display-alerts img{max-width:100%;height:auto}#sls-display:fullscreen{background:#0b1220;padding:24px;overflow:auto}</style>
      <script nonce="'.$nonce.'" src="/mass-notify/display.js" defer></script>';
} catch(DomainException $e) {
    $content='<section class="portal-login"><h1>SLS Browser Display</h1><p>'.htmlspecialchars($e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p></section>';
} catch(Throwable $e) {
    http_response_code(503);$content='<section class="portal-login"><h1>SLS Browser Display unavailable</h1><p>Ask an administrator to check protected settings and the desktop API.</p></section>';
}
header('Content-Type: text/html; charset=utf-8');echo OperatorPortal::document($content,$nonce,[],'','display');
