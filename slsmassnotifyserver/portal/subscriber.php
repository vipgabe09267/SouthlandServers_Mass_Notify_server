<?php
declare(strict_types=1);
ini_set('display_errors','0');
$moduleRoot='/var/www/html/admin/modules/slsmassnotifyserver';
require_once $moduleRoot.'/OperatorPortal.php';
require_once $moduleRoot.'/SubscriberService.php';
use SLS\MassNotify\{SubscriberConfig,SubscriberService,EnterpriseIdentityStore,IncidentStore,OperatorPortal,LivePagingSession,ApiSecurity};
$nonce=base64_encode(random_bytes(18));OperatorPortal::headers($nonce);header('Referrer-Policy: no-referrer');$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$csrf='';$content='';
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)){http_response_code(405);throw new DomainException('Use GET or POST.');}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192||$_GET){http_response_code(400);throw new DomainException('Invalid subscriber request.');}
    $settings=LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');$config=SubscriberConfig::normalize($settings['subscriber_browser']??[]);
    if(!$config['enabled']){http_response_code(404);throw new DomainException('Subscriber browser access is unavailable.');}
    $network=ApiSecurity::requestNetwork($_SERVER,$settings);if(!$network['https']||$network['error']!==''){http_response_code(403);throw new DomainException('Subscriber access requires HTTPS.');}
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');session_name('SLSSUB');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/mass-notify/subscriber.php','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);if(!session_start()){throw new RuntimeException('Session unavailable.');}
    $csrf=OperatorPortal::csrf($_SESSION);$service=new SubscriberService(new EnterpriseIdentityStore('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-state'),new IncidentStore());$now=time();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // This page uses no-referrer to hide emailed proof fragments. Browsers
        // may serialize that Origin as null; the same-origin fetch metadata
        // and unpredictable session CSRF token still remain mandatory.
        $server=$_SERVER;if(($server['HTTP_ORIGIN']??'')==='null'&&($server['HTTP_SEC_FETCH_SITE']??'')==='same-origin'){unset($server['HTTP_ORIGIN']);}
        if(!OperatorPortal::validPost($server,$_SESSION,$_POST['portal_csrf']??null)){http_response_code(403);throw new DomainException('The security token expired. Reload this page.');}
        $action=$_POST['subscriber_action']??'';
        if($action==='verify'){
            if(array_diff(array_keys($_POST),['subscriber_action','portal_csrf','token'])||!is_string($_POST['token']??null)){throw new DomainException('Invalid verification request.');}
            $retry=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/subscriber-verify-rate.json',$network['ip'],$network['ip'],$now);if($retry){http_response_code(429);header('Retry-After: '.$retry);throw new DomainException('Too many verification attempts.');}
            $binding=$service->verify($settings,$_POST['token'],$now);unset($_POST['token']);if(!session_regenerate_id(true)){throw new RuntimeException('Session rotation failed.');}$_SESSION=[];$_SESSION['subscriber_identity']=$binding;OperatorPortal::csrf($_SESSION);
        }elseif($action==='respond'){
            if(array_diff(array_keys($_POST),['subscriber_action','portal_csrf','request_id','response','note'])||!is_array($_SESSION['subscriber_identity']??null)){throw new DomainException('Your subscriber session expired.');}
            $retry=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/subscriber-response-rate.json',$network['ip'],$_SESSION['subscriber_identity']['subscriber_id'],$now);if($retry){http_response_code(429);header('Retry-After: '.$retry);throw new DomainException('Too many response attempts.');}
            $service->respond($settings,$_SESSION['subscriber_identity'],['request_id'=>$_POST['request_id']??'','response'=>$_POST['response']??'','note'=>$_POST['note']??''],$now);$_SESSION['subscriber_notice']='Your human response was recorded.';
        }elseif($action==='logout'){$_SESSION=[];session_regenerate_id(true);}
        else{throw new DomainException('Unsupported subscriber action.');}
        session_write_close();header('Location: /mass-notify/subscriber.php',true,303);exit;
    }
    $binding=$_SESSION['subscriber_identity']??null;
    if(is_array($binding)){
        $current=$service->current($settings,$binding,$now);$content='<section class="portal-login"><h1>'.$e($current['title']).'</h1><p>Verified email for '.$e($current['person']['name']).'</p><p>'.$e($current['message']).'</p>';
        if(isset($_SESSION['subscriber_notice'])){$content.='<p role="status">'.$e($_SESSION['subscriber_notice']).'</p>';unset($_SESSION['subscriber_notice']);}
        $labels=['received'=>'Received','safe'=>'I am safe','needs_assistance'=>'I need help'];if($current['response']){$content.='<p>Your response: <strong>'.$e($labels[$current['response']['response']]??'Recorded').'</strong></p>';}
        if($current['state']==='open'){$content.='<form method="post"><input type="hidden" name="subscriber_action" value="respond"><input type="hidden" name="portal_csrf" value="'.$e($csrf).'"><input type="hidden" name="request_id" value="'.bin2hex(random_bytes(16)).'"><label>Optional note<textarea name="note" maxlength="500"></textarea></label>';foreach($labels as $value=>$label){$content.='<button type="submit" class="btn btn-primary" name="response" value="'.$value.'">'.$e($label).'</button> ';}$content.='</form><p>These are human responses. A delivery receipt does not establish that you are safe. For urgent help, follow your organization’s emergency procedures.</p>';}
        else{$content.='<p>This incident is closed; responses are no longer accepted.</p>';}$content.='<form method="post"><input type="hidden" name="subscriber_action" value="logout"><input type="hidden" name="portal_csrf" value="'.$e($csrf).'"><button type="submit">Sign out</button></form></section>';
    }else{
        $content='<section class="portal-login"><h1>Incident response</h1><p>Open the verification link emailed by your administrator. It grants access only to your assigned incident participant and expires promptly.</p></section>';
        $content.='<script>(()=>{const m=/^#verify=([a-f0-9]{64})$/.exec(location.hash);if(!m)return;history.replaceState(null,"",location.pathname);const f=document.createElement("form");f.method="POST";f.action="/mass-notify/subscriber.php";for(const [name,value] of Object.entries({subscriber_action:"verify",portal_csrf:'.json_encode($csrf).',token:m[1]})){const i=document.createElement("input");i.type="hidden";i.name=name;i.value=value;f.append(i);}document.body.append(f);f.submit();})();</script>';
    }
}catch(DomainException|InvalidArgumentException|JsonException $error){$status=http_response_code();http_response_code(is_int($status)&&$status>=400?$status:400);$content='<section class="portal-login"><h1>Incident response</h1><p class="portal-error" role="alert">'.$e($error->getMessage()).'</p></section>';}
catch(Throwable $error){http_response_code(503);$content='<section class="portal-login"><h1>Incident response unavailable</h1><p>Ask an administrator to check protected subscriber and incident storage.</p></section>';}
finally{if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}}
header('Content-Type: text/html; charset=utf-8');echo OperatorPortal::document($content,$nonce,[],$csrf,'subscriber');
