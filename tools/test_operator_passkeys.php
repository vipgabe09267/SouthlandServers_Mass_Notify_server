<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/OperatorAccess.php';
require dirname(__DIR__).'/slsmassnotifyserver/OperatorPasskeys.php';
use SLS\MassNotify\{OperatorAuth,OperatorAccess,OperatorPasskeys,PasskeyConfig};
$checks=0;
function keyCheck(bool $ok,string $message): void {$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function keyReject(callable $work): void {try{$work();}catch(DomainException|InvalidArgumentException $e){keyCheck($e->getMessage()!=='','Missing authentication error.');return;}throw new RuntimeException('Invalid passkey response accepted.');}
function keyEncode(string $value): string {return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function keyBytes(string $value): string {return strlen($value)<256?"\x58".chr(strlen($value)).$value:"\x59".pack('n',strlen($value)).$value;}
$now=time();$settings=['public_pbx_host'=>'pbx.example.test','desktop_auth_key'=>base64_encode(random_bytes(32)),
    'sipnotify'=>['base_url'=>'https://pbx.example.test:8443/api/sipnotify'],'operator_access'=>['portal_enabled'=>true,'passkeys_enabled'=>true]];
$account=['id'=>'api_'.str_repeat('a',24),'source'=>'portal','username'=>'fixture.operator','name'=>'Fixture operator','enabled'=>true,'expires_at'=>0,
    'auth'=>OperatorAuth::create('Unique fixture passphrase for passkeys 2026')];
$secret=OperatorAuth::secret();$account['auth']['force_password_change']=false;$account['auth']['totp_secret_enc']=OperatorAuth::seal($secret,$account['id'],$settings);
$private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$details=openssl_pkey_get_details($private);$credentialId=random_bytes(32);
$cose="\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT)."\x22\x58\x20".str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT);
$register=OperatorPasskeys::begin($settings,$account,'register',$now);
keyCheck($register['public_key']['rp']['id']==='pbx.example.test'&&$register['state']['origin']==='https://pbx.example.test:8443','Passkeys lost the saved host or forwarded HTTPS port.');
keyCheck($register['public_key']['authenticatorSelection']['userVerification']==='required'&&$register['public_key']['attestation']==='none','Enrollment relaxed verification or requested identifying attestation.');
$creation=static function(array $begin,array $clientChanges=[],int $flags=0x45,string $statement="\xa0")use($credentialId,$cose):string{
    $client=json_encode(array_replace(['type'=>'webauthn.create','challenge'=>$begin['public_key']['challenge'],'origin'=>$begin['state']['origin'],'crossOrigin'=>false],$clientChanges),JSON_THROW_ON_ERROR);
    $auth=hash('sha256',$begin['public_key']['rp']['id'],true).chr($flags).pack('N',0).str_repeat("\0",16).pack('n',strlen($credentialId)).$credentialId.$cose;
    $attestation="\xa3\x63fmt\x64none\x67attStmt".$statement."\x68authData".keyBytes($auth);
    return json_encode(['id'=>keyEncode($credentialId),'rawId'=>keyEncode($credentialId),'type'=>'public-key','response'=>[
        'clientDataJSON'=>keyEncode($client),'attestationObject'=>keyEncode($attestation),'transports'=>['usb']],'clientExtensionResults'=>(object)[]],JSON_THROW_ON_ERROR);
};
OperatorPasskeys::verifyRuntime();
$key=OperatorPasskeys::register($settings,$account,$register['state'],$creation($register),'Fixture hardware key',$now);
keyCheck(!class_exists(\Webauthn\PublicKeyCredential::class,false),'Verifier dependencies leaked into the FreePBX process.');
keyCheck($key['credential_id']===keyEncode($credentialId)&&$key['last_used_at']===0,'Registration returned the wrong credential.');
keyCheck(!str_contains($key['record'],'PRIVATE KEY'),'Passkey configuration contains private key material.');
$account['auth']['passkeys']=[$key];$identity=OperatorAuth::identity($account);
$assertion=static function(array $begin,int $counter=1,int $flags=0x05,array $clientChanges=[],?string $rp=null)use($private,$credentialId,$register):string{
    $client=json_encode(array_replace(['type'=>'webauthn.get','challenge'=>$begin['public_key']['challenge'],'origin'=>$begin['state']['origin'],'crossOrigin'=>false],$clientChanges),JSON_THROW_ON_ERROR);
    $auth=hash('sha256',$rp??$begin['state']['rp_id'],true).chr($flags).pack('N',$counter);openssl_sign($auth.hash('sha256',$client,true),$signature,$private,OPENSSL_ALGO_SHA256);
    return json_encode(['id'=>keyEncode($credentialId),'rawId'=>keyEncode($credentialId),'type'=>'public-key','response'=>[
        'clientDataJSON'=>keyEncode($client),'authenticatorData'=>keyEncode($auth),'signature'=>keyEncode($signature),'userHandle'=>$register['public_key']['user']['id']],
        'clientExtensionResults'=>(object)[]],JSON_THROW_ON_ERROR);
};
$begin=OperatorPasskeys::begin($settings,$account,'authenticate',$now);
keyCheck($begin['public_key']['allowCredentials'][0]['id']===$key['credential_id']&&$begin['public_key']['userVerification']==='required','Assertion did not bind enrolled keys and user verification.');
$updated=OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin),$now+1);
keyCheck(json_decode($updated['record'],true)['counter']===1&&$updated['last_used_at']===$now+1,'Verified counter or last use was not updated.');
$account['auth']['passkeys']=[$updated];keyCheck(OperatorAuth::identity($account)===$identity,'Routine passkey use invalidated unrelated sessions.');
keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin),$now+2));
foreach([['origin'=>'https://pbx.example.test'],['origin'=>'https://other.example.test:8443'],['challenge'=>keyEncode(random_bytes(32))],
    ['crossOrigin'=>true],['crossOrigin'=>null],['tokenBinding'=>['status'=>'present']],['topOrigin'=>'https://pbx.example.test:8443']] as $change){
    keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin,2,0x05,$change),$now+2));
}
foreach([0x01,0x04,0x15] as $flags){keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin,2,$flags),$now+2));}
keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin,2,0x05,[],'other.example.test'),$now+2));
$wrong=json_decode($assertion($begin,2),true);$wrong['id']=$wrong['rawId']=keyEncode(random_bytes(32));keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],json_encode($wrong),$now+2));
$wrong=json_decode($assertion($begin,2),true);$wrong['response']['signature']=keyEncode(random_bytes(70));keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],json_encode($wrong),$now+2));
$wrong=json_decode($assertion($begin,2),true);$wrong['response']['userHandle']=keyEncode(random_bytes(32));keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],json_encode($wrong),$now+2));
keyReject(fn()=>OperatorPasskeys::authenticate($settings,$account,$begin['state'],$assertion($begin,2),$now+120));
$disabled=$settings;$disabled['operator_access']['passkeys_enabled']=false;keyReject(fn()=>OperatorPasskeys::authenticate($disabled,$account,$begin['state'],$assertion($begin,2),$now+2));
$moved=$settings;$moved['sipnotify']['base_url']='https://pbx.example.test:9443/api/sipnotify';keyReject(fn()=>OperatorPasskeys::authenticate($moved,$account,$begin['state'],$assertion($begin,2),$now+2));
$changed=$account;$changed['auth']['version']=str_repeat('f',64);keyReject(fn()=>OperatorPasskeys::authenticate($settings,$changed,$begin['state'],$assertion($begin,2),$now+2));
$expired=$account;$expired['expires_at']=$now+1;keyReject(fn()=>OperatorPasskeys::authenticate($settings,$expired,$begin['state'],$assertion($begin,2),$now+2));
$empty=$account;unset($empty['auth']['passkeys']);keyReject(fn()=>OperatorPasskeys::begin($settings,$empty,'authenticate',$now));
$fresh=OperatorPasskeys::begin($settings,$empty,'register',$now);
keyReject(fn()=>OperatorPasskeys::register($settings,$empty,$fresh['state'],$creation($fresh,[],0x55),'Invalid flags',$now));
keyReject(fn()=>OperatorPasskeys::register($settings,$empty,$fresh['state'],$creation($fresh,[],0x45,"\xa1\x63sig\x41x"),'Invalid none statement',$now));
keyReject(fn()=>PasskeyConfig::normalize(array_fill(0,6,$key)));
$public=OperatorAuth::publicAccount($account);keyCheck(!isset($public['auth'])&&!isset($public['passkeys'][0]['record']),'Public operator metadata leaked authentication records.');
echo "Operator passkeys: $checks real ES256 registration/assertion, isolated dependencies, exact origin/port, UV, RP, challenge, counter, ownership, expiry and disabled-state checks passed.\n";
