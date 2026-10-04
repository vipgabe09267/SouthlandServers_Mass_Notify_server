<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/identity-libs/vendor/autoload.php';
use OneLogin\Saml2\{Auth,Constants,Utils};
use RobRichards\XMLSecLibs\{XMLSecurityKey,XMLSecurityDSig};

/** SP-initiated, signed-assertion SAML via the maintained OneLogin toolkit/xmlseclibs. */
final class EnterpriseIdentitySaml
{
    public static function settings(array $p): array
    {
        return ['strict'=>true,'debug'=>false,'baseurl'=>substr($p['callback_url'],0,strrpos($p['callback_url'],'/')+1),
            'sp'=>['entityId'=>$p['sp_entity_id'],'assertionConsumerService'=>['url'=>$p['callback_url'],'binding'=>Constants::BINDING_HTTP_POST],
                'NameIDFormat'=>$p['nameid_format'],'x509cert'=>$p['sp_certificate'],'privateKey'=>$p['sp_private_key']],
            'idp'=>['entityId'=>$p['issuer'],'singleSignOnService'=>['url'=>$p['idp_sso_url'],'binding'=>Constants::BINDING_HTTP_REDIRECT],
                'x509certMulti'=>['signing'=>$p['idp_signing_certificates']]],
            'security'=>['authnRequestsSigned'=>true,'wantAssertionsSigned'=>true,'wantMessagesSigned'=>false,'wantNameId'=>true,'wantXMLValidation'=>true,
                'requestedAuthnContext'=>false,'rejectUnsolicitedResponsesWithInResponseTo'=>true,'destinationStrictlyMatches'=>true,'allowRepeatAttributeName'=>false,
                'signatureAlgorithm'=>XMLSecurityKey::RSA_SHA256,'digestAlgorithm'=>XMLSecurityDSig::SHA256]];
    }
    public function begin(array $p,EnterpriseIdentityStore $store,string $browser,int $now): string
    {
        Utils::setProxyVars(false);$auth=new Auth(self::settings($p));
        // RelayState is an opaque transaction key, never a redirect target.
        $requestUrl=$auth->login('',[],false,false,true);$requestId=$auth->getLastRequestID();
        $state=$store->reserve(['provider_id'=>$p['id'],'revision'=>EnterpriseIdentityConfig::revision($p),'protocol'=>'saml','request_id'=>$requestId],$browser,$now);
        // RelayState must be included before the Redirect-binding signature.
        // Create the final request through the toolkit, then persist its ID.
        $auth=new Auth(self::settings($p));$url=$auth->login($state,[],false,false,true);$actual=$auth->getLastRequestID();
        $store->transaction('login',static function(array &$rows)use($state,$actual):void{$rows[hash('sha256',$state)]['request_id']=$actual;});return $url;
    }
    public function finish(array $p,array $tx,string $encoded,EnterpriseIdentityStore $store,int $now): array
    {
        if(($tx['protocol']??'')!=='saml'||($tx['provider_id']??'')!==$p['id']||!hash_equals($tx['revision']??'',EnterpriseIdentityConfig::revision($p))
            || strlen($encoded)>131072){throw new \DomainException('SAML transaction expired or its registration changed.');}
        $raw=base64_decode($encoded,true);
        if(!is_string($raw)||strlen($raw)>98304||stripos($raw,'<!DOCTYPE')!==false||stripos($raw,'<!ENTITY')!==false){throw new \DomainException('SAML response has unsafe XML.');}
        $doc=new \DOMDocument();$prior=libxml_use_internal_errors(true);try{$loaded=$doc->loadXML($raw,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        if(!$loaded){throw new \DomainException('SAML response XML invalid.');}if($doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion','EncryptedAssertion')->length){throw new \DomainException('Configure a signed, unencrypted SAML assertion over HTTPS.');}$xp=new \DOMXPath($doc);$xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
        foreach($xp->query('//ds:SignatureMethod|//ds:DigestMethod') as $node){if(!in_array($node->getAttribute('Algorithm'),[XMLSecurityKey::RSA_SHA256,XMLSecurityKey::RSA_SHA384,XMLSecurityKey::RSA_SHA512,XMLSecurityDSig::SHA256,XMLSecurityDSig::SHA384,XMLSecurityDSig::SHA512],true)){throw new \DomainException('SAML requires SHA-256 or stronger RSA signature/digest algorithms.');}}
        Utils::setProxyVars(false);$auth=new Auth(self::settings($p));$old=$_POST;$_POST=['SAMLResponse'=>$encoded];
        try{$auth->processResponse($tx['request_id']);}finally{$_POST=$old;}
        if($auth->getErrors()||!$auth->isAuthenticated()||$auth->getNameIdFormat()!==$p['nameid_format']){throw new \DomainException('SAML signature, request, issuer, audience, destination, time or subject validation failed.');}
        $expiry=$auth->getLastAssertionNotOnOrAfter();$subject=$auth->getNameId();
        if(!is_int($expiry)||$expiry<=$now-30||$expiry>$now+86400||!is_string($subject)||$subject===''||strlen($subject)>512){throw new \DomainException('SAML assertion has invalid subject or expiry.');}
        // Signed Conditions/SubjectConfirmation are checked by the toolkit;
        // impose a stricter freshness limit than its default 180s clock drift.
        $xp->registerNamespace('saml','urn:oasis:names:tc:SAML:2.0:assertion');$xp->registerNamespace('samlp','urn:oasis:names:tc:SAML:2.0:protocol');
        $conditions=$xp->query('/samlp:Response/saml:Assertion/saml:Conditions');if($conditions->length!==1||!$conditions->item(0)->hasAttribute('NotBefore')||!$conditions->item(0)->hasAttribute('NotOnOrAfter')){throw new \DomainException('SAML requires one bounded signed Conditions element.');}
        foreach($xp->query('/samlp:Response/saml:Assertion/saml:Conditions/@NotOnOrAfter|/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData/@NotOnOrAfter|/samlp:Response/saml:Assertion/saml:AuthnStatement/@SessionNotOnOrAfter') as $node){$deadline=strtotime($node->nodeValue);if($deadline===false||$deadline<=$now-30||$deadline>$now+86400){throw new \DomainException('SAML signed expiry is outside the accepted window.');}$expiry=min($expiry,$deadline);}
        foreach($xp->query('/samlp:Response/saml:Assertion/saml:Conditions/@NotBefore|/samlp:Response/saml:Assertion/@IssueInstant|/samlp:Response/@IssueInstant') as $node){$time=strtotime($node->nodeValue);if($time===false||$time>$now+30||$time<$now-600){throw new \DomainException('SAML assertion is outside the freshness window.');}}
        foreach([$auth->getLastMessageId(),$auth->getLastAssertionId()] as $id){if(!is_string($id)||$id===''){throw new \DomainException('SAML response lacks replay identifiers.');}$store->replay($p['id']."\0saml\0".$id,$expiry+180,$now);}
        return ['provider_id'=>$p['id'],'subject'=>$subject,'protocol'=>'saml','authenticated_at'=>$now,'expires_at'=>min($now+28800,$expiry)];
    }
}
