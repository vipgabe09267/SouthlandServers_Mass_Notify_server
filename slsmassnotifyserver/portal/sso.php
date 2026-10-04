<?php
declare(strict_types=1);
ini_set('display_errors','0');
$moduleRoot='/var/www/html/admin/modules/slsmassnotifyserver';
require_once $moduleRoot.'/OperatorPortal.php';
require_once $moduleRoot.'/EnterpriseIdentity.php';
require_once $moduleRoot.'/EnterpriseIdentityStore.php';
use SLS\MassNotify\{EnterpriseIdentity,EnterpriseIdentityConfig,EnterpriseIdentityStore,OperatorPortal,LivePagingSession,ApiSecurity};

$nonce=base64_encode(random_bytes(18));OperatorPortal::headers($nonce);$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)){http_response_code(405);throw new DomainException('Use GET or POST.');}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>196608){http_response_code(413);throw new DomainException('Authentication response exceeds its limit.');}
    $settings=LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');$network=ApiSecurity::requestNetwork($_SERVER,$settings);
    $c=EnterpriseIdentityConfig::normalize($settings['enterprise_identity']??[]);
    if(!$c['enabled']||empty($settings['operator_access']['portal_enabled'])){http_response_code(404);throw new DomainException('Enterprise sign-in is unavailable.');}
    if(!$network['https']||$network['error']!==''){http_response_code(403);throw new DomainException('Enterprise sign-in requires HTTPS.');}
    $store=new EnterpriseIdentityStore('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-state');$now=time();
    $rate=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-sso-rate.json',$network['ip'],$network['ip'],$now);
    if($rate){header('Retry-After: '.$rate);http_response_code(429);throw new DomainException('Enterprise sign-in rate limit reached.');}
    $start=($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['provider_id']));
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');session_name(OperatorPortal::COOKIE);
    session_set_cookie_params(['lifetime'=>0,'path'=>OperatorPortal::BASE,'secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    if(!session_start()){throw new RuntimeException('Session unavailable.');}unset($_SESSION['AMP_user']);
    if($start){
        if(array_diff(array_keys($_POST),['provider_id','portal_csrf'])||$_GET||!is_string($_POST['provider_id'])||!OperatorPortal::validPost($_SERVER,$_SESSION,$_POST['portal_csrf']??null)){http_response_code(403);throw new DomainException('Start sign-in from the operator portal.');}
        $p=EnterpriseIdentityConfig::provider($settings,$_POST['provider_id']);
        $browser=bin2hex(random_bytes(32));setcookie('SLSIDP',$browser,['expires'=>$now+300,'path'=>OperatorPortal::BASE,'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        require_once $moduleRoot.($p['protocol']==='oidc'?'/EnterpriseIdentityOidc.php':'/EnterpriseIdentitySaml.php');
        $adapter=$p['protocol']==='oidc'?new \SLS\MassNotify\EnterpriseIdentityOidc():new \SLS\MassNotify\EnterpriseIdentitySaml();$url=$adapter->begin($p,$store,$browser,$now);
        session_write_close();header('Location: '.$url,true,303);exit;
    }
    $browser=$_COOKIE['SLSIDP']??'';setcookie('SLSIDP','',['expires'=>1,'path'=>OperatorPortal::BASE,'secure'=>true,'httponly'=>true,'samesite'=>'None']);
    if(!is_string($browser)){throw new DomainException('Authentication browser binding missing.');}
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(array_diff(array_keys($_GET),['state','code','error','error_description','session_state','iss'])||$_POST||!is_string($_GET['state']??null)){throw new DomainException('OIDC callback invalid.');}
        $state=$_GET['state'];$tx=$store->take($state,$browser,$now);if(!empty($tx['invalid'])){throw new DomainException('Authentication transaction expired or already used.');}$p=EnterpriseIdentityConfig::provider($settings,$tx['provider_id']);
        if(isset($_GET['error'])){throw new DomainException('The identity provider did not complete sign-in.');}
        if(isset($_GET['iss'])&&$_GET['iss']!==$p['issuer']){throw new DomainException('Authorization response issuer mismatch.');}
        require_once $moduleRoot.'/EnterpriseIdentityOidc.php';$assertion=(new \SLS\MassNotify\EnterpriseIdentityOidc())->finish($p,$tx,$_GET['code']??'',$store,$now);
    }else{
        if($_GET||array_diff(array_keys($_POST),['RelayState','SAMLResponse'])||!is_string($_POST['RelayState']??null)||!is_string($_POST['SAMLResponse']??null)){throw new DomainException('SAML callback invalid.');}
        $tx=$store->take($_POST['RelayState'],$browser,$now);if(!empty($tx['invalid'])){throw new DomainException('Authentication transaction expired or already used.');}$p=EnterpriseIdentityConfig::provider($settings,$tx['provider_id']);
        require_once $moduleRoot.'/EnterpriseIdentitySaml.php';$assertion=(new \SLS\MassNotify\EnterpriseIdentitySaml())->finish($p,$tx,$_POST['SAMLResponse'],$store,$now);
    }
    // Refresh the encrypted settings immediately before assigning authority.
    $settings=LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');$grant=EnterpriseIdentity::grant($settings,$assertion);
    $store->transaction('identity_audit',static function(array &$rows)use($grant,$now):void{if(count($rows)>=1000){$rows=array_slice($rows,-999,null,true);}$rows['event_'.bin2hex(random_bytes(16))]=['at'=>$now,'kind'=>'enterprise_login','provider_id'=>$grant['binding']['provider_id'],'protocol'=>$grant['binding']['protocol'],'operator_account_id'=>$grant['account']['id']];});
    // Attribute verified federation to the same saved operator account as local
    // sign-in. Provider subjects and authentication tokens stay out of this log.
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true,'include_compress'=>false];$restrict_mods=true;
    require '/etc/freepbx.conf';$module=FreePBX::create()->Slsmassnotifyserver;
    if(!$module->operatorSecurityEvent('operator_sign_in_completed',$grant['account'])){throw new RuntimeException('Operator sign-in audit unavailable.');}
    if(!session_regenerate_id(true)){throw new RuntimeException('Session rotation failed.');}$_SESSION=[];$_SESSION['portal_identity']=OperatorPortal::binding($grant['account'],$now);
    $_SESSION['portal_identity']['enterprise_identity']=$grant['binding'];OperatorPortal::csrf($_SESSION);session_write_close();header('Location: '.OperatorPortal::BASE,true,303);exit;
}catch(DomainException|InvalidArgumentException|JsonException $error){$status=http_response_code();http_response_code(is_int($status)&&$status>=400?$status:401);$message='Enterprise sign-in failed. '.$error->getMessage();}
catch(Throwable $error){http_response_code(503);$message='Enterprise sign-in is unavailable. Use your local operator login or ask an administrator to check the provider registration and protected state.';}
finally{if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}}
header('Content-Type: text/html; charset=utf-8');echo OperatorPortal::document('<section class="portal-login"><h1>Enterprise sign-in</h1><p class="portal-error" role="alert">'.$e($message).'</p><a href="'.OperatorPortal::BASE.'">Return to local sign-in</a></section>',$nonce,[],'','login');
