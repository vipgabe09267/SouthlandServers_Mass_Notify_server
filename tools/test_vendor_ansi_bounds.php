<?php

declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

use phpseclib3\File\ANSI;

function require_ansi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$ansi = new ANSI();
foreach ([[0, 24], [80, 0], [4097, 1], [2048, 1024]] as [$width, $height]) {
    try {
        $ansi->setDimensions($width, $height);
        throw new RuntimeException('Unsafe terminal dimensions were accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}

try {
    $ansi->setHistory(10001);
    throw new RuntimeException('An unsafe terminal history limit was accepted.');
} catch (InvalidArgumentException $expected) {
}

$ansi->setDimensions(4096, 1);
try {
    $ansi->setHistory(257);
    throw new RuntimeException('A terminal history allocation exceeding the cell bound was accepted.');
} catch (InvalidArgumentException $expected) {
}
$ansi->setDimensions(80, 24);
try {
    $ansi->appendString(str_repeat("\x1B[H", 32769));
    throw new RuntimeException('An excessive number of terminal escape sequences was accepted.');
} catch (LengthException $expected) {
}

$ansi->setDimensions(4, 2);
$ansi->appendString("ABCDZ");
$screen = $ansi->getScreen();
require_ansi(str_contains($screen, "ABCD\r\nZ"), 'Terminal wrapping discarded or misplaced a character.');

$ansi->appendString("\x1B[999999;999999H!");
require_ansi(str_contains($ansi->getScreen(), '!'), 'Bounded cursor movement did not preserve terminal output.');

$ansi->setHistory(2);
$ansi->appendString("\n1\n2\n3");
$history = $ansi->getHistory();
require_ansi(substr_count($history, '<pre') === 1, 'Terminal history contains nested preformatted elements.');
require_ansi(!str_contains($history, '</span></pre>'), 'Terminal history contains an unmatched span close.');

$ansi->appendString("\x1B" . str_repeat('1', 2048) . 'X');

try {
    $ansi->appendString(str_repeat('x', 16777217));
    throw new RuntimeException('Oversized terminal input was accepted.');
} catch (LengthException $expected) {
}

echo "phpseclib ANSI parser boundary checks passed.\n";
