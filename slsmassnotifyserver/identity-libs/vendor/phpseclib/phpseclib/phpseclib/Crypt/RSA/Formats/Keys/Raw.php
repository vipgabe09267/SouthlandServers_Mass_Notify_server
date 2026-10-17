<?php

/**
 * Raw RSA Key Handler
 *
 * PHP version 5
 *
 * An array containing two \phpseclib3\Math\BigInteger objects.
 *
 * The exponent can be indexed with any of the following:
 *
 * 0, e, exponent, publicExponent
 *
 * The modulus can be indexed with any of the following:
 *
 * 1, n, modulo, modulus
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\RSA\Formats\Keys;

use phpseclib3\Exception\UnsupportedFormatException;
use phpseclib3\Math\BigInteger;

/**
 * Raw RSA Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class Raw
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
        if (!is_array($key)) {
            throw new \UnexpectedValueException('Key should be an array - not a ' . gettype($key));
        }

        $normalized = [];
        foreach ($key as $index => $value) {
            $canonical = is_string($index) ? strtolower($index) : $index;
            if (array_key_exists($canonical, $normalized)) {
                throw new \UnexpectedValueException('Raw RSA key contains case-insensitive duplicate fields');
            }
            $normalized[$canonical] = $value;
        }
        $key = $normalized;

        $publicExponent = self::alias($key, ['e', 'exponent', 'publicexponent', 0], 'public exponent');
        $modulus = self::alias($key, ['n', 'modulo', 'modulus', 1], 'modulus');
        if (!$publicExponent instanceof BigInteger || !$modulus instanceof BigInteger) {
            throw new \UnexpectedValueException('Raw RSA modulus and public exponent are required');
        }

        static $one;
        if (!isset($one)) {
            $one = new BigInteger(1);
        }
        if (
            $modulus->compare($one) <= 0 ||
            !$modulus->isOdd() ||
            $publicExponent->compare($one) <= 0 ||
            !$publicExponent->isOdd() ||
            $publicExponent->compare($modulus) >= 0
        ) {
            throw new \UnexpectedValueException('Raw RSA modulus or public exponent is invalid');
        }

        $privateExponent = self::alias($key, ['privateexponent', 'd'], 'private exponent');
        $primes = self::arrayRepresentations($key, 'primes', [['p', 'q'], ['prime1', 'prime2']], 'primes', 1);
        $exponents = self::arrayRepresentations($key, 'exponents', [['dp', 'dq'], ['exponent1', 'exponent2']], 'CRT exponents', 1);
        $coefficients = self::coefficientRepresentations($key);

        if ($primes === null) {
            if ($privateExponent !== null || $exponents !== null || $coefficients !== null) {
                throw new \UnexpectedValueException('Raw RSA private fields require a complete prime set');
            }
            return compact('publicExponent', 'modulus') + ['isPublicKey' => true];
        }
        if (count($primes) < 2 || count($primes) > 16) {
            throw new \UnexpectedValueException('Raw RSA private keys require between two and sixteen primes');
        }

        $product = new BigInteger(1);
        $lambda = new BigInteger(1);
        foreach ($primes as $index => $prime) {
            if ($prime->compare($one) <= 0 || !$prime->isOdd()) {
                throw new \UnexpectedValueException('Raw RSA primes must be odd integers greater than one');
            }
            foreach ($primes as $priorIndex => $prior) {
                if ($priorIndex >= $index) {
                    break;
                }
                if ($prime->equals($prior)) {
                    throw new \UnexpectedValueException('Raw RSA primes must be distinct');
                }
            }
            $product = $product->multiply($prime);
            $minusOne = $prime->subtract($one);
            $lambda = $lambda->divide($lambda->gcd($minusOne))[0]->multiply($minusOne);
        }
        if (!$product->equals($modulus)) {
            throw new \UnexpectedValueException('Raw RSA primes do not multiply to the modulus');
        }

        if ($privateExponent === null) {
            $privateExponent = $publicExponent->modInverse($lambda);
        }
        if (
            !$privateExponent instanceof BigInteger ||
            $privateExponent->compare($one) <= 0 ||
            $privateExponent->compare($modulus) >= 0 ||
            !$publicExponent->multiply($privateExponent)->divide($lambda)[1]->equals($one)
        ) {
            throw new \UnexpectedValueException('Raw RSA private exponent is inconsistent');
        }

        $canonicalExponents = [];
        foreach ($primes as $index => $prime) {
            $canonicalExponents[$index] = $privateExponent->divide($prime->subtract($one))[1];
        }
        self::requireEqualArrays($exponents, $canonicalExponents, 'Raw RSA CRT exponents are inconsistent');
        $exponents = $canonicalExponents;

        $canonicalCoefficients = [2 => $primes[2]->modInverse($primes[1])];
        $runningProduct = $primes[1]->multiply($primes[2]);
        for ($index = 3; $index <= count($primes); $index++) {
            $canonicalCoefficients[$index] = $runningProduct->modInverse($primes[$index]);
            $runningProduct = $runningProduct->multiply($primes[$index]);
        }
        self::requireEqualArrays($coefficients, $canonicalCoefficients, 'Raw RSA CRT coefficients are inconsistent');
        $coefficients = $canonicalCoefficients;

        return compact('publicExponent', 'modulus', 'privateExponent', 'primes', 'exponents', 'coefficients') +
            ['isPublicKey' => false];
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
     * @return array
     */
    public static function savePrivateKey(BigInteger $n, BigInteger $e, BigInteger $d, array $primes, array $exponents, array $coefficients, $password = '', array $options = [])
    {
        if (is_object($password) && method_exists($password, '__toString')) {
            $password = (string) $password;
        }
        if (is_string($password) && $password !== '') {
            throw new UnsupportedFormatException('Raw private keys do not support encryption');
        }
        if ($password !== '' && $password !== false && $password !== null) {
            throw new \UnexpectedValueException('Raw private-key password must be empty, false or null');
        }

        $components = self::load([
            'e' => $e,
            'n' => $n,
            'd' => $d,
            'primes' => $primes,
            'exponents' => $exponents,
            'coefficients' => $coefficients
        ]);

        return [
            'e' => clone $components['publicExponent'],
            'n' => clone $components['modulus'],
            'd' => clone $components['privateExponent'],
            'primes' => array_map(function ($var) {
                return clone $var;
            }, $components['primes']),
            'exponents' => array_map(function ($var) {
                return clone $var;
            }, $components['exponents']),
            'coefficients' => array_map(function ($var) {
                return clone $var;
            }, $components['coefficients'])
        ];
    }

    /**
     * Convert a public key to the appropriate format
     *
     * @param BigInteger $n
     * @param BigInteger $e
     * @return array
     */
    public static function savePublicKey(BigInteger $n, BigInteger $e)
    {
        $components = self::load(['e' => $e, 'n' => $n]);
        return ['e' => clone $components['publicExponent'], 'n' => clone $components['modulus']];
    }

    /** @return BigInteger|null */
    private static function alias(array $key, array $aliases, $label)
    {
        $value = null;
        foreach ($aliases as $alias) {
            if (!array_key_exists($alias, $key)) {
                continue;
            }
            if (!$key[$alias] instanceof BigInteger) {
                throw new \UnexpectedValueException("Raw RSA $label must be a BigInteger");
            }
            if ($value !== null && !$value->equals($key[$alias])) {
                throw new \UnexpectedValueException("Raw RSA $label aliases conflict");
            }
            $value = $key[$alias];
        }
        return $value;
    }

    /** @return array|null */
    private static function arrayRepresentations(array $key, $arrayName, array $pairs, $label, $firstIndex)
    {
        $representations = [];
        if (array_key_exists($arrayName, $key)) {
            $representations[] = self::integerArray($key[$arrayName], $label, $firstIndex);
        }
        foreach ($pairs as $pair) {
            $hasFirst = array_key_exists($pair[0], $key);
            $hasSecond = array_key_exists($pair[1], $key);
            if ($hasFirst xor $hasSecond) {
                throw new \UnexpectedValueException("Raw RSA $label aliases are incomplete");
            }
            if ($hasFirst) {
                $representations[] = self::integerArray(
                    [$firstIndex => $key[$pair[0]], $firstIndex + 1 => $key[$pair[1]]],
                    $label,
                    $firstIndex
                );
            }
        }
        if (!$representations) {
            return null;
        }
        $value = array_shift($representations);
        foreach ($representations as $candidate) {
            self::requireEqualArrays($candidate, $value, "Raw RSA $label aliases conflict");
        }
        return $value;
    }

    /** @return array|null */
    private static function coefficientRepresentations(array $key)
    {
        $representations = [];
        if (array_key_exists('coefficients', $key)) {
            $representations[] = self::integerArray($key['coefficients'], 'CRT coefficients', 2);
        }
        foreach (['inverseq', "q'", 'coefficient'] as $alias) {
            if (array_key_exists($alias, $key)) {
                $representations[] = self::integerArray([2 => $key[$alias]], 'CRT coefficients', 2);
            }
        }
        if (!$representations) {
            return null;
        }
        $value = array_shift($representations);
        foreach ($representations as $candidate) {
            self::requireEqualArrays($candidate, $value, 'Raw RSA CRT coefficient aliases conflict');
        }
        return $value;
    }

    /** @return array */
    private static function integerArray($value, $label, $firstIndex)
    {
        if (!is_array($value) || !$value || array_keys($value) !== range($firstIndex, $firstIndex + count($value) - 1)) {
            throw new \UnexpectedValueException("Raw RSA $label must use contiguous integer indexes");
        }
        foreach ($value as $component) {
            if (!$component instanceof BigInteger) {
                throw new \UnexpectedValueException("Raw RSA $label must contain only BigInteger values");
            }
        }
        return $value;
    }

    private static function requireEqualArrays($provided, array $canonical, $message)
    {
        if ($provided === null) {
            return;
        }
        if (array_keys($provided) !== array_keys($canonical)) {
            throw new \UnexpectedValueException($message);
        }
        foreach ($canonical as $index => $value) {
            if (!$value->equals($provided[$index])) {
                throw new \UnexpectedValueException($message);
            }
        }
    }
}
