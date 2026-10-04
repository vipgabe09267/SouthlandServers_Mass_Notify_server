<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Immutable reports also retain every retired incident's replay identity. */
final class IncidentArchive
{
    public const MAX_RECORDS = 20000;
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
                        $bytes = "SLS_INCIDENT_ARCHIVE_V1\n";
                        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                            throw new \RuntimeException('The incident archive initialization could not be synchronized.');
                        }
                    } finally { fclose($handle); }
                }
                if (file_get_contents($this->directory . '/initialized') !== "SLS_INCIDENT_ARCHIVE_V1\n") {
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
            if (!$opened || $at['ino'] !== $opened['ino'] || $at['dev'] !== $opened['dev']
                || ($before && ($at['ino'] !== $before['ino'] || $at['dev'] !== $before['dev']))) {
                throw new \RuntimeException('The incident archive lock changed during access.');
            }
            foreach (['-journal', '-wal', '-shm'] as $suffix) { $this->file($this->directory . '/reports.sqlite' . $suffix, true); }
            if (isset($this->databaseIdentity)) {
                $at = $this->file($this->directory . '/reports.sqlite');
                if ($at['ino'] !== $this->databaseIdentity['ino'] || $at['dev'] !== $this->databaseIdentity['dev']) {
                    throw new \RuntimeException('The incident archive database changed during access.');
                }
                if ($this->file($this->directory . '/initialized')['size'] !== 24
                    || file_get_contents($this->directory . '/initialized') !== "SLS_INCIDENT_ARCHIVE_V1\n") {
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
            $row = $this->query('SELECT body,digest,raw_bytes,retired_at FROM reports WHERE id=?', [$id])->fetch();
            if ($row === false) { return null; }
            if ($row['raw_bytes'] < 1 || $row['raw_bytes'] > IncidentStore::MAX_BYTES || strlen($row['body']) > IncidentStore::MAX_BYTES) {
                throw new \RuntimeException('An archived incident exceeds its verified size limit.');
            }
            $raw = @gzdecode($row['body'], IncidentStore::MAX_BYTES);
            if (!is_string($raw) || strlen($raw) !== (int)$row['raw_bytes'] || !hash_equals($row['digest'], hash('sha256', $raw))) {
                throw new \RuntimeException('An archived incident failed its integrity check. Preserve the archive for recovery.');
            }
            $record = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['id'] ?? '') !== $id || ($record['schema'] ?? null) !== 1) {
                throw new \RuntimeException('An archived incident has an invalid identity or schema.');
            }
            return $record + ['archived' => true, 'archived_at' => $row['retired_at']];
        });
    }
    public function put(array $record, array $summary, string $at, int $maxMiB): void
    {
        if (posix_geteuid() !== $this->directoryIdentity['uid']) { throw new \RuntimeException('Only the PBX runtime account may retire incidents.'); }
        $raw = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($raw) > IncidentStore::MAX_BYTES) { throw new \RuntimeException('The incident is too large to archive safely.'); }
        $packed = gzencode($raw, 6);
        if (!is_string($packed)) { throw new \RuntimeException('The incident report could not be compressed.'); }
        $free = @disk_free_space($this->directory);
        if ((!is_int($free) && !is_float($free)) || $free < max(67108864, strlen($packed) * 2 + 1048576)) {
            throw new \RuntimeException('Incident archival needs at least 64 MiB of free workspace for its database and rollback journal. Active reports were preserved; free storage before retrying.');
        }
        $this->locked(function () use ($record, $summary, $at, $maxMiB, $raw, $packed): void {
            $mask = umask(0077); $this->db->exec('BEGIN IMMEDIATE');
            try {
                $digest = hash('sha256', $raw);
                $old = $this->query('SELECT digest FROM reports WHERE id=?', [$record['id']])->fetchColumn();
                if ($old !== false) {
                    if (!hash_equals($old, $digest)) { throw new \RuntimeException('An archived and active incident disagree. Preserve both copies for recovery.'); }
                } else {
                    $size = $this->file($this->directory . '/reports.sqlite')['size'];
                    // Leave room for SQLite pages and its rollback journal.
                    if ((int)$this->db->query('SELECT count(*) FROM reports')->fetchColumn() >= self::MAX_RECORDS
                        || $size + strlen($packed) + strlen(json_encode($summary)) + 32768 > $maxMiB * 1048576) {
                        throw new \RuntimeException('The incident archive is full. Increase its allocation or export and plan an offline migration; reports and replay identities were preserved.');
                    }
                    $statement = $this->db->prepare('INSERT INTO reports VALUES (?,?,?,?,?,?,?,?)');
                    foreach ([$record['id'], $record['created_at'], $at, $packed, $digest, strlen($raw), strlen($packed), json_encode($summary, JSON_THROW_ON_ERROR)] as $index => $value) {
                        $statement->bindValue($index + 1, $value, $index === 3 ? \PDO::PARAM_LOB : (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR));
                    }
                    $statement->execute();
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
            finally { umask($mask); }
            $this->syncDirectory();
        });
    }
    public function listing(int $limit, ?array $after): array
    {
        return $this->locked(function () use ($limit, $after): array {
            $where = $after ? ' WHERE created_at<? OR (created_at=? AND id<?)' : '';
            $values = $after ? [$after['created_at'], $after['created_at'], $after['id']] : [];
            $rows = $this->query('SELECT id,summary,retired_at FROM reports' . $where . ' ORDER BY created_at DESC,id DESC LIMIT ' . ($limit + 1), $values)->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                if (strlen($row['summary']) > 8192) { throw new \RuntimeException('The archived incident summary is too large.'); }
                $summary = json_decode($row['summary'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($summary) || ($summary['id'] ?? '') !== $row['id']) { throw new \RuntimeException('The archived incident summary has an invalid identity.'); }
                $out[] = $summary + ['archived' => true, 'archived_at' => $row['retired_at']];
            }
            return ['rows' => $out, 'total' => (int)$this->db->query('SELECT count(*) FROM reports')->fetchColumn()];
        });
    }
    public function contains(string $id): bool
    {
        if (!IncidentStore::validId($id)) { throw new \InvalidArgumentException('Invalid incident identifier.'); }
        return $this->locked(fn(): bool => $this->query('SELECT 1 FROM reports WHERE id=?', [$id])->fetchColumn() !== false);
    }
}
