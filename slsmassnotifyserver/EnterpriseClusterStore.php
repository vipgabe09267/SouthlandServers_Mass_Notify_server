<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterConfig.php';
if (!class_exists('SlsAnnouncementJobStore',false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_announcement_jobs.php'; }

/** Private fsynced journals; local flock protects a node, never elects a leader. */
final class EnterpriseClusterStore
{
    public const DIRECTORY='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-cluster';
    public const REQUIRED_MARKER='cluster-required.json';
    public const PARENT_REQUIRED_MARKER='.enterprise-cluster-required.json';
    private \SlsAnnouncementJobStore $files;
    private \SlsAnnouncementJobStore $parentFiles;
    private array $identity;
    private string $directory;
    public function __construct(array $config,string $directory=self::DIRECTORY)
    {
        $this->identity=['schema'=>1,'cluster_id'=>$config['cluster_id'],'node_id'=>$config['node_id'],'witness_epoch'=>$config['witness_epoch']];
        $this->directory=$directory;
        $this->files=new \SlsAnnouncementJobStore($directory);
        $this->parentFiles=new \SlsAnnouncementJobStore(dirname($directory));
    }
    public function initial(): array
    {
        return $this->identity+['revision'=>0,'last_time'=>0,'lease'=>null,'retired'=>[], 'nonces'=>[], 'effects'=>[],
            'replica'=>['jobs'=>[],'schedules'=>[],'incidents'=>[],'events'=>[],'receipts'=>[]],
            'snapshot_revisions'=>[], 'mirrors'=>[], 'queue'=>[], 'cache'=>[],
            'control'=>['stopped'=>false,'spent_cents'=>0,'spend_limit_cents'=>0], 'boot_ids'=>[]];
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
            $this->files->atomic('worker-state.json',$state);
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function read(): array
    {
        $marker=$this->marker(); $parentMarker=$this->marker(true);
        if ($marker!==$this->identity || $parentMarker!==$this->identity) { throw new \RuntimeException('A permanent cluster marker is missing or belongs to another node/epoch; reviewed recovery is required.'); }
        $s=$this->files->state();
        foreach ($this->identity as $key=>$value) { if (($s[$key] ?? null)!==$value) { throw new \RuntimeException('Cluster journal missing, damaged, or belongs to another node/epoch. Explicit initialization or reviewed recovery is required.'); } }
        if (array_diff(array_keys($s),array_keys($this->initial())) || count($s)!==count($this->initial())
            || !is_int($s['revision']) || $s['revision']<0 || !is_int($s['last_time']) || $s['last_time']<0 || !is_array($s['control'])) { throw new \RuntimeException('Cluster journal schema is invalid.'); }
        foreach (['retired','nonces','effects','replica','snapshot_revisions','mirrors','queue','cache','boot_ids'] as $key) { if (!is_array($s[$key])) { throw new \RuntimeException('Cluster journal is invalid: '.$key); } }
        self::validateAuthority($s);
        return $s;
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
        foreach ($s['effects'] as $id=>$effect) {
            if (!$hex($id) || !is_array($effect) || !$exact($effect,['intent','owner','boot_id','token','state','started_at','receipt'])
                || !$hex($effect['boot_id']) || !is_int($effect['token']) || $effect['token']<0 || !is_int($effect['started_at']) || $effect['started_at']<1
                || !in_array($effect['state'],['uncertain','complete','reconciled'],true)
                || ($effect['receipt']!==null && !is_array($effect['receipt']))) { $invalid(); }
            EnterpriseClusterConfig::id($effect['owner']); $intent=$effect['intent'];
            if (!is_array($intent) || !$exact($intent,['delivery_id','channel','target','site_id','created_at','expires_at','content_sha256','estimated_cost_cents','schedule_id','incident_id'])
                || !is_int($intent['created_at']) || !is_int($intent['expires_at']) || $intent['created_at']<1
                || $intent['expires_at']<=$intent['created_at'] || $intent['expires_at']>$intent['created_at']+900
                || !$hex($intent['content_sha256']) || !is_int($intent['estimated_cost_cents']) || $intent['estimated_cost_cents']<0
                || $intent['estimated_cost_cents']>10000000) { $invalid(); }
            foreach (['delivery_id','channel','target','site_id'] as $key) { EnterpriseClusterConfig::id($intent[$key]); }
            foreach (['schedule_id','incident_id'] as $key) { if ($intent[$key]!=='') { EnterpriseClusterConfig::id($intent[$key]); } }
            if (!hash_equals($id,hash('sha256',$intent['delivery_id']."\0".$intent['channel']."\0".$intent['target']."\0".$intent['site_id']))
                || ($effect['state']==='complete' && ($effect['receipt']['uncertain']??null)!==false)
                || ($effect['state']==='reconciled' && ($effect['receipt']['prior_node_fenced']??null)!==true)) { $invalid(); }
        }
        if (!$exact($s['replica'],['jobs','schedules','incidents','events','receipts'])) { $invalid(); }
        foreach ($s['replica'] as $records) { if (!is_array($records)) { $invalid(); } }
        foreach ($s['nonces'] as $id=>$until) { if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}:[a-f0-9]{48}$/D',$id) || !is_int($until) || $until<1) { $invalid(); } }
        foreach ($s['retired'] as $node=>$at) { EnterpriseClusterConfig::id((string)$node); if (!is_int($at) || $at<1) { $invalid(); } }
        foreach ($s['boot_ids'] as $node=>$boot) { EnterpriseClusterConfig::id((string)$node); if (!$hex($boot)) { $invalid(); } }
        foreach ($s['snapshot_revisions'] as $revision) { if (!is_int($revision) || $revision<1) { $invalid(); } }
    }
    private function marker(bool $parent=false): ?array
    {
        $directory=$parent ? dirname($this->directory) : $this->directory;
        $path=$directory.'/'.($parent ? self::PARENT_REQUIRED_MARKER : self::REQUIRED_MARKER); clearstatcache(true,$path); $before=@lstat($path);
        if ($before===false) { return null; }
        if (($before['mode']&0170000)!==0100000 || $before['nlink']!==1 || ($before['mode']&0027)
            || $before['size']<1 || $before['size']>2048 || realpath($path)!==$path
            || $before['uid']!==fileowner($directory)) { throw new \RuntimeException('Cluster required marker has unsafe ownership, permissions, link identity or size.'); }
        $handle=@fopen($path,'r+b'); if (!$handle) { throw new \RuntimeException('Cluster required marker is unreadable.'); }
        try {
            $opened=fstat($handle);
            if ($opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino']) { throw new \RuntimeException('Cluster marker changed during open.'); }
            $raw=stream_get_contents($handle,2049); $after=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
            foreach (['dev','ino','size','mtime','ctime'] as $key) { if (!$named || $named[$key]!==$before[$key] || $after[$key]!==$before[$key]) { throw new \RuntimeException('Cluster marker changed while read.'); } }
            if (!is_string($raw) || strlen($raw)!==$before['size']) { throw new \RuntimeException('Cluster marker read was incomplete.'); }
            $marker=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($marker) || $marker!==$this->identity) { throw new \RuntimeException('Cluster marker identity/epoch mismatch; never reset an established witness under the same epoch.'); }
            return $marker;
        } finally { fclose($handle); }
    }
    public function transaction(callable $work)
    {
        $lock=$this->files->admissionLock();
        if (!$lock) { throw new \RuntimeException('Cluster journal busy; no external operation authorized.'); }
        try {
            $s=$this->read(); $result=$work($s);
            $s['revision']++;
            self::validateAuthority($s);
            if (strlen(json_encode($s,JSON_THROW_ON_ERROR))>1800000 || count($s['effects'])>2000 || count($s['queue'])>500 || count($s['nonces'])>4096) {
                throw new \RuntimeException('Cluster journal capacity reached; admission stopped. Export/review retention; do not delete uncertainty records.');
            }
            $this->files->atomic('worker-state.json',$s); return $result;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }
    public function acceptNonce(string $peer,string $nonce,int $now): void
    {
        $this->transaction(static function(array &$s) use($peer,$nonce,$now): void {
            if ($now<$s['last_time']) { throw new \RuntimeException('Witness clock moved backwards; automatic authority is suspended.'); }
            $s['last_time']=$now;
            $s['nonces']=array_filter($s['nonces'],static fn($until)=>$until>$now);
            $id=$peer.':'.$nonce;
            if (isset($s['nonces'][$id])) { throw new \DomainException('Cluster request replay rejected.'); }
            $s['nonces'][$id]=$now+120;
        });
    }
}
