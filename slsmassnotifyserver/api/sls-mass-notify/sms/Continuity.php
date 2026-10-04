<?php
declare(strict_types=1);
namespace SLS\MassNotify\Sms;

/** Portable data only: a restore never executes SQL from a backup or replays a send. */
trait Continuity
{
    public const BACKUP_MAX_BYTES=134217728;
    private const BACKUP_FIELDS=[
        'deliveries'=>['id','provider','route','recipient_id','job_id','number_hash','body_hash','created_at','expires_at','updated_at','state','provider_id','segments','cost_micros','currency','error_code'],
        'budgets'=>['period','segments','amount','currency'], 'opt_outs'=>['number_hash','blocked_at'], 'inbound_events'=>['id','created_at'],
        'provider_errors'=>['id','detail'], 'message_formats'=>['id','message_type','media_hash']];
    public function exportSnapshot(string $path): void
    {
        $this->verifyStorage(); $mask=umask(0077);
        try { $handle=@fopen($path,'x+b'); } finally { umask($mask); }
        if (!$handle) { throw new \RuntimeException('sms_backup_create_failed'); }
        try {
            $bytes=0;
            $write=static function(array $record) use ($handle,&$bytes) {
                $line=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; $bytes+=strlen($line);
                if ($bytes>self::BACKUP_MAX_BYTES || fwrite($handle,$line)!==strlen($line)) { throw new \RuntimeException('sms_backup_write_failed'); }
            };
            $this->db->exec('BEGIN');
            try {
                $mms=(int)$this->db->query("SELECT count(*) FROM message_formats WHERE message_type='MMS'")->fetchColumn()>0;
                $write(['schema'=>$mms?'sls-sms-continuity-v2':'sls-sms-continuity-v1']);
                foreach (self::BACKUP_FIELDS as $table=>$fields) {
                    if ($table==='message_formats' && !$mms) { continue; }
                    $rows=$this->query('SELECT '.implode(',',$fields).' FROM '.$table.' ORDER BY '.$fields[0]);
                    while (($row=$rows->fetch())!==false) { $write(['table'=>$table,'row'=>$row]); }
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
            if (!fflush($handle) || !fsync($handle)) { throw new \RuntimeException('sms_backup_sync_failed'); }
        } catch (\Throwable $error) { fclose($handle); @unlink($path); throw $error; }
        fclose($handle);
    }
    private static function validateBackupRow(string $table, array $row): void
    {
        $fields=self::BACKUP_FIELDS[$table]??[];
        if (!$fields || count($row)!==count($fields) || array_diff($fields,array_keys($row))) { throw new \DomainException('sms_backup_invalid_fields'); }
        foreach ($row as $key=>$value) {
            if ($table==='provider_errors' && $key==='detail') {
                if (!is_string($value) || !preg_match('//u',$value) || mb_strlen($value)>384 || preg_match('/\p{C}/u',$value)) {
                    throw new \DomainException('sms_backup_invalid_provider_detail');
                }
                continue;
            }
            if (in_array($key,['created_at','expires_at','updated_at','blocked_at','segments','cost_micros','amount'],true)) {
                if (!is_int($value) || $value<0 || $value>1000000000000000) { throw new \DomainException('sms_backup_invalid_number'); }
                if (str_ends_with($key,'_at') && ($value<1 || $value>4102444800)) { throw new \DomainException('sms_backup_invalid_time'); }
            } elseif (!is_string($value) || strlen($value)>100 || !preg_match('/^[A-Za-z0-9_-]*$/D',$value)) { throw new \DomainException('sms_backup_invalid_text'); }
        }
        foreach (['route','number_hash','body_hash'] as $key) {
            if (isset($row[$key]) && !preg_match('/^[a-f0-9]{64}$/D',$row[$key])) { throw new \DomainException('sms_backup_invalid_hash'); }
        }
        if ($table==='provider_errors' && !preg_match('/^smsd_[a-f0-9]{64}$/D',$row['id'])) { throw new \DomainException('sms_backup_invalid_provider_detail'); }
        if ($table==='message_formats' && (!preg_match('/^smsd_[a-f0-9]{64}$/D',$row['id']) || !in_array($row['message_type'],['SMS','MMS'],true)
            || ($row['message_type']==='MMS' && !preg_match('/^[a-f0-9]{64}$/D',$row['media_hash']))
            || ($row['message_type']==='SMS' && $row['media_hash']!==''))) { throw new \DomainException('sms_backup_invalid_format'); }
        if (isset($row['currency']) && !preg_match('/^[A-Z]{3}$/D',$row['currency'])) { throw new \DomainException('sms_backup_invalid_currency'); }
        if ($table==='deliveries' && (!preg_match('/^smsd_[a-f0-9]{64}$/D',$row['id']) || !Config::id($row['recipient_id'])
            || !preg_match('/^job_[a-f0-9]{32}$/D',$row['job_id']) || !in_array($row['provider'],['twilio','telnyx','bulkvs'],true)
            || ($row['provider_id']!=='' && !Provider::providerId($row['provider'],$row['provider_id']))
            || !in_array($row['state'],['submitting','accepted','sent','delivered','failed','uncertain'],true)
            || $row['segments']<1 || $row['segments']>10 || $row['cost_micros']<1 || $row['cost_micros']>1000000000
            || strlen($row['error_code'])>64)) { throw new \DomainException('sms_backup_invalid_delivery'); }
        if ($table==='budgets' && !preg_match('/^(?:D[0-9]{4}-[0-9]{2}-[0-9]{2}|M[0-9]{4}-[0-9]{2})$/D',$row['period'])) { throw new \DomainException('sms_backup_invalid_period'); }
        if ($table==='inbound_events' && $row['id']==='') { throw new \DomainException('sms_backup_invalid_event'); }
    }
    /** Merge conservatively. Existing claims/opt-outs/charges are never removed. */
    public function mergeSnapshot(string $path): void
    {
        clearstatcache(true,$path); $meta=@lstat($path);
        if (!$meta || ($meta['mode']&0170000)!==0100000 || $meta['nlink']!==1 || $meta['size']<1 || $meta['size']>self::BACKUP_MAX_BYTES) { throw new \DomainException('sms_backup_file_invalid'); }
        $handle=@fopen($path,'r+b');
        if (!$handle) { throw new \RuntimeException('sms_backup_unreadable'); }
        try {
            $opened=fstat($handle);
            if ($opened['dev']!==$meta['dev'] || $opened['ino']!==$meta['ino']) { throw new \RuntimeException('sms_backup_changed'); }
            $this->transaction(function () use ($handle) {
                $header=json_decode((string)fgets($handle,4097),true,4,JSON_THROW_ON_ERROR);
                if (!in_array($header,[['schema'=>'sls-sms-continuity-v1'],['schema'=>'sls-sms-continuity-v2']],true)) { throw new \DomainException('sms_backup_schema_unsupported'); }
                $seen=[]; $counts=[]; $bytes=0;
                while (($line=fgets($handle,4097))!==false) {
                    $bytes+=strlen($line);
                    if ($bytes>self::BACKUP_MAX_BYTES || !str_ends_with($line,"\n")) { throw new \DomainException('sms_backup_truncated_or_oversized'); }
                    $entry=json_decode($line,true,5,JSON_THROW_ON_ERROR);
                    if (!is_array($entry) || count($entry)!==2 || !is_string($entry['table']??null) || !is_array($entry['row']??null)) { throw new \DomainException('sms_backup_invalid_record'); }
                    $table=$entry['table']; $row=$entry['row']; self::validateBackupRow($table,$row);
                    if ($table==='message_formats' && $header['schema']!=='sls-sms-continuity-v2') { throw new \DomainException('sms_backup_schema_unsupported'); }
                    $fields=self::BACKUP_FIELDS[$table]; $id=$row[$fields[0]];
                    if (isset($seen[$table][$id])) { throw new \DomainException('sms_backup_duplicate_record'); }
                    $seen[$table][$id]=true; $counts[$table]=($counts[$table]??0)+1;
                    if ($counts[$table]>($table==='budgets'?10000:100000)) { throw new \DomainException('sms_backup_capacity_exceeded'); }
                    if ($table==='deliveries') {
                        $old=$this->get($id);
                        if ($old) {
                            foreach (['provider','route','recipient_id','number_hash','body_hash','currency','segments','cost_micros','created_at'] as $key) {
                                if ($old[$key]!==$row[$key]) { throw new \DomainException('sms_backup_delivery_conflict'); }
                            }
                            // The live ledger is authoritative, including receipts newer than this backup.
                            continue;
                        }
                        if (!in_array($row['state'],['failed','delivered'],true)) { $row['state']='uncertain'; $row['error_code']='sms_restored_without_replay'; }
                        $this->query('INSERT INTO deliveries ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',array_map(static fn($field)=>$row[$field],$fields));
                    } elseif ($table==='budgets') {
                        $this->mergeBudget($row['period'],$row['segments'],$row['amount'],$row['currency']);
                    } elseif ($table==='opt_outs') {
                        $this->query('INSERT INTO opt_outs VALUES (?,?) ON CONFLICT(number_hash) DO UPDATE SET blocked_at=max(blocked_at,excluded.blocked_at)',[$row['number_hash'],$row['blocked_at']]);
                    } elseif ($table==='provider_errors') {
                        if (!$this->get($id)) { throw new \DomainException('sms_backup_provider_detail_without_delivery'); }
                        $this->query('INSERT INTO provider_errors VALUES (?,?) ON CONFLICT(id) DO NOTHING',[$id,$row['detail']]);
                    } elseif ($table==='message_formats') {
                        $delivery=$this->get($id);
                        if (!$delivery || ($row['message_type']==='MMS' && $delivery['segments']!==1)) { throw new \DomainException('sms_backup_format_delivery_missing'); }
                        $existing=$this->query('SELECT * FROM message_formats WHERE id=?',[$id])->fetch();
                        if ($existing && ($existing['message_type']!==$row['message_type'] || $existing['media_hash']!==$row['media_hash'])) { throw new \DomainException('sms_backup_format_conflict'); }
                        $this->query('INSERT INTO message_formats VALUES (?,?,?) ON CONFLICT(id) DO NOTHING',[$id,$row['message_type'],$row['media_hash']]);
                    } else {
                        $this->query('INSERT INTO inbound_events VALUES (?,?) ON CONFLICT(id) DO UPDATE SET created_at=max(created_at,excluded.created_at)',[$row['id'],$row['created_at']]);
                    }
                }
                if (!feof($handle)) { throw new \RuntimeException('sms_backup_read_failed'); }
                // Union of disjoint live/backup deliveries may exceed either original counter.
                foreach (['D%Y-%m-%d','M%Y-%m'] as $format) {
                    $rows=$this->query("SELECT strftime(?,created_at,'unixepoch') AS period,sum(segments) AS segments,sum(cost_micros) AS amount,currency FROM deliveries GROUP BY period,currency",[$format]);
                    while (($row=$rows->fetch())!==false) { $this->mergeBudget($row['period'],(int)$row['segments'],(int)$row['amount'],$row['currency']); }
                }
                foreach (self::BACKUP_FIELDS as $table=>$fields) {
                    if ((int)$this->db->query('SELECT count(*) FROM '.$table)->fetchColumn()>($table==='budgets'?10000:100000)) { throw new \DomainException('sms_backup_merged_capacity_exceeded'); }
                }
            });
        } finally { fclose($handle); }
    }
    private function mergeBudget(string $period,int $segments,int $amount,string $currency): void
    {
        $old=$this->query('SELECT currency FROM budgets WHERE period=?',[$period])->fetchColumn();
        if ($old!==false && $old!==$currency) { throw new \DomainException('sms_backup_budget_currency_conflict'); }
        $this->query('INSERT INTO budgets VALUES (?,?,?,?) ON CONFLICT(period) DO UPDATE SET segments=max(segments,excluded.segments),amount=max(amount,excluded.amount)',[$period,$segments,$amount,$currency]);
    }
}
