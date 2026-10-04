<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/OperatorAccess.php';
use SLS\MassNotify\{OperatorAuth as Auth, OperatorAccess as Access, ApiSecurity, LocationDirectory as Directory};
$count=0;
function authCheck(bool $ok,string $message):void { global $count; $count++; if(!$ok)throw new RuntimeException($message); }
function authReject(callable $work):void { try{$work();}catch(DomainException|InvalidArgumentException $e){authCheck(true,'Rejected');return;}throw new RuntimeException('Unsafe authentication input was accepted.'); }
$secret=Auth::base32('12345678901234567890');
foreach ([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$code) {
    authCheck(Auth::code($secret,intdiv($time,30),8)===$code,'RFC 6238 SHA-1 vector failed.');
}
$now=1234567890; $step=intdiv($now,30); $code=Auth::code($secret,$step);
authCheck(Auth::counter($secret,$code,$now)===$step,'Current code rejected.');
authCheck(Auth::counter($secret,$code,$now,$step)===null,'A previously consumed code was accepted.');
authCheck(Auth::counter($secret,Auth::code($secret,$step-2),$now)===null,'Old code accepted.');
authCheck(Auth::counter($secret,'1234567',$now)===null,'Wrong code length accepted.');
$hash=Auth::password('fixture personal passphrase 923');
authCheck(Auth::validHash($hash)&&Auth::verify('fixture personal passphrase 923',$hash)&&!Auth::verify('wrong passphrase',$hash),'Strong password verification failed.');
authCheck($hash!==Auth::password('fixture personal passphrase 923'),'Passwords do not use random salts.');
if(str_starts_with($hash,'$argon2id$'))authCheck(str_contains($hash,'m=65536,t=4,p=1'),'Argon2id work factor was reduced.');
$pbk='pbkdf2-sha256:600000:'.str_repeat('ab',32).':'.hash_pbkdf2('sha256','fixture personal passphrase 923',str_repeat("\xab",32),600000,64);
authCheck(Auth::verify('fixture personal passphrase 923',$pbk),'High-iteration portability fallback rejected.');
authCheck(Auth::validHash(Auth::dummyHash())&&!Auth::verify('unknown login password',Auth::dummyHash()),'Unknown native login skipped valid password work.');
$fallbackCode='namespace SLS\\MassNotify {function password_algos(){return [];}} namespace {require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/OperatorAuth.php',true).';'
    .'$hash=\\SLS\\MassNotify\\OperatorAuth::password("fallback fixture passphrase");$dummy=\\SLS\\MassNotify\\OperatorAuth::dummyHash();'
    .'echo json_encode([str_starts_with($hash,"pbkdf2-sha256:600000:"),\\SLS\\MassNotify\\OperatorAuth::verify("fallback fixture passphrase",$hash),'
    .'str_starts_with($dummy,"pbkdf2-sha256:600000:"),\\SLS\\MassNotify\\OperatorAuth::validHash($dummy),!\\SLS\\MassNotify\\OperatorAuth::verify("unknown login",$dummy)]);}';
$process=proc_open([PHP_BINARY,'-r',$fallbackCode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
authCheck(is_resource($process),'Fallback fixture could not start');fclose($pipes[0]);$fallback=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
authCheck(proc_close($process)===0 && $errors==='' && json_decode($fallback,true)===[true,true,true,true,true],'A PBX without Argon2id skipped unknown-login work or password fallback.');
foreach(['short',str_repeat('a',129),"bad\npassword-with-enough-characters"] as $password)authReject(fn()=>Auth::password($password));
authCheck(!Auth::validHash(str_replace('t=4','t=9999',$hash)),'Unbounded hash work factor accepted.');
$settings=['desktop_auth_key'=>base64_encode(random_bytes(32))]; $id='api_'.str_repeat('a',24);
$sealed=Auth::seal($secret,$id,$settings);
authCheck(Auth::open($sealed,$id,$settings)===$secret&&!str_contains($sealed,$secret),'Authenticated seed encryption failed.');
authReject(fn()=>Auth::open($sealed,'api_'.str_repeat('b',24),$settings));
authReject(fn()=>Auth::open(substr($sealed,0,-1).'A',$id,$settings));
authReject(fn()=>Auth::open($sealed,$id,['desktop_auth_key'=>base64_encode(random_bytes(32))]));
$auth=Auth::create('initial fixture passphrase 752');
$account=['id'=>$id,'username'=>'tester','source'=>'portal','name'=>'Tester','role'=>'sender','enabled'=>true,
    'site_ids'=>[],'location_ids'=>[],'group_ids'=>[],'personal_members'=>['extensions'=>['1000']],'actions'=>['view','send','schedule'],'channels'=>['extensions'],'auth'=>$auth];
$account['identity']=Auth::identity($account);
$access=Access::normalize(['enabled'=>false,'accounts'=>[$account]]);
authCheck($access['accounts'][0]['personal_members']['extensions']===['1000'],'Numeric personal extension changed type.');
$settings['operator_access']=$access;
authCheck(ApiSecurity::currentCredential($settings,$id)===null,'An unenrolled account acquired queued-delivery authority.');
$account['auth']['totp_secret_enc']=$sealed; $account['auth']['force_password_change']=false; $account['identity']=Auth::identity($account);
$settings['operator_access']['accounts']=[$account];
authCheck(ApiSecurity::currentCredential($settings,$id)===null,'A portal without an explicit enable switch retained authority.');
$settings['operator_access']['portal_enabled']=true; $principal=ApiSecurity::currentCredential($settings,$id);
authCheck($principal!==null&&Access::may($principal,'send')&&Access::may($principal,'schedule')&&!Access::may($principal,'roll_call'),'Explicit action grants failed.');
authCheck(Access::delivery($principal,['extensions'=>['1000']],$settings)&&!Access::delivery($principal,['extensions'=>['2000']],$settings),'Personal recipient scope leaked.');
$group='grp_'.str_repeat('c',24);$settings['announcement_groups']=[['id'=>$group,'extensions'=>['2000'],'desktop_clients'=>[],'webhook_ids'=>['private-hook']]];
$settings['operator_access']['accounts'][0]['group_ids']=[$group];$principal=Access::principal($settings,$id);
authCheck(ApiSecurity::allowedAudience($principal,$settings)['phones']===['1000','2000'],'Saved group authority disappeared.');
authCheck(ApiSecurity::allowedAudience($principal,$settings)['webhooks']===[],'A group bypassed the channel restriction.');
$settings['operator_access']['accounts'][0]['enabled']=false;
authCheck(ApiSecurity::currentCredential($settings,$id)===null,'Disabled local account retained authority.');
$public=Auth::publicAccount($account);$encoded=json_encode($public);
authCheck(!isset($public['auth'],$public['identity'])&&!str_contains($encoded,$hash)&&!str_contains($encoded,$sealed),'Editor leaked authentication material.');
$recovery=Auth::recovery();authCheck(count(array_unique($recovery['codes']))===10&&hash('sha256',$recovery['codes'][0])===$recovery['hashes'][0],'Recovery generation failed.');
$account['auth']['recovery_hashes']=$recovery['hashes'];Auth::normalize($account['auth']);
foreach([['totp_last_counter'=>'0'],['force_password_change'=>'1'],['version'=>'bad'],['totp_secret_enc'=>'plaintext'],['password_hash'=>sha1('bad')]] as $patch)authReject(fn()=>Auth::normalize(array_replace($auth,$patch)));
if(!interface_exists('BMO')){interface BMO{}}
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflect=new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
$redacted=$reflect->getMethod('redactConfigSecrets')->invoke($reflect->newInstanceWithoutConstructor(),['operator_access'=>['accounts'=>[$account]]]);
authCheck(($redacted['operator_access']['accounts'][0]['auth']??null)==='[redacted]'&&!str_contains(json_encode($redacted),$sealed),'Public config projection leaked auth material.');
echo "$count operator password, RFC TOTP, encryption, readiness and scope checks passed.\n";
