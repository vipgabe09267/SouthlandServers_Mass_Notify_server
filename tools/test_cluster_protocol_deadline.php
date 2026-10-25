<?php
declare(strict_types=1);

namespace SLS\MassNotify {
    // Only this private transport is substituted. No socket is opened.
    function hrtime(bool $numeric = false) { return $GLOBALS['fixtureClock']; }
    function stream_socket_client($address, &$code, &$reason, $timeout, $flags, $context) {
        $code=0; $reason='';
        $host=parse_url(str_replace('tls://','https://',$address),PHP_URL_HOST);
        $GLOBALS['fixturePeerPin']=$GLOBALS['fixturePins'][$host]??str_repeat('a',64);
        $GLOBALS['fixturePeerSecret']=$GLOBALS['fixtureSecrets'][$host]??str_repeat('b',64);
        $GLOBALS['fixtureOffset']=0; $GLOBALS['fixtureResponse']=''; $GLOBALS['fixtureRequest']='';
        return $GLOBALS['fixtureStream']=\fopen('php://temp','w+b');
    }
    function stream_context_get_params($connection): array {
        return ['options'=>['ssl'=>['peer_certificate'=>'inert-fixture-certificate']]];
    }
    function openssl_x509_fingerprint($certificate, $algorithm): string { return $GLOBALS['fixturePeerPin']??str_repeat('a',64); }
    function stream_set_timeout($connection, $seconds, $microseconds=0): bool {
        $GLOBALS['fixtureTimeouts'][]=$seconds+$microseconds/1000000; return true;
    }
    function fwrite($connection, $bytes, ...$arguments) {
        if ($connection!==($GLOBALS['fixtureStream']??null)) { return \fwrite($connection,$bytes,...$arguments); }
        $GLOBALS['fixtureClock']+=$GLOBALS['fixtureWriteStep'];
        $GLOBALS['fixtureRequest'].=$bytes;
        $request=json_decode(explode("\r\n\r\n",$GLOBALS['fixtureRequest'],2)[1],true,32,JSON_THROW_ON_ERROR);
        $reply=$request; $reply['source']=$request['target']; $reply['target']='local'; $reply['action']='reply:'.$request['action'];
        $reply['payload']=['ok'=>true,'result'=>['confirmed'=>true]]; unset($reply['signature']);
        $reply['signature']=hash_hmac('sha256',EnterpriseClusterProtocol::canonical($reply),hex2bin($GLOBALS['fixturePeerSecret']??str_repeat('b',64)));
        $body=EnterpriseClusterProtocol::canonical($reply);
        $mode=$GLOBALS['fixtureHeaderMode']??''; $name=$mode==='lowercase'?'content-length':'Content-Length';
        $extra=match($mode){'duplicate_equal'=>"\r\nContent-Length: ".strlen($body),
            'duplicate_conflict'=>"\r\nContent-Length: 1",'folded'=>"\r\n folded: value",
            'transfer'=>"\r\nTransfer-Encoding: chunked",default=>''};
        $tail=$mode==='extra_body'?'x':'';
        $GLOBALS['fixtureResponse']="HTTP/1.1 200 OK\r\n".$name.": ".strlen($body).$extra."\r\n\r\n".$body.$tail;
        return strlen($bytes);
    }
    function feof($connection): bool {
        return $connection===($GLOBALS['fixtureStream']??null)
            ? $GLOBALS['fixtureOffset']>=strlen($GLOBALS['fixtureResponse']) : \feof($connection);
    }
    function fread($connection, $length) {
        if ($connection!==($GLOBALS['fixtureStream']??null)) { return \fread($connection,$length); }
        $GLOBALS['fixtureClock']+=$GLOBALS['fixtureReadStep'];
        $size=min($length,$GLOBALS['fixtureChunk']);
        $bytes=substr($GLOBALS['fixtureResponse'],$GLOBALS['fixtureOffset'],$size);
        $GLOBALS['fixtureOffset']+=strlen($bytes); return $bytes;
    }
}

namespace {
    $network=@readlink('/proc/self/ns/net'); $parent=getenv('SLS_TEST_PARENT_NET_NS');
    if (getenv('SLS_TEST_NAMESPACE')!=='entered' || !is_string($parent) || $parent==='' || $parent===$network) {
        throw new RuntimeException('Use tools/run_isolated_tests.sh for the cluster deadline fixture.');
    }
    require dirname(__DIR__).'/slsmassnotifyserver/EnterpriseClusterProtocol.php';
    use SLS\MassNotify\{EnterpriseClusterProtocol as Protocol, EnterpriseClusterStore as Store};
    $directory='/etc/sls-mass-notify/sls-peer-deadline-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
    try {
        foreach (['cert','key','ca'] as $name) { file_put_contents($directory.'/'.$name,'fixture'); chmod($directory.'/'.$name,0600); }
        $config=['cluster_id'=>'fixture','node_id'=>'local','witness_epoch'=>str_repeat('c',64),
            'tls_cert'=>$directory.'/cert','tls_key'=>$directory.'/key','tls_ca'=>$directory.'/ca',
            'peers'=>[['node_id'=>'peer','https_url'=>'https://localhost/peer','cert_sha256'=>str_repeat('a',64),'hmac_secret'=>str_repeat('b',64)]]];
        // Request verification consumes no incoming nonce and never calls this
        // store. Its real type is retained without initializing any PBX state.
        $store=(new ReflectionClass(Store::class))->newInstanceWithoutConstructor();
        $protocol=new Protocol($config,$store);
        $reset=static function(int $readStep,int $writeStep,int $chunk): void {
            $GLOBALS['fixtureClock']=0; $GLOBALS['fixtureReadStep']=$readStep; $GLOBALS['fixtureWriteStep']=$writeStep;
            $GLOBALS['fixtureChunk']=$chunk; $GLOBALS['fixtureOffset']=0; $GLOBALS['fixtureResponse']='';
            $GLOBALS['fixtureRequest']=''; $GLOBALS['fixtureTimeouts']=[];
            $GLOBALS['fixtureHeaderMode']='';
        };
        $reset(0,0,8192);
        if ($protocol->request('peer','status',[])!==['confirmed'=>true]) { throw new RuntimeException('Valid signed reply was rejected.'); }
        foreach ([[1000000000,0,1],[0,9000000000,8192]] as [$read,$write,$chunk]) {
            $reset($read,$write,$chunk); $rejected=false;
            try { $protocol->request('peer','status',[]); }
            catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(),'deadline')) { throw $error; }
                $rejected=true;
            }
            if (!$rejected) { throw new RuntimeException('A cumulatively slow peer bypassed the request deadline.'); }
            if ($GLOBALS['fixtureClock']>10000000000) { throw new RuntimeException('The private peer consumed an unbounded total budget.'); }
        }
        $reset(0,0,8192);$GLOBALS['fixtureHeaderMode']='lowercase';
        if ($protocol->request('peer','status',[])!==['confirmed'=>true]) { throw new RuntimeException('Case-insensitive canonical framing was rejected.'); }
        foreach (['duplicate_equal','duplicate_conflict','folded','transfer','extra_body'] as $mode) {
            $reset(0,0,8192);$GLOBALS['fixtureHeaderMode']=$mode;$rejected=false;
            try{$protocol->request('peer','status',[]);}catch(Throwable $error){$rejected=true;}
            if(!$rejected){throw new RuntimeException('Ambiguous cluster response framing was accepted: '.$mode);}
        }
        $rejectMaterial=static function(string $label) use($protocol,$reset): void {
            $reset(0,0,8192);$rejected=false;
            try{$protocol->request('peer','status',[]);}catch(RuntimeException $error){$rejected=str_contains($error->getMessage(),'TLS');}
            if(!$rejected){throw new RuntimeException('Unsafe cluster TLS material was accepted: '.$label);}
        };
        chmod($directory.'/key',0644);$rejectMaterial('public key mode');chmod($directory.'/key',0600);
        chmod($directory.'/cert',0666);$rejectMaterial('writable certificate');chmod($directory.'/cert',0600);
        chmod($directory.'/ca',0666);$rejectMaterial('writable CA');chmod($directory.'/ca',0600);
        link($directory.'/key',$directory.'/key-hardlink');$rejectMaterial('hard-linked key');unlink($directory.'/key-hardlink');
        chmod($directory,0777);$rejectMaterial('writable TLS parent');chmod($directory,0700);
        $linkedConfig=$config;$linkedConfig['tls_key']=$directory.'/key-link';symlink($directory.'/key',$linkedConfig['tls_key']);
        $linked=new Protocol($linkedConfig,$store);$reset(0,0,8192);$rejected=false;
        try{$linked->request('peer','status',[]);}catch(RuntimeException $error){$rejected=str_contains($error->getMessage(),'TLS');}
        if(!$rejected){throw new RuntimeException('Symbolic-link TLS key was accepted.');}unlink($linkedConfig['tls_key']);
        require_once dirname(__DIR__).'/slsmassnotifyserver/EnterpriseClusterRuntime.php';
        $peers=[];$GLOBALS['fixturePins']=[];$GLOBALS['fixtureSecrets']=[];
        for ($index=0;$index<32;$index++) {
            $host='peer'.$index.'.invalid';$pin=hash('sha256','pin-'.$index);$secret=hash('sha256','secret-'.$index);
            $GLOBALS['fixturePins'][$host]=$pin;$GLOBALS['fixtureSecrets'][$host]=$secret;
            $peers[]=['node_id'=>'peer'.$index,'site_id'=>'site','role'=>'node','https_url'=>'https://peer'.$index.'.invalid/peer',
                'cert_sha256'=>$pin,'hmac_secret'=>$secret];
        }
        $fanoutConfig=\SLS\MassNotify\EnterpriseClusterConfig::normalize(['enabled'=>true,'mirroring_enabled'=>true,'mirror_authority'=>'local','mirror_epoch'=>str_repeat('d',64),
            'cluster_id'=>'fixture','witness_epoch'=>'','node_id'=>'local','site_id'=>'site','role'=>'node',
            'tls_cert'=>$directory.'/cert','tls_key'=>$directory.'/key','tls_ca'=>$directory.'/ca',
            'mirror_fields'=>['quiet_hours_start'],'peers'=>$peers]);
        $receipt=['revision'=>\SLS\MassNotify\LabsSafety::revision(),'accepted_at'=>time(),'actor'=>'pbx:deadline-fixture'];
        $fanoutSettings=['enterprise_labs'=>['enabled'=>true],'enterprise_cluster'=>$fanoutConfig,'labs_safety'=>['schema'=>1,'receipts'=>['enterprise_cluster'=>$receipt]],
            'quiet_hours_start'=>'22:00'];
        $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($fanoutSettings,$directory.'/unused-journal',str_repeat('e',64),null,
            static fn():array=>$fanoutSettings);
        $reset(250000000,0,64);
        $outcomes=$runtime->mirror(['quiet_hours_start'=>'22:00'],1);
        if (count($outcomes)!==32 || $GLOBALS['fixtureClock']>9000000000
            || !array_filter($outcomes,static fn($row)=>($row['error']??'')==='fanout_deadline')) {
            throw new RuntimeException('Thirty-two-peer fanout did not retain per-peer outcomes within one total deadline.');
        }
        echo "Cluster cumulative read/write deadlines and valid signed replies passed with a private inert transport.\n";
    } finally {
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $path=$entry->getPathname();
            $entry->isDir()&&!$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($directory);
    }
}
