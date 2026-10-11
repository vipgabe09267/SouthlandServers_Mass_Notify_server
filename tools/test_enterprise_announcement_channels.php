<?php
declare(strict_types=1);
// Reuse the real incident/email-proof fixture, never a production PBX or transport.
require __DIR__.'/test_enterprise_identity.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseAnnouncementChannels.php';
require_once __DIR__.'/../slsmassnotifyserver/api/sls-mass-notify/security.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterIntegration.php';
use SLS\MassNotify\{BrowserPush,EnterpriseIntegrationsConfig,EnterpriseDeliveryConnector,ApiSecurity,SubscriberConfig,SubscriberService,EnterpriseIdentityStore};
class QueuedLabsFixture
{
    use \FreePBX\modules\SlsEnterpriseAnnouncementChannels;
    public array $settings;
    public array $effects=[];
    public int $browserStatus=201;
    public bool $automationAllowed=true;
    public bool $approvalAllowed=true;
    private SubscriberService $subscribers;
    private EnterpriseIdentityStore $state;
    public function __construct(array $settings,SubscriberService $subscribers,EnterpriseIdentityStore $state){$this->settings=$settings;$this->subscribers=$subscribers;$this->state=$state;}
    public function getActiveSettings():array{return $this->settings;}
    protected function subscriberService():SubscriberService{return $this->subscribers;}
    protected function enterpriseLabsStore():EnterpriseIdentityStore{return $this->state;}
    private function automationContextPermitted(array $context):bool{return $this->automationAllowed;}
    protected function enterpriseApprovalPermitted(array $request):bool{return $this->approvalAllowed;}
    protected function transmitEnterpriseBrowserPush(string $url,string $body,array $headers):array{$this->effects[]=['browser',$url,$body,$headers];return ['status'=>$this->browserStatus,'error'=>0];}
    protected function transmitEnterpriseConnector(array $destination,array $event):array{return EnterpriseDeliveryConnector::send($destination,$event,function($url,$body,$headers,$target):array{$this->effects[]=['connector',$url,$body,$headers];return ['status'=>200,'error'=>0,'body'=>'{"id":"fixture-message"}'];});}
    public function freeze(array $request):array{return $this->snapshotEnterpriseAnnouncementChannels($request,$this->settings);}
    public function deliver(array $request):array{$rows=[];$this->deliverEnterpriseAnnouncementChannels($request,static function($channel,$id,$state,$detail,$retry,$meta=[])use(&$rows):void{$rows[]=compact('channel','id','state','detail','retry','meta');},null);return $rows;}
}
$settings['enabled']='0';$settings['runtime_enabled']=true;
$key=BrowserPush::generateKey();$settings['subscriber_browser']['push']=['enabled'=>true,'private_key_pem'=>$key['private_key_pem'],'subject'=>'mailto:admin@example.test'];
$settings['subscriber_browser']=SubscriberConfig::normalize($settings['subscriber_browser']);
$client=BrowserPush::generateKey();$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/fixture-1','keys'=>['p256dh'=>$client['public_key'],'auth'=>BrowserPush::encode(random_bytes(16))]];
$invalid=$bound;unset($invalid['email_verified_at']);reject(fn()=>$subscriber->enrollPush($settings,$invalid,$subscription,$now),'Unverified email enrolled browser');
$enrollment=$subscriber->enrollPush($settings,$bound,$subscription,$now);check($enrollment['success'],'Verified enrollment failed');
$connector=['id'=>'conn_'.str_repeat('7',24),'name'=>'Local ntfy','enabled'=>'1','provider'=>'ntfy','origin'=>'https://notify.example.test','pinned_ipv4'=>'192.168.2.20','token'=>'private-scoped-token','topic'=>'sls'];
$config=EnterpriseIntegrationsConfig::normalize([]);$config['enabled']='1';$config['connectors']=['enabled'=>'1','destinations'=>[$connector]];$settings['enterprise_integrations']=EnterpriseIntegrationsConfig::normalize($config);
$fixture=new QueuedLabsFixture($settings,$subscriber,$store);
$request=['delivery_id'=>'announcement-'.str_repeat('8',32),'delivery_timestamp'=>gmdate('c',$now),'display_timeout'=>300,'title'=>'Fixture alert','message'=>'Fixture only','is_test'=>true,'browser_recipient_ids'=>[$person['id']],'connector_ids'=>[$connector['id']]];
$frozen=$fixture->freeze($request);check($frozen['force_queue']&&count($frozen['browser_targets'][$person['id']]['endpoint_ids'])===1,'Labs destination snapshot was not frozen');
// Later enrollment is excluded from the already queued event.
$later=$subscription;$later['endpoint']='https://fcm.googleapis.com/fcm/send/fixture-2';$subscriber->enrollPush($settings,$bound,$later,$now);
$result=$fixture->deliver($frozen);check(count($fixture->effects)===2&&count($result)===2,'Frozen queue did not dispatch exactly one browser and connector');
check($result[0]['channel']==='browser_push'&&$result[0]['state']==='accepted'&&$result[1]['state']==='accepted','Provider accepted receipts missing');
check(strlen($fixture->effects[0][2])>86&&!str_contains($fixture->effects[0][2],'Fixture only'),'Browser transport lacked encryption');
check(json_decode($fixture->effects[1][2],true)['title']==='TEST · Fixture alert','Connector test metadata lost');
$again=$fixture->deliver($frozen);check(count($fixture->effects)===2&&$again===$result,'Duplicate queued execution repeated delivery or changed result');
$revoked=$frozen;$revoked['delivery_id']='announcement-'.str_repeat('9',32);$fixture->settings['subscriber_browser']['people'][0]['version']=str_repeat('6',64);$fixture->settings['enterprise_integrations']['connectors']['destinations'][0]['token']='rotated-token';
$rejected=$fixture->deliver($revoked);check(count($fixture->effects)===2&&array_column($rejected,'state')===['cancelled','cancelled'],'Changed destination configuration retained authorization');
$fixture->settings=$settings;$expired=$frozen;$expired['delivery_id']='announcement-'.str_repeat('a',32);$expired['delivery_timestamp']=gmdate('c',$now-700);check(array_column($fixture->deliver($expired),'state')===['cancelled','cancelled']&&count($fixture->effects)===2,'Expired queued event had a side effect');
$restricted=['id'=>'fixture-key','scopes'=>['send'],'audience'=>['unrestricted'=>false]];$GLOBALS['sls_control_principal']=$restricted;reject(fn()=>$fixture->freeze($request),'Restricted sender selected Labs channels');unset($GLOBALS['sls_control_principal']);
$only=$frozen;$only['delivery_id']='announcement-'.str_repeat('b',32);$only['only_channels']=['connector'=>[$connector['id']]];$fixture->deliver($only);check(count($fixture->effects)===3&&$fixture->effects[2][0]==='connector','Explicit partial channel selection leaked browser delivery');
$fixture->approvalAllowed=false;$invalidApproval=$frozen;$invalidApproval['delivery_id']='announcement-'.str_repeat('f',32);check(array_column($fixture->deliver($invalidApproval),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Revoked dual approval allowed browser or connector effects');$fixture->approvalAllowed=true;
$fixture->settings['runtime_enabled']=false;$paused=$frozen;$paused['delivery_id']='announcement-'.str_repeat('0',32);check(array_column($fixture->deliver($paused),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Stopped notifications allowed browser or connector effects');$fixture->settings['runtime_enabled']=true;
$incidentExpired=$frozen;$incidentExpired['delivery_id']='announcement-'.str_repeat('d',32);$incidentExpired['incident_context']=['expires_at'=>gmdate('c',time()-1)];check(array_column($fixture->deliver($incidentExpired),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Shared incident expiry was extended by a later browser or connector cohort');
$replacement=$subscription;$replacement['keys']['auth']=BrowserPush::encode(random_bytes(16));$subscriber->enrollPush($settings,$bound,$replacement,$now);$changedEndpoint=$frozen;$changedEndpoint['delivery_id']='announcement-'.str_repeat('e',32);$changedEndpoint['connector_fingerprints']=[];check(array_column($fixture->deliver($changedEndpoint),'state')===['cancelled']&&count($fixture->effects)===3,'New subscription keys received an old frozen announcement');
$subscriber->enrollPush($settings,$bound,$subscription,$now);
$fixture->browserStatus=410;$gone=$frozen;$gone['delivery_id']='announcement-'.str_repeat('c',32);$gone['connector_fingerprints']=[];$fixture->deliver($gone);check(!in_array($enrollment['endpoint_id'],$subscriber->pushTargets($settings,$person['id']),true),'Revoked push endpoint remained enabled');
$disabled=$settings;$disabled['subscriber_browser']['push']['enabled']=false;reject(fn()=>$subscriber->push($disabled,$person['id'],['event_id'=>'disabled'],time()),'Disabled push accepted');
echo "Queued Labs delivery: verified enrollment, frozen endpoints, RFC-encrypted submission, connector acceptance, replay isolation, revocation, expiry and explicit channel selection passed.\n";
