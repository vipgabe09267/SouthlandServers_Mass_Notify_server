<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\ChaCha20;
use phpseclib3\Crypt\Common\Formats\Keys\JWK;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\Formats\Keys\PKCS1 as ECPKCS1;
use phpseclib3\Crypt\EC\Formats\Keys\PKCS8 as ECPKCS8;
use phpseclib3\Crypt\EC\Formats\Keys\PuTTY as ECPuTTY;
use phpseclib3\Crypt\EC\Formats\Keys\XML as ECXML;
use phpseclib3\Crypt\EC\Formats\Keys\libsodium as ECLibsodium;
use phpseclib3\Crypt\Hash;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RC2;
use phpseclib3\Crypt\RC4;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\Salsa20;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\Math\BigInteger;

final class RsaPkcs8Fixture extends phpseclib3\Crypt\RSA\Formats\Keys\PKCS8
{
    public static function wrapPrivateFixture(string $private, string $public = ''): string
    {
        return parent::wrapPrivateKey(
            $private,
            [],
            null,
            '',
            null,
            $public === '' ? '' : "\0" . $public
        );
    }
}

final class RsaPssFixture extends phpseclib3\Crypt\RSA\Formats\Keys\PSS
{
    public static function wrapPrivateFixture(string $private, $params, string $public = ''): string
    {
        return parent::wrapPrivateKey(
            $private,
            [],
            $params,
            '',
            null,
            $public === '' ? '' : "\0" . $public
        );
    }

    public static function wrapPublicFixture(string $public, $params): string
    {
        return parent::wrapPublicKey($public, $params);
    }
}

final class RsaPuttyFixture extends phpseclib3\Crypt\RSA\Formats\Keys\PuTTY
{
    public static function wrapPrivateFixture(string $public, string $private, $password = false, array $options = []): string
    {
        return parent::wrapPrivateKey($public, $private, 'ssh-rsa', $password, $options);
    }
}

function require_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$jwk = JWK::load('{"kty":"RSA","kid":"alpha beta"}');
require_true(($jwk->kid ?? null) === 'alpha beta', 'JWK string whitespace was changed.');

try {
    JWK::load('{"keys":{"kty":"RSA"}}');
    throw new RuntimeException('A non-array JWK set was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'A non-array JWK set was accepted.') {
        throw $exception;
    }
}

$key = RSA::createKey(1024)->withPassword('0');
require_true(
    str_contains($key->toString('PKCS1'), 'Proc-Type: 4,ENCRYPTED'),
    'Password string zero produced an unencrypted PKCS#1 key.'
);
require_true(
    str_contains($key->toString('PKCS8'), 'BEGIN ENCRYPTED PRIVATE KEY'),
    'Password string zero produced an unencrypted PKCS#8 key.'
);

RSA::setExponent(3);
try {
    $exponentKey = RSA::createKey(512);
    $rsaPublicExponent = new ReflectionProperty(RSA::class, 'publicExponent');
    $rsaPublicExponent->setAccessible(true);
    require_true(
        $rsaPublicExponent->getValue($exponentKey)->toString() === '3',
        'RSA key generation reused a stale cached public exponent.'
    );
} finally {
    RSA::setExponent(65537);
}
foreach ([2, 4, '65537'] as $invalidExponent) {
    try {
        RSA::setExponent($invalidExponent);
        throw new RuntimeException('RSA accepted an invalid public exponent.');
    } catch (UnexpectedValueException $expected) {
    }
}
foreach ([255, '4096'] as $invalidPrimeSize) {
    try {
        RSA::setSmallestPrime($invalidPrimeSize);
        throw new RuntimeException('RSA accepted an invalid smallest-prime size.');
    } catch (UnexpectedValueException $expected) {
    }
}
try {
    RSA::createKey('1024');
    throw new RuntimeException('RSA accepted a non-integer key size.');
} catch (UnexpectedValueException $expected) {
}
$oddSizedRsa = RSA::createKey(513)->withPadding(RSA::SIGNATURE_PKCS1);
$oddSizedSignature = $oddSizedRsa->sign('odd-sized RSA modulus');
require_true(
    $oddSizedRsa->getLength() === 513 &&
        $oddSizedRsa->getPublicKey()->verify('odd-sized RSA modulus', $oddSizedSignature),
    'A non-byte-aligned RSA modulus used an undersized octet length.'
);
$oddSizedPublicBlob = $oddSizedRsa->getPublicKey()->toString('MSBLOB');
$oddSizedPrivateBlob = $oddSizedRsa->withPassword()->toString('MSBLOB');
require_true(
    RSA::load($oddSizedPublicBlob)->getLength() === 513 &&
        RSA::load($oddSizedPrivateBlob)->getLength() === 513,
    'Fixed-width MSBLOB public/private round-trip failed for an odd-sized modulus.'
);
$publicBlobBytes = Strings::base64_decode($oddSizedPublicBlob);
require_true(is_string($publicBlobBytes), 'Unable to decode generated MSBLOB fixture.');
$invalidBlobs = [];
$badVersionBlob = $publicBlobBytes;
$badVersionBlob[1] = "\x03";
$invalidBlobs[] = $badVersionBlob;
$badMagicBlob = substr_replace($publicBlobBytes, 'RSA2', 8, 4);
$invalidBlobs[] = $badMagicBlob;
$invalidBlobs[] = $publicBlobBytes . str_repeat("\0", 8);
foreach ($invalidBlobs as $invalidBlob) {
    try {
        RSA::load(Strings::base64_encode($invalidBlob));
        throw new RuntimeException('RSA accepted an inconsistent or trailing-data MSBLOB.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted an inconsistent or trailing-data MSBLOB.') {
            throw $expected;
        }
    }
}
try {
    $key->toString('MSBLOB');
    throw new RuntimeException('MSBLOB export silently discarded a private-key password.');
} catch (phpseclib3\Exception\UnsupportedFormatException $expected) {
}
$wideExponentRsa = RSA::load([
    'n' => new BigInteger('281474976710677'),
    'e' => new BigInteger('1099511627777'),
]);
try {
    $wideExponentRsa->toString('MSBLOB');
    throw new RuntimeException('MSBLOB accepted a public exponent wider than a DWORD.');
} catch (InvalidArgumentException $expected) {
}

$sha3Message = 'RSA SHA3 interoperability';
foreach ([224, 256, 384, 512] as $sha3Bits) {
    $rsaSha3 = $key
        ->withHash('sha3/' . $sha3Bits)
        ->withPadding(RSA::SIGNATURE_PKCS1);
    $rsaSha3Signature = $rsaSha3->sign($sha3Message);
    $rsaSha3Public = $rsaSha3->getPublicKey();
    require_true(
        $rsaSha3Public->verify($sha3Message, $rsaSha3Signature),
        'RSA SHA3 PKCS#1 verification failed for SHA3-' . $sha3Bits . '.'
    );
    $opensslHash = 'sha3-' . $sha3Bits;
    if (function_exists('openssl_verify') && in_array($opensslHash, openssl_get_md_methods(), true)) {
        require_true(
            openssl_verify(
                $sha3Message,
                $rsaSha3Signature,
                $rsaSha3Public->toString('PKCS8'),
                $opensslHash
            ) === 1,
            'OpenSSL rejected the RSA SHA3-' . $sha3Bits . ' PKCS#1 signature.'
        );
    }
}
foreach (
    [
        static fn() => $key->withHash([]),
        static fn() => $key->withMGFHash([]),
        static fn() => $key->withSaltLength(-1),
        static fn() => $key->withSaltLength('1'),
        static fn() => $key->withLabel([]),
        static fn() => $key->withPadding(128),
        static fn() => $key->withPadding('64'),
    ] as $invalidRsaOption
) {
    try {
        $invalidRsaOption();
        throw new RuntimeException('RSA accepted an invalid option value.');
    } catch (UnexpectedValueException $expected) {
    }
}
require_true(
    $key->withSaltLength(null)->getSaltLength() === $key->getHash()->getLengthInBytes(),
    'RSA no longer accepts the internal automatic salt-length sentinel.'
);
foreach ([[15, 1], [14, 3], [15, 16]] as [$invalidModulus, $invalidPublicExponent]) {
    try {
        RSA::load([
            'n' => new BigInteger($invalidModulus),
            'e' => new BigInteger($invalidPublicExponent),
        ]);
        throw new RuntimeException('RSA accepted malformed raw public-key components.');
    } catch (\phpseclib3\Exception\NoKeyLoadedException $expected) {
    }
}
$validRsaJwkSet = json_decode($key->getPublicKey()->toString('JWK'), true, 16, JSON_THROW_ON_ERROR);
$validRsaJwk = $validRsaJwkSet['keys'][0];
$invalidRsaJwks = [];
$paddedRsaJwk = $validRsaJwk;
$paddedRsaJwk['n'] .= '=';
$invalidRsaJwks[] = $paddedRsaJwk;
$leadingZeroRsaJwk = $validRsaJwk;
$leadingZeroRsaJwk['n'] = Strings::base64url_encode(
    "\0" . Strings::base64url_decode($validRsaJwk['n'])
);
$invalidRsaJwks[] = $leadingZeroRsaJwk;
$multiPrimeRsaJwk = $validRsaJwk;
$multiPrimeRsaJwk['oth'] = [];
$invalidRsaJwks[] = $multiPrimeRsaJwk;
$wrongTypeRsaJwk = $validRsaJwk;
$wrongTypeRsaJwk['kty'] = 0;
$invalidRsaJwks[] = $wrongTypeRsaJwk;
foreach ($invalidRsaJwks as $invalidRsaJwk) {
    try {
        RSA::load(json_encode(['keys' => [$invalidRsaJwk]], JSON_THROW_ON_ERROR));
        throw new RuntimeException('RSA accepted a non-canonical or unsupported JWK.');
    } catch (UnexpectedValueException | RuntimeException $expected) {
        if ($expected->getMessage() === 'RSA accepted a non-canonical or unsupported JWK.') {
            throw $expected;
        }
    }
}
try {
    $key->toString('JWK');
    throw new RuntimeException('RSA JWK export silently discarded a private-key password.');
} catch (InvalidArgumentException $expected) {
}
$unprotectedRsaJwk = $key->withPassword()->toString('JWK');
require_true(
    RSA::load($unprotectedRsaJwk) instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Unprotected RSA private JWK round-trip failed.'
);

$pkcs1Public = $key->getPublicKey()->toString('PKCS1');
$pkcs1PublicDerText = preg_replace('#(?:^-.*?-[\r\n]*$)|\s#ms', '', $pkcs1Public);
require_true(is_string($pkcs1PublicDerText), 'Unable to extract PKCS#1 public fixture.');
$pkcs1PublicDer = Strings::base64_decode($pkcs1PublicDerText);
require_true(is_string($pkcs1PublicDer), 'Unable to decode PKCS#1 public fixture.');
$pkcs1Private = $key->withPassword()->toString('PKCS1');
$pkcs1PrivateDerText = preg_replace('#(?:^-.*?-[\r\n]*$)|\s#ms', '', $pkcs1Private);
require_true(is_string($pkcs1PrivateDerText), 'Unable to extract PKCS#1 private fixture.');
$pkcs1PrivateDer = Strings::base64_decode($pkcs1PrivateDerText);
require_true(is_string($pkcs1PrivateDer), 'Unable to decode PKCS#1 private fixture.');
$wrapPkcs1Public = static fn(string $der): string =>
    "-----BEGIN RSA PUBLIC KEY-----\n" .
    chunk_split(Strings::base64_encode($der), 64, "\n") .
    "-----END RSA PUBLIC KEY-----\n";
$negativePkcs1Der = ASN1::encodeDER(
    [
        'modulus' => new BigInteger(-15),
        'publicExponent' => new BigInteger(3),
    ],
    Maps\RSAPublicKey::MAP
);
$invalidPkcs1Keys = [
    str_replace('RSA PUBLIC KEY', 'RSA PRIVATE KEY', $pkcs1Public),
    $wrapPkcs1Public($pkcs1PublicDer . $pkcs1PublicDer),
    $wrapPkcs1Public($negativePkcs1Der),
];
foreach ($invalidPkcs1Keys as $pkcs1Index => $invalidPkcs1Key) {
    try {
        RSA::load($invalidPkcs1Key);
        throw new RuntimeException('RSA accepted PKCS#1 invalid fixture ' . $pkcs1Index . '.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted PKCS#1 invalid fixture ' . $pkcs1Index . '.') {
            throw $expected;
        }
    }
}
try {
    phpseclib3\Crypt\RSA\Formats\Keys\PKCS1::savePrivateKey(
        new BigInteger(105),
        new BigInteger(5),
        new BigInteger(5),
        [1 => new BigInteger(3), 3 => new BigInteger(7)],
        [1 => new BigInteger(1), 2 => new BigInteger(1)],
        [2 => new BigInteger(1)]
    );
    throw new RuntimeException('PKCS#1 accepted sparse RSA CRT arrays.');
} catch (InvalidArgumentException $expected) {
}
$otherPkcs1Public = RSA::createKey(512)->getPublicKey()->toString('PKCS1');
$otherPkcs1PublicText = preg_replace('#(?:^-.*?-[\r\n]*$)|\s#ms', '', $otherPkcs1Public);
require_true(is_string($otherPkcs1PublicText), 'Unable to extract second PKCS#1 public fixture.');
$otherPkcs1PublicDer = Strings::base64_decode($otherPkcs1PublicText);
require_true(is_string($otherPkcs1PublicDer), 'Unable to decode second PKCS#1 public fixture.');
require_true(
    RSA::load(RsaPkcs8Fixture::wrapPrivateFixture($pkcs1PrivateDer, $pkcs1PublicDer))
        instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Matching PKCS#8 v2 public/private RSA components did not round-trip.'
);
foreach (
    [
        RsaPkcs8Fixture::wrapPrivateFixture($pkcs1PublicDer),
        RsaPkcs8Fixture::wrapPrivateFixture($pkcs1PrivateDer, $otherPkcs1PublicDer),
    ] as $invalidPkcs8
) {
    try {
        RSA::load($invalidPkcs8);
        throw new RuntimeException('RSA accepted inconsistent PKCS#8 envelope contents.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted inconsistent PKCS#8 envelope contents.') {
            throw $expected;
        }
    }
}
$pkcs1Components = phpseclib3\Crypt\RSA\Formats\Keys\PKCS1::load($pkcs1Private);
$rawPublic = RSA::load([
    'E' => $pkcs1Components['publicExponent'],
    'N' => $pkcs1Components['modulus'],
]);
require_true(
    $rawPublic instanceof phpseclib3\Crypt\RSA\PublicKey,
    'Raw RSA public aliases did not round-trip.'
);
$rawPrivate = RSA::load([
    'e' => $pkcs1Components['publicExponent'],
    'n' => $pkcs1Components['modulus'],
    'd' => $pkcs1Components['privateExponent'],
    'prime1' => $pkcs1Components['primes'][1],
    'prime2' => $pkcs1Components['primes'][2],
    'exponent1' => $pkcs1Components['exponents'][1],
    'exponent2' => $pkcs1Components['exponents'][2],
    'coefficient' => $pkcs1Components['coefficients'][2],
]);
require_true(
    $rawPrivate instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Raw RSA private aliases did not round-trip.'
);
$rawDerived = RSA::load([
    'e' => $pkcs1Components['publicExponent'],
    'n' => $pkcs1Components['modulus'],
    'primes' => $pkcs1Components['primes'],
]);
$rawDerivedSignature = $rawDerived->sign('raw-derived-components');
require_true(
    $rawDerived->getPublicKey()->verify('raw-derived-components', $rawDerivedSignature),
    'Raw RSA failed to derive consistent private and CRT exponents.'
);
$invalidRawKeys = [
    [
        'n' => $pkcs1Components['modulus'],
        'd' => $pkcs1Components['privateExponent'],
    ],
    [
        'e' => $pkcs1Components['publicExponent'],
        'E' => $pkcs1Components['publicExponent'],
        'n' => $pkcs1Components['modulus'],
    ],
    [
        'e' => $pkcs1Components['publicExponent'],
        'exponent' => new BigInteger(3),
        'n' => $pkcs1Components['modulus'],
    ],
    [
        'e' => $pkcs1Components['publicExponent'],
        'n' => $pkcs1Components['modulus'],
        'd' => $pkcs1Components['privateExponent'],
        'primes' => [
            1 => $pkcs1Components['primes'][1],
            3 => $pkcs1Components['primes'][2],
        ],
    ],
    [
        'e' => $pkcs1Components['publicExponent'],
        'n' => $pkcs1Components['modulus'],
        'd' => $pkcs1Components['privateExponent'],
        'primes' => $pkcs1Components['primes'],
        'coefficients' => [
            2 => $pkcs1Components['coefficients'][2]->add(new BigInteger(1)),
        ],
    ],
];
foreach ($invalidRawKeys as $rawIndex => $invalidRawKey) {
    try {
        RSA::load($invalidRawKey);
        throw new RuntimeException('RSA accepted malformed raw fixture ' . $rawIndex . '.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted malformed raw fixture ' . $rawIndex . '.') {
            throw $expected;
        }
    }
}
try {
    phpseclib3\Crypt\RSA\Formats\Keys\Raw::savePrivateKey(
        $pkcs1Components['modulus'],
        $pkcs1Components['publicExponent'],
        $pkcs1Components['privateExponent'],
        $pkcs1Components['primes'],
        $pkcs1Components['exponents'],
        $pkcs1Components['coefficients'],
        '0'
    );
    throw new RuntimeException('Raw RSA export silently discarded password string zero.');
} catch (phpseclib3\Exception\UnsupportedFormatException $expected) {
}
$puttyPublic = Strings::packSSH2(
    'ii',
    $pkcs1Components['publicExponent'],
    $pkcs1Components['modulus']
);
$puttyPrivate = Strings::packSSH2(
    'iiii',
    $pkcs1Components['privateExponent'],
    $pkcs1Components['primes'][1],
    $pkcs1Components['primes'][2],
    $pkcs1Components['coefficients'][2]
);
$puttyPlain = RsaPuttyFixture::wrapPrivateFixture($puttyPublic, $puttyPrivate);
require_true(
    RSA::load($puttyPlain) instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Valid unencrypted PuTTY RSA key did not round-trip.'
);
$puttyPublicText = $key->getPublicKey()->toString('PuTTY');
require_true(
    RSA::load($puttyPublicText) instanceof phpseclib3\Crypt\RSA\PublicKey,
    'Valid RFC 4716 PuTTY RSA public key did not round-trip.'
);
$puttyEncrypted = RsaPuttyFixture::wrapPrivateFixture($puttyPublic, $puttyPrivate, 'putty-password');
require_true(
    RSA::load($puttyEncrypted, 'putty-password') instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Valid encrypted PuTTY RSA key did not round-trip.'
);
$puttyComment = $key->withPassword(false)->toString('PuTTY', ['comment' => "reviewed\r\nPrivate-MAC: injected"]);
require_true(
    RSA::load($puttyComment)->getComment() === 'reviewedPrivate-MAC: injected',
    'PuTTY comment line breaks were not removed safely.'
);
$badPuttyPrivate = Strings::packSSH2(
    'iiii',
    $pkcs1Components['privateExponent'],
    $pkcs1Components['primes'][1],
    $pkcs1Components['primes'][2],
    $pkcs1Components['coefficients'][2]->add(new BigInteger(1))
);
$invalidPuttyKeys = [
    RsaPuttyFixture::wrapPrivateFixture($puttyPublic . "\x00", $puttyPrivate),
    RsaPuttyFixture::wrapPrivateFixture($puttyPublic, $puttyPrivate . str_repeat("\x00", 17), false),
    RsaPuttyFixture::wrapPrivateFixture($puttyPublic, $badPuttyPrivate),
    $puttyPlain . "Trailing-Field: rejected\r\n",
    preg_replace('/^PuTTY-User-Key-File-2:/', 'PuTTY-User-Key-File-2x:', $puttyPlain),
];
foreach ($invalidPuttyKeys as $puttyIndex => $invalidPuttyKey) {
    require_true(is_string($invalidPuttyKey), 'Unable to create malformed PuTTY fixture.');
    try {
        RSA::load($invalidPuttyKey);
        throw new RuntimeException('RSA accepted malformed PuTTY fixture ' . $puttyIndex . '.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted malformed PuTTY fixture ' . $puttyIndex . '.') {
            throw $expected;
        }
    }
}
$puttyV3Bomb = preg_replace('/^PuTTY-User-Key-File-2:/', 'PuTTY-User-Key-File-3:', $puttyEncrypted);
require_true(is_string($puttyV3Bomb), 'Unable to create PuTTY v3 work-factor fixture.');
$puttyV3Bomb = preg_replace(
    '/^Private-Lines:/m',
    "Key-Derivation: Argon2id\r\nArgon2-Memory: 999999999\r\nArgon2-Passes: 1\r\nArgon2-Parallelism: 1\r\nArgon2-Salt: " . str_repeat('00', 16) . "\r\nPrivate-Lines:",
    $puttyV3Bomb
);
$puttyV3Bomb = preg_replace('/^Private-MAC: [0-9a-f]+$/m', 'Private-MAC: ' . str_repeat('0', 64), $puttyV3Bomb);
require_true(is_string($puttyV3Bomb), 'Unable to finalize PuTTY v3 work-factor fixture.');
try {
    RSA::load($puttyV3Bomb, 'putty-password');
    throw new RuntimeException('PuTTY accepted an excessive Argon2 memory request.');
} catch (Throwable $expected) {
    if ($expected->getMessage() === 'PuTTY accepted an excessive Argon2 memory request.') {
        throw $expected;
    }
}

$pssKey = $key
    ->withPassword()
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha3/256')
    ->withMGFHash('sha3/384')
    ->withSaltLength(12);
$loadedPssKey = RSA::load($pssKey->toString('PSS'));
require_true(
    $loadedPssKey->getHash()->getHash() === 'sha3-256' &&
        $loadedPssKey->getMGFHash()->getHash() === 'sha3-384' &&
        $loadedPssKey->getSaltLength() === 12,
    'RSA-PSS SHA-3 parameters did not round-trip.'
);
$validPssParams = phpseclib3\Crypt\RSA\Formats\Keys\PSS::savePSSParams([
    'hash' => 'sha256',
    'MGFHash' => 'sha384',
    'saltLength' => 16,
]);
require_true(
    RSA::load(RsaPssFixture::wrapPrivateFixture($pkcs1PrivateDer, $validPssParams, $pkcs1PublicDer))
        instanceof phpseclib3\Crypt\RSA\PrivateKey,
    'Matching RSA-PSS v2 public/private components did not round-trip.'
);
$invalidPssParams = [
    new ASN1\Element($validPssParams->element . "\x00"),
    new ASN1\Element(ASN1::encodeDER(
        ['trailerField' => new BigInteger(2)],
        Maps\RSASSA_PSS_params::MAP
    )),
    new ASN1\Element(ASN1::encodeDER(
        ['hashAlgorithm' => ['algorithm' => '1.2.3.4']],
        Maps\RSASSA_PSS_params::MAP
    )),
    new ASN1\Element(ASN1::encodeDER(
        [
            'maskGenAlgorithm' => [
                'algorithm' => 'rsaEncryption',
                'parameters' => new ASN1\Element(ASN1::encodeDER(
                    ['algorithm' => 'id-sha256'],
                    Maps\HashAlgorithm::MAP
                )),
            ],
        ],
        Maps\RSASSA_PSS_params::MAP
    )),
];
foreach ($invalidPssParams as $pssIndex => $invalidPssParam) {
    try {
        RSA::load(RsaPssFixture::wrapPublicFixture($pkcs1PublicDer, $invalidPssParam));
        throw new RuntimeException('RSA accepted malformed PSS parameters ' . $pssIndex . '.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted malformed PSS parameters ' . $pssIndex . '.') {
            throw $expected;
        }
    }
}
foreach (
    [
        RsaPssFixture::wrapPrivateFixture($pkcs1PublicDer, $validPssParams),
        RsaPssFixture::wrapPrivateFixture($pkcs1PrivateDer, $validPssParams, $otherPkcs1PublicDer),
    ] as $invalidPss
) {
    try {
        RSA::load($invalidPss);
        throw new RuntimeException('RSA accepted inconsistent PSS envelope contents.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted inconsistent PSS envelope contents.') {
            throw $expected;
        }
    }
}
foreach (
    [
        ['hash' => []],
        ['MGFHash' => 'unknown'],
        ['saltLength' => -1],
        ['saltLength' => '16'],
    ] as $invalidPssOptions
) {
    try {
        phpseclib3\Crypt\RSA\Formats\Keys\PSS::savePSSParams($invalidPssOptions);
        throw new RuntimeException('RSA-PSS accepted invalid serialization options.');
    } catch (UnexpectedValueException $expected) {
    }
}

$openssh = $key->toString('OpenSSH');
$body = preg_replace('#(?:^-.*?-[\r\n]*$)|\s#ms', '', $openssh);
require_true(is_string($body), 'Unable to extract the generated OpenSSH payload.');
$binary = Strings::base64_decode($body);
require_true(Strings::shift($binary, 15) === "openssh-key-v1\0", 'Generated OpenSSH key has invalid magic.');
[$cipher] = Strings::unpackSSH2('s', $binary);
require_true($cipher === 'aes256-ctr', 'Password string zero produced an unencrypted OpenSSH key.');

try {
    phpseclib3\Crypt\RSA\Formats\Keys\OpenSSH::load('ssh-rsa !!!!');
    throw new RuntimeException('Invalid OpenSSH Base64 was accepted.');
} catch (Throwable $exception) {
    if ($exception->getMessage() === 'Invalid OpenSSH Base64 was accepted.') {
        throw $exception;
    }
}

$plainOpenSsh = $key->withPassword()->toString(
    'OpenSSH',
    ['comment' => "reviewed\ninjected"]
);
$plainOpenSshKey = RSA::load($plainOpenSsh);
require_true(
    $plainOpenSshKey->getComment() === 'reviewedinjected',
    'OpenSSH private-key comment retained a line break.'
);
$publicOpenSsh = $key->getPublicKey()->toString(
    'OpenSSH',
    ['comment' => "reviewed\nssh-rsa injected"]
);
require_true(
    !str_contains($publicOpenSsh, "\n") && !str_contains($publicOpenSsh, "\r"),
    'OpenSSH public-key comment retained a line break.'
);

$plainBody = preg_replace('#(?:^-.*?-[\r\n]*$)|\s#ms', '', $plainOpenSsh);
require_true(is_string($plainBody), 'Unable to extract unencrypted OpenSSH fixture.');
$plainBinary = Strings::base64_decode($plainBody);
require_true(is_string($plainBinary), 'Unable to decode unencrypted OpenSSH fixture.');
require_true(
    Strings::shift($plainBinary, 15) === "openssh-key-v1\0",
    'Unencrypted OpenSSH fixture has invalid magic.'
);
[$plainCipher, $plainKdf, $plainKdfOptions, $plainKeyCount, $plainPublic, $plainPadded] =
    Strings::unpackSSH2('sssNss', $plainBinary);
require_true($plainBinary === '', 'Generated OpenSSH envelope contains trailing data.');
$wrapOpenSshFixture = static function (string $public, string $padded) use (
    $plainCipher,
    $plainKdf,
    $plainKdfOptions,
    $plainKeyCount
): string {
    $payload = "openssh-key-v1\0" . Strings::packSSH2(
        'sssNss',
        $plainCipher,
        $plainKdf,
        $plainKdfOptions,
        $plainKeyCount,
        $public,
        $padded
    );
    return "-----BEGIN OPENSSH PRIVATE KEY-----\n" .
        chunk_split(Strings::base64_encode($payload), 70, "\n") .
        "-----END OPENSSH PRIVATE KEY-----\n";
};
$mismatchedOpenSshPublic = $plainPublic;
$mismatchedOpenSshPublic[strlen($mismatchedOpenSshPublic) - 1] =
    chr(ord($mismatchedOpenSshPublic[strlen($mismatchedOpenSshPublic) - 1]) ^ 1);
$badOpenSshPadding = $plainPadded;
$badOpenSshPadding[strlen($badOpenSshPadding) - 1] = "\0";
foreach (
    [
        $wrapOpenSshFixture($mismatchedOpenSshPublic, $plainPadded),
        $wrapOpenSshFixture($plainPublic, $badOpenSshPadding),
    ] as $invalidOpenSshPrivate
) {
    try {
        RSA::load($invalidOpenSshPrivate);
        throw new RuntimeException('RSA accepted an inconsistent OpenSSH private key.');
    } catch (Throwable $expected) {
        if ($expected->getMessage() === 'RSA accepted an inconsistent OpenSSH private key.') {
            throw $expected;
        }
    }
}
$publicOpenSshParts = explode(' ', $publicOpenSsh, 3);
$publicOpenSshBlob = Strings::base64_decode($publicOpenSshParts[1]);
require_true(is_string($publicOpenSshBlob), 'Unable to decode OpenSSH public fixture.');
$trailingOpenSsh = $publicOpenSshParts[0] . ' ' .
    Strings::base64_encode($publicOpenSshBlob . "\0") . ' ' .
    ($publicOpenSshParts[2] ?? '');
try {
    RSA::load($trailingOpenSsh);
    throw new RuntimeException('RSA accepted trailing data in an OpenSSH public key.');
} catch (Throwable $expected) {
    if ($expected->getMessage() === 'RSA accepted trailing data in an OpenSSH public key.') {
        throw $expected;
    }
}
try {
    phpseclib3\Crypt\RSA\Formats\Keys\OpenSSH::savePrivateKey(
        new BigInteger(105),
        new BigInteger(5),
        new BigInteger(5),
        [1 => new BigInteger(3), 2 => new BigInteger(5), 3 => new BigInteger(7)],
        [1 => new BigInteger(1), 2 => new BigInteger(1), 3 => new BigInteger(1)],
        [2 => new BigInteger(1), 3 => new BigInteger(1)]
    );
    throw new RuntimeException('OpenSSH silently truncated a multi-prime RSA key.');
} catch (InvalidArgumentException $expected) {
}

$ecKey = EC::createKey('secp256r1');
$ecOtherKey = EC::createKey('secp256r1');
$ecPrivateScalar = new ReflectionProperty(phpseclib3\Crypt\EC\PrivateKey::class, 'dA');
$ecPrivateScalar->setAccessible(true);
$ecCurve = new ReflectionProperty(EC::class, 'curve');
$ecCurve->setAccessible(true);
$ecPublicPoint = new ReflectionProperty(EC::class, 'QA');
$ecPublicPoint->setAccessible(true);
$ecPrivateValue = $ecPrivateScalar->getValue($ecKey);
$ecCurveValue = $ecCurve->getValue($ecKey);
$ecPublicValue = $ecPublicPoint->getValue($ecKey);
$ecOtherPublicValue = $ecPublicPoint->getValue($ecOtherKey);
$ecPkcs1 = ECPKCS1::savePrivateKey($ecPrivateValue, $ecCurveValue, $ecPublicValue);
$ecParameters = ECPKCS1::saveParameters($ecCurveValue);
require_true(
    ECPKCS1::load($ecParameters . $ecPkcs1)['dA']->equals($ecPrivateValue),
    'EC parameters followed by an EC private key could not be loaded.'
);

try {
    ECPKCS1::load(ECPKCS1::savePrivateKey($ecPrivateValue, $ecCurveValue, $ecOtherPublicValue));
    throw new RuntimeException('EC PKCS#1 accepted a public point that did not match the private scalar.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'EC PKCS#1 accepted a public point that did not match the private scalar.') {
        throw $exception;
    }
}

try {
    ECPKCS1::load(ECPKCS1::savePrivateKey($ecCurveValue->getOrder(), $ecCurveValue, $ecPublicValue));
    throw new RuntimeException('EC PKCS#1 accepted a private scalar equal to the group order.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'EC PKCS#1 accepted a private scalar equal to the group order.') {
        throw $exception;
    }
}

try {
    ECPKCS8::load(ECPKCS8::savePrivateKey($ecPrivateValue, $ecCurveValue, $ecOtherPublicValue));
    throw new RuntimeException('EC PKCS#8 accepted a public point that did not match the private scalar.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'EC PKCS#8 accepted a public point that did not match the private scalar.') {
        throw $exception;
    }
}

foreach (['Ed25519', 'Curve25519', 'Curve448'] as $curveName) {
    $modernKey = EC::createKey($curveName);
    $loadedModernKey = ECPKCS8::load($modernKey->toString('PKCS8'));
    require_true(
        isset($loadedModernKey['dA'], $loadedModernKey['QA']),
        $curveName . ' PKCS#8 failed a private-key round trip.'
    );
}

$edPutty = EC::createKey('Ed25519')->withPassword('test password')->toString('PuTTY');
$loadedEdPutty = ECPuTTY::load($edPutty, 'test password');
require_true(
    isset($loadedEdPutty['secret'], $loadedEdPutty['QA']) && strlen($loadedEdPutty['secret']) === 32,
    'Encrypted Ed25519 PuTTY key failed to round trip.'
);

try {
    ECPuTTY::savePrivateKey($ecPrivateValue, $ecCurveValue, $ecOtherPublicValue);
    throw new RuntimeException('EC PuTTY serializer accepted mismatched public and private material.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'EC PuTTY serializer accepted mismatched public and private material.') {
        throw $exception;
    }
}

if (class_exists('DOMDocument')) {
    $ecPublicKey = $ecKey->getPublicKey();
    foreach ([false, true] as $rfc4050) {
        foreach ([false, true] as $specified) {
            $rfc4050 ? ECXML::enableRFC4050Syntax() : ECXML::disableRFC4050Syntax();
            $specified ? ECXML::useSpecifiedCurve() : ECXML::useNamedCurve();
            $xmlKey = $ecPublicKey->toString('XML');
            $loadedXmlKey = ECXML::load($xmlKey);
            require_true(isset($loadedXmlKey['curve'], $loadedXmlKey['QA']), 'EC XML key failed to round trip.');
        }
    }
    ECXML::disableRFC4050Syntax();
    ECXML::useNamedCurve();

    try {
        ECXML::load('<!DOCTYPE key [<!ENTITY x SYSTEM "file:///etc/passwd">]><ECDSAKeyValue>&x;</ECDSAKeyValue>');
        throw new RuntimeException('EC XML accepted a DTD declaration.');
    } catch (UnexpectedValueException $exception) {
        if ($exception->getMessage() === 'EC XML accepted a DTD declaration.') {
            throw $exception;
        }
    }

    try {
        ECXML::setNamespace('invalid:prefix');
        throw new RuntimeException('EC XML accepted an invalid namespace prefix.');
    } catch (InvalidArgumentException $exception) {
        if ($exception->getMessage() === 'EC XML accepted an invalid namespace prefix.') {
            throw $exception;
        }
    }
    ECXML::setNamespace(null);
}

$edSodiumKey = EC::createKey('Ed25519');
$edSodiumRaw = $edSodiumKey->toString('libsodium');
require_true(strlen($edSodiumRaw) === 64, 'Ed25519 libsodium private key has the wrong length.');
require_true(isset(ECLibsodium::load($edSodiumRaw)['dA']), 'Ed25519 libsodium private key failed to load.');
$mismatchedSodiumRaw = $edSodiumRaw;
$mismatchedSodiumRaw[63] = chr(ord($mismatchedSodiumRaw[63]) ^ 1);
try {
    ECLibsodium::load($mismatchedSodiumRaw);
    throw new RuntimeException('libsodium key loader accepted mismatched public and private material.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'libsodium key loader accepted mismatched public and private material.') {
        throw $exception;
    }
}
try {
    ECLibsodium::load("\1" . str_repeat("\0", 31));
    throw new RuntimeException('Ed25519 identity encoding was accepted as a public key.');
} catch (UnexpectedValueException $exception) {
    if ($exception->getMessage() === 'Ed25519 identity encoding was accepted as a public key.') {
        throw $exception;
    }
}
try {
    $edSodiumKey->withPassword('0')->toString('libsodium');
    throw new RuntimeException('libsodium key serializer silently ignored password string zero.');
} catch (phpseclib3\Exception\UnsupportedFormatException $expected) {
}

$signatureMessage = 'signature format boundary';
$ecdsaKey = EC::createKey('secp256r1');
$ecdsaPublic = $ecdsaKey->getPublicKey();
$asn1Signature = $ecdsaKey->withSignatureFormat('ASN1')->sign($signatureMessage);
require_true(
    $ecdsaPublic->withSignatureFormat('ASN1')->verify($signatureMessage, $asn1Signature),
    'Canonical ASN.1 ECDSA signature did not verify.'
);
require_true(
    !$ecdsaPublic->withSignatureFormat('ASN1')->verify($signatureMessage, $asn1Signature . "\0"),
    'ASN.1 ECDSA signature accepted trailing data.'
);
if (function_exists('openssl_get_md_methods')) {
    EC::forceEngine('OpenSSL');
    try {
        require_true(
            !$ecdsaPublic->withSignatureFormat('ASN1')->verify($signatureMessage . ' modified', $asn1Signature),
            'Forced OpenSSL accepted an invalid ECDSA signature.'
        );
    } finally {
        EC::forceEngine();
    }
}

$ieeeSignature = $ecdsaKey->withSignatureFormat('IEEE')->sign($signatureMessage);
require_true(
    $ecdsaPublic->withSignatureFormat('IEEE')->verify($signatureMessage, $ieeeSignature),
    'IEEE P1363 ECDSA signature did not verify.'
);
require_true(
    !$ecdsaPublic->withSignatureFormat('IEEE')->verify($signatureMessage, substr($ieeeSignature, 1)),
    'IEEE P1363 ECDSA signature accepted a noncanonical width.'
);

$sshSignature = $ecdsaKey->withSignatureFormat('SSH2')->sign($signatureMessage);
require_true(
    $ecdsaPublic->withSignatureFormat('SSH2')->verify($signatureMessage, $sshSignature),
    'SSH2 ECDSA signature did not verify.'
);
$sshSignatureCopy = $sshSignature;
[$sshType, $sshBlob] = Strings::unpackSSH2('ss', $sshSignatureCopy);
require_true($sshSignatureCopy === '', 'Generated SSH2 ECDSA signature has trailing data.');
$wrongCurveSshSignature = Strings::packSSH2('ss', 'ecdsa-sha2-nistp384', $sshBlob);
require_true(
    !$ecdsaPublic->withSignatureFormat('SSH2')->verify($signatureMessage, $wrongCurveSshSignature),
    'SSH2 ECDSA signature ignored its curve identifier.'
);
require_true(
    !$ecdsaPublic->withSignatureFormat('SSH2')->verify($signatureMessage, $sshSignature . "\0"),
    'SSH2 ECDSA signature accepted trailing data.'
);

$edSshKey = EC::createKey('Ed25519')->withSignatureFormat('SSH2');
$edSshSignature = $edSshKey->sign($signatureMessage);
$edSshPublic = $edSshKey->getPublicKey()->withSignatureFormat('SSH2');
require_true($edSshPublic->verify($signatureMessage, $edSshSignature), 'SSH2 Ed25519 signature did not verify.');
$edSshSignatureCopy = $edSshSignature;
[, $edSshBlob] = Strings::unpackSSH2('ss', $edSshSignatureCopy);
require_true(
    !$edSshPublic->verify($signatureMessage, Strings::packSSH2('ss', 'ssh-ed448', $edSshBlob)),
    'SSH2 Ed25519 signature ignored its algorithm identifier.'
);
require_true(
    !$edSshPublic->verify($signatureMessage, $edSshSignature . "\0"),
    'SSH2 Ed25519 signature accepted trailing data.'
);

foreach (['Curve25519', 'Curve448', 'secp256r1', 'sect233r1'] as $ecdhCurve) {
    $ecdhAlice = EC::createKey($ecdhCurve);
    $ecdhBob = EC::createKey($ecdhCurve);
    $aliceShared = $ecdhAlice->multiply($ecdhBob->getPublicKey()->getEncodedCoordinates());
    $bobShared = $ecdhBob->multiply($ecdhAlice->getPublicKey()->getEncodedCoordinates());
    require_true(hash_equals($aliceShared, $bobShared), $ecdhCurve . ' ECDH shared secrets did not match.');
}
$x25519Key = EC::createKey('Curve25519');
foreach ([str_repeat("\0", 32), str_repeat("\0", 31)] as $invalidPeer) {
    try {
        $x25519Key->multiply($invalidPeer);
        throw new RuntimeException('X25519 accepted a malformed or all-zero peer key.');
    } catch (UnexpectedValueException | LengthException $exception) {
        if ($exception->getMessage() === 'X25519 accepted a malformed or all-zero peer key.') {
            throw $exception;
        }
    }
}

$shakeVectors = [
    'shake256-1600' => '483366601360a8771c6863080cc4114d8db44530f8f1e1ee4f94ea37e78b5739d5a15bef186a5386c75744c0527e1faa9f8726e462a12a4feb06bd8801e751e41385141204f329979fd3047a13c5657724ada64d2470157b3cdc288620944d78dbcddbd912993f0913f164fb2ce95131a2d09a3e6d51cbfc622720d7a75c6334e8a2d7ec71a7cc29cf0ea610eeff1a588290a53000faa79932becec0bd3cd0b33a7e5d397fed1ada9442b99903f4dcfd8559ed3950faf40fe6f3b5d710ed3b677513771af6bfe119',
    'shake128-1600' => '5881092dd818bf5cf8a3ddb793fbcba74097d5c526a6d35f97b83351940f2cc844c50af32acd3f2cdd066568706f509bc1bdde58295dae3f891a9a0fca5783789a41f8611214ce612394df286a62d1a2252aa94db9c538956c717dc2bed4f232a0294c857c730aa16067ac1062f1201fb0d377cfb9cde4c63599b27f3462bba4a0ed296c801f9ff7f57302bb3076ee145f97a32ae68e76ab66c48d51675bd49acc29082f5647584e6aa01b3f5af057805f973ff8ecb8b226ac32ada6f01c1fcd4818cb006aa5b4cd',
];
foreach ($shakeVectors as $algorithm => $expectedHex) {
    require_true(
        bin2hex((new Hash($algorithm))->hash('abc')) === $expectedHex,
        $algorithm . ' failed its multi-block known-answer test.'
    );
}
$shakeUnkeyed = (new Hash('shake256-256'))->hash('abc');
foreach (['', '0'] as $hmacKey) {
    $shakeHmac = new Hash('shake256-256');
    $shakeHmac->setKey($hmacKey);
    require_true($shakeHmac->hash('abc') !== $shakeUnkeyed, 'SHAKE HMAC discarded a valid string key.');
}
try {
    (new Hash('umac-64'))->setNonce([]);
    throw new RuntimeException('UMAC accepted a non-string nonce.');
} catch (LengthException $expected) {
}
foreach (['shake256-7', 'shake256-65544'] as $invalidShake) {
    try {
        new Hash($invalidShake);
        throw new RuntimeException('SHAKE accepted an unsafe output length.');
    } catch (phpseclib3\Exception\UnsupportedAlgorithmException $expected) {
    }
}
$cmac = new Hash('aes_cmac');
$cmac->setKey(hex2bin('2b7e151628aed2a6abf7158809cf4f3c'));
$cmacExpected = hex2bin('bb1d6929e95937287fa37d129b756746');
require_true(hash_equals($cmacExpected, $cmac->hash('')), 'AES-CMAC failed the RFC 4493 empty-message vector.');
require_true(hash_equals($cmacExpected, $cmac->hash('')), 'AES-CMAC changed across repeated calls.');

$ecParameterPem = $ecKey->getParameters()->toString('PKCS1');
require_true(
    PublicKeyLoader::loadParameters($ecParameterPem) instanceof phpseclib3\Crypt\EC\Parameters,
    'PublicKeyLoader rejected a valid EC parameter object.'
);
try {
    PublicKeyLoader::loadParameters($ecKey->getPublicKey()->toString('PKCS8'));
    throw new RuntimeException('PublicKeyLoader accepted a public key as parameters.');
} catch (phpseclib3\Exception\NoKeyLoadedException $expected) {
}

$keyBytes = str_repeat('k', 16);
$iv = str_repeat('i', 16);
$poly1305Key = str_repeat('p', 32);
$plaintext = 'authenticated payload';
$encryptor = new AES('ctr');
$encryptor->setPreferredEngine('PHP');
$encryptor->setKey($keyBytes);
$encryptor->setIV($iv);
$encryptor->setPoly1305Key($poly1305Key);
$encryptor->setAAD('header');
$ciphertext = $encryptor->encrypt($plaintext);
$tag = $encryptor->getTag();
$encryptor->setAAD('next header');

foreach ([16, 8] as $tagLength) {
    $decryptor = new AES('ctr');
    $decryptor->setPreferredEngine('PHP');
    $decryptor->setKey($keyBytes);
    $decryptor->setIV($iv);
    $decryptor->setPoly1305Key($poly1305Key);
    $decryptor->setAAD('header');
    $decryptor->setTag(substr($tag, 0, $tagLength));
    require_true(
        $decryptor->decrypt($ciphertext) === $plaintext,
        'The shared Poly1305 decrypt path did not return authenticated plaintext.'
    );
}

$rejector = new AES('ctr');
$rejector->setPreferredEngine('PHP');
$rejector->setKey($keyBytes);
$rejector->setIV($iv);
$rejector->setPoly1305Key($poly1305Key);
$rejector->setAAD('header');
$tag[0] = chr(ord($tag[0]) ^ 1);
$rejector->setTag($tag);
try {
    $rejector->decrypt($ciphertext);
    throw new RuntimeException('The shared Poly1305 path accepted a modified tag.');
} catch (phpseclib3\Exception\BadDecryptionException $expected) {
}

$makeCtr = static function (bool $continuous): AES {
    $cipher = new AES('ctr');
    $cipher->setPreferredEngine('OpenSSL');
    $cipher->setKey(str_repeat('k', 16));
    $cipher->setIV(str_repeat('i', 16));
    if ($continuous) {
        $cipher->enableContinuousBuffer();
    }
    $emulation = new ReflectionProperty(
        phpseclib3\Crypt\Common\SymmetricKey::class,
        'openssl_emulate_ctr'
    );
    $emulation->setAccessible(true);
    $emulation->setValue($cipher, true);
    return $cipher;
};
$ctrPlaintext = 'abcdefghijklmnopqrstuvwxyz';
$oneShot = $makeCtr(false)->encrypt($ctrPlaintext);
$streamed = $makeCtr(true);
$split = $streamed->encrypt(substr($ctrPlaintext, 0, 3));
$split .= $streamed->encrypt(substr($ctrPlaintext, 3));
require_true($split === $oneShot, 'OpenSSL CTR emulation reused key bytes instead of buffered keystream bytes.');

$cbcKey = str_repeat('c', 16);
$cbcIv = str_repeat('v', 16);
$cbcEncryptor = new AES('cbc');
$cbcEncryptor->setPreferredEngine('PHP');
$cbcEncryptor->setKey($cbcKey);
$cbcEncryptor->setIV($cbcIv);
$cbcCiphertext = $cbcEncryptor->encrypt('A');
$invalidIv = $cbcIv;
$invalidIv[14] = chr(ord($invalidIv[14]) ^ 1);
$cbcDecryptor = new AES('cbc');
$cbcDecryptor->setPreferredEngine('PHP');
$cbcDecryptor->setKey($cbcKey);
$cbcDecryptor->setIV($invalidIv);
try {
    $cbcDecryptor->decrypt($cbcCiphertext);
    throw new RuntimeException('CBC decryption accepted inconsistent PKCS#7 padding bytes.');
} catch (phpseclib3\Exception\BadDecryptionException $expected) {
}

$chachaKey = str_repeat('h', 32);
$chachaNonce = str_repeat('n', 12);
$chachaEncryptor = new ChaCha20();
$chachaEncryptor->enablePoly1305();
$chachaEncryptor->setKey($chachaKey);
$chachaEncryptor->setNonce($chachaNonce);
$chachaCiphertext = $chachaEncryptor->encrypt('authenticated');
$chachaDecryptor = new ChaCha20();
$chachaDecryptor->enablePoly1305();
$chachaDecryptor->setKey($chachaKey);
$chachaDecryptor->setNonce($chachaNonce);
try {
    $chachaDecryptor->decrypt($chachaCiphertext);
    throw new RuntimeException('ChaCha20-Poly1305 decrypted without a supplied authentication tag.');
} catch (phpseclib3\Exception\InsufficientSetupException $expected) {
}

$salsaKey = str_repeat('s', 32);
$salsaNonce = str_repeat('o', 8);
$salsaEncryptor = new Salsa20();
$salsaEncryptor->enablePoly1305();
$salsaEncryptor->setKey($salsaKey);
$salsaEncryptor->setNonce($salsaNonce);
$salsaCiphertext = $salsaEncryptor->encrypt('authenticated');
$salsaDecryptor = new Salsa20();
$salsaDecryptor->enablePoly1305();
$salsaDecryptor->setKey($salsaKey);
$salsaDecryptor->setNonce($salsaNonce);
try {
    $salsaDecryptor->decrypt($salsaCiphertext);
    throw new RuntimeException('Salsa20-Poly1305 decrypted without a supplied authentication tag.');
} catch (phpseclib3\Exception\InsufficientSetupException $expected) {
}

$rc2Vectors = [
    ['0000000000000000', 63, '0000000000000000', 'ebb773f993278eff'],
    ['ffffffffffffffff', 64, 'ffffffffffffffff', '278b27e42e2f0d49'],
    ['3000000000000000', 64, '1000000000000001', '30649edf9be7d2c2'],
];
foreach ($rc2Vectors as [$keyHex, $effectiveBits, $plainHex, $cipherHex]) {
    $rc2 = new RC2('ecb');
    $rc2->setPreferredEngine('PHP');
    $rc2->disablePadding();
    $rc2->setKey(hex2bin($keyHex), $effectiveBits);
    require_true(
        bin2hex($rc2->encrypt(hex2bin($plainHex))) === $cipherHex,
        'RC2 encryption did not match an RFC 2268 test vector.'
    );
    require_true(
        bin2hex($rc2->decrypt(hex2bin($cipherHex))) === $plainHex,
        'RC2 decryption did not match an RFC 2268 test vector.'
    );
}
foreach ([[[], 64], ['key', '64'], ['key', 0], ['key', 1025]] as [$keyValue, $effectiveBits]) {
    try {
        (new RC2('ecb'))->setKey($keyValue, $effectiveBits);
        throw new RuntimeException('RC2 accepted an invalid key or effective-key length.');
    } catch (UnexpectedValueException | LengthException $expected) {
    }
}
foreach (['64', 7, 1025] as $keyBits) {
    try {
        (new RC2('ecb'))->setKeyLength($keyBits);
        throw new RuntimeException('RC2 accepted an invalid configured key length.');
    } catch (UnexpectedValueException | LengthException $expected) {
    }
}
$stableRc2 = new RC2('ecb');
$stableRc2->setPreferredEngine('PHP');
$stableRc2->disablePadding();
$stableRc2->setKey(hex2bin('0000000000000000'), 63);
$stableCiphertext = $stableRc2->encrypt(hex2bin('0000000000000000'));
try {
    $stableRc2->setKey('', 64);
    throw new RuntimeException('RC2 accepted an empty replacement key.');
} catch (LengthException $expected) {
}
require_true(
    $stableRc2->encrypt(hex2bin('0000000000000000')) === $stableCiphertext,
    'A rejected RC2 replacement key corrupted the active key state.'
);

$rc4 = new RC4();
$rc4->setPreferredEngine('PHP');
$rc4->setKey('Key');
require_true(
    bin2hex($rc4->encrypt('Plaintext')) === 'bbf316e8d940af0ad3',
    'RC4 encryption did not match the published Key/Plaintext vector.'
);
$stableRc4 = new RC4();
$stableRc4->setPreferredEngine('PHP');
$stableRc4->setKey('Key');
$stableRc4Ciphertext = $stableRc4->encrypt('Plaintext');
foreach (['24', 9, 2056] as $keyBits) {
    try {
        $stableRc4->setKeyLength($keyBits);
        throw new RuntimeException('RC4 accepted an invalid configured key length.');
    } catch (UnexpectedValueException | LengthException $expected) {
    }
}
require_true(
    $stableRc4->encrypt('Plaintext') === $stableRc4Ciphertext,
    'A rejected RC4 key length corrupted the active key state.'
);
try {
    $stableRc4->setKey([]);
    throw new RuntimeException('RC4 accepted a non-string key.');
} catch (UnexpectedValueException $expected) {
}

fwrite(STDOUT, "phpseclib key-format boundary regressions passed\n");
