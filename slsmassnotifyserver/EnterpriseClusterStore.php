<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterConfig.php';
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Private fsynced journals; local flock protects a node, never elects a leader. */
final class EnterpriseClusterStore
{
    public const MAX_STATE_BYTES=700000;
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-cluster';
    public const REQUIRED_MARKER='cluster-required.json';
    public const PARENT_REQUIRED_MARKER='.enterprise-cluster-required.json';
    private \SlsAnnouncementJobStore $files;
    private \SlsAnnouncementJobStore $parentFiles;
    private array $identity;
    private array $peerIds;
    private string $directory;
    public function __construct(array $config,string $directory=self::DIRECTORY)
    {
        $this->identity=['schema'=>1,'cluster_id'=>$config['cluster_id'],'node_id'=>$config['node_id'],'witness_epoch'=>$config['witness_epoch']];
        $this->peerIds=[];
        foreach ($config['peers']??[] as $peer) { if (is_array($peer) && is_string($peer['node_id']??null)) { $this->peerIds[]=$peer['node_id']; } }
        $this->directory=$directory;
        $this->files=new \SlsAnnouncementJobStore($directory);
        $this->parentFiles=new \SlsAnnouncementJobStore(dirname($directory));
    }
    public function initial(): array
    {
        return $this->identity+['revision'=>0,'last_time'=>0,'lease'=>null,'retired'=>[], 'nonces'=>[], 'effects'=>[], 'effect_history'=>[],
            'replica'=>['jobs'=>[],'schedules'=>[],'incidents'=>[],'events'=>[],'receipts'=>[]],
            'snapshot_revisions'=>[], 'replica_provenance'=>[], 'replica_outbox'=>[], 'replica_consumed'=>[], 'mirrors'=>[], 'queue'=>[], 'cache'=>[], 'consumed'=>[],
            'control'=>['stopped'=>false,'spent_cents'=>0,'spend_limit_cents'=>0], 'boot_ids'=>[], 'replay_journal_required'=>true];
    }
    private static function validateEdgeReceipt(array $receipt,string $device,array $event): void
    {
        $invalid=static function(): void { throw new \RuntimeException('Cluster edge cache receipt schema is invalid.'); };
        $base=['device_id','kind','received_at','human_acknowledgment']; $actual=array_keys($receipt); $stages=$receipt['stages']??null;
        if ($stages!==null) { $base[]='stages'; }
        sort($base,SORT_STRING); sort($actual,SORT_STRING);
        if ($actual!==$base || ($receipt['device_id']??null)!==$device
            || !in_array($receipt['kind']??null,['application_received','displayed','audio_played'],true)
            || !is_int($receipt['received_at']??null) || $receipt['received_at']<$event['created_at'] || $receipt['received_at']>=$event['expires_at']
            || ($receipt['human_acknowledgment']??null)!==false) { $invalid(); }
        if ($stages===null) { return; }
        if (!is_array($stages) || array_is_list($stages) || !$stages || count($stages)>3) { $invalid(); }
        foreach ($stages as $kind=>$stage) {
            if (!is_string($kind) || !is_array($stage) || isset($stage['stages'])) { $invalid(); }
            self::validateEdgeReceipt($stage,$device,$event);
            if (($stage['kind']??null)!==$kind) { $invalid(); }
        }
        $summary=$receipt; unset($summary['stages']);
        if (($stages[$receipt['kind']]??null)!==$summary) { $invalid(); }
    }
    public function initialize(int $spendLimit): void
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \RuntimeException('Cluster journal is busy.'); }
        try {
            $state=$this->files->state(); $marker=$this->marker(); $parentMarker=$this->marker(true);
            if ($marker!==null || $parentMarker!==null) {
                if (!$state || $marker===null || $parentMarker===null) { throw new \RuntimeException('Established cluster evidence is missing. Preserve both required markers; restore complete authority/replay history or commission a separately reviewed new epoch. The same epoch cannot be reset.'); }
                $this->read(); return;
            }
            if ($state) { throw new \RuntimeException('Cluster state exists without its permanent required marker. Initialization is incomplete or recovery was partial; automatic authority is suspended.'); }
            $state=$this->initial(); $state['control']['spend_limit_cents']=$spendLimit;
            // Marker first: an interrupted first initialization cannot later be
            // mistaken for an unused empty journal and discard authority state.
            $this->parentFiles->atomic(self::PARENT_REQUIRED_MARKER,$this->identity);
            $this->files->atomic(self::REQUIRED_MARKER,$this->identity);
            $this->files->atomic('cluster-replay.json',['schema'=>1,'last_time'=>0,'nonces'=>[],
                'global'=>['tokens_milli'=>4096000,'updated_at'=>time()],'peers'=>[]]);
            $this->files->atomic('worker-state.json',$state);
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function read(): array
    {
        $marker=$this->marker(); $parentMarker=$this->marker(true);
        if ($marker!==$this->identity || $parentMarker!==$this->identity) { throw new \RuntimeException('A permanent cluster marker is missing or belongs to another node/epoch; reviewed recovery is required.'); }
        $s=$this->files->state();
        if (!is_array($s)) { throw new \RuntimeException('Required cluster state is missing or unreadable; reviewed recovery is required.'); }
        // Earlier Labs journals did not record witness-admitted replica
        // provenance. Preserve their data, but do not make any legacy queued
        // job recoverable until a current lease owner republishes it.
        if (!array_key_exists('replica_provenance',$s)) { $s['replica_provenance']=[]; }
        if (!array_key_exists('effect_history',$s)) { $s['effect_history']=[]; }
        if (!array_key_exists('replica_outbox',$s)) { $s['replica_outbox']=[]; }
        if (!array_key_exists('replica_consumed',$s)) { $s['replica_consumed']=[]; }
        if (!array_key_exists('consumed',$s)) { $s['consumed']=[]; }
        if (!array_key_exists('replay_journal_required',$s)) { $s['replay_journal_required']=false; }
        // Keep administrator reconciliation separate from the immutable
        // transport receipt. Older Labs journals are upgraded in memory and
        // persisted by the next bounded transaction.
        $effects=$s['effects']??null;
        if (is_array($effects)) {
            foreach ($s['effects'] as &$effect) {
                if (is_array($effect) && !array_key_exists('reconciliation',$effect)) { $effect['reconciliation']=null; }
            }
            unset($effect);
        }
        $this->validateState($s);
        return $s;
    }
    private function validateState(array $s): void
    {
        $expected=$this->initial();
        if (array_diff(array_keys($s),array_keys($expected)) || count($s)!==count($expected)
            || !is_int($s['revision']) || $s['revision']<0 || !is_int($s['last_time']) || $s['last_time']<0
            || !is_bool($s['replay_journal_required'])
            || !is_array($s['control'])) { throw new \RuntimeException('Cluster journal schema is invalid.'); }
        foreach ($this->identity as $key=>$value) {
            if (($s[$key]??null)!==$value) { throw new \RuntimeException('Cluster journal missing, damaged, or belongs to another node/epoch. Explicit recovery is required.'); }
        }
        foreach (['retired','nonces','effects','effect_history','replica','snapshot_revisions','replica_provenance','replica_outbox','replica_consumed','mirrors','queue','cache','consumed','boot_ids'] as $key) {
            if (!is_array($s[$key])) { throw new \RuntimeException('Cluster journal is invalid: '.$key); }
        }
        $replicaKeys=['jobs','schedules','incidents','events','receipts'];
        if (count($s['replica'])!==count($replicaKeys) || array_diff(array_keys($s['replica']),$replicaKeys)) {
            throw new \RuntimeException('Cluster replica journal schema is invalid.');
        }
        foreach ($replicaKeys as $kind) {
            if (!is_array($s['replica'][$kind])) { throw new \RuntimeException('Cluster replica journal schema is invalid.'); }
        }
        if (count($s['queue'])>500 || count($s['cache'])>500) { throw new \RuntimeException('Cluster queue or edge cache exceeds its hard bound.'); }
        foreach ($s['queue'] as $id=>$row) {
            EnterpriseClusterConfig::id((string)$id);
            if (!is_array($row) || count($row)!==4 || array_diff(array_keys($row),['job','state','received_at','receipt'])
                || !is_array($row['job']) || ($row['job']['id']??null)!==$id
                || !in_array($row['state'],['queued','uncertain','complete','expired'],true)
                || !is_int($row['received_at']) || $row['received_at']<1
                || ($row['receipt']!==null && (!is_array($row['receipt']) || strlen(EnterpriseClusterProtocol::canonical($row['receipt']))>262144))) {
                throw new \RuntimeException('Cluster queue schema is invalid.');
            }
            if (class_exists(EnterpriseClusterRuntime::class,false)) { EnterpriseClusterRuntime::job($row['job']); }
        }
        foreach ($s['cache'] as $id=>$event) {
            EnterpriseClusterConfig::id((string)$id);
            $row=$s['queue'][$id]??null; $job=is_array($row)?($row['job']??null):null;
            if (!is_array($event) || count($event)!==8
                || array_diff(array_keys($event),['job_id','delivery_id','expires_at','created_at','recipients','channels','intent','receipts'])
                || $event['job_id']!==$id || !is_int($event['expires_at']) || !is_int($event['created_at'])
                || $event['expires_at']<=$event['created_at'] || !is_array($event['recipients']) || !array_is_list($event['recipients'])
                || !is_array($event['channels']) || !array_is_list($event['channels']) || !is_array($event['intent']) || !is_array($event['receipts'])
                || !is_array($job) || $event['delivery_id']!==($job['delivery_id']??null) || $event['expires_at']!==($job['expires_at']??null)
                || $event['created_at']!==($job['created_at']??null) || $event['recipients']!==($job['recipients']??null)
                || $event['channels']!==($job['channels']??null) || $event['intent']!==($job['intent']??null)
                || strlen(EnterpriseClusterProtocol::canonical($event))>262144) { throw new \RuntimeException('Cluster edge cache schema is invalid.'); }
            if (!$event['recipients'] || count($event['recipients'])>1000 || count($event['channels'])<1 || count($event['channels'])>3
                || count(array_filter($event['channels'],'is_string'))!==count($event['channels'])
                || count(array_unique($event['channels'],SORT_STRING))!==count($event['channels'])
                || array_diff($event['channels'],['desktop','local_audio','local_display'])) { throw new \RuntimeException('Cluster edge cache audience is invalid.'); }
            foreach ($event['recipients'] as $recipient) { EnterpriseClusterConfig::id($recipient); }
            if (count(array_unique($event['recipients'],SORT_STRING))!==count($event['recipients'])) { throw new \RuntimeException('Cluster edge cache audience is invalid.'); }
            if (class_exists(EnterpriseClusterRuntime::class,false)) { EnterpriseClusterRuntime::edgeJobIntent($event['intent'],$event['channels']); }
            if (array_is_list($event['receipts']) && $event['receipts']!==[]) { throw new \RuntimeException('Cluster edge cache receipts are invalid.'); }
            foreach ($event['receipts'] as $device=>$receipt) {
                $device=(string)$device; EnterpriseClusterConfig::id($device);
                if (!in_array($device,$event['recipients'],true) || !is_array($receipt)) { throw new \RuntimeException('Cluster edge cache receipt identity is invalid.'); }
                self::validateEdgeReceipt($receipt,$device,$event);
            }
            $queuedReceipts=is_array($row['receipt']??null)?($row['receipt']['device_receipts']??[]):[];
            if (!is_array($queuedReceipts) || $queuedReceipts!==$event['receipts']) { throw new \RuntimeException('Cluster edge cache receipts are detached from the queue.'); }
        }
        foreach (['jobs','schedules','incidents','events'] as $kind) {
            if (count($s['replica'][$kind])>2000) { throw new \RuntimeException('Cluster replica collection exceeds its hard bound.'); }
            foreach ($s['replica'][$kind] as $id=>$record) {
                EnterpriseClusterConfig::id((string)$id);
                if (!is_array($record)) { throw new \RuntimeException('Cluster replica record is invalid.'); }
                if (class_exists(EnterpriseClusterRuntime::class,false)) { EnterpriseClusterRuntime::record($record); }
            }
        }
        if (count($s['replica']['receipts'])>2000) { throw new \RuntimeException('Cluster receipt replica collection exceeds its hard bound.'); }
        foreach ($s['replica']['receipts'] as $id=>$receipt) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id) || !is_array($receipt)
                || strlen(EnterpriseClusterProtocol::canonical($receipt))>65536
                || !isset($s['effects'][$id]) || ($s['effects'][$id]['receipt']??null)!==$receipt) {
                throw new \RuntimeException('Cluster receipt replica is detached or invalid.');
            }
        }
        self::validateAuthority($s);
        if (strlen(EnterpriseClusterProtocol::canonical($s))>self::MAX_STATE_BYTES) {
            throw new \RuntimeException('Cluster journal exceeds its recoverable signed-wire allocation; delivery is suspended pending reviewed compaction.');
        }
    }
    private static function validateAuthority(array $s): void
    {
        $invalid=static function(): void { throw new \RuntimeException('Cluster authority journal schema is damaged; delivery is suspended.'); };
        $exact=static fn(array $value,array $keys): bool=>count($value)===count($keys) && !array_diff(array_keys($value),$keys);
        $hex=static fn($value): bool=>is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1;
        if (!$exact($s['control'],['stopped','spent_cents','spend_limit_cents']) || !is_bool($s['control']['stopped'])
            || !is_int($s['control']['spent_cents']) || !is_int($s['control']['spend_limit_cents'])
            || $s['control']['spent_cents']<0 || $s['control']['spend_limit_cents']<$s['control']['spent_cents']
            || $s['control']['spend_limit_cents']>100000000) { $invalid(); }
        if ($s['lease']!==null) {
            $lease=$s['lease'];
            if (!is_array($lease) || !$exact($lease,['owner','boot_id','token','expires_at'])
                || !is_int($lease['token']) || $lease['token']<1 || !is_int($lease['expires_at']) || $lease['expires_at']<0
                || ($lease['boot_id']!=='' && !$hex($lease['boot_id']))) { $invalid(); }
            EnterpriseClusterConfig::id($lease['owner']);
        }
        if (count($s['effects'])>2000) { $invalid(); }
        foreach ($s['effects'] as $id=>$effect) {
            if (!$hex($id) || !is_array($effect) || !$exact($effect,['intent','owner','boot_id','token','state','started_at','receipt','reconciliation'])
                || !$hex($effect['boot_id']) || !is_int($effect['token']) || $effect['token']<0 || !is_int($effect['started_at']) || $effect['started_at']<1
                || !in_array($effect['state'],['prepared','uncertain','complete','reconciled'],true)
                || ($effect['receipt']!==null && (!is_array($effect['receipt']) || strlen(EnterpriseClusterProtocol::canonical($effect['receipt']))>65536))
                || ($effect['reconciliation']!==null && (!is_array($effect['reconciliation']) || strlen(EnterpriseClusterProtocol::canonical($effect['reconciliation']))>8192))) { $invalid(); }
            EnterpriseClusterConfig::id($effect['owner']); $intent=$effect['intent'];
            if (!is_array($intent) || !$exact($intent,['delivery_id','channel','target','site_id','created_at','expires_at','content_sha256','estimated_cost_cents','schedule_id','incident_id'])
                || !is_int($intent['created_at']) || !is_int($intent['expires_at']) || $intent['created_at']<1
                || $intent['expires_at']<=$intent['created_at'] || $intent['expires_at']>$intent['created_at']+900
                || !$hex($intent['content_sha256']) || !is_int($intent['estimated_cost_cents']) || $intent['estimated_cost_cents']<0
                || $intent['estimated_cost_cents']>10000000) { $invalid(); }
            foreach (['delivery_id','channel','target','site_id'] as $key) { EnterpriseClusterConfig::id($intent[$key]); }
            foreach (['schedule_id','incident_id'] as $key) { if ($intent[$key]!=='') { EnterpriseClusterConfig::id($intent[$key]); } }
            if (!hash_equals($id,hash('sha256',$intent['delivery_id']."\0".$intent['channel']."\0".$intent['target']."\0".$intent['site_id']))
                || ($effect['state']==='prepared' && ($effect['token']!==0 || $effect['receipt']!==null || $effect['reconciliation']!==null))
                || ($effect['state']==='complete' && (($effect['receipt']['uncertain']??null)!==false || $effect['reconciliation']!==null))
                || ($effect['state']==='uncertain' && $effect['reconciliation']!==null)
                || ($effect['state']==='reconciled' && (!is_array($effect['reconciliation']) || ($effect['reconciliation']['prior_node_fenced']??null)!==true))) { $invalid(); }
            if ($effect['reconciliation']!==null) {
                $review=$effect['reconciliation'];
                if (!$exact($review,['manual_reconciliation','prior_node_fenced','evidence','reviewed_by','reviewed_at'])
                    || $review['manual_reconciliation']!==true || $review['prior_node_fenced']!==true
                    || !is_string($review['evidence']) || strlen($review['evidence'])<20 || strlen($review['evidence'])>2048
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$review['evidence'])
                    || !is_int($review['reviewed_at']) || $review['reviewed_at']<$effect['started_at']) { $invalid(); }
                try { EnterpriseClusterConfig::id($review['reviewed_by']); } catch (\Throwable $error) { $invalid(); }
            }
        }
        if (count($s['effect_history'])>4096) { $invalid(); }
        $historyIdentities=0;
        foreach ($s['effect_history'] as $delivery=>$history) {
            if (!$hex($delivery) || !is_array($history) || !$exact($history,['expires_at','retain_until','closed','effects'])
                || !is_int($history['expires_at']) || $history['expires_at']<1 || !is_int($history['retain_until'])
                || $history['retain_until']<=$history['expires_at'] || !is_bool($history['closed']) || !is_array($history['effects'])
                || ($history['closed'] && $history['effects'])) { $invalid(); }
            $historyIdentities+=count($history['effects']); if ($historyIdentities>10000) { $invalid(); }
            foreach ($history['effects'] as $effectId=>$receiptHash) { if (!$hex($effectId) || !$hex($receiptHash)) { $invalid(); } }
        }
        foreach ($s['effects'] as $id=>$effect) {
            $delivery=hash('sha256',$effect['intent']['delivery_id']); $history=$s['effect_history'][$delivery]??null;
            if ($history && ($history['closed'] || $history['expires_at']!==$effect['intent']['expires_at'] || isset($history['effects'][$id]))) { $invalid(); }
        }
        if (!$exact($s['replica'],['jobs','schedules','incidents','events','receipts'])) { $invalid(); }
        foreach ($s['replica'] as $records) { if (!is_array($records)) { $invalid(); } }
        foreach ($s['nonces'] as $id=>$until) { if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}:[a-f0-9]{48}$/D',$id) || !is_int($until) || $until<1) { $invalid(); } }
        if ($s['replay_journal_required'] && ($s['nonces'] || $s['last_time']!==0)) { $invalid(); }
        foreach ($s['retired'] as $node=>$at) { EnterpriseClusterConfig::id((string)$node); if (!is_int($at) || $at<1) { $invalid(); } }
        foreach ($s['boot_ids'] as $node=>$boot) { EnterpriseClusterConfig::id((string)$node); if (!$hex($boot)) { $invalid(); } }
        if (count($s['snapshot_revisions'])>2000) { $invalid(); }
        foreach ($s['snapshot_revisions'] as $key=>$revision) {
            if (!is_string($key) || !preg_match('/^(?:job|jobs|schedule|schedules|incident|incidents|event|events):[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D',$key)
                || !is_int($revision) || $revision<1) { $invalid(); }
        }
        if (count($s['consumed'])>8192) { $invalid(); }
        foreach ($s['consumed'] as $digest=>$until) { if (!$hex($digest) || !is_int($until) || $until<1) { $invalid(); } }
        if (count($s['replica_provenance'])>2000) { $invalid(); }
        foreach ($s['replica_provenance'] as $key=>$proof) {
            if (!is_string($key) || !preg_match('/^(jobs|schedules|incidents|events):([A-Za-z0-9][A-Za-z0-9_.:-]{0,95})$/D',$key,$match)
                || !is_array($proof) || !$exact($proof,['source','boot_id','token','record_sha256','admitted_at'])
                || !$hex($proof['boot_id']) || !$hex($proof['record_sha256']) || !is_int($proof['token']) || $proof['token']<1
                || !is_int($proof['admitted_at']) || $proof['admitted_at']<1) { $invalid(); }
            EnterpriseClusterConfig::id($proof['source']);
            $record=$s['replica'][$match[1]][$match[2]]??null;
            if (!is_array($record) || !hash_equals($proof['record_sha256'],hash('sha256',EnterpriseClusterProtocol::canonical($record)))) { $invalid(); }
        }
        if (count($s['replica_outbox'])>500) { $invalid(); }
        foreach ($s['replica_outbox'] as $key=>$entry) {
            if (!$hex($key) || !is_array($entry) || !$exact($entry,['kind','id','source_sha256','record','state','created_at','previous_revision'])
                || !in_array($entry['kind'],['incidents','schedules'],true) || !$hex($entry['source_sha256']) || !is_array($entry['record'])
                || !in_array($entry['state'],['prepared','committed'],true) || !is_int($entry['created_at']) || $entry['created_at']<1
                || !is_int($entry['previous_revision']) || $entry['previous_revision']<0
                || ($entry['record']['revision']??null)!==$entry['previous_revision']+1) { $invalid(); }
            EnterpriseClusterConfig::id($entry['id']);
            if (class_exists(EnterpriseClusterRuntime::class,false)) { EnterpriseClusterRuntime::record($entry['record']); }
        }
        if (count($s['replica_consumed'])>4096) { $invalid(); }
        foreach ($s['replica_consumed'] as $digest=>$until) { if (!$hex($digest) || !is_int($until) || $until<1) { $invalid(); } }
        if (count($s['mirrors'])>32) { $invalid(); }
        foreach ($s['mirrors'] as $node=>$mirror) {
            EnterpriseClusterConfig::id((string)$node);
            if (!is_array($mirror)) { $invalid(); }
            $legacy=$exact($mirror,['revision','shared','applied']);
            if ($legacy) {
                if (!is_int($mirror['revision']) || $mirror['revision']<1 || !is_array($mirror['shared']) || !is_bool($mirror['applied'])) { $invalid(); }
                continue;
            }
            if (!$exact($mirror,['schema','authority','epoch','revision','shared','content_sha256','state','reason'])
                || $mirror['schema']!==1 || $mirror['authority']!==$node || !$hex($mirror['epoch'])
                || !is_int($mirror['revision']) || $mirror['revision']<1 || !is_array($mirror['shared'])
                || !$hex($mirror['content_sha256']) || !hash_equals(hash('sha256',EnterpriseClusterProtocol::canonical($mirror['shared'])),$mirror['content_sha256'])
                || !in_array($mirror['state'],['pending','applied','rejected'],true) || !is_string($mirror['reason'])
                || strlen($mirror['reason'])>256 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$mirror['reason'])) { $invalid(); }
        }
    }
    private function marker(bool $parent=false): ?array
    {
        $directory=$parent ? dirname($this->directory) : $this->directory;
        $path=$directory.'/'.($parent ? self::PARENT_REQUIRED_MARKER : self::REQUIRED_MARKER); clearstatcache(true,$path); $before=@lstat($path);
        if ($before===false) { return null; }
        if (($before['mode']&0170000)!==0100000 || $before['nlink']!==1 || ($before['mode']&0027)
            || $before['size']<1 || $before['size']>2048 || realpath($path)!==$path
            || $before['uid']!==fileowner($directory)) { throw new \RuntimeException('Cluster required marker has unsafe ownership, permissions, link identity or size.'); }
        $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('Cluster required marker is unreadable.'); }
        try {
            $opened=fstat($handle);
            foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $key) {
                if ($opened[$key]!==$before[$key]) { throw new \RuntimeException('Cluster marker changed during open.'); }
            }
            $raw=stream_get_contents($handle,2049); $after=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
            foreach (['dev','ino','size','mtime','ctime'] as $key) { if (!$named || $named[$key]!==$before[$key] || $after[$key]!==$before[$key]) { throw new \RuntimeException('Cluster marker changed while read.'); } }
            if (!is_string($raw) || strlen($raw)!==$before['size']) { throw new \RuntimeException('Cluster marker read was incomplete.'); }
            $marker=\SlsAtomicJson::decodeObject($raw,8);
            if (!is_array($marker) || $marker!==$this->identity) { throw new \RuntimeException('Cluster marker identity/epoch mismatch; never reset an established witness under the same epoch.'); }
            return $marker;
        } finally { fclose($handle); }
    }
    public function transaction(callable $work)
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \RuntimeException('Cluster journal busy; no external operation authorized.'); }
        try {
            $s=$this->read();
            if (class_exists(EnterpriseClusterRuntime::class,false)) {
                EnterpriseClusterRuntime::compactEffectState($s,time());
                EnterpriseClusterRuntime::compactReplicaState($s,time());
            }
            $result=$work($s);
            $s['revision']++;
            $this->validateState($s);
            if (strlen(json_encode($s,JSON_THROW_ON_ERROR))>self::MAX_STATE_BYTES || count($s['effects'])>2000 || count($s['queue'])>500
                || count($s['nonces'])>4096 || count($s['replica_provenance'])>2000 || count($s['replica_outbox'])>500
                || count($s['effect_history'])>4096 || count($s['replica_consumed'])>4096 || count($s['consumed'])>8192) {
                throw new \RuntimeException('Cluster journal capacity reached; admission stopped. Export/review retention; do not delete uncertainty records.');
            }
            $this->files->atomic('worker-state.json',$s); return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function acceptNonce(string $peer,string $nonce,int $now): void
    {
        EnterpriseClusterConfig::id($peer);
        if (!preg_match('/^[a-f0-9]{48}$/D',$nonce)) { throw new \DomainException('Invalid cluster replay nonce.'); }
        $lock=$this->files->clusterReplayLock(); if (!$lock) { throw new \RuntimeException('Cluster replay journal is busy.'); }
        try {
            $main=$this->read(); $state=$this->files->clusterReplayState();
            if ($state===null) {
                if ($main['replay_journal_required']) { throw new \RuntimeException('Required cluster replay journal is missing; delivery is suspended.'); }
                $state=['schema'=>1,'last_time'=>$main['last_time'],'nonces'=>$main['nonces'],
                    'global'=>['tokens_milli'=>4096000,'updated_at'=>$now],'peers'=>[]];
            }
            $exact=static fn(array $value,array $keys): bool=>count($value)===count($keys)&&!array_diff(array_keys($value),$keys);
            if (!$exact($state,['schema','last_time','nonces','global','peers']) || $state['schema']!==1
                || !is_int($state['last_time']) || $state['last_time']<0 || !is_array($state['nonces'])
                || count($state['nonces'])>4096 || !is_array($state['global']) || !is_array($state['peers']) || count($state['peers'])>33
                || !$exact($state['global'],['tokens_milli','updated_at'])) { throw new \RuntimeException('Cluster replay journal schema is invalid.'); }
            if ($now<$state['last_time']) { throw new \RuntimeException('Witness clock moved backwards; automatic authority is suspended.'); }
            foreach ($state['nonces'] as $id=>$until) {
                if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}:[a-f0-9]{48}$/D',$id)
                    || !is_int($until) || $until<1) {
                    throw new \RuntimeException('Cluster replay nonce journal is invalid.');
                }
            }
            $bucket=static function(array $value,int $capacity,int $refill,int $now) use($exact): array {
                if (!$exact($value,['tokens_milli','updated_at']) || !is_int($value['tokens_milli']) || !is_int($value['updated_at'])
                    || $value['tokens_milli']<0 || $value['tokens_milli']>$capacity || $value['updated_at']<0 || $now<$value['updated_at']) {
                    throw new \RuntimeException('Cluster replay rate journal is invalid.');
                }
                $value['tokens_milli']=min($capacity,$value['tokens_milli']+min(3600,$now-$value['updated_at'])*$refill);
                $value['updated_at']=$now;
                if ($value['tokens_milli']<1000) { throw new \DomainException('Cluster peer request rate exceeded.'); }
                $value['tokens_milli']-=1000; return $value;
            };
            $state['global']=$bucket($state['global'],4096000,64000,$now);
            foreach ($state['peers'] as $savedPeer=>$savedBucket) {
                try { EnterpriseClusterConfig::id((string)$savedPeer); } catch (\Throwable $error) { throw new \RuntimeException('Cluster replay peer journal is invalid.',0,$error); }
                if (!is_array($savedBucket) || !$exact($savedBucket,['tokens_milli','updated_at'])
                    || !is_int($savedBucket['tokens_milli']) || !is_int($savedBucket['updated_at'])
                    || $savedBucket['tokens_milli']<0 || $savedBucket['tokens_milli']>2048000 || $savedBucket['updated_at']<0) {
                    throw new \RuntimeException('Cluster replay peer journal is invalid.');
                }
            }
            $state['peers']=array_filter($state['peers'],fn($value,$id):bool=>in_array((string)$id,$this->peerIds,true),ARRAY_FILTER_USE_BOTH);
            if (!array_key_exists($peer,$state['peers']) && count($state['peers'])>=32) { throw new \RuntimeException('Cluster replay peer journal capacity reached.'); }
            $peerBucket=$state['peers'][$peer]??['tokens_milli'=>2048000,'updated_at'=>$now];
            $state['peers'][$peer]=$bucket($peerBucket,2048000,32000,$now);
            $state['last_time']=$now; $state['nonces']=array_filter($state['nonces'],static fn($until)=>is_int($until)&&$until>$now);
            $id=$peer.':'.$nonce;
            if (isset($state['nonces'][$id])) { $this->files->atomic('cluster-replay.json',$state); throw new \DomainException('Cluster request replay rejected.'); }
            if (count($state['nonces'])>=4096) { $this->files->atomic('cluster-replay.json',$state); throw new \RuntimeException('Cluster replay journal capacity reached.'); }
            $state['nonces'][$id]=$now+120; $this->files->atomic('cluster-replay.json',$state);
            if (!$main['replay_journal_required']) {
                $this->transaction(static function(array &$current): void {
                    $current['replay_journal_required']=true; $current['nonces']=[]; $current['last_time']=0;
                });
            }
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
}
