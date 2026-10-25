<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Synthetic planning outcomes. This class has no storage or transport adapters. */
final class DeliveryRehearsal
{
    private const TARGETS = [
        'audio'=>'phones', 'sip_notify'=>'phones', 'desktop'=>'desktops',
        'external_voice'=>'voice_recipient_ids', 'email'=>'email_recipient_ids',
        'sms'=>'sms_recipient_ids', 'webhook'=>'webhooks',
        'browser_push'=>'browser_recipient_ids', 'connector'=>'connector_ids',
    ];
    private const OUTCOMES = ['accepted', 'refused', 'uncertain', 'unavailable', 'expired'];

    public static function scenario($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['default', 'channels'])
            || !in_array($value['default'] ?? 'accepted', self::OUTCOMES, true)
            || !is_array($value['channels'] ?? [])
            || array_diff(array_keys($value['channels'] ?? []), array_keys(self::TARGETS))) {
            throw new \InvalidArgumentException('Rehearsal accepts a default outcome and optional per-channel outcomes.');
        }
        foreach ($value['channels'] ?? [] as $outcome) {
            if (!in_array($outcome, self::OUTCOMES, true)) {
                throw new \InvalidArgumentException('Choose accepted, refused, uncertain, unavailable or expired for rehearsal.');
            }
        }
        return ['default'=>$value['default'] ?? 'accepted', 'channels'=>$value['channels'] ?? []];
    }

    public static function simulate(array $preview, array $scenario = []): array
    {
        if (($preview['success'] ?? false) !== true || ($preview['preview'] ?? false) !== true
            || ($preview['delivery_started'] ?? null) !== false
            || ($preview['transports_attempted'] ?? null) !== []) {
            throw new \DomainException('Rehearsal requires a successful, authorized no-delivery preview.');
        }
        $scenario = self::scenario($scenario);
        $rows = []; $counts = [];
        foreach (self::TARGETS as $channel=>$field) {
            if ($channel === 'audio' && ($preview['audio_mode'] ?? 'none') === 'none') { continue; }
            $targets = $preview[$field] ?? [];
            if (!is_array($targets) || !array_is_list($targets) || count($targets) > 1000) {
                throw new \DomainException('The resolved rehearsal audience is invalid.');
            }
            foreach ($targets as $target) {
                if (!is_string($target) || $target === '' || strlen($target) > 128
                    || preg_match('/[\x00-\x20\x7f]/', $target) || count($rows) >= 5000) {
                    throw new \DomainException('The resolved rehearsal audience exceeds its allocation.');
                }
                if ($channel === 'sip_notify' && in_array($target, $preview['radio_extensions'] ?? [], true)) { continue; }
                $outcome = $scenario['channels'][$channel] ?? $scenario['default'];
                if (in_array($channel, ['audio', 'sip_notify'], true)
                    && in_array($target, $preview['unavailable_phones'] ?? [], true)) { $outcome = 'unavailable'; }
                $detail = match ($outcome) {
                    'accepted' => $channel === 'audio' ? 'Would request Asterisk admission and queue audio.'
                        : ($channel === 'desktop' ? 'Would publish a recipient-bound event; app receipt would still be pending.'
                        : 'Would submit once; provider acceptance would not confirm endpoint delivery.'),
                    'refused' => 'Simulated refusal before submission; nothing would be sent.',
                    'uncertain' => 'Simulated timeout after possible submission; inspect evidence before any manual retry.',
                    'unavailable' => 'Simulated unavailable recipient; no confirmed endpoint delivery.',
                    'expired' => 'Simulated absolute expiry before submission; no delivery would start.',
                };
                $rows[] = ['simulated'=>true, 'channel'=>$channel, 'target'=>$target,
                    'outcome'=>$outcome, 'detail'=>$detail];
                $counts[$channel] = ($counts[$channel] ?? 0) + 1;
            }
        }
        return ['schema'=>'sls-rehearsal-v1', 'simulated'=>true, 'delivery_started'=>false,
            'transports_attempted'=>[], 'scenario'=>$scenario, 'counts'=>$counts,
            'simulated_receipts'=>$rows,
            'message'=>'Rehearsal only. No audio, publication, provider request or delivery job was created. Synthetic outcomes do not qualify real transports.'];
    }
}
