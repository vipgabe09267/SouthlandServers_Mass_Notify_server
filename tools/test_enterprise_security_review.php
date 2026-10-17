<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIntegrationsStore.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseOperationsStore.php';
use SLS\MassNotify\{EnterpriseIntegrationsStore,EnterpriseOperationsStore};

function securityCheck(bool $value,string $message): void { if (!$value) { throw new RuntimeException($message); } }
function securityReject(callable $work,string $message): void
{
    try { $work(); } catch (RuntimeException|DomainException $error) { securityCheck($error->getMessage()!=='',$message);return; }
    throw new RuntimeException($message);
}
function removeSecurityDirectory(string $directory,bool $strict=false): void
{
    if (!file_exists($directory)&&!is_link($directory)) { return; }
    if (!is_dir($directory)||is_link($directory)) { if($strict){throw new RuntimeException('Unsafe enterprise-security cleanup root.');}return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $removed=$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
        if(!$removed&&$strict){throw new RuntimeException('Unable to remove an enterprise-security fixture entry.');}
    }
    if(!rmdir($directory)&&$strict){throw new RuntimeException('Unable to remove the enterprise-security fixture root.');}
}
function securityWrite(string $path,string $bytes,int $mode=0600): void
{
    if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($path,$mode)){throw new RuntimeException('Unable to write a private enterprise-security fixture.');}
}
function securityRemove(string $path): void { if(!unlink($path)){throw new RuntimeException('Unable to remove an enterprise-security fixture path.');} }
$base=sys_get_temp_dir().'/sls-enterprise-security-'.bin2hex(random_bytes(8));
if(!mkdir($base,0700)||!chmod($base,0700)){throw new RuntimeException('Unable to create the private enterprise-security fixture.');}
register_shutdown_function(static fn()=>removeSecurityDirectory($base,false));

$directory=$base.'/integrations';$marker=$base.'/.integrations-required';
$store=new EnterpriseIntegrationsStore($directory);
securityCheck($store->read()['operations']===[]&&!file_exists($marker),'Read-only status initialized replay state.');
securityReject(static fn()=>$store->transaction(static function(array &$state):void { throw new DomainException('Rejected fixture input'); }),'A rejected callback unexpectedly succeeded.');
securityCheck(file_exists($marker)&&$store->read()['operations']===[],'Rejected initial input left a half-initialized journal.');
clearstatcache(true,$marker);$markerMode=fileperms($marker);securityCheck(is_int($markerMode)&&($markerMode&0777)===0600,'Parent replay marker is not private.');
$request=str_repeat('a',32);
$store->transaction(static function(array &$state)use($request):void {
    $created=time();
    $state['operations'][$request]=['request_id'=>$request,'fingerprint'=>str_repeat('b',64),'kind'=>'door','state'=>'uncertain','created_at'=>$created,
        'detail'=>'Fixture uncertainty retained for replay protection.','finished_at'=>$created];
});
securityCheck(isset((new EnterpriseIntegrationsStore($directory))->read()['operations'][$request]),'Uncertain provider claim did not survive reopening.');
$markerBytes=file_get_contents($marker);securityCheck(is_string($markerBytes),'Unable to read the integrations replay marker.');
securityRemove($marker);
securityReject(static fn()=>(new EnterpriseIntegrationsStore($directory))->read(),'Existing provider history was accepted without its outside replay fence.');
securityWrite($marker,$markerBytes);
removeSecurityDirectory($directory,true);
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'Whole-directory loss silently recreated provider replay history.');
securityCheck(!file_exists($directory),'Failed recovery recreated the established journal directory.');
securityRemove($marker);securityCheck(symlink('/etc/passwd',$marker),'Unable to create the linked replay-marker fixture.');
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A linked replay marker was accepted.');
securityRemove($marker);
if (function_exists('posix_mkfifo')) {
    securityCheck(posix_mkfifo($marker,0600),'Unable to create the FIFO replay-marker fixture.');
    securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A FIFO replay marker was opened.');
    securityRemove($marker);
}
securityWrite($marker,$markerBytes,0640);
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A readable-by-group replay marker was accepted.');
securityRemove($marker);
$operations=$base.'/operations';$operationsMarker=$base.'/.operations-required';
$operationStore=new EnterpriseOperationsStore($operations);
$operationStore->transaction(static function(array &$state):void { $state['clock']=123; });
securityCheck(file_exists($operationsMarker),'Operations did not persist an outside replay fence.');
$operationsMarkerBytes=file_get_contents($operationsMarker);securityCheck(is_string($operationsMarkerBytes),'Unable to read the operations replay marker.');securityRemove($operationsMarker);
securityReject(static fn()=>(new EnterpriseOperationsStore($operations))->transaction(static fn()=>null),'Operations history was accepted without its outside replay fence.');
securityWrite($operationsMarker,$operationsMarkerBytes);
removeSecurityDirectory($operations,true);
securityReject(static fn()=>new EnterpriseOperationsStore($operations),'Whole-directory loss silently recreated approval and drill history.');
securityCheck(!file_exists($operations),'Rejected operations recovery recreated its established directory.');
removeSecurityDirectory($base,true);
echo "Enterprise security review: read-only initialization, rejected admission, private parent fence, uncertain history, complete-directory loss and unsafe marker rejection passed.\n";
