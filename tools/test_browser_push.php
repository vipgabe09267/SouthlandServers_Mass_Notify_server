<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/BrowserPush.php';
use SLS\MassNotify\{BrowserPush,SubscriberConfig};
use Firebase\JWT\{JWT,Key};
function bpcheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function bpreject(callable $work):void{try{$work();}catch(Throwable $expected){return;}throw new RuntimeException('Expected push rejection.');}
// Published RFC 8291 section 5 vector, not an implementation-generated expectation.
$ua='BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$as='BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
$d=BrowserPush::decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',32);
$der=hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').BrowserPush::decode($as,65);
$pem="-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END EC PRIVATE KEY-----\n";
$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/fixture','keys'=>['p256dh'=>$ua,'auth'=>'BTBZMqHH6r4Tts7J_aSIgg']];
$expected='DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';
$encrypted=BrowserPush::encrypt($subscription,'When I grow up, I want to be a watermelon',$pem,BrowserPush::decode('DGv6ra1nlYgDCS1FRnbzlw',16));
bpcheck(BrowserPush::encode($encrypted)===$expected,'RFC 8291 known-answer encryption failed.');
$key=BrowserPush::generateKey();$now=time();$config=['enabled'=>'1','private_key_pem'=>$key['private_key_pem'],'subject'=>'mailto:admin@example.test'];
$headers=BrowserPush::headers($subscription,$config,$now,$now+300);$authorization=end($headers);preg_match('/t=([^,]+)/',$authorization,$match);
$private=openssl_pkey_get_private($key['private_key_pem']);$details=openssl_pkey_get_details($private);$jwt=JWT::decode($match[1],new Key($details['key'],'ES256'));
bpcheck($jwt->aud==='https://fcm.googleapis.com'&&$jwt->exp===$now+300,'VAPID JWT origin or expiry failed.');
foreach(['http://fcm.googleapis.com/send/a','https://fcm.googleapis.com.evil.test/a','https://127.0.0.1/a','https://web.push.apple.com:8443/a','https://user@fcm.googleapis.com/a'] as $bad){bpreject(fn()=>BrowserPush::subscription(array_replace($subscription,['endpoint'=>$bad])));}
bpreject(fn()=>BrowserPush::subscription(array_replace($subscription,['keys'=>['p256dh'=>BrowserPush::encode("\x04".str_repeat("\0",64)),'auth'=>$subscription['keys']['auth']]])));
$event=['event_id'=>'fixture','title'=>'Fixture','message'=>'Test only','is_test'=>true,'expires_at'=>$now+300];$calls=0;
$transport=static function($url,$body,$headers)use(&$calls):array{$calls++;bpcheck(!str_contains($body,'Test only'),'Push body was plaintext.');return ['error'=>0,'status'=>201];};
bpcheck(BrowserPush::send($subscription,$config,$event,$now,$transport)['state']==='accepted'&&$calls===1,'Push accepted response classification failed.');
bpcheck(BrowserPush::send($subscription,$config,$event,$now,static fn()=>['error'=>0,'status'=>410])['state']==='revoked','Expired endpoint not revoked.');
bpreject(fn()=>BrowserPush::send($subscription,$config,array_replace($event,['expires_at'=>$now]),$now,$transport));
bpreject(fn()=>BrowserPush::send($subscription,array_replace($config,['enabled'=>'0']),$event,$now,$transport));
require dirname(__DIR__).'/slsmassnotifyserver/SubscriberConfig.php';$subscriber=SubscriberConfig::defaults();$subscriber['push']['private_key_pem']=$key['private_key_pem'];
$public=SubscriberConfig::publicConfig($subscriber);bpcheck(!str_contains(json_encode($public),'PRIVATE KEY')&&$public['push']['public_key']===$key['public_key'],'Subscriber projection exposed private VAPID key.');
echo "Browser push: RFC 8291 known-answer vector, ES256 VAPID, encrypted transport, endpoint isolation, expiry, disabled state and private-key redaction passed.\n";
