<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Failed-password windows survive cookies, restarts and parallel workers. */
final class OperatorLoginRate
{
    private const MAX_BYTES=524288;

    private static function safe(string $path, array $meta, bool $lock=false): void
    {
        if (($meta['mode']&0170000)!==0100000 || ($meta['mode']&0027) || $meta['uid']!==posix_geteuid()
            || $meta['nlink']!==1 || $meta['size']>($lock?0:self::MAX_BYTES) || is_link($path)) {
            throw new \RuntimeException('operator_failure_storage_unsafe');
        }
    }

    private static function update(string $path, callable $work)
    {
        $parent=dirname($path);clearstatcache(true,$parent);$meta=@lstat($parent);
        if (!$meta || realpath($parent)!==$parent || ($meta['mode']&0170000)!==0040000 || ($meta['mode']&0022)
            || $meta['uid']!==posix_geteuid()) { throw new \RuntimeException('operator_failure_storage_unsafe'); }
        $lockPath=$path.'.lock';clearstatcache(true,$lockPath);$before=@lstat($lockPath);$mask=umask(0037);
        if ($before) { self::safe($lockPath,$before,true); }
        try {
            $lock=@fopen($lockPath,$before?'r+b':'x+b');
            if (!$lock && !$before) { clearstatcache(true,$lockPath);$before=@lstat($lockPath);if($before){self::safe($lockPath,$before,true);$lock=@fopen($lockPath,'r+b');} }
        } finally { umask($mask); }
        if (!$lock) { throw new \RuntimeException('operator_failure_storage_unavailable'); }
        $temporary=null;$output=null;
        try {
            $opened=fstat($lock);clearstatcache(true,$lockPath);$named=@lstat($lockPath);
            if (!$named || !$opened || $opened['dev']!==$named['dev'] || $opened['ino']!==$named['ino']) { throw new \RuntimeException('operator_failure_storage_changed'); }
            self::safe($lockPath,$opened,true);
            $deadline=microtime(true)+2;
            while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline){throw new \RuntimeException('operator_failure_storage_busy');}usleep(10000);}
            clearstatcache(true,$path);$old=@lstat($path);
            $state=['schema'=>1,'last_time'=>0,'ips'=>[]];
            if ($old) {
                self::safe($path,$old);$input=@fopen($path,'rb');
                if (!$input) { throw new \RuntimeException('operator_failure_storage_unavailable'); }
                try {
                    $opened=fstat($input);if($opened['ino']!==$old['ino']||$opened['dev']!==$old['dev']){throw new \RuntimeException('operator_failure_storage_changed');}
                    $raw=stream_get_contents($input,self::MAX_BYTES+1);
                } finally { fclose($input); }
                if(strlen($raw)>self::MAX_BYTES){throw new \RuntimeException('operator_failure_storage_invalid');}
                $state=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            }
            if (!is_array($state) || array_diff(array_keys($state),['schema','last_time','ips']) || ($state['schema']??null)!==1
                || !is_int($state['last_time']??null) || !is_array($state['ips']??null) || count($state['ips'])>512) {
                throw new \RuntimeException('operator_failure_storage_invalid');
            }
            $result=$work($state);
            $bytes=json_encode($state,JSON_THROW_ON_ERROR);if(strlen($bytes)>self::MAX_BYTES){throw new \RuntimeException('operator_failure_storage_full');}
            $temporary=$parent.'/.operator-failures-'.bin2hex(random_bytes(16));$mask=umask(0037);
            try{$output=@fopen($temporary,'x+b');}finally{umask($mask);}
            if(!$output || fwrite($output,$bytes)!==strlen($bytes)||!fflush($output)||!fsync($output)){throw new \RuntimeException('operator_failure_storage_write_failed');}
            fclose($output);$output=null;clearstatcache(true,$path);$current=@lstat($path);
            if(($old===false)!==($current===false)||($old&&($old['dev']!==$current['dev']||$old['ino']!==$current['ino']))){throw new \RuntimeException('operator_failure_storage_changed');}
            if(!rename($temporary,$path)){throw new \RuntimeException('operator_failure_storage_write_failed');}$temporary=null;
            $directory=@fopen($parent,'r');if(!$directory){throw new \RuntimeException('operator_failure_storage_write_failed');}
            try{if(!fsync($directory)){throw new \RuntimeException('operator_failure_storage_write_failed');}}finally{fclose($directory);}
            return $result;
        } finally {if(is_resource($output)){fclose($output);}if($temporary!==null){@unlink($temporary);}fclose($lock);}
    }

    private static function prepare(array &$state, int $now): int
    {
        if($now<0 || $now+30<$state['last_time']){throw new \RuntimeException('operator_failure_clock_reversed');}
        $now=max($now,$state['last_time']);$state['last_time']=$now;
        foreach($state['ips'] as $key=>&$row){
            if(!preg_match('/^[a-f0-9]{64}$/D',(string)$key)||!is_array($row)||array_diff(array_keys($row),['failures','pending','locked_until'])
                ||!is_array($row['failures']??null)||!array_is_list($row['failures'])||count($row['failures'])>20
                ||!is_array($row['pending']??null)||count($row['pending'])>6||!is_int($row['locked_until']??null)
                ||$row['locked_until']<0||$row['locked_until']>$now+86400){throw new \RuntimeException('operator_failure_storage_invalid');}
            foreach($row['failures'] as $at){if(!is_int($at)||$at<0||$at>$now){throw new \RuntimeException('operator_failure_storage_invalid');}}
            $row['failures']=array_values(array_filter($row['failures'],static fn($at)=>$at>$now-86400));
            foreach($row['pending'] as $id=>$at){if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!is_int($at)||$at>$now||$at<0){throw new \RuntimeException('operator_failure_storage_invalid');}if($at<=$now-60){unset($row['pending'][$id]);}}
            if(!$row['failures']&&!$row['pending']&&$row['locked_until']<=$now){unset($state['ips'][$key]);}
        }unset($row);return $now;
    }

    private static function key(string $ip): string
    {
        $packed=@inet_pton($ip);if($packed===false){throw new \RuntimeException('operator_failure_ip_invalid');}
        return hash('sha256',$packed);
    }

    public static function begin(string $path, string $ip, int $now): array
    {
        $key=self::key($ip);
        return self::update($path,static function(array &$state)use($key,$now):array{
            $now=self::prepare($state,$now);$row=$state['ips'][$key]??['failures'=>[],'pending'=>[],'locked_until'=>0];
            $retry=max(0,$row['locked_until']-$now);
            foreach([[300,6],[600,12],[86400,20]] as [$window,$limit]){
                $failures=array_values(array_filter($row['failures'],static fn($at)=>$at>$now-$window));
                if(count($failures)+count($row['pending'])>=$limit){
                    $until=$failures?min($failures)+$window:$now+1;
                    if($row['pending']){$until=min($until,min($row['pending'])+60);}
                    $retry=max($retry,max(1,$until-$now));
                }
            }
            if($retry){return ['retry_after'=>$retry,'reservation'=>''];}
            if(!isset($state['ips'][$key])&&count($state['ips'])>=512){throw new \RuntimeException('operator_failure_storage_full');}
            $id=bin2hex(random_bytes(16));$row['pending'][$id]=$now;$state['ips'][$key]=$row;
            return ['retry_after'=>0,'reservation'=>$id];
        });
    }

    public static function finish(string $path, string $ip, string $id, bool $failed, int $now): void
    {
        $key=self::key($ip);
        self::update($path,static function(array &$state)use($key,$id,$failed,$now):void{
            $now=self::prepare($state,$now);$row=$state['ips'][$key]??null;
            if(!$row||!isset($row['pending'][$id])){throw new \RuntimeException('operator_failure_reservation_expired');}
            unset($row['pending'][$id]);if($failed){$row['failures'][]=$now;if(count($row['failures'])>=20){$row['locked_until']=$now+86400;}}
            $state['ips'][$key]=$row;
        });
    }

    /** Called only after a reset credential and its existing TOTP were consumed. */
    public static function recovered(string $path, string $ip, int $now): void
    {
        $key=self::key($ip);self::update($path,static function(array &$state)use($key,$now):void{
            self::prepare($state,$now);
            if(isset($state['ips'][$key])){$state['ips'][$key]['failures']=[];$state['ips'][$key]['locked_until']=0;}
        });
    }
}
