<?php
declare(strict_types=1);
namespace SLS\MassNotify;

if (!class_exists(PhpSecurityPolicy::class, false)) {
final class PhpSecurityPolicy
{
    public static function evaluate(string $version, ?array $policy = null): array
    {
        if ($policy === null) {
            foreach ([dirname(__DIR__, 2).'/bin/sls_mass_notify/php-security-policy.json', '/usr/local/bin/sls_mass_notify/php-security-policy.json'] as $path) {
                if (!is_file($path) || is_link($path)) { continue; }
                $raw = file_get_contents($path, false, null, 0, 8193);
                if (!is_string($raw) || strlen($raw) > 8192) { continue; }
                $policy = json_decode($raw, true, 8);
                break;
            }
        }
        $result = ['ok'=>false, 'code'=>'php_security_policy_unavailable', 'minimum'=>'', 'version'=>$version];
        if (!is_array($policy) || ($policy['schema'] ?? null) !== 1 || !is_array($policy['branches'] ?? null)
            || count($policy['branches']) > 16) { return $result; }
        if (strlen($version) > 80 || !preg_match('/^(\d{1,2}\.\d{1,2})\.(\d{1,3})(?:[-+][A-Za-z0-9.+~_-]+)?$/D', $version, $match)
            || preg_match('/(?:^|[-+~_.])(?:dev|alpha|beta|rc)[0-9]*/i', $version)) {
            return array_replace($result, ['code'=>'php_version_unrecognized']);
        }
        $minimum = $policy['branches'][$match[1]] ?? null;
        if (!is_string($minimum) || !preg_match('/^'.preg_quote($match[1], '/').'\.\d{1,3}$/D', $minimum)) {
            return array_replace($result, ['code'=>'php_branch_unqualified']);
        }
        $ok = version_compare($match[1].'.'.$match[2], $minimum, '>=');
        return array_replace($result, ['ok'=>$ok, 'code'=>$ok ? 'php_security_floor_met' : 'php_security_update_required', 'minimum'=>$minimum]);
    }
}
}
