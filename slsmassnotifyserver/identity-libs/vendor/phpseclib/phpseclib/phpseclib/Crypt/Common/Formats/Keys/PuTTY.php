<?php

/**
 * PuTTY Formatted Key Handler
 *
 * See PuTTY's SSHPUBK.C and https://tartarus.org/~simon/putty-snapshots/htmldoc/AppendixC.html
 *
 * PHP version 5
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2016 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\Common\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Hash;
use phpseclib3\Crypt\Random;
use phpseclib3\Exception\UnsupportedAlgorithmException;

/**
 * PuTTY Formatted Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class PuTTY
{
    /**
     * Default comment
     *
     * @var string
     */
    private static $comment = 'phpseclib-generated-key';

    /**
     * Default version
     *
     * @var int
     */
    private static $version = 2;

    /**
     * Sets the default comment
     *
     * @param string $comment
     */
    public static function setComment($comment)
    {
        if (!Strings::is_stringable($comment)) {
            throw new \UnexpectedValueException('PuTTY key comment must be a string');
        }
        $comment = str_replace(["\r", "\n"], '', (string) $comment);
        if (strlen($comment) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $comment)) {
            throw new \UnexpectedValueException('PuTTY key comment is invalid');
        }
        self::$comment = $comment;
    }

    /**
     * Sets the default version
     *
     * @param int $version
     */
    public static function setVersion($version)
    {
        if (!is_int($version) || ($version !== 2 && $version !== 3)) {
            throw new \RuntimeException('Only supported versions are 2 and 3');
        }
        self::$version = $version;
    }

    /**
     * Generate a symmetric key for PuTTY v2 keys
     *
     * @param string $password
     * @param int $length
     * @return string
     */
    private static function generateV2Key($password, $length)
    {
        $symkey = '';
        $sequence = 0;
        while (strlen($symkey) < $length) {
            $temp = pack('Na*', $sequence++, $password);
            $symkey .= Strings::hex2bin(sha1($temp));
        }
        return substr($symkey, 0, $length);
    }

    /**
     * Generate a symmetric key for PuTTY v3 keys
     *
     * @param string $password
     * @param string $flavour
     * @param int $memory
     * @param int $passes
     * @param string $salt
     * @return array
     */
    private static function generateV3Key($password, $flavour, $memory, $passes, $salt)
    {
        if (!function_exists('sodium_crypto_pwhash')) {
            throw new \RuntimeException('sodium_crypto_pwhash is required for Argon2 password hashing');
        }

        switch ($flavour) {
            case 'Argon2i':
                $flavour = SODIUM_CRYPTO_PWHASH_ALG_ARGON2I13;
                break;
            case 'Argon2id':
                $flavour = SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;
                break;
            default:
                throw new UnsupportedAlgorithmException('Only Argon2i and Argon2id are supported');
        }

        $length = 80; // keylen + ivlen + mac_keylen
        $temp = sodium_crypto_pwhash($length, $password, $salt, $passes, $memory * 1024, $flavour);

        $symkey = substr($temp, 0, 32);
        $symiv = substr($temp, 32, 16);
        $hashkey = substr($temp, -32);

        return compact('symkey', 'symiv', 'hashkey');
    }

    /**
     * Break a public or private key down into its constituent components
     *
     * @param string $key
     * @param string $password
     * @return array
     */
    public static function load($key, $password)
    {
        if (!Strings::is_stringable($key)) {
            throw new \UnexpectedValueException('Key should be a string - not a ' . gettype($key));
        }
        $key = (string) $key;
        if (strlen($key) > 4194304) {
            throw new \UnexpectedValueException('PuTTY key exceeds the 4 MiB parsing limit');
        }

        if (strncmp($key, '---- BEGIN SSH2 PUBLIC KEY ----', 31) === 0) {
            $lines = preg_split('#\r\n|\r|\n#', $key);
            switch (true) {
                case $lines[0] != '---- BEGIN SSH2 PUBLIC KEY ----':
                    throw new \UnexpectedValueException('Key doesn\'t start with ---- BEGIN SSH2 PUBLIC KEY ----');
                case $lines[count($lines) - 1] != '---- END SSH2 PUBLIC KEY ----':
                    throw new \UnexpectedValueException('Key doesn\'t end with ---- END SSH2 PUBLIC KEY ----');
            }
            $lines = array_splice($lines, 1, -1);
            $lines = array_map(function ($line) {
                return rtrim($line, "\r\n");
            }, $lines);
            $data = $current = '';
            $values = [];
            $in_value = false;
            $dataStarted = false;
            foreach ($lines as $line) {
                if (!is_string($line) || strlen($line) > 4096 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $line)) {
                    throw new \UnexpectedValueException('SSH2 public-key line is invalid');
                }
                switch (true) {
                    case $in_value:
                        if ($line === '') {
                            throw new \UnexpectedValueException('SSH2 public-key header continuation is empty');
                        }
                        $in_value = $line[strlen($line) - 1] == '\\';
                        $values[$current] .= $in_value ? substr($line, 0, -1) : $line;
                        if (strlen($values[$current]) > 4096) {
                            throw new \UnexpectedValueException('SSH2 public-key header value is too long');
                        }
                        break;
                    case preg_match('#^([A-Za-z0-9-]{1,64}): (.*)$#D', $line, $match) === 1:
                        if ($dataStarted || isset($values[strtolower($match[1])])) {
                            throw new \UnexpectedValueException('SSH2 public-key headers must precede key data and must not repeat');
                        }
                        $in_value = $line[strlen($line) - 1] == '\\';
                        $current = strtolower($match[1]);
                        $values[$current] = $in_value ? substr($match[2], 0, -1) : $match[2];
                        break;
                    default:
                        if ($line === '' || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $line)) {
                            throw new \UnexpectedValueException('SSH2 public-key Base64 is invalid');
                        }
                        $dataStarted = true;
                        $data .= $line;
                }
            }
            if ($in_value || $data === '') {
                throw new \UnexpectedValueException('SSH2 public key is incomplete');
            }

            $components = call_user_func([static::PUBLIC_HANDLER, 'load'], $data);
            if ($components === false) {
                throw new \UnexpectedValueException('Unable to decode public key');
            }
            $components += $values;
            $components['comment'] = isset($values['comment']) ?
                str_replace(['\\\\', '\"'], ['\\', '"'], $values['comment']) :
                '';

            return $components;
        }

        $components = [];

        $key = preg_split('#\r\n|\r|\n#', trim($key));
        if (!isset($key[0]) || strncmp($key[0], 'PuTTY-User-Key-File-', 20) !== 0) {
            return false;
        }
        if (!preg_match('#^PuTTY-User-Key-File-(2|3): ([^\x00-\x20\x7f]{1,128})$#D', $key[0], $header)) {
            throw new \UnexpectedValueException('PuTTY private-key header is malformed');
        }
        $version = (int) $header[1];
        if ($version !== 2 && $version !== 3) {
            throw new \RuntimeException('Only v2 and v3 PuTTY private keys are supported');
        }
        $components['type'] = $type = $header[2];
        if (!in_array($type, static::$types, true)) {
            $error = count(static::$types) == 1 ?
                'Only ' . static::$types[0] . ' keys are supported. ' :
                '';
            throw new UnsupportedAlgorithmException($error . 'This is an unsupported ' . $type . ' key');
        }
        $offset = 1;
        $read = static function ($label, $pattern = '.*') use (&$key, &$offset) {
            if (!isset($key[$offset]) || !preg_match('#^' . preg_quote($label, '#') . ': (' . $pattern . ')$#D', $key[$offset], $match)) {
                throw new \UnexpectedValueException("PuTTY private-key $label field is malformed or missing");
            }
            $offset++;
            return $match[1];
        };
        $decodeLines = static function ($label, $count) use (&$key, &$offset) {
            if (!is_int($count) || $count < 1 || $count > 16384 || $offset + $count > count($key)) {
                throw new \UnexpectedValueException("PuTTY private-key $label line count is invalid");
            }
            $encoded = '';
            for ($i = 0; $i < $count; $i++, $offset++) {
                $line = $key[$offset];
                if (!is_string($line) || $line === '' || strlen($line) > 128 || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $line)) {
                    throw new \UnexpectedValueException("PuTTY private-key $label Base64 is invalid");
                }
                $encoded .= $line;
            }
            try {
                $decoded = Strings::base64_decode($encoded);
            } catch (\Throwable $error) {
                throw new \UnexpectedValueException("PuTTY private-key $label Base64 is invalid", 0, $error);
            }
            if (!is_string($decoded) || Strings::base64_encode($decoded) !== $encoded) {
                throw new \UnexpectedValueException("PuTTY private-key $label Base64 is noncanonical");
            }
            return $decoded;
        };

        $encryption = $read('Encryption', 'none|aes256-cbc');
        $components['comment'] = $read('Comment');
        if (strlen($components['comment']) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $components['comment'])) {
            throw new \UnexpectedValueException('PuTTY private-key comment is invalid');
        }
        $publicLengthText = $read('Public-Lines', '0|[1-9][0-9]{0,4}');
        $public = $decodeLines('public', (int) $publicLengthText);

        $source = Strings::packSSH2('ssss', $type, $encryption, $components['comment'], $public);

        try {
            $newtype = Strings::unpackSSH2('s', $public)[0];
        } catch (\Throwable $error) {
            throw new \UnexpectedValueException('PuTTY public key blob is malformed', 0, $error);
        }
        if (!hash_equals($type, $newtype)) {
            throw new \RuntimeException('The binary type does not match the human readable type field');
        }

        $components['public'] = $public;
        $components['version'] = $version;
        $components['encryption'] = $encryption;

        switch ($version) {
            case 3:
                $hashkey = '';
                break;
            case 2:
                $hashkey = 'putty-private-key-file-mac-key';
        }

        switch ($encryption) {
            case 'aes256-cbc':
                if (!Strings::is_stringable($password)) {
                    throw new \UnexpectedValueException('PuTTY private-key password must be a string');
                }
                $password = (string) $password;
                $crypto = new AES('cbc');
                switch ($version) {
                    case 3:
                        $flavour = $read('Key-Derivation', 'Argon2i|Argon2id');
                        $memory = (int) $read('Argon2-Memory', '[1-9][0-9]{0,8}');
                        $passes = (int) $read('Argon2-Passes', '[1-9][0-9]{0,5}');
                        $parallelism = (int) $read('Argon2-Parallelism', '[1-9][0-9]{0,5}');
                        $saltText = $read('Argon2-Salt', '[0-9a-fA-F]{32}');
                        if ($memory < 8 || $memory > 65536 || $passes < 1 || $passes > 32 || $parallelism !== 1) {
                            throw new \UnexpectedValueException('PuTTY Argon2 work factors exceed supported safety limits');
                        }
                        $salt = Strings::hex2bin($saltText);

                        $v3key = self::generateV3Key($password, $flavour, $memory, $passes, $salt);
                        $symkey = $v3key['symkey'];
                        $symiv = $v3key['symiv'];
                        $hashkey = $v3key['hashkey'];

                        break;
                    case 2:
                        $symkey = self::generateV2Key($password, 32);
                        $symiv = str_repeat("\0", $crypto->getBlockLength() >> 3);
                        $hashkey .= $password;
                }
        }

        switch ($version) {
            case 3:
                $hash = new Hash('sha256');
                $hash->setKey($hashkey);
                break;
            case 2:
                $hash = new Hash('sha1');
                $hash->setKey(sha1($hashkey, true));
        }

        $privateLengthText = $read('Private-Lines', '0|[1-9][0-9]{0,4}');
        $privateLength = (int) $privateLengthText;
        $private = $decodeLines('private', $privateLength);

        if ($encryption != 'none') {
            if ($private === '' || (strlen($private) & 15)) {
                throw new \UnexpectedValueException('Encrypted PuTTY private-key data is not block aligned');
            }
            $crypto->setKey($symkey);
            $crypto->setIV($symiv);
            $crypto->disablePadding();
            $private = $crypto->decrypt($private);
        }

        $source .= Strings::packSSH2('s', $private);

        $macLength = $version === 3 ? 64 : 40;
        $hmacText = $read('Private-MAC', '[0-9a-fA-F]{' . $macLength . '}');
        if ($offset !== count($key)) {
            throw new \UnexpectedValueException('PuTTY private key contains trailing fields');
        }
        $hmac = Strings::hex2bin($hmacText);

        if (!hash_equals($hash->hash($source), $hmac)) {
            throw new \UnexpectedValueException('MAC validation error');
        }

        $components['private'] = $private;

        return $components;
    }

    /**
     * Wrap a private key appropriately
     *
     * @param string $public
     * @param string $private
     * @param string $type
     * @param string $password
     * @param array $options optional
     * @return string
     */
    protected static function wrapPrivateKey($public, $private, $type, $password, array $options = [])
    {
        if (!is_string($type) || !in_array($type, static::$types, true)) {
            throw new UnsupportedAlgorithmException('Unsupported PuTTY key type');
        }
        if (Strings::is_stringable($password)) {
            $password = (string) $password;
            $encryption = 'aes256-cbc';
        } elseif ($password === null || $password === false) {
            $encryption = 'none';
        } else {
            throw new \UnexpectedValueException('PuTTY private-key password must be a string, null or false');
        }
        $comment = isset($options['comment']) ? $options['comment'] : self::$comment;
        $version = isset($options['version']) ? $options['version'] : self::$version;
        if (!Strings::is_stringable($comment)) {
            throw new \UnexpectedValueException('PuTTY key comment must be a string');
        }
        $comment = str_replace(["\r", "\n"], '', (string) $comment);
        if (strlen($comment) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $comment)) {
            throw new \UnexpectedValueException('PuTTY key comment is invalid');
        }
        if (!is_int($version) || ($version !== 2 && $version !== 3)) {
            throw new \UnexpectedValueException('PuTTY key version must be 2 or 3');
        }

        $key = "PuTTY-User-Key-File-$version: $type\r\n";
        $key .= "Encryption: $encryption\r\n";
        $key .= "Comment: $comment\r\n";

        $public = Strings::packSSH2('s', $type) . $public;

        $source = Strings::packSSH2('ssss', $type, $encryption, $comment, $public);

        $public = Strings::base64_encode($public);
        $key .= "Public-Lines: " . ((strlen($public) + 63) >> 6) . "\r\n";
        $key .= chunk_split($public, 64);

        if ($encryption === 'none') {
            $source .= Strings::packSSH2('s', $private);
            switch ($version) {
                case 3:
                    $hash = new Hash('sha256');
                    $hash->setKey('');
                    break;
                case 2:
                    $hash = new Hash('sha1');
                    $hash->setKey(sha1('putty-private-key-file-mac-key', true));
            }
        } else {
            $private .= Random::string(16 - (strlen($private) & 15));
            $source .= Strings::packSSH2('s', $private);
            $crypto = new AES('cbc');

            switch ($version) {
                case 3:
                    $salt = Random::string(16);
                    $key .= "Key-Derivation: Argon2id\r\n";
                    $key .= "Argon2-Memory: 8192\r\n";
                    $key .= "Argon2-Passes: 13\r\n";
                    $key .= "Argon2-Parallelism: 1\r\n";
                    $key .= "Argon2-Salt: " . Strings::bin2hex($salt) . "\r\n";
                    $v3key = self::generateV3Key($password, 'Argon2id', 8192, 13, $salt);
                    $symkey = $v3key['symkey'];
                    $symiv = $v3key['symiv'];
                    $hashkey = $v3key['hashkey'];

                    $hash = new Hash('sha256');
                    $hash->setKey($hashkey);

                    break;
                case 2:
                    $symkey = self::generateV2Key($password, 32);
                    $symiv = str_repeat("\0", $crypto->getBlockLength() >> 3);
                    $hashkey = 'putty-private-key-file-mac-key' . $password;

                    $hash = new Hash('sha1');
                    $hash->setKey(sha1($hashkey, true));
            }

            $crypto->setKey($symkey);
            $crypto->setIV($symiv);
            $crypto->disablePadding();
            $private = $crypto->encrypt($private);
        }

        $private = Strings::base64_encode($private);
        $key .= 'Private-Lines: ' . ((strlen($private) + 63) >> 6) . "\r\n";
        $key .= chunk_split($private, 64);
        $key .= 'Private-MAC: ' . Strings::bin2hex($hash->hash($source)) . "\r\n";

        return $key;
    }

    /**
     * Wrap a public key appropriately
     *
     * This is basically the format described in RFC 4716 (https://tools.ietf.org/html/rfc4716)
     *
     * @param string $key
     * @param string $type
     * @return string
     */
    protected static function wrapPublicKey($key, $type)
    {
        if (!is_string($type) || !in_array($type, static::$types, true)) {
            throw new UnsupportedAlgorithmException('Unsupported PuTTY key type');
        }
        $key = pack('Na*a*', strlen($type), $type, $key);
        $key = "---- BEGIN SSH2 PUBLIC KEY ----\r\n" .
               'Comment: "' . str_replace(['\\', '"'], ['\\\\', '\"'], self::$comment) . "\"\r\n" .
               chunk_split(Strings::base64_encode($key), 64) .
               '---- END SSH2 PUBLIC KEY ----';
        return $key;
    }
}
