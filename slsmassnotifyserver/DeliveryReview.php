<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Short-lived review binding; never grants authority or changes a recipient. */
final class DeliveryReview
{
    private static function canonical($value)
    {
        if(!is_array($value)){return $value;}
        if(!array_is_list($value)){ksort($value,SORT_STRING);}
        foreach($value as &$child){$child=self::canonical($child);}unset($child);return $value;
    }
    private static function digest(array $request,array $settings): string
    {
        $fields=['message','title','background_color','image','phones','desktops','desktop_client_ids','voice_recipient_ids','email_recipient_ids','sms_recipient_ids','browser_recipient_ids','connector_ids','webhooks','audio_mode','opening_tone','closing_tone','voice','external_voice','volume','priority','timeout_mode','display_timeout','unavailable_phones','is_test'];
        $config=['desktop_clients','announcement_groups','outbound_voice','announcement_email','announcement_sms','announcement_webhooks','subscriber_browser','enterprise_integrations','sipnotify','announcement_pronunciation','tts_max_seconds'];
        return hash('sha256',json_encode(self::canonical(['request'=>array_intersect_key($request,array_flip($fields)),'configuration'=>array_intersect_key($settings,array_flip($config))]),JSON_THROW_ON_ERROR));
    }
    private static function key(array $settings): string
    {
        $key=base64_decode((string)($settings['desktop_auth_key']??''),true);
        if(!is_string($key)||strlen($key)!==32){throw new \DomainException('The protected delivery review key is unavailable. Run Repair Installation.');}return $key;
    }
    public static function speechRevision(string $voice,array $rules,int $maximum): string
    {
        return hash('sha256',json_encode(self::canonical([$voice,$rules,$maximum]),JSON_THROW_ON_ERROR));
    }
    public static function waveSeconds(string $data): float
    {
        $length=strlen($data);$offset=12;$pcm=false;$audio=null;$chunks=0;
        if($length<44||$length>10485760||substr($data,0,4)!=='RIFF'||substr($data,8,4)!=='WAVE'||unpack('V',substr($data,4,4))[1]+8!==$length){throw new \DomainException('The speech preview returned an invalid WAV file.');}
        while($offset+8<=$length&&++$chunks<=64){
            $kind=substr($data,$offset,4);$size=unpack('V',substr($data,$offset+4,4))[1];$start=$offset+8;$end=$start+$size;
            if($end>$length){throw new \DomainException('The speech preview WAV is truncated.');}
            if($kind==='fmt '){
                if($pcm||$size<16||unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits',substr($data,$start,16))!==['format'=>1,'channels'=>1,'rate'=>8000,'bytes'=>16000,'block'=>2,'bits'=>16]){throw new \DomainException('The speech preview requires 8 kHz mono PCM audio.');}$pcm=true;
            }elseif($kind==='data'){
                if($audio!==null||$size<2||$size%2){throw new \DomainException('The speech preview has an invalid audio chunk.');}$audio=$size;
            }
            $offset=$end+($size%2);
        }
        if(!$pcm||$audio===null||$offset!==$length||$chunks>64){throw new \DomainException('The speech preview WAV is incomplete.');}
        return $audio/16000;
    }
    public static function create(array $request,array $settings,?int $now=null): array
    {
        $expires=($now??time())+300;$digest=self::digest($request,$settings);
        $token=$expires.'.'.hash_hmac('sha256',"sls-delivery-review-v1\n".$expires."\n".$digest,self::key($settings));
        $channels=['phones'=>'phones','desktops'=>'desktops','external_voice'=>'voice_recipient_ids','email'=>'email_recipient_ids','sms'=>'sms_recipient_ids','webhooks'=>'webhooks','browser'=>'browser_recipient_ids','connectors'=>'connector_ids'];
        $counts=[];$recipients=[];foreach($channels as $label=>$field){$counts[$label]=count($request[$field]??[]);$recipients[$label]=array_values($request[$field]??[]);}
        $labels=[];
        foreach(['external_voice'=>'outbound_voice','email'=>'announcement_email','sms'=>'announcement_sms'] as $channel=>$config){
            foreach($settings[$config]['recipients']??[] as $recipient){$id=$recipient['id']??'';$name=$recipient['name']??$id;$address=$recipient['number']??($recipient['address']??'');if(is_string($id)&&is_string($name)&&is_string($address)){$labels[$channel][$id]=$name.($address!==''?' · '.$address:'');}}
        }
        foreach($recipients as $channel=>&$targets){$targets=array_map(static fn($id)=>$labels[$channel][$id]??$id,$targets);}unset($targets);
        return ['token'=>$token,'expires_at'=>gmdate('c',$expires),'counts'=>$counts,'recipients'=>$recipients,'unavailable_phones'=>$request['unavailable_phones']??[],
            'speech'=>['mode'=>$request['audio_mode'],'maximum_seconds'=>(int)($settings['tts_max_seconds']??30),'gateway_maximum_seconds'=>$request['radio_context']['maximum_audio_seconds']??null,'duration_seconds'=>null,'revision'=>self::speechRevision((string)($request['voice']??''),$request['pronunciation_rules']??[],(int)($settings['tts_max_seconds']??30)),'external_revision'=>self::speechRevision((string)($request['external_voice']??''),$request['pronunciation_rules']??[],(int)($settings['tts_max_seconds']??30))],
            'delivery_started'=>false];
    }
    public static function verify($token,array $request,array $settings,?int $now=null): void
    {
        $now=$now??time();
        if(!is_string($token)||!preg_match('/^([1-9][0-9]{0,10})\.([a-f0-9]{64})$/D',$token,$match)
            ||(int)$match[1]<=$now||(int)$match[1]>$now+300
            ||!hash_equals(hash_hmac('sha256',"sls-delivery-review-v1\n".$match[1]."\n".self::digest($request,$settings),self::key($settings)),$match[2])){
            throw new \DomainException('The message, recipients or delivery settings changed, or the review expired. Review the current selection again.');
        }
    }
}
