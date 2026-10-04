<?php
declare(strict_types=1);
namespace SLS\MassNotify {
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Revalidate already selected recipients immediately before delayed delivery. */
final class ApiPermissionGuards
{
    public static function audio(array $settings, array $context, array $phones, array $outbound): array
    {
        $phones = array_values(array_unique(array_map('strval', $phones)));
        $id = $context['api_credential_id'] ?? '';
        if ($id === '') { return ['extensions' => $phones, 'outbound_targets' => $outbound, 'removed' => ['phones' => [], 'voice_recipient_ids' => []]]; }
        $principal = is_string($id) ? ApiSecurity::currentCredential($settings, $id) : null;
        $allowed = ['phones' => [], 'voice_recipient_ids' => []];
        if (is_array($principal) && ApiSecurity::permits($principal, 'send')) {
            $allowed = ($principal['audience']['unrestricted'] ?? false) === true
                ? ['phones' => $phones, 'voice_recipient_ids' => array_column($outbound, 'id')]
                : ApiSecurity::allowedAudience($principal, $settings);
        }
        $kept = array_values(array_intersect($phones, $allowed['phones']));
        $voice = array_values(array_filter($outbound, static function ($row) use ($allowed) { return is_array($row) && in_array($row['id'] ?? '', $allowed['voice_recipient_ids'], true); }));
        return ['extensions' => $kept, 'outbound_targets' => $voice,
            'removed' => ['phones' => array_values(array_diff($phones, $kept)),
                'voice_recipient_ids' => array_values(array_diff(array_column($outbound, 'id'), array_column($voice, 'id')))]];
    }

    public static function weatherContext(array $settings, ?array $principal, array $zoneIds, array $phones, array $desktops): ?array
    {
        if ($principal === null || ($principal['id'] ?? '') === 'legacy') { return null; }
        $current = ApiSecurity::currentCredential($settings, (string)($principal['id'] ?? ''));
        $zoneIds = array_values(array_unique(array_map('strval', $zoneIds)));
        if (!$current || !ApiSecurity::permitsTest($current, ['zone_scope' => 'selected', 'zone_ids' => $zoneIds], $settings)) {
            throw new \DomainException('The API credential was revoked or no longer permits the selected Weather zones. No test was started.');
        }
        $identities = [];
        foreach ($settings['desktop_clients'] ?? [] as $row) {
            if (is_array($row) && !empty($row['enabled']) && in_array($row['username'] ?? '', $desktops, true)) {
                $identities[(string)$row['username']] = (string)($row['client_id'] ?? '');
            }
        }
        if (array_diff($desktops, array_keys($identities))) { throw new \DomainException('A selected Weather test desktop is no longer enabled. No test was started.'); }
        return ['schema' => 1, 'credential_id' => $current['id'], 'zone_ids' => $zoneIds,
            'phones' => array_values(array_unique(array_map('strval', $phones))), 'desktops' => $identities];
    }

    public static function weather(array $settings, array $context): array
    {
        if (($context['schema'] ?? null) !== 1 || !is_string($context['credential_id'] ?? null)
            || !preg_match('/^api_[a-f0-9]{24}$/D', $context['credential_id'])
            || !is_array($context['zone_ids'] ?? null) || !array_is_list($context['zone_ids'])
            || !is_array($context['phones'] ?? null) || !array_is_list($context['phones'])
            || !is_array($context['desktops'] ?? null) || count($context['phones']) > 1000 || count($context['desktops']) > 1000 || count($context['zone_ids']) > 1000) {
            throw new \DomainException('Weather test API authorization context is invalid.');
        }
        foreach ($context['phones'] as $phone) { if (!is_string($phone) || !preg_match('/^[0-9]{1,20}$/D', $phone)) { throw new \DomainException('Invalid Weather test phone identity.'); } }
        foreach ($context['zone_ids'] as $zone) { if (!is_string($zone) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $zone)) { throw new \DomainException('Invalid Weather test zone identity.'); } }
        foreach ($context['desktops'] as $username => $id) {
            if ((!is_string($username) && !is_int($username)) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', (string)$username) || !is_string($id) || strlen($id) > 80) { throw new \DomainException('Invalid Weather test desktop identity.'); }
        }
        $principal = ApiSecurity::currentCredential($settings, $context['credential_id']);
        $phones = []; $desktops = [];
        if ($principal && ApiSecurity::permits($principal, 'test') && !empty($settings['enabled'])) {
            $zones = ($principal['audience']['unrestricted'] ?? false) === true ? $context['zone_ids']
                : array_values(array_intersect($context['zone_ids'], $principal['audience']['nws_zone_ids']));
            foreach ($settings['nws_zones'] ?? [] as $zone) {
                if (!is_array($zone) || !in_array($zone['id'] ?? '', $zones, true)) { continue; }
                $phones = array_merge($phones, array_map('strval', (array)($zone['extensions'] ?? [])));
                $desktops = array_merge($desktops, array_map('strval', (array)($zone['desktop_clients'] ?? [])));
            }
        }
        $phones = array_values(array_intersect($context['phones'], array_unique($phones)));
        $currentDesktopIds = [];
        foreach ($settings['desktop_clients'] ?? [] as $row) {
            if (is_array($row) && !empty($row['enabled'])) { $currentDesktopIds[(string)($row['username'] ?? '')] = (string)($row['client_id'] ?? ''); }
        }
        $desktops = array_values(array_map('strval', array_filter(array_intersect(array_keys($context['desktops']), array_unique($desktops)), static function ($username) use ($context, $currentDesktopIds) {
            return isset($currentDesktopIds[$username]) && hash_equals($context['desktops'][$username], $currentDesktopIds[$username]);
        })));
        return ['phones' => $phones, 'desktops' => $desktops,
            'removed_count' => count($context['phones']) + count($context['desktops']) - count($phones) - count($desktops)];
    }
}
}

namespace FreePBX\modules {
trait SlsApiPermissionGuards
{
    private function guardPreparedApiAudio(array $context, array $extensions, array $outboundTargets): array
    {
        $filtered = \SLS\MassNotify\ApiPermissionGuards::audio($this->getActiveSettings(), $context, $extensions, $outboundTargets);
        if (!empty($context['automation_context']) && !$this->automationContextPermitted($context['automation_context'])) {
            $filtered=['extensions'=>[], 'outbound_targets'=>[], 'removed'=>['phones'=>$extensions,'voice_recipient_ids'=>array_column($outboundTargets,'id')]];
        }
        $filtered['success'] = !empty($filtered['extensions']) || !empty($filtered['outbound_targets']);
        $filtered['permission_denied_targets'] = $filtered['removed'];
        if (!$filtered['success']) {
            $filtered += ['error' => 'api_permission_revoked', 'retryable' => false, 'delivery_started' => false,
                'message' => 'The API credential was revoked or no longer permits these audio recipients. No audio was queued.'];
        }
        return $filtered;
    }

    private function apiWeatherTestEnvironment(array $settings, array $zoneIds, array $phones, array $desktops): array
    {
        $context = \SLS\MassNotify\ApiPermissionGuards::weatherContext($settings,
            $GLOBALS['sls_control_principal'] ?? null, $zoneIds, $phones, $desktops);
        // Explicitly clear inherited context for ordinary GUI/legacy tests.
        $encoded = $context === null ? '' : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 120000) { throw new \DomainException('The selected Weather test audience exceeds the safe worker handoff size. Test fewer zones at a time.'); }
        return ['SLS_API_TEST_CONTEXT' => $encoded];
    }
}
}
