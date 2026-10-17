<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\File\ASN1;

function require_asn1(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_asn1(ASN1::decodeOID('') === false, 'An empty object identifier was accepted.');
require_asn1(ASN1::decodeBER("\x17\x00") === null, 'An empty ASN.1 time was accepted.');
require_asn1(ASN1::decodeBER("\x03\x00") === null, 'A BIT STRING without an unused-bit count was accepted.');
require_asn1(ASN1::decodeBER("\x03\x02\x08\x00") === null, 'A BIT STRING with eight unused bits was accepted.');
require_asn1(ASN1::decodeBER("\x03\x02\x03\xA1") === null, 'A BIT STRING with nonzero unused bits was accepted.');
require_asn1(ASN1::decodeBER("\x03\x02\x03\xA0") !== null, 'A valid primitive BIT STRING was rejected.');
require_asn1(ASN1::decodeBER("\x04\x80a\x00\x00") === null, 'An indefinite-length primitive value was accepted.');

$oversizedNamedBits = ASN1::decodeBER("\x03\x03\x00\x00\x00");
require_asn1(is_array($oversizedNamedBits), 'A valid zero-extended BIT STRING was rejected.');
require_asn1(
    ASN1::asn1map($oversizedNamedBits[0], ['type' => ASN1::TYPE_BIT_STRING, 'mapping' => ['first', 'second']]) === [],
    'Zero-valued bits beyond a named BIT STRING map were not handled safely.'
);
$unknownNamedBit = ASN1::decodeBER("\x03\x03\x00\x00\x01");
require_asn1(is_array($unknownNamedBit), 'The unknown named-bit regression fixture did not decode.');
require_asn1(
    ASN1::asn1map($unknownNamedBit[0], ['type' => ASN1::TYPE_BIT_STRING, 'mapping' => ['first', 'second']]) === false,
    'A set bit beyond a named BIT STRING map was accepted.'
);
require_asn1(
    ASN1::encodeDER('abc', [
        'type' => ASN1::TYPE_OCTET_STRING,
        'class' => ASN1::CLASS_CONTEXT_SPECIFIC,
        'cast' => 0,
    ]) === "\x80\x03abc",
    'Implicit primitive tagging did not preserve the expected constructed bit.'
);
$emptyExplicit = ASN1::decodeBER("\xA0\x00");
require_asn1(is_array($emptyExplicit), 'The empty explicit-tag regression fixture did not decode.');
require_asn1(
    ASN1::asn1map($emptyExplicit[0], ['type' => ASN1::TYPE_OCTET_STRING, 'explicit' => true]) === null,
    'An empty explicit tag was accepted.'
);
$reorderedSet = ASN1::decodeBER("\x31\x06\x04\x01x\x02\x01\x01");
require_asn1(is_array($reorderedSet), 'The reordered SET regression fixture did not decode.');
$setMap = [
    'type' => ASN1::TYPE_SET,
    'children' => [
        'number' => ['type' => ASN1::TYPE_INTEGER],
        'text' => ['type' => ASN1::TYPE_OCTET_STRING],
    ],
];
$mappedSet = ASN1::asn1map($reorderedSet[0], $setMap);
require_asn1(
    is_array($mappedSet) && isset($mappedSet['number']) && $mappedSet['number']->toString() === '1' && $mappedSet['text'] === 'x',
    'A valid SET whose fields use a different order was rejected.'
);
$setWithUnknown = ASN1::decodeBER("\x31\x09\x04\x01x\x02\x01\x01\x01\x01\xFF");
require_asn1(
    is_array($setWithUnknown) && ASN1::asn1map($setWithUnknown[0], $setMap) === null,
    'A SET containing an unmapped element was accepted.'
);
require_asn1(ASN1::decodeBER("\x04\x85\x00\x00\x00\x00\x00") === null, 'A five-octet length was accepted.');
require_asn1(ASN1::decodeBER("\x17\x0D240132000000Z") === null, 'An invalid calendar date was normalized and accepted.');
require_asn1(ASN1::decodeBER("\x17\x0D240101000000Z") !== null, 'A valid UTC time was rejected.');
foreach (['1.2.trailing-junk', '3.1.2', '1.40.1', '1'] as $invalidOid) {
    $rejected = false;
    try {
        ASN1::encodeOID($invalidOid);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    require_asn1($rejected, 'Invalid textual OID was accepted: ' . $invalidOid);
}
require_asn1(bin2hex(ASN1::encodeOID('2.999.3')) === '883703', 'A valid large second OID arc was rejected.');

$definiteOctets = ASN1::decodeBER("\x24\x06\x04\x01a\x04\x01b");
require_asn1(
    is_array($definiteOctets) && $definiteOctets[0]['content'] === 'ab',
    'A valid definite-length constructed OCTET STRING was rejected.'
);
$indefiniteOctets = ASN1::decodeBER("\x24\x80\x04\x01a\x00\x00");
require_asn1(
    is_array($indefiniteOctets) && $indefiniteOctets[0]['content'] === 'a',
    'A valid indefinite-length constructed OCTET STRING was rejected.'
);
$constructedBits = ASN1::decodeBER("\x23\x08\x03\x02\x00\xAA\x03\x02\x04\xB0");
require_asn1(
    is_array($constructedBits) && $constructedBits[0]['content'] === "\x04\xAA\xB0",
    'A valid constructed BIT STRING was rejected.'
);
require_asn1(
    ASN1::decodeBER("\x23\x08\x03\x02\x01\xAA\x03\x02\x04\xB0") === null,
    'A non-final BIT STRING segment with unused bits was accepted.'
);

$nested = "\x05\x00";
for ($depth = 0; $depth < 70; ++$depth) {
    $nested = "\x30\x80" . $nested . "\x00\x00";
}
require_asn1(ASN1::decodeBER($nested) === null, 'ASN.1 nesting exceeded the decoder depth limit.');

$manyChildren = str_repeat("\x05\x00", 4097);
$manyChildren = "\x30" . ASN1::encodeLength(strlen($manyChildren)) . $manyChildren;
require_asn1(
    ASN1::decodeBER($manyChildren) === null,
    'ASN.1 accepted more than the bounded number of children in one constructed value.'
);

require_asn1(
    ASN1::convert("\xC2\xA2", ASN1::TYPE_UTF8_STRING, ASN1::TYPE_BMP_STRING) === "\x00\xA2",
    'Two-byte UTF-8 conversion failed.'
);
require_asn1(
    ASN1::convert("\xE2\x82\xAC", ASN1::TYPE_UTF8_STRING, ASN1::TYPE_BMP_STRING) === "\x20\xAC",
    'Three-byte UTF-8 conversion failed.'
);
require_asn1(
    ASN1::convert("\xF0\x9F\x98\x80", ASN1::TYPE_UTF8_STRING, ASN1::TYPE_UNIVERSAL_STRING) === "\x00\x01\xF6\x00",
    'Four-byte UTF-8 conversion failed.'
);
foreach (["\xC0\x80", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xF8\x80\x80\x80\x80"] as $invalidUtf8) {
    require_asn1(
        ASN1::convert($invalidUtf8, ASN1::TYPE_UTF8_STRING, ASN1::TYPE_UNIVERSAL_STRING) === false,
        'A non-canonical or invalid Unicode scalar value was accepted.'
    );
}

restore_error_handler();
echo "phpseclib ASN.1 bounds and conversion checks passed.\n";
