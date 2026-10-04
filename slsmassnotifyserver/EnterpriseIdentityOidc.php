<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityHttp.php';
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/identity-libs/vendor/autoload.php';
use Firebase\JWT\{JWT,JWK};
use League\OAuth2\Client\Provider\GenericProvider;

/** Authorization-code-only OIDC; maintained libraries build PKCE and verify JWT signatures. */
final class EnterpriseIdentityOidc
{
    private $http;
    public function __construct(?callable $http=null) { $this->http=$http??[EnterpriseIdentityHttp::class,'request']; }
    private function discovery(array $p): array
    {
        $d=($this->http)($p['discovery_url'],$p['endpoint_hosts'],'GET',[],[]);
        if(($d['issuer']??'')!==$p['issuer'] || !in_array('code',$d['response_types_supported']??[],true)
            || !in_array('RS256',$d['id_token_signing_alg_values_supported']??[],true)
            || !in_array($p['token_auth'],$d['token_endpoint_auth_methods_supported']??['client_secret_basic'],true)) { throw new \DomainException('OIDC discovery does not match the registered issuer, code flow or signing/authentication methods.'); }
        // Some conforming servers omit this optional metadata. S256 is always
        // sent, never downgraded; unsupported servers fail at authorization.
        if(isset($d['code_challenge_methods_supported']) && !in_array('S256',$d['code_challenge_methods_supported'],true)){throw new \DomainException('This OIDC provider does not advertise S256 PKCE.');}
        foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $k){EnterpriseIdentityConfig::url($d[$k]??'','OIDC '.$k);if(!in_array(strtolower(parse_url($d[$k],PHP_URL_HOST)),$p['endpoint_hosts'],true)){throw new \DomainException('Discovery returned an unapproved endpoint hostname.');}}
        return $d;
    }
    public function begin(array $p,EnterpriseIdentityStore $store,string $browser,int $now): string
    {
        $d=$this->discovery($p);$nonce=bin2hex(random_bytes(32));
        $provider=new GenericProvider(['clientId'=>$p['client_id'],'redirectUri'=>$p['callback_url'],'urlAuthorize'=>$d['authorization_endpoint'],
            'urlAccessToken'=>$d['token_endpoint'],'urlResourceOwnerDetails'=>$p['issuer'],'scopes'=>['openid','profile','email'],'scopeSeparator'=>' ','pkceMethod'=>'S256']);
        // Build once to obtain the library's verifier, then bind its exact
        // generated state and verifier to one browser and provider revision.
        $url=$provider->getAuthorizationUrl(['nonce'=>$nonce,'response_mode'=>'query']);$verifier=$provider->getPkceCode();
        if(!is_string($verifier)||strlen($verifier)<43){throw new \RuntimeException('OIDC PKCE unavailable.');}
        $state=$store->reserve(['provider_id'=>$p['id'],'revision'=>EnterpriseIdentityConfig::revision($p),'protocol'=>'oidc','nonce'=>$nonce,'verifier'=>$verifier,
            'discovery'=>$d],$browser,$now);
        $query=parse_url($url,PHP_URL_QUERY);parse_str($query,$args);$args['state']=$state;if($p['vendor']==='google'){$args['hd']=$p['hosted_domain'];}
        return $d['authorization_endpoint'].'?'.http_build_query($args,'','&',PHP_QUERY_RFC3986);
    }
    public function finish(array $p,array $tx,string $code,EnterpriseIdentityStore $store,int $now): array
    {
        if(($tx['protocol']??'')!=='oidc'||($tx['provider_id']??'')!==$p['id']||!hash_equals($tx['revision']??'',EnterpriseIdentityConfig::revision($p))
            || !is_string($code)||$code===''||strlen($code)>4096){throw new \DomainException('OIDC transaction expired or its registration changed.');}
        $d=$tx['discovery'];$form=['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$p['callback_url'],'code_verifier'=>$tx['verifier'],'client_id'=>$p['client_id']];$headers=['Accept: application/json'];
        if($p['token_auth']==='client_secret_basic'){$headers[]='Authorization: Basic '.base64_encode(rawurlencode($p['client_id']).':'.rawurlencode($p['client_secret']));}
        elseif($p['token_auth']==='client_secret_post'){$form['client_secret']=$p['client_secret'];}
        $tokens=($this->http)($d['token_endpoint'],$p['endpoint_hosts'],'POST',$form,$headers);
        if(!is_string($tokens['id_token']??null)||strlen($tokens['id_token'])>16384){throw new \DomainException('OIDC token response lacks a bounded ID token.');}
        $jwks=($this->http)($d['jwks_uri'],$p['endpoint_hosts'],'GET',[],[]);
        $claims=self::verify($tokens['id_token'],$jwks,$p,$tx['nonce'],$now);
        $store->replay($p['id']."\0oidc\0".hash('sha256',$tokens['id_token']),$claims['exp']+30,$now);
        return ['provider_id'=>$p['id'],'subject'=>$claims['sub'],'protocol'=>'oidc','authenticated_at'=>$now,'expires_at'=>min($now+28800,$claims['exp'])];
    }
    public static function verify(string $token,array $jwks,array $p,string $nonce,int $now): array
    {
        $parts=explode('.',$token);if(count($parts)!==3){throw new \DomainException('Invalid ID token.');}
        $header=json_decode(base64_decode(strtr($parts[0],'-_','+/'),true)?:'',true,8,JSON_THROW_ON_ERROR);
        if(($header['alg']??'')!=='RS256'||!is_string($header['kid']??null)||$header['kid']===''||isset($header['jku'])||isset($header['x5u'])||isset($header['crit'])){throw new \DomainException('Only provider-pinned RS256 ID tokens are accepted.');}
        if(!isset($jwks['keys'])||!is_array($jwks['keys'])||count($jwks['keys'])>50){throw new \DomainException('Invalid provider key set.');}
        $keys=[];$seen=[];foreach($jwks['keys'] as $key){if(!is_array($key)||!is_string($key['kid']??null)){continue;}if(isset($seen[$key['kid']])){throw new \DomainException('Repeated provider signing key ID.');}$seen[$key['kid']]=true;
            if(($key['kty']??'')==='RSA'&&($key['alg']??'RS256')==='RS256'&&($key['use']??'sig')==='sig'&&(!isset($key['key_ops'])||in_array('verify',$key['key_ops'],true))){$modulus=base64_decode(strtr($key['n']??'','-_','+/'),true);if(!is_string($modulus)||strlen(ltrim($modulus,"\0"))<256){throw new \DomainException('OIDC signing keys require RSA 2048 bits or stronger.');}$key['alg']='RS256';$keys[]=$key;}}
        $oldTime=JWT::$timestamp;$oldLeeway=JWT::$leeway;JWT::$timestamp=$now;JWT::$leeway=30;
        try{$claims=(array)JWT::decode($token,JWK::parseKeySet(['keys'=>$keys],'RS256'));}finally{JWT::$timestamp=$oldTime;JWT::$leeway=$oldLeeway;}
        $aud=$claims['aud']??null;$audiences=is_string($aud)?[$aud]:(is_array($aud)?$aud:[]);
        if(($claims['iss']??'')!==$p['issuer']||!in_array($p['client_id'],$audiences,true)||!$audiences
            || (count($audiences)>1&&($claims['azp']??'')!==$p['client_id'])||(isset($claims['azp'])&&$claims['azp']!==$p['client_id'])
            || !is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce'])||!is_string($claims['sub']??null)||$claims['sub']===''||strlen($claims['sub'])>512
            || !is_int($claims['exp']??null)||!is_int($claims['iat']??null)||$claims['exp']<=$now-30||$claims['exp']>$now+86400||$claims['iat']>$now+30||$claims['iat']<$now-600
            || (isset($claims['nbf'])&&(!is_int($claims['nbf'])||$claims['nbf']>$now+30))){throw new \DomainException('ID token issuer, audience, nonce, subject or time validation failed.');}
        if($p['vendor']==='google'&&($claims['hd']??'')!==$p['hosted_domain']){throw new \DomainException('This identity is outside the configured Google Workspace domain.');}
        return $claims;
    }
}
