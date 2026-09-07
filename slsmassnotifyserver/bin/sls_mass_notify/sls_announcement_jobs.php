<?php
/** Bootstrap-independent announcement state. Never loads config or sends alerts. */
final class SlsAnnouncementJobStore
{
    const DATA = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/announcement-jobs';
    const LIMIT = 2097152;
    const PENDING = ['queued', 'worker_starting', 'running'];
    const REASONS = [
        'worker_start_failed' => 'The announcement worker did not start or claim its job. Run the general announcement worker health check.',
        'worker_bootstrap_failed' => 'The announcement worker could not bootstrap FreePBX. Check PHP CLI and FreePBX configuration.',
        'worker_module_load_failed' => 'The announcement worker could not load Mass Notify. Run protected repair and check module status.',
        'worker_runtime_failed' => 'The announcement worker stopped before confirming all results. Review channel receipts before sending again.',
        'worker_timeout' => 'The announcement worker exceeded its execution deadline. Review channel receipts before sending again.',
        'announcement_activity_timeout' => 'Protected configuration maintenance prevented announcement startup. No channels were submitted.',
        'audio_submission_failed' => 'One or more audio page submissions failed. Review the audio channel receipts.',
        'sip_notify_submission_failed' => 'One or more SIP NOTIFY submissions failed. Review the phone visual channel receipts.',
        'channel_submission_failed' => 'One or more requested channels failed. Review the channel receipts.',
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
        $this->ownership($path);
        return $handle;
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
        return $job;
    }

    public function atomic($name, array $data)
    {
        $this->assertWriter();
        if (basename($name) !== $name) { throw new RuntimeException('Invalid state filename.'); }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($json) > self::LIMIT) { throw new RuntimeException('Announcement state is oversized.'); }
        $path = $this->directory . '/' . $name;
        if (is_link($path)) { throw new RuntimeException('Unsafe announcement state destination.'); }
        $temp = $this->directory . '/.job-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        try { $handle = $this->openChecked($temp, 'x+b'); }
        finally { umask($old); }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Unable to persist announcement state.');
            }
            $this->ownership($temp);
            if (!rename($temp, $path)) { throw new RuntimeException('Unable to commit announcement state.'); }
        } finally { fclose($handle); if (is_file($temp)) { unlink($temp); } }
    }

    /** Caller holds the job lock, except for first creation with a random ID. */
    public function write(array $job)
    {
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
        $health = ['state' => $job['state'] ?? 'failed', 'updated_at' => gmdate('c'),
            'failure_category' => $job['failure_category'] ?? '',
            'failure_reason' => isset($job['failure_category']) ? self::reason($job['failure_category']) : '',
            'startup_attempted_at' => $job['startup_attempted_at'] ?? '', 'started_at' => $job['started_at'] ?? ''];
        $this->atomic('worker-state.json', $health);
    }

    public function failure(array $job, $category, $uncertain = false)
    {
        if (!isset(self::REASONS[$category])) { $category = 'worker_runtime_failed'; }
        $job['state'] = $category === 'job_expired' ? 'expired' : 'failed';
        $job['failure_category'] = $category; $job['message'] = self::reason($category);
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
