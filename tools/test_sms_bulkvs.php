<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/sms/Service.php';
use SLS\MassNotify\Sms\{Config, Provider, Service, Store};
final class BulkvsFixtureProvider extends Provider
{
    public array $requests = [];
    public array $response = [];
    protected function lookup(string $url, string $basic): array
    {
        $this->requests[] = ['lookup'=>$url, 'basic'=>$basic];
        return $this->response;
    }
    protected function request(string $url, array $headers, string $body, ?string $basic): array
    {
        $this->requests[] = compact('url','headers','body','basic');
        return $this->response;
    }
}
$count=0;
$check=static function(bool $ok,string $message)use(&$count):void{++$count;if(!$ok)throw new RuntimeException($message);};
$config=Config::normalize(['enabled'=>true,'provider'=>'bulkvs','from'=>'+13109060901','organization'=>'Fixture',
    'bulkvs_api_username'=>'fixture-api-user','bulkvs_api_password'=>'fixture-api-token','segment_cost_micros'=>10000,
    'recipients'=>[['id'=>'sms_'.str_repeat('a',24),'name'=>'Fixture','number'=>'+13105551212','enabled'=>true,
        'consent'=>true,'consent_note'=>'Isolated fixture','consent_at'=>gmdate('c',time()-3600)]]]);
$provider=new BulkvsFixtureProvider(); $now=time(); $id='5a66dee6-ff7a-40ee-8218-5805c074dc01';
$accepted=['RefId'=>$id,'From'=>'13109060901','MessageType'=>'SMS','Fragments'=>1,
    'Results'=>[['To'=>'13105551212','Status'=>'SUCCESS']]];
$provider->response=['status'=>200,'error'=>0,'body'=>json_encode($accepted)];
$callback='https://pbx.example.test:8443/api/sls-mass-notify/sms-callback.php?delivery=smsd_'.str_repeat('a',64);
$send=static fn()=>$provider->send($config,'+13105551212','Complete fixture alert',$callback,$now+900,$now);
$check($send()['state']==='accepted','BulkVS acceptance was lost.');
$request=$provider->requests[0];
$check($request['url']==='https://portal.bulkvs.com/api/v1.0/messageSend','Unexpected provider destination.');
$check(json_decode($request['body'],true)===['From'=>'13109060901','To'=>['13105551212'],'Message'=>'Complete fixture alert'],'Published request schema or exact recipient changed.');
$check($request['basic']==='fixture-api-user:fixture-api-token','API credentials not used for Basic authentication.');
foreach (['twilio','telnyx'] as $name) {
    $body=Config::body(array_replace($config,['provider'=>$name]),'Notice','Complete original instructions',true);
    $check(str_starts_with($body,'[TEST] Fixture: Notice')&&str_contains($body,'Complete original instructions')&&str_ends_with($body,'Reply STOP to opt out.'),'Test metadata, complete text or supported opt-out footer lost.');
}
$check(!str_contains(Config::body($config,'Notice','Complete message',false),'Reply STOP'),'Unverified BulkVS inbound STOP was promised.');
$provider->response=['status'=>200,'error'=>0,'body'=>json_encode([['TN'=>'13109060901','Messaging'=>['Sms'=>false,'Class'=>'A2PLC','Tcr'=>'']]])];
$diagnostic=$provider->diagnoseBulkvs($config);
$check(!$diagnostic['sms_enabled']&&!$diagnostic['campaign_configured']&&count($diagnostic['issues'])===2,'Disabled sender/campaign check failed.');
$check(end($provider->requests)['lookup']==='https://portal.bulkvs.com/api/v1.0/tnRecord?Number=13109060901','Sender check used a sending/provisioning route.');
$provider->response=['status'=>409,'error'=>0,'body'=>json_encode(['Description'=>'SMS disabled for 13109060901; fixture-api-token https://private.example/token <b>review</b>', 'Code'=>'7401'])];
$failure=$send();
$check(str_contains($failure['error_detail'],'SMS disabled')&&str_contains($failure['error_detail'],'7401')
    &&!str_contains($failure['error_detail'],'fixture-api-token')&&!str_contains($failure['error_detail'],'13109060901')
    &&!str_contains($failure['error_detail'],'https://')&&!str_contains($failure['error_detail'],'<b>'),'Provider diagnostics leaked private data or lost its cause.');
foreach ([400,401,403,404,409,429] as $http) {
    $provider->response=['status'=>$http,'error'=>0,'body'=>'private provider diagnostics'];
    $result=$send();$check($result['state']==='failed'&&$result['error_code']==='bulkvs_http_'.$http,'HTTP rejection lost its safe error code.');
}
foreach ([['status'=>503,'error'=>0,'body'=>''],['status'=>200,'error'=>28,'body'=>''],['status'=>302,'error'=>0,'body'=>''],
    ['status'=>200,'error'=>0,'body'=>'{invalid']] as $response) {
    $provider->response=$response;$check($send()['state']==='uncertain','Ambiguous provider submission became retryable or successful.');
}
foreach ([array_replace($accepted,['From'=>'13109060902']),array_replace($accepted,['RefId'=>'invalid']),
    array_replace($accepted,['MessageType'=>'MMS']),array_replace($accepted,['Results'=>[['To'=>'13105551213','Status'=>'SUCCESS']]]),
    array_replace($accepted,['Results'=>[$accepted['Results'][0],$accepted['Results'][0]]])] as $wrong) {
    $provider->response=['status'=>200,'error'=>0,'body'=>json_encode($wrong)];
    $check($send()['state']==='uncertain','A mismatched provider receipt confirmed this recipient.');
}
$before=count($provider->requests);
$check($provider->send($config,'+13105551212','Expired',$callback,$now,$now)['submission_started']===false
    &&count($provider->requests)===$before,'An expired message contacted the provider.');
$root=sys_get_temp_dir().'/sls-bulkvs-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $store=new Store($root.'/ledger');$service=new Service($store,$provider);
    $settings=['announcement_sms'=>$config,'public_pbx_host'=>'pbx.example.test',
        'control_api'=>['base_url'=>'https://pbx.example.test:8443/api/sls-mass-notify']];
    $recipient=$config['recipients'][0]['id'];$snapshot=Service::snapshot($settings,[$recipient],'Notice','Fixture only',false)[$recipient];
    $provider->response=['status'=>200,'error'=>0,'body'=>json_encode($accepted)];$before=count($provider->requests);
    $delivery='announcement-'.str_repeat('1',32);$job='job_'.str_repeat('2',32);
    $receipt=$service->send($settings,$snapshot,$delivery,$job,$now+900,$now);
    $service->send($settings,$snapshot,$delivery,$job,$now+900,$now+1);
    $check($receipt['state']==='accepted'&&count($provider->requests)===$before+1,'Duplicate submission created another billable message.');
    $check(str_contains($receipt['detail'],'inbound opt-out updates are not confirmed'),'Unimplemented callback evidence was claimed.');
    $provider->response=['status'=>409,'error'=>0,'body'=>json_encode(['Description'=>'Sender SMS disabled','Code'=>'7401'])];
    $failedDelivery='announcement-'.str_repeat('4',32);
    $failed=$service->send($settings,$snapshot,$failedDelivery,$job,$now+900,$now);
    $before=count($provider->requests);
    $duplicate=$service->send($settings,$snapshot,$failedDelivery,$job,$now+900,$now+1);
    $check(str_contains($failed['detail'],'Sender SMS disabled')&&$duplicate['detail']===$failed['detail']&&count($provider->requests)===$before,'Failure diagnostics did not persist through duplicate receipt reads.');
    $snapshotFile=$root.'/snapshot.jsonl';$store->exportSnapshot($snapshotFile);$restored=new Store($root.'/restored');$restored->mergeSnapshot($snapshotFile);
    $check($restored->get($receipt['sms_delivery_id'])['provider_id']===$id,'BulkVS receipt failed backup/restore.');
    $check($restored->get($failed['sms_delivery_id'])['error_detail']==='Sender SMS disabled Provider code: 7401.','Provider diagnostics failed backup/restore.');
    $legacyFile=$root.'/legacy-snapshot.jsonl';
    $lines=file($snapshotFile);file_put_contents($legacyFile,implode('',array_filter($lines,static fn($line)=>!str_contains($line,'"table":"provider_errors"'))));
    $legacy=new Store($root.'/legacy-restored');$legacy->mergeSnapshot($legacyFile);
    $check($legacy->get($failed['sms_delivery_id'])['error_code']==='bulkvs_http_409'&&$legacy->get($failed['sms_delivery_id'])['error_detail']==='','Legacy V1 receipt backup failed to restore.');
    $store->optOut('fixture_stop',hash('sha256','+13105551212'),$now+2);
    try {$service->send($settings,$snapshot,'announcement-'.str_repeat('3',32),$job,$now+900,$now+3);throw new RuntimeException('Opt-out not enforced.');}
    catch (DomainException $error) {$check($error->getMessage()==='sms_recipient_opted_out','Opt-out rejection reason lost.');}
    try {Provider::callback($config,$callback,'{}',[],$now);throw new RuntimeException('Unsigned BulkVS callback accepted.');}
    catch (DomainException $error) {$check($error->getMessage()==='sms_callback_unauthorized','Unsupported callbacks did not fail closed.');}
    echo "$count BulkVS schema, acceptance, failure, duplicate, budget, expiry, opt-out and continuity checks passed. No network requests sent.\n";
} finally {
    unset($restored,$service,$store);
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
