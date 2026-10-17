<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/OperatorPortal.php';
use SLS\MassNotify\OperatorPortal as Portal;
function portalCheck(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$session=[];$token=Portal::csrf($session);
portalCheck(strlen($token)===64 && Portal::csrf($session)===$token,'CSRF identity changed across reads');
$server=['HTTP_HOST'=>'pbx.example.test:8443','HTTP_ORIGIN'=>'https://pbx.example.test:8443'];
portalCheck(Portal::validPost($server,$session,$token),'Valid forwarded-port origin rejected');
foreach (['https://attacker.test','http://pbx.example.test:8443','https://pbx.example.test','null','https://user@pbx.example.test:8443','https://pbx.example.test:8443/path'] as $origin) {
    portalCheck(!Portal::validPost(array_replace($server,['HTTP_ORIGIN'=>$origin]),$session,$token),'Cross-origin submission accepted');
}
portalCheck(!Portal::validPost(array_replace($server,['HTTP_ORIGIN'=>[]]),$session,$token),'Array Origin reached URL parsing');
portalCheck(!Portal::validPost(array_replace($server,['HTTP_HOST'=>[]]),$session,$token),'Array Host reached URL parsing');
portalCheck(!Portal::validPost(array_replace($server,['HTTP_HOST'=>'user@pbx.example.test:8443']),$session,$token),'Host userinfo was accepted');
portalCheck(!Portal::validPost(array_replace($server,['HTTP_HOST'=>'pbx.example.test:8443/path']),$session,$token),'Host path was accepted');
portalCheck(!Portal::validPost($server,$session,[]),'Array CSRF accepted');
portalCheck(!Portal::validPost($server,$session,str_repeat('a',64)),'Unknown CSRF accepted');
portalCheck(!Portal::validPost($server+['HTTP_SEC_FETCH_SITE'=>'cross-site'],$session,$token),'Cross-site submission accepted');
$identity=['username'=>'fixture','mode'=>'database','password_sha1'=>str_repeat('a',40)];
$binding=Portal::binding($identity,10000);
portalCheck(Portal::current($binding,$identity,1800,10001),'Current identity denied');
foreach ([9999,11800,38800] as $now) { portalCheck(!Portal::current($binding,$identity,1800,$now),'Expired/reversed session survived'); }
foreach (['username'=>'reused','mode'=>'usermanager','password_sha1'=>str_repeat('b',40)] as $key=>$value) {
    portalCheck(!Portal::current($binding,array_replace($identity,[$key=>$value]),1800,10001),'Changed login identity survived');
}
portalCheck(Portal::current($binding,$identity+['expires_at'=>10002],1800,10001),'Future account expiry denied an active session');
portalCheck(!Portal::current($binding,$identity+['expires_at'=>10001],1800,10001),'Account expiry survived the exact boundary');
portalCheck(!Portal::current($binding,$identity+['expires_at'=>9999],1800,10001),'Expired account retained an existing session');
$root=sys_get_temp_dir().'/sls-portal-unit-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700)){throw new RuntimeException('Private portal fixture directory could not be created.');}
$hadPortalBrand=array_key_exists('portal_brand',$GLOBALS);
$previousPortalBrand=$GLOBALS['portal_brand']??null;
try {
    portalCheck(chmod($root,0700),'Private portal fixture directory permissions could not be set.');
    $prefix=$root.'/password-work'; $calls=0;
    $answer=Portal::passwordWork($prefix,function()use($prefix,&$calls){
        $calls++;
        return Portal::passwordWork($prefix,function()use($prefix,&$calls){
            $calls++;
            try { Portal::passwordWork($prefix,function()use(&$calls){$calls++;}); throw new RuntimeException('Third password worker admitted'); }
            catch (DomainException $expected) { portalCheck($expected->getCode()===429,'Busy password worker did not return retryable status'); }
            return 'two-workers';
        });
    });
    portalCheck($answer==='two-workers' && $calls===2,'Password worker bound or callback result changed');
    portalCheck(Portal::passwordWork($prefix,static fn()=>42)===42,'Released password slot remained busy');
    try { Portal::passwordWork($prefix,static function(){throw new DomainException('callback-failure');}); throw new RuntimeException('Password callback failure was swallowed.'); }
    catch (DomainException $expected) { portalCheck($expected->getMessage()==='callback-failure','Password callback failure changed'); }
    portalCheck(Portal::passwordWork($prefix,static fn()=>43)===43,'Failed callback retained password slot');
    $slot=$prefix.'-0.lock';
    clearstatcache(true,$slot);$slotMode=fileperms($slot);
    portalCheck(is_int($slotMode)&&($slotMode&0777)===0640,'New password slot was not created privately');
    $expectUnsafe=static function(string $name)use($prefix):void{
        try { Portal::passwordWork($prefix,static function(){throw new DomainException('Unsafe callback executed');}); throw new DomainException($name.' accepted'); }
        catch (RuntimeException $expected) { portalCheck($expected->getMessage()==='portal_password_storage_unsafe',$name.' did not fail closed'); }
    };
    $linkedSlot=$root.'/linked-slot';
    portalCheck(link($slot,$linkedSlot),'Hard-link fixture could not be created.');
    $expectUnsafe('Hard-linked password slot');
    portalCheck(unlink($linkedSlot),'Hard-link fixture could not be removed.');
    portalCheck(file_put_contents($slot,'unexpected')===10,'Nonempty slot fixture could not be written.');
    $expectUnsafe('Nonempty password slot');
    portalCheck(file_put_contents($slot,'')===0,'Password slot fixture could not be emptied.');
    portalCheck(chmod($slot,0660),'Writable slot fixture permissions could not be set.');
    $expectUnsafe('Writable password slot');
    portalCheck(chmod($slot,0640),'Password slot fixture permissions could not be restored.');
    $savedSlot=$root.'/saved-slot';
    portalCheck(rename($slot,$savedSlot),'Password slot fixture could not be moved.');
    portalCheck(symlink($savedSlot,$slot),'Symlink slot fixture could not be created.');
    $expectUnsafe('Symlink password slot');
    portalCheck(unlink($slot),'Symlink slot fixture could not be removed.');
    portalCheck(rename($savedSlot,$slot),'Password slot fixture could not be restored.');
    portalCheck(chmod($root,0770),'Writable directory fixture permissions could not be set.');
    $expectUnsafe('Writable password directory');
    portalCheck(chmod($root,0700),'Password directory fixture permissions could not be restored.');
    $fifoPrefix=$root.'/fifo-password';
    portalCheck(function_exists('posix_mkfifo')&&posix_mkfifo($fifoPrefix.'-0.lock',0640),'FIFO password slot fixture could not be created.');
    $began=microtime(true);$called=false;
    try{Portal::passwordWork($fifoPrefix,static function()use(&$called):void{$called=true;});throw new DomainException('Existing FIFO password slot was accepted');}
    catch(RuntimeException $expected){portalCheck($expected->getMessage()==='portal_password_storage_unsafe','Existing FIFO did not fail with the storage diagnostic');}
    portalCheck(!$called&&microtime(true)-$began<1,'Existing FIFO reached hashing or blocked the request');
    $path=$root.'/rate.json';
    foreach ([['bad-ip','fixture',10000],['192.0.2.1',str_repeat('x',257),10000],['192.0.2.1','fixture',-1]] as [$badIp,$badUser,$badNow]) {
        try{Portal::loginAttempt($path,$badIp,$badUser,$badNow);throw new DomainException('Invalid rate input accepted');}
        catch(InvalidArgumentException $expected){portalCheck($expected->getMessage()==='portal_login_input_invalid','Invalid rate input used the wrong refusal');}
    }
    for($i=0;$i<10;$i++)portalCheck(Portal::loginAttempt($path,'192.0.2.1','fixture',10000)===0,'Early login attempt throttled');
    portalCheck(Portal::loginAttempt($path,'192.0.2.2','fixture',10001)===299,'Changing IP bypassed account throttle');
    portalCheck(Portal::loginAttempt($path,'192.0.2.1','fixture',10300)===0,'Expired throttle retained');
    for($i=0;$i<49;$i++)portalCheck(Portal::loginAttempt($path,'192.0.2.1','user'.$i,10300)===0,'Shared IP throttled before limit');
    portalCheck(Portal::loginAttempt($path,'192.0.2.1','other',10301)===299,'Changing username bypassed IP throttle');
    $before=file_get_contents($path);portalCheck(is_string($before),'Rate ledger could not be read before the throttled request.');
    portalCheck(Portal::loginAttempt($path,'192.0.2.1','other',10302)>0,'Throttled attempt granted');
    portalCheck(file_get_contents($path)===$before,'Throttled request extended its deadline');
    $ipv6Path=$root.'/ipv6-rate.json';
    for($i=0;$i<10;$i++)portalCheck(Portal::loginAttempt($ipv6Path,'2001:0db8:0000:0000:0000:0000:0000:0001','fixture-v6',10400)===0,'IPv6 fixture throttled early');
    portalCheck(Portal::loginAttempt($ipv6Path,'2001:db8::1','fixture-v6',10401)===299,'Equivalent IPv6 spelling bypassed account throttling');
    $schemaPath=$root.'/schema-rate.json';portalCheck(Portal::loginAttempt($schemaPath,'192.0.2.10','fixture',10500)===0,'Schema fixture admission failed');
    $schemaBytes=file_get_contents($schemaPath);portalCheck(is_string($schemaBytes),'Schema fixture ledger could not be read.');
    $schema=json_decode($schemaBytes,true,16,JSON_THROW_ON_ERROR);$schema[array_key_first($schema)]['unknown']=true;
    $schemaBytes=json_encode($schema,JSON_THROW_ON_ERROR);portalCheck(file_put_contents($schemaPath,$schemaBytes)===strlen($schemaBytes),'Schema corruption fixture write failed');
    try{Portal::loginAttempt($schemaPath,'192.0.2.10','fixture',10501);throw new DomainException('Unknown rate-state field accepted');}
    catch(RuntimeException $expected){portalCheck($expected->getMessage()==='portal_login_storage_invalid','Unknown rate-state field used the wrong refusal');}
    $other=$root.'/other';portalCheck(link($path,$other),'Rate-ledger hard-link fixture could not be created.');
    try{Portal::loginAttempt($path,'192.0.2.9','other',10600);throw new DomainException('Hard link accepted');}
    catch(RuntimeException $expected){portalCheck($expected->getMessage()==='json_storage_unsafe','Hard-linked rate ledger used the wrong refusal: '.$expected->getMessage());}
    portalCheck(unlink($other),'Rate-ledger hard-link fixture could not be removed.');
    portalCheck(file_put_contents($path,'{corrupt')===8,'Corrupt rate-ledger fixture could not be written.');
    try{Portal::loginAttempt($path,'192.0.2.9','other',10600);throw new DomainException('Corrupt ledger accepted');}catch(JsonException $expected){}
    $ssoPath=$root.'/sso-rate.json';
    for($attempt=0;$attempt<50;$attempt++){
        portalCheck(Portal::loginAttempt($ssoPath,'192.0.2.25','',20000)===0,'Accountless provider handshakes consumed the smaller account budget');
    }
    portalCheck(Portal::loginAttempt($ssoPath,'192.0.2.25','',20001)===299,'Provider handshakes bypassed their IP limit');
    $ssoBytes=file_get_contents($ssoPath);portalCheck(is_string($ssoBytes),'SSO rate ledger could not be read.');
    $ssoRows=json_decode($ssoBytes,true,16,JSON_THROW_ON_ERROR);
    portalCheck(count($ssoRows)===2&&!isset($ssoRows['u_'.hash('sha256','')]),'An unknown SSO account created a synthetic shared-user bucket');
    if(!class_exists('FreePBX',false)){
        class PortalBrandConfig{public function get(string $key){return $GLOBALS['portal_brand'][$key]??'';}}
        class FreePBX{public static function Config():PortalBrandConfig{return new PortalBrandConfig();}}
    }
    $GLOBALS['portal_brand']=['BRAND_CSS_CUSTOM'=>'//attacker.example/portal.css','BRAND_IMAGE_TANGO_LEFT'=>'//attacker.example/logo.svg'];
    $externalBrand=Portal::document('content','fixture-nonce',[],$token,'sign-in');
    portalCheck(!str_contains($externalBrand,'attacker.example'),'Protocol-relative branding asset was accepted');
    $GLOBALS['portal_brand']=['BRAND_CSS_CUSTOM'=>'/admin/assets/custom/theme.css','BRAND_IMAGE_TANGO_LEFT'=>'/admin/assets/images/logo.svg'];
    $localBrand=Portal::document('content','fixture-nonce',[],$token,'sign-in');
    portalCheck(str_contains($localBrand,'/admin/assets/custom/theme.css')&&str_contains($localBrand,'/admin/assets/images/logo.svg'),'Same-origin branding asset was rejected');
    $document=Portal::document('<script>window.fixture=true;</script>','fixture-nonce',['username'=>'<owner>','operator_role'=>'administrator'],$token,'access');
    $already=Portal::document('<script nonce="previous-nonce">window.fixture=true;</script>','fixture-nonce',[],$token,'sign-in');
    portalCheck(preg_match('/<script[^>]*>/',$already,$opening)===1,'Document script tag could not be located.');
    portalCheck(isset($opening[0]) && substr_count($opening[0],'nonce=')===1 && str_contains($opening[0],'nonce="fixture-nonce"'),'Document created duplicate CSP nonces.');
    portalCheck(str_contains($document,'nonce="fixture-nonce"') && str_contains($document,'&lt;owner&gt;') && !str_contains($document,'/admin/config.php'),'Portal frame/session display escaped or linked to PBX administration');
} finally {
    if($hadPortalBrand){$GLOBALS['portal_brand']=$previousPortalBrand;}else{unset($GLOBALS['portal_brand']);}
    $entries=scandir($root);
    if(!is_array($entries)){throw new RuntimeException('Portal fixture directory could not be enumerated for cleanup.');}
    foreach($entries as $entry){if($entry!=='.'&&$entry!=='..'){portalCheck(unlink($root.'/'.$entry),'Portal fixture entry could not be removed.');}}
    portalCheck(rmdir($root),'Portal fixture directory could not be removed.');
}
echo "Portal origin/CSRF, password binding, bounded password workers, idle/absolute expiry, durable account/IP limits, protected storage and separate frame passed.\n";
