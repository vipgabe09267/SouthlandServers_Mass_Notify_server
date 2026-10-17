<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\ASN1;
use phpseclib3\File\X509;

function require_x509(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$key = RSA::createKey(1024);
$issuer = new X509();
$issuer->setPrivateKey($key);
$issuer->setDNProp('id-at-commonName', 'SLS fixture CA');
$subject = new X509();
$subject->setPublicKey($key->getPublicKey());
$subject->setDNProp('id-at-commonName', 'SLS fixture subject');
$builder = new X509();
$certificate = $builder->sign($issuer, $subject);
require_x509(is_array($certificate), 'Unable to create the disposable X.509 fixture.');
$pem = $builder->saveX509($certificate);
$der = ASN1::extractBER($pem);
require_x509(is_string($der), 'Unable to extract the disposable DER certificate.');

$loader = new X509();
require_x509(
    is_array($loader->loadX509($der, X509::FORMAT_DER)),
    'A valid exact-length DER certificate was rejected.'
);
require_x509(
    $loader->loadX509($der . "\0", X509::FORMAT_DER) === false,
    'A DER certificate with trailing bytes was accepted.'
);

$csrBuilder = new X509();
$csrBuilder->setPrivateKey($key);
$csrBuilder->setDNProp('id-at-commonName', 'SLS CSR fixture');
$csr = $csrBuilder->signCSR();
require_x509(is_array($csr), 'Unable to create the disposable CSR fixture.');
$csrDer = $csrBuilder->saveCSR($csr, X509::FORMAT_DER);
$objectLoader = new X509();
require_x509(is_array($objectLoader->loadCSR($csrDer, X509::FORMAT_DER)), 'A valid exact-length CSR was rejected.');
require_x509($objectLoader->loadCSR($csrDer . "\0", X509::FORMAT_DER) === false, 'A CSR with trailing bytes was accepted.');

$spkacBuilder = new X509();
$spkacBuilder->setPrivateKey($key);
$spkacBuilder->setChallenge('SLS fixture');
$spkac = $spkacBuilder->signSPKAC();
require_x509(is_array($spkac), 'Unable to create the disposable SPKAC fixture.');
$spkacDer = $spkacBuilder->saveSPKAC($spkac, X509::FORMAT_DER);
require_x509(is_array($objectLoader->loadSPKAC($spkacDer)), 'A valid exact-length SPKAC was rejected.');
require_x509($objectLoader->loadSPKAC($spkacDer . "\0") === false, 'An SPKAC with trailing bytes was accepted.');

$crlBuilder = new X509();
$crl = $crlBuilder->signCRL($issuer, new X509());
require_x509(is_array($crl), 'Unable to create the disposable CRL fixture.');
$crlDer = $crlBuilder->saveCRL($crl, X509::FORMAT_DER);
require_x509(is_array($objectLoader->loadCRL($crlDer, X509::FORMAT_DER)), 'A valid exact-length CRL was rejected.');
require_x509($objectLoader->loadCRL($crlDer . "\0", X509::FORMAT_DER) === false, 'A CRL with trailing bytes was accepted.');

$loader->setKeyIdentifier('preserved-state');
require_x509($loader->loadCA($pem), 'A valid certificate could not be added to the explicit trust collection.');
$identifier = new ReflectionProperty(X509::class, 'currentKeyIdentifier');
require_x509(
    $identifier->getValue($loader) === 'preserved-state',
    'Loading a CA changed the caller certificate key-identifier state.'
);

$requested = [[
    'extnId' => 'id-ce-subjectAltName',
    'critical' => false,
    'extnValue' => [['dNSName' => 'fixture.example']],
]];
$csr = [
    'certificationRequestInfo' => [
        'attributes' => [[
            'type' => 'pkcs-9-at-extensionRequest',
            'value' => [$requested],
        ]],
    ],
];
require_x509(
    $loader->getRequestedCertificateExtensions($csr) === $requested,
    'Requested certificate extensions were read from object state instead of the supplied CSR.'
);
require_x509(
    $loader->getRequestedCertificateExtensions(['certificationRequestInfo' => ['attributes' => [[
        'type' => 'pkcs-9-at-extensionRequest',
        'value' => [],
    ]]]]) === false,
    'An empty extension-request attribute was treated as a populated request.'
);

$nameSubject = new X509();
$nameSubject->setPublicKey($key->getPublicKey());
$nameSubject->setDNProp('id-at-commonName', 'safe.example|attacker.example');
$nameCertificate = $builder->sign($issuer, $nameSubject);
require_x509(is_array($nameCertificate), 'Unable to create the hostname regression certificate.');
$nameLoader = new X509();
require_x509(is_array($nameLoader->loadX509($builder->saveX509($nameCertificate))), 'Unable to load the hostname regression certificate.');
require_x509(!$nameLoader->validateURL('https://attacker.example'), 'A common name was interpreted as a regular expression.');

$caseSubject = new X509();
$caseSubject->setPublicKey($key->getPublicKey());
$caseSubject->setDomain('MiXeD.Example.test');
$caseCertificate = $builder->sign($issuer, $caseSubject);
require_x509(is_array($caseCertificate), 'Unable to create the SAN regression certificate.');
$caseLoader = new X509();
require_x509(is_array($caseLoader->loadX509($builder->saveX509($caseCertificate))), 'Unable to load the SAN regression certificate.');
require_x509($caseLoader->validateURL('https://mixed.example.test'), 'DNS SAN matching was not case-insensitive.');

$wildcardSubject = new X509();
$wildcardSubject->setPublicKey($key->getPublicKey());
$wildcardSubject->setDomain('*.example.test');
$wildcardCertificate = $builder->sign($issuer, $wildcardSubject);
require_x509(is_array($wildcardCertificate), 'Unable to create the wildcard hostname fixture.');
$wildcardLoader = new X509();
require_x509(is_array($wildcardLoader->loadX509($builder->saveX509($wildcardCertificate))), 'Unable to load the wildcard hostname fixture.');
require_x509($wildcardLoader->validateURL('https://one.example.test'), 'A valid single-label wildcard match was rejected.');
require_x509(!$wildcardLoader->validateURL('https://example.test'), 'A wildcard matched an empty hostname label.');
require_x509(!$wildcardLoader->validateURL('https://two.one.example.test'), 'A wildcard matched more than one hostname label.');

$fragmentSubject = new X509();
$fragmentSubject->setPublicKey($key->getPublicKey());
$fragmentSubject->setDomain('prefix*.example.test');
$fragmentCertificate = $builder->sign($issuer, $fragmentSubject);
require_x509(is_array($fragmentCertificate), 'Unable to create the wildcard-fragment fixture.');
$fragmentLoader = new X509();
require_x509(is_array($fragmentLoader->loadX509($builder->saveX509($fragmentCertificate))), 'Unable to load the wildcard-fragment fixture.');
require_x509(!$fragmentLoader->validateURL('https://prefix123.example.test'), 'A partial-label wildcard was accepted.');

require_x509(X509::decodeIP("\x7f\x00\x00\x01") === '127.0.0.1', 'A valid packed IPv4 address was rejected.');
require_x509(X509::decodeIP('bad') === false, 'An invalid packed IP address was accepted.');
require_x509(X509::decodeNameConstraintIP(str_repeat("\0", 7)) === false, 'An invalid IP name constraint was accepted.');
require_x509(X509::encodeIP(['127.0.0.1', 'ffff::']) === false, 'A mixed-family IP constraint was accepted.');

$alias = new X509();
require_x509($alias->setDNProp('organizationIdentifier', 'SLS-1'), 'The organizationIdentifier alias was rejected.');
require_x509($alias->getDNProp('id-at-organizationIdentifier') === ['SLS-1'], 'The organizationIdentifier alias was not normalized.');

$subarrayCheck = new ReflectionMethod(X509::class, 'isSubArrayValid');
$subarrayCheck->setAccessible(true);
require_x509(!$subarrayCheck->invoke($loader, ['present' => []], 'missing/path'), 'A missing subarray path was reported as valid.');

$cycleLoader = new X509();
$currentProperty = new ReflectionProperty(X509::class, 'currentCert');
$casProperty = new ReflectionProperty(X509::class, 'CAs');
$currentProperty->setValue($cycleLoader, ['tbsCertificate' => ['issuer' => 'A', 'subject' => 'leaf']]);
$casProperty->setValue($cycleLoader, [
    ['tbsCertificate' => ['issuer' => 'B', 'subject' => 'A']],
    ['tbsCertificate' => ['issuer' => 'A', 'subject' => 'B']],
]);
$cycleChain = $cycleLoader->getChain();
require_x509(is_array($cycleChain) && count($cycleChain) === 3, 'A cyclic CA collection did not terminate at the first repeated CA.');

$fetchDisabled = new ReflectionProperty(X509::class, 'disable_url_fetch');
require_x509($fetchDisabled->getValue() === true, 'X.509 authority URL fetching is not disabled by default.');
X509::enableURLFetch();
require_x509($fetchDisabled->getValue() === false, 'Explicit X.509 URL-fetch enablement failed.');
$fetchURL = new ReflectionMethod(X509::class, 'fetchURL');
$publicFetchIp = new ReflectionMethod(X509::class, 'isPublicFetchIp');
require_x509(!$publicFetchIp->invoke(null, '100.64.0.1'), 'CGNAT was accepted as a public AIA target.');
require_x509(!$publicFetchIp->invoke(null, '::ffff:127.0.0.1'), 'Mapped loopback IPv6 was accepted as a public AIA target.');
require_x509(!$publicFetchIp->invoke(null, '::ffff:7f00:1'), 'Hex-encoded mapped loopback IPv6 was accepted as a public AIA target.');
X509::setURLFetchCallback(static fn() => true);
require_x509(
    $fetchURL->invoke(null, 'http://127.0.0.1/certificate') === false,
    'Explicit URL fetching allowed a private address even when a permissive callback was installed.'
);
require_x509(
    $fetchURL->invoke(null, 'http://8.8.8.8:81/certificate') === false,
    'Explicit URL fetching allowed a nonstandard AIA port.'
);
X509::disableURLFetch();
require_x509($fetchDisabled->getValue() === true, 'Explicit X.509 URL-fetch disablement failed.');
$invalidCallbackRejected = false;
try {
    X509::setURLFetchCallback('definitely-not-callable');
} catch (InvalidArgumentException $error) {
    $invalidCallbackRejected = true;
}
require_x509($invalidCallbackRejected, 'A non-callable X.509 URL-fetch policy was accepted.');
X509::setURLFetchCallback(null);

echo "phpseclib X.509 state and input-boundary checks passed.\n";
