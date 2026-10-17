<?php

/**
 * Microsoft BLOB Formatted RSA Key Handler
 *
 * More info:
 *
 * https://msdn.microsoft.com/en-us/library/windows/desktop/aa375601(v=vs.85).aspx
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
use phpseclib3\Exception\UnsupportedFormatException;
use phpseclib3\Math\BigInteger;

/**
 * Microsoft BLOB Formatted RSA Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class MSBLOB
{
    /**
     * Public/Private Key Pair
     *
     */
    const PRIVATEKEYBLOB = 0x7;
    /**
     * Public Key
     *
     */
    const PUBLICKEYBLOB = 0x6;
    /**
     * Public Key
     *
     */
    const PUBLICKEYBLOBEX = 0xA;
    /**
     * RSA public key exchange algorithm
     *
     */
    const CALG_RSA_KEYX = 0x0000A400;
    /**
     * RSA public key exchange algorithm
     *
     */
    const CALG_RSA_SIGN = 0x00002400;
    /**
     * Public Key
     *
     */
    const RSA1 = 0x31415352;
    /**
     * Private Key
     *
     */
    const RSA2 = 0x32415352;

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

        $key = Strings::base64_decode((string) $key);

        if (!is_string($key)) {
            throw new \UnexpectedValueException('Base64 decoding produced an error');
        }
        if (strlen($key) < 20) {
            throw new \UnexpectedValueException('Key appears to be malformed');
        }

        // PUBLICKEYSTRUC  publickeystruc
        // https://msdn.microsoft.com/en-us/library/windows/desktop/aa387453(v=vs.85).aspx
        $unpacked = unpack('atype/aversion/vreserved/Valgo', Strings::shift($key, 8));
        $type = $unpacked['type'];
        $version = $unpacked['version'];
        $reserved = $unpacked['reserved'];
        $algo = $unpacked['algo'];
        if (ord($version) !== 2 || $reserved !== 0) {
            throw new \UnexpectedValueException('Key appears to have an invalid MSBLOB header');
        }
        switch (ord($type)) {
            case self::PUBLICKEYBLOB:
            case self::PUBLICKEYBLOBEX:
                $publickey = true;
                break;
            case self::PRIVATEKEYBLOB:
                $publickey = false;
                break;
            default:
                throw new \UnexpectedValueException('Key appears to be malformed');
        }

        $components = ['isPublicKey' => $publickey];

        // https://msdn.microsoft.com/en-us/library/windows/desktop/aa375549(v=vs.85).aspx
        switch ($algo) {
            case self::CALG_RSA_KEYX:
            case self::CALG_RSA_SIGN:
                break;
            default:
                throw new \UnexpectedValueException('Key appears to be malformed');
        }

        // RSAPUBKEY rsapubkey
        // https://msdn.microsoft.com/en-us/library/windows/desktop/aa387685(v=vs.85).aspx
        // could do V for pubexp but that's unsigned 32-bit whereas some PHP installs only do signed 32-bit
        $unpacked = unpack('Vmagic/Vbitlen/a4pubexp', Strings::shift($key, 12));
        $magic = $unpacked['magic'];
        $bitlen = $unpacked['bitlen'];
        $pubexp = $unpacked['pubexp'];
        switch ($magic) {
            case self::RSA2:
                if ($publickey) {
                    throw new \UnexpectedValueException('Public MSBLOB has private-key magic');
                }
                break;
            case self::RSA1:
                if (!$publickey) {
                    throw new \UnexpectedValueException('Private MSBLOB has public-key magic');
                }
                break;
            default:
                throw new \UnexpectedValueException('Key appears to be malformed');
        }

        if ($bitlen < 16 || ($bitlen & 15)) {
            throw new \UnexpectedValueException('MSBLOB bit length must be a positive multiple of 16');
        }
        $baseLength = intdiv($bitlen, 16);
        $expectedLength = $publickey ? 2 * $baseLength : 9 * $baseLength;
        if (strlen($key) !== $expectedLength) {
            throw new \UnexpectedValueException('Key appears to be malformed');
        }

        $components['publicExponent'] = new BigInteger(strrev($pubexp), 256);
        // BYTE modulus[rsapubkey.bitlen/8]
        $components['modulus'] = new BigInteger(strrev(Strings::shift($key, 2 * $baseLength)), 256);

        if ($publickey) {
            return $components;
        }

        $components['isPublicKey'] = false;

        // BYTE prime1[rsapubkey.bitlen/16]
        $components['primes'] = [1 => new BigInteger(strrev(Strings::shift($key, $baseLength)), 256)];
        // BYTE prime2[rsapubkey.bitlen/16]
        $components['primes'][] = new BigInteger(strrev(Strings::shift($key, $baseLength)), 256);
        // BYTE exponent1[rsapubkey.bitlen/16]
        $components['exponents'] = [1 => new BigInteger(strrev(Strings::shift($key, $baseLength)), 256)];
        // BYTE exponent2[rsapubkey.bitlen/16]
        $components['exponents'][] = new BigInteger(strrev(Strings::shift($key, $baseLength)), 256);
        // BYTE coefficient[rsapubkey.bitlen/16]
        $components['coefficients'] = [2 => new BigInteger(strrev(Strings::shift($key, $baseLength)), 256)];
        // BYTE privateExponent[rsapubkey.bitlen/8]
        $components['privateExponent'] = new BigInteger(strrev(Strings::shift($key, 2 * $baseLength)), 256);

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
     * @return string
     */
    public static function savePrivateKey(BigInteger $n, BigInteger $e, BigInteger $d, array $primes, array $exponents, array $coefficients, $password = '')
    {
        if (count($primes) != 2) {
            throw new \InvalidArgumentException('MSBLOB does not support multi-prime RSA keys');
        }

        if ($password !== false && $password !== null && $password !== '') {
            throw new UnsupportedFormatException('MSBLOB private keys do not support encryption');
        }

        $modulus = $n->toBytes();
        $modulusLength = strlen($modulus);
        if ($modulusLength & 1) {
            $modulus = "\0" . $modulus;
            $modulusLength++;
        }
        $baseLength = intdiv($modulusLength, 2);
        $publicExponent = $e->toBytes();
        if (strlen($publicExponent) > 4) {
            throw new \InvalidArgumentException('MSBLOB public exponent does not fit in a DWORD');
        }
        $n = strrev($modulus);
        $e = str_pad(strrev($publicExponent), 4, "\0");
        $key = pack('aavV', chr(self::PRIVATEKEYBLOB), chr(2), 0, self::CALG_RSA_KEYX);
        $key .= pack('VVa*', self::RSA2, 8 * strlen($n), $e);
        $key .= $n;
        foreach ([$primes[1], $primes[2], $exponents[1], $exponents[2], $coefficients[2]] as $component) {
            $bytes = $component->toBytes();
            if (strlen($bytes) > $baseLength) {
                throw new \InvalidArgumentException('RSA CRT component exceeds the MSBLOB field width');
            }
            $key .= str_pad(strrev($bytes), $baseLength, "\0");
        }
        $privateExponent = $d->toBytes();
        if (strlen($privateExponent) > $modulusLength) {
            throw new \InvalidArgumentException('RSA private exponent exceeds the MSBLOB field width');
        }
        $key .= str_pad(strrev($privateExponent), $modulusLength, "\0");

        return Strings::base64_encode($key);
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
        $modulus = $n->toBytes();
        if (strlen($modulus) & 1) {
            $modulus = "\0" . $modulus;
        }
        $n = strrev($modulus);
        $publicExponent = $e->toBytes();
        if (strlen($publicExponent) > 4) {
            throw new \InvalidArgumentException('MSBLOB public exponent does not fit in a DWORD');
        }
        $e = str_pad(strrev($publicExponent), 4, "\0");
        $key = pack('aavV', chr(self::PUBLICKEYBLOB), chr(2), 0, self::CALG_RSA_KEYX);
        $key .= pack('VVa*', self::RSA1, 8 * strlen($n), $e);
        $key .= $n;

        return Strings::base64_encode($key);
    }
}
