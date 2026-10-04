<?php
declare(strict_types=1);
set_error_handler(static function ($code,$message,$file,$line) { if (error_reporting() & $code) { throw new ErrorException($message,0,$code,$file,$line); } return false; });
require dirname(__DIR__).'/slsmassnotifyserver/api/sls-mass-notify/sms/Service.php';
use SLS\MassNotify\Sms\{Config,Store,Provider,Service};
function check($ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $call,string $code): void {
    try { $call(); } catch (Throwable $error) { check(str_contains($error->getMessage(),$code),'Unexpected error: '.$error->getMessage()); return; }
    throw new RuntimeException('Expected failure: '.$code);
}
final class FixtureProvider extends Provider
{
    public array $requests=[]; public array $response=[]; public $during=null;
    protected function request(string $url,array $headers,string $body,?string $basic): array {
        $this->requests[]=[$url,$headers,$body,$basic];
        if ($this->during) { ($this->during)(); }
        return $this->response;
    }
}
function twilioBody(array $values,array $config,string $url): array {
    $sorted=$values; ksort($sorted); $signed=$url;
    foreach ($sorted as $key=>$value) { $signed.=$key.$value; }
    return [http_build_query($values),['content-type'=>'application/x-www-form-urlencoded','x-twilio-signature'=>base64_encode(hash_hmac('sha1',$signed,$config['twilio_auth_token'],true))]];
}
$directory=sys_get_temp_dir().'/sls-sms-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$now=time(); $recipient='sms_'.str_repeat('a',24); $message='SM'.str_repeat('b',32);
$config=Config::normalize(['enabled'=>true,'provider'=>'twilio','from'=>'+15555550101','organization'=>'SLS',
    'twilio_account_sid'=>'AC'.str_repeat('a',32),'twilio_key_sid'=>'SK'.str_repeat('b',32),'twilio_key_secret'=>'fixture-secret',
    'twilio_auth_token'=>str_repeat('c',32),'segment_cost_micros'=>10000,'daily_segments'=>2,
    'recipients'=>[['id'=>$recipient,'name'=>'Fixture recipient','number'=>'+15555550102','enabled'=>true,'consent'=>true,'consent_note'=>'Fixture only','consent_at'=>gmdate('c',$now-3600)]]]);
$settings=['announcement_sms'=>$config,'public_pbx_host'=>'pbx.example.com','control_api'=>['base_url'=>'https://pbx.example.com:8443/api/sls-mass-notify']];
try {
    check(!Config::normalize([])['enabled'],'SMS enabled by default');
    foreach ([[str_repeat('A',160),'',1],[str_repeat('A',161),'',2],[str_repeat('{',80),'',1],[str_repeat('{',81),'',2],
        [str_repeat('漢',70),'',1],[str_repeat('漢',71),'',2],[str_repeat('😀',35),'',1],[str_repeat('😀',36),'',2],
        [str_repeat('A',305),'+18005550101',3],[str_repeat('A',305),'+15555550101',2]] as [$text,$from,$count]) {
        check(Config::segments($text,$from)['segments']===$count,'Incorrect segment count');
    }
    check(Config::segments('£$¥èéùìòÇΔ_ΦΓΛΩΠΨΣΘΞ€')['encoding']==='GSM-7','GSM alphabet lost currency or national characters');
    check(Config::segments(Config::body($config,'Notice','All clear.',false))['encoding']==='GSM-7','Formatter forces Unicode cost');
    check(Config::amount('0.012345')===12345 && Config::amount('10')===10000000,'Amount parsing');
    rejects(fn()=>Config::amount('1e3'),'SMS amounts');
    rejects(fn()=>Config::normalize(['enabled'=>true]),'Before enabling');
    rejects(fn()=>Config::normalize(array_replace($config,['recipients'=>[$config['recipients'][0],$config['recipients'][0]]])),'duplicated');
    $store=new Store($directory.'/ledger'); $provider=new FixtureProvider(); $service=new Service($store,$provider);
    $frozen=Service::snapshot($settings,[$recipient],'Notice','Go to the meeting point.',false)[$recipient];
    $delivery='announcement-'.str_repeat('1',32); $job='job_'.str_repeat('2',32);
    $redirected=$frozen; $redirected['callback']='https://other.example.com/api/sls-mass-notify/sms-callback.php';
    rejects(fn()=>$service->send($settings,$redirected,$delivery,$job,$now+900,$now),'changed');
    check(!$provider->requests,'Changed frozen callback reached provider');
    $local='smsd_'.hash('sha256',$delivery."\0".$recipient); $callback=$frozen['callback'].'?delivery='.$local;
    [$raw,$headers]=twilioBody(['AccountSid'=>$config['twilio_account_sid'],'MessageSid'=>$message,'MessageStatus'=>'delivered','From'=>$config['from'],'To'=>'+15555550102','FutureField'=>'preserved'],$config,$callback);
    $provider->response=['status'=>201,'error'=>0,'body'=>json_encode(['sid'=>$message,'account_sid'=>$config['twilio_account_sid'],'from'=>$config['from'],'to'=>'+15555550102'])];
    $provider->during=function () use ($service,$settings,$raw,$headers,$local,$now,$store) {
        check($store->get($local)['state']==='submitting','Provider request began before durable claim');
        $service->callback($settings,$local,$raw,$headers,$now);
    };
    $receipt=$service->send($settings,$frozen,$delivery,$job,$now+900,$now);
    check($receipt['state']==='delivered' && count($provider->requests)===1,'Early receipt lost after HTTP result');
    $service->callback($settings,$local,$raw,$headers,$now+1);
    check($service->send($settings,$frozen,$delivery,$job,$now+900,$now+2)['state']==='delivered' && count($provider->requests)===1,'Duplicate submission or callback is not idempotent');
    parse_str($provider->requests[0][2],$posted);
    check($posted['StatusCallback']===$callback && (int)$posted['ValidityPeriod']===900 && $posted['To']==='+15555550102','Twilio envelope lost callback, expiry or recipient');
    rejects(fn()=>$service->callback($settings,$local,$raw.'&Injected=yes',$headers,$now),'unauthorized');
    rejects(fn()=>$service->callback($settings,$local,$raw,['content-type'=>'application/x-www-form-urlencoded','x-twilio-signature'=>'bad'],$now),'unauthorized');
    [$badRaw,$badHeaders]=twilioBody(['AccountSid'=>$config['twilio_account_sid'],'MessageSid'=>$message,'MessageStatus'=>'delivered','From'=>$config['from'],'To'=>'+15555550103'],$config,$callback);
    rejects(fn()=>$service->callback($settings,$local,$badRaw,$badHeaders,$now),'identity_mismatch');
    rejects(fn()=>$service->send($settings,$frozen,'announcement-'.str_repeat('9',32),$job,$now-1,$now),'sms_expired');
    check(count($provider->requests)===1,'Expired message contacted provider');
    $provider->during=null; $provider->response=['status'=>0,'error'=>28,'body'=>''];
    $second='announcement-'.str_repeat('3',32);
    check($service->send($settings,$frozen,$second,$job,$now+900,$now)['state']==='uncertain','Timed out request was labeled definite failure');
    $service->send($settings,$frozen,$second,$job,$now+900,$now);
    check(count($provider->requests)===2,'Uncertain request was repeated');
    rejects(fn()=>$service->send($settings,$frozen,'announcement-'.str_repeat('4',32),$job,$now+900,$now),'daily_budget_exceeded');
    check(count($provider->requests)===2,'Budget rejection contacted provider');
    [$stop,$stopHeaders]=twilioBody(['AccountSid'=>$config['twilio_account_sid'],'MessageSid'=>'SM'.str_repeat('d',32),'SmsStatus'=>'received','From'=>'+15555550102','To'=>$config['from'],'Body'=>'STOP'],$config,$frozen['callback']);
    $service->callback($settings,'',$stop,$stopHeaders,$now);
    $settings['announcement_sms']['daily_segments']=10;
    rejects(fn()=>$service->send($settings,$frozen,'announcement-'.str_repeat('5',32),$job,$now+900,$now),'opted_out');
    $settings['announcement_sms']['recipients'][0]['number']='+15555550104';
    rejects(fn()=>$service->send($settings,$frozen,'announcement-'.str_repeat('6',32),$job,$now+900,$now),'changed');
    // Ed25519 verification uses a generated fixture key, never a provider account.
    $keypair=sodium_crypto_sign_keypair(); $secret=sodium_crypto_sign_secretkey($keypair);
    $telnyx=Config::normalize(array_replace($config,['provider'=>'telnyx','telnyx_api_key'=>'fixture-key','telnyx_public_key'=>base64_encode(sodium_crypto_sign_publickey($keypair)),'telnyx_profile_id'=>'11111111-1111-4111-8111-111111111111']));
    $payload=['data'=>['id'=>'22222222-2222-4222-8222-222222222222','event_type'=>'message.finalized','payload'=>[
        'id'=>'33333333-3333-4333-8333-333333333333','messaging_profile_id'=>$telnyx['telnyx_profile_id'],
        'from'=>['phone_number'=>$config['from']],'to'=>[['phone_number'=>'+15555550102','status'=>'delivered']]]]];
    $raw=json_encode($payload); $h=['telnyx-timestamp'=>(string)$now,'telnyx-signature-ed25519'=>base64_encode(sodium_crypto_sign_detached($now.'|'.$raw,$secret))];
    check(Provider::callback($telnyx,$callback,$raw,$h,$now)['state']==='delivered','Telnyx receipt signature or status');
    rejects(fn()=>Provider::callback($telnyx,$callback,$raw.' ',$h,$now),'unauthorized');
    rejects(fn()=>Provider::callback($telnyx,$callback,$raw,$h,$now+301),'unauthorized');
    $telnyxProvider=new FixtureProvider();
    $telnyxProvider->response=['status'=>202,'error'=>0,'body'=>json_encode(['data'=>['id'=>'33333333-3333-4333-8333-333333333333',
        'messaging_profile_id'=>$telnyx['telnyx_profile_id'],'from'=>['phone_number'=>$config['from']],'to'=>[['phone_number'=>'+15555550102']]]])];
    $sent=$telnyxProvider->send($telnyx,'+15555550102','Full fixture text',$callback,$now+900,$now);
    check($sent['state']==='accepted','Telnyx accepted response not recognized');
    $posted=json_decode($telnyxProvider->requests[0][2],true);
    check($posted['encoding']==='gsm7' && $posted['text']==='Full fixture text' && $posted['webhook_url']===$callback,'Telnyx envelope changed text/encoding/receipt identity');
    $telnyxProvider->response=['status'=>503,'error'=>0,'body'=>''];
    check($telnyxProvider->send($telnyx,'+15555550102','Full fixture text',$callback,$now+900,$now)['state']==='uncertain','Provider 5xx treated as safe to retry');
    $telnyxProvider->response=['status'=>400,'error'=>0,'body'=>json_encode(['errors'=>[['code'=>'40002']]])];
    check($telnyxProvider->send($telnyx,'+15555550102','Full fixture text',$callback,$now+900,$now)['error_code']==='provider_40002','Provider rejection lost bounded error code');
    // Native continuity merges evidence and limits, never queues a replay.
    $backup=$directory.'/sms-ledger.jsonl'; $store->exportSnapshot($backup);
    $restored=new Store($directory.'/restored'); $restored->mergeSnapshot($backup);
    check($restored->get($local)['state']==='delivered','Delivered receipt lost on restore');
    $secondLocal='smsd_'.hash('sha256',$second."\0".$recipient);
    check($restored->get($secondLocal)['state']==='uncertain','Unfinished restored message treated as ready to resend');
    check($restored->stats($now)['periods']===$store->stats($now)['periods'],'Restore lost budget reservations');
    check($restored->stats($now)['opt_outs']===$store->stats($now)['opt_outs'],'Restore lost opt-out tombstone');
    $restored->mergeSnapshot($backup);
    check($restored->stats($now)['periods']===$store->stats($now)['periods'],'Repeated restore doubled charges');
    $badBackup=$directory.'/invalid-backup.jsonl'; $lines=file($backup);
    file_put_contents($badBackup,implode('',$lines).$lines[1]);
    $before=$restored->stats($now);
    rejects(fn()=>$restored->mergeSnapshot($badBackup),'duplicate_record');
    check($restored->stats($now)===$before,'Invalid backup partially committed');
    $changedBackup=$directory.'/conflict-backup.jsonl';
    $records=array_map(static fn($line)=>json_decode($line,true),$lines);
    foreach ($records as &$record) { if (($record['table']??'')==='deliveries') { $record['row']['body_hash']=str_repeat('f',64); break; } } unset($record);
    file_put_contents($changedBackup,implode("\n",array_map(static fn($row)=>json_encode($row),$records))."\n");
    rejects(fn()=>$restored->mergeSnapshot($changedBackup),'delivery_conflict');
    // A detached database cannot silently serve a stale receipt or reset costs.
    rename($directory.'/restored/deliveries.sqlite',$directory.'/restored/detached.sqlite');
    rejects(fn()=>$restored->stats($now),'sms_storage_file_unsafe');
    rejects(fn()=>$restored->get($local),'sms_storage_file_unsafe');
    // Real concurrent processes must share one durable spending reservation.
    check(function_exists('pcntl_fork'),'CLI concurrency test needs pcntl');
    $parallelSettings=$settings; $parallelSettings['announcement_sms']=$config;
    $parallelSettings['announcement_sms']['daily_segments']=3;
    $parallelPath=$directory.'/parallel'; $parallel=new Store($parallelPath); unset($parallel);
    $children=[];
    for ($i=0;$i<6;$i++) {
        $pid=pcntl_fork(); check($pid>=0,'Could not start isolated concurrency fixture');
        if ($pid===0) {
            try {
                $childStore=null;
                for ($attempt=0;$attempt<100;$attempt++) { try {$childStore=new Store($parallelPath);break;} catch (RuntimeException $e) {if($e->getMessage()!=='sms_storage_busy')throw $e; usleep(10000);} }
                check($childStore!==null,'Concurrent storage stayed busy');
                file_put_contents($directory.'/ready-'.$i,'1');
                $until=microtime(true)+5; while (!file_exists($directory.'/go') && microtime(true)<$until) {usleep(1000);}
                check(file_exists($directory.'/go'),'Fixture barrier timed out');
                $childProvider=new FixtureProvider();
                $childProvider->response=['status'=>201,'error'=>0,'body'=>json_encode(['sid'=>'SM'.str_repeat(dechex($i),32),'account_sid'=>$config['twilio_account_sid'],'from'=>$config['from'],'to'=>'+15555550102'])];
                $childService=new Service($childStore,$childProvider);
                try {
                    $childService->send($parallelSettings,$frozen,'announcement-'.str_repeat(dechex($i),32),$job,$now+900,$now);
                    file_put_contents($directory.'/sent-'.$i,'1');
                } catch (DomainException $e) { check($e->getMessage()==='sms_daily_budget_exceeded','Wrong concurrent limit rejection'); check(!$childProvider->requests,'Rejected claim contacted provider'); }
                exit(0);
            } catch (Throwable $e) {fwrite(STDERR,$e->getMessage()."\n");exit(1);}
        }
        $children[]=$pid;
    }
    $until=microtime(true)+5; while (count(glob($directory.'/ready-*'))<6 && microtime(true)<$until) {usleep(1000);}
    file_put_contents($directory.'/go','1');
    foreach ($children as $pid) {pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Concurrent fixture child failed');}
    check(count(glob($directory.'/sent-*'))===3,'Concurrent send overspent or lost valid reservations');
    $parallel=new Store($parallelPath);
    foreach ($parallel->stats($now)['periods'] as $period) {check($period['segments']===3 && $period['amount']===30000,'Concurrent budget totals diverged');}
    // A committed claim remains uncertain after a simulated receipt-write failure.
    $db=new PDO('sqlite:'.$directory.'/ledger/deliveries.sqlite');
    $db->exec("CREATE TRIGGER deny_receipt BEFORE UPDATE ON deliveries BEGIN SELECT RAISE(ABORT,'fixture storage failure'); END");
    $settings['announcement_sms']['recipients'][0]['number']='+15555550102';
    $settings['announcement_sms']['recipients'][0]['consent_at']=gmdate('c',$now+1);
    $fresh=Service::snapshot($settings,[$recipient],'Notice','New consent.',false)[$recipient];
    $third='announcement-'.str_repeat('7',32);
    rejects(fn()=>$service->send($settings,$fresh,$third,$job,$now+900,$now+2),'fixture storage failure');
    $count=count($provider->requests); $db->exec('DROP TRIGGER deny_receipt');
    check($service->send($settings,$fresh,$third,$job,$now+900,$now+3)['state']==='uncertain' && count($provider->requests)===$count,'Receipt failure caused a repeated provider request');
    // A provider opt-out must suppress later jobs even without an inbound STOP.
    $optSettings=$settings;$optSettings['announcement_sms']=$config;$optSettings['announcement_sms']['daily_segments']=10;
    $optStore=new Store($directory.'/provider-optout');$optProvider=new FixtureProvider();$optService=new Service($optStore,$optProvider);
    $optProvider->response=['status'=>400,'error'=>0,'body'=>json_encode(['code'=>21610])];
    $optFrozen=Service::snapshot($optSettings,[$recipient],'Notice','Opt-out fixture.',false)[$recipient];
    $optDelivery='announcement-'.str_repeat('8',32);$optLocal='smsd_'.hash('sha256',$optDelivery."\0".$recipient);
    $optReceipt=$optService->send($optSettings,$optFrozen,$optDelivery,$job,$now+900,$now);
    check($optReceipt['state']==='failed'&&str_contains($optReceipt['detail'],'opted out'),'Provider opt-out lost its actionable receipt');
    check($optStore->stats($now)['opt_outs']===[['number_hash'=>hash('sha256','+15555550102'),'blocked_at'=>$now]],'Provider 21610 did not persist a number block');
    rejects(fn()=>$optService->send($optSettings,$optFrozen,'announcement-'.str_repeat('9',32),$job,$now+900,$now+1),'opted_out');
    check(count($optProvider->requests)===1,'Known provider opt-out reached another request');
    [$optRaw,$optHeaders]=twilioBody(['AccountSid'=>$config['twilio_account_sid'],'MessageSid'=>'SM'.str_repeat('e',32),
        'MessageStatus'=>'failed','ErrorCode'=>'21610','From'=>$config['from'],'To'=>'+15555550102'],$config,$optFrozen['callback'].'?delivery='.$optLocal);
    $optService->callback($optSettings,$optLocal,$optRaw,$optHeaders,$now+10);
    check($optStore->stats($now)['opt_outs'][0]['blocked_at']===$now,'Duplicate provider opt-out renewed its timestamp');
    $optSettings['announcement_sms']['recipients'][0]['consent_at']=gmdate('c',$now+5);
    $optSettings['announcement_sms']['recipients'][0]['consent_note']='Renewed fixture consent';
    $newFrozen=Service::snapshot($optSettings,[$recipient],'Notice','New opt-in fixture.',false)[$recipient];
    $optProvider->response=['status'=>201,'error'=>0,'body'=>json_encode(['sid'=>'SM'.str_repeat('f',32),'account_sid'=>$config['twilio_account_sid'],'from'=>$config['from'],'to'=>'+15555550102'])];
    check($optService->send($optSettings,$newFrozen,'announcement-'.str_repeat('9',32),$job,$now+900,$now+11)['state']==='accepted','Replayed 21610 blocked explicitly renewed consent');
    $optStore->optOut('new_stop',hash('sha256','+15555550102'),$now+20);
    $optStore->optOut('older_stop',hash('sha256','+15555550102'),$now+15);
    check($optStore->stats($now)['opt_outs'][0]['blocked_at']===$now+20,'Clock reversal weakened an opt-out block');
    $afterRetention=$now+91*86400;
    $optStore->optOut('unrelated_stop',hash('sha256','+15555550103'),$afterRetention);
    $optStore->optOut('new_stop',hash('sha256','+15555550102'),$afterRetention+1);
    $originalBlock=array_values(array_filter($optStore->stats($afterRetention)['opt_outs'],static fn($row)=>$row['number_hash']===hash('sha256','+15555550102')))[0];
    check($originalBlock['blocked_at']===$now+20,'An old signed STOP identity became executable after historical receipt retention');
    // The same numeric code from a different provider does not assert Twilio semantics.
    $otherSettings=$optSettings;$otherSettings['announcement_sms']=array_replace($telnyx,['daily_segments'=>10]);
    $otherStore=new Store($directory.'/other-provider');$otherProvider=new FixtureProvider();$otherService=new Service($otherStore,$otherProvider);
    $otherProvider->response=['status'=>400,'error'=>0,'body'=>json_encode(['errors'=>[['code'=>'21610']]])];
    $otherFrozen=Service::snapshot($otherSettings,[$recipient],'Notice','Other provider fixture.',false)[$recipient];
    $otherService->send($otherSettings,$otherFrozen,$optDelivery,$job,$now+900,$now);
    check($otherStore->stats($now)['opt_outs']===[],'Numeric rejection from another provider was misclassified');
    // Detect missing ledgers and unsafe paths instead of resetting spending or claims.
    unset($service,$store,$db);
    rename($directory.'/ledger/deliveries.sqlite',$directory.'/saved.sqlite');
    rejects(fn()=>new Store($directory.'/ledger'),'initialization_incomplete');
    symlink($directory.'/saved.sqlite',$directory.'/ledger/deliveries.sqlite');
    rejects(fn()=>new Store($directory.'/ledger'),'file_unsafe');
    echo "SMS: encoding/segments, immutable recipients, durable intent, early/duplicate receipts, signature validation, expiry, opt-outs, limits, uncertain submission and storage failure recovery passed without network requests.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($directory);
}
