<?php
namespace FreePBX\modules;
if (!class_exists('SLS\MassNotify\Sms\Service', false)) { require_once __DIR__.'/api/sls-mass-notify/sms/Service.php'; }
use SLS\MassNotify\Sms\{Config as SmsConfig, Service as SmsService, Store as SmsStore};

trait SlsAnnouncementSms
{
    public function checkBulkvsSender(): array
    {
        try {
            $settings=$this->getPendingSettings()??$this->getActiveSettings();
            return ['success'=>true]+(new \SLS\MassNotify\Sms\Provider())->diagnoseBulkvs($settings['announcement_sms']??[]);
        } catch (\DomainException $error) { return ['success'=>false, 'message'=>$error->getMessage()]; }
        catch (\Throwable $error) { return ['success'=>false, 'message'=>_('The sender check could not complete. Check saved BulkVS credentials, PBX internet access and TLS certificates. No SMS was sent.')]; }
    }
    public function receiveSmsCallback(string $delivery,string $raw,array $headers): void
    {
        $activity=$this->acquireAnnouncementActivityLock(false,1);
        try { $this->announcementSmsService()->callback($this->getActiveSettings(),$delivery,$raw,$headers,time()); }
        finally { $this->releaseNativeBackupFileLock($activity); }
    }
    private function defaultAnnouncementSms(): array { return SmsConfig::defaults(); }
    private function validateAnnouncementSms($value, bool $partial=false): array
    {
        if ($partial && is_array($value)) {
            if (array_key_exists('enabled',$value) && !in_array($value['enabled'],[true,false,0,1,'0','1'],true)) { return [_('SMS Enabled must be on or off.')]; }
            $value['enabled']=false; // Required credentials are checked after merging the patch.
            foreach (['twilio_key_secret','twilio_auth_token','telnyx_api_key','bulkvs_api_password'] as $key) {
                if (($value[$key]??null)==='[redacted]') { unset($value[$key]); }
            }
        }
        try { SmsConfig::normalize($value); return []; } catch (\DomainException $error) { return [$error->getMessage()]; }
    }
    public function getAnnouncementSmsUsage(): array
    {
        if (!file_exists(self::PLUGIN_DATA_DIR.'/sms') && !is_link(self::PLUGIN_DATA_DIR.'/sms')) { return ['available'=>true,'periods'=>[],'blocked_recipients'=>[]]; }
        try {
            $stats=$this->announcementSmsStore()->stats(time()); $blocked=array_column($stats['opt_outs'],'blocked_at','number_hash');
            $stats['available']=true; $stats['blocked_recipients']=[];
            foreach (SmsConfig::normalize($this->getActiveSettings()['announcement_sms']??[])['recipients'] as $row) {
                if (isset($blocked[hash('sha256',$row['number'])]) && (strtotime($row['consent_at'])?:0)<=$blocked[hash('sha256',$row['number'])]) {
                    $stats['blocked_recipients'][]=$row['id'];
                }
            }
            unset($stats['opt_outs']); return $stats;
        } catch (\Throwable $error) { return ['available'=>false,'periods'=>[],'blocked_recipients'=>[]]; }
    }
    public function getAnnouncementSmsRecipients($settings=null): array
    {
        return array_values(SmsService::targets(is_array($settings)?$settings:$this->getActiveSettings()));
    }
    private function validateSmsRecipientIds($ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids)>50) { return [_('SMS recipients must be a list of at most 50 saved recipient IDs.')]; }
        $seen=[];
        foreach ($ids as $id) { if (!SmsConfig::id($id) || isset($seen[$id])) { return [_('Select distinct saved SMS recipients; raw phone numbers are not accepted.')]; } $seen[$id]=true; }
        return [];
    }
    private function readAnnouncementSmsForm(array $input,array $current): array
    {
        if (!array_key_exists('announcement_sms_present',$input)) { return $current; }
        if (($input['announcement_sms_present']??'')!=='1' || ($input['announcement_sms_complete']??'')!=='1'
            || !is_string($input['announcement_sms_recipients_json']??null) || strlen($input['announcement_sms_recipients_json'])>65536) {
            throw new \DomainException(_('The SMS recipient editor is incomplete. Reload with JavaScript enabled; no settings were changed.'));
        }
        try { $rows=json_decode($input['announcement_sms_recipients_json'],true,8,JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \DomainException(_('The SMS recipient list is invalid. Reload before saving.')); }
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>50) { throw new \DomainException(_('Save at most 50 SMS recipients.')); }
        $current=SmsConfig::normalize($current); $next=$current; $known=array_column($current['recipients'],null,'id');
        foreach ($rows as &$row) {
            if (!is_array($row)) { throw new \DomainException(_('An SMS recipient row is invalid.')); }
            $old=$known[$row['id']??'']??null; $renew=$row['renew_consent']??false;
            if (!is_bool($renew)) { throw new \DomainException(_('The SMS renewed-consent choice must be on or off.')); }
            unset($row['renew_consent']);
            if ($renew && ($row['consent_note']??'')===($old['consent_note']??'')) { throw new \DomainException(_('Update the consent note when recording renewed SMS consent.')); }
            $fresh=$renew || !$old || empty($old['consent']) || ($row['number']??'')!==$old['number'];
            if ($old && $fresh && ($row['consent']??false)===true && ($row['consent_note']??'')===$old['consent_note']) {
                throw new \DomainException(_('Update the consent note before restoring SMS consent or changing a consented phone number. Record when and how this recipient agreed.'));
            }
            $row['consent_at']=($row['consent']??false)===true?($fresh?gmdate('c'):$old['consent_at']):'';
        }
        unset($row);
        $next['enabled']=($input['announcement_sms_enabled']??'0')==='1';
        foreach (['provider','from','organization','currency','message_format','twilio_account_sid','twilio_key_sid','telnyx_public_key','telnyx_profile_id','bulkvs_api_username'] as $key) {
            $next[$key]=$input['sms_'.$key]??$current[$key];
        }
        foreach (['twilio_key_secret','twilio_auth_token','telnyx_api_key','bulkvs_api_password'] as $key) {
            $value=$input['sms_'.$key]??'';
            if (!is_string($value)) { throw new \DomainException(_('An SMS credential has an invalid type.')); }
            if ($value!=='') { $next[$key]=$value; }
            elseif (($input['sms_clear_'.$key]??'0')==='1') { $next[$key]=''; }
        }
        foreach (['max_segments','daily_segments','monthly_segments'] as $key) {
            $value=$input['sms_'.$key]??$current[$key];
            if ((!is_string($value)&&!is_int($value)) || !preg_match('/^[0-9]{1,8}$/D',(string)$value)) { throw new \DomainException(_('SMS segment limits must be whole numbers.')); }
            $next[$key]=(int)$value;
        }
        foreach (['segment_cost','mms_cost','daily_budget','monthly_budget'] as $key) {
            if (array_key_exists('sms_'.$key,$input)) { $next[$key.'_micros']=SmsConfig::amount($input['sms_'.$key]); }
        }
        $next['recipients']=$rows;
        return SmsConfig::normalize($next,true);
    }
    protected function announcementSmsStore(): SmsStore { return new SmsStore(self::PLUGIN_DATA_DIR.'/sms'); }
    protected function announcementSmsService(): SmsService
    {
        return new SmsService($this->announcementSmsStore(), null, '/var/www/html/sls_mass_notify',
            method_exists($this, 'receiveIncidentSmsResponse') ? fn(array $event)=>$this->receiveIncidentSmsResponse($event) : null);
    }
    public function previewSmsMessage(array $input): array
    {
        $settings=$this->getActiveSettings(); $config=SmsConfig::normalize($settings['announcement_sms']??[]);
        if (trim($config['organization'])==='') { throw new \DomainException(_('Set the SMS organization label in General Settings before previewing the complete text.')); }
        $body=SmsConfig::body($config,$input['title']??'Announcement',$input['message'],($input['is_test']??false)===true);
        $type=$config['message_format']==='mms'?'MMS':'SMS'; $segments=SmsConfig::units($body,$config['from'],$type);
        return ['success'=>true,'kind'=>'sms','text'=>$body,'segments'=>$segments['segments'],'encoding'=>$segments['encoding'],
            'budgeted_cost_micros'=>$type==='MMS'?$config['mms_cost_micros']:$segments['segments']*$config['segment_cost_micros'],'currency'=>$config['currency'],
            'message_type'=>$type,'unit'=>$type==='MMS'?'MMS message':'SMS segment',
            'within_limit'=>$type==='MMS' || $segments['segments']<=$config['max_segments'],'max_segments'=>$config['max_segments']];
    }
    private function prepareMmsImage(array $request, array $settings): array
    {
        $config=SmsConfig::normalize($settings['announcement_sms']??[]);
        if ($config['message_format']!=='mms') { return []; }
        // Reuse the private renderer. No public file or provider request is
        // created during review; the queued worker publishes frozen bytes.
        $image=$this->renderPhoneImagePreview(mb_substr($request['message'],0,500),$request['title'],$request['background_color']??'#1f2937',true);
        $bytes=base64_decode($image['data'],true);
        if (!is_string($bytes) || strlen($bytes)>300000) { throw new \DomainException(_('The MMS alert image exceeds 300 KB. Use SMS text or shorten its image summary.')); }
        $origin=parse_url(SmsService::callbackUrl($settings));
        $url='https://'.$origin['host'].(isset($origin['port'])?':'.$origin['port']:'').'/sls_mass_notify/mms_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(16)).'.png';
        return ['url'=>$url,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'data'=>$image['data']];
    }
    private function deliverAnnouncementSmsTargets(array $request,array &$receipts,callable $progress): void
    {
        $ids=$request['sms_recipient_ids']??[];
        if (isset($request['only_channels'])) { $ids=array_values(array_intersect($ids,$request['only_channels']['sms']??[])); }
        if (!$ids) { return; }
        $started=hrtime(true); $service=null;
        foreach ($ids as $id) {
            $row=['channel'=>'sms','target'=>$id,'state'=>'cancelled','detail'=>'SMS recipient or sending permission changed after Send.',
                'retryable'=>false,'submission_started'=>false];
            $settings=$this->getActiveSettings(); $allowed=true;
            if (!empty($request['weather_context'])) {
                try { $weather=$this->weatherChannelAuthorization($request['weather_context']); }
                catch (\Throwable $error) { $weather=['status'=>'deferred','sms_recipient_ids'=>[]]; }
                $allowed=$weather['status']==='eligible' && in_array($id,$weather['sms_recipient_ids']??[],true);
                if (!$allowed) { $row['detail']='Weather source, quiet-hours policy or original SMS permission no longer allows submission.'; }
            }
            if (isset($request['api_credential_id'])) {
                $principal=\SLS\MassNotify\ApiSecurity::currentCredential($settings,$request['api_credential_id']);
                $allowed=$allowed && $principal && \SLS\MassNotify\ApiSecurity::permits($principal,'send')
                    && (($principal['audience']['unrestricted']??false)===true || in_array($id,\SLS\MassNotify\ApiSecurity::allowedAudience($principal,$settings)['sms_recipient_ids']??[],true));
            }
            if ($allowed && !empty($request['automation_context']) && !$this->automationContextPermitted($request['automation_context'])) {
                $allowed=false; $row['detail']='Trigger changed, expired or was superseded before SMS submission.';
            }
            $deadline=$this->scheduledAnnouncementDeadline($request);
            if ($allowed && $deadline!==null && time()>=$deadline) { $allowed=false; $row['detail']='The scheduled start deadline passed before SMS submission.'; }
            if ($allowed && (hrtime(true)-$started)/1e9>78) { $allowed=false; $row['state']='failed'; $row['detail']='The 90-second SMS batch budget ended before this recipient started. No SMS was submitted.'; }
            if ($allowed) {
                $index=count($receipts); $row['state']='submitting'; $row['detail']='Recording SMS submission intent; provider acceptance is not yet confirmed.';
                $receipts[]=$row; $progress($receipts,'Preparing saved SMS destination…');
                try {
                    if (!is_int($request['sms_expires_at']??null) || !is_array($request['sms_targets'][$id]??null)) { throw new \DomainException('sms_delivery_identity_missing'); }
                    $service=$service??$this->announcementSmsService();
                    $clusterRequest=$request;
                    if (\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) {
                        $smsConfig=SmsConfig::normalize($settings['announcement_sms']??[]);
                        $clusterConfig=\SLS\MassNotify\EnterpriseClusterConfig::normalize($settings['enterprise_cluster']);
                        if ($smsConfig['currency']!==$clusterConfig['spend_currency']) { throw new \DomainException('Cluster and SMS spending currencies differ.'); }
                        $type=($request['sms_targets'][$id]['message_type']??'SMS')==='MMS'?'MMS':'SMS';
                        $units=SmsConfig::units($request['sms_targets'][$id]['body'],$smsConfig['from'],$type);
                        $micros=$type==='MMS'?$smsConfig['mms_cost_micros']:$units['segments']*$smsConfig['segment_cost_micros'];
                        $clusterRequest['estimated_cost_cents']=(int)ceil($micros/10000);
                    }
                    $receipts[$index]=\SLS\MassNotify\EnterpriseClusterIntegration::effect($settings,$clusterRequest,'sms',(string)$id,
                        fn()=>$service->send($settings,$request['sms_targets'][$id],$request['delivery_id'],
                            $request['sms_attempt_id']??'',min($request['sms_expires_at'],$deadline??PHP_INT_MAX),time(),$request['sms_media']['data']??''));
                } catch (\DomainException $error) {
                    $row['state']='failed'; $row['detail']='SMS was not submitted: '.$error->getMessage().'. Review SMS settings, recipient consent and limits.';
                    $receipts[$index]=$row;
                } catch (\Throwable $error) {
                    $row['state']='uncertain'; $row['detail']='SMS state could not be committed. Check SMS storage and provider receipts before sending another alert.';
                    $row['sms_delivery_id']='smsd_'.hash('sha256',($request['delivery_id']??'')."\0".$id); $receipts[$index]=$row;
                }
                $progress($receipts,'Recorded SMS destination outcome.');
            } else { $receipts[]=$row; $progress($receipts,'Recorded SMS destination exclusion.'); }
        }
    }
    private function refreshAnnouncementSmsReceipts(array $result): array
    {
        $store=null;
        foreach ($result['receipts']??[] as $index=>$receipt) {
            if (($receipt['channel']??'')!=='sms' || !is_string($receipt['sms_delivery_id']??null)
                || !preg_match('/^smsd_[a-f0-9]{64}$/D',$receipt['sms_delivery_id'])) { continue; }
            try {
                $store=$store??$this->announcementSmsStore(); $row=$store->get($receipt['sms_delivery_id']);
                if ($row && $row['recipient_id']===$receipt['target']) { $result['receipts'][$index]=SmsService::receipt($row); }
            } catch (\Throwable $error) { $result['sms_receipts_unavailable']=true; }
        }
        $smsFailed=false; $uncertain=false;
        foreach ($result['receipts']??[] as $receipt) {
            if (($receipt['channel']??'')!=='sms') { continue; }
            $smsFailed=$smsFailed || in_array($receipt['state']??'', ['failed','uncertain'],true);
            $uncertain=$uncertain || ($receipt['state']??'')==='uncertain';
        }
        if ($smsFailed && !in_array($result['state']??'', ['prepared','queued','worker_starting','running'],true)) {
            $result['success']=false; $result['state']='partial_or_failed';
            $result['message']='An SMS destination failed or remains unconfirmed. Open delivery details for its provider receipt. SMS is never retried automatically.';
        }
        $result['submission_uncertain']=!empty($result['submission_uncertain']) || $uncertain;
        return $result;
    }
}
