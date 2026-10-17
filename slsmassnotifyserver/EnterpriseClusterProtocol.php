<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterConfig.php';
require_once __DIR__.'/EnterpriseClusterStore.php';

/** Mutual TLS, exact certificate identity, bounded canonical signed requests. */
final class EnterpriseClusterProtocol
{
    private const ACTIONS=['status','lease.acquire','lease.handoff','effect.begin','effect.finish','effect.reconcile',
        'control.set','state.put','state.pull','mirror.put','site.enqueue','site.receipt'];
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
        if ($consume) {
            if (!in_array($e['action'],self::ACTIONS,true)) { throw new \DomainException('Unsupported cluster operation.'); }
            $this->store->acceptNonce($e['source'],$e['nonce'],time());
        }
        return $e;
    }

    public function respond(array $request,array $payload): string
    {
        $peer=EnterpriseClusterConfig::peer($this->config,$request['source']);
        if (!$peer) { throw new \DomainException('Unknown peer.'); }
        return $this->envelope($peer,'reply:'.$request['action'],$payload,$request['nonce']);
    }

    private static function socketDeadline($connection, int $deadline): void
    {
        $remaining=$deadline-hrtime(true);
        if ($remaining<=0) { throw new \RuntimeException('Cluster request deadline elapsed; result is uncertain.'); }
        $microseconds=min(3000000,(int)ceil($remaining/1000));
        if (!stream_set_timeout($connection,intdiv($microseconds,1000000),$microseconds%1000000)) {
            throw new \RuntimeException('Cluster socket timeout could not be set; no further request work was performed.');
        }
    }
    private static function tlsEvidence(string $path,bool $private): array
    {
        $account=function_exists('posix_getpwnam') ? posix_getpwnam('asterisk') : false;
        $owners=array_values(array_unique([0,function_exists('posix_geteuid')?posix_geteuid():-1,is_array($account)?$account['uid']:-1]));
        $parent=dirname($path);
        while ($parent!=='/') {
            clearstatcache(true,$parent); $directory=@lstat($parent);
            if (!$directory || ($directory['mode']&0170000)!==0040000 || ($directory['mode']&0022)
                || !in_array($directory['uid'],$owners,true) || realpath($parent)!==$parent) {
                throw new \RuntimeException('Cluster TLS parent directory is unsafe.');
            }
            $parent=dirname($parent);
        }
        clearstatcache(true,$path); $before=@lstat($path); $limit=$private?65536:1048576;
        if (!$before || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1 || ($before['mode']&0022)
            || ($private && ($before['mode']&0027)) || !in_array($before['uid'],$owners,true)
            || realpath($path)!==$path || $before['size']<1 || $before['size']>$limit) {
            throw new \RuntimeException('Cluster TLS material is missing or unsafe.');
        }
        $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('Cluster TLS material cannot be opened.'); }
        try {
            $opened=fstat($handle); $bytes=stream_get_contents($handle,$limit+1); $after=fstat($handle);
            clearstatcache(true,$path); $named=@lstat($path);
            foreach (['dev','ino','mode','uid','nlink','size','mtime','ctime'] as $field) {
                if (!$named || $opened[$field]!==$before[$field] || $after[$field]!==$before[$field] || $named[$field]!==$before[$field]) {
                    throw new \RuntimeException('Cluster TLS material changed while verified.');
                }
            }
            if (!is_string($bytes) || strlen($bytes)!==$before['size']) { throw new \RuntimeException('Cluster TLS material read was incomplete.'); }
            return ['dev'=>$before['dev'],'ino'=>$before['ino'],'mode'=>$before['mode'],'uid'=>$before['uid'],
                'size'=>$before['size'],'mtime'=>$before['mtime'],'ctime'=>$before['ctime'],'sha256'=>hash('sha256',$bytes)];
        } finally { fclose($handle); }
    }
    private static function responseLength(string $header): int
    {
        $lines=explode("\r\n",$header); $status=array_shift($lines);
        if (!is_string($status) || !preg_match('/^HTTP\/1\.[01] 200(?: |$)/D',$status)) { throw new \RuntimeException('Cluster peer rejected the operation.'); }
        $length=null;
        foreach ($lines as $line) {
            if ($line==='' || preg_match('/^[ \t]/',$line) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$line)
                || !str_contains($line,':')) { throw new \DomainException('Cluster response contains invalid or folded headers.'); }
            [$name,$value]=explode(':',$line,2); $name=strtolower($name); $value=trim($value," \t");
            if (!preg_match('/^[a-z0-9-]+$/D',$name)) { throw new \DomainException('Cluster response header name is invalid.'); }
            if ($name==='transfer-encoding') { throw new \DomainException('Chunked cluster replies are prohibited.'); }
            if ($name==='content-length') {
                if ($length!==null || !preg_match('/^(?:0|[1-9][0-9]{0,6})$/D',$value)) {
                    throw new \DomainException('Cluster response has ambiguous Content-Length framing.');
                }
                $length=(int)$value;
            }
        }
        if ($length===null || $length>EnterpriseClusterConfig::MAX_WIRE) { throw new \DomainException('Cluster response length is missing or exceeds its bound.'); }
        return $length;
    }

    /** Certificate is pinned before any signed application body is transmitted. */
    public function request(string $id,string $action,array $payload,?int $absoluteDeadlineNs=null): array
    {
        $peer=EnterpriseClusterConfig::peer($this->config,$id);
        if (!$peer) { throw new \DomainException('Cluster peer is not configured.'); }
        $url=parse_url($peer['https_url']); $host=trim($url['host'],'[]'); $port=$url['port'] ?? 443;
        $authority=(str_contains($host,':')?'['.$host.']':$host).':'.$port;
        $tls=[];
        foreach (['tls_cert','tls_key','tls_ca'] as $field) { $tls[$field]=self::tlsEvidence($this->config[$field],$field==='tls_key'); }
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,
            'cafile'=>$this->config['tls_ca'],'local_cert'=>$this->config['tls_cert'],'local_pk'=>$this->config['tls_key'],
            'capture_peer_cert'=>true,'disable_compression'=>true,'security_level'=>2,
            'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $address='tls://'.(str_contains($host,':')?'['.$host.']':$host).':'.$port;
        $localDeadline=hrtime(true)+8000000000;
        $deadline=$absoluteDeadlineNs===null ? $localDeadline : min($localDeadline,$absoluteDeadlineNs);
        $remaining=$deadline-hrtime(true);
        if ($remaining<=0) { throw new \RuntimeException('Cluster request deadline elapsed before connection.'); }
        $connectTimeout=min(3.0,max(0.001,$remaining/1000000000));
        $connection=@stream_socket_client($address,$errno,$error,$connectTimeout,STREAM_CLIENT_CONNECT,$context);
        if (!$connection) { throw new \RuntimeException('Cluster peer unavailable or TLS authentication failed.'); }
        try {
            self::socketDeadline($connection,$deadline);
            foreach (['tls_cert','tls_key','tls_ca'] as $field) {
                if (self::tlsEvidence($this->config[$field],$field==='tls_key')!==$tls[$field]) {
                    throw new \RuntimeException('Cluster TLS material changed during connection; no request body was sent.');
                }
            }
            $params=stream_context_get_params($connection); $cert=$params['options']['ssl']['peer_certificate']??null;
            $pin=$cert ? openssl_x509_fingerprint($cert,'sha256') : false;
            if (!is_string($pin) || !hash_equals($peer['cert_sha256'],$pin)) { throw new \DomainException('Cluster peer certificate pin changed; no request body was sent.'); }
            $body=$this->envelope($peer,$action,$payload); $request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            $path=$url['path'] ?? '/api/sls-mass-notify/peer.php';
            $wire="POST ".$path." HTTP/1.1\r\nHost: ".$authority."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
            $offset=0;
            while ($offset<strlen($wire)) {
                self::socketDeadline($connection,$deadline);
                $n=fwrite($connection,substr($wire,$offset));
                if (!$n) { throw new \RuntimeException('Cluster request write was interrupted; result is uncertain.'); }
                $offset+=$n;
            }
            $response=''; $headerEnd=false; $length=null;
            while (!feof($connection)) {
                self::socketDeadline($connection,$deadline);
                $part=fread($connection,8192);
                if ($part===false || ($part==='' && !feof($connection))) { throw new \RuntimeException('Cluster reply was interrupted; result is uncertain.'); }
                $response.=$part;
                if (strlen($response)>EnterpriseClusterConfig::MAX_WIRE+8192) { throw new \DomainException('Oversize cluster response rejected.'); }
                if ($headerEnd===false) {
                    $headerEnd=strpos($response,"\r\n\r\n");
                    if ($headerEnd===false && strlen($response)>8192) { throw new \DomainException('Cluster response headers exceed limit.'); }
                    if ($headerEnd!==false) {
                        $header=substr($response,0,$headerEnd); $length=self::responseLength($header);
                    }
                }
                if ($headerEnd!==false && strlen($response)-$headerEnd-4 > $length) {
                    throw new \DomainException('Cluster response contains bytes beyond its declared Content-Length.');
                }
            }
            if ($headerEnd===false || strlen($response)-$headerEnd-4!==$length) { throw new \RuntimeException('Cluster reply incomplete; result is uncertain.'); }
            self::socketDeadline($connection,$deadline);
            $reply=$this->verify(substr($response,$headerEnd+4),$pin,true,false);
            if ($reply['nonce']!==$request['nonce'] || $reply['action']!=='reply:'.$action || $reply['source']!==$id) { throw new \DomainException('Cluster reply is not bound to this request.'); }
            $replyPayload=$reply['payload'];
            if (($replyPayload['ok']??false)!==true) {
                if (count($replyPayload)!==2 || array_diff(array_keys($replyPayload),['ok','error']) || $replyPayload['ok']!==false
                    || !is_string($replyPayload['error']) || $replyPayload['error']==='' || strlen($replyPayload['error'])>256
                    || preg_match('/[\x00-\x1f\x7f]/',$replyPayload['error'])) {
                    throw new \RuntimeException('Cluster peer returned an invalid rejection.');
                }
                throw new \RuntimeException('Cluster operation rejected: '.$replyPayload['error']);
            }
            if (count($replyPayload)!==2 || array_diff(array_keys($replyPayload),['ok','result']) || !is_array($replyPayload['result'])) {
                throw new \RuntimeException('Cluster peer returned an invalid success payload.');
            }
            return $replyPayload['result'];
        } finally { fclose($connection); }
    }
}
