<?php
declare(strict_types=1);

namespace Symfony\Polyfill\Php82\Random\Engine {
    function random_bytes(int $length): string
    {
        throw new \Exception('fixture entropy unavailable', 17);
    }
}

namespace {
    require dirname(__DIR__) . '/slsmassnotifyserver/identity-libs/vendor/autoload.php';

    try {
        (new \Symfony\Polyfill\Php82\Random\Engine\Secure())->generate();
        throw new \RuntimeException('Secure random polyfill accepted an unavailable entropy source.');
    } catch (\Random\RandomException $error) {
        if ($error->getMessage() !== 'fixture entropy unavailable'
            || $error->getCode() !== 17
            || !($error->getPrevious() instanceof \Exception)
            || $error->getPrevious()->getMessage() !== 'fixture entropy unavailable') {
            throw new \RuntimeException('Secure random polyfill lost its causal entropy failure.');
        }
    }

    echo "Vendored random engine: entropy failures retain their causal exception.\n";
}
