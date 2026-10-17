<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

function require_xmlsec(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function require_xmlsec_rejection(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $expected) {
        return;
    }
    throw new RuntimeException($message);
}

$rsa = RSA::createKey(1024);
$verificationKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
$verificationKey->loadKey($rsa->getPublicKey()->toString('PKCS8'));
require_xmlsec(
    $verificationKey->verifySignature('data', []) === -1,
    'A signature verifier TypeError escaped instead of returning the documented error result.'
);

$document = new DOMDocument();
$document->loadXML(
    '<enc:EncryptedData xmlns:enc="http://www.w3.org/2001/04/xmlenc#">' .
    '<enc:CipherData><enc:CipherValue>%%%invalid%%%</enc:CipherValue></enc:CipherData>' .
    '</enc:EncryptedData>',
    LIBXML_NONET
);
$encryption = new XMLSecEnc();
$encryption->setNode($document->documentElement);
require_xmlsec_rejection(
    static fn() => $encryption->getCipherValue(),
    'An invalid base64 CipherValue was accepted.'
);

$document = new DOMDocument();
$document->loadXML(
    '<enc:EncryptedData xmlns:enc="http://www.w3.org/2001/04/xmlenc#" ' .
    'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:evil="urn:evil">' .
    '<ds:KeyInfo><evil:KeyName>attacker-key</evil:KeyName></ds:KeyInfo>' .
    '</enc:EncryptedData>',
    LIBXML_NONET
);
$symmetricKey = new XMLSecurityKey(XMLSecurityKey::AES128_CBC);
XMLSecEnc::staticLocateKeyInfo($symmetricKey, $document->documentElement);
require_xmlsec($symmetricKey->name === null, 'A foreign-namespace KeyName was accepted.');

$document = new DOMDocument();
$document->loadXML(
    '<enc:EncryptedData xmlns:enc="http://www.w3.org/2001/04/xmlenc#" ' .
    'xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' .
    '<ds:KeyInfo><ds:RetrievalMethod Type="http://www.w3.org/2001/04/xmlenc#EncryptedKey" URI="#duplicate"/></ds:KeyInfo>' .
    '<enc:EncryptedKey Id="duplicate"/><enc:EncryptedKey Id="duplicate"/>' .
    '</enc:EncryptedData>',
    LIBXML_NONET
);
require_xmlsec_rejection(
    static fn() => XMLSecEnc::staticLocateKeyInfo($symmetricKey, $document->documentElement),
    'An ambiguous EncryptedKey ID was accepted.'
);

$document = new DOMDocument();
$document->loadXML(
    '<enc:EncryptedData xmlns:enc="http://www.w3.org/2001/04/xmlenc#" ' .
    'xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' .
    '<ds:KeyInfo><ds:RetrievalMethod Type="http://www.w3.org/2001/04/xmlenc#EncryptedKey" ' .
    'URI="#quoted&quot;id"/></ds:KeyInfo><enc:EncryptedKey Id="quotedid"/>' .
    '</enc:EncryptedData>',
    LIBXML_NONET
);
require_xmlsec_rejection(
    static fn() => XMLSecEnc::staticLocateKeyInfo($symmetricKey, $document->documentElement),
    'A lossy EncryptedKey reference ID was accepted.'
);

$document = new DOMDocument();
$document->loadXML(
    '<enc:EncryptedData xmlns:enc="http://www.w3.org/2001/04/xmlenc#" ' .
    'xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' .
    '<ds:KeyInfo/><ds:KeyInfo/></enc:EncryptedData>',
    LIBXML_NONET
);
require_xmlsec_rejection(
    static fn() => XMLSecEnc::staticLocateKeyInfo($symmetricKey, $document->documentElement),
    'Multiple KeyInfo elements were accepted.'
);

$fetch = new ReflectionMethod(XMLSecurityDSig::class, 'fetchCertFromURL');
require_xmlsec_rejection(
    static fn() => $fetch->invoke(null, "http://example.com/line\nbreak"),
    'A certificate URL containing control characters was accepted.'
);
require_xmlsec_rejection(
    static fn() => $fetch->invoke(null, 'http://8.8.8.8:81/certificate'),
    'A certificate URL using a nonstandard port was accepted.'
);
require_xmlsec_rejection(
    static fn() => $fetch->invoke(null, 'https://user:secret@example.com/certificate'),
    'A certificate URL containing credentials was accepted.'
);
$oversized = tempnam(sys_get_temp_dir(), 'sls-xmlsec-');
if ($oversized === false) {
    throw new RuntimeException('Unable to create the XML certificate-size fixture.');
}
try {
    file_put_contents($oversized, str_repeat('x', 4194305));
    require_xmlsec_rejection(
        static fn() => $fetch->invoke(null, 'file://' . $oversized, ['allow_file_scheme' => true]),
        'An oversized certificate URL response was accepted.'
    );
} finally {
    unlink($oversized);
}

require_xmlsec(
    XMLSecurityKey::getRawThumbprint(
        "-----BEGIN CERTIFICATE-----\n%%%invalid%%%\n-----END CERTIFICATE-----\n"
    ) === null,
    'Malformed PEM data produced a certificate thumbprint.'
);
require_xmlsec(
    XMLSecurityKey::getRawThumbprint(
        "-----BEGIN CERTIFICATE-----\nQQ==\n"
    ) === null,
    'An incomplete PEM certificate produced a thumbprint.'
);

$keyFile = tempnam(sys_get_temp_dir(), 'sls-key-');
if ($keyFile === false) {
    throw new RuntimeException('Unable to create the XML key fixture.');
}
try {
    file_put_contents($keyFile, str_repeat('k', 4194305));
    $boundedKey = new XMLSecurityKey(XMLSecurityKey::HMAC_SHA1);
    require_xmlsec_rejection(
        static fn() => $boundedKey->loadKey($keyFile, true),
        'An oversized key file was accepted.'
    );
} finally {
    unlink($keyFile);
}

require_xmlsec_rejection(
    static fn() => XMLSecurityDSig::staticGet509XCerts(str_repeat('x', 4194305), false),
    'Oversized direct certificate data was accepted.'
);
require_xmlsec_rejection(
    static fn() => XMLSecurityDSig::staticGet509XCerts(
        "-----BEGIN CERTIFICATE-----\n%%%invalid%%%\n-----END CERTIFICATE-----\n",
        true
    ),
    'Malformed PEM certificate data was accepted.'
);
require_xmlsec_rejection(
    static fn() => XMLSecurityDSig::staticGet509XCerts(
        "-----BEGIN CERTIFICATE-----\nQUJD\n",
        true
    ),
    'An incomplete PEM certificate was accepted.'
);
$manyCertificates = str_repeat(
    "-----BEGIN CERTIFICATE-----\nQQ==\n-----END CERTIFICATE-----\n",
    101
);
require_xmlsec_rejection(
    static fn() => XMLSecurityDSig::staticGet509XCerts($manyCertificates, true),
    'An excessive number of PEM certificates was accepted.'
);

$parseSameDocumentURI = new ReflectionMethod(XMLSecurityDSig::class, 'parseSameDocumentURI');
$signature = new XMLSecurityDSig();
require_xmlsec_rejection(
    static fn() => $parseSameDocumentURI->invoke($signature, '#' . str_repeat('a', 1025)),
    'An oversized same-document Reference URI was accepted.'
);
require_xmlsec_rejection(
    static fn() => new XMLSecurityDSig('bad:prefix'),
    'An invalid XML signature prefix was accepted.'
);
require_xmlsec_rejection(
    static fn() => (new XMLSecurityDSig())->setSignatureId("bad\nid"),
    'A Signature Id containing controls was accepted.'
);
require_xmlsec(
    (new XMLSecurityDSig())->locateSignature('not a node') === null,
    'A non-DOM signature search target was accepted.'
);

$transformXml = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo>' .
    '<ds:Reference><ds:Transforms>' .
    str_repeat('<ds:Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>', 21) .
    '</ds:Transforms></ds:Reference></ds:SignedInfo></ds:Signature>';
$transformDocument = new DOMDocument();
$transformDocument->loadXML($transformXml, LIBXML_NONET);
$signature = new XMLSecurityDSig();
$signature->locateSignature($transformDocument);
$transformXPath = new DOMXPath($transformDocument);
$transformXPath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
$reference = $transformXPath->query('//ds:Reference')->item(0);
require_xmlsec($reference instanceof DOMElement, 'Unable to construct the transform-count fixture.');
require_xmlsec_rejection(
    static fn() => $signature->processTransforms($reference, $transformDocument->documentElement),
    'An excessive number of transforms was accepted.'
);

$duplicateTransformDocument = new DOMDocument();
$duplicateTransformDocument->loadXML(
    '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo><ds:Reference>' .
    '<ds:Transforms/><ds:Transforms/></ds:Reference></ds:SignedInfo></ds:Signature>',
    LIBXML_NONET
);
$duplicateSignature = new XMLSecurityDSig();
$duplicateSignature->locateSignature($duplicateTransformDocument);
$duplicateXPath = new DOMXPath($duplicateTransformDocument);
$duplicateXPath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
$duplicateReference = $duplicateXPath->query('//ds:Reference')->item(0);
require_xmlsec($duplicateReference instanceof DOMElement, 'Unable to construct the duplicate Transforms fixture.');
require_xmlsec_rejection(
    static fn() => $duplicateSignature->processTransforms(
        $duplicateReference,
        $duplicateTransformDocument->documentElement
    ),
    'Multiple Transforms containers were accepted.'
);

$referenceXml = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo>' .
    str_repeat('<ds:Reference URI=""/>', 101) .
    '</ds:SignedInfo></ds:Signature>';
$referenceDocument = new DOMDocument();
$referenceDocument->loadXML($referenceXml, LIBXML_NONET);
$signature = new XMLSecurityDSig();
$signature->locateSignature($referenceDocument);
require_xmlsec_rejection(
    static fn() => $signature->validateReference(),
    'An excessive number of signed References was accepted.'
);

$orderedTransformXml = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo>' .
    '<ds:Reference><ds:Transforms>' .
    '<ds:Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>' .
    '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>' .
    '</ds:Transforms></ds:Reference></ds:SignedInfo></ds:Signature>';
$orderedTransformDocument = new DOMDocument();
$orderedTransformDocument->loadXML($orderedTransformXml, LIBXML_NONET);
$signature = new XMLSecurityDSig();
$signature->locateSignature($orderedTransformDocument);
$orderedXPath = new DOMXPath($orderedTransformDocument);
$orderedXPath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
$orderedReference = $orderedXPath->query('//ds:Reference')->item(0);
require_xmlsec($orderedReference instanceof DOMElement, 'Unable to construct the transform-order fixture.');
require_xmlsec_rejection(
    static fn() => $signature->processTransforms($orderedReference, $orderedTransformDocument->documentElement),
    'A Transform following canonicalization was accepted.'
);

$emptyCertificateDocument = new DOMDocument();
$emptyCertificateDocument->loadXML('<Root/>', LIBXML_NONET);
require_xmlsec_rejection(
    static fn() => XMLSecurityDSig::staticAdd509Cert($emptyCertificateDocument->documentElement, 'not a certificate'),
    'An empty certificate bundle was attached to KeyInfo.'
);

echo "xmlseclibs parser and certificate-fetch boundary checks passed.\n";
