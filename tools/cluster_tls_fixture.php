<?php
declare(strict_types=1);
// Isolated, short-lived loopback TLS fixture. Never installed or published.
$network=@readlink('/proc/self/ns/net');
$testParent=getenv('SLS_TEST_PARENT_NET_NS');$buildParent=getenv('SLS_BUILD_PARENT_NET');
$privateTest=getenv('SLS_TEST_NAMESPACE')==='entered'&&is_string($testParent)&&$testParent!==''&&$testParent!==$network;
$privateBuild=getenv('SLS_BUILD_STAGE')==='isolated'&&is_string($buildParent)&&$buildParent!==''&&$buildParent!==$network;
if(PHP_SAPI!=='cli'||!is_string($network)||!($privateTest||$privateBuild)){
    fwrite(STDERR,"Use tools/run_isolated_tests.sh; the cluster fixture requires an isolated network namespace.\n");
    exit(2);
}
if(count($argv)<2||count($argv)>3){fwrite(STDERR,"Usage: cluster_tls_fixture.php fixture.json [mode]\n");exit(64);}
$mode=$argv[2]??'serve';
if(!in_array($mode,['serve','authority','recover','stage-outbox','flush-outbox','exercise','initialize'],true)){
    fwrite(STDERR,"Unknown cluster fixture mode.\n");exit(64);
}
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterRuntime.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterEdge.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseCluster.php';
$fixture=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$provider=static fn()=>json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR)['settings'];
$mirrorValidator=static function(array $candidate,array $shared): array {
    if (array_key_exists('quiet_hours_start',$shared)
        && (!is_string($shared['quiet_hours_start']) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$shared['quiet_hours_start']))) {
        throw new DomainException('Fixture rejected a noncanonical mirrored quiet-hours value.');
    }
    return $candidate;
};
$r=new \SLS\MassNotify\EnterpriseClusterRuntime($fixture['settings'],$fixture['directory'],$fixture['boot_id'],$fixture['pbx_files'],$provider,$mirrorValidator);
$append=static function(string $path,string $value): void {
    if(file_put_contents($path,$value,FILE_APPEND)!==strlen($value)){throw new RuntimeException('Fixture side-effect journal write failed.');}
};
if ($mode==='authority') {
    try { echo json_encode(\SLS\MassNotify\EnterpriseClusterIntegration::authority($fixture['settings'],$r)); }
    catch (Throwable $error) { echo json_encode(['ok'=>false,'error'=>'cluster_effect_fenced_or_uncertain']); }
    exit;
}
if ($mode==='recover') {
    $module=new class($fixture,$append) {
        use \FreePBX\modules\SlsEnterpriseCluster;
        public function __construct(private array $fixture,private $append) {}
        private function getActiveSettings(): array { return $this->fixture['settings']; }
        protected function enterpriseClusterRuntime(array $settings): \SLS\MassNotify\EnterpriseClusterRuntime {
            return new \SLS\MassNotify\EnterpriseClusterRuntime($settings,$this->fixture['directory'],$this->fixture['boot_id'],$this->fixture['pbx_files'],fn()=>$this->fixture['settings'],static fn(array $candidate,array $shared):array=>$candidate);
        }
        private function announcementJobDirectory(): string { return $this->fixture['jobs']; }
        private function scheduledAnnouncementDeadline(array $request): ?int { return $request['schedule_deadline_at']??null; }
        public function processAnnouncementJobs(string $id): bool {
            ($this->append)($this->fixture['counter'],$id."\n"); return true;
        }
    };
    try { echo json_encode(['ok'=>true,'result'=>$module->enterpriseRecoverPendingJobs()]); }
    catch (Throwable $error) { echo json_encode(['ok'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
if ($mode==='stage-outbox') {
    try {
        $source=$fixture['outbox_source']??null;
        if (!is_array($source) || !is_array($fixture['outbox_record']??null)) { throw new DomainException('Missing outbox fixture.'); }
        $sourceHash=hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($source));
        $key=$r->stageReplicaOutbox((string)($fixture['outbox_kind']??'incidents'),(string)($fixture['outbox_id']??''),$sourceHash,$fixture['outbox_record']);
        echo json_encode(['ok'=>true,'key'=>$key,'entry'=>$r->store()->read()['replica_outbox'][$key]??null]);
    } catch (Throwable $error) { echo json_encode(['ok'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
if ($mode==='flush-outbox') {
    try {
        $results=$r->flushReplicaOutbox(static function(string $kind,string $id) use($fixture): ?string {
            $source=$fixture['outbox_source']??null;
            if ($kind!==($fixture['outbox_kind']??'incidents') || $id!==($fixture['outbox_id']??null) || !is_array($source)) { return null; }
            return hash('sha256',\SLS\MassNotify\EnterpriseClusterProtocol::canonical($source));
        },[(string)($fixture['outbox_kind']??'incidents')]);
        echo json_encode(['ok'=>true,'results'=>$results,'outbox'=>$r->store()->read()['replica_outbox']]);
    } catch (Throwable $error) { echo json_encode(['ok'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
if ($mode==='exercise') {
    try {
        $intent=\SLS\MassNotify\EnterpriseClusterRuntime::intent($fixture['intent']);
        $result=$r->executeEffect($intent,static function() use($fixture,$append): array {
            $append($fixture['counter'],"effect\n"); return ['state'=>'accepted'];
        });
        echo json_encode(['ok'=>true,'result'=>$result]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'reason'=>$e->getMessage(),'diagnostic'=>error_get_last()['message']??null]); }
    exit;
}
if ($mode==='initialize') { $r->initialize(); echo "initialized\n"; exit; }
$c=$r->config();
$context=stream_context_create(['ssl'=>['local_cert'=>$c['tls_cert'],'local_pk'=>$c['tls_key'],'cafile'=>$c['tls_ca'],
    'verify_peer'=>true,'verify_peer_name'=>false,'capture_peer_cert'=>true,'allow_self_signed'=>false,
    'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_SERVER|STREAM_CRYPTO_METHOD_TLSv1_3_SERVER]]);
$server=stream_socket_server('tls://127.0.0.1:'.$fixture['port'],$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if (!$server) { throw new RuntimeException('Fixture bind failed.'); }
stream_set_blocking($server,false); echo "ready\n"; flush();
$until=microtime(true)+90;
while (microtime(true)<$until) {
    $connection=@stream_socket_accept($server,0.1); if (!$connection) { usleep(10000); continue; }
    stream_set_timeout($connection,2); $status=200; $reply='';
    try {
        $header='';
        while (!str_contains($header,"\r\n\r\n")) {
            if(strlen($header)>=8192){throw new DomainException('Invalid HTTP header.');}
            $byte=fread($connection,1); if ($byte==='' || $byte===false) { throw new DomainException('Invalid HTTP header.'); }
            $header.=$byte;
        }
        $lengthCount=preg_match_all('/\r\nContent-Length: ([0-9]+)\r\n/i',$header,$lengths);
        if (!preg_match('/^POST \/peer HTTP\/1\.1\r\n/',$header) || $lengthCount!==1
            || preg_match('/\r\nTransfer-Encoding\s*:/i',$header)) { throw new DomainException('Invalid HTTP framing.'); }
        $m=[null,$lengths[1][0]];
        if ((int)$m[1]>\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE) { $status=413; throw new DomainException('Oversize request.'); }
        $body=''; while (strlen($body)<(int)$m[1]) { $part=fread($connection,(int)$m[1]-strlen($body)); if ($part==='' || $part===false) { throw new DomainException('Truncated request.'); } $body.=$part; }
        $params=stream_context_get_params($connection); $cert=$params['options']['ssl']['peer_certificate']??null;
        $pin=$cert ? openssl_x509_fingerprint($cert,'sha256') : false;
        if (!$pin) { throw new DomainException('Missing verified client certificate.'); }
        $reply=$r->handle($body,$pin,true);
    } catch (Throwable $e) {
        if ($status===200) { $status=403; }
        $reply=json_encode(['error'=>'fixture_rejected','diagnostic'=>substr($e->getMessage(),0,240)],JSON_THROW_ON_ERROR);
    }
    $wire='HTTP/1.1 '.$status.' '.($status===200?'OK':'Rejected')."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($reply)."\r\nConnection: close\r\n\r\n".$reply;
    for($offset=0,$length=strlen($wire);$offset<$length;){$written=fwrite($connection,substr($wire,$offset));if(!is_int($written)||$written<1){break;}$offset+=$written;}
    fclose($connection);
}
fclose($server);
