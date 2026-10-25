<?php
declare(strict_types=1);
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentityOidc.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentitySaml.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseIdentity.php';
require_once __DIR__.'/../slsmassnotifyserver/DirectorySync.php';
require_once __DIR__.'/../slsmassnotifyserver/SubscriberService.php';
require_once __DIR__.'/../slsmassnotifyserver/IncidentService.php';
use SLS\MassNotify\{EnterpriseIdentity,EnterpriseIdentityConfig,EnterpriseIdentityHttp,EnterpriseIdentityStore,EnterpriseIdentityOidc,EnterpriseIdentitySaml,DirectoryConfig,DirectorySync,SubscriberConfig,SubscriberService,IncidentConfig,IncidentStore,IncidentService};
use Firebase\JWT\JWT;
use OneLogin\Saml2\{Constants,Utils};
use RobRichards\XMLSecLibs\{XMLSecurityKey,XMLSecurityDSig};
function check(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}}
function identityRejectedBy(callable $fn,string $why,array $allowed,bool $exact=false):void
{
    try{$fn();}
    catch(Throwable $error){
        foreach($allowed as $class){if(($exact&&get_class($error)===$class)||(!$exact&&$error instanceof $class)){check($error->getMessage()!=='',$why.' The rejection lacked a diagnostic.');return;}}
        throw new RuntimeException($why.' Unexpected exception: '.get_class($error).'.',0,$error);
    }
    throw new RuntimeException($why);
}
function reject(callable $fn,string $why):void{identityRejectedBy($fn,$why,[DomainException::class,InvalidArgumentException::class]);}
function rejectStorage(callable $fn,string $why):void{identityRejectedBy($fn,$why,[RuntimeException::class],true);}
function b64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function identityRead(string $path):string{$bytes=file_get_contents($path);check(is_string($bytes),'Unable to read identity fixture evidence: '.$path);return $bytes;}
function identityWrite(string $path,string $bytes,?int $mode=null):void{$written=file_put_contents($path,$bytes,LOCK_EX);check($written===strlen($bytes),'Unable to write complete identity fixture evidence: '.$path);if($mode!==null){check(chmod($path,$mode),'Unable to protect identity fixture evidence: '.$path);}}
function identityRemove(string $path):void{check(unlink($path),'Unable to remove identity fixture evidence: '.$path);}
function identityRename(string $from,string $to):void{check(rename($from,$to),'Unable to rename identity fixture evidence.');}
function identityRestoreGlobals(array $post,array $server):void
{
    $_POST=$post;
    foreach($server as $key=>$saved){if($saved['present']){$_SERVER[$key]=$saved['value'];}else{unset($_SERVER[$key]);}}
}
function identityCleanup(string $root,bool $strict):void
{
    if(!file_exists($root)&&!is_link($root)){return;}
    if(!is_dir($root)||is_link($root)){if($strict){throw new RuntimeException('Unsafe identity fixture cleanup root.');}return;}
    try{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);}
    catch(Throwable $error){if($strict){throw $error;}return;}
    foreach($it as $file){$path=$file->getPathname();$removed=$file->isDir()&&!$file->isLink()?rmdir($path):unlink($path);if(!$removed&&$strict){throw new RuntimeException('Unable to remove identity fixture evidence.');}}
    if(!rmdir($root)&&$strict){throw new RuntimeException('Unable to remove the identity fixture root.');}
}
$root=sys_get_temp_dir().'/sls-identity-'.bin2hex(random_bytes(8));$cleanupComplete=false;
$originalPost=$_POST;$originalServer=[];foreach(['HTTP_HOST','SERVER_PORT','HTTPS','SCRIPT_NAME','REQUEST_URI'] as $serverKey){$originalServer[$serverKey]=['present'=>array_key_exists($serverKey,$_SERVER),'value'=>$_SERVER[$serverKey]??null];}$globalsRestored=false;
register_shutdown_function(static function()use($root,&$cleanupComplete,$originalPost,$originalServer,&$globalsRestored):void{if(!$globalsRestored){identityRestoreGlobals($originalPost,$originalServer);}if(!$cleanupComplete){identityCleanup($root,false);}});
check(mkdir($root,0700)&&chmod($root,0700),'Unable to create the private identity fixture root.');
$now=time();$store=new EnterpriseIdentityStore($root.'/state');$browser=str_repeat('b',64);
check(\ParagonIE\ConstantTime\RFC4648::base32HexEncode('foo')==='CPNMU===','Vendored RFC 4648 Base32-Hex encoder dispatched to the ordinary alphabet.');
check(\ParagonIE\ConstantTime\RFC4648::base32HexDecode('CPNMU===')==='foo','Vendored RFC 4648 Base32-Hex decoder dispatched to the ordinary alphabet.');
$referenceFixture=new DOMDocument();
check($referenceFixture->loadXML('<Root><Exact Id="foo&quot;bar"/><Filtered Id="foobar"/><ds:Reference xmlns:ds="http://www.w3.org/2000/09/xmldsig#" URI="#foo&quot;bar"/></Root>'),'Unable to parse the exact-reference fixture.');
$referenceNode=$referenceFixture->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS,'Reference')->item(0);
check($referenceNode instanceof DOMElement,'Exact-reference fixture lacks its Reference node.');
$referenceVerifier=new class extends XMLSecurityDSig { public function resolveForTest(DOMElement $node):array{return $this->resolveReferenceData($node);} };
$referenceRejected=false;try{$referenceVerifier->resolveForTest($referenceNode);}catch(Exception $expected){$referenceRejected=true;}
check($referenceRejected,'A quoted Reference URI was rewritten and resolved to a different element.');
$partialDocument=new DOMDocument();
check($partialDocument->loadXML('<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo><ds:Reference URI="#first"/><ds:Reference URI="#second"/></ds:SignedInfo></ds:Signature>'),'Unable to parse the partial-reference fixture.');
$partialVerifier=new class extends XMLSecurityDSig { private int $calls=0; public function processRefNode($node){$this->calls++;if($this->calls===1){$this->validatedNodes['first']=$node;return true;}throw new Exception('Synthetic second-reference failure.');} };
$partialVerifier->sigNode=$partialDocument->documentElement;$partialRejected=false;try{$partialVerifier->validateReference();}catch(Exception $expected){$partialRejected=true;}
check($partialRejected&&$partialVerifier->getValidatedNodes()===null,'Failed multi-reference validation exposed a partial validated-node set.');
$verifyDocumentVerifier=new class extends XMLSecurityDSig { public function locateSignature($document,$position=0){return $this->sigNode;} public function canonicalizeSignedInfo(){return 'fixture';} public function validateReference(){$this->validatedNodes=['fixture'=>$this->sigNode];return true;} public function verify($key){return 0;} };
$verifyDocumentVerifier->sigNode=$partialDocument->documentElement;$fixtureVerificationKey=new XMLSecurityKey(XMLSecurityKey::RSA_SHA256,['type'=>'public']);$documentRejected=false;
try{$verifyDocumentVerifier->verifyDocument($fixtureVerificationKey,$partialDocument);}catch(Exception $expected){$documentRejected=true;}
check($documentRejected&&$verifyDocumentVerifier->getValidatedNodes()===null,'Failed document signature verification exposed validated reference nodes.');
check(!EnterpriseIdentityConfig::normalize([])['enabled']&&!DirectoryConfig::normalize([])['enabled']&&!SubscriberConfig::normalize([])['enabled'],'Features enabled by default');
foreach (['https://idp.example.test/sign"in','https://idp.example.test/a b','https://idp.example.test/\\signin',
    'https://idp.example.test/<signin>','https://idp.example.test/%','https://idp.example.test/%0','https://idp.example.test/%zz'] as $invalidUrl) {
    try { EnterpriseIdentityConfig::url($invalidUrl,'Sign-in URL'); throw new RuntimeException('Malformed IdP URL accepted'); }
    catch (DomainException $expected) { check(str_contains($expected->getMessage(),'invalid URL characters'),'Malformed URL diagnostic was unclear'); }
}
foreach (['https://idp.example.test/signin','https://idp.example.test/a%20b','https://idp.example.test/%22',
    'https://idp.example.test:8443/tenant/signin','https://idp.example.test/100%25'] as $validUrl) {
    check(EnterpriseIdentityConfig::url($validUrl,'Sign-in URL')===$validUrl,'Valid encoded IdP URL was changed');
}
$originSettings=['control_api'=>['base_url'=>'https://pbx.example.test:8443/api/sls-mass-notify']];
check(EnterpriseIdentityConfig::expectedCallback($originSettings)==='https://pbx.example.test:8443/mass-notify/sso.php','Compatibility port was lost from SSO callback');
EnterpriseIdentityConfig::assertCallbackRequest($originSettings,['HTTPS'=>'on','HTTP_HOST'=>'PBX.EXAMPLE.TEST:8443','REQUEST_URI'=>'/mass-notify/sso.php?code=fixture','HTTP_X_FORWARDED_HOST'=>'attacker.example']);
$defaultOrigin=['control_api'=>['base_url'=>'https://pbx.example.test/api/sls-mass-notify']];
EnterpriseIdentityConfig::assertCallbackRequest($defaultOrigin,['REQUEST_SCHEME'=>'https','HTTP_HOST'=>'PBX.EXAMPLE.TEST:443','REQUEST_URI'=>'/mass-notify/sso.php']);
foreach([
 ['HTTPS'=>'on','HTTP_HOST'=>'attacker.example','REQUEST_URI'=>'/mass-notify/sso.php'],
 ['HTTPS'=>'on','HTTP_HOST'=>'pbx.example.test','REQUEST_URI'=>'/mass-notify/sso.php'],
 ['HTTPS'=>'on','HTTP_HOST'=>'pbx.example.test:8443','REQUEST_URI'=>'/other/sso.php'],
 ['HTTP_HOST'=>'pbx.example.test:8443','REQUEST_URI'=>'/mass-notify/sso.php'],
] as $request){reject(fn()=>EnterpriseIdentityConfig::assertCallbackRequest($originSettings,$request),'Foreign SSO callback request origin/path accepted');}
foreach(['localhost','127.0.0.1','idp..example','-idp.example','idp.example.','123.456'] as $badHost){reject(fn()=>EnterpriseIdentityConfig::hostname($badHost),'Invalid provider DNS hostname accepted: '.$badHost);}
$loginRecord=['provider_id'=>'idp_'.str_repeat('f',24),'revision'=>str_repeat('f',64),'protocol'=>'saml','request_id'=>'ONELOGIN_'.str_repeat('f',40)];
$state=$store->reserve($loginRecord,$browser,$now);check($store->take($state,$browser,$now)['provider_id']===$loginRecord['provider_id'],'Valid browser transaction rejected');check(isset($store->take($state,$browser,$now)['invalid']),'Replayed transaction accepted');
$poisonedLogin=array_replace($loginRecord,['browser'=>str_repeat('0',64),'created_at'=>$now-1000,'expires_at'=>$now-700]);
$state=$store->reserve($poisonedLogin,$browser,$now);check($store->take($state,$browser,$now)['provider_id']===$loginRecord['provider_id'],'Caller-controlled transaction metadata replaced the store CSRF or lifetime binding.');
$state=$store->reserve($loginRecord,$browser,$now);check(isset($store->take($state,str_repeat('c',64),$now)['invalid']),'Browser CSRF binding bypass');check(isset($store->take($state,$browser,$now)['invalid']),'Rejected browser attempt did not consume transaction');
$state=$store->reserve($loginRecord,$browser,$now);check(isset($store->take($state,$browser,$now+301)['invalid']),'Expired state accepted');
$store->replay('assertion', $now+60,$now);reject(fn()=>$store->replay('assertion',$now+60,$now),'Assertion replay accepted');
reject(fn()=>$store->replay('already-expired',$now,$now),'An already-expired replay identity was admitted.');
if(!function_exists('SLS\\MassNotify\\fwrite')){
    eval('namespace SLS\\MassNotify; function fwrite($stream,$data,$length=null){$meta=\\stream_get_meta_data($stream);$limit=!empty($GLOBALS["sls_identity_force_short_write"])&&\\str_contains((string)($meta["uri"]??""),"/replay.json.")?31:$length;if($limit===null){return \\fwrite($stream,$data);}return \\fwrite($stream,$data,\\min((int)$limit,\\strlen($data)));}');
}
$GLOBALS['sls_identity_force_short_write']=true;$store->replay('short-write',$now+60,$now);$GLOBALS['sls_identity_force_short_write']=false;
reject(fn()=>$store->replay('short-write',$now+60,$now),'A short-write replay record was not persisted completely.');
// Lost/corrupt ledger must fail closed and preserve evidence.
$raw=identityRead($root.'/state/replay.json');identityRemove($root.'/state/replay.json');rejectStorage(fn()=>$store->replay('assertion',$now+60,$now),'Missing ledger silently reset');identityWrite($root.'/state/replay.json',$raw,0600);
identityWrite($root.'/state/replay.json','{"duplicate":1,"duplicate":2}',0600);rejectStorage(fn()=>$store->replay('new',$now+60,$now),'Duplicate JSON keys accepted');identityWrite($root.'/state/replay.json',$raw,0600);
$target=$root.'/outside';identityWrite($target,'unchanged',0600);check(symlink($target,$root.'/state/unsafe.lock'),'Unable to create the identity symlink fixture.');rejectStorage(fn()=>$store->transaction('unsafe',static fn(&$r)=>null),'Symlink lock accepted');check(identityRead($target)==='unchanged','Symlink target touched');
check(function_exists('posix_mkfifo')&&posix_mkfifo($root.'/state/fifo.lock',0600),'Unable to create the identity FIFO fixture.');$began=microtime(true);rejectStorage(fn()=>$store->transaction('fifo',static fn(&$r)=>null),'FIFO lock accepted');check(microtime(true)-$began<1,'FIFO opened and blocked');
$other=new EnterpriseIdentityStore($root.'/other');identityRename($root.'/other',$root.'/other-lost');rejectStorage(fn()=>new EnterpriseIdentityStore($root.'/other'),'Deleted established state was recreated');identityRename($root.'/other-lost',$root.'/other');
$limits=new EnterpriseIdentityStore($root.'/limits');$limits->transaction('test_rows',static fn(array &$rows)=>null);$limitPath=$root.'/limits/test_rows.json';$before=identityRead($limitPath);
rejectStorage(fn()=>$limits->transaction('test_rows',static function(array &$rows):void{for($i=0;$i<=20000;$i++){$rows['row_'.str_pad((string)$i,5,'0',STR_PAD_LEFT)]=$i;}}),'Over-limit callback committed a damaged enterprise ledger');
check(identityRead($limitPath)===$before&&$limits->read('test_rows')===[],'Rejected over-limit ledger mutation changed prior bytes');
$limits->reserve($loginRecord,$browser,$now-1000);check($limits->read('login')===[],'Read-time compaction retained an expired PKCE transaction');
$limits->transaction('replay',static function(array &$rows)use($now):void{$rows[hash('sha256','expired-assertion')]=$now-1;$rows[hash('sha256','active-assertion')]=$now+60;});$compacted=$limits->compact($now);
check($compacted['replay']===1&&count($limits->read('replay'))===1,'Scheduled compaction removed live replay evidence or retained expired evidence');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);check($key!==false,'Unable to create the identity fixture key.');$private='';check(openssl_pkey_export($key,$private)&&$private!=='','Unable to export the identity fixture key.');$details=openssl_pkey_get_details($key);check(is_array($details)&&isset($details['rsa']['n'],$details['rsa']['e']),'Unable to inspect the identity fixture key.');
$csr=openssl_csr_new(['commonName'=>'Fixture IdP'],$key,['digest_alg'=>'sha256']);check($csr!==false,'Unable to create the identity fixture CSR.');$cert=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256']);check($cert!==false,'Unable to sign the identity fixture certificate.');$pem='';check(openssl_x509_export($cert,$pem)&&$pem!=='','Unable to export the identity fixture certificate.');
$jwks=['keys'=>[['kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>'fixture','n'=>b64($details['rsa']['n']),'e'=>b64($details['rsa']['e'])]]];
$tokenClaims=['iss'=>'https://idp.example.test','aud'=>'fixture-client','sub'=>'immutable-42','nonce'=>str_repeat('n',64),'iat'=>$now,'exp'=>$now+300];
$p=['id'=>'idp_'.str_repeat('a',24),'vendor'=>'okta','protocol'=>'oidc','name'=>'Fixture','enabled'=>true,'issuer'=>'https://idp.example.test','callback_url'=>'https://pbx.example.test/mass-notify/sso.php',
    'discovery_url'=>'https://idp.example.test/.well-known/openid-configuration','client_id'=>'fixture-client','client_secret'=>'fixture-secret','token_auth'=>'client_secret_basic','endpoint_hosts'=>['idp.example.test'],'hosted_domain'=>''];
$p=EnterpriseIdentityConfig::normalize(['providers'=>[$p]])['providers'][0];$validToken=JWT::encode($tokenClaims,$private,'RS256','fixture');
$providerSettings=['control_api'=>['base_url'=>'https://pbx.example.test/api/sls-mass-notify'],
    'enterprise_identity'=>['schema'=>1,'enabled'=>true,'providers'=>[$p],'grants'=>[]],
    'operator_access'=>['schema'=>1,'enabled'=>false,'portal_enabled'=>true,'accounts'=>[]]];
check(EnterpriseIdentityConfig::provider($providerSettings,$p['id'])['id']===$p['id'],'A valid enabled enterprise provider was unavailable.');
foreach([false,'1',1,[],['invalid']] as $portalEnabled){
    $candidate=$providerSettings;$candidate['operator_access']['portal_enabled']=$portalEnabled;
    try{EnterpriseIdentityConfig::provider($candidate,$p['id']);throw new RuntimeException('Malformed or disabled portal state enabled enterprise sign-in.');}
    catch(DomainException $error){check($error->getMessage()!=='','Portal-state refusal was missing its diagnostic.');}
}
reject(fn()=>EnterpriseIdentityConfig::bindCallbacks(['providers'=>[$p]],['control_api'=>['base_url'=>'https://other.example.test/api/sls-mass-notify']]),'Foreign registered callback accepted');
$badProvider=$p;$badProvider['endpoint_hosts']=['IDP.EXAMPLE.TEST','idp.example.test'];reject(fn()=>EnterpriseIdentityConfig::normalize(['providers'=>[$badProvider]]),'Canonical duplicate endpoint hosts accepted');
$badProvider=$p;$badProvider['endpoint_hosts']=['other.example.test'];reject(fn()=>EnterpriseIdentityConfig::normalize(['providers'=>[$badProvider]]),'Discovery host outside allowlist accepted');
$badProvider=$p;$badProvider['vendor']='google';$badProvider['issuer']='https://accounts.google.com';$badProvider['discovery_url']=$badProvider['issuer'].'/.well-known/openid-configuration';$badProvider['endpoint_hosts']=['accounts.google.com'];$badProvider['hosted_domain']='bad..example';reject(fn()=>EnterpriseIdentityConfig::normalize(['providers'=>[$badProvider]]),'Malformed hosted domain accepted');
check(EnterpriseIdentityHttp::pinnedAddress('idp.example.test',['hosts'=>['idp.example.test'],'private_pins'=>[]],fn()=>['8.8.8.8'])==='8.8.8.8','Public provider address was not pinned');
foreach(['0.0.0.1','10.0.0.1','100.64.0.1','127.0.0.1','169.254.1.1','172.16.0.1','192.0.0.1','192.0.2.1','192.31.196.1','192.52.193.1','192.88.99.1','192.168.1.1','192.175.48.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','240.0.0.1','255.255.255.255','::','::1','::ffff:127.0.0.1','64:ff9b::a00:1','100::1','2001::1','2001:db8::1','2002:0a00:1::','3fff::1','fc00::1','fe80::1','ff00::1'] as $blocked){reject(fn()=>EnterpriseIdentityHttp::pinnedAddress('idp.example.test',['hosts'=>['idp.example.test'],'private_pins'=>[]],fn()=>[$blocked]),'Special-use provider DNS answer accepted: '.$blocked);}
reject(fn()=>EnterpriseIdentityHttp::pinnedAddress('idp.example.test',['hosts'=>['idp.example.test'],'private_pins'=>[]],fn()=>['8.8.8.8','10.0.0.4']),'Mixed public/private provider DNS answers accepted');
check(EnterpriseIdentityHttp::pinnedAddress('idp.example.test',['hosts'=>['idp.example.test'],'private_pins'=>[]],fn()=>['2606:4700:4700::1111'])==='2606:4700:4700::1111','Public IPv6 provider address was rejected');
$privatePolicy=['hosts'=>['idp.example.test'],'private_pins'=>[['host'=>'idp.example.test','ip'=>'10.20.30.40']]];
check(EnterpriseIdentityHttp::pinnedAddress('idp.example.test',$privatePolicy,fn()=>['127.0.0.1'])==='10.20.30.40','Exact private endpoint pin was not authoritative');
reject(fn()=>EnterpriseIdentityConfig::normalize(['providers'=>[array_replace($p,['private_endpoint_pins'=>[['host'=>'idp.example.test','ip'=>'127.0.0.1']]])]]),'Loopback private endpoint pin accepted');
check(EnterpriseIdentityOidc::verify($validToken,$jwks,$p,$tokenClaims['nonce'],$now)['sub']==='immutable-42','Valid signed OIDC token rejected');
foreach([['iss'=>'https://other.example.test'],['aud'=>'other-client'],['nonce'=>'other'],['exp'=>$now-90],['iat'=>$now+90],['iat'=>$now-601],['sub'=>''],['aud'=>['fixture-client','another'],'azp'=>'another'],['aud'=>['primary'=>'fixture-client']],['aud'=>['fixture-client',[]]],['aud'=>['fixture-client','fixture-client']],['exp'=>(string)($now+300)],['nbf'=>$now+90]] as $bad){$claims=array_replace($tokenClaims,$bad);reject(fn()=>EnterpriseIdentityOidc::verify(JWT::encode($claims,$private,'RS256','fixture'),$jwks,$p,$tokenClaims['nonce'],$now),'Bad OIDC claim accepted: '.json_encode($bad));}
reject(fn()=>EnterpriseIdentityOidc::verify(JWT::encode($tokenClaims,'fixture-secret','HS256','fixture'),$jwks,$p,$tokenClaims['nonce'],$now),'HMAC algorithm confusion accepted');
reject(fn()=>EnterpriseIdentityOidc::verify($validToken,['keys'=>[$jwks['keys'][0],$jwks['keys'][0]]],$p,$tokenClaims['nonce'],$now),'Duplicate kid accepted');
$nonmatching=['kty'=>'RSA','kid'=>'other','n'=>'!','e'=>'!'];check(EnterpriseIdentityOidc::verify($validToken,['keys'=>[$nonmatching,$jwks['keys'][0]]],$p,$tokenClaims['nonce'],$now)['sub']==='immutable-42','Malformed nonmatching provider key affected selected key');
foreach([
 array_replace($jwks['keys'][0],['n'=>b64("\0".$details['rsa']['n'])]),
 array_replace($jwks['keys'][0],['e'=>b64("\x02")]),
 array_replace($jwks['keys'][0],['kid'=>str_repeat('k',129)]),
] as $badKey){reject(fn()=>EnterpriseIdentityOidc::verify($validToken,['keys'=>[$badKey]],$p,$tokenClaims['nonce'],$now),'Invalid matching JWK accepted');}
$tampered=substr($validToken,0,-1).($validToken[-1]==='A'?'B':'A');try{EnterpriseIdentityOidc::verify($tampered,$jwks,$p,$tokenClaims['nonce'],$now);throw new RuntimeException('Tampered token accepted');}
catch(DomainException $expected){check(EnterpriseIdentityOidc::rejectionReason($expected)==='signature','Invalid signature received the wrong private audit category');$store->auditRejection($p['id'],'oidc',EnterpriseIdentityOidc::rejectionReason($expected),$now);}
$identityAudit=$store->read('identity_audit');$rejection=end($identityAudit);check($rejection['kind']==='enterprise_identity_rejected'&&$rejection['reason']==='signature'&&!isset($rejection['operator_account_id']),'Private rejection audit stored unsafe or incorrect fields');
$identityAuditPath=$root.'/state/identity_audit.json';$identityAuditBefore=identityRead($identityAuditPath);
rejectStorage(fn()=>$store->auditRejection($p['id'],'oidc','raw_exception_text',$now),'Arbitrary identity rejection detail entered the private audit ledger');
check(identityRead($identityAuditPath)===$identityAuditBefore,'Rejected identity audit detail changed prior evidence');
$store->auditRejection($p['id'],'saml','assertion',$now);$identityAudit=$store->read('identity_audit');check(end($identityAudit)['reason']==='assertion','Bounded SAML rejection category was not retained');
foreach(['header','claims'] as $ambiguousPart){
 $header=json_encode(['alg'=>'RS256','kid'=>'fixture'],JSON_THROW_ON_ERROR);
 $payload=json_encode($tokenClaims,JSON_THROW_ON_ERROR);
 if($ambiguousPart==='header'){$header='{"alg":"HS256","alg":"RS256","kid":"fixture"}';}
 else{$payload=substr($payload,0,-1).',"sub":"immutable-42"}';}
 $signed=b64($header).'.'.b64($payload);$signature='';check(openssl_sign($signed,$signature,$private,OPENSSL_ALGO_SHA256)&&$signature!=='','Unable to sign the ambiguous-token fixture.');
 reject(fn()=>EnterpriseIdentityOidc::verify($signed.'.'.b64($signature),$jwks,$p,$tokenClaims['nonce'],$now),'Repeated signed token JSON accepted: '.$ambiguousPart);
}

foreach(EnterpriseIdentityConfig::VENDORS as $vendor){$vp=$p;$vp['vendor']=$vendor;if($vendor==='entra'){$vp['issuer']='https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0';$vp['discovery_url']=$vp['issuer'].'/.well-known/openid-configuration';$vp['endpoint_hosts']=['login.microsoftonline.com'];}
    elseif($vendor==='google'){$vp['issuer']='https://accounts.google.com';$vp['discovery_url']=$vp['issuer'].'/.well-known/openid-configuration';$vp['endpoint_hosts']=['accounts.google.com'];$vp['hosted_domain']='example.test';}
    elseif($vendor==='onelogin'){$vp['token_auth']='none';$vp['client_secret']='';}
    $vp=EnterpriseIdentityConfig::normalize(['providers'=>[$vp]])['providers'][0];$host=$vp['endpoint_hosts'][0];$d=['issuer'=>$vp['issuer'],'authorization_endpoint'=>'https://'.$host.'/authorize','token_endpoint'=>'https://'.$host.'/token','jwks_uri'=>'https://'.$host.'/keys','response_types_supported'=>['code'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>[$vp['token_auth']],'code_challenge_methods_supported'=>['S256']];$issuedClaims=null;$captured=null;
    $http=static function($url,$hosts,$method,$form,$headers)use($d,$vp,$jwks,$private,&$issuedClaims,&$captured){if($url===$vp['discovery_url']){return $d;}if($url===$d['token_endpoint']){$captured=compact('url','method','form','headers');return ['id_token'=>JWT::encode($issuedClaims,$private,'RS256','fixture')];}if($url===$d['jwks_uri']){return $jwks;}throw new UnexpectedValueException('Unexpected fixture HTTP');};
    $adapter=new EnterpriseIdentityOidc($http);$url=$adapter->begin($vp,$store,$browser,$now);parse_str(parse_url($url,PHP_URL_QUERY),$q);check($q['response_type']==='code'&&$q['code_challenge_method']==='S256'&&$q['scope']==='openid','Code, scope or PKCE downgrade');$tx=$store->take($q['state'],$browser,$now);check(b64(hash('sha256',$tx['verifier'],true))===$q['code_challenge'],'PKCE verifier not bound');check(array_keys($tx['discovery'])===['issuer','authorization_endpoint','token_endpoint','jwks_uri'],'Unneeded discovery metadata entered the login transaction ledger');
    $issuedClaims=array_replace($tokenClaims,['iss'=>$vp['issuer'],'nonce'=>$tx['nonce']]);if($vendor==='google'){$issuedClaims['hd']='example.test';}$proof=$adapter->finish($vp,$tx,'fixture-code',$store,$now);
    check($proof['subject']==='immutable-42'&&is_array($captured)&&$captured['url']===$d['token_endpoint']&&$captured['method']==='POST'&&$captured['form']['code_verifier']===$tx['verifier'],'Provider authorization code/PKCE completion failed: '.$vendor);
    $basic='Authorization: Basic '.base64_encode(rawurlencode($vp['client_id']).':'.rawurlencode($vp['client_secret']));
    if($vp['token_auth']==='client_secret_basic'){check(in_array($basic,$captured['headers'],true)&&!isset($captured['form']['client_secret']),'OIDC basic client authentication changed: '.$vendor);}
    elseif($vp['token_auth']==='client_secret_post'){check(($captured['form']['client_secret']??null)===$vp['client_secret']&&!in_array($basic,$captured['headers'],true),'OIDC form client authentication changed: '.$vendor);}
    else{check(!isset($captured['form']['client_secret'])&&!in_array($basic,$captured['headers'],true),'OIDC public client unexpectedly sent a secret: '.$vendor);}
    reject(fn()=>$adapter->finish($vp,$tx,'fixture-code',$store,$now),'ID token replay accepted');
}
$validDiscovery=['issuer'=>$p['issuer'],'authorization_endpoint'=>'https://idp.example.test/authorize','token_endpoint'=>'https://idp.example.test/token','jwks_uri'=>'https://idp.example.test/keys','response_types_supported'=>['code'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>[$p['token_auth']],'code_challenge_methods_supported'=>['S256']];
foreach(['response_types_supported','id_token_signing_alg_values_supported','token_endpoint_auth_methods_supported','code_challenge_methods_supported'] as $field){$badDiscovery=$validDiscovery;$badDiscovery[$field]='not-a-list';$adapter=new EnterpriseIdentityOidc(static fn()=>$badDiscovery);reject(fn()=>$adapter->begin($p,$store,$browser,$now),'Malformed OIDC discovery capability accepted: '.$field);}
reject(fn()=>EnterpriseIdentityHttp::request('http://idp.example.test',['idp.example.test']),'Non-TLS endpoint accepted');reject(fn()=>EnterpriseIdentityHttp::request('https://other.example.test',['idp.example.test']),'Discovery SSRF hostname accepted');
// SAML positive signature plus wrapping/issuer/audience/request/time fixtures.
$sp=['id'=>$p['id'],'vendor'=>'okta','protocol'=>'saml','name'=>'SAML Fixture','enabled'=>true,'issuer'=>'https://idp.example.test/saml','callback_url'=>$p['callback_url'],'idp_sso_url'=>'https://idp.example.test/sso','sp_entity_id'=>'https://pbx.example.test/mass-notify','idp_signing_certificates'=>[$pem],'sp_certificate'=>$pem,'sp_private_key'=>$private,'nameid_format'=>Constants::NAMEID_PERSISTENT];
$sp=EnterpriseIdentityConfig::normalize(['providers'=>[$sp]])['providers'][0];foreach(['HTTP_HOST'=>'pbx.example.test','SERVER_PORT'=>443,'HTTPS'=>'on','SCRIPT_NAME'=>'/mass-notify/sso.php','REQUEST_URI'=>'/mass-notify/sso.php'] as $serverKey=>$serverValue){$_SERVER[$serverKey]=$serverValue;}
$samlTime=new ReflectionMethod(EnterpriseIdentitySaml::class,'samlTime');$samlTime->setAccessible(true);check($samlTime->invoke(null,'2026-10-01T00:00:00.123Z')===strtotime('2026-10-01T00:00:00Z'),'Canonical fractional SAML timestamp rejected');foreach(['tomorrow','2026-02-30T00:00:00Z','2026-10-01 00:00:00','2026-10-01T00:00:00+00:00'] as $badTime){reject(fn()=>$samlTime->invoke(null,$badTime),'Invalid SAML timestamp accepted');}
$certValidator=new ReflectionMethod(EnterpriseIdentityConfig::class,'certificate');$certValidator->setAccessible(true);reject(fn()=>$certValidator->invoke(null,$pem,$now+259200),'Expired SAML certificate accepted at runtime');reject(fn()=>$certValidator->invoke(null,$pem,$now-86400),'Not-yet-valid SAML certificate accepted at runtime');
$opensslConfig=$root.'/openssl-key-usage.cnf';identityWrite($opensslConfig,"[ req ]\ndistinguished_name = dn\n[ dn ]\n[ v3 ]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,keyEncipherment\n",0600);
$badUsage=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256','config'=>$opensslConfig,'x509_extensions'=>'v3']);check($badUsage!==false,'Unable to create key-usage fixture');$badUsagePem='';check(openssl_x509_export($badUsage,$badUsagePem)&&$badUsagePem!=='','Unable to export the key-usage fixture.');reject(fn()=>$certValidator->invoke(null,$badUsagePem,$now),'SAML certificate without digital-signature usage accepted');
$duplicateCerts=$sp;$duplicateCerts['idp_signing_certificates']=[$pem,$pem];reject(fn()=>EnterpriseIdentityConfig::normalize(['providers'=>[$duplicateCerts]]),'Duplicate SAML rollover certificate pins accepted');
function samlFixture(array $sp,string $private,string $cert,string $request,int $time,array $changes=[]):string {
 $a=['issuer'=>$sp['issuer'],'audience'=>$sp['sp_entity_id'],'request'=>$request,'recipient'=>$sp['callback_url'],'expiry'=>$time+300,'subject'=>'stable-saml-42'];$a=array_replace($a,$changes);$e=static fn($x)=>htmlspecialchars((string)$x,ENT_XML1|ENT_QUOTES);$at=gmdate('Y-m-d\TH:i:s\Z',$time);$before=gmdate('Y-m-d\TH:i:s\Z',$time-5);$after=gmdate('Y-m-d\TH:i:s\Z',$a['expiry']);
 $assertion='<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_assertion'.bin2hex(random_bytes(8)).'" Version="2.0" IssueInstant="'.$at.'"><saml:Issuer>'.$e($a['issuer']).'</saml:Issuer><saml:Subject><saml:NameID Format="'.$sp['nameid_format'].'">'.$e($a['subject']).'</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData InResponseTo="'.$e($a['request']).'" NotOnOrAfter="'.$after.'" Recipient="'.$e($a['recipient']).'"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="'.$before.'" NotOnOrAfter="'.$after.'"><saml:AudienceRestriction><saml:Audience>'.$e($a['audience']).'</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="'.$at.'" SessionIndex="_session"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement></saml:Assertion>';
 if(isset($changes['omit_confirmation_attribute'])){
  $attribute=$changes['omit_confirmation_attribute'];
  $replaced=preg_replace('/ '.preg_quote($attribute,'/').'="[^"]*"/','',$assertion);check(is_string($replaced),'Unable to remove the SAML confirmation fixture attribute.');$assertion=$replaced;
 }
 if(!empty($changes['omit_audience'])){$replaced=preg_replace('/<saml:AudienceRestriction>.*?<\/saml:AudienceRestriction>/','',$assertion);check(is_string($replaced),'Unable to remove the SAML audience fixture.');$assertion=$replaced;}
 if(isset($changes['additional_restriction'])){
  $assertion=str_replace('</saml:Conditions>','<saml:AudienceRestriction><saml:Audience>'.$e($changes['additional_restriction']).'</saml:Audience></saml:AudienceRestriction></saml:Conditions>',$assertion);
 }
 $signed=Utils::addSign($assertion,$private,$cert,XMLSecurityKey::RSA_SHA256,XMLSecurityDSig::SHA256);check(is_string($signed)&&$signed!=='','Unable to sign the SAML assertion fixture.');$doc=new DOMDocument();check($doc->loadXML($signed),'Unable to parse the signed SAML assertion fixture.');$signature=$doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#','Signature')->item(0);$issuer=$doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion','Issuer')->item(0);check($doc->documentElement instanceof DOMElement&&$signature instanceof DOMNode&&$issuer instanceof DOMNode,'Signed SAML assertion fixture lacks required nodes.');$doc->documentElement->removeChild($signature);$doc->documentElement->insertBefore($signature,$issuer->nextSibling);$serialized=$doc->saveXML($doc->documentElement);check(is_string($serialized)&&$serialized!=='','Unable to serialize the signed SAML assertion fixture.');$signed=$serialized;
 return '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_response'.bin2hex(random_bytes(8)).'" Version="2.0" IssueInstant="'.$at.'" Destination="'.$sp['callback_url'].'" InResponseTo="'.$e($a['request']).'"><saml:Issuer>'.$e($a['issuer']).'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'.$signed.'</samlp:Response>';
}
$saml=new EnterpriseIdentitySaml();$url=$saml->begin($sp,$store,$browser,$now);parse_str(parse_url($url,PHP_URL_QUERY),$q);check(isset($q['Signature'])&&$q['SigAlg']===XMLSecurityKey::RSA_SHA256,'SAML AuthnRequest not signed');$tx=$store->take($q['RelayState'],$browser,$now);$xml=samlFixture($sp,$private,$pem,$tx['request_id'],$now);
$profileMethod=new ReflectionMethod(EnterpriseIdentitySaml::class,'validateSignatureProfile');$profileMethod->setAccessible(true);
$checkProfile=static function(string $source)use($profileMethod):void{$document=new DOMDocument();check($document->loadXML($source,LIBXML_NONET|LIBXML_NOBLANKS|LIBXML_COMPACT),'Unable to parse SAML signature-profile fixture.');$xpath=new DOMXPath($document);$xpath->registerNamespace('ds',XMLSecurityDSig::XMLDSIGNS);$profileMethod->invoke(null,$xpath,$document);};
$rejectProfile=static function(string $source,string $why)use($checkProfile):void{reject(static fn()=>$checkProfile($source),$why);};
$checkProfile($xml);
$duplicateIdDocument=new DOMDocument();check($duplicateIdDocument->loadXML($xml),'Unable to parse duplicate SAML ID fixture.');$duplicateAssertion=$duplicateIdDocument->getElementsByTagNameNS(Constants::NS_SAML,'Assertion')->item(0);check($duplicateAssertion instanceof DOMElement,'Duplicate-ID fixture lacks its assertion.');$duplicateId=$duplicateAssertion->getAttribute('ID');$duplicateNode=$duplicateIdDocument->createElementNS(Constants::NS_SAML,'saml:Attribute');$duplicateNode->setAttribute('ID',$duplicateId);$duplicateIdDocument->documentElement->appendChild($duplicateNode);$duplicateIdXml=$duplicateIdDocument->saveXML();check(is_string($duplicateIdXml),'Unable to serialize duplicate SAML ID fixture.');$rejectProfile($duplicateIdXml,'Duplicate SAML ID accepted by the signature profile.');
$signatureConfusionCount=0;$signatureConfusion=str_replace('Algorithm="'.XMLSecurityKey::RSA_SHA256.'"','Algorithm="'.XMLSecurityDSig::SHA256.'"',$xml,$signatureConfusionCount);check($signatureConfusionCount===1,'Unable to build signature-algorithm confusion fixture.');$rejectProfile($signatureConfusion,'Digest URI accepted as a SAML signature algorithm.');
$digestConfusionCount=0;$digestConfusion=str_replace('Algorithm="'.XMLSecurityDSig::SHA256.'"','Algorithm="'.XMLSecurityKey::RSA_SHA256.'"',$xml,$digestConfusionCount);check($digestConfusionCount===1,'Unable to build digest-algorithm confusion fixture.');$rejectProfile($digestConfusion,'Signature URI accepted as a SAML digest algorithm.');
$misplacedSignatureDocument=new DOMDocument();check($misplacedSignatureDocument->loadXML($xml),'Unable to parse misplaced SAML signature fixture.');$misplacedAssertion=$misplacedSignatureDocument->getElementsByTagNameNS(Constants::NS_SAML,'Assertion')->item(0);$misplacedSignature=$misplacedSignatureDocument->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS,'Signature')->item(0);check($misplacedAssertion instanceof DOMElement&&$misplacedSignature instanceof DOMElement,'Misplaced-signature fixture lacks required nodes.');$misplacedContainer=$misplacedSignatureDocument->createElementNS(Constants::NS_SAML,'saml:Attribute');$misplacedContainer->setAttribute('ID','_misplaced');$misplacedAssertion->appendChild($misplacedContainer);$misplacedContainer->appendChild($misplacedSignature);$misplacedSignatureXml=$misplacedSignatureDocument->saveXML();check(is_string($misplacedSignatureXml),'Unable to serialize misplaced SAML signature fixture.');$rejectProfile($misplacedSignatureXml,'Signature under an arbitrary SAML element passed the signature profile.');
reject(fn()=>$saml->finish($sp,$tx," \t".base64_encode($xml)."\n",$store,$now),'Non-canonical SAML transport Base64 accepted.');
$hostileKeyInfoDocument=new DOMDocument();check($hostileKeyInfoDocument->loadXML($xml),'Unable to parse the hostile-KeyInfo fixture.');$hostileSignature=$hostileKeyInfoDocument->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS,'Signature')->item(0);check($hostileSignature instanceof DOMElement,'Hostile-KeyInfo fixture lacks its signature.');$hostileKeyInfo=$hostileKeyInfoDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS,'ds:KeyInfo');$hostileKeyValue=$hostileKeyInfoDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS,'ds:KeyValue');$hostileRsa=$hostileKeyInfoDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS,'ds:RSAKeyValue');$hostileRsa->appendChild($hostileKeyInfoDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS,'ds:Modulus','!'));$hostileRsa->appendChild($hostileKeyInfoDocument->createElementNS(XMLSecurityDSig::XMLDSIGNS,'ds:Exponent','!'));$hostileKeyValue->appendChild($hostileRsa);$hostileKeyInfo->appendChild($hostileKeyValue);$hostileSignature->appendChild($hostileKeyInfo);
check(Utils::validateSign($hostileKeyInfoDocument,null,null,'sha1',Utils::ASSERTION_SIGNATURE_XPATH,[$pem]),'Pinned SAML verification parsed or trusted attacker-controlled KeyInfo.');
$encryptedMarkerCount=0;$encryptedMarkerXml=str_replace('</samlp:Response>','<xenc:EncryptedData xmlns:xenc="http://www.w3.org/2001/04/xmlenc#"/></samlp:Response>',$xml,$encryptedMarkerCount);check($encryptedMarkerCount===1,'Unable to build the encrypted-element refusal fixture.');reject(fn()=>$saml->finish($sp,$tx,base64_encode($encryptedMarkerXml),$store,$now),'Encrypted SAML content entered the unqualified decryption path.');
$debugAuth=new \OneLogin\Saml2\Auth(EnterpriseIdentitySaml::settings($sp));$_POST=['SAMLResponse'=>base64_encode($xml)];$debugAuth->processResponse($tx['request_id']);if($debugAuth->getErrors()){throw new RuntimeException('SAML fixture: '.$debugAuth->getLastErrorReason());}$_POST=[];$proof=$saml->finish($sp,$tx,base64_encode($xml),$store,$now);check($proof['subject']==='stable-saml-42','Valid signed SAML fixture rejected');reject(fn()=>$saml->finish($sp,$tx,base64_encode($xml),$store,$now),'SAML assertion replay accepted');
foreach([['issuer'=>'https://attacker.example.test'],['audience'=>'another-app'],['request'=>'_wrong-request'],['recipient'=>'https://other.example.test/acs'],['expiry'=>$now-300]] as $bad){$badXml=samlFixture($sp,$private,$pem,$tx['request_id'],$now,$bad);$replayBefore=$store->read('replay');reject(fn()=>$saml->finish($sp,$tx,base64_encode($badXml),$store,$now),'Bad signed SAML accepted: '.json_encode($bad));check($store->read('replay')===$replayBefore,'Rejected signed SAML changed replay history: '.json_encode($bad));}
foreach([
 ['recipient'=>$sp['callback_url'].'/another-service'],['recipient'=>$sp['callback_url'].'?another-service'],
 ['audience'=>''],['omit_audience'=>true],['additional_restriction'=>'another-app'],
 ['omit_confirmation_attribute'=>'Recipient'],['omit_confirmation_attribute'=>'InResponseTo'],
 ['omit_confirmation_attribute'=>'NotOnOrAfter'],['omit_confirmation_attribute'=>'Method'],
] as $bad){
 $badXml=samlFixture($sp,$private,$pem,$tx['request_id'],$now,$bad);
 $replayBefore=$store->read('replay');
 reject(fn()=>$saml->finish($sp,$tx,base64_encode($badXml),$store,$now),'Incomplete SAML portal binding accepted: '.json_encode($bad));
 check($store->read('replay')===$replayBefore,'Rejected SAML binding consumed replay evidence');
}
$allowed=samlFixture($sp,$private,$pem,$tx['request_id'],$now,['additional_restriction'=>$sp['sp_entity_id']]);
check($saml->finish($sp,$tx,base64_encode($allowed),$store,$now)['subject']==='stable-saml-42','Valid intersecting SAML audiences rejected');
foreach(['','/another-service'] as $destinationSuffix){
 $badXml=samlFixture($sp,$private,$pem,$tx['request_id'],$now);
 $destination=$destinationSuffix===''?'':' Destination="'.$sp['callback_url'].$destinationSuffix.'"';
 $badXml=str_replace(' Destination="'.$sp['callback_url'].'"',$destination,$badXml);
 $replayBefore=$store->read('replay');reject(fn()=>$saml->finish($sp,$tx,base64_encode($badXml),$store,$now),'Missing or incorrect SAML response destination accepted');check($store->read('replay')===$replayBefore,'Rejected SAML response destination changed replay history');
}
// A valid generic XML signature must not exclude the identity consumed by SLS.
$filtered=new DOMDocument();check($filtered->loadXML(samlFixture($sp,$private,$pem,$tx['request_id'],$now)),'Unable to parse the filtered-signature fixture.');
$assertion=$filtered->getElementsByTagNameNS(Constants::NS_SAML,'Assertion')->item(0);
check($assertion instanceof DOMElement,'Filtered-signature fixture lacks its assertion.');$existingSignature=$assertion->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS,'Signature')->item(0);check($existingSignature instanceof DOMNode,'Filtered-signature fixture lacks its original signature.');$assertion->removeChild($existingSignature);
$ds=new XMLSecurityDSig();$ds->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
$ds->addReference($assertion,XMLSecurityDSig::SHA256,[
 'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
 ['http://www.w3.org/TR/1999/REC-xpath-19991116'=>[
  'query'=>'not(ancestor-or-self::saml:NameID)','namespaces'=>['saml'=>Constants::NS_SAML]]],
 XMLSecurityDSig::EXC_C14N
],['id_name'=>'ID','overwrite'=>false]);
$fixtureKey=new XMLSecurityKey(XMLSecurityKey::RSA_SHA256,['type'=>'private']);$fixtureKey->loadKey($private);
$ds->sign($fixtureKey);$ds->add509Cert($pem);$ds->insertSignature($assertion,$assertion->firstChild->nextSibling);
$nameId=$assertion->getElementsByTagNameNS(Constants::NS_SAML,'NameID')->item(0);check($nameId instanceof DOMNode,'Filtered-signature fixture lacks its NameID.');$nameId->nodeValue='changed-fixture-subject';
$filteredXml=$filtered->saveXML();check(is_string($filteredXml)&&$filteredXml!=='','Unable to serialize the filtered-signature fixture.');
$toolkit=new \OneLogin\Saml2\Auth(EnterpriseIdentitySaml::settings($sp));$_POST=['SAMLResponse'=>base64_encode($filteredXml)];
$toolkit->processResponse($tx['request_id']);$_POST=[];
check($toolkit->getErrors()!==[]&&!$toolkit->isAuthenticated(),'xmlseclibs accepted a filtered SAML signature');
$replayBefore=$store->read('replay');
reject(fn()=>$saml->finish($sp,$tx,base64_encode($filteredXml),$store,$now),'Filtered signed identity accepted');
check($store->read('replay')===$replayBefore,'Filtered signature refusal changed replay history');
$tamperBase=samlFixture($sp,$private,$pem,$tx['request_id'],$now);$tamperCount=0;$tamperedXml=str_replace('stable-saml-42','attacker',$tamperBase,$tamperCount);check($tamperCount===1&&$tamperedXml!==$tamperBase,'Unable to build the signed-SAML tamper fixture.');$replayBefore=$store->read('replay');reject(fn()=>$saml->finish($sp,$tx,base64_encode($tamperedXml),$store,$now),'Tampered signed SAML accepted');check($store->read('replay')===$replayBefore,'Tampered-signature refusal consumed replay evidence');
$wrapBase=samlFixture($sp,$private,$pem,$tx['request_id'],$now);$wrapCount=0;$wrapped=str_replace('</samlp:Response>','<saml:Assertion ID="_unsigned" Version="2.0" IssueInstant="'.gmdate('c',$now).'"/></samlp:Response>',$wrapBase,$wrapCount);check($wrapCount===1&&$wrapped!==$wrapBase,'Unable to build the SAML wrapping fixture.');$replayBefore=$store->read('replay');reject(fn()=>$saml->finish($sp,$tx,base64_encode($wrapped),$store,$now),'SAML wrapping accepted');check($store->read('replay')===$replayBefore,'Wrapping refusal consumed replay evidence');
reject(fn()=>$saml->finish($sp,$tx,base64_encode('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'),$store,$now),'XXE accepted');
$utf16="\xff\xfe".mb_convert_encoding('<!DOCTYPE x [<!ENTITY e "unsafe">]><x>&e;</x>','UTF-16LE','UTF-8');
reject(fn()=>$saml->finish($sp,$tx,base64_encode($utf16),$store,$now),'UTF-16 encoded DTD/entity response accepted');
$unsafeXmlFixtures=[
 "\x00\x00\xfe\xff".mb_convert_encoding('<!DOCTYPE x [<!ENTITY e "unsafe">]><x>&e;</x>','UTF-32BE','UTF-8'),
 "\xff\xfe\x00\x00".mb_convert_encoding('<!DOCTYPE x [<!ENTITY e SYSTEM "https://attacker.example/entity">]><x>&e;</x>','UTF-32LE','UTF-8'),
 '<!DOCTYPE x [<!ENTITY a "1234567890"><!ENTITY b "&a;&a;&a;&a;">]><x>&b;</x>',
 '<?xml version="1.0" encoding="UTF-16"?><x>broken encoding</x>',
 '<!DoCtYpE x [<!ENTITY e "unsafe">]><x>&e;</x>',
];
foreach($unsafeXmlFixtures as $unsafeXml){reject(fn()=>$saml->finish($sp,$tx,base64_encode($unsafeXml),$store,$now),'Encoded, malformed or expansion XML fixture accepted');}
// Directory applies only a complete reviewed snapshot, retaining manual rows.
$template=IncidentConfig::template(['id'=>'tpl_'.str_repeat('d',24),'name'=>'Fixture','title'=>'Fixture incident','message'=>'Fixture message','delivery'=>['extensions'=>['1000']],'roster'=>[['id'=>'manual-person','name'=>'Manual Person']]]);
$source=DirectoryConfig::normalize(['sources'=>[['id'=>'dir_'.str_repeat('d',24),'name'=>'CSV fixture','type'=>'csv','enabled'=>true,'template_ids'=>[$template['id']],'sync_subscribers'=>true,'records'=>[]]]])['sources'][0];
$settings=['directory_sync'=>['schema'=>1,'enabled'=>true,'sources'=>[$source]],'incident_workflows'=>['schema'=>1,'templates'=>[$template]],'subscriber_browser'=>SubscriberConfig::defaults()];
$records=DirectorySync::csv("external_id,name,email,active\n42,Fixture Person,person@example.test,true\n");$plan=DirectorySync::plan($settings,$source,$records);check($settings['incident_workflows']['templates'][0]['roster']===[$template['roster'][0]],'Dry-run mutated original roster');check(($plan['changes'][0]['external_id']??null)==='42','Numeric-looking directory ID was coerced to an integer');
$planStore=new EnterpriseIdentityStore($root.'/plan-state');$planId=hash('sha256','numeric-directory-preview');$planRecord=$plan+['deprovision_all'=>false,'created_at'=>$now,'expires_at'=>$now+900];
$planStore->transaction('directory_plans',static function(array &$rows)use($planId,$planRecord):void{$rows[$planId]=$planRecord;});check($planStore->read('directory_plans')[$planId]['changes'][0]['external_id']==='42','Persisted numeric-looking directory ID changed type');
$planPath=$root.'/plan-state/directory_plans.json';$planBefore=identityRead($planPath);rejectStorage(fn()=>$planStore->transaction('directory_plans',static function(array &$rows)use($planId):void{$rows[$planId]['changes'][0]['unexpected']=true;}),'Invalid directory-plan mutation was persisted');check(identityRead($planPath)===$planBefore,'Rejected directory-plan mutation changed prior bytes');
$settings=DirectorySync::apply($settings,$source,$records);check(count($settings['incident_workflows']['templates'][0]['roster'])===2,'Directory import not applied');$pid=DirectoryConfig::personId($source['id'],'42');check($settings['incident_workflows']['templates'][0]['roster'][1]['id']===$pid,'Stable ID mismatch');$original=$settings;
$source=$settings['directory_sync']['sources'][0];$unchanged=DirectorySync::apply($settings,$source,$records);
check($unchanged['subscriber_browser']['people'][0]['version']===$settings['subscriber_browser']['people'][0]['version'],'Unchanged directory sync revoked a current subscriber identity');
$off=DirectorySync::apply($settings,$source,[]);check(count($off['incident_workflows']['templates'][0]['roster'])===1&&!$off['subscriber_browser']['people'][0]['enabled'],'Deprovisioning left active roster or subscriber');
check(!hash_equals($settings['subscriber_browser']['people'][0]['version'],$off['subscriber_browser']['people'][0]['version']),'Deprovisioning preserved subscriber sessions and browser enrollments');
$reactivated=DirectorySync::apply($off,$off['directory_sync']['sources'][0],$records);
check($reactivated['subscriber_browser']['people'][0]['enabled']&&!hash_equals($off['subscriber_browser']['people'][0]['version'],$reactivated['subscriber_browser']['people'][0]['version']),'Reactivation restored a previously revoked subscriber identity');
check(count($original['incident_workflows']['templates'][0]['roster'])===2,'Existing snapshot object mutated');
reject(fn()=>DirectorySync::csv("external_id,name\n42,One\n42,Two\n"),'Repeated external ID accepted');
$scim=['scim_base_url'=>'https://scim.example.test/v2','scim_token'=>'inert'];$scimHttp=static fn()=>['schemas'=>['urn:ietf:params:scim:api:messages:2.0:ListResponse'],'totalResults'=>2,'startIndex'=>1,'Resources'=>[]];reject(fn()=>DirectorySync::scim($scim,$scimHttp),'Partial SCIM allowed deprovisioning');
// An actual IncidentStore/Service verifies token ownership and immutable roster responses.
$settings['subscriber_browser']['enabled']=true;$settings['subscriber_browser']['public_base_url']='https://pbx.example.test/mass-notify/subscriber.php';$settings['subscriber_browser']['people'][0]['registered_at']=$now-30;
$istore=new IncidentStore($root.'/incidents');$fixtureTemplate=$settings['incident_workflows']['templates'][0];$incidentService=new IncidentService($istore,fn()=>$fixtureTemplate,fn($d)=>$d,fn()=>['success'=>true,'job_id'=>'job_'.str_repeat('1',32)],fn()=>[],fn()=>$now);
$incident=$incidentService->start(['template_id'=>$fixtureTemplate['id'],'request_id'=>str_repeat('f',32),'fields'=>[],'is_test'=>true],['identity'=>'Fixture admin']);$subscriber=new SubscriberService($store,$istore);$person=$settings['subscriber_browser']['people'][0];$issued=$subscriber->issue($settings,$person['id'],$incident['id'],$now);reject(fn()=>$subscriber->verify($settings,$issued['token'],$now),'Unaccepted email proof accepted');$subscriber->delivery($issued['token_hash'],'accepted');$bound=$subscriber->verify($settings,$issued['token'],$now);reject(fn()=>$subscriber->verify($settings,$issued['token'],$now),'One-use email proof replay accepted');
$reply=$subscriber->respond($settings,$bound,['request_id'=>str_repeat('1',32),'response'=>'safe','note'=>'Fixture safe'],$now);check($reply['response']['source']==='human_browser_response'&&$reply['response']['response']==='safe','Browser response confused with receipt/operator');check($istore->read($incident['id'])['template']['roster'][1]['id']===$pid,'Browser response modified roster');
reject(fn()=>$subscriber->respond($settings,$bound,['request_id'=>str_repeat('2',32),'person_id'=>'manual-person','response'=>'safe'],$now),'Cross-person response accepted');$changed=$settings;$changed['subscriber_browser']['people'][0]['version']=str_repeat('f',64);reject(fn()=>$subscriber->current($changed,$bound,$now),'Revoked version kept session');$changed=$settings;$changed['subscriber_browser']['people'][0]['registered_at']=$now+1;reject(fn()=>$subscriber->issue($changed,$person['id'],$incident['id'],$now),'Post-incident replacement identity admitted');
reject(fn()=>$subscriber->current($settings,$bound,$now+901),'Expired subscriber session accepted');
$subscriber->issue($settings,$person['id'],$incident['id'],$now+1);
$subscriber->issue($settings,$person['id'],$incident['id'],$now+2);
$invitationHistory=$store->read('subscriber_tokens');
try{$subscriber->issue($settings,$person['id'],$incident['id'],$now+901);throw new RuntimeException('Link expiry reset the hourly invitation limit');}
catch(DomainException $error){check(str_contains($error->getMessage(),'hourly limit'),'Hourly invitation refusal was unclear');}
check($store->read('subscriber_tokens')===$invitationHistory,'Rejected hourly invitation changed prior token evidence');
$afterHour=$subscriber->issue($settings,$person['id'],$incident['id'],$now+3602);
check(is_string($afterHour['token'])&&count($store->read('subscriber_tokens'))===1,'Completed invitation window did not admit one new token');
// Pre-enrollment is not an incident invitation and cannot submit a human response.
require_once dirname(__DIR__).'/slsmassnotifyserver/BrowserPush.php';
$pre=$settings;$prePerson=$person;$prePerson['id']='sub_'.str_repeat('e',24);$prePerson['person_id']='pre-enrolled';
$prePerson['version']=str_repeat('e',64);$prePerson['registered_at']=$now-10;$pre['subscriber_browser']['people'][]=$prePerson;
$preKey=\SLS\MassNotify\BrowserPush::generateKey();
$pre['subscriber_browser']['push']=['enabled'=>true,'private_key_pem'=>$preKey['private_key_pem'],'subject'=>'mailto:fixture@example.test'];
$invitation=$subscriber->issue($pre,$prePerson['id'],'',$now);$subscriber->delivery($invitation['token_hash'],'accepted');
$enrolled=$subscriber->verify($pre,$invitation['token'],$now);
check($subscriber->current($pre,$enrolled,$now)['state']==='preferences','Pre-enrollment required an incident.');
reject(fn()=>$subscriber->respond($pre,$enrolled,['request_id'=>str_repeat('9',32),'response'=>'safe'],$now),'Pre-enrollment gained incident response authority.');
$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/pre-enrollment-fixture',
    'keys'=>['p256dh'=>$preKey['public_key'],'auth'=>\SLS\MassNotify\BrowserPush::encode(random_bytes(16))]];
$endpoint=$subscriber->enrollPush($pre,$enrolled,$subscription,$now)['endpoint_id'];
check($subscriber->pushTargets($pre,$prePerson['id'])===[$endpoint]&&count($subscriber->browserPreferences($pre,$enrolled,$now))===1,'Verified browser enrollment or preferences missing.');
$subscriber->removeAllPush($pre,$enrolled,$now);check($subscriber->pushTargets($pre,$prePerson['id'])===[],'Unsubscribe did not revoke delivery targets.');
$subscriber->enrollPush($pre,$enrolled,$subscription,$now);check(count($subscriber->pushTargets($pre,$prePerson['id']))===1,'Explicit browser reconsent failed.');
$different=$pre;$different['subscriber_browser']['people'][array_key_last($different['subscriber_browser']['people'])]['version']=str_repeat('f',64);
reject(fn()=>$subscriber->current($different,$enrolled,$now),'Changed subscriber identity retained pre-enrollment access.');
reject(fn()=>$subscriber->current($pre,$enrolled,$now+901),'Pre-enrollment proof did not expire.');
reject(fn()=>$subscriber->removePush($pre,$bound,$endpoint,$now),'Another subscriber removed a browser.');
identityRestoreGlobals($originalPost,$originalServer);$globalsRestored=true;
if(!defined('SLS_IDENTITY_FIXTURE_RETAIN')||SLS_IDENTITY_FIXTURE_RETAIN!==true){identityCleanup($root,true);$cleanupComplete=true;}
echo "Enterprise identity: five synthetic OIDC providers, signed SAML/attack fixtures, directory snapshots, subscriber ownership and protected-ledger failures passed.\n";
