<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** A first-creation intent permits recovery only before any application effect. */
final class InitializationJournal
{
    public static function initializeStore(\SlsAnnouncementJobStore $files, string $kind, array $initial, string $parentMarker, string $marker, $lock): array
    {
        if (!preg_match('/^[a-z][a-z-]{0,63}$/D', $kind) || !preg_match('/^SLS_[A-Z0-9_]{1,48}_V[0-9]+\n$/D', $marker)
            || strlen($marker) > 64 || !is_resource($lock)) { throw new \InvalidArgumentException('Initialization journal contract is invalid.'); }
        $directory = $files->directory();
        $intentPath = $directory . '/initialization.pending';
        $intentBytes = "SLS_INIT_V1\n" . $kind . "\n" . hash('sha256', json_encode($initial, JSON_THROW_ON_ERROR)) . "\n";
        $intent = self::read($intentPath);
        $parent = self::read($parentMarker);
        if (!rewind($lock)) { throw new \RuntimeException('Initialization lock marker could not be read.'); }
        $current = stream_get_contents($lock, 65);
        if (!is_string($current)) { throw new \RuntimeException('Initialization lock marker could not be read.'); }
        $present = file_exists($directory . '/worker-state.json') || is_link($directory . '/worker-state.json');
        $state = $files->state();
        if ($intent === null && ($parent !== null || $present || $current !== '')) {
            if ($parent !== $marker || !$present || $current !== $marker) {
                throw new \RuntimeException('Established journal or initialization marker is missing. Preserve replay evidence for recovery.');
            }
            return $state;
        }
        if ($intent !== null && ($intent !== $intentBytes || ($parent !== null && $parent !== $marker)
            || !str_starts_with($marker, $current) || ($present && $state !== $initial))) {
            throw new \RuntimeException('Initialization evidence conflicts with application history. Reviewed recovery is required.');
        }
        if ($intent === null) { self::write($intentPath, $intentBytes); }
        if (!$present) { $files->atomic('worker-state.json', $initial); }
        self::write($parentMarker, $marker);
        if (!rewind($lock) || !self::writeAll($lock, $marker) || !ftruncate($lock, strlen($marker)) || !fflush($lock) || !fsync($lock)) {
            throw new \RuntimeException('Initialization lock marker could not be synchronized.');
        }
        self::sync($directory);
        self::finish($intentPath, $intentBytes);
        return $initial;
    }

    public static function read(string $path): ?string
    {
        $captured = self::capture($path);
        return $captured === null ? null : $captured['bytes'];
    }

    private static function writeAll($handle, string $bytes): bool
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { return false; }
            $offset += $written;
        }
        return true;
    }

    private static function capture(string $path): ?array
    {
        clearstatcache(true, $path); $before = @lstat($path);
        if (!$before) { return null; }
        $safe = static fn($m): bool => is_array($m) && ($m['mode'] & 0170000) === 0100000
            && !($m['mode'] & 07027) && $m['nlink'] === 1 && $m['uid'] === posix_geteuid() && $m['size'] <= 1024;
        if (!$safe($before)) { throw new \RuntimeException('Initialization evidence is unsafe. Preserve it for recovery.'); }
        $handle = @fopen($path, 'r+b');
        if (!$handle) { throw new \RuntimeException('Initialization evidence is unreadable.'); }
        try {
            $opened = fstat($handle);
            $bytes = stream_get_contents($handle, 1025);
            $after = fstat($handle); clearstatcache(true, $path); $named = @lstat($path);
            foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field) {
                if (!$safe($opened) || !$safe($after) || !$safe($named)
                    || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]
                    || $after[$field] !== $named[$field]) {
                    throw new \RuntimeException('Initialization evidence changed.');
                }
            }
            if (!is_string($bytes) || strlen($bytes) !== $opened['size']) {
                throw new \RuntimeException('Initialization evidence changed.');
            }
            return ['bytes' => $bytes, 'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']]];
        } finally { fclose($handle); }
    }

    private static function unlinkSame(string $path, $identity): bool
    {
        if (!is_array($identity) || !isset($identity['dev'], $identity['ino'])) { return false; }
        clearstatcache(true, $path); $current = @lstat($path);
        return is_array($current) && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1
            && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino'] && @unlink($path);
    }

    public static function write(string $path, string $bytes): void
    {
        $directory = dirname($path); clearstatcache(true, $directory); $parent = @lstat($directory);
        $runtime=posix_getpwnam('asterisk');$owners=[0,posix_geteuid()];
        if (posix_geteuid()===0 && is_array($runtime)) { $owners[]=$runtime['uid']; }
        if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022)
            || realpath($directory) !== $directory || !in_array($parent['uid'], $owners, true)
            || strlen($bytes) > 1024) { throw new \RuntimeException('Initialization directory is unsafe.'); }
        $existing = self::read($path);
        if ($existing !== null) {
            if ($existing !== $bytes) { throw new \RuntimeException('Initialization evidence conflicts.'); }
            return;
        }
        $temporary = $directory . '/.initialization-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try { $handle = @fopen($temporary, 'x+b'); } finally { umask($mask); }
        if (!$handle) { throw new \RuntimeException('Initialization evidence could not be created.'); }
        $temporaryIdentity = fstat($handle);
        try {
            if (!self::writeAll($handle, $bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Initialization evidence could not be synchronized.');
            }
            clearstatcache(true, $directory); $now = @lstat($directory);
            foreach (['dev','ino','mode','uid','gid','nlink'] as $field) {
                if (!is_array($now) || $parent[$field] !== $now[$field]) {
                    throw new \RuntimeException('Initialization directory changed.');
                }
            }
            $opened = fstat($handle); clearstatcache(true, $temporary); $named = @lstat($temporary);
            if (!is_array($temporaryIdentity) || !is_array($opened) || !is_array($named)
                || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || ($opened['mode'] & 0777) !== 0600 || $opened['uid'] !== posix_geteuid()
                || $opened['dev'] !== $temporaryIdentity['dev'] || $opened['ino'] !== $temporaryIdentity['ino']
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino'] || $opened['size'] !== strlen($bytes)) {
                throw new \RuntimeException('Initialization staging changed.');
            }
            $current = self::read($path);
            if ($current !== null && $current !== $bytes) { throw new \RuntimeException('Initialization evidence conflicts.'); }
            if ($current === null) {
                if (!rename($temporary, $path)) { throw new \RuntimeException('Initialization publication failed.'); }
                clearstatcache(true, $path); $committed = @lstat($path);
                if (!is_array($committed) || $committed['dev'] !== $temporaryIdentity['dev']
                    || $committed['ino'] !== $temporaryIdentity['ino'] || $committed['nlink'] !== 1
                    || $committed['size'] !== strlen($bytes)) {
                    throw new \RuntimeException('Initialization publication changed unexpectedly. Preserve the evidence for recovery.');
                }
            }
        } finally { fclose($handle); self::unlinkSame($temporary, $temporaryIdentity); }
        self::sync($directory);
    }

    public static function finish(string $path, string $bytes): void
    {
        $captured = self::capture($path);
        if ($captured === null || $captured['bytes'] !== $bytes || !self::unlinkSame($path, $captured['identity'])) {
            throw new \RuntimeException('Initialization completion failed.');
        }
        self::sync(dirname($path));
    }

    public static function sync(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        try {
            if (!$handle || !fsync($handle)) { throw new \RuntimeException('Initialization directory durability unavailable.'); }
        } finally { if ($handle) { fclose($handle); } }
    }
}
