<?php
declare(strict_types=1);
namespace SLS\MassNotify\Sms;
require_once __DIR__.'/Config.php';
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Provider.php';

final class Service
{
    private Store $store;
    private Provider $provider;
    private string $mediaRoot;
    private $inboundHumanResponse;
    public function __construct(Store $store, ?Provider $provider=null, string $mediaRoot='/var/www/html/sls_mass_notify', ?callable $inboundHumanResponse=null) { $this->store=$store; $this->provider=$provider??new Provider(); $this->mediaRoot=$mediaRoot; $this->inboundHumanResponse=$inboundHumanResponse; }
    public static function callbackUrl(array $settings): string
    {
        $base=$settings['control_api']['base_url']??''; $host=$settings['public_pbx_host']??'';
        if (!is_string($base) || !is_string($host) || !preg_match('#^https://([a-z0-9.-]+)(?::([0-9]{1,5}))?/api/sls-mass-notify/?$#Di',$base,$match)
            || strtolower($match[1])!==strtolower($host) || (isset($match[2]) && $match[2]!=='' && ((int)$match[2]<1 || (int)$match[2]>65535))) {
            throw new \DomainException('sms_callback_address_invalid');
        }
        return rtrim($base,'/').'/sms-callback.php';
    }
    public static function targetFingerprint(array $row): string
    {
        return hash('sha256',json_encode([$row['id'],$row['number'],$row['consent_at']],JSON_THROW_ON_ERROR));
    }
    public static function targets(array $settings): array
    {
        $config=Config::normalize($settings['announcement_sms']??[]); $targets=[];
        if (!$config['enabled']) { return []; }
        foreach ($config['recipients'] as $row) { if ($row['enabled'] && $row['consent'] && $row['consent_at']!=='') { $targets[$row['id']]=$row; } }
        return $targets;
    }
    public static function snapshot(array $settings, array $ids, string $title, string $message, bool $test, array $media=[]): array
    {
        if (!array_is_list($ids) || count($ids)>Config::MAX_RECIPIENTS || count(array_unique($ids,SORT_REGULAR))!==count($ids)) {
            throw new \DomainException('Select distinct saved SMS recipients, up to 50.');
        }
        if (!$ids) { return []; }
        $config=Config::normalize($settings['announcement_sms']??[]); $known=self::targets($settings);
        $callback=self::callbackUrl($settings); $route=Config::route($config,$callback);
        $type=$config['message_format']==='mms'?'MMS':'SMS';
        self::validateMedia($type,$media,$callback);
        $body=Config::body($config,$title,$message,$test); $segments=Config::units($body,$config['from'],$type);
        if ($type==='SMS' && $segments['segments']>$config['max_segments']) { throw new \DomainException('This SMS requires '.$segments['segments'].' segments; the configured maximum is '.$config['max_segments'].'. Shorten it or review SMS limits.'); }
        $result=[];
        foreach ($ids as $id) {
            if (!Config::id($id) || !isset($known[$id])) { throw new \DomainException('A selected SMS recipient is disabled, lacks recorded consent or no longer exists.'); }
            $row=$known[$id];
            $result[$id]=['id'=>$id,'number'=>$row['number'],'consent_at'=>$row['consent_at'],'fingerprint'=>self::targetFingerprint($row),
                'route'=>$route,'callback'=>$callback,'body'=>$body,'body_hash'=>Config::messageHash($body,$type,$media)]
                +($type==='MMS'?['message_type'=>$type,'media'=>$media]:[]);
        }
        return $result;
    }
    public static function validateMedia(string $type, array $media, string $callback): void
    {
        if ($type==='SMS' && !$media) { return; }
        if ($type!=='MMS' || array_diff(array_keys($media),['url','sha256','bytes']) || !is_string($media['url']??null)
            || !Config::validMediaUrl($media['url'],$callback) || !is_string($media['sha256']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$media['sha256']) || !is_int($media['bytes']??null)
            || $media['bytes']<8 || $media['bytes']>300000) { throw new \DomainException('MMS needs an immutable HTTPS alert image on the advertised PBX origin, up to 300 KB.'); }
    }
    /** Publish frozen image bytes only when a worker is ready to submit. */
    private function publishMedia(array $media, string $encoded): void
    {
        if (strlen($encoded)>400000) { throw new \DomainException('mms_image_too_large'); }
        $bytes=base64_decode($encoded,true);
        if (!is_string($bytes) || strlen($bytes)!==$media['bytes'] || !hash_equals($media['sha256'],hash('sha256',$bytes))
            || substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n") { throw new \DomainException('mms_image_snapshot_invalid'); }
        $size=@getimagesizefromstring($bytes);
        if (!$size || $size[2]!==IMAGETYPE_PNG || $size[0]>1440 || $size[1]>816) { throw new \DomainException('mms_image_snapshot_invalid'); }
        $parent=@lstat($this->mediaRoot);
        if (!$parent || ($parent['mode']&0170000)!==0040000 || ($parent['mode']&0022) || realpath($this->mediaRoot)!==$this->mediaRoot) { throw new \RuntimeException('mms_image_workspace_unsafe'); }
        $path=$this->mediaRoot.'/'.basename(parse_url($media['url'],PHP_URL_PATH));
        clearstatcache(true,$path); $before=@lstat($path); $created=$before===false;
        $handle=@fopen($path,$created?'x+b':'r+b');
        if (!$handle) { throw new \RuntimeException('mms_image_workspace_unavailable'); }
        try {
            $open=fstat($handle); clearstatcache(true,$path); $named=@lstat($path);
            if (!$open || !$named || ($open['mode']&0170000)!==0100000 || $open['nlink']!==1 || ($open['mode']&0022)
                || $open['dev']!==$named['dev'] || $open['ino']!==$named['ino'] || is_link($path)
                || ($before && ($before['ino']!==$open['ino'] || $before['dev']!==$open['dev'])) || !flock($handle,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('mms_image_workspace_changed'); }
            if ($created) {
                if (!chmod($path,0644) || fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('mms_image_write_failed'); }
                $directory=@fopen($this->mediaRoot,'r');
                try { if (!$directory || !fsync($directory)) { throw new \RuntimeException('mms_image_write_failed'); } }
                finally { if ($directory) { fclose($directory); } }
            } else {
                if ($open['size']!==strlen($bytes) || !hash_equals($media['sha256'],hash('sha256',stream_get_contents($handle,300001)))) { throw new \RuntimeException('mms_image_identity_changed'); }
            }
        } finally { fclose($handle); }
    }
    public static function receipt(array $row): array
    {
        $state=$row['state']==='submitting'?'uncertain':$row['state'];
        $labels=['accepted'=>'Accepted by SMS provider; handset delivery is not yet confirmed.',
            'sent'=>'Sent by SMS provider; handset delivery is not yet confirmed.',
            'delivered'=>'SMS provider reports delivery. This does not confirm that a person read the alert.',
            'failed'=>'SMS provider rejected the message or reported delivery failure.',
            'uncertain'=>'SMS submission or delivery could not be confirmed. This delivery will not be repeated automatically.'];
        $detail=$labels[$state]??$labels['uncertain'];
        if (($row['provider']??'')==='bulkvs') {
            if ($state==='accepted') { $detail='Accepted by BulkVS. Handset delivery and inbound opt-out updates are not confirmed by this integration.'; }
            $bulkErrors=['bulkvs_http_401'=>'BulkVS rejected the API credentials. Check the API username and password in General Settings.',
                'bulkvs_http_403'=>'BulkVS reports insufficient funds or a disabled messaging service. Check the provider account.',
                'bulkvs_http_400'=>'BulkVS rejected the message fields. Check the sending number, recipient and complete message.',
                'bulkvs_http_404'=>'BulkVS reports that the number is not enabled for messaging. Check the sending number in its portal.',
                'bulkvs_http_409'=>'BulkVS rejected the sender configuration. Use Check saved BulkVS sender in General Settings; verify SMS is enabled on that number and its approved messaging campaign is assigned.',
                'bulkvs_recipient_rejected'=>'BulkVS rejected this recipient. Review the message result in its portal.'];
            $detail=$bulkErrors[$row['error_code']]??$detail;
            if (($row['error_detail']??'')!=='') { $detail.=' Provider reason: '.$row['error_detail']; }
        }
        if ($row['error_code']==='sms_expired') { $detail='The SMS submission deadline passed before the provider request. No SMS was submitted.'; }
        if ($row['error_code']==='mms_image_unavailable') { $detail='The MMS image could not be stored safely. No provider request was sent. Check generated-media ownership, free space and the original image snapshot.'; }
        if (($row['provider']??'')==='twilio' && $row['error_code']==='provider_21610') {
            $detail='Twilio reports that this recipient opted out. SLS has blocked further SMS to this number until new consent is recorded; the provider must also accept their opt-in.';
        }
        if ($row['error_code']!=='') { $detail.=' Code: '.$row['error_code'].'.'; }
        $type=$row['message_type']??'SMS';
        if ($type==='MMS') { $detail='MMS · '.$detail; }
        return ['channel'=>'sms','target'=>$row['recipient_id'],'state'=>$state,'detail'=>$detail,'retryable'=>false,'message_type'=>$type,
            'sms_delivery_id'=>$row['id'],'provider_message_id'=>$row['provider_id'],'segments'=>(int)$row['segments'],
            'budgeted_cost_micros'=>(int)$row['cost_micros'],'currency'=>$row['currency'], 'submission_started'=>!in_array($row['error_code'],['sms_expired','mms_image_unavailable'],true)];
    }
    public function send(array $settings, array $frozen, string $delivery, string $job, int $expires, int $now, string $mediaData=''): array
    {
        $config=Config::normalize($settings['announcement_sms']??[]); $id=$frozen['id']??'';
        $current=self::targets($settings)[$id]??null; $type=$frozen['message_type']??'SMS'; $media=$frozen['media']??[];
        if (!is_array($media) || !in_array($type,['SMS','MMS'],true) || ($type==='MMS' && $config['message_format']!=='mms')) { throw new \DomainException('sms_format_changed'); }
        self::validateMedia($type,$media,self::callbackUrl($settings));
        if (!$current || !hash_equals($frozen['fingerprint']??'',self::targetFingerprint($current))
            || ($frozen['number']??null)!==$current['number'] || ($frozen['callback']??null)!==self::callbackUrl($settings)
            || !hash_equals($frozen['route']??'',Config::route($config,self::callbackUrl($settings)))
            || !hash_equals($frozen['body_hash']??'',Config::messageHash($frozen['body']??'',$type,$media))) { throw new \DomainException('sms_recipient_or_route_changed'); }
        if (!preg_match('/^announcement-[a-f0-9]{32}$/D',$delivery) || !preg_match('/^job_[a-f0-9]{32}$/D',$job)) { throw new \DomainException('sms_delivery_identity_missing'); }
        $segments=Config::units($frozen['body'],$config['from'],$type);
        if ($type==='SMS' && $segments['segments']>$config['max_segments']) { throw new \DomainException('sms_segment_limit_exceeded'); }
        if ($type==='MMS' && $expires<=$now+5) { throw new \DomainException('sms_expired'); }
        $local='smsd_'.hash('sha256',$delivery."\0".$id); $numberHash=hash('sha256',$current['number']);
        $claim=$this->store->claim(['id'=>$local,'provider'=>$config['provider'],'route'=>$frozen['route'],'recipient_id'=>$id,'job_id'=>$job,
            'number_hash'=>$numberHash,'body_hash'=>$frozen['body_hash'],'expires_at'=>$expires,'segments'=>$segments['segments'],
            'cost_micros'=>$type==='MMS'?$config['mms_cost_micros']:$segments['segments']*$config['segment_cost_micros'],'currency'=>$config['currency'],
            'message_type'=>$type,'media_hash'=>$media['sha256']??''],$config,strtotime($current['consent_at']),$now);
        if (!$claim['claimed']) { return self::receipt($claim['row']); }
        $outcome=null;
        if ($type==='MMS') {
            try { $this->publishMedia($media,$mediaData); }
            catch (\Throwable $error) { $outcome=['state'=>'failed','provider_id'=>'','error_code'=>'mms_image_unavailable','submission_started'=>false]; }
        }
        try { if ($outcome===null) { $outcome=$type==='MMS'?$this->provider->send($config,$current['number'],$frozen['body'],$frozen['callback'].'?delivery='.$local,$expires,$now,$media['url'])
            :$this->provider->send($config,$current['number'],$frozen['body'],$frozen['callback'].'?delivery='.$local,$expires,$now); } }
        catch (\Throwable $error) { $outcome=['state'=>'uncertain','provider_id'=>'','error_code'=>'sms_submission_unconfirmed']; }
        try { $row=$this->store->outcome($local,$frozen['route'],$numberHash,$outcome['provider_id'],$outcome['state'],$outcome['error_code'],$now,$outcome['error_detail']??''); }
        catch (\DomainException $error) { throw new \RuntimeException('sms_receipt_commit_failed',0,$error); }
        return self::receipt($row);
    }
    public function callback(array $settings, string $delivery, string $raw, array $headers, int $now): void
    {
        $config=Config::normalize($settings['announcement_sms']??[]); $base=self::callbackUrl($settings);
        if (!in_array($config['provider'],['twilio','telnyx'],true)) { throw new \DomainException('sms_callback_unauthorized'); }
        if ($delivery!=='' && !preg_match('/^smsd_[a-f0-9]{64}$/D',$delivery)) { throw new \InvalidArgumentException('sms_callback_invalid_delivery'); }
        $event=Provider::callback($config,$base.($delivery!==''?'?delivery='.$delivery:''),$raw,$headers,$now);
        if (!Config::number($event['from']) || !Config::number($event['to'])) { throw new \DomainException('sms_callback_identity_mismatch'); }
        if ($event['inbound']) {
            if ($delivery!=='' || $event['to']!==$config['from']) { throw new \DomainException('sms_callback_identity_mismatch'); }
            $known=array_filter($config['recipients'],static fn($row)=>$row['number']===$event['from']);
            if ($known && $event['opt_out']) { $this->store->optOut($event['event_id'],hash('sha256',$event['from']),$now); }
            if ($known && !$event['opt_out'] && $this->inboundHumanResponse!==null) {
                $eligible=array_filter($known,fn($row)=>$config['enabled'] && $row['enabled'] && $row['consent'] && $this->store->allowsHumanReply(hash('sha256',$row['number']),(int)strtotime($row['consent_at'])));
                if (count($eligible)===1) { ($this->inboundHumanResponse)($event); }
            }
            // START never clears a local opt-out on a replayable signed request.
            // An administrator must record new consent; the provider also
            // applies its own opt-out and re-enrollment controls.
            return;
        }
        if ($delivery==='' || $event['from']!==$config['from']) { throw new \DomainException('sms_callback_identity_mismatch'); }
        if ($event['state']===null) { return; }
        $this->store->outcome($delivery,Config::route($config,$base),hash('sha256',$event['to']),$event['provider_id'],$event['state'],$event['error_code'],$now);
    }
}
