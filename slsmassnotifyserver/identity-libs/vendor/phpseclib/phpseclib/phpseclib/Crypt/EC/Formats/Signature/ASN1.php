<?php

/**
 * ASN1 Signature Handler
 *
 * PHP version 5
 *
 * Handles signatures in the format described in
 * https://tools.ietf.org/html/rfc3279#section-2.2.3
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2016 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\EC\Formats\Signature;

use phpseclib3\File\ASN1 as Encoder;
use phpseclib3\File\ASN1\Maps\EcdsaSigValue;
use phpseclib3\Math\BigInteger;

/**
 * ASN1 Signature Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class ASN1
{
    /**
     * Loads a signature
     *
     * @param string $sig
     * @return array
     */
    public static function load($sig)
    {
        if (!is_string($sig) || strlen($sig) > 512) {
            return false;
        }

        $decoded = Encoder::decodeBER($sig);
        if (empty($decoded)) {
            return false;
        }
        $components = Encoder::asn1map($decoded[0], EcdsaSigValue::MAP);
        if (!is_array($components) || !isset($components['r'], $components['s'])) {
            return false;
        }
        try {
            $canonical = self::save($components['r'], $components['s']);
        } catch (\UnexpectedValueException $e) {
            return false;
        }
        if (!hash_equals($canonical, $sig)) {
            return false;
        }

        return $components;
    }

    /**
     * Returns a signature in the appropriate format
     *
     * @param BigInteger $r
     * @param BigInteger $s
     * @return string
     */
    public static function save(BigInteger $r, BigInteger $s)
    {
        $zero = new BigInteger();
        if ($r->compare($zero) <= 0 || $s->compare($zero) <= 0 ||
            strlen($r->toBytes()) > 128 || strlen($s->toBytes()) > 128) {
            throw new \UnexpectedValueException('Invalid ECDSA signature component');
        }
        return Encoder::encodeDER(compact('r', 's'), EcdsaSigValue::MAP);
    }
}
