<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/identity-libs/vendor/autoload.php';
require_once __DIR__.'/IncidentConfig.php';
use Firebase\JWT\JWT;

/** RFC 8291 encryption and RFC 8292 VAPID using OpenSSL and the vendored JWT library. */
final class BrowserPush
{
    public const MAX_PAYLOAD_BYTES=3000;
    public static function validateContent(string $title,string $message,bool $isTest,?array $incident=null): void
    {
        // Reserve the complete supported ID and expiry width before queueing;
        // JSON escaping and multibyte text count toward the encrypted wire limit.
        self::payload(['event_id'=>str_repeat('x',128),'title'=>$title,'message'=>$message,
            'expires_at'=>PHP_INT_MAX,'is_test'=>$isTest,'browser_receipt'=>['subscriber_id'=>'sub_'.str_repeat('a',24),'expires_at'=>PHP_INT_MAX,'event_key'=>str_repeat('a',64),'endpoint_id'=>str_repeat('a',64),'token'=>str_repeat('a',64)]]
            +($incident===null?[]:['incident'=>$incident]));
    }
    private static function payload(array $event): string
    {
        if(!is_string($event['title']??null)||!is_string($event['message']??null)
            ||!preg_match('//u',$event['title'])||!preg_match('//u',$event['message'])
            ||mb_strlen($event['title'],'UTF-8')>80||!is_bool($event['is_test']??null)){
            throw new \DomainException('Browser push requires valid UTF-8 text, a title of at most 80 characters and an explicit test flag.');
        }
        if(array_key_exists('incident',$event)){
            if(IncidentConfig::context($event['incident'])['is_test']!==$event['is_test']){throw new \DomainException('Browser incident test context conflicts with its announcement.');}
        }
        if(array_key_exists('browser_receipt',$event)){
            $proof=$event['browser_receipt'];
            if(!is_array($proof)||array_diff(array_keys($proof),['subscriber_id','expires_at','event_key','endpoint_id','token'])||count($proof)!==5
                ||!is_string($proof['subscriber_id']??null)||!preg_match('/^sub_[a-f0-9]{24}$/D',$proof['subscriber_id'])||($proof['expires_at']??null)!==($event['expires_at']??null)){
                throw new \DomainException('Browser receipt proof is invalid.');
            }
            foreach(['event_key','endpoint_id','token'] as $key){if(!is_string($proof[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof[$key])){throw new \DomainException('Browser receipt proof is invalid.');}}
        }
        $payload=json_encode(array_intersect_key($event,array_flip(['event_id','title','message','expires_at','is_test','incident','browser_receipt'])),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($payload)>self::MAX_PAYLOAD_BYTES){throw new \DomainException('Browser push exceeds 3,000 encoded bytes including the title, JSON escaping and reserved event metadata. Shorten the message or remove browser recipients; no browser request was sent.');}
        return $payload;
    }
    public static function available(): bool
    {
        return extension_loaded('openssl')&&extension_loaded('curl')&&function_exists('openssl_pkey_derive')&&in_array('aes-128-gcm',openssl_get_cipher_methods(),true);
    }
    public static function encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
    public static function decode(string $value,int $length): string
    {
        if($length<1||$length>4096||strlen($value)!==intdiv($length*8+5,6)
            ||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)){throw new \InvalidArgumentException('Push key is not canonical base64url for the required length.');}
        $bytes=base64_decode(strtr($value,'-_','+/'),true);
        if(!is_string($bytes)||strlen($bytes)!==$length||self::encode($bytes)!==$value){throw new \InvalidArgumentException('Push key has an invalid length or encoding.');}return $bytes;
    }
    public static function subscription(array $value): array
    {
        if(array_diff(array_keys($value),['endpoint','expirationTime','keys'])||!is_array($value['keys']??null)||array_diff(array_keys($value['keys']),['p256dh','auth'])){throw new \InvalidArgumentException('Unsupported browser subscription fields.');}
        $url=$value['endpoint']??'';$parts=is_string($url)?parse_url($url):false;$host=strtolower($parts['host']??'');
        $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)
            ||(bool)preg_match('/^[a-z0-9-]+\.push\.services\.mozilla\.com$/D',$host)
            ||(bool)preg_match('/^[a-z0-9-]+\.notify\.windows\.com$/D',$host);
        if(!$parts||strlen($url)>2048||preg_match('/[\x00-\x20]/',$url)||!$allowed||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||($parts['port']??443)!==443||empty($parts['path'])){throw new \InvalidArgumentException('Use a supported HTTPS browser push service endpoint.');}
        $public=self::decode($value['keys']['p256dh']??'',65);if($public[0]!=="\x04"){throw new \InvalidArgumentException('Push subscription requires an uncompressed P-256 public key.');}
        self::publicKey($public);self::decode($value['keys']['auth']??'',16);
        return ['endpoint'=>$url,'keys'=>['p256dh'=>$value['keys']['p256dh'],'auth'=>$value['keys']['auth']]];
    }
    private static function publicKey(string $point)
    {
        $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
        $key=openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n");
        if(!$key){throw new \InvalidArgumentException('Push subscription public key is not on P-256.');}return $key;
    }
    public static function publicPoint(string $privatePem): string
    {
        $key=openssl_pkey_get_private($privatePem);$details=$key?openssl_pkey_get_details($key):false;
        if(!$details||($details['ec']['curve_name']??'')!=='prime256v1'||strlen($details['ec']['x']??'')>32||strlen($details['ec']['y']??'')>32){throw new \InvalidArgumentException('VAPID requires an unencrypted P-256 private key.');}
        return "\x04".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT);
    }
    public static function generateKey(): array
    {
        if(!self::available()){throw new \DomainException('Browser push requires PHP cURL, OpenSSL P-256 ECDH and AES-GCM in the runtime serving this page.');}
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if(!$key||!openssl_pkey_export($key,$pem)){throw new \RuntimeException('OpenSSL could not create a VAPID key.');}
        return ['private_key_pem'=>$pem,'public_key'=>self::encode(self::publicPoint($pem))];
    }
    private static function expand(string $prk,string $info,int $length): string { return substr(hash_hmac('sha256',$info."\x01",$prk,true),0,$length); }
    public static function encrypt(array $subscription,string $payload,?string $privatePem=null,?string $salt=null): string
    {
        $subscription=self::subscription($subscription);if(strlen($payload)>self::MAX_PAYLOAD_BYTES){throw new \InvalidArgumentException('Browser push payload exceeds 3000 bytes.');}
        $ua=self::decode($subscription['keys']['p256dh'],65);$auth=self::decode($subscription['keys']['auth'],16);
        $private=$privatePem!==null?openssl_pkey_get_private($privatePem):openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if(!$private||!openssl_pkey_export($private,$pem)){throw new \RuntimeException('Push encryption key generation failed.');}
        $as=self::publicPoint($pem);$shared=openssl_pkey_derive(self::publicKey($ua),$private,32);
        if(!is_string($shared)||strlen($shared)!==32){throw new \RuntimeException('Push ECDH key agreement failed.');}
        $prkKey=hash_hmac('sha256',$shared,$auth,true);$ikm=self::expand($prkKey,"WebPush: info\0".$ua.$as,32);
        $salt??=random_bytes(16);if(strlen($salt)!==16){throw new \InvalidArgumentException('Push encryption salt must be 16 bytes.');}
        $prk=hash_hmac('sha256',$ikm,$salt,true);$cek=self::expand($prk,"Content-Encoding: aes128gcm\0",16);$nonce=self::expand($prk,"Content-Encoding: nonce\0",12);
        $ciphertext=openssl_encrypt($payload."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
        if(!is_string($ciphertext)||strlen($tag)!==16){throw new \RuntimeException('Push authenticated encryption failed.');}
        return $salt.pack('N',4096).chr(65).$as.$ciphertext.$tag;
    }
    public static function headers(array $subscription,array $config,int $now,int $expires): array
    {
        $subscription=self::subscription($subscription);$point=self::publicPoint($config['private_key_pem']??'');$subject=$config['subject']??'';
        if(!is_string($subject)||!preg_match('#^(?:mailto:[A-Za-z0-9_.+%-]+@[A-Za-z0-9.-]+|https://[A-Za-z0-9.-]+(?:/[^\s]*)?)$#D',$subject)){throw new \InvalidArgumentException('Set a VAPID contact email or HTTPS contact URL.');}
        if($expires<=$now||$expires>$now+600){throw new \InvalidArgumentException('Browser alert expiry must be within ten minutes.');}
        $origin='https://'.parse_url($subscription['endpoint'],PHP_URL_HOST);
        $jwt=JWT::encode(['aud'=>$origin,'exp'=>$now+300,'sub'=>$subject],$config['private_key_pem'],'ES256');
        return ['Content-Type: application/octet-stream','Content-Encoding: aes128gcm','TTL: '.($expires-$now),
            'Urgency: high','Authorization: vapid t='.$jwt.', k='.self::encode($point)];
    }
    public static function validateEvent(array $event,int $now): string
    {
        $expires=$event['expires_at']??0;
        if(!is_int($expires)||$expires<=$now||$expires>$now+600||!is_string($event['event_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$event['event_id'])
            ||!is_string($event['title']??null)||!is_string($event['message']??null)||!preg_match('//u',$event['title'])||mb_strlen($event['title'],'UTF-8')>80||strlen($event['message'])>3000||!is_bool($event['is_test']??null)){throw new \InvalidArgumentException('Invalid or expired browser announcement; titles support at most 80 characters.');}
        return self::payload($event);
    }
    public static function send(array $subscription,array $config,array $event,int $now,?callable $transport=null): array
    {
        if(($config['enabled']??'0')!=='1'){throw new \DomainException('Browser push is disabled.');}
        $subscription=self::subscription($subscription);$expires=$event['expires_at']??0;
        $payload=self::validateEvent($event,$now);
        $headers=self::headers($subscription,$config,$now,$expires);$body=self::encrypt($subscription,$payload);
        if($transport){$result=$transport($subscription['endpoint'],$body,$headers);}
        else{$result=self::transmit($subscription['endpoint'],$body,$headers);}
        $status=(int)($result['status']??0);return ['state'=>($result['error']??1)===0&&in_array($status,[201,202],true)?'accepted':(in_array($status,[404,410],true)?'revoked':'unconfirmed'),
            'http_status'=>$status,'detail'=>'Push-service acceptance does not confirm browser display or a human response. No automatic resend.'];
    }
    public static function transmit(string $url,string $body,array $headers): array
    {
        if(!function_exists('curl_init')){throw new \RuntimeException('Browser push HTTPS support is unavailable.');}$curl=curl_init($url);if($curl===false){throw new \RuntimeException('Browser push HTTPS request could not be initialized.');}$received=0;$configured=curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$received):int{$received+=strlen($chunk);return $received>8192?0:strlen($chunk);}]);
        if(!$configured){curl_close($curl);throw new \RuntimeException('Browser push HTTPS safeguards could not be configured.');}
        try{$ok=curl_exec($curl);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'error'=>$ok===false?curl_errno($curl):0];}finally{curl_close($curl);}
    }
}
