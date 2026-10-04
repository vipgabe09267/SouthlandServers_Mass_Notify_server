<?php
declare(strict_types=1);

namespace FreePBX\modules;

if (!class_exists(SlsConfigCrypto::class, false)) {
/** Authenticated central settings, shared without a FreePBX bootstrap. */
final class SlsConfigCrypto
{
    public const FORMAT = 'sls-mass-notify-config-aes256gcm-v1';
    public const KEYRING_FORMAT = 'sls-mass-notify-config-keyring-v1';
    public const KEYRING_PATH = '/etc/sls-mass-notify/config-keys.json';
    public const MAX_PLAIN_BYTES = 2 * 1024 * 1024;
    public const MAX_FILE_BYTES = 3 * 1024 * 1024;
    public const MAX_KEYRING_BYTES = 32768;
    public const ROTATE_SECONDS = 365 * 86400;

    private static function object(string $raw, int $maximum): array
    {
        if ($raw === '' || strlen($raw) > $maximum) { throw new \RuntimeException('The protected configuration exceeds its size limit.'); }
        try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('The protected configuration contains invalid JSON.', 0, $error); }
        if (!is_array($value) || array_is_list($value)) { throw new \RuntimeException('The protected configuration must contain a JSON object.'); }
        self::rejectDuplicateKeys($raw);
        return $value;
    }

    /** PHP otherwise silently overwrites duplicate keys that Python rejects. */
    private static function rejectDuplicateKeys(string $raw): void
    {
        $frames = []; $length = strlen($raw);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $raw[$offset];
            if ($character === '{' || $character === '[') {
                $frames[] = ['object' => $character === '{', 'key' => true, 'seen' => []];
            } elseif ($character === '}' || $character === ']') {
                array_pop($frames);
            } elseif ($character === ',' || $character === ':') {
                $top = count($frames) - 1;
                if ($top >= 0 && $frames[$top]['object']) { $frames[$top]['key'] = $character === ','; }
            } elseif ($character === '"') {
                $end = strpos($raw, '"', $offset + 1);
                while ($end !== false) {
                    $slashes = 0;
                    for ($cursor = $end - 1; $cursor > $offset && $raw[$cursor] === '\\'; $cursor--) { $slashes++; }
                    if (($slashes % 2) === 0) { break; }
                    $end = strpos($raw, '"', $end + 1);
                }
                // Syntax has already been validated by json_decode.
                if ($end === false) { throw new \RuntimeException('The protected configuration contains invalid JSON.'); }
                $top = count($frames) - 1;
                if ($top >= 0 && $frames[$top]['object'] && $frames[$top]['key']) {
                    $key = "\0" . json_decode(substr($raw, $offset, $end - $offset + 1), true, 2, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $frames[$top]['seen'])) {
                        throw new \RuntimeException('The protected configuration contains duplicate JSON fields.');
                    }
                    $frames[$top]['seen'][$key] = true;
                }
                $offset = $end;
            }
        }
    }

    private static function binary($value, int $minimum, int $maximum): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || base64_encode($decoded) !== $value || strlen($decoded) < $minimum || strlen($decoded) > $maximum) {
            throw new \RuntimeException('The protected configuration contains invalid encryption data.');
        }
        return $decoded;
    }

    public static function validateKeyring(array $ring): array
    {
        if (count($ring) !== 3 || ($ring['format'] ?? null) !== self::KEYRING_FORMAT
            || !is_string($ring['active'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $ring['active'])
            || !is_array($ring['keys'] ?? null) || count($ring['keys']) < 1 || count($ring['keys']) > 128
            || !array_key_exists($ring['active'], $ring['keys'])) {
            throw new \RuntimeException('The protected configuration keyring is invalid. Run the signed installer or protected repair.');
        }
        foreach ($ring['keys'] as $id => $record) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_array($record) || count($record) !== 2
                || !is_int($record['created_at'] ?? null) || $record['created_at'] < 1 || $record['created_at'] > 253402300799) {
                throw new \RuntimeException('The protected configuration keyring has invalid key metadata.');
            }
            self::binary($record['key'] ?? null, 32, 32);
        }
        return $ring;
    }

    /** No links, shared inodes, public read access, or unsafe keyring ownership. */
    private static function readProtected(string $path, int $maximum, bool $keyring): string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || ($before['mode'] & 0007) !== 0 || ($before['mode'] & 0022) !== 0
            || ($keyring && $before['uid'] !== 0) || $before['size'] < 1 || $before['size'] > $maximum) {
            throw new \RuntimeException('The protected configuration or encryption key file is missing or has unsafe permissions.');
        }
        if ($keyring) {
            $parent = @lstat(dirname($path));
            if (!is_array($parent) || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0 || ($parent['mode'] & 0022) !== 0) {
                throw new \RuntimeException('The encryption key directory must be owned by root and protected against group or public writes.');
            }
        }
        $component = $path;
        while ($component !== dirname($component)) {
            if (is_link($component)) { throw new \RuntimeException('The protected configuration path must not contain symbolic links.'); }
            if ($keyring && $component !== $path) {
                $ancestor = @lstat($component);
                if (!is_array($ancestor) || ($ancestor['mode'] & 0170000) !== 0040000 || $ancestor['uid'] !== 0
                    || (($ancestor['mode'] & 0022) !== 0 && ($ancestor['mode'] & 01000) === 0)) {
                    throw new \RuntimeException('Encryption keys must not be stored below a directory writable by the PBX runtime account.');
                }
            }
            $component = dirname($component);
        }
        // O_RDWR opens a swapped FIFO without waiting for a writer. The keyring
        // has a root-owned, non-writable parent and needs read access only.
        $handle = @fopen($path, $keyring ? 'rb' : 'r+b');
        if ($handle === false) { throw new \RuntimeException('The protected configuration or encryption key cannot be read.'); }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) {
                throw new \RuntimeException('The opened configuration is not a safe regular file.');
            }
            foreach (['dev', 'ino', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                if ($opened[$field] !== $before[$field]) { throw new \RuntimeException('The protected configuration changed while it was being opened.'); }
            }
            $raw = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            foreach (['dev', 'ino', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                if (!is_array($current) || $before[$field] !== $after[$field] || $after[$field] !== $current[$field]) {
                    throw new \RuntimeException('The protected configuration changed while it was being read.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $before['size']) { throw new \RuntimeException('The protected configuration read was incomplete.'); }
            return $raw;
        } finally { fclose($handle); }
    }

    public static function readKeyring(?string $path = null): array
    {
        return self::validateKeyring(self::object(self::readProtected($path ?? self::KEYRING_PATH, self::MAX_KEYRING_BYTES, true), self::MAX_KEYRING_BYTES));
    }

    public static function isEncrypted(array $value): bool
    {
        return ($value['format'] ?? null) === self::FORMAT;
    }

    public static function decodeWithKeyring(string $raw, array $ring, bool $allowLegacy = false): array
    {
        $value = self::object($raw, self::MAX_FILE_BYTES);
        if (!self::isEncrypted($value)) {
            if (!$allowLegacy || array_key_exists('format', $value) || array_key_exists('ciphertext', $value)
                || array_key_exists('key_id', $value) || array_key_exists('nonce', $value)
                || strlen($raw) > self::MAX_PLAIN_BYTES) {
                throw new \RuntimeException('The central configuration encryption format is unsupported or damaged. No default settings were substituted.');
            }
            return $value;
        }
        self::validateKeyring($ring);
        if (count($value) !== 4 || !is_string($value['key_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $value['key_id'])
            || !isset($ring['keys'][$value['key_id']])) {
            throw new \RuntimeException('The encrypted configuration key is unavailable or its envelope is invalid. Restore its protected key material or configuration backup.');
        }
        $nonce = self::binary($value['nonce'] ?? null, 12, 12);
        $ciphertext = self::binary($value['ciphertext'] ?? null, 17, self::MAX_PLAIN_BYTES + 16);
        $key = self::binary($ring['keys'][$value['key_id']]['key'], 32, 32);
        if (!function_exists('openssl_decrypt')) { throw new \RuntimeException('AES-256 configuration encryption requires the PHP OpenSSL extension.'); }
        $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce,
            substr($ciphertext, -16), self::FORMAT . "\n" . $value['key_id']);
        if (!is_string($plain)) { throw new \RuntimeException('The central configuration failed authentication. No settings were loaded; verify the configuration and key backups.'); }
        return self::object($plain, self::MAX_PLAIN_BYTES);
    }

    public static function decode(string $raw, ?string $keyringPath = null, bool $allowLegacy = true): array
    {
        $value = self::object($raw, self::MAX_FILE_BYTES);
        return self::decodeWithKeyring($raw, self::isEncrypted($value) ? self::readKeyring($keyringPath) : [], $allowLegacy);
    }

    public static function readFile(string $path, ?string $keyringPath = null, bool $allowLegacy = true): array
    {
        return self::decode(self::readProtected($path, self::MAX_FILE_BYTES, false), $keyringPath, $allowLegacy);
    }

    public static function encodeWithKeyring(array $settings, array $ring): string
    {
        self::validateKeyring($ring);
        $plain = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::object($plain, self::MAX_PLAIN_BYTES);
        $id = $ring['active']; $nonce = random_bytes(12);
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('AES-256 configuration encryption requires the PHP OpenSSL extension.'); }
        $key = self::binary($ring['keys'][$id]['key'], 32, 32); $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::FORMAT . "\n" . $id, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) { throw new \RuntimeException('The configuration could not be encrypted. Existing settings were preserved.'); }
        return json_encode(['format' => self::FORMAT, 'key_id' => $id, 'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext . $tag)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function encode(array $settings, ?string $keyringPath = null): string
    {
        return self::encodeWithKeyring($settings, self::readKeyring($keyringPath));
    }

    /** The private backup contains only the key needed by its configuration. */
    public static function backupKeyring(string $raw, ?string $keyringPath = null): string
    {
        $value = self::object($raw, self::MAX_FILE_BYTES); $ring = self::readKeyring($keyringPath);
        self::decodeWithKeyring($raw, $ring);
        $id = $value['key_id'];
        return json_encode(['format' => self::KEYRING_FORMAT, 'active' => $id, 'keys' => [$id => $ring['keys'][$id]]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function decodeBackup(string $raw, string $backupKeyring): array
    {
        return self::decodeWithKeyring($raw, self::validateKeyring(self::object($backupKeyring, self::MAX_KEYRING_BYTES)));
    }
}
}
