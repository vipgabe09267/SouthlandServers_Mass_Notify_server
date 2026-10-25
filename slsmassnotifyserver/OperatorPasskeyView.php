<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/PasskeyConfig.php';

final class OperatorPasskeyView
{
    public static function render(array $settings,array $account,string $csrf,string $nonce): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if(($settings['operator_access']['passkeys_enabled']??false)!==true){return '<section class="portal-login"><h1>Operator security</h1><p>Passkeys have not been enabled. Continue using your password and authenticator code.</p></section>';}
        $out='<section class="portal-login" id="sls-passkeys" data-csrf="'.$e($csrf).'" data-mode="manage"><h1>Operator security</h1><p><i class="fa fa-flask" aria-hidden="true"></i> Labs · Passkeys</p><p>A passkey can replace the authenticator code after your password. Your authenticator and recovery codes remain available. Adding or removing a key signs other sessions out.</p><label>Fresh authenticator code<input id="passkey-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"></label><label>New passkey name<input id="passkey-name" maxlength="60" value="My security key"></label><button type="button" class="btn btn-primary" id="passkey-add">Enroll a passkey</button><p id="passkey-status" role="status" aria-live="polite"></p><ul>';
        foreach(PasskeyConfig::publicRows($account['auth']['passkeys']??[]) as $key){
            $out.='<li><strong>'.$e($key['name']).'</strong> · added '.$e(gmdate('Y-m-d',$key['created_at'])).' · last used '.($key['last_used_at']?$e(gmdate('Y-m-d H:i \U\T\C',$key['last_used_at'])):'never').'<button type="button" class="btn btn-default" data-passkey-revoke="'.$e($key['credential_id']).'">Revoke</button></li>';
        }
        $out.='</ul><p>Management consumes a current six-digit authenticator code. If you cancel enrollment, wait for the next code before trying again. Credential data stays in encrypted SLS configuration; no private key leaves your authenticator.</p></section><script nonce="'.$e($nonce).'" src="/mass-notify/passkeys.js" defer></script>';
        return $out;
    }
}
