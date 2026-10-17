<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Immutable reports also retain every retired incident's replay identity. */
final class IncidentArchive
{
    public const MAX_RECORDS = 20000;
    private const MARKER = "SLS_INCIDENT_ARCHIVE_V1\n";
    private string $directory;
    private array $directoryIdentity;
    private array $databaseIdentity;
    private \PDO $db;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->directoryIdentity = $this->directoryMeta();
        if (!extension_loaded('pdo_sqlite') || !function_exists('gzencode')) {
            throw new \RuntimeException('Incident archival requires PHP SQLite and zlib support. Existing records were preserved.');
        }
        $this->locked(function (): void {
            $path = $this->directory . '/reports.sqlite';
            $exists = $this->file($path, true);
            $marker = $this->file($this->directory . '/initialized', true);
            if ($marker && !$exists) { throw new \RuntimeException('The incident archive database is missing. Restore it before allowing incident requests.'); }
            $mask = umask(0077);
            try {
                if (!$exists) {
                    $handle = @fopen($path, 'x+b');
                    if (!$handle) { throw new \RuntimeException('The incident archive could not be created.'); }
                    fclose($handle);
                }
                $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
                $this->db->exec('PRAGMA busy_timeout=250; PRAGMA trusted_schema=OFF; PRAGMA journal_mode=DELETE; PRAGMA synchronous=EXTRA;');
                $version = (int)$this->db->query('PRAGMA user_version')->fetchColumn();
                if ($version === 0 && !$marker && (!$exists || $exists['size'] === 0)) {
                    $this->db->exec('BEGIN IMMEDIATE');
                    try {
                        $this->db->exec('CREATE TABLE reports (id TEXT PRIMARY KEY, created_at TEXT NOT NULL, retired_at TEXT NOT NULL,
                            body BLOB NOT NULL, digest TEXT NOT NULL, raw_bytes INTEGER NOT NULL, packed_bytes INTEGER NOT NULL, summary TEXT NOT NULL);
                            CREATE INDEX report_order ON reports(created_at DESC,id DESC); PRAGMA user_version=1;');
                        $this->db->exec('COMMIT');
                    } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
                    $version = 1;
                }
                if ($version !== 1) { throw new \RuntimeException('The incident archive is damaged or uses an unsupported schema. Preserve it for recovery.'); }
                if (!$marker) {
                    // Recover an interrupted first initialization only while
                    // empty; a missing marker on populated history is a fault.
                    if ((int)$this->db->query('SELECT count(*) FROM reports')->fetchColumn() !== 0) {
                        throw new \RuntimeException('The incident archive initialization marker is missing. Preserve the database for recovery.');
                    }
                    $handle = @fopen($this->directory . '/initialized', 'x+b');
                    if (!$handle) { throw new \RuntimeException('The incident archive initialization could not be confirmed.'); }
                    try {
                        if (!$this->writeAll($handle, self::MARKER) || !fflush($handle) || !fsync($handle)) {
                            throw new \RuntimeException('The incident archive initialization could not be synchronized.');
                        }
                    } finally { fclose($handle); }
                }
                if ($this->readSmallFile($this->directory . '/initialized', strlen(self::MARKER)) !== self::MARKER) {
                    throw new \RuntimeException('The incident archive initialization marker is damaged.');
                }
                $this->databaseIdentity = $this->file($path);
                $this->syncDirectory();
            } finally { umask($mask); }
        });
    }
    private function directoryMeta(): array
    {
        clearstatcache(true, $this->directory); $meta = @lstat($this->directory);
        if (!$meta || ($meta['mode'] & 0170000) !== 0040000 || ($meta['mode'] & 0077)
            || realpath($this->directory) !== $this->directory || !function_exists('posix_geteuid')
            || !in_array(posix_geteuid(), [0, $meta['uid']], true)) {
            throw new \RuntimeException('The incident archive must be a private local directory owned by the PBX runtime account.');
        }
        return $meta;
    }
    private function file(string $path, bool $optional = false): ?array
    {
        clearstatcache(true, $path); $meta = @lstat($path);
        if (!$meta && $optional) { return null; }
        if (!$meta || ($meta['mode'] & 0170000) !== 0100000 || $meta['nlink'] !== 1 || ($meta['mode'] & 0077)
            || $meta['uid'] !== $this->directoryIdentity['uid'] || $meta['size'] > 4294967296) {
            throw new \RuntimeException('The incident archive contains an unsafe file, link, ownership or permission.');
        }
        return $meta;
    }
    private function writeAll($handle, string $bytes): bool
    {
        $offset = 0; $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset, 1048576));
            if (!is_int($written) || $written < 1) { return false; }
            $offset += $written;
        }
        return true;
    }
    private function readSmallFile(string $path, int $expectedBytes): string
    {
        $before = $this->file($path);
        if ($before['size'] !== $expectedBytes) { throw new \RuntimeException('The incident archive initialization marker is damaged.'); }
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new \RuntimeException('The incident archive initialization marker could not be read.'); }
        try {
            $opened = fstat($handle); clearstatcache(true, $path); $current = @lstat($path);
            if (!$opened || !$current || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || $current['dev'] !== $before['dev'] || $current['ino'] !== $before['ino']) {
                throw new \RuntimeException('The incident archive initialization marker changed during access.');
            }
            $raw = stream_get_contents($handle, $expectedBytes + 1);
            if (!is_string($raw) || strlen($raw) !== $expectedBytes || !feof($handle)) {
                throw new \RuntimeException('The incident archive initialization marker is damaged.');
            }
            return $raw;
        } finally { fclose($handle); }
    }
    private static function utcTimestamp($value, string $label): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $value)) {
            throw new \RuntimeException($label . ' has an invalid timestamp.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('c') !== $value) {
            throw new \RuntimeException($label . ' has an invalid timestamp.');
        }
        return $value;
    }
    private function syncDirectory(): void
    {
        $handle = @fopen($this->directory, 'r');
        try { if (!$handle || !fsync($handle)) { throw new \RuntimeException('The incident archive directory could not be synchronized.'); } }
        finally { if ($handle) { fclose($handle); } }
    }
    private function locked(callable $work)
    {
        $current = $this->directoryMeta();
        if ($current['ino'] !== $this->directoryIdentity['ino'] || $current['dev'] !== $this->directoryIdentity['dev']) {
            throw new \RuntimeException('The incident archive directory changed during access.');
        }
        $path = $this->directory . '/storage.lock'; $before = $this->file($path, true); $mask = umask(0077);
        try { $handle = @fopen($path, 'c+b'); } finally { umask($mask); }
        if (!$handle) { throw new \RuntimeException('The incident archive lock could not be opened.'); }
        try {
            $deadline = microtime(true) + .25;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) { throw new \RuntimeException('The incident archive is busy. Retry the same request identifier.'); }
                usleep(10000);
            }
            $at = $this->file($path); $opened = fstat($handle);
            if (!$opened || $opened['size'] !== 0 || $at['size'] !== 0 || $at['ino'] !== $opened['ino'] || $at['dev'] !== $opened['dev']
                || ($before && ($at['ino'] !== $before['ino'] || $at['dev'] !== $before['dev']))) {
                throw new \RuntimeException('The incident archive lock changed during access.');
            }
            foreach (['-journal', '-wal', '-shm'] as $suffix) { $this->file($this->directory . '/reports.sqlite' . $suffix, true); }
            if (isset($this->databaseIdentity)) {
                $at = $this->file($this->directory . '/reports.sqlite');
                if ($at['ino'] !== $this->databaseIdentity['ino'] || $at['dev'] !== $this->databaseIdentity['dev']) {
                    throw new \RuntimeException('The incident archive database changed during access.');
                }
                if ($this->readSmallFile($this->directory . '/initialized', strlen(self::MARKER)) !== self::MARKER) {
                    throw new \RuntimeException('The incident archive initialization marker is damaged.');
                }
            }
            return $work();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private function query(string $sql, array $values = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql); $statement->execute($values); return $statement;
    }
    public function read(string $id): ?array
    {
        if (!IncidentStore::validId($id)) { throw new \InvalidArgumentException('Invalid incident identifier.'); }
        return $this->locked(function () use ($id): ?array {
            $row = $this->query('SELECT created_at,body,digest,raw_bytes,retired_at FROM reports WHERE id=?', [$id])->fetch();
            if ($row === false) { return null; }
            if (!is_string($row['body'] ?? null) || !is_string($row['digest'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['digest']) || !is_int($row['raw_bytes'] ?? null)
                || $row['raw_bytes'] < 1 || $row['raw_bytes'] > IncidentStore::MAX_BYTES
                || strlen($row['body']) > IncidentStore::MAX_BYTES) {
                throw new \RuntimeException('An archived incident exceeds its verified size limit.');
            }
            $createdAt = self::utcTimestamp($row['created_at'] ?? null, 'The archived incident');
            $retiredAt = self::utcTimestamp($row['retired_at'] ?? null, 'The archived incident retirement');
            $raw = @gzdecode($row['body'], IncidentStore::MAX_BYTES);
            if (!is_string($raw) || strlen($raw) !== $row['raw_bytes'] || !hash_equals($row['digest'], hash('sha256', $raw))) {
                throw new \RuntimeException('An archived incident failed its integrity check. Preserve the archive for recovery.');
            }
            try { $record = ApiSecurity::jsonObject($raw); }
            catch (\Throwable $error) { throw new \RuntimeException('An archived incident contains ambiguous or invalid JSON. Preserve the archive for recovery.', 0, $error); }
            if (!is_array($record) || ($record['id'] ?? '') !== $id || ($record['schema'] ?? null) !== 1
                || ($record['created_at'] ?? null) !== $createdAt || strcmp($retiredAt, $createdAt) < 0) {
                throw new \RuntimeException('An archived incident has an invalid identity or schema.');
            }
            return $record + ['archived' => true, 'archived_at' => $retiredAt];
        });
    }
    public function put(array $record, array $summary, string $at, int $maxMiB): void
    {
        if (posix_geteuid() !== $this->directoryIdentity['uid']) { throw new \RuntimeException('Only the PBX runtime account may retire incidents.'); }
        if ($maxMiB < 64 || $maxMiB > 4096 || !IncidentStore::validId($record['id'] ?? null)
            || ($record['schema'] ?? null) !== 1 || ($summary['id'] ?? null) !== $record['id']) {
            throw new \InvalidArgumentException('Incident archive input is invalid.');
        }
        $createdAt = self::utcTimestamp($record['created_at'] ?? null, 'The incident');
        $retiredAt = self::utcTimestamp($at, 'The incident retirement');
        if (($summary['created_at'] ?? null) !== $createdAt || strcmp($retiredAt, $createdAt) < 0) {
            throw new \InvalidArgumentException('Incident archive timestamps are inconsistent.');
        }
        $raw = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($raw) > IncidentStore::MAX_BYTES) { throw new \RuntimeException('The incident is too large to archive safely.'); }
        $summaryRaw = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($summaryRaw) > 8192) { throw new \RuntimeException('The incident summary is too large to archive safely.'); }
        $packed = gzencode($raw, 6);
        if (!is_string($packed)) { throw new \RuntimeException('The incident report could not be compressed.'); }
        $free = @disk_free_space($this->directory);
        if ((!is_int($free) && !is_float($free)) || $free < max(67108864, strlen($packed) * 2 + 1048576)) {
            throw new \RuntimeException('Incident archival needs at least 64 MiB of free workspace for its database and rollback journal. Active reports were preserved; free storage before retrying.');
        }
        $this->locked(function () use ($record, $createdAt, $retiredAt, $maxMiB, $raw, $summaryRaw, $packed): void {
            $mask = umask(0077); $transaction = false;
            try {
                $this->db->exec('BEGIN IMMEDIATE'); $transaction = true;
                $digest = hash('sha256', $raw);
                $old = $this->query('SELECT digest FROM reports WHERE id=?', [$record['id']])->fetchColumn();
                if ($old !== false) {
                    if (!is_string($old) || !preg_match('/^[a-f0-9]{64}$/D', $old) || !hash_equals($old, $digest)) { throw new \RuntimeException('An archived and active incident disagree. Preserve both copies for recovery.'); }
                } else {
                    $size = $this->file($this->directory . '/reports.sqlite')['size'];
                    // Leave room for SQLite pages and its rollback journal.
                    if ((int)$this->db->query('SELECT count(*) FROM reports')->fetchColumn() >= self::MAX_RECORDS
                        || $size + strlen($packed) + strlen($summaryRaw) + 32768 > $maxMiB * 1048576) {
                        throw new \RuntimeException('The incident archive is full. Increase its allocation or export and plan an offline migration; reports and replay identities were preserved.');
                    }
                    $statement = $this->db->prepare('INSERT INTO reports VALUES (?,?,?,?,?,?,?,?)');
                    foreach ([$record['id'], $createdAt, $retiredAt, $packed, $digest, strlen($raw), strlen($packed), $summaryRaw] as $index => $value) {
                        $statement->bindValue($index + 1, $value, $index === 3 ? \PDO::PARAM_LOB : (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR));
                    }
                    $statement->execute();
                }
                $this->db->exec('COMMIT'); $transaction = false;
            } catch (\Throwable $error) {
                if ($transaction) {
                    try { $this->db->exec('ROLLBACK'); }
                    catch (\Throwable $rollback) { throw new \RuntimeException('The incident archive transaction and rollback both failed. Preserve the database for recovery.', 0, $error); }
                }
                throw $error;
            }
            finally { umask($mask); }
            $this->syncDirectory();
        });
    }
    public function listing(int $limit, ?array $after): array
    {
        if ($limit < 1 || $limit > 200) { throw new \InvalidArgumentException('Incident archive list limit must be 1–200.'); }
        if ($after !== null) {
            $keys = array_keys($after); sort($keys);
            if (($keys !== ['created_at', 'id'] && $keys !== ['archived', 'created_at', 'id'])
                || (array_key_exists('archived', $after) && $after['archived'] !== true)
                || !IncidentStore::validId($after['id'] ?? null)) {
                throw new \InvalidArgumentException('Incident archive cursor is invalid.');
            }
            self::utcTimestamp($after['created_at'] ?? null, 'The incident archive cursor');
        }
        return $this->locked(function () use ($limit, $after): array {
            $where = $after ? ' WHERE created_at<? OR (created_at=? AND id<?)' : '';
            $values = $after ? [$after['created_at'], $after['created_at'], $after['id']] : [];
            $rows = $this->query('SELECT id,summary,retired_at FROM reports' . $where . ' ORDER BY created_at DESC,id DESC LIMIT ' . ($limit + 1), $values)->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                if (!is_string($row['id'] ?? null) || !IncidentStore::validId($row['id'])
                    || !is_string($row['summary'] ?? null) || strlen($row['summary']) > 8192) { throw new \RuntimeException('The archived incident summary is too large or invalid.'); }
                $retiredAt = self::utcTimestamp($row['retired_at'] ?? null, 'The archived incident retirement');
                try { $summary = ApiSecurity::jsonObject($row['summary']); }
                catch (\Throwable $error) { throw new \RuntimeException('The archived incident summary contains ambiguous or invalid JSON. Preserve the archive for recovery.', 0, $error); }
                if (!is_array($summary) || ($summary['id'] ?? '') !== $row['id']) { throw new \RuntimeException('The archived incident summary has an invalid identity.'); }
                $out[] = $summary + ['archived' => true, 'archived_at' => $retiredAt];
            }
            $total = $this->db->query('SELECT count(*) FROM reports')->fetchColumn();
            if (!is_int($total) || $total < 0 || $total > self::MAX_RECORDS) { throw new \RuntimeException('The incident archive count is invalid.'); }
            return ['rows' => $out, 'total' => $total];
        });
    }
    public function contains(string $id): bool
    {
        if (!IncidentStore::validId($id)) { throw new \InvalidArgumentException('Invalid incident identifier.'); }
        return $this->locked(fn(): bool => $this->query('SELECT 1 FROM reports WHERE id=?', [$id])->fetchColumn() !== false);
    }
}
