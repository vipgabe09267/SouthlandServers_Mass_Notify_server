<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/DeliveryRehearsal.php';
use SLS\MassNotify\DeliveryRehearsal;
function rehearsalCheck(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}}
function rehearsalReject(callable $work):void{try{$work();}catch(DomainException|InvalidArgumentException $expected){return;}throw new RuntimeException('Invalid rehearsal was accepted.');}
$preview=['success'=>true,'preview'=>true,'delivery_started'=>false,'transports_attempted'=>[],
    'audio_mode'=>'tts','phones'=>['1000','1001'],'radio_extensions'=>['1001'],'desktops'=>['desktop-a'],
    'voice_recipient_ids'=>['voice_fixture'],'email_recipient_ids'=>['email_fixture'],'sms_recipient_ids'=>['sms_fixture'],
    'webhooks'=>['hook_fixture'],'browser_recipient_ids'=>['sub_fixture'],'connector_ids'=>['conn_fixture']];
$result=DeliveryRehearsal::simulate($preview,['channels'=>['audio'=>'uncertain','sms'=>'expired','email'=>'refused','desktop'=>'unavailable']]);
rehearsalCheck(count($result['counts'])===9&&count($result['simulated_receipts'])===10,'A supported channel was omitted or a radio received synthetic SIP NOTIFY.');
rehearsalCheck($result['simulated']&&!$result['delivery_started']&&$result['transports_attempted']===[],'Synthetic result claimed delivery.');
foreach($result['simulated_receipts'] as $row){rehearsalCheck($row['simulated']===true&&!array_intersect(array_keys($row),['state','event_id','job_id']),'Synthetic receipt can be mistaken for durable delivery evidence.');}
$byChannel=array_column($result['simulated_receipts'],'outcome','channel');
rehearsalCheck($byChannel['audio']==='uncertain'&&$byChannel['sms']==='expired'&&$byChannel['email']==='refused'&&$byChannel['desktop']==='unavailable'&&$byChannel['webhook']==='accepted','Per-channel failure scenario was lost.');
$offline=$preview+['unavailable_phones'=>['1000']];$result=DeliveryRehearsal::simulate($offline);
rehearsalCheck($result['simulated_receipts'][0]['outcome']==='unavailable','Rehearsal invented availability for an offline phone.');
foreach(['success','preview'] as $key){rehearsalReject(fn()=>DeliveryRehearsal::simulate(array_replace($preview,[$key=>false])));}
rehearsalReject(fn()=>DeliveryRehearsal::simulate(array_replace($preview,['delivery_started'=>true])));
rehearsalReject(fn()=>DeliveryRehearsal::simulate(array_replace($preview,['transports_attempted'=>['sms']])));
foreach([['default'=>'delivered'],['channels'=>['audio'=>'read']],['channels'=>['unknown'=>'accepted']],['send'=>true]] as $scenario){rehearsalReject(fn()=>DeliveryRehearsal::simulate($preview,$scenario));}
echo "Delivery rehearsal: nine inert channel models, distinct synthetic evidence, failure scenarios, offline recipients and no real-delivery fallback passed.\n";
