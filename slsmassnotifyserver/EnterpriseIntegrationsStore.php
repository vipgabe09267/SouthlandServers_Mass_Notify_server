<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityStore.php';
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Private durable provider claims, reply bindings and enrolled sensor health. */
final class EnterpriseIntegrationsStore
{
    private const MARKER="SLS_ENTERPRISE_INTEGRATIONS_V1\n";
    private const ARCHIVE_ROWS_PER_SHARD=10000;
    private const ARCHIVE_BYTES_PER_SHARD=4194304;
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
    private static function exact(array $row,array $keys): bool
    { return count($row)===count($keys)&&!array_diff(array_keys($row),$keys); }
    private static function hash($value): bool
    { return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value); }
    private static function text($value,int $maximum,bool $empty=false): bool
    { return is_string($value)&&preg_match('//u',$value)===1&&strlen($value)<=$maximum&&($empty||$value!=='')&&!preg_match('/[\x00-\x1f\x7f]/',$value); }
    private static function bounded($value,int $depth,int &$nodes): void
    {
        if($depth>16||++$nodes>50000){throw new \RuntimeException('Integration journal exceeds its structural bound. Preserve replay evidence.');}
        if(is_string($value)){if(strlen($value)>8192||preg_match('//u',$value)!==1){throw new \RuntimeException('Integration journal contains invalid or oversized text. Preserve replay evidence.');}return;}
        if(is_int($value)||is_bool($value)||$value===null){return;}if(!is_array($value)){throw new \RuntimeException('Integration journal contains an unsupported value. Preserve replay evidence.');}
        foreach($value as $key=>$child){if(is_string($key)&&(strlen($key)>256||preg_match('/[\x00-\x1f\x7f]/',$key))){throw new \RuntimeException('Integration journal contains an invalid field name. Preserve replay evidence.');}self::bounded($child,$depth+1,$nodes);}
    }
    private static function meetingId(string $provider,$value): bool
    {
        return is_string($value)&&(bool)preg_match($provider==='zoom'?'/^[1-9][0-9]{0,19}$/D':($provider==='google_meet'?'/^spaces\/[A-Za-z0-9_-]{1,128}$/D':'/^[A-Za-z0-9_-]{1,256}$/D'),$value);
    }
    private static function meetingUrl(string $provider,$value): bool
    {
        if(!self::text($value,2048)){return false;}$parts=parse_url($value);if(!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(($parts['port']??443)!==443)){return false;}
        $host=strtolower((string)($parts['host']??''));return $provider==='google_meet'?$host==='meet.google.com':(bool)preg_match($provider==='zoom'?'/^(?:[a-z0-9-]+\.)*zoom\.us$/D':'/^(?:[a-z0-9-]+\.)*webex\.com$/D',$host);
    }
    private static function operation(string $id,$row,bool $archived=false): void
    {
        $base=['request_id','kind','fingerprint','state','created_at'];
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_array($row)||array_is_list($row)||($row['request_id']??null)!==$id||!self::hash($row['fingerprint']??null)
            ||!in_array($row['kind']??null,['meeting','door','ipaws'],true)||!in_array($row['state']??null,['submitting','accepted','provider_reported','failed','uncertain','resolved'],true)
            ||!is_int($row['created_at']??null)||$row['created_at']<1){throw new \RuntimeException('Integration operation journal is invalid. Preserve replay evidence.');}
        $state=$row['state'];if($state==='submitting'){if($archived||!self::exact($row,$base)){throw new \RuntimeException('Integration operation journal is invalid. Preserve replay evidence.');}return;}
        if(!is_int($row['finished_at']??null)||$row['finished_at']<$row['created_at']||!self::text($row['detail']??null,1000)){throw new \RuntimeException('Integration operation receipt is invalid. Preserve replay evidence.');}
        if(in_array($state,['failed','uncertain'],true)){if(($archived&&$state==='uncertain')||!self::exact($row,array_merge($base,['detail','finished_at']))){throw new \RuntimeException('Integration operation receipt is invalid. Preserve replay evidence.');}return;}
        if($state==='resolved'){
            $resolvedKeys=array_merge($base,['resolution','evidence_reference','resolved_by','detail','finished_at','resolved_at']);
            $lateKeys=array_merge($resolvedKeys,['late_outcome_hash','late_outcome_at']);
            if((!self::exact($row,$resolvedKeys)&&!self::exact($row,$lateKeys))
                ||!in_array($row['resolution']??null,['confirmed_completed','confirmed_not_completed','abandoned_unconfirmed'],true)
                ||!self::text($row['evidence_reference']??null,256)||!self::hash($row['resolved_by']??null)
                ||!is_int($row['resolved_at']??null)||$row['resolved_at']<$row['finished_at']
                ||(isset($row['late_outcome_hash'])&&(!self::hash($row['late_outcome_hash'])||!is_int($row['late_outcome_at']??null)||$row['late_outcome_at']<$row['resolved_at']))){
                throw new \RuntimeException('Integration uncertainty resolution is invalid. Preserve replay evidence.');
            }return;
        }
        if($state==='accepted'&&$row['kind']==='meeting'){
            $provider=$row['provider']??'';if(!self::exact($row,array_merge($base,['provider','provider_id','join_url','detail','finished_at']))||!in_array($provider,['zoom','google_meet','webex'],true)
                ||!self::meetingId($provider,$row['provider_id']??null)||!self::meetingUrl($provider,$row['join_url']??null)){throw new \RuntimeException('Integration meeting receipt is invalid. Preserve replay evidence.');}return;
        }
        if($state==='accepted'&&$row['kind']==='door'){
            if(!self::exact($row,array_merge($base,['door_id','operation','detail','finished_at']))||!is_string($row['door_id']??null)||!preg_match('/^door_[a-f0-9]{24}$/D',$row['door_id'])||!in_array($row['operation']??null,['lock','unlock','access'],true)){throw new \RuntimeException('Integration door receipt is invalid. Preserve replay evidence.');}return;
        }
        if($state==='provider_reported'&&$row['kind']==='ipaws'){
            $codes=$row['status_codes']??null;if(!self::exact($row,array_merge($base,['status_codes','detail','finished_at']))||!is_array($codes)||!array_is_list($codes)||!$codes||count($codes)>100){throw new \RuntimeException('Integration public-warning receipt is invalid. Preserve replay evidence.');}
            $seen=[];foreach($codes as $code){if(!is_string($code)||!preg_match('/^[0-9]{1,4}$/D',$code)||isset($seen[$code])){throw new \RuntimeException('Integration public-warning receipt is invalid. Preserve replay evidence.');}$seen[$code]=true;}return;
        }
        throw new \RuntimeException('Integration operation state does not match its receipt. Preserve replay evidence.');
    }
    /** Validate one provider result before it is joined to a durable claim. */
    public static function assertOutcome(string $kind,array $outcome): void
    {
        if(array_intersect(array_keys($outcome),['request_id','kind','fingerprint','created_at','finished_at'])){
            throw new \RuntimeException('Provider receipt attempted to replace claim-owned fields.');
        }
        $id=str_repeat('a',32);
        self::operation($id,array_replace(['request_id'=>$id,'kind'=>$kind,'fingerprint'=>str_repeat('b',64),
            'state'=>'submitting','created_at'=>1],$outcome,['finished_at'=>1]));
    }
    private static function response(string $id,$row,bool $archived=false): void
    {
        if(!self::hash($id)||!is_array($row)||array_is_list($row)||!self::hash($row['fingerprint']??null)||!in_array($row['state']??null,['pending','complete'],true)||!is_int($row['created_at']??null)||$row['created_at']<1){throw new \RuntimeException('Integration human response journal is invalid.');}
        if($row['state']==='pending'){if($archived||!self::exact($row,['fingerprint','state','created_at'])){throw new \RuntimeException('Integration human response journal is invalid.');}}
        else{
            $legacy=self::exact($row,['fingerprint','state','created_at']);
            $current=self::exact($row,['fingerprint','state','created_at','completed_at'])&&is_int($row['completed_at']??null)&&$row['completed_at']>=$row['created_at'];
            if(!$legacy&&!$current){throw new \RuntimeException('Integration human response journal is invalid.');}
        }
    }
    private static function validate(array $state): void
    {
        if(!self::exact($state,['schema','operations','bindings','responses','sensors'])||($state['schema']??null)!==1){throw new \RuntimeException('Integration journal schema is unsupported. Preserve its state.');}
        $nodes=0;self::bounded($state,0,$nodes);
        foreach(['operations'=>500,'bindings'=>2000,'responses'=>4000,'sensors'=>100] as $key=>$maximum){if(!is_array($state[$key])||($state[$key]&&array_is_list($state[$key]))||count($state[$key])>$maximum){throw new \RuntimeException('Integration journal is invalid or exceeds its bound. Preserve its state.');}}
        foreach($state['operations'] as $id=>$row){self::operation((string)$id,$row);}
        foreach($state['bindings'] as $id=>$row){$keys=['id','token','identity_fingerprint','created_at','expires_at','incident_id','person_id','person_fingerprint','channel','recipient_id','number_hash','provider','route','config_fingerprint'];
            if(!self::hash($id)||!is_array($row)||array_is_list($row)||!self::exact($row,$keys)||($row['id']??null)!==$id||!is_string($row['token']??null)||!preg_match('/^[A-F0-9]{12}$/D',$row['token'])
                ||!is_string($row['incident_id']??null)||!preg_match('/^inc_[a-f0-9]{32}$/D',$row['incident_id'])||!is_string($row['person_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$row['person_id'])||!in_array($row['channel']??null,['sms','voice'],true)
                ||!in_array($row['provider']??null,['twilio','telnyx','pbx'],true)||!is_string($row['recipient_id']??null)||!preg_match('/^(?:sms|voice)_[a-f0-9]{24}$/D',$row['recipient_id'])
                ||!self::hash($row['identity_fingerprint']??null)||!self::hash($row['number_hash']??null)||!self::hash($row['person_fingerprint']??null)||!self::hash($row['config_fingerprint']??null)
                ||!self::text($row['route']??null,512,true)||!is_int($row['created_at']??null)||$row['created_at']<1||!is_int($row['expires_at']??null)||$row['expires_at']<=$row['created_at']||$row['expires_at']>$row['created_at']+86400){throw new \RuntimeException('Integration reply journal is invalid. Preserve participant bindings.');}}
        foreach($state['responses'] as $id=>$row){self::response((string)$id,$row);}
        foreach($state['sensors'] as $id=>$row){if(!is_array($row)||array_is_list($row)||!self::exact($row,['request_id','fingerprint','sent_at','last_seen','is_test','rule_revision'])
                ||!preg_match('/^trg_[a-f0-9]{24}$/D',(string)$id)||!is_string($row['request_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$row['request_id'])||!self::hash($row['fingerprint']??null)||!self::hash($row['rule_revision']??null)
                ||!is_int($row['sent_at']??null)||$row['sent_at']<1||!is_int($row['last_seen']??null)||$row['last_seen']<1||!is_bool($row['is_test']??null)){throw new \RuntimeException('Integration sensor journal is invalid.');}}
        if(strlen(json_encode($state,JSON_THROW_ON_ERROR))>1800000){throw new \RuntimeException('Integration journal exceeds its private storage budget.');}
    }
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
        self::validate($state);
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
            InitializationJournal::initializeStore($this->files, 'enterprise-integrations', self::initial(), $this->parentMarker, self::MARKER, $lock);
            $state=$this->read(); $original=$state; $result=$operation($state);self::validate($state);
            if ($state!==$original) { $this->files->atomic('worker-state.json',$state); }
            return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public static function requireAllocation(array $state,string $section,string $id,array $row): void
    {
        $limits=['operations'=>500,'bindings'=>2000,'responses'=>4000];
        if(!isset($limits[$section])||isset($state[$section][$id])){return;}
        if(count($state[$section])>=$limits[$section]){throw new \DomainException('Integration '.$section.' capacity is full. Archive completed evidence or resolve reviewed uncertainty before submitting another action.');}
        $projected=$state;$projected[$section][$id]=$row;
        try{self::validate($projected);}catch(\RuntimeException $error){throw new \DomainException('Integration journal capacity cannot safely accept this action. Archive completed evidence or resolve reviewed uncertainty first.',0,$error);}
    }
    private function archivePresent(): bool
    {
        return is_dir($this->directory.'/archive')||is_link($this->directory.'/archive')||file_exists($this->directory.'/.archive-required')||is_link($this->directory.'/.archive-required');
    }
    private static function fullLedger(string $kind,string $id): string {return $kind.'_'.substr($id,0,2);}
    private static function tombstoneLedger(string $kind,string $id): string {return ($kind==='operations'?'operation':'response').'_tombstones_'.substr($id,0,2);}
    private function archiveLedgers(string $kind,bool $tombstones=false): array
    {
        if(!$this->archivePresent()){return [];}$directory=$this->directory.'/archive';
        if(!is_dir($directory)||is_link($directory)){throw new \RuntimeException('Integration archive is unavailable. Preserve replay evidence.');}
        $entries=scandir($directory);if($entries===false){throw new \RuntimeException('Integration archive cannot be enumerated. Preserve replay evidence.');}
        $base=$kind==='operations'?'operation':'response';$pattern=$tombstones
            ?'/^'.preg_quote($base,'/').'_tombstones_[a-f0-9]{2}\.(?:json|required)$/D'
            :'/^'.preg_quote($kind,'/').'(?:_[a-f0-9]{2})?\.(?:json|required)$/D';
        $ledgers=[];foreach($entries as $entry){if(preg_match($pattern,$entry)){$ledgers[preg_replace('/\.(?:json|required)$/D','',$entry)]=true;}}
        $names=array_keys($ledgers);sort($names,SORT_STRING);return $names;
    }
    private static function evidenceHash(array $row): string
    {return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    private static function assertShardIdentity(string $ledger,string $id): void
    {
        if(preg_match('/_([a-f0-9]{2})$/D',$ledger,$match)&&!str_starts_with($id,$match[1])){throw new \RuntimeException('Integration archive record is stored in the wrong shard. Preserve replay evidence.');}
    }
    private static function tombstone(string $kind,string $id,array $row): array
    {
        if($kind==='operations'){
            self::operation($id,$row,true);return ['fingerprint'=>$row['fingerprint'],'kind'=>$row['kind'],'terminal_state'=>$row['state'],
                'created_at'=>$row['created_at'],'finished_at'=>max($row['finished_at'],$row['resolved_at']??0,$row['late_outcome_at']??0),'evidence_hash'=>self::evidenceHash($row)];
        }
        self::response($id,$row,true);return ['fingerprint'=>$row['fingerprint'],'created_at'=>$row['created_at'],
            'completed_at'=>$row['completed_at']??$row['created_at'],'evidence_hash'=>self::evidenceHash($row)];
    }
    private static function assertTombstone(string $kind,string $id,$row): void
    {
        $validId=$kind==='operations'?preg_match('/^[a-f0-9]{32}$/D',$id):preg_match('/^[a-f0-9]{64}$/D',$id);
        if(!$validId||!is_array($row)||array_is_list($row)||!self::hash($row['fingerprint']??null)||!self::hash($row['evidence_hash']??null)
            ||!is_int($row['created_at']??null)||$row['created_at']<1){throw new \RuntimeException('Integration replay tombstone is invalid. Preserve replay evidence.');}
        if($kind==='operations'){
            if(!self::exact($row,['fingerprint','kind','terminal_state','created_at','finished_at','evidence_hash'])||!in_array($row['kind']??null,['meeting','door','ipaws'],true)
                ||!in_array($row['terminal_state']??null,['accepted','provider_reported','failed','resolved'],true)||!is_int($row['finished_at']??null)||$row['finished_at']<$row['created_at']){throw new \RuntimeException('Integration operation tombstone is invalid. Preserve replay evidence.');}
        }elseif(!self::exact($row,['fingerprint','created_at','completed_at','evidence_hash'])||!is_int($row['completed_at']??null)||$row['completed_at']<$row['created_at']){throw new \RuntimeException('Integration response tombstone is invalid. Preserve replay evidence.');}
    }
    private function readFullArchive(string $kind,string $id): ?array
    {
        if(!$this->archivePresent()){return null;}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$found=null;$available=array_fill_keys($this->archiveLedgers($kind),true);
        foreach([$kind,self::fullLedger($kind,$id)] as $ledger){if(!isset($available[$ledger])){continue;}$rows=$archive->read($ledger);foreach($rows as $rowId=>$row){self::assertShardIdentity($ledger,(string)$rowId);if($kind==='operations'){self::operation((string)$rowId,$row,true);}else{self::response((string)$rowId,$row,true);}}
            if(isset($rows[$id])){if($found!==null&&$found!==$rows[$id]){throw new \RuntimeException('Integration archive identity conflict. Preserve replay evidence.');}$found=$rows[$id];}}
        return $found;
    }
    private function readTombstone(string $kind,string $id): ?array
    {
        if(!$this->archivePresent()){return null;}$ledger=self::tombstoneLedger($kind,$id);if(!in_array($ledger,$this->archiveLedgers($kind,true),true)){return null;}
        $rows=(new EnterpriseIdentityStore($this->directory.'/archive'))->read($ledger);foreach($rows as $rowId=>$row){self::assertShardIdentity($ledger,(string)$rowId);self::assertTombstone($kind,(string)$rowId,$row);}return $rows[$id]??null;
    }
    public function archived(string $kind,string $id): ?array
    {
        $pattern=$kind==='operations'?'/^[a-f0-9]{32}$/D':($kind==='responses'?'/^[a-f0-9]{64}$/D':'');
        if($pattern===''||!preg_match($pattern,$id)){throw new \InvalidArgumentException('Invalid integration archive lookup.');}
        $full=$this->readFullArchive($kind,$id);$tombstone=$this->readTombstone($kind,$id);
        if($full!==null&&$tombstone!==null&&!hash_equals($tombstone['evidence_hash'],self::evidenceHash($full))){throw new \RuntimeException('Integration archive and replay tombstone conflict. Preserve both for review.');}
        if($full!==null){return $full;}if($tombstone===null){return null;}
        return $kind==='operations'
            ?['request_id'=>$id,'kind'=>$tombstone['kind'],'fingerprint'=>$tombstone['fingerprint'],'state'=>'archived','terminal_state'=>$tombstone['terminal_state'],'created_at'=>$tombstone['created_at'],'finished_at'=>$tombstone['finished_at'],'detail'=>'Full provider evidence aged into a replay tombstone. The original request remains blocked from replay.','evidence_hash'=>$tombstone['evidence_hash'],'tombstone'=>true]
            :['fingerprint'=>$tombstone['fingerprint'],'state'=>'complete','created_at'=>$tombstone['created_at'],'completed_at'=>$tombstone['completed_at'],'evidence_hash'=>$tombstone['evidence_hash'],'tombstone'=>true];
    }
    public function archiveReport(string $kind): array
    {
        if(!in_array($kind,['operations','responses'],true)){throw new \InvalidArgumentException('Invalid integration report kind.');}
        if(!$this->archivePresent()){return [];}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$result=[];$bytes=2;
        foreach($this->archiveLedgers($kind) as $ledger){$rows=$archive->read($ledger);foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);if($kind==='operations'){self::operation((string)$id,$row,true);}else{self::response((string)$id,$row,true);}
                if(isset($result[$id])&&$result[$id]!==$row){throw new \RuntimeException('Integration archive identity conflict. Preserve replay evidence.');}$result[$id]=$row;}
            $bytes+=strlen(json_encode((object)$rows,JSON_THROW_ON_ERROR));if(count($result)>20000||$bytes>16777216){throw new \RuntimeException('The browser activity export exceeds 20,000 rows or 16 MiB. Use the protected FreePBX backup for complete retained evidence.');}}
        return $result;
    }
    public function tombstoneReport(string $kind): array
    {
        if(!in_array($kind,['operations','responses'],true)){throw new \InvalidArgumentException('Invalid integration tombstone kind.');}
        if(!$this->archivePresent()){return [];}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$result=[];$bytes=2;
        foreach($this->archiveLedgers($kind,true) as $ledger){$rows=$archive->read($ledger);foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);self::assertTombstone($kind,(string)$id,$row);if(isset($result[$id])&&$result[$id]!==$row){throw new \RuntimeException('Integration replay tombstone conflict. Preserve replay evidence.');}$result[$id]=$row;}
            $bytes+=strlen(json_encode((object)$rows,JSON_THROW_ON_ERROR));if(count($result)>20000||$bytes>16777216){throw new \RuntimeException('The browser tombstone export exceeds 20,000 rows or 16 MiB. Use the protected FreePBX backup for complete replay evidence.');}}
        return $result;
    }
    public function usage(): array
    {
        $state=$this->read();$usage=[];
        foreach (['operations'=>500,'bindings'=>2000,'responses'=>4000,'sensors'=>100] as $kind=>$maximum) {
            $usage[$kind]=['used'=>count($state[$kind]),'maximum'=>$maximum,'needs_attention'=>count($state[$kind])>=$maximum*.8];
        }
        foreach(['operations','responses'] as $kind){
            foreach([false,true] as $tombstones){$used=0;$bytes=0;$near=false;$shards=$this->archiveLedgers($kind,$tombstones);if($shards){$archive=new EnterpriseIdentityStore($this->directory.'/archive');foreach($shards as $ledger){$rows=$archive->read($ledger);foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);if($tombstones){self::assertTombstone($kind,(string)$id,$row);}elseif($kind==='operations'){self::operation((string)$id,$row,true);}else{self::response((string)$id,$row,true);}}$shardBytes=strlen(json_encode((object)$rows,JSON_THROW_ON_ERROR));$used+=count($rows);$bytes+=$shardBytes;$near=$near||count($rows)>=self::ARCHIVE_ROWS_PER_SHARD*.8||$shardBytes>=self::ARCHIVE_BYTES_PER_SHARD*.8;}}
                $key=($tombstones?'tombstone_':'archive_').$kind;$maximum=max(1,count($shards))*self::ARCHIVE_ROWS_PER_SHARD;$maximumBytes=max(1,count($shards))*self::ARCHIVE_BYTES_PER_SHARD;
                $usage[$key]=['used'=>$used,'maximum'=>$maximum,'bytes'=>$bytes,'maximum_bytes'=>$maximumBytes,'shards'=>count($shards),'needs_attention'=>false];
                if($shards){$usage[$key]['needs_attention']=$near;}
            }
        }
        return $usage;
    }
    private function writeArchived(string $kind,array $eligible): void
    {
        if(!$eligible){return;}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$groups=[];
        foreach($eligible as $id=>$row){if($kind==='operations'){self::operation((string)$id,$row,true);}else{self::response((string)$id,$row,true);}$groups[self::fullLedger($kind,(string)$id)][$id]=$row;}
        foreach($groups as $ledger=>$records){$archive->transaction($ledger,static function(array &$rows)use($kind,$records,$ledger):void{
                foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);if($kind==='operations'){self::operation((string)$id,$row,true);}else{self::response((string)$id,$row,true);}}
                foreach($records as $id=>$row){if(isset($rows[$id])&&$rows[$id]!==$row){throw new \RuntimeException('Archive identity conflict. Live history was preserved.');}$rows[$id]=$row;}
                $bytes=strlen(json_encode((object)$rows,JSON_THROW_ON_ERROR));if(count($rows)>self::ARCHIVE_ROWS_PER_SHARD||$bytes>self::ARCHIVE_BYTES_PER_SHARD){throw new \RuntimeException('An integration evidence shard is full. Export and preserve its protected backup before reducing retained full-detail history.');}
            });}
    }
    private function writeTombstones(string $kind,array $eligible): void
    {
        if(!$eligible){return;}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$groups=[];
        foreach($eligible as $id=>$row){$groups[self::tombstoneLedger($kind,(string)$id)][$id]=self::tombstone($kind,(string)$id,$row);}
        foreach($groups as $ledger=>$records){$archive->transaction($ledger,static function(array &$rows)use($kind,$records,$ledger):void{
                foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);self::assertTombstone($kind,(string)$id,$row);}foreach($records as $id=>$row){if(isset($rows[$id])&&$rows[$id]!==$row){throw new \RuntimeException('Replay tombstone identity conflict. Full evidence was preserved.');}$rows[$id]=$row;}
                $bytes=strlen(json_encode((object)$rows,JSON_THROW_ON_ERROR));if(count($rows)>self::ARCHIVE_ROWS_PER_SHARD||$bytes>self::ARCHIVE_BYTES_PER_SHARD){throw new \RuntimeException('An integration replay-tombstone shard is full. Preserve its protected backup and review retention capacity before continuing.');}
            });}
    }
    private function compactArchivedEvidence(string $kind,int $now,int $evidenceRetentionSeconds): int
    {
        if(!$this->archivePresent()){return 0;}$archive=new EnterpriseIdentityStore($this->directory.'/archive');$removed=0;
        foreach($this->archiveLedgers($kind) as $ledger){$rows=$archive->read($ledger);$eligible=[];
            foreach($rows as $id=>$row){self::assertShardIdentity($ledger,(string)$id);if($kind==='operations'){self::operation((string)$id,$row,true);$finished=max($row['finished_at'],$row['resolved_at']??0,$row['late_outcome_at']??0);}else{self::response((string)$id,$row,true);$finished=$row['completed_at']??$row['created_at'];}
                if($finished<=$now-$evidenceRetentionSeconds){$eligible[$id]=$row;}}
            if(!$eligible){continue;}$this->writeTombstones($kind,$eligible);
            $archive->transaction($ledger,static function(array &$current)use($eligible):void{foreach($eligible as $id=>$row){if(isset($current[$id])&&$current[$id]!==$row){throw new \RuntimeException('Archived evidence changed during tombstone compaction. Preserve both copies.');}unset($current[$id]);}});$removed+=count($eligible);
        }return $removed;
    }
    public function resolveUncertain(string $id,string $resolution,string $evidenceReference,string $resolvedBy,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($resolution,['confirmed_completed','confirmed_not_completed','abandoned_unconfirmed'],true)
            ||!self::text($evidenceReference,256)||!self::hash($resolvedBy)||$now<1){throw new \InvalidArgumentException('Choose an uncertain operation, a reviewed outcome and a bounded evidence reference.');}
        return $this->transaction(static function(array &$state)use($id,$resolution,$evidenceReference,$resolvedBy,$now):array{
            $row=$state['operations'][$id]??null;if(!$row||$row['state']!=='uncertain'){throw new \DomainException('Only a live uncertain operation can be resolved. Completed, pending and archived requests remain unchanged.');}
            if($row['finished_at']>$now-300){throw new \DomainException('Wait five minutes after the uncertain result before recording external evidence. This prevents resolution from racing an active provider request.');}
            $details=['confirmed_completed'=>'Administrator reviewed external evidence and confirmed completion. The request remains blocked from replay.',
                'confirmed_not_completed'=>'Administrator reviewed external evidence and confirmed no completion. The original request remains blocked from replay; use a new request identifier for any reviewed follow-up.',
                'abandoned_unconfirmed'=>'Administrator documented that completion remains unknown and closed local review. The request remains blocked from replay.'];
            $row['state']='resolved';$row['resolution']=$resolution;$row['evidence_reference']=$evidenceReference;$row['resolved_by']=$resolvedBy;$row['detail']=$details[$resolution];$row['resolved_at']=$now;
            $state['operations'][$id]=$row;return $row;
        });
    }
    /** Archive full evidence first, then compact old detail to immutable replay tombstones. */
    public function maintain(int $now,int $retentionSeconds=604800,int $evidenceRetentionSeconds=2592000): array
    {
        if ($now<1||$retentionSeconds<86400||$retentionSeconds>31536000||$evidenceRetentionSeconds<86400||$evidenceRetentionSeconds>31536000) { throw new \InvalidArgumentException('Live and full-evidence retention must each be one day through one year.'); }
        return $this->transaction(function(array &$state) use($now,$retentionSeconds,$evidenceRetentionSeconds): array {
            $counts=['operations'=>0,'responses'=>0,'tombstoned_operations'=>$this->compactArchivedEvidence('operations',$now,$evidenceRetentionSeconds),'tombstoned_responses'=>$this->compactArchivedEvidence('responses',$now,$evidenceRetentionSeconds),'expired_bindings'=>0];
            foreach (['operations','responses'] as $kind) {
                $eligible=array_filter($state[$kind],static function(array $row)use($kind,$now,$retentionSeconds):bool{$terminal=$kind==='operations'
                    ?max($row['finished_at']??$now,$row['resolved_at']??0,$row['late_outcome_at']??0):($row['completed_at']??$row['created_at']??$now);return $terminal>0&&$terminal<=$now-$retentionSeconds
                    && ($kind==='operations'?in_array($row['state'],['accepted','provider_reported','failed','resolved'],true):$row['state']==='complete');});
                if (!$eligible) { continue; }
                $this->writeArchived($kind,$eligible);
                foreach ($eligible as $id=>$row) { unset($state[$kind][$id]); }$counts[$kind]=count($eligible);
            }
            foreach ($state['bindings'] as $id=>$row) { if ($row['expires_at']<=$now) { unset($state['bindings'][$id]);$counts['expired_bindings']++; } }
            return ['success'=>true,'archived'=>$counts,'meaning'=>'Full evidence is retained for the selected window, then reduced to immutable replay tombstones. Pending and uncertain operations remain live until reviewed.'];
        });
    }
}
