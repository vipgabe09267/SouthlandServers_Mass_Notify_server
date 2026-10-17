<?php
namespace FreePBX\modules;

/** Project operational history into current health; never rewrite the evidence. */
final class SlsStatusHealth
{
    const RECENT_FAILURE_SECONDS = 900;

    public static function queueFaults(array $storage): array
    {
        // Current summaries carry a separate 15-minute window. Older summaries
        // cannot prove recovery, so retain their warning until refreshed.
        $recent = static function (string $name) use ($storage): bool {
            $value = $storage['recent_' . $name] ?? $storage[$name] ?? 0;
            return !is_int($value) || $value !== 0;
        };
        return [
            'external' => !empty($storage['queue_errors']) || !empty($storage['queue_scan_incomplete']) || $recent('expired_external'),
            'weather' => $recent('failed_weather') || $recent('uncertain_weather') || $recent('expired_weather') || $recent('weather_deadline_misses'),
        ];
    }

    public static function timestamp($value): int
    {
        if (is_int($value)) { return max(0, $value); }
        return is_string($value) && $value !== '' ? (strtotime($value) ?: 0) : 0;
    }

    public static function recent($value, int $now): bool
    {
        $at = self::timestamp($value);
        // A future timestamp is not evidence that a fault has expired.
        return $at > 0 && $at >= $now - self::RECENT_FAILURE_SECONDS;
    }

    public static function announcementFailure(array $state, array $probe, int $now): bool
    {
        if (!in_array($state['state'] ?? '', ['failed', 'expired'], true)) { return false; }
        $at = !empty($state['finished_at']) ? $state['finished_at'] : ($state['updated_at'] ?? '');
        $category = $state['failure_category'] ?? '';
        // Source cancellation is an expected fail-closed delivery outcome, not
        // evidence that the announcement worker or a selected channel is unhealthy.
        if ($category === 'weather_source_cancelled') { return false; }
        if (in_array($category, ['worker_bootstrap_failed', 'worker_module_load_failed'], true)
            && !empty($probe['ok']) && !empty($probe['bootstrap']) && !empty($probe['module_loaded'])
            && self::timestamp($probe['checked_at'] ?? '') > self::timestamp($at)) { return false; }
        return self::recent($at, $now);
    }

    public static function project(array $status, array $settings, int $now): array
    {
        $nws = ($settings['enabled'] ?? '0') === '1';
        $xweather = is_array($settings['xweather'] ?? null) ? $settings['xweather'] : [];
        $lightning = !empty($xweather['enabled']);
        $ids = static function ($rows, bool $enabledOnly = false): array {
            if (!is_array($rows) || !array_is_list($rows)) { return []; }
            $result=[];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['id'] ?? null)
                    || ($enabledOnly && ($row['enabled'] ?? '0') !== '1')) { continue; }
                $result[]=$row['id'];
            }
            return array_values(array_unique($result, SORT_STRING));
        };
        $nwsIds = $ids($settings['nws_zones'] ?? []);
        $lightningIds = $ids($xweather['groups'] ?? [], true);
        $text = static fn($value): string => is_string($value) ? $value : '';
        $source = strtolower($text($status['last_fault_source'] ?? ''));
        $groupId = $text($status['last_fault_group_id'] ?? '');
        $stage = strtolower($text($status['last_fault_stage'] ?? ''));
        $at = self::timestamp($status['last_fault_at'] ?? '');
        $applicable = !in_array($source, ['test', 'manual_test', 'dry_run'], true);
        $group = $status;
        if (in_array($source, ['nws', 'weather', 'weather.gov'], true)) {
            $applicable = $nws && ($groupId === '' || in_array($groupId, $nwsIds, true));
            $nwsGroups=is_array($status['nws_groups']??null) ? $status['nws_groups'] : [];
            if ($groupId !== '') { $group = $nwsGroups[$groupId] ?? []; }
        } elseif (in_array($source, ['xweather', 'lightning'], true)) {
            $applicable = $lightning && ($groupId === '' || in_array($groupId, $lightningIds, true));
            $lightningGroups=is_array($status['xweather_groups']??null) ? $status['xweather_groups'] : [];
            if ($groupId !== '') { $group = $lightningGroups[$groupId] ?? []; }
        }
        // Only matching evidence can resolve a current fault. A successful
        // local page or another area's poll cannot resolve external uncertainty.
        $group = is_array($group) ? $group : [];
        $recovered = $stage === 'api' && ($group['last_poll_status'] ?? '') === 'ok'
            && self::timestamp($group['last_poll_ok_at'] ?? '') > $at;
        $recovered = $recovered || ($stage === 'piper_voice_download'
            && ($status['last_piper_voice_install_status'] ?? '') === 'ok'
            && self::timestamp($status['last_piper_voice_install_at'] ?? '') > $at);
        $ongoing = in_array($stage, ['api', 'external', 'dependencies', 'piper_voice_download'], true);
        if (!$applicable || $recovered || (!$ongoing && !self::recent($status['last_fault_at'] ?? '', $now))) {
            $status['last_fault_at'] = '';
        }
        $deliverySource = strtolower($text($status['last_delivery_source'] ?? ''));
        $deliveryGroup = $text($status['last_delivery_group_id'] ?? '');
        if (!self::recent($status['last_delivery_at'] ?? '', $now)
            || (in_array($deliverySource, ['nws', 'weather', 'weather.gov'], true)
                && (!$nws || ($deliveryGroup !== '' && !in_array($deliveryGroup, $nwsIds, true))))) {
            if (($status['last_delivery_status'] ?? '') === 'fault') { $status['last_delivery_status'] = ''; }
        }
        $groups = is_array($status['xweather_groups'] ?? null) ? $status['xweather_groups'] : [];
        $status['xweather_groups'] = $lightning ? array_intersect_key($groups, array_flip($lightningIds)) : [];
        foreach ($status['xweather_groups'] as &$row) {
            if (!is_array($row)) { $row = []; }
            if (!self::recent($row['last_xweather_delivery_at'] ?? '', $now)) { $row['last_xweather_delivery_status'] = ''; }
        }
        unset($row);
        if (!$lightning || !self::recent($status['last_xweather_delivery_at'] ?? '', $now)) {
            $status['last_xweather_delivery_status'] = '';
        }
        if (!$lightning || !$lightningIds) {
            foreach (['poll', 'delivery', 'external'] as $kind) { $status['last_xweather_' . $kind . '_status'] = ''; }
        }
        if (!array_filter((array)($settings['scheduled_announcements'] ?? []),
            static function ($row) { return is_array($row) && !empty($row['enabled']); })) {
            $status['last_schedule_worker_status'] = '';
        }
        return $status;
    }
}
