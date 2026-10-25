<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';
require_once __DIR__.'/api/sls-mass-notify/security.php';

/** Explicit HTTPS origins, pinned DNS results, verified certificates, bounded responses and no redirects. */
final class EnterpriseIdentityHttp
{
    private static function policy(array $value): array
    {
        if(array_is_list($value)){$value=['hosts'=>$value,'private_pins'=>[]];}
        if(array_diff(array_keys($value),['hosts','private_pins','tls'])||!is_array($value['hosts']??null)||!array_is_list($value['hosts'])
            || !is_array($value['private_pins']??[])||!array_is_list($value['private_pins']??[])){throw new \DomainException('Provider endpoint network policy is invalid.');}
        $hosts=[];foreach($value['hosts'] as $host){$host=EnterpriseIdentityConfig::hostname($host);if(isset($hosts[$host])){throw new \DomainException('Provider endpoint hostnames must not repeat.');}$hosts[$host]=true;}
        $pins=[];foreach($value['private_pins']??[] as $pin){if(!is_array($pin)||array_diff(array_keys($pin),['host','ip'])){throw new \DomainException('Private endpoint policy is invalid.');}$host=EnterpriseIdentityConfig::hostname($pin['host']??'');$ip=self::canonicalIp($pin['ip']??'');if(!isset($hosts[$host])||isset($pins[$host])||!self::isPrivatePin($ip)){throw new \DomainException('Private endpoint policy contains an invalid host or address.');}$pins[$host]=$ip;}
        require_once __DIR__.'/ScopedTls.php';
        return ['hosts'=>array_keys($hosts),'private_pins'=>$pins,'tls'=>ScopedTls::normalize($value['tls']??[])];
    }
    public static function networkPolicy(array $provider): array
    {
        return ['hosts'=>$provider['endpoint_hosts']??[],'private_pins'=>$provider['private_endpoint_pins']??[]]+(isset($provider['tls'])?['tls'=>$provider['tls']]:[]);
    }
    private static function canonicalIp($value): string
    {
        if(!is_string($value)||filter_var($value,FILTER_VALIDATE_IP)===false){throw new \DomainException('Provider hostname resolved to an invalid address.');}
        $packed=inet_pton($value);if($packed===false){throw new \DomainException('Provider hostname resolved to an invalid address.');}
        if(strlen($packed)===16&&substr($packed,0,12)==="\0\0\0\0\0\0\0\0\0\0\xff\xff"){$packed=substr($packed,12);}
        return strtolower(inet_ntop($packed));
    }
    private static function inCidr(string $ip,string $network,int $bits): bool
    {
        $a=inet_pton($ip);$b=inet_pton($network);if($a===false||$b===false||strlen($a)!==strlen($b)){return false;}$bytes=intdiv($bits,8);$rest=$bits%8;
        if($bytes&&substr($a,0,$bytes)!==substr($b,0,$bytes)){return false;}return !$rest||((ord($a[$bytes])&(0xff<<(8-$rest)))===(ord($b[$bytes])&(0xff<<(8-$rest))));
    }
    private static function isPrivatePin(string $ip): bool
    {
        return self::inCidr($ip,'10.0.0.0',8)||self::inCidr($ip,'172.16.0.0',12)||self::inCidr($ip,'192.168.0.0',16)||self::inCidr($ip,'fc00::',7);
    }
    private static function isPublic(string $ip): bool
    {
        $packed=inet_pton($ip);if($packed===false){return false;}
        if(strlen($packed)===16&&!self::inCidr($ip,'2000::',3)){return false;}
        $blocked=strlen($packed)===4
            ? [['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.31.196.0',24],['192.52.193.0',24],['192.88.99.0',24],['192.168.0.0',16],['192.175.48.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]]
            : [['2001::',23],['2001:db8::',32],['2002::',16],['3fff::',20]];
        foreach($blocked as [$network,$bits]){if(self::inCidr($ip,$network,$bits)){return false;}}return true;
    }
    private static function resolve(string $host): array
    {
        $current=$host;$seen=[];
        for($depth=0;$depth<8;$depth++){
            if(isset($seen[$current])){throw new \DomainException('Provider hostname contains a DNS alias loop.');}$seen[$current]=true;
            $records=dns_get_record($current,DNS_A|DNS_AAAA|DNS_CNAME);if($records===false){throw new \DomainException('Provider hostname resolution failed.');}$addresses=[];$aliases=[];
            foreach($records as $record){$ip=$record['ip']??$record['ipv6']??null;if($ip!==null){$addresses[]=self::canonicalIp($ip);if(count($addresses)>16){throw new \DomainException('Provider hostname returned too many addresses.');}}
                if(($record['type']??'')==='CNAME'){$target=$record['target']??'';if(is_string($target)&&str_ends_with($target,'.')){$target=substr($target,0,-1);}$aliases[EnterpriseIdentityConfig::hostname($target,'Provider DNS alias')]=true;}}
            $addresses=array_values(array_unique($addresses));sort($addresses,SORT_STRING);if($addresses){return $addresses;}
            if(count($aliases)!==1){throw new \DomainException('Provider hostname did not resolve to one bounded DNS alias or address.');}$current=array_key_first($aliases);
        }
        throw new \DomainException('Provider hostname DNS alias chain exceeds its limit.');
    }
    /** Resolve and vet one immutable cURL destination. Public for deterministic security fixtures. */
    public static function pinnedAddress(string $host,array $policy,?callable $resolver=null): string
    {
        $policy=self::policy($policy);$host=EnterpriseIdentityConfig::hostname($host);
        if(!in_array($host,$policy['hosts'],true)){throw new \DomainException('Provider endpoint hostname is outside the explicit allowlist.');}
        if(isset($policy['private_pins'][$host])){return $policy['private_pins'][$host];}
        $addresses=($resolver??[self::class,'resolve'])($host);if(!is_array($addresses)||!array_is_list($addresses)||!$addresses||count($addresses)>16){throw new \DomainException('Provider hostname resolution returned an invalid address set.');}
        $valid=[];foreach($addresses as $address){$address=self::canonicalIp($address);if(!self::isPublic($address)){throw new \DomainException('Provider hostname resolves to a private, local or reserved address without an exact private pin.');}$valid[$address]=true;}
        $valid=array_keys($valid);sort($valid,SORT_STRING);return $valid[0];
    }
    public static function request(string $url,array $policy,string $method='GET',array $form=[],array $headers=[]): array
    {
        $parts=parse_url($url);if(!is_array($parts)||isset($parts['fragment'])){throw new \DomainException('Provider endpoint URL is invalid.');}$validated=$url;if(isset($parts['query'])){if(!preg_match('/^startIndex=[1-9][0-9]{0,4}&count=200$/D',$parts['query'])){throw new \DomainException('Unsupported provider endpoint query.');}$validated=substr($url,0,strpos($url,'?'));}
        return self::perform($url,$validated,$policy,$method,$form,$headers,12000);
    }

    public static function directoryRequest(string $url,array $policy,string $method='GET',array $form=[],array $headers=[],int $timeoutMs=12000): array
    {
        require_once __DIR__.'/DirectoryCloud.php';
        DirectoryCloud::requestUrl($url);
        if ($timeoutMs < 1 || $timeoutMs > 12000) { throw new \DomainException('Cloud directory request deadline is invalid.'); }
        return self::perform($url,explode('?',$url,2)[0],$policy,$method,$form,$headers,$timeoutMs);
    }

    private static function perform(string $url,string $validated,array $policy,string $method,array $form,array $headers,int $timeoutMs): array
    {
        $validated=EnterpriseIdentityConfig::url($validated,'Provider endpoint');$host=EnterpriseIdentityConfig::hostname(parse_url($validated,PHP_URL_HOST));$port=(int)(parse_url($validated,PHP_URL_PORT)??443);$address=self::pinnedAddress($host,$policy);
        if(!function_exists('curl_init')||!defined('CURLOPT_RESOLVE')){throw new \DomainException('The PHP cURL runtime cannot pin provider DNS results.');}
        $h=curl_init($url);if($h===false){throw new \DomainException('Provider HTTPS request could not be initialized.');}$body='';$resolveAddress=str_contains($address,':')?'['.$address.']':$address;
        $options=ScopedTls::curlOptions(self::policy($policy)['tls'])+[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$timeoutMs),CURLOPT_TIMEOUT_MS=>$timeoutMs,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
            CURLOPT_RESOLVE=>[$host.':'.$port.':'.$resolveAddress],CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $bytes)use(&$body):int{if(strlen($body)+strlen($bytes)>1048576){return 0;}$body.=$bytes;return strlen($bytes);}];
        if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986);}
        elseif($method!=='GET'){curl_close($h);throw new \LogicException('Unsupported provider method.');}
        if(!curl_setopt_array($h,$options)){curl_close($h);throw new \DomainException('Provider HTTPS safeguards could not be configured.');}
        try{$ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$primaryRaw=(string)curl_getinfo($h,CURLINFO_PRIMARY_IP);
            if(!$ok||$status!==200||$primaryRaw===''){throw new \DomainException('Provider HTTPS request failed or reached an address outside its pinned DNS result.');}
            $primary=self::canonicalIp($primaryRaw);if(!hash_equals($address,$primary)){throw new \DomainException('Provider HTTPS request failed or reached an address outside its pinned DNS result.');}
        }finally{curl_close($h);}
        try{return ApiSecurity::jsonObject($body,false,32);}catch(\JsonException|\InvalidArgumentException $error){throw new \DomainException('Provider returned invalid or ambiguous JSON.');}
    }
}
