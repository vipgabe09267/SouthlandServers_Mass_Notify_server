<?php
namespace FreePBX\modules;

/** A small allowlisted report, never a dump of configuration or raw logs. */
trait SlsSupportDiagnostics
{
    public function getRedactedSupportDiagnostics()
    {
        $version = static function ($value) {
            return is_string($value) && preg_match('/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $value) ? $value : 'unavailable';
        };
        $report = [
            'schema' => 'sls-redacted-diagnostics-v1', 'generated_at' => gmdate('c'),
            'versions' => ['slsmassnotifyserver' => self::MODULE_VERSION, 'php' => $version(PHP_VERSION)],
            'timezone' => in_array(date_default_timezone_get(), \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? date_default_timezone_get() : 'unavailable',
            'checks' => [], 'permissions' => [], 'devices' => ['available' => false, 'count' => 0, 'formats' => [], 'transports' => []],
            'queue_counts' => [],
            'excluded' => ['configuration', 'credentials', 'addresses', 'hostnames', 'device_identifiers', 'message_contents', 'raw_logs'],
        ];
        try {
            $query = $this->FreePBX->Database()->prepare('SELECT version FROM modules WHERE modulename = ? LIMIT 1');
            foreach (['framework', 'dashboard', 'backup', 'recordings'] as $module) {
                $query->execute([$module]);
                $report['versions'][$module] = $version($query->fetchColumn());
            }
        } catch (\Throwable $error) { $report['versions']['freepbx_inventory'] = 'unavailable'; }
        $os = @file_get_contents('/etc/os-release', false, null, 0, 8192);
        if (is_string($os) && preg_match('/^VERSION_ID="?([0-9.]+)"?$/m', $os, $match)) {
            $report['versions']['os_version_id'] = $version($match[1]);
        }
        // Only these static labels can appear; diagnostic details may contain
        // settings or paths and must never be copied into the downloadable file.
        $labels = ['Central config', 'Central config loader', 'SIP NOTIFY sender', 'NWS poller',
            'Weather scheduler', 'Weather delivery worker', 'Announcement scheduler', 'Xweather poller',
            'Branded email sender', 'Branded Discord sender', 'Notification destination dispatcher',
            'System/error email notifier', 'Weather zone status helper', 'Weather cross-zone delivery coordinator',
            'Maintenance worker', 'Piper binary', 'Executable runtime ownership', 'Piper voice',
            'Notification log', 'Desktop journal', 'Local email transport', 'Control API',
            'Storage available', 'External delivery queue', 'Weather delivery queue'];
        try {
            $checks = $this->getDiagnosticsSummary()['checks'] ?? [];
            foreach ($labels as $label) {
                foreach ($checks as $check) {
                    if (is_array($check) && ($check['label'] ?? '') === _($label)) {
                        $report['checks'][] = ['check' => $label, 'ok' => ($check['ok'] ?? null) === true];
                        break;
                    }
                }
            }
        } catch (\Throwable $error) { $report['checks_available'] = false; }
        foreach (['central_config' => self::SETTINGS_JSON, 'runtime' => self::RUNTIME_DIR,
            'announcement_worker' => self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php',
            'audio_queue' => self::RUNTIME_DIR . '/sls_audio_queue.py',
            'piper' => self::PIPER_BIN, 'generated_audio' => self::TTS_DIR] as $name => $path) {
            $metadata = @lstat($path);
            $report['permissions'][$name] = $metadata === false ? ['exists' => false] : [
                'exists' => true, 'mode' => sprintf('%04o', $metadata['mode'] & 0777),
                'root_owned' => $metadata['uid'] === 0, 'symbolic_link' => ($metadata['mode'] & 0170000) === 0120000,
                'readable' => is_readable($path), 'executable' => is_executable($path),
            ];
        }
        try {
            $inventory = $this->getDeviceOverrideInventory();
            $report['devices']['available'] = ($inventory['success'] ?? false) === true;
            foreach (array_slice($inventory['devices'] ?? [], 0, 1000) as $device) {
                if (!is_array($device)) { continue; }
                $report['devices']['count']++;
                $format = $device['format'] ?? 'unknown';
                if (!in_array($format, ['yealink', 'yealink_text', 'cisco', 'poly', 'polycom', 'grandstream', 'fanvil', 'snom', 'aastra', 'mitel', 'sangoma', 'avaya', 'vtech', 'ale', 'alcatel', 'panasonic'], true)) { $format = 'unknown'; }
                $transport = strtolower(is_string($device['transport'] ?? null) ? $device['transport'] : 'unknown');
                if (!in_array($transport, ['udp', 'tcp', 'tls'], true)) { $transport = 'unknown'; }
                foreach (['formats' => $format, 'transports' => $transport] as $field => $key) {
                    $report['devices'][$field][$key] = ($report['devices'][$field][$key] ?? 0) + 1;
                }
            }
        } catch (\Throwable $error) { $report['devices']['available'] = false; }
        try {
            $summary = $this->loadJsonFile(self::PLUGIN_DATA_DIR . '/storage-summary.json');
            foreach (['pending_external', 'expired_external', 'queue_errors', 'pending_weather', 'failed_weather', 'uncertain_weather', 'expired_weather'] as $key) {
                $value = $summary[$key] ?? null;
                $report['queue_counts'][$key] = is_int($value) && $value >= 0 && $value <= 1000000 ? $value : null;
            }
        } catch (\Throwable $error) { $report['queue_counts_available'] = false; }
        return $report;
    }
}
