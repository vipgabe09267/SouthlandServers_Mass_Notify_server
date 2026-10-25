<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/security.php';

/** Optional read acceleration. Current authentication and original enrollment remain mandatory. */
if (!class_exists(DesktopFeedCache::class, false)) {
final class DesktopFeedCache
{
    public static function read(string $journal,array $source,array $settings,array $client,int $days,callable $timestamp): ?array
    {
        $handle=null;
        try{
            $key=base64_decode((string)($settings['desktop_auth_key']??''),true);
            if(!is_string($key)||strlen($key)!==32||!is_string($client['username']??null)||!is_string($client['client_id']??null)){return null;}
            $path=dirname($journal).'/.desktop-feed.cache';$directory=lstat(dirname($journal));clearstatcache(true,$path);$before=@lstat($path);
            $valid=static fn($meta):bool=>is_array($meta)&&($meta['mode']&0170000)===0100000&&$meta['nlink']===1&&!($meta['mode']&0137)&&in_array($meta['uid'],[0,posix_geteuid()],true)&&$meta['size']>0&&$meta['size']<=67112960;
            $identity=static fn(array $meta):array=>array_intersect_key($meta,array_flip(['dev','ino','size','mode','uid','gid','mtime','ctime','nlink']));
            if(!$directory||($directory['mode']&0170000)!==0040000||($directory['mode']&0022)||!in_array($directory['uid'],[0,posix_geteuid()],true)||!$valid($before)||realpath($path)!==$path){return null;}
            $memory=trim((string)ini_get('memory_limit'));$budget=-1;
            if(preg_match('/^([0-9]+)([KMG]?)$/i',$memory,$match)){$budget=(int)$match[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($match[2])]));}
            if($budget>0&&memory_get_usage(true)+max(4194304,$before['size']*16)>$budget){return null;}
            $handle=@fopen($path,'r+b');if($handle===false){$handle=null;return null;}
            if(!$valid(fstat($handle))||$identity($before)!==$identity(fstat($handle))||!flock($handle,LOCK_SH|LOCK_NB)){return null;}
            $header=fgets($handle,4097);if(!is_string($header)||strlen($header)>4096||!str_ends_with($header,"\n")){return null;}
            $meta=ApiSecurity::jsonObject($header);$expected=[$source['dev'],$source['ino'],$source['size'],$source['mtime'],$source['ctime']];
            if(array_diff(array_keys($meta),['schema','journal','bytes','hmac'])||($meta['schema']??null)!==1||($meta['journal']??null)!==$expected
                ||!is_int($meta['bytes']??null)||$meta['bytes']<1||$meta['bytes']>67108864||!is_string($meta['hmac']??null)||!preg_match('/^[a-f0-9]{64}$/D',$meta['hmac'])){return null;}
            $raw=stream_get_contents($handle,$meta['bytes']+1);clearstatcache(true,$path);$after=@lstat($path);$final=fstat($handle);
            if(!is_string($raw)||strlen($raw)!==$meta['bytes']||!$valid($after)||!$valid($final)||$identity($before)!==$identity($after)||$identity($after)!==$identity($final)
                ||!hash_equals(hash_hmac('sha256',"sls-desktop-feed-v1\n".json_encode($expected,JSON_THROW_ON_ERROR)."\n".$raw,$key),$meta['hmac'])){return null;}
            clearstatcache(true,$journal);$current=@lstat($journal);
            if(!$current||array_intersect_key($source,array_flip(['dev','ino','size','mtime','ctime','mode','uid','gid','nlink']))!==$identity($current)){return null;}
            $cache=ApiSecurity::jsonObject($raw);$sets=$cache['sets']??null;$events=$cache['events']??null;
            if(array_diff(array_keys($cache),['sets','events'])||!is_array($sets)||count($sets)>1000||!is_array($events)||!array_is_list($events)||count($events)>1000){return null;}
            $selected=[];$now=time();$username=$client['username'];$clientId=$client['client_id'];
            foreach($events as $row){
                if(!is_array($row)||array_diff(array_keys($row),['set','event'])||!is_string($row['set']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['set'])||!is_array($row['event']??null)||!is_array($sets[$row['set']]??null)){return null;}
                $bindings=$sets[$row['set']];if(count($bindings)>1000){return null;}
                if(!is_string($bindings[$username]??null)||!hash_equals($clientId,$bindings[$username])){continue;}
                $event=$row['event'];
                if(array_intersect(array_keys($event),['desktop_all','desktop_recipients','desktop_client_ids'])){return null;}
                if(isset($event['created_at'])){
                    if(!is_string($event['created_at'])||($created=$timestamp($event['created_at']))===null){return null;}
                    if($created<$now-$days*86400){continue;}
                }
                $event['desktop_all']=false;$event['desktop_recipients']=[$username];$event['desktop_client_ids']=[$username=>$clientId];$selected[]=$event;
            }
            return $selected;
        }catch(\Throwable $error){return null;}
        finally{if(is_resource($handle)){fclose($handle);}}
    }
}

}
