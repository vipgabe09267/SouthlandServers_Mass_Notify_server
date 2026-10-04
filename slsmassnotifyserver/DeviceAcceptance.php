<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Human observations are separate from transport receipts and local health. */
final class DeviceAcceptance
{
    public const MAX_RECORDS = 200;
    public const CHECKS = [
        'phone' => ['text' => 'SIP text or image display', 'audio' => 'Complete internal audio', 'https' => 'HTTPS image retrieval'],
        'desktop' => ['plain' => 'Plain announcement', 'image' => 'Colored announcement', 'receipt' => 'Exact-event app receipt', 'resume' => 'Sleep/resume without stale replay', 'burst' => 'Consecutive or burst alerts'],
        'external_voice' => ['answer' => 'Audio starts after answer', 'audio' => 'Complete external audio', 'quality' => 'Speech quality', 'dtmf' => 'Keypad acknowledgement'],
        'email' => ['receipt' => 'Inbox receipt', 'details' => 'Complete alert details and instructions'],
        'sms' => ['receipt' => 'Handset receipt', 'segments' => 'Complete message segments', 'optout' => 'Provider opt-out handling'],
        'webhook' => ['receipt' => 'Destination receipt', 'format' => 'Message and artwork rendering'],
        'paging' => ['menu' => 'Menu and group selection', 'pin' => 'PIN and caller restrictions', 'audio' => 'Live audio and saved SIP text', 'external' => 'Approved external IVR caller'],
        'action' => ['activation' => 'Physical device or reviewed script action', 'rejection' => 'Unauthorized activation rejected'],
    ];

    public static function text($value, int $maximum, string $label, bool $optional = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new \DomainException($label . ' must be valid text of at most ' . $maximum . ' bytes.');
        }
        $value = trim($value);
        if (!$optional && $value === '') { throw new \DomainException($label . ' is required.'); }
        return $value;
    }

    public static function observation(array $input, int $now): array
    {
        if (array_diff(array_keys($input), ['target_type', 'target_id', 'tested_at', 'result', 'model', 'firmware', 'checks', 'notes', 'confirmed'])) {
            throw new \DomainException('The device-test form contains unsupported fields. Reload the page.');
        }
        $type = $input['target_type'] ?? null;
        if (!is_string($type) || !isset(self::CHECKS[$type])) { throw new \DomainException('Select a device or channel type.'); }
        $target = self::text($input['target_id'] ?? null, 80, 'Saved destination');
        if (!preg_match('/^[A-Za-z0-9_.@:-]{1,80}$/D', $target)) { throw new \DomainException('The saved destination identifier is invalid.'); }
        $date = $input['tested_at'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
            throw new \DomainException('The test time must include its timezone.');
        }
        try { $time = new \DateTimeImmutable($date); }
        catch (\Throwable $error) { throw new \DomainException('The test date or time is invalid.'); }
        $errors = \DateTimeImmutable::getLastErrors();
        if (($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->getTimestamp() < 946684800 || $time->getTimestamp() > $now + 60) {
            throw new \DomainException('Enter a real test time from January 2000 through the current time.');
        }
        if (!in_array($input['result'] ?? null, ['passed', 'failed', 'incomplete'], true)) { throw new \DomainException('Select Passed, Failed or Incomplete.'); }
        $checks = $input['checks'] ?? null;
        if (!is_array($checks) || !array_is_list($checks) || !$checks || count($checks) > count(self::CHECKS[$type])) {
            throw new \DomainException('Select the behavior you actually checked.');
        }
        foreach ($checks as $check) {
            if (!is_string($check) || !isset(self::CHECKS[$type][$check])) { throw new \DomainException('A selected check does not apply to this device type.'); }
        }
        if (count(array_unique($checks)) !== count($checks)) { throw new \DomainException('A device check was submitted twice.'); }
        if (($input['confirmed'] ?? false) !== true) { throw new \DomainException('Confirm that this records your own test observation. Saving sends no alert.'); }
        return ['target_type' => $type, 'target_id' => $target, 'tested_at' => gmdate('c', $time->getTimestamp()),
            'result' => $input['result'], 'model' => self::text($input['model'] ?? '', 100, 'Device model', $type !== 'phone'),
            'firmware' => self::text($input['firmware'] ?? '', 100, 'Firmware or app version', true),
            'checks' => $checks, 'notes' => self::text($input['notes'] ?? '', 600, 'Test notes', false)];
    }

    public static function normalize($value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['schema', 'records', 'retired_records'])
            || ($value['schema'] ?? 1) !== 1 || !is_array($value['records'] ?? []) || !array_is_list($value['records'] ?? [])
            || count($value['records'] ?? []) > self::MAX_RECORDS || !is_int($value['retired_records'] ?? 0)
            || ($value['retired_records'] ?? 0) < 0 || ($value['retired_records'] ?? 0) > PHP_INT_MAX - 1) {
            throw new \DomainException('Device-test history is invalid or exceeds its 200-record bound. Preserve the configuration and review the imported data.');
        }
        $records = []; $seen = [];
        foreach ($value['records'] ?? [] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'target_type', 'target_id', 'target_label', 'tested_at', 'result', 'model', 'firmware', 'checks', 'notes', 'recorded_at', 'recorded_by', 'module_version', 'configuration_fingerprint'])
                || !is_string($row['id'] ?? null) || !preg_match('/^dtest_[a-f0-9]{24}$/D', $row['id']) || isset($seen[$row['id']])
                || !is_string($row['configuration_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['configuration_fingerprint'])
                || !is_string($row['recorded_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $row['recorded_at'])) {
                throw new \DomainException('A device-test history record has invalid identity, provenance or fields.');
            }
            $observation = self::observation(array_intersect_key($row, array_flip(['target_type', 'target_id', 'tested_at', 'result', 'model', 'firmware', 'checks', 'notes'])) + ['confirmed' => true], PHP_INT_MAX - 60);
            try { $recorded = new \DateTimeImmutable($row['recorded_at']); }
            catch (\Throwable $error) { throw new \DomainException('A device-test recording time is invalid.'); }
            $errors = \DateTimeImmutable::getLastErrors();
            if (($errors !== false && ($errors['warning_count'] || $errors['error_count']))
                || $recorded->getTimestamp() < 946684800 || strtotime($observation['tested_at']) > $recorded->getTimestamp() + 60) {
                throw new \DomainException('A device-test recording time precedes its observation or is invalid.');
            }
            $records[] = $observation + ['id' => $row['id'], 'target_label' => self::text($row['target_label'] ?? '', 200, 'Destination label'),
                'recorded_at' => $row['recorded_at'], 'recorded_by' => self::text($row['recorded_by'] ?? '', 100, 'Recording operator'),
                'module_version' => self::text($row['module_version'] ?? '', 64, 'SLS version'), 'configuration_fingerprint' => $row['configuration_fingerprint']];
            $seen[$row['id']] = true;
        }
        $result = ['schema' => 1, 'records' => $records, 'retired_records' => $value['retired_records'] ?? 0];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 524288) { throw new \DomainException('Device-test history exceeds its 512 KiB storage bound. Shorten notes before saving.'); }
        return $result;
    }

    /** Stable payload identity, including unpublished builds sharing a version. */
    public static function softwareIdentity(string $directory = __DIR__): string
    {
        if (realpath($directory) !== $directory || is_link($directory)) { throw new \DomainException('The installed SLS payload cannot be identified safely.'); }
        $files = []; $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname(); $name = substr($path, strlen($directory) + 1);
            // Local signing timestamps are not software changes. Python caches
            // are runtime artifacts, never part of the signed release payload.
            if ($name === 'module.sig' || preg_match('~(?:^|/)__pycache__/|\.pyc$~D', $name)) { continue; }
            $meta = lstat($path);
            if (!$meta || ($meta['mode'] & 0170000) !== 0100000 || $meta['nlink'] !== 1
                || $meta['size'] > 8388608 || ($bytes += $meta['size']) > 33554432 || count($files) >= 2048) {
                throw new \DomainException('The installed SLS payload contains an unsafe file or exceeds its identification bounds.');
            }
            $digest = hash_file('sha256', $path);
            clearstatcache(true, $path); $after = lstat($path);
            if (!is_string($digest) || !$after || array_intersect_key($meta, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))
                !== array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))) {
                throw new \DomainException('SLS software changed while its test record was being checked. Reload before continuing.');
            }
            $files[$name] = $digest;
        }
        ksort($files, SORT_STRING);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    public static function fingerprint(array $settings, string $version): string
    {
        // Use exact stored values. Reading legacy plaintext credentials may
        // produce fresh ciphertext during normalization without a saved change.
        unset($settings['device_acceptance']);
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) { $item = $sort($item); } unset($item);
            return $value;
        };
        // Only a digest leaves this method; no configured credentials are projected.
        return hash('sha256', json_encode([$version, self::softwareIdentity(), $sort($settings)], JSON_THROW_ON_ERROR));
    }

    public static function report(array $settings, string $version, ?int $now = null): array
    {
        $now = $now ?? time();
        $history = self::normalize($settings['device_acceptance'] ?? []);
        $records = $history['records'];
        // New records are prepended. Preserve that order for equal timestamps;
        // random IDs cannot establish which observation was recorded last.
        $order = array_keys($records);
        usort($order, static fn($a, $b) => strcmp($records[$b]['tested_at'], $records[$a]['tested_at'])
            ?: strcmp($records[$b]['recorded_at'], $records[$a]['recorded_at']) ?: ($a <=> $b));
        $records = array_map(static fn($index) => $records[$index], $order);
        $fingerprint = self::fingerprint($settings, $version); $latest = []; $counts = ['passed' => 0, 'failed' => 0, 'incomplete' => 0, 'review_required' => 0];
        foreach ($records as &$row) {
            $key = $row['target_type'] . ':' . $row['target_id'];
            $row['superseded'] = isset($latest[$key]);
            $row['configuration_changed'] = !hash_equals($row['configuration_fingerprint'], $fingerprint);
            $row['time_ahead'] = strtotime($row['tested_at']) > $now + 60 || strtotime($row['recorded_at']) > $now + 60;
            $row['check_labels'] = array_map(static fn($check) => self::CHECKS[$row['target_type']][$check], $row['checks']);
            unset($row['configuration_fingerprint']);
            if (!$row['superseded']) { $latest[$key] = true; $counts[$row['configuration_changed'] || $row['time_ahead'] ? 'review_required' : $row['result']]++; }
        } unset($row);
        return ['schema' => 1, 'records' => $records, 'current_counts' => $counts, 'retired_records' => $history['retired_records'],
            'maximum_records' => self::MAX_RECORDS, 'meaning' => 'Operator observations, not automatic receipt or production certification. Recheck after software, routing, firmware or deployment changes. The latest 200 observations are retained; export the report before further tests when long-term evidence is required.'];
    }
}
