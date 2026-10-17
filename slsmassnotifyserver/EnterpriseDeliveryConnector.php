<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';

/** Private, administrator-selected HTTPS bridges; tokens never enter URL queries. */
final class EnterpriseDeliveryConnector
{
    public static function validateContent(string $title,string $message): void
    {
        if(!preg_match('//u',$title)||!preg_match('//u',$message)||mb_strlen($title,'UTF-8')>80||strlen($message)>4000){
            throw new \DomainException('Notification connectors require valid UTF-8 text, a title of at most 80 characters and a message of at most 4,000 bytes. Shorten the message or remove connector recipients.');
        }
    }
    public static function normalize(array $value): array
    {
        $value=IncidentConfig::object($value,['id','name','enabled','provider','origin','pinned_ipv4','token','topic'],'Notification connector');
        $id=EnterpriseIntegrationsConfig::id($value['id']??'','conn');$origin=$value['origin']??'';$ip=$value['pinned_ipv4']??'';
        EnterpriseIntegrationsConfig::origin($origin,$ip);if($origin===''){throw new \InvalidArgumentException('A connector needs its HTTPS origin and pinned private address.');}
        $provider=$value['provider']??'';if(!in_array($provider,['ntfy','gotify'],true)){throw new \InvalidArgumentException('Choose ntfy or Gotify.');}
        $token=$value['token']??'';if(!is_string($token)||$token===''||strlen($token)>4096||preg_match('/[\x00-\x20]/',$token)){throw new \InvalidArgumentException('A saved private connector token is required.');}
        $topic=$value['topic']??'';if(!is_string($topic)||($provider==='ntfy'&&!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$topic))||($provider==='gotify'&&$topic!=='')){throw new \InvalidArgumentException('ntfy requires a topic; Gotify uses its application token.');}
        return ['id'=>$id,'name'=>IncidentConfig::text($value['name']??'',100,'Connector name'),'enabled'=>EnterpriseIntegrationsConfig::flag($value['enabled']??'0'),
            'provider'=>$provider,'origin'=>$origin,'pinned_ipv4'=>$ip,'token'=>$token,'topic'=>$topic];
    }
    public static function send(array $destination,array $event,?callable $transport=null): array
    {
        $destination=self::normalize($destination);if($destination['enabled']!=='1'){throw new \DomainException('Connector is disabled.');}
        if(!is_string($event['title']??null)||!is_string($event['message']??null)||!is_bool($event['is_test']??null)){throw new \InvalidArgumentException('Connector announcement content is invalid; titles support at most 80 characters.');}
        $title=($event['is_test']?'TEST · ':'').$event['title'];
        self::validateContent($title,$event['message']);
        $headers=['Content-Type: application/json','Accept: application/json'];
        if($destination['provider']==='ntfy'){$url=$destination['origin'];$payload=['topic'=>$destination['topic'],'title'=>$title,'message'=>$event['message'],'priority'=>4];$headers[]='Authorization: Bearer '.$destination['token'];}
        else{$url=$destination['origin'].'/message';$payload=['title'=>$title,'message'=>$event['message'],'priority'=>5];$headers[]='X-Gotify-Key: '.$destination['token'];}
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($transport){$result=$transport($url,$body,$headers,$destination);}
        else{if(!function_exists('curl_init')){throw new \RuntimeException('Connector HTTPS support is unavailable.');}$curl=curl_init($url);if($curl===false){throw new \RuntimeException('Connector HTTPS request could not be initialized.');}$response='';$host=parse_url($destination['origin'],PHP_URL_HOST);$port=parse_url($destination['origin'],PHP_URL_PORT)??443;
            $configured=curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$destination['pinned_ipv4']],
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>16384){return 0;}$response.=$chunk;return strlen($chunk);}]);
            if(!$configured){curl_close($curl);throw new \RuntimeException('Connector HTTPS safeguards could not be configured.');}
            try{$ok=curl_exec($curl);$result=['error'=>$ok===false?curl_errno($curl):0,'status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response];}finally{curl_close($curl);}}
        if(($result['error']??1)!==0){return ['state'=>'uncertain','detail'=>'Connector completion is unconfirmed. No automatic replay.'];}
        $status=(int)($result['status']??0);
        if($status<200||$status>=500||$status===408||($status>=300&&$status<400)){return ['state'=>'uncertain','http_status'=>$status,'detail'=>'Connector submission could not be confirmed. Review the provider before sending again. No automatic replay.'];}
        if($status>=200&&$status<300&&!in_array($status,[200,201,202],true)){return ['state'=>'uncertain','http_status'=>$status,'detail'=>'Connector returned an unsupported acceptance response. Review the provider before sending again. No automatic replay.'];}
        if(!in_array($status,[200,201,202],true)){return ['state'=>'failed','http_status'=>$status,'detail'=>'Connector rejected the request. No automatic replay.'];}
        try {
            $raw=$result['body']??'';
            if(!is_string($raw)||strlen($raw)>16384){throw new \InvalidArgumentException('Invalid connector response size.');}
            $receipt=ApiSecurity::jsonObject($raw);
        } catch (\Throwable $error) {
            return ['state'=>'uncertain','http_status'=>$status,'detail'=>'Connector returned an invalid or ambiguous receipt. No automatic replay.'];
        }
        $id=$receipt['id']??null;
        $validId=$destination['provider']==='ntfy'
            ? is_string($id)&&preg_match('/^[A-Za-z0-9]{10}$/D',$id)===1
            : is_int($id)&&$id>0;
        if(!$validId){return ['state'=>'uncertain','http_status'=>$status,'detail'=>'Connector did not return a canonical message identity. No automatic replay.'];}
        return ['state'=>'accepted','http_status'=>$status,'provider_message_id'=>(string)$id,'detail'=>'Accepted by '.$destination['provider'].'. Client display and human receipt are not confirmed.'];
    }
}
