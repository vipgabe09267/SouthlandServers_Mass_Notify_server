<?php

/**
 * IEEE P1363 Signature Handler
 *
 * PHP version 5
 *
 * Handles signatures in the format described in
 * https://standards.ieee.org/ieee/1363/2049/ and
 * https://developer.mozilla.org/en-US/docs/Web/API/SubtleCrypto/sign#ecdsa
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2016 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\EC\Formats\Signature;

use phpseclib3\Math\BigInteger;

/**
 * ASN1 Signature Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class IEEE
{
    /**
     * Loads a signature
     *
     * @param string $sig
     * @return array
     */
    public static function load($sig)
    {
        if (!is_string($sig)) {
            return false;
        }

        $len = strlen($sig);
        if ($len < 2 || $len > 256 || $len & 1) {
            return false;
        }

        $r = new BigInteger(substr($sig, 0, $len >> 1), 256);
        $s = new BigInteger(substr($sig, $len >> 1), 256);

        return compact('r', 's');
    }

    /**
     * Returns a signature in the appropriate format
     *
     * @param BigInteger $r
     * @param BigInteger $s
     * @param string $curve
     * @param int $length
     * @return string
     */
    public static function save(BigInteger $r, BigInteger $s, $curve, $length)
    {
        $zero = new BigInteger();
        if ($r->compare($zero) <= 0 || $s->compare($zero) <= 0) {
            throw new \UnexpectedValueException('Invalid ECDSA signature component');
        }
        $r = $r->toBytes();
        $s = $s->toBytes();
        $length = (int) ceil($length / 8);
        if ($length < 1 || $length > 128 || strlen($r) > $length || strlen($s) > $length) {
            throw new \UnexpectedValueException('Signature components do not fit the requested IEEE P1363 width');
        }
        return str_pad($r, $length, "\0", STR_PAD_LEFT) . str_pad($s, $length, "\0", STR_PAD_LEFT);
    }
}
