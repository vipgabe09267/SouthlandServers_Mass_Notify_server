<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/IncidentConfig.php';

/** Partition a frozen audience using reviewed wording; never translate text. */
final class IncidentLanguages
{
    public const CHANNELS = ['extensions','desktop_clients','voice_recipient_ids','email_recipient_ids','sms_recipient_ids','webhook_ids','browser_recipient_ids','connector_ids'];

    public static function plan(array $template, array $delivery, array $preferences, array $fields, string $fallback, bool $drill, callable $speech): array
    {
        $routes = [];
        foreach (IncidentConfig::recipientLanguages($preferences) as $row) { $routes[$row['channel']][$row['recipient_id']] = $row['locale']; }
        if (!$routes) { return []; }
        $variants = array_column($template['language_variants'] ?? [], null, 'locale');
        $groups = []; $matched = false;
        foreach (self::CHANNELS as $channel) {
            foreach ($delivery[$channel] ?? [] as $id) {
                $requested = $routes[$channel][$id] ?? $fallback;
                $locale = isset($variants[$requested]) ? $requested : $fallback;
                $matched = $matched || isset($routes[$channel][$id]);
                if (!isset($groups[$locale])) {
                    $groups[$locale] = $delivery;
                    foreach (self::CHANNELS as $field) { $groups[$locale][$field] = []; }
                    foreach ($groups[$locale]['_identities'] ?? [] as $field => $identities) { $groups[$locale]['_identities'][$field] = []; }
                }
                $groups[$locale][$channel][] = $id;
                if (isset($delivery['_identities'][$channel][$id])) { $groups[$locale]['_identities'][$channel][$id] = $delivery['_identities'][$channel][$id]; }
            }
        }
        if (!$matched) { return []; }
        ksort($groups, SORT_STRING); $out = [];
        foreach ($groups as $locale => $targets) {
            if (!$targets['extensions'] && !$targets['voice_recipient_ids']) { $targets['audio_mode'] = 'none'; }
            $cohort = $template; $cohort['delivery'] = $targets; $cohort['escalation']['enabled'] = false;
            $message = IncidentConfig::render($cohort, $fields, $locale);
            if ($drill) { $message['title'] = IncidentConfig::text('DRILL: '.$message['title'], 80, 'Drill title'); }
            $targets = $speech($targets, $locale);
            $out[] = ['locale'=>$locale, 'title'=>$message['title'], 'message'=>$message['message'], 'delivery'=>$targets];
        }
        return $out;
    }
}
