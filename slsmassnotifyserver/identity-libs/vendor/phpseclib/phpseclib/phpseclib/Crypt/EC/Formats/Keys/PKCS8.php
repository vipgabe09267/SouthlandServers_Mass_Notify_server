<?php

/**
 * PKCS#8 Formatted EC Key Handler
 *
 * PHP version 5
 *
 * Processes keys with the following headers:
 *
 * -----BEGIN ENCRYPTED PRIVATE KEY-----
 * -----BEGIN PRIVATE KEY-----
 * -----BEGIN PUBLIC KEY-----
 *
 * Analogous to ssh-keygen's pkcs8 format (as specified by -m). Although PKCS8
 * is specific to private keys it's basically creating a DER-encoded wrapper
 * for keys. This just extends that same concept to public keys (much like ssh-keygen)
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\EC\Formats\Keys;

use phpseclib3\Crypt\Common\Formats\Keys\PKCS8 as Progenitor;
use phpseclib3\Crypt\EC\BaseCurves\Base as BaseCurve;
use phpseclib3\Crypt\EC\BaseCurves\Montgomery as MontgomeryCurve;
use phpseclib3\Crypt\EC\BaseCurves\TwistedEdwards as TwistedEdwardsCurve;
use phpseclib3\Crypt\EC\Curves\Curve25519;
use phpseclib3\Crypt\EC\Curves\Curve448;
use phpseclib3\Crypt\EC\Curves\Ed25519;
use phpseclib3\Crypt\EC\Curves\Ed448;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\Math\BigInteger;
use phpseclib3\Math\Common\FiniteField\Integer;

/**
 * PKCS#8 Formatted EC Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class PKCS8 extends Progenitor
{
    use Common;

    /**
     * OID Name
     *
     * @var array
     */
    const OID_NAME = ['id-ecPublicKey', 'id-Ed25519', 'id-Ed448', 'id-X25519', 'id-X448'];

    /**
     * OID Value
     *
     * @var string
     */
    const OID_VALUE = ['1.2.840.10045.2.1', '1.3.101.112', '1.3.101.113', '1.3.101.110', '1.3.101.111'];

    /**
     * Break a public or private key down into its constituent components
     *
     * @param string $key
     * @param string $password optional
     * @return array
     */
    public static function load($key, $password = '')
    {
        // initialize_static_variables() is defined in both the trait and the parent class
        // when it's defined in two places it's the traits one that's called
        // the parent one is needed, as well, but the parent one is called by other methods
        // in the parent class as needed and in the context of the parent it's the parent
        // one that's called
        self::initialize_static_variables();

        $key = parent::load($key, $password);

        $type = isset($key['privateKey']) ? 'privateKey' : 'publicKey';
        $algorithm = $key[$type . 'Algorithm']['algorithm'];

        switch ($algorithm) {
            case 'id-Ed25519':
            case 'id-Ed448':
                if (isset($key[$type . 'Algorithm']['parameters'])) {
                    throw new \RuntimeException($algorithm . ' parameters must be absent');
                }
                return self::loadEdDSA($key);
            case 'id-X25519':
            case 'id-X448':
                if (isset($key[$type . 'Algorithm']['parameters'])) {
                    throw new \RuntimeException($algorithm . ' parameters must be absent');
                }
                return self::loadECDH($key);
        }

        if (!isset($key[$type . 'Algorithm']['parameters']) ||
            !$key[$type . 'Algorithm']['parameters'] instanceof ASN1\Element) {
            throw new \RuntimeException('EC algorithm parameters are required');
        }
        $decoded = ASN1::decodeBER($key[$type . 'Algorithm']['parameters']->element);
        if (!$decoded) {
            throw new \RuntimeException('Unable to decode BER');
        }
        $params = ASN1::asn1map($decoded[0], Maps\ECParameters::MAP);
        if (!$params) {
            throw new \RuntimeException('Unable to decode the parameters using Maps\ECParameters');
        }

        $components = [];
        $components['curve'] = self::loadCurveByParam($params);

        if ($type == 'publicKey') {
            $components['QA'] = self::extractPoint("\0" . $key['publicKey'], $components['curve']);

            return $components;
        }

        $decoded = ASN1::decodeBER($key['privateKey']);
        if (!$decoded) {
            throw new \RuntimeException('Unable to decode BER');
        }
        $key = ASN1::asn1map($decoded[0], Maps\ECPrivateKey::MAP);
        if (!is_array($key)) {
            throw new \RuntimeException('Unable to decode the EC private key');
        }
        if (isset($key['parameters']) && $params != $key['parameters']) {
            throw new \RuntimeException('The PKCS8 parameter field does not match the private key parameter field');
        }

        $components['dA'] = new BigInteger($key['privateKey'], 256);
        $components['curve']->rangeCheck($components['dA']);
        $components['QA'] = isset($key['publicKey']) ?
            self::extractPoint($key['publicKey'], $components['curve']) :
            $components['curve']->multiplyPoint($components['curve']->getBasePoint(), $components['dA']);
        if (isset($key['publicKey'])) {
            self::validatePublicPoint($components['curve'], $components['dA'], $components['QA']);
        }

        return $components;
    }

    /**
     * Break a public or private EdDSA key down into its constituent components
     *
     * @return array
     */
    private static function loadEdDSA(array $key)
    {
        $components = [];

        if (isset($key['privateKey'])) {
            $components['curve'] = $key['privateKeyAlgorithm']['algorithm'] == 'id-Ed25519' ? new Ed25519() : new Ed448();
            $expected = chr(ASN1::TYPE_OCTET_STRING) . ASN1::encodeLength($components['curve']::SIZE);
            if (substr($key['privateKey'], 0, 2) != $expected) {
                throw new \RuntimeException(
                    'The first two bytes of the ' .
                    $key['privateKeyAlgorithm']['algorithm'] .
                    ' private key field should be 0x' . bin2hex($expected)
                );
            }
            $arr = $components['curve']->extractSecret(substr($key['privateKey'], 2));
            $components['dA'] = $arr['dA'];
            $components['secret'] = $arr['secret'];
        }

        if (isset($key['publicKey'])) {
            if (!isset($components['curve'])) {
                $components['curve'] = $key['publicKeyAlgorithm']['algorithm'] == 'id-Ed25519' ? new Ed25519() : new Ed448();
            }

            $components['QA'] = self::extractPoint($key['publicKey'], $components['curve']);
        }

        if (isset($key['privateKey']) && !isset($components['QA'])) {
            $components['QA'] = $components['curve']->multiplyPoint($components['curve']->getBasePoint(), $components['dA']);
        }
        if (isset($key['privateKey'], $key['publicKey'])) {
            self::validatePublicPoint($components['curve'], $components['dA'], $components['QA']);
        }

        return $components;
    }

    private static function loadECDH(array $key)
    {
        $components = [];

        if (isset($key['privateKey'])) {
            $components['curve'] = $key['privateKeyAlgorithm']['algorithm'] == 'id-X25519' ? new Curve25519() : new Curve448();
            $expected = chr(ASN1::TYPE_OCTET_STRING) . ASN1::encodeLength($components['curve']::SIZE);
            $privateKey = (string) $key['privateKey'];
            if (substr($privateKey, 0, 2) != $expected) {
                throw new \RuntimeException(
                    'The first two bytes of the ' .
                    $key['privateKeyAlgorithm']['algorithm'] .
                    ' private key field should be 0x' . bin2hex($expected)
                );
            }
            if (strlen($privateKey) !== $components['curve']::SIZE + strlen($expected)) {
                throw new \LengthException(
                    $key['privateKeyAlgorithm']['algorithm'] .
                    ' private keys must be exactly ' . $components['curve']::SIZE . ' bytes'
                );
            }
            $components['dA'] = new BigInteger(substr($privateKey, 2), 256);
            $components['curve']->rangeCheck($components['dA']);
        }

        if (isset($key['publicKey'])) {
            if (!isset($components['curve'])) {
                $components['curve'] = $key['publicKeyAlgorithm']['algorithm'] == 'id-X25519' ? new Curve25519() : new Curve448();
            }
            if (strlen($key['publicKey']) !== $components['curve']::SIZE) {
                $algorithm = isset($key['privateKey']) ?
                    $key['privateKeyAlgorithm']['algorithm'] :
                    $key['publicKeyAlgorithm']['algorithm'];
                throw new \LengthException(
                    $algorithm .
                    ' public keys must be exactly ' . $components['curve']::SIZE . ' bytes'
                );
            }

            $components['QA'] = [$components['curve']->convertInteger(new BigInteger(strrev($key['publicKey']), 256))];
        }

        if (isset($key['privateKey']) && !isset($components['QA'])) {
            $components['QA'] = self::deriveMontgomeryPublicKey($components);
        }
        if (isset($key['privateKey'], $key['publicKey'])) {
            $derived = self::deriveMontgomeryPublicKey($components);
            if (count($components['QA']) !== 1 || !$components['QA'][0]->equals($derived[0])) {
                throw new \RuntimeException('Montgomery public key does not correspond to private key');
            }
        }

        return $components;
    }

    /**
     * Confirm that a public point belongs to a private scalar.
     *
     * @param BaseCurve $curve
     * @param BigInteger $privateKey
     * @param Integer[] $publicKey
     */
    private static function validatePublicPoint(BaseCurve $curve, BigInteger $privateKey, array $publicKey)
    {
        $derived = $curve->multiplyPoint($curve->getBasePoint(), $privateKey);
        if (count($publicKey) !== count($derived)) {
            throw new \RuntimeException('EC public key does not correspond to private key');
        }
        foreach ($derived as $index => $coordinate) {
            if (!isset($publicKey[$index]) || !$publicKey[$index]->equals($coordinate)) {
                throw new \RuntimeException('EC public key does not correspond to private key');
            }
        }
    }

    /**
     * Convert an EC public key to the appropriate format
     *
     * @param BaseCurve $curve
     * @param Integer[] $publicKey
     * @param array $options optional
     * @return string
     */
    public static function savePublicKey(BaseCurve $curve, array $publicKey, array $options = [])
    {
        self::initialize_static_variables();
        if ($curve instanceof MontgomeryCurve) {
            if (count($publicKey) !== 1 || strlen($publicKey[0]->toBytes()) > $curve::SIZE) {
                throw new \UnexpectedValueException('Invalid Montgomery public key');
            }
            return self::wrapPublicKey(
                str_pad(strrev($publicKey[0]->toBytes()), $curve::SIZE, "\0", STR_PAD_RIGHT),
                null,
                $curve instanceof Curve25519 ? 'id-X25519' : 'id-X448',
                $options
            );
        }

        if ($curve instanceof TwistedEdwardsCurve) {
            if (count($publicKey) !== 2 || !$curve->verifyPoint($publicKey)) {
                throw new \UnexpectedValueException('Invalid Edwards public key');
            }
            return self::wrapPublicKey(
                $curve->encodePoint($publicKey),
                null,
                $curve instanceof Ed25519 ? 'id-Ed25519' : 'id-Ed448',
                $options
            );
        }

        if (count($publicKey) !== 2 || !$curve->verifyPoint($publicKey)) {
            throw new \UnexpectedValueException('Invalid EC public key');
        }

        $params = new ASN1\Element(self::encodeParameters($curve, false, $options));

        $key = "\4" . $publicKey[0]->toBytes() . $publicKey[1]->toBytes();

        return self::wrapPublicKey($key, $params, 'id-ecPublicKey', $options);
    }

    /**
     * Convert a private key to the appropriate format.
     *
     * @param BigInteger $privateKey
     * @param BaseCurve $curve
     * @param Integer[] $publicKey
     * @param string $secret optional
     * @param string $password optional
     * @param array $options optional
     * @return string
     */
    public static function savePrivateKey(BigInteger $privateKey, BaseCurve $curve, array $publicKey, $secret = null, $password = '', array $options = [])
    {
        self::initialize_static_variables();

        if ($curve instanceof MontgomeryCurve) {
            $curve->rangeCheck($privateKey);
            return self::wrapPrivateKey(
                chr(ASN1::TYPE_OCTET_STRING) . ASN1::encodeLength($curve::SIZE) . str_pad($privateKey->toBytes(), $curve::SIZE, "\0", STR_PAD_LEFT),
                [],
                null,
                $password,
                $curve instanceof Curve25519 ? 'id-X25519' : 'id-X448'
            );
        }

        if ($curve instanceof TwistedEdwardsCurve) {
            if (!is_string($secret) || strlen($secret) !== $curve::SIZE) {
                throw new \LengthException('Edwards private secret must be exactly ' . $curve::SIZE . ' bytes');
            }
            self::validatePublicPoint($curve, $privateKey, $publicKey);
            return self::wrapPrivateKey(
                chr(ASN1::TYPE_OCTET_STRING) . ASN1::encodeLength($curve::SIZE) . $secret,
                [],
                null,
                $password,
                $curve instanceof Ed25519 ? 'id-Ed25519' : 'id-Ed448'
            );
        }

        $curve->rangeCheck($privateKey);
        self::validatePublicPoint($curve, $privateKey, $publicKey);

        $publicKey = "\4" . $publicKey[0]->toBytes() . $publicKey[1]->toBytes();

        $params = new ASN1\Element(self::encodeParameters($curve, false, $options));

        $key = [
            'version' => 'ecPrivkeyVer1',
            'privateKey' => $privateKey->toBytes(),
            //'parameters' => $params,
            'publicKey' => "\0" . $publicKey
        ];

        $key = ASN1::encodeDER($key, Maps\ECPrivateKey::MAP);

        return self::wrapPrivateKey($key, [], $params, $password, 'id-ecPublicKey', '', $options);
    }
}
