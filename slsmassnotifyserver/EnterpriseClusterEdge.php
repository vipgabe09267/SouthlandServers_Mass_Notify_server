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
        return $this->runtime->drain(function(array $job): array {
            $intent=$job['intent'];
            if (array_diff(array_keys($intent),['message','title','audio_asset_id']) || !is_string($intent['message']??null)
                || strlen($intent['message'])>16384 || !is_string($intent['title']??'') || strlen($intent['title']??'')>200) { throw new \DomainException('Invalid local edge notification content.'); }
            if (in_array('local_audio',$job['channels'],true)) { $this->asset((string)($intent['audio_asset_id']??'')); }
            $devices=array_column($this->config['edge_devices'],'id');
            if (array_diff($job['recipients'],$devices)) { throw new \DomainException('A site recipient has no approved local edge device.'); }
            $this->runtime->store()->transaction(static function(array &$s) use($job,$intent): void {
                if ($s['control']['stopped'] || time()>=$job['expires_at']) { throw new \DomainException('Edge STOP or expiry blocked publication.'); }
                if (isset($s['cache'][$job['id']])) { throw new \DomainException('Edge event was already published; no replay.'); }
                $s['cache'][$job['id']]=['job_id'=>$job['id'],'delivery_id'=>$job['delivery_id'],'expires_at'=>$job['expires_at'],
                    'created_at'=>$job['created_at'],'recipients'=>$job['recipients'],'channels'=>$job['channels'],'intent'=>$intent,'receipts'=>[]];
            });
            return ['uncertain'=>false,'state'=>'published','job_id'=>$job['id'],'human_acknowledgment'=>false];
        },$connected);
    }
    public function poll(array $device): array
    {
        $s=$this->runtime->store()->read(); if ($s['control']['stopped']) { return []; }
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
            if (!$e || time()>=$e['expires_at'] || !in_array($device['id'],$e['recipients'],true) || $s['control']['stopped']) { throw new \DomainException('Receipt event is unavailable, expired, or belongs to another device.'); }
            if ($input['kind']==='audio_played' && (!in_array('local_audio',$e['channels'],true) || !in_array('local_audio',$device['channels'],true))) { throw new \DomainException('This device was not permitted to play local audio.'); }
            $old=$e['receipts'][$device['id']]??null;
            if ($old && $old['kind']!==$input['kind']) { throw new \DomainException('A committed local device receipt is immutable.'); }
            $receipt=$old ?? ['device_id'=>$device['id'],'kind'=>$input['kind'],'received_at'=>time(),'human_acknowledgment'=>false];
            $s['cache'][$input['id']]['receipts'][$device['id']]=$receipt;
            $s['queue'][$input['id']]['receipt']['device_receipts'][$device['id']]=$receipt;
            return $receipt;
        });
    }
    private function asset(string $id): array
    {
        foreach ($this->config['edge_assets'] as $a) {
            if ($a['id']!==$id) { continue; }
            clearstatcache(true,$a['path']); $m=@lstat($a['path']);
            if (!$m || ($m['mode']&0170000)!==0100000 || $m['nlink']!==1 || ($m['mode']&0022)
                || realpath($a['path'])!==$a['path'] || $m['size']>2097152) { throw new \RuntimeException('Approved edge audio is missing or unsafe.'); }
            $bytes=file_get_contents($a['path'],false,null,0,2097153);
            if (!is_string($bytes) || !hash_equals($a['sha256'],hash('sha256',$bytes)) || substr($bytes,0,4)!=='RIFF' || substr($bytes,8,4)!=='WAVE') { throw new \RuntimeException('Approved cached edge audio changed or is not WAV.'); }
            return ['bytes'=>$bytes,'sha256'=>$a['sha256']];
        }
        throw new \DomainException('Local audio asset is not explicitly approved.');
    }
    public function media(array $device,string $eventId,string $assetId): array
    {
        foreach ($this->poll($device) as $e) {
            if ($e['id']===$eventId && $e['audio_asset_id']===$assetId && in_array('local_audio',$e['channels'],true)) { return $this->asset($assetId); }
        }
        throw new \DomainException('Local audio is expired or not authorized for this device.');
    }
}
