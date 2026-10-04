<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIntegrationsStore.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseOperationsStore.php';
use SLS\MassNotify\{EnterpriseIntegrationsStore,EnterpriseOperationsStore};

function securityCheck(bool $value,string $message): void { if (!$value) { throw new RuntimeException($message); } }
function securityReject(callable $work,string $message): void
{
    try { $work(); } catch (RuntimeException|DomainException $error) { return; }
    throw new RuntimeException($message);
}
function removeSecurityDirectory(string $directory): void
{
    if (!is_dir($directory)||is_link($directory)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());
    }
    rmdir($directory);
}
$base=sys_get_temp_dir().'/sls-enterprise-security-'.bin2hex(random_bytes(8));mkdir($base,0700);
register_shutdown_function(static fn()=>removeSecurityDirectory($base));

$directory=$base.'/integrations';$marker=$base.'/.integrations-required';
$store=new EnterpriseIntegrationsStore($directory);
securityCheck($store->read()['operations']===[]&&!file_exists($marker),'Read-only status initialized replay state.');
securityReject(static fn()=>$store->transaction(static function(array &$state):void { throw new DomainException('Rejected fixture input'); }),'A rejected callback unexpectedly succeeded.');
securityCheck(file_exists($marker)&&$store->read()['operations']===[],'Rejected initial input left a half-initialized journal.');
clearstatcache(true,$marker);securityCheck((fileperms($marker)&0777)===0600,'Parent replay marker is not private.');
$request=str_repeat('a',32);
$store->transaction(static function(array &$state)use($request):void {
    $state['operations'][$request]=['request_id'=>$request,'fingerprint'=>str_repeat('b',64),'kind'=>'door','state'=>'uncertain','created_at'=>time()];
});
securityCheck(isset((new EnterpriseIntegrationsStore($directory))->read()['operations'][$request]),'Uncertain provider claim did not survive reopening.');
$markerBytes=file_get_contents($marker);
unlink($marker);
securityReject(static fn()=>(new EnterpriseIntegrationsStore($directory))->read(),'Existing provider history was accepted without its outside replay fence.');
file_put_contents($marker,$markerBytes);chmod($marker,0600);
removeSecurityDirectory($directory);
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'Whole-directory loss silently recreated provider replay history.');
securityCheck(!file_exists($directory),'Failed recovery recreated the established journal directory.');
unlink($marker);symlink('/etc/passwd',$marker);
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A linked replay marker was accepted.');
unlink($marker);
if (function_exists('posix_mkfifo')) {
    posix_mkfifo($marker,0600);
    securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A FIFO replay marker was opened.');
    unlink($marker);
}
file_put_contents($marker,$markerBytes);chmod($marker,0640);
securityReject(static fn()=>new EnterpriseIntegrationsStore($directory),'A readable-by-group replay marker was accepted.');
unlink($marker);
$operations=$base.'/operations';$operationsMarker=$base.'/.operations-required';
$operationStore=new EnterpriseOperationsStore($operations);
$operationStore->transaction(static function(array &$state):void { $state['clock']=123; });
securityCheck(file_exists($operationsMarker),'Operations did not persist an outside replay fence.');
$operationsMarkerBytes=file_get_contents($operationsMarker);unlink($operationsMarker);
securityReject(static fn()=>(new EnterpriseOperationsStore($operations))->transaction(static fn()=>null),'Operations history was accepted without its outside replay fence.');
file_put_contents($operationsMarker,$operationsMarkerBytes);chmod($operationsMarker,0600);
removeSecurityDirectory($operations);
securityReject(static fn()=>new EnterpriseOperationsStore($operations),'Whole-directory loss silently recreated approval and drill history.');
securityCheck(!file_exists($operations),'Rejected operations recovery recreated its established directory.');
echo "Enterprise security review: read-only initialization, rejected admission, private parent fence, uncertain history, complete-directory loss and unsafe marker rejection passed.\n";
