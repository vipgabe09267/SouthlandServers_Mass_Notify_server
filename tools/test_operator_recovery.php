<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/Operators.php';
require dirname(__DIR__).'/slsmassnotifyserver/OperatorPortal.php';
use SLS\MassNotify\{OperatorAuth as Auth,OperatorRecovery as Recovery,OperatorAccess as Access,OperatorPortal as Portal};
$checks=0;
function recoveryCheck(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function recoveryReject(callable $work,string $message):void{try{$work();}catch(DomainException|InvalidArgumentException $e){recoveryCheck(true,$message);return;}throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/sls-operator-recovery-'.bin2hex(random_bytes(8));mkdir($root,0700);define('SLS_RECOVERY_FIXTURE',$root);
class RecoveryFixture {
 use \FreePBX\modules\SlsOperators { operatorSecurityEvent as realOperatorSecurityEvent; }
 const SETTINGS_JSON=SLS_RECOVERY_FIXTURE.'/settings.json';const PENDING_SETTINGS_JSON=SLS_RECOVERY_FIXTURE.'/pending.json';
 const RUNTIME_DIR=SLS_RECOVERY_FIXTURE;
 public int $now=1900000000;public array $mail=[],$audit=[];public string $mailStatus='accepted';public bool $auditAvailable=true,$admin=true,$writeFailure=false;
 public function getActiveSettings():array{return json_decode(file_get_contents(self::SETTINGS_JSON),true,64,JSON_THROW_ON_ERROR);}
 public function currentOperator():array{return ['id'=>'recovery_admin','username'=>'owner','operator_role'=>$this->admin?'administrator':'sender'];}
 protected function operatorRecoveryNow():int{return $this->now;}
 public function operatorSecurityEvent($action,$account=null,$ok=true,$actor=null):bool{$this->audit[]=['action'=>$action,'account'=>$account['id']??'','actor'=>$actor['username']??($account['username']??''),'ok'=>$ok];return $this->auditAvailable;}
 protected function sendOperatorRecoveryEmail($settings,$recipient,$subject,$body):string{$this->mail[]=compact('recipient','subject','body');return $this->mailStatus;}
 private function acquireSettingsLock(...$args){$lock=fopen(SLS_RECOVERY_FIXTURE.'/settings.lock','c+b');flock($lock,LOCK_EX);return $lock;}
 private function releaseSettingsLock($lock):void{fclose($lock);}
 private function loadSettingsFile($path):array{return json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);}
 private function writeSettingsFileUnlocked($path,$settings,$active):void{if($this->writeFailure)throw new RuntimeException('fixture write failed');file_put_contents($path.'.tmp',json_encode($settings));chmod($path.'.tmp',0640);rename($path.'.tmp',$path);}
 public function seed(array $settings):void{file_put_contents(self::SETTINGS_JSON,json_encode($settings));chmod(self::SETTINGS_JSON,0640);}
 public function account():array{return $this->getActiveSettings()['operator_access']['accounts'][0];}
}
try{
 $module=new RecoveryFixture;$secret=Auth::base32('12345678901234567890');$id='api_'.str_repeat('a',24);
 $oldPassword='Original fixture passphrase 123';$newPassword='New fixture phrase + &= " ü 829';
 $settings=['enabled'=>'1','desktop_auth_key'=>base64_encode(str_repeat('k',32)),'public_pbx_host'=>'pbx.example.test',
  'sipnotify'=>['base_url'=>'https://pbx.example.test:8443/api/sipnotify'],'unrelated'=>'preserved'];
 $auth=Auth::create($oldPassword);$auth['force_password_change']=false;$auth['totp_secret_enc']=Auth::seal($secret,$id,$settings);
 $auth['recovery_hashes']=[hash('sha256','01234567-01234567-01234567-01234567')];
 $account=['id'=>$id,'username'=>'fixture','name'=>'Fixture','email'=>'fixture@example.test','source'=>'portal','enabled'=>true,'role'=>'administrator','site_ids'=>[],'group_ids'=>[],'auth'=>$auth];
 $account['identity']=Auth::identity($account);$settings['operator_access']=Access::normalize(['enabled'=>false,'portal_enabled'=>true,'accounts'=>[$account]]);
 $module->seed($settings);file_put_contents(RecoveryFixture::PENDING_SETTINGS_JSON,json_encode($settings+['pending_only'=>'keep']));
 $issue=static function()use($module,$id):array{$out=$module->manageOperatorPasswordReset('generate_reset_link',['id'=>$id]);recoveryCheck($out['success'],$out['message']??'Generate failed');return $out;};
 $token=static fn(array $out):string=>parse_url($out['reset_url'],PHP_URL_FRAGMENT)===false?'':substr(parse_url($out['reset_url'],PHP_URL_FRAGMENT),6);
 $out=$issue();$raw=$token($out);
 recoveryCheck(str_starts_with($out['reset_url'],'https://pbx.example.test:8443/mass-notify/#reset='),'Reset origin ignored configured forwarded HTTPS port');
 recoveryCheck(strlen($raw)===64&&!str_contains(file_get_contents(RecoveryFixture::SETTINGS_JSON),$raw),'Raw reset credential persisted');
 recoveryCheck($module->account()['auth']['password_recovery']['token']['expires_at']===$module->now+86400,'Reset lifetime is not exactly 24 hours');
 recoveryCheck(!str_contains(json_encode($out['account']),$module->account()['auth']['password_hash'])&&!str_contains(json_encode($out['account']),hash('sha256',$raw)),'Public recovery metadata leaked credential');
 $oldBinding=$module->beginOperatorPasswordReset($raw);$next=$issue();recoveryReject(fn()=>$module->beginOperatorPasswordReset($raw),'Replaced link still accepted');
 $binding=$module->beginOperatorPasswordReset($token($next));$module->manageOperatorPasswordReset('revoke_reset_link',['id'=>$id]);
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,$newPassword,Auth::code($secret,intdiv($module->now,30))),'Revoked open reset form accepted');
 $out=$issue();$raw=$token($out);$module->now+=86400;recoveryReject(fn()=>$module->beginOperatorPasswordReset($raw),'Expired link accepted at exact boundary');$module->now-=86400;
 $out=$issue();$binding=$module->beginOperatorPasswordReset($token($out));$module->now+=1800;
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,$newPassword,'123456'),'Expired reset session accepted');$module->now-=1800;
 $module->admin=false;recoveryCheck(!$module->manageOperatorPasswordReset('generate_reset_link',['id'=>$id])['success'],'Sender generated reset link');$module->admin=true;
 recoveryCheck(!$module->manageOperatorPasswordReset('revoke_reset_link',['id'=>$id,'username'=>'fixture'])['success'],'Unknown admin input accepted');
 $module->auditAvailable=false;$before=file_get_contents(RecoveryFixture::SETTINGS_JSON);recoveryCheck(!$module->manageOperatorPasswordReset('generate_reset_link',['id'=>$id])['success'],'Unaudited security mutation accepted');
 recoveryCheck($before===file_get_contents(RecoveryFixture::SETTINGS_JSON),'Audit failure changed credentials');$module->auditAvailable=true;
 // Failed recovery attempts are bounded and do not disable password sign-in.
 $module->seed($settings);$module->mail=[];
 foreach([['missing','fixture@example.test'],['fixture','other@example.test']]as[$user,$email])$module->requestOperatorPasswordRecovery($user,$email);
 recoveryCheck(!$module->mail,'Unknown username/email triggered mail');
 $module->requestOperatorPasswordRecovery('FiXtUrE','FIXTURE@example.test');recoveryCheck(count($module->mail)===1,'Matching recovery request did not produce one handoff');
 preg_match('/#reset=([a-f0-9]{64})/',$module->mail[0]['body'],$match);$binding=$module->beginOperatorPasswordReset($match[1]);
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,$newPassword,'01234567-01234567-01234567-01234567'),'Backup recovery code bypassed required existing TOTP');
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,$newPassword,'not-a-code'),'Second invalid code did not consume reset');
 recoveryCheck($module->account()['auth']['password_recovery']['failed_attempts']===2&&$module->account()['auth']['password_recovery']['token']===null,'Two failures did not revoke link and exhaust recovery');
 $module->requestOperatorPasswordRecovery('fixture','fixture@example.test');recoveryCheck(count($module->mail)===1,'Exhausted recovery sent more mail');
 recoveryCheck(Auth::verify($oldPassword,$module->account()['auth']['password_hash'])&&$module->account()['enabled'],'Recovery failures disabled normal account or changed password');
 $issue(); // Administrator assistance remains available after self-service exhaustion.
 // Two email attempts are durable; the second link remains usable.
 $module->seed($settings);$module->mail=[];
 for($i=0;$i<3;$i++)$module->requestOperatorPasswordRecovery('fixture','fixture@example.test');
 recoveryCheck(count($module->mail)===2&&$module->account()['auth']['password_recovery']['emails_sent']===2,'Email quota is not exactly two');
 preg_match('/#reset=([a-f0-9]{64})/',$module->mail[0]['body'],$first);recoveryReject(fn()=>$module->beginOperatorPasswordReset($first[1]),'Previous emailed link accepted');
 preg_match('/#reset=([a-f0-9]{64})/',$module->mail[1]['body'],$second);$binding=$module->beginOperatorPasswordReset($second[1]);
 $before=$module->account();$oldSession=Portal::binding($before,$module->now);
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,$oldPassword,Auth::code($secret,intdiv($module->now,30))),'Old password reused');
 $result=$module->completeOperatorPasswordReset($binding,$newPassword,Auth::code($secret,intdiv($module->now,30)));$after=$module->account();
 recoveryCheck($result['username']==='fixture'&&Auth::verify($newPassword,$after['auth']['password_hash'])&&!Auth::verify($oldPassword,$after['auth']['password_hash']),'Verified reset did not install exact new password');
 recoveryCheck($after['auth']['totp_secret_enc']===$before['auth']['totp_secret_enc']&&$after['auth']['recovery_hashes']===$before['auth']['recovery_hashes'],'Password reset replaced enrolled authenticator or backup codes');
 recoveryCheck(!Portal::current($oldSession,$after,1800,$module->now),'Old authenticated session survived reset');
 recoveryReject(fn()=>$module->beginOperatorPasswordReset($second[1]),'Used reset link replayed');
 recoveryReject(fn()=>$module->completeOperatorPasswordReset($binding,'Another fixture password 999',Auth::code($secret,intdiv($module->now,30))),'Consumed reset form replayed');
 recoveryCheck($after['auth']['totp_last_counter']===intdiv($module->now,30),'Reset TOTP was not consumed');
 $pending=json_decode(file_get_contents(RecoveryFixture::PENDING_SETTINGS_JSON),true);
 recoveryCheck($pending['operator_access']===$module->getActiveSettings()['operator_access']&&$pending['pending_only']==='keep','Reset did not preserve/mirror staged settings');
 recoveryCheck($module->getActiveSettings()['unrelated']==='preserved','Reset overwrote unrelated configuration');
 recoveryCheck(!str_contains(file_get_contents(RecoveryFixture::SETTINGS_JSON),$newPassword)&&!str_contains(json_encode($module->audit),$second[1]),'Plaintext password or token leaked to storage/audit');
 recoveryCheck(in_array('operator_password_reset_completed',array_column($module->audit,'action'),true),'Completed reset was not audited');
 // Definitive or uncertain Postfix handoff must not retry behind the user.
 foreach(['failed','unconfirmed']as$status){$module->seed($settings);$module->mail=[];$module->mailStatus=$status;
  $module->requestOperatorPasswordRecovery('fixture','fixture@example.test');$state=$module->account()['auth']['password_recovery'];
  recoveryCheck(count($module->mail)===1&&$state['emails_sent']===1&&$state['last_delivery']===$status,'Failed mail was retried or not accounted');
  recoveryCheck(($state['token']===null)===($status==='failed'),'Uncertain/failed handoff reset credential handled incorrectly');
 }
 // Audit/write failure cannot claim a completed password change.
 $module->seed($settings);$module->mailStatus='accepted';$out=$issue();$binding=$module->beginOperatorPasswordReset($token($out));$module->audit=[];$module->writeFailure=true;
 try{$module->completeOperatorPasswordReset($binding,$newPassword,Auth::code($secret,intdiv($module->now,30)));throw new DomainException('Failed write accepted');}catch(RuntimeException $expected){}
 recoveryCheck(Auth::verify($oldPassword,$module->account()['auth']['password_hash'])&&!in_array('operator_password_reset_completed',array_column($module->audit,'action'),true),'Failed write changed password or logged false completion');$module->writeFailure=false;
 foreach(["a@example.test\r\nBcc:evil@example.test",'User <a@example.test>',['a@example.test'],' a@example.test',str_repeat('a',255)]as$bad)recoveryReject(fn()=>Recovery::email($bad),'Unsafe recovery email accepted');
 foreach([['emails_sent'=>3],['failed_attempts'=>'2'],['token'=>['hash'=>'plain']],['last_delivery'=>'delivered'],['unexpected'=>true]]as$bad)recoveryReject(fn()=>Recovery::normalize($bad),'Malformed recovery state accepted');
 $module->seed($settings);$s=$module->getActiveSettings();$s['operator_access']['portal_enabled']=false;$module->seed($s);$module->mail=[];$module->requestOperatorPasswordRecovery('fixture','fixture@example.test');
 recoveryCheck(!$module->mail&&!$module->manageOperatorPasswordReset('generate_reset_link',['id'=>$id])['success'],'Disabled portal allowed recovery');
 // Verify the actual PHP -> bounded Python -> durable journal audit path.
 $maintenance=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_storage_maintenance.py');
 $maintenance=preg_replace('/^DATA = Path\([^\n]+\)$/m','DATA = Path('.json_encode($root,JSON_UNESCAPED_SLASHES).')',$maintenance,1,$replaced);recoveryCheck($replaced===1,'Audit fixture isolation failed');
 file_put_contents($root.'/sls_storage_maintenance.py',$maintenance);copy(dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_audio_state.py',$root.'/sls_audio_state.py');
 copy(dirname(__DIR__).'/slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py',$root.'/sls_config_crypto.py');
 $_SERVER['REMOTE_ADDR']='192.0.2.7';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.7';$_POST=['password'=>'never-log-password','reset_token'=>'never-log-token','code'=>'987654'];
 recoveryCheck($module->realOperatorSecurityEvent('operator_login_failed_password',$account,false),'Real operator audit handoff failed');
 $rawAudit=file_get_contents($root.'/security-audit.jsonl');$audit=json_decode($rawAudit,true);
 recoveryCheck($audit['actor']==='operator:fixture'&&$audit['operator_id']===$id&&$audit['ip']==='192.0.2.7'&&$audit['ok']===false,'Real operator audit lost attribution or trusted spoofed forwarding');
 recoveryCheck(!file_exists($root.'/control-api-audit.jsonl'),'Operator authentication still writes Control API usage');
 recoveryCheck(!str_contains($rawAudit,'never-log')&&!str_contains($rawAudit,'987654'),'Real audit leaked POST credentials');
 echo "$checks reset lifetime, TOTP continuity, replay, revocation, quota, mail uncertainty, audit and config preservation checks passed.\n";
}finally{foreach(array_merge(glob($root.'/*'),glob($root.'/.audit*'))as$file)unlink($file);rmdir($root);}
