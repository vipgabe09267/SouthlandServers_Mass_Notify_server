<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterEdge.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterIntegration.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseCluster.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterPbxContract.php';
require_once __DIR__.'/../slsmassnotifyserver/ConfigCrypto.php';
use SLS\MassNotify\EnterpriseClusterConfig as C;
use SLS\MassNotify\EnterpriseClusterRuntime as R;
use SLS\MassNotify\EnterpriseClusterProtocol as P;
use SLS\MassNotify\EnterpriseClusterEdge as E;
use FreePBX\modules\SlsConfigCrypto as Crypto;
function check(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function reject(callable $work,string $message): void {
    try { $work(); }
    catch (DomainException|RuntimeException $expected) { return; }
    catch (Throwable $unexpected) { throw new RuntimeException('Unexpected rejection type: '.$unexpected::class,0,$unexpected); }
    throw new RuntimeException($message);
}
function rejectWithoutWarning(callable $work,string $message): void {
    set_error_handler(static function(int $severity,string $text,string $file,int $line): never {
        throw new ErrorException($text,0,$severity,$file,$line);
    });
    try { reject($work,$message); }
    finally { restore_error_handler(); }
}
function rejectEffectInput(string $payload): void {
    $pipes=[];$process=proc_open(['/usr/bin/timeout','--signal=KILL','5','/usr/bin/php',__DIR__.'/../slsmassnotifyserver/bin/sls_mass_notify_cluster_effect.php'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'],['bypass_shell'=>true]);
    if (!is_resource($process)) { throw new RuntimeException('Cluster effect rejection fixture could not start.'); }
    fwrite($pipes[0],$payload);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    check($exit===1&&$stdout==="{\"ok\":false,\"error\":\"cluster_effect_fenced_or_uncertain\"}\n"
        &&$stderr==="SLS cluster: effect fenced or receipt uncertain.\n",'Malformed cluster effect input crossed the fixed pre-configuration boundary.');
}
$temporary=sys_get_temp_dir().'/sls-enterprise-unit-'.bin2hex(random_bytes(8)); mkdir($temporary,0700);
register_shutdown_function(static function() use($temporary): void {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($temporary);
});
$pbxPaths=[];
foreach (array_keys(\SLS\MassNotify\EnterpriseClusterPbxContract::FILES) as $index=>$name) {
    $pbxPaths[$name]=$temporary.'/pbx-'.$name; file_put_contents($pbxPaths[$name],str_repeat(chr(65+$index),16)); chmod($pbxPaths[$name],0640);
}
$pbxBefore=\SLS\MassNotify\EnterpriseClusterPbxContract::measure($pbxPaths);
file_put_contents($pbxPaths['dialplan'],str_repeat('Z',16));
$pbxAfter=\SLS\MassNotify\EnterpriseClusterPbxContract::measure($pbxPaths);
check($pbxBefore['contract']!==$pbxAfter['contract']&&!$pbxAfter['pbx_failover_qualified'],'A same-size PBX rewrite was hidden by cached matching evidence.');
reject(static fn()=>\SLS\MassNotify\EnterpriseClusterPbxContract::measure(array_replace($pbxPaths,['dialplan'=>[]])),'A non-text PBX evidence path was accepted.');
reject(static fn()=>\SLS\MassNotify\EnterpriseClusterPbxContract::measure(array_replace($pbxPaths,['dialplan'=>$pbxPaths['endpoints']])),'Duplicate PBX evidence paths were accepted.');
foreach (['[]','{"action":"authority","action":"begin"}','{"action":"begin","channel":[],"target":"fixture","request":{}}'] as $badEffect) { rejectEffectInput($badEffect); }
check(C::defaults()['enabled']===false && C::defaults()['mirroring_enabled']===false && C::defaults()['remote_enabled']===false,'All switches must default off.');
$ran=0;
check(\SLS\MassNotify\EnterpriseClusterIntegration::effect([],[],'test','target',static function() use(&$ran): int { return ++$ran; })===1,'Disabled config must preserve normal dispatch.');
check(\SLS\MassNotify\EnterpriseClusterIntegration::authority([])===['ok'=>true,'enabled'=>false,'claim'=>null] && $ran===1,'Disabled supervisor authority check changed normal delivery.');
reject(static fn()=>C::normalize(['peers'=>[['node_id'=>'evil']]]),'Invalid peer schema accepted.');
rejectWithoutWarning(static fn()=>\SLS\MassNotify\EnterpriseClusterIntegration::intent([],['delivery_id'=>'bad-time','delivery_timestamp'=>[]],'desktop','target'),
    'Malformed cluster delivery time emitted a warning or was accepted.');
reject(static fn()=>C::normalize(['mirror_fields'=>['ami']]),'AMI mirror accepted.');
reject(static fn()=>C::normalize(['mirror_fields'=>[[]]]),'Non-text mirror field reached array comparison.');
reject(static fn()=>C::normalize(['peers'=>[['node_id'=>'peer','site_id'=>'site','role'=>'node','https_url'=>"https://localhost/peer\r\nInjected: bad",'cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]]),'HTTP control characters accepted in peer URL.');
$peerA=['node_id'=>'peer-a','site_id'=>'site','role'=>'node','https_url'=>'https://peer-a.example/cluster','cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)];
$peerB=['node_id'=>'peer-b','site_id'=>'site','role'=>'node','https_url'=>'https://peer-b.example/cluster','cert_sha256'=>str_repeat('c',64),'hmac_secret'=>str_repeat('d',64)];
reject(static fn()=>C::normalize(['peers'=>[$peerA,array_replace($peerB,['cert_sha256'=>$peerA['cert_sha256']])]]),'A certificate pin was shared by two peer identities.');
reject(static fn()=>C::normalize(['peers'=>[$peerA,array_replace($peerB,['hmac_secret'=>$peerA['hmac_secret']])]]),'A pair HMAC secret was shared by two peer identities.');
reject(static fn()=>C::normalize(['peers'=>[$peerA,array_replace($peerB,['https_url'=>'https://PEER-A.example:443/cluster'])]]),'One HTTPS endpoint was assigned to two peer identities.');
$edgeBase=['enabled'=>true,'remote_enabled'=>true,'mode'=>'edge','role'=>'node','cluster_id'=>'edge-matrix','node_id'=>'edge-matrix-a','site_id'=>'site',
    'tls_cert'=>'/fixture-cert','tls_key'=>'/fixture-key','tls_ca'=>'/fixture-ca','site_recipients'=>['edge-a','edge-b'],
    'peers'=>[['node_id'=>'coord','site_id'=>'site','role'=>'coordinator','https_url'=>'https://coord.example/cluster','cert_sha256'=>str_repeat('e',64),'hmac_secret'=>str_repeat('f',64)]],
    'edge_devices'=>[['id'=>'edge-a','token_sha256'=>str_repeat('1',64),'channels'=>['desktop']]]];
reject(static fn()=>C::normalize(array_replace($edgeBase,['edge_devices'=>[
    ['id'=>'edge-a','token_sha256'=>str_repeat('1',64),'channels'=>['desktop']],
    ['id'=>'edge-b','token_sha256'=>str_repeat('1',64),'channels'=>['desktop']]
]])),'One bearer-token hash was shared by two edge devices.');
reject(static fn()=>C::normalize(array_replace($edgeBase,['edge_devices'=>[['id'=>'edge-a','token_sha256'=>str_repeat('1',64),'channels'=>['desktop','desktop']]]])),'Duplicate edge channels were accepted.');
reject(static fn()=>C::normalize(array_replace($edgeBase,['edge_devices'=>[['id'=>'edge-a','token_sha256'=>str_repeat('1',64),'channels'=>[[]]]]])),'Non-text edge channel reached array comparison.');
reject(static fn()=>C::normalize(array_replace($edgeBase,['role'=>'coordinator'])),'An edge was accepted with coordinator role.');
reject(static fn()=>C::normalize(array_replace($edgeBase,['mirroring_enabled'=>true])),'An edge was allowed to mirror PBX configuration.');
$witnessBase=['enabled'=>true,'mode'=>'standalone','role'=>'witness','cluster_id'=>'witness-matrix','node_id'=>'witness','site_id'=>'site',
    'tls_cert'=>'/fixture-cert','tls_key'=>'/fixture-key','tls_ca'=>'/fixture-ca','witness_epoch'=>str_repeat('2',64),
    'initial_owner'=>'peer-a','pbx_contract'=>str_repeat('3',64),'peers'=>[$peerA]];
check(C::normalize($witnessBase)['role']==='witness','A complete independent witness topology was rejected.');
reject(static fn()=>C::normalize(array_replace($witnessBase,['initial_owner'=>''])),'A witness without an initial owner was accepted.');
reject(static fn()=>C::normalize(array_replace($witnessBase,['remote_enabled'=>true])),'A witness was allowed to dispatch remote jobs.');
reject(static fn()=>C::normalize(['enabled'=>true,'mode'=>'standalone','role'=>'node','cluster_id'=>'node','node_id'=>'node','site_id'=>'site',
    'tls_cert'=>'/fixture-cert','tls_key'=>'/fixture-key','tls_ca'=>'/fixture-ca','auto_failover'=>true]),'A standalone node accepted an ignored HA switch.');
reject(static fn()=>C::normalize(array_replace($witnessBase,['role'=>'node','mode'=>'notification_ha','node_id'=>'peer-b','witness_id'=>'witness',
    'peers'=>[['node_id'=>'witness','site_id'=>'site','role'=>'witness','https_url'=>'https://witness.example/cluster',
        'cert_sha256'=>str_repeat('5',64),'hmac_secret'=>str_repeat('6',64)],$peerA],'auto_failover'=>true])),
    'Unqualified automatic PBX takeover was accepted.');
$mirrorBase=['enabled'=>true,'mirroring_enabled'=>true,'mode'=>'standalone','role'=>'node','cluster_id'=>'mirror-matrix',
    'node_id'=>'mirror-source','site_id'=>'site','tls_cert'=>'/fixture-cert','tls_key'=>'/fixture-key','tls_ca'=>'/fixture-ca',
    'mirror_authority'=>'mirror-source','mirror_epoch'=>str_repeat('4',64),'peers'=>[$peerA]];
check(C::normalize($mirrorBase)['mirror_authority']==='mirror-source','A complete single-authority mirror topology was rejected.');
reject(static fn()=>C::normalize(array_replace($mirrorBase,['mirror_authority'=>'missing'])),'An unknown mirror authority was accepted.');
reject(static fn()=>C::normalize(array_replace($mirrorBase,['mirror_epoch'=>''])),'Mirroring without a reviewed authority epoch was accepted.');
$mirrorSourceReceipt=['revision'=>\SLS\MassNotify\LabsSafety::revision(),'accepted_at'=>time(),'actor'=>'pbx:fixture'];
$mirrorSourceSettings=['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$mirrorBase,'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$mirrorSourceReceipt]]];
$mirrorSourceRuntime=new R($mirrorSourceSettings,$temporary.'/mirror-source-journal',str_repeat('8',64),null,static fn()=>$mirrorSourceSettings);
reject(static fn()=>$mirrorSourceRuntime->mirror($mirrorSourceSettings,0),'A nonpositive mirror revision reached cluster fanout.');
$admin=new class {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public array $settings=[]; public ?string $savedRevision=null;
    protected function assertEnterpriseAdministrator(): void {}
    private function getActiveSettings(): array { return $this->settings; }
    protected function saveEnterpriseNamespace(string $key,array $config,?string $revision=null): void {
        check($revision!==null && hash_equals(hash('sha256',json_encode(C::normalize($this->settings[$key]??[]),JSON_THROW_ON_ERROR)),$revision),'Expected revision was not passed to the locked central writer.');
        $this->savedRevision=$revision; $this->settings[$key]=$config;
    }
};
$base=C::defaults(); $baseRevision=hash('sha256',json_encode($base,JSON_THROW_ON_ERROR)); $changed=$base; $changed['max_queue']=77;
$saved=$admin->configureEnterpriseCluster($changed,$baseRevision);
check($saved['revision']===hash('sha256',json_encode($changed,JSON_THROW_ON_ERROR)) && $admin->savedRevision===$baseRevision,'Configuration revision roundtrip failed.');
reject(static fn()=>$admin->configureEnterpriseCluster(['peers'=>'not-a-list'],$saved['revision']),'Malformed peer container reached iteration.');
reject(static fn()=>$admin->configureEnterpriseCluster($base,$baseRevision),'Stale cluster tab reverted newer settings.');
check($admin->settings['enterprise_cluster']['max_queue']===77,'Stale write changed current configuration.');
rejectWithoutWarning(static fn()=>$admin->enterpriseDistributeJob(['peer_id'=>[],'job'=>[]]),
    'Malformed distributed peer identity emitted a warning or crossed the administrator boundary.');
$jobBuilder=new class {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public function build(array $input):array{return $this->buildEnterpriseDistributedJob($input);}
};
$before=time();
[$builtPeer,$builtJob]=$jobBuilder->build(['peer_id'=>'remote-node','site_id'=>'remote-site','ttl'=>120,
    'recipients'=>['device-a'],'channels'=>['desktop'],'intent'=>['title'=>'Fixture','message'=>'Server-built job'],
    'offline_approved'=>false]);
check($builtPeer==='remote-node' && str_starts_with($builtJob['id'],'site-')
    && str_starts_with($builtJob['delivery_id'],'remote-') && $builtJob['site_id']==='remote-site'
    && $builtJob['created_at']>=$before && $builtJob['created_at']<=time()
    && $builtJob['expires_at']===$builtJob['created_at']+120
    && hash_equals(hash('sha256',P::canonical($builtJob['intent'])),$builtJob['content_sha256']),
    'The PBX did not supply an immutable remote-job identity, clock and canonical digest.');
R::job($builtJob);
reject(static fn()=>$jobBuilder->build(['peer_id'=>'remote-node','site_id'=>'remote-site','ttl'=>120.0,
    'recipients'=>['device-a'],'channels'=>['desktop'],'intent'=>[],'offline_approved'=>false]),
    'A browser-supplied non-integer remote-job lifetime was accepted.');
$recoveryParser=new class {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public function parse(array $recovery):array{return $this->enterpriseRecoveryCollections($recovery);}
};
rejectWithoutWarning(static fn()=>$recoveryParser->parse(['replica'=>'invalid']),
    'Malformed witness recovery containers emitted a warning or crossed the standby boundary.');
$recoveryEnvelope=['replica'=>['jobs'=>[],'schedules'=>[],'incidents'=>[],'events'=>[],'receipts'=>[]],
    'replica_provenance'=>[],'effects'=>[],'effect_history'=>[],'control'=>[],'revision'=>0];
check($recoveryParser->parse($recoveryEnvelope)===[[],[],[]],'A valid empty witness recovery envelope was rejected.');
$ring=static function(string $id,string $key): array { return ['format'=>Crypto::KEYRING_FORMAT,'active'=>$id,'keys'=>[$id=>['created_at'=>time(),'key'=>base64_encode($key)]]]; };
$sourceRing=$ring(str_repeat('a',32),random_bytes(32)); $targetRing=$ring(str_repeat('b',32),random_bytes(32));
$source=['ami'=>['password'=>'source-secret'],'public_pbx_host'=>'source.example','phone_device_limit'=>25,'enterprise_cluster'=>['node_id'=>'source'],
    'quiet_hours_start'=>'22:00','incident_workflows'=>['enabled'=>false]];
$local=['ami'=>['password'=>'target-secret'],'public_pbx_host'=>'target.example','phone_device_limit'=>50,'enterprise_cluster'=>['node_id'=>'target'],
    'quiet_hours_start'=>'21:00'];
$c=C::defaults(); $shared=C::mirror($source,$c); $merged=C::applyMirror($local,$shared,$c);
check($merged['ami']===$local['ami'] && $merged['public_pbx_host']===$local['public_pbx_host'] && $merged['phone_device_limit']===50 && $merged['enterprise_cluster']===$local['enterprise_cluster'],'Node-local fields leaked through mirroring.');
$sourceEnvelope=Crypto::encodeWithKeyring($source,$sourceRing); $targetEnvelope=Crypto::encodeWithKeyring($merged,$targetRing);
check(Crypto::decodeWithKeyring($targetEnvelope,$targetRing)===$merged,'Node-local AES re-encryption failed.');
reject(static fn()=>Crypto::decodeWithKeyring($targetEnvelope,$sourceRing),'Replica improperly uses source keyring.');
check(json_decode($targetEnvelope,true)['key_id']!==json_decode($sourceEnvelope,true)['key_id'],'Replica key identity was copied.');
$nearLimit=['occurrences'=>array_fill(0,36599,null)];
$nearLimit['occurrences']['occ-near-limit']=['schedule_id'=>'schedule-a','occurrence_id'=>'occ-near-limit','run_at_utc'=>'2026-10-15T12:00:00Z','state'=>'queued'];
$scheduleDelta=\SLS\MassNotify\EnterpriseClusterIntegration::scheduleDelta($nearLimit,'occ-near-limit');
check($scheduleDelta['id']==='occ-near-limit'
    && ($scheduleDelta['record']['receipts']['snapshot']['schedule_id']??'')==='schedule-a'
    && count($scheduleDelta['record']['receipts'])===1,
    'A one-occurrence schedule change expanded into the retained schedule ledger.');
unset($nearLimit);
$haPeers=[
    ['node_id'=>'node-b','site_id'=>'site','role'=>'node','https_url'=>'https://node-b.example/cluster','cert_sha256'=>str_repeat('1',64),'hmac_secret'=>str_repeat('2',64)],
    ['node_id'=>'witness','site_id'=>'site','role'=>'witness','https_url'=>'https://witness.example/cluster','cert_sha256'=>str_repeat('3',64),'hmac_secret'=>str_repeat('4',64)],
];
$haConfig=array_replace(C::defaults(),['enabled'=>true,'mode'=>'notification_ha','role'=>'node','cluster_id'=>'ha-test','node_id'=>'node-a',
    'site_id'=>'site','witness_id'=>'witness','witness_epoch'=>str_repeat('5',64),'initial_owner'=>'node-a','pbx_contract'=>$pbxAfter['contract'],
    'tls_cert'=>'/fixture-cert','tls_key'=>'/fixture-key','tls_ca'=>'/fixture-ca','peers'=>$haPeers]);
$haSettings=['enterprise_labs'=>['enabled'=>true],'runtime_enabled'=>true,'enterprise_cluster'=>$haConfig,'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>[
    'revision'=>\SLS\MassNotify\LabsSafety::revision(),'accepted_at'=>time(),'actor'=>'pbx:fixture']]]];
mkdir($temporary.'/ha-outbox',0700);
$haRuntime=new R($haSettings,$temporary.'/ha-outbox/enterprise-cluster',str_repeat('6',64),$pbxPaths,static fn()=>$haSettings);
$haRuntime->initialize();
$leaseKey=hash('sha256',$haConfig['cluster_id']."\0".$haConfig['node_id']."\0".$haConfig['witness_id']."\0"
    .$haConfig['witness_epoch']."\0".$haConfig['pbx_contract']."\0".str_repeat('6',64));
$leaseCache=new ReflectionProperty(R::class,'leaseCache');
$leaseCache->setValue(null,[$leaseKey=>['owner'=>'node-a','boot_id'=>str_repeat('6',64),'token'=>1,'expires_at'=>time()+60]]);
$outboxRecord=['revision'=>1,'intent'=>['id'=>'schedule-fixture'],'state'=>'queued','uncertain'=>false,
    'schedule_id'=>'schedule-fixture','incident_id'=>'','updated_at'=>time(),'receipts'=>[]];
$outboxHash=hash('sha256','local-source-fixture');
$outboxKey=$haRuntime->stageReplicaOutbox('schedules','schedule-fixture',$outboxHash,$outboxRecord);
$stagedState=$haRuntime->store()->read();
check(($stagedState['replica_outbox'][$outboxKey]['state']??'')==='prepared','Schedule outbox was not durably prepared before a local write.');
reject(static fn()=>$haRuntime->abandonPreparedReplicaOutbox($outboxKey,str_repeat('f',64)),'A mismatched source hash abandoned a prepared replica.');
check(isset($haRuntime->store()->read()['replica_outbox'][$outboxKey]),'A rejected abandonment removed its outbox entry.');
$haRuntime->abandonPreparedReplicaOutbox($outboxKey,$outboxHash);
$abandonedState=$haRuntime->store()->read();
check(!isset($abandonedState['replica_outbox'][$outboxKey],$abandonedState['snapshot_revisions']['schedules:schedule-fixture']),
    'A failed local write left its prepared replica or revision reservation behind.');
$committedKey=$haRuntime->stageReplicaOutbox('schedules','schedule-fixture',$outboxHash,$outboxRecord);
$haRuntime->commitReplicaOutbox($committedKey,$outboxHash);
reject(static fn()=>$haRuntime->abandonPreparedReplicaOutbox($committedKey,$outboxHash),'A committed replica was removable through the failed-write cleanup path.');
check(($haRuntime->store()->read()['replica_outbox'][$committedKey]['state']??'')==='committed','Rejected committed-row abandonment changed durable state.');
echo "PASS default-disabled bypass, strict config fields, excluded node-local settings, AES-GCM keyrings and exact prepared-outbox cleanup\n";
$deviceToken=str_repeat('T',48); $intent=['message'=>'Approved isolated LAN content','title'=>'Local test']; $hash=hash('sha256',P::canonical($intent));
$config=['enabled'=>true,'remote_enabled'=>true,'mode'=>'edge','role'=>'node','cluster_id'=>'edge-test','node_id'=>'edge-a','site_id'=>'site-a',
    'tls_cert'=>'/unused-fixture-cert','tls_key'=>'/unused-fixture-key','tls_ca'=>'/unused-fixture-ca',
    'site_recipients'=>['desktop-a'],'offline_cache_enabled'=>true,'approved_cache'=>[$hash],'max_queue'=>1,
    'edge_devices'=>[['id'=>'desktop-a','token_sha256'=>hash('sha256',$deviceToken),'channels'=>['desktop']]],
    'peers'=>[['node_id'=>'coordinator','site_id'=>'site-a','role'=>'coordinator','https_url'=>'https://localhost/peer',
        'cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]];
mkdir($temporary.'/edge',0700);
check(!C::enabled(['enterprise_cluster'=>$config])
    &&!C::enabled(['enterprise_labs'=>['enabled'=>false],'enterprise_cluster'=>$config])
    &&!C::enabled(['enterprise_labs'=>['enabled'=>'1'],'enterprise_cluster'=>$config]),
    'Cluster activation accepted a missing, disabled or non-boolean Labs master.');
foreach ([null,false] as $index=>$master) {
    $policy=['enterprise_cluster'=>$config,'runtime_enabled'=>true];
    if ($master!==null) { $policy['enterprise_labs']=['enabled'=>$master]; }
    mkdir($temporary.'/master-off-'.$index,0700);
    $disabled=new R($policy,$temporary.'/master-off-'.$index.'/enterprise-cluster',str_repeat('8',64),null,static fn()=>$policy);
    reject(static fn()=>$disabled->initialize(),'Disabled Labs initialized cluster authority state.');
    check(!is_file($temporary.'/master-off-'.$index.'/enterprise-cluster/worker-state.json'),
        'Refused initialization created a cluster journal.');
}
$runtime=new R(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config],$temporary.'/edge/enterprise-cluster',str_repeat('c',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config]);
$productionFactory=new class($config) {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public function __construct(private array $cluster) {}
    private function getActiveSettings():array{return ['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$this->cluster];}
    private function validateConfigSchema(array $candidate):array{return [];}
    private function normalizeSettings(array $candidate):array{return $candidate;}
    public function make():R{return $this->enterpriseClusterRuntime($this->getActiveSettings());}
};
$productionRuntime=$productionFactory->make();$providerProperty=new ReflectionProperty(R::class,'settingsProvider');
check($providerProperty->getValue($productionRuntime)===null,
    'The production module runtime bypassed the fixed protected settings-lock reader.');
reject(static fn()=>$runtime->store()->read(),'Missing cluster journal silently reinitialized.');
$runtime->initialize(); $edge=new E($runtime); $now=time();
$replayPath=$temporary.'/edge/enterprise-cluster/cluster-replay.json';$replayNonce=str_repeat('1',48);
$runtime->store()->acceptNonce('coordinator',$replayNonce,$now);$replayFirst=json_decode(file_get_contents($replayPath),true,32,JSON_THROW_ON_ERROR);
reject(static fn()=>$runtime->store()->acceptNonce('coordinator',$replayNonce,$now),'A repeated signed nonce was accepted.');
$replayDuplicate=json_decode(file_get_contents($replayPath),true,32,JSON_THROW_ON_ERROR);
check($replayDuplicate['global']['tokens_milli']===$replayFirst['global']['tokens_milli']-1000
    &&$replayDuplicate['peers']['coordinator']['tokens_milli']===$replayFirst['peers']['coordinator']['tokens_milli']-1000,
    'A rejected replay did not consume its durable global and peer rate allowance.');
$replayDuplicate['peers']=[];for($i=0;$i<32;$i++){$replayDuplicate['peers']['retired-'.$i]=['tokens_milli'=>1000000,'updated_at'=>$replayDuplicate['last_time']];}
file_put_contents($replayPath,json_encode($replayDuplicate,JSON_THROW_ON_ERROR),LOCK_EX);chmod($replayPath,0600);
$runtime->store()->acceptNonce('coordinator',str_repeat('2',48),$now+1);$replayPruned=json_decode(file_get_contents($replayPath),true,32,JSON_THROW_ON_ERROR);
check(array_keys($replayPruned['peers'])===['coordinator'],'Removed peer rate buckets were not pruned before admitting a configured peer.');
$damagedReplay=$replayPruned;$damagedReplay['nonces']['coordinator:'.str_repeat('3',48)]='invalid';
file_put_contents($replayPath,json_encode($damagedReplay,JSON_THROW_ON_ERROR),LOCK_EX);chmod($replayPath,0600);$damagedReplayBytes=file_get_contents($replayPath);
rejectWithoutWarning(static fn()=>$runtime->store()->acceptNonce('coordinator',str_repeat('4',48),$now+2),
    'A malformed replay nonce was silently pruned or emitted a warning.');
check(file_get_contents($replayPath)===$damagedReplayBytes,'Rejected replay-journal damage was rewritten.');
file_put_contents($replayPath,json_encode($replayPruned,JSON_THROW_ON_ERROR),LOCK_EX);chmod($replayPath,0600);
$statePath=$temporary.'/edge/enterprise-cluster/worker-state.json'; $markerPath=$temporary.'/edge/enterprise-cluster/cluster-required.json';
$beforeInvalidTransaction=file_get_contents($statePath);
reject(static fn()=>$runtime->store()->transaction(static function(array &$state):void{unset($state['cluster_id']);}),
    'A transaction callback removed protected cluster identity.');
check(file_get_contents($statePath)===$beforeInvalidTransaction,'Rejected cluster postcondition was written to disk.');
$runtime->store()->transaction(static function(array &$state):void{$state['control']['stopped']=true;});
reject(static fn()=>\SLS\MassNotify\EnterpriseClusterIntegration::authority(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config],$runtime),
    'Non-HA authority preflight ignored durable STOP.');
$runtime->store()->transaction(static function(array &$state):void{$state['control']['stopped']=false;});
$stateBytes=file_get_contents($statePath); $markerBytes=file_get_contents($markerPath);
unlink($statePath);
reject(static fn()=>$runtime->initialize(),'Established missing state was reset under the same epoch.');
reject(static fn()=>$runtime->store()->read(),'Required marker permitted missing authority state.');
file_put_contents($statePath,$stateBytes); chmod($statePath,0600);
unlink($markerPath);
reject(static fn()=>$runtime->initialize(),'Existing state without its permanent marker was accepted.');
reject(static fn()=>$runtime->store()->read(),'Existing state silently bypassed missing permanent marker.');
file_put_contents($markerPath,$markerBytes); chmod($markerPath,0600);
$ambiguousMarker='{"schema":0,'.substr($markerBytes,1);
file_put_contents($markerPath,$ambiguousMarker);
reject(static fn()=>$runtime->store()->read(),'Repeated marker fields authorized a cluster journal.');
check(file_get_contents($markerPath)===$ambiguousMarker,'Rejected marker evidence was overwritten.');
file_put_contents($markerPath,$markerBytes);
$broken=json_decode($stateBytes,true); $broken['control']['stopped']='0'; file_put_contents($statePath,json_encode($broken));
reject(static fn()=>$runtime->store()->read(),'Damaged STOP/control schema permitted authority.');
$broken=json_decode($stateBytes,true);$broken['effects']='invalid';file_put_contents($statePath,json_encode($broken));
rejectWithoutWarning(static fn()=>$runtime->store()->read(),'Malformed effect storage emitted a warning or crossed journal validation.');
$broken=json_decode($stateBytes,true);unset($broken['replica']['jobs']);file_put_contents($statePath,json_encode($broken));
rejectWithoutWarning(static fn()=>$runtime->store()->read(),'Incomplete replica storage emitted a warning or crossed journal validation.');
file_put_contents($statePath,$stateBytes);
check($runtime->store()->read()['control']['stopped']===false,'Complete restored evidence did not retain original identity.');
$differentIdentityConfig=array_replace($config,['cluster_id'=>'different-cluster']);
$differentIdentity=new R(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$differentIdentityConfig],$temporary.'/edge/enterprise-cluster',str_repeat('c',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$differentIdentityConfig]);
reject(static fn()=>$differentIdentity->initialize(),'New cluster identity overwrote existing authority evidence instead of requiring separate reviewed recovery.');
echo "PASS permanent required marker, same-epoch reset prevention, partial recovery and corrupt authority schema fence\n";
$job=['id'=>'job-a','delivery_id'=>'delivery-a','site_id'=>'site-a','created_at'=>$now,'expires_at'=>$now+60,
    'recipients'=>['desktop-a'],'channels'=>['desktop'],'intent'=>$intent,'content_sha256'=>$hash,'offline_approved'=>true];
R::job($job);
reject(static fn()=>R::job(array_replace($job,['id'=>'123'])),'A numeric-only job identity was accepted as an unstable PHP map key.');
reject(static fn()=>R::job(array_replace($job,['recipients'=>['desktop-a','desktop-a']])),'Duplicate remote recipients were accepted.');
reject(static fn()=>R::job(array_replace($job,['channels'=>['desktop','desktop']])),'Duplicate remote channels were accepted.');
reject(static fn()=>R::job(array_replace($job,['channels'=>[[]]])),'Non-text remote channel reached deduplication.');
reject(static fn()=>R::pbxJobRecipients(['message'=>'Fixture','targets'=>[[]]]),'Non-text remote selector reached deduplication.');
reject(static fn()=>R::job(array_replace($job,['recipients'=>['z-device','a-device']])),'Noncanonical remote recipient ordering was accepted.');
reject(static fn()=>R::job(array_replace($job,['channels'=>['webhook','desktop']])),'Noncanonical remote channel ordering was accepted.');
$request=['source'=>'coordinator','action'=>'site.enqueue','payload'=>$job];
rejectWithoutWarning(static fn()=>\SLS\MassNotify\EnterpriseClusterIntegration::command(
    ['delivery_id'=>'bad-time','delivery_timestamp'=>[],'schedule_deadline_at'=>$now+60],'true',['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config]),
    'Malformed cluster command time emitted a warning or was accepted.');
$replicaBuilder=new ReflectionMethod(\SLS\MassNotify\EnterpriseClusterIntegration::class,'jobReplicaRecord');
$replicaJob=['id'=>'replica-job','state'=>'complete','created_at'=>gmdate('c',$now),
    'request'=>['delivery_id'=>'replica-job'],'receipts'=>[],'result'=>[]];
$replicaPrepared=$replicaBuilder->invoke(null,$replicaJob);
check($replicaPrepared['id']==='replica-job'&&$replicaPrepared['record']['state']==='complete',
    'A valid immutable job could not be prepared before revision reservation.');
rejectWithoutWarning(static fn()=>$replicaBuilder->invoke(null,array_replace($replicaJob,['created_at'=>'2026-10-15T12:00:00'])),
    'A timezone-ambiguous immutable job creation time was accepted.');
rejectWithoutWarning(static fn()=>\SLS\MassNotify\EnterpriseClusterIntegration::intent([],['delivery_id'=>'ambiguous-time','delivery_timestamp'=>'2026-10-15T12:00:00'],'desktop','target'),
    'A timezone-ambiguous cluster effect creation time was accepted.');
rejectWithoutWarning(static fn()=>\SLS\MassNotify\EnterpriseClusterIntegration::command(
    ['delivery_id'=>'ambiguous-command','delivery_timestamp'=>'2026-10-15T12:00:00','schedule_deadline_at'=>$now+60],
    'true',['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$config]),'A timezone-ambiguous cluster command time was accepted.');
rejectWithoutWarning(static fn()=>$replicaBuilder->invoke(null,array_replace($replicaJob,['result'=>'invalid'])),
    'A scalar job result crossed the pre-reservation replica validator.');
check($runtime->dispatch($request)['state']==='queued','Site enqueue failed.');
check($runtime->dispatch($request)['state']==='queued','Identical job must be idempotent.');
reject(static fn()=>$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'queue-overflow'])]),'Bounded site queue overflow accepted.');
$policyEffect=R::intent(['delivery_id'=>'paused-direct','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>$now,'expires_at'=>$now+60,'content_sha256'=>$hash,'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$policyCallbacks=0;
foreach([false,'0',null] as $policy){
    $pausedRuntime=new R(['enterprise_labs'=>['enabled'=>true],'enabled'=>'0','runtime_enabled'=>$policy,'enterprise_cluster'=>$config],$temporary.'/edge/enterprise-cluster',str_repeat('c',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'enabled'=>'0','runtime_enabled'=>$policy,'enterprise_cluster'=>$config]);
    $pausedEdge=new E($pausedRuntime);$beforePolicy=file_get_contents($statePath);
    foreach([static fn()=>$pausedEdge->drain(false),static fn()=>$pausedRuntime->executeEffect($policyEffect,static function() use(&$policyCallbacks):array{++$policyCallbacks;return ['state'=>'accepted'];})] as $attempt){
        $rejected=false;
        try{$attempt();}catch(DomainException $error){$rejected=$policy===false?str_contains($error->getMessage(),'stopped'):str_contains($error->getMessage(),'must be a boolean');}
        check($rejected,'Paused or malformed edge runtime did not fail at the strict notification gate.');
    }
    check($policyCallbacks===0&&file_get_contents($statePath)===$beforePolicy,'Runtime rejection executed an effect or consumed queued/replay state.');
    check($pausedRuntime->dispatch(['source'=>'coordinator','action'=>'status','payload'=>[]])['queued']===1,'Runtime policy blocked read-only cluster status.');
    reject(static fn()=>$pausedRuntime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,
        ['id'=>'paused-'.$policy,'delivery_id'=>'paused-delivery-'.$policy])]),'Paused runtime admitted a new remote-site notification.');
    check($pausedRuntime->store()->read()['queue']['job-a']['job']['expires_at']===$job['expires_at'],'Pause renewed or altered queued expiry.');
}
$pausedRuntime=new R(['enterprise_labs'=>['enabled'=>true],'runtime_enabled'=>false,'enterprise_cluster'=>$config],$temporary.'/edge/enterprise-cluster',str_repeat('c',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'runtime_enabled'=>false,'enterprise_cluster'=>$config]);
$pausedEdge=new E($pausedRuntime);
$edge->drain(false); $device=$edge->authenticate('desktop-a',$deviceToken); $events=$edge->poll($device);
check(count($events)===1 && $events[0]['message']===$intent['message'],'Approved offline cache did not serve LAN event.');
check($edge->drain(false)===[],'Completed local publication was automatically replayed.');
reject(static fn()=>$edge->authenticate('desktop-a',str_repeat('X',48)),'Wrong local device credential accepted.');
$receipt=$pausedEdge->receipt($device,['id'=>'job-a','kind'=>'application_received']);
check(!$receipt['human_acknowledgment'] && $edge->poll($device)===[],'Application receipt was conflated with human acknowledgment or event replayed.');
check($edge->receipt($device,['id'=>'job-a','kind'=>'application_received'])===$receipt,'Local application receipt should be idempotent.');
$displayed=$edge->receipt($device,['id'=>'job-a','kind'=>'displayed']);
check($displayed['kind']==='displayed' && $edge->receipt($device,['id'=>'job-a','kind'=>'application_received'])===$receipt,'Display evidence overwrote the exact earlier application receipt.');
check($edge->receipt($device,['id'=>'job-a','kind'=>'displayed'])===$displayed,'Display receipt was not idempotent.');
reject(static fn()=>$edge->receipt($device,['id'=>'job-a','kind'=>'audio_played']),'A desktop-only device reported audio playback.');
check($edge->drain(true)===[],'Resumed runtime replayed an already completed publication or paused receipt.');
echo "PASS Weather-independent edge dispatch, strict stopped/malformed runtime fences, unchanged queue claims/expiry, paused status and actual device receipt\n";
$changingConfig=array_replace($config,['max_queue'=>2]);mkdir($temporary.'/changing-policy',0700);
$currentPolicy=['enterprise_labs'=>['enabled'=>true],'runtime_enabled'=>true,'enterprise_cluster'=>$changingConfig];
$changingRuntime=new R($currentPolicy,$temporary.'/changing-policy/enterprise-cluster',str_repeat('f',64),null,
    static function() use(&$currentPolicy):array{return $currentPolicy;});
$changingRuntime->initialize();
$masterReceipt=R::intent(['delivery_id'=>'master-receipt','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>$now,'expires_at'=>$now+60,'content_sha256'=>$hash,'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$masterClaim=$changingRuntime->begin($masterReceipt);$masterBefore=$changingRuntime->store()->read();
$currentPolicy['enterprise_labs']['enabled']=false;$masterEffects=0;
reject(static fn()=>$changingRuntime->initialize(),'Dynamically disabled Labs reinitialized cluster authority.');
reject(static fn()=>$changingRuntime->executeEffect($policyEffect,static function()use(&$masterEffects):array{++$masterEffects;return ['state'=>'accepted'];}),
    'Dynamically disabled Labs admitted an effect through a cached runtime.');
check($masterEffects===0&&$changingRuntime->store()->read()['effects']===$masterBefore['effects'],
    'Master-disabled effect consumed history or executed its callback.');
check($changingRuntime->dispatch(['source'=>'coordinator','action'=>'status','payload'=>[]])['queued']===0,
    'Master disable blocked read-only cluster status.');
$changingRuntime->finish($masterClaim,['uncertain'=>false,'state'=>'accepted']);
check(($changingRuntime->store()->read()['effects'][$masterClaim['id']]['receipt']['state']??'')==='accepted',
    'Master disable prevented recording an already admitted effect receipt.');
$currentPolicy['enterprise_labs']['enabled']=true;
foreach(['first','second'] as $suffix){$changingRuntime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'changing-'.$suffix,'delivery_id'=>'changing-delivery-'.$suffix])]);}
$currentPolicy['enterprise_cluster']['max_queue']=3;
$beforePolicyMismatch=file_get_contents($temporary.'/changing-policy/enterprise-cluster/worker-state.json');
reject(static fn()=>$changingRuntime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,
    ['id'=>'changed-policy','delivery_id'=>'changed-policy-delivery'])]),'Remote dispatch used a stale constructor policy snapshot.');
reject(static fn()=>$changingRuntime->request('coordinator','status',[]),'Outbound cluster request used a stale constructor policy snapshot.');
check(file_get_contents($temporary.'/changing-policy/enterprise-cluster/worker-state.json')===$beforePolicyMismatch,
    'Rejected stale-policy dispatch changed cluster journal state.');
$currentPolicy['enterprise_cluster']['max_queue']=2;
$freshReceiptIntent=R::intent(['delivery_id'=>'current-policy-receipt','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>$now,'expires_at'=>$now+60,'content_sha256'=>$hash,'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$freshReceiptClaim=$changingRuntime->begin($freshReceiptIntent);$currentPolicy['enterprise_cluster']['max_queue']=3;
reject(static fn()=>$changingRuntime->finish($freshReceiptClaim,['uncertain'=>false,'state'=>'accepted']),
    'Receipt commit used a stale constructor policy snapshot.');
check(($changingRuntime->store()->read()['effects'][$freshReceiptClaim['id']]['receipt']??null)===null,
    'Rejected stale-policy receipt changed effect evidence.');
$currentPolicy['enterprise_cluster']['max_queue']=2;
$changingRuntime->finish($freshReceiptClaim,['uncertain'=>false,'state'=>'accepted']);
$dispatches=[];$stoppedDuringDrain=false;
try{
    $changingRuntime->drain(static function(array $queued) use(&$dispatches,&$currentPolicy):array{
        $dispatches[]=$queued['id'];$currentPolicy['runtime_enabled']=false;return ['uncertain'=>false,'state'=>'accepted'];
    },false);
}catch(DomainException $error){$stoppedDuringDrain=str_contains($error->getMessage(),'stopped');}
$afterStop=$changingRuntime->store()->read();
check($stoppedDuringDrain&&$dispatches===['changing-first'],'Stop between jobs used a stale constructor policy or dispatched the second job.');
check($afterStop['queue']['changing-first']['state']==='complete'&&$afterStop['queue']['changing-second']['state']==='queued'
    &&$afterStop['queue']['changing-second']['job']['expires_at']===$job['expires_at'],'Concurrent stop consumed or renewed unadmitted work.');
$beforeEffects=$afterStop['effects'];
reject(static fn()=>$changingRuntime->executeEffect($policyEffect,static function() use(&$dispatches):array{$dispatches[]='forbidden-effect';return ['state'=>'accepted'];}),'Direct effect used stale running policy.');
check($changingRuntime->store()->read()['effects']===$beforeEffects&&$dispatches===['changing-first'],'Stopped direct effect consumed a replay claim.');
$currentPolicy['runtime_enabled']=true;
$resumed=$changingRuntime->drain(static function(array $queued) use(&$dispatches):array{$dispatches[]=$queued['id'];return ['uncertain'=>false,'state'=>'accepted'];},false);
check(array_keys($resumed)===['changing-second']&&$dispatches===['changing-first','changing-second'],'Resume replayed admitted work or lost the original unclaimed job.');
check($changingRuntime->drain(static function() use(&$dispatches):array{$dispatches[]='replay';return [];},true)===[],'Completed jobs replayed on a subsequent drain.');
$currentPolicy['runtime_enabled']='0';
reject(static fn()=>$changingRuntime->executeEffect($policyEffect,static fn()=>['state'=>'accepted']),'Current malformed policy was hidden by an earlier valid snapshot.');
$currentPolicy['runtime_enabled']=true;$currentPolicy['enterprise_cluster']['enabled']=false;
reject(static fn()=>$changingRuntime->executeEffect($policyEffect,static fn()=>['state'=>'accepted']),'Revoked cluster enablement was cached as an authority grant.');
check($changingRuntime->store()->read()['effects']===$beforeEffects,'Changed policy consumed a previously untouched effect identity.');
echo "PASS fresh per-claim policy, stop between jobs, revoked cluster enablement, unclaimed expiry preservation and single resume delivery\n";
$runtime->store()->transaction(static function(array &$s): void {$created=time()-61;$expires=time()-1;
    $s['cache']['job-a']['created_at']=$created;$s['cache']['job-a']['expires_at']=$expires;
    foreach($s['cache']['job-a']['receipts'] as &$receipt){$receipt['received_at']=$created+1;if(isset($receipt['stages'])){foreach($receipt['stages'] as &$stage){$stage['received_at']=$created+1;}unset($stage);}}unset($receipt);
    $s['queue']['job-a']['job']['created_at']=$created;$s['queue']['job-a']['job']['expires_at']=$expires;$s['queue']['job-a']['state']='complete';
    $s['queue']['job-a']['receipt']['device_receipts']=$s['cache']['job-a']['receipts'];});
reject(static fn()=>$edge->receipt($device,['id'=>'job-a','kind'=>'application_received']),'Expired edge receipt accepted.');
$replacement=array_replace($job,['id'=>'job-after-compaction','delivery_id'=>'delivery-after-compaction','created_at'=>time(),'expires_at'=>time()+60]);
check($runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>$replacement])['state']==='queued','Expired terminal work permanently exhausted queue capacity.');
$reused=array_replace($job,['created_at'=>time(),'expires_at'=>time()+60]);
reject(static fn()=>$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>$reused]),'Consumed job identity was reusable during replay retention.');
$runtime->drain(static function(array $queued) use($runtime): array {
    $runtime->store()->transaction(static function(array &$state) use($queued): void {
        $state['queue'][$queued['id']]['receipt']['device_receipts']['desktop-a']=['kind'=>'application_received'];
    });
    return ['uncertain'=>false,'state'=>'published'];
},false);
check(($runtime->store()->read()['queue']['job-after-compaction']['receipt']['device_receipts']['desktop-a']['kind']??'')==='application_received',
    'Final queue receipt overwrote device evidence recorded during publication.');
echo "PASS bounded owned-site queue, approved offline LAN cache, local device authentication, immutable receipts and expired replay rejection\n";
$unapproved=array_replace($config,['approved_cache'=>[],'max_queue'=>2]); mkdir($temporary.'/unapproved',0700);
$r2=new R(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$unapproved],$temporary.'/unapproved/enterprise-cluster',str_repeat('d',64),null,static fn()=>['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$unapproved]); $r2->initialize();
$r2->dispatch($request); check((new E($r2))->drain(false)===[],'Unapproved cache was played offline.');
$r2->store()->transaction(static function(array &$s): void { $s['queue']['job-a']['state']='uncertain'; });
check((new E($r2))->drain(true)===[],'Interrupted site action was automatically replayed after reconnect.');
reject(static fn()=>$r2->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'expired','created_at'=>$now-120,'expires_at'=>$now-1])]),'Expired WAN job replay accepted.');
reject(static fn()=>$r2->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'telephone','channels'=>['phones']])]),'Linux edge pretended to provide phone/PBX service.');
$ha=array_replace($config,['mode'=>'notification_ha','mirroring_enabled'=>true]);
reject(static fn()=>new R(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$ha],$temporary.'/danger'),'Dangerous feature was enabled without prerequisites/receipt.');
echo "PASS offline approval boundary, reconnect uncertainty, edge/PBX separation and dangerous activation rejection\n";
$cycle=new class {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public array $calls=[];
    private function getActiveSettings(): array { return ['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>['enabled'=>true]]; }
    public function enterpriseApplyPendingMirrors(): array { $this->calls[]='mirrors'; throw new RuntimeException('poisoned mirror'); }
    public function enterpriseFlushReplicationOutbox(): array { $this->calls[]='replication_outbox'; return ['flushed'=>true]; }
    public function enterpriseRecoverPendingJobs(): array { $this->calls[]='recovery'; return ['job'=>'recovered']; }
    public function enterpriseProcessRemoteJobs(?bool $connected=null): array { $this->calls[]='site_jobs'; return ['job'=>'processed']; }
};
$cycleResult=$cycle->enterpriseClusterCycle();
check($cycle->calls===['mirrors','replication_outbox','recovery','site_jobs'],'A failed mirror prevented independent cluster work.');
check($cycleResult['mirrors']===['ok'=>false,'error_code'=>'cluster_mirrors_unavailable']
    && $cycleResult['replication_outbox']['ok']===true && $cycleResult['recovery']['ok']===true && $cycleResult['site_jobs']['ok']===true,
    'Cluster cycle did not return bounded per-component outcomes.');
echo "PASS poisoned mirror isolation and continued recovery/site processing\n";
$localConfig=array_replace($config,['mode'=>'standalone','remote_enabled'=>false,'offline_cache_enabled'=>false,'site_recipients'=>[],
    'edge_devices'=>[],'approved_cache'=>[]]);
mkdir($temporary.'/local-effect',0700);
$localRuntime=new R(['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$localConfig],$temporary.'/local-effect/enterprise-cluster',str_repeat('9',64),null,
    static fn()=>['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$localConfig]);
$localRuntime->initialize();
$reviewIntent=R::intent(['delivery_id'=>'reconciliation-schema','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>time(),'expires_at'=>time()+60,'content_sha256'=>str_repeat('6',64),'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$reviewClaim=$localRuntime->begin($reviewIntent);$beforeBadReview=file_get_contents($temporary.'/local-effect/enterprise-cluster/worker-state.json');
reject(static fn()=>$localRuntime->store()->transaction(static function(array &$state)use($reviewClaim):void{
    $state['effects'][$reviewClaim['id']]['state']='reconciled';$state['effects'][$reviewClaim['id']]['reconciliation']=['prior_node_fenced'=>true];
}),'Incomplete reconciliation evidence passed journal validation.');
check(file_get_contents($temporary.'/local-effect/enterprise-cluster/worker-state.json')===$beforeBadReview,'Rejected reconciliation evidence changed the journal.');
$localRuntime->finish($reviewClaim,['uncertain'=>false,'state'=>'accepted']);
$expiredPreparedIntent=R::intent(['delivery_id'=>'expired-prepared','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>time()-120,'expires_at'=>time()-60,'content_sha256'=>str_repeat('5',64),'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$expiredPreparedId=R::effectId($expiredPreparedIntent);
$localRuntime->store()->transaction(static function(array &$state)use($expiredPreparedIntent,$expiredPreparedId):void{
    $state['effects'][$expiredPreparedId]=['intent'=>$expiredPreparedIntent,'owner'=>'edge-a','boot_id'=>str_repeat('9',64),'token'=>0,
        'state'=>'prepared','started_at'=>$expiredPreparedIntent['created_at'],'receipt'=>null,'reconciliation'=>null];
});
$localRuntime->store()->transaction(static fn(array &$state)=>null);$afterPreparedExpiry=$localRuntime->store()->read();
check(!isset($afterPreparedExpiry['effects'][$expiredPreparedId])
    &&($afterPreparedExpiry['effect_history'][hash('sha256','expired-prepared')]['closed']??false)===true,
    'An expired never-admitted prepared effect permanently consumed active journal capacity.');
reject(static fn()=>$localRuntime->stageReplicaOutbox('incidents','incident-a',str_repeat('a',64),[
    'revision'=>1,'intent'=>[],'state'=>'running','uncertain'=>false,'schedule_id'=>'','incident_id'=>'incident-a','updated_at'=>time(),'receipts'=>[]
]),'A standalone node admitted an HA replication outbox row.');
$localIntent=R::intent(['delivery_id'=>'local-receipt','channel'=>'desktop','target'=>'desktop-a','site_id'=>'site-a',
    'created_at'=>time(),'expires_at'=>time()+60,'content_sha256'=>str_repeat('8',64),'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
$localClaim=$localRuntime->begin($localIntent);$localReceipt=['uncertain'=>false,'state'=>'accepted'];
$localRuntime->finish($localClaim,$localReceipt);$localRuntime->finish($localClaim,$localReceipt);
reject(static fn()=>$localRuntime->finish($localClaim,['uncertain'=>true,'state'=>'changed']),
    'A completed local effect receipt was replaceable.');
$afterReceipt=$localRuntime->store()->read();
check($afterReceipt['effects']===[]
    && ($afterReceipt['effect_history'][$localClaim['delivery_hash']]['effects'][$localClaim['id']]??'')===hash('sha256',P::canonical($localReceipt)),
    'A completed effect did not compact into replay-preserving receipt history.');
$bulkCreated=time();$bulkExpires=$bulkCreated+120;
$localRuntime->store()->transaction(static function(array &$state) use($bulkCreated,$bulkExpires): void {
    for($i=0;$i<1000;$i++){
        $intent=R::intent(['delivery_id'=>'bulk-effect-capacity','channel'=>'desktop','target'=>'target-'.$i,'site_id'=>'site-a',
            'created_at'=>$bulkCreated,'expires_at'=>$bulkExpires,'content_sha256'=>str_repeat('7',64),'estimated_cost_cents'=>0,'schedule_id'=>'','incident_id'=>'']);
        $id=R::effectId($intent);$state['effects'][$id]=['intent'=>$intent,'owner'=>'edge-a','boot_id'=>str_repeat('9',64),'token'=>0,
            'state'=>'complete','started_at'=>$bulkCreated,'receipt'=>['uncertain'=>false,'state'=>'accepted'],'reconciliation'=>null];
    }
});
$localRuntime->store()->transaction(static fn(array &$state)=>null);
$bulkState=$localRuntime->store()->read();$bulkDelivery=hash('sha256','bulk-effect-capacity');
check($bulkState['effects']===[] && count($bulkState['effect_history'][$bulkDelivery]['effects']??[])===1000
    && strlen(P::canonical($bulkState))<\SLS\MassNotify\EnterpriseClusterStore::MAX_STATE_BYTES,
    'A 1,000-destination completed delivery did not roll into bounded replay history.');
echo "PASS exact journal postconditions, non-HA STOP preflight, expired preparation cleanup, immutable receipts and 1,000-destination effect rollover\n";
$mirrorTarget=array_replace($mirrorBase,['node_id'=>'mirror-target','mirror_authority'=>'peer-a','peers'=>[$peerA]]);
$mirrorReceipt=['revision'=>\SLS\MassNotify\LabsSafety::revision(),'accepted_at'=>time(),'actor'=>'pbx:fixture'];
$mirrorSettings=['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$mirrorTarget,'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$mirrorReceipt]]];
mkdir($temporary.'/mirror-target',0700);$mirrorRuntime=new R($mirrorSettings,$temporary.'/mirror-target/enterprise-cluster',str_repeat('5',64),null,static fn()=>$mirrorSettings);
$mirrorRuntime->initialize();$shared=['quiet_hours_start'=>'20:00'];$mirror=['schema'=>1,'authority'=>'peer-a','epoch'=>$mirrorTarget['mirror_epoch'],'revision'=>1,
    'shared'=>$shared,'content_sha256'=>hash('sha256',P::canonical($shared)),'state'=>'pending','reason'=>''];
$mirrorRuntime->store()->transaction(static function(array &$state)use($mirror):void{$state['mirrors']['peer-a']=$mirror;});
$mirrorFinalizer=new class {
    use \FreePBX\modules\SlsEnterpriseCluster;
    public array $lockOrder=[];
    private function acquireSettingsLock($quiesceAnnouncements=false) {
        $this->lockOrder[]='settings_acquired';
        $handle=tmpfile();
        if (!is_resource($handle)) { throw new RuntimeException('Unable to create the mirror settings-lock fixture.'); }
        return $handle;
    }
    private function releaseSettingsLock($handle):void {
        $this->lockOrder[]='settings_released';
        if (is_resource($handle)) { fclose($handle); }
    }
    public function markCommit():void{$this->lockOrder[]='commit';}
    public function finish(R $runtime,string $node,array $mirror,callable $commit):array{return $this->finalizeEnterpriseMirror($runtime,$node,$mirror,$commit);}
    public function rejectLegacy(R $runtime,string $node,array $mirror):bool{return $this->rejectLegacyEnterpriseMirror($runtime,$node,$mirror);}
};
$contended=false;$outcome=$mirrorFinalizer->finish($mirrorRuntime,'peer-a',$mirror,function()use($mirrorRuntime,$mirrorFinalizer,&$contended):array{
    $mirrorFinalizer->markCommit();
    try{$mirrorRuntime->store()->transaction(static fn(array &$state)=>null);}catch(RuntimeException $error){$contended=str_contains($error->getMessage(),'busy');}
    return ['state'=>'applied','reason'=>''];
});
check($contended&&$outcome===['state'=>'applied','reason'=>'']&&$mirrorRuntime->store()->read()['mirrors']['peer-a']['state']==='applied',
    'Mirror settings commit was not serialized against a concurrently staged revision.');
check($mirrorFinalizer->lockOrder===['settings_acquired','commit','settings_released'],
    'Mirror finalization did not preserve the settings-before-cluster global lock order.');
$next=array_replace($mirror,['revision'=>2,'state'=>'pending']);$mirrorRuntime->store()->transaction(static function(array &$state)use($next):void{$state['mirrors']['peer-a']=$next;});
reject(static fn()=>$mirrorFinalizer->finish($mirrorRuntime,'peer-a',$next,static function():array{throw new RuntimeException('injected settings write failure');}),
    'Interrupted mirror settings write was reported as terminal.');
check($mirrorRuntime->store()->read()['mirrors']['peer-a']===$next,'Interrupted mirror settings write did not remain pending for exact retry.');
$legacy=['revision'=>3,'shared'=>['quiet_hours_start'=>'19:00'],'applied'=>false];$mirrorRuntime->store()->transaction(static function(array &$state)use($legacy):void{$state['mirrors']['peer-a']=$legacy;});
check($mirrorFinalizer->rejectLegacy($mirrorRuntime,'peer-a',$legacy),'Legacy pending mirror was not retired.');$legacyRejected=$mirrorRuntime->store()->read()['mirrors']['peer-a'];
check($legacyRejected['state']==='rejected'&&$legacyRejected['reason']==='legacy_mirror_requires_restage'
    &&$legacyRejected['epoch']===$mirrorTarget['mirror_epoch'],'Legacy pending mirror inherited executable authority in the current epoch.');
echo "PASS serialized mirror commit, interrupted retry preservation and legacy epoch restaging\n";
