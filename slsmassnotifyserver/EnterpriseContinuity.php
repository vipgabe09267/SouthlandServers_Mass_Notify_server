<?php
declare(strict_types=1);
namespace SLS\MassNotify;

require_once __DIR__ . '/OperatorAuth.php';
require_once __DIR__ . '/OperatorAccess.php';
require_once __DIR__ . '/DeviceAcceptance.php';
require_once __DIR__ . '/bin/sls_mass_notify/sls_runtime_state.php';

/** Read-only continuity review. It opens no network or device connection. */
final class EnterpriseContinuity
{
    private static function enabled($value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /** @return string[] */
    private static function enabledIds($rows, string $field = 'id'): array
    {
        if (!is_array($rows)) { return []; }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row[$field] ?? null) || $row[$field] === ''
                || (array_key_exists('enabled', $row) && !self::enabled($row['enabled']))) { continue; }
            $ids[] = $row[$field];
        }
        return array_values(array_unique($ids, SORT_STRING));
    }

    /** @return string[] */
    private static function phoneTargets(array $settings): array
    {
        $targets = [];
        $add = static function ($value) use (&$targets): void {
            if (is_int($value)) { $value = (string)$value; }
            if (is_string($value) && preg_match('/^[0-9]{1,20}$/D', $value)) { $targets[] = $value; }
        };
        $add($settings['page_group'] ?? '');
        foreach (is_array($settings['alert_recipients'] ?? null) ? $settings['alert_recipients'] : [] as $row) {
            if (is_array($row)) {
                foreach (['extension', 'number', 'id'] as $field) { $add($row[$field] ?? ''); }
            } else { $add($row); }
        }
        foreach (is_array($settings['announcement_groups'] ?? null) ? $settings['announcement_groups'] : [] as $group) {
            if (!is_array($group) || (array_key_exists('enabled', $group) && !self::enabled($group['enabled']))) { continue; }
            foreach (['recipients', 'extensions', 'phone_recipients'] as $field) {
                foreach (is_array($group[$field] ?? null) ? $group[$field] : [] as $row) {
                    if (is_array($row)) { $add($row['extension'] ?? ($row['number'] ?? '')); }
                    else { $add($row); }
                }
            }
        }
        return array_values(array_unique($targets, SORT_STRING));
    }

    /** @return array<string,array<string,bool>> */
    private static function currentAcceptance(array $settings, string $moduleVersion): array
    {
        $accepted = [];
        if ($moduleVersion === '' || empty($settings['device_acceptance']['records'])) { return $accepted; }
        try { $report = DeviceAcceptance::report($settings, $moduleVersion); }
        catch (\Throwable $error) { return $accepted; }
        foreach ($report['records'] as $record) {
            if (($record['superseded'] ?? true) || ($record['configuration_changed'] ?? true)
                || ($record['time_ahead'] ?? true) || ($record['result'] ?? '') !== 'passed') { continue; }
            $type = $record['target_type'] ?? null;
            $id = $record['target_id'] ?? null;
            if (is_string($type) && is_string($id)) { $accepted[$type][$id] = true; }
        }
        return $accepted;
    }

    public static function report(array $settings, string $moduleVersion = ''): array
    {
        $running = RuntimeState::running($settings);
        $notifications = $running && $settings !== [];
        $integrations = is_array($settings['enterprise_integrations'] ?? null) ? $settings['enterprise_integrations'] : [];
        $labs = $notifications && self::enabled($integrations['enabled'] ?? false);
        $acceptance = self::currentAcceptance($settings, $moduleVersion);
        $rows = [];
        $add = static function (
            string $id,
            string $name,
            bool $enabled,
            array $targets,
            array $missing,
            string $requires,
            string $offline,
            ?string $acceptanceType = null
        ) use (&$rows, $acceptance): void {
            $targets = array_values(array_unique(array_filter($targets, 'is_string')));
            $missing = array_values(array_unique(array_filter($missing, 'is_string')));
            $accepted = [];
            if ($acceptanceType !== null) {
                foreach ($targets as $target) {
                    if (!empty($acceptance[$acceptanceType][$target])) { $accepted[] = $target; }
                }
            }
            if (!$enabled) { $state = 'disabled'; $label = 'Disabled'; }
            elseif ($missing) { $state = 'enabled_incomplete'; $label = 'Enabled; setup incomplete'; }
            elseif ($targets && count($accepted) === count($targets)) { $state = 'acceptance_verified'; $label = 'Current acceptance recorded'; }
            else { $state = 'configured_unverified'; $label = 'Configured; acceptance not verified'; }
            $unverified = $enabled && !$missing && $targets ? array_values(array_diff($targets, $accepted)) : [];
            $rows[] = ['id' => $id, 'name' => $name, 'enabled' => $enabled, 'state' => $state,
                'state_label' => $label, 'configured' => $enabled && !$missing,
                'configured_destinations' => count($targets), 'accepted_destinations' => count($accepted),
                'missing_prerequisites' => $missing, 'unverified_prerequisites' => $unverified,
                'requires' => $requires, 'offline_behavior' => $offline,
                'runtime_acceptance' => $accepted ? 'operator_observation_current' : 'not_measured'];
        };

        $phoneTargets = self::phoneTargets($settings);
        $add('phones', 'Internal phone audio', $notifications, $phoneTargets,
            $notifications && !$phoneTargets ? ['No internal phone destination is saved.'] : [],
            'Running Asterisk, fresh authenticated collector, online PJSIP contacts and locally installed tones/voice model.',
            'May operate on the local PBX/LAN without Internet. Registration, auto-answer and complete playback require a local drill.', 'phone');

        $desktopTargets = self::enabledIds($settings['desktop_clients'] ?? [], 'username');
        $add('desktops', 'Desktop app', $notifications && $desktopTargets !== [], $desktopTargets, [],
            'Clients must reach the configured PBX HTTPS origin and authenticate with current credentials.',
            'Local delivery requires local DNS/routing and a reachable certificate-valid origin. Disconnected clients intentionally skip missed announcements.', 'desktop');

        $speakerTargets = [];
        foreach (is_array($integrations['speakers']['devices'] ?? null) ? $integrations['speakers']['devices'] : [] as $speaker) {
            if (is_array($speaker) && self::enabled($speaker['enabled'] ?? false) && is_string($speaker['extension'] ?? null)) {
                $speakerTargets[] = $speaker['extension'];
            }
        }
        $speakersEnabled = $labs && self::enabled($integrations['speakers']['enabled'] ?? false);
        $add('speakers', 'SIP speakers', $speakersEnabled, $speakerTargets,
            $speakersEnabled && !$speakerTargets ? ['No enabled SIP speaker is saved.'] : [],
            'Saved online PBX extensions, approved firmware and configured device auto-answer.',
            'May use local LAN audio. SIP acceptance does not confirm audibility; verify offline and busy device results.', 'phone');

        $connectorTargets = self::enabledIds($integrations['connectors']['destinations'] ?? []);
        $connectorsEnabled = $labs && self::enabled($integrations['connectors']['enabled'] ?? false);
        $add('connectors', 'ntfy / Gotify', $connectorsEnabled, $connectorTargets,
            $connectorsEnabled && !$connectorTargets ? ['No enabled connector destination is saved.'] : [],
            'Saved HTTPS service, trusted certificate, pinned private address and scoped token.',
            'Self-hosted services may remain reachable on the LAN. Downstream browser/mobile delivery can still depend on external push infrastructure.');

        $voice = is_array($settings['outbound_voice'] ?? null) ? $settings['outbound_voice'] : [];
        $voiceTargets = self::enabledIds($voice['recipients'] ?? []);
        $voiceEnabled = $notifications && self::enabled($voice['enabled'] ?? false);
        $add('external_voice', 'External calls', $voiceEnabled, $voiceTargets,
            $voiceEnabled && !$voiceTargets ? ['No enabled external voice recipient is saved.'] : [],
            'Authorized outbound route, trunk, carrier and answer-gated playback.',
            'Availability depends on the trunk and carrier. A local PBX cannot guarantee service during an Internet or carrier outage.', 'external_voice');

        $mail = is_array($settings['announcement_email'] ?? null) ? $settings['announcement_email'] : [];
        $mailTargets = self::enabledIds($mail['recipients'] ?? []);
        $mailEnabled = $notifications && self::enabled($mail['enabled'] ?? false);
        $add('email', 'Email', $mailEnabled, $mailTargets,
            $mailEnabled && !$mailTargets ? ['No enabled email recipient is saved.'] : [],
            'Working local Postfix and the recipient mail route.',
            'Local queue acceptance can continue; remote SMTP delivery may wait for connectivity. Accepted mail is not a delivery or human receipt.', 'email');

        $sms = is_array($settings['announcement_sms'] ?? null) ? $settings['announcement_sms'] : [];
        $smsTargets = self::enabledIds($sms['recipients'] ?? []);
        $smsEnabled = $notifications && self::enabled($sms['enabled'] ?? false);
        $smsMissing = [];
        if ($smsEnabled && !$smsTargets) { $smsMissing[] = 'No enabled SMS/MMS recipient is saved.'; }
        if ($smsEnabled && (!is_string($sms['provider'] ?? null) || $sms['provider'] === '')) { $smsMissing[] = 'No SMS/MMS provider is selected.'; }
        $add('sms', 'SMS / MMS', $smsEnabled, $smsTargets, $smsMissing,
            'Authorized provider HTTPS API, consented recipients and provider connectivity.',
            'Cloud submission requires connectivity. Uncertain or failed submissions are never automatically resent.', 'sms');

        $subscriber = is_array($settings['subscriber_browser'] ?? null) ? $settings['subscriber_browser'] : [];
        $subscriberTargets = self::enabledIds($subscriber['people'] ?? []);
        $browserEnabled = $notifications && self::enabled($subscriber['enabled'] ?? false) && self::enabled($subscriber['push']['enabled'] ?? false);
        $browserMissing = [];
        if ($browserEnabled && !$subscriberTargets) { $browserMissing[] = 'No enabled browser subscriber is enrolled.'; }
        if ($browserEnabled && empty($subscriber['push']['private_key_pem'])) { $browserMissing[] = 'No VAPID private key is saved.'; }
        $add('browser_push', 'Browser Web Push', $browserEnabled, $subscriberTargets, $browserMissing,
            'Verified enrollment, browser permission, valid VAPID key and browser push-service HTTPS access.',
            'Requires the browser vendor external push service. Expired alerts are discarded; no local push-service substitute is claimed.');

        $weatherEnabled = $notifications && ((self::enabled($settings['enabled'] ?? false) && !empty($settings['nws_zones']))
            || self::enabled($settings['xweather']['enabled'] ?? false));
        $add('weather', 'Weather and lightning', $weatherEnabled, [], [],
            'Fresh authorized provider data and a currently valid alert.',
            'New remote data requires connectivity. Delayed, expired or superseded alerts must not become a fresh warning after recovery.');

        $identity = is_array($settings['enterprise_identity'] ?? null) ? $settings['enterprise_identity'] : [];
        $identityTargets = self::enabledIds($identity['providers'] ?? []);
        $identityEnabled = self::enabled($identity['enabled'] ?? false);
        $add('identity', 'Enterprise identity', $identityEnabled, $identityTargets,
            $identityEnabled && !$identityTargets ? ['No enabled identity provider is saved.'] : [],
            'Reachable saved IdP and matching local operator grant.',
            'New SSO sessions depend on the IdP. Keep a tested local password/TOTP administrator and FreePBX recovery access.');

        $sensorTargets = [];
        foreach (is_array($settings['automations']['rules'] ?? null) ? $settings['automations']['rules'] : [] as $rule) {
            if (is_array($rule) && ($rule['kind'] ?? '') === 'sensor' && self::enabled($rule['enabled'] ?? false)
                && is_string($rule['id'] ?? null)) { $sensorTargets[] = $rule['id']; }
        }
        $sensorsEnabled = $labs && self::enabled($integrations['sensors']['enabled'] ?? false);
        $add('sensors', 'Enrolled physical inputs', $sensorsEnabled, $sensorTargets,
            $sensorsEnabled && !$sensorTargets ? ['No enabled signed sensor rule is enrolled.'] : [],
            'Reachable local HTTPS trigger route, signature, current enrollment and fresh sensor heartbeat.',
            'Can operate on a reachable LAN. Heartbeat freshness does not prove sensor accuracy or supervise a life-safety panel.');

        $local = 0;
        foreach ($settings['operator_access']['accounts'] ?? [] as $account) {
            if (is_array($account) && ($account['source'] ?? '') === 'portal' && self::enabled($account['enabled'] ?? false)
                && ($account['role'] ?? '') === 'administrator' && OperatorAccess::activeAccount($account)
                && OperatorAuth::ready($account)) { $local++; }
        }
        $portal = self::enabled($settings['operator_access']['portal_enabled'] ?? false);
        return ['schema' => 2, 'generated_at' => gmdate('c'), 'basis' => 'saved_configuration_and_current_operator_observations',
            'notifications_running' => $running, 'channels' => $rows,
            'local_recovery' => ['portal_enabled' => $portal, 'enrolled_local_administrators' => $local,
                'ready_for_local_login' => $portal && $local > 0,
                'required_drill' => 'Sign in with a local administrator password and TOTP while the IdP/WAN is isolated in a separate lab. Keep FreePBX recovery access available.'],
            'cluster_scope' => 'Notification coordination only. Matching PBX configuration, Asterisk/trunks and complete PBX failover are not qualified by this report.',
            'qualification' => 'This review sends no alert and does not simulate an outage on the live PBX. Current acceptance means a saved operator observation still matches the destination configuration; run drills in an isolated test environment.'];
    }
}
