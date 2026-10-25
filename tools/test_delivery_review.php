<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/DeliveryReview.php';
use SLS\MassNotify\DeliveryReview;
function reviewCheck(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}}
$now=1800000000;$settings=['desktop_auth_key'=>base64_encode(str_repeat('k',32)),'announcement_sms'=>['secret'=>'PRIVATE_VALUE'],'tts_max_seconds'=>30];
$request=['message'=>'Fixture','title'=>'Review','phones'=>['1000'],'desktops'=>['desktop-a'],'webhooks'=>[],'audio_mode'=>'tts','sms_recipient_ids'=>['sms_'.str_repeat('a',24)]];
$review=DeliveryReview::create($request,$settings,$now);
DeliveryReview::verify($review['token'],$request,$settings,$now+299);
reviewCheck($review['counts']['phones']===1&&$review['recipients']['desktops']===['desktop-a'],'Review did not contain the exact resolved selection.');
reviewCheck(!str_contains(json_encode($review),'PRIVATE_VALUE')&&!$review['delivery_started'],'Review disclosed configuration or claimed delivery.');
foreach([[$request,$settings,$now+300],[array_replace($request,['message'=>'Changed']),$settings,$now],[array_replace($request,['desktops'=>['desktop-b']]),$settings,$now],[$request,array_replace($settings,['announcement_sms'=>['secret'=>'ROTATED']]),$now],[$request,$settings,$now-1]] as $case){
    try{DeliveryReview::verify($review['token'],...$case);throw new RuntimeException('Changed or expired review accepted.');}catch(DomainException $expected){}
}
$reordered=array_reverse($settings,true);DeliveryReview::verify($review['token'],$request,$reordered,$now);
$pcm=str_repeat("\1\0",8000);$fmt=pack('vvVVvv',1,1,8000,16000,2,16);$wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('V',16).$fmt.'data'.pack('V',strlen($pcm)).$pcm;
reviewCheck(DeliveryReview::waveSeconds($wav)===1.0,'Measured WAV duration was incorrect.');
try{DeliveryReview::waveSeconds(substr($wav,0,-1));throw new RuntimeException('Truncated WAV accepted.');}catch(DomainException $expected){}
$radio=$request+['radio_context'=>['maximum_audio_seconds'=>30]];reviewCheck(DeliveryReview::create($radio,$settings,$now)['speech']['gateway_maximum_seconds']===30,'Radio sequence limit is missing from the final review.');
echo "Delivery review: exact recipient projection, no secrets, canonical binding, configuration changes and absolute expiry passed.\n";
