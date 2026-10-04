<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Administrator acknowledgment, separate from feature activation. */
final class LabsSafety
{
    public const TITLE = 'DANGER! DO NOT USE ON PRODUCTION READY SERVERS!';
    public const BODY = 'THIS FEATURE IS DANGEROUSLY EXPEREMENTAL AND HAS NOT BEEN THOROUGHLY TESTED OR EXAMINED AND IS STRONGLY DISCOURAGED TO BE USED IN SERIOUS ENVIROMENTS. ONLY USE THIS FOR NON-PRODUCTION READY HOME LAB / NON-MISSION CRITICAL SETUPS.';
    public const AGREEMENT = 'I UNDERSTAND THE DANGEROUS RISKS AND AM WILLING TO ACCEPT THEM AND PROCEED ANYWAY.';
    public const DELAY_SECONDS = 5;
    public const PAGE_FEATURE = 'enterprise_labs';
    public const FEATURES = [self::PAGE_FEATURE, 'enterprise_cluster', 'enterprise_identity', 'access_control_actuation', 'public_warning_origination'];

    public static function revision(): string { return hash('sha256', self::TITLE . "\n" . self::BODY . "\n" . self::AGREEMENT); }
    public static function defaults(): array { return ['schema' => 1, 'receipts' => []]; }
    private static function feature(string $feature): void
    {
        if (!in_array($feature, self::FEATURES, true)) { throw new \InvalidArgumentException('This feature has no dangerous-activation acknowledgment.'); }
    }
    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), ['schema', 'receipts'])
            || ($value['schema'] ?? 1) !== 1 || !is_array($value['receipts'] ?? [])) {
            throw new \InvalidArgumentException('Labs safety settings are invalid.');
        }
        $receipts = $value['receipts'] ?? [];
        if (count($receipts) > count(self::FEATURES)) { throw new \InvalidArgumentException('Labs safety acknowledgments exceed their limit.'); }
        foreach ($receipts as $feature => $receipt) {
            self::feature((string)$feature);
            if (!is_array($receipt) || array_diff(array_keys($receipt), ['revision', 'accepted_at', 'actor'])
                || !is_string($receipt['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['revision'])
                || !is_int($receipt['accepted_at'] ?? null) || $receipt['accepted_at'] < 1
                || !is_string($receipt['actor'] ?? null) || $receipt['actor'] === '' || strlen($receipt['actor']) > 200
                || preg_match('/[\x00-\x1f\x7f]/', $receipt['actor'])) {
                throw new \InvalidArgumentException('A Labs safety acknowledgment is invalid.');
            }
        }
        return ['schema' => 1, 'receipts' => $receipts];
    }
    public static function hasReceipt(array $settings, string $feature): bool
    {
        self::feature($feature);
        $receipts = self::normalize($settings['labs_safety'] ?? [])['receipts'];
        foreach (array_unique([$feature, self::PAGE_FEATURE]) as $key) {
            $receipt = $receipts[$key] ?? null;
            if (is_array($receipt) && hash_equals(self::revision(), $receipt['revision']) && $receipt['accepted_at'] <= time()) { return true; }
        }
        return false;
    }
    public static function requireReceipt(array $settings, string $feature): void
    {
        if (!self::hasReceipt($settings, $feature)) {
            throw new \DomainException('Open Enterprise Labs and acknowledge the dangerous-feature notice before enabling this feature.');
        }
    }
    public static function begin(array &$session, string $feature, string $actor, string $sessionId, ?int $now = null, ?int $monotonic = null): array
    {
        self::feature($feature);
        if ($sessionId === '' || $actor === '') { throw new \DomainException('An authenticated administrator session is required.'); }
        $now ??= time(); $monotonic ??= hrtime(true);
        $nonce = bin2hex(random_bytes(32));
        $session['_sls_labs_challenges'][$feature] = ['nonce' => $nonce, 'actor' => $actor,
            'session' => hash('sha256', $sessionId), 'issued_at' => $now, 'monotonic' => $monotonic, 'revision' => self::revision()];
        return ['feature' => $feature, 'challenge' => $nonce, 'title' => self::TITLE, 'body' => self::BODY,
            'agreement' => self::AGREEMENT, 'delay_seconds' => self::DELAY_SECONDS, 'revision' => self::revision()];
    }
    public static function accept(array &$session, array $input, string $actor, string $sessionId, ?int $now = null, ?int $monotonic = null): array
    {
        if (array_diff(array_keys($input), ['feature', 'challenge', 'agree']) || !is_string($input['feature'] ?? null)
            || !is_string($input['challenge'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['challenge'])
            || ($input['agree'] ?? null) !== true) { throw new \InvalidArgumentException('Agree to the displayed warning before continuing.'); }
        $feature = $input['feature']; self::feature($feature); $now ??= time(); $monotonic ??= hrtime(true);
        $challenge = $session['_sls_labs_challenges'][$feature] ?? null;
        if (!is_array($challenge) || $sessionId === '' || !hash_equals($challenge['nonce'], $input['challenge'])
            || !hash_equals($challenge['actor'], $actor) || !hash_equals($challenge['session'], hash('sha256', $sessionId))
            || !hash_equals($challenge['revision'], self::revision()) || $now < $challenge['issued_at']
            || $now - $challenge['issued_at'] > 600 || $monotonic < $challenge['monotonic']) {
            throw new \DomainException('This warning challenge expired or belongs to another session. Open the warning again.');
        }
        if ($monotonic - $challenge['monotonic'] < self::DELAY_SECONDS * 1000000000) {
            throw new \DomainException('Wait five full seconds before accepting this warning.');
        }
        unset($session['_sls_labs_challenges'][$feature]);
        return ['feature' => $feature, 'receipt' => ['revision' => self::revision(), 'accepted_at' => $now, 'actor' => $actor]];
    }
}
