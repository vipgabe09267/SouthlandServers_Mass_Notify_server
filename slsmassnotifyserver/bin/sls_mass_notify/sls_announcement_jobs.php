<?php
/** Bootstrap-independent announcement state. Never loads config or sends alerts. */
final class SlsAnnouncementJobStore
{
    const DATA = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/announcement-jobs';
    const LIMIT = 2097152;
    const PENDING = ['prepared', 'queued', 'worker_starting', 'running'];
    const REASONS = [
        'worker_start_failed' => 'The announcement worker did not start or claim its job. Run the general announcement worker health check.',
        'worker_bootstrap_failed' => 'The announcement worker could not bootstrap FreePBX. Check PHP CLI and FreePBX configuration.',
        'worker_module_load_failed' => 'The announcement worker could not load Mass Notify. Run protected repair and check module status.',
        'worker_runtime_failed' => 'The announcement worker stopped before confirming all results. Review channel receipts before sending again.',
        'worker_timeout' => 'The announcement worker exceeded its execution deadline. Review channel receipts before sending again.',
        'announcement_activity_timeout' => 'Protected configuration maintenance prevented announcement startup. No channels were submitted.',
        'audio_submission_failed' => 'One or more audio page submissions failed. Review the audio channel receipts.',
        'sip_notify_submission_failed' => 'One or more SIP NOTIFY submissions failed. Review the phone visual channel receipts.',
        'channel_submission_failed' => 'The last announcement had a delivery failure. Open its Delivery details for the affected destination and reason.',
        'job_expired' => 'The announcement expired before processing began.',
    ];
    private $directory;

    public function __construct($directory = self::DATA)
    {
        $this->directory = rtrim($directory, '/');
        if (!is_dir($this->directory) && !is_link($this->directory)) {
            if ($this->directory === self::DATA && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                throw new RuntimeException('Initialize announcement storage as the PBX runtime account.');
            }
            if (!@mkdir($this->directory, 0750) && !is_dir($this->directory)) {
                throw new RuntimeException('Announcement job storage is unavailable.');
            }
        }
        clearstatcache(true, $this->directory);
        $meta = @lstat($this->directory);
        if (!$meta || ($meta['mode'] & 0170000) !== 0040000 || ($meta['mode'] & 0022)
            || realpath($this->directory) !== $this->directory) {
            throw new RuntimeException('Announcement job directory is unsafe.');
        }
    }

    public static function validId($id) { return is_string($id) && preg_match('/^job_[a-f0-9]{32}$/D', $id); }
    public static function reason($category) { return self::REASONS[$category] ?? self::REASONS['worker_runtime_failed']; }
    public function directory() { return $this->directory; }

    public static function unconfirmedWebhook(array $row): bool
    {
        return ($row['channel'] ?? '') === 'webhook' && ($row['state'] ?? '') === 'uncertain'
            && (int)($row['http_status'] ?? 0) < 400 && ($row['failure_code'] ?? '') !== 'http_failure';
    }

    public static function receiptNeedsAttention(array $row): bool
    {
        return in_array($row['state'] ?? '', ['failed', 'uncertain', 'cancelled', 'unavailable'], true)
            && !self::unconfirmedWebhook($row);
    }

    /** Display projection only; historical receipts are never rewritten. */
    public static function deliveryProjection(array $result): array
    {
        $neutral = 0; $attention = 0;
        $result['receipts'] = is_array($result['receipts'] ?? null) ? $result['receipts'] : [];
        foreach ($result['receipts'] as &$row) {
            if (!is_array($row)) { continue; }
            $row['needs_attention'] = self::receiptNeedsAttention($row);
            if ($row['needs_attention']) { $attention++; }
            if (self::unconfirmedWebhook($row)) {
                $neutral++;
                $row['detail'] = 'Webhook response was not confirmed. Human receipt is not tracked; this submission will not be replayed automatically.';
                $row['retryable'] = false;
            }
        }
        unset($row);
        if ($neutral && !$attention && ($result['failure_category'] ?? '') === 'channel_submission_failed') {
            $result['success'] = true; $result['state'] = 'complete'; $result['failure_category'] = '';
            $result['message'] = 'Webhook submission recorded; response confirmation is unavailable. See Delivery details.';
            $result['retryable'] = false;
        }
        return $result;
    }

    public static function failureChannels(array $receipts): array
    {
        $channels = [];
        foreach (array_slice($receipts, 0, 5000) as $row) {
            if (!is_array($row) || !self::receiptNeedsAttention($row)
                || !in_array($row['channel'] ?? '', ['desktop', 'audio', 'external_voice', 'sip_notify', 'webhook', 'email', 'sms'], true)) { continue; }
            $channel = $row['channel']; $channels[$channel] = $channels[$channel] ?? [];
            $code = $row['failure_code'] ?? $row['failure_category'] ?? '';
            // Older external receipts retained this fixed code only in their detail.
            if ($code === '' && $channel === 'external_voice' && is_string($row['detail'] ?? null)
                && preg_match('/\((outbound_[a-z0-9_]{1,80})\)/', substr($row['detail'], 0, 1024), $match)) { $code = $match[1]; }
            if (is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $code) && count($channels[$channel]) < 2) {
                $channels[$channel][$code] = $code;
            }
        }
        $rows = [];
        foreach ($channels as $channel => $codes) {
            $rows[] = ['channel' => $channel, 'state' => 'failed', 'failure_code' => array_shift($codes) ?? ''];
            if ($codes) { $rows[] = ['channel' => $channel, 'state' => 'failed', 'failure_code' => array_shift($codes)]; }
        }
        return $rows;
    }

    public static function failureReason($category, array $receipts): string
    {
        if (!in_array($category, ['channel_submission_failed', 'audio_submission_failed', 'sip_notify_submission_failed'], true)) {
            return self::reason($category);
        }
        $labels = ['desktop' => 'Desktop', 'audio' => 'Phone audio', 'external_voice' => 'External voice',
            'sip_notify' => 'Phone display', 'webhook' => 'Webhook', 'email' => 'Email', 'sms' => 'SMS'];
        $channels = [];
        foreach (self::failureChannels($receipts) as $row) { $channels[$row['channel']][] = $row['failure_code']; }
        $parts = [];
        foreach ($channels as $channel => $codes) {
            $codes = array_filter($codes); $parts[] = $labels[$channel] . ($codes ? ' (' . implode(', ', $codes) . ')' : '');
        }
        return $parts ? 'Delivery needs attention: ' . implode('; ', $parts) . '. Open Delivery details for the destination and reason.' : self::reason($category);
    }

    public static function scheduleContext(array $context): array
    {
        $keys = ['version', 'schedule_id', 'occurrence_id', 'run_at_utc', 'deadline_at'];
        if (count($context) !== count($keys) || array_diff(array_keys($context), $keys)
            || ($context['version'] ?? null) !== 1
            || !is_string($context['schedule_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $context['schedule_id'])
            || !is_string($context['occurrence_id'] ?? null) || !preg_match('/^occ_[a-f0-9]{20}$/D', $context['occurrence_id'])
            || !is_string($context['run_at_utc'] ?? null) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $context['run_at_utc'])
            || !is_int($context['deadline_at'] ?? null)) { throw new RuntimeException('Invalid scheduled announcement provenance.'); }
        $due = strtotime($context['run_at_utc']);
        if ($due === false || gmdate('Y-m-d\TH:i:s\Z', $due) !== $context['run_at_utc']
            || $context['deadline_at'] < $due + 60 || $context['deadline_at'] > $due + 900
            || $context['occurrence_id'] !== 'occ_' . substr(hash('sha256', $context['schedule_id'] . '|' . $context['run_at_utc']), 0, 20)) {
            throw new RuntimeException('Invalid scheduled announcement deadline or occurrence identity.');
        }
        return array_combine($keys, array_map(static function ($key) use ($context) { return $context[$key]; }, $keys));
    }

    public static function scheduledId(array $context): string
    {
        $context = self::scheduleContext($context);
        return 'job_' . substr(hash('sha256', json_encode(['sls-scheduled-announcement-v1', $context['schedule_id'],
            $context['occurrence_id'], $context['run_at_utc']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 32);
    }

    public static function requestFingerprint(array $request): string
    {
        $canonical = static function ($value) use (&$canonical) {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) { $item = $canonical($item); }
            unset($item); return $value;
        };
        return hash('sha256', json_encode($canonical($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function validateScheduledJob(array $job): void
    {
        if (!array_key_exists('schedule_context', $job['request'] ?? [])) {
            if (($job['state'] ?? '') === 'prepared') { throw new RuntimeException('Prepared announcement has no schedule provenance.'); }
            return;
        }
        if (!is_array($job['request']['schedule_context'])) { throw new RuntimeException('Invalid scheduled announcement provenance.'); }
        $id = self::scheduledId($job['request']['schedule_context']);
        $isRetry = self::validId($job['request']['retry_of'] ?? null) && ($job['schedule_origin_job_id'] ?? '') === $id;
        if ((!$isRetry && ($job['id'] ?? '') !== $id)
            || !is_string($job['request_fingerprint'] ?? null)
            || !hash_equals(self::requestFingerprint($job['request']), $job['request_fingerprint'])) {
            throw new RuntimeException('Scheduled announcement snapshot identity mismatch.');
        }
    }

    private function path($id)
    {
        if (!self::validId($id)) { throw new RuntimeException('Invalid announcement job identifier.'); }
        return $this->directory . '/' . $id . '.json';
    }

    private function openChecked($path, $mode)
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) {
            throw new RuntimeException('Unsafe announcement state file.');
        }
        $handle = @fopen($path, $mode);
        if (!$handle) { throw new RuntimeException('Announcement state file is unavailable.'); }
        $opened = fstat($handle); clearstatcache(true, $path); $after = @lstat($path);
        $owner = @fileowner($this->directory);
        if (!$after || ($after['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
            || $after['ino'] !== $opened['ino'] || $after['dev'] !== $opened['dev']
            || !in_array($opened['uid'], [0, $owner], true) || ($opened['mode'] & 0022)) {
            fclose($handle); throw new RuntimeException('Unsafe announcement state file.');
        }
        return $handle;
    }

    public function lock($id)
    {
        $this->assertWriter();
        $path = $this->path($id) . '.lock';
        $old = umask(0027);
        try { $handle = $this->openChecked($path, 'c+b'); }
        finally { umask($old); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        // Retention can retire an unlocked inode after this process opens it.
        // Recheck after acquiring the lock so no writer uses a detached inode
        // while another process locks the replacement at the same pathname.
        clearstatcache(true, $path);
        $current = @lstat($path); $opened = fstat($handle);
        if (!$current || !$opened || $opened['nlink'] !== 1 || $current['dev'] !== $opened['dev'] || $current['ino'] !== $opened['ino']) {
            self::unlock($handle); return null;
        }
        $this->ownership($path);
        return $handle;
    }

    /** Shared admission lock; never alias a job lock or accept a supplied path. */
    public function admissionLock()
    {
        return $this->serviceLock('announcement-send.lock');
    }

    public function previewLock()
    {
        return $this->serviceLock('announcement-preview.lock');
    }

    private function serviceLock(string $name)
    {
        $this->assertWriter();
        $path = $this->directory . '/' . $name;
        $old = umask(0027);
        try { $handle = $this->openChecked($path, 'c+b'); }
        finally { umask($old); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        try {
            $meta = fstat($handle);
            if ($meta['uid'] !== fileowner($this->directory) || ($meta['mode'] & 07022) || $meta['size'] > 64) {
                throw new RuntimeException('Unsafe announcement admission lock.');
            }
            $this->ownership($path);
            if (!fsync($handle)) { throw new RuntimeException('Cannot synchronize announcement admission lock.'); }
            $directory = @fopen($this->directory, 'r');
            if (!$directory) { throw new RuntimeException('Cannot synchronize announcement admission directory.'); }
            try { if (!fsync($directory)) { throw new RuntimeException('Cannot synchronize announcement admission directory.'); } }
            finally { fclose($directory); }
            return $handle;
        } catch (Throwable $error) { self::unlock($handle); throw $error; }
    }

    public static function unlock($handle)
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function ownership($path)
    {
        $this->assertWriter();
        if (!chmod($path, 0640)) { throw new RuntimeException('Unable to protect announcement state.'); }
    }

    private function assertWriter()
    {
        // Never perform root pathname mutations in an asterisk-owned tree.
        if (!function_exists('posix_geteuid') || posix_geteuid() !== fileowner($this->directory)) {
            throw new RuntimeException('Announcement state must be written by its runtime account.');
        }
    }

    private function readNamed($path)
    {
        if (!file_exists($path) && !is_link($path)) { return null; }
        $handle = $this->openChecked($path, 'rb');
        try {
            if (fstat($handle)['size'] > self::LIMIT) { throw new RuntimeException('Announcement state is oversized.'); }
            $raw = stream_get_contents($handle, self::LIMIT + 1);
            if (strlen($raw) > self::LIMIT) { throw new RuntimeException('Announcement state is oversized.'); }
            $job = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($job)) { throw new RuntimeException('Announcement state is malformed.'); }
            return $job;
        } finally { fclose($handle); }
    }

    public function read($id)
    {
        $job = $this->readNamed($this->path($id));
        if ($job !== null && ($job['id'] ?? null) !== $id) { throw new RuntimeException('Announcement job identity mismatch.'); }
        if ($job !== null) { self::validateScheduledJob($job); }
        return $job;
    }

    public function atomic($name, array $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->atomicBytes($name, $json);
    }

    public function admissionTimestamp(int $timestamp): void
    {
        if ($timestamp < 0) { throw new RuntimeException('Invalid announcement cooldown timestamp.'); }
        $this->atomicBytes('announcement-cooldown.ts', $timestamp . "\n");
    }

    public function admissionCooldown(): int
    {
        $path = $this->directory . '/announcement-cooldown.ts';
        clearstatcache(true, $path);
        if (@lstat($path) === false) { return 0; }
        $handle = $this->openChecked($path, 'rb');
        try {
            $meta = fstat($handle);
            if ($meta['size'] < 1 || $meta['size'] > 24) { throw new RuntimeException('Announcement cooldown state is corrupt.'); }
            $raw = stream_get_contents($handle, 25);
            if (!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,11})\n?$/D', $raw)) {
                throw new RuntimeException('Announcement cooldown state is corrupt.');
            }
            return (int)$raw;
        } finally { fclose($handle); }
    }

    private function atomicBytes($name, string $json): void
    {
        $this->assertWriter();
        if (basename($name) !== $name || in_array($name, ['.', '..', ''], true)) { throw new RuntimeException('Invalid state filename.'); }
        if (strlen($json) > self::LIMIT) { throw new RuntimeException('Announcement state is oversized.'); }
        $path = $this->directory . '/' . $name;
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            $existing = $this->openChecked($path, 'rb');
            fclose($existing);
        }
        $temp = $this->directory . '/.job-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        try { $handle = $this->openChecked($temp, 'x+b'); }
        finally { umask($old); }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Unable to persist announcement state.');
            }
            $this->ownership($temp);
            if (!rename($temp, $path)) { throw new RuntimeException('Unable to commit announcement state.'); }
            $directory = @fopen($this->directory, 'r');
            if (!$directory) { throw new RuntimeException('Unable to open announcement storage for synchronization.'); }
            try {
                if (!fsync($directory)) { throw new RuntimeException('Unable to synchronize announcement storage.'); }
            } finally { fclose($directory); }
        } finally { fclose($handle); if (is_file($temp)) { unlink($temp); } }
    }

    /** Caller holds the job lock, except for first creation with a random ID. */
    public function write(array $job)
    {
        self::validateScheduledJob($job);
        $path = $this->path($job['id'] ?? null);
        $this->atomic(basename($path), $job);
        $marker = $this->directory . '/pending_' . $job['id'] . '.mark';
        if (is_link($marker)) { throw new RuntimeException('Unsafe announcement queue marker.'); }
        if (in_array($job['state'] ?? '', self::PENDING, true)) {
            if (!file_exists($marker)) {
                $old = umask(0027);
                try { $handle = $this->openChecked($marker, 'x+b'); }
                finally { umask($old); }
                fclose($handle); $this->ownership($marker);
            }
        } elseif (file_exists($marker)) { unlink($marker); }
        $health = ['state' => $job['state'] ?? 'failed', 'updated_at' => gmdate('c'), 'job_id' => $job['id'],
            'finished_at' => $job['finished_at'] ?? '',
            'failure_category' => $job['failure_category'] ?? '',
            'failure_channels' => self::failureChannels($job['receipts'] ?? []),
            'failure_reason' => isset($job['failure_category']) ? self::failureReason($job['failure_category'], $job['receipts'] ?? []) : '',
            'startup_attempted_at' => $job['startup_attempted_at'] ?? '', 'started_at' => $job['started_at'] ?? ''];
        $this->atomic('worker-state.json', $health);
    }

    public function failure(array $job, $category, $uncertain = false)
    {
        if (!isset(self::REASONS[$category])) { $category = 'worker_runtime_failed'; }
        $job['state'] = $category === 'job_expired' ? 'expired' : 'failed';
        $job['failure_category'] = $category; $job['message'] = self::reason($category);
        foreach ($job['receipts'] ?? [] as $index => $row) {
            if (($row['channel'] ?? '') === 'email' && ($row['state'] ?? '') === 'submitting') {
                $job['receipts'][$index]['state'] = 'uncertain';
                $job['receipts'][$index]['retryable'] = false;
                $job['receipts'][$index]['detail'] = 'The email sender stopped without a durable result. Acceptance is uncertain; this destination will not be replayed.';
                $uncertain = true;
            }
        }
        $job['submission_uncertain'] = $uncertain; $job['finished_at'] = gmdate('c');
        unset($job['result']);
        $this->write($job);
        error_log('SLS announcement ' . $job['id'] . ': ' . $category);
        return $job;
    }

    public function fail($id, $category)
    {
        $lock = $this->lock($id); if (!$lock) { return false; }
        try {
            $job = $this->read($id);
            if ($job && in_array($job['state'] ?? '', self::PENDING, true)) {
                $this->failure($job, $category, ($job['state'] ?? '') === 'running');
            }
        } finally { self::unlock($lock); }
        return true;
    }

    public function reconcile($id)
    {
        $lock = $this->lock($id); if (!$lock) { return; }
        try {
            try { $job = $this->read($id); }
            catch (\Throwable $error) {
                $this->removePending($id);
                $this->atomic('worker-state.json', ['state' => 'failed', 'updated_at' => gmdate('c'), 'failure_category' => 'worker_runtime_failed']);
                error_log('SLS announcement ' . $id . ': job_state_unreadable');
                return;
            }
            if (!$job || !in_array($job['state'] ?? '', self::PENDING, true)) { $this->removePending($id); return; }
            $state = $job['state'] ?? '';
            $deadline = $job['request']['schedule_context']['deadline_at'] ?? null;
            if ($state !== 'running' && is_int($deadline) && time() >= $deadline) {
                $this->failure($job, 'job_expired'); return;
            }
            if ($state === 'prepared') { return; }
            $age = time() - (strtotime($job['startup_attempted_at'] ?? $job['created_at'] ?? '') ?: 0);
            if ($state === 'running') {
                // No process owns the processing lock: never replay an interrupted job.
                $this->failure($job, 'worker_runtime_failed', true);
            } elseif (in_array($state, ['queued', 'worker_starting'], true) && $age > 30) {
                $this->failure($job, $state === 'queued' && $age > 900 ? 'job_expired' : 'worker_start_failed');
            }
        } finally { self::unlock($lock); }
    }

    private function removePending($id)
    {
        $this->assertWriter();
        $path = $this->directory . '/pending_' . $id . '.mark';
        if (is_link($path)) { throw new RuntimeException('Unsafe announcement queue marker.'); }
        if (is_file($path)) { unlink($path); }
    }

    public function pendingIds()
    {
        $ids = [];
        foreach (new DirectoryIterator($this->directory) as $file) {
            if (preg_match('/^pending_(job_[a-f0-9]{32})\.mark$/D', $file->getFilename(), $match)) {
                $ids[] = $match[1]; if (count($ids) >= 100) { break; }
            }
        }
        return $ids;
    }

    public function state() { return $this->readNamed($this->directory . '/worker-state.json') ?? []; }
    public function health() { return $this->readNamed($this->directory . '/worker-probe.json') ?? []; }
    public function probeStorage()
    {
        $name = '.probe-' . bin2hex(random_bytes(16));
        $this->atomic($name, ['probe' => true]);
        try { return $this->readNamed($this->directory . '/' . $name) === ['probe' => true]; }
        finally { unlink($this->directory . '/' . $name); }
    }
}
