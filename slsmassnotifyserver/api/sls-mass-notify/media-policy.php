<?php
declare(strict_types=1);
namespace SLS\MassNotify;

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config-crypto.php';

/** Anonymous device-compatible downloads, optionally limited by network and age. */
final class MediaAccess
{
    public static function defaults(): array
    {
        return ['network_restricted' => false, 'allowed_cidrs' => [], 'max_age_minutes' => 0];
    }

    public static function normalize($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), array_keys(self::defaults()))) {
            throw new \DomainException('Generated-media access settings contain an unsupported field.');
        }
        $value = array_replace(self::defaults(), $value);
        if (!is_bool($value['network_restricted']) || !is_array($value['allowed_cidrs']) || !array_is_list($value['allowed_cidrs'])
            || count($value['allowed_cidrs']) > 32) {
            throw new \DomainException('Generated-media access needs an explicit network checkbox and at most 32 IPv4/IPv6 networks.');
        }
        try { $cidrs = ApiSecurity::network(['trusted_proxy_cidrs' => $value['allowed_cidrs']])['trusted_proxy_cidrs']; }
        catch (\DomainException $error) {
            throw new \DomainException('Allowed media networks must be IPv4/IPv6 addresses or CIDRs. A /0 network is not allowed.');
        }
        if ($value['network_restricted'] && !$cidrs) {
            throw new \DomainException('Add at least one allowed media network before enabling the restriction.');
        }
        $minutes = $value['max_age_minutes'];
        if (!is_int($minutes) || ($minutes !== 0 && ($minutes < 10 || $minutes > 1440))) {
            throw new \DomainException('Generated-media expiry must be 0 (disabled) or 10 through 1440 minutes.');
        }
        return ['network_restricted' => $value['network_restricted'], 'allowed_cidrs' => $cidrs, 'max_age_minutes' => $minutes];
    }

    public static function form(array $input): array
    {
        if (array_diff(array_keys($input), array_keys(self::defaults())) || !is_string($input['allowed_cidrs'] ?? null)
            || !in_array($input['network_restricted'] ?? null, ['0', '1'], true)
            || !is_string($input['max_age_minutes'] ?? null) || !preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $input['max_age_minutes'])) {
            throw new \DomainException('Media access settings are invalid. Enter one network per line and a whole-number expiry.');
        }
        if (strlen($input['allowed_cidrs']) > 4096) { throw new \DomainException('Allowed media networks exceed the 4096-byte input limit.'); }
        return self::normalize(['network_restricted' => $input['network_restricted'] === '1',
            'allowed_cidrs' => preg_split('/[\s,]+/', trim($input['allowed_cidrs']), -1, PREG_SPLIT_NO_EMPTY),
            'max_age_minutes' => (int)$input['max_age_minutes']]);
    }

    public static function filename($value): bool
    {
        return is_string($value) && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,179}\.(?:png|xml)$/D', $value);
    }

    /** Read a bounded, stable, single-link file; never follow a changed inode. */
    private static function readFile(string $path, int $limit): array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $valid = static function ($meta) use ($limit): bool {
            return is_array($meta) && ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1
                && !($meta['mode'] & 0022)
                && $meta['size'] > 0 && $meta['size'] <= $limit;
        };
        if (!$valid($before) || realpath($path) !== $path) { throw new \RuntimeException('media_file_unavailable'); }
        // O_RDWR does not truncate/write. Unlike O_RDONLY it cannot block while
        // opening a FIFO substituted between lstat and open; fstat rejects it.
        $handle = @fopen($path, 'r+b');
        if ($handle === false) { throw new \RuntimeException('media_file_unavailable'); }
        try {
            $identity = static function (array $meta): array {
                return array_intersect_key($meta, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime', 'nlink']));
            };
            $opened = fstat($handle);
            if (!$valid($opened) || $identity($before) !== $identity($opened) || !flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('media_file_unavailable');
            }
            $bytes = stream_get_contents($handle, $limit + 1);
            clearstatcache(true, $path); $after = @lstat($path); $final = fstat($handle);
            if (!is_string($bytes) || strlen($bytes) !== $opened['size'] || !$valid($after) || !$valid($final)
                || $identity($opened) !== $identity($after) || $identity($opened) !== $identity($final) || realpath($path) !== $path) {
                throw new \RuntimeException('media_file_unavailable');
            }
            return ['bytes' => $bytes, 'mtime' => $opened['mtime']];
        } finally { fclose($handle); }
    }

    public static function response(array $server, $filename, string $configPath, string $mediaRoot, ?int $now = null): array
    {
        $error = static function (int $code, string $message): array {
            return ['status' => $code, 'type' => 'application/json; charset=utf-8', 'body' => json_encode(['ok' => false, 'error' => $message]) . "\n"];
        };
        if (!in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) { return $error(405, 'media_method_not_allowed'); }
        if (!self::filename($filename)) { return $error(404, 'media_not_found'); }
        try {
            $settings = \FreePBX\modules\SlsConfigCrypto::readFile($configPath);
            $policy = self::normalize(array_key_exists('media_access', $settings) ? $settings['media_access'] : []);
        } catch (\Throwable $errorValue) { return $error(503, 'media_access_configuration_unavailable'); }
        if ($policy['network_restricted']) {
            $network = ApiSecurity::requestNetwork($server, $settings);
            if ($network['error'] !== '') { return $error(403, 'media_network_denied'); }
            $allowed = false;
            foreach ($policy['allowed_cidrs'] as $cidr) { if (ApiSecurity::inCidr($network['ip'], $cidr)) { $allowed = true; break; } }
            if (!$allowed) { return $error(403, 'media_network_denied'); }
        }
        try { $file = self::readFile($mediaRoot . '/' . $filename, str_ends_with($filename, '.png') ? 5 * 1024 * 1024 : 256 * 1024); }
        catch (\Throwable $errorValue) { return $error(404, 'media_not_found'); }
        $now = $now ?? time();
        if ($policy['max_age_minutes'] > 0) {
            if ($now < $file['mtime'] - 60) { return $error(503, 'media_clock_invalid'); }
            if ($now >= $file['mtime'] + $policy['max_age_minutes'] * 60) { return $error(410, 'media_expired'); }
        }
        $png = str_ends_with($filename, '.png');
        if ($png && substr($file['bytes'], 0, 8) !== "\x89PNG\r\n\x1a\n") { return $error(404, 'media_not_found'); }
        return ['status' => 200, 'type' => $png ? 'image/png' : 'text/xml; charset=utf-8', 'body' => $file['bytes']];
    }
}
