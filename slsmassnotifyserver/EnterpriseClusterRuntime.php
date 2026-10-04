<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterProtocol.php';
require_once __DIR__.'/LabsSafety.php';
require_once __DIR__.'/EnterpriseClusterPbxContract.php';

/** Notification authority and remote-site jobs; heartbeats never grant authority. */
final class EnterpriseClusterRuntime
{
    private array $config;
    private EnterpriseClusterStore $store;
    private EnterpriseClusterProtocol $protocol;
    private string $boot;
    private ?array $pbxEvidencePaths;
    public function __construct(array $settings,string $directory=EnterpriseClusterStore::DIRECTORY,?string $boot=null,?array $pbxEvidencePaths=null)
    {
        $this->config=EnterpriseClusterConfig::normalize($settings['enterprise_cluster'] ?? []);
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
    public function initialize(): void { $this->store->initialize($this->config['spend_limit_cents']); }
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
        $peer=EnterpriseClusterConfig::peer($this->config,$request['source']);
        if (!$peer) { throw new \DomainException('Unknown cluster source.'); }
        $p=$request['payload']; $node=$peer['node_id']; $now=time();
        switch ($request['action']) {
            case 'status':
                self::keys($p,[]); $s=$this->store->read();
                return ['node_id'=>$this->config['node_id'],'site_id'=>$this->config['site_id'],'role'=>$this->config['role'],
                    'revision'=>$s['revision'],'lease'=>$s['lease'],'control'=>$s['control'],'queued'=>count($s['queue']),
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
                    if (isset($s['effects'][$id])) { throw new \DomainException('Effect already recorded; completed or uncertain actions are never resent.'); }
                    if ($s['control']['spent_cents']+$intent['estimated_cost_cents']>$s['control']['spend_limit_cents']) { throw new \DomainException('Cluster spending ceiling blocks this effect.'); }
                    $s['control']['spent_cents']+=$intent['estimated_cost_cents'];
                    $s['effects'][$id]=['intent'=>$intent,'owner'=>$node,'boot_id'=>$p['boot_id'],'token'=>$p['token'],
                        'state'=>'uncertain','started_at'=>$now,'receipt'=>null];
                    return ['id'=>$id,'admitted'=>true,'token'=>$p['token']];
                });
            case 'effect.finish':
                $this->witnessOnly(); self::keys($p,['id','boot_id','token','receipt']);
                if (!is_string($p['id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['id']) || !is_array($p['receipt'])
                    || strlen(EnterpriseClusterProtocol::canonical($p['receipt']))>65536) { throw new \DomainException('Invalid effect receipt.'); }
                return $this->store->transaction(static function(array &$s) use($p,$node): array {
                    $e=$s['effects'][$p['id']]??null;
                    if (!$e || $e['owner']!==$node || $e['boot_id']!==$p['boot_id'] || $e['token']!==$p['token']) { throw new \DomainException('Receipt does not belong to the effect owner and fencing token.'); }
                    if ($e['receipt']!==null && $e['receipt']!==$p['receipt']) { throw new \DomainException('Committed receipt cannot be changed.'); }
                    $s['effects'][$p['id']]['receipt']=$p['receipt'];
                    $s['effects'][$p['id']]['state']=($p['receipt']['uncertain']??true)===false ? 'complete' : 'uncertain';
                    $s['replica']['receipts'][$p['id']]=$p['receipt']; return ['recorded'=>true];
                });
            case 'effect.reconcile':
                $this->witnessOnly(); $this->requireCoordinator($peer); self::keys($p,['id','prior_node_fenced','evidence']);
                if (!is_string($p['id']) || !preg_match('/^[a-f0-9]{64}$/D',$p['id']) || $p['prior_node_fenced']!==true
                    || !is_string($p['evidence']) || strlen($p['evidence'])<20 || strlen($p['evidence'])>2048) { throw new \DomainException('Manual reconciliation requires explicit prior-node fencing and a recorded outcome review.'); }
                return $this->store->transaction(static function(array &$s) use($p,$node,$now): array {
                    if (!$s['control']['stopped'] || ($s['lease']['expires_at']??0)>$now || !isset($s['effects'][$p['id']])) { throw new \DomainException('Reconciliation requires STOP, lease expiry, and an existing immutable effect.'); }
                    $s['effects'][$p['id']]['state']='reconciled';
                    $s['effects'][$p['id']]['receipt']=['uncertain'=>true,'manual_reconciliation'=>true,'prior_node_fenced'=>true,'evidence'=>$p['evidence'],'reviewed_by'=>$node,'reviewed_at'=>$now];
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
                self::keys($p,['kind','id','record']);
                if (!in_array($p['kind'],['jobs','schedules','incidents','events'],true)) { throw new \DomainException('Invalid replica record kind.'); }
                EnterpriseClusterConfig::id($p['id']); self::record($p['record']);
                return $this->store->transaction(static function(array &$s) use($p): array {
                    $old=$s['replica'][$p['kind']][$p['id']]??null; $record=$p['record'];
                    if ($old && ($old['intent']!==$record['intent'] || $old['revision']>$record['revision'])) { throw new \DomainException('Replica intent is immutable and revisions cannot move backwards.'); }
                    if ($old && $old['revision']===$record['revision'] && $old!==$record) { throw new \DomainException('Replica revision conflicts.'); }
                    if ($old && $old['uncertain'] && !$record['uncertain']) { throw new \DomainException('Uncertainty requires manual reconciliation; snapshots cannot clear it.'); }
                    $s['replica'][$p['kind']][$p['id']]=$record; return ['stored'=>true,'revision'=>$record['revision']];
                });
            case 'state.pull':
                self::keys($p,[]);
                if ($peer['site_id']!==$this->config['site_id'] || !in_array($peer['role'],['node','coordinator'],true)) { throw new \DomainException('Replica recovery is limited to matching site nodes.'); }
                $s=$this->store->read(); return ['replica'=>$s['replica'],'effects'=>$s['effects'],'control'=>$s['control'],'revision'=>$s['revision']];
            case 'mirror.put':
                if (!$this->config['mirroring_enabled'] || $peer['site_id']!==$this->config['site_id'] || !in_array($peer['role'],['node','coordinator'],true)) { throw new \DomainException('Configuration mirroring is not enabled for this site/node.'); }
                self::keys($p,['revision','shared']);
                if (!is_int($p['revision']) || $p['revision']<1 || !is_array($p['shared'])) { throw new \DomainException('Invalid configuration mirror schema.'); }
                EnterpriseClusterConfig::applyMirror([],$p['shared'],$this->config);
                return $this->store->transaction(static function(array &$s) use($p,$node): array {
                    $old=$s['mirrors'][$node]??null;
                    if ($old && ($old['revision']>$p['revision'] || ($old['revision']===$p['revision'] && $old['shared']!==$p['shared']))) { throw new \DomainException('Configuration mirror revision moved backwards or changed.'); }
                    $s['mirrors'][$node]=$p+['applied'=>false]; return ['staged'=>true,'revision'=>$p['revision']];
                });
            case 'site.enqueue':
                $this->requireCoordinator($peer);
                if (!$this->config['remote_enabled']) { throw new \DomainException('Remote-site delivery is disabled.'); }
                self::job($p); $this->assertFresh($p,$now);
                if ($p['site_id']!==$this->config['site_id'] || array_diff($p['recipients'],$this->config['site_recipients'])) { throw new \DomainException('This job contains recipients not owned by this site.'); }
                if ($this->config['mode']==='edge' && array_diff($p['channels'],$this->config['local_device_channels'])) { throw new \DomainException('An edge cannot act as a PBX; only approved local devices are supported.'); }
                return $this->store->transaction(function(array &$s) use($p,$now): array {
                    $old=$s['queue'][$p['id']]??null;
                    if ($old) { if ($old['job']!==$p) { throw new \DomainException('Remote job identifier was reused with different immutable intent.'); } return ['id'=>$p['id'],'state'=>$old['state'],'receipt'=>$old['receipt']]; }
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
            || !is_bool($r['uncertain']) || !is_int($r['updated_at']) || !is_array($r['receipts'])
            || strlen(EnterpriseClusterProtocol::canonical($r))>262144) { throw new \DomainException('Invalid bounded replica record schema.'); }
        foreach (['schedule_id','incident_id'] as $key) { if ($r[$key]!=='') { EnterpriseClusterConfig::id($r[$key]); } }
    }
    public static function job(array $j): void
    {
        self::keys($j,['id','delivery_id','site_id','created_at','expires_at','recipients','channels','intent','content_sha256','offline_approved']);
        foreach (['id','delivery_id','site_id'] as $key) { EnterpriseClusterConfig::id($j[$key]); }
        if (!is_int($j['created_at']) || !is_int($j['expires_at']) || $j['created_at']<1 || $j['expires_at']<=$j['created_at'] || $j['expires_at']>$j['created_at']+900
            || !is_array($j['recipients']) || !array_is_list($j['recipients']) || !$j['recipients'] || count($j['recipients'])>1000
            || !is_array($j['channels']) || !array_is_list($j['channels']) || !$j['channels'] || count($j['channels'])>9
            || array_diff($j['channels'],['phones','desktop','audio','external_voice','email','sms','webhook','local_audio','local_display'])
            || !is_array($j['intent']) || !is_bool($j['offline_approved'])
            || !is_string($j['content_sha256']) || !hash_equals(hash('sha256',EnterpriseClusterProtocol::canonical($j['intent'])),$j['content_sha256'])
            || strlen(EnterpriseClusterProtocol::canonical($j))>262144) { throw new \DomainException('Invalid remote-site immutable job schema.'); }
        foreach ($j['recipients'] as $recipient) { EnterpriseClusterConfig::id($recipient); }
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
        if ($this->config['mode']==='notification_ha' && $this->config['role']!=='witness') {
            $evidence=EnterpriseClusterPbxContract::measure($this->pbxEvidencePaths);
            if (!hash_equals($this->config['pbx_contract'],$evidence['contract'])) { throw new \DomainException('PBX matching prerequisites changed; delivery is fenced.'); }
        }
        return $this->protocol->request($this->config['witness_id'],'lease.acquire',['boot_id'=>$this->boot,'pbx_contract'=>$this->config['pbx_contract']]);
    }
    public function begin(array $intent): array
    {
        if ($this->config['role']==='witness') { throw new \DomainException('An independent witness never submits delivery effects.'); }
        $intent=self::intent($intent); $this->assertFresh($intent,time()); $id=self::effectId($intent);
        if ($this->config['mode']==='notification_ha') {
            $lease=$this->acquire();
            $claim=$this->protocol->request($this->config['witness_id'],'effect.begin',['boot_id'=>$this->boot,'token'=>$lease['token'],'intent'=>$intent]);
        } else { $claim=['id'=>$id,'admitted'=>true,'token'=>0]; }
        // Witness first means a crash before local journaling also cannot replay.
        $this->store->transaction(function(array &$s) use($intent,$id,$claim): void {
            if (isset($s['effects'][$id]) || $s['control']['stopped']) { throw new \DomainException('Local effect duplicate or STOP blocked submission.'); }
            if ($intent['site_id']!==$this->config['site_id']) { throw new \DomainException('Effect belongs to another site.'); }
            if ($this->config['mode']==='edge' && !in_array($intent['channel'],$this->config['local_device_channels'],true)) { throw new \DomainException('An edge cannot submit phone or external provider actions.'); }
            if ($this->config['mode']!=='notification_ha' && $s['control']['spent_cents']+$intent['estimated_cost_cents']>$s['control']['spend_limit_cents']) { throw new \DomainException('Local spending ceiling blocks this effect.'); }
            if ($this->config['mode']!=='notification_ha') { $s['control']['spent_cents']+=$intent['estimated_cost_cents']; }
            $s['effects'][$id]=['intent'=>$intent,'owner'=>$this->config['node_id'],'boot_id'=>$this->boot,'token'=>$claim['token'],'state'=>'uncertain','started_at'=>time(),'receipt'=>null];
        });
        return $claim+['boot_id'=>$this->boot];
    }
    public function finish(array $claim,array $receipt): void
    {
        if ($this->config['mode']==='notification_ha') {
            $this->protocol->request($this->config['witness_id'],'effect.finish',['id'=>$claim['id'],'boot_id'=>$this->boot,'token'=>$claim['token'],'receipt'=>$receipt]);
        }
        $this->store->transaction(static function(array &$s) use($claim,$receipt): void {
            $e=$s['effects'][$claim['id']]??null;
            if (!$e || $e['boot_id']!==$claim['boot_id'] || $e['token']!==$claim['token']) { throw new \DomainException('Local effect receipt identity mismatch.'); }
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
        self::record($record); EnterpriseClusterConfig::id($id); $results=[];
        $targets=array_filter($this->config['peers'],fn($p)=>$p['site_id']===$this->config['site_id'] && in_array($p['role'],['node','coordinator','witness'],true));
        foreach ($targets as $peer) {
            try { $results[$peer['node_id']]=$this->protocol->request($peer['node_id'],'state.put',['kind'=>$kind,'id'=>$id,'record'=>$record]); }
            catch (\Throwable $e) { $results[$peer['node_id']]=['stored'=>false]; }
        }
        if ($this->config['mode']==='notification_ha' && empty($results[$this->config['witness_id']]['stored'])) { throw new \RuntimeException('Immutable job/state could not be durably replicated to its witness; automatic sending is suspended.'); }
        return $results;
    }
    public function recover(): array { return $this->protocol->request($this->config['witness_id'],'state.pull',[]); }
    public function mirror(array $settings,int $revision): array
    {
        if (!$this->config['mirroring_enabled']) { throw new \DomainException('Configuration mirroring is disabled.'); }
        $result=[];
        foreach ($this->config['peers'] as $p) {
            if (in_array($p['role'],['node','coordinator'],true) && $p['site_id']===$this->config['site_id']) { $result[$p['node_id']]=$this->protocol->request($p['node_id'],'mirror.put',['revision'=>$revision,'shared'=>EnterpriseClusterConfig::mirror($settings,$this->config)]); }
        }
        return $result;
    }
    public function request(string $peer,string $action,array $payload): array { return $this->protocol->request($peer,$action,$payload); }
    public function drain(callable $dispatch,bool $connected): array
    {
        if ($this->config['role']==='witness') { throw new \DomainException('An independent witness never dispatches site jobs.'); }
        // A standby must not mutate queued jobs into uncertainty before it has
        // proved exclusive authority. The durable outer claim also protects
        // callers that dispatch locally without the module facade.
        if ($this->config['mode']==='notification_ha') { $this->acquire(); }
        $ids=array_keys($this->store->read()['queue']); $results=[];
        foreach ($ids as $id) {
            $job=$this->store->transaction(function(array &$s) use($id,$connected): ?array {
                $row=$s['queue'][$id]; $j=$row['job'];
                if ($row['state']!=='queued') { return null; }
                if (time()>=$j['expires_at']) { $s['queue'][$id]['state']='expired'; return null; }
                if ($s['control']['stopped']) { return null; }
                if (!$connected && (!$this->config['offline_cache_enabled'] || !$j['offline_approved'] || !in_array($j['content_sha256'],$this->config['approved_cache'],true))) { return null; }
                // Persist uncertainty before invoking anything. Reboot never
                // silently resets this to queued.
                $s['queue'][$id]['state']='uncertain'; return $j;
            });
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
                $s['queue'][$id]['receipt']=$receipt;
                $s['queue'][$id]['state']=!empty($receipt['uncertain']) ? 'uncertain' : 'complete';
            });
            $results[$id]=$receipt;
        }
        return $results;
    }
}
