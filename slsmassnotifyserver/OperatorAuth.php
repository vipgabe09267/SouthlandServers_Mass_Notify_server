<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/OperatorRecovery.php';

/** Independent portal credentials. Authentication state lives in .config. */
final class OperatorAuth
{
    public const ACTIONS = ['view','send','schedule','roll_call'];
    private const ARGON = ['memory_cost'=>65536,'time_cost'=>4,'threads'=>1];

    /** Unknown logins still perform the password work supported by this PBX. */
    public static function dummyHash(): string
    {
        return in_array('argon2id',password_algos(),true)
            ? '$argon2id$v=19$m=65536,t=4,p=1$ei5nRE9Ld0xINUF2eXNHaA$4oDQOKe3LEVj569QFz79388f/PJkudSc402rH2bpC6c'
            : 'pbkdf2-sha256:600000:'.str_repeat('0',64).':'.str_repeat('0',64);
    }

    public static function password(string $password): string
    {
        if (!preg_match('//u',$password) || mb_strlen($password)<15 || strlen($password)>128 || preg_match('/\p{C}/u',$password)) {
            throw new \DomainException('Use a password of at least 15 characters and at most 128 bytes, without control characters.');
        }
        if (in_array('argon2id',password_algos(),true)) {
            return password_hash($password,PASSWORD_ARGON2ID,self::ARGON);
        }
        $salt=random_bytes(32);
        return 'pbkdf2-sha256:600000:'.bin2hex($salt).':'.hash_pbkdf2('sha256',$password,$salt,600000,64);
    }

    public static function validHash($hash): bool
    {
        if (!is_string($hash) || strlen($hash)>256) { return false; }
        if (preg_match('/^pbkdf2-sha256:600000:[a-f0-9]{64}:[a-f0-9]{64}$/D',$hash)) { return true; }
        $info=password_get_info($hash); $opts=$info['options']??[];
        return ($info['algoName']??'')==='argon2id' && ($opts['memory_cost']??0)===65536
            && ($opts['time_cost']??0)===4 && ($opts['threads']??0)===1;
    }

    public static function verify(string $password, string $hash): bool
    {
        if (!self::validHash($hash) || strlen($password)>128) { return false; }
        if (str_starts_with($hash,'pbkdf2-sha256:')) {
            $parts=explode(':',$hash);
            return hash_equals($parts[3],hash_pbkdf2('sha256',$password,hex2bin($parts[2]),600000,64));
        }
        return password_verify($password,$hash);
    }

    public static function normalize($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value),['password_hash','version','totp_secret_enc','totp_last_counter','recovery_hashes','force_password_change','password_recovery'])
            || !self::validHash($value['password_hash']??null) || !is_string($value['version']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$value['version']) || !is_bool($value['force_password_change']??null)
            || !is_string($value['totp_secret_enc']??null) || !preg_match('/^(?:|op1:[A-Za-z0-9+\/]{80})$/D',$value['totp_secret_enc'])
            || !is_int($value['totp_last_counter']??null) || $value['totp_last_counter']< -1 || $value['totp_last_counter']>PHP_INT_MAX-1
            || !is_array($value['recovery_hashes']??null) || !array_is_list($value['recovery_hashes']) || count($value['recovery_hashes'])>10) {
            throw new \DomainException('The operator authentication record is invalid. Reset its login in Operator Access.');
        }
        foreach ($value['recovery_hashes'] as $hash) { if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new \DomainException('Invalid operator recovery record.'); } }
        if (array_key_exists('password_recovery',$value)) { $value['password_recovery']=OperatorRecovery::normalize($value['password_recovery']); }
        return $value;
    }

    public static function create(string $password): array
    {
        return ['password_hash'=>self::password($password),'version'=>bin2hex(random_bytes(32)),
            'totp_secret_enc'=>'','totp_last_counter'=>-1,'recovery_hashes'=>[],'force_password_change'=>true];
    }

    public static function identity(array $account): string
    {
        $auth=self::normalize($account['auth']);
        return hash('sha256',json_encode([$account['id'],$account['source'],$account['username'],$auth['version'],$auth['password_hash'],$auth['totp_secret_enc'],$auth['force_password_change']],JSON_THROW_ON_ERROR));
    }

    public static function ready(array $account): bool
    {
        try { $auth=self::normalize($account['auth']??null); return $auth['totp_secret_enc']!=='' && !$auth['force_password_change']; }
        catch (\Throwable $e) { return false; }
    }

    public static function secret(): string { return self::base32(random_bytes(20)); }
    public static function base32(string $bytes): string
    {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
        foreach (str_split($bytes) as $c) { $bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT); }
        foreach (str_split($bits,5) as $b) { $out.=$alphabet[bindec(str_pad($b,5,'0'))]; }
        return $out;
    }
    private static function decode(string $secret): string
    {
        if (!preg_match('/^[A-Z2-7]{32}$/D',$secret)) { throw new \DomainException('Invalid authenticator secret.'); }
        $bits=''; foreach (str_split($secret) as $c) { $bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c)),5,'0',STR_PAD_LEFT); }
        $out=''; foreach (str_split($bits,8) as $b) { $out.=chr(bindec($b)); } return $out;
    }
    public static function code(string $secret, int $counter, int $digits=6): string
    {
        if ($counter<0 || !in_array($digits,[6,8],true)) { throw new \DomainException('Invalid authenticator counter.'); }
        $hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),self::decode($secret),true);
        $offset=ord($hash[19])&15; $binary=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($binary%(10**$digits)),$digits,'0',STR_PAD_LEFT);
    }
    public static function counter(string $secret, string $code, int $now, int $last=-1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D',$code)) { return null; }
        $step=intdiv($now,30);
        foreach ([$step,$step-1,$step+1] as $counter) {
            if ($counter>$last && $counter>=0 && hash_equals(self::code($secret,$counter),$code)) { return $counter; }
        }
        return null;
    }

    private static function key(array $settings): string
    {
        $key=base64_decode($settings['desktop_auth_key']??'',true);
        if (!is_string($key) || strlen($key)!==32) { throw new \DomainException('The protected configuration encryption key is unavailable. Check module readiness before enrolling an authenticator.'); }
        return hash_hkdf('sha256',$key,32,'sls-operator-totp-v1');
    }
    public static function seal(string $secret, string $id, array $settings): string
    {
        self::decode($secret); $iv=random_bytes(12); $tag='';
        $cipher=openssl_encrypt($secret,'aes-256-gcm',self::key($settings),OPENSSL_RAW_DATA,$iv,$tag,"sls-operator-totp-v1\0".$id,16);
        if (!is_string($cipher)) { throw new \RuntimeException('operator_totp_encryption_failed'); }
        return 'op1:'.base64_encode($iv.$tag.$cipher);
    }
    public static function open(string $encrypted, string $id, array $settings): string
    {
        $raw=base64_decode(substr($encrypted,4),true);
        if (!str_starts_with($encrypted,'op1:') || !is_string($raw) || strlen($raw)!==60) { throw new \DomainException('The operator authenticator record cannot be decrypted. Ask an administrator to reset this login.'); }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key($settings),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),"sls-operator-totp-v1\0".$id);
        if (!is_string($plain)) { throw new \DomainException('The operator authenticator record cannot be decrypted. Ask an administrator to reset this login.'); }
        self::decode($plain); return $plain;
    }
    public static function recovery(): array
    {
        $codes=[]; for ($i=0;$i<10;$i++) { $codes[]=implode('-',str_split(bin2hex(random_bytes(16)),8)); }
        return ['codes'=>$codes,'hashes'=>array_map(static fn($c)=>hash('sha256',$c),$codes)];
    }
    public static function publicAccount(array $account): array
    {
        $auth=$account['auth']??null;
        $account['totp_enrolled']=is_array($auth) && $auth['totp_secret_enc']!=='';
        $account['recovery_remaining']=is_array($auth)?count($auth['recovery_hashes']):0;
        $account['password_change_required']=is_array($auth) && $auth['force_password_change'];
        if (is_array($auth)) {
            $state=OperatorRecovery::normalize($auth['password_recovery']??[]);
            $account['password_recovery']=['emails_remaining'=>2-$state['emails_sent'],
                'email_enabled'=>OperatorRecovery::available($state) && ($account['email']??'')!=='',
                'failed_attempts'=>$state['failed_attempts'], 'last_delivery'=>$state['last_delivery'],
                'reset_expires_at'=>$state['token']['expires_at']??null,
                'reset_active'=>$state['token']!==null && $state['token']['expires_at']>time() && $state['token']['version']===$auth['version']];
        }
        unset($account['identity'],$account['auth']); return $account;
    }
}
