<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterConfig.php';
require_once __DIR__.'/EnterpriseClusterStore.php';

/** Mutual TLS, exact certificate identity, bounded canonical signed requests. */
final class EnterpriseClusterProtocol
{
    public function __construct(private array $config,private EnterpriseClusterStore $store) {}

    public static function canonical($value): string
    {
        $sort=static function($v) use (&$sort) {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v,SORT_STRING); }
            foreach ($v as &$part) { $part=$sort($part); } unset($part);
            return $v;
        };
        return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private function envelope(array $peer,string $action,array $payload,?string $nonce=null): string
    {
        $e=['version'=>1,'cluster_id'=>$this->config['cluster_id'],'epoch'=>$this->config['witness_epoch'],'source'=>$this->config['node_id'],
            'target'=>$peer['node_id'],'nonce'=>$nonce ?? bin2hex(random_bytes(24)), 'issued_at'=>time(),
            'action'=>$action,'payload'=>$payload];
        $e['signature']=hash_hmac('sha256',self::canonical($e),hex2bin($peer['hmac_secret']));
        $body=self::canonical($e);
        if (strlen($body)>EnterpriseClusterConfig::MAX_WIRE) { throw new \DomainException('Cluster request exceeds 1 MiB.'); }
        return $body;
    }

    public function verify(string $body,string $fingerprint,bool $verifiedTls,bool $consume=true): array
    {
        if (!$verifiedTls || $body==='' || strlen($body)>EnterpriseClusterConfig::MAX_WIRE) { throw new \DomainException('Cluster TLS authentication or request size rejected.'); }
        $e=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        $keys=['version','cluster_id','epoch','source','target','nonce','issued_at','action','payload','signature'];
        if (!is_array($e) || array_diff(array_keys($e),$keys) || count($e)!==count($keys)
            || self::canonical($e)!==$body || ($e['version']??null)!==1 || ($e['cluster_id']??null)!==$this->config['cluster_id']
            || ($e['epoch']??null)!==$this->config['witness_epoch'] || ($e['target']??null)!==$this->config['node_id'] || !is_string($e['source']??null)
            || !is_string($e['nonce']??null) || !preg_match('/^[a-f0-9]{48}$/D',$e['nonce'])
            || !is_int($e['issued_at']??null) || abs(time()-$e['issued_at'])>30
            || !is_string($e['action']??null) || !preg_match('/^(?:reply:)?[a-z][a-z_.]{0,47}$/D',$e['action'])
            || !is_array($e['payload']??null) || !is_string($e['signature']??null) || !preg_match('/^[a-f0-9]{64}$/D',$e['signature'])) {
            throw new \DomainException('Cluster request schema, identity, canonical encoding, or freshness rejected.');
        }
        $peer=EnterpriseClusterConfig::peer($this->config,$e['source']);
        if (!$peer || !hash_equals($peer['cert_sha256'],strtolower($fingerprint))) { throw new \DomainException('Cluster certificate does not match the claimed node.'); }
        $unsigned=$e; unset($unsigned['signature']);
        if (!hash_equals(hash_hmac('sha256',self::canonical($unsigned),hex2bin($peer['hmac_secret'])),$e['signature'])) { throw new \DomainException('Cluster message authentication rejected.'); }
        if ($consume) { $this->store->acceptNonce($e['source'],$e['nonce'],time()); }
        return $e;
    }

    public function respond(array $request,array $payload): string
    {
        $peer=EnterpriseClusterConfig::peer($this->config,$request['source']);
        if (!$peer) { throw new \DomainException('Unknown peer.'); }
        return $this->envelope($peer,'reply:'.$request['action'],$payload,$request['nonce']);
    }

    /** Certificate is pinned before any signed application body is transmitted. */
    public function request(string $id,string $action,array $payload): array
    {
        $peer=EnterpriseClusterConfig::peer($this->config,$id);
        if (!$peer) { throw new \DomainException('Cluster peer is not configured.'); }
        $url=parse_url($peer['https_url']); $host=$url['host']; $port=$url['port'] ?? 443;
        foreach (['tls_cert','tls_key','tls_ca'] as $field) {
            $path=$this->config[$field]; clearstatcache(true,$path); $m=@lstat($path);
            if (!$m || ($m['mode']&0170000)!==0100000 || $m['nlink']!==1 || realpath($path)!==$path
                || ($field==='tls_key' && ($m['mode']&0027))) { throw new \RuntimeException('Cluster TLS material is missing or unsafe.'); }
        }
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,
            'cafile'=>$this->config['tls_ca'],'local_cert'=>$this->config['tls_cert'],'local_pk'=>$this->config['tls_key'],
            'capture_peer_cert'=>true,'disable_compression'=>true,'security_level'=>2,
            'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $address='tls://'.(str_contains($host,':')?'['.$host.']':$host).':'.$port;
        $connection=@stream_socket_client($address,$errno,$error,3,STREAM_CLIENT_CONNECT,$context);
        if (!$connection) { throw new \RuntimeException('Cluster peer unavailable or TLS authentication failed.'); }
        try {
            stream_set_timeout($connection,3);
            $params=stream_context_get_params($connection); $cert=$params['options']['ssl']['peer_certificate']??null;
            $pin=$cert ? openssl_x509_fingerprint($cert,'sha256') : false;
            if (!is_string($pin) || !hash_equals($peer['cert_sha256'],$pin)) { throw new \DomainException('Cluster peer certificate pin changed; no request body was sent.'); }
            $body=$this->envelope($peer,$action,$payload); $request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            $path=$url['path'] ?? '/api/sls-mass-notify/peer.php';
            $wire="POST ".$path." HTTP/1.1\r\nHost: ".$host.":".$port."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
            $offset=0;
            while ($offset<strlen($wire)) { $n=fwrite($connection,substr($wire,$offset)); if (!$n) { throw new \RuntimeException('Cluster request write was interrupted; result is uncertain.'); } $offset+=$n; }
            $response=''; $headerEnd=false; $length=null;
            while (!feof($connection)) {
                $part=fread($connection,8192);
                if ($part===false || ($part==='' && !feof($connection))) { throw new \RuntimeException('Cluster reply was interrupted; result is uncertain.'); }
                $response.=$part;
                if (strlen($response)>EnterpriseClusterConfig::MAX_WIRE+8192) { throw new \DomainException('Oversize cluster response rejected.'); }
                if ($headerEnd===false) {
                    $headerEnd=strpos($response,"\r\n\r\n");
                    if ($headerEnd===false && strlen($response)>8192) { throw new \DomainException('Cluster response headers exceed limit.'); }
                    if ($headerEnd!==false) {
                        $header=substr($response,0,$headerEnd);
                        if (!preg_match('/^HTTP\/1\.[01] 200(?: |\r)/',$header) || stripos($header,'Transfer-Encoding:')!==false
                            || !preg_match('/\r\nContent-Length: ([0-9]{1,7})\r?$/mi',$header,$m)) { throw new \RuntimeException('Cluster peer rejected the operation or returned an invalid reply.'); }
                        $length=(int)$m[1]; if ($length>EnterpriseClusterConfig::MAX_WIRE) { throw new \DomainException('Oversize cluster reply.'); }
                    }
                }
                if ($headerEnd!==false && strlen($response)-$headerEnd-4 >= $length) { break; }
            }
            if ($headerEnd===false || strlen($response)-$headerEnd-4!==$length) { throw new \RuntimeException('Cluster reply incomplete; result is uncertain.'); }
            $reply=$this->verify(substr($response,$headerEnd+4),$pin,true,false);
            if ($reply['nonce']!==$request['nonce'] || $reply['action']!=='reply:'.$action || $reply['source']!==$id) { throw new \DomainException('Cluster reply is not bound to this request.'); }
            if (($reply['payload']['ok']??false)!==true) { throw new \RuntimeException('Cluster operation rejected: '.($reply['payload']['error']??'unspecified')); }
            return $reply['payload']['result'];
        } finally { fclose($connection); }
    }
}
