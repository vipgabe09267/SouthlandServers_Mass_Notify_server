<?php
declare(strict_types=1);
namespace SLS\MassNotify;

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
        $value=self::text($value,2048,$label);$p=parse_url($value);
        if (!$p || ($p['scheme']??'')!=='https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || !preg_match('/^[A-Za-z0-9.-]+$/D',$p['host']) || str_ends_with($p['host'],'.') || isset($p['query'])) { throw new \DomainException($label.' must be an explicit HTTPS URL without credentials, query or fragment.'); }
        return $value;
    }
    public static function normalize($value): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value),['schema','enabled','providers','grants'])
            || ($value['schema']??1)!==1 || !is_bool($value['enabled']??false)) { throw new \DomainException('Enterprise identity settings must use schema 1.'); }
        $out=self::defaults();$out['enabled']=$value['enabled']??false;$seen=[];
        $providers=$value['providers']??[];
        if (!is_array($providers) || !array_is_list($providers) || count($providers)>10) { throw new \DomainException('Configure at most ten enterprise providers.'); }
        foreach ($providers as $row) {
            $allowed=['id','vendor','protocol','name','enabled','issuer','callback_url','discovery_url','client_id','client_secret','token_auth','endpoint_hosts','hosted_domain',
                'idp_sso_url','idp_signing_certificates','sp_entity_id','sp_certificate','sp_private_key','nameid_format'];
            if (!is_array($row) || array_diff(array_keys($row),$allowed) || !preg_match('/^idp_[a-f0-9]{24}$/D',$row['id']??'') || isset($seen[$row['id']])
                || !in_array($row['vendor']??'',self::VENDORS,true) || !in_array($row['protocol']??'',['oidc','saml'],true) || !is_bool($row['enabled']??false)) { throw new \DomainException('An enterprise provider has invalid identity or protocol fields.'); }
            $p=['id'=>$row['id'],'vendor'=>$row['vendor'],'protocol'=>$row['protocol'],'name'=>self::text($row['name']??'',80,'Provider name'),
                'enabled'=>$row['enabled']??false,'issuer'=>self::text($row['issuer']??'',2048,'Exact issuer'),'callback_url'=>self::url($row['callback_url']??'','Callback URL')];
            if (!str_ends_with($p['callback_url'],'/mass-notify/sso.php')) { throw new \DomainException('Register a callback ending /mass-notify/sso.php.'); }
            if ($p['protocol']==='oidc') {
                self::url($p['issuer'],'OIDC issuer');
                $p['discovery_url']=self::url($row['discovery_url']??'','Discovery URL');$p['client_id']=self::text($row['client_id']??'',256,'Client ID');
                $p['client_secret']=self::text($row['client_secret']??'',2048,'Client secret',true);$p['token_auth']=$row['token_auth']??'client_secret_basic';
                if (!in_array($p['token_auth'],['none','client_secret_basic','client_secret_post'],true) || ($p['token_auth']!=='none' && $p['client_secret']==='')) { throw new \DomainException('Select a supported token authentication method and provide its secret.'); }
                $hosts=$row['endpoint_hosts']??[];
                if (!is_array($hosts) || !array_is_list($hosts) || !$hosts || count($hosts)>8) { throw new \DomainException('Explicitly allowlist the provider endpoint hostnames.'); }
                foreach ($hosts as $h) { if (!is_string($h) || !preg_match('/^[a-z0-9.-]{1,253}$/D',$h) || str_ends_with($h,'.')) { throw new \DomainException('Invalid provider endpoint hostname.'); } }
                $p['endpoint_hosts']=array_values(array_unique($hosts));$p['hosted_domain']=strtolower(self::text($row['hosted_domain']??'',253,'Hosted domain',true));
                if ($p['vendor']==='google' && ($p['issuer']!=='https://accounts.google.com' || $p['discovery_url']!=='https://accounts.google.com/.well-known/openid-configuration' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D',$p['hosted_domain']))) { throw new \DomainException('Google Workspace requires its exact Google issuer/discovery and an explicit Workspace hosted domain.'); }
                if ($p['vendor']==='entra' && !preg_match('#^https://login\.microsoftonline\.com/[a-fA-F0-9-]{36}/v2\.0$#D',$p['issuer'])) { throw new \DomainException('Entra requires a single tenant UUID issuer; common/organizations tenants are unsupported.'); }
            } else {
                $p['idp_sso_url']=self::url($row['idp_sso_url']??'','SAML sign-in URL');$p['sp_entity_id']=self::text($row['sp_entity_id']??'',2048,'SP entity ID');
                $certs=$row['idp_signing_certificates']??[];
                if (!is_array($certs) || !array_is_list($certs) || !$certs || count($certs)>3) { throw new \DomainException('Pin one through three IdP signing certificates.'); }
                foreach ($certs as $cert) { self::certificate($cert); }$p['idp_signing_certificates']=$certs;
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
            if (!is_array($g) || array_diff(array_keys($g),['provider_id','subject','operator_account_id','enabled']) || !isset($seen[$g['provider_id']??''])
                || !preg_match('/^api_[a-f0-9]{24}$/D',$g['operator_account_id']??'') || !is_bool($g['enabled']??null)) { throw new \DomainException('Invalid explicit enterprise operator binding.'); }
            $g['subject']=self::text($g['subject']??'',512,'Stable provider subject');$k=$g['provider_id']."\0".$g['subject'];
            if (isset($bindings[$k])) { throw new \DomainException('A provider subject can bind to one local account.'); }$bindings[$k]=true;$out['grants'][]=$g;
        }
        return $out;
    }
    private static function pem($value,string $label): string
    {
        if(!is_string($value)||$value===''||strlen($value)>16384||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){throw new \DomainException($label.' must be PEM text.');}return $value;
    }
    private static function certificate($cert): string
    {
        $cert=self::pem($cert,'Signing certificate');$x=openssl_x509_read($cert);$key=$x?openssl_pkey_get_public($x):false;$d=$key?openssl_pkey_get_details($key):false;
        if (!$d || $d['type']!==OPENSSL_KEYTYPE_RSA || $d['bits']<2048) { throw new \DomainException('Pin a PEM RSA signing certificate with at least 2048 bits.'); }return $cert;
    }
    public static function provider(array $settings,string $id): array
    {
        $c=self::normalize($settings['enterprise_identity']??[]);
        if (!$c['enabled'] || empty($settings['operator_access']['portal_enabled'])) { throw new \DomainException('Enterprise operator sign-in is disabled.'); }
        foreach ($c['providers'] as $p) { if ($p['id']===$id && $p['enabled']) { return $p; } }throw new \DomainException('This enterprise provider is unavailable.');
    }
    public static function revision(array $provider): string { return hash('sha256',json_encode($provider,JSON_THROW_ON_ERROR)); }
    public static function publicConfig(array $config): array
    {
        foreach ($config['providers'] as &$p) { foreach (['client_secret','sp_private_key'] as $k) { if (isset($p[$k])) { $configured=$p[$k]!=='';$p[$k]='';$p[$k.'_configured']=$configured; } } }unset($p);return $config;
    }
}
