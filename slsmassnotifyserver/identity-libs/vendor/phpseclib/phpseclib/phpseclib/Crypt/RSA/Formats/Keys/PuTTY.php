<?php

/**
 * PuTTY Formatted RSA Key Handler
 *
 * PHP version 5
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\RSA\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\Common\Formats\Keys\PuTTY as Progenitor;
use phpseclib3\Math\BigInteger;

/**
 * PuTTY Formatted RSA Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class PuTTY extends Progenitor
{
    /**
     * Public Handler
     *
     * @var string
     */
    const PUBLIC_HANDLER = 'phpseclib3\Crypt\RSA\Formats\Keys\OpenSSH';

    /**
     * Algorithm Identifier
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
        $components = parent::load($key, $password);
        if (!isset($components['private'])) {
            return $components;
        }
        $comment = $components['comment'];
        $encryption = $components['encryption'] ?? null;
        $public = $components['public'];
        $private = $components['private'];
        unset($components['public'], $components['private'], $components['encryption'], $components['version']);

        $isPublicKey = false;

        try {
            $result = Strings::unpackSSH2('ii', $public);
        } catch (\Throwable $error) {
            throw new \UnexpectedValueException('PuTTY RSA public data is malformed', 0, $error);
        }
        if ($public !== '') {
            throw new \UnexpectedValueException('PuTTY RSA public data contains trailing bytes');
        }
        list($publicExponent, $modulus) = $result;

        try {
            $result = Strings::unpackSSH2('iiii', $private);
        } catch (\Throwable $error) {
            throw new \UnexpectedValueException('PuTTY RSA private data is malformed', 0, $error);
        }
        if (($encryption === 'none' && $private !== '') || strlen($private) > 16) {
            throw new \UnexpectedValueException('PuTTY RSA private data contains invalid trailing padding');
        }
        $primes = $coefficients = [];
        list($privateExponent, $primes[1], $primes[2], $coefficients[2]) = $result;

        $exponents = self::validatePrivateComponents(
            $modulus,
            $publicExponent,
            $privateExponent,
            $primes,
            null,
            $coefficients
        );

        return compact('publicExponent', 'modulus', 'privateExponent', 'primes', 'coefficients', 'exponents', 'comment', 'isPublicKey');
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
        if (array_keys($primes) !== [1, 2]) {
            throw new \InvalidArgumentException('PuTTY does not support multi-prime RSA keys');
        }
        if (array_keys($exponents) !== [1, 2] || array_keys($coefficients) !== [2]) {
            throw new \InvalidArgumentException('PuTTY requires complete two-prime RSA CRT components');
        }
        self::validatePrivateComponents($n, $e, $d, $primes, $exponents, $coefficients);

        $public =  Strings::packSSH2('ii', $e, $n);
        $private = Strings::packSSH2('iiii', $d, $primes[1], $primes[2], $coefficients[2]);

        return self::wrapPrivateKey($public, $private, 'ssh-rsa', $password, $options);
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
        return self::wrapPublicKey(Strings::packSSH2('ii', $e, $n), 'ssh-rsa');
    }

    /**
     * Validate two-prime RSA material and return canonical CRT exponents.
     *
     * @param BigInteger $n
     * @param BigInteger $e
     * @param BigInteger $d
     * @param array $primes
     * @param array|null $exponents
     * @param array $coefficients
     * @return array
     */
    private static function validatePrivateComponents(BigInteger $n, BigInteger $e, BigInteger $d, array $primes, $exponents, array $coefficients)
    {
        static $one;
        if (!isset($one)) {
            $one = new BigInteger(1);
        }
        if (
            !isset($primes[1], $primes[2], $coefficients[2]) ||
            !$primes[1] instanceof BigInteger ||
            !$primes[2] instanceof BigInteger ||
            !$coefficients[2] instanceof BigInteger ||
            $primes[1]->compare($one) <= 0 ||
            $primes[2]->compare($one) <= 0 ||
            !$primes[1]->isOdd() ||
            !$primes[2]->isOdd() ||
            $primes[1]->equals($primes[2]) ||
            !$primes[1]->multiply($primes[2])->equals($n)
        ) {
            throw new \UnexpectedValueException('PuTTY RSA primes do not match the modulus');
        }
        if (
            $e->compare($one) <= 0 ||
            !$e->isOdd() ||
            $e->compare($n) >= 0 ||
            $d->compare($one) <= 0 ||
            $d->compare($n) >= 0
        ) {
            throw new \UnexpectedValueException('PuTTY RSA public or private exponent is invalid');
        }

        $pMinusOne = $primes[1]->subtract($one);
        $qMinusOne = $primes[2]->subtract($one);
        $lcm = $pMinusOne->divide($pMinusOne->gcd($qMinusOne))[0]->multiply($qMinusOne);
        if (!$e->multiply($d)->divide($lcm)[1]->equals($one)) {
            throw new \UnexpectedValueException('PuTTY RSA exponents are inconsistent');
        }
        $expectedCoefficient = $primes[2]->modInverse($primes[1]);
        if (!$expectedCoefficient->equals($coefficients[2])) {
            throw new \UnexpectedValueException('PuTTY RSA CRT coefficient is inconsistent');
        }

        $canonical = [
            1 => $d->divide($pMinusOne)[1],
            2 => $d->divide($qMinusOne)[1]
        ];
        if ($exponents !== null && (
            !isset($exponents[1], $exponents[2]) ||
            !$exponents[1] instanceof BigInteger ||
            !$exponents[2] instanceof BigInteger ||
            !$canonical[1]->equals($exponents[1]) ||
            !$canonical[2]->equals($exponents[2])
        )) {
            throw new \UnexpectedValueException('PuTTY RSA CRT exponents are inconsistent');
        }

        return $canonical;
    }
}
