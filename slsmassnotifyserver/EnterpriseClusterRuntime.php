<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterProtocol.php';
require_once __DIR__.'/LabsSafety.php';
require_once __DIR__.'/EnterpriseClusterPbxContract.php';
require_once __DIR__.'/bin/sls_mass_notify/sls_runtime_state.php';
require_once __DIR__.'/ConfigCrypto.php';

/** Notification authority and remote-site jobs; heartbeats never grant authority. */
final class EnterpriseClusterRuntime
{
    private static array $leaseCache=[];
    private array $config;
    private EnterpriseClusterStore $store;
    private EnterpriseClusterProtocol $protocol;
    private string $boot;
    private ?array $pbxEvidencePaths;
    private ?\Closure $settingsProvider;
    private ?\Closure $mirrorValidator;
    private int $admissionDepth=0;
    private ?array $admissionSettings=null;
    public function __construct(array $settings,string $directory=EnterpriseClusterStore::DIRECTORY,?string $boot=null,?array $pbxEvidencePaths=null,
        ?callable $settingsProvider=null,?callable $mirrorValidator=null)
    {
        $this->config=EnterpriseClusterConfig::normalize($settings['enterprise_cluster'] ?? []);
        $this->settingsProvider=$settingsProvider===null ? null : \Closure::fromCallable($settingsProvider);
        $this->mirrorValidator=$mirrorValidator===null ? null : \Closure::fromCallable($mirrorValidator);
        $this->pbxEvidencePaths=$pbxEvidencePaths;
        if (!$this->config['enabled']) { throw new \DomainException('Enterprise cluster is disabled.'); }
        if ($this->config['mode']==='notification_ha' || $this->config['role']==='witness' || $this->config['mirroring_enabled']) { LabsSafety::requireReceipt($settings,'enterprise_cluster'); }
        if ($this->config['mode']==='notification_ha' && $this->config['role']!=='witness') {
            $evidence=EnterpriseClusterPbxContract::measure($pbxEvidencePaths);
            if (!hash_equals($this->config['pbx_contract'],$evidence['contract'])) { throw new \DomainException('Operator-managed PBX prerequisites changed or do not match the configured HA contract; automatic delivery is fenced.'); }
        }
        $this->store=new EnterpriseClusterStore($this->config,$directory);
        $this->protocol=new EnterpriseClusterProtocol($this->config,$this->store);
        $systemBoot=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if ($boot===null && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$systemBoot)) {
            throw new \RuntimeException('Kernel boot identity is unavailable; exclusive authority cannot be proved.');
        }
        $this->boot=$boot ?? hash('sha256',$this->config['node_id'].'|'.$systemBoot);
        if (!preg_match('/^[a-f0-9]{64}$/D',$this->boot)) { throw new \DomainException('Invalid node boot identity.'); }
    }
    public function config(): array { return $this->config; }
    public function store(): EnterpriseClusterStore { return $this->store; }
    public function initialize(): void
    {
        $this->admit(function(): void { $this->store->initialize($this->config['spend_limit_cents']); });
    }
    /** Order durable admission against console stop; release before notification callbacks. */
    private function admit(callable $claim,bool $requireRunning=true)
    {
        $check=function(array $settings) use($claim,$requireRunning) {
            if ($requireRunning) {
                RuntimeState::requireLabsEnabled($settings);
                RuntimeState::requireRunning($settings);
                if (!EnterpriseClusterConfig::enabled($settings)) { throw new \DomainException('Enterprise cluster is disabled.'); }
            }
            if (EnterpriseClusterConfig::normalize($settings['enterprise_cluster']??[])!==$this->config) {
                throw new \DomainException('Enterprise cluster settings changed. Reload the worker before admitting further effects.');
            }
            if ($this->config['mode']==='notification_ha' || $this->config['role']==='witness' || $this->config['mirroring_enabled']) {
                LabsSafety::requireReceipt($settings,'enterprise_cluster');
            }
            $previous=$this->admissionSettings;
            $this->admissionDepth++;
            $this->admissionSettings=$settings;
            try { return $claim($settings); }
            finally { $this->admissionDepth--; $this->admissionSettings=$previous; }
        };
        if ($this->admissionDepth>0) {
            if ($this->admissionSettings===null) { throw new \LogicException('Cluster policy admission context is unavailable.'); }
            return $check($this->admissionSettings);
        }
        // Private fixtures supply their own current-policy reader explicitly.
        // Runtime endpoints and workers always use the fixed protected files.
        if ($this->settingsProvider!==null) { return $check(($this->settingsProvider)()); }
        $directory='/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
        $path=$directory.'/mass-notifications.config.lock';
        clearstatcache(true,$directory);$parent=@lstat($directory);$account=posix_getpwnam('asterisk');
        $owners=array_unique([0,posix_geteuid(),$account['uid']??-1]);
        if (!$parent || ($parent['mode']&0170000)!==0040000 || ($parent['mode']&0022)
            || !in_array($parent['uid'],$owners,true) || realpath($directory)!==$directory) {
            throw new \RuntimeException('Cluster runtime configuration directory is unsafe; no effect was admitted.');
        }
        $regular=static fn($m): bool=>is_array($m) && ($m['mode']&0170000)===0100000 && $m['nlink']===1
            && in_array($m['uid'],$owners,true) && ($m['mode']&0027)===0 && $m['size']<=4096;
        clearstatcache(true,$path);$before=@lstat($path);
        if ($before!==false && !$regular($before)) { throw new \RuntimeException('Cluster runtime configuration lock is unsafe.'); }
        $mask=umask(0037);
        try {
            $handle=@fopen($path,$before===false?'x+b':'r+b');
            if (!$handle && $before===false) { clearstatcache(true,$path);$before=@lstat($path);if ($regular($before)) { $handle=@fopen($path,'r+b'); } }
        } finally { umask($mask); }
        if (!is_resource($handle)) { throw new \RuntimeException('Cluster runtime configuration lock is unavailable.'); }
        try {
            $identity=static function() use($path,$handle,$before,$regular): bool {
                clearstatcache(true,$path);$named=@lstat($path);$opened=fstat($handle);
                return $regular($named)&&$regular($opened)&&$named['dev']===$opened['dev']&&$named['ino']===$opened['ino']
                    && ($before===false||($before['dev']===$opened['dev']&&$before['ino']===$opened['ino']));
            };
            if (!$identity() || !@flock($handle,LOCK_SH|LOCK_NB) || !$identity()) {
                throw new \RuntimeException('Protected runtime configuration is changing; no effect was admitted.');
            }
            return $check(\FreePBX\modules\SlsConfigCrypto::readFile($directory.'/mass-notifications.config',null,false));
        } finally { @flock($handle,LOCK_UN);fclose($handle); }
    }
    private static function keys(array $p,array $keys): void
    {
        if (array_diff(array_keys($p),$keys) || count($p)!==count($keys)) { throw new \DomainException('Invalid cluster operation schema.'); }
    }
    private function witnessOnly(): void
    {
        if ($this->config['role']!=='witness') { throw new \DomainException('This node is not the configured authority witness.'); }
    }
    private static function leaseMatches(array $s,string $node,array $p,int $now): bool
    {
        $l=$s['lease'];
        return is_array($l) && $l['owner']===$node && ($p['boot_id']??null)===$l['boot_id']
            && ($p['token']??null)===$l['token'] && $l['expires_at']>$now && !$s['control']['stopped'];
    }
    private function validateLeaseReply(array $lease): array
    {
        self::keys($lease,['owner','boot_id','token','expires_at']);
        if ($lease['owner']!==$this->config['node_id'] || $lease['boot_id']!==$this->boot
            || !is_int($lease['token']) || $lease['token']<1 || !is_int($lease['expires_at']) || $lease['expires_at']<=time()) {
            throw new \RuntimeException('Witness returned an invalid or expired authority lease.');
        }
        return $lease;
    }
    private function requireCoordinator(array $peer): void
    {
        if ($peer['role']!=='coordinator') { throw new \DomainException('This operation requires an explicitly configured coordinator identity.'); }
    }
    public static function intent(array $intent): array
    {
        self::keys($intent,['delivery_id','channel','target','site_id','created_at','expires_at','content_sha256',
            'estimated_cost_cents','schedule_id','incident_id']);
        foreach (['delivery_id','channel','target','site_id'] as $key) { EnterpriseClusterConfig::id($intent[$key]); }
        foreach (['schedule_id','incident_id'] as $key) { if ($intent[$key]!=='') { EnterpriseClusterConfig::id($intent[$key]); } }
        if (!is_int($intent['created_at']) || !is_int($intent['expires_at']) || $intent['created_at']<1
            || $intent['expires_at']<=$intent['created_at'] || $intent['expires_at']>$intent['created_at']+900
            || !is_int($intent['estimated_cost_cents']) || $intent['estimated_cost_cents']<0 || $intent['estimated_cost_cents']>10000000
            || !is_string($intent['content_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$intent['content_sha256'])) {
            throw new \DomainException('Invalid immutable cluster effect intent.');
        }
        return $intent;
    }
    public static function effectId(array $intent): string
    {
        return hash('sha256',$intent['delivery_id']."\0".$intent['channel']."\0".$intent['target']."\0".$intent['site_id']);
    }
    private function assertFresh(array $intent,int $now): void
    {
        if ($now<$intent['created_at']-30 || $now>=$intent['expires_at']) { throw new \DomainException('Cluster effect expired or has a future creation time; it will not replay.'); }
    }

    /** Called only after protocol identity/certificate/replay checks. */
    public function dispatch(array $request): array
    {
        $keys=array_keys($request);
        $direct=count($request)===3 && !array_diff($keys,['source','action','payload']);
        $verified=count($request)===10 && !array_diff($keys,
            ['version','cluster_id','epoch','source','target','nonce','issued_at','action','payload','signature']);
        if ((!$direct && !$verified)
            || !is_string($request['source']??null) || !is_string($request['action']??null)
            || !is_array($request['payload']??null)) {
            throw new \DomainException('Invalid admitted cluster request schema.');
        }
        // Receipt, reconciliation, STOP/handoff administration and reads remain
        // available while notification admission is paused. Every operation still
        // holds one current protected policy snapshot through its journal access.
        $notificationAdmission=in_array($request['action'],['lease.acquire','effect.begin','mirror.put','site.enqueue'],true);
        return $this->admit(fn(array $settings): array=>$this->dispatchAdmitted($request,$settings),$notificationAdmission);
    }
    private function dispatchAdmitted(array $request,array $currentSettings): array
    {
        $peer=EnterpriseClusterConfig::peer($this->config,$request['source']);
        if (!$peer) { throw new \DomainException('Unknown cluster source.'); }
        $p=$request['payload']; $node=$peer['node_id']; $now=time();
        switch ($request['action']) {
            case 'status':
                self::keys($p,[]); $s=$this->store->read();
                return ['node_id'=>$this->config['node_id'],'site_id'=>$this->config['site_id'],'role'=>$this->config['role'],
                    'revision'=>$s['revision'],'lease'=>$s['lease'],'control'=>$s['control'],'queued'=>count($s['queue']),
                    'queue_available'=>max(0,$this->config['max_queue']-count($s['queue'])),'consumed_tombstones'=>count($s['consumed']),
                    'active_effects'=>count($s['effects']),'effect_capacity_available'=>max(0,2000-count($s['effects'])),
                    'effect_delivery_history'=>count($s['effect_history']),
                    'effect_identity_history'=>array_sum(array_map(static fn($row)=>count($row['effects']),$s['effect_history'])),
                    'replication_pending'=>count($s['replica_outbox']),'replication_available'=>max(0,500-count($s['replica_outbox'])),
                    'replica_tombstones'=>count($s['replica_consumed']),'replica_tombstones_available'=>max(0,4096-count($s['replica_consumed'])),
                    'uncertain'=>count(array_filter($s['effects'],static fn($e)=>$e['state']==='uncertain'))];
            case 'lease.acquire':
                $this->witnessOnly(); self::keys($p,['boot_id','pbx_contract']);
                if ($peer['site_id']!==$this->config['site_id'] || !in_array($peer['role'],['node','coordinator'],true) || !is_string($p['boot_id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['boot_id'])
                    || $p['pbx_contract']!==$this->config['pbx_contract']) { throw new \DomainException('Candidate identity or matching PBX qualification rejected.'); }
                return $this->store->transaction(function(array &$s) use($p,$node,$now): array {
                    if ($s['control']['stopped']) { throw new \DomainException('Cluster STOP is active.'); }
                    $l=$s['lease'];
                    if (is_array($l) && $l['expires_at']>$now && ($l['owner']!==$node || $l['boot_id']!==$p['boot_id'])) { throw new \DomainException('Exclusive lease belongs to another node or boot.'); }
                    if (isset($s['retired'][$node])) { throw new \DomainException('Automatic failback is prohibited. An administrator must authorize handoff.'); }
                    if (!$l && $node!==$this->config['initial_owner']) { throw new \DomainException('Initial owner must acquire the first lease.'); }
                    if ($l && $l['owner']!==$node && !$this->config['auto_failover']) { throw new \DomainException('Automatic takeover is disabled.'); }
                    if ($l && ($l['owner']!==$node || $l['boot_id']!==$p['boot_id'])) {
                        foreach ($s['effects'] as $effect) {
                            if ($effect['owner']===$l['owner'] && $effect['state']==='uncertain') {
                                throw new \DomainException('Prior owner has an interrupted or outstanding external action. Automatic takeover is suspended pending manual fencing and receipt reconciliation.');
                            }
                        }
                    }
                    if (!$l || $l['owner']!==$node || $l['boot_id']!==$p['boot_id'] || $l['expires_at']<=$now) {
                        if ($l && $l['owner']!==$node) { $s['retired'][$l['owner']]=$now; }
                        $l=['owner'=>$node,'boot_id'=>$p['boot_id'],'token'=>($l['token']??0)+1,'expires_at'=>$now+$this->config['lease_seconds']];
                    } else { $l['expires_at']=$now+$this->config['lease_seconds']; }
                    $s['lease']=$l; $s['boot_ids'][$node]=$p['boot_id']; return $l;
                });
            case 'lease.handoff':
                $this->witnessOnly(); $this->requireCoordinator($peer); self::keys($p,['target']);
                $candidate=EnterpriseClusterConfig::peer($this->config,EnterpriseClusterConfig::id($p['target']));
                if (!$candidate || $candidate['site_id']!==$this->config['site_id'] || !in_array($candidate['role'],['node','coordinator'],true)) { throw new \DomainException('Invalid handoff target.'); }
                return $this->store->transaction(function(array &$s) use($p,$now): array {
                    // Handoff requires STOP and expiry so no current node may
                    // begin another effect while authority changes.
                    if (!$s['control']['stopped'] || ($s['lease']['expires_at']??0)>$now) { throw new \DomainException('Set STOP and wait for current lease expiry before manual handoff.'); }
                    foreach ($s['effects'] as $effect) { if ($effect['state']==='uncertain') { throw new \DomainException('Outstanding external actions must be fenced and manually reconciled before handoff.'); } }
                    $previous=$s['lease']['owner']??null; if ($previous && $previous!==$p['target']) { $s['retired'][$previous]=$now; }
                    unset($s['retired'][$p['target']]);
                    $s['lease']=['owner'=>$p['target'],'boot_id'=>'','token'=>($s['lease']['token']??0)+1,'expires_at'=>0];
                    return ['manual_target'=>$p['target']];
                });
            case 'effect.begin':
                $this->witnessOnly(); self::keys($p,['boot_id','token','intent']);
                $intent=self::intent($p['intent']); $this->assertFresh($intent,$now); $id=self::effectId($intent);
                if ($intent['site_id']!==$peer['site_id'] || !in_array($peer['role'],['node','coordinator'],true)) { throw new \DomainException('Effect site or peer role is not permitted.'); }
                return $this->store->transaction(function(array &$s) use($p,$intent,$id,$node,$now): array {
                    if (!self::leaseMatches($s,$node,$p,$now)) { throw new \DomainException('No current exclusive authority; software fencing blocked this effect.'); }
                    $delivery=hash('sha256',$intent['delivery_id']); $history=$s['effect_history'][$delivery]??null;
                    if ($history && ($history['closed'] || $history['expires_at']!==$intent['expires_at'] || isset($history['effects'][$id]))) {
                        throw new \DomainException('Effect delivery or destination identity was already consumed.');
                    }
                    if (isset($s['effects'][$id])) {
                        $old=$s['effects'][$id];
                        if ($old['state']==='uncertain' && $old['intent']===$intent && $old['owner']===$node
                            && $old['boot_id']===$p['boot_id'] && $old['token']===$p['token'] && $old['receipt']===null) {
                            return ['id'=>$id,'admitted'=>true,'token'=>$p['token'],'resumed'=>true];
                        }
                        throw new \DomainException('Effect already recorded; completed or uncertain actions are never resent.');
                    }
                    if ($s['control']['spent_cents']+$intent['estimated_cost_cents']>$s['control']['spend_limit_cents']) { throw new \DomainException('Cluster spending ceiling blocks this effect.'); }
                    $s['control']['spent_cents']+=$intent['estimated_cost_cents'];
                    $s['effects'][$id]=['intent'=>$intent,'owner'=>$node,'boot_id'=>$p['boot_id'],'token'=>$p['token'],
                        'state'=>'uncertain','started_at'=>$now,'receipt'=>null,'reconciliation'=>null];
                    return ['id'=>$id,'admitted'=>true,'token'=>$p['token']];
                });
            case 'effect.finish':
                $this->witnessOnly();
                if (array_diff(array_keys($p),['id','boot_id','token','delivery_hash','receipt'])
                    || array_diff(['id','boot_id','token','receipt'],array_keys($p))) { throw new \DomainException('Invalid effect receipt schema.'); }
                if (!is_string($p['id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['id']) || !is_array($p['receipt'])
                    || (isset($p['delivery_hash']) && (!is_string($p['delivery_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$p['delivery_hash'])))
                    || strlen(EnterpriseClusterProtocol::canonical($p['receipt']))>65536) { throw new \DomainException('Invalid effect receipt.'); }
                return $this->store->transaction(static function(array &$s) use($p,$node): array {
                    $e=$s['effects'][$p['id']]??null;
                    if (!$e) {
                        $receiptHash=hash('sha256',EnterpriseClusterProtocol::canonical($p['receipt']));
                        if (isset($p['delivery_hash']) && hash_equals($s['effect_history'][$p['delivery_hash']]['effects'][$p['id']]??'',$receiptHash)) { return ['recorded'=>true,'idempotent'=>true]; }
                        throw new \DomainException('Receipt identity was already consumed or is unknown.');
                    }
                    if ($e['owner']!==$node || $e['boot_id']!==$p['boot_id'] || $e['token']!==$p['token']) { throw new \DomainException('Receipt does not belong to the effect owner and fencing token.'); }
                    if ($e['receipt']!==null && $e['receipt']!==$p['receipt']) { throw new \DomainException('Committed receipt cannot be changed.'); }
                    $s['effects'][$p['id']]['receipt']=$p['receipt'];
                    $s['effects'][$p['id']]['state']=($p['receipt']['uncertain']??true)===false ? 'complete' : 'uncertain';
                    return ['recorded'=>true];
                });
            case 'effect.reconcile':
                $this->witnessOnly(); $this->requireCoordinator($peer); self::keys($p,['id','prior_node_fenced','evidence']);
                if (!is_string($p['id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['id']) || $p['prior_node_fenced']!==true
                    || !is_string($p['evidence']) || strlen($p['evidence'])<20 || strlen($p['evidence'])>2048
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$p['evidence'])) { throw new \DomainException('Manual reconciliation requires explicit prior-node fencing and a recorded outcome review.'); }
                return $this->store->transaction(static function(array &$s) use($p,$node,$now): array {
                    if (!$s['control']['stopped'] || ($s['lease']['expires_at']??0)>$now || !isset($s['effects'][$p['id']])) { throw new \DomainException('Reconciliation requires STOP, lease expiry, and an existing immutable effect.'); }
                    if ($s['effects'][$p['id']]['state']!=='uncertain' || $s['effects'][$p['id']]['reconciliation']!==null) {
                        throw new \DomainException('Only one unresolved uncertain effect can be manually reconciled.');
                    }
                    $s['effects'][$p['id']]['state']='reconciled';
                    $s['effects'][$p['id']]['reconciliation']=['manual_reconciliation'=>true,'prior_node_fenced'=>true,
                        'evidence'=>$p['evidence'],'reviewed_by'=>$node,'reviewed_at'=>$now];
                    // Identity remains permanently consumed. Reconciliation is
                    // permission to resume other work, never to resend this effect.
                    return ['reconciled'=>true,'resend_permitted'=>false];
                });
            case 'control.set':
                $this->requireCoordinator($peer); self::keys($p,['stopped','spend_limit_cents']);
                if (!is_bool($p['stopped']) || !is_int($p['spend_limit_cents']) || $p['spend_limit_cents']<0 || $p['spend_limit_cents']>100000000) { throw new \DomainException('Invalid cluster controls.'); }
                return $this->store->transaction(static function(array &$s) use($p): array {
                    if ($p['spend_limit_cents']<$s['control']['spent_cents']) { throw new \DomainException('Spending ceiling is below committed spending.'); }
                    $s['control']['stopped']=$p['stopped']; $s['control']['spend_limit_cents']=$p['spend_limit_cents']; return $s['control'];
                });
            case 'state.put':
                if (!in_array($peer['role'],['node','coordinator'],true) || $peer['site_id']!==$this->config['site_id']) { throw new \DomainException('Replica site or role mismatch.'); }
                $this->witnessOnly();
                self::keys($p,['kind','id','record','proof']);
                if (!in_array($p['kind'],['jobs','schedules','incidents','events'],true)) { throw new \DomainException('Invalid replica record kind.'); }
                EnterpriseClusterConfig::id($p['id']); self::record($p['record']);
                if (!is_array($p['proof'])) { throw new \DomainException('Replicas require current witness lease proof.'); }
                self::keys($p['proof'],['boot_id','token']);
                if (!is_string($p['proof']['boot_id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['proof']['boot_id'])
                    || !is_int($p['proof']['token']) || $p['proof']['token']<1) { throw new \DomainException('Invalid replica lease proof.'); }
                if ($p['kind']==='jobs') {
                    self::jobReplicaIntent($p['record']['intent'],$p['id']);
                }
                return $this->store->transaction(static function(array &$s) use($p,$node,$now): array {
                    if (!self::leaseMatches($s,$node,$p['proof'],$now)) {
                        throw new \DomainException('Replica source does not hold the current witness lease.');
                    }
                    $digest=self::replicaDigest($p['kind'],$p['id']);
                    if (isset($s['replica_consumed'][$digest])) { throw new \DomainException('Replica identity was already consumed during the replay-retention window.'); }
                    $old=$s['replica'][$p['kind']][$p['id']]??null; $record=$p['record'];
                    if ($old && ($old['intent']!==$record['intent'] || $old['revision']>$record['revision'])) { throw new \DomainException('Replica intent is immutable and revisions cannot move backwards.'); }
                    if ($old && $old['revision']===$record['revision'] && $old!==$record) { throw new \DomainException('Replica revision conflicts.'); }
                    if ($old) { self::replicaTransition($old,$record); }
                    $s['replica'][$p['kind']][$p['id']]=$record;
                    $s['replica_provenance'][$p['kind'].':'.$p['id']]=['source'=>$node,'boot_id'=>$p['proof']['boot_id'],
                        'token'=>$p['proof']['token'],'record_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($record)),'admitted_at'=>$now];
                    return ['stored'=>true,'revision'=>$record['revision']];
                });
            case 'state.pull':
                $this->witnessOnly();
                self::keys($p,[]);
                if ($peer['site_id']!==$this->config['site_id'] || !in_array($peer['role'],['node','coordinator'],true)) { throw new \DomainException('Replica recovery is limited to matching site nodes.'); }
                $s=$this->store->read(); return ['replica'=>$s['replica'],'replica_provenance'=>$s['replica_provenance'],
                    'effects'=>$s['effects'],'effect_history'=>$s['effect_history'],'control'=>$s['control'],'revision'=>$s['revision']];
            case 'mirror.put':
                if (!$this->config['mirroring_enabled'] || $peer['site_id']!==$this->config['site_id']
                    || !in_array($peer['role'],['node','coordinator'],true) || $node!==$this->config['mirror_authority']) {
                    throw new \DomainException('Configuration mirroring is not enabled for this authoritative site peer.');
                }
                self::keys($p,['schema','authority','epoch','revision','shared','content_sha256']);
                if (($p['schema']??null)!==1 || ($p['authority']??null)!==$node || ($p['epoch']??null)!==$this->config['mirror_epoch']
                    || !is_int($p['revision']) || $p['revision']<1 || !is_array($p['shared'])
                    || !is_string($p['content_sha256']??null) || !hash_equals(hash('sha256',EnterpriseClusterProtocol::canonical($p['shared'])),$p['content_sha256'])) {
                    throw new \DomainException('Invalid configuration mirror authority, epoch, revision, or content identity.');
                }
                EnterpriseClusterConfig::applyMirror([],$p['shared'],$this->config);
                if ($this->mirrorValidator===null) { throw new \RuntimeException('Full mirror configuration validation is unavailable; no mirror was staged.'); }
                $candidate=EnterpriseClusterConfig::applyMirror($currentSettings,$p['shared'],$this->config);
                $validated=($this->mirrorValidator)($candidate,$p['shared']);
                if (!is_array($validated)) { throw new \RuntimeException('Full mirror configuration validation did not return a configuration.'); }
                return $this->store->transaction(static function(array &$s) use($p,$node): array {
                    $old=$s['mirrors'][$node]??null;
                    $oldEpoch=is_array($old) ? ($old['epoch']??$p['epoch']) : $p['epoch'];
                    $oldRevision=is_array($old) ? ($old['revision']??0) : 0;
                    $oldShared=is_array($old) ? ($old['shared']??null) : null;
                    if ($old && $oldEpoch===$p['epoch'] && $oldRevision===$p['revision'] && $oldShared===$p['shared']) {
                        return ['staged'=>($old['state']??(!empty($old['applied'])?'applied':'pending'))==='pending',
                            'revision'=>$p['revision'],'epoch'=>$p['epoch'],'state'=>$old['state']??(!empty($old['applied'])?'applied':'pending')];
                    }
                    if ($old && $oldEpoch===$p['epoch'] && ($oldRevision>$p['revision'] || ($oldRevision===$p['revision'] && $oldShared!==$p['shared']))) {
                        throw new \DomainException('Configuration mirror revision moved backwards or changed within its authority epoch.');
                    }
                    $s['mirrors'][$node]=$p+['state'=>'pending','reason'=>'']; return ['staged'=>true,'revision'=>$p['revision'],'epoch'=>$p['epoch']];
                });
            case 'site.enqueue':
                $this->requireCoordinator($peer);
                if (!$this->config['remote_enabled']) { throw new \DomainException('Remote-site delivery is disabled.'); }
                self::job($p); $this->assertFresh($p,$now);
                if ($p['site_id']!==$this->config['site_id'] || array_diff($p['recipients'],$this->config['site_recipients'])) { throw new \DomainException('This job contains recipients not owned by this site.'); }
                if ($this->config['mode']==='edge') {
                    if (array_diff($p['channels'],$this->config['local_device_channels'])) { throw new \DomainException('An edge cannot act as a PBX; only approved local devices are supported.'); }
                    self::edgeJobIntent($p['intent'],$p['channels']);
                    $capabilities=[];
                    foreach ($this->config['edge_devices'] as $device) { $capabilities[$device['id']]=$device['channels']; }
                    foreach ($p['recipients'] as $recipient) {
                        if (!isset($capabilities[$recipient]) || !array_intersect($p['channels'],$capabilities[$recipient])) {
                            throw new \DomainException('Every edge recipient must have an approved device for at least one requested channel.');
                        }
                    }
                } else {
                    $declared=$p['channels']; sort($declared,SORT_STRING);
                    if ($declared!==self::pbxJobChannels($p['intent'])) { throw new \DomainException('Remote-site channel manifest does not match its immutable selectors.'); }
                    if ($p['recipients']!==self::pbxJobRecipients($p['intent'])) { throw new \DomainException('Remote-site recipients do not match the immutable selectors.'); }
                }
                return $this->store->transaction(function(array &$s) use($p,$now): array {
                    self::compactRemoteState($s,$now);
                    $old=$s['queue'][$p['id']]??null;
                    if ($old) { if ($old['job']!==$p) { throw new \DomainException('Remote job identifier was reused with different immutable intent.'); } return ['id'=>$p['id'],'state'=>$old['state'],'receipt'=>$old['receipt']]; }
                    if (isset($s['consumed'][hash('sha256',$p['id'])])) { throw new \DomainException('Remote job identity was already consumed during the replay-retention window.'); }
                    if (count($s['queue'])>=$this->config['max_queue'] || $s['control']['stopped']) { throw new \DomainException('Site queue is full or STOP is active.'); }
                    $s['queue'][$p['id']]=['job'=>$p,'state'=>'queued','received_at'=>$now,'receipt'=>null]; return ['id'=>$p['id'],'state'=>'queued'];
                });
            case 'site.receipt':
                $this->requireCoordinator($peer); self::keys($p,['id']); EnterpriseClusterConfig::id($p['id']);
                $s=$this->store->read(); $row=$s['queue'][$p['id']]??null;
                if (!$row) { throw new \DomainException('Unknown site job.'); }
                return ['id'=>$p['id'],'state'=>$row['state'],'receipt'=>$row['receipt'],'expires_at'=>$row['job']['expires_at']];
            default: throw new \DomainException('Unsupported cluster operation.');
        }
    }
    public static function record(array $r): void
    {
        self::keys($r,['revision','intent','state','uncertain','schedule_id','incident_id','updated_at','receipts']);
        if (!is_int($r['revision']) || $r['revision']<1 || !is_array($r['intent']) || !is_string($r['state'])
            || !in_array($r['state'],['queued','prepared','running','complete','failed','uncertain','cancelled','expired'],true)
            || !is_bool($r['uncertain']) || !is_int($r['updated_at']) || $r['updated_at']<1 || $r['updated_at']>time()+300 || !is_array($r['receipts'])
            || strlen(EnterpriseClusterProtocol::canonical($r))>262144) { throw new \DomainException('Invalid bounded replica record schema.'); }
        foreach (['schedule_id','incident_id'] as $key) { if ($r[$key]!=='') { EnterpriseClusterConfig::id($r[$key]); } }
    }
    private static function replicaTransition(array $old,array $next): void
    {
        $allowed=['prepared'=>['prepared','queued','running','complete','failed','uncertain','cancelled','expired'],
            'queued'=>['queued','running','complete','failed','uncertain','cancelled','expired'],
            'running'=>['running','complete','failed','uncertain','cancelled','expired'],
            'complete'=>['complete'],'failed'=>['failed'],'uncertain'=>['uncertain'],'cancelled'=>['cancelled'],'expired'=>['expired']];
        if (!in_array($next['state'],$allowed[$old['state']]??[],true) || ($old['uncertain'] && !$next['uncertain'])) {
            throw new \DomainException('Replica state cannot move backward or clear uncertainty.');
        }
        foreach ($old['receipts'] as $key=>$receipt) {
            if (!array_key_exists($key,$next['receipts']) || $next['receipts'][$key]!==$receipt) {
                throw new \DomainException('Committed replica receipts cannot be removed or changed.');
            }
        }
        if (in_array($old['state'],['complete','failed','uncertain','cancelled','expired'],true)
            && $next['receipts']!==$old['receipts']) { throw new \DomainException('Terminal replica receipts are immutable.'); }
    }
    public static function jobReplicaIntent(array $intent,string $id): void
    {
        self::keys($intent,['schema','id','created_at','request','request_sha256']);
        if ($intent['schema']!==1 || $intent['id']!==$id || !is_string($intent['created_at'])
            || strlen($intent['created_at'])<20 || strlen($intent['created_at'])>40
            || !is_array($intent['request']) || !is_string($intent['request_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$intent['request_sha256'])
            || !hash_equals(hash('sha256',EnterpriseClusterProtocol::canonical($intent['request'])),$intent['request_sha256'])) {
            throw new \DomainException('Invalid versioned immutable job replica intent.');
        }
        self::timestamp($intent['created_at']);
    }
    public static function timestamp(string $value): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)) {
            throw new \DomainException('Cluster timestamps must be timezone-aware ISO 8601 values.');
        }
        try { $parsed=new \DateTimeImmutable($value); }
        catch (\Throwable $error) { throw new \DomainException('Cluster timestamp is invalid.',0,$error); }
        $diagnostic=\DateTimeImmutable::getLastErrors();
        if (is_array($diagnostic) && (($diagnostic['warning_count']??0)>0 || ($diagnostic['error_count']??0)>0)) {
            throw new \DomainException('Cluster timestamp is invalid.');
        }
        $timestamp=$parsed->getTimestamp();
        if ($timestamp<1) { throw new \DomainException('Cluster timestamp is outside the supported range.'); }
        return $timestamp;
    }
    public static function job(array $j): void
    {
        self::keys($j,['id','delivery_id','site_id','created_at','expires_at','recipients','channels','intent','content_sha256','offline_approved']);
        foreach (['id','delivery_id','site_id'] as $key) { EnterpriseClusterConfig::id($j[$key]); }
        $keyProbe=[];$keyProbe[$j['id']]=true;
        if (!is_string(array_key_first($keyProbe))) { throw new \DomainException('Remote-site job identity must remain a string journal key.'); }
        if (!is_int($j['created_at']) || !is_int($j['expires_at']) || $j['created_at']<1 || $j['expires_at']<=$j['created_at'] || $j['expires_at']>$j['created_at']+900
            || !is_array($j['recipients']) || !array_is_list($j['recipients']) || !$j['recipients'] || count($j['recipients'])>1000
            || !is_array($j['channels']) || !array_is_list($j['channels']) || !$j['channels'] || count($j['channels'])>9
            || count(array_filter($j['channels'],'is_string'))!==count($j['channels'])
            || count(array_unique($j['channels'],SORT_STRING))!==count($j['channels'])
            || array_diff($j['channels'],['phones','desktop','audio','external_voice','email','sms','webhook','local_audio','local_display'])
            || !is_array($j['intent']) || !is_bool($j['offline_approved'])
            || !is_string($j['content_sha256']) || !hash_equals(hash('sha256',EnterpriseClusterProtocol::canonical($j['intent'])),$j['content_sha256'])
            || strlen(EnterpriseClusterProtocol::canonical($j))>262144) { throw new \DomainException('Invalid remote-site immutable job schema.'); }
        foreach ($j['recipients'] as $recipient) { EnterpriseClusterConfig::id($recipient); }
        if (count(array_unique($j['recipients']))!==count($j['recipients'])) { throw new \DomainException('Remote-site recipients must be unique.'); }
        $recipients=$j['recipients']; $channels=$j['channels']; sort($recipients,SORT_STRING); sort($channels,SORT_STRING);
        if ($recipients!==$j['recipients'] || $channels!==$j['channels']) { throw new \DomainException('Remote-site recipient and channel lists must use canonical order.'); }
    }
    public static function pbxJobChannels(array $intent): array
    {
        self::pbxJobRecipients($intent);
        $map=['targets'=>'phones','desktop_clients'=>'desktop','email_recipient_ids'=>'email','sms_recipient_ids'=>'sms',
            'voice_recipient_ids'=>'external_voice','webhook_ids'=>'webhook'];
        $channels=[];
        foreach ($map as $field=>$channel) {
            if (!array_key_exists($field,$intent)) { continue; }
            if (!is_array($intent[$field]) || !array_is_list($intent[$field]) || count($intent[$field])>1000) {
                throw new \DomainException('Remote-site selectors must be bounded JSON arrays.');
            }
            if ($intent[$field]) { $channels[]=$channel; }
        }
        $audio=$intent['audio_mode']??'none';
        if (!is_string($audio) || !in_array($audio,['none','tts','tones_tts'],true)) { throw new \DomainException('Remote-site phone audio mode is invalid.'); }
        if ($audio!=='none') {
            if (empty($intent['targets'])) { throw new \DomainException('Remote-site phone audio requires explicit phone targets.'); }
            $channels[]='audio';
        }
        sort($channels,SORT_STRING);
        return $channels;
    }
    public static function pbxJobRecipients(array $intent): array
    {
        $allowed=['message','title','targets','desktop_clients','audio_mode','email_recipient_ids','sms_recipient_ids','voice_recipient_ids','webhook_ids'];
        if (array_diff(array_keys($intent),$allowed) || !is_string($intent['message']??null) || $intent['message']===''
            || strlen($intent['message'])>16384 || !is_string($intent['title']??'') || strlen($intent['title']??'')>200) {
            throw new \DomainException('Remote-site payload content or selectors are invalid.');
        }
        $recipients=[];
        foreach (['targets','desktop_clients','email_recipient_ids','sms_recipient_ids','voice_recipient_ids','webhook_ids'] as $field) {
            if (!array_key_exists($field,$intent)) { continue; }
            if (!is_array($intent[$field]) || !array_is_list($intent[$field]) || count($intent[$field])>1000
                || count(array_filter($intent[$field],'is_string'))!==count($intent[$field])
                || count(array_unique($intent[$field],SORT_STRING))!==count($intent[$field])) {
                throw new \DomainException('Remote-site selectors must be bounded unique JSON arrays.');
            }
            foreach ($intent[$field] as $recipient) { $recipients[]=EnterpriseClusterConfig::id($recipient); }
        }
        $audio=$intent['audio_mode']??'none';
        if (!is_string($audio) || !in_array($audio,['none','tts','tones_tts'],true)) { throw new \DomainException('Remote-site phone audio mode is invalid.'); }
        $recipients=array_values(array_unique($recipients)); sort($recipients,SORT_STRING); return $recipients;
    }
    public static function edgeJobIntent(array $intent,array $channels): void
    {
        if (array_diff(array_keys($intent),['message','title','audio_asset_id']) || !is_string($intent['message']??null)
            || $intent['message']==='' || strlen($intent['message'])>16384 || !is_string($intent['title']??'')
            || strlen($intent['title']??'')>200 || (isset($intent['audio_asset_id']) && !is_string($intent['audio_asset_id']))) {
            throw new \DomainException('Invalid local edge notification content.');
        }
        if (in_array('local_audio',$channels,true)) { EnterpriseClusterConfig::id($intent['audio_asset_id']??''); }
        elseif (array_key_exists('audio_asset_id',$intent) && $intent['audio_asset_id']!=='') { throw new \DomainException('An edge audio asset requires the local audio channel.'); }
    }
    public static function compactRemoteState(array &$state,int $now): void
    {
        $state['consumed']=array_filter($state['consumed']??[],static fn($until)=>is_int($until)&&$until>$now);
        $retire=static function(string $id) use(&$state,$now): void {
            $digest=hash('sha256',$id);
            if (!isset($state['consumed'][$digest]) && count($state['consumed'])>=8192) {
                throw new \RuntimeException('Remote replay tombstone capacity reached; new work is suspended pending reviewed archival.');
            }
            $state['consumed'][$digest]=max($state['consumed'][$digest]??0,$now+2592000);
        };
        foreach ($state['queue'] as $id=>$row) {
            $expires=$row['job']['expires_at']??0; $terminal=in_array($row['state']??'', ['complete','expired'],true);
            if ($terminal && is_int($expires) && $expires<=$now) { $retire((string)$id); unset($state['queue'][$id]); }
        }
        foreach ($state['cache'] as $id=>$event) {
            if (is_int($event['expires_at']??null) && $event['expires_at']<=$now) { $retire((string)$id); unset($state['cache'][$id]); }
        }
    }
    /** Replace completed per-target effects with compact receipt hashes. */
    public static function compactEffectState(array &$state,int $now): void
    {
        foreach ($state['effect_history']??[] as $delivery=>$history) {
            if (is_int($history['retain_until']??null) && $history['retain_until']<=$now) { unset($state['effect_history'][$delivery]); }
        }
        foreach ($state['effects']??[] as $id=>$effect) {
            $intent=$effect['intent']; $delivery=hash('sha256',$intent['delivery_id']);
            $preparedExpired=($effect['state']??'')==='prepared' && $intent['expires_at']<=$now;
            if (!$preparedExpired && !in_array($effect['state']??'', ['complete','reconciled'],true)) { continue; }
            $history=$state['effect_history'][$delivery]??null;
            if ($history===null) {
                if (count($state['effect_history'])>=4096) {
                    throw new \RuntimeException('Effect replay-history capacity reached; new external actions are suspended pending reviewed retention cleanup.');
                }
                $history=['expires_at'=>$intent['expires_at'],'retain_until'=>$intent['expires_at']+31536000,'closed'=>false,'effects'=>[]];
            }
            if ($history['closed'] || $history['expires_at']!==$intent['expires_at']) {
                throw new \RuntimeException('Effect delivery history conflicts with an active immutable intent.');
            }
            $evidence=$preparedExpired ? ['prepared_expired'=>true]
                : ($effect['state']==='reconciled'
                    ? ['receipt'=>$effect['receipt'],'reconciliation'=>$effect['reconciliation']]
                    : $effect['receipt']);
            $receiptHash=hash('sha256',EnterpriseClusterProtocol::canonical($evidence));
            if (isset($history['effects'][$id]) && !hash_equals($history['effects'][$id],$receiptHash)) {
                throw new \RuntimeException('Effect receipt history cannot be changed.');
            }
            if (!isset($history['effects'][$id]) && count($history['effects'])>=10000) {
                throw new \RuntimeException('One delivery exceeds the compact effect-identity allocation.');
            }
            $history['effects'][$id]=$receiptHash; $state['effect_history'][$delivery]=$history; unset($state['effects'][$id]);
        }
        $active=[];
        foreach ($state['effects']??[] as $effect) { $active[hash('sha256',$effect['intent']['delivery_id'])]=true; }
        foreach ($state['effect_history'] as $delivery=>&$history) {
            if (!$history['closed'] && $history['expires_at']<=$now && !isset($active[$delivery])) {
                $history['closed']=true; $history['effects']=[];
            }
        }
        unset($history);
    }
    private static function replicaDigest(string $kind,string $id): string
    {
        return hash('sha256',$kind."\0".$id);
    }
    private static function consumeReplica(array &$state,string $kind,string $id,int $now): void
    {
        $digest=self::replicaDigest($kind,$id);
        if (!isset($state['replica_consumed'][$digest]) && count($state['replica_consumed'])>=4096) {
            throw new \RuntimeException('Replica replay-tombstone capacity reached; new cluster mutations are suspended pending reviewed export and retention cleanup.');
        }
        $state['replica_consumed'][$digest]=max($state['replica_consumed'][$digest]??0,$now+2592000);
    }
    /** Compact terminal replicas while retaining exact 30-day replay identities. */
    public static function compactReplicaState(array &$state,int $now): void
    {
        $state['replica_consumed']=array_filter($state['replica_consumed']??[],static fn($until)=>is_int($until)&&$until>$now);
        foreach ($state['replica']['receipts']??[] as $id=>$receipt) {
            if (!isset($state['effects'][$id]) || ($state['effects'][$id]['receipt']??null)!==$receipt) {
                throw new \RuntimeException('Replica receipt history is detached from its immutable effect.');
            }
            unset($state['replica']['receipts'][$id]);
        }
        $terminal=['complete','failed','uncertain','cancelled','expired'];
        foreach (['jobs','schedules','incidents','events'] as $kind) {
            foreach ($state['replica'][$kind]??[] as $id=>$record) {
                $retire=in_array($record['state']??'', $terminal,true)
                    || ($kind==='events' && is_int($record['updated_at']??null) && $record['updated_at']+900<=$now);
                if (!$retire) { continue; }
                self::consumeReplica($state,$kind,(string)$id,$now);
                unset($state['replica'][$kind][$id],$state['replica_provenance'][$kind.':'.$id]);
                foreach ([$kind.':'.$id,rtrim($kind,'s').':'.$id] as $revisionKey) { unset($state['snapshot_revisions'][$revisionKey]); }
            }
        }
    }
    public function handle(string $body,string $fingerprint,bool $tlsVerified): string
    {
        $request=$this->protocol->verify($body,$fingerprint,$tlsVerified);
        try { $result=$this->dispatch($request); $payload=['ok'=>true,'result'=>$result]; }
        catch (\Throwable $e) { $payload=['ok'=>false,'error'=>$e instanceof \DomainException ? substr($e->getMessage(),0,256) : 'authority_or_journal_unavailable']; }
        return $this->protocol->respond($request,$payload);
    }
    public function acquire(): array
    {
        return $this->admit(fn(): array=>$this->acquireAdmitted());
    }
    private function acquireAdmitted(): array
    {
        if ($this->config['mode']==='notification_ha' && $this->config['role']!=='witness') {
            $evidence=EnterpriseClusterPbxContract::measure($this->pbxEvidencePaths);
            if (!hash_equals($this->config['pbx_contract'],$evidence['contract'])) { throw new \DomainException('PBX matching prerequisites changed; delivery is fenced.'); }
        }
        $key=hash('sha256',$this->config['cluster_id']."\0".$this->config['node_id']."\0".$this->config['witness_id']."\0"
            .$this->config['witness_epoch']."\0".$this->config['pbx_contract']."\0".$this->boot);
        $cached=self::$leaseCache[$key]??null;
        if (is_array($cached) && ($cached['owner']??null)===$this->config['node_id'] && ($cached['boot_id']??null)===$this->boot
            && is_int($cached['expires_at']??null) && $cached['expires_at']>time()+1) { return $this->validateLeaseReply($cached); }
        $lease=$this->validateLeaseReply($this->protocol->request($this->config['witness_id'],'lease.acquire',
            ['boot_id'=>$this->boot,'pbx_contract'=>$this->config['pbx_contract']]));
        self::$leaseCache=[$key=>$lease]; return $lease;
    }
    public function authorize(): array
    {
        return $this->admit(function(): array {
            if ($this->config['role']==='witness') { throw new \DomainException('Witness-only runtime cannot process notification jobs.'); }
            $state=$this->store->read();
            if ($state['control']['stopped']) { throw new \DomainException('Cluster STOP blocks new notification work.'); }
            $claim=$this->config['mode']==='notification_ha' ? $this->acquire() : null;
            return ['ok'=>true,'enabled'=>true,'claim'=>$claim];
        });
    }
    public function begin(array $intent): array
    {
        return $this->admit(function() use($intent): array {
            if ($this->config['role']==='witness') { throw new \DomainException('An independent witness never submits delivery effects.'); }
            $intent=self::intent($intent); $this->assertFresh($intent,time()); $id=self::effectId($intent);
            $prepared=$this->store->transaction(function(array &$s) use($intent,$id): bool {
                $delivery=hash('sha256',$intent['delivery_id']); $history=$s['effect_history'][$delivery]??null;
                if ($history && ($history['closed'] || $history['expires_at']!==$intent['expires_at'] || isset($history['effects'][$id]))) {
                    throw new \DomainException('Local effect delivery or destination identity was already consumed.');
                }
                $old=$s['effects'][$id]??null;
                if ($old) {
                    if ($old['state']==='prepared' && $old['intent']===$intent && $old['owner']===$this->config['node_id'] && $old['boot_id']===$this->boot) { return true; }
                    throw new \DomainException('Local effect duplicate or previously consumed identity.');
                }
                if ($s['control']['stopped']) { throw new \DomainException('Local STOP blocked submission.'); }
                if ($intent['site_id']!==$this->config['site_id']) { throw new \DomainException('Effect belongs to another site.'); }
                if ($this->config['mode']==='edge' && !in_array($intent['channel'],$this->config['local_device_channels'],true)) { throw new \DomainException('An edge cannot submit phone or external provider actions.'); }
                if ($this->config['mode']!=='notification_ha' && $s['control']['spent_cents']+$intent['estimated_cost_cents']>$s['control']['spend_limit_cents']) { throw new \DomainException('Local spending ceiling blocks this effect.'); }
                if ($this->config['mode']!=='notification_ha') { $s['control']['spent_cents']+=$intent['estimated_cost_cents']; }
                $state=$this->config['mode']==='notification_ha'?'prepared':'uncertain';
                $s['effects'][$id]=['intent'=>$intent,'owner'=>$this->config['node_id'],'boot_id'=>$this->boot,'token'=>0,
                    'state'=>$state,'started_at'=>time(),'receipt'=>null,'reconciliation'=>null];
                return $state==='prepared';
            });
            if ($this->config['mode']==='notification_ha') {
                $lease=$this->acquire();
                $claim=$this->protocol->request($this->config['witness_id'],'effect.begin',['boot_id'=>$this->boot,'token'=>$lease['token'],'intent'=>$intent]);
            } else { $claim=['id'=>$id,'admitted'=>true,'token'=>0]; }
            if (array_diff(array_keys($claim),['id','admitted','token','resumed'])
                || array_diff(['id','admitted','token'],array_keys($claim)) || $claim['id']!==$id || $claim['admitted']!==true
                || !is_int($claim['token']) || $claim['token']<0 || (isset($claim['resumed']) && !is_bool($claim['resumed']))) {
                throw new \RuntimeException('Authority witness returned an invalid effect claim.');
            }
            if ($prepared) {
                $this->store->transaction(function(array &$s) use($id,$claim): void {
                    $old=$s['effects'][$id]??null;
                    if (!$old || $old['state']!=='prepared' || $old['boot_id']!==$this->boot) {
                        throw new \DomainException('Prepared local effect changed before witness commit.');
                    }
                    $s['effects'][$id]['token']=$claim['token']; $s['effects'][$id]['state']='uncertain';
                });
            }
            $claim['boot_id']=$this->boot; $claim['delivery_hash']=hash('sha256',$intent['delivery_id']); return $claim;
        });
    }
    public function finish(array $claim,array $receipt): void
    {
        $this->admit(fn()=>$this->finishAdmitted($claim,$receipt),false);
    }
    private function finishAdmitted(array $claim,array $receipt): void
    {
        if (array_diff(array_keys($claim),['id','admitted','token','resumed','boot_id','delivery_hash'])
            || array_diff(['id','admitted','token','boot_id','delivery_hash'],array_keys($claim))
            || !is_string($claim['id']) || !preg_match('/^[a-f0-9]{64}$/D',$claim['id']) || $claim['admitted']!==true
            || !is_int($claim['token']) || $claim['token']<0 || $claim['boot_id']!==$this->boot
            || !is_string($claim['delivery_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$claim['delivery_hash'])
            || (isset($claim['resumed']) && !is_bool($claim['resumed']))) {
            throw new \DomainException('Invalid local cluster effect claim.');
        }
        if ($this->config['mode']==='notification_ha') {
            $this->protocol->request($this->config['witness_id'],'effect.finish',['id'=>$claim['id'],'boot_id'=>$this->boot,'token'=>$claim['token'],
                'delivery_hash'=>$claim['delivery_hash'],'receipt'=>$receipt]);
        }
        $this->store->transaction(static function(array &$s) use($claim,$receipt): void {
            $e=$s['effects'][$claim['id']]??null;
            if (!$e) {
                $delivery=$claim['delivery_hash']??''; $receiptHash=hash('sha256',EnterpriseClusterProtocol::canonical($receipt));
                if (is_string($delivery) && preg_match('/^[a-f0-9]{64}$/D',$delivery)
                    && hash_equals($s['effect_history'][$delivery]['effects'][$claim['id']]??'',$receiptHash)) { return; }
                throw new \DomainException('Local effect receipt identity was already consumed or is unknown.');
            }
            if ($e['boot_id']!==$claim['boot_id'] || $e['token']!==$claim['token']) { throw new \DomainException('Local effect receipt identity mismatch.'); }
            if (strlen(EnterpriseClusterProtocol::canonical($receipt))>65536) { throw new \DomainException('Local effect receipt exceeds its bound.'); }
            if ($e['receipt']!==null) {
                if ($e['receipt']===$receipt) { return; }
                throw new \DomainException('Committed local effect receipt cannot be changed.');
            }
            if ($e['state']!=='uncertain') { throw new \DomainException('Only an admitted uncertain effect can be finished.'); }
            $s['effects'][$claim['id']]['receipt']=$receipt;
            $s['effects'][$claim['id']]['state']=($receipt['uncertain']??true)===false ? 'complete' : 'uncertain';
        });
    }
    public function executeEffect(array $intent,callable $effect)
    {
        $claim=$this->begin($intent);
        try { $result=$effect(); }
        catch (\Throwable $error) {
            try { $this->finish($claim,['uncertain'=>true,'category'=>'external_action_interrupted']); } catch (\Throwable $ignored) {}
            throw $error;
        }
        $receipt=['uncertain'=>is_array($result) ? (!empty($result['uncertain']) || !empty($result['submission_uncertain']) || ($result['state']??'')==='uncertain') : false,
            'result'=>$result,'completed_at'=>time()];
        try { $this->finish($claim,$receipt); }
        catch (\Throwable $error) { throw new \RuntimeException('External action may have completed, but its receipt could not be replicated; do not resend.',0,$error); }
        return $result;
    }
    public function replicate(string $kind,string $id,array $record): array
    {
        return $this->admit(fn(): array=>$this->replicateAdmitted($kind,$id,$record),false);
    }
    private function replicateAdmitted(string $kind,string $id,array $record): array
    {
        if (!in_array($kind,['jobs','schedules','incidents','events'],true)) { throw new \DomainException('Invalid replica kind.'); }
        self::record($record); EnterpriseClusterConfig::id($id); $results=[];
        if ($kind==='jobs') { self::jobReplicaIntent($record['intent'],$id); }
        if ($this->config['mode']!=='notification_ha' || $this->config['role']==='witness') {
            throw new \DomainException('Recoverable replication requires an active notification-HA node.');
        }
        $lease=$this->acquireAdmitted(); $proof=['boot_id'=>$this->boot,'token'=>$lease['token']];
        $targets=array_filter($this->config['peers'],fn($p)=>$p['site_id']===$this->config['site_id']
            && $p['node_id']===$this->config['witness_id'] && $p['role']==='witness');
        $deadline=hrtime(true)+8000000000;
        foreach ($targets as $peer) {
            if (hrtime(true)>=$deadline) { $results[$peer['node_id']]=['stored'=>false,'error'=>'fanout_deadline']; continue; }
            try { $results[$peer['node_id']]=$this->protocol->request($peer['node_id'],'state.put',
                ['kind'=>$kind,'id'=>$id,'record'=>$record,'proof'=>$proof],$deadline); }
            catch (\Throwable $e) { $results[$peer['node_id']]=['stored'=>false,'error'=>'peer_unavailable']; }
        }
        if (empty($results[$this->config['witness_id']]['stored'])) { throw new \RuntimeException('Immutable job/state could not be durably replicated to its witness; automatic sending is suspended.'); }
        return $results;
    }
    public function reserveReplicaRevision(string $kind,string $id,int $minimum=0): int
    {
        return $this->admit(fn(): int=>$this->reserveReplicaRevisionAdmitted($kind,$id,$minimum),false);
    }
    private function reserveReplicaRevisionAdmitted(string $kind,string $id,int $minimum=0): int
    {
        if ($this->config['mode']!=='notification_ha' || $this->config['role']==='witness') { throw new \DomainException('Replica revisions require an active notification-HA node.'); }
        if (!in_array($kind,['jobs','schedules','incidents','events'],true) || $minimum<0) { throw new \DomainException('Invalid replica revision reservation.'); }
        EnterpriseClusterConfig::id($id);
        return $this->store->transaction(static function(array &$state) use($kind,$id,$minimum): int {
            if (isset($state['replica_consumed'][self::replicaDigest($kind,$id)])) {
                throw new \DomainException('Replica identity was already consumed during the replay-retention window.');
            }
            $key=rtrim($kind,'s').':'.$id;
            $state['snapshot_revisions'][$key]=max($state['snapshot_revisions'][$key]??0,$minimum)+1;
            return $state['snapshot_revisions'][$key];
        });
    }
    public function retireReplicaSource(string $kind,string $id): void
    {
        $this->admit(fn()=>$this->retireReplicaSourceAdmitted($kind,$id),false);
    }
    private function retireReplicaSourceAdmitted(string $kind,string $id): void
    {
        if ($this->config['mode']!=='notification_ha' || $this->config['role']==='witness') { throw new \DomainException('Replica retirement requires an active notification-HA node.'); }
        if (!in_array($kind,['jobs','schedules','incidents','events'],true)) { throw new \DomainException('Invalid replica retirement kind.'); }
        EnterpriseClusterConfig::id($id);
        $this->store->transaction(static function(array &$state) use($kind,$id): void {
            self::consumeReplica($state,$kind,$id,time());
            foreach ([$kind.':'.$id,rtrim($kind,'s').':'.$id] as $key) { unset($state['snapshot_revisions'][$key]); }
        });
    }
    public function stageReplicaOutbox(string $kind,string $id,string $sourceSha256,array $record): string
    {
        return $this->admit(fn(): string=>$this->stageReplicaOutboxAdmitted($kind,$id,$sourceSha256,$record),false);
    }
    private function stageReplicaOutboxAdmitted(string $kind,string $id,string $sourceSha256,array $record): string
    {
        if ($this->config['mode']!=='notification_ha' || $this->config['role']==='witness') {
            throw new \DomainException('A durable replication outbox requires an active notification-HA node.');
        }
        if (!in_array($kind,['incidents','schedules'],true) || !preg_match('/^[a-f0-9]{64}$/D',$sourceSha256)) { throw new \DomainException('Invalid cluster replication outbox identity.'); }
        EnterpriseClusterConfig::id($id); self::record($record); $this->acquireAdmitted();
        return $this->store->transaction(static function(array &$state) use($kind,$id,$sourceSha256,$record): string {
            $digest=self::replicaDigest($kind,$id);
            if (isset($state['replica_consumed'][$digest])) { throw new \DomainException('Replica identity was already consumed during the replay-retention window.'); }
            foreach ($state['replica_outbox'] as $existingKey=>$existing) {
                if ($existing['kind']!==$kind || $existing['id']!==$id || !hash_equals($existing['source_sha256'],$sourceSha256)) { continue; }
                $existingRecord=$existing['record']; $existingRecord['revision']=$record['revision'];
                if ($existingRecord===$record) { return (string)$existingKey; }
            }
            $previous=$state['snapshot_revisions'][$kind.':'.$id]??0; $revision=$previous+1;
            $record['revision']=$revision; self::record($record);
            $key=hash('sha256',$kind."\0".$id."\0".$sourceSha256."\0".$revision);
            if (isset($state['replica_outbox'][$key])) { return $key; }
            if (count($state['replica_outbox'])>=500) { throw new \RuntimeException('Cluster replication outbox is full; no source mutation was admitted.'); }
            $state['snapshot_revisions'][$kind.':'.$id]=$revision;
            $state['replica_outbox'][$key]=['kind'=>$kind,'id'=>$id,'source_sha256'=>$sourceSha256,
                'record'=>$record,'state'=>'prepared','created_at'=>time(),'previous_revision'=>$previous];
            return $key;
        });
    }
    public function commitReplicaOutbox(string $key,string $sourceSha256): void
    {
        $this->admit(fn()=>$this->commitReplicaOutboxAdmitted($key,$sourceSha256),false);
    }
    private function commitReplicaOutboxAdmitted(string $key,string $sourceSha256): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key) || !preg_match('/^[a-f0-9]{64}$/D',$sourceSha256)) { throw new \DomainException('Invalid cluster outbox commit identity.'); }
        $this->store->transaction(static function(array &$state) use($key,$sourceSha256): void {
            $entry=$state['replica_outbox'][$key]??null;
            if (!$entry || !hash_equals($entry['source_sha256'],$sourceSha256)) { throw new \DomainException('Cluster outbox source commit does not match its prepared record.'); }
            $state['replica_outbox'][$key]['state']='committed';
        });
    }
    public function abandonPreparedReplicaOutbox(string $key,string $sourceSha256): void
    {
        $this->admit(fn()=>$this->abandonPreparedReplicaOutboxAdmitted($key,$sourceSha256),false);
    }
    private function abandonPreparedReplicaOutboxAdmitted(string $key,string $sourceSha256): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key) || !preg_match('/^[a-f0-9]{64}$/D',$sourceSha256)) {
            throw new \DomainException('Invalid cluster outbox abandonment identity.');
        }
        $this->store->transaction(static function(array &$state) use($key,$sourceSha256): void {
            $entry=$state['replica_outbox'][$key]??null;
            if (!is_array($entry) || ($entry['state']??null)!=='prepared'
                || !is_string($entry['source_sha256']??null) || !hash_equals($entry['source_sha256'],$sourceSha256)) {
                throw new \DomainException('Only the exact prepared cluster outbox entry may be abandoned.');
            }
            unset($state['replica_outbox'][$key]);
            $revisionKey=$entry['kind'].':'.$entry['id'];
            $newer=false;
            foreach ($state['replica_outbox'] as $pending) {
                if ($pending['kind']===$entry['kind'] && $pending['id']===$entry['id']) { $newer=true; break; }
            }
            if (!$newer && ($state['snapshot_revisions'][$revisionKey]??null)===$entry['record']['revision']) {
                if ($entry['previous_revision']>0) { $state['snapshot_revisions'][$revisionKey]=$entry['previous_revision']; }
                else { unset($state['snapshot_revisions'][$revisionKey]); }
            }
        });
    }
    public function flushReplicaOutbox(callable $sourceHash,?array $kinds=null): array
    {
        return $this->admit(fn(): array=>$this->flushReplicaOutboxAdmitted($sourceHash,$kinds),false);
    }
    private function flushReplicaOutboxAdmitted(callable $sourceHash,?array $kinds=null): array
    {
        if ($kinds!==null) {
            if (!$kinds || count($kinds)>2 || count(array_filter($kinds,'is_string'))!==count($kinds)
                || array_diff($kinds,['incidents','schedules']) || count(array_unique($kinds,SORT_STRING))!==count($kinds)) {
                throw new \DomainException('Invalid cluster replication outbox filter.');
            }
        }
        $entries=$this->store->read()['replica_outbox']; $results=[];
        $processed=0;
        foreach ($entries as $key=>$entry) {
            if ($kinds!==null && !in_array($entry['kind'],$kinds,true)) { continue; }
            if (++$processed>20) { break; }
            try {
                $actual=$sourceHash($entry['kind'],$entry['id']);
                if (!is_string($actual) || !preg_match('/^[a-f0-9]{64}$/D',$actual) || !hash_equals($entry['source_sha256'],$actual)) {
                    $this->store->transaction(static function(array &$state) use($key,$entry): void {
                        if (($state['replica_outbox'][$key]??null)!==$entry) { return; }
                        unset($state['replica_outbox'][$key]); $revisionKey=$entry['kind'].':'.$entry['id'];
                        $newer=false;
                        foreach ($state['replica_outbox'] as $pending) {
                            if ($pending['kind']===$entry['kind'] && $pending['id']===$entry['id']) { $newer=true; break; }
                        }
                        if (!$newer && ($state['snapshot_revisions'][$revisionKey]??null)===$entry['record']['revision']) {
                            if ($entry['previous_revision']>0) { $state['snapshot_revisions'][$revisionKey]=$entry['previous_revision']; }
                            else { unset($state['snapshot_revisions'][$revisionKey]); }
                        }
                    });
                    $results[$key]=['state'=>'abandoned']; continue;
                }
                if ($entry['state']==='prepared') { $this->commitReplicaOutboxAdmitted((string)$key,$actual); $entry['state']='committed'; }
                $peerResults=$this->replicateAdmitted($entry['kind'],$entry['id'],$entry['record']);
                $this->store->transaction(static function(array &$state) use($key,$entry): void {
                    $current=$state['replica_outbox'][$key]??null;
                    if ($current && $current['source_sha256']===$entry['source_sha256'] && $current['record']===$entry['record']) {
                        unset($state['replica_outbox'][$key]);
                        if (in_array($entry['record']['state'],['complete','failed','uncertain','cancelled','expired'],true)) {
                            self::consumeReplica($state,$entry['kind'],$entry['id'],time());
                            unset($state['snapshot_revisions'][$entry['kind'].':'.$entry['id']]);
                        }
                    }
                });
                $results[$key]=['state'=>'replicated','peers'=>$peerResults];
            } catch (\Throwable $error) { $results[$key]=['state'=>'pending','error'=>'replication_unavailable']; }
        }
        return $results;
    }
    public function recover(): array
    { return $this->admit(fn(): array=>$this->protocol->request($this->config['witness_id'],'state.pull',[]),false); }
    public function mirror(array $settings,int $revision): array
    {
        if (!$this->config['mirroring_enabled']) { throw new \DomainException('Configuration mirroring is disabled.'); }
        if ($this->config['mirror_authority']!==$this->config['node_id']) { throw new \DomainException('Only the configured shared-policy authority may publish mirror revisions.'); }
        if ($revision<1) { throw new \DomainException('Configuration mirror revision must be positive.'); }
        return $this->admit(fn(array $current): array=>$this->mirrorAdmitted($current,$revision));
    }
    private function mirrorAdmitted(array $settings,int $revision): array
    {
        $shared=EnterpriseClusterConfig::mirror($settings,$this->config);
        $payload=['schema'=>1,'authority'=>$this->config['node_id'],'epoch'=>$this->config['mirror_epoch'],'revision'=>$revision,
            'shared'=>$shared,'content_sha256'=>hash('sha256',EnterpriseClusterProtocol::canonical($shared))];
        $result=[]; $deadline=hrtime(true)+8000000000;
        foreach ($this->config['peers'] as $p) {
            if (!in_array($p['role'],['node','coordinator'],true) || $p['site_id']!==$this->config['site_id']) { continue; }
            if (hrtime(true)>=$deadline) { $result[$p['node_id']]=['staged'=>false,'error'=>'fanout_deadline']; continue; }
            try { $result[$p['node_id']]=$this->protocol->request($p['node_id'],'mirror.put',$payload,$deadline); }
            catch (\Throwable $error) { $result[$p['node_id']]=['staged'=>false,'error'=>'peer_unavailable']; }
        }
        return $result;
    }
    public function request(string $peer,string $action,array $payload,?int $absoluteDeadlineNs=null): array
    {
        $notificationAdmission=in_array($action,['lease.acquire','effect.begin','mirror.put','site.enqueue'],true);
        return $this->admit(fn(): array=>$this->protocol->request($peer,$action,$payload,$absoluteDeadlineNs),$notificationAdmission);
    }
    public function drain(callable $dispatch,bool $connected,?callable $preflight=null): array
    {
        $this->admit(static fn()=>null);
        if ($this->config['role']==='witness') { throw new \DomainException('An independent witness never dispatches site jobs.'); }
        // A standby must not mutate queued jobs into uncertainty before it has
        // proved exclusive authority. The durable outer claim also protects
        // callers that dispatch locally without the module facade.
        if ($this->config['mode']==='notification_ha') { $this->acquire(); }
        $ids=array_keys($this->store->read()['queue']); $results=[];
        foreach ($ids as $id) {
            $job=$this->admit(fn()=>$this->store->transaction(function(array &$s) use($id,$connected,$preflight): ?array {
                $row=$s['queue'][$id]??null; if (!is_array($row)) { return null; } $j=$row['job'];
                if ($row['state']!=='queued') { return null; }
                if (time()>=$j['expires_at']) { $s['queue'][$id]['state']='expired'; return null; }
                if ($s['control']['stopped']) { return null; }
                if (!$connected && (!$this->config['offline_cache_enabled'] || !$j['offline_approved'] || !in_array($j['content_sha256'],$this->config['approved_cache'],true))) { return null; }
                if ($preflight!==null) { $preflight($j); }
                // Persist uncertainty before invoking anything. Reboot never
                // silently resets this to queued.
                $s['queue'][$id]['state']='uncertain'; return $j;
            }));
            if (!$job) { continue; }
            try {
                if ($this->config['mode']==='notification_ha') {
                    $intent=['delivery_id'=>$job['delivery_id'],'channel'=>'site_job','target'=>$job['id'],'site_id'=>$job['site_id'],
                        'created_at'=>$job['created_at'],'expires_at'=>$job['expires_at'],'content_sha256'=>$job['content_sha256'],
                        'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>''];
                    $receipt=$this->executeEffect($intent,static fn()=>$dispatch($job));
                } else { $receipt=$dispatch($job); }
                if (!is_array($receipt)) { throw new \RuntimeException('Invalid site dispatch receipt.'); }
            }
            catch (\Throwable $e) { $receipt=['uncertain'=>true,'category'=>'site_dispatch_interrupted']; }
            $this->store->transaction(static function(array &$s) use($id,$receipt): void {
                $deviceReceipts=$s['queue'][$id]['receipt']['device_receipts']??[];
                if ($deviceReceipts) { $receipt['device_receipts']=$deviceReceipts; }
                $s['queue'][$id]['receipt']=$receipt;
                $s['queue'][$id]['state']=!empty($receipt['uncertain']) ? 'uncertain' : 'complete';
            });
            $results[$id]=$receipt;
        }
        return $results;
    }
}
