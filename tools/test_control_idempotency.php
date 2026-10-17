<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/ControlIdempotency.php';

use SLS\MassNotify\ControlIdempotency;

$base = sys_get_temp_dir() . '/sls-idempotency-' . bin2hex(random_bytes(8));
if (!mkdir($base, 0700)) {
    throw new RuntimeException('Unable to create the idempotency fixture directory.');
}
$check = static function (bool $ok, string $why): void {
    if (!$ok) {
        throw new RuntimeException($why);
    }
};
$reject = static function (string $class, callable $work): Throwable {
    try {
        $work();
    } catch (Throwable $error) {
        if (!$error instanceof $class || $error->getMessage() === '') {
            throw new RuntimeException('Unexpected idempotency rejection.', 0, $error);
        }
        return $error;
    }
    throw new RuntimeException('Expected idempotency rejection.');
};
try {
    $ledger = new ControlIdempotency($base . '/ledger');
    $principal = [
        'id' => 'fixture',
        'audience' => ['unrestricted' => true],
        'scopes' => ['send'],
    ];
    $body = ['message' => 'Fixture', 'targets' => ['1000']];
    $calls = 0;
    $send = static function () use (&$calls): array {
        ++$calls;
        return [
            'success' => true,
            'job_id' => 'job_' . str_repeat('a', 32),
            'recipients' => ['1000'],
        ];
    };

    $first = $ledger->submit(str_repeat('a', 32), $principal, $body, $send, 1000);
    $retry = $ledger->submit(
        str_repeat('a', 32),
        $principal,
        array_reverse($body, true),
        $send,
        1001
    );
    $check($retry === $first && $calls === 1, 'Identical retry was submitted twice.');
    $reject(DomainException::class, fn() => $ledger->submit(
        str_repeat('a', 32), $principal, ['message' => 'Changed'], $send, 1001));
    $reject(DomainException::class, fn() => $ledger->submit(
        str_repeat('a', 32),
        array_replace($principal, ['audience' => ['unrestricted' => false]]),
        $body,
        $send,
        1001
    ));
    $reject(InvalidArgumentException::class, fn() => $ledger->submit(
        'short', $principal, $body, $send, 1001));
    $reject(RuntimeException::class, fn() => $ledger->submit(
        str_repeat('b', 32),
        $principal,
        $body,
        static function (): void {
            throw new RuntimeException('Interrupted after submission.');
        },
        1001
    ));
    $uncertain = $ledger->submit(str_repeat('b', 32), $principal, $body, $send, 1002);
    $check(
        $uncertain['error_code'] === 'announcement_submission_uncertain' && $calls === 1,
        'Uncertain claim was replayed.'
    );
    $ledger->submit(
        str_repeat('a', 32),
        array_replace($principal, ['id' => 'another']),
        $body,
        $send,
        1002
    );
    $check($calls === 2, 'Credential identities were conflated.');
    $ledger->submit(
        str_repeat('a', 32),
        $principal,
        $body,
        $send,
        1000 + ControlIdempotency::RETENTION_SECONDS
    );
    $check($calls === 3, 'Published retention boundary was not enforced.');

    foreach ([
        ['id' => []],
        ['id' => "fixture\nother"],
        ['id' => str_repeat('x', 129)],
    ] as $badPrincipal) {
        $reject(DomainException::class, fn() => $ledger->submit(
            str_repeat('p', 32), $badPrincipal, $body, $send, 1003));
    }
    foreach ([0, 253402300799 - ControlIdempotency::RETENTION_SECONDS + 1] as $badTime) {
        $reject(InvalidArgumentException::class, fn() => $ledger->submit(
            str_repeat('t', 32), $principal, $body, $send, $badTime));
    }
    $nested = 'leaf';
    for ($depth = 0; $depth < 40; ++$depth) {
        $nested = ['child' => $nested];
    }
    foreach ([
        ['message' => "\xff"],
        ['message' => new stdClass()],
        ['message' => $nested],
        ['messages' => array_fill(0, 5, str_repeat('x', 65536))],
    ] as $badBody) {
        $reject(InvalidArgumentException::class, fn() => $ledger->submit(
            str_repeat('i', 32), $principal, $badBody, $send, 1003));
    }
    $check($calls === 3, 'Rejected idempotency input reached the send callback.');

    $history = $base . '/ledger/announcement_claims.json';
    $saved = file_get_contents($history);
    if (!is_string($saved)) {
        throw new RuntimeException('Idempotency history fixture could not be read.');
    }
    $badId = str_repeat('d', 64);
    $fingerprint = str_repeat('e', 64);
    $badRows = [
        [$badId => [
            'state' => 'complete',
            'fingerprint' => $fingerprint,
            'created_at' => 1000,
            'expires_at' => 1000 + ControlIdempotency::RETENTION_SECONDS,
        ]],
        [$badId => [
            'state' => 'complete',
            'fingerprint' => $fingerprint,
            'created_at' => 1000,
            'expires_at' => 1000 + ControlIdempotency::RETENTION_SECONDS,
            'result' => 'scalar',
        ]],
        [$badId => [
            'state' => 'complete',
            'fingerprint' => $fingerprint,
            'created_at' => 1000,
            'expires_at' => 1000 + ControlIdempotency::RETENTION_SECONDS,
            'result' => ['message' => str_repeat('x', 16385)],
        ]],
        [$badId => [
            'state' => 'claimed',
            'fingerprint' => $fingerprint,
            'created_at' => 1000,
            'expires_at' => 1000 + ControlIdempotency::RETENTION_SECONDS,
            'unexpected' => true,
        ]],
    ];
    foreach ($badRows as $rows) {
        $encoded = json_encode((object)$rows, JSON_THROW_ON_ERROR);
        if (file_put_contents($history, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Unable to write malformed idempotency fixture.');
        }
        $reject(RuntimeException::class, fn() => $ledger->submit(
            str_repeat('c', 32), $principal, $body, $send, 1003));
    }
    if (file_put_contents($history, $saved) !== strlen($saved)) {
        throw new RuntimeException('Unable to restore idempotency fixture.');
    }
    $raceKey = str_repeat('r', 32);
    $raceId = hash('sha256', $principal['id'] . "\0" . $raceKey);
    $corruptClaim = static function () use ($history, $raceId): array {
        $raw = file_get_contents($history);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read the claimed idempotency fixture.');
        }
        $rows = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $rows[$raceId] = 'corrupt';
        $encoded = json_encode((object)$rows, JSON_THROW_ON_ERROR);
        if (file_put_contents($history, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Unable to corrupt the claimed idempotency fixture.');
        }
        return ['success' => true];
    };
    $reject(RuntimeException::class, fn() => $ledger->submit(
        $raceKey, $principal, $body, $corruptClaim, 1004));
    if (file_put_contents($history, $saved) !== strlen($saved)) {
        throw new RuntimeException('Unable to restore idempotency fixture after the race test.');
    }
    if (!unlink($history)) {
        throw new RuntimeException('Unable to remove idempotency history fixture.');
    }
    $reject(RuntimeException::class, fn() => $ledger->submit(
        str_repeat('c', 32), $principal, $body, $send, 1003));
    echo "Control announcement idempotency: stable retries, conflicts, credential isolation, uncertainty, retention and missing-ledger fencing passed.\n";
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $path = $entry->getPathname();
        $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
    }
    rmdir($base);
}
