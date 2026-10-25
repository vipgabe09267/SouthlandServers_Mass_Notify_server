<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/OperatorAccess.php';
require_once __DIR__.'/EnterpriseIdentity.php';
require_once __DIR__.'/OperatorLoginRate.php';
if (!class_exists('SlsAtomicJson', false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_atomic_json.php'; }
require_once __DIR__.'/bin/sls_mass_notify/sls_live_paging.php';

/** Separate web sessions, operator passwords, TOTP, and current SLS permissions. */
final class OperatorPortal
{
    public const COOKIE = 'SLSOPS';
    public const BASE = '/mass-notify/';

    public static function csrf(array &$session): string
    {
        if (!is_string($session['portal_csrf'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $session['portal_csrf'])) {
            $session['portal_csrf'] = bin2hex(random_bytes(32));
        }
        return $session['portal_csrf'];
    }

    public static function validPost(array $server, array $session, $token): bool
    {
        if (!is_string($token) || !is_string($session['portal_csrf'] ?? null)
            || !hash_equals($session['portal_csrf'], $token)) { return false; }
        if (in_array($server['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site'], true)) { return false; }
        if (isset($server['HTTP_ORIGIN'])) {
            if (!is_string($server['HTTP_ORIGIN']) || !is_string($server['HTTP_HOST'] ?? null)) { return false; }
            $origin = parse_url($server['HTTP_ORIGIN']); $host = parse_url('https://'.$server['HTTP_HOST']);
            if (!is_array($origin) || !is_array($host) || ($origin['scheme'] ?? '') !== 'https'
                || strtolower($origin['host'] ?? '') !== strtolower($host['host'] ?? '')
                || ($origin['port'] ?? 443) !== ($host['port'] ?? 443)
                || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])
                || isset($host['user']) || isset($host['pass']) || isset($host['query']) || isset($host['fragment'])
                || !in_array($host['path'] ?? '', ['', '/'], true)
                || !in_array($origin['path'] ?? '', ['', '/'], true)) { return false; }
        }
        return true;
    }

    public static function binding(array $identity, int $now): array
    {
        return ['username'=>$identity['username'], 'source'=>$identity['mode']??$identity['source'], 'identity'=>OperatorAccess::identity($identity),
            'signed_in_at'=>$now, 'last_activity'=>$now];
    }

    public static function current(array $binding, array $identity, int $timeout, int $now): bool
    {
        try {
            return OperatorAccess::activeAccount($identity, $now) && is_int($binding['signed_in_at'] ?? null) && is_int($binding['last_activity'] ?? null)
                && $now >= $binding['signed_in_at'] && $now >= $binding['last_activity']
                && $now - $binding['signed_in_at'] < 28800
                && $now - $binding['last_activity'] < min(28800, max(60, $timeout))
                && ($binding['username'] ?? '') === ($identity['username'] ?? '')
                && ($binding['source'] ?? '') === ($identity['mode'] ?? $identity['source'] ?? '')
                && is_string($binding['identity'] ?? null)
                && hash_equals($binding['identity'], OperatorAccess::identity($identity));
        } catch (\Throwable $error) { return false; }
    }

    /** Bound anonymous password work to two processes, protecting PBX memory. */
    public static function passwordWork(string $prefix, callable $work)
    {
        $parent=@lstat(dirname($prefix));
        if (!$parent || ($parent['mode']&0170000)!==0040000 || !in_array($parent['uid'],[0,posix_geteuid()],true)
            || realpath(dirname($prefix))!==dirname($prefix) || ($parent['mode']&0022)) { throw new \RuntimeException('portal_password_storage_unsafe'); }
        $safe=static fn(array $meta):bool=>($meta['mode']&0170000)===0100000&&!($meta['mode']&07027)
            &&$meta['uid']===posix_geteuid()&&$meta['nlink']===1&&$meta['size']===0;
        for ($slot=0;$slot<2;$slot++) {
            $path=$prefix.'-'.$slot.'.lock'; clearstatcache(true,$path); $before=@lstat($path);
            if($before&&(!$safe($before)||is_link($path))){throw new \RuntimeException('portal_password_storage_unsafe');}
            $mask=umask(0037);
            try {
                $handle=@fopen($path,$before===false?'x+b':'r+b');
                if (!$handle && $before===false) { clearstatcache(true,$path); $before=@lstat($path); if ($before) { if(!$safe($before)||is_link($path)){throw new \RuntimeException('portal_password_storage_unsafe');}$handle=@fopen($path,'r+b'); } }
            } finally { umask($mask); }
            if (!$handle) { throw new \RuntimeException('portal_password_storage_unavailable'); }
            try {
                $open=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
                if (!$open || !$named || ($open['mode']&0170000)!==0100000 || ($open['mode']&0027) || $open['nlink']!==1
                    || $open['uid']!==posix_geteuid() || $open['size']!==0 || $open['dev']!==$named['dev'] || $open['ino']!==$named['ino']
                    || is_link($path) || ($before && ($open['dev']!==$before['dev'] || $open['ino']!==$before['ino']))) { throw new \RuntimeException('portal_password_storage_unsafe'); }
                if (flock($handle,LOCK_EX|LOCK_NB)) {
                    clearstatcache(true,$path);$named=@lstat($path);$held=fstat($handle);
                    clearstatcache(true,dirname($prefix));$currentParent=@lstat(dirname($prefix));
                    if(!$named||!$held||!$safe($held)||!$safe($named)||is_link($path)
                        ||$held['dev']!==$named['dev']||$held['ino']!==$named['ino']
                        ||!$currentParent||$currentParent['dev']!==$parent['dev']||$currentParent['ino']!==$parent['ino']
                        ||($currentParent['mode']&0022)||realpath(dirname($prefix))!==dirname($prefix)){
                        throw new \RuntimeException('portal_password_storage_unsafe');
                    }
                    return $work();
                }
            } finally { fclose($handle); }
        }
        header('Retry-After: 1'); http_response_code(429);
        throw new \DomainException('Sign-in is busy. Try again in one second.',429);
    }

    /** Persisted before password checking; new cookies do not reset attempts. */
    public static function loginAttempt(string $path, string $ip, string $username, int $now): int
    {
        return self::loginBudget($path,$ip,$username,$now,false);
    }

    /** Clear only the recovered address/account; preserve global abuse protection. */
    public static function recoveredLogin(string $path, string $ip, string $username, int $now): void
    {
        self::loginBudget($path,$ip,$username,$now,true);
    }

    private static function loginBudget(string $path, string $ip, string $username, int $now, bool $clear): int
    {
        $packed=@inet_pton($ip);$canonical=$packed===false?false:@inet_ntop($packed);
        if ($now<0 || $now>253402300799 || !is_string($canonical) || strlen($username)>256
            || preg_match('/[\x00-\x1f\x7f]/',$username)) { throw new \InvalidArgumentException('portal_login_input_invalid'); }
        $ip=$canonical;
        return \SlsAtomicJson::transaction($path, [], static function (array $rows) use ($ip, $username, $now, $clear): array {
            if (count($rows)>512) { throw new \RuntimeException('portal_login_storage_invalid'); }
            foreach ($rows as $key=>$row) {
                if (!preg_match('/^(?:global|[iu]_[a-f0-9]{64})$/D', (string)$key) || !is_array($row)
                    || array_diff(array_keys($row),['start','count']) || !is_int($row['start'] ?? null) || $row['start']<0
                    || !is_int($row['count'] ?? null) || $row['count']<0 || $row['count']>100000
                    || $row['start']>$now+30) { throw new \RuntimeException('portal_login_storage_invalid'); }
                if ($now-$row['start']>=300) { unset($rows[$key]); }
            }
            $keys=['i_'.hash('sha256', $ip)=>50, 'global'=>300];
            // Provider handshakes have no authenticated account yet. An IP
            // must not also consume the smaller per-account budget.
            if($username!==''){$keys['u_'.hash('sha256',strtolower($username))]=10;}
            if ($clear) {
                unset($rows['i_'.hash('sha256',$ip)],$rows['u_'.hash('sha256',strtolower($username))]);
                $keys=[];
            }
            $retry=0;
            foreach ($keys as $key=>$limit) {
                $row=$rows[$key] ?? ['start'=>$now, 'count'=>0];
                if ($row['count'] >= $limit) { $retry=max($retry, max(1, 300-($now-$row['start']))); }
            }
            if ($retry) { return [$rows, $retry, false]; }
            if (count(array_unique(array_merge(array_keys($rows), array_keys($keys))))>512) {
                throw new \RuntimeException('portal_login_capacity_reached');
            }
            foreach ($keys as $key=>$limit) { $rows[$key] ??= ['start'=>$now,'count'=>0]; $rows[$key]['count']++; }
            return [$rows, 0, true];
        }, 65536, true, true);
    }

    public static function headers(string $nonce): void
    {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        // HTML form navigation uses an opaque Origin under no-referrer in
        // Chromium. Keep the same-origin Origin intact for the CSRF check.
        header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; worker-src 'self'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public static function document(string $content, string $nonce, array $principal, string $csrf, string $view): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $authenticated=$principal!==[];
        $navigation='';
        if ($authenticated) {
            $navigation='<nav aria-label="Operator portal"><a '.($view==='operations'?'aria-current="page" ':'').'href="'.self::BASE.'">Operations</a>';
            $navigation.='<a '.($view==='security'?'aria-current="page" ':'').'href="'.self::BASE.'?view=security">Security</a>';
            if (($principal['operator_role'] ?? '')==='administrator') {
                $navigation.='<a '.($view==='access'?'aria-current="page" ':'').'href="'.self::BASE.'?view=access">Operator access</a>';
            }
            $navigation.='</nav><div class="portal-session"><span>'.$e($principal['name'] ?? $principal['username']).'</span><form method="post" action="'.self::BASE.'"><input type="hidden" name="portal_action" value="logout"><input type="hidden" name="portal_csrf" value="'.$e($csrf).'"><button type="submit" class="btn btn-default">Sign out</button></form></div>';
        }
        $css=@file_get_contents(__DIR__.'/views/portal.css');if(!is_string($css)){throw new \RuntimeException('Operator portal stylesheet is unavailable.');}
        $branding=''; $mark='<span class="portal-mark" aria-hidden="true"><i class="fa fa-bullhorn"></i></span>';
        try {
            if (class_exists('FreePBX',false)) {
                $theme=\FreePBX::Config()->get('BRAND_CSS_CUSTOM'); $logo=\FreePBX::Config()->get('BRAND_IMAGE_TANGO_LEFT');
                if (is_string($theme) && preg_match('#^/(?!/)(?!.*\.\.)[A-Za-z0-9_./-]+\.css$#D',$theme)) { $branding='<link rel="stylesheet" href="'.$e($theme).'">'; }
                if (is_string($logo) && preg_match('#^/(?!/)(?!.*\.\.)[A-Za-z0-9_./-]+\.(?:png|svg|jpe?g)$#Di',$logo)) { $mark='<img class="portal-logo" src="'.$e($logo).'" alt="">'; }
            }
        } catch (\Throwable $ignored) { }
        $body='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>SLS Mass Notify · '.($authenticated?'Operations':'Sign in').'</title><link rel="stylesheet" href="/admin/assets/css/font-awesome.min-4.7.0.css"><style>'.$css.'</style>'.$branding.'</head><body><header class="portal-header"><a class="portal-brand" href="'.self::BASE.'">'.$mark.'<span>SLS Mass Notify<small>Operator portal</small></span></a>'.$navigation.'</header><main class="portal-main">'.$content.'</main><footer class="portal-footer">Southland Servers · Mass Notifications</footer></body></html>';
        $document=preg_replace_callback('/<(script|style)(?=[\s>])([^>]*)>/i', static function (array $tag) use ($e,$nonce): string {
            $attributes=preg_replace('/\s+nonce(?=\s|=|$)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s]+))?/i','',$tag[2]);
            return '<'.$tag[1].' nonce="'.$e($nonce).'"'.$attributes.'>';
        }, $body);
        if(!is_string($document)){throw new \RuntimeException('Operator portal document could not be rendered.');}
        return $document;
    }
}
