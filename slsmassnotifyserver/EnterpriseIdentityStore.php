<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** A bounded, locked operational ledger; configuration stays in encrypted .config. */
final class EnterpriseIdentityStore
{
    private string $directory;
    public function __construct(string $directory)
    {
        $this->directory=rtrim($directory,'/');
        $parent=@lstat(dirname($this->directory));
        if (!$parent || ($parent['mode']&0022) || realpath(dirname($this->directory))!==dirname($this->directory)) { throw new \RuntimeException('Enterprise state parent is unsafe.'); }
        $marker=dirname($this->directory).'/.'.basename($this->directory).'-required';clearstatcache(true,$marker);$required=@lstat($marker);
        if($required){$h=$this->open($marker,'r+b');try{$valid=stream_get_contents($h,32)==="SLS_ENTERPRISE_STATE_V1\n";}finally{fclose($h);}if(!$valid||!is_dir($this->directory)||is_link($this->directory)){throw new \RuntimeException('Established enterprise state is missing or damaged. Restore it; do not recreate replay history.');}}
        elseif(is_dir($this->directory)&&count(scandir($this->directory))>2){throw new \RuntimeException('Enterprise state initialization marker is missing. Preserve the existing ledger for recovery.');}
        if (!file_exists($this->directory) && !is_link($this->directory)) { $mask=umask(0077);try { if (!mkdir($this->directory,0700)) { throw new \RuntimeException('Enterprise state cannot be initialized.'); } }finally {umask($mask);} }
        $this->safeDirectory();
        if(!$required){$h=$this->open($marker,'x+b');try{$bytes="SLS_ENTERPRISE_STATE_V1\n";if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h)){throw new \RuntimeException('Enterprise initialization marker failed.');}}finally{fclose($h);}$this->syncDirectory(dirname($this->directory));}
    }
    private function safeDirectory(): void
    {
        clearstatcache(true,$this->directory);$m=@lstat($this->directory);
        if (!$m || ($m['mode']&0170000)!==0040000 || ($m['mode']&0077) || $m['uid']!==posix_geteuid() || realpath($this->directory)!==$this->directory) { throw new \RuntimeException('Enterprise state must be owned by its runtime account, mode 0700, without links.'); }
    }
    private function syncDirectory(string $path): void
    { $h=fopen($path,'r');try{if(!$h||!fsync($h)){throw new \RuntimeException('Enterprise directory durability unavailable.');}}finally{if($h){fclose($h);}} }
    private function regular(array $m): bool {return ($m['mode']&0170000)===0100000&&!($m['mode']&0077)&&$m['uid']===posix_geteuid()&&$m['nlink']===1;}
    private function open(string $path,string $mode)
    {
        $parent=dirname($path);clearstatcache(true,$parent);$parentBefore=@lstat($parent);
        if(!$parentBefore||($parentBefore['mode']&0170000)!==0040000||($parentBefore['mode']&0022)||realpath($parent)!==$parent){throw new \RuntimeException('Enterprise state parent changed.');}
        clearstatcache(true,$path);$before=@lstat($path);if($before&&(!$this->regular($before)||is_link($path))){throw new \RuntimeException('Enterprise state contains an unsafe file.');}$mask=umask(0077);try{$h=@fopen($path,$mode);}finally{umask($mask);}
        if (!$h) { throw new \RuntimeException('Enterprise state unavailable.'); }$m=fstat($h);clearstatcache(true,$path);$at=@lstat($path);clearstatcache(true,$parent);$parentAfter=@lstat($parent);
        if(!$parentAfter||$parentBefore['dev']!==$parentAfter['dev']||$parentBefore['ino']!==$parentAfter['ino']){fclose($h);throw new \RuntimeException('Enterprise state directory replaced.');}
        if (!$m || !$at || ($m['mode']&0170000)!==0100000 || ($m['mode']&0077) || $m['uid']!==posix_geteuid() || $m['nlink']!==1
            || is_link($path) || $m['ino']!==$at['ino'] || $m['dev']!==$at['dev'] || ($before && ($m['ino']!==$before['ino'] || $m['dev']!==$before['dev']))) { fclose($h);throw new \RuntimeException('Enterprise state contains an unsafe file.'); }return $h;
    }
    public function transaction(string $name,callable $work)
    {
        if (!preg_match('/^[a-z_-]{1,32}$/D',$name)) { throw new \LogicException('Invalid ledger name.'); }$this->safeDirectory();
        $lock=$this->open($this->directory.'/'.$name.'.lock','c+b');$deadline=microtime(true)+2;
        try {
            while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline){throw new \RuntimeException('Enterprise state busy.');}usleep(10000);}
            $path=$this->directory.'/'.$name.'.json';$marker=$this->directory.'/'.$name.'.required';$rows=[];$required=file_exists($marker)||is_link($marker);$present=file_exists($path)||is_link($path);
            if($required){$h=$this->open($marker,'r+b');try{$valid=stream_get_contents($h,40)==="SLS_ENTERPRISE_LEDGER_V1\n";}finally{fclose($h);}if(!$valid||!$present){throw new \RuntimeException('Established enterprise ledger missing or damaged.');}}elseif($present){throw new \RuntimeException('Enterprise ledger initialization incomplete. Preserve its history.');}
            if (file_exists($path)||is_link($path)) { $h=$this->open($path,'r+b');try{if(fstat($h)['size']>4194304){throw new \RuntimeException('Enterprise state allocation exceeded.');}$raw=stream_get_contents($h,4194305);$rows=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(json_encode((object)$rows,JSON_THROW_ON_ERROR)!==$raw){throw new \RuntimeException('Enterprise ledger is noncanonical or has repeated keys.');}}finally{fclose($h);} }
            if (!is_array($rows) || count($rows)>20000 || ($rows && array_is_list($rows))) { throw new \RuntimeException('Enterprise state damaged.'); }
            $result=$work($rows);$bytes=json_encode((object)$rows,JSON_THROW_ON_ERROR);if(strlen($bytes)>4194304){throw new \RuntimeException('Enterprise state allocation exceeded.');}
            $temp=$path.'.'.bin2hex(random_bytes(12));$h=$this->open($temp,'x+b');
            try{if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h)){throw new \RuntimeException('Enterprise state write failed.');}}finally{fclose($h);}
            try{if(!rename($temp,$path)){throw new \RuntimeException('Enterprise state replace failed.');}$this->syncDirectory($this->directory);if(!$required){$h=$this->open($marker,'x+b');try{$bytes="SLS_ENTERPRISE_LEDGER_V1\n";if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h)){throw new \RuntimeException('Enterprise ledger marker failed.');}}finally{fclose($h);}$this->syncDirectory($this->directory);}}finally{if(file_exists($temp)){unlink($temp);}}
            return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function reserve(array $record,string $browser,int $now): string
    {
        return $this->transaction('login',static function(array &$rows)use($record,$browser,$now):string{foreach($rows as $k=>$r){if(($r['expires_at']??0)<=$now){unset($rows[$k]);}}if(count($rows)>=1000){throw new \DomainException('Enterprise login capacity reached.');}$state=bin2hex(random_bytes(32));$rows[hash('sha256',$state)]=$record+['browser'=>hash('sha256',$browser),'created_at'=>$now,'expires_at'=>$now+300];return $state;});
    }
    public function take(string $state,string $browser,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$state)||!preg_match('/^[a-f0-9]{64}$/D',$browser)){throw new \DomainException('Enterprise login transaction invalid.');}
        return $this->transaction('login',static function(array &$rows)use($state,$browser,$now):array{$key=hash('sha256',$state);$r=$rows[$key]??null;unset($rows[$key]);if(!$r||$now<$r['created_at']||$now>=$r['expires_at']||!hash_equals($r['browser'],hash('sha256',$browser))){return ['invalid'=>true];}return $r;});
    }
    public function replay(string $key,int $expires,int $now): void
    {
        $ok=$this->transaction('replay',static function(array &$rows)use($key,$expires,$now):bool{foreach($rows as $k=>$e){if(!is_int($e)){throw new \RuntimeException('Replay ledger damaged.');}if($e<=$now){unset($rows[$k]);}}$k=hash('sha256',$key);if(isset($rows[$k])){return false;}if(count($rows)>=10000){throw new \RuntimeException('Replay ledger capacity reached.');}$rows[$k]=$expires;return true;});if(!$ok){throw new \DomainException('This authentication assertion was already used.');}
    }
}
