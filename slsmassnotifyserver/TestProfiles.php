<?php
namespace FreePBX\modules;

/** Portable, explicitly scoped channel checks. Never sends email or webhooks. */
trait SlsTestProfiles
{
    private function normalizeTestProfiles($rows)
    {
        $profiles = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 10) as $row) {
            if (!is_array($row)) { continue; }
            $name = $this->sanitizeScheduleText(is_scalar($row['name'] ?? null) ? $row['name'] : '', 64, true);
            if ($name === '') { continue; }
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', is_scalar($row['id'] ?? null) ? (string)$row['id'] : '');
            $id = $id !== '' ? substr($id, 0, 64) : 'test_' . substr(hash('sha256', $name), 0, 16);
            if (isset($profiles[$id])) { continue; }
            $phones = array_values(array_unique(array_filter(array_map('strval', array_filter((array)($row['extensions'] ?? []), 'is_scalar')),
                static function ($value) { return preg_match('/^[0-9]{1,20}$/', $value); })));
            $desktops = array_values(array_unique(array_filter(array_map('strval', array_filter((array)($row['desktop_clients'] ?? []), 'is_scalar')),
                static function ($value) { return preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $value); })));
            $profiles[$id] = ['id' => $id, 'name' => $name, 'extensions' => array_slice($phones, 0, 100),
                'desktop_clients' => array_slice($desktops, 0, 100),
                'channels' => in_array($row['channels'] ?? '', ['all', 'audio', 'visual'], true) ? $row['channels'] : 'all'];
        }
        return array_values($profiles);
    }

    public function runTestProfile($profileId)
    {
        if (!is_string($profileId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $profileId)) {
            return ['success' => false, 'message' => _('Invalid test profile identifier.')];
        }
        foreach ($this->getActiveSettings()['test_profiles'] ?? [] as $profile) {
            if ($profile['id'] !== (string)$profileId) { continue; }
            $audio = $profile['channels'] !== 'visual';
            $visual = $profile['channels'] !== 'audio';
            if (empty($profile['extensions']) && (!$visual || empty($profile['desktop_clients']))) {
                return ['success' => false, 'message' => _('This test profile has no destination for the selected channels.')];
            }
            return $this->sendSipNotifyAnnouncement($profile['extensions'],
                'SYSTEM TEST — NOT AN ACTUAL ALERT. This is a notification channel check: ' . $profile['name'] . '.',
                true, $audio, [], ['desktop_clients' => $visual ? $profile['desktop_clients'] : [],
                    'audio_mode' => $audio ? 'tones_tts' : 'none', 'title' => 'System channel test',
                    'trigger_source' => 'Saved Channel Test', '_test_channels' => [
                        'audio' => $audio ? $profile['extensions'] : [],
                        'sip_notify' => $visual ? $profile['extensions'] : [],
                        'desktop' => $visual ? $profile['desktop_clients'] : [], 'webhook' => []]]);
        }
        return ['success' => false, 'message' => _('Saved test profile not found. Save and apply it before running.')];
    }

    private function normalizePagingAnswerTimeout($value)
    {
        // Page() is an auto-answer path. Keep the established five-second
        // ceiling; a longer ringing window needs a different playback lifecycle.
        return max(1, min(5, is_numeric($value) ? (int)$value : 5));
    }
}
