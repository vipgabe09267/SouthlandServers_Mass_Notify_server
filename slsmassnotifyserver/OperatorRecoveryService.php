<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/OperatorRecovery.php';
require_once __DIR__.'/SecurityAudit.php';
require_once __DIR__.'/AdvertisedAddress.php';
use SLS\MassNotify\{OperatorAccess,OperatorAuth,OperatorRecovery};

/** Password recovery changes authentication atomically under the settings lock. */
trait SlsOperatorRecovery
{
    protected function operatorRecoveryNow(): int { return time(); }

    private function operatorResetUrl(array $settings, string $secret): string
    {
        $address=SlsAdvertisedAddress::fields($settings);
        // Never derive an emailed credential's origin from request headers.
        return SlsAdvertisedAddress::url('https',SlsAdvertisedAddress::host($address['host']),$address['api_port'],'/mass-notify/')
            .'#reset='.$secret;
    }

    /** Record only allowlisted security metadata, never credentials or bodies. */
    public function operatorSecurityEvent(string $action, ?array $account=null, bool $ok=true, ?array $actor=null): bool
    {
        if (!isset(\SLS\MassNotify\SecurityAudit::OPERATOR_ACTIONS[$action])) {
            throw new \LogicException('Unsupported operator security event.');
        }
        $settings=$this->getActiveSettings();
        $network=\SLS\MassNotify\ApiSecurity::requestNetwork($_SERVER,$settings);
        $user=$_SESSION['AMP_user']??null;
        $name=$actor['username']??($account['username']??(is_object($user)?($user->username??''):'anonymous'));
        $name=is_string($name)?substr(preg_replace('/[^\x20-\x7e]/','_',$name),0,140):'anonymous';
        $method=$_SERVER['REQUEST_METHOD']??'';$method=PHP_SAPI==='cli'?'CLI':(in_array($method,['GET','POST'],true)?$method:'UNKNOWN');
        $record=['created_at'=>gmdate('c'),'ip'=>filter_var($network['ip']??'',FILTER_VALIDATE_IP)?$network['ip']:'',
            'method'=>$method,'action'=>$action,'status'=>$ok?200:401,'ok'=>$ok,
            'actor'=>'operator:'.$name,'event_id'=>'audit_'.bin2hex(random_bytes(16))];
        if (is_string($account['id']??null) && preg_match('/^api_[a-f0-9]{24}$/D',$account['id'])) { $record['operator_id']=$account['id']; }
        if (is_string($account['username']??null)) { $record['operator_username']=substr(preg_replace('/[^\x20-\x7e]/','_',$account['username']),0,140); }
        $body=json_encode($record,JSON_THROW_ON_ERROR)."\n";
        $command=['/usr/bin/timeout','--signal=KILL','2','/usr/bin/python3','-I',self::RUNTIME_DIR.'/sls_storage_maintenance.py','--append-security-audit'];
        if (($settings['control_api']['audit_syslog']??'0')==='1') { $command[]='--syslog'; }
        $pipes=[];$process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) { return false; }
        try {
            $written=fwrite($pipes[0],$body);fclose($pipes[0]);unset($pipes[0]);
            $response=stream_get_contents($pipes[1],4097);fclose($pipes[1]);unset($pipes[1]);
            $exit=proc_close($process);$process=null;$reply=json_decode($response,true);
            return $written===strlen($body) && $exit===0 && ($reply['ok']??false)===true;
        } finally { foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}if(is_resource($process)){proc_close($process);} }
    }

    private function requireOperatorSecurityEvent(string $action, array $account, ?array $actor=null): void
    {
        if (!$this->operatorSecurityEvent($action,$account,true,$actor)) {
            throw new \DomainException('This security change could not be audited. Ask an administrator to check audit storage and retry.');
        }
    }

    /** Admin-only, immediate and revocable; generating a link does not sign out the user. */
    public function manageOperatorPasswordReset(string $action, array $input): array
    {
        $lock=null;
        try {
            $principal=$this->currentOperator();
            if ($principal['operator_role']!=='administrator') { throw new \DomainException('Only an SLS administrator can manage password reset links.'); }
            if (!in_array($action,['generate_reset_link','revoke_reset_link'],true) || array_keys($input)!==['id']
                || !is_string($input['id']) || !preg_match('/^api_[a-f0-9]{24}$/D',$input['id'])) {
                throw new \DomainException('Select one saved operator login.');
            }
            $lock=$this->acquireSettingsLock(true);$active=$this->loadSettingsFile(self::SETTINGS_JSON);
            $config=OperatorAccess::normalize($active['operator_access']??[]);
            foreach($config['accounts'] as &$account){
                if($account['id']!==$input['id']||$account['source']!=='portal'){continue;}
                $state=OperatorRecovery::normalize($account['auth']['password_recovery']??[]);
                if($action==='generate_reset_link'){
                    if(!$config['portal_enabled']||!$account['enabled']||!OperatorAuth::ready($account)){
                        throw new \DomainException('Enable the portal and login, and complete authenticator enrollment before generating a reset link. For an unenrolled login, set a new initial password in its account editor.');
                    }
                    $issued=OperatorRecovery::issue($account['auth'],'administrator',$this->operatorRecoveryNow());
                    $url=$this->operatorResetUrl($active,$issued['secret']);$state=$issued['state'];
                    $this->requireOperatorSecurityEvent('operator_reset_link_issue_authorized',$account,$principal);
                }else{
                    $state['token']=null;$this->requireOperatorSecurityEvent('operator_reset_link_revoke_authorized',$account,$principal);
                }
                $account['auth']['password_recovery']=$state;$saved=$account;$result=OperatorAuth::publicAccount($account);unset($account);
                $this->writeOperatorConfig($active,$config);
                $this->operatorSecurityEvent($action==='generate_reset_link'?'operator_reset_link_issued':'operator_reset_link_revoked',$saved,true,$principal);
                return ['success'=>true,'message'=>$action==='generate_reset_link'?'Reset link generated. It expires in 24 hours and requires the existing authenticator.':'Reset link revoked.',
                    'account'=>$result]+($action==='generate_reset_link'?['reset_url'=>$url,'expires_at'=>gmdate('c',$state['token']['expires_at'])]:[]);
            }unset($account);throw new \DomainException('That saved portal login no longer exists.');
        }catch(\DomainException $e){return ['success'=>false,'message'=>$e->getMessage()];}
        finally{if($lock!==null){$this->releaseSettingsLock($lock);}}
    }

    /** Local Postfix acceptance only; no automatic resend after failure/uncertainty. */
    protected function sendOperatorRecoveryEmail(array $settings, string $recipient, string $subject, string $body): string
    {
        OperatorRecovery::email($recipient);
        try{$sender=OperatorRecovery::email(array_key_exists('mail_from_domain',$settings)
            ?(string)($settings['mail_from_local_part']??'no-reply').'@'.(string)$settings['mail_from_domain']:(string)($settings['mail_from_addr']??''));}
        catch(\DomainException $error){return 'failed';}
        if($sender===''||!is_executable('/usr/sbin/sendmail')){return 'failed';}
        $message='From: <'.$sender.">\r\nTo: <".$recipient.">\r\nSubject: ".$subject
            ."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$body."\r\n";
        $pipes=[];$process=@proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=1','1','/usr/sbin/sendmail','-oi','-t'],
            [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){return 'failed';}
        try{$written=fwrite($pipes[0],$message);fclose($pipes[0]);unset($pipes[0]);$exit=proc_close($process);$process=null;
            return $written===strlen($message)&&$exit===0?'accepted':(in_array($exit,[124,137],true)?'unconfirmed':'failed');
        }finally{foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}if(is_resource($process)){proc_close($process);}}
    }

    public function requestOperatorPasswordRecovery(string $username, string $email): void
    {
        $email=OperatorRecovery::email($email);$lock=$this->acquireSettingsLock(true);$envelope=null;
        try{
            $active=$this->loadSettingsFile(self::SETTINGS_JSON);$config=OperatorAccess::normalize($active['operator_access']??[]);
            if(!$config['portal_enabled']){return;}
            foreach($config['accounts'] as &$account){
                if($account['source']!=='portal'||!$account['enabled']||!OperatorAuth::ready($account)
                    ||strcasecmp($account['username'],$username)!==0||$email===''||($account['email']??'')===''
                    ||!hash_equals(strtolower($account['email']),strtolower($email))){continue;}
                $state=OperatorRecovery::normalize($account['auth']['password_recovery']??[]);
                if(!OperatorRecovery::available($state)){return;}
                $issued=OperatorRecovery::issue($account['auth'],'email',$this->operatorRecoveryNow());
                $url=$this->operatorResetUrl($active,$issued['secret']);
                $this->requireOperatorSecurityEvent('operator_reset_email_reserved',$account);
                $account['auth']['password_recovery']=$issued['state'];
                $envelope=['account'=>$account,'hash'=>$issued['state']['token']['hash'],'settings'=>$active,'url'=>$url];unset($account);
                $this->writeOperatorConfig($active,$config);break;
            }unset($account);
        }finally{$this->releaseSettingsLock($lock);}
        if($envelope===null){return;}
        // Count the attempt before launching Postfix; an uncertain handoff is never retried.
        $delivery=$this->sendOperatorRecoveryEmail($envelope['settings'],$envelope['account']['email'],'SLS operator password reset',
            "A password reset was requested for your SLS operator login.\n\n".$envelope['url']
            ."\n\nThis link expires in 24 hours and works once. Saving a new password requires your current authenticator code.\n\nIf you did not request this, contact your PBX administrator. Two recovery emails or two failed recovery attempts require administrator assistance.");
        $lock=$this->acquireSettingsLock(true);
        try{
            $active=$this->loadSettingsFile(self::SETTINGS_JSON);$config=OperatorAccess::normalize($active['operator_access']??[]);
            foreach($config['accounts'] as &$account){
                if($account['id']!==$envelope['account']['id']){continue;}
                $state=OperatorRecovery::normalize($account['auth']['password_recovery']??[]);
                if(($state['token']['hash']??'')!==$envelope['hash']){break;}
                $state['last_delivery']=$delivery;
                if($delivery==='failed'){$state['failed_attempts']=min(2,$state['failed_attempts']+1);$state['token']=null;}
                $account['auth']['password_recovery']=$state;unset($account);$this->writeOperatorConfig($active,$config);break;
            }unset($account);
        }finally{$this->releaseSettingsLock($lock);}
        $this->operatorSecurityEvent($delivery==='accepted'?'operator_reset_email_accepted':'operator_reset_email_failed',$envelope['account'],$delivery==='accepted');
    }

    public function beginOperatorPasswordReset(string $secret): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret)){throw new \DomainException('This reset link is invalid, expired or revoked. Contact an administrator.');}
        $hash=hash('sha256',$secret);$settings=$this->getActiveSettings();$config=OperatorAccess::normalize($settings['operator_access']??[]);
        if($config['portal_enabled']){foreach($config['accounts'] as $account){
            if($account['source']==='portal'&&$account['enabled']&&OperatorRecovery::valid($account['auth'],$hash,$this->operatorRecoveryNow())){
                return ['id'=>$account['id'],'hash'=>$hash,'version'=>$account['auth']['version'],'started_at'=>$this->operatorRecoveryNow()];
            }
        }}
        throw new \DomainException('This reset link is invalid, expired or revoked. Contact an administrator.');
    }

    public function completeOperatorPasswordReset(array $binding, string $password, string $code): array
    {
        if(array_diff(array_keys($binding),['id','hash','version','started_at'])||!is_string($binding['id']??null)
            ||!is_string($binding['hash']??null)||!is_string($binding['version']??null)||!is_int($binding['started_at']??null)
            ||$binding['started_at']>$this->operatorRecoveryNow()||$this->operatorRecoveryNow()-$binding['started_at']>=1800){
            throw new \DomainException('The reset form expired. Open the reset link again.');
        }
        $hash=OperatorAuth::password($password);$lock=$this->acquireSettingsLock(true);$result=null;$failure='';
        try{
            $active=$this->loadSettingsFile(self::SETTINGS_JSON);$config=OperatorAccess::normalize($active['operator_access']??[]);
            if(!$config['portal_enabled']){throw new \DomainException('The operator portal has not been enabled.');}
            foreach($config['accounts'] as &$account){
                if($account['id']!==$binding['id']||!$account['enabled']||$account['source']!=='portal'){continue;}
                $auth=$account['auth'];
                if(!hash_equals($auth['version'],$binding['version'])||!OperatorRecovery::valid($auth,$binding['hash'],$this->operatorRecoveryNow())){
                    throw new \DomainException('This reset link is invalid, expired or revoked. Contact an administrator.');
                }
                $counter=OperatorAuth::counter(OperatorAuth::open($auth['totp_secret_enc'],$account['id'],$active),$code,$this->operatorRecoveryNow(),$auth['totp_last_counter']);
                if($counter===null){
                    $auth['password_recovery']=OperatorRecovery::failed(OperatorRecovery::normalize($auth['password_recovery']??[]));
                    $account['auth']=$auth;$result=$account;unset($account);$this->writeOperatorConfig($active,$config);
                    $failure=$auth['password_recovery']['token']===null?'Two verification attempts failed. Contact an administrator for a new reset link.':'The authenticator code is invalid or already used. Wait for the next code; one attempt remains.';
                    break;
                }
                if(OperatorAuth::verify($password,$auth['password_hash'])){throw new \DomainException('Choose a new password different from the current password.');}
                $this->requireOperatorSecurityEvent('operator_password_reset_authorized',$account);
                $auth['password_hash']=$hash;$auth['version']=bin2hex(random_bytes(32));$auth['totp_last_counter']=$counter;$auth['force_password_change']=false;
                $auth['password_recovery']=OperatorRecovery::normalize($auth['password_recovery']??[]);$auth['password_recovery']['token']=null;
                $account['auth']=OperatorAuth::normalize($auth);$account['identity']=OperatorAuth::identity($account);$result=$account;unset($account);
                $this->writeOperatorConfig($active,$config);break;
            }unset($account);
            if($result===null){throw new \DomainException('This operator login is disabled or no longer exists.');}
        }finally{$this->releaseSettingsLock($lock);}
        if($failure!==''){$this->operatorSecurityEvent('operator_password_reset_failed',$result,false);throw new \DomainException($failure);}
        $this->operatorSecurityEvent('operator_password_reset_completed',$result);
        if(($result['email']??'')!==''&&$this->sendOperatorRecoveryEmail($active,$result['email'],'SLS operator password changed',
            "Your SLS operator password has been changed. Existing sessions and reset links were revoked; your authenticator remains enrolled.\n\nIf you did not make this change, contact your PBX administrator immediately.")!=='accepted'){
            $this->operatorSecurityEvent('operator_reset_confirmation_failed',$result,false);
        }
        return ['id'=>$result['id'],'username'=>$result['username']];
    }
}
