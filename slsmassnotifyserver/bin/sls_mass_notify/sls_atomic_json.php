<?php
declare(strict_types=1);

/** Atomic JSON replacement under a permanent sidecar lock. */
final class SlsAtomicJson
{
    private const MARKER = "SLS_JSON_V1\n";
    private const DECODE_LIMIT = 4 * 1024 * 1024;

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes, string $failure): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException($failure); }
            $offset += $written;
        }
    }

    public static function decodeObject(string $raw, int $depth = 64): array
    {
        if ($depth < 2 || $depth > 128 || strlen($raw) < 2 || strlen($raw) > self::DECODE_LIMIT) {
            throw new InvalidArgumentException('json_storage_decode_limit');
        }
        if (!str_starts_with(ltrim($raw), '{')) { throw new RuntimeException('json_storage_object_required'); }
        self::validateJsonKeys($raw, $depth);
        return json_decode($raw, true, $depth, JSON_THROW_ON_ERROR);
    }

    private static function validateJsonKeys(string $raw, int $depth = 64): void
    {
        $keys=0;
        $pattern=<<<'REGEX'
~"(?:\\.|[^"\\])*"\s*:?~s
REGEX;
        $stripped=preg_replace_callback($pattern,static function(array $token) use (&$keys): string {
            if (str_ends_with($token[0],':')) { $keys++; }
            return '';
        },$raw);
        if ($stripped===null) { throw new RuntimeException('json_storage_invalid'); }
        $decoded=json_decode($raw,false,$depth,JSON_THROW_ON_ERROR);
        $stack=[$decoded];$decodedKeys=0;
        while ($stack) {
            $value=array_pop($stack);
            if (is_object($value)) { $members=get_object_vars($value);$decodedKeys+=count($members); }
            elseif (is_array($value)) { $members=$value; }
            else {
                if (is_float($value) && !is_finite($value)) { throw new RuntimeException('json_storage_invalid'); }
                continue;
            }
            foreach ($members as $member) { $stack[]=$member; }
        }
        if ($keys!==$decodedKeys) { throw new RuntimeException('json_storage_duplicate_key'); }
    }

    public static function transaction(string $path, array $defaults, callable $work, int $maximum = 1048576, bool $requireExisting = true, bool $requireObject = false)
    {
        if ($maximum < 2 || $maximum > 64 * 1024 * 1024) { throw new InvalidArgumentException('json_storage_capacity_invalid'); }
        $directory = dirname($path);
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        $owners = array_unique([0, $euid]);
        clearstatcache(true, $directory);
        $parent = @lstat($directory);
        if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022)
            || realpath($directory) !== $directory || !in_array($parent['uid'], $owners, true)) {
            throw new RuntimeException('json_storage_unsafe');
        }
        $safe = static function ($meta) use ($maximum, $owners): bool {
            return is_array($meta) && ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1
                && !($meta['mode'] & 07027) && in_array($meta['uid'], $owners, true)
                && $meta['size'] >= 0 && $meta['size'] <= $maximum;
        };
        $open = static function (string $name, string $mode) use ($safe) {
            clearstatcache(true, $name);
            $before = @lstat($name);
            if ($before && !$safe($before)) { throw new RuntimeException('json_storage_unsafe'); }
            $mask = umask(0037);
            try { $handle = @fopen($name, $mode); } finally { umask($mask); }
            if (!$handle) { throw new RuntimeException('json_storage_unavailable'); }
            $opened = fstat($handle); clearstatcache(true, $name); $named = @lstat($name);
            if (!$safe($opened) || !$safe($named) || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
                || ($before && ($before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']))) {
                fclose($handle); throw new RuntimeException('json_storage_changed');
            }
            return $handle;
        };
        $lockPath = $path . '.lock';
        clearstatcache(true, $lockPath);
        $lock = $open($lockPath, @lstat($lockPath) ? 'r+b' : 'c+b');
        $temporary = null; $temp = null; $temporaryIdentity = null;
        try {
            $verifyLease = static function () use ($lock, $lockPath, $directory, $parent, $safe): void {
                clearstatcache(true, $lockPath); clearstatcache(true, $directory);
                $named = @lstat($lockPath); $opened = fstat($lock); $currentParent = @lstat($directory);
                if (!$safe($named) || !$safe($opened) || $named['dev'] !== $opened['dev'] || $named['ino'] !== $opened['ino']
                    || !$currentParent || ($currentParent['mode'] & 0170000) !== 0040000
                    || ($currentParent['mode'] & 0022) || realpath($directory) !== $directory
                    || $parent['dev'] !== $currentParent['dev'] || $parent['ino'] !== $currentParent['ino']
                    || $parent['uid'] !== $currentParent['uid']) {
                    throw new RuntimeException('json_storage_changed');
                }
            };
            $deadline = hrtime(true) + 2000000000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) { throw new RuntimeException('json_storage_busy'); }
                usleep(10000);
            }
            $verifyLease();
            rewind($lock); $marker = stream_get_contents($lock, 32);
            if (!is_string($marker) || !str_starts_with(self::MARKER, $marker)) { throw new RuntimeException('json_storage_marker_invalid'); }
            clearstatcache(true, $path); $before = @lstat($path);
            if (!$before && $marker !== '' && $requireExisting) { throw new RuntimeException('json_storage_missing'); }
            $rows = $defaults;
            if ($before) {
                $data = $open($path, 'r+b');
                try {
                    $opened = fstat($data);
                    $raw = stream_get_contents($data, $maximum + 1);
                    $after = fstat($data); clearstatcache(true, $path); $named = @lstat($path);
                    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                        if (!$safe($opened) || !$safe($after) || !$safe($named)
                            || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]
                            || $after[$field] !== $named[$field]) {
                            throw new RuntimeException('json_storage_changed');
                        }
                    }
                    if (!is_string($raw) || $raw === '' || strlen($raw) !== $opened['size']) {
                        throw new RuntimeException('json_storage_invalid');
                    }
                    if ($requireObject && !str_starts_with(ltrim($raw),'{')) { throw new RuntimeException('json_storage_invalid'); }
                    $rows = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    self::validateJsonKeys($raw);
                    if (!is_array($rows)) { throw new RuntimeException('json_storage_invalid'); }
                } finally { fclose($data); }
            }
            $outcome = $work($rows);
            if (!is_array($outcome) || count($outcome) !== 3 || !array_key_exists(0, $outcome)
                || !array_key_exists(1, $outcome) || !array_key_exists(2, $outcome)
                || !is_array($outcome[0]) || !is_bool($outcome[2])) {
                throw new RuntimeException('json_storage_callback_invalid');
            }
            [$next, $result, $changed] = $outcome;
            if ($requireObject && $next !== [] && array_is_list($next)) {
                throw new RuntimeException('json_storage_object_required');
            }
            $verifyLease();
            if ($changed || !$before) {
                $bytes = ($requireObject && $next === [])
                    ? "{}\n"
                    : json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (strlen($bytes) > $maximum) { throw new RuntimeException('json_storage_capacity'); }
                $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(12)) . '.tmp';
                $temp = $open($temporary, 'x+b');
                $temporaryIdentity = fstat($temp);
                self::writeAll($temp, $bytes, 'json_storage_write_failed');
                if (!fflush($temp) || !fsync($temp)) {
                    throw new RuntimeException('json_storage_write_failed');
                }
                $opened = fstat($temp); clearstatcache(true, $temporary); $staged = @lstat($temporary);
                if (!$safe($opened) || !$safe($staged) || !is_array($temporaryIdentity)
                    || $opened['dev'] !== $temporaryIdentity['dev'] || $opened['ino'] !== $temporaryIdentity['ino']
                    || $opened['dev'] !== $staged['dev'] || $opened['ino'] !== $staged['ino']
                    || $opened['size'] !== strlen($bytes)) {
                    throw new RuntimeException('json_storage_changed');
                }
                clearstatcache(true, $path); $named = @lstat($path);
                clearstatcache(true, $directory); $currentParent = @lstat($directory);
                if (!$currentParent || $parent['dev'] !== $currentParent['dev'] || $parent['ino'] !== $currentParent['ino']
                    || ($before ? !$named || $before['dev'] !== $named['dev'] || $before['ino'] !== $named['ino'] : $named !== false)) {
                    throw new RuntimeException('json_storage_changed');
                }
                $verifyLease();
                if (!rename($temporary, $path)) { throw new RuntimeException('json_storage_write_failed'); }
                clearstatcache(true, $path); $committed = @lstat($path);
                if (!$safe($committed) || !is_array($temporaryIdentity)
                    || $committed['dev'] !== $temporaryIdentity['dev'] || $committed['ino'] !== $temporaryIdentity['ino']
                    || $committed['size'] !== strlen($bytes)) {
                    throw new RuntimeException('json_storage_commit_uncertain');
                }
                $temporary = null;
            }
            $directoryHandle = @fopen($directory, 'r');
            try {
                if (!$directoryHandle || !fsync($directoryHandle)) { throw new RuntimeException('json_storage_commit_uncertain'); }
            } finally { if ($directoryHandle) { fclose($directoryHandle); } }
            $verifyLease();
            if ($marker !== self::MARKER) {
                rewind($lock);
                self::writeAll($lock, self::MARKER, 'json_storage_commit_uncertain');
                if (!fflush($lock) || !fsync($lock)) {
                    throw new RuntimeException('json_storage_commit_uncertain');
                }
            }
            return $result;
        } finally {
            if (is_resource($temp)) { fclose($temp); }
            if ($temporary !== null && is_array($temporaryIdentity)) {
                clearstatcache(true, $temporary); $current = @lstat($temporary);
                if (is_array($current) && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1
                    && $current['dev'] === $temporaryIdentity['dev'] && $current['ino'] === $temporaryIdentity['ino']) {
                    @unlink($temporary);
                }
            }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
