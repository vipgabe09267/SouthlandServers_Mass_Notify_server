<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterEdge.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterIntegration.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseCluster.php';
require_once __DIR__.'/../slsmassnotifyserver/ConfigCrypto.php';
use SLS\MassNotify\EnterpriseClusterConfig as C;
use SLS\MassNotify\EnterpriseClusterRuntime as R;
use SLS\MassNotify\EnterpriseClusterProtocol as P;
use SLS\MassNotify\EnterpriseClusterEdge as E;
use FreePBX\modules\SlsConfigCrypto as Crypto;
function check(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function reject(callable $work,string $message): void { try { $work(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$temporary=sys_get_temp_dir().'/sls-enterprise-unit-'.bin2hex(random_bytes(8)); mkdir($temporary,0700);
register_shutdown_function(static function() use($temporary): void {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($temporary);
});
check(C::defaults()['enabled']===false && C::defaults()['mirroring_enabled']===false && C::defaults()['remote_enabled']===false,'All switches must default off.');
$ran=0;
check(\SLS\MassNotify\EnterpriseClusterIntegration::effect([],[],'test','target',static function() use(&$ran): int { return ++$ran; })===1,'Disabled config must preserve normal dispatch.');
check(\SLS\MassNotify\EnterpriseClusterIntegration::authority([])===['ok'=>true,'enabled'=>false,'claim'=>null] && $ran===1,'Disabled supervisor authority check changed normal delivery.');
reject(static fn()=>C::normalize(['peers'=>[['node_id'=>'evil']]]),'Invalid peer schema accepted.');
reject(static fn()=>C::normalize(['mirror_fields'=>['ami']]),'AMI mirror accepted.');
reject(static fn()=>C::normalize(['peers'=>[['node_id'=>'peer','site_id'=>'site','role'=>'node','https_url'=>"https://localhost/peer\r\nInjected: bad",'cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]]),'HTTP control characters accepted in peer URL.');
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
reject(static fn()=>$admin->configureEnterpriseCluster($base,$baseRevision),'Stale cluster tab reverted newer settings.');
check($admin->settings['enterprise_cluster']['max_queue']===77,'Stale write changed current configuration.');
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
echo "PASS default-disabled bypass, strict config fields, excluded node-local settings and real independent AES-GCM keyrings\n";
$deviceToken=str_repeat('T',48); $intent=['message'=>'Approved isolated LAN content','title'=>'Local test']; $hash=hash('sha256',P::canonical($intent));
$config=['enabled'=>true,'remote_enabled'=>true,'mode'=>'edge','role'=>'node','cluster_id'=>'edge-test','node_id'=>'edge-a','site_id'=>'site-a',
    'tls_cert'=>'/unused-fixture-cert','tls_key'=>'/unused-fixture-key','tls_ca'=>'/unused-fixture-ca',
    'site_recipients'=>['desktop-a'],'offline_cache_enabled'=>true,'approved_cache'=>[$hash],'max_queue'=>1,
    'edge_devices'=>[['id'=>'desktop-a','token_sha256'=>hash('sha256',$deviceToken),'channels'=>['desktop']]],
    'peers'=>[['node_id'=>'coordinator','site_id'=>'site-a','role'=>'coordinator','https_url'=>'https://localhost/peer',
        'cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]];
mkdir($temporary.'/edge',0700);
$runtime=new R(['enterprise_cluster'=>$config],$temporary.'/edge/enterprise-cluster',str_repeat('c',64));
reject(static fn()=>$runtime->store()->read(),'Missing cluster journal silently reinitialized.');
$runtime->initialize(); $edge=new E($runtime); $now=time();
$statePath=$temporary.'/edge/enterprise-cluster/worker-state.json'; $markerPath=$temporary.'/edge/enterprise-cluster/cluster-required.json';
$stateBytes=file_get_contents($statePath); $markerBytes=file_get_contents($markerPath);
unlink($statePath);
reject(static fn()=>$runtime->initialize(),'Established missing state was reset under the same epoch.');
reject(static fn()=>$runtime->store()->read(),'Required marker permitted missing authority state.');
file_put_contents($statePath,$stateBytes); chmod($statePath,0600);
unlink($markerPath);
reject(static fn()=>$runtime->initialize(),'Existing state without its permanent marker was accepted.');
reject(static fn()=>$runtime->store()->read(),'Existing state silently bypassed missing permanent marker.');
file_put_contents($markerPath,$markerBytes); chmod($markerPath,0600);
$broken=json_decode($stateBytes,true); $broken['control']['stopped']='0'; file_put_contents($statePath,json_encode($broken));
reject(static fn()=>$runtime->store()->read(),'Damaged STOP/control schema permitted authority.');
file_put_contents($statePath,$stateBytes);
check($runtime->store()->read()['control']['stopped']===false,'Complete restored evidence did not retain original identity.');
$differentEpoch=new R(['enterprise_cluster'=>array_replace($config,['witness_epoch'=>str_repeat('e',64)])],$temporary.'/edge/enterprise-cluster',str_repeat('c',64));
reject(static fn()=>$differentEpoch->initialize(),'New epoch overwrote existing authority evidence instead of requiring separate reviewed recovery.');
echo "PASS permanent required marker, same-epoch reset prevention, partial recovery and corrupt authority schema fence\n";
$job=['id'=>'job-a','delivery_id'=>'delivery-a','site_id'=>'site-a','created_at'=>$now,'expires_at'=>$now+60,
    'recipients'=>['desktop-a'],'channels'=>['desktop'],'intent'=>$intent,'content_sha256'=>$hash,'offline_approved'=>true];
$request=['source'=>'coordinator','action'=>'site.enqueue','payload'=>$job];
check($runtime->dispatch($request)['state']==='queued','Site enqueue failed.');
check($runtime->dispatch($request)['state']==='queued','Identical job must be idempotent.');
reject(static fn()=>$runtime->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'queue-overflow'])]),'Bounded site queue overflow accepted.');
$edge->drain(false); $device=$edge->authenticate('desktop-a',$deviceToken); $events=$edge->poll($device);
check(count($events)===1 && $events[0]['message']===$intent['message'],'Approved offline cache did not serve LAN event.');
check($edge->drain(false)===[],'Completed local publication was automatically replayed.');
reject(static fn()=>$edge->authenticate('desktop-a',str_repeat('X',48)),'Wrong local device credential accepted.');
$receipt=$edge->receipt($device,['id'=>'job-a','kind'=>'application_received']);
check(!$receipt['human_acknowledgment'] && $edge->poll($device)===[],'Application receipt was conflated with human acknowledgment or event replayed.');
check($edge->receipt($device,['id'=>'job-a','kind'=>'application_received'])===$receipt,'Local application receipt should be idempotent.');
$runtime->store()->transaction(static function(array &$s): void { $s['cache']['job-a']['expires_at']=time()-1; });
reject(static fn()=>$edge->receipt($device,['id'=>'job-a','kind'=>'application_received']),'Expired edge receipt accepted.');
echo "PASS bounded owned-site queue, approved offline LAN cache, local device authentication, immutable receipts and expired replay rejection\n";
$unapproved=array_replace($config,['approved_cache'=>[],'max_queue'=>2]); mkdir($temporary.'/unapproved',0700);
$r2=new R(['enterprise_cluster'=>$unapproved],$temporary.'/unapproved/enterprise-cluster',str_repeat('d',64)); $r2->initialize();
$r2->dispatch($request); check((new E($r2))->drain(false)===[],'Unapproved cache was played offline.');
$r2->store()->transaction(static function(array &$s): void { $s['queue']['job-a']['state']='uncertain'; });
check((new E($r2))->drain(true)===[],'Interrupted site action was automatically replayed after reconnect.');
reject(static fn()=>$r2->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'expired','created_at'=>$now-120,'expires_at'=>$now-1])]),'Expired WAN job replay accepted.');
reject(static fn()=>$r2->dispatch(['source'=>'coordinator','action'=>'site.enqueue','payload'=>array_replace($job,['id'=>'telephone','channels'=>['phones']])]),'Linux edge pretended to provide phone/PBX service.');
$ha=array_replace($config,['mode'=>'notification_ha','mirroring_enabled'=>true]);
reject(static fn()=>new R(['enterprise_cluster'=>$ha],$temporary.'/danger'),'Dangerous feature was enabled without prerequisites/receipt.');
echo "PASS offline approval boundary, reconnect uncertainty, edge/PBX separation and dangerous activation rejection\n";
