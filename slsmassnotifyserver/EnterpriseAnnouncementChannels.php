<?php
declare(strict_types=1);
namespace FreePBX\modules;
use SLS\MassNotify\{ApiSecurity,SubscriberConfig,EnterpriseIntegrationsConfig,EnterpriseDeliveryConnector,EnterpriseClusterIntegration,BrowserPush};
require_once __DIR__.'/EnterpriseDeliveryConnector.php';
require_once __DIR__.'/BrowserPush.php';
require_once __DIR__.'/bin/sls_mass_notify/sls_runtime_state.php';

/** Additive Labs channels use the existing immutable queue and live authorization. */
trait SlsEnterpriseAnnouncementChannels
{
    protected function snapshotEnterpriseAnnouncementChannels(array $request,array $settings): array
    {
        $browser=$request['browser_recipient_ids']??[];$connectors=$request['connector_ids']??[];
        foreach([$browser,$connectors] as $ids){if(!is_array($ids)||!array_is_list($ids)||count($ids)>50||count(array_unique($ids))!==count($ids)){throw new \DomainException('Choose at most 50 distinct Labs recipients.');}}
        $request['browser_targets']=[];$request['connector_fingerprints']=[];
        if(!$browser&&!$connectors){return $request;}
        $principal=$GLOBALS['sls_control_principal']??null;
        if(is_array($principal)&&(($principal['id']??'')==='legacy'||($principal['audience']['unrestricted']??false)!==true||!ApiSecurity::permits($principal,'send'))){throw new \DomainException('Labs notification channels require an unrestricted named sender.');}
        $subscriber=SubscriberConfig::normalize($settings['subscriber_browser']??[]);
        foreach($browser as $id){if(!is_string($id)||!preg_match('/^sub_[a-f0-9]{24}$/D',$id)||!$subscriber['push']['enabled']){throw new \DomainException('Select enabled browser push recipients.');}
            $person=SubscriberConfig::person($settings,$id);$endpoints=$this->subscriberService()->pushSnapshot($settings,$id);
            if(!$endpoints){throw new \DomainException($person['name'].' has no currently enrolled browser.');}
            $request['browser_targets'][$id]=['identity'=>SubscriberConfig::identity($person),'endpoint_ids'=>array_keys($endpoints),'endpoint_fingerprints'=>$endpoints];
        }
        $config=EnterpriseIntegrationsConfig::normalize($settings['enterprise_integrations']??[]);$destinations=array_column($config['connectors']['destinations'],null,'id');
        foreach($connectors as $id){if(!is_string($id)||!isset($destinations[$id])||$config['enabled']!=='1'||$config['connectors']['enabled']!=='1'||$destinations[$id]['enabled']!=='1'){throw new \DomainException('A selected notification connector is disabled or missing.');}
            $request['connector_fingerprints'][$id]=EnterpriseIntegrationsConfig::fingerprint($destinations[$id]);
        }
        $request['force_queue']=true;return $request;
    }
    private function enterpriseQueuedChannelPermitted(array $request,array $settings): bool
    {
        if(!\SLS\MassNotify\RuntimeState::running($settings)){return false;}
        if(!empty($request['automation_context'])&&!$this->automationContextPermitted($request['automation_context'])){return false;}
        if(method_exists($this,'enterpriseApprovalPermitted')&&!$this->enterpriseApprovalPermitted($request)){return false;}
        if(isset($request['api_credential_id'])){$principal=ApiSecurity::currentCredential($settings,$request['api_credential_id']);if(!$principal||!ApiSecurity::permits($principal,'send')||($principal['audience']['unrestricted']??false)!==true){return false;}}
        return true;
    }
    protected function transmitEnterpriseBrowserPush(string $url,string $body,array $headers): array { return BrowserPush::transmit($url,$body,$headers); }
    protected function transmitEnterpriseConnector(array $destination,array $event): array { return EnterpriseDeliveryConnector::send($destination,$event); }
    protected function deliverEnterpriseAnnouncementChannels(array $request,callable $record,?int $deadline): void
    {
        $created=strtotime($request['delivery_timestamp']??'');$expires=$created+min(600,max(1,(int)(($request['display_timeout']??600)?:600)));
        if($deadline!==null){$expires=min($expires,$deadline);}
        if(isset($request['incident_context']['expires_at'])){$incidentExpiry=strtotime($request['incident_context']['expires_at']);if($incidentExpiry===false){throw new \DomainException('Incident expiry is invalid; Labs delivery was fenced.');}$expires=min($expires,$incidentExpiry);}
        $event=['event_id'=>$request['delivery_id'],'title'=>$request['title'],'message'=>$request['message'],'expires_at'=>$expires,'is_test'=>($request['is_test']??false)===true];
        $only=$request['only_channels']??null;
        $phaseDeadline=min($expires,time()+90);
        foreach($request['browser_targets']??[] as $id=>$target){
            if($only!==null&&!in_array($id,$only['browser_push']??[],true)){continue;}$settings=$this->getActiveSettings();
            try{$person=SubscriberConfig::person($settings,$id);$config=SubscriberConfig::normalize($settings['subscriber_browser']);
                if(!$config['push']['enabled']||!hash_equals($target['identity'],SubscriberConfig::identity($person))||!$this->enterpriseQueuedChannelPermitted($request,$settings)||$phaseDeadline<=time()){throw new \DomainException('Browser destination changed, authorization was revoked or the submission deadline passed.');}
                $transport=function(string $url,string $body,array $headers)use($settings,$request,$phaseDeadline,$expires):array{
                    $now=time();$live=$this->getActiveSettings();$push=SubscriberConfig::normalize($live['subscriber_browser']??[])['push'];
                    if($phaseDeadline<=$now||!$push['enabled']||!hash_equals($settings['subscriber_browser']['push']['private_key_pem'],$push['private_key_pem'])||!$this->enterpriseQueuedChannelPermitted($request,$live)){throw new \DomainException('Browser push was disabled, authorization changed or the submission deadline passed before this endpoint could be sent.');}
                    foreach($headers as &$header){if(str_starts_with($header,'TTL: ')){$header='TTL: '.($expires-$now);}}unset($header);
                    return EnterpriseClusterIntegration::effect($live,$request,'browser_push',hash('sha256',$url),fn()=>$this->transmitEnterpriseBrowserPush($url,$body,$headers));
                };
                $outcome=$this->subscriberService()->push($settings,$id,$event,time(),$transport,$target['endpoint_fingerprints']??$target['endpoint_ids']);$accepted=0;$uncertain=0;
                foreach($outcome['receipts'] as $receipt){$accepted+=$receipt['state']==='accepted'?1:0;$uncertain+=$receipt['state']==='unconfirmed'?1:0;}
                $record('browser_push',$id,$uncertain?'uncertain':($accepted?'accepted':'cancelled'),$accepted.' push-service acceptance(s); browser display and human response are not confirmed.',false,['event_id'=>$event['event_id']]);
            }catch(\DomainException $error){$record('browser_push',$id,'cancelled',$error->getMessage(),false);}catch(\Throwable $error){$record('browser_push',$id,'uncertain','Browser submission completion is unconfirmed. No automatic replay.',false);}
        }
        foreach($request['connector_fingerprints']??[] as $id=>$fingerprint){
            if($only!==null&&!in_array($id,$only['connector']??[],true)){continue;}$settings=$this->getActiveSettings();
            try{$config=EnterpriseIntegrationsConfig::normalize($settings['enterprise_integrations']??[]);$destination=array_column($config['connectors']['destinations'],null,'id')[$id]??null;
                if(!$destination||$config['enabled']!=='1'||$config['connectors']['enabled']!=='1'||!hash_equals($fingerprint,EnterpriseIntegrationsConfig::fingerprint($destination))||!$this->enterpriseQueuedChannelPermitted($request,$settings)||$phaseDeadline<=time()){throw new \DomainException('Connector changed, authorization was revoked or the submission deadline passed.');}
                $claim=hash('sha256',$request['delivery_id'].'|'.$id);$store=$this->enterpriseLabsStore();$existing=$store->transaction('connector_claims',static function(array &$rows)use($claim,$expires):?array{
                    foreach($rows as $key=>$row){if($row['expires_at']<time()){unset($rows[$key]);}}if(isset($rows[$claim])){return $rows[$claim]['receipt']??['state'=>'uncertain','detail'=>'Earlier connector completion is unconfirmed. No replay.'];}
                    if(count($rows)>=10000){throw new \DomainException('Connector journal allocation reached.');}$rows[$claim]=['expires_at'=>$expires+86400];return null;
                });
                if($existing!==null){$record('connector',$id,$existing['state'],$existing['detail'],false);continue;}
                $receipt=EnterpriseClusterIntegration::effect($settings,$request,'connector',$id,fn()=>$this->transmitEnterpriseConnector($destination,$event));
                $store->transaction('connector_claims',static function(array &$rows)use($claim,$receipt):void{$rows[$claim]['receipt']=$receipt;});$record('connector',$id,$receipt['state'],$receipt['detail'],false);
            }catch(\DomainException $error){$record('connector',$id,'cancelled',$error->getMessage(),false);}catch(\Throwable $error){$record('connector',$id,'uncertain','Connector completion is unconfirmed. No automatic replay.',false);}
        }
    }
}
