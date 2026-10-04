<?php
declare(strict_types=1);
namespace SLS\MassNotify\Sms;
require_once __DIR__.'/Continuity.php';

/** Submission claims, limits and receipts commit before provider requests. */
final class Store
{
    use Continuity;
    private \PDO $db;
    private string $directory;
    private array $identity;
    private array $directoryIdentity;
    public function __construct(string $directory)
    {
        $this->directory=rtrim($directory,'/');
        if (!extension_loaded('pdo_sqlite')) { throw new \RuntimeException('sms_sqlite_unavailable'); }
        if (!file_exists($directory) && !is_link($directory) && !@mkdir($directory,0700)) { throw new \RuntimeException('sms_storage_unavailable'); }
        $meta=$this->directory(); $this->directoryIdentity=$meta;
        $path=$this->directory.'/deliveries.sqlite'; $marker=$this->directory.'/initialized';
        $mask=umask(0077); $lock=null;
        try {
            $lockPath=$this->directory.'/storage.lock';
            $this->file($lockPath,true); $lock=@fopen($lockPath,'c+b');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('sms_storage_busy'); }
            $at=$this->file($lockPath); $open=fstat($lock);
            if ($at['dev']!==$open['dev'] || $at['ino']!==$open['ino']) { throw new \RuntimeException('sms_storage_changed'); }
            foreach (['-journal','-wal','-shm'] as $suffix) { $this->file($path.$suffix,true); }
            $exists=$this->file($path,true)!==null; $marked=$this->file($marker,true)!==null;
            if ($exists!==$marked) { throw new \RuntimeException('sms_storage_initialization_incomplete'); }
            if (!$exists) {
                $created=@fopen($path,'x+b');
                if (!$created) { throw new \RuntimeException('sms_storage_create_failed'); }
                fclose($created);
            }
            $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            $this->db->exec('PRAGMA busy_timeout=250; PRAGMA trusted_schema=OFF; PRAGMA foreign_keys=ON; PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL;');
            if (!$exists) {
                $this->db->exec('BEGIN IMMEDIATE');
                try {
                    $this->db->exec("CREATE TABLE deliveries (id TEXT PRIMARY KEY, provider TEXT NOT NULL, route TEXT NOT NULL,
                        recipient_id TEXT NOT NULL, job_id TEXT NOT NULL, number_hash TEXT NOT NULL, body_hash TEXT NOT NULL,
                        created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
                        state TEXT NOT NULL, provider_id TEXT NOT NULL DEFAULT '', segments INTEGER NOT NULL,
                        cost_micros INTEGER NOT NULL, currency TEXT NOT NULL, error_code TEXT NOT NULL DEFAULT '');
                        CREATE UNIQUE INDEX provider_message ON deliveries(provider,provider_id) WHERE provider_id<>'';
                        CREATE INDEX delivery_age ON deliveries(created_at);
                        CREATE TABLE budgets (period TEXT PRIMARY KEY, segments INTEGER NOT NULL, amount INTEGER NOT NULL, currency TEXT NOT NULL);
                        CREATE TABLE opt_outs (number_hash TEXT PRIMARY KEY, blocked_at INTEGER NOT NULL);
                        CREATE TABLE inbound_events (id TEXT PRIMARY KEY, created_at INTEGER NOT NULL);
                        PRAGMA user_version=1;");
                    $this->db->exec('COMMIT');
                } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
                $handle=@fopen($marker,'x+b');
                if (!$handle) { throw new \RuntimeException('sms_storage_initialization_incomplete'); }
                try { if (fwrite($handle,"SLS_SMS_LEDGER_V1\n")!==18 || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('sms_storage_initialization_incomplete'); } }
                finally { fclose($handle); }
                $parent=fopen($directory,'r');
                try { if (!$parent || !fsync($parent)) { throw new \RuntimeException('sms_storage_sync_failed'); } }
                finally { if ($parent) { fclose($parent); } }
            }
            if ((int)$this->db->query('PRAGMA user_version')->fetchColumn()!==1) { throw new \RuntimeException('sms_storage_schema_unsupported'); }
            // Additive diagnostics: existing V1 claims, budgets and opt-outs
            // remain readable by the previous implementation during rollback.
            $this->db->exec("CREATE TABLE IF NOT EXISTS provider_errors (id TEXT PRIMARY KEY REFERENCES deliveries(id) ON DELETE CASCADE, detail TEXT NOT NULL)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS message_formats (id TEXT PRIMARY KEY REFERENCES deliveries(id) ON DELETE CASCADE, message_type TEXT NOT NULL, media_hash TEXT NOT NULL)");
            if (file_get_contents($marker)!=="SLS_SMS_LEDGER_V1\n") { throw new \RuntimeException('sms_storage_initialization_incomplete'); }
            $this->identity=$this->file($path);
            $now=$this->directory();
            if ($now['ino']!==$meta['ino'] || $now['dev']!==$meta['dev']) { throw new \RuntimeException('sms_storage_changed'); }
        } finally { if ($lock) { fclose($lock); } umask($mask); }
    }
    private function directory(): array
    {
        clearstatcache(true,$this->directory); $m=@lstat($this->directory);
        if (!$m || ($m['mode']&0170000)!==0040000 || ($m['mode']&0022) || !function_exists('posix_geteuid')
            || $m['uid']!==posix_geteuid() || realpath($this->directory)!==$this->directory) {
            throw new \RuntimeException('sms_storage_directory_unsafe');
        }
        return $m;
    }
    private function file(string $path, bool $optional=false): ?array
    {
        clearstatcache(true,$path); $m=@lstat($path);
        if (!$m && $optional) { return null; }
        if (!$m || ($m['mode']&0170000)!==0100000 || $m['nlink']!==1 || ($m['mode']&0037)
            || $m['uid']!==posix_geteuid() || $m['size']>1073741824) { throw new \RuntimeException('sms_storage_file_unsafe'); }
        return $m;
    }
    private function verifyStorage(): void
    {
        $directory=$this->directory();
        if ($directory['dev']!==$this->directoryIdentity['dev'] || $directory['ino']!==$this->directoryIdentity['ino']) { throw new \RuntimeException('sms_storage_changed'); }
        $marker=$this->file($this->directory.'/initialized');
        if ($marker['size']!==18) { throw new \RuntimeException('sms_storage_initialization_incomplete'); }
        $current=$this->file($this->directory.'/deliveries.sqlite');
        if ($current['dev']!==$this->identity['dev'] || $current['ino']!==$this->identity['ino']) { throw new \RuntimeException('sms_storage_changed'); }
        foreach (['-journal','-wal','-shm'] as $suffix) { $this->file($this->directory.'/deliveries.sqlite'.$suffix,true); }
    }
    private function transaction(callable $operation)
    {
        $this->verifyStorage();
        $mask=umask(0077);
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            try { $value=$operation(); $this->db->exec('COMMIT'); return $value; }
            catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
        } finally { umask($mask); }
    }
    private function query(string $sql, array $values=[]): \PDOStatement
    {
        $q=$this->db->prepare($sql); $q->execute($values); return $q;
    }
    public function get(string $id): ?array
    {
        $this->verifyStorage();
        $row=$this->query("SELECT d.*,COALESCE(e.detail,'') AS error_detail,COALESCE(f.message_type,'SMS') AS message_type,COALESCE(f.media_hash,'') AS media_hash FROM deliveries d LEFT JOIN provider_errors e ON e.id=d.id LEFT JOIN message_formats f ON f.id=d.id WHERE d.id=?",[$id])->fetch();
        return $row===false?null:$row;
    }
    public function claim(array $row, array $limits, int $consentAt, int $now): array
    {
        $row['message_type']=$row['message_type']??'SMS'; $row['media_hash']=$row['media_hash']??'';
        if (!in_array($row['message_type'],['SMS','MMS'],true) || !is_string($row['media_hash'])
            || ($row['message_type']==='MMS' && (!preg_match('/^[a-f0-9]{64}$/D',$row['media_hash']) || ($row['segments']??0)!==1))
            || ($row['message_type']==='SMS' && $row['media_hash']!=='')) { throw new \InvalidArgumentException('sms_invalid_format_claim'); }
        if (!preg_match('/^smsd_[a-f0-9]{64}$/D',$row['id']??'') || !Config::id($row['recipient_id']??null)
            || !preg_match('/^job_[a-f0-9]{32}$/D',$row['job_id']??'') || !in_array($row['provider']??null,['twilio','telnyx','bulkvs'],true)
            || !is_int($row['expires_at']??null) || !is_int($row['segments']??null) || $row['segments']<1 || $row['segments']>10
            || !is_int($row['cost_micros']??null) || $row['cost_micros']<1 || $row['cost_micros']>1000000000
            || !preg_match('/^[A-Z]{3}$/D',$row['currency']??'') || $consentAt<1 || $now<1) { throw new \InvalidArgumentException('sms_invalid_claim'); }
        foreach (['route','number_hash','body_hash'] as $key) {
            if (!is_string($row[$key]??null) || !preg_match('/^[a-f0-9]{64}$/D',$row[$key])) { throw new \InvalidArgumentException('sms_invalid_claim'); }
        }
        $limits=Config::normalize($limits);
        return $this->transaction(function () use ($row,$limits,$consentAt,$now) {
            $old=$this->get($row['id']);
            if ($old!==null) {
                foreach (['route','recipient_id','number_hash','body_hash','message_type','media_hash'] as $key) {
                    if (!hash_equals($old[$key],$row[$key])) { throw new \RuntimeException('sms_delivery_identity_conflict'); }
                }
                return ['claimed'=>false,'row'=>$old];
            }
            if ($now>=$row['expires_at']) { throw new \DomainException('sms_expired'); }
            $blocked=$this->query('SELECT blocked_at FROM opt_outs WHERE number_hash=?',[$row['number_hash']])->fetchColumn();
            if ($blocked!==false && $consentAt<=(int)$blocked) { throw new \DomainException('sms_recipient_opted_out'); }
            $this->query('DELETE FROM deliveries WHERE created_at<?',[$now-90*86400]);
            if ((int)$this->db->query('SELECT count(*) FROM deliveries')->fetchColumn()>=100000) { throw new \RuntimeException('sms_history_capacity_reached'); }
            foreach (['daily'=>'D'.gmdate('Y-m-d',$now),'monthly'=>'M'.gmdate('Y-m',$now)] as $period=>$key) {
                $current=$this->query('SELECT * FROM budgets WHERE period=?',[$key])->fetch();
                if ($current && $current['currency']!==$row['currency']) { throw new \DomainException('sms_budget_currency_changed'); }
                $segments=(int)($current['segments']??0)+$row['segments']; $amount=(int)($current['amount']??0)+$row['cost_micros'];
                if ($segments>$limits[$period.'_segments'] || $amount>$limits[$period.'_budget_micros']) { throw new \DomainException('sms_'.$period.'_budget_exceeded'); }
                $this->query('INSERT INTO budgets VALUES (?,?,?,?) ON CONFLICT(period) DO UPDATE SET segments=excluded.segments,amount=excluded.amount',[$key,$segments,$amount,$row['currency']]);
            }
            $fields=['id','provider','route','recipient_id','job_id','number_hash','body_hash','created_at','expires_at','updated_at','state','segments','cost_micros','currency'];
            $row['created_at']=$now; $row['updated_at']=$now; $row['state']='submitting';
            $this->query('INSERT INTO deliveries ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',array_map(static fn($key)=>$row[$key],$fields));
            $this->query('INSERT INTO message_formats VALUES (?,?,?)',[$row['id'],$row['message_type'],$row['media_hash']]);
            return ['claimed'=>true,'row'=>$this->get($row['id'])];
        });
    }
    /** Exact local/provider identities; stale progress cannot overwrite terminal receipts. */
    public function outcome(string $id, string $route, string $numberHash, string $providerId, string $state, string $code, int $now, string $detail=''): array
    {
        if (!in_array($state,['submitting','accepted','sent','delivered','failed','uncertain'],true)
            || !preg_match('/^[A-Za-z0-9_-]{0,64}$/D',$code) || $now < 1 || !preg_match('//u',$detail)
            || mb_strlen($detail)>384 || preg_match('/\p{C}/u',$detail)) { throw new \InvalidArgumentException('sms_invalid_outcome'); }
        return $this->transaction(function () use ($id,$route,$numberHash,$providerId,$state,$code,$now,$detail) {
            $row=$this->get($id);
            if (!$row || !hash_equals($row['route'],$route) || !hash_equals($row['number_hash'],$numberHash)
                || ($providerId!=='' && !Provider::providerId($row['provider'],$providerId))
                || ($row['provider_id']!=='' && $providerId!=='' && !hash_equals($row['provider_id'],$providerId))) { throw new \DomainException('sms_receipt_identity_mismatch'); }
            // Twilio can report an existing opt-out without delivering its
            // original inbound STOP to this PBX. Commit the suppression with
            // the exact delivery result. Replayed receipts cannot move it past
            // a later, explicitly recorded consent.
            if ($row['provider']==='twilio' && $state==='failed' && $code==='provider_21610') {
                $this->recordOptOut('twilio_optout_'.$id, $numberHash, $now);
            }
            $rank=['submitting'=>0,'uncertain'=>1,'accepted'=>2,'sent'=>3,'failed'=>4,'delivered'=>5];
            if ($row['error_code']==='sms_conflicting_terminal_receipts') { return $row; }
            if (in_array($row['state'],['delivered','failed'],true) && in_array($state,['delivered','failed'],true) && $row['state']!==$state) {
                $state='uncertain'; $code='sms_conflicting_terminal_receipts';
            } elseif (in_array($row['state'],['delivered','failed'],true) || $rank[$state]<$rank[$row['state']]) { return $row; }
            $this->query('UPDATE deliveries SET state=?,provider_id=?,error_code=?,updated_at=? WHERE id=?',[$state,$providerId!==''?$providerId:$row['provider_id'],$code,$now,$id]);
            if ($detail!=='' && $row['provider']==='bulkvs' && $state==='failed') {
                $this->query('INSERT INTO provider_errors VALUES (?,?) ON CONFLICT(id) DO NOTHING',[$id,$detail]);
            }
            return $this->get($id);
        });
    }
    public function optOut(string $event, string $numberHash, int $now): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$event) || !preg_match('/^[a-f0-9]{64}$/D',$numberHash) || $now<1) { throw new \InvalidArgumentException('sms_invalid_opt_out'); }
        $this->transaction(function () use ($event,$numberHash,$now) { $this->recordOptOut($event,$numberHash,$now); });
    }
    /** Caller holds the ledger transaction; this method must not start another. */
    private function recordOptOut(string $event, string $numberHash, int $now): void
    {
        if ($this->query('SELECT id FROM inbound_events WHERE id=?',[$event])->fetchColumn()!==false) { return; }
        // Legacy Twilio signatures do not include a timestamp. Keep accepted
        // STOP identities so an old signed request cannot renew suppression
        // after an administrator has recorded newer consent.
        if ((int)$this->db->query('SELECT count(*) FROM inbound_events')->fetchColumn()>=100000) { throw new \RuntimeException('sms_opt_out_history_full'); }
        $this->query('INSERT INTO inbound_events VALUES (?,?)',[$event,$now]);
        $this->query('INSERT INTO opt_outs VALUES (?,?) ON CONFLICT(number_hash) DO UPDATE SET blocked_at=MAX(opt_outs.blocked_at,excluded.blocked_at)',[$numberHash,$now]);
    }
    /** Signed human replies still require current saved consent after any STOP event. */
    public function allowsHumanReply(string $numberHash,int $consentAt): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$numberHash) || $consentAt<1) { return false; }
        $this->verifyStorage();
        $blocked=$this->query('SELECT blocked_at FROM opt_outs WHERE number_hash=?',[$numberHash])->fetchColumn();
        return $blocked===false || $consentAt>(int)$blocked;
    }
    public function stats(int $now): array
    {
        $this->verifyStorage(); $rows=$this->query('SELECT * FROM budgets WHERE period IN (?,?)',['D'.gmdate('Y-m-d',$now),'M'.gmdate('Y-m',$now)])->fetchAll();
        $blocked=$this->db->query('SELECT number_hash,blocked_at FROM opt_outs LIMIT 100001')->fetchAll();
        return ['periods'=>$rows,'opt_outs'=>$blocked];
    }
}
