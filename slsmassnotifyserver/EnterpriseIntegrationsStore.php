<?php
declare(strict_types=1);
namespace SLS\MassNotify;
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Private durable provider claims, reply bindings and enrolled sensor health. */
final class EnterpriseIntegrationsStore
{
    private const MARKER="SLS_ENTERPRISE_INTEGRATIONS_V1\n";
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-integrations';
    private \SlsAnnouncementJobStore $files;
    private string $directory;
    private string $parentMarker;
    public function __construct(string $directory=self::DIRECTORY)
    {
        $this->directory=rtrim($directory,'/');
        $this->parentMarker=dirname($this->directory).'/.'.basename($this->directory).'-required';
        if ($this->parentFence() && (!is_dir($this->directory) || is_link($this->directory))) {
            throw new \RuntimeException('Established integration journal directory is missing. Preserve its parent marker; do not recreate replay history.');
        }
        $this->files=new \SlsAnnouncementJobStore($this->directory);
    }
    private static function initial(): array { return ['schema'=>1,'operations'=>[],'bindings'=>[],'responses'=>[],'sensors'=>[]]; }
    /** A marker outside the journal survives loss of the entire directory. */
    private function parentFence(bool $create=false): bool
    {
        $parent=dirname($this->directory); clearstatcache(true,$parent); $parentBefore=@lstat($parent);
        if (!$parentBefore || ($parentBefore['mode']&0170000)!==0040000 || ($parentBefore['mode']&0022) || realpath($parent)!==$parent) {
            throw new \RuntimeException('Integration replay marker parent is unsafe.');
        }
        clearstatcache(true,$this->parentMarker); $before=@lstat($this->parentMarker);
        if (!$before && !$create) { return false; }
        $safe=static fn($m):bool=>is_array($m)&&($m['mode']&0170000)===0100000&&($m['mode']&0077)===0&&$m['nlink']===1&&$m['uid']===posix_geteuid()&&$m['size']<=64;
        if ($before && !$safe($before)) { throw new \RuntimeException('Integration replay marker is unsafe.'); }
        $mask=umask(0077); try { $handle=@fopen($this->parentMarker,$before?'rb':'x+b'); } finally { umask($mask); }
        if (!$handle) { throw new \RuntimeException('Integration replay marker could not be opened.'); }
        try {
            $opened=fstat($handle); clearstatcache(true,$this->parentMarker); $named=@lstat($this->parentMarker);
            clearstatcache(true,$parent); $parentAfter=@lstat($parent);
            if (!$safe($opened)||!$safe($named)||$opened['dev']!==$named['dev']||$opened['ino']!==$named['ino']
                || ($before&&($before['dev']!==$opened['dev']||$before['ino']!==$opened['ino']))
                || !$parentAfter||$parentBefore['dev']!==$parentAfter['dev']||$parentBefore['ino']!==$parentAfter['ino']) {
                throw new \RuntimeException('Integration replay marker changed while opening.');
            }
            if ($before) {
                if (stream_get_contents($handle,65)!==self::MARKER) { throw new \RuntimeException('Integration replay marker is damaged. Preserve replay evidence.'); }
            } else {
                if (fwrite($handle,self::MARKER)!==strlen(self::MARKER)||!fflush($handle)||!fsync($handle)) { throw new \RuntimeException('Integration replay marker could not be synchronized.'); }
                $directoryHandle=@fopen($parent,'r');
                try { if (!$directoryHandle||!fsync($directoryHandle)) { throw new \RuntimeException('Integration replay marker directory could not be synchronized.'); } }
                finally { if ($directoryHandle) { fclose($directoryHandle); } }
            }
            return true;
        } finally { fclose($handle); }
    }
    public function read(): array
    {
        $exists=file_exists($this->files->directory().'/worker-state.json') || is_link($this->files->directory().'/worker-state.json');
        $marker=$this->initializationMarker();
        $required=$this->parentFence();
        if (($required&&(!$exists||$marker!==self::MARKER)) || (!$required&&($exists||$marker!==''))) { throw new \RuntimeException('Established integration journal or replay marker is missing. Preserve replay evidence; do not initialize it again.'); }
        $state=$this->files->state(); if (!$exists) { return self::initial(); }
        if (($state['schema']??null)!==1) { throw new \RuntimeException('Integration journal schema is unsupported. Preserve its state.'); }
        foreach (['operations'=>500,'bindings'=>2000,'responses'=>4000,'sensors'=>100] as $key=>$maximum) {
            if (!is_array($state[$key]??null) || count($state[$key])>$maximum) { throw new \RuntimeException('Integration journal is invalid or exceeds its bound. Preserve its state.'); }
        }
        $hash=static fn($value):bool=>is_string($value) && (bool)preg_match('/^[a-f0-9]{64}$/D',$value);
        foreach ($state['operations'] as $id=>$row) {
            if (!preg_match('/^[a-f0-9]{32}$/D',(string)$id) || !is_array($row) || ($row['request_id']??null)!==$id || !$hash($row['fingerprint']??null)
                || !in_array($row['kind']??null,['meeting','door','ipaws'],true) || !in_array($row['state']??null,['submitting','accepted','provider_reported','failed','uncertain'],true)
                || !is_int($row['created_at']??null) || $row['created_at']<1) { throw new \RuntimeException('Integration operation journal is invalid. Preserve replay evidence.'); }
        }
        foreach ($state['bindings'] as $id=>$row) {
            if (!$hash($id) || !is_array($row) || ($row['id']??null)!==$id || !preg_match('/^[A-F0-9]{12}$/D',$row['token']??'')
                || !preg_match('/^inc_[a-f0-9]{32}$/D',$row['incident_id']??'') || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$row['person_id']??'')
                || !in_array($row['channel']??null,['sms','voice'],true) || !in_array($row['provider']??null,['twilio','telnyx','pbx'],true)
                || !preg_match('/^(?:sms|voice)_[a-f0-9]{24}$/D',$row['recipient_id']??'') || !$hash($row['identity_fingerprint']??null)
                || !$hash($row['number_hash']??null) || !$hash($row['person_fingerprint']??null) || !$hash($row['config_fingerprint']??null)
                || !is_string($row['route']??null) || strlen($row['route'])>512 || preg_match('/[\x00-\x1f]/',$row['route'])
                || !is_int($row['created_at']??null) || !is_int($row['expires_at']??null) || $row['expires_at']<=$row['created_at'] || $row['expires_at']>$row['created_at']+86400) { throw new \RuntimeException('Integration reply journal is invalid. Preserve participant bindings.'); }
        }
        foreach ($state['responses'] as $id=>$row) {
            if (!$hash($id) || !is_array($row) || !$hash($row['fingerprint']??null) || !in_array($row['state']??null,['pending','complete'],true) || !is_int($row['created_at']??null)) { throw new \RuntimeException('Integration human response journal is invalid.'); }
        }
        foreach ($state['sensors'] as $id=>$row) {
            if (!preg_match('/^trg_[a-f0-9]{24}$/D',(string)$id) || !is_array($row) || !preg_match('/^[a-f0-9]{32}$/D',$row['request_id']??'')
                || !$hash($row['fingerprint']??null) || !$hash($row['rule_revision']??null) || !is_int($row['sent_at']??null) || !is_int($row['last_seen']??null) || !is_bool($row['is_test']??null)) { throw new \RuntimeException('Integration sensor journal is invalid.'); }
        }
        return $state;
    }
    private function initializationMarker(): string
    {
        $path=$this->files->directory().'/announcement-send.lock'; clearstatcache(true,$path); $before=@lstat($path);
        if (!$before) { return ''; }
        if (($before['mode']&0170000)!==0100000 || $before['nlink']!==1 || ($before['mode']&07022) || $before['uid']!==fileowner($this->files->directory()) || $before['size']>64) { throw new \RuntimeException('Integration initialization marker is unsafe.'); }
        $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('Integration initialization marker is unavailable.'); }
        try {
            $opened=fstat($handle); if (!$opened || $opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino']) { throw new \RuntimeException('Integration initialization marker changed.'); }
            $value=stream_get_contents($handle,65);
            if ($value!=='' && $value!==self::MARKER) { throw new \RuntimeException('Integration initialization marker is invalid. Preserve replay evidence.'); }
            return $value;
        } finally { fclose($handle); }
    }
    public function transaction(callable $operation)
    {
        $lock=$this->files->admissionLock(); if (!$lock) { throw new \RuntimeException('Integration journal is busy. Retry the same request identifier.'); }
        try {
            if (!$this->parentFence()) {
                if ($this->files->state() || $this->initializationMarker()!=='') { throw new \RuntimeException('Integration history exists without its parent replay marker. Preserve it for recovery.'); }
                // Persist an empty established ledger before invoking application
                // work. Rejected input then leaves a usable, fenced journal.
                $this->parentFence(true);
                $this->files->atomic('worker-state.json',self::initial());
                $this->writeInitializationMarker($lock);
            }
            $state=$this->read(); $result=$operation($state);
            foreach (['operations'=>500,'bindings'=>2000,'responses'=>4000,'sensors'=>100] as $key=>$maximum) { if (count($state[$key])>$maximum) { throw new \RuntimeException('Integration journal is full. Preserve replay evidence and review retention.'); } }
            if (strlen(json_encode($state,JSON_THROW_ON_ERROR))>1800000) { throw new \RuntimeException('Integration journal exceeds its private storage budget.'); }
            $this->files->atomic('worker-state.json',$state);
            // The existing protected admission lock is also the durable initialization marker.
            // Preserve it alongside worker-state.json in operational backup/restore.
            $this->writeInitializationMarker($lock);
            return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    private function writeInitializationMarker($lock): void
    {
        if (fseek($lock,0)!==0 || fwrite($lock,self::MARKER)!==strlen(self::MARKER) || !ftruncate($lock,strlen(self::MARKER)) || !fflush($lock) || !fsync($lock)) { throw new \RuntimeException('Cannot commit the integration initialization marker. Preserve replay evidence.'); }
    }
}
