<?php

declare(strict_types=1);

namespace phpseclib3\Crypt {
    function random_bytes(int $length): string
    {
        throw new \Exception('fixture entropy unavailable', 19);
    }
}

namespace {
    require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

    try {
        \phpseclib3\Crypt\Random::string(16);
        throw new \RuntimeException('phpseclib accepted an unavailable secure entropy source.');
    } catch (\RuntimeException $error) {
        if ($error->getMessage() !== 'A secure random source is unavailable'
            || !($error->getPrevious() instanceof \Exception)
            || $error->getPrevious()->getMessage() !== 'fixture entropy unavailable'
            || $error->getPrevious()->getCode() !== 19) {
            throw new \RuntimeException('phpseclib lost its causal entropy failure.', 0, $error);
        }
    }

    echo "phpseclib random source fails closed with its causal exception.\n";
}
