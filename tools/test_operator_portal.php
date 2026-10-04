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
$root=sys_get_temp_dir().'/sls-portal-unit-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
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
    try { Portal::passwordWork($prefix,static function(){throw new DomainException('callback-failure');}); }
    catch (DomainException $expected) { portalCheck($expected->getMessage()==='callback-failure','Password callback failure changed'); }
    portalCheck(Portal::passwordWork($prefix,static fn()=>43)===43,'Failed callback retained password slot');
    $slot=$prefix.'-0.lock';
    portalCheck((fileperms($slot)&0777)===0640,'New password slot was not created privately');
    $expectUnsafe=static function(string $name)use($prefix):void{
        try { Portal::passwordWork($prefix,static function(){throw new DomainException('Unsafe callback executed');}); throw new DomainException($name.' accepted'); }
        catch (RuntimeException $expected) { portalCheck($expected->getMessage()==='portal_password_storage_unsafe',$name.' did not fail closed'); }
    };
    link($slot,$root.'/linked-slot'); $expectUnsafe('Hard-linked password slot'); unlink($root.'/linked-slot');
    file_put_contents($slot,'unexpected'); $expectUnsafe('Nonempty password slot'); file_put_contents($slot,'');
    chmod($slot,0660); $expectUnsafe('Writable password slot'); chmod($slot,0640);
    rename($slot,$root.'/saved-slot'); symlink($root.'/saved-slot',$slot); $expectUnsafe('Symlink password slot'); unlink($slot); rename($root.'/saved-slot',$slot);
    chmod($root,0770); $expectUnsafe('Writable password directory'); chmod($root,0700);
    $path=$root.'/rate.json';
    for($i=0;$i<10;$i++)portalCheck(Portal::loginAttempt($path,'192.0.2.1','fixture',10000)===0,'Early login attempt throttled');
    portalCheck(Portal::loginAttempt($path,'192.0.2.2','fixture',10001)===299,'Changing IP bypassed account throttle');
    portalCheck(Portal::loginAttempt($path,'192.0.2.1','fixture',10300)===0,'Expired throttle retained');
    for($i=0;$i<49;$i++)portalCheck(Portal::loginAttempt($path,'192.0.2.1','user'.$i,10300)===0,'Shared IP throttled before limit');
    portalCheck(Portal::loginAttempt($path,'192.0.2.1','other',10301)===299,'Changing username bypassed IP throttle');
    $before=file_get_contents($path);portalCheck(Portal::loginAttempt($path,'192.0.2.1','other',10302)>0,'Throttled attempt granted');
    portalCheck(file_get_contents($path)===$before,'Throttled request extended its deadline');
    $other=$root.'/other';link($path,$other);
    try{Portal::loginAttempt($path,'192.0.2.9','other',10600);throw new DomainException('Hard link accepted');}catch(RuntimeException $expected){}
    unlink($other);file_put_contents($path,'{corrupt');
    try{Portal::loginAttempt($path,'192.0.2.9','other',10600);throw new DomainException('Corrupt ledger accepted');}catch(JsonException $expected){}
    $document=Portal::document('<script>window.fixture=true;</script>','fixture-nonce',['username'=>'<owner>','operator_role'=>'administrator'],$token,'access');
    portalCheck(str_contains($document,'nonce="fixture-nonce"') && str_contains($document,'&lt;owner&gt;') && !str_contains($document,'/admin/config.php'),'Portal frame/session display escaped or linked to PBX administration');
} finally { foreach(glob($root.'/*')as$file)unlink($file);rmdir($root); }
echo "Portal origin/CSRF, password binding, bounded password workers, idle/absolute expiry, durable account/IP limits, protected storage and separate frame passed.\n";
