<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIdentityConfig.php';
require_once __DIR__.'/EnterpriseIdentityStore.php';
require_once __DIR__.'/identity-libs/vendor/autoload.php';
use OneLogin\Saml2\{Auth,Constants,Utils};
use RobRichards\XMLSecLibs\{XMLSecEnc,XMLSecurityKey,XMLSecurityDSig};

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
        $state=$store->reserve(['provider_id'=>$p['id'],'revision'=>EnterpriseIdentityConfig::revision($p),'protocol'=>'saml','request_id'=>'pending'], $browser,$now);
        // RelayState must be included before the Redirect-binding signature.
        // Create exactly one final request through the toolkit, then persist its ID.
        $url=$auth->login($state,[],false,false,true);$actual=$auth->getLastRequestID();
        if(!is_string($actual)||$actual===''||strlen($actual)>512){throw new \RuntimeException('SAML request identity is unavailable.');}
        $store->transaction('login',static function(array &$rows)use($state,$actual):void{
            $key=hash('sha256',$state);$row=$rows[$key]??null;
            if(!is_array($row)||($row['protocol']??null)!=='saml'||($row['request_id']??null)!=='pending'){
                throw new \RuntimeException('SAML login reservation is unavailable.');
            }
            $rows[$key]['request_id']=$actual;
        });return $url;
    }
    public function finish(array $p,array $tx,string $encoded,EnterpriseIdentityStore $store,int $now): array
    {
        if(($tx['protocol']??'')!=='saml'||($tx['provider_id']??'')!==$p['id']||!hash_equals($tx['revision']??'',EnterpriseIdentityConfig::revision($p))
            || strlen($encoded)>131072){throw new \DomainException('SAML transaction expired or its registration changed.');}
        if(!preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D',$encoded)){
            throw new \DomainException('SAML response transport encoding is invalid.');
        }
        $raw=base64_decode($encoded,true);
        if(!is_string($raw)||base64_encode($raw)!==$encoded||strlen($raw)>98304||stripos($raw,'<!DOCTYPE')!==false||stripos($raw,'<!ENTITY')!==false){throw new \DomainException('SAML response has unsafe XML.');}
        $doc=new \DOMDocument();$prior=libxml_use_internal_errors(true);try{$loaded=$doc->loadXML($raw,LIBXML_NONET|LIBXML_NOBLANKS|LIBXML_COMPACT);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        if(!$loaded){throw new \DomainException('SAML response XML invalid.');}self::rejectUnsafeXml($doc);
        if($doc->getElementsByTagNameNS(Constants::NS_SAML,'EncryptedAssertion')->length
            || $doc->getElementsByTagNameNS(Constants::NS_SAML,'EncryptedID')->length
            || $doc->getElementsByTagNameNS(XMLSecEnc::XMLENCNS,'EncryptedData')->length){
            throw new \DomainException('Configure a signed, unencrypted SAML assertion over HTTPS.');
        }
        $xp=new \DOMXPath($doc);$xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
        self::validateSignatureProfile($xp,$doc);
        Utils::setProxyVars(false);$auth=new Auth(self::settings($p));$old=$_POST;$_POST=['SAMLResponse'=>$encoded];
        try{$auth->processResponse($tx['request_id']);}finally{$_POST=$old;}
        if($auth->getErrors()||!$auth->isAuthenticated()||$auth->getNameIdFormat()!==$p['nameid_format']){throw new \DomainException('SAML signature, request, issuer, audience, destination, time or subject validation failed.');}
        self::validateBindings($doc,$p,$tx['request_id'],$now);
        $expiry=$auth->getLastAssertionNotOnOrAfter();$subject=$auth->getNameId();
        if(!is_int($expiry)||$expiry<=$now-30||$expiry>$now+86400||!is_string($subject)||$subject===''||strlen($subject)>512||preg_match('/[\x00-\x1f\x7f]/',$subject)){throw new \DomainException('SAML assertion has invalid subject or expiry.');}
        // Signed Conditions/SubjectConfirmation are checked by the toolkit;
        // impose a stricter freshness limit than its default 180s clock drift.
        $xp->registerNamespace('saml','urn:oasis:names:tc:SAML:2.0:assertion');$xp->registerNamespace('samlp','urn:oasis:names:tc:SAML:2.0:protocol');
        $conditions=$xp->query('/samlp:Response/saml:Assertion/saml:Conditions');if($conditions->length!==1||!$conditions->item(0)->hasAttribute('NotBefore')||!$conditions->item(0)->hasAttribute('NotOnOrAfter')){throw new \DomainException('SAML requires one bounded signed Conditions element.');}
        foreach($xp->query('/samlp:Response/saml:Assertion/saml:Conditions/@NotOnOrAfter|/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData/@NotOnOrAfter|/samlp:Response/saml:Assertion/saml:AuthnStatement/@SessionNotOnOrAfter') as $node){$deadline=self::samlTime($node->nodeValue);if($deadline<=$now-30||$deadline>$now+86400){throw new \DomainException('SAML signed expiry is outside the accepted window.');}$expiry=min($expiry,$deadline);}
        foreach($xp->query('/samlp:Response/saml:Assertion/saml:Conditions/@NotBefore|/samlp:Response/saml:Assertion/@IssueInstant|/samlp:Response/@IssueInstant') as $node){$time=self::samlTime($node->nodeValue);if($time>$now+30||$time<$now-600){throw new \DomainException('SAML assertion is outside the freshness window.');}}
        foreach([$auth->getLastMessageId(),$auth->getLastAssertionId()] as $id){if(!is_string($id)||$id===''){throw new \DomainException('SAML response lacks replay identifiers.');}$store->replay($p['id']."\0saml\0".$id,$expiry+180,$now);}
        return ['provider_id'=>$p['id'],'subject'=>$subject,'protocol'=>'saml','authenticated_at'=>$now,'expires_at'=>min($now+28800,$expiry)];
    }

    private static function rejectUnsafeXml(\DOMDocument $doc): void
    {
        if($doc->doctype!==null){throw new \DomainException('SAML responses must not contain a document type or entities.');}
        $nodes=[$doc->documentElement];while($nodes){$node=array_pop($nodes);if(!$node instanceof \DOMNode){continue;}
            if(in_array($node->nodeType,[XML_ENTITY_REF_NODE,XML_ENTITY_NODE,XML_DOCUMENT_TYPE_NODE],true)){throw new \DomainException('SAML responses must not contain a document type or entities.');}
            foreach($node->childNodes as $child){$nodes[]=$child;}
        }
    }

    private static function samlTime($value): int
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D',$value)){
            throw new \DomainException('SAML assertion contains an invalid timestamp.');
        }
        try{$timestamp=Utils::parseSAML2Time($value);}catch(\Throwable $error){throw new \DomainException('SAML assertion contains an invalid timestamp.',0,$error);}
        $seconds=preg_replace('/\.\d+Z$/','Z',$value);
        if(!is_int($timestamp)||gmdate('Y-m-d\TH:i:s\Z',$timestamp)!==$seconds){throw new \DomainException('SAML assertion contains an invalid timestamp.');}
        return $timestamp;
    }

    private static function validateSignatureProfile(\DOMXPath $xpath,\DOMDocument $document): void
    {
        $canonicalization=[XMLSecurityDSig::C14N,XMLSecurityDSig::C14N_COMMENTS,
            XMLSecurityDSig::EXC_C14N,XMLSecurityDSig::EXC_C14N_COMMENTS];
        $signatureAlgorithms=[XMLSecurityKey::RSA_SHA256,XMLSecurityKey::RSA_SHA384,XMLSecurityKey::RSA_SHA512];
        $digestAlgorithms=[XMLSecurityDSig::SHA256,XMLSecurityDSig::SHA384,XMLSecurityDSig::SHA512];
        $xpath->registerNamespace('saml',Constants::NS_SAML);
        $xpath->registerNamespace('samlp',Constants::NS_SAMLP);
        $root=$document->documentElement;
        if(!$root instanceof \DOMElement||$root->namespaceURI!==Constants::NS_SAMLP||$root->localName!=='Response'){
            throw new \DomainException('SAML response must have one protocol Response document element.');
        }
        $assertions=$xpath->query('/samlp:Response/saml:Assertion');
        if(!$assertions instanceof \DOMNodeList||$assertions->length!==1){
            throw new \DomainException('SAML response must contain exactly one direct assertion.');
        }
        $ids=[];$idNodes=$xpath->query('//*[@ID]');
        if(!$idNodes instanceof \DOMNodeList){throw new \DomainException('SAML identity attributes could not be inspected.');}
        foreach($idNodes as $node){
            $id=$node instanceof \DOMElement?$node->getAttribute('ID'):'';
            if($id===''||strlen($id)>512||isset($ids[$id])){throw new \DomainException('SAML element IDs must be nonempty and unique.');}
            $ids[$id]=true;
        }
        $signatures=$xpath->query('//ds:Signature');
        if(!$signatures instanceof \DOMNodeList||$signatures->length<1||$signatures->length>2){
            throw new \DomainException('SAML response must contain a signed assertion and at most one signed response.');
        }
        $assertionSigned=false;$signedParents=[];
        foreach($signatures as $signature){
            $references=$xpath->query('ds:SignedInfo/ds:Reference',$signature);
            $parent=$signature->parentNode;
            $isAssertion=$parent instanceof \DOMElement&&$parent->namespaceURI===Constants::NS_SAML&&$parent->localName==='Assertion';
            $isResponse=$parent instanceof \DOMElement&&$parent->namespaceURI===Constants::NS_SAMLP&&$parent->localName==='Response';
            $parentId=$parent instanceof \DOMElement?$parent->getAttribute('ID'):'';
            if((!$isAssertion&&!$isResponse)||$parentId===''||isset($signedParents[$parentId])
                ||!$references instanceof \DOMNodeList||$references->length!==1
                || $references->item(0)->getAttribute('URI')!=='#'.$parent->getAttribute('ID')){
                throw new \DomainException('SAML signature must reference its complete parent assertion or response.');
            }
            $signedParents[$parentId]=true;if($isAssertion){$assertionSigned=true;}
            $canonicalMethods=$xpath->query('ds:SignedInfo/ds:CanonicalizationMethod',$signature);
            $signatureMethods=$xpath->query('ds:SignedInfo/ds:SignatureMethod',$signature);
            $digestMethods=$xpath->query('ds:DigestMethod',$references->item(0));
            if(!$canonicalMethods instanceof \DOMNodeList||$canonicalMethods->length!==1
                ||!in_array($canonicalMethods->item(0)->getAttribute('Algorithm'),$canonicalization,true)){
                throw new \DomainException('SAML signature requires one supported canonicalization method.');
            }
            if(!$signatureMethods instanceof \DOMNodeList||$signatureMethods->length!==1
                ||!in_array($signatureMethods->item(0)->getAttribute('Algorithm'),$signatureAlgorithms,true)){
                throw new \DomainException('SAML requires an RSA SHA-256 or stronger signature algorithm.');
            }
            if(!$digestMethods instanceof \DOMNodeList||$digestMethods->length!==1
                ||!in_array($digestMethods->item(0)->getAttribute('Algorithm'),$digestAlgorithms,true)){
                throw new \DomainException('SAML requires a SHA-256 or stronger digest algorithm.');
            }
            $transforms=$xpath->query('ds:Transforms/ds:Transform',$references->item(0));
            if(!$transforms instanceof \DOMNodeList||$transforms->length<1||$transforms->length>2
                ||$transforms->item(0)->getAttribute('Algorithm')!=='http://www.w3.org/2000/09/xmldsig#enveloped-signature'){
                throw new \DomainException('SAML signature requires the standard enveloped-signature transform.');
            }
            for($index=1;$index<$transforms->length;$index++){
                $transform=$transforms->item($index);
                $algorithm=$transform->getAttribute('Algorithm');
                if(!in_array($algorithm,$canonicalization,true)){
                    throw new \DomainException('SAML signatures must cover the full assertion without XPath or other filtering transforms.');
                }
            }
        }
        if(!$assertionSigned){throw new \DomainException('SAML assertion must be signed directly.');}
    }

    private static function validateBindings(\DOMDocument $doc,array $provider,string $requestId,int $now): void
    {
        $response=$doc->documentElement;
        if($response->getAttribute('Destination')!==$provider['callback_url']){
            throw new \DomainException('SAML response destination must match the configured operator callback.');
        }
        $xpath=new \DOMXPath($doc);
        $xpath->registerNamespace('samlp',Constants::NS_SAMLP);
        $xpath->registerNamespace('saml',Constants::NS_SAML);
        $restrictions=$xpath->query('/samlp:Response/saml:Assertion/saml:Conditions/saml:AudienceRestriction');
        if($restrictions->length===0){
            throw new \DomainException('SAML assertion requires an audience restriction for this operator portal.');
        }
        // Separate restrictions are ANDed; the audiences within each are alternatives.
        foreach($restrictions as $restriction){
            $matched=false;
            foreach($xpath->query('saml:Audience',$restriction) as $audience){
                if(trim($audience->textContent)===$provider['sp_entity_id']){$matched=true;break;}
            }
            if(!$matched){throw new \DomainException('SAML audience restriction does not permit this operator portal.');}
        }
        foreach($xpath->query('/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation') as $confirmation){
            if($confirmation->getAttribute('Method')!==Constants::CM_BEARER){continue;}
            $data=$xpath->query('saml:SubjectConfirmationData',$confirmation);
            if($data->length!==1){continue;}
            $data=$data->item(0);
            if($data->getAttribute('Recipient')!==$provider['callback_url']
                || $data->getAttribute('InResponseTo')!==$requestId){continue;}
            try{$expires=self::samlTime($data->getAttribute('NotOnOrAfter'));}catch(\DomainException $error){continue;}
            if($expires<=$now-30||$expires>$now+86400){continue;}
            if($data->hasAttribute('NotBefore')){
                try{$begins=self::samlTime($data->getAttribute('NotBefore'));}catch(\DomainException $error){continue;}
                if($begins>$now+30){continue;}
            }
            return;
        }
        throw new \DomainException('SAML assertion requires a valid bearer confirmation bound to this callback and request.');
    }
}
