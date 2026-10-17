<?php

/**
 * XML Formatted EC Key Handler
 *
 * More info:
 *
 * https://www.w3.org/TR/xmldsig-core/#sec-ECKeyValue
 * http://en.wikipedia.org/wiki/XML_Signature
 *
 * PHP version 5
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt\EC\Formats\Keys;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\EC\BaseCurves\Base as BaseCurve;
use phpseclib3\Crypt\EC\BaseCurves\Montgomery as MontgomeryCurve;
use phpseclib3\Crypt\EC\BaseCurves\Prime as PrimeCurve;
use phpseclib3\Crypt\EC\BaseCurves\TwistedEdwards as TwistedEdwardsCurve;
use phpseclib3\Exception\BadConfigurationException;
use phpseclib3\Exception\UnsupportedCurveException;
use phpseclib3\Math\BigInteger;

/**
 * XML Formatted EC Key Handler
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class XML
{
    use Common;

    /**
     * Default namespace
     *
     * @var string
     */
    private static $namespace;

    /**
     * Flag for using RFC4050 syntax
     *
     * @var bool
     */
    private static $rfc4050 = false;

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

        if (!class_exists('DOMDocument')) {
            throw new BadConfigurationException('The dom extension is not setup correctly on this system');
        }

        $key = (string) $key;
        if (strlen($key) > 1048576) {
            throw new \LengthException('EC XML keys must not exceed 1 MiB');
        }
        if (stripos($key, '<!DOCTYPE') !== false || stripos($key, '<!ENTITY') !== false) {
            throw new \UnexpectedValueException('DTD and entity declarations are not permitted in EC XML keys');
        }

        $use_errors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $key = ltrim($key, "\xEF\xBB\xBF \t\r\n");
        if (substr($key, 0, 5) != '<?xml') {
            $key = '<xml>' . $key . '</xml>';
        }

        $temp = self::isolateNamespace($key, 'http://www.w3.org/2009/xmldsig11#');
        if ($temp) {
            $key = $temp;
        }

        $temp = self::isolateNamespace($key, 'http://www.w3.org/2001/04/xmldsig-more#');
        if ($temp) {
            $key = $temp;
        }

        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        if (!$dom->loadXML($key, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_clear_errors();
            libxml_use_internal_errors($use_errors);
            throw new \UnexpectedValueException('Key does not appear to contain XML');
        }
        $xpath = new \DOMXPath($dom);
        libxml_clear_errors();
        libxml_use_internal_errors($use_errors);
        $curve = self::loadCurveByParam($xpath);

        $rfcX = self::query($xpath, 'publickey/x');
        $rfcY = self::query($xpath, 'publickey/y');
        if ($rfcX->length || $rfcY->length) {
            $QA = self::extractPointRFC4050($xpath, $curve);
        } else {
            $pubkey = self::query($xpath, 'publickey', 'Public Key is not present');
            $QA = self::extractPoint("\0" . $pubkey, $curve);
        }

        return compact('curve', 'QA');
    }

    /**
     * Case-insensitive xpath query
     *
     * @param \DOMXPath $xpath
     * @param string $name
     * @param string $error optional
     * @param bool $decode optional
     * @return \DOMNodeList
     */
    private static function query(\DOMXPath $xpath, $name, $error = null, $decode = true)
    {
        $query = '/';
        $names = explode('/', $name);
        foreach ($names as $name) {
            $query .= "/*[translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$name']";
        }
        $result = $xpath->query($query);
        if (!isset($error)) {
            return $result;
        }

        if ($result->length !== 1) {
            throw new \RuntimeException($result->length ? $error . ' or is ambiguous' : $error);
        }
        return $decode ? self::decodeValue($result->item(0)->textContent) : $result->item(0)->textContent;
    }

    /**
     * Finds the first element in the relevant namespace, strips the namespacing and returns the XML for that element.
     *
     * @param string $xml
     * @param string $ns
     */
    private static function isolateNamespace($xml, $ns)
    {
        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return false;
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[namespace::*[.='$ns'] and not(../namespace::*[.='$ns'])]");
        if (!$nodes->length) {
            return false;
        }
        $node = $nodes->item(0);
        $ns_name = $node->lookupPrefix($ns);
        if ($ns_name) {
            $node->removeAttributeNS($ns, $ns_name);
        }
        return $dom->saveXML($node);
    }

    /**
     * Decodes the value
     *
     * @param string $value
     */
    private static function decodeValue($value)
    {
        $value = str_replace(["\r", "\n", ' ', "\t"], '', $value);
        if (strlen($value) > 16384) {
            throw new \LengthException('Encoded EC XML value is too large');
        }
        $value = Strings::base64_decode($value);
        if (strlen($value) > 4096) {
            throw new \LengthException('Decoded EC XML value is too large');
        }
        return $value;
    }

    /**
     * Extract points from an XML document
     *
     * @param \DOMXPath $xpath
     * @param BaseCurve $curve
     * @return object[]
     */
    private static function extractPointRFC4050(\DOMXPath $xpath, BaseCurve $curve)
    {
        if (!$curve instanceof PrimeCurve) {
            throw new UnsupportedCurveException('RFC 4050 XML keys require a prime-field curve');
        }
        $x = self::query($xpath, 'publickey/x');
        $y = self::query($xpath, 'publickey/y');
        if ($x->length !== 1 || !$x->item(0)->hasAttribute('Value')) {
            throw new \RuntimeException('Public Key / X coordinate not found');
        }
        if ($y->length !== 1 || !$y->item(0)->hasAttribute('Value')) {
            throw new \RuntimeException('Public Key / Y coordinate not found');
        }
        $x = self::decodeDecimal($x->item(0)->getAttribute('Value'), 'Public Key / X coordinate');
        $y = self::decodeDecimal($y->item(0)->getAttribute('Value'), 'Public Key / Y coordinate');
        if ($x->compare($curve->getModulo()) >= 0 || $y->compare($curve->getModulo()) >= 0) {
            throw new \RuntimeException('Public key coordinates must be less than the field modulus');
        }
        $point = [$curve->convertInteger($x), $curve->convertInteger($y)];
        if (!$curve->verifyPoint($point)) {
            throw new \RuntimeException('Unable to verify that point exists on curve');
        }
        return $point;
    }

    /**
     * Returns an instance of \phpseclib3\Crypt\EC\BaseCurves\Base based
     * on the curve parameters
     *
     * @param \DomXPath $xpath
     * @return BaseCurve|false
     */
    private static function loadCurveByParam(\DOMXPath $xpath)
    {
        $namedCurve = self::query($xpath, 'namedcurve');
        $explicitParams = self::query($xpath, 'explicitparams');
        $ecParameters = self::query($xpath, 'ecparameters');
        if ($namedCurve->length > 1) {
            throw new \RuntimeException('Exactly one named curve is permitted');
        }
        if ($namedCurve->length == 1) {
            if ($explicitParams->length || $ecParameters->length) {
                throw new \RuntimeException('Named and explicit curve parameters cannot be combined');
            }
            $node = $namedCurve->item(0);
            $identifiers = [];
            foreach ($node->attributes as $attribute) {
                if (strcasecmp($attribute->name, 'URI') === 0 || strcasecmp($attribute->name, 'URN') === 0) {
                    $identifiers[] = trim($attribute->value);
                }
            }
            if (count($identifiers) !== 1 ||
                preg_match('#^urn:oid:([0-9]+(?:\.[0-9]+)+)$#iD', $identifiers[0], $matches) !== 1) {
                throw new \RuntimeException('NamedCurve must contain one valid urn:oid identifier');
            }
            $oid = $matches[1];
            $name = array_search($oid, self::$curveOIDs);
            if ($name === false) {
                throw new UnsupportedCurveException('Curve with OID of ' . $oid . ' is not supported');
            }

            $curve = '\phpseclib3\Crypt\EC\Curves\\' . $name;
            if (!class_exists($curve)) {
                throw new UnsupportedCurveException('Named Curve of ' . $name . ' is not supported');
            }
            return new $curve();
        }

        if ($explicitParams->length > 1 || $ecParameters->length > 1 ||
            ($explicitParams->length && $ecParameters->length)) {
            throw new \RuntimeException('Exactly one explicit curve parameter set is permitted');
        }
        if ($explicitParams->length) {
            return self::loadCurveByParamRFC4050($xpath);
        }

        if (!$ecParameters->length) {
            throw new \RuntimeException('No parameters are present');
        }

        $fieldTypes = [
            'prime-field' => ['fieldid/prime/p'],
            'gnb' => ['fieldid/gnb/m'],
            'tnb' => ['fieldid/tnb/k'],
            'pnb' => ['fieldid/pnb/k1', 'fieldid/pnb/k2', 'fieldid/pnb/k3'],
            'unknown' => []
        ];

        foreach ($fieldTypes as $type => $queries) {
            foreach ($queries as $query) {
                $result = self::query($xpath, $query);
                if (!$result->length) {
                    continue 2;
                }
                if ($result->length !== 1) {
                    throw new \RuntimeException('Explicit EC parameter is ambiguous');
                }
                $param = preg_replace('#.*/#', '', $query);
                $$param = self::decodeValue($result->item(0)->textContent);
            }
            break;
        }

        $a = self::query($xpath, 'curve/a', 'A coefficient is not present');
        $b = self::query($xpath, 'curve/b', 'B coefficient is not present');
        $base = self::query($xpath, 'base', 'Base point is not present');
        $order = self::query($xpath, 'order', 'Order is not present');

        switch ($type) {
            case 'prime-field':
                self::assertBinaryLength($p, 'field modulus');
                self::assertBinaryLength($a, 'A coefficient');
                self::assertBinaryLength($b, 'B coefficient');
                self::assertBinaryLength($order, 'group order');
                if (strlen($base) > 257) {
                    throw new \LengthException('EC base point is too large');
                }
                $p = new BigInteger($p, 256);
                $a = new BigInteger($a, 256);
                $b = new BigInteger($b, 256);
                $order = new BigInteger($order, 256);
                self::validatePrimeParameters($p, $a, $b, $order);
                $curve = new PrimeCurve();
                $curve->setModulo($p);
                $curve->setCoefficients($a, $b);
                $point = self::extractPoint("\0" . $base, $curve);
                $curve->setBasePoint(...$point);
                $curve->setOrder($order);
                self::validateBaseOrder($curve, $point, $order);
                return $curve;
            case 'gnb':
            case 'tnb':
            case 'pnb':
            default:
                throw new UnsupportedCurveException('Field Type of ' . $type . ' is not supported');
        }
    }

    /**
     * Returns an instance of \phpseclib3\Crypt\EC\BaseCurves\Base based
     * on the curve parameters
     *
     * @param \DomXPath $xpath
     * @return BaseCurve|false
     */
    private static function loadCurveByParamRFC4050(\DOMXPath $xpath)
    {
        $fieldTypes = [
            'prime-field' => ['primefieldparamstype/p'],
            'unknown' => []
        ];

        foreach ($fieldTypes as $type => $queries) {
            foreach ($queries as $query) {
                $result = self::query($xpath, $query);
                if (!$result->length) {
                    continue 2;
                }
                if ($result->length !== 1) {
                    throw new \RuntimeException('RFC 4050 EC parameter is ambiguous');
                }
                $param = preg_replace('#.*/#', '', $query);
                $$param = $result->item(0)->textContent;
            }
            break;
        }

        $a = self::query($xpath, 'curveparamstype/a', 'A coefficient is not present', false);
        $b = self::query($xpath, 'curveparamstype/b', 'B coefficient is not present', false);
        $x = self::query($xpath, 'basepointparams/basepoint/ecpointtype/x', 'Base Point X is not present', false);
        $y = self::query($xpath, 'basepointparams/basepoint/ecpointtype/y', 'Base Point Y is not present', false);
        $order = self::query($xpath, 'order', 'Order is not present', false);

        switch ($type) {
            case 'prime-field':
                $curve = new PrimeCurve();

                $p = self::decodeDecimal($p, 'field modulus');
                $a = self::decodeDecimal($a, 'A coefficient');
                $b = self::decodeDecimal($b, 'B coefficient');
                $x = self::decodeDecimal($x, 'base point X coordinate');
                $y = self::decodeDecimal($y, 'base point Y coordinate');
                $order = self::decodeDecimal($order, 'group order');
                self::validatePrimeParameters($p, $a, $b, $order);

                $curve->setModulo($p);
                $curve->setCoefficients($a, $b);
                if ($x->compare($p) >= 0 || $y->compare($p) >= 0) {
                    throw new \RuntimeException('EC base point coordinates must be less than the field modulus');
                }
                $point = [$curve->convertInteger($x), $curve->convertInteger($y)];
                if (!$curve->verifyPoint($point)) {
                    throw new \RuntimeException('EC base point is not on the curve');
                }
                $curve->setBasePoint($x, $y);
                $curve->setOrder($order);
                self::validateBaseOrder($curve, $point, $order);
                return $curve;
            default:
                throw new UnsupportedCurveException('Field Type of ' . $type . ' is not supported');
        }
    }

    /**
     * Decode a bounded, non-negative decimal integer.
     *
     * @param string $value
     * @param string $name
     * @return BigInteger
     */
    private static function decodeDecimal($value, $name)
    {
        $value = trim((string) $value);
        if (!is_string($value) || strlen($value) > 310 ||
            preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \UnexpectedValueException($name . ' must be a bounded non-negative decimal integer');
        }
        return new BigInteger($value);
    }

    /**
     * Bound binary explicit-curve components before creating large integers.
     *
     * @param string $value
     * @param string $name
     */
    private static function assertBinaryLength($value, $name)
    {
        if (strlen($value) > 128) {
            throw new \LengthException('EC ' . $name . ' exceeds 1024 bits');
        }
    }

    /**
     * Validate explicit prime-field parameters before constructing a curve.
     */
    private static function validatePrimeParameters(BigInteger $p, BigInteger $a, BigInteger $b, BigInteger $order)
    {
        $zero = new BigInteger();
        $one = new BigInteger(1);
        if ($p->getLength() < 112 || $p->getLength() > 1024 || !$p->isPrime()) {
            throw new \UnexpectedValueException('EC field modulus must be a prime between 112 and 1024 bits');
        }
        if ($a->isNegative() || $b->isNegative() || $a->compare($p) >= 0 || $b->compare($p) >= 0) {
            throw new \UnexpectedValueException('EC coefficients must be non-negative and less than the field modulus');
        }
        if ($order->compare($one) <= 0 || $order->getLength() < 112 ||
            $order->getLength() > $p->getLength() + 1 || !$order->isPrime()) {
            throw new \UnexpectedValueException('EC group order is outside the supported range');
        }

        $four = new BigInteger(4);
        $twentySeven = new BigInteger(27);
        $discriminant = $a->multiply($a)->multiply($a)->multiply($four)
            ->add($b->multiply($b)->multiply($twentySeven));
        list(, $discriminant) = $discriminant->divide($p);
        if ($discriminant->equals($zero)) {
            throw new \UnexpectedValueException('Singular EC curves are not permitted');
        }
    }

    /**
     * Confirm the declared order annihilates the supplied base point.
     *
     * @param \phpseclib3\Math\Common\FiniteField\Integer[] $point
     */
    private static function validateBaseOrder(PrimeCurve $curve, array $point, BigInteger $order)
    {
        if (!empty($curve->multiplyPoint($point, $order))) {
            throw new \UnexpectedValueException('EC group order does not match the base point');
        }
    }

    /**
     * Sets the namespace. dsig11 is the most common one.
     *
     * Set to null to unset. Used only for creating public keys.
     *
     * @param string $namespace
     */
    public static function setNamespace($namespace)
    {
        if ($namespace !== null && $namespace !== '' &&
            (!is_string($namespace) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $namespace) !== 1)) {
            throw new \InvalidArgumentException('XML namespace prefix is invalid');
        }
        self::$namespace = $namespace;
    }

    /**
     * Uses the XML syntax specified in https://tools.ietf.org/html/rfc4050
     */
    public static function enableRFC4050Syntax()
    {
        self::$rfc4050 = true;
    }

    /**
     * Uses the XML syntax specified in https://www.w3.org/TR/xmldsig-core/#sec-ECParameters
     */
    public static function disableRFC4050Syntax()
    {
        self::$rfc4050 = false;
    }

    /**
     * Convert a public key to the appropriate format
     *
     * @param BaseCurve $curve
     * @param \phpseclib3\Math\Common\FiniteField\Integer[] $publicKey
     * @param array $options optional
     * @return string
     */
    public static function savePublicKey(BaseCurve $curve, array $publicKey, array $options = [])
    {
        self::initialize_static_variables();

        if ($curve instanceof TwistedEdwardsCurve || $curve instanceof MontgomeryCurve) {
            throw new UnsupportedCurveException('TwistedEdwards and Montgomery Curves are not supported');
        }
        if (count($publicKey) !== 2 || !$curve->verifyPoint($publicKey)) {
            throw new \UnexpectedValueException('Invalid EC public key');
        }

        if (empty(static::$namespace)) {
            $pre = $post = '';
        } else {
            $pre = static::$namespace . ':';
            $post = ':' . static::$namespace;
        }

        if (self::$rfc4050) {
            return '<' . $pre . 'ECDSAKeyValue xmlns' . $post . '="http://www.w3.org/2001/04/xmldsig-more#">' . "\r\n" .
                   self::encodeXMLParameters($curve, $pre, $options) . "\r\n" .
                   '<' . $pre . 'PublicKey>' . "\r\n" .
                   '<' . $pre . 'X Value="' . $publicKey[0] . '" />' . "\r\n" .
                   '<' . $pre . 'Y Value="' . $publicKey[1] . '" />' . "\r\n" .
                   '</' . $pre . 'PublicKey>' . "\r\n" .
                   '</' . $pre . 'ECDSAKeyValue>';
        }

        $publicKey = "\4" . $publicKey[0]->toBytes() . $publicKey[1]->toBytes();

        return '<' . $pre . 'ECDSAKeyValue xmlns' . $post . '="http://www.w3.org/2009/xmldsig11#">' . "\r\n" .
               self::encodeXMLParameters($curve, $pre, $options) . "\r\n" .
               '<' . $pre . 'PublicKey>' . Strings::base64_encode($publicKey) . '</' . $pre . 'PublicKey>' . "\r\n" .
               '</' . $pre . 'ECDSAKeyValue>';
    }

    /**
     * Encode Parameters
     *
     * @param BaseCurve $curve
     * @param string $pre
     * @param array $options optional
     * @return string|false
     */
    private static function encodeXMLParameters(BaseCurve $curve, $pre, array $options = [])
    {
        $result = self::encodeParameters($curve, true, $options);

        if (isset($result['namedCurve'])) {
            $namedCurve = '<' . $pre . 'NamedCurve URI="urn:oid:' . self::$curveOIDs[$result['namedCurve']] . '" />';
            return self::$rfc4050 ?
                '<' . $pre . 'DomainParameters>' . str_replace('URI', 'URN', $namedCurve) . '</' . $pre . 'DomainParameters>' :
                $namedCurve;
        }

        if (self::$rfc4050) {
            $xml = '<' . $pre . 'ExplicitParams>' . "\r\n" .
                  '<' . $pre . 'FieldParams>' . "\r\n";
            $temp = $result['specifiedCurve'];
            switch ($temp['fieldID']['fieldType']) {
                case 'prime-field':
                    $xml .= '<' . $pre . 'PrimeFieldParamsType>' . "\r\n" .
                           '<' . $pre . 'P>' . $temp['fieldID']['parameters'] . '</' . $pre . 'P>' . "\r\n" .
                           '</' . $pre . 'PrimeFieldParamsType>' . "\r\n";
                    $a = $curve->getA();
                    $b = $curve->getB();
                    list($x, $y) = $curve->getBasePoint();
                    break;
                default:
                    throw new UnsupportedCurveException('Field Type of ' . $temp['fieldID']['fieldType'] . ' is not supported');
            }
            $xml .= '</' . $pre . 'FieldParams>' . "\r\n" .
                   '<' . $pre . 'CurveParamsType>' . "\r\n" .
                   '<' . $pre . 'A>' . $a . '</' . $pre . 'A>' . "\r\n" .
                   '<' . $pre . 'B>' . $b . '</' . $pre . 'B>' . "\r\n" .
                   '</' . $pre . 'CurveParamsType>' . "\r\n" .
                   '<' . $pre . 'BasePointParams>' . "\r\n" .
                   '<' . $pre . 'BasePoint>' . "\r\n" .
                   '<' . $pre . 'ECPointType>' . "\r\n" .
                   '<' . $pre . 'X>' . $x . '</' . $pre . 'X>' . "\r\n" .
                   '<' . $pre . 'Y>' . $y . '</' . $pre . 'Y>' . "\r\n" .
                   '</' . $pre . 'ECPointType>' . "\r\n" .
                   '</' . $pre . 'BasePoint>' . "\r\n" .
                   '<' . $pre . 'Order>' . $curve->getOrder() . '</' . $pre . 'Order>' . "\r\n" .
                   '</' . $pre . 'BasePointParams>' . "\r\n" .
                   '</' . $pre . 'ExplicitParams>' . "\r\n";

            return $xml;
        }

        if (isset($result['specifiedCurve'])) {
            $xml = '<' . $pre . 'ECParameters>' . "\r\n" .
                   '<' . $pre . 'FieldID>' . "\r\n";
            $temp = $result['specifiedCurve'];
            switch ($temp['fieldID']['fieldType']) {
                case 'prime-field':
                    $xml .= '<' . $pre . 'Prime>' . "\r\n" .
                           '<' . $pre . 'P>' . Strings::base64_encode($temp['fieldID']['parameters']->toBytes()) . '</' . $pre . 'P>' . "\r\n" .
                           '</' . $pre . 'Prime>' . "\r\n" ;
                    break;
                default:
                    throw new UnsupportedCurveException('Field Type of ' . $temp['fieldID']['fieldType'] . ' is not supported');
            }
            $xml .= '</' . $pre . 'FieldID>' . "\r\n" .
                   '<' . $pre . 'Curve>' . "\r\n" .
                   '<' . $pre . 'A>' . Strings::base64_encode($temp['curve']['a']) . '</' . $pre . 'A>' . "\r\n" .
                   '<' . $pre . 'B>' . Strings::base64_encode($temp['curve']['b']) . '</' . $pre . 'B>' . "\r\n" .
                   '</' . $pre . 'Curve>' . "\r\n" .
                   '<' . $pre . 'Base>' . Strings::base64_encode($temp['base']) . '</' . $pre . 'Base>' . "\r\n" .
                   '<' . $pre . 'Order>' . Strings::base64_encode($temp['order']->toBytes()) . '</' . $pre . 'Order>' . "\r\n" .
                   '</' . $pre . 'ECParameters>';
            return $xml;
        }
    }
}
