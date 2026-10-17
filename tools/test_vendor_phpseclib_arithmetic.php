<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\Crypt\Random;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;
use phpseclib3\Math\BinaryField;
use phpseclib3\Math\BinaryField\Integer as BinaryFieldInteger;

function require_vendor_arithmetic(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function require_vendor_rejection(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $expected) {
        return;
    }
    throw new RuntimeException($message);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_vendor_arithmetic(Random::string(0) === '', 'A zero-length secure-random request did not return an empty string.');
foreach ([-1, 1.5, '2', [], null, 16777217] as $invalidLength) {
    require_vendor_rejection(
        static fn() => Random::string($invalidLength),
        'An invalid or excessive secure-random request length was accepted.'
    );
}

$zero = new BigInteger(0);
require_vendor_arithmetic(
    $zero->bitwise_leftRotate(1)->equals($zero) && $zero->bitwise_rightRotate(9)->equals($zero),
    'Rotating a zero BigInteger did not preserve zero.'
);
require_vendor_arithmetic(!$zero->isPrime() && !(new BigInteger(-17))->isPrime(), 'A value below two was treated as prime.');
require_vendor_arithmetic((new BigInteger(3))->isPrime(true), 'The documented boolean primality-round form was rejected.');
require_vendor_arithmetic(!$zero->isOdd(), 'Zero was treated as odd.');
require_vendor_rejection(static fn() => $zero->bitwise_leftShift(-1), 'A negative left-shift count was accepted.');
require_vendor_rejection(static fn() => $zero->bitwise_rightShift(-1), 'A negative right-shift count was accepted.');
require_vendor_rejection(
    static fn() => BigInteger::minMaxBits(0),
    'BigInteger accepted a zero-bit random range.'
);
require_vendor_rejection(
    static fn() => BigInteger::randomPrime(8197),
    'BigInteger allocated a prime candidate beyond the supported bound.'
);
$largeComposite = (new BigInteger(1009))->multiply(new BigInteger(1013));
require_vendor_rejection(
    static fn() => $largeComposite->isPrime(-1),
    'BigInteger accepted a negative Miller-Rabin round count.'
);
require_vendor_arithmetic(!$largeComposite->isPrime(), 'BigInteger treated a known composite as prime.');

BigInteger::setEngine('PHP64');
require_vendor_arithmetic(
    (new BigInteger(0))->bitwise_split(4) === [],
    'The PHP BigInteger engine did not split zero into an empty digit list.'
);

$degree = new ReflectionMethod(BinaryFieldInteger::class, 'deg');
require_vendor_arithmetic(
    $degree->invoke(null, '') === -1 && $degree->invoke(null, "\0\0") === -1,
    'The binary-field degree helper mishandled an empty polynomial.'
);
$binaryField = new BinaryField(4, 1, 0);
$binaryZero = $binaryField->newInteger('');
require_vendor_rejection(static fn() => $binaryZero->modInverse(), 'Binary-field zero returned a multiplicative inverse.');
require_vendor_rejection(
    static fn() => $binaryField->newInteger("\1")->divide($binaryZero),
    'Binary-field division by zero returned a field element.'
);

if (extension_loaded('bcmath')) {
    eval(
        'namespace phpseclib3\\Math\\BigInteger\\Engines\\BCMath; ' .
        'abstract class SlsEvalBarrettFixture extends ' .
        '\\phpseclib3\\Math\\BigInteger\\Engines\\BCMath\\Reductions\\EvalBarrett {}'
    );
    BigInteger::setEngine('BCMath', ['SlsEvalBarrettFixture']);
    $moduloSeven = (new BigInteger(7))->createRecurringModuloFunction();
    require_vendor_arithmetic(
        $moduloSeven(new BigInteger(10))->toString() === '3',
        'The BCMath recurring reducer failed for a small modulus.'
    );
}

eval(
    'namespace phpseclib3\\Math\\BigInteger\\Engines\\PHP; ' .
    'abstract class SlsMontgomeryFixture extends ' .
    '\\phpseclib3\\Math\\BigInteger\\Engines\\PHP\\Reductions\\Montgomery {}'
);
BigInteger::setEngine('PHP64', ['SlsMontgomeryFixture']);
foreach ([3, 5, 17, 31, 97] as $modulus) {
    foreach ([0, 1, 4, 17, 80, 123456] as $base) {
        foreach ([0, 1, 2, 3, 11, 23] as $exponent) {
            $expected = 1 % $modulus;
            for ($index = 0; $index < $exponent; ++$index) {
                $expected = ($expected * $base) % $modulus;
            }
            $actual = (new BigInteger($base))->modPow(new BigInteger($exponent), new BigInteger($modulus));
            require_vendor_arithmetic(
                $actual->toString() === (string) $expected,
                "Montgomery reduction failed for $base ** $exponent mod $modulus."
            );
        }
    }
}

BigInteger::setEngine('PHP64');
$privateKey = RSA::createKey(512);
$publicKey = $privateKey->getPublicKey();
$modulusProperty = new ReflectionProperty(RSA::class, 'modulus');
$modulus = $modulusProperty->getValue($privateKey);
$modulusBytes = $modulus->toBytes();
$octets = strlen($modulusBytes);

require_vendor_rejection(
    static fn() => $publicKey->withPadding(RSA::ENCRYPTION_NONE)->encrypt($modulusBytes),
    'RSA accepted a message representative equal to its modulus.'
);
require_vendor_rejection(
    static fn() => $privateKey->withPadding(RSA::ENCRYPTION_NONE)->decrypt($modulusBytes),
    'RSA accepted a ciphertext representative equal to its modulus.'
);

$malformedBlocks = [
    "\0\1" . str_repeat("\xFF", $octets - 6) . "\0bad",
    "\0\2" . str_repeat("\x7F", $octets - 2),
    "\0\2abc\0" . str_repeat('x', $octets - 6),
];
foreach ($malformedBlocks as $block) {
    require_vendor_arithmetic(strlen($block) === $octets, 'Malformed RSA test block has the wrong length.');
    $ciphertext = $publicKey->withPadding(RSA::ENCRYPTION_NONE)->encrypt($block);
    require_vendor_rejection(
        static fn() => $privateKey->withPadding(RSA::ENCRYPTION_PKCS1)->decrypt($ciphertext),
        'RSA PKCS#1 v1.5 decryption accepted a malformed encoded message.'
    );
}
$message = 'valid PKCS1 message';
$ciphertext = $publicKey->withPadding(RSA::ENCRYPTION_PKCS1)->encrypt($message);
require_vendor_arithmetic(
    $privateKey->withPadding(RSA::ENCRYPTION_PKCS1)->decrypt($ciphertext) === $message,
    'RSA PKCS#1 v1.5 valid-message round trip failed.'
);

if (class_exists('DOMDocument')) {
    $xml = $publicKey->toString('XML');
    $loaded = RSA::load($xml);
    require_vendor_arithmetic(
        $loaded->toString('PKCS8') === $publicKey->toString('PKCS8'),
        'A valid RSA XML key did not round trip.'
    );
    $modulusElement = preg_replace('/.*?(<Modulus>.*?<\/Modulus>).*/s', '$1', $xml);
    require_vendor_arithmetic(is_string($modulusElement), 'Unable to construct the duplicate XML-field fixture.');
    $duplicate = str_replace('</RSAKeyValue>', $modulusElement . '</RSAKeyValue>', $xml);
    require_vendor_rejection(
        static fn() => RSA::load($duplicate),
        'RSA XML accepted an ambiguous modulus.'
    );
    require_vendor_rejection(
        static fn() => RSA::load(
            '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' .
            '<RSAKeyValue><Modulus>&e;</Modulus><Exponent>AQAB</Exponent></RSAKeyValue>'
        ),
        'RSA XML accepted a DTD or external entity declaration.'
    );
    require_vendor_rejection(
        static fn() => RSA::load(str_repeat(' ', 1048577)),
        'RSA XML accepted an oversized document.'
    );
}

restore_error_handler();
echo "phpseclib arithmetic and parser regression checks passed.\n";
