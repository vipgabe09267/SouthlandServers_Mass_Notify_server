<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Pure schedule configuration checks and bounded advisory presentation. */
final class SchedulePresentation
{
    public static function textErrors(array $schedule): array
    {
        $errors = [];
        foreach (['name'=>[$schedule['name'] ?? '', 80], 'message'=>[$schedule['message'] ?? '', 500],
            'image title'=>[$schedule['delivery']['title'] ?? '', 80]] as $label => [$value, $limit]) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) {
                $errors[] = sprintf('The scheduled %s must be valid text of at most %d characters. Shorten it before saving; it will not be cut silently.', $label, $limit);
            }
        }
        return $errors;
    }

    public static function latenessErrors(array $schedule): array
    {
        if (!array_key_exists('max_lateness_minutes', $schedule)) { return []; }
        $value = $schedule['max_lateness_minutes'];
        return is_int($value) && $value >= 1 && $value <= 15 ? []
            : ['Maximum start delay must be a whole number from 1 through 15 minutes.'];
    }

    public static function formLateness(array $input, array $existing): int
    {
        if (!array_key_exists('schedule_max_lateness_minutes', $input)) {
            $value = array_key_exists('max_lateness_minutes', $existing) ? $existing['max_lateness_minutes'] : 15;
        } else {
            $value = $input['schedule_max_lateness_minutes'];
            if (is_string($value) && preg_match('/^(?:[1-9]|1[0-5])$/D', $value)) { $value = (int)$value; }
        }
        $errors = self::latenessErrors(['max_lateness_minutes' => $value]);
        if ($errors) { throw new \DomainException($errors[0]); }
        return $value;
    }

    /** No PBX reads, live contact queries, or guessed speech duration. */
    public static function conflicts(array $settings, int $now): array
    {
        $groups = [];
        foreach (array_slice((array)($settings['announcement_groups'] ?? []), 0, 1000) as $group) {
            if (is_array($group) && is_string($group['id'] ?? null)) { $groups[$group['id']] = $group; }
        }
        $audiences = []; $events = []; $names = []; $incomplete = false;
        $channels = ['extensions' => 'phone', 'desktop_clients' => 'desktop', 'voice_recipient_ids' => 'voice', 'email_recipient_ids' => 'email', 'sms_recipient_ids'=>'sms', 'webhook_ids'=>'webhook'];
        $schedules = (array)($settings['scheduled_announcements'] ?? []);
        if (count($schedules) > 100) { $incomplete = true; }
        foreach (array_slice($schedules, 0, 100) as $schedule) {
            if (!is_array($schedule) || empty($schedule['enabled']) || !is_string($schedule['id'] ?? null)) { continue; }
            $id = $schedule['id']; $names[$id] = (string)($schedule['name'] ?? $id);
            $targets = is_array($schedule['targets'] ?? null) ? $schedule['targets'] : [];
            $sources = [$targets];
            foreach (array_slice((array)($targets['groups'] ?? []), 0, 1000) as $groupId) {
                if (is_string($groupId) && isset($groups[$groupId])) { $sources[] = $groups[$groupId]; }
            }
            $audience = [];
            foreach ($sources as $source) {
                foreach ($channels as $field => $channel) {
                    foreach (array_slice((array)($source[$field] ?? []), 0, 1000) as $value) {
                        if (is_string($value) && $value !== '') { $audience[$channel . ':' . $value] = true; }
                    }
                }
            }
            if (!empty($targets['phones_all'])) { $audience['phone:*'] = true; }
            if (!empty($targets['desktop_all'])) { $audience['desktop:*'] = true; }
            $audiences[$id] = $audience;
            $occurrences = (array)($schedule['occurrences'] ?? []);
            if (count($occurrences) > 366) { $incomplete = true; }
            foreach (array_slice($occurrences, 0, 366) as $occurrence) {
                $raw = is_array($occurrence) ? ($occurrence['run_at_utc'] ?? '') : '';
                if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $raw)) { continue; }
                $time = strtotime($raw);
                if ($time !== false && $time >= $now) { $events[] = [$time, $id]; }
            }
        }
        usort($events, static fn($a, $b) => $a[0] <=> $b[0]);
        $window = max(60, min(3600, (int)($settings['announcement_cooldown_seconds'] ?? 60)));
        $rows = []; $seen = []; $checks = 0;
        for ($i = 0, $length = count($events); $i < $length; ++$i) {
            for ($j = $i + 1; $j < $length && $events[$j][0] - $events[$i][0] <= $window; ++$j) {
                if (++$checks > 20000 || count($rows) >= 100) { $incomplete = true; break 2; }
                [$time, $left] = $events[$i]; [$otherTime, $right] = $events[$j];
                $pair = [$left, $right]; sort($pair); $key = json_encode($pair);
                if (isset($seen[$key])) { continue; }
                $a = $audiences[$left]; $b = $audiences[$right];
                $common = array_intersect_key($a, $b); unset($common['phone:*'], $common['desktop:*']);
                $dynamic = false;
                foreach (['phone', 'desktop'] as $channel) {
                    foreach ([$a, $b] as $index => $side) {
                        if (!isset($side[$channel . ':*'])) { continue; }
                        foreach (array_keys($index === 0 ? $b : $a) as $recipient) {
                            if (str_starts_with($recipient, $channel . ':')) { $dynamic = true; break; }
                        }
                    }
                }
                if (!$common && !$dynamic) { continue; }
                $seen[$key] = true;
                $rows[] = ['schedule_id' => $left, 'other_schedule_id' => $right, 'name' => $names[$left], 'other_name' => $names[$right],
                    'run_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $time), 'other_run_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $otherTime),
                    'shared_recipients' => count($common), 'dynamic_audience' => $dynamic];
            }
        }
        return ['rows' => $rows, 'incomplete' => $incomplete, 'comparison_window_seconds' => $window];
    }
}
