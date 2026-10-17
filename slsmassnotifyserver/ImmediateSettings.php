<?php
declare(strict_types=1);
namespace SLS\MassNotify;
if (!class_exists('FreePBX\\modules\\SlsConfigCrypto', false)) {
    require_once __DIR__ . '/bin/sls_mass_notify/sls_config_crypto.php';
}
if (!class_exists('SlsAtomicJson', false)) {
    require_once __DIR__ . '/bin/sls_mass_notify/sls_atomic_json.php';
}

/** Keep a partial active/pending commit from reverting an immediate decision. */
final class ImmediateSettings
{
    private const TRANSACTION = '.sls-immediate-settings-transaction.json';
    private const TRANSACTION_LIMIT = 12 * 1024 * 1024;
    private const CANONICAL_DEPTH_LIMIT = 64;
    private const CANONICAL_NODE_LIMIT = 131072;

    public static function revisions($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || count($value) > 256) {
            throw new \DomainException('Immediate configuration revisions are invalid.');
        }
        foreach ($value as $key => $revision) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key) || $key === 'immediate_config_revisions') {
                throw new \DomainException('Immediate configuration section is invalid.');
            }
            if (!is_int($revision) || $revision < 0 || $revision >= PHP_INT_MAX) {
                throw new \DomainException('Immediate configuration revisions must be nonnegative integers.');
            }
        }
        return $value;
    }

    public static function prepare(array $active, ?array $pending, array $keys): array
    {
        if (!$keys || !array_is_list($keys) || count($keys) > 256) {
            throw new \DomainException('Choose a valid list of immediate configuration sections.');
        }
        $requested = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key)
                || $key === 'immediate_config_revisions' || array_key_exists($key, $requested)) {
                throw new \DomainException('Immediate configuration sections must be unique valid names.');
            }
            $requested[$key] = 0;
        }
        self::revisions($requested);
        $keys = array_keys($requested);
        $current = self::revisions($active['immediate_config_revisions'] ?? []);
        $staged = self::revisions($pending['immediate_config_revisions'] ?? []);
        foreach ($keys as $key) {
            $revision = max($current[$key] ?? 0, $staged[$key] ?? 0);
            if ($revision >= PHP_INT_MAX - 1) { throw new \DomainException('Immediate configuration revision limit reached. Preserve the configuration for repair.'); }
            $current[$key] = $revision + 1;
            if ($pending !== null) { $staged[$key] = $revision + 1; }
        }
        $active['immediate_config_revisions'] = $current;
        if ($pending !== null) { $pending['immediate_config_revisions'] = $staged; }
        return [$active, $pending];
    }

    public static function reconcile(array $active, array $pending): array
    {
        $current = self::revisions($active['immediate_config_revisions'] ?? []);
        $staged = self::revisions($pending['immediate_config_revisions'] ?? []);
        foreach (array_unique(array_merge(array_keys($current), array_keys($staged))) as $key) {
            if (($current[$key] ?? 0) !== ($staged[$key] ?? 0)) {
                if (array_key_exists($key, $active)) { $pending[$key] = $active[$key]; }
                else { unset($pending[$key]); }
            }
        }
        $pending['immediate_config_revisions'] = $current;
        return $pending;
    }

    private static function directory(string $activePath, string $pendingPath): string
    {
        if ($activePath === '' || $activePath[0] !== '/' || dirname($activePath) !== dirname($pendingPath)
            || basename($activePath) !== substr($activePath, strlen(dirname($activePath)) + 1)
            || basename($pendingPath) !== substr($pendingPath, strlen(dirname($pendingPath)) + 1)
            || in_array(basename($activePath), ['', '.', '..'], true)
            || in_array(basename($pendingPath), ['', '.', '..'], true)
            || $activePath === $pendingPath) {
            throw new \RuntimeException('Immediate configuration paths are invalid.');
        }
        $directory = dirname($activePath);
        clearstatcache(true, $directory);
        $metadata = @lstat($directory);
        $runtime = function_exists('posix_getpwnam') ? @posix_getpwnam('asterisk') : false;
        $owners = [0, function_exists('posix_geteuid') ? posix_geteuid() : -1,
            is_array($runtime) ? ($runtime['uid'] ?? -1) : -1];
        if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0040000
            || ($metadata['mode'] & 0022) !== 0 || realpath($directory) !== $directory
            || !in_array($metadata['uid'], array_unique($owners), true)) {
            throw new \RuntimeException('Immediate configuration storage is unsafe.');
        }
        return $directory;
    }

    private static function semanticHash(array $settings): string
    {
        $nodes = 0;
        $scalarBytes = 0;
        $canonical = static function ($value, int $depth = 0) use (&$canonical, &$nodes, &$scalarBytes) {
            $nodes++;
            if ($nodes > self::CANONICAL_NODE_LIMIT || $depth > self::CANONICAL_DEPTH_LIMIT) {
                throw new \DomainException('Immediate configuration exceeds the supported structure limit.');
            }
            if (!is_array($value)) {
                if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                    throw new \DomainException('Immediate configuration contains an unsupported value.');
                }
                if (is_float($value) && !is_finite($value)) {
                    throw new \DomainException('Immediate configuration contains a non-finite number.');
                }
                if (is_string($value)) {
                    $scalarBytes += strlen($value);
                    if ($scalarBytes > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES) {
                        throw new \DomainException('Immediate configuration exceeds the protected file limit.');
                    }
                }
                return $value;
            }
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as $key => &$item) {
                if (is_string($key)) {
                    $scalarBytes += strlen($key);
                    if ($scalarBytes > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES) {
                        throw new \DomainException('Immediate configuration exceeds the protected file limit.');
                    }
                }
                $item = $canonical($item, $depth + 1);
            }
            unset($item);
            return $value;
        };
        $encoded = json_encode($canonical($settings), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES) {
            throw new \DomainException('Immediate configuration exceeds the protected file limit.');
        }
        return hash('sha256', $encoded);
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes, string $message): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset, min(1048576, $length - $offset)));
            if (!is_int($written) || $written < 1) { throw new \RuntimeException($message); }
            $offset += $written;
        }
    }

    private static function unlinkNamedFileIfIdentity(string $path, $identity): bool
    {
        if (!is_array($identity) || !isset($identity['dev'], $identity['ino'])) { return false; }
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || ($current['mode'] & 0170000) !== 0100000 || $current['nlink'] !== 1
            || $current['dev'] !== $identity['dev'] || $current['ino'] !== $identity['ino']) { return false; }
        return @unlink($path);
    }

    /** @param resource $handle */
    private static function protectOpenFile($handle,array $identity,int $uid,int $gid,int $mode): void
    {
        if(!is_resource($handle)||!isset($identity['dev'],$identity['ino'])||$uid<0||$gid<0||!in_array($mode,[0600,0640],true)){
            throw new \RuntimeException('Immediate configuration rollback descriptor metadata is invalid.');
        }
        $python='/usr/bin/python3';$timeout='/usr/bin/timeout';
        if(!is_file($python)||!is_executable($python)||!is_file($timeout)||!is_executable($timeout)){
            throw new \RuntimeException('Immediate configuration rollback requires the installed Python and timeout runtimes.');
        }
        $program=implode("\n",['import os,stat,sys','fd=3','dev,ino,uid,gid,mode=map(int,sys.argv[1:])','s=os.fstat(fd)',
            'assert stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_dev==dev and s.st_ino==ino',
            'if os.geteuid()==0: os.fchown(fd,uid,gid)','else: assert s.st_uid==uid and s.st_gid==gid',
            'os.fchmod(fd,mode)','os.fsync(fd)','s=os.fstat(fd)',
            'assert stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_dev==dev and s.st_ino==ino and s.st_uid==uid and s.st_gid==gid and stat.S_IMODE(s.st_mode)==mode'])."\n";
        $command=[$timeout,'--kill-after=1','5',$python,'-I','-c',$program,(string)$identity['dev'],(string)$identity['ino'],(string)$uid,(string)$gid,(string)$mode];
        $process=@proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w'],3=>$handle],$pipes,null,
            ['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C','LANG'=>'C'],['bypass_shell'=>true]);
        if(!is_resource($process)||!isset($pipes[2])||!is_resource($pipes[2])){
            if(is_resource($process)){@proc_terminate($process);@proc_close($process);}
            throw new \RuntimeException('Immediate configuration rollback descriptor helper could not start.');
        }
        $diagnostic=stream_get_contents($pipes[2],4097);fclose($pipes[2]);$status=proc_close($process);
        if($status!==0||!is_string($diagnostic)||strlen($diagnostic)>4096){
            throw new \RuntimeException('Immediate configuration rollback could not protect its open descriptor.');
        }
    }

    private static function safeMetadata($metadata, int $maximum): bool
    {
        if (!is_array($metadata) || ($metadata['mode'] & 0170000) !== 0100000
            || $metadata['nlink'] !== 1 || $metadata['size'] < 1 || $metadata['size'] > $maximum
            || !in_array($metadata['mode'] & 07777, [0600, 0640], true)) {
            return false;
        }
        $runtime = function_exists('posix_getpwnam') ? @posix_getpwnam('asterisk') : false;
        $owners = [0, function_exists('posix_geteuid') ? posix_geteuid() : -1, is_array($runtime) ? ($runtime['uid'] ?? -1) : -1];
        $groups = [0, function_exists('posix_getegid') ? posix_getegid() : -1, is_array($runtime) ? ($runtime['gid'] ?? -1) : -1];
        return in_array($metadata['uid'], array_unique($owners), true)
            && (($metadata['mode'] & 07777) === 0600 || in_array($metadata['gid'], array_unique($groups), true));
    }

    private static function snapshot(string $path, bool $required): array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            if ($required) { throw new \RuntimeException('Active configuration disappeared during an immediate update.'); }
            return ['present' => false];
        }
        if (!self::safeMetadata($before, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES)) {
            throw new \RuntimeException('Immediate configuration has unsafe ownership, permissions, type or size.');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) { throw new \RuntimeException('Immediate configuration cannot be read safely.'); }
        try {
            $opened = fstat($handle);
            $raw = stream_get_contents($handle, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                if (!self::safeMetadata($opened, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES)
                    || !self::safeMetadata($after, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES)
                    || !self::safeMetadata($named, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES)
                    || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]
                    || $after[$field] !== $named[$field]) {
                    throw new \RuntimeException('Immediate configuration changed while it was being snapshotted.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $before['size']) {
                throw new \RuntimeException('Immediate configuration snapshot is incomplete.');
            }
            $decoded = \FreePBX\modules\SlsConfigCrypto::decode($raw);
            return ['present' => true, 'raw' => $raw, 'sha256' => hash('sha256', $raw),
                'semantic_sha256' => self::semanticHash($decoded), 'mode' => $before['mode'] & 07777,
                'uid' => $before['uid'], 'gid' => $before['gid'],
                'identity' => ['dev' => $before['dev'], 'ino' => $before['ino']]];
        } finally { fclose($handle); }
    }

    private static function journalPath(string $activePath, string $pendingPath): string
    {
        return self::directory($activePath, $pendingPath) . '/' . self::TRANSACTION;
    }

    private static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) { throw new \RuntimeException('Immediate configuration directory cannot be synchronized.'); }
        try {
            if (!fsync($handle)) { throw new \RuntimeException('Immediate configuration directory cannot be synchronized.'); }
        } finally { fclose($handle); }
    }

    private static function writeJournal(string $path, array $journal): void
    {
        if (@lstat($path) !== false) { throw new \RuntimeException('An earlier immediate configuration transaction requires recovery.'); }
        $raw = json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($raw) > self::TRANSACTION_LIMIT) { throw new \RuntimeException('Immediate configuration recovery evidence exceeds its limit.'); }
        $temporary = dirname($path) . '/.immediate-settings-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try { $handle = @fopen($temporary, 'x+b'); }
        finally { umask($mask); }
        if (!is_resource($handle)) { throw new \RuntimeException('Immediate configuration recovery evidence could not be created.'); }
        $temporaryIdentity = fstat($handle);
        try {
            self::writeAll($handle, $raw, 'Immediate configuration recovery evidence could not be synchronized.');
            if (!fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Immediate configuration recovery evidence could not be synchronized.');
            }
            $opened = fstat($handle); clearstatcache(true, $temporary); $named = @lstat($temporary);
            if (!is_array($opened) || !is_array($named) || ($opened['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || !is_array($temporaryIdentity) || $opened['dev'] !== $temporaryIdentity['dev']
                || $opened['ino'] !== $temporaryIdentity['ino']
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
                || $opened['size'] !== strlen($raw) || ($opened['mode'] & 07777) !== 0600
                || $opened['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : $opened['uid'])) {
                throw new \RuntimeException('Immediate configuration recovery evidence changed while it was staged.');
            }
            clearstatcache(true, $path);
            if (@lstat($path) !== false) { throw new \RuntimeException('An earlier immediate configuration transaction requires recovery.'); }
            if (!rename($temporary, $path)) { throw new \RuntimeException('Immediate configuration recovery evidence could not be published.'); }
            clearstatcache(true, $path); $committed = @lstat($path);
            if (!is_array($committed) || $committed['dev'] !== $temporaryIdentity['dev']
                || $committed['ino'] !== $temporaryIdentity['ino'] || $committed['nlink'] !== 1
                || $committed['size'] !== strlen($raw) || ($committed['mode'] & 07777) !== 0600) {
                throw new \RuntimeException('Immediate configuration recovery evidence changed during publication.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            fclose($handle);
            self::unlinkNamedFileIfIdentity($temporary, $temporaryIdentity);
        }
    }

    private static function encodedSnapshot(array $snapshot): array
    {
        if (!$snapshot['present']) { return ['present' => false]; }
        return ['present' => true, 'raw' => base64_encode($snapshot['raw']), 'sha256' => $snapshot['sha256'],
            'semantic_sha256' => $snapshot['semantic_sha256'], 'mode' => $snapshot['mode'],
            'uid' => $snapshot['uid'], 'gid' => $snapshot['gid']];
    }

    private static function decodedSnapshot($snapshot): array
    {
        if (!is_array($snapshot) || !array_key_exists('present', $snapshot) || !is_bool($snapshot['present'])) {
            throw new \RuntimeException('Immediate configuration recovery evidence is invalid.');
        }
        if (!$snapshot['present']) {
            if (count($snapshot) !== 1) { throw new \RuntimeException('Immediate configuration recovery evidence is invalid.'); }
            return ['present' => false];
        }
        if (count($snapshot) !== 7 || array_diff(array_keys($snapshot), ['present', 'raw', 'sha256', 'semantic_sha256', 'mode', 'uid', 'gid'])
            || !is_string($snapshot['raw']) || !is_string($snapshot['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['sha256'])
            || !is_string($snapshot['semantic_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['semantic_sha256'])
            || !in_array($snapshot['mode'], [0600, 0640], true) || !is_int($snapshot['uid']) || $snapshot['uid'] < 0
            || !is_int($snapshot['gid']) || $snapshot['gid'] < 0) {
            throw new \RuntimeException('Immediate configuration recovery evidence is invalid.');
        }
        $raw = base64_decode($snapshot['raw'], true);
        if (!is_string($raw) || base64_encode($raw) !== $snapshot['raw'] || hash('sha256', $raw) !== $snapshot['sha256']
            || strlen($raw) < 1 || strlen($raw) > \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES
            || self::semanticHash(\FreePBX\modules\SlsConfigCrypto::decode($raw)) !== $snapshot['semantic_sha256']) {
            throw new \RuntimeException('Immediate configuration recovery evidence is damaged.');
        }
        $snapshot['raw'] = $raw;
        return $snapshot;
    }

    private static function readJournal(string $path): ?array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) { return null; }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        $safe = static fn($metadata): bool => is_array($metadata) && ($metadata['mode'] & 0170000) === 0100000
            && ($metadata['mode'] & 07777) === 0600 && $metadata['uid'] === $euid
            && $metadata['nlink'] === 1 && $metadata['size'] > 0 && $metadata['size'] <= self::TRANSACTION_LIMIT;
        if (!$safe($before)) { throw new \RuntimeException('Immediate configuration recovery evidence is unsafe.'); }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) { throw new \RuntimeException('Immediate configuration recovery evidence is unreadable.'); }
        try {
            $opened = fstat($handle); $raw = stream_get_contents($handle, self::TRANSACTION_LIMIT + 1);
            $after = fstat($handle);
            clearstatcache(true, $path); $named = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                if (!$safe($opened) || !$safe($after) || !$safe($named)
                    || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]
                    || $after[$field] !== $named[$field]) {
                    throw new \RuntimeException('Immediate configuration recovery evidence changed while it was read.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $before['size']) {
                throw new \RuntimeException('Immediate configuration recovery evidence changed while it was read.');
            }
        } finally { fclose($handle); }
        $journal = \FreePBX\modules\SlsConfigCrypto::strictObject($raw, self::TRANSACTION_LIMIT);
        $expected = ['schema', 'created_at', 'active_name', 'pending_name', 'active_before', 'pending_before',
            'active_after', 'pending_after'];
        if (count($journal) !== count($expected) || array_diff(array_keys($journal), $expected)
            || array_diff($expected, array_keys($journal)) || ($journal['schema'] ?? null) !== 1
            || !is_int($journal['created_at'] ?? null)
            || $journal['created_at'] < 1 || !is_string($journal['active_after'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $journal['active_after'])
            || !is_string($journal['pending_after'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $journal['pending_after'])
            || ($journal['active_name'] ?? null) === '' || !is_string($journal['active_name'] ?? null)
            || strlen($journal['active_name']) > 255 || basename($journal['active_name']) !== $journal['active_name']
            || in_array($journal['active_name'], ['.', '..'], true)
            || ($journal['pending_name'] ?? null) === '' || !is_string($journal['pending_name'] ?? null)
            || strlen($journal['pending_name']) > 255 || basename($journal['pending_name']) !== $journal['pending_name']
            || in_array($journal['pending_name'], ['.', '..'], true)) {
            throw new \RuntimeException('Immediate configuration recovery evidence is invalid.');
        }
        $journal['active_before'] = self::decodedSnapshot($journal['active_before'] ?? null);
        $journal['pending_before'] = self::decodedSnapshot($journal['pending_before'] ?? null);
        $journal['_journal_identity'] = ['dev' => $named['dev'], 'ino' => $named['ino']];
        return $journal;
    }

    private static function restore(string $path, array $snapshot): void
    {
        if (!$snapshot['present']) {
            if (@lstat($path) !== false) {
                $current = self::snapshot($path, true);
                if (!self::unlinkNamedFileIfIdentity($path, $current['identity'] ?? null)) {
                    throw new \RuntimeException('Partial immediate configuration could not be removed during recovery.');
                }
                self::syncDirectory(dirname($path));
            }
            return;
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : $snapshot['uid'];
        if ($euid !== 0 && $snapshot['uid'] !== $euid) {
            throw new \RuntimeException('Immediate configuration ownership cannot be restored by this runtime account.');
        }
        clearstatcache(true, $path); $destinationBefore = @lstat($path);
        if ($destinationBefore !== false && !self::safeMetadata($destinationBefore, \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES)) {
            throw new \RuntimeException('Immediate configuration rollback destination is unsafe.');
        }
        $temporary = dirname($path) . '/.settings-rollback-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try { $handle = @fopen($temporary, 'x+b'); }
        finally { umask($mask); }
        if (!is_resource($handle)) { throw new \RuntimeException('Immediate configuration rollback could not be staged.'); }
        $temporaryIdentity = fstat($handle);
        try {
            self::writeAll($handle, $snapshot['raw'], 'Immediate configuration rollback could not preserve its protected bytes and ownership.');
            if (!fflush($handle)) {
                throw new \RuntimeException('Immediate configuration rollback could not preserve its protected bytes and ownership.');
            }
            self::protectOpenFile($handle,$temporaryIdentity,$snapshot['uid'],$snapshot['gid'],$snapshot['mode']);
            if(!fsync($handle)){throw new \RuntimeException('Immediate configuration rollback could not preserve its protected bytes and ownership.');}
            $opened = fstat($handle); clearstatcache(true, $temporary); $named = @lstat($temporary);
            if (!is_array($opened) || !is_array($named) || ($opened['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || !is_array($temporaryIdentity) || $opened['dev'] !== $temporaryIdentity['dev']
                || $opened['ino'] !== $temporaryIdentity['ino']
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
                || $opened['size'] !== strlen($snapshot['raw']) || ($opened['mode'] & 07777) !== $snapshot['mode']
                || $opened['uid'] !== $snapshot['uid'] || $opened['gid'] !== $snapshot['gid']) {
                throw new \RuntimeException('Immediate configuration rollback staging changed unexpectedly.');
            }
            clearstatcache(true, $path); $destinationAtCommit = @lstat($path);
            if (($destinationBefore === false) !== ($destinationAtCommit === false)
                || ($destinationBefore !== false && ($destinationBefore['dev'] !== $destinationAtCommit['dev']
                    || $destinationBefore['ino'] !== $destinationAtCommit['ino']))) {
                throw new \RuntimeException('Immediate configuration rollback destination changed before publication.');
            }
            if (!rename($temporary, $path)) { throw new \RuntimeException('Immediate configuration rollback could not be published.'); }
            clearstatcache(true, $path); $committed = @lstat($path);
            if (!is_array($committed) || $committed['dev'] !== $temporaryIdentity['dev']
                || $committed['ino'] !== $temporaryIdentity['ino'] || $committed['nlink'] !== 1
                || $committed['size'] !== strlen($snapshot['raw']) || ($committed['mode'] & 07777) !== $snapshot['mode']) {
                throw new \RuntimeException('Immediate configuration rollback changed during publication.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            fclose($handle);
            self::unlinkNamedFileIfIdentity($temporary, $temporaryIdentity);
        }
        $restored = self::snapshot($path, true);
        if (!hash_equals($snapshot['sha256'], $restored['sha256'])) {
            throw new \RuntimeException('Immediate configuration rollback could not be verified.');
        }
    }

    private static function state(string $path, array $before, string $after): string
    {
        $current = self::snapshot($path, false);
        if ($current['present'] === $before['present']
            && (!$current['present'] || hash_equals($before['sha256'], $current['sha256']))) {
            return 'before';
        }
        if ($current['present'] && hash_equals($after, $current['semantic_sha256'])) { return 'after'; }
        return 'unknown';
    }

    /** Caller holds the central settings lock. Returns none, committed or rolled_back. */
    public static function recoverFiles(string $activePath, string $pendingPath): string
    {
        $path = self::journalPath($activePath, $pendingPath);
        $journal = self::readJournal($path);
        if ($journal === null) { return 'none'; }
        if ($journal['active_name'] !== basename($activePath) || $journal['pending_name'] !== basename($pendingPath)) {
            throw new \RuntimeException('Immediate configuration recovery evidence names unexpected files.');
        }
        $activeState = self::state($activePath, $journal['active_before'], $journal['active_after']);
        $pendingState = self::state($pendingPath, $journal['pending_before'], $journal['pending_after']);
        if ($activeState === 'unknown' || $pendingState === 'unknown') {
            throw new \RuntimeException('Immediate configuration changed outside its retained transaction. Preserve all files for recovery.');
        }
        $outcome = 'committed';
        if ($activeState !== 'after' || $pendingState !== 'after') {
            self::restore($activePath, $journal['active_before']);
            self::restore($pendingPath, $journal['pending_before']);
            $outcome = 'rolled_back';
        }
        if (!self::unlinkNamedFileIfIdentity($path, $journal['_journal_identity'] ?? null)) {
            throw new \RuntimeException('Immediate configuration recovery completed but its transaction marker could not be removed.');
        }
        self::syncDirectory(dirname($path));
        return $outcome;
    }

    /** Caller holds the central settings lock. */
    public static function commitFiles(string $activePath, string $pendingPath, array $active, ?array $pending, callable $writer): void
    {
        if (self::recoverFiles($activePath, $pendingPath) !== 'none') {
            throw new \RuntimeException('An interrupted immediate configuration transaction was recovered. Reload before retrying the change.');
        }
        if ($pending === null) {
            $expected = self::semanticHash($active);
            $writer($activePath, $active, true);
            $written = self::snapshot($activePath, true);
            if (!hash_equals($expected, $written['semantic_sha256'])) {
                throw new \RuntimeException('Immediate configuration write verification failed.');
            }
            return;
        }
        $activeBefore = self::snapshot($activePath, true);
        $pendingBefore = self::snapshot($pendingPath, true);
        $journal = ['schema' => 1, 'created_at' => time(), 'active_name' => basename($activePath),
            'pending_name' => basename($pendingPath), 'active_before' => self::encodedSnapshot($activeBefore),
            'pending_before' => self::encodedSnapshot($pendingBefore), 'active_after' => self::semanticHash($active),
            'pending_after' => self::semanticHash($pending)];
        self::writeJournal(self::journalPath($activePath, $pendingPath), $journal);
        try {
            $writer($activePath, $active, true);
            $writer($pendingPath, $pending, false);
            $activeWritten = self::snapshot($activePath, true);
            $pendingWritten = self::snapshot($pendingPath, true);
            if (!hash_equals($journal['active_after'], $activeWritten['semantic_sha256'])
                || !hash_equals($journal['pending_after'], $pendingWritten['semantic_sha256'])) {
                throw new \RuntimeException('Immediate configuration write verification failed.');
            }
            $outcome = self::recoverFiles($activePath, $pendingPath);
            if ($outcome !== 'committed') { throw new \RuntimeException('Immediate configuration did not commit both protected files.'); }
        } catch (\Throwable $error) {
            try { $outcome = self::recoverFiles($activePath, $pendingPath); }
            catch (\Throwable $recovery) {
                throw new \RuntimeException('Immediate configuration rollback is incomplete. Preserve the transaction journal and both settings files for protected recovery.', 0, $error);
            }
            if ($outcome === 'committed') { return; }
            throw $error;
        }
    }
}
