<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';

/** CAP 1.2 authoring and FEMA-documented CAP/WS-Security signing primitives. */
final class EnterprisePublicWarning
{
    public const CAP='urn:oasis:names:tc:emergency:cap:1.2';
    private const DS='http://www.w3.org/2000/09/xmldsig#';
    private const EXC='http://www.w3.org/2001/10/xml-exc-c14n#';
    private const SOAP='http://schemas.xmlsoap.org/soap/envelope/';
    private const WSU='http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const WSSE='http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private static function node(\DOMElement $parent,string $name,string $value,string $namespace=self::CAP): \DOMElement
    {
        $node=$parent->ownerDocument->createElementNS($namespace,$name); $node->appendChild($parent->ownerDocument->createTextNode($value)); $parent->appendChild($node); return $node;
    }
    private static function choice($value,array $choices,string $label): string
    {
        if (!is_string($value) || !in_array($value,$choices,true)) { throw new \InvalidArgumentException('Choose a valid CAP '.$label.'.'); } return $value;
    }
    private static function at($value,string $label): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) { throw new \InvalidArgumentException($label.' requires an ISO timestamp with timezone.'); }
        $date=new \DateTimeImmutable($value); $errors=\DateTimeImmutable::getLastErrors(); if ($errors && ($errors['warning_count'] || $errors['error_count'])) { throw new \InvalidArgumentException($label.' is invalid.'); }
        return $date->getTimestamp();
    }
    public static function author(array $input,int $now): array
    {
        $input=IncidentConfig::object($input,['identifier','sender','sent','status','msg_type','references','language','category','event','urgency','severity','certainty','expires','headline','description','instruction','area_description','polygon','geocodes','event_code','ipaws_profile'],'Public warning');
        if (isset($input['ipaws_profile']) && !is_bool($input['ipaws_profile'])) { throw new \InvalidArgumentException('IPAWS profile selection must be a boolean.'); }
        $identifier=IncidentConfig::identifier($input['identifier']??'','/^[A-Za-z0-9_.:-]{1,128}$/D','CAP identifier');
        $sender=IncidentConfig::identifier($input['sender']??'','/^[^\s,<>]{1,128}$/uD','CAP sender');
        $sent=self::at($input['sent']??'','CAP sent time'); $expires=self::at($input['expires']??'','CAP expiry');
        if (abs($sent-$now)>300 || $expires<=$sent || $expires>$sent+86400) { throw new \InvalidArgumentException('Public warnings require a current sent time and expiry within 24 hours.'); }
        $status=self::choice($input['status']??'Test',['Actual','Test','Exercise'],'status'); $type=self::choice($input['msg_type']??'Alert',['Alert','Update','Cancel'],'message type');
        $references=[]; foreach (IncidentConfig::listOf($input['references']??[],20,'CAP references') as $ref) {
            $ref=IncidentConfig::object($ref,['sender','identifier','sent'],'CAP reference');
            $refSender=IncidentConfig::identifier($ref['sender']??'','/^[^\s,<>]{1,128}$/uD','Reference sender');
            if ($refSender!==$sender) { throw new \InvalidArgumentException('Updates and cancellations must reference the same sender.'); }
            $refId=IncidentConfig::identifier($ref['identifier']??'','/^[A-Za-z0-9_.:-]{1,128}$/D','Reference identifier'); self::at($ref['sent']??'','Reference sent time');
            $references[]=$refSender.','.$refId.','.$ref['sent'];
        }
        if (($type!=='Alert' && !$references) || ($type==='Alert' && $references)) { throw new \InvalidArgumentException('Update/Cancel require references; a new Alert must not claim references.'); }
        $document=new \DOMDocument('1.0','UTF-8'); $alert=$document->createElementNS(self::CAP,'alert'); $document->appendChild($alert);
        foreach (['identifier'=>$identifier,'sender'=>$sender,'sent'=>$input['sent'],'status'=>$status,'msgType'=>$type,'scope'=>'Public'] as $key=>$value) { self::node($alert,$key,$value); }
        if (($input['ipaws_profile']??false)===true) { self::node($alert,'code','IPAWSv1.0'); }
        if ($references) { self::node($alert,'references',implode(' ',$references)); }
        $info=self::node($alert,'info','');
        self::node($info,'language',IncidentConfig::identifier($input['language']??'en-US','/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D','CAP language'));
        foreach (['category'=>self::choice($input['category']??'Safety',['Geo','Met','Safety','Security','Rescue','Fire','Health','Env','Transport','Infra','CBRNE','Other'],'category'),
            'event'=>IncidentConfig::text($input['event']??'',100,'CAP event'),
            'urgency'=>self::choice($input['urgency']??'Immediate',['Immediate','Expected','Future','Past','Unknown'],'urgency'),
            'severity'=>self::choice($input['severity']??'Severe',['Extreme','Severe','Moderate','Minor','Unknown'],'severity'),
            'certainty'=>self::choice($input['certainty']??'Observed',['Observed','Likely','Possible','Unlikely','Unknown'],'certainty')] as $key=>$value) { self::node($info,$key,$value); }
        $eventCode=$input['event_code']??'';
        if ($eventCode!=='') { $code=self::node($info,'eventCode',''); self::node($code,'valueName','SAME'); self::node($code,'value',IncidentConfig::identifier($eventCode,'/^[A-Z]{3}$/D','SAME event code')); }
        self::node($info,'expires',$input['expires']);
        foreach (['headline'=>160,'description'=>4000,'instruction'=>2000] as $key=>$limit) { self::node($info,$key,IncidentConfig::text($input[$key]??'',$limit,'Warning '.$key,$key==='instruction')); }
        $area=self::node($info,'area',''); self::node($area,'areaDesc',IncidentConfig::text($input['area_description']??'',1000,'Warning area description'));
        $polygon=$input['polygon']??'';
        if ($polygon!=='') {
            if (!is_string($polygon) || strlen($polygon)>8192) { throw new \InvalidArgumentException('CAP polygon exceeds its bound.'); }
            $points=preg_split('/\s+/',trim($polygon));
            if (count($points)<4 || count($points)>100 || $points[0]!==end($points)) { throw new \InvalidArgumentException('CAP polygons need 4–100 coordinates and a matching closing point.'); }
            foreach ($points as $point) {
                if (!preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/D',$point,$parts) || abs((float)$parts[1])>90 || abs((float)$parts[2])>180) { throw new \InvalidArgumentException('CAP polygon coordinates must be latitude,longitude in range.'); }
            } self::node($area,'polygon',implode(' ',$points));
        }
        foreach (IncidentConfig::listOf($input['geocodes']??[],50,'CAP geocodes') as $value) {
            $code=self::node($area,'geocode',''); self::node($code,'valueName','SAME'); self::node($code,'value',IncidentConfig::identifier($value,'/^[0-9]{6}$/D','SAME area code'));
        }
        if ($polygon==='' && !($input['geocodes']??[])) { throw new \InvalidArgumentException('Choose an explicit polygon or SAME geocodes for the warning area.'); }
        $xml=$document->saveXML();
        return ['identifier'=>$identifier,'sender'=>$sender,'status'=>$status,'expires_at'=>$expires,'xml'=>$xml,'sha256'=>hash('sha256',$xml),
            'detail'=>'CAP 1.2 authoring export. Channel-specific IPAWS validation and authorization remain required.'];
    }
    private static function keys(array $config,int $now): array
    {
        $certificate=@openssl_x509_read($config['certificate_pem']??''); $key=@openssl_pkey_get_private($config['private_key_pem']??'',$config['private_key_password']??'');
        $meta=$certificate?openssl_x509_parse($certificate):false; $details=$key?openssl_pkey_get_details($key):false;
        if (!$certificate || !$key || !is_array($meta) || !is_array($details) || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048
            || !openssl_x509_check_private_key($certificate,$key) || ($meta['validFrom_time_t']??PHP_INT_MAX)>$now || ($meta['validTo_time_t']??0)<=$now
            || ($config['cog_id']??'')==='' || !str_contains((string)($meta['subject']['CN']??''),$config['cog_id'])) {
            throw new \DomainException('IPAWS needs a current matching RSA certificate/key whose CN contains the authorized COG identifier.');
        }
        openssl_x509_export($certificate,$pem); $der=preg_replace('/-----[^-]+-----|\s/','',$pem);
        return [$key,$der];
    }
    private static function signature(\DOMElement $target,\DOMElement $parent,string $reference,$key,string $certificate,bool $enveloped): \DOMElement
    {
        $document=$parent->ownerDocument; $digest=base64_encode(hash('sha256',$target->C14N(true,false),true));
        $sig=$document->createElementNS(self::DS,'ds:Signature'); $parent->appendChild($sig); $info=self::node($sig,'ds:SignedInfo','',self::DS);
        self::node($info,'ds:CanonicalizationMethod','',self::DS)->setAttribute('Algorithm',self::EXC);
        self::node($info,'ds:SignatureMethod','',self::DS)->setAttribute('Algorithm','http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $ref=self::node($info,'ds:Reference','',self::DS); $ref->setAttribute('URI',$reference);
        $transforms=self::node($ref,'ds:Transforms','',self::DS);
        if ($enveloped) { self::node($transforms,'ds:Transform','',self::DS)->setAttribute('Algorithm',self::DS.'enveloped-signature'); }
        self::node($transforms,'ds:Transform','',self::DS)->setAttribute('Algorithm',self::EXC);
        self::node($ref,'ds:DigestMethod','',self::DS)->setAttribute('Algorithm','http://www.w3.org/2001/04/xmlenc#sha256'); self::node($ref,'ds:DigestValue',$digest,self::DS);
        if (!openssl_sign($info->C14N(true,false),$bytes,$key,OPENSSL_ALGO_SHA256)) { throw new \RuntimeException('IPAWS XML signature could not be created.'); }
        self::node($sig,'ds:SignatureValue',base64_encode($bytes),self::DS); $ki=self::node($sig,'ds:KeyInfo','',self::DS);
        $data=self::node($ki,'ds:X509Data','',self::DS); self::node($data,'ds:X509Certificate',$certificate,self::DS); return $sig;
    }
    public static function signCap(string $xml,array $config,int $now): string
    {
        [$key,$certificate]=self::keys($config,$now); $document=new \DOMDocument();
        if (strlen($xml)>65536 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml) || !@$document->loadXML($xml,LIBXML_NONET)
            || $document->documentElement->namespaceURI!==self::CAP || $document->documentElement->localName!=='alert') { throw new \InvalidArgumentException('Provide one bounded CAP 1.2 alert.'); }
        if ($document->getElementsByTagNameNS(self::DS,'Signature')->length) { throw new \InvalidArgumentException('The draft already contains a signature.'); }
        self::signature($document->documentElement,$document->documentElement,'',$key,$certificate,true); return $document->saveXML();
    }
    /** A fully documented getAck wire request, useful for authorized CDTE qualification. */
    public static function acknowledgementEnvelope(array $config,int $now): string
    {
        [$key,$certificate]=self::keys($config,$now); $document=new \DOMDocument('1.0','UTF-8');
        $envelope=$document->createElementNS(self::SOAP,'soap:Envelope'); $document->appendChild($envelope);
        $header=self::node($envelope,'soap:Header','',self::SOAP); $identity=self::node($header,'ipaws:CAPHeaderTypeDef','','http://gov.fema.ipaws.services/IPAWS_CAPService/');
        self::node($identity,'ipaws:logonUser',IncidentConfig::text($config['logon_user']??'',100,'IPAWS logon user'),'http://gov.fema.ipaws.services/IPAWS_CAPService/');
        self::node($identity,'ipaws:logonCogId',$config['cog_id'],'http://gov.fema.ipaws.services/IPAWS_CAPService/');
        $security=self::node($header,'wsse:Security','',self::WSSE); $security->setAttributeNS(self::SOAP,'soap:mustUnderstand','1');
        $binary=self::node($security,'wsse:BinarySecurityToken',$certificate,self::WSSE); $binary->setAttributeNS(self::WSU,'wsu:Id','certificate');
        $binary->setAttribute('EncodingType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
        $binary->setAttribute('ValueType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $body=self::node($envelope,'soap:Body','',self::SOAP); $body->setAttributeNS(self::WSU,'wsu:Id','body');
        $request=self::node($body,'ipaws:getRequestTypeDef','','http://gov.fema.ipaws.services/IPAWS_CAPService/');
        self::node($request,'req:requestAPI','REQUEST1','http://gov.fema.ipaws.services/caprequest'); self::node($request,'req:requestOperation','getAck','http://gov.fema.ipaws.services/caprequest');
        self::signature($body,$security,'#body',$key,$certificate,false); return $document->saveXML();
    }
    /** Validate an authority's WSDL-generated signed postCAP envelope without inventing its wrapper. */
    public static function verifyPreparedEnvelope(string $envelope,string $draft,array $config,int $now): void
    {
        self::keys($config,$now); $document=new \DOMDocument();
        if (strlen($envelope)>131072 || preg_match('/<!DOCTYPE|<!ENTITY/i',$envelope) || !@$document->loadXML($envelope,LIBXML_NONET)
            || $document->documentElement->namespaceURI!==self::SOAP || $document->documentElement->localName!=='Envelope') { throw new \InvalidArgumentException('Provide a bounded signed IPAWS SOAP envelope generated from the authority-issued WSDL.'); }
        $xpath=new \DOMXPath($document); foreach (['s'=>self::SOAP,'c'=>self::CAP,'d'=>self::DS,'u'=>self::WSU,'w'=>self::WSSE,'i'=>'http://gov.fema.ipaws.services/IPAWS_CAPService/'] as $name=>$namespace) { $xpath->registerNamespace($name,$namespace); }
        $bodies=$xpath->query('/s:Envelope/s:Body'); $headers=$xpath->query('/s:Envelope/s:Header'); $alerts=$xpath->query('/s:Envelope/s:Body//c:alert');
        if ($bodies->length!==1 || $headers->length!==1 || $alerts->length!==1
            || $xpath->query('//c:alert')->length!==1 || $xpath->query('//s:Body')->length!==1 || $xpath->query('//d:Signature')->length!==2
            || $xpath->evaluate('string(/s:Envelope/s:Header/i:CAPHeaderTypeDef/i:logonCogId)')!==($config['cog_id']??'')
            || $xpath->evaluate('string(/s:Envelope/s:Header/i:CAPHeaderTypeDef/i:logonUser)')!==($config['logon_user']??'')) { throw new \DomainException('IPAWS envelope identity, alert count or signed message structure is invalid.'); }
        $body=$bodies->item(0); $alert=$alerts->item(0); $bodyId=$body->getAttributeNS(self::WSU,'Id');
        if ($bodyId==='' || $xpath->query('//*[@u:Id]')->length!==count(array_unique(array_map(static fn($node)=>$node->getAttributeNS(self::WSU,'Id'),iterator_to_array($xpath->query('//*[@u:Id]')))))) { throw new \DomainException('IPAWS signing identifiers are missing or ambiguous.'); }
        $capSignatures=$xpath->query('./d:Signature',$alert); $soapSignatures=$xpath->query('/s:Envelope/s:Header/w:Security/d:Signature');
        if ($capSignatures->length!==1 || $soapSignatures->length!==1) { throw new \DomainException('IPAWS requires both alert and SOAP security signatures.'); }
        $verify=static function(\DOMElement $signature,\DOMElement $target,string $reference,bool $enveloped) use($xpath,$config): void {
            $info=$xpath->query('./d:SignedInfo',$signature); $refs=$xpath->query('./d:SignedInfo/d:Reference',$signature);
            if ($info->length!==1 || $refs->length!==1 || $refs->item(0)->getAttribute('URI')!==$reference
                || $xpath->evaluate('string(./d:SignedInfo/d:CanonicalizationMethod/@Algorithm)',$signature)!==self::EXC
                || $xpath->evaluate('string(./d:SignedInfo/d:SignatureMethod/@Algorithm)',$signature)!=='http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                || $xpath->evaluate('string(./d:DigestMethod/@Algorithm)',$refs->item(0))!=='http://www.w3.org/2001/04/xmlenc#sha256') { throw new \DomainException('IPAWS signature algorithm/reference is unsupported.'); }
            $transforms=[]; foreach ($xpath->query('./d:Transforms/d:Transform',$refs->item(0)) as $node) { $transforms[]=$node->getAttribute('Algorithm'); }
            if ($transforms!==($enveloped?[self::DS.'enveloped-signature',self::EXC]:[self::EXC])) { throw new \DomainException('IPAWS signature transforms are unsupported.'); }
            $clone=$target->cloneNode(true);
            if ($enveloped) { foreach (iterator_to_array($clone->childNodes) as $child) { if ($child instanceof \DOMElement && $child->namespaceURI===self::DS && $child->localName==='Signature') { $clone->removeChild($child); } } }
            $canonical=$enveloped?$clone->C14N(true,false):$target->C14N(true,false); if ($canonical===false || $canonical==='') { $copy=new \DOMDocument(); $copy->appendChild($copy->importNode($clone,true)); $canonical=$copy->documentElement->C14N(true,false); }
            $digest=base64_encode(hash('sha256',$canonical,true));
            if (!hash_equals($digest,$xpath->evaluate('string(./d:DigestValue)',$refs->item(0)))
                || openssl_verify($info->item(0)->C14N(true,false),base64_decode($xpath->evaluate('string(./d:SignatureValue)',$signature),true)?:'',openssl_x509_read($config['certificate_pem']),OPENSSL_ALGO_SHA256)!==1) {
                throw new \DomainException('IPAWS '.($enveloped?'alert':'SOAP').' signature or signed payload is invalid.');
            }
        };
        $verify($capSignatures->item(0),$alert,'',true); $verify($soapSignatures->item(0),$body,'#'.$bodyId,false);
        $copy=new \DOMDocument(); $copy->appendChild($copy->importNode($alert,true));
        foreach (iterator_to_array($copy->documentElement->childNodes) as $child) { if ($child instanceof \DOMElement && $child->namespaceURI===self::DS) { $copy->documentElement->removeChild($child); } }
        $expected=new \DOMDocument(); if (!@$expected->loadXML($draft,LIBXML_NONET) || !hash_equals(hash('sha256',$expected->documentElement->C14N(true,false)),hash('sha256',$copy->documentElement->C14N(true,false)))) { throw new \DomainException('Signed IPAWS alert differs from the reviewed CAP draft.'); }
    }
}
