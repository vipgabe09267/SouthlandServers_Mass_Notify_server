<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/SubscriberService.php';
require dirname(__DIR__).'/slsmassnotifyserver/AnnouncementDelivery.php';
use SLS\MassNotify\{SubscriberService,SubscriberConfig,EnterpriseIdentityStore,IncidentStore,BrowserPush,BrowserEvents};
$checks=0;
function eventCheck(bool $ok,string $message): void {$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function eventReject(callable $work): void {try{$work();}catch(Throwable $e){if($e instanceof RuntimeException||$e instanceof DomainException){eventCheck($e->getMessage()!=='','Missing error.');return;}throw $e;}throw new RuntimeException('Unsafe browser operation accepted.');}
$root=sys_get_temp_dir().'/sls-browser-events-'.bin2hex(random_bytes(12));mkdir($root,0700);
register_shutdown_function(static function()use($root):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);});
$state=new EnterpriseIdentityStore($root.'/state');$incidents=new IncidentStore($root.'/incidents');$service=new SubscriberService($state,$incidents);$events=new BrowserEvents($state);$now=time();
$vapid=BrowserPush::generateKey();$person=['id'=>'sub_'.str_repeat('a',24),'person_id'=>'fixture-person','name'=>'Fixture person','email'=>'fixture@example.test','enabled'=>true,'version'=>str_repeat('a',64),'registered_at'=>$now-30];
$settings=['subscriber_browser'=>array_replace(SubscriberConfig::defaults(),['enabled'=>true,'public_base_url'=>'https://pbx.example.test:8443/mass-notify/subscriber.php','people'=>[$person],
    'push'=>['enabled'=>true,'private_key_pem'=>$vapid['private_key_pem'],'subject'=>'mailto:fixture@example.test']])];
$issued=$service->issue($settings,$person['id'],'',$now);$service->delivery($issued['token_hash'],'accepted');$binding=$service->verify($settings,$issued['token'],$now);
$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/browser-event-fixture','keys'=>['p256dh'=>$vapid['public_key'],'auth'=>BrowserPush::encode(random_bytes(16))]];
$endpoint=$service->enrollPush($settings,$binding,$subscription,$now)['endpoint_id'];$snapshot=$service->pushSnapshot($settings,$person['id']);$attempts=0;
$transport=static function($url,$body,$headers)use(&$attempts):array{$attempts++;eventCheck(!str_contains($body,'Fixture message'),'Browser payload was not encrypted.');return ['error'=>0,'status'=>201];};
$incident=['schema'=>1,'incident_id'=>'inc_'.str_repeat('a',32),'sequence'=>2,'kind'=>'update','severity'=>'warning','is_test'=>true];
$event=['event_id'=>'browser_fixture_one','title'=>'Fixture title','message'=>'Fixture message','expires_at'=>$now+300,'is_test'=>true,'incident'=>$incident];
$sent=$service->push($settings,$person['id'],$event,$now,$transport,$snapshot);
eventCheck($sent['receipts'][$endpoint]['state']==='accepted'&&$attempts===1,'Provider submission failed.');
$key=BrowserEvents::key($person['id'],$event['event_id']);$ledger='browser_events_'.substr($key,0,1);$row=$state->read($ledger)[$key];$proof=BrowserEvents::proof($person,$row,$endpoint);
$wire=json_decode(BrowserPush::validateEvent($event+['browser_receipt'=>$proof],$now),true);
eventCheck($wire['incident']===$incident&&$wire['browser_receipt']===$proof,'Browser wire payload dropped typed context or proof.');
eventCheck($events->summary($person['id'],$event['event_id'])['received']===0,'Provider acceptance counted as browser receipt.');
$one=$events->receipt($settings,$proof+['stage'=>'received'],$now+1);$events->receipt($settings,$proof+['stage'=>'received'],$now+2);
eventCheck($one['ok']&&$one['event_id']===$event['event_id']&&$one['receipt_type']==='browser_software_received','Receipt response lost exact event identity.');
eventCheck($state->read($ledger)[$key]['receipts'][$endpoint]['received']===$now+1,'Duplicate receipt changed its original timestamp.');
$events->receipt($settings,$proof+['stage'=>'displayed'],$now+2);$events->receipt($settings,$proof+['stage'=>'clicked'],$now+3);
$summary=(new BrowserEvents($state))->summary($person['id'],$event['event_id']);
eventCheck($summary===['endpoints'=>1,'received'=>1,'displayed'=>1,'clicked'=>1],'Separate browser evidence was lost.');
$detail=$events->details($settings,$person['id'],$event['event_id'],$now+3);eventCheck($detail===$event,'Exact alert navigation returned another event.');
$disabled=$settings;$disabled['enterprise_labs']=['enabled'=>false];$disabled['subscriber_browser']['enabled']=false;$disabled['subscriber_browser']['push']['enabled']=false;
eventReject(fn()=>$events->receipt($disabled,$proof+['stage'=>'received'],$now+3));
eventCheck($events->receipt($disabled,$proof+['stage'=>'received'],$now+3,true)['ok'],'Feature disablement discarded a valid passive receipt.');
eventReject(fn()=>$events->receipt($disabled,array_replace($proof,['token'=>str_repeat('f',64)])+['stage'=>'received'],$now+3,true));
eventReject(fn()=>$events->receipt($disabled,$proof+['stage'=>'received'],$now+301,true));
$revoked=$disabled;$revoked['subscriber_browser']['people'][0]['enabled']=false;
eventReject(fn()=>$events->receipt($revoked,$proof+['stage'=>'received'],$now+3,true));
$changedIdentity=$disabled;$changedIdentity['subscriber_browser']['people'][0]['version']=str_repeat('e',64);
eventReject(fn()=>$events->receipt($changedIdentity,$proof+['stage'=>'received'],$now+3,true));
eventReject(fn()=>$service->enrollPush($disabled,$binding,$subscription,$now+3));
eventReject(fn()=>$service->push($disabled,$person['id'],$event,$now+3,$transport,$snapshot));
eventReject(fn()=>$service->removeAllPush($revoked,$binding,$now+3,true));
eventReject(fn()=>$service->removeAllPush($changedIdentity,$binding,$now+3,true));
eventReject(fn()=>$service->removeAllPush($disabled,$binding,$now+3));
$other=$person;$other['id']='sub_'.str_repeat('b',24);$other['person_id']='other';$settings['subscriber_browser']['people'][]=$other;
eventReject(fn()=>$events->details($settings,$other['id'],$event['event_id'],$now+3));
eventReject(fn()=>$events->receipt($settings,array_replace($proof,['token'=>str_repeat('f',64)])+['stage'=>'received'],$now+3));
eventReject(fn()=>$events->receipt($settings,$proof+['stage'=>'safe'],$now+3));
eventReject(fn()=>$events->receipt($settings,$proof+['stage'=>'received'],$now+301));
eventReject(fn()=>$events->details($settings,$person['id'],$event['event_id'],$now+301));
eventReject(fn()=>$service->respond($settings,$binding,['request_id'=>str_repeat('c',32),'response'=>'safe'],$now+3));
$service->push($settings,$person['id'],$event,$now+4,$transport,$snapshot);eventCheck($attempts===1,'Duplicate push replayed its transport.');
$changed=$event;$changed['message']='Changed';eventReject(fn()=>$service->push($settings,$person['id'],$changed,$now+4,$transport,$snapshot));
// An enrollment generation is part of the frozen endpoint identity. Explicit
// unsubscribe/reconsent must not revive receipts or pending sends from the old one.
$service->removeAllPush($disabled,$binding,$now+5,true);eventReject(fn()=>$events->receipt($settings,$proof+['stage'=>'received'],$now+5));
eventReject(fn()=>$events->receipt($disabled,$proof+['stage'=>'received'],$now+5,true));
eventCheck((new BrowserEvents($state))->summary($person['id'],$event['event_id'])===$summary,'Passive cleanup erased delivery history.');
$service->enrollPush($settings,$binding,$subscription,$now+6);eventCheck($snapshot!==$service->pushSnapshot($settings,$person['id']),'Re-enrollment retained the old endpoint generation.');
eventReject(fn()=>$events->receipt($settings,$proof+['stage'=>'received'],$now+6));
eventCheck($service->push($settings,$person['id'],$event,$now+6,$transport,$snapshot)['state']==='no_enrolled_browser'&&$attempts===1,'Old queued delivery reached a re-enrolled browser.');
class BrowserEvidenceProjection {use \FreePBX\modules\SlsAnnouncementDelivery;private EnterpriseIdentityStore $state;public function __construct($s){$this->state=$s;}protected function enterpriseLabsStore(){return $this->state;}public function project($r){return $this->refreshAnnouncementDesktopReceipts($r,gmdate('c'));}}
$projected=(new BrowserEvidenceProjection($state))->project(['receipts'=>[['channel'=>'browser_push','target'=>$person['id'],'state'=>'accepted','event_id'=>$event['event_id'],'detail'=>'Provider accepted.']]]);
eventCheck($projected['receipts'][0]['state']==='received'&&$projected['receipts'][0]['browser_software_receipts']['received']===1,'Early browser receipt did not refresh the exact saved delivery row.');
eventCheck(str_contains($projected['receipts'][0]['detail'],'Human responses are separate'),'Delivery view conflated software and human evidence.');
eventCheck((new BrowserEvents($state))->summary($person['id'],$event['event_id'])['clicked']===1,'Expiration or revocation erased delivery evidence.');
$path=$root.'/state/'.$ledger.'.json';chmod($path,0644);eventReject(fn()=>$events->details($settings,$person['id'],$event['event_id'],$now+7));
try{$events->receipt($settings,array_replace($proof,['token'=>str_repeat('f',64)])+['stage'=>'received'],$now+7);throw new RuntimeException('Forged proof was accepted.');}
catch(DomainException $error){eventCheck(str_contains($error->getMessage(),'proof'),'Forged capability opened an unsafe event ledger before authentication.');}
$fallback=$event;
for($i=0;$i<1000;$i++){$fallback['event_id']='fallback_'.$i;if(substr(BrowserEvents::key($person['id'],$fallback['event_id']),0,1)===substr($key,0,1)){break;}}
$liveSnapshot=$service->pushSnapshot($settings,$person['id']);$beforeAttempts=$attempts;
$fallbackResult=$service->push($settings,$person['id'],$fallback,$now+7,$transport,$liveSnapshot);
eventCheck($attempts===$beforeAttempts+1&&$fallbackResult['receipts'][$endpoint]['state']==='accepted'&&!$fallbackResult['software_evidence_available'],'A supplemental receipt fault blocked an otherwise authorized alert or claimed software evidence.');
$service->push($settings,$person['id'],$fallback,$now+8,$transport,$liveSnapshot);eventCheck($attempts===$beforeAttempts+1,'Supplemental history failure bypassed durable replay claims.');
$claims=$root.'/state/browser_push_claims.json';chmod($claims,0644);$otherEvent=$fallback;$otherEvent['event_id']='mandatory_claim_failure';
eventReject(fn()=>$service->push($settings,$person['id'],$otherEvent,$now+9,$transport,$liveSnapshot));eventCheck($attempts===$beforeAttempts+1,'Unsafe mandatory replay history allowed provider delivery.');
chmod($claims,0600);chmod($path,0600);
$pending=(new BrowserEvidenceProjection($state))->project(['receipts'=>[['channel'=>'browser_push','target'=>$person['id'],'state'=>'uncertain','event_id'=>'pending_receipt_without_response','detail'=>'Provider result was uncertain.']]]);
eventCheck($pending['receipt_poll_pending']===true,'An uncertain provider result stopped polling before an exact browser receipt could arrive.');
echo "Browser events: $checks exact-context, recipient isolation, one-use enrollment generations, idempotent software evidence, absolute expiry and delivery refresh checks passed.\n";
