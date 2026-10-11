<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/ControlIdempotency.php';
use SLS\MassNotify\ControlIdempotency;
$base=sys_get_temp_dir().'/sls-idempotency-'.bin2hex(random_bytes(8));mkdir($base,0700);
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $work):void{try{$work();}catch(Throwable $expected){return;}throw new RuntimeException('Expected rejection.');};
try {
    $ledger=new ControlIdempotency($base.'/ledger');$principal=['id'=>'fixture','audience'=>['unrestricted'=>true],'scopes'=>['send']];$body=['message'=>'Fixture','targets'=>['1000']];$calls=0;
    $send=static function()use(&$calls):array{$calls++;return ['success'=>true,'job_id'=>'job_'.str_repeat('a',32),'recipients'=>['1000']];};
    $first=$ledger->submit(str_repeat('a',32),$principal,$body,$send,1000);
    $check($ledger->submit(str_repeat('a',32),$principal,array_reverse($body,true),$send,1001)===$first&&$calls===1,'Identical retry was submitted twice.');
    $reject(fn()=> $ledger->submit(str_repeat('a',32),$principal,['message'=>'Changed'],$send,1001));
    $reject(fn()=> $ledger->submit(str_repeat('a',32),array_replace($principal,['audience'=>['unrestricted'=>false]]),$body,$send,1001));
    $reject(fn()=> $ledger->submit('short',$principal,$body,$send,1001));
    $reject(fn()=> $ledger->submit(str_repeat('b',32),$principal,$body,static function(){throw new RuntimeException('Interrupted after submission.');},1001));
    $uncertain=$ledger->submit(str_repeat('b',32),$principal,$body,$send,1002);
    $check($uncertain['error_code']==='announcement_submission_uncertain'&&$calls===1,'Uncertain claim was replayed.');
    $ledger->submit(str_repeat('a',32),array_replace($principal,['id'=>'another']),$body,$send,1002);
    $check($calls===2,'Credential identities were conflated.');
    $ledger->submit(str_repeat('a',32),$principal,$body,$send,1000+ControlIdempotency::RETENTION_SECONDS);
    $check($calls===3,'Published retention boundary was not enforced.');
    unlink($base.'/ledger/announcement_claims.json');$reject(fn()=> $ledger->submit(str_repeat('c',32),$principal,$body,$send,1003));
    echo "Control announcement idempotency: stable retries, conflicts, credential isolation, uncertainty, retention and missing-ledger fencing passed.\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($base);
}
