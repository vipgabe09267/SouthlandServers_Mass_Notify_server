<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/EnterpriseIntegrationsConfig.php';

/** Documented provider wire contracts; HTTPS, fixed paths, no redirects/retries. */
class EnterpriseIntegrationsProvider
{
    protected function request(string $url,string $body,array $headers,?array $device=null): array
    {
        $curl=curl_init($url); $response='';
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT_MS=>2500,
            CURLOPT_TIMEOUT_MS=>10000,CURLOPT_NOSIGNAL=>true,CURLOPT_USERAGENT=>'SLS-Mass-Notify-Labs/1',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use(&$response): int {
                if (strlen($response)+strlen($chunk)>131072) { return 0; } $response.=$chunk; return strlen($chunk);
            }];
        if ($device!==null) {
            EnterpriseIntegrationsConfig::origin($device['origin'],$device['pinned_ipv4']);
            $host=parse_url($device['origin'],PHP_URL_HOST); $port=parse_url($device['origin'],PHP_URL_PORT)??443;
            $options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$device['pinned_ipv4']];
            $options[CURLOPT_HTTPAUTH]=CURLAUTH_DIGEST; $options[CURLOPT_USERPWD]=$device['username'].':'.$device['password'];
        }
        curl_setopt_array($curl,$options);
        try { $ok=curl_exec($curl); return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'error'=>$ok===false?curl_errno($curl):0,'body'=>$response]; }
        finally { curl_close($curl); }
    }
    private static function jsonResult(array $result,array $statuses): array
    {
        if (($result['error']??1)!==0) { throw new \RuntimeException('Provider submission is uncertain. It will not be repeated automatically.'); }
        if (!in_array($result['status']??0,$statuses,true)) { throw new \DomainException('Provider rejected the request (HTTP '.(int)($result['status']??0).').'); }
        $body=json_decode($result['body']??'',true,32,JSON_THROW_ON_ERROR);
        if (!is_array($body)) { throw new \RuntimeException('Provider response could not be confirmed. Review the provider before any new request.'); }
        return $body;
    }
    public function meeting(array $config,string $title,int $start,int $duration): array
    {
        if (($config['enabled']??'0')!=='1') { throw new \DomainException('Incident meeting creation is disabled.'); }
        $provider=$config['provider']; $token=$config[$provider==='google_meet'?'google_access_token':$provider.'_access_token']??'';
        if ($token==='' || preg_match('/[\r\n]/',$token)) { throw new \DomainException('A valid provider OAuth access token is required.'); }
        if ($provider==='zoom') {
            $url='https://api.zoom.us/v2/users/'.rawurlencode($config['zoom_user']).'/meetings';
            $payload=['topic'=>$title,'type'=>2,'start_time'=>gmdate('Y-m-d\TH:i:s\Z',$start),'duration'=>$duration,
                'settings'=>['waiting_room'=>true,'join_before_host'=>false]]; $statuses=[201];
        } elseif ($provider==='google_meet') {
            $url='https://meet.googleapis.com/v2/spaces'; $payload=['config'=>['accessType'=>'RESTRICTED']]; $statuses=[200];
        } elseif ($provider==='webex') {
            $url='https://webexapis.com/v1/meetings'; $payload=['title'=>$title,'start'=>gmdate('Y-m-d\TH:i:s\Z',$start),
                'end'=>gmdate('Y-m-d\TH:i:s\Z',$start+$duration*60)]; $statuses=[200,201];
        } else { throw new \InvalidArgumentException('Unknown meeting provider.'); }
        $body=self::jsonResult($this->request($url,json_encode($payload,JSON_THROW_ON_ERROR),['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$token]),$statuses);
        $id=(string)($body[$provider==='google_meet'?'name':'id']??''); $join=$body[$provider==='zoom'?'join_url':($provider==='google_meet'?'meetingUri':'webLink')]??'';
        $host=is_string($join)?strtolower((string)parse_url($join,PHP_URL_HOST)):'';
        $allowed=$provider==='google_meet'?$host==='meet.google.com':(bool)preg_match($provider==='zoom'?'/^(?:[a-z0-9-]+\.)*zoom\.us$/D':'/^(?:[a-z0-9-]+\.)*webex\.com$/D',$host);
        if ($id==='' || strlen($id)>256 || !is_string($join) || strlen($join)>2048 || parse_url($join,PHP_URL_SCHEME)!=='https' || parse_url($join,PHP_URL_USER)!==null || !$allowed) {
            throw new \RuntimeException('Meeting creation returned an unconfirmed identity or join link. Review the provider before creating another.');
        }
        // Host start URLs, host keys, passwords and OAuth tokens are never receipts.
        return ['state'=>'accepted','provider'=>$provider,'provider_id'=>$id,'join_url'=>$join,
            'detail'=>$provider==='google_meet'?'Google Meet space created. Calendar invitations and meeting attendance are not confirmed.':'Meeting created. Invitations and meeting attendance are not confirmed.'];
    }
    public function door(array $config,array $door,string $operation): array
    {
        if (($config['enabled']??'0')!=='1' || $config['origin']==='' || $config['username']==='' || $config['password']==='') { throw new \DomainException('Save and enable the authorized Axis controller before using its API.'); }
        if (!in_array($operation,['read','lock','unlock'],true) || empty($door['allow_'.$operation])) { throw new \DomainException('This operation is outside the saved door permission.'); }
        $command=['read'=>'tdc:GetDoorState','lock'=>'tdc:LockDoor','unlock'=>'tdc:UnlockDoor'][$operation];
        $body=self::jsonResult($this->request($config['origin'].'/vapix/doorcontrol',json_encode([$command=>['Token'=>$door['token']]],JSON_THROW_ON_ERROR),['Content-Type: application/json','Accept: application/json'],$config),[200]);
        if (isset($body['Fault']) || isset($body['fault']) || isset($body['error'])) { throw new \DomainException('The Axis controller returned an API fault.'); }
        if ($operation==='read') {
            $state=$body['DoorState']??null; if (!is_array($state)) { throw new \RuntimeException('Door state response is unconfirmed.'); }
            // Export only documented monitor/mode values, never account configuration.
            $fields=[]; foreach (['DoorMode','DoorPhysicalState','LockPhysicalState','DoubleLockPhysicalState','Alarm'] as $field) {
                if (is_string($state[$field]??null) && preg_match('/^[A-Za-z]{1,40}$/D',$state[$field])) { $fields[$field]=$state[$field]; }
            }
            return ['state'=>'observed','door_id'=>$door['id'],'observed'=>$fields,'detail'=>'Controller-reported door telemetry; physical verification may still be required.'];
        }
        if ($body!==[] && !isset($body[$operation==='lock'?'LockDoorResponse':'UnlockDoorResponse'])) { throw new \RuntimeException('Door command response is unconfirmed.'); }
        return ['state'=>'accepted','door_id'=>$door['id'],'operation'=>$operation,'detail'=>'Controller accepted the command. Physical door state is not confirmed.'];
    }
    public function speakerTelemetry(array $speaker): array
    {
        if (($speaker['enabled']??'0')!=='1' || ($speaker['brand']??'')!=='axis' || ($speaker['telemetry_origin']??'')==='') { throw new \DomainException('This speaker has no enabled documented telemetry adapter.'); }
        $device=['origin'=>$speaker['telemetry_origin'],'pinned_ipv4'=>$speaker['telemetry_ipv4'],'username'=>$speaker['telemetry_username'],'password'=>$speaker['telemetry_password']];
        $body=self::jsonResult($this->request($device['origin'].'/vapix/axast','{"axast:GetSpeakerTestReport":{}}',['Content-Type: application/json','Accept: application/json'],$device),[200]);
        $report=$body['SpeakerTestReport']??[]; $status=$report['SpeakerTestStatus']??'';
        if (!in_array($status,['OK','Failed','Calibrated','Pending','Uncalibrated','DisabledInHardware','InternalError'],true)) { throw new \RuntimeException('Speaker telemetry returned an unknown report.'); }
        $timestamps=[]; foreach (['TestTimestamp','CalibrationTimestamp'] as $field) {
            $time=$report[$field]??'';
            if (!is_string($time) || strlen($time)>40 || ($time!=='' && strtotime($time)===false)) { throw new \RuntimeException('Speaker telemetry timestamp is invalid.'); } $timestamps[$field]=$time;
        }
        return ['state'=>'observed','speaker_id'=>$speaker['id'],'report'=>['SpeakerTestStatus'=>$status]+$timestamps,
            'detail'=>'Read-only result of the last device speaker test. No test tones were started; this does not confirm that this announcement was heard.'];
    }
    public function ipaws(array $config,string $envelope): array
    {
        if (($config['enabled']??'0')!=='1' || ($config['ipaws_enabled']??'0')!=='1' || empty($config['authority_confirmed']) || $config['endpoint']==='') { throw new \DomainException('IPAWS requires an enabled authorized COG and a configured FEMA endpoint.'); }
        $action=$config['soap_action']??'';
        if ($action==='' || strlen($action)>512 || preg_match('/[\x00-\x20\"]/', $action)) { throw new \DomainException('Save the exact postCAP SOAPAction from the authority-issued WSDL.'); }
        $result=$this->request($config['endpoint'],$envelope,['Content-Type: text/xml; charset=utf-8','SOAPAction: "'.$action.'"']);
        if ($result['error']!==0) { throw new \RuntimeException('IPAWS submission is uncertain. Review FEMA status before submitting another alert.'); }
        if ($result['status']!==200) { throw new \DomainException('IPAWS rejected the request (HTTP '.(int)$result['status'].').'); }
        $document=new \DOMDocument(); if (preg_match('/<!DOCTYPE|<!ENTITY/i',$result['body']) || !@$document->loadXML($result['body'],LIBXML_NONET)) { throw new \RuntimeException('IPAWS receipt is unconfirmed.'); }
        $xpath=new \DOMXPath($document); if ($xpath->query('//*[local-name()="Fault"]')->length) { throw new \DomainException('IPAWS returned a SOAP fault.'); }
        $codes=[]; foreach ($xpath->query('//*[local-name()="subParaListItem"]') as $row) {
            $name=$xpath->evaluate('string(*[local-name()="subParameterName"])',$row); $value=$xpath->evaluate('string(*[local-name()="subParameterValue"])',$row);
            if ($name==='STATUSITEMID' && preg_match('/^[0-9]{1,4}$/D',$value)) { $codes[]=$value; }
        }
        if (!$codes) { throw new \RuntimeException('IPAWS acceptance is unconfirmed. Review FEMA status before another submission.'); }
        return ['state'=>'provider_reported','status_codes'=>array_values(array_unique($codes)),
            'detail'=>'IPAWS returned channel status codes. API transport success is not dissemination or public receipt confirmation.'];
    }
}
