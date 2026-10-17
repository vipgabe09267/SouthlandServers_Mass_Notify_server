<?php

/**
 * OpenSSH Formatted RSA Key Handler
 *
 * PHP version 5
 *
 * Place in $HOME/.ssh/authorized_keys
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\RSA\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\Common\Formats\Keys\OpenSSH as Progenitor;
use phpseclib3\Math\BigInteger;

/**
 * OpenSSH Formatted RSA Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class OpenSSH extends Progenitor
{
    /**
     * Supported Key Types
     *
     * @var array
     */
    protected static $types = ['ssh-rsa'];

    /**
     * Break a public or private key down into its constituent components
     *
     * @param string $key
     * @param string $password optional
     * @return array
     */
    public static function load($key, $password = '')
    {
        static $one;
        if (!isset($one)) {
            $one = new BigInteger(1);
        }

        $parsed = parent::load($key, $password);

        if (isset($parsed['paddedKey'])) {
            list($type) = Strings::unpackSSH2('s', $parsed['paddedKey']);
            if ($type !== $parsed['type']) {
                throw new \RuntimeException("The public and private keys are not of the same type ($type vs {$parsed['type']})");
            }

            $primes = $coefficients = [];

            list(
                $modulus,
                $publicExponent,
                $privateExponent,
                $coefficients[2],
                $primes[1],
                $primes[2],
                $comment,
            ) = Strings::unpackSSH2('i6s', $parsed['paddedKey']);

            $publicKey = $parsed['publicKey'];
            list($outerPublicExponent, $outerModulus) = Strings::unpackSSH2('ii', $publicKey);
            if (
                $publicKey !== '' ||
                !$outerPublicExponent->equals($publicExponent) ||
                !$outerModulus->equals($modulus)
            ) {
                throw new \UnexpectedValueException('OpenSSH public and private RSA components do not match');
            }
            if (strlen($parsed['paddedKey']) > 15) {
                throw new \UnexpectedValueException('OpenSSH private-key padding is too long');
            }
            for ($i = 1, $length = strlen($parsed['paddedKey']); $i <= $length; $i++) {
                if (ord($parsed['paddedKey'][$i - 1]) !== $i) {
                    throw new \UnexpectedValueException('OpenSSH private-key padding is invalid');
                }
            }

            $temp = $primes[1]->subtract($one);
            $exponents = [1 => $publicExponent->modInverse($temp)];
            $temp = $primes[2]->subtract($one);
            $exponents[] = $publicExponent->modInverse($temp);

            $isPublicKey = false;

            return compact('publicExponent', 'modulus', 'privateExponent', 'primes', 'coefficients', 'exponents', 'comment', 'isPublicKey');
        }

        list($publicExponent, $modulus) = Strings::unpackSSH2('ii', $parsed['publicKey']);
        if ($parsed['publicKey'] !== '') {
            throw new \UnexpectedValueException('OpenSSH RSA public key contains trailing data');
        }

        return [
            'isPublicKey' => true,
            'modulus' => $modulus,
            'publicExponent' => $publicExponent,
            'comment' => $parsed['comment']
        ];
    }

    /**
     * Convert a public key to the appropriate format
     *
     * @param BigInteger $n
     * @param BigInteger $e
     * @param array $options optional
     * @return string
     */
    public static function savePublicKey(BigInteger $n, BigInteger $e, array $options = [])
    {
        $RSAPublicKey = Strings::packSSH2('sii', 'ssh-rsa', $e, $n);

        if (isset($options['binary']) ? $options['binary'] : self::$binary) {
            return $RSAPublicKey;
        }

        $comment = isset($options['comment']) ? $options['comment'] : self::$comment;
        if (!Strings::is_stringable($comment)) {
            throw new \UnexpectedValueException('OpenSSH key comment must be a string');
        }
        $comment = str_replace(["\r", "\n"], '', (string) $comment);
        $RSAPublicKey = 'ssh-rsa ' . base64_encode($RSAPublicKey) . ' ' . $comment;

        return $RSAPublicKey;
    }

    /**
     * Convert a private key to the appropriate format.
     *
     * @param BigInteger $n
     * @param BigInteger $e
     * @param BigInteger $d
     * @param array $primes
     * @param array $exponents
     * @param array $coefficients
     * @param string $password optional
     * @param array $options optional
     * @return string
     */
    public static function savePrivateKey(BigInteger $n, BigInteger $e, BigInteger $d, array $primes, array $exponents, array $coefficients, $password = '', array $options = [])
    {
        if (count($primes) !== 2) {
            throw new \InvalidArgumentException('OpenSSH does not support multi-prime RSA keys');
        }
        if (array_key_exists('comment', $options)) {
            if (!Strings::is_stringable($options['comment'])) {
                throw new \UnexpectedValueException('OpenSSH key comment must be a string');
            }
            $options['comment'] = str_replace(["\r", "\n"], '', (string) $options['comment']);
        }
        $publicKey = self::savePublicKey($n, $e, ['binary' => true]);
        $privateKey = Strings::packSSH2('si6', 'ssh-rsa', $n, $e, $d, $coefficients[2], $primes[1], $primes[2]);

        return self::wrapPrivateKey($publicKey, $privateKey, $password, $options);
    }
}
