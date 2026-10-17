<?php
declare(strict_types=1);
namespace SLS\MassNotify {
    // Replace only a fixture's named lock after the real lease succeeds.
    function flock($handle,int $operation,?int &$wouldBlock=null): bool
    {
        $accepted=\flock($handle,$operation,$wouldBlock);
        if($accepted&&($operation&LOCK_EX)&&!empty($GLOBALS['fixture_lock_replacement'])){
            $path=$GLOBALS['fixture_lock_replacement'];unset($GLOBALS['fixture_lock_replacement']);
            if(!rename($path,$path.'.retained')||file_put_contents($path,'',LOCK_EX)!==0||!chmod($path,0600)){
                throw new \RuntimeException('Unable to replace the fixture lock safely.');
            }
        }
        return $accepted;
    }
}
namespace {
    require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentityStore.php';
    if(getenv('SLS_TEST_NAMESPACE')!=='entered'){throw new RuntimeException('Use the private isolated fixture runner.');}
    $root=sys_get_temp_dir().'/sls-identity-lease-'.bin2hex(random_bytes(8));
    if(!mkdir($root,0700)){throw new RuntimeException('Unable to create the private identity lease fixture.');}
    $checks=0;
    $check=static function(bool $value,string $message)use(&$checks):void{++$checks;if(!$value){throw new RuntimeException($message);}};
    try{
        $store=new \SLS\MassNotify\EnterpriseIdentityStore($root.'/state');
        $path=$root.'/state/lease.lock';$called=false;$GLOBALS['fixture_lock_replacement']=$path;
        try{
            $store->transaction('lease',static function(array &$rows)use(&$called):void{$called=true;$rows['unexpected']=true;});
            throw new RuntimeException('A replaced fixture lock authorized work.');
        }catch(RuntimeException $error){$check(str_contains($error->getMessage(),'lock or directory changed'),'Lease replacement did not produce the expected refusal.');}
        $check(!$called&&!file_exists($root.'/state/lease.json'),'A detached lock reached initialization or its callback.');
        $store->transaction('commit',static function(array &$rows):void{$rows['prior']='preserve';});
        $before=file_get_contents($root.'/state/commit.json');$check(is_string($before),'Unable to read prior identity history.');
        try{
            $store->transaction('commit',static function(array &$rows)use($root):void{
                $rows['unexpected']=true;$lock=$root.'/state/commit.lock';
                if(!rename($lock,$lock.'.retained')||file_put_contents($lock,'',LOCK_EX)!==0||!chmod($lock,0600)){throw new RuntimeException('Unable to replace the callback lock.');}
            });
            throw new RuntimeException('A changed callback lease authorized commit.');
        }catch(RuntimeException $error){$check(str_contains($error->getMessage(),'lock or directory changed'),'Callback lease change did not produce the expected refusal.');}
        $check(file_get_contents($root.'/state/commit.json')===$before,'Rejected commit changed prior history.');
        $store->transaction('normal',static function(array &$rows):void{$rows['prior']='preserve';});
        $normalStat=lstat($root.'/state/normal.lock');$check(is_array($normalStat),'Unable to inspect the normal identity lock.');$inode=$normalStat['ino'];
        $store->transaction('normal',static function(array &$rows):void{$rows['new']='accepted';});
        $check($store->read('normal')===['prior'=>'preserve','new'=>'accepted'],'Normal serialized ledger updates failed.');
        $normalAfter=lstat($root.'/state/normal.lock');$check(is_array($normalAfter)&&$normalAfter['ino']===$inode,'Normal ledger updates replaced their lock.');
        echo $checks." private enterprise ledger acquisition, callback, preserved-history and normal-update checks passed.\n";
    }finally{
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
    }
}
