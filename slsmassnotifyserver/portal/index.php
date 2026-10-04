<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once '/var/www/html/admin/modules/slsmassnotifyserver/OperatorPortal.php';
use SLS\MassNotify\{OperatorPortal, OperatorLoginRate, LivePagingSession, ApiSecurity};

$nonce=base64_encode(random_bytes(18)); OperatorPortal::headers($nonce);
$module=null; $principal=[]; $content=''; $csrf=''; $view='operations';
$json=(($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['slsmassnotifyserver_action']));
$reply=static function(int $status,array $body): void {
    unset($_SESSION['AMP_user'],$GLOBALS['sls_operator_portal_principal']);
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit;
};
$escape=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); throw new DomainException('Use GET or POST to open the operator portal.'); }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>98304) { http_response_code(413); throw new DomainException('The request exceeds the portal size limit.'); }
    $settings=LivePagingSession::loadSettings('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
    $network=ApiSecurity::requestNetwork($_SERVER,$settings);
    if (!$network['https'] || $network['error']!=='') { http_response_code(403); throw new DomainException('Open the operator portal using HTTPS.'); }
    // Block before session creation, password work, PBX bootstrap or actions.
    if (!\SLS\MassNotify\OperatorAccess::normalize($settings['operator_access']??[])['portal_enabled']) {
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') { $reply(403,['success'=>false,'message'=>'The operator portal has not been enabled.']); }
        header('Content-Type: text/html; charset=utf-8');
        echo OperatorPortal::document('<section class="portal-login"><div class="portal-login-heading"><i class="fa fa-lock" aria-hidden="true"></i><h1>Operator portal disabled</h1><p>This feature has not been enabled.</p></div><p class="portal-login-note">A PBX administrator can enable it in SLS Operator Access.</p></section>',$nonce,[],'','disabled');
        exit;
    }
    if (session_status()!==PHP_SESSION_NONE) { throw new RuntimeException('portal_session_already_started'); }
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.use_trans_sid','0');
    session_name(OperatorPortal::COOKIE);
    session_set_cookie_params(['lifetime'=>0,'path'=>OperatorPortal::BASE,'secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) { throw new RuntimeException('portal_session_unavailable'); }
    unset($_SESSION['AMP_user']); // Never accept a PBX session or serialized user here.
    $csrf=OperatorPortal::csrf($_SESSION);
    $requestedView=$_GET['view'] ?? 'operations';
    if (!is_string($requestedView) || !in_array($requestedView,['operations','access','forgot'],true) || array_diff(array_keys($_GET),['view','cursor'])) {
        http_response_code(404); throw new DomainException('That operator page does not exist.');
    }
    $view=$requestedView;
    $post=($_SERVER['REQUEST_METHOD'] ?? '')==='POST';
    if ($post && !OperatorPortal::validPost($_SERVER,$_SESSION,$_POST['portal_csrf'] ?? $_POST['slsmassnotifyserver_csrf'] ?? null)) {
        http_response_code(403); throw new DomainException('The security token expired. Reload the page before submitting.');
    }
    if ($post && ($_POST['portal_action'] ?? '')==='logout') {
        $_SESSION=[]; session_regenerate_id(true);
        header('Location: '.OperatorPortal::BASE, true,303); exit;
    }
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true,'include_compress'=>false];
    $restrict_mods=true;
    require '/etc/freepbx.conf';
    require_once '/var/www/html/admin/libraries/ampuser.class.php';
    $module=FreePBX::create()->Slsmassnotifyserver;
    $passwordWork=static fn(callable $work)=>OperatorPortal::passwordWork('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-password-work',$work);
    $failurePath='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-login-failures.json';
    $generalRatePath='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-login-rate.json';
    $error=''; $now=time();
    $action=$post?($_POST['portal_action']??''):'';
    $finish=static function(array $account) use($now,$module): void {
        if (!\SLS\MassNotify\OperatorAuth::ready($account)) { throw new DomainException('Finish your password and authenticator setup before signing in.'); }
        if (!session_regenerate_id(true)) { throw new RuntimeException('portal_session_rotation_failed'); }
        $_SESSION=[]; $_SESSION['portal_identity']=OperatorPortal::binding($account,$now); OperatorPortal::csrf($_SESSION);
        $module->operatorSecurityEvent('operator_sign_in_completed',$account);
        header('Location: '.OperatorPortal::BASE,true,303); exit;
    };
    if ($action==='request_reset') {
        $started=microtime(true);
        try {
            if (array_diff(array_keys($_POST),['portal_action','portal_csrf','username','email'])
                || !is_string($_POST['username']??null) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D',trim($_POST['username']))
                || !is_string($_POST['email']??null)) { throw new DomainException('Enter your operator username and registered email address.'); }
            $retry=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-recovery-rate.json',$network['ip'],trim($_POST['username']),time());
            if ($retry) { header('Retry-After: '.$retry); http_response_code(429); throw new DomainException('Too many recovery requests. Try again later or contact an administrator.'); }
            // Validate the form identically before any account lookup. A mail
            // or audit fault must not reveal whether this login exists.
            \SLS\MassNotify\OperatorRecovery::email($_POST['email']);
            try{$module->requestOperatorPasswordRecovery(trim($_POST['username']),$_POST['email']);}
            catch(Throwable $failure){/* The protected audit/mail evidence is for administrators. */}
        } finally {
            // Keep the same response floor for unknown and exhausted accounts.
            $remaining=3-(microtime(true)-$started);if($remaining>0){usleep((int)($remaining*1000000));}
        }
        $_SESSION['portal_notice']='If this enabled login and registered email match and recovery is available, instructions will be sent. After two emails or two failed recovery attempts, contact an administrator for a reset link.';
        header('Location: '.OperatorPortal::BASE.'?view=forgot',true,303);exit;
    }
    if ($action==='begin_reset') {
        if(array_diff(array_keys($_POST),['portal_action','portal_csrf','reset_token'])||!is_string($_POST['reset_token']??null)){throw new DomainException('This reset link is invalid.');}
        $retry=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-reset-rate.json',$network['ip'],$network['ip'],time());
        if($retry){header('Retry-After: '.$retry);http_response_code(429);throw new DomainException('Too many reset-link attempts. Try again later.');}
        $reset=$module->beginOperatorPasswordReset($_POST['reset_token']);unset($_POST['reset_token']);
        if(!session_regenerate_id(true)){throw new RuntimeException('portal_session_rotation_failed');}
        $_SESSION=[];$_SESSION['portal_reset']=$reset;OperatorPortal::csrf($_SESSION);
        header('Location: '.OperatorPortal::BASE,true,303);exit;
    }
    if ($action==='reset_password') {
        if(array_diff(array_keys($_POST),['portal_action','portal_csrf','new_password','confirm_password','code'])
            ||!is_array($_SESSION['portal_reset']??null)||!is_string($_POST['new_password']??null)||strlen($_POST['new_password'])>128
            ||!is_string($_POST['confirm_password']??null)||!hash_equals($_POST['new_password'],$_POST['confirm_password'])
            ||!is_string($_POST['code']??null)||strlen($_POST['code'])>35){throw new DomainException('The reset form is invalid or the passwords do not match.');}
        try {
            $result=$passwordWork(static fn()=>$module->completeOperatorPasswordReset($_SESSION['portal_reset'],$_POST['new_password'],$_POST['code']));
            if(function_exists('sodium_memzero')){sodium_memzero($_POST['new_password']);}unset($_POST['new_password'],$_POST['confirm_password'],$_POST['code']);
            $notice='Password changed. Sign in with your new password and the next authenticator code.';
            try{OperatorLoginRate::recovered($failurePath,$network['ip'],time());OperatorPortal::recoveredLogin($generalRatePath,$network['ip'],$result['username'],time());}
            catch(Throwable $e){$notice='Password changed. The address limit could not be cleared; ask an administrator to check sign-in rate storage.';}
            if(!session_regenerate_id(true)){throw new RuntimeException('portal_session_rotation_failed');}
            $_SESSION=[];$_SESSION['portal_notice']=$notice;OperatorPortal::csrf($_SESSION);
            header('Location: '.OperatorPortal::BASE,true,303);exit;
        }catch(DomainException $e){$error=$e->getMessage();http_response_code($e->getCode()===429?429:400);}
    }
    if ($action==='login') {
        if (array_diff(array_keys($_POST),['portal_action','portal_csrf','username','password'])) { throw new DomainException('The sign-in form contains unsupported fields.'); }
        $username=is_string($_POST['username']??null)?trim($_POST['username']):null; $password=$_POST['password']??null;
        if (!is_string($username) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D',$username)
            || !is_string($password) || strlen($password)<1 || strlen($password)>128) { throw new DomainException('Enter your operator username and password.'); }
        $retry=OperatorPortal::loginAttempt($generalRatePath,$network['ip'],$username,$now);
        if ($retry) { header('Retry-After: '.$retry); http_response_code(429); throw new DomainException('Too many sign-in attempts. Try again in '.$retry.' seconds.'); }
        $grant=OperatorLoginRate::begin($failurePath,$network['ip'],time());
        if($grant['retry_after']){header('Retry-After: '.$grant['retry_after']);http_response_code(429);throw new DomainException('This address reached the sign-in limit. Try again in '.$grant['retry_after'].' seconds, use Forgot password, or ask an administrator for a reset link.');}
        $accepted=false;$checked=false;$account=null;
        try {
            $account=$module->portalAccount($username);$dummy=\SLS\MassNotify\OperatorAuth::dummyHash();
            $accepted=$passwordWork(static fn()=>\SLS\MassNotify\OperatorAuth::verify($password,$account['auth']['password_hash']??$dummy)) && $account!==null;$checked=true;
        } finally {OperatorLoginRate::finish($failurePath,$network['ip'],$grant['reservation'],$checked&&!$accepted,time());}
        $module->operatorSecurityEvent($accepted?'operator_login_password_accepted':($account?'operator_login_failed_password':'operator_login_unknown_or_disabled'),$account,$accepted);
        if (function_exists('sodium_memzero')) { sodium_memzero($password); } unset($_POST['password'],$password);
        if ($accepted) {
            if (!session_regenerate_id(true)) { throw new RuntimeException('portal_session_rotation_failed'); }
            $_SESSION=[]; $_SESSION['portal_pending']=['id'=>$account['id'],'username'=>$account['username'],
                'identity'=>\SLS\MassNotify\OperatorAuth::identity($account),'started_at'=>$now,'mfa_passed'=>false];
            OperatorPortal::csrf($_SESSION); header('Location: '.OperatorPortal::BASE,true,303); exit;
        }
        unset($_SESSION['portal_pending'],$_SESSION['portal_identity']);
        $error='Sign-in failed or this operator login is disabled.'; http_response_code(401);
    }
    $pending=$_SESSION['portal_pending']??null; $stage='login'; $account=null;
    if (is_array($pending)) {
        $account=$module->portalAccount($pending['username']??'');
        if (!$account || $account['id']!==($pending['id']??'') || !is_int($pending['started_at']??null)
            || $now<$pending['started_at'] || $now-$pending['started_at']>=300
            || !hash_equals(\SLS\MassNotify\OperatorAuth::identity($account),$pending['identity']??'')) {
            unset($_SESSION['portal_pending']); $pending=null; $account=null;
            $error='Sign-in setup expired or this login changed. Start again.'; http_response_code(401);
        } else {
            $auth=$account['auth'];
            $stage=!empty($pending['recovery_codes'])?'recovery':
                ($auth['totp_secret_enc']!=='' && empty($pending['mfa_passed'])?'totp':($auth['force_password_change']?'password':($auth['totp_secret_enc']===''?'enroll':'complete')));
        }
    }
    if (in_array($action,['totp','password','enroll','recovery'],true)) {
        $fields=['totp'=>['code'],'password'=>['new_password','confirm_password'],'enroll'=>['code'],'recovery'=>[]][$action];
        if (!$pending || $stage!==$action || array_diff(array_keys($_POST),array_merge(['portal_action','portal_csrf'],$fields))) { throw new DomainException('This sign-in step expired. Start again.'); }
        // OTP guesses are bounded independently from the password stage.
        $retry=OperatorPortal::loginAttempt('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/operator-mfa-rate.json',$network['ip'],$account['username'],$now);
        if ($retry) { header('Retry-After: '.$retry); http_response_code(429); throw new DomainException('Too many verification attempts. Try again in '.$retry.' seconds.'); }
        try {
            if ($action==='recovery') { $finish($account); }
            $token=$_POST['code']??''; $password=$_POST['new_password']??'';
            if (!is_string($token) || strlen($token)>35 || !is_string($password) || strlen($password)>128) { throw new DomainException('The verification form has an invalid value.'); }
            if ($action==='password' && (!is_string($_POST['confirm_password']??null) || !hash_equals($password,$_POST['confirm_password']))) { throw new DomainException('The new passwords do not match.'); }
            $update=static fn()=>$module->operatorAuthUpdate($account['id'],$pending['identity'],$action,$token,$pending['enrollment_secret']??'',$password);
            $updated=$action==='password'?$passwordWork($update):$update();
            if (function_exists('sodium_memzero') && $password!=='') { sodium_memzero($password); }
            unset($_POST['new_password'],$_POST['confirm_password'],$password); $account=$updated['account'];
            $_SESSION['portal_pending']['identity']=\SLS\MassNotify\OperatorAuth::identity($account);
            if ($action==='totp') { $_SESSION['portal_pending']['mfa_passed']=true; }
            if ($action==='enroll') {
                $_SESSION['portal_pending']['mfa_passed']=true; $_SESSION['portal_pending']['recovery_codes']=$updated['recovery_codes'];
                unset($_SESSION['portal_pending']['enrollment_secret']);
            }
            header('Location: '.OperatorPortal::BASE,true,303); exit;
        } catch (DomainException $e) { $module->operatorSecurityEvent('operator_authenticator_failed',$account,false);$error=$e->getMessage(); http_response_code($e->getCode()===429?429:401); }
    }
    if ($stage==='complete' && $account) { $finish($account); }
    if($view==='forgot'){$stage='forgot';}
    if(is_array($_SESSION['portal_reset']??null)){
        $reset=$_SESSION['portal_reset'];
        if(!is_int($reset['started_at']??null)||$reset['started_at']>$now||$now-$reset['started_at']>=1800){unset($_SESSION['portal_reset']);$error='The reset form expired. Open the reset link again.';}
        else{$stage='reset';}
    }
    $binding=$_SESSION['portal_identity']??[];
    if (is_array($binding) && ($binding['source']??'')==='portal') {
        $account=$module->portalAccount($binding['username']??''); $timeout=(int)FreePBX::Config()->get('SESSION_TIMEOUT');
        $federationCurrent=!isset($binding['enterprise_identity']) || \SLS\MassNotify\EnterpriseIdentity::current($settings,$binding['enterprise_identity'],$account['id']??'',$now);
        if ($federationCurrent && $account && \SLS\MassNotify\OperatorAuth::ready($account) && OperatorPortal::current($binding,$account,$timeout>0?$timeout:1800,$now)) {
            $principal=\SLS\MassNotify\OperatorAccess::principal($settings,$account['id'])??[];
            if ($principal && hash_equals($principal['identity'],$binding['identity'])) {
                $GLOBALS['sls_operator_portal_principal']=$principal; $_SESSION['portal_identity']['last_activity']=$now;
                try { $principal=$module->currentOperator(); } catch (Throwable $e) { $principal=[]; }
            }
        }
        if (!$principal) { unset($_SESSION['portal_identity'],$GLOBALS['sls_operator_portal_principal']); }
    }
    if (!$principal) {
        if ($json) { $reply(401,['success'=>false,'message'=>'Your operator session expired or access changed. Sign in again.']); }
        $headings=['login'=>['Sign in to Mass Notify','Use the operator login created by your PBX administrator.'],
            'password'=>['Set your personal password','Use at least 15 characters. A passphrase works well.'],
            'totp'=>['Verify your identity','Enter the current code from your authenticator, or a recovery code.'],
            'enroll'=>['Set up your authenticator','Add a new account in your authenticator app using manual setup.'],
            'recovery'=>['Save your recovery codes','Each code works once. Store these privately before continuing.'],
            'forgot'=>['Recover your operator login','Enter your username and the email registered by your administrator.'],
            'reset'=>['Reset your password','Use a new passphrase of at least 15 characters and the current code from your enrolled authenticator.']];
        [$heading,$intro]=$headings[$stage];
        $content='<section class="portal-login"><div class="portal-login-heading"><i class="fa fa-shield" aria-hidden="true"></i><h1>'.$escape($heading).'</h1><p>'.$escape($intro).'</p></div>';
        if ($error) { $content.='<p class="portal-error" role="alert">'.$escape($error).'</p>'; }
        if (is_string($_SESSION['portal_notice']??null)) { $content.='<p class="portal-notice" role="status">'.$escape($_SESSION['portal_notice']).'</p>';unset($_SESSION['portal_notice']); }
        $formAction=['forgot'=>'request_reset','reset'=>'reset_password'][$stage]??$stage;
        $content.='<form method="post" action="'.OperatorPortal::BASE.'"><input type="hidden" name="portal_action" value="'.$escape($formAction).'"><input type="hidden" name="portal_csrf" value="'.$escape($csrf).'">';
        if ($stage==='login') {
            $content.='<label>Username<input name="username" autocomplete="username" maxlength="80" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" maxlength="128" required></label>';
        } elseif ($stage==='forgot') {
            $content.='<label>Username<input name="username" autocomplete="username" maxlength="80" pattern="[A-Za-z0-9_.@-]+" required autofocus></label><label>Registered email<input type="email" name="email" autocomplete="email" maxlength="254" required></label><p class="portal-login-note">Recovery emails stop after two requests or two failed recovery attempts. An administrator can issue a 24-hour reset link. Your existing authenticator is required to change the password.</p>';
        } elseif ($stage==='password'||$stage==='reset') {
            $content.='<label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="15" maxlength="128" required autofocus></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" minlength="15" maxlength="128" required></label>';
            if($stage==='reset'){$content.='<label>Current authenticator code<input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><p class="portal-login-note">Recovery codes cannot replace your authenticator for password resets. Two incorrect codes revoke this link.</p>';}
        } elseif ($stage==='totp') {
            $content.='<label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" maxlength="35" required autofocus spellcheck="false"></label>';
        } elseif ($stage==='enroll') {
            $_SESSION['portal_pending']['enrollment_secret']??=\SLS\MassNotify\OperatorAuth::secret();
            $content.='<dl class="portal-setup"><dt>Account</dt><dd>SLS Mass Notify · '.$escape($pending['username']).'</dd><dt>Type</dt><dd>Time based · 6 digits · 30 seconds</dd><dt>Setup key</dt><dd><code class="portal-secret">'.$escape($_SESSION['portal_pending']['enrollment_secret']).'</code></dd></dl><label>Authenticator code<input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus></label>';
        } else {
            $content.='<ul class="portal-recovery">'; foreach ($pending['recovery_codes'] as $code) { $content.='<li><code>'.$escape($code).'</code></li>'; } $content.='</ul>';
        }
        $button=['recovery'=>'I saved my codes','login'=>'Sign in','forgot'=>'Send recovery email','reset'=>'Save new password'][$stage]??'Continue';
        $content.='<button class="btn btn-primary" type="submit">'.$escape($button).' <i class="fa fa-arrow-right" aria-hidden="true"></i></button></form>';
        if ($stage==='login') { $content.='<p class="portal-login-note"><a href="'.OperatorPortal::BASE.'?view=forgot">Forgot password?</a></p><p class="portal-login-note">Create and manage logins in the PBX admin panel → SLS Operator Access. Portal logins do not grant access to FreePBX or UCP.</p>'; }
        elseif($stage==='forgot'){$content.='<p class="portal-login-note"><a href="'.OperatorPortal::BASE.'">Back to sign in</a></p>';}
        else { $content.='<form method="post" action="'.OperatorPortal::BASE.'"><input type="hidden" name="portal_action" value="logout"><input type="hidden" name="portal_csrf" value="'.$escape($csrf).'"><button class="btn btn-default" type="submit">Start again</button></form>'; }
        if($stage==='login'){
            $federation=\SLS\MassNotify\EnterpriseIdentityConfig::normalize($settings['enterprise_identity']??[]);
            if($federation['enabled']){foreach($federation['providers'] as $provider){if(!$provider['enabled']){continue;}$content.='<form method="post" action="'.OperatorPortal::BASE.'sso.php"><input type="hidden" name="portal_csrf" value="'.$escape($csrf).'"><input type="hidden" name="provider_id" value="'.$escape($provider['id']).'"><button type="submit" class="btn btn-default">Sign in with '.$escape($provider['name']).'</button></form>';}}
        }
        $content.='</section>';
    } else {
        if ($view==='access' && $principal['operator_role']!=='administrator') { http_response_code(403); throw new DomainException('Only an SLS administrator can manage operator access.'); }
        if ($json) {
            $action=$_POST['slsmassnotifyserver_action']; $raw=$_POST['payload'] ?? null;
            if (!is_string($action) || !is_string($raw) || strlen($raw)>65536) { throw new DomainException('The request is missing or exceeds 64 KiB.'); }
            if (!(json_decode($raw,false,16,JSON_THROW_ON_ERROR) instanceof \stdClass)) { throw new DomainException('The portal requires an object request.'); }
            $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if ($view==='access') {
                if(!in_array($action,['save_access','generate_reset_link','revoke_reset_link'],true)){throw new DomainException('Unsupported operator-access action.');}
                $result=$action==='save_access'?$module->saveOperatorAccess($input):$module->manageOperatorPasswordReset($action,$input);
            } else { $result=$module->operatorAction($action,$input); }
            $reply(200,$result);
        }
        if ($post) { throw new DomainException('Unsupported operator portal action.'); }
        $endpoint=OperatorPortal::BASE.'?view='.$view;
        $content=$view==='access'?$module->renderOperatorsPage($endpoint,$csrf)
            :$module->renderOperationsPage(is_string($_GET['cursor'] ?? null)?$_GET['cursor']:'',$endpoint,$csrf);
    }
} catch (DomainException|InvalidArgumentException|JsonException $error) {
    $status=http_response_code(); if (!is_int($status) || $status<400) { $status=400; }
    $message=$error instanceof JsonException?'The request is invalid. Reload the page.':$error->getMessage();
    if ($json) { $reply($status,['success'=>false,'message'=>$message]); }
    http_response_code($status); $content='<section class="portal-login"><h1>Unable to open this page</h1><p class="portal-error" role="alert">'.$escape($message).'</p><a class="btn btn-default" href="'.OperatorPortal::BASE.'">Return to Operations</a><p class="portal-login-note"><a href="'.OperatorPortal::BASE.'?view=forgot">Forgot password?</a></p></section>';
} catch (Throwable $error) {
    if ($json) { $reply(503,['success'=>false,'message'=>'The operator portal could not confirm completion. Check its runtime and protected storage before retrying.']); }
    http_response_code(503); header('Retry-After: 5');
    $content='<section class="portal-login"><h1>Operator portal unavailable</h1><p>The PBX runtime or protected session storage could not be accessed. Ask your administrator to check module readiness.</p></section>';
} finally {
    unset($_SESSION['AMP_user'],$GLOBALS['sls_operator_portal_principal']);
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
}
header('Content-Type: text/html; charset=utf-8');
if(preg_match('/^[a-f0-9]{64}$/D',$csrf)){
    // A fragment is never sent to the HTTP server or included in access logs.
    // Remove it before posting the credential to this same-origin CSRF endpoint.
    $content.='<script>(()=>{const match=/^#reset=([a-f0-9]{64})$/.exec(location.hash);if(!match)return;history.replaceState(null,"",location.pathname);const form=document.createElement("form");form.method="POST";form.action='.json_encode(OperatorPortal::BASE,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';for(const [name,value] of Object.entries({portal_action:"begin_reset",portal_csrf:'.json_encode($csrf).',reset_token:match[1]})){const field=document.createElement("input");field.type="hidden";field.name=name;field.value=value;form.append(field);}document.body.append(form);form.submit();})();</script>';
}
echo OperatorPortal::document($content,$nonce,$principal,$csrf,$view);
