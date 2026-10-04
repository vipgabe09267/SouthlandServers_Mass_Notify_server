<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/RecipientSelection.php';
use SLS\MassNotify\RecipientSelection;
$count=0;
$check=static function($ok,$message)use(&$count):void{++$count;if(!$ok){throw new RuntimeException($message);}};
$actions=['send_announcement'=>'announcement_email_recipient_ids','save_announcement_group'=>'group_email_recipient_ids','save_scheduled_announcement'=>'schedule_email_recipient_ids'];
$ids=[];for($i=0;$i<51;$i++){$ids[]='email_'.str_pad(dechex($i+1),24,'0',STR_PAD_LEFT);}
foreach($actions as $action=>$field){
 $check(in_array($field,RecipientSelection::FIELDS[$action],true),'Email field missing from recipient envelope.');
 $envelope=array_fill_keys(RecipientSelection::FIELDS[$action],[]);
 $input=['slsmassnotifyserver_action'=>$action,'sls_recipient_selection_present'=>'1','sls_recipient_selection_complete'=>'1','unrelated'=>'preserve',$field=>['arbitrary@example.com']];
 foreach([[],[$ids[0]],array_slice($ids,0,50)] as $selected){
  $envelope[$field]=$selected;$input['sls_recipient_selection_json']=json_encode($envelope);$decoded=RecipientSelection::decode($input);
  $check($decoded[$field]===$selected && $decoded['unrelated']==='preserve','Envelope lost email selection or accepted conflicting raw fields.');
 }
 foreach([$ids,['raw@example.com'],['email_short'],[strtoupper($ids[0])],[$ids[0]."\n"],[123],[[$ids[0]]],['id'=>$ids[0]],null,'all'] as $bad){
  $envelope[$field]=$bad;$input['sls_recipient_selection_json']=json_encode($envelope);$rejected=false;
  try{RecipientSelection::decode($input);}catch(DomainException $error){$rejected=true;}
  $check($rejected,'Malformed/oversized email recipient envelope accepted.');
 }
 $envelope[$field]=[];unset($envelope[$field]);$input['sls_recipient_selection_json']=json_encode($envelope);$rejected=false;
 try{RecipientSelection::decode($input);}catch(DomainException $error){$rejected=true;}
 $check($rejected,'Incomplete modern recipient envelope silently lost email field.');
 $legacy=['slsmassnotifyserver_action'=>$action,$field=>[$ids[0]]];
 $check(RecipientSelection::decode($legacy)===$legacy,'Non-envelope compatibility path changed.');
}
echo "Email recipient form envelopes: $count checks passed.\n";
