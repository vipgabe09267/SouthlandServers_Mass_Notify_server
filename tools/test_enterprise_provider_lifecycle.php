<?php
declare(strict_types=1);
require __DIR__.'/../slsmassnotifyserver/EnterpriseIntegrationsProvider.php';
require __DIR__.'/../slsmassnotifyserver/EnterpriseDeliveryConnector.php';
use SLS\MassNotify\{EnterpriseIntegrationsConfig as Config,EnterpriseIntegrationsProvider as Provider,EnterpriseDeliveryConnector as Connector};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejects(callable $call):void{try{$call();}catch(Throwable $expected){return;}throw new RuntimeException('Expected disabled or unsafe provider rejection');}
class LifecycleProviderFixture extends Provider
{
 public array $calls=[];public array $replies=[];
 protected function request(string $url,string $body,array $headers,?array $device=null):array{$this->calls[]=compact('url','body','headers','device');return array_shift($this->replies)??['status'=>200,'error'=>0,'body'=>'{}'];}
}
$provider=new LifecycleProviderFixture();$now=time();$config=Config::defaults();
foreach(['zoom'=>'https://zoom.us/oauth/token','google_meet'=>'https://oauth2.googleapis.com/token','webex'=>'https://webexapis.com/v1/access_token'] as $brand=>$url){
 $meeting=array_replace($config['meetings'],['provider'=>$brand,'oauth_refresh_enabled'=>'1','oauth_client_id'=>'fixture-client','oauth_client_secret'=>'fixture-secret','oauth_refresh_token'=>'old-refresh']);
 $provider->replies=[['status'=>200,'error'=>0,'body'=>'{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600,"token_type":"Bearer"}']];$renewed=$provider->refreshMeeting($meeting,$now);$wire=end($provider->calls);parse_str($wire['body'],$form);
 check($wire['url']===$url&&$form['grant_type']==='refresh_token'&&$form['refresh_token']==='old-refresh','OAuth refresh endpoint or body incorrect');
 check($renewed['oauth_expires_at']===$now+3600&&$renewed['oauth_refresh_token']==='new-refresh','Provider rotation or absolute expiry lost');
 check($brand==='zoom'?in_array('Authorization: Basic '.base64_encode('fixture-client:fixture-secret'),$wire['headers'],true)&&!isset($form['client_secret']):$form['client_id']==='fixture-client'&&$form['client_secret']==='fixture-secret','Client authentication contract incorrect');
 $all=$config;$all['meetings']=$renewed;$public=Config::redacted($all);check(!str_contains(json_encode($public),'new-access')&&!str_contains(json_encode($public),'new-refresh')&&!str_contains(json_encode($public),'fixture-secret'),'OAuth credentials leaked to public config');
 $meeting['enabled']='1';$meeting['oauth_expires_at']=$now-1;rejects(fn()=>$provider->meeting($meeting,'Fixture',$now+60,30));
}
$provider->replies=[['status'=>200,'error'=>0,'body'=>'{"access_token":"unsafe\\nheader","expires_in":3600}']];rejects(fn()=>$provider->refreshMeeting($meeting,$now));
$disabled=$meeting;$disabled['oauth_refresh_enabled']='0';$before=count($provider->calls);rejects(fn()=>$provider->refreshMeeting($disabled,$now));check(count($provider->calls)===$before,'Disabled refresh contacted provider');
$webex=$meeting;$webex['provider']='webex';$webex['webex_access_token']='fixture';check(!$provider->revokeMeeting($webex)['provider_contacted'],'Webex invented a revoke endpoint');
$access=array_replace($config['access_control'],['enabled'=>'1','origin'=>'https://door.example.test','pinned_ipv4'=>'192.168.1.20','username'=>'fixture','password'=>'fixture']);
$door=['id'=>'door_'.str_repeat('d',24),'token'=>'door-token','allow_access'=>true,'maximum_access_seconds'=>10];
$provider->replies=[['status'=>200,'error'=>0,'body'=>'{"Door":[{"token":"door-token","AccessTime":"PT7S"}]}'],['status'=>200,'error'=>0,'body'=>'{}']];$before=count($provider->calls);$receipt=$provider->door($access,$door,'access');check($receipt['state']==='accepted'&&count($provider->calls)===$before+2,'Device-timed access rejected valid timer');check(json_decode(end($provider->calls)['body'],true)===['tdc:AccessDoor'=>['Token'=>'door-token']],'Timed access used a delayed PBX unlock task');
foreach(['{"Door":[{"token":"door-token","AccessTime":"PT11S"}]}','{"Door":[{"token":"other","AccessTime":"PT5S"}]}','{"Door":[{"token":"door-token","AccessTime":"PT5S"},{"token":"door-token","AccessTime":"PT5S"}]}','{"Door":[{"token":"door-token","AccessTime":"PT0S"}]}'] as $body){$provider->replies=[['status'=>200,'error'=>0,'body'=>$body]];$before=count($provider->calls);rejects(fn()=>$provider->door($access,$door,'access'));check(count($provider->calls)===$before+1,'Unsafe timer caused door actuation');}
foreach(['ntfy','gotify'] as $brand){$destination=['id'=>'conn_'.str_repeat('e',24),'name'=>'Fixture connector','provider'=>$brand,'enabled'=>'1','origin'=>'https://notify.example.test','pinned_ipv4'=>'192.168.1.30','token'=>'fixture-secret','topic'=>$brand==='ntfy'?'fixture-topic':''];$calls=[];$transport=static function($url,$body,$headers,$target)use(&$calls):array{$calls[]=compact('url','body','headers','target');return ['status'=>200,'error'=>0,'body'=>'{"id":42}'];};$receipt=Connector::send($destination,['title'=>'Fixture','message'=>'Read the message','is_test'=>true],$transport);check($receipt['state']==='accepted'&&!str_contains(json_encode($receipt),'fixture-secret'),'Connector acceptance or redaction failed');$call=$calls[0];check(!str_contains($call['url'],'fixture-secret'),'Connector token placed in URL');$body=json_decode($call['body'],true);check($body['title']==='TEST · Fixture','Test status missing');check($brand==='ntfy'?$body['topic']==='fixture-topic'&&in_array('Authorization: Bearer fixture-secret',$call['headers'],true):str_ends_with($call['url'],'/message')&&in_array('X-Gotify-Key: fixture-secret',$call['headers'],true),'Connector provider wire contract incorrect');$destination['pinned_ipv4']='127.0.0.1';rejects(fn()=>Connector::send($destination,['title'=>'Fixture','message'=>'Message','is_test'=>false],$transport));}
echo "Provider lifecycle: three OAuth refresh contracts, rotation, expiry, redaction, disabled state, device relock timers and ntfy/Gotify authenticated publication passed.\n";
