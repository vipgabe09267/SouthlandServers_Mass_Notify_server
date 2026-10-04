<?php
declare(strict_types=1);
namespace SLS\MassNotify\Sms;
require_once __DIR__.'/Config.php';
require_once __DIR__.'/vendor/twilio/Values.php';
require_once __DIR__.'/vendor/twilio/RequestValidator.php';

/** Fixed provider APIs. No arbitrary destinations, redirects or automatic retries. */
class Provider
{
    public static function providerId(string $provider, $id): bool
    {
        return in_array($provider,['twilio','telnyx','bulkvs'],true) && is_string($id)
            && ($provider==='twilio'?(bool)preg_match('/^(?:SM|MM)[a-f0-9]{32}$/Di',$id):Config::uuid($id));
    }
    protected function request(string $url, array $headers, string $body, ?string $basic): array
    {
        return $this->transport($url, $headers, $body, $basic, true);
    }
    protected function lookup(string $url, string $basic): array
    {
        return $this->transport($url, ['Accept: application/json'], '', $basic, false);
    }
    private function transport(string $url, array $headers, string $body, ?string $basic, bool $post): array
    {
        $response=''; $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>2500,
            CURLOPT_TIMEOUT_MS=>8000,CURLOPT_NOSIGNAL=>true,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'SLS-Mass-Notify-SMS/1',
            CURLOPT_WRITEFUNCTION=>static function ($handle,$chunk) use (&$response) {
                if (strlen($response)+strlen($chunk)>65536) { return 0; } $response.=$chunk; return strlen($chunk);
            }]);
        if ($post) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,$body); }
        if ($basic!==null) { curl_setopt($curl,CURLOPT_HTTPAUTH,CURLAUTH_BASIC); curl_setopt($curl,CURLOPT_USERPWD,$basic); }
        try { $ok=curl_exec($curl); return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'error'=>$ok===false?curl_errno($curl):0,'body'=>$response]; }
        finally { curl_close($curl); }
    }
    /** Explicit, read-only account check; never run during ordinary page loads. */
    public function diagnoseBulkvs(array $config): array
    {
        $config=Config::normalize($config);
        if ($config['provider']!=='bulkvs' || $config['bulkvs_api_username']==='' || $config['bulkvs_api_password']==='' || !Config::number($config['from'])) {
            throw new \DomainException('Save the BulkVS API credentials and sending number before checking the sender.');
        }
        $result=$this->lookup('https://portal.bulkvs.com/api/v1.0/tnRecord?'.http_build_query(['Number'=>substr($config['from'],1)], '', '&', PHP_QUERY_RFC3986),
            $config['bulkvs_api_username'].':'.$config['bulkvs_api_password']);
        if ($result['error']!==0 || $result['status']!==200) {
            $network=[6=>'DNS resolution failed',7=>'The provider connection failed',28=>'The provider request timed out',60=>'The provider TLS certificate could not be verified'];
            if ($result['error']!==0) {
                throw new \DomainException(($network[$result['error']]??'The provider request failed').' during the BulkVS sender check. Review PBX internet access and TLS trust. No SMS was sent.');
            }
            throw new \DomainException('The BulkVS sender check failed (HTTP '.(int)$result['status'].'). Review the saved API credentials, API permissions and PBX internet access. No SMS was sent.');
        }
        $value=json_decode($result['body'],true,32);
        if (!is_array($value) || !array_is_list($value)) { throw new \DomainException('BulkVS returned an unexpected sender record. No SMS was sent.'); }
        $rows=array_values(array_filter($value,static fn($r)=>is_array($r) && (string)($r['TN']??'')===substr($config['from'],1)));
        if (count($rows)!==1) { throw new \DomainException('The saved sending number was not found in this BulkVS account. Select a number owned by the account. No SMS was sent.'); }
        $messaging=$rows[0]['Messaging']??[];
        $sms=($messaging['Sms']??null)===true; $mms=($messaging['Mms']??null)===true;
        $campaign=is_string($messaging['Tcr']??null) && trim($messaging['Tcr'])!=='';
        $class=is_string($messaging['Class']??null)?$messaging['Class']:'';
        $issues=[];
        if (!$sms) { $issues[]='The sending number has SMS disabled. Enable SMS for that number in the BulkVS portal.'; }
        if ($config['message_format']==='mms' && !$mms) { $issues[]='The sending number has MMS disabled. Enable MMS for that number before using the MMS format.'; }
        if ($class==='A2PLC' && !$campaign) { $issues[]='No messaging campaign is assigned to this A2P long-code number. Complete registration and assign its approved campaign in BulkVS.'; }
        return ['sms_enabled'=>$sms, 'mms_enabled'=>$mms, 'campaign_configured'=>$campaign, 'issues'=>$issues,
            'message'=>$issues?implode(' ', $issues):'BulkVS reports SMS enabled for the sender. Carrier approval, delivery and inbound opt-out handling still require verification. No SMS was sent.'];
    }
    private static function bulkvsErrorDetail($value, array $config, string $number, string $body): string
    {
        if (!is_array($value)) { return ''; }
        $text=is_string($value['Description']??null)?$value['Description']:'';
        foreach (array_merge([$number,substr($number,1),$config['from'],substr($config['from'],1),$body],
            [$config['bulkvs_api_username'],$config['bulkvs_api_password']]) as $secret) {
            if ($secret!=='') { $text=str_replace($secret,'[redacted]',$text); }
        }
        $text=preg_replace('#https?://[^\s<>]+#i','[URL removed]',strip_tags($text));
        $text=preg_replace('/\+?[0-9][0-9 ().-]{6,}[0-9]/','[number removed]',$text);
        if (!is_string($text) || !preg_match('//u',$text)) { $text=''; }
        $text=trim(preg_replace('/\s+/u',' ',preg_replace('/\p{C}/u',' ',$text)));
        $code=$value['Code']??'';
        if (is_scalar($code) && preg_match('/^[A-Za-z0-9_-]{1,32}$/D',(string)$code)
            && !in_array((string)$code,[$config['bulkvs_api_username'],$config['bulkvs_api_password'],$number,substr($number,1),$config['from'],substr($config['from'],1)],true)) { $text.=' Provider code: '.$code.'.'; }
        return mb_substr($text,0,384);
    }
    public function send(array $config, string $number, string $body, string $callback, int $expiresAt, int $now, string $mediaUrl=''): array
    {
        $config=Config::normalize($config);
        if (!$config['enabled'] || !Config::number($number)
            || !preg_match('#^https://[a-z0-9.-]+(?::[0-9]{1,5})?/api/sls-mass-notify/sms-callback\.php\?delivery=smsd_[a-f0-9]{64}$#Di',$callback)) {
            throw new \DomainException('sms_submission_settings_invalid');
        }
        $type=$mediaUrl===''?'SMS':'MMS';
        if ($type==='MMS' && ($config['message_format']!=='mms' || !Config::validMediaUrl($mediaUrl,$callback))) { throw new \DomainException('mms_media_address_invalid'); }
        $preview=Config::units($body,$config['from'],$type);
        if ($type==='SMS' && $preview['segments']>$config['max_segments']) { throw new \DomainException('sms_segment_limit_exceeded'); }
        if ($expiresAt<=$now+5) { return ['state'=>'failed','provider_id'=>'','error_code'=>'sms_expired','submission_started'=>false]; }
        if ($config['provider']==='twilio') {
            $result=$this->request('https://api.twilio.com/2010-04-01/Accounts/'.$config['twilio_account_sid'].'/Messages.json',
                ['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
                http_build_query(['To'=>$number,'From'=>$config['from'],'Body'=>$body,'StatusCallback'=>$callback,
                    'ValidityPeriod'=>min(900,$expiresAt-$now)]+($type==='MMS'?['MediaUrl'=>$mediaUrl]:[]),'','&',PHP_QUERY_RFC3986),$config['twilio_key_sid'].':'.$config['twilio_key_secret']);
        } elseif ($config['provider']==='bulkvs') {
            // BulkVS OpenAPI 1.0.05: one original recipient per durable claim.
            // Its published request schema has no signed callback or TTL field.
            $result=$this->request('https://portal.bulkvs.com/api/v1.0/messageSend',
                ['Content-Type: application/json','Accept: application/json'],
                json_encode(['From'=>substr($config['from'],1),'To'=>[substr($number,1)],'Message'=>$body]+($type==='MMS'?['MediaURLs'=>[$mediaUrl]]:[]),
                    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $config['bulkvs_api_username'].':'.$config['bulkvs_api_password']);
        } else {
            $result=$this->request('https://api.telnyx.com/v2/messages',
                ['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$config['telnyx_api_key']],
                json_encode(['from'=>$config['from'],'to'=>$number,'text'=>$body,'type'=>$type,
                    'messaging_profile_id'=>$config['telnyx_profile_id'],'webhook_url'=>$callback,
                    'encoding'=>$preview['encoding']==='GSM-7'?'gsm7':'ucs2']+($type==='MMS'?['media_urls'=>[$mediaUrl]]:[]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),null);
        }
        // A successful write followed by a timeout, truncated response or 5xx
        // may already have created a billable message. Never repeat it here.
        $uncertain=['state'=>'uncertain','provider_id'=>'','error_code'=>'sms_submission_unconfirmed','submission_started'=>true];
        if ($result['error']!==0 || $result['status']>=500 || $result['status']<200 || $result['status']>=300 && $result['status']<400) { return $uncertain; }
        $value=json_decode($result['body'],true,32);
        if ($result['status']>=400) {
            if ($config['provider']==='bulkvs') {
                return ['state'=>'failed','provider_id'=>'','error_code'=>'bulkvs_http_'.$result['status'], 'submission_started'=>true,
                    'error_detail'=>self::bulkvsErrorDetail($value,$config,$number,$body)];
            }
            $code=$config['provider']==='twilio'?($value['code']??''):($value['errors'][0]['code']??'');
            return ['state'=>'failed','provider_id'=>'','error_code'=>preg_match('/^[0-9]{1,10}$/D',(string)$code)?'provider_'.$code:'provider_http_'.$result['status'],
                'submission_started'=>true];
        }
        if ($config['provider']==='bulkvs') {
            if (!is_array($value) || !self::providerId('bulkvs',$value['RefId']??null)
                || ($value['From']??null)!==substr($config['from'],1) || ($value['MessageType']??null)!==$type
                || !is_array($value['Results']??null) || count($value['Results'])!==1
                || ($value['Results'][0]['To']??null)!==substr($number,1)) { return $uncertain; }
            if (($value['Results'][0]['Status']??null)!=='SUCCESS') {
                return ['state'=>'failed','provider_id'=>$value['RefId'],'error_code'=>'bulkvs_recipient_rejected','submission_started'=>true];
            }
            return ['state'=>'accepted','provider_id'=>$value['RefId'],'error_code'=>'','submission_started'=>true];
        }
        $id=$config['provider']==='twilio'?($value['sid']??null):($value['data']['id']??null);
        $from=$config['provider']==='twilio'?($value['from']??null):($value['data']['from']['phone_number']??null);
        $to=$config['provider']==='twilio'?($value['to']??null):($value['data']['to'][0]['phone_number']??null);
        if (!self::providerId($config['provider'],$id) || $from!==$config['from'] || $to!==$number
            || ($config['provider']==='twilio' && ($value['account_sid']??'')!==$config['twilio_account_sid'])
            || ($config['provider']==='telnyx' && ($value['data']['messaging_profile_id']??'')!==$config['telnyx_profile_id'])) { return $uncertain; }
        return ['state'=>'accepted','provider_id'=>$id,'error_code'=>'','submission_started'=>true];
    }
    private static function form(string $raw): array
    {
        $result=[]; $parts=explode('&',$raw);
        if (count($parts)>100) { throw new \InvalidArgumentException('sms_callback_too_many_fields'); }
        foreach ($parts as $part) {
            $pair=explode('=',$part,2); $key=urldecode($pair[0]); $value=urldecode($pair[1]??'');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$key) || array_key_exists($key,$result)) { throw new \InvalidArgumentException('sms_callback_invalid_fields'); }
            $result[$key]=$value;
        }
        return $result;
    }
    /** Authenticate the original body before interpreting any provider fields. */
    public static function callback(array $config, string $canonicalUrl, string $raw, array $headers, int $now): array
    {
        if (!in_array($config['provider']??'', ['twilio','telnyx'],true)) { throw new \DomainException('sms_callback_unauthorized'); }
        if (strlen($raw)>65536) { throw new \InvalidArgumentException('sms_callback_too_large'); }
        if ($config['provider']==='twilio') {
            if ($config['twilio_auth_token']==='' || strtolower(explode(';',$headers['content-type']??'')[0])!=='application/x-www-form-urlencoded') { throw new \DomainException('sms_callback_unauthorized'); }
            $payload=self::form($raw);
            $validator=new \SLS\MassNotify\Vendor\Twilio\Security\RequestValidator($config['twilio_auth_token']);
            if (!$validator->validate($headers['x-twilio-signature']??'',$canonicalUrl,$payload)
                || ($payload['AccountSid']??'')!==$config['twilio_account_sid']) { throw new \DomainException('sms_callback_unauthorized'); }
            $id=$payload['MessageSid']??'';
            if (!self::providerId('twilio',$id)) { throw new \InvalidArgumentException('sms_callback_invalid_id'); }
            $status=$payload['MessageStatus']??$payload['SmsStatus']??'';
            $inbound=$status==='received';
            $state=['accepted'=>'accepted','scheduled'=>'accepted','queued'=>'accepted','sending'=>'accepted','sent'=>'sent',
                'delivered'=>'delivered','undelivered'=>'failed','failed'=>'failed','canceled'=>'failed'][$status]??null;
            $optOut=($payload['OptOutType']??'')==='STOP' || in_array(strtoupper(trim($payload['Body']??'')),['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPT OUT'],true);
            $code=$payload['ErrorCode']??'';
            $text=$payload['Body']??'';
            if (!is_string($text) || strlen($text)>6400) { throw new \InvalidArgumentException('sms_callback_invalid_body'); }
            return ['provider'=>'twilio','body'=>$inbound?$text:'','provider_id'=>$id,'event_id'=>'twilio_'.$id,'inbound'=>$inbound,'opt_out'=>$inbound&&$optOut,
                'from'=>$payload['From']??'','to'=>$payload['To']??'','state'=>$state,
                'error_code'=>$code!==''&&preg_match('/^[0-9]{1,10}$/D',$code)?'provider_'.$code:''];
        }
        $timestamp=$headers['telnyx-timestamp']??'';
        $signature=base64_decode($headers['telnyx-signature-ed25519']??'',true);
        $key=base64_decode($config['telnyx_public_key'],true);
        if (!preg_match('/^[0-9]{10}$/D',$timestamp) || abs($now-(int)$timestamp)>300 || !is_string($signature) || strlen($signature)!==64
            || !is_string($key) || strlen($key)!==32 || !sodium_crypto_sign_verify_detached($signature,$timestamp.'|'.$raw,$key)) {
            throw new \DomainException('sms_callback_unauthorized');
        }
        $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR); $data=$value['data']??[]; $payload=$data['payload']??[];
        if (!Config::uuid($data['id']??null) || !self::providerId('telnyx',$payload['id']??null)
            || ($payload['messaging_profile_id']??'')!==$config['telnyx_profile_id']) { throw new \DomainException('sms_callback_identity_mismatch'); }
        $inbound=($data['event_type']??'')==='message.received';
        $status=$payload['to'][0]['status']??'';
        $state=['queued'=>'accepted','sending'=>'accepted','sent'=>'sent','delivered'=>'delivered','sending_failed'=>'failed',
            'delivery_failed'=>'failed','delivery_unconfirmed'=>'uncertain'][$status]??null;
        if (!$inbound && !in_array($data['event_type']??'',['message.sent','message.finalized'],true)) { $state=null; }
        $optOut=in_array(strtoupper(trim($payload['text']??'')),['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPT OUT'],true);
        $code=$payload['errors'][0]['code']??'';
        $text=$payload['text']??'';
        if (!is_string($text) || strlen($text)>6400) { throw new \InvalidArgumentException('sms_callback_invalid_body'); }
        return ['provider'=>'telnyx','body'=>$inbound?$text:'','provider_id'=>$payload['id'],'event_id'=>'telnyx_'.$data['id'],'inbound'=>$inbound,'opt_out'=>$inbound&&$optOut,
            'from'=>$payload['from']['phone_number']??'','to'=>$payload['to'][0]['phone_number']??'','state'=>$state,
            'error_code'=>preg_match('/^[0-9]{1,10}$/D',(string)$code)?'provider_'.$code:''];
    }
}
