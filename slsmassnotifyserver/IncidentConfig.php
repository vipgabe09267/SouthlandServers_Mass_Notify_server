<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Portable configuration only. Incident execution and responses use IncidentStore. */
final class IncidentConfig
{
    public const MAX_ESCALATION_STEPS = 5;

    /** Also reads existing frozen incidents without changing their history. */
    public static function escalationSteps(array $policy): array
    {
        if (array_key_exists('steps', $policy)) { return self::listOf($policy['steps'], self::MAX_ESCALATION_STEPS, 'Escalation steps'); }
        return [['name' => 'Supervisor follow-up', 'after_seconds' => $policy['after_seconds'] ?? 300, 'delivery' => $policy['delivery'] ?? []]];
    }

    /** One next attempt; uncertain submissions block automatic progression. */
    public static function nextEscalation(array $record): ?array
    {
        $policy = $record['template']['escalation'];
        if ($record['state'] !== 'open' || empty($policy['enabled']) || $record['opened_at'] === '') { return null; }
        $unresolved = false;
        foreach ($record['template']['roster'] as $person) {
            if (in_array($record['responses'][$person['id']]['response'] ?? 'no_response', ['no_response', 'missing', 'needs_assistance'], true)) { $unresolved = true; break; }
        }
        if (!$unresolved) { return null; }
        $attempts = [];
        foreach ($record['operations'] as $operation) {
            if ($operation['state'] === 'submitting' || $operation['state'] === 'uncertain') { return null; }
            if ($operation['kind'] === 'escalation') {
                $number = $operation['escalation_step'] ?? 1;
                if (!is_int($number) || $number < 1 || $number > self::MAX_ESCALATION_STEPS) { return null; }
                $attempts[$number] = $operation;
            }
        }
        $opened = strtotime($record['opened_at']);
        if ($opened === false) { return null; }
        $steps = self::escalationSteps($policy);
        foreach ($steps as $index => $step) {
            $number = $index + 1;
            if (isset($attempts[$number])) { continue; }
            $due = $opened + $step['after_seconds'];
            if ($index > 0) {
                if (!isset($attempts[$index])) { return null; }
                // A delayed worker never catches up by firing several levels
                // together. Preserve the configured interval between levels.
                $previous = strtotime($attempts[$index]['finished_at'] ?? $attempts[$index]['claimed_at']);
                if ($previous === false) { return null; }
                $due = max($due, $previous + $step['after_seconds'] - $steps[$index - 1]['after_seconds']);
            }
            return $step + ['number' => $number, 'due_at' => $due];
        }
        return null;
    }

    public static function context($value): array
    {
        $value = self::object($value, ['schema', 'incident_id', 'sequence', 'kind', 'severity', 'is_test'], 'Incident context');
        if (($value['schema'] ?? null) !== 1 || !is_int($value['sequence'] ?? null) || $value['sequence'] < 1 || $value['sequence'] > 250
            || !in_array($value['kind'] ?? null, ['initial', 'update', 'all_clear', 'escalation'], true)
            || !in_array($value['severity'] ?? null, ['information', 'warning', 'critical'], true)) {
            throw new \InvalidArgumentException('Unsupported incident delivery context.');
        }
        self::identifier($value['incident_id'] ?? '', '/^inc_[a-f0-9]{32}$/D', 'Incident identifier');
        self::flag($value['is_test'] ?? null, 'Incident test flag');
        return $value;
    }
    public static function defaults(): array { return ['schema' => 1, 'templates' => []]; }
    public static function retention($value): array
    {
        $value = self::object($value, ['enabled', 'after_days', 'max_archive_mib'], 'Incident archival');
        $out = ['enabled' => self::flag($value['enabled'] ?? false, 'Incident archival'),
            'after_days' => $value['after_days'] ?? 90, 'max_archive_mib' => $value['max_archive_mib'] ?? 256];
        foreach (['after_days' => [1, 3650], 'max_archive_mib' => [64, 4096]] as $key => [$min, $max]) {
            if (!is_int($out[$key]) || $out[$key] < $min || $out[$key] > $max) {
                throw new \InvalidArgumentException(($key === 'after_days' ? 'Archive age' : 'Archive allocation') . ' must be an integer from ' . $min . ' to ' . $max . '.');
            }
        }
        return $out;
    }
    public static function text($value, int $limit, string $label, bool $empty = false): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
            throw new \InvalidArgumentException($label . ' must be plain UTF-8 text.');
        }
        $value = trim($value);
        if ((!$empty && $value === '') || (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value)) > $limit) {
            throw new \InvalidArgumentException($label . ' must contain ' . ($empty ? 'at most ' : '1–') . $limit . ' characters.');
        }
        return $value;
    }
    public static function object($value, array $allowed, string $label): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || array_diff(array_keys($value), $allowed)) {
            throw new \InvalidArgumentException($label . ' contains missing, unknown or invalid fields.');
        }
        return $value;
    }
    public static function listOf($value, int $limit, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new \InvalidArgumentException($label . ' must be a list of no more than ' . $limit . ' entries.');
        }
        return $value;
    }
    public static function flag($value, string $label): bool
    {
        if (!is_bool($value)) { throw new \InvalidArgumentException($label . ' must be true or false.'); }
        return $value;
    }
    public static function identifier($value, string $pattern, string $label): string
    {
        if (!is_string($value) || !preg_match($pattern, $value)) { throw new \InvalidArgumentException($label . ' is invalid.'); }
        return $value;
    }
    private static function selectors($value, string $pattern, int $limit, string $label): array
    {
        $out = [];
        foreach (self::listOf($value, $limit, $label) as $item) { $out[] = self::identifier($item, $pattern, $label); }
        if (count($out) !== count(array_unique($out))) { throw new \InvalidArgumentException($label . ' contains duplicate selections.'); }
        return $out;
    }
    public static function delivery($value): array
    {
        $row = self::object($value, ['extensions', 'desktop_clients', 'group_ids', 'voice_recipient_ids', 'webhook_ids', 'email_recipient_ids', 'sms_recipient_ids', 'audio_mode', 'opening_tone', 'closing_tone', 'style', 'background_color'], 'Incident delivery');
        $out = [];
        foreach (['extensions' => '/^[0-9]{1,20}$/D', 'desktop_clients' => '/^[A-Za-z0-9_.@-]{1,80}$/D',
            'group_ids' => '/^[A-Za-z0-9_-]{1,64}$/D', 'voice_recipient_ids' => '/^voice_[a-f0-9]{24}$/D', 'webhook_ids' => '/^[A-Za-z0-9_-]{1,64}$/D', 'email_recipient_ids' => '/^email_[a-f0-9]{24}$/D', 'sms_recipient_ids' => '/^sms_[a-f0-9]{24}$/D'] as $key => $pattern) {
            $out[$key] = self::selectors(array_key_exists($key, $row) ? $row[$key] : [], $pattern, in_array($key, ['group_ids', 'webhook_ids', 'email_recipient_ids', 'sms_recipient_ids'], true) ? 50 : 1000, $key);
        }
        $out['audio_mode'] = $row['audio_mode'] ?? 'none';
        if (!in_array($out['audio_mode'], ['none', 'tones', 'tts', 'tones_tts'], true)) { throw new \InvalidArgumentException('Choose a supported incident audio mode.'); }
        foreach (['opening_tone', 'closing_tone'] as $key) { $out[$key] = self::identifier($row[$key] ?? '', '/^(?:[A-Za-z0-9_-]{1,128})?$/D', $key); }
        $out['style'] = $row['style'] ?? 'standard';
        if (!in_array($out['style'], ['standard', 'colored'], true)) { throw new \InvalidArgumentException('Choose standard or colored incident messages.'); }
        $out['background_color'] = self::identifier($row['background_color'] ?? '#1f2937', '/^#[a-fA-F0-9]{6}$/D', 'Message color');
        if ($out['voice_recipient_ids'] && $out['audio_mode'] === 'none') { throw new \InvalidArgumentException('External voice recipients require audio.'); }
        return $out;
    }
    public static function hasAudience(array $delivery): bool
    {
        foreach (['extensions', 'desktop_clients', 'group_ids', 'voice_recipient_ids', 'webhook_ids', 'email_recipient_ids', 'sms_recipient_ids'] as $field) { if (!empty($delivery[$field])) { return true; } }
        return false;
    }
    public static function resources($value): array
    {
        $out = []; $seen = [];
        foreach (self::listOf($value, 10, 'Incident resources') as $row) {
            $row = self::object($row, ['kind', 'label', 'url', 'revision', 'reviewed'], 'Incident resource');
            if (!in_array($row['kind'] ?? '', ['map', 'instructions', 'reference'], true)) {
                throw new \InvalidArgumentException('Choose map, instructions or reference for each incident resource.');
            }
            $url = self::text($row['url'] ?? '', 2048, 'Resource HTTPS URL');
            $parts = parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !filter_var($url, FILTER_VALIDATE_URL)
                || isset($parts['user']) || isset($parts['pass']) || strpos($url, '\\') !== false
                || preg_match('/[\x00-\x20\x7f]|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) {
                throw new \InvalidArgumentException('Resource links must be complete HTTPS URLs without embedded credentials, spaces or control characters.');
            }
            if (isset($seen[$url])) { throw new \InvalidArgumentException('Each incident resource URL must be unique.'); }
            if (!self::flag($row['reviewed'] ?? false, 'Resource review')) {
                throw new \InvalidArgumentException('Review each resource, its revision and responder access before saving the template.');
            }
            $seen[$url] = true;
            $out[] = ['kind'=>$row['kind'], 'label'=>self::text($row['label'] ?? '', 80, 'Resource label'),
                'url'=>$url, 'revision'=>self::text($row['revision'] ?? '', 100, 'Resource revision or review note'), 'reviewed'=>true];
        }
        return $out;
    }
    private static function placeholders(string $title, string $message, array $keys): void
    {
        preg_match_all('/{{([a-z][a-z0-9_]{0,31})}}/', $title . '\n' . $message, $matches);
        if (array_diff($matches[1], $keys) || array_diff($keys, $matches[1])
            || preg_match('/{{|}}/', preg_replace('/{{[a-z][a-z0-9_]{0,31}}}/', '', $title . $message))) {
            throw new \InvalidArgumentException('Every message variant must use every required {{field}} and contain no undeclared placeholders.');
        }
    }
    public static function languageVariants($value, array $keys): array
    {
        $out = []; $seen = [];
        foreach (self::listOf($value, 6, 'Reviewed language variants') as $row) {
            $row = self::object($row, ['locale', 'label', 'title', 'message', 'review_note', 'reviewed'], 'Language variant');
            $locale = strtolower(self::identifier($row['locale'] ?? '', '/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', 'Language tag'));
            if (isset($seen[$locale])) { throw new \InvalidArgumentException('Language tags must be unique within a template.'); }
            if (!self::flag($row['reviewed'] ?? false, 'Language review')) {
                throw new \InvalidArgumentException('A competent reviewer must approve each language variant before it can be saved.');
            }
            $variant = ['locale'=>$locale, 'label'=>self::text($row['label'] ?? '', 80, 'Language label'),
                'title'=>self::text($row['title'] ?? '', 80, 'Translated title'),
                'message'=>self::text($row['message'] ?? '', 500, 'Translated message'),
                'review_note'=>self::text($row['review_note'] ?? '', 100, 'Language reviewer and revision'), 'reviewed'=>true];
            self::placeholders($variant['title'], $variant['message'], $keys);
            $seen[$locale] = true; $out[] = $variant;
        }
        return $out;
    }
    public static function template($value): array
    {
        $row = self::object($value, ['id', 'name', 'title', 'message', 'severity', 'fields', 'delivery', 'roster', 'checklist', 'escalation', 'resources', 'language_variants'], 'Incident template');
        $out = ['id' => self::identifier($row['id'] ?? '', '/^tpl_[a-f0-9]{24}$/D', 'Template identifier'),
            'name' => self::text($row['name'] ?? '', 80, 'Template name'), 'title' => self::text($row['title'] ?? '', 80, 'Incident title'),
            'message' => self::text($row['message'] ?? '', 500, 'Incident message'), 'severity' => $row['severity'] ?? 'warning'];
        if (!in_array($out['severity'], ['information', 'warning', 'critical'], true)) { throw new \InvalidArgumentException('Choose information, warning or critical severity.'); }
        $out['resources'] = self::resources($row['resources'] ?? []);
        $out['fields'] = []; $keys = [];
        foreach (self::listOf($row['fields'] ?? [], 12, 'Required operator fields') as $field) {
            $field = self::object($field, ['key', 'label'], 'Operator field');
            $key = self::identifier($field['key'] ?? '', '/^[a-z][a-z0-9_]{0,31}$/D', 'Operator field key');
            if (isset($keys[$key])) { throw new \InvalidArgumentException('Operator field keys must be unique.'); }
            $keys[$key] = true;
            $out['fields'][] = ['key' => $key, 'label' => self::text($field['label'] ?? '', 80, 'Operator field label')];
        }
        self::placeholders($out['title'], $out['message'], array_keys($keys));
        $out['language_variants'] = self::languageVariants($row['language_variants'] ?? [], array_keys($keys));
        $out['delivery'] = self::delivery($row['delivery'] ?? []);
        if (!self::hasAudience($out['delivery'])) { throw new \InvalidArgumentException('Select at least one incident destination.'); }
        $out['roster'] = []; $ids = []; $desktops = [];
        foreach (self::listOf($row['roster'] ?? [], 1000, 'Incident roster') as $person) {
            $person = self::object($person, ['id', 'name', 'location', 'desktop_username', 'location_id'], 'Roster person');
            $id = self::identifier($person['id'] ?? '', '/^[A-Za-z0-9_-]{1,64}$/D', 'Roster person identifier');
            $desktop = self::identifier($person['desktop_username'] ?? '', '/^(?:[A-Za-z0-9_.@-]{1,80})?$/D', 'Roster desktop username');
            if (isset($ids[$id]) || ($desktop !== '' && isset($desktops[strtolower($desktop)]))) { throw new \InvalidArgumentException('Roster identifiers and desktop assignments must be unique.'); }
            $ids[$id] = true; if ($desktop !== '') { $desktops[strtolower($desktop)] = true; }
            $out['roster'][] = ['id' => $id, 'name' => self::text($person['name'] ?? '', 100, 'Person name'),
                'location' => self::text($person['location'] ?? '', 120, 'Person location', true), 'desktop_username' => $desktop]
                + (array_key_exists('location_id', $person) && $person['location_id'] !== ''
                    ? ['location_id'=>self::identifier($person['location_id'], '/^loc_[a-f0-9]{24}$/D', 'Roster location')] : []);
        }
        $out['checklist'] = [];
        foreach (self::listOf($row['checklist'] ?? [], 25, 'Observer checklist') as $label) { $out['checklist'][] = self::text($label, 200, 'Checklist item'); }
        $policy = self::object($row['escalation'] ?? [], ['enabled', 'after_seconds', 'delivery', 'steps'], 'Supervisor escalation');
        $enabled = self::flag($policy['enabled'] ?? false, 'Escalation enabled'); $steps = []; $previous = 0;
        foreach (self::escalationSteps($policy) as $index => $step) {
            $step = self::object($step, ['name', 'after_seconds', 'delivery'], 'Escalation step');
            $seconds = $step['after_seconds'] ?? 300;
            if (!is_int($seconds) || $seconds < 60 || $seconds > 86400 || $seconds <= $previous) {
                throw new \InvalidArgumentException('Escalation delays must be increasing whole seconds from 60 through 86400, measured from the initial job.');
            }
            $delivery = self::delivery($step['delivery'] ?? []);
            if ($enabled && !self::hasAudience($delivery)) { throw new \InvalidArgumentException('Every enabled escalation step requires explicit destinations.'); }
            $steps[] = ['name' => self::text($step['name'] ?? ('Supervisor follow-up ' . ($index + 1)), 80, 'Escalation step name'),
                'after_seconds' => $seconds, 'delivery' => $delivery];
            $previous = $seconds;
        }
        if ($enabled && (!$out['roster'] || !$steps)) { throw new \InvalidArgumentException('Enabled escalation requires a person roster and at least one configured follow-up.'); }
        // Keep the first-step fields for existing readers. Conflicting copies
        // must not hide a different audience from authorization or the UI.
        if (array_key_exists('steps', $policy) && ((isset($policy['after_seconds']) && $policy['after_seconds'] !== ($steps[0]['after_seconds'] ?? 300))
            || (isset($policy['delivery']) && self::delivery($policy['delivery']) !== ($steps[0]['delivery'] ?? self::delivery([]))))) {
            throw new \InvalidArgumentException('The legacy supervisor fields disagree with the first escalation step. Reload the template before saving.');
        }
        $out['escalation'] = ['enabled' => $enabled, 'after_seconds' => $steps[0]['after_seconds'] ?? 300,
            'delivery' => $steps[0]['delivery'] ?? self::delivery([]), 'steps' => $steps];
        return $out;
    }
    public static function normalize($value): array
    {
        $value = self::object($value, ['schema', 'templates', 'retention'], 'Incident workflow settings');
        if (($value['schema'] ?? 1) !== 1) { throw new \InvalidArgumentException('Unsupported incident configuration schema.'); }
        $out = self::defaults(); $seen = [];
        // Leave legacy portable settings unchanged when this opt-in feature
        // has never been configured.
        if (array_key_exists('retention', $value)) { $out['retention'] = self::retention($value['retention']); }
        foreach (self::listOf($value['templates'] ?? [], 50, 'Incident templates') as $row) {
            $template = self::template($row);
            if (isset($seen[$template['id']])) { throw new \InvalidArgumentException('Incident template identifiers must be unique.'); }
            $seen[$template['id']] = true; $out['templates'][] = $template;
        }
        if (strlen(json_encode($out, JSON_THROW_ON_ERROR)) > 2097152) { throw new \InvalidArgumentException('Incident templates exceed the 2 MiB protected configuration allocation.'); }
        return $out;
    }
    public static function render(array $template, $values, $language = ''): array
    {
        if (!is_string($language)) { throw new \InvalidArgumentException('Select a saved language variant or the default wording.'); }
        if ($language !== '') {
            $selected = null;
            foreach ($template['language_variants'] ?? [] as $variant) {
                if ($variant['locale'] === strtolower($language)) { $selected = $variant; break; }
            }
            if (!$selected) { throw new \InvalidArgumentException('That reviewed language variant is unavailable. Refresh the applied template before sending.'); }
            $speech = in_array($template['delivery']['audio_mode'] ?? 'none', ['tts', 'tones_tts'], true);
            if (!empty($template['escalation']['enabled'])) {
                foreach (self::escalationSteps($template['escalation']) as $step) { $speech = $speech || in_array($step['delivery']['audio_mode'] ?? 'none', ['tts', 'tones_tts'], true); }
            }
            if ($speech) { self::speechLanguage($selected['locale']); }
            $template['title'] = $selected['title']; $template['message'] = $selected['message'];
        }
        $keys = array_column($template['fields'], 'key'); $values = self::object($values, $keys, 'Operator values'); $replace = [];
        foreach ($template['fields'] as $field) {
            $value = self::text($values[$field['key']] ?? '', 120, $field['label']);
            if (strpos($value, '{{') !== false || strpos($value, '}}') !== false) { throw new \InvalidArgumentException('Operator values cannot contain template placeholders.'); }
            $replace['{{' . $field['key'] . '}}'] = $value;
        }
        return ['title' => self::text(strtr($template['title'], $replace), 80, 'Rendered title'),
            'message' => self::text(strtr($template['message'], $replace), 500, 'Rendered message')];
    }

    /** Supported model languages, independent of a template's regional accent. */
    public static function speechLanguage(string $locale): string
    {
        $locale = strtolower(self::identifier($locale, '/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', 'Speech language'));
        $language = explode('-', $locale)[0];
        if (!in_array($language, ['en', 'es', 'fr', 'de', 'pt'], true)) {
            throw new \InvalidArgumentException('Incident speech supports English, Spanish, French, German and Portuguese. For another reviewed language, use no speech or tones only for the audience and every supervisor follow-up. No channels were submitted.');
        }
        return $language;
    }

    public static function escalationMessage(string $title, array $counts, string $locale): string
    {
        $language = explode('-', strtolower($locale))[0];
        $formats = [
            'en' => 'Supervisor follow-up for %s: %d no response, %d missing, %d need assistance. Review the incident roster.',
            'es' => 'Seguimiento para el supervisor de %s: %d sin respuesta, %d desaparecidos, %d necesitan ayuda. Revise la lista de participantes del incidente.',
            'fr' => 'Suivi du responsable pour %s : %d sans réponse, %d disparus, %d ont besoin d’aide. Consultez la liste des participants à l’incident.',
            'de' => 'Rückmeldung für die Leitung zu %s: %d ohne Antwort, %d vermisst, %d benötigen Hilfe. Prüfen Sie die Teilnehmerliste des Vorfalls.',
            'pt' => 'Acompanhamento para o supervisor de %s: %d sem resposta, %d desaparecidos, %d precisam de ajuda. Consulte a lista de participantes do incidente.',
        ];
        return sprintf($formats[$language] ?? $formats['en'], $title, $counts['no_response'], $counts['missing'], $counts['needs_assistance']);
    }
}
