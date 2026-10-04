<?php
declare(strict_types=1);
// Isolated, short-lived loopback TLS fixture. Never installed or published.
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterRuntime.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterEdge.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseCluster.php';
$fixture=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$r=new \SLS\MassNotify\EnterpriseClusterRuntime($fixture['settings'],$fixture['directory'],$fixture['boot_id'],$fixture['pbx_files']);
if (($argv[2]??'')==='authority') {
    try { echo json_encode(\SLS\MassNotify\EnterpriseClusterIntegration::authority($fixture['settings'],$r)); }
    catch (Throwable $error) { echo json_encode(['ok'=>false,'error'=>'cluster_effect_fenced_or_uncertain']); }
    exit;
}
if (($argv[2]??'')==='recover') {
    $module=new class($fixture) {
        use \FreePBX\modules\SlsEnterpriseCluster;
        public function __construct(private array $fixture) {}
        private function getActiveSettings(): array { return $this->fixture['settings']; }
        protected function enterpriseClusterRuntime(array $settings): \SLS\MassNotify\EnterpriseClusterRuntime {
            return new \SLS\MassNotify\EnterpriseClusterRuntime($settings,$this->fixture['directory'],$this->fixture['boot_id'],$this->fixture['pbx_files']);
        }
        private function announcementJobDirectory(): string { return $this->fixture['jobs']; }
        private function scheduledAnnouncementDeadline(array $request): ?int { return $request['schedule_deadline_at']??null; }
        public function processAnnouncementJobs(string $id): bool {
            file_put_contents($this->fixture['counter'],$id."\n",FILE_APPEND); return true;
        }
    };
    try { echo json_encode(['ok'=>true,'result'=>$module->enterpriseRecoverPendingJobs()]); }
    catch (Throwable $error) { echo json_encode(['ok'=>false,'reason'=>$error->getMessage()]); }
    exit;
}
if (($argv[2]??'')==='exercise') {
    try {
        $intent=\SLS\MassNotify\EnterpriseClusterRuntime::intent($fixture['intent']);
        $result=$r->executeEffect($intent,static function() use($fixture): array {
            file_put_contents($fixture['counter'],"effect\n",FILE_APPEND); return ['state'=>'accepted'];
        });
        echo json_encode(['ok'=>true,'result'=>$result]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'reason'=>$e->getMessage(),'diagnostic'=>error_get_last()['message']??null]); }
    exit;
}
if (($argv[2]??'')==='initialize') { $r->initialize(); echo "initialized\n"; exit; }
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
            $byte=fread($connection,1); if ($byte==='' || $byte===false || strlen($header)>8192) { throw new DomainException('Invalid HTTP header.'); }
            $header.=$byte;
        }
        if (!preg_match('/^POST \/peer HTTP\/1\.1\r\n/',$header) || !preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i',$header,$m)) { throw new DomainException('Invalid HTTP framing.'); }
        if ((int)$m[1]>\SLS\MassNotify\EnterpriseClusterConfig::MAX_WIRE) { $status=413; throw new DomainException('Oversize request.'); }
        $body=''; while (strlen($body)<(int)$m[1]) { $part=fread($connection,(int)$m[1]-strlen($body)); if (!$part) { throw new DomainException('Truncated request.'); } $body.=$part; }
        $params=stream_context_get_params($connection); $cert=$params['options']['ssl']['peer_certificate']??null;
        $pin=$cert ? openssl_x509_fingerprint($cert,'sha256') : false;
        if (!$pin) { throw new DomainException('Missing verified client certificate.'); }
        $reply=$r->handle($body,$pin,true);
    } catch (Throwable $e) { if ($status===200) { $status=403; } $reply='{"error":"fixture_rejected"}'; }
    fwrite($connection,'HTTP/1.1 '.$status.' '.($status===200?'OK':'Rejected')."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($reply)."\r\nConnection: close\r\n\r\n".$reply);
    fclose($connection);
}
fclose($server);
