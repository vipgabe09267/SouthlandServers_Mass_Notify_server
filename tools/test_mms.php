<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/sms/Service.php';
use SLS\MassNotify\Sms\{Config,Provider,Service,Store};
class MmsFixtureProvider extends Provider {
    public array $calls=[]; public array $reply=[];
    protected function request(string $url,array $headers,string $body,?string $basic):array {
        $this->calls[]=compact('url','headers','body','basic'); return $this->reply;
    }
}
$count=0;
function mmsCheck(bool $ok,string $message):void {global $count;$count++;if(!$ok)throw new RuntimeException($message);}
function mmsReject(callable $work):void {try{$work();}catch(DomainException|InvalidArgumentException $e){mmsCheck(true,'Rejected');return;}throw new RuntimeException('Unsafe MMS accepted.');}
$dir=sys_get_temp_dir().'/sls-mms-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/media',0700);
$data=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jH1cAAAAASUVORK5CYII=',true);
$filename='mms_20261002000000_'.str_repeat('c',32).'.png';
$media=['url'=>'https://pbx.example.test:8443/sls_mass_notify/'.$filename,'sha256'=>hash('sha256',$data),'bytes'=>strlen($data)];
$now=1790899200;$callback='https://pbx.example.test:8443/api/sls-mass-notify/sms-callback.php';
$number='+15555550102';$id='sms_'.str_repeat('d',24);
$config=Config::defaults();$config=array_replace($config,['enabled'=>true,'provider'=>'twilio','from'=>'+15555550101','organization'=>'Test PBX','message_format'=>'mms','mms_cost_micros'=>30000,'segment_cost_micros'=>6000,
    'twilio_account_sid'=>'AC'.str_repeat('a',32),'twilio_key_sid'=>'SK'.str_repeat('b',32),'twilio_key_secret'=>'fixture-key','twilio_auth_token'=>str_repeat('e',32),
    'telnyx_api_key'=>'fixture-key','telnyx_public_key'=>base64_encode(str_repeat('k',32)),'telnyx_profile_id'=>'11111111-2222-3333-4444-555555555555',
    'bulkvs_api_username'=>'fixture-user','bulkvs_api_password'=>'fixture-token',
    'recipients'=>[['id'=>$id,'name'=>'Fixture','number'=>$number,'enabled'=>true,'consent'=>true,'consent_note'=>'Fixture consent','consent_at'=>gmdate('c',$now-100)]]]);
try {
    foreach(['twilio','telnyx','bulkvs'] as $index=>$provider) {
        $config['provider']=$provider;$settings=['public_pbx_host'=>'pbx.example.test','control_api'=>['base_url'=>'https://pbx.example.test:8443/api/sls-mass-notify'],'announcement_sms'=>$config];
        $frozen=Service::snapshot($settings,[$id],'MMS validation','Full instructions remain in the body. This is a test.',true,$media)[$id];
        mmsCheck($frozen['message_type']==='MMS'&&str_contains($frozen['body'],'Full instructions'),'MMS lost complete text.');
        $fake=new MmsFixtureProvider();
        $reply=match($provider){
            'twilio'=>['sid'=>'MM'.str_repeat('1',32),'account_sid'=>$config['twilio_account_sid'],'from'=>$config['from'],'to'=>$number],
            'telnyx'=>['data'=>['id'=>'22222222-3333-4444-5555-666666666666','from'=>['phone_number'=>$config['from']],'to'=>[['phone_number'=>$number]],'messaging_profile_id'=>$config['telnyx_profile_id']]],
            default=>['RefId'=>'33333333-4444-5555-6666-777777777777','From'=>substr($config['from'],1),'MessageType'=>'MMS','Results'=>[['To'=>substr($number,1),'Status'=>'SUCCESS']]]};
        $fake->reply=['status'=>201,'error'=>0,'body'=>json_encode($reply)];
        $store=new Store($dir.'/'.$provider);$service=new Service($store,$fake,$dir.'/media');$delivery='announcement-'.str_repeat((string)($index+1),32);$job='job_'.str_repeat((string)($index+1),32);
        $receipt=$service->send($settings,$frozen,$delivery,$job,$now+600,$now,base64_encode($data));
        mmsCheck($receipt['state']==='accepted'&&$receipt['message_type']==='MMS'&&$receipt['segments']===1&&$receipt['budgeted_cost_micros']===30000,'MMS claim/price/receipt failed.');
        mmsCheck(file_get_contents($dir.'/media/'.$filename)===$data,'Published media differs from frozen image.');
        $body=$fake->calls[0]['body'];
        if($provider==='twilio') {parse_str($body,$fields);mmsCheck($fields['MediaUrl']===$media['url']&&$fields['To']===$number&&$fields['Body']===$frozen['body'],'Twilio MediaUrl schema wrong.');}
        else {$fields=json_decode($body,true);mmsCheck(($provider==='bulkvs'?$fields['MediaURLs']:$fields['media_urls'])===[$media['url']],'Provider media schema wrong.');}
        $same=$service->send($settings,$frozen,$delivery,$job,$now+600,$now,base64_encode($data));mmsCheck(count($fake->calls)===1&&$same===$receipt,'Duplicate MMS was resubmitted.');
        $changed=$frozen;$changed['media']['sha256']=str_repeat('f',64);mmsReject(fn()=>$service->send($settings,$changed,$delivery,$job,$now+600,$now,base64_encode($data)));
        $bad=$settings;$bad['announcement_sms']['recipients'][0]['consent']=false;mmsReject(fn()=>$service->send($bad,$frozen,$delivery,$job,$now+600,$now,base64_encode($data)));
        $bad=$settings;$bad['announcement_sms']['monthly_budget_micros']=30000;
        mmsReject(fn()=>$service->send($bad,$frozen,'announcement-'.str_repeat('9',32),$job,$now+600,$now,base64_encode($data)));
        mmsCheck(count($fake->calls)===1,'Budget refusal transmitted another MMS.');
        $broken=$frozen;$broken['media']['url']=str_replace($filename,'mms_20261002000000_'.str_repeat('b',32).'.png',$media['url']);$broken['body_hash']=Config::messageHash($broken['body'],'MMS',$broken['media']);
        $receipt=$service->send($settings,$broken,'announcement-'.str_repeat('8',32),$job,$now+600,$now,'bad-data');
        mmsCheck($receipt['state']==='failed'&&!$receipt['submission_started']&&count($fake->calls)===1,'Image-storage failure transmitted or retried.');
        mmsCheck((new Store($dir.'/'.$provider))->get($receipt['sms_delivery_id'])['message_type']==='MMS','MMS subtype was not durable.');
    }
    $snapshot=$dir.'/mms.snapshot';$store->exportSnapshot($snapshot);
    mmsCheck(str_starts_with(file_get_contents($snapshot),'{"schema":"sls-sms-continuity-v2"}'),'MMS continuity format omitted.');
    $restored=new Store($dir.'/restored');$restored->mergeSnapshot($snapshot);$restored->mergeSnapshot($snapshot);
    mmsCheck($restored->get($receipt['sms_delivery_id'])['message_type']==='MMS','Restore erased MMS evidence.');
    foreach(['http://pbx.example.test:8443/sls_mass_notify/'.$filename,'https://other.test:8443/sls_mass_notify/'.$filename,'https://pbx.example.test/sls_mass_notify/'.$filename,'https://user@pbx.example.test:8443/sls_mass_notify/'.$filename,$media['url'].'?secret=value'] as $url) {
        mmsCheck(!Config::validMediaUrl($url,$callback),'Unsafe media origin accepted.');
    }
    mmsReject(fn()=>Service::snapshot($settings,[$id],'Alert','Body',false,[]));
    $oversize=$media;$oversize['bytes']=300001;mmsReject(fn()=>Service::validateMedia('MMS',$oversize,$callback));
    $plain=$settings;$plain['announcement_sms']['message_format']='sms';$old=Service::snapshot($plain,[$id],'Alert','Body',false)[$id];
    mmsCheck(!isset($old['message_type'],$old['media'])&&$old['body_hash']===hash('sha256',$old['body']),'Existing SMS snapshot contract changed.');
    mmsCheck(Config::defaults()['message_format']==='sms','MMS became an automatic default.');
    echo "$count MMS provider-schema, immutable-media, consent, budget, duplicate and failure checks passed; no network requests.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);
}
