<?php
declare(strict_types=1);
namespace SLS\MassNotify;

final class BetaNotice
{
    public const TITLE = 'DANGER! NON-PRODUCTION READY SOFTWARE.';
    public const BODY = 'This software is in beta testing and is not intended for production environments. All source code is free and open source, licensed under AGPLv3 or later. Expect bugs and report them, along with suggestions, in the Discord server. Do not rely on SLS Mass Notifications to relay critical or urgent information. Use it only in noncritical, nonurgent testing environments.';
    public const DELAY_SECONDS = 5;
    public const MAX_RECEIPTS = 1024;

    private const LEGACY_REVISIONS = [
        'ff40a241032168da33d496200653eced9f5d91b1481ccc0b0127d1854bf0c4da',
        'eaa61c4dc612b50633cc9c64972a47edb8db2a5f00a1f2653b670e7f6336ba9d',
    ];

    // Release numbers and wording edits do not reset per-administrator acceptance.
    public static function revision(): string { return hash('sha256', 'sls-mass-notify-beta-warning-v1'); }
    public static function defaults(): array { return ['schema' => 1, 'receipt' => null, 'receipts' => []]; }
    private static function validActor($actor): bool
    {
        return is_string($actor) && $actor !== '' && strlen($actor) <= 200 && $actor === trim($actor)
            && preg_match('//u', $actor) === 1 && !preg_match('/[\x00-\x1f\x7f]/', $actor);
    }
    private static function actorKey(string $actor): string { return 'admin_' . hash('sha256', $actor); }
    private static function checkedReceipt($receipt): array
    {
        if (!is_array($receipt) || array_is_list($receipt) || array_diff(array_keys($receipt), ['revision', 'accepted_at', 'actor'])
            || !is_string($receipt['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['revision'])
            || !is_int($receipt['accepted_at'] ?? null) || $receipt['accepted_at'] < 1 || $receipt['accepted_at'] > 253402300799
            || !self::validActor($receipt['actor'] ?? null)) {
            throw new \InvalidArgumentException('The beta warning acknowledgment is invalid.');
        }
        return $receipt;
    }
    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), ['schema', 'receipt', 'receipts']) || ($value['schema'] ?? 1) !== 1) {
            throw new \InvalidArgumentException('The beta warning acknowledgment is invalid.');
        }
        $receipts = $value['receipts'] ?? [];
        if (!is_array($receipts) || ($receipts && array_is_list($receipts))) {
            throw new \InvalidArgumentException('The beta warning acknowledgment is invalid.');
        }
        if (count($receipts) > self::MAX_RECEIPTS) { throw new \DomainException('The beta warning supports up to 1024 administrator acknowledgments. Review retired accounts before adding another.'); }
        foreach ($receipts as $key => $receipt) {
            $receipt = self::checkedReceipt($receipt);
            if (!is_string($key) || !preg_match('/^admin_[a-f0-9]{64}$/D', $key) || !hash_equals(self::actorKey($receipt['actor']), $key)) {
                throw new \InvalidArgumentException('The beta warning administrator identity is invalid.');
            }
        }
        if (($value['receipt'] ?? null) !== null) {
            $legacy = self::checkedReceipt($value['receipt']); $key = self::actorKey($legacy['actor']);
            if (isset($receipts[$key]) && $receipts[$key] !== $legacy) {
                throw new \InvalidArgumentException('The beta warning contains conflicting administrator acknowledgments.');
            }
            $receipts[$key] = $legacy;
        }
        if (count($receipts) > self::MAX_RECEIPTS) { throw new \DomainException('The beta warning supports up to 1024 administrator acknowledgments. Review retired accounts before adding another.'); }
        ksort($receipts, SORT_STRING);
        return ['schema' => 1, 'receipt' => null, 'receipts' => $receipts];
    }
    public static function merge($current, $accepted): array
    {
        $current = self::normalize($current); $accepted = self::normalize($accepted);
        return self::normalize(['schema' => 1, 'receipts' => array_replace($current['receipts'], $accepted['receipts'])]);
    }
    public static function acknowledged(array $settings, string $actor): bool
    {
        if (!self::validActor($actor)) { throw new \DomainException('An authenticated FreePBX administrator identity is required.'); }
        $receipt = self::normalize($settings['beta_notice'] ?? [])['receipts'][self::actorKey($actor)] ?? null;
        return $receipt !== null && $receipt['accepted_at'] <= time()
            && (hash_equals(self::revision(), $receipt['revision']) || in_array($receipt['revision'], self::LEGACY_REVISIONS, true));
    }
    public static function begin(array &$session, string $actor, string $sessionId, ?int $now = null, ?int $monotonic = null): array
    {
        if ($sessionId === '' || !self::validActor($actor)) { throw new \DomainException('An authenticated FreePBX administrator session is required.'); }
        $now ??= time(); $monotonic ??= hrtime(true);
        if ($now < 1 || $monotonic < 0) { throw new \DomainException('The warning clock is unavailable. Reload the page before agreeing.'); }
        $nonce = bin2hex(random_bytes(32));
        $session['_sls_beta_notice'] = ['nonce' => $nonce, 'actor' => $actor, 'session' => hash('sha256', $sessionId),
            'issued_at' => $now, 'monotonic' => $monotonic, 'revision' => self::revision()];
        return ['challenge' => $nonce, 'delay_seconds' => self::DELAY_SECONDS];
    }
    public static function accept(array &$session, array $input, string $actor, string $sessionId, ?int $now = null, ?int $monotonic = null): array
    {
        if (array_diff(array_keys($input), ['challenge', 'agree']) || ($input['agree'] ?? null) !== true
            || !is_string($input['challenge'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['challenge'])) {
            throw new \InvalidArgumentException('Agree to the beta warning before continuing.');
        }
        $now ??= time(); $monotonic ??= hrtime(true);
        $challenge = $session['_sls_beta_notice'] ?? null;
        if (!is_array($challenge) || array_is_list($challenge) || count($challenge) !== 6
            || array_diff(array_keys($challenge), ['nonce', 'actor', 'session', 'issued_at', 'monotonic', 'revision'])
            || !is_string($challenge['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $challenge['nonce'])
            || !self::validActor($challenge['actor'] ?? null)
            || !is_string($challenge['session'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $challenge['session'])
            || !is_int($challenge['issued_at'] ?? null) || $challenge['issued_at'] < 1
            || !is_int($challenge['monotonic'] ?? null) || $challenge['monotonic'] < 0
            || !is_string($challenge['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $challenge['revision'])
            || $now < 1 || $monotonic < 0 || $sessionId === '' || !self::validActor($actor) || !hash_equals($challenge['nonce'], $input['challenge'])
            || !hash_equals($challenge['actor'], $actor) || !hash_equals($challenge['session'], hash('sha256', $sessionId))
            || !hash_equals($challenge['revision'], self::revision()) || $now < $challenge['issued_at']
            || $now - $challenge['issued_at'] > 600 || $monotonic < $challenge['monotonic']) {
            throw new \DomainException('This warning session expired. Reload the page before agreeing.');
        }
        if ($monotonic - $challenge['monotonic'] < self::DELAY_SECONDS * 1000000000) {
            throw new \DomainException('Wait five full seconds before agreeing to this warning.');
        }
        unset($session['_sls_beta_notice']);
        return ['schema' => 1, 'receipt' => ['revision' => self::revision(), 'accepted_at' => $now, 'actor' => $actor]];
    }
}
