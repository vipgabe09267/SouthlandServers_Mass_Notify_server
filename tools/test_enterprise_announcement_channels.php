<?php
declare(strict_types=1);
// Reuse the real incident/email-proof fixture, never a production PBX or transport.
define('SLS_IDENTITY_FIXTURE_RETAIN',true);
require __DIR__.'/test_enterprise_identity.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseAnnouncementChannels.php';
require_once __DIR__.'/../slsmassnotifyserver/api/sls-mass-notify/security.php';
require_once __DIR__.'/../slsmassnotifyserver/EnterpriseClusterIntegration.php';
use SLS\MassNotify\{BrowserPush,EnterpriseIntegrationsConfig,EnterpriseDeliveryConnector,ApiSecurity,SubscriberConfig,SubscriberService,EnterpriseIdentityStore};
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(!(error_reporting()&$severity)){return false;}throw new ErrorException($message,0,$severity,$file,$line);});
class QueuedLabsFixture
{
    use \FreePBX\modules\SlsEnterpriseAnnouncementChannels;
    public array $settings;
    public array $effects=[];
    public int $browserStatus=201;
    public bool $browserThrows=false;
    public bool $automationAllowed=true;
    public bool $approvalAllowed=true;
    public ?Closure $beforeConnectorClaim=null;
    private SubscriberService $subscribers;
    private EnterpriseIdentityStore $state;
    public function __construct(array $settings,SubscriberService $subscribers,EnterpriseIdentityStore $state){$this->settings=$settings;$this->subscribers=$subscribers;$this->state=$state;}
    public function getActiveSettings():array{return $this->settings;}
    protected function subscriberService():SubscriberService{return $this->subscribers;}
    protected function enterpriseLabsStore():EnterpriseIdentityStore{
        if($this->beforeConnectorClaim){$hook=$this->beforeConnectorClaim;$this->beforeConnectorClaim=null;$hook($this);}
        return $this->state;
    }
    private function automationContextPermitted(array $context):bool{return $this->automationAllowed;}
    protected function enterpriseApprovalPermitted(array $request):bool{return $this->approvalAllowed;}
    protected function transmitEnterpriseBrowserPush(string $url,string $body,array $headers):array{$this->effects[]=['browser',$url,$body,$headers];if($this->browserThrows){throw new RuntimeException('Synthetic interrupted push transport');}return ['status'=>$this->browserStatus,'error'=>0];}
    protected function transmitEnterpriseConnector(array $destination,array $event):array{return EnterpriseDeliveryConnector::send($destination,$event,function($url,$body,$headers,$target):array{$this->effects[]=['connector',$url,$body,$headers];return ['status'=>200,'error'=>0,'body'=>'{"id":"Abc123xyZ9"}'];});}
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
$wire=[];$acceptedTitle=EnterpriseDeliveryConnector::send($connector,['title'=>str_repeat('T',73),'message'=>'Fixture only','is_test'=>true],static function($url,$body)use(&$wire):array{$wire=json_decode($body,true);return ['status'=>200,'error'=>0,'body'=>'{"id":"Abc123xyZ9"}'];});
check($acceptedTitle['state']==='accepted'&&mb_strlen($wire['title'],'UTF-8')===80,'Exact decorated connector title boundary failed');
reject(fn()=>EnterpriseDeliveryConnector::send($connector,['title'=>str_repeat('T',74),'message'=>'Fixture only','is_test'=>true],static fn()=>['status'=>200,'error'=>0,'body'=>'{"id":"Abc123xyZ9"}']),'Decorated connector title exceeded its wire limit');
foreach(['{"id":"first","id":"last"}','{"id":"valid","extra":1e309}','{"id":"valid","extra":NaN}','[ {"id":"valid"} ]'] as $ambiguousReply){
    $receipt=EnterpriseDeliveryConnector::send($connector,['title'=>'Fixture','message'=>'Fixture only','is_test'=>true],static fn()=>['status'=>200,'error'=>0,'body'=>$ambiguousReply]);
    check($receipt['state']==='uncertain','Ambiguous connector reply was reported accepted');
}
$fixture=new QueuedLabsFixture($settings,$subscriber,$store);
$request=['delivery_id'=>'announcement-'.str_repeat('8',32),'delivery_timestamp'=>gmdate('c',$now),'display_timeout'=>300,'title'=>'Fixture alert','message'=>'Fixture only','is_test'=>true,'browser_recipient_ids'=>[$person['id']],'connector_ids'=>[$connector['id']]];
$beforeContent=count($fixture->effects);
foreach([['message'=>str_repeat('A',3000)],['message'=>str_repeat('"',1500)],['message'=>str_repeat('😀',750)],['title'=>str_repeat('T',81)]] as $invalidContent){
    reject(fn()=>$fixture->freeze(array_replace($request,$invalidContent)),'Oversized browser content reached queue admission');
}
$connectorOnly=array_replace($request,['browser_recipient_ids'=>[]]);
reject(fn()=>$fixture->freeze(array_replace($connectorOnly,['message'=>str_repeat('A',4001)])),'Oversized connector content reached queue admission');
reject(fn()=>$fixture->freeze(array_replace($request,['browser_recipient_ids'=>[[]]])),'Non-text Labs recipient reached queue admission');
check(count($fixture->effects)===$beforeContent,'Rejected Labs content caused a transport effect');
reject(fn()=>$fixture->freeze(array_replace($connectorOnly,['title'=>str_repeat('T',74)])),'Decorated connector title reached the queue');
$validContent=$fixture->freeze(array_replace($request,['connector_ids'=>[],'title'=>str_repeat('😀',80),'message'=>str_repeat('A',2000)]));
check($validContent['force_queue'],'Valid Unicode browser content was rejected');
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
$invalidTime=$frozen;$invalidTime['delivery_id']='announcement-'.hash('md5','invalid-created-at');$invalidTime['delivery_timestamp']='not-a-timestamp';$beforeInvalidTime=count($fixture->effects);
reject(fn()=>$fixture->deliver($invalidTime),'Invalid Labs creation timestamp was accepted');check(count($fixture->effects)===$beforeInvalidTime,'Invalid Labs creation timestamp caused a transport effect');
$invalidSnapshot=$frozen;$invalidSnapshot['delivery_id']='announcement-'.hash('md5','invalid-browser-snapshot');$invalidSnapshot['connector_fingerprints']=[];$invalidSnapshot['browser_targets'][$person['id']]['identity']=[];
check(array_column($fixture->deliver($invalidSnapshot),'state')===['cancelled']&&count($fixture->effects)===$beforeInvalidTime,'Malformed browser snapshot was not fenced before transport');
$restricted=['id'=>'fixture-key','scopes'=>['send'],'audience'=>['unrestricted'=>false]];$GLOBALS['sls_control_principal']=$restricted;reject(fn()=>$fixture->freeze($request),'Restricted sender selected Labs channels');unset($GLOBALS['sls_control_principal']);
$issued=ApiSecurity::issue(['name'=>'Scoped Labs fixture','scopes'=>['send'],'audience'=>['unrestricted'=>false,'browser_recipient_ids'=>[$person['id']],'connector_ids'=>[$connector['id']]]]);
$fixture->settings['control_api']=['enabled'=>true,'credentials'=>[$issued['credential']]];
$GLOBALS['sls_control_principal']=ApiSecurity::authenticate($fixture->settings['control_api'],$issued['secret']);
$scoped=$request;$scoped['delivery_id']='announcement-'.hash('md5','scoped-frozen-fixture');$scoped['api_credential_id']=$issued['credential']['id'];
$scoped=$fixture->freeze($scoped);unset($GLOBALS['sls_control_principal']);
$beforeScoped=count($fixture->effects);$scopedRows=$fixture->deliver($scoped);
check(count($fixture->effects)===$beforeScoped+3&&array_column($scopedRows,'state')===['accepted','accepted'],'Explicit scoped sender did not deliver its frozen browser endpoints and connector');
$revokedScope=$scoped;$revokedScope['delivery_id']='announcement-'.hash('md5','revoked-scoped-fixture');
$fixture->settings['control_api']['credentials']=ApiSecurity::revoke($fixture->settings['control_api']['credentials'],$issued['credential']['id']);
check(array_column($fixture->deliver($revokedScope),'state')===['cancelled','cancelled']&&count($fixture->effects)===$beforeScoped+3,'Revoked named sender dispatched queued Labs recipients');
// Subsequent existing cases use their independent configuration and effect count.
$fixture->settings=$settings;$fixture->effects=array_slice($fixture->effects,0,$beforeScoped);
$only=$frozen;$only['delivery_id']='announcement-'.str_repeat('b',32);$only['only_channels']=['connector'=>[$connector['id']]];$fixture->deliver($only);check(count($fixture->effects)===3&&$fixture->effects[2][0]==='connector','Explicit partial channel selection leaked browser delivery');
$fixture->approvalAllowed=false;$invalidApproval=$frozen;$invalidApproval['delivery_id']='announcement-'.str_repeat('f',32);check(array_column($fixture->deliver($invalidApproval),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Revoked dual approval allowed browser or connector effects');$fixture->approvalAllowed=true;
$fixture->settings['runtime_enabled']=false;$paused=$frozen;$paused['delivery_id']='announcement-'.str_repeat('0',32);check(array_column($fixture->deliver($paused),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Stopped notifications allowed browser or connector effects');$fixture->settings['runtime_enabled']=true;
$incidentExpired=$frozen;$incidentExpired['delivery_id']='announcement-'.str_repeat('d',32);$incidentExpired['incident_context']=['expires_at'=>gmdate('c',time()-1)];check(array_column($fixture->deliver($incidentExpired),'state')===['cancelled','cancelled']&&count($fixture->effects)===3,'Shared incident expiry was extended by a later browser or connector cohort');
$replacement=$subscription;$replacement['keys']['auth']=BrowserPush::encode(random_bytes(16));$subscriber->enrollPush($settings,$bound,$replacement,$now);$changedEndpoint=$frozen;$changedEndpoint['delivery_id']='announcement-'.str_repeat('e',32);$changedEndpoint['connector_fingerprints']=[];check(array_column($fixture->deliver($changedEndpoint),'state')===['cancelled']&&count($fixture->effects)===3,'New subscription keys received an old frozen announcement');
$subscriber->enrollPush($settings,$bound,$subscription,$now);
$boundaryFailures=[];
foreach(['expiry','credential','runtime'] as $boundaryCase){
    $fixture->settings=$settings;$beforeBoundary=count($fixture->effects);
    $boundaryRequest=$frozen;$boundaryRequest['delivery_id']='announcement-'.hash('md5','boundary-'.$boundaryCase);
    $boundaryRequest['browser_targets']=[];$boundaryRequest['delivery_timestamp']=gmdate('c');
    if($boundaryCase==='expiry'){$boundaryRequest['display_timeout']=1;}
    $fixture->beforeConnectorClaim=static function(QueuedLabsFixture $fixture)use($boundaryCase):void{
        if($boundaryCase==='expiry'){sleep(2);}
        elseif($boundaryCase==='credential'){$fixture->settings['enterprise_integrations']['connectors']['destinations'][0]['token']='revoked-after-check';}
        else{$fixture->settings['runtime_enabled']=false;}
    };
    $boundaryRows=$fixture->deliver($boundaryRequest);
    if(count($fixture->effects)!==$beforeBoundary||array_column($boundaryRows,'state')!==['cancelled']){$boundaryFailures[]=$boundaryCase;}
    $fixture->settings=$settings;
    if(count($fixture->effects)!==$beforeBoundary||array_column($fixture->deliver($boundaryRequest),'state')!==['cancelled']||count($fixture->effects)!==$beforeBoundary){$boundaryFailures[]=$boundaryCase.' replay';}
}
check(!$boundaryFailures,'Connector sent after final-boundary changes: '.implode(', ',$boundaryFailures));
$fixture->settings=$settings;
$fixture->browserThrows=true;$interrupted=$frozen;$interrupted['delivery_id']='announcement-'.hash('md5','interrupted-browser-fixture');$interrupted['connector_fingerprints']=[];
$beforeInterrupted=count($fixture->effects);$interruptedRows=$fixture->deliver($interrupted);check(array_column($interruptedRows,'state')===['uncertain']&&count($fixture->effects)===$beforeInterrupted+1,'Interrupted browser submission was not stored as uncertain');
$claims=$store->read('browser_push_claims');$interruptedReceipts=array_values(array_filter(array_column($claims,'receipt'),static fn($receipt)=>is_array($receipt)&&($receipt['detail']??'')==='Browser push was not confirmed; no automatic replay.'));
check(count($interruptedReceipts)===1&&!isset($interruptedReceipts[0]['http_status']),'Interrupted browser receipt was lost or assigned a fabricated HTTP status');
check($fixture->deliver($interrupted)===$interruptedRows&&count($fixture->effects)===$beforeInterrupted+1,'Interrupted browser submission replayed its external effect');$fixture->browserThrows=false;
$pushLedger=$root.'/state/browser_push_claims.json';$pushBefore=file_get_contents($pushLedger);rejectStorage(fn()=>$store->transaction('browser_push_claims',static function(array &$rows):void{$key=array_key_first($rows);$rows[$key]['receipt']['state']='bogus';}),'Invalid browser receipt mutation was persisted');check(file_get_contents($pushLedger)===$pushBefore,'Rejected browser receipt mutation changed prior bytes');
$connectorLedger=$root.'/state/connector_claims.json';$connectorBefore=file_get_contents($connectorLedger);rejectStorage(fn()=>$store->transaction('connector_claims',static function(array &$rows):void{$key=array_key_first($rows);$rows[$key]['receipt']['provider_message_id']=['invalid'];}),'Invalid connector receipt mutation was persisted');check(file_get_contents($connectorLedger)===$connectorBefore,'Rejected connector receipt mutation changed prior bytes');
$fixture->browserStatus=410;$gone=$frozen;$gone['delivery_id']='announcement-'.str_repeat('c',32);$gone['connector_fingerprints']=[];$fixture->deliver($gone);check(!in_array($enrollment['endpoint_id'],$subscriber->pushTargets($settings,$person['id']),true),'Revoked push endpoint remained enabled');
$disabled=$settings;$disabled['subscriber_browser']['push']['enabled']=false;reject(fn()=>$subscriber->push($disabled,$person['id'],['event_id'=>'disabled'],time()),'Disabled push accepted');
restore_error_handler();
identityCleanup($root,true);$cleanupComplete=true;
echo "Queued Labs delivery: verified enrollment, frozen endpoints, RFC-encrypted submission, connector acceptance, replay isolation, revocation, expiry and explicit channel selection passed.\n";
