<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseClusterRuntime.php';

/** Independent LAN edge cache; phones require a local PBX and are excluded. */
final class EnterpriseClusterEdge
{
    private array $config;
    public function __construct(private EnterpriseClusterRuntime $runtime)
    {
        $this->config=$runtime->config();
        if ($this->config['mode']!=='edge' || !$this->config['remote_enabled']) { throw new \DomainException('This node is not an enabled LAN edge.'); }
    }
    public function authenticate(string $id,string $token): array
    {
        if (strlen($token)<32 || strlen($token)>256 || preg_match('/[\x00-\x20]/',$token)) { throw new \DomainException('Invalid edge device credential.'); }
        foreach ($this->config['edge_devices'] as $d) { if ($d['id']===$id && hash_equals($d['token_sha256'],hash('sha256',$token))) { return $d; } }
        throw new \DomainException('Unknown edge device or revoked credential.');
    }
    public function drain(bool $connected=false): array
    {
        $preflight=function(array $job): void {
            $capabilities=[];
            foreach ($this->config['edge_devices'] as $device) { $capabilities[$device['id']]=$device['channels']; }
            foreach ($job['recipients'] as $recipient) {
                if (!isset($capabilities[$recipient])) { throw new \DomainException('A site recipient has no approved local edge device.'); }
                if (!array_intersect($job['channels'],$capabilities[$recipient])) {
                    throw new \DomainException('A site recipient has no device capable of any requested local channel.');
                }
            }
        };
        return $this->runtime->drain(function(array $job): array {
            $intent=$job['intent'];
            EnterpriseClusterRuntime::edgeJobIntent($intent,$job['channels']);
            if (in_array('local_audio',$job['channels'],true)) { $this->asset((string)($intent['audio_asset_id']??'')); }
            $devices=array_column($this->config['edge_devices'],'id'); $capabilities=[];
            foreach ($this->config['edge_devices'] as $device) { $capabilities[$device['id']]=$device['channels']; }
            if (array_diff($job['recipients'],$devices)) { throw new \DomainException('A site recipient has no approved local edge device.'); }
            foreach ($job['recipients'] as $recipient) {
                if (!array_intersect($job['channels'],$capabilities[$recipient]??[])) {
                    throw new \DomainException('A site recipient has no device capable of any requested local channel.');
                }
            }
            $this->runtime->store()->transaction(static function(array &$s) use($job,$intent): void {
                if ($s['control']['stopped'] || time()>=$job['expires_at']) { throw new \DomainException('Edge STOP or expiry blocked publication.'); }
                if (isset($s['cache'][$job['id']])) { throw new \DomainException('Edge event was already published; no replay.'); }
                $s['cache'][$job['id']]=['job_id'=>$job['id'],'delivery_id'=>$job['delivery_id'],'expires_at'=>$job['expires_at'],
                    'created_at'=>$job['created_at'],'recipients'=>$job['recipients'],'channels'=>$job['channels'],'intent'=>$intent,'receipts'=>[]];
            });
            return ['uncertain'=>false,'state'=>'published','job_id'=>$job['id'],'human_acknowledgment'=>false];
        },$connected,$preflight);
    }
    public function poll(array $device): array
    {
        $s=$this->runtime->store()->read();
        $events=[];
        foreach ($s['cache'] as $e) {
            if (time()>=$e['expires_at'] || !in_array($device['id'],$e['recipients'],true) || isset($e['receipts'][$device['id']])) { continue; }
            $channels=array_values(array_intersect($e['channels'],$device['channels'])); if (!$channels) { continue; }
            $events[]=['id'=>$e['job_id'],'delivery_id'=>$e['delivery_id'],'created_at'=>$e['created_at'],'expires_at'=>$e['expires_at'],
                'channels'=>$channels,'message'=>$e['intent']['message'],'title'=>$e['intent']['title']??'Announcement',
                'audio_asset_id'=>in_array('local_audio',$channels,true) ? ($e['intent']['audio_asset_id']??'') : ''];
            if (count($events)>=50) { break; }
        }
        return $events;
    }
    public function receipt(array $device,array $input): array
    {
        if (count($input)!==2 || array_diff(array_keys($input),['id','kind']) || !is_string($input['id'])
            || !in_array($input['kind'],['application_received','displayed','audio_played'],true)) { throw new \DomainException('Invalid local device receipt.'); }
        return $this->runtime->store()->transaction(static function(array &$s) use($device,$input): array {
            $e=$s['cache'][$input['id']]??null;
            $queue=$s['queue'][$input['id']]??null;
            if (!is_array($e) || !is_array($queue) || time()>=$e['expires_at'] || !in_array($device['id'],$e['recipients'],true)) { throw new \DomainException('Receipt event is unavailable, expired, or belongs to another device.'); }
            if ($input['kind']==='audio_played' && (!in_array('local_audio',$e['channels'],true) || !in_array('local_audio',$device['channels'],true))) { throw new \DomainException('This device was not permitted to play local audio.'); }
            if ($input['kind']==='displayed' && !array_intersect($e['channels'],$device['channels'],['desktop','local_display'])) { throw new \DomainException('This device was not permitted to display this event.'); }
            if (!array_intersect($e['channels'],$device['channels'])) { throw new \DomainException('This device has no permitted channel for this event.'); }
            $old=$e['receipts'][$device['id']]??null;
            $stages=$old['stages']??($old?[$old['kind']=>$old]:[]);
            if (isset($stages[$input['kind']])) {
                $s['queue'][$input['id']]['receipt']['device_receipts'][$device['id']]=$old;
                return $stages[$input['kind']];
            }
            $receipt=['device_id'=>$device['id'],'kind'=>$input['kind'],'received_at'=>time(),'human_acknowledgment'=>false];
            $stages[$input['kind']]=$receipt;
            $rank=['application_received'=>0,'displayed'=>1,'audio_played'=>2];
            $summary=$old && $rank[$old['kind']]>$rank[$input['kind']] ? array_diff_key($old,['stages'=>true]) : $receipt;
            $summary['stages']=$stages;
            $s['cache'][$input['id']]['receipts'][$device['id']]=$summary;
            $s['queue'][$input['id']]['receipt']['device_receipts'][$device['id']]=$summary;
            return $receipt;
        });
    }
    private function asset(string $id): array
    {
        foreach ($this->config['edge_assets'] as $a) {
            if ($a['id']!==$id) { continue; }
            $path=$a['path']; $parent=dirname($path);
            while ($parent!=='/') {
                clearstatcache(true,$parent); $directory=@lstat($parent);
                if (!$directory || ($directory['mode']&0170000)!==0040000 || $directory['uid']!==0 || ($directory['mode']&0022)
                    || realpath($parent)!==$parent) { throw new \RuntimeException('Approved edge audio parent path is not root-owned and immutable.'); }
                $parent=dirname($parent);
            }
            clearstatcache(true,$path); $before=@lstat($path);
            if (!$before || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1 || $before['uid']!==0
                || ($before['mode']&0022) || realpath($path)!==$path || $before['size']<44 || $before['size']>2097152) {
                throw new \RuntimeException('Approved edge audio is missing or unsafe.');
            }
            $handle=@fopen($path,'rb'); if (!$handle) { throw new \RuntimeException('Approved edge audio cannot be opened.'); }
            try {
                $opened=fstat($handle);
                if ($opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino'] || $opened['mode']!==$before['mode']
                    || $opened['uid']!==$before['uid'] || $opened['nlink']!==1) { throw new \RuntimeException('Approved edge audio changed while opening.'); }
                $bytes=stream_get_contents($handle,2097153); $after=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
                foreach (['dev','ino','mode','uid','nlink','size','mtime','ctime'] as $field) {
                    if (!$named || $named[$field]!==$before[$field] || $after[$field]!==$before[$field]) { throw new \RuntimeException('Approved edge audio changed while reading.'); }
                }
            } finally { fclose($handle); }
            if (!is_string($bytes) || strlen($bytes)!==$before['size'] || !hash_equals($a['sha256'],hash('sha256',$bytes))
                || substr($bytes,0,4)!=='RIFF' || substr($bytes,8,4)!=='WAVE') { throw new \RuntimeException('Approved cached edge audio changed or is not WAV.'); }
            return ['bytes'=>$bytes,'sha256'=>$a['sha256']];
        }
        throw new \DomainException('Local audio asset is not explicitly approved.');
    }
    public function media(array $device,string $eventId,string $assetId): array
    {
        EnterpriseClusterConfig::id($eventId); EnterpriseClusterConfig::id($assetId);
        $state=$this->runtime->store()->read(); $event=$state['cache'][$eventId]??null;
        if (!is_array($event) || !is_array($event['receipts']??null)) { throw new \DomainException('Local audio is expired or not authorized for this device.'); }
        // Receiving/displaying an event does not complete its audio. Authorize
        // this exact cached event independently of the paginated polling list.
        $receipt=$event['receipts'][$device['id']]??null;
        $played=isset($receipt['stages']['audio_played']) || ($receipt['kind']??'')==='audio_played';
        if ($event && time()<$event['expires_at'] && !$played
            && in_array($device['id'],$event['recipients'],true)
            && in_array('local_audio',$event['channels'],true) && in_array('local_audio',$device['channels'],true)
            && ($event['intent']['audio_asset_id']??'')===$assetId) {
            return $this->asset($assetId);
        }
        throw new \DomainException('Local audio is expired or not authorized for this device.');
    }
}
