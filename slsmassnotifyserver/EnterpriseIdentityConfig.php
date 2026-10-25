<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/OperatorAccess.php';

/** Only portable settings; no transactions, replay IDs or browser cookies. */
final class EnterpriseIdentityConfig
{
    public const VENDORS = ['entra','okta','google','keycloak','onelogin'];
    public static function defaults(): array { return ['schema'=>1,'enabled'=>false,'providers'=>[],'grants'=>[]]; }
    public static function text($value,int $max,string $label,bool $empty=false): string
    {
        if (!is_string($value) || !preg_match('//u',$value) || strlen($value)>$max || (!$empty && $value==='') || preg_match('/[\x00-\x1f\x7f]/',$value)) { throw new \DomainException($label.' is invalid.'); }
        return $value;
    }
    public static function url($value,string $label): string
    {
        $value=self::text($value,2048,$label);
        if (strpbrk($value," <>\"\\")!==false || preg_match('/%(?![0-9A-Fa-f]{2})/',$value)!==0) {
            throw new \DomainException($label.' contains invalid URL characters. Percent-encode spaces and special characters.');
        }
        $p=parse_url($value);
        if (!$p || ($p['scheme']??'')!=='https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || isset($p['query']) || (($p['path']??'')!=='' && !str_starts_with($p['path'],'/'))
            || (isset($p['port']) && ($p['port']<1 || $p['port']>65535))) { throw new \DomainException($label.' must be an explicit HTTPS URL without credentials, query or fragment.'); }
        $host=filter_var($p['host'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false?$p['host']:self::hostname($p['host'],$label.' hostname');
        return 'https://'.$host.(isset($p['port'])&&$p['port']!==443?':'.$p['port']:'').($p['path']??'');
    }
    public static function hostname($value,string $label='Provider endpoint hostname'): string
    {
        if(!is_string($value)||$value===''||strlen($value)>253||$value!==trim($value)||str_ends_with($value,'.')
            || filter_var($value,FILTER_VALIDATE_IP)!==false){throw new \DomainException($label.' must be a fully qualified DNS hostname.');}
        $host=strtolower($value);$labels=explode('.',$host);
        if(count($labels)<2){throw new \DomainException($label.' must be a fully qualified DNS hostname.');}
        foreach($labels as $part){if(strlen($part)<1||strlen($part)>63||!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$part)){throw new \DomainException($label.' contains an invalid DNS label.');}}
        $last=end($labels);if(!preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D',$last)){throw new \DomainException($label.' must end in a DNS name, not a numeric or local label.');}
        return $host;
    }
    public static function expectedCallback(array $settings): string
    {
        $base=self::url($settings['control_api']['base_url']??'','Advertised Control API URL');$parts=parse_url($base);
        if(($parts['path']??'')!=='/api/sls-mass-notify'){throw new \DomainException('Save the advertised Control API address before configuring operator SSO.');}
        return 'https://'.$parts['host'].(isset($parts['port'])&&$parts['port']!==443?':'.$parts['port']:'').'/mass-notify/sso.php';
    }
    public static function bindCallbacks(array $config,array $settings): array
    {
        $expected=self::expectedCallback($settings);
        foreach($config['providers'] as $provider){if(!hash_equals($expected,$provider['callback_url'])){throw new \DomainException('The operator SSO callback must exactly match the advertised PBX HTTPS origin: '.$expected);}}
        return $config;
    }
    public static function assertCallbackRequest(array $settings,array $server): void
    {
        $expected=self::expectedCallback($settings);$parts=parse_url($expected);$authority=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        $host=$server['HTTP_HOST']??'';$uri=$server['REQUEST_URI']??'';$actual='';
        $https=strtolower((string)($server['HTTPS']??''));$scheme=strtolower((string)($server['REQUEST_SCHEME']??''));$secure=in_array($https,['on','1'],true)||$scheme==='https';
        if(is_string($host)){try{$origin=parse_url(self::url('https://'.$host,'Callback request origin'));if(($origin['path']??'')===''){$actual=$origin['host'].(isset($origin['port'])?':'.$origin['port']:'');}}catch(\DomainException $error){$actual='';}}
        if(!$secure||!is_string($uri)||!hash_equals($authority,$actual)||parse_url($uri,PHP_URL_PATH)!=='/mass-notify/sso.php'){
            throw new \DomainException('Enterprise sign-in reached an origin or path outside the registered PBX callback.');
        }
    }
    private static function privatePin($value): string
    {
        if(!is_string($value)||filter_var($value,FILTER_VALIDATE_IP)===false){throw new \DomainException('A private endpoint pin is not a valid IP address.');}
        $packed=inet_pton($value);if($packed===false){throw new \DomainException('A private endpoint pin is not a valid IP address.');}
        if(strlen($packed)===16&&substr($packed,0,12)==="\0\0\0\0\0\0\0\0\0\0\xff\xff"){$packed=substr($packed,12);}
        $private=strlen($packed)===4
            ? ord($packed[0])===10||(ord($packed[0])===172&&(ord($packed[1])&0xf0)===16)||(ord($packed[0])===192&&ord($packed[1])===168)
            : (ord($packed[0])&0xfe)===0xfc;
        if(!$private){throw new \DomainException('Private endpoint pins are limited to RFC 1918 IPv4 or unique-local IPv6 addresses.');}
        return strtolower(inet_ntop($packed));
    }
    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value),['schema','enabled','providers','grants'])
            || ($value['schema']??1)!==1 || !is_bool($value['enabled']??false)) { throw new \DomainException('Enterprise identity settings must use schema 1.'); }
        $out=self::defaults();$out['enabled']=$value['enabled']??false;$seen=[];
        $providers=$value['providers']??[];
        if (!is_array($providers) || !array_is_list($providers) || count($providers)>10) { throw new \DomainException('Configure at most ten enterprise providers.'); }
        foreach ($providers as $row) {
            $allowed=['id','vendor','protocol','name','enabled','issuer','callback_url','discovery_url','client_id','client_secret','token_auth','endpoint_hosts','private_endpoint_pins','hosted_domain',
                'idp_sso_url','idp_signing_certificates','sp_entity_id','sp_certificate','sp_private_key','nameid_format','tls'];
            if (!is_array($row) || array_diff(array_keys($row),$allowed) || !is_string($row['id']??null) || !preg_match('/^idp_[a-f0-9]{24}$/D',$row['id']) || isset($seen[$row['id']])
                || !in_array($row['vendor']??'',self::VENDORS,true) || !in_array($row['protocol']??'',['oidc','saml'],true) || !is_bool($row['enabled']??false)) { throw new \DomainException('An enterprise provider has invalid identity or protocol fields.'); }
            $p=['id'=>$row['id'],'vendor'=>$row['vendor'],'protocol'=>$row['protocol'],'name'=>self::text($row['name']??'',80,'Provider name'),
                'enabled'=>$row['enabled']??false,'issuer'=>self::text($row['issuer']??'',2048,'Exact issuer'),'callback_url'=>self::url($row['callback_url']??'','Callback URL')];
            require_once __DIR__.'/ScopedTls.php';$tls=ScopedTls::normalize($row['tls']??[]);
            if($tls&&$p['protocol']!=='oidc'){throw new \DomainException('Scoped TLS trust applies to OIDC server requests. SAML browser connections use the browser trust store.');}
            if($tls){$p['tls']=$tls;}
            if (!str_ends_with($p['callback_url'],'/mass-notify/sso.php')) { throw new \DomainException('Register a callback ending /mass-notify/sso.php.'); }
            if ($p['protocol']==='oidc') {
                $p['issuer']=self::url($p['issuer'],'OIDC issuer');self::hostname(parse_url($p['issuer'],PHP_URL_HOST),'OIDC issuer hostname');
                $p['discovery_url']=self::url($row['discovery_url']??'','Discovery URL');$p['client_id']=self::text($row['client_id']??'',256,'Client ID');
                $p['client_secret']=self::text($row['client_secret']??'',2048,'Client secret',true);$p['token_auth']=$row['token_auth']??'client_secret_basic';
                if (!in_array($p['token_auth'],['none','client_secret_basic','client_secret_post'],true) || ($p['token_auth']!=='none' && $p['client_secret']==='')) { throw new \DomainException('Select a supported token authentication method and provide its secret.'); }
                $hosts=$row['endpoint_hosts']??[];
                if (!is_array($hosts) || !array_is_list($hosts) || !$hosts || count($hosts)>8) { throw new \DomainException('Explicitly allowlist the provider endpoint hostnames.'); }
                $normalized=[];foreach($hosts as $h){$h=self::hostname($h);if(isset($normalized[$h])){throw new \DomainException('Provider endpoint hostnames must not repeat.');}$normalized[$h]=true;}
                $p['endpoint_hosts']=array_keys($normalized);$discoveryHost=self::hostname(parse_url($p['discovery_url'],PHP_URL_HOST));
                if(!isset($normalized[$discoveryHost])){throw new \DomainException('The discovery hostname must appear in the explicit endpoint allowlist.');}
                $pins=$row['private_endpoint_pins']??[];if(!is_array($pins)||!array_is_list($pins)||count($pins)>8){throw new \DomainException('Configure at most eight private endpoint pins.');}$pinHosts=[];$p['private_endpoint_pins']=[];
                foreach($pins as $pin){if(!is_array($pin)||array_diff(array_keys($pin),['host','ip'])){throw new \DomainException('Private endpoint pins require only host and IP fields.');}$pinHost=self::hostname($pin['host']??'');if(!isset($normalized[$pinHost])||isset($pinHosts[$pinHost])){throw new \DomainException('Each private endpoint pin must name one unique allowlisted host.');}$pinHosts[$pinHost]=true;$p['private_endpoint_pins'][]=['host'=>$pinHost,'ip'=>self::privatePin($pin['ip']??'')];}
                $p['hosted_domain']=strtolower(self::text($row['hosted_domain']??'',253,'Hosted domain',true));if($p['hosted_domain']!==''){$p['hosted_domain']=self::hostname($p['hosted_domain'],'Hosted domain');}
                if ($p['vendor']==='google' && ($p['issuer']!=='https://accounts.google.com' || $p['discovery_url']!=='https://accounts.google.com/.well-known/openid-configuration' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D',$p['hosted_domain']))) { throw new \DomainException('Google Workspace requires its exact Google issuer/discovery and an explicit Workspace hosted domain.'); }
                if ($p['vendor']==='entra' && !preg_match('#^https://login\.microsoftonline\.com/[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}/v2\.0$#D',$p['issuer'])) { throw new \DomainException('Entra requires a single tenant UUID issuer; common/organizations tenants are unsupported.'); }
            } else {
                $p['idp_sso_url']=self::url($row['idp_sso_url']??'','SAML sign-in URL');$p['sp_entity_id']=self::text($row['sp_entity_id']??'',2048,'SP entity ID');
                $certs=$row['idp_signing_certificates']??[];
                if (!is_array($certs) || !array_is_list($certs) || !$certs || count($certs)>3) { throw new \DomainException('Pin one through three IdP signing certificates.'); }
                $certificatePins=[];foreach($certs as $cert){self::certificate($cert);$fingerprint=openssl_x509_fingerprint($cert,'sha256');if(!is_string($fingerprint)||isset($certificatePins[$fingerprint])){throw new \DomainException('IdP signing certificate pins must be unique.');}$certificatePins[$fingerprint]=true;}$p['idp_signing_certificates']=$certs;
                $p['sp_certificate']=self::certificate($row['sp_certificate']??'');$p['sp_private_key']=self::pem($row['sp_private_key']??'','SP private key');
                $key=openssl_pkey_get_private($p['sp_private_key']);$details=$key?openssl_pkey_get_details($key):false;
                if (!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048 || !openssl_x509_check_private_key($p['sp_certificate'],$key)) { throw new \DomainException('SAML requests require a matching RSA certificate and private key of at least 2048 bits.'); }
                $p['nameid_format']=$row['nameid_format']??'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';
                if (!in_array($p['nameid_format'],['urn:oasis:names:tc:SAML:2.0:nameid-format:persistent','urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress','urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified'],true)) { throw new \DomainException('Select the registered SAML NameID format.'); }
            }
            $out['providers'][]=$p;$seen[$p['id']]=true;
        }
        $grants=$value['grants']??[];$bindings=[];
        if (!is_array($grants) || !array_is_list($grants) || count($grants)>100) { throw new \DomainException('At most 100 explicit operator bindings are supported.'); }
        foreach ($grants as $g) {
            if (!is_array($g) || array_diff(array_keys($g),['provider_id','subject','operator_account_id','enabled'])
                || !is_string($g['provider_id']??null) || !isset($seen[$g['provider_id']])
                || !is_string($g['operator_account_id']??null) || !preg_match('/^api_[a-f0-9]{24}$/D',$g['operator_account_id']) || !is_bool($g['enabled']??null)) { throw new \DomainException('Invalid explicit enterprise operator binding.'); }
            $g['subject']=self::text($g['subject']??'',512,'Stable provider subject');$k=$g['provider_id']."\0".$g['subject'];
            if (isset($bindings[$k])) { throw new \DomainException('A provider subject can bind to one local account.'); }$bindings[$k]=true;$out['grants'][]=$g;
        }
        return $out;
    }
    private static function pem($value,string $label): string
    {
        if(!is_string($value)||$value===''||strlen($value)>16384||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){throw new \DomainException($label.' must be PEM text.');}return $value;
    }
    private static function certificate($cert,?int $now=null): string
    {
        $cert=self::pem($cert,'Signing certificate');$x=openssl_x509_read($cert);$key=$x?openssl_pkey_get_public($x):false;$d=$key?openssl_pkey_get_details($key):false;
        $parsed=$x?openssl_x509_parse($x,false):false;$now??=time();$usage=is_array($parsed)?($parsed['extensions']['keyUsage']??''):'';
        if (!$d || $d['type']!==OPENSSL_KEYTYPE_RSA || $d['bits']<2048 || $d['bits']>8192 || !is_array($parsed)
            || !is_int($parsed['validFrom_time_t']??null)||!is_int($parsed['validTo_time_t']??null)||$parsed['validFrom_time_t']>$now||$parsed['validTo_time_t']<=$now
            || ($usage!==''&&!preg_match('/(?:^|,)\s*Digital Signature(?:,|$)/i',$usage))) { throw new \DomainException('Pin a currently valid 2048–8192 bit RSA certificate permitted for digital signatures.'); }return $cert;
    }
    public static function provider(array $settings,string $id): array
    {
        $c=self::bindCallbacks(self::normalize($settings['enterprise_identity']??[]),$settings);
        $access=OperatorAccess::normalize($settings['operator_access']??[]);
        if (!$c['enabled'] || !$access['portal_enabled']) { throw new \DomainException('Enterprise operator sign-in is disabled.'); }
        foreach ($c['providers'] as $p) { if ($p['id']===$id && $p['enabled']) { return $p; } }throw new \DomainException('This enterprise provider is unavailable.');
    }
    public static function revision(array $provider): string { return hash('sha256',json_encode($provider,JSON_THROW_ON_ERROR)); }
    public static function publicConfig(array $config): array
    {
        foreach ($config['providers'] as &$p) {
            foreach (['client_secret','sp_private_key'] as $k) { if (isset($p[$k])) { $configured=$p[$k]!=='';$p[$k]='';$p[$k.'_configured']=$configured; } }
            if($p['protocol']==='saml'){$exp=[];foreach($p['idp_signing_certificates'] as $cert){$parsed=openssl_x509_parse($cert,false);$exp[]=is_array($parsed)?($parsed['validTo_time_t']??null):null;}$sp=openssl_x509_parse($p['sp_certificate'],false);$p['idp_certificate_expires_at']=$exp;$p['sp_certificate_expires_at']=is_array($sp)?($sp['validTo_time_t']??null):null;$soon=array_filter(array_merge($exp,[$p['sp_certificate_expires_at']]),static fn($time)=>is_int($time)&&$time<time()+2592000);$p['certificate_warning']=$soon?'One or more SAML certificates expire within 30 days. Complete a reviewed rollover before expiry.':'';}
        }unset($p);return $config;
    }
}
