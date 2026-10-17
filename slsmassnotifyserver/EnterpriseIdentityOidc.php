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
    public const REJECT_TRANSACTION=1101;
    public const REJECT_PROVIDER_RESPONSE=1102;
    public const REJECT_SIGNING_KEY=1103;
    public const REJECT_SIGNATURE=1104;
    public const REJECT_CLAIMS=1105;
    public const REJECT_REPLAY=1106;
    private $http;
    public function __construct(?callable $http=null) { $this->http=$http??[EnterpriseIdentityHttp::class,'request']; }
    private function discovery(array $p): array
    {
        $d=($this->http)($p['discovery_url'],EnterpriseIdentityHttp::networkPolicy($p),'GET',[],[]);
        $responses=$d['response_types_supported']??null;$algorithms=$d['id_token_signing_alg_values_supported']??null;
        $auth=$d['token_endpoint_auth_methods_supported']??['client_secret_basic'];$pkce=$d['code_challenge_methods_supported']??null;
        foreach([$responses,$algorithms,$auth] as $values){if(!is_array($values)||!array_is_list($values)){throw new \DomainException('OIDC discovery contains invalid capability metadata.');}}
        if(($d['issuer']??'')!==$p['issuer'] || !in_array('code',$responses,true)
            || !in_array('RS256',$algorithms,true)
            || !in_array($p['token_auth'],$auth,true)) { throw new \DomainException('OIDC discovery does not match the registered issuer, code flow or signing/authentication methods.'); }
        // Some conforming servers omit this optional metadata. S256 is always
        // sent, never downgraded; unsupported servers fail at authorization.
        if($pkce!==null&&(!is_array($pkce)||!array_is_list($pkce)||!in_array('S256',$pkce,true))){throw new \DomainException('This OIDC provider does not advertise S256 PKCE.');}
        foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $k){EnterpriseIdentityConfig::url($d[$k]??'','OIDC '.$k);if(!in_array(strtolower(parse_url($d[$k],PHP_URL_HOST)),$p['endpoint_hosts'],true)){throw new \DomainException('Discovery returned an unapproved endpoint hostname.');}}
        return array_intersect_key($d,array_flip(['issuer','authorization_endpoint','token_endpoint','jwks_uri']));
    }
    public function begin(array $p,EnterpriseIdentityStore $store,string $browser,int $now): string
    {
        $d=$this->discovery($p);$nonce=bin2hex(random_bytes(32));
        $provider=new GenericProvider(['clientId'=>$p['client_id'],'redirectUri'=>$p['callback_url'],'urlAuthorize'=>$d['authorization_endpoint'],
            'urlAccessToken'=>$d['token_endpoint'],'urlResourceOwnerDetails'=>$p['issuer'],'scopes'=>['openid'],'scopeSeparator'=>' ','pkceMethod'=>'S256']);
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
            || !is_string($code)||$code===''||strlen($code)>4096){throw new \DomainException('OIDC transaction expired or its registration changed.',self::REJECT_TRANSACTION);}
        $d=$tx['discovery'];$form=['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$p['callback_url'],'code_verifier'=>$tx['verifier'],'client_id'=>$p['client_id']];$headers=['Accept: application/json'];
        if($p['token_auth']==='client_secret_basic'){$headers[]='Authorization: Basic '.base64_encode(rawurlencode($p['client_id']).':'.rawurlencode($p['client_secret']));}
        elseif($p['token_auth']==='client_secret_post'){$form['client_secret']=$p['client_secret'];}
        $tokens=($this->http)($d['token_endpoint'],EnterpriseIdentityHttp::networkPolicy($p),'POST',$form,$headers);
        if(!is_string($tokens['id_token']??null)||strlen($tokens['id_token'])>16384){throw new \DomainException('OIDC token response lacks a bounded ID token.',self::REJECT_PROVIDER_RESPONSE);}
        $jwks=($this->http)($d['jwks_uri'],EnterpriseIdentityHttp::networkPolicy($p),'GET',[],[]);
        $claims=self::verify($tokens['id_token'],$jwks,$p,$tx['nonce'],$now);
        try{$store->replay($p['id']."\0oidc\0".hash('sha256',$tokens['id_token']),$claims['exp']+30,$now);}
        catch(\DomainException $error){throw new \DomainException('This authentication assertion was already used.',self::REJECT_REPLAY,$error);}
        return ['provider_id'=>$p['id'],'subject'=>$claims['sub'],'protocol'=>'oidc','authenticated_at'=>$now,'expires_at'=>min($now+28800,$claims['exp'])];
    }
    public static function verify(string $token,array $jwks,array $p,string $nonce,int $now): array
    {
        $parts=explode('.',$token);if(count($parts)!==3){throw new \DomainException('Invalid ID token.',self::REJECT_SIGNATURE);}
        $headerRaw=self::base64url($parts[0]);$claimsRaw=self::base64url($parts[1]);$signature=self::base64url($parts[2]);
        if(strlen($token)>16384||strlen($signature)<256||strlen($signature)>1024){throw new \DomainException('Invalid or oversized ID token.',self::REJECT_SIGNATURE);}
        try{$header=ApiSecurity::jsonObject($headerRaw,false,8);ApiSecurity::jsonObject($claimsRaw,false,32);}
        catch(\JsonException|\InvalidArgumentException $error){throw new \DomainException('ID token contains invalid or ambiguous JSON.',self::REJECT_SIGNATURE,$error);}
        if(($header['alg']??'')!=='RS256'||!is_string($header['kid']??null)||$header['kid']===''||strlen($header['kid'])>128||preg_match('/[\x00-\x20\x7f]/',$header['kid'])
            || isset($header['jku'])||isset($header['x5u'])||isset($header['crit'])){throw new \DomainException('Only provider-pinned RS256 ID tokens are accepted.',self::REJECT_SIGNATURE);}
        if(!isset($jwks['keys'])||!is_array($jwks['keys'])||!array_is_list($jwks['keys'])||count($jwks['keys'])>50){throw new \DomainException('Invalid provider key set.',self::REJECT_SIGNING_KEY);}
        $keys=[];$seen=[];foreach($jwks['keys'] as $key){if(!is_array($key)||!is_string($key['kid']??null)||$key['kid']===''||strlen($key['kid'])>128||preg_match('/[\x00-\x20\x7f]/',$key['kid'])){throw new \DomainException('Provider signing key IDs are invalid.',self::REJECT_SIGNING_KEY);}if(isset($seen[$key['kid']])){throw new \DomainException('Repeated provider signing key ID.',self::REJECT_SIGNING_KEY);}$seen[$key['kid']]=true;if(!hash_equals($header['kid'],$key['kid'])){continue;}
            if(($key['kty']??'')!=='RSA'||($key['alg']??'RS256')!=='RS256'||($key['use']??'sig')!=='sig'||(isset($key['key_ops'])&&(!is_array($key['key_ops'])||!array_is_list($key['key_ops'])||!in_array('verify',$key['key_ops'],true)))){throw new \DomainException('The selected OIDC key is not an RS256 verification key.',self::REJECT_SIGNING_KEY);}
            try{$modulus=self::base64url($key['n']??'');$exponent=self::base64url($key['e']??'');}
            catch(\DomainException $error){throw new \DomainException('OIDC signing key uses invalid integer encoding.',self::REJECT_SIGNING_KEY,$error);}
            if($modulus===''||$modulus[0]==="\0"||!(ord($modulus[strlen($modulus)-1])&1)){throw new \DomainException('OIDC RSA modulus is not canonical.',self::REJECT_SIGNING_KEY);}
            $first=ord($modulus[0]);$bits=(strlen($modulus)-1)*8;while($first){$bits++;$first>>=1;}if($bits<2048||$bits>8192){throw new \DomainException('OIDC signing keys require RSA 2048–8192 bits.',self::REJECT_SIGNING_KEY);}
            if($exponent===''||strlen($exponent)>4||$exponent[0]==="\0") { throw new \DomainException('OIDC RSA exponent is not canonical.',self::REJECT_SIGNING_KEY); }$exponentValue=0;foreach(str_split($exponent) as $byte){$exponentValue=($exponentValue<<8)|ord($byte);}if($exponentValue<3||$exponentValue>2147483647||!($exponentValue&1)){throw new \DomainException('OIDC RSA exponent is outside the safe range.',self::REJECT_SIGNING_KEY);}
            $key['alg']='RS256';$keys[]=$key;}
        if(count($keys)!==1){throw new \DomainException('The ID token signing key is unavailable or ambiguous.',self::REJECT_SIGNING_KEY);}
        $oldTime=JWT::$timestamp;$oldLeeway=JWT::$leeway;JWT::$timestamp=$now;JWT::$leeway=30;
        try{try{$claims=(array)JWT::decode($token,JWK::parseKeySet(['keys'=>$keys],'RS256'));}catch(\Throwable $error){throw new \DomainException('ID token signature or signing key validation failed.',self::REJECT_SIGNATURE,$error);}}finally{JWT::$timestamp=$oldTime;JWT::$leeway=$oldLeeway;}
        $aud=$claims['aud']??null;$audiences=is_string($aud)?[$aud]:(is_array($aud)?$aud:[]);
        $audiencesValid=$audiences!==[]&&count($audiences)<=16&&array_is_list($audiences)
            &&count(array_filter($audiences,static fn($value):bool=>is_string($value)&&$value!==''&&strlen($value)<=512))===count($audiences)
            &&count(array_unique($audiences,SORT_STRING))===count($audiences);
        if(($claims['iss']??'')!==$p['issuer']||!$audiencesValid||!in_array($p['client_id'],$audiences,true)
            || (count($audiences)>1&&($claims['azp']??'')!==$p['client_id'])||(isset($claims['azp'])&&$claims['azp']!==$p['client_id'])
            || !is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce'])||!is_string($claims['sub']??null)||$claims['sub']===''||strlen($claims['sub'])>512||preg_match('/[\x00-\x1f\x7f]/',$claims['sub'])
            || !is_int($claims['exp']??null)||!is_int($claims['iat']??null)||$claims['exp']<=$now-30||$claims['exp']>$now+86400||$claims['iat']>$now+30||$claims['iat']<$now-600
            || (isset($claims['nbf'])&&(!is_int($claims['nbf'])||$claims['nbf']>$now+30))){throw new \DomainException('ID token issuer, audience, nonce, subject or time validation failed.',self::REJECT_CLAIMS);}
        if($p['vendor']==='google'&&($claims['hd']??'')!==$p['hosted_domain']){throw new \DomainException('This identity is outside the configured Google Workspace domain.',self::REJECT_CLAIMS);}
        return $claims;
    }
    private static function base64url($value): string
    {
        if(!is_string($value)||$value===''||strlen($value)%4===1||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)){throw new \DomainException('ID token or signing key uses invalid base64url encoding.',self::REJECT_SIGNATURE);}
        $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
        if(!is_string($decoded)||!hash_equals($value,rtrim(strtr(base64_encode($decoded),'+/','-_'),'='))){throw new \DomainException('ID token or signing key uses noncanonical base64url encoding.',self::REJECT_SIGNATURE);}return $decoded;
    }

    public static function rejectionReason(\Throwable $error): string
    {
        return match($error->getCode()){
            self::REJECT_TRANSACTION=>'transaction',self::REJECT_PROVIDER_RESPONSE=>'provider_response',
            self::REJECT_SIGNING_KEY=>'signing_key',self::REJECT_SIGNATURE=>'signature',
            self::REJECT_CLAIMS=>'claims',self::REJECT_REPLAY=>'replay',default=>'provider_response'};
    }
}
