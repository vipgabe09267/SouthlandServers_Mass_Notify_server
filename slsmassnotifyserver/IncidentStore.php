<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/IncidentConfig.php';
require_once __DIR__ . '/IncidentArchive.php';

/** Bounded operational history with durable claims before any announcement submission. */
class IncidentStore
{
    public const DIRECTORY = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/incidents';
    public const MAX_RECORDS = 2000;
    public const MAX_BYTES = 4194304;
    private string $directory;
    private ?IncidentArchive $archive = null;
    private function restoredReplayBlocked(string $id): bool
    {
        $path = dirname($this->directory) . '/.incident-replay-blocked.json';
        $marker = dirname($this->directory) . '/.incident-replay-required';
        clearstatcache(true, $path); clearstatcache(true, $marker);
        $required = @lstat($marker); $present = @lstat($path);
        if (!$required && !$present) { return false; }
        if (!$required || !$present) { throw new \RuntimeException('Restored incident replay protection is incomplete. Restore its permanent marker and identity ledger before accepting new incident requests.'); }
        $handle = $this->checked($marker, 'r+b');
        try { $valid = stream_get_contents($handle, 32) === "SLS_RESTORED_INCIDENT_REPLAY_V1\n"; } finally { fclose($handle); }
        if (!$valid) { throw new \RuntimeException('Restored incident replay protection has a damaged marker.'); }
        $handle = $this->checked($path, 'r+b');
        try {
            if (fstat($handle)['size'] > self::MAX_BYTES) { throw new \RuntimeException('Restored incident replay history exceeds its protected allocation.'); }
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) { throw new \RuntimeException('Restored incident replay history could not be read within its allocation.'); }
            try { $guard = json_decode($raw, true, 4, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \RuntimeException('Restored incident replay history is damaged. Preserve it for recovery.'); }
            if (!is_array($guard) || count($guard) !== 2 || ($guard['schema'] ?? null) !== 1 || !is_array($guard['ids'] ?? null)
                || !array_is_list($guard['ids']) || count($guard['ids']) > 100000) { throw new \RuntimeException('Restored incident replay history is damaged. Preserve it for recovery.'); }
            $seen = [];
            foreach ($guard['ids'] as $entry) {
                if (!self::validId($entry) || isset($seen[$entry])) { throw new \RuntimeException('Restored incident replay history contains invalid or repeated identifiers.'); }
                $seen[$entry] = true;
            }
            return isset($seen[$id]);
        } finally { fclose($handle); }
    }
    private function archive(bool $create = false): ?IncidentArchive
    {
        $this->directoryMeta();
        $path = $this->directory . '/archive'; $marker = $this->directory . '/.archive-required';
        clearstatcache(true, $path); clearstatcache(true, $marker);
        $required = @lstat($marker); $present = @lstat($path);
        if ($required) {
            if (!$this->regular($required) || $required['size'] !== 24 || !$present) {
                throw new \RuntimeException('The permanent incident archive is missing or unsafe. Restore it before accepting incident requests.');
            }
            $handle = $this->checked($marker, 'r+b');
            try { $valid = stream_get_contents($handle, 25) === "SLS_INCIDENT_ARCHIVE_V1\n"; } finally { fclose($handle); }
            if (!$valid) { throw new \RuntimeException('The permanent incident archive marker is damaged.'); }
        } elseif (!$create) {
            if ($present) { throw new \RuntimeException('Incident archival initialization is incomplete. Preserve the archive and retry the archival worker.'); }
            return null;
        }
        if (!$this->archive) {
            if (!$present) {
                $this->writer();
                if (!@mkdir($path, 0700)) { throw new \RuntimeException('Incident archive storage could not be created.'); }
                if (!$this->syncDirectory()) { throw new \RuntimeException('Incident archive storage could not be synchronized.'); }
            }
            $this->archive = new IncidentArchive($path);
        }
        if (!$required) {
            $this->writer();
            if ($this->archive->listing(1, null)['total'] !== 0) { throw new \RuntimeException('The permanent incident archive marker is missing from populated history. Preserve the archive for recovery.'); }
            $handle = $this->checked($marker, 'x+b');
            try {
                $bytes = "SLS_INCIDENT_ARCHIVE_V1\n";
                if (fwrite($handle, $bytes) !== strlen($bytes) || !$this->syncFile($handle) || !$this->syncDirectory()) {
                    throw new \RuntimeException('The permanent incident archive marker could not be synchronized.');
                }
            } finally { fclose($handle); }
        }
        return $this->archive;
    }
    public function __construct(string $directory = self::DIRECTORY)
    {
        $this->directory = rtrim($directory, '/');
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            if ($this->directory === self::DIRECTORY && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                throw new \RuntimeException('Initialize incident storage as the PBX runtime account.');
            }
            if (!@mkdir($this->directory, 0750) && !is_dir($this->directory)) { throw new \RuntimeException('Incident storage could not be created.'); }
        }
        $this->directoryMeta();
    }
    public static function validId($id): bool { return is_string($id) && (bool)preg_match('/^inc_[a-f0-9]{32}$/D', $id); }
    private function directoryMeta(): array
    {
        clearstatcache(true, $this->directory); $meta = @lstat($this->directory);
        if (!$meta || ($meta['mode'] & 0170000) !== 0040000 || ($meta['mode'] & 0022) || realpath($this->directory) !== $this->directory) {
            throw new \RuntimeException('Incident storage must be a protected local directory without symbolic links.');
        }
        return $meta;
    }
    private function writer(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== $this->directoryMeta()['uid']) {
            throw new \RuntimeException('Incident records must be written by their PBX runtime account.');
        }
    }
    private function path(string $id): string
    {
        if (!self::validId($id)) { throw new \InvalidArgumentException('Invalid incident identifier.'); }
        return $this->directory . '/' . $id . '.json';
    }
    private function regular(array $meta): bool
    {
        return ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1 && !($meta['mode'] & 0022)
            && in_array($meta['uid'], [0, $this->directoryMeta()['uid']], true);
    }
    private function checked(string $path, string $mode)
    {
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before && !$this->regular($before)) { throw new \RuntimeException('Incident state contains an unsafe file or link.'); }
        $old = umask(0077);
        try { $handle = @fopen($path, $mode); } finally { umask($old); }
        if (!$handle) { throw new \RuntimeException('Incident state could not be opened.'); }
        $opened = fstat($handle); clearstatcache(true, $path); $after = @lstat($path);
        if (!$opened || !$after || !$this->regular($opened) || !$this->regular($after)
            || $opened['ino'] !== $after['ino'] || $opened['dev'] !== $after['dev']
            || ($before && ($opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']))) {
            fclose($handle); throw new \RuntimeException('Incident storage identity changed during access.');
        }
        return $handle;
    }
    private function acquire(string $name)
    {
        $this->writer(); $path = $this->directory . '/' . $name . '.lock';
        $handle = $this->checked($path, 'c+b'); $deadline = microtime(true) + .25;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $path); $at = @lstat($path); $opened = fstat($handle);
                if (!$at || !$opened || !$this->regular($at) || $at['ino'] !== $opened['ino'] || $at['dev'] !== $opened['dev']) {
                    fclose($handle); throw new \RuntimeException('Incident lock identity changed.');
                }
                return $handle;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($handle); throw new \RuntimeException('Incident record is busy. Retry the same request identifier.');
    }
    private static function release($handle): void { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    public function read(string $id): ?array
    {
        $path = $this->path($id); $this->directoryMeta();
        // Verify permanent replay history even while the requested record is
        // active. Losing that ledger must not permit a reused start identifier.
        $archive = $this->archive();
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $record = $archive ? $archive->read($id) : null;
            if ($record) { return $record; }
            if ($this->restoredReplayBlocked($id)) {
                throw new \InvalidArgumentException('This incident identifier belongs to restored history and cannot execute again. Review its report in the recovery archive; use a new request for a new incident.');
            }
            return null;
        }
        // r+b does not truncate and opening a substituted FIFO read/write cannot
        // wait for its opposite endpoint. Descriptor checks precede all reads.
        $handle = $this->checked($path, 'r+b');
        try {
            if (fstat($handle)['size'] > self::MAX_BYTES) { throw new \RuntimeException('Incident record exceeds its storage limit.'); }
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) { throw new \RuntimeException('Incident record exceeds its storage limit.'); }
            try { $record = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \RuntimeException('Incident record is damaged; preserve it for recovery.'); }
            if (!is_array($record) || ($record['schema'] ?? null) !== 1 || ($record['id'] ?? '') !== $id
                || !is_array($record['timeline'] ?? null) || !is_array($record['operations'] ?? null)
                || count($record['timeline']) > 4000 || count($record['operations']) > 250) {
                throw new \RuntimeException('Incident record has an unsupported or invalid structure.');
            }
            unset($record['_storage_revision']);
            return $record;
        } finally { fclose($handle); }
    }
    private static function version(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
    private function metadataVersion(string $path, array $meta): array
    {
        $handle = $this->checked($path, 'r+b');
        try {
            $header = fread($handle, 96); $opened = fstat($handle);
            if (!$opened || self::version($opened) !== self::version($meta)) { throw new \RuntimeException('Incident changed during summary lookup.'); }
        } finally { fclose($handle); }
        $revision = is_string($header) && preg_match('/^\{"_storage_revision":"([a-f0-9]{32})",/', $header, $matches) ? $matches[1] : null;
        return self::version($meta) + ['revision' => $revision];
    }
    private static function projection(array $record): array
    {
        $counts = ['expected' => count($record['template']['roster']), 'no_response' => 0, 'received' => 0, 'safe' => 0, 'needs_assistance' => 0, 'missing' => 0];
        foreach ($record['template']['roster'] as $person) { $counts[$record['responses'][$person['id']]['response'] ?? 'no_response']++; }
        $row = array_intersect_key($record, array_flip(['id', 'title', 'severity', 'state', 'is_test', 'created_at', 'updated_at', 'planned_at'])) + ['response_counts' => $counts];
        $due = [];
        foreach ($record['operations'] as $operation) {
            if ($operation['state'] === 'submitting') { $due[] = (strtotime($operation['claimed_at']) ?: 0) + 121; }
        }
        if ($record['state'] === 'planned') { $due[] = strtotime($record['planned_at']) ?: 0; }
        $step = IncidentConfig::nextEscalation($record);
        if ($step !== null) { $due[] = $step['due_at']; }
        return ['row' => $row, 'due_at' => $due ? min($due) : null];
    }
    /** Advisory bounded cache. Full records remain the only mutation/auth source. */
    private function cacheMetadata(array $record, array $version): void
    {
        $this->writer(); $path = $this->directory . '/.' . $record['id'] . '.summary';
        $raw = json_encode(['schema' => 1, 'version' => $version] + self::projection($record), JSON_THROW_ON_ERROR);
        if (strlen($raw) > 8192) { throw new \RuntimeException('Incident summary exceeds its bounded cache size.'); }
        clearstatcache(true, $path); $existing = @lstat($path);
        if ($existing && !$this->regular($existing)) { throw new \RuntimeException('Incident summary cache contains an unsafe file.'); }
        $temp = $this->directory . '/.incident-' . bin2hex(random_bytes(16)); $handle = $this->checked($temp, 'x+b');
        try {
            $offset = 0;
            while ($offset < strlen($raw)) {
                $written = fwrite($handle, substr($raw, $offset));
                if (!is_int($written) || $written < 1) { throw new \RuntimeException('Incident summary cache write failed.'); }
                $offset += $written;
            }
            if (!fflush($handle) || !rename($temp, $path)) { throw new \RuntimeException('Incident summary cache could not be replaced.'); }
        } finally { fclose($handle); if (is_file($temp) && !is_link($temp)) { @unlink($temp); } }
        // No durability dependency: missing/stale caches are rebuilt from the
        // inode-bound record. Never turn an acknowledged durable claim into a
        // retryable send just because an optional cache could not be persisted.
    }
    public function metadata(string $id): array
    {
        $path = $this->path($id); $cache = $this->directory . '/.' . $id . '.summary';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            clearstatcache(true, $path); $before = @lstat($path);
            if (!$before || !$this->regular($before)) { throw new \RuntimeException('Incident record is unavailable or unsafe.'); }
            $version = $this->metadataVersion($path, $before);
            clearstatcache(true, $cache);
            if (file_exists($cache) || is_link($cache)) {
                $handle = $this->checked($cache, 'r+b');
                try {
                    $raw = fstat($handle)['size'] <= 8192 ? stream_get_contents($handle, 8193) : '';
                    $cached = is_string($raw) ? json_decode($raw, true, 16) : null;
                } finally { fclose($handle); }
                if ($version['revision'] !== null && is_array($cached) && ($cached['schema'] ?? null) === 1 && ($cached['version'] ?? null) === $version
                    && is_array($cached['row'] ?? null) && ($cached['row']['id'] ?? '') === $id
                    && count($cached['row']) === 9 && is_array($cached['row']['response_counts'] ?? null)
                    && array_key_exists('due_at', $cached) && ($cached['due_at'] === null || is_int($cached['due_at']))) {
                    return ['row' => $cached['row'], 'due_at' => $cached['due_at']];
                }
            }
            $record = $this->read($id);
            clearstatcache(true, $path); $after = @lstat($path);
            if (!$record || !$after || $this->metadataVersion($path, $after) !== $version) { continue; }
            if ($version['revision'] === null && function_exists('posix_geteuid') && posix_geteuid() === $this->directoryMeta()['uid']) {
                // Upgrade only the storage envelope under the same incident lock.
                // Logical history/idempotency keys stay byte-for-byte equivalent
                // after decoding; no workflow or notification is executed.
                $lock = $this->acquire($id);
                try {
                    $current = $this->read($id);
                    if (!$current) { throw new \RuntimeException('Incident disappeared during summary migration.'); }
                    $this->write($current);
                } finally { self::release($lock); }
                return $this->metadata($id);
            }
            $metadata = self::projection($record);
            try { $this->cacheMetadata($record, $version); } catch (\Throwable $error) { /* Advisory cache only; durable record remains authoritative. */ }
            return $metadata;
        }
        throw new \RuntimeException('Incident changed while its summary was being read. Retry the history request.');
    }
    protected function writeBytes($handle, string $bytes) { return fwrite($handle, $bytes); }
    protected function syncFile($handle): bool { return fflush($handle) && fsync($handle); }
    protected function replace(string $source, string $destination): bool { return rename($source, $destination); }
    protected function syncDirectory(): bool
    {
        $handle = @fopen($this->directory, 'r'); if (!$handle) { return false; }
        try { return fsync($handle); } finally { fclose($handle); }
    }
    private function write(array $record): void
    {
        $this->writer(); $path = $this->path($record['id'] ?? '');
        $revision = bin2hex(random_bytes(16));
        $raw = json_encode(['_storage_revision' => $revision] + $record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($raw) > self::MAX_BYTES || count($record['timeline'] ?? []) > 4000 || count($record['operations'] ?? []) > 250) {
            throw new \RuntimeException('Incident history is full. Export the report and begin a new incident; existing history was preserved.');
        }
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before && !$this->regular($before)) { throw new \RuntimeException('Incident destination is unsafe.'); }
        $temp = $this->directory . '/.incident-' . bin2hex(random_bytes(16)); $handle = $this->checked($temp, 'x+b');
        try {
            $offset = 0;
            while ($offset < strlen($raw)) {
                $count = $this->writeBytes($handle, substr($raw, $offset));
                if (!is_int($count) || $count <= 0) { throw new \RuntimeException('Incident storage could not complete its write.'); }
                $offset += $count;
            }
            if (!$this->syncFile($handle)) { throw new \RuntimeException('Incident storage could not synchronize its write.'); }
            clearstatcache(true, $path); $at = @lstat($path);
            if (($before === false) !== ($at === false) || ($at && (!$this->regular($at) || $at['ino'] !== $before['ino'] || $at['dev'] !== $before['dev']))) {
                throw new \RuntimeException('Incident destination changed before commit.');
            }
            if (!$this->replace($temp, $path)) { throw new \RuntimeException('Incident storage could not replace its record.'); }
            if (!$this->syncDirectory()) { throw new \RuntimeException('Incident write was replaced but directory synchronization failed. Retry the same request identifier; do not create another send.'); }
            clearstatcache(true, $path); $committed = @lstat($path);
            if ($committed) { try { $this->cacheMetadata($record, self::version($committed) + ['revision' => $revision]); } catch (\Throwable $error) { /* Derived cache cannot change durable operation outcome. */ } }
        } finally { fclose($handle); if (is_file($temp) && !is_link($temp)) { @unlink($temp); } }
    }
    public function transaction(string $id, callable $operation, bool $create = false): array
    {
        $this->path($id);
        if (!$create && $this->read($id) === null) { throw new \InvalidArgumentException('Incident was not found.'); }
        $global = $create ? $this->acquire('admission') : null; $lock = null;
        try {
            $existing = $this->read($id);
            if (!empty($existing['archived'])) {
                $next = $operation($existing);
                if ($next !== $existing) { throw new \InvalidArgumentException('This incident is archived and read-only. Its report and previous request identities remain available.'); }
                return $existing;
            }
            if ($create && $this->read($id) === null && count($this->ids()) >= self::MAX_RECORDS) {
                throw new \RuntimeException('Incident history reached 2000 records. Preserve/export history and arrange retention before opening another incident.');
            }
            $lock = $this->acquire($id); $record = $this->read($id);
            if (!$create && $record === null) { throw new \InvalidArgumentException('Incident was not found.'); }
            $next = $operation($record);
            if (!is_array($next) || ($next['id'] ?? '') !== $id) { throw new \LogicException('Incident transaction returned an invalid record.'); }
            if ($next !== $record) {
                if (!empty($record['archived'])) { throw new \InvalidArgumentException('This incident is archived and read-only. Its report and previous request identities remain available.'); }
                $this->write($next);
            }
            return $next;
        } finally { self::release($lock); self::release($global); }
    }
    public function ids(): array
    {
        $this->directoryMeta(); $ids = []; $scanned = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) { continue; }
            if (++$scanned > 6500) { throw new \RuntimeException('Incident storage scan exceeded its safe limit; results are incomplete.'); }
            if (preg_match('/^(inc_[a-f0-9]{32})\.json$/D', $file->getFilename(), $matches)) { $ids[] = $matches[1]; }
        }
        if (count($ids) > self::MAX_RECORDS) { throw new \RuntimeException('Incident history exceeds its supported record limit.'); }
        return $ids;
    }
    public function archivedListing(int $limit, ?array $after): array
    {
        $archive = $this->archive();
        return $archive ? $archive->listing($limit, $after) : ['rows' => [], 'total' => 0];
    }
    protected function archiveCommitted(string $id): void { /* Interruption qualification hook; no production action. */ }
    protected function activeCopyRetired(string $id): void { /* Interruption qualification hook; no production action. */ }
    private function retiredOrphans(IncidentArchive $archive, float $deadline): int
    {
        $removed = 0; $scanned = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) { continue; }
            if (++$scanned > 25000 || $removed >= 10 || microtime(true) >= $deadline) { break; }
            if (!preg_match('/^\.?(inc_[a-f0-9]{32})\.(lock|summary)$/D', $file->getFilename(), $match)) { continue; }
            $expected = $match[2] === 'summary' ? '.' . $match[1] . '.summary' : $match[1] . '.lock';
            if ($file->getFilename() !== $expected) { continue; }
            clearstatcache(true, $file->getPathname()); $candidate = @lstat($file->getPathname());
            if (!$candidate) { continue; }
            $id = $match[1]; $path = $this->path($id); clearstatcache(true, $path);
            if (file_exists($path) || is_link($path) || !$archive->contains($id)) { continue; }
            if (!$this->regular($candidate)) { throw new \RuntimeException('Retired incident cache or lock contains an unsafe file or link.'); }
            $lock = $this->acquire($id);
            try {
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) { continue; }
                foreach (['/.' . $id . '.summary', '/' . $id . '.lock'] as $suffix) {
                    $stale = $this->directory . $suffix; clearstatcache(true, $stale); $at = @lstat($stale);
                    if ($at && (!$this->regular($at) || !unlink($stale))) { throw new \RuntimeException('Retired incident cache or lock cleanup failed. Inspect storage permissions.'); }
                }
                if (!$this->syncDirectory()) { throw new \RuntimeException('Retired incident orphan cleanup could not be synchronized. Replay history remains archived.'); }
                $removed++;
            } finally { self::release($lock); }
        }
        return $removed;
    }
    /** Bounded minute cleanup. Never deletes a report or a replay identity. */
    public function retire(array $policy, int $now): array
    {
        $policy = IncidentConfig::retention($policy);
        $out = ['enabled' => $policy['enabled'], 'retired' => 0, 'errors' => []];
        if (!$policy['enabled']) { return $out; }
        $this->writer(); $deadline = microtime(true) + 3; $cutoff = $now - $policy['after_days'] * 86400;
        // Initialization and retirement use the same admission lock as starts.
        $global = $this->acquire('admission');
        try {
            $archive = $this->archive(true);
            $out['orphan_pairs_removed'] = $this->retiredOrphans($archive, $deadline);
            foreach ($this->ids() as $id) {
                if ($out['retired'] >= 10 || microtime(true) >= $deadline) { $out['more_pending'] = true; break; }
                $lock = null;
                try {
                    $row = $this->metadata($id)['row'];
                    if (!in_array($row['state'], ['closed', 'missed'], true) || (strtotime($row['updated_at']) ?: PHP_INT_MAX) > $cutoff) { continue; }
                    $lock = $this->acquire($id); $record = $this->read($id);
                    if (!$record || !empty($record['archived']) || !in_array($record['state'], ['closed', 'missed'], true)
                        || (strtotime($record['updated_at']) ?: PHP_INT_MAX) > $cutoff) { continue; }
                    // Unresolved submission evidence requires explicit review;
                    // retirement cannot make an uncertain send look terminal.
                    if (array_filter($record['operations'], static fn(array $op): bool => in_array($op['state'], ['submitting', 'uncertain'], true))) { continue; }
                    $archive->put($record, self::projection($record)['row'], gmdate('c', $now), $policy['max_archive_mib']);
                    $this->archiveCommitted($id);
                    $verified = $archive->read($id); unset($verified['archived'], $verified['archived_at']);
                    if ($verified !== $record || $this->read($id) !== $record) {
                        throw new \RuntimeException('The archived and active incident failed their final consistency check. Both copies were preserved.');
                    }
                    $path = $this->path($id); $at = @lstat($path);
                    if (!$at || !$this->regular($at) || !unlink($path) || !$this->syncDirectory()) {
                        throw new \RuntimeException('An incident report was archived but its active copy could not be retired. The next pass will verify both copies before retrying.');
                    }
                    $this->activeCopyRetired($id);
                    // Waiters verify lock inode identity after taking flock;
                    // removing a retired lock cannot create a second writer.
                    foreach (['/.' . $id . '.summary', '/' . $id . '.lock'] as $suffix) {
                        $path = $this->directory . $suffix; $at = @lstat($path);
                        if ($at && (!$this->regular($at) || !unlink($path))) { throw new \RuntimeException('Retired incident cache or lock cleanup failed. Preserve the archive and inspect storage permissions.'); }
                    }
                    if (!$this->syncDirectory()) { throw new \RuntimeException('Retired incident cleanup could not be synchronized. Replay history remains archived.'); }
                    $out['retired']++;
                } catch (\Throwable $error) { $out['errors'][] = ['incident_id' => $id, 'message' => $error->getMessage()]; break; }
                finally { self::release($lock); }
            }
        } finally { self::release($global); }
        return $out;
    }
}
