<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';

/** Explicit HTTPS origins, verified certificates, bounded responses and no redirects. */
final class EnterpriseIdentityHttp
{
    public static function request(string $url,array $hosts,string $method='GET',array $form=[],array $headers=[]): array
    {
        $parts=parse_url($url);$validated=$url; if(isset($parts['query'])) { if(!preg_match('/^startIndex=[1-9][0-9]{0,4}&count=200$/D',$parts['query'])) { throw new \DomainException('Unsupported provider endpoint query.'); }$validated=substr($url,0,strpos($url,'?')); } EnterpriseIdentityConfig::url($validated,'Provider endpoint');$host=strtolower(parse_url($url,PHP_URL_HOST));
        if(!in_array($host,$hosts,true)){throw new \DomainException('Provider endpoint hostname is outside the explicit allowlist.');}
        $h=curl_init($url);$body='';
        $options=[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $bytes)use(&$body):int{if(strlen($body)+strlen($bytes)>1048576){return 0;}$body.=$bytes;return strlen($bytes);}];
        if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986);}
        elseif($method!=='GET'){throw new \LogicException('Unsupported provider method.');}
        curl_setopt_array($h,$options);
        try{if(!curl_exec($h)||curl_getinfo($h,CURLINFO_RESPONSE_CODE)!==200){throw new \DomainException('Provider HTTPS request failed; check its endpoint and trusted certificate.');}}finally{curl_close($h);}
        $json=json_decode($body,true,32,JSON_THROW_ON_ERROR);if(!is_array($json)||array_is_list($json)){throw new \DomainException('Provider returned an invalid JSON document.');}return $json;
    }
}
