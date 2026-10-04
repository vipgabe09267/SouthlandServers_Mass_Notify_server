<?php
namespace FreePBX\modules;

/** Portable authenticated encryption; the passphrase is never retained. */
final class SlsEncryptedConfig
{
    public const FORMAT = 'sls-mass-notify-encrypted-config-v1';
    public const MAX_PLAIN_BYTES = 4 * 1024 * 1024;
    public const MAX_FILE_BYTES = 6 * 1024 * 1024;
    private const OPS = 3;
    private const MEMORY = 64 * 1024 * 1024;

    private static function available(): void
    {
        if (!function_exists('sodium_crypto_pwhash') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException(_('Encrypted configuration requires the PHP Sodium extension. Enable it for the PBX web PHP version and retry.'));
        }
    }

    private static function passphrase($value): string
    {
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 1024 || strpos($value, "\0") !== false) {
            throw new \DomainException(_('Use a backup passphrase of 12–1,024 bytes. Spaces are significant. Keep it somewhere separate from the backup; SLS cannot recover it.'));
        }
        return $value;
    }

    private static function key(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(32, $passphrase, $salt, self::OPS, self::MEMORY, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function context(): string
    {
        return self::FORMAT . "\nargon2id13:3:67108864\nxchacha20poly1305-ietf";
    }

    public static function encrypt(string $plaintext, $passphrase): string
    {
        self::available(); $passphrase = self::passphrase($passphrase);
        if ($plaintext === '' || strlen($plaintext) > self::MAX_PLAIN_BYTES) {
            throw new \DomainException(_('The configuration export exceeds the 4 MiB encryption limit. No backup was produced.'));
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = self::key($passphrase, $salt);
        try { $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::context(), $nonce, $key); }
        finally { sodium_memzero($key); sodium_memzero($passphrase); }
        return json_encode(['format' => self::FORMAT,
            'kdf' => ['algorithm' => 'argon2id13', 'opslimit' => self::OPS, 'memlimit' => self::MEMORY, 'salt' => base64_encode($salt)],
            'cipher' => 'xchacha20poly1305-ietf', 'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($ciphertext)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function decode(string $file, $passphrase = ''): array
    {
        if ($file === '' || strlen($file) > self::MAX_FILE_BYTES) {
            throw new \DomainException(_('The configuration upload must be between 1 byte and 6 MiB.'));
        }
        try { $payload = json_decode($file, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \DomainException(_('The configuration file is not valid JSON. Select an SLS configuration export.')); }
        if (!is_array($payload) || array_is_list($payload)) { throw new \DomainException(_('The configuration must contain a JSON object.')); }
        if (($payload['format'] ?? '') !== self::FORMAT) {
            if (array_key_exists('format', $payload) && $payload['format'] !== 'sls-mass-notify-config-v1') {
                throw new \DomainException(_('This configuration export format is unsupported. Use a compatible SLS export.'));
            }
            if (strlen($file) > self::MAX_PLAIN_BYTES) { throw new \DomainException(_('The plain configuration export exceeds 4 MiB.')); }
            return $payload;
        }
        self::available(); $passphrase = self::passphrase($passphrase);
        $kdf = $payload['kdf'] ?? null;
        // Fixed work factors prevent a crafted upload from allocating arbitrary memory.
        if (!is_array($kdf) || count($payload) !== 5 || count($kdf) !== 4
            || ($kdf['algorithm'] ?? null) !== 'argon2id13' || ($kdf['opslimit'] ?? null) !== self::OPS
            || ($kdf['memlimit'] ?? null) !== self::MEMORY || ($payload['cipher'] ?? null) !== 'xchacha20poly1305-ietf') {
            throw new \DomainException(_('The encrypted backup has unsupported or altered encryption parameters.'));
        }
        $decode = static function ($value): string {
            if (!is_string($value)) { throw new \DomainException(_('The encrypted backup contains an invalid binary field.')); }
            $decoded = base64_decode($value, true);
            if (!is_string($decoded) || base64_encode($decoded) !== $value) { throw new \DomainException(_('The encrypted backup contains invalid Base64 data.')); }
            return $decoded;
        };
        $salt = $decode($kdf['salt'] ?? null); $nonce = $decode($payload['nonce'] ?? null); $ciphertext = $decode($payload['ciphertext'] ?? null);
        if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || strlen($ciphertext) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            || strlen($ciphertext) > self::MAX_PLAIN_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \DomainException(_('The encrypted backup is truncated or exceeds its size limit.'));
        }
        $key = self::key($passphrase, $salt);
        try { $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, self::context(), $nonce, $key); }
        finally { sodium_memzero($key); sodium_memzero($passphrase); }
        if ($plaintext === false) { throw new \DomainException(_('The backup passphrase is incorrect or the encrypted file was changed. No configuration was imported.')); }
        try { $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \DomainException(_('The decrypted backup does not contain a valid SLS configuration.')); }
        finally { sodium_memzero($plaintext); }
        if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'sls-mass-notify-config-v1' || !is_array($decoded['settings'] ?? null)) {
            throw new \DomainException(_('The decrypted backup is not an SLS configuration export.'));
        }
        return $decoded;
    }
}
