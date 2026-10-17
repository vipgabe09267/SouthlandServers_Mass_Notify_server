<?php
declare(strict_types=1);
namespace FreePBX\modules;
require_once __DIR__.'/ImmediateSettings.php';
require_once __DIR__.'/EnterpriseIdentity.php';
require_once __DIR__.'/DirectorySync.php';
require_once __DIR__.'/SubscriberService.php';
use SLS\MassNotify\{EnterpriseIdentityConfig,EnterpriseIdentityStore,DirectoryConfig,DirectorySync,SubscriberConfig,SubscriberService};

/** Administrator-only Labs configuration and explicit connector/invite actions. */
trait SlsEnterpriseLabs
{
    protected function enterpriseLabsStore(): EnterpriseIdentityStore { return new EnterpriseIdentityStore(self::PLUGIN_DATA_DIR.'/enterprise-state'); }
    protected function subscriberService(): SubscriberService { return new SubscriberService($this->enterpriseLabsStore(),$this->incidentStore()); }
    private function enterpriseLabsAdmin(): void
    {
        $this->assertEnterpriseAdministrator();
    }
    private function enterpriseLabsConfigRevision(string $key,array $settings): string
    {
        $class=match($key){'enterprise_identity'=>EnterpriseIdentityConfig::class,'directory_sync'=>DirectoryConfig::class,'subscriber_browser'=>SubscriberConfig::class};
        return hash('sha256',json_encode($class::normalize($settings[$key]??[]),JSON_THROW_ON_ERROR));
    }
    private function enterpriseLabsWrite(array $active,array $changedKeys,?array $directorySource=null,?array $incoming=null): void
    {
        $active=$this->normalizeSettings($active);$this->assertEnterpriseActivationSafe($active);$pending=null;
        if(is_file(self::PENDING_SETTINGS_JSON)||is_link(self::PENDING_SETTINGS_JSON)){
            $pending=$this->loadSettingsFile(self::PENDING_SETTINGS_JSON);if($directorySource!==null){$pending=DirectorySync::apply($pending,$directorySource,$incoming);}
            $keys=$directorySource===null?$changedKeys:array_values(array_diff($changedKeys,['incident_workflows']));foreach($keys as $key){$pending[$key]=$active[$key];}
            $pending=$this->normalizeSettings($pending);$this->assertEnterpriseActivationSafe($pending);
        }
        [$active,$pending]=\SLS\MassNotify\ImmediateSettings::prepare($active,$pending,$changedKeys);
        $this->writeSettingsFileUnlocked(self::SETTINGS_JSON,$active,true);$this->rememberSettingsFingerprint(self::SETTINGS_JSON);
        if($pending!==null){$this->writeSettingsFileUnlocked(self::PENDING_SETTINGS_JSON,$pending,false);$this->rememberSettingsFingerprint(self::PENDING_SETTINGS_JSON);}
    }
    public function enterpriseLabsAction(string $action,array $input): array
    {
        $lock=null;$activity=null;
        try{
            $this->enterpriseLabsAdmin();$allowed=['identity_save','directory_save','directory_preview','directory_apply','subscriber_save','subscriber_invite','subscriber_revoke','subscriber_push_key','subscriber_push_test'];
            if(!in_array($action,$allowed,true)){throw new \DomainException('Unsupported enterprise Labs action.');}
            if($action==='subscriber_push_test'){
                $activity=$this->acquireAnnouncementActivityLock(false,5);
                if($activity===null){throw new \DomainException('Browser test settings are busy. Retry after the current settings operation completes.');}
                $settings=$this->getActiveSettings();require_once __DIR__.'/EnterpriseClusterConfig.php';
                if(\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)){throw new \DomainException('Use the queued Enterprise Labs test when cluster coordination is enabled; direct browser tests cannot prove witness authority. No push was submitted.');}
                require_once __DIR__.'/bin/sls_mass_notify/sls_runtime_state.php';\SLS\MassNotify\RuntimeState::requireRunning($settings);
                if(array_diff(array_keys($input),['subscriber_id','request_id','title','message'])
                    ||!is_string($input['subscriber_id']??null)||!preg_match('/^sub_[a-f0-9]{24}$/D',$input['subscriber_id'])
                    ||!is_string($input['request_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$input['request_id'])){
                    throw new \DomainException('Select one subscriber and a valid test request.');
                }
                $event=['event_id'=>'push_'.$input['request_id'],'title'=>EnterpriseIdentityConfig::text($input['title']??'Browser test',320,'Title'),
                    'message'=>EnterpriseIdentityConfig::text($input['message']??'Browser notification test',1500,'Message'),'is_test'=>true,'expires_at'=>time()+300];
                require_once __DIR__.'/BrowserPush.php';\SLS\MassNotify\BrowserPush::validateContent($event['title'],$event['message'],true);
                $event['subscriber_id']=($input['subscriber_id']??'');$id=$input['request_id'];$event=$this->enterpriseLabsStore()->transaction('browser_test_events',static function(array &$rows)use($id,$event):array{
                    foreach($rows as $key=>$saved){if($saved['expires_at']<time()){unset($rows[$key]);}}
                    if(isset($rows[$id])){foreach(['title','message','subscriber_id'] as $field){if($rows[$id][$field]!==$event[$field]){throw new \DomainException('Test request identity was reused with different content.');}}return $rows[$id];}
                    if(count($rows)>=100){throw new \DomainException('Too many browser tests in progress.');}return $rows[$id]=$event;
                });
                return ['success'=>true,'message'=>'Browser test submission recorded. Review push-service receipts.','result'=>$this->subscriberService()->push($settings,$input['subscriber_id']??'',$event,time())];
            }
            if($action==='directory_preview'){
                if(array_diff(array_keys($input),['source_id','csv','deprovision_all'])
                    ||!is_string($input['source_id']??null)||!preg_match('/^dir_[a-f0-9]{24}$/D',$input['source_id'])
                    ||(array_key_exists('csv',$input)&&!is_string($input['csv']))
                    ||(array_key_exists('deprovision_all',$input)&&!is_bool($input['deprovision_all']))){
                    throw new \DomainException('Directory dry-run request contains invalid fields.');
                }$settings=$this->getActiveSettings();$c=DirectoryConfig::normalize($settings['directory_sync']??[]);$deprovision=$input['deprovision_all']??false;
                if(!$c['enabled']){throw new \DomainException('Directory sync is disabled.');}$source=null;foreach($c['sources'] as $s){if($s['id']===($input['source_id']??'')&&($s['enabled']||$deprovision)){$source=$s;}}
                if(!$source){throw new \DomainException('This directory source is disabled or unavailable.');}
                $records=$deprovision?[]:match($source['type']){'csv'=>DirectorySync::csv($input['csv']??''),'ldap'=>DirectorySync::ldap($source),'scim'=>DirectorySync::scim($source)};
                $plan=DirectorySync::plan($settings,$source,$records);$plan['deprovision_all']=$deprovision;$id=bin2hex(random_bytes(32));$now=time();
                $this->enterpriseLabsStore()->transaction('directory_plans',static function(array &$rows)use($plan,$id,$now):void{foreach($rows as $k=>$r){if($r['expires_at']<=$now){unset($rows[$k]);}}if(count($rows)>=20){throw new \DomainException('Too many pending directory previews.');}$rows[$id]=$plan+['created_at'=>$now,'expires_at'=>$now+900];});
                unset($plan['records']);return ['success'=>true,'message'=>'Dry run complete. No roster or subscriber was changed.','plan_id'=>$id,'plan'=>$plan,'expires_at'=>gmdate('c',$now+900)];
            }
            if($action==='subscriber_invite'){
                if(count($input)!==2||array_diff(array_keys($input),['subscriber_id','incident_id'])
                    ||!is_string($input['subscriber_id']??null)||!is_string($input['incident_id']??null)){
                    throw new \DomainException('Select one subscriber and one open incident.');
                }
                $settings=$this->getActiveSettings();$service=$this->subscriberService();$issued=$service->issue($settings,$input['subscriber_id'],$input['incident_id'],time());
                // Persisted reservation precedes the single explicit transport
                // submission. Failed/uncertain delivery never retries itself.
                try{$delivery=$this->sendOperatorRecoveryEmail($settings,$issued['person']['email'],'SLS incident email verification',
                    "Open this link to verify your email and respond to the incident:\n\n".$issued['url']."\n\nThis link expires at ".gmdate('c',$issued['expires_at'])." and works once. Your response is separate from message delivery. If you need emergency assistance, follow your organization's emergency procedures.");}catch(\Throwable $e){$delivery='unconfirmed';}
                $service->delivery($issued['token_hash'],$delivery);return ['success'=>$delivery==='accepted','message'=>$delivery==='accepted'?'One invitation was accepted by local Postfix; this is not proof of delivery or a human response.':'Invitation delivery failed or is unconfirmed. Its link was revoked; no automatic resend will occur.','delivery'=>$delivery,'expires_at'=>gmdate('c',$issued['expires_at'])];
            }
            $activity=$this->acquireAnnouncementActivityLock(true,5);
            if($activity===null){throw new \DomainException('An announcement is using these settings. Wait for delivery to finish, then try again.');}
            $lock=$this->acquireSettingsLock(false);$active=$this->loadSettingsFile(self::SETTINGS_JSON);
            if(in_array($action,['identity_save','directory_save','subscriber_save'],true)){
                if(count($input)!==2||array_diff(array_keys($input),['config','revision'])||!is_array($input['config']??null)
                    ||!is_string($input['revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['revision'])){throw new \DomainException('Submit the complete configuration and its current revision. Reload this editor before saving.');}
                $key=match($action){'identity_save'=>'enterprise_identity','directory_save'=>'directory_sync','subscriber_save'=>'subscriber_browser'};
                if(!hash_equals($this->enterpriseLabsConfigRevision($key,$active),$input['revision'])){throw new \DomainException('These settings changed in another request. Reload the editor before saving; current grants, secrets and revocations were preserved.');}
            }
            if($action==='identity_save'){
                $config=$input['config'];$old=EnterpriseIdentityConfig::normalize($active['enterprise_identity']??[]);$byId=array_column($old['providers'],null,'id');
                if(!is_array($config)){throw new \DomainException('Configuration must be an object.');}$config['providers']??=[];if(!is_array($config['providers'])||!array_is_list($config['providers'])){throw new \DomainException('Enterprise providers must be a list.');}foreach($config['providers'] as &$p){if(!is_array($p)||array_is_list($p)){throw new \DomainException('Each enterprise provider must be an object.');}if(!array_key_exists('id',$p)||$p['id']===''){$p['id']='idp_'.bin2hex(random_bytes(12));}elseif(!is_string($p['id'])){throw new \DomainException('Provider identity is invalid.');}unset($p['idp_certificate_expires_at'],$p['sp_certificate_expires_at'],$p['certificate_warning']);foreach(['client_secret','sp_private_key'] as $key){unset($p[$key.'_configured']);if(isset($byId[$p['id']][$key])&&($p[$key]??'')===''){$p[$key]=$byId[$p['id']][$key];}}}unset($p);
                $config=EnterpriseIdentityConfig::bindCallbacks(EnterpriseIdentityConfig::normalize($config),$active);$accounts=array_column($active['operator_access']['accounts']??[],null,'id');
                foreach($config['grants'] as $g){$a=$accounts[$g['operator_account_id']]??null;if(!$a||$a['source']!=='portal'){throw new \DomainException('Bind enterprise subjects only to existing local portal accounts.');}}
                if($config['enabled']){$localAdmins=array_filter($accounts,static fn($a)=>$a['source']==='portal'&&$a['enabled']&&$a['role']==='administrator'&&\SLS\MassNotify\OperatorAccess::activeAccount($a)&&\SLS\MassNotify\OperatorAuth::ready($a));if(!$localAdmins){throw new \DomainException('Keep an enabled, enrolled and unexpired local portal administrator for recovery before enabling operator SSO.');}}
                $active['enterprise_identity']=$config;$this->enterpriseLabsWrite($active,['enterprise_identity']);return ['success'=>true,'message'=>'Enterprise identity saved. Local grants and recovery accounts remain authoritative.','config'=>EnterpriseIdentityConfig::publicConfig($config),'revision'=>$this->enterpriseLabsConfigRevision('enterprise_identity',$active)];
            }
            if($action==='directory_save'){
                $config=$input['config'];$old=DirectoryConfig::normalize($active['directory_sync']??[]);$byId=array_column($old['sources'],null,'id');$kept=[];
                if(!is_array($config)){throw new \DomainException('Configuration must be an object.');}$config['sources']??=[];if(!is_array($config['sources'])||!array_is_list($config['sources'])){throw new \DomainException('Directory sources must be a list.');}foreach($config['sources'] as &$s){if(!is_array($s)||array_is_list($s)){throw new \DomainException('Each directory source must be an object.');}if(!array_key_exists('id',$s)||$s['id']===''){$s['id']='dir_'.bin2hex(random_bytes(12));}elseif(!is_string($s['id'])){throw new \DomainException('Directory source identity is invalid.');}$kept[]=$s['id'];$prior=$byId[$s['id']]??null;
                    foreach(['ldap_password','scim_token'] as $key){unset($s[$key.'_configured']);if(isset($prior[$key])&&($s[$key]??'')===''){$s[$key]=$prior[$key];}}
                    if($prior&&$prior['records']&&($s['template_ids']??[])!==$prior['template_ids']){throw new \DomainException('First preview and apply deprovisioning for this source before changing its template assignments.');}$s['records']=$prior['records']??[];
                }unset($s);foreach($byId as $id=>$s){if($s['records']&&!in_array($id,$kept,true)){throw new \DomainException('Deprovision all records before deleting their directory source.');}}
                $active['directory_sync']=DirectoryConfig::normalize($config);
                foreach($active['directory_sync']['sources'] as $source){if($active['directory_sync']['enabled']&&$source['enabled']&&$source['type']==='ldap'&&!extension_loaded('ldap')){throw new \DomainException('The PHP runtime serving FreePBX lacks LDAP. Install its matching php-ldap package and reload the web PHP handler before enabling this source.');}}
                $this->enterpriseLabsWrite($active,['directory_sync']);return ['success'=>true,'message'=>'Directory settings saved; no connector was contacted or roster changed.','config'=>DirectoryConfig::publicConfig($active['directory_sync']),'revision'=>$this->enterpriseLabsConfigRevision('directory_sync',$active)];
            }
            if($action==='directory_apply'){
                if(array_diff(array_keys($input),['plan_id','confirm_deactivation'])
                    ||!is_string($input['plan_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['plan_id'])
                    ||(array_key_exists('confirm_deactivation',$input)&&!is_bool($input['confirm_deactivation']))){throw new \DomainException('Select one valid directory preview.');}
                $id=$input['plan_id'];$plan=$this->enterpriseLabsStore()->transaction('directory_plans',static function(array &$rows)use($id):?array{$p=$rows[$id]??null;unset($rows[$id]);return $p;});
                if(!$plan||$plan['expires_at']<=time()||!hash_equals($plan['revision'],DirectorySync::revision($active))){throw new \DomainException('Directory preview expired or settings changed. Run another dry run.');}
                if(!empty($plan['requires_deactivation_confirmation'])&&empty($input['confirm_deactivation'])){throw new \DomainException('This snapshot deactivates '.$plan['deactivation_count'].' people ('.$plan['deactivation_percent'].'%). Review another preview and explicitly confirm this change.');}
                $config=DirectoryConfig::normalize($active['directory_sync']??[]);$source=null;foreach($config['sources'] as $s){if($s['id']===$plan['source_id']){$source=$s;}}
                if(!$config['enabled']||!$source||(!$source['enabled']&&!($plan['deprovision_all']??false))){throw new \DomainException('Directory source was disabled.');}
                $active=DirectorySync::apply($active,$source,$plan['records']);$this->enterpriseLabsWrite($active,['directory_sync','incident_workflows','subscriber_browser'],$source,$plan['records']);
                return ['success'=>true,'message'=>'Reviewed snapshot applied to future incident templates. Existing jobs and incident snapshots were preserved.','changes'=>$plan['changes']];
            }
            if($action==='subscriber_save'){
                $config=$input['config'];$old=SubscriberConfig::normalize($active['subscriber_browser']??[]);$people=array_column($old['people'],null,'id');
                if(!array_key_exists('push',$config)){$config['push']=$old['push'];}
                if(is_array($config['push']??null)){unset($config['push']['private_key_configured'],$config['push']['public_key']);if(($config['push']['private_key_pem']??'')===''){$config['push']['private_key_pem']=$old['push']['private_key_pem'];}}
                if(!is_array($config)){throw new \DomainException('Configuration must be an object.');}$config['people']??=[];if(!is_array($config['people'])||!array_is_list($config['people'])){throw new \DomainException('Subscriber people must be a list.');}foreach($config['people'] as &$p){if(!is_array($p)||array_is_list($p)){throw new \DomainException('Each subscriber must be an object.');}if(!array_key_exists('id',$p)||$p['id']===''){$p['id']='sub_'.bin2hex(random_bytes(12));}elseif(!is_string($p['id'])){throw new \DomainException('Subscriber identity is invalid.');}$prior=$people[$p['id']]??null;$email=$p['email']??'';$same=$prior&&is_string($email)&&($p['person_id']??'')===$prior['person_id']&&($p['name']??'')===$prior['name']&&strtolower($email)===strtolower($prior['email']);
                    $p['version']=$same?$prior['version']:bin2hex(random_bytes(32));$p['registered_at']=$same?$prior['registered_at']:time();}unset($p);
                $active['subscriber_browser']=SubscriberConfig::normalize($config);$this->enterpriseLabsWrite($active,['subscriber_browser']);return ['success'=>true,'message'=>'Subscriber people saved. No verification invitation was sent.','config'=>SubscriberConfig::publicConfig($active['subscriber_browser']),'revision'=>$this->enterpriseLabsConfigRevision('subscriber_browser',$active)];
            }
            if($action==='subscriber_push_key'){
                if($input){throw new \DomainException('Key generation accepts no fields.');}$config=SubscriberConfig::normalize($active['subscriber_browser']??[]);
                if($config['push']['private_key_pem']!==''){throw new \DomainException('A VAPID key already exists. Existing browser enrollments depend on it.');}
                require_once __DIR__.'/BrowserPush.php';$config['push']['private_key_pem']=\SLS\MassNotify\BrowserPush::generateKey()['private_key_pem'];
                $active['subscriber_browser']=$config;$this->enterpriseLabsWrite($active,['subscriber_browser']);return ['success'=>true,'message'=>'VAPID key generated in encrypted configuration. Browser push remains disabled.','config'=>SubscriberConfig::publicConfig($config),'revision'=>$this->enterpriseLabsConfigRevision('subscriber_browser',$active)];
            }
            if($action==='subscriber_revoke'){
                if(array_keys($input)!==['subscriber_id']||!is_string($input['subscriber_id'])||!preg_match('/^sub_[a-f0-9]{24}$/D',$input['subscriber_id'])){throw new \DomainException('Select one subscriber.');}$config=SubscriberConfig::normalize($active['subscriber_browser']??[]);$found=false;
                foreach($config['people'] as &$p){if($p['id']===$input['subscriber_id']){$p['version']=bin2hex(random_bytes(32));$found=true;}}unset($p);if(!$found){throw new \DomainException('Subscriber unavailable.');}
                $active['subscriber_browser']=$config;$this->enterpriseLabsWrite($active,['subscriber_browser']);$this->subscriberService()->revoke($input['subscriber_id']);return ['success'=>true,'message'=>'Subscriber links and browser sessions revoked.','config'=>SubscriberConfig::publicConfig($config),'revision'=>$this->enterpriseLabsConfigRevision('subscriber_browser',$active)];
            }
            throw new \LogicException('Unhandled enterprise Labs action.');
        }catch(\DomainException|\InvalidArgumentException|\JsonException $e){return ['success'=>false,'message'=>$e->getMessage()];}
        finally{if($lock!==null){$this->releaseSettingsLock($lock);}if($activity!==null){$this->releaseNativeBackupFileLock($activity);}}
    }
    public function operatorIdentityAction(string $action,array $input): array
    {
        $this->enterpriseLabsAdmin();
        if(!in_array($action,['identity_save','danger_begin','danger_accept'],true)){
            throw new \DomainException('Unsupported operator SSO action.');
        }
        if($action!=='identity_save'&&($input['feature']??'')!=='enterprise_identity'){
            throw new \DomainException('This warning applies only to operator SSO.');
        }
        return $this->enterpriseAdministratorAction($action,$input);
    }
    public function renderOperatorIdentitySettings(string $endpoint,string $csrf): string
    {
        $this->enterpriseLabsAdmin();$settings=$this->getActiveSettings();
        $callback=EnterpriseIdentityConfig::expectedCallback($settings);$identity=EnterpriseIdentityConfig::publicConfig(EnterpriseIdentityConfig::normalize($settings['enterprise_identity']??[]));
        foreach($identity['providers'] as &$provider){$provider['callback_url']=$callback;}unset($provider);
        $revisions=['identity'=>$this->enterpriseLabsConfigRevision('enterprise_identity',$settings)];
        $accounts=array_map(static fn($account)=>['id'=>$account['id'],'username'=>$account['username'],'role'=>$account['role']],
            array_values(array_filter($settings['operator_access']['accounts']??[],static fn($account)=>$account['source']==='portal')));
        $safety=\SLS\MassNotify\LabsSafety::normalize($settings['labs_safety']??[]);
        $module=$this;$safetyFeature='enterprise_identity';$safetyAutomatic=false;$safetySelectors='';
        ob_start();include __DIR__.'/views/labs_danger.php';include __DIR__.'/views/operator_identity.php';return ob_get_clean();
    }
    public function renderEnterpriseLabsPage(string $endpoint,string $csrf): string
    {
        $this->enterpriseLabsAdmin();$settings=$this->getActiveSettings();
        $directory=DirectoryConfig::publicConfig(DirectoryConfig::normalize($settings['directory_sync']??[]));$subscriber=SubscriberConfig::publicConfig($settings['subscriber_browser']??[]);
        $revisions=['directory'=>$this->enterpriseLabsConfigRevision('directory_sync',$settings),'subscriber'=>$this->enterpriseLabsConfigRevision('subscriber_browser',$settings)];
        $templates=array_map(static fn($t)=>['id'=>$t['id'],'name'=>$t['name']],$settings['incident_workflows']['templates']??[]);
        $rosterChoices=[];foreach($settings['incident_workflows']['templates']??[] as $template){foreach($template['roster']??[] as $person){$rosterChoices[$person['id']]=['id'=>$person['id'],'name'=>$person['name']];}}$rosterChoices=array_values($rosterChoices);
        $incidentChoices=method_exists($this,'listIncidents')?array_values(array_map(static fn($incident)=>['id'=>$incident['id'],'name'=>$incident['title']],array_filter($this->listIncidents(50)['incidents']??[],static fn($incident)=>($incident['state']??'')==='open'))):[];
        ob_start();include __DIR__.'/views/identity_labs.php';return ob_get_clean();
    }
}
