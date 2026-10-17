<?php

/**
 * PKCS#8 Formatted RSA-PSS Key Handler
 *
 * PHP version 5
 *
 * Used by PHP's openssl_public_encrypt() and openssl's rsautl (when -pubin is set)
 *
 * Processes keys with the following headers:
 *
 * -----BEGIN ENCRYPTED PRIVATE KEY-----
 * -----BEGIN PRIVATE KEY-----
 * -----BEGIN PUBLIC KEY-----
 *
 * Analogous to "openssl genpkey -algorithm rsa-pss".
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\RSA\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\Common\Formats\Keys\PKCS8 as Progenitor;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\Math\BigInteger;

/**
 * PKCS#8 Formatted RSA-PSS Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class PSS extends Progenitor
{
    /**
     * OID Name
     *
     * @var string
     */
    const OID_NAME = 'id-RSASSA-PSS';

    /**
     * OID Value
     *
     * @var string
     */
    const OID_VALUE = '1.2.840.113549.1.1.10';

    /**
     * OIDs loaded
     *
     * @var bool
     */
    private static $oidsLoaded = false;

    /**
     * Child OIDs loaded
     *
     * @var bool
     */
    protected static $childOIDsLoaded = false;

    /**
     * Initialize static variables
     */
    private static function initialize_static_variables()
    {
        if (!self::$oidsLoaded) {
            ASN1::loadOIDs([
                'md2' => '1.2.840.113549.2.2',
                'md4' => '1.2.840.113549.2.4',
                'md5' => '1.2.840.113549.2.5',
                'id-sha1' => '1.3.14.3.2.26',
                'id-sha256' => '2.16.840.1.101.3.4.2.1',
                'id-sha384' => '2.16.840.1.101.3.4.2.2',
                'id-sha512' => '2.16.840.1.101.3.4.2.3',
                'id-sha224' => '2.16.840.1.101.3.4.2.4',
                'id-sha512/224' => '2.16.840.1.101.3.4.2.5',
                'id-sha512/256' => '2.16.840.1.101.3.4.2.6',
                'id-sha3-224' => '2.16.840.1.101.3.4.2.7',
                'id-sha3-256' => '2.16.840.1.101.3.4.2.8',
                'id-sha3-384' => '2.16.840.1.101.3.4.2.9',
                'id-sha3-512' => '2.16.840.1.101.3.4.2.10',

                'id-mgf1' => '1.2.840.113549.1.1.8'
            ]);
            self::$oidsLoaded = true;
        }
    }

    /**
     * Break a public or private key down into its constituent components
     *
     * @param string $key
     * @param string $password optional
     * @return array
     */
    public static function load($key, $password = '')
    {
        self::initialize_static_variables();

        if (!Strings::is_stringable($key)) {
            throw new \UnexpectedValueException('Key should be a string - not a ' . gettype($key));
        }

        $key = (string) $key;

        $key = parent::load($key, $password);

        $isPublicKey = !isset($key['privateKey']);
        $type = $isPublicKey ? 'public' : 'private';

        $result = PKCS1::load($key[$type . 'Key']);
        if ($result['isPublicKey'] !== $isPublicKey) {
            throw new \UnexpectedValueException('RSA-PSS envelope and inner RSA key types do not match');
        }
        if (!$isPublicKey && isset($key['publicKey'])) {
            $public = PKCS1::load($key['publicKey']);
            if (
                !$public['isPublicKey'] ||
                !$public['modulus']->equals($result['modulus']) ||
                !$public['publicExponent']->equals($result['publicExponent'])
            ) {
                throw new \UnexpectedValueException('RSA-PSS embedded public key does not match the private key');
            }
        }

        if (isset($key[$type . 'KeyAlgorithm']['parameters'])) {
            $params = self::decodeParameters(
                $key[$type . 'KeyAlgorithm']['parameters'],
                Maps\RSASSA_PSS_params::MAP,
                'RSA-PSS parameters'
            );
        } else {
            $params = [];
        }

        if (isset($params['maskGenAlgorithm'])) {
            if (
                !is_array($params['maskGenAlgorithm']) ||
                ($params['maskGenAlgorithm']['algorithm'] ?? null) !== 'id-mgf1' ||
                !isset($params['maskGenAlgorithm']['parameters'])
            ) {
                throw new \UnexpectedValueException('RSA-PSS mask generation algorithm must be MGF1 with hash parameters');
            }
            $params['maskGenAlgorithm']['parameters'] = self::decodeParameters(
                $params['maskGenAlgorithm']['parameters'],
                Maps\HashAlgorithm::MAP,
                'RSA-PSS MGF1 hash parameters'
            );
        } else {
            $params['maskGenAlgorithm'] = [
                'algorithm' => 'id-mgf1',
                'parameters' => ['algorithm' => 'id-sha1']
            ];
        }

        if (!isset($params['hashAlgorithm']['algorithm'])) {
            $params['hashAlgorithm']['algorithm'] = 'id-sha1';
        }

        if (isset($params['trailerField']) && self::integerToInt($params['trailerField'], 'trailer field') !== 1) {
            throw new \UnexpectedValueException('RSA-PSS trailer field must be 1');
        }

        $result['hash'] = self::hashNameFromIdentifier($params['hashAlgorithm']);
        $result['MGFHash'] = self::hashNameFromIdentifier($params['maskGenAlgorithm']['parameters']);
        if (isset($params['saltLength'])) {
            $result['saltLength'] = self::integerToInt($params['saltLength'], 'salt length');
        }

        if (isset($key['meta'])) {
            $result['meta'] = $key['meta'];
        }

        return $result;
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
        self::initialize_static_variables();

        $key = PKCS1::savePrivateKey($n, $e, $d, $primes, $exponents, $coefficients);
        $key = ASN1::extractBER($key);
        $params = self::savePSSParams($options);
        return self::wrapPrivateKey($key, [], $params, $password, null, '', $options);
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
        self::initialize_static_variables();

        $key = PKCS1::savePublicKey($n, $e);
        $key = ASN1::extractBER($key);
        $params = self::savePSSParams($options);
        return self::wrapPublicKey($key, $params);
    }

    /**
     * Encodes PSS parameters
     *
     * @param array $options
     * @return ASN1\Element
     */
    public static function savePSSParams(array $options)
    {
        /*
         The trailerField field is an integer.  It provides
         compatibility with IEEE Std 1363a-2004 [P1363A].  The value
         MUST be 1, which represents the trailer field with hexadecimal
         value 0xBC.  Other trailer fields, including the trailer field
         composed of HashID concatenated with 0xCC that is specified in
         IEEE Std 1363a, are not supported.  Implementations that
         perform signature generation MUST omit the trailerField field,
         indicating that the default trailer field value was used.
         Implementations that perform signature validation MUST
         recognize both a present trailerField field with value 1 and an
         absent trailerField field.

         source: https://tools.ietf.org/html/rfc4055#page-9
        */
        $params = [
            'trailerField' => new BigInteger(1)
        ];
        if (isset($options['hash'])) {
            $params['hashAlgorithm']['algorithm'] = self::hashOIDFromName($options['hash']);
        }
        if (isset($options['MGFHash'])) {
            $temp = ['algorithm' => self::hashOIDFromName($options['MGFHash'])];
            $temp = ASN1::encodeDER($temp, Maps\HashAlgorithm::MAP);
            $params['maskGenAlgorithm'] = [
                'algorithm' => 'id-mgf1',
                'parameters' => new ASN1\Element($temp)
            ];
        }
        if (isset($options['saltLength'])) {
            if (!is_int($options['saltLength']) || $options['saltLength'] < 0) {
                throw new \UnexpectedValueException('RSA-PSS salt length must be a non-negative integer');
            }
            $params['saltLength'] = new BigInteger($options['saltLength']);
        }

        return new ASN1\Element(ASN1::encodeDER($params, Maps\RSASSA_PSS_params::MAP));
    }

    /**
     * Decode one complete ASN.1 value and apply its map.
     *
     * @param ASN1\Element|string $encoded
     * @param array $map
     * @param string $label
     * @return array
     */
    private static function decodeParameters($encoded, array $map, $label)
    {
        if ($encoded instanceof ASN1\Element) {
            $encoded = $encoded->element;
        }
        if (!is_string($encoded) || $encoded === '') {
            throw new \UnexpectedValueException("Unable to decode $label");
        }

        $decoded = ASN1::decodeBER($encoded);
        if (
            !is_array($decoded) ||
            count($decoded) !== 1 ||
            ($decoded[0]['start'] ?? null) !== 0 ||
            ($decoded[0]['length'] ?? null) !== strlen($encoded)
        ) {
            throw new \UnexpectedValueException("Unable to decode one complete $label value");
        }

        $mapped = ASN1::asn1map($decoded[0], $map);
        if (!is_array($mapped)) {
            throw new \UnexpectedValueException("Unable to map $label");
        }

        return $mapped;
    }

    /**
     * Convert a supported hash OID name into the RSA hash name.
     *
     * @param mixed $oid
     * @return string
     */
    private static function hashNameFromOID($oid)
    {
        switch ($oid) {
            case 'md2':
            case 'md5':
                return $oid;
            case 'id-sha1':
            case 'id-sha224':
            case 'id-sha256':
            case 'id-sha384':
            case 'id-sha512':
            case 'id-sha512/224':
            case 'id-sha512/256':
            case 'id-sha3-224':
            case 'id-sha3-256':
            case 'id-sha3-384':
            case 'id-sha3-512':
                return substr($oid, 3);
            default:
                throw new \UnexpectedValueException('Unsupported RSA-PSS hash algorithm identifier');
        }
    }

    /**
     * Validate a hash AlgorithmIdentifier and return its RSA hash name.
     *
     * @param mixed $identifier
     * @return string
     */
    private static function hashNameFromIdentifier($identifier)
    {
        if (!is_array($identifier) || !isset($identifier['algorithm'])) {
            throw new \UnexpectedValueException('Invalid RSA-PSS hash algorithm identifier');
        }
        if (isset($identifier['parameters'])) {
            $parameters = $identifier['parameters'] instanceof ASN1\Element ?
                $identifier['parameters']->element :
                $identifier['parameters'];
            if ($parameters !== "\x05\x00") {
                throw new \UnexpectedValueException('RSA-PSS hash algorithm parameters must be absent or NULL');
            }
        }

        return self::hashNameFromOID($identifier['algorithm']);
    }

    /**
     * Convert an RSA hash name into its registered OID name.
     *
     * @param mixed $hash
     * @return string
     */
    private static function hashOIDFromName($hash)
    {
        if (!is_string($hash)) {
            throw new \UnexpectedValueException('RSA-PSS hash algorithm must be a string');
        }
        $hash = strtolower(preg_replace('#^sha3/#i', 'sha3-', $hash));
        switch ($hash) {
            case 'md2':
            case 'md5':
                return $hash;
            case 'sha1':
            case 'sha224':
            case 'sha256':
            case 'sha384':
            case 'sha512':
            case 'sha512/224':
            case 'sha512/256':
            case 'sha3-224':
            case 'sha3-256':
            case 'sha3-384':
            case 'sha3-512':
                return 'id-' . $hash;
            default:
                throw new \UnexpectedValueException('Unsupported RSA-PSS hash algorithm');
        }
    }

    /**
     * Convert a non-negative ASN.1 integer into a native integer safely.
     *
     * @param mixed $value
     * @param string $label
     * @return int
     */
    private static function integerToInt($value, $label)
    {
        if ($value instanceof BigInteger) {
            if ($value->isNegative() || $value->compare(new BigInteger(PHP_INT_MAX)) > 0) {
                throw new \UnexpectedValueException("RSA-PSS $label is outside the supported range");
            }
            return (int) $value->toString();
        }
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("RSA-PSS $label must be a non-negative integer");
        }
        return $value;
    }
}
