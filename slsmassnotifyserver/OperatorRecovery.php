<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Only hashed reset credentials and bounded recovery evidence are portable. */
final class OperatorRecovery
{
    public const LIFETIME = 86400;

    public static function defaults(): array
    {
        return ['emails_sent'=>0, 'failed_attempts'=>0, 'token'=>null, 'last_delivery'=>'none'];
    }

    public static function normalize($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value),array_keys(self::defaults()))) {
            throw new \DomainException('Invalid operator password recovery record.');
        }
        $value=array_replace(self::defaults(),$value);
        foreach (['emails_sent','failed_attempts'] as $key) {
            if (!is_int($value[$key]) || $value[$key]<0 || $value[$key]>2) { throw new \DomainException('Invalid operator recovery counter.'); }
        }
        if (!in_array($value['last_delivery'],['none','reserved','accepted','failed','unconfirmed'],true)) { throw new \DomainException('Invalid operator recovery delivery evidence.'); }
        if ($value['token']!==null) {
            $token=$value['token'];
            if (!is_array($token) || array_diff(array_keys($token),['hash','version','issued_at','expires_at','source','attempts'])
                || !is_string($token['hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$token['hash'])
                || !is_string($token['version']??null) || !preg_match('/^[a-f0-9]{64}$/D',$token['version'])
                || !is_int($token['issued_at']??null) || $token['issued_at']<0
                || !is_int($token['expires_at']??null) || $token['expires_at']-$token['issued_at']!==self::LIFETIME
                || !in_array($token['source']??null,['email','administrator'],true)
                || !is_int($token['attempts']??null) || $token['attempts']<0 || $token['attempts']>2) {
                throw new \DomainException('Invalid operator password reset credential.');
            }
        }
        return $value;
    }

    public static function email($value): string
    {
        if (!is_string($value) || strlen($value)>254 || $value!==trim($value)
            || ($value!=='' && (!filter_var($value,FILTER_VALIDATE_EMAIL) || preg_match('/[^\x21-\x7e]/',$value)))) {
            throw new \DomainException('Enter one valid recovery email address without a display name.');
        }
        return $value;
    }

    public static function available(array $state): bool
    {
        return $state['emails_sent']<2 && $state['failed_attempts']<2;
    }

    public static function issue(array $auth, string $source, int $now): array
    {
        if (!in_array($source,['email','administrator'],true)) { throw new \LogicException('Unsupported reset issuer.'); }
        $state=self::normalize($auth['password_recovery']??[]);
        if ($source==='email' && !self::available($state)) { throw new \DomainException('Contact an administrator for a password reset link.'); }
        $secret=bin2hex(random_bytes(32));
        $state['token']=['hash'=>hash('sha256',$secret),'version'=>$auth['version'],'issued_at'=>$now,
            'expires_at'=>$now+self::LIFETIME,'source'=>$source,'attempts'=>0];
        if ($source==='email') { $state['emails_sent']++; $state['last_delivery']='reserved'; }
        return ['state'=>$state,'secret'=>$secret];
    }

    public static function valid(array $auth, string $hash, int $now): bool
    {
        $token=self::normalize($auth['password_recovery']??[])['token'];
        return $token!==null && $token['attempts']<2 && $now>=$token['issued_at'] && $now<$token['expires_at']
            && hash_equals($token['hash'],$hash) && hash_equals($token['version'],$auth['version'])
            && $auth['totp_secret_enc']!=='' && !$auth['force_password_change'];
    }

    public static function failed(array $state): array
    {
        if ($state['token']===null) { throw new \LogicException('No reset credential to consume.'); }
        if ($state['token']['source']==='email') { $state['failed_attempts']=min(2,$state['failed_attempts']+1); }
        $state['token']['attempts']++;
        if ($state['token']['attempts']>=2 || ($state['token']['source']==='email' && !self::available($state) && $state['failed_attempts']>=2)) {
            $state['token']=null;
        }
        return $state;
    }
}
