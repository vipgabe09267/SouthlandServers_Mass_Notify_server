<?php

/**
 * PKCS#1 Formatted RSA Key Handler
 *
 * PHP version 5
 *
 * Used by File/X509.php
 *
 * Processes keys with the following headers:
 *
 * -----BEGIN RSA PRIVATE KEY-----
 * -----BEGIN RSA PUBLIC KEY-----
 *
 * Analogous to ssh-keygen's pem format (as specified by -m)
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\RSA\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\Common\Formats\Keys\PKCS1 as Progenitor;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\Math\BigInteger;

/**
 * PKCS#1 Formatted RSA Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class PKCS1 extends Progenitor
{
    /**
     * Break a public or private key down into its constituent components
     *
     * @param string $key
     * @param string $password optional
     * @return array
     */
    public static function load($key, $password = '')
    {
        if (!Strings::is_stringable($key)) {
            throw new \UnexpectedValueException('Key should be a string - not a ' . gettype($key));
        }
        $key = (string) $key;

        $declaredPublic = null;
        if (preg_match('#^\s*-----BEGIN RSA (PUBLIC|PRIVATE) KEY-----#', $key, $matches)) {
            $declaredPublic = $matches[1] === 'PUBLIC';
        }

        $key = parent::load($key, $password);

        $decoded = ASN1::decodeBER($key);
        if (
            !$decoded ||
            count($decoded) !== 1 ||
            ($decoded[0]['start'] ?? null) !== 0 ||
            ($decoded[0]['length'] ?? null) !== strlen($key)
        ) {
            throw new \RuntimeException('Unable to decode BER');
        }

        $key = ASN1::asn1map($decoded[0], Maps\RSAPrivateKey::MAP);
        if (is_array($key)) {
            if ($key['version'] === false) {
                throw new \UnexpectedValueException('Version number is not valid');
            }
            if ($declaredPublic === true) {
                throw new \UnexpectedValueException('RSA PUBLIC KEY header contains a private key');
            }
            $components = [
                'isPublicKey' => false,
                'modulus' => $key['modulus'],
                'publicExponent' => $key['publicExponent'],
                'privateExponent' => $key['privateExponent'],
                'primes' => [1 => $key['prime1'], $key['prime2']],
                'exponents' => [1 => $key['exponent1'], $key['exponent2']],
                'coefficients' => [2 => $key['coefficient']]
            ];
            $otherPrimeInfos = $key['otherPrimeInfos'] ?? [];
            if ($key['version'] === 'multi') {
                if (!is_array($otherPrimeInfos) || $otherPrimeInfos === []) {
                    throw new \UnexpectedValueException('Multi-prime RSA key has no additional prime information');
                }
                foreach ($otherPrimeInfos as $primeInfo) {
                    $components['primes'][] = $primeInfo['prime'];
                    $components['exponents'][] = $primeInfo['exponent'];
                    $components['coefficients'][] = $primeInfo['coefficient'];
                }
            } elseif ($otherPrimeInfos !== []) {
                throw new \UnexpectedValueException('Two-prime RSA key contains additional prime information');
            }
            self::assertNonNegative($components);
            return $components;
        }

        $key = ASN1::asn1map($decoded[0], Maps\RSAPublicKey::MAP);

        if (!is_array($key)) {
            throw new \RuntimeException('Unable to perform ASN1 mapping');
        }

        if ($declaredPublic === false) {
            throw new \UnexpectedValueException('RSA PRIVATE KEY header contains a public key');
        }
        $components = ['isPublicKey' => true] + $key;
        self::assertNonNegative($components);
        return $components;
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
        $num_primes = count($primes);
        if (
            $num_primes < 2 ||
            array_keys($primes) !== range(1, $num_primes) ||
            array_keys($exponents) !== range(1, $num_primes) ||
            array_keys($coefficients) !== range(2, $num_primes)
        ) {
            throw new \InvalidArgumentException('RSA prime, exponent, and coefficient arrays must be complete and sequential');
        }
        $key = [
            'version' => $num_primes == 2 ? 'two-prime' : 'multi',
            'modulus' => $n,
            'publicExponent' => $e,
            'privateExponent' => $d,
            'prime1' => $primes[1],
            'prime2' => $primes[2],
            'exponent1' => $exponents[1],
            'exponent2' => $exponents[2],
            'coefficient' => $coefficients[2]
        ];
        for ($i = 3; $i <= $num_primes; $i++) {
            $key['otherPrimeInfos'][] = [
                'prime' => $primes[$i],
                'exponent' => $exponents[$i],
                'coefficient' => $coefficients[$i]
            ];
        }

        $key = ASN1::encodeDER($key, Maps\RSAPrivateKey::MAP);

        return self::wrapPrivateKey($key, 'RSA', $password, $options);
    }

    /**
     * Convert a public key to the appropriate format
     *
     * @param BigInteger $n
     * @param BigInteger $e
     * @return string
     */
    public static function savePublicKey(BigInteger $n, BigInteger $e)
    {
        $key = [
            'modulus' => $n,
            'publicExponent' => $e
        ];

        $key = ASN1::encodeDER($key, Maps\RSAPublicKey::MAP);

        return self::wrapPublicKey($key, 'RSA');
    }

    /**
     * Reject negative RSA integers rather than accepting an ambiguous encoding.
     *
     * @param mixed $value
     */
    private static function assertNonNegative($value)
    {
        if ($value instanceof BigInteger) {
            if ($value->isNegative()) {
                throw new \UnexpectedValueException('RSA key contains a negative integer');
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertNonNegative($child);
            }
        }
    }
}
