<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/OperatorAccess.php';
require_once __DIR__.'/EnterpriseIdentity.php';
require_once __DIR__.'/OperatorLoginRate.php';
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
            $origin = parse_url($server['HTTP_ORIGIN']); $host = parse_url('https://'.($server['HTTP_HOST'] ?? ''));
            if (!is_array($origin) || !is_array($host) || ($origin['scheme'] ?? '') !== 'https'
                || strtolower($origin['host'] ?? '') !== strtolower($host['host'] ?? '')
                || ($origin['port'] ?? 443) !== ($host['port'] ?? 443)
                || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])
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
            return is_int($binding['signed_in_at'] ?? null) && is_int($binding['last_activity'] ?? null)
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
        if (!$parent || realpath(dirname($prefix))!==dirname($prefix) || ($parent['mode']&0022)) { throw new \RuntimeException('portal_password_storage_unsafe'); }
        for ($slot=0;$slot<2;$slot++) {
            $path=$prefix.'-'.$slot.'.lock'; clearstatcache(true,$path); $before=@lstat($path); $mask=umask(0037);
            try {
                $handle=@fopen($path,$before===false?'x+b':'r+b');
                if (!$handle && $before===false) { clearstatcache(true,$path); $before=@lstat($path); if ($before) { $handle=@fopen($path,'r+b'); } }
            } finally { umask($mask); }
            if (!$handle) { throw new \RuntimeException('portal_password_storage_unavailable'); }
            try {
                $open=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
                if (!$open || !$named || ($open['mode']&0170000)!==0100000 || ($open['mode']&0027) || $open['nlink']!==1
                    || $open['uid']!==posix_geteuid() || $open['size']!==0 || $open['dev']!==$named['dev'] || $open['ino']!==$named['ino']
                    || is_link($path) || ($before && ($open['dev']!==$before['dev'] || $open['ino']!==$before['ino']))) { throw new \RuntimeException('portal_password_storage_unsafe'); }
                if (flock($handle,LOCK_EX|LOCK_NB)) { return $work(); }
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
        clearstatcache(true, $path); $before = @lstat($path);
        $mask=umask(0037);
        try {
            $handle = @fopen($path, $before === false ? 'x+b' : 'r+b');
            // Two first sign-ins can create the same ledger concurrently.
            // Open the winning inode, then perform all ownership/link checks.
            if (!is_resource($handle) && $before===false) {
                clearstatcache(true,$path); $before=@lstat($path);
                if ($before!==false) { $handle=@fopen($path,'r+b'); }
            }
        } finally { umask($mask); }
        if (!is_resource($handle)) { throw new \RuntimeException('portal_login_storage_unavailable'); }
        try {
            if ($before === false) { chmod($path, 0640); }
            $meta = fstat($handle); clearstatcache(true, $path); $current = @lstat($path);
            if (!$meta || !$current || ($meta['mode'] & 0170000) !== 0100000 || ($meta['mode'] & 0027)
                || $meta['nlink'] !== 1 || $meta['uid'] !== posix_geteuid() || $meta['size'] > 65536
                || $meta['dev'] !== $current['dev'] || $meta['ino'] !== $current['ino'] || is_link($path)
                || (is_array($before) && ($meta['dev'] !== $before['dev'] || $meta['ino'] !== $before['ino']))) {
                throw new \RuntimeException('portal_login_storage_unsafe');
            }
            $deadline = microtime(true)+2;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) { throw new \RuntimeException('portal_login_storage_busy'); }
                usleep(10000);
            }
            $raw = stream_get_contents($handle, 65537);
            $rows = $raw === '' ? [] : json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($rows) || count($rows)>512) { throw new \RuntimeException('portal_login_storage_invalid'); }
            foreach ($rows as $key=>$row) {
                if (!preg_match('/^(?:global|[iu]_[a-f0-9]{64})$/D', (string)$key) || !is_array($row)
                    || !is_int($row['start'] ?? null) || !is_int($row['count'] ?? null) || $row['count']<0
                    || $row['start']>$now+30) { throw new \RuntimeException('portal_login_storage_invalid'); }
                if ($now-$row['start']>=300) { unset($rows[$key]); }
            }
            $keys=['i_'.hash('sha256', $ip)=>50, 'u_'.hash('sha256', strtolower($username))=>10, 'global'=>300];
            if ($clear) {
                unset($rows['i_'.hash('sha256',$ip)],$rows['u_'.hash('sha256',strtolower($username))]);
                $keys=[];
            }
            $retry=0;
            foreach ($keys as $key=>$limit) {
                $row=$rows[$key] ?? ['start'=>$now, 'count'=>0];
                if ($row['count'] >= $limit) { $retry=max($retry, max(1, 300-($now-$row['start']))); }
            }
            if ($retry) { return $retry; }
            if (count(array_unique(array_merge(array_keys($rows), array_keys($keys))))>512) {
                throw new \RuntimeException('portal_login_capacity_reached');
            }
            foreach ($keys as $key=>$limit) { $rows[$key] ??= ['start'=>$now,'count'=>0]; $rows[$key]['count']++; }
            $bytes=json_encode((object)$rows, JSON_THROW_ON_ERROR); rewind($handle);
            if (!ftruncate($handle,0) || fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('portal_login_storage_write_failed');
            }
            return 0;
        } finally { fclose($handle); }
    }

    public static function headers(string $nonce): void
    {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        // HTML form navigation uses an opaque Origin under no-referrer in
        // Chromium. Keep the same-origin Origin intact for the CSRF check.
        header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public static function document(string $content, string $nonce, array $principal, string $csrf, string $view): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $authenticated=$principal!==[];
        $navigation='';
        if ($authenticated) {
            $navigation='<nav aria-label="Operator portal"><a '.($view==='operations'?'aria-current="page" ':'').'href="'.self::BASE.'">Operations</a>';
            if (($principal['operator_role'] ?? '')==='administrator') {
                $navigation.='<a '.($view==='access'?'aria-current="page" ':'').'href="'.self::BASE.'?view=access">Operator access</a>';
            }
            $navigation.='</nav><div class="portal-session"><span>'.$e($principal['name'] ?? $principal['username']).'</span><form method="post" action="'.self::BASE.'"><input type="hidden" name="portal_action" value="logout"><input type="hidden" name="portal_csrf" value="'.$e($csrf).'"><button type="submit" class="btn btn-default">Sign out</button></form></div>';
        }
        $css=file_get_contents(__DIR__.'/views/portal.css');
        $branding=''; $mark='<span class="portal-mark" aria-hidden="true"><i class="fa fa-bullhorn"></i></span>';
        try {
            if (class_exists('FreePBX',false)) {
                $theme=\FreePBX::Config()->get('BRAND_CSS_CUSTOM'); $logo=\FreePBX::Config()->get('BRAND_IMAGE_TANGO_LEFT');
                if (is_string($theme) && preg_match('#^/(?!.*\.\.)[A-Za-z0-9_./-]+\.css$#D',$theme)) { $branding='<link rel="stylesheet" href="'.$e($theme).'">'; }
                if (is_string($logo) && preg_match('#^/(?!.*\.\.)[A-Za-z0-9_./-]+\.(?:png|svg|jpe?g)$#Di',$logo)) { $mark='<img class="portal-logo" src="'.$e($logo).'" alt="">'; }
            }
        } catch (\Throwable $ignored) { }
        $body='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>SLS Mass Notify · '.($authenticated?'Operations':'Sign in').'</title><link rel="stylesheet" href="/admin/assets/css/font-awesome.min-4.7.0.css"><style>'.$css.'</style>'.$branding.'</head><body><header class="portal-header"><a class="portal-brand" href="'.self::BASE.'">'.$mark.'<span>SLS Mass Notify<small>Operator portal</small></span></a>'.$navigation.'</header><main class="portal-main">'.$content.'</main><footer class="portal-footer">Southland Servers · Mass Notifications</footer></body></html>';
        return preg_replace('/<(script|style)(?=[\s>])/i', '<$1 nonce="'.$e($nonce).'"', $body);
    }
}
