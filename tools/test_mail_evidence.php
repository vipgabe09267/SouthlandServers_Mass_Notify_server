<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/MailEvidence.php';
use SLS\MassNotify\MailEvidence;
$checks=0;
function mailCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$now=1800000000;$message='<sls-'.str_repeat('a',32).'-'.str_repeat('b',24).'@example.test>';$recipient=hash('sha256','fixture@example.test');$messageHash=hash('sha256',$message);
$receipt=['channel'=>'email','target'=>'email_'.str_repeat('b',24),'state'=>'accepted','detail'=>'Accepted by local Postfix.','message_id'=>$message,'mail_recipient_hash'=>$recipient,'retryable'=>false];
$key=hash('sha256',$messageHash.'|'.$recipient);
$record=['message_hash'=>$messageHash,'recipient_hash'=>$recipient,'stage'=>'relay_accepted','dsn'=>'2.0.0','observed_at'=>$now-1,'expires_at'=>$now-1+7*86400,'queue_hash'=>str_repeat('c',64)];
$snapshot=['schema'=>1,'generated_at'=>$now,'messages'=>[$key=>$record]];
$projected=MailEvidence::project($receipt,$snapshot,$now);mailCheck($projected['state']==='accepted'&&$projected['postfix_stage']==='relay_accepted','Relay acceptance was lost.');
mailCheck(str_contains($projected['detail'],'does not confirm mailbox placement'),'Relay acceptance implied a mailbox or human read.');
$snapshot['messages'][$key]['stage']='deferred';$snapshot['messages'][$key]['dsn']='4.4.1';
$projected=MailEvidence::project($receipt,$snapshot,$now);mailCheck($projected['state']==='accepted'&&str_contains($projected['detail'],'SLS has not resubmitted'),'Deferral changed submission evidence or implied an SLS retry.');
$uncertain=$receipt;$uncertain['state']='uncertain';mailCheck(MailEvidence::project($uncertain,$snapshot,$now)['state']==='accepted','Exact trusted queue evidence did not resolve uncertain submission.');
$snapshot['messages'][$key]['stage']='bounced';$snapshot['messages'][$key]['dsn']='5.1.1';
$projected=MailEvidence::project($receipt,$snapshot,$now);mailCheck($projected['state']==='failed'&&!$projected['retryable']&&$projected['submission_started'],'Bounce allowed an automatic replay.');
mailCheck($projected['local_submission_state']==='accepted','Bounce erased original local acceptance.');
foreach([['message_hash'=>str_repeat('f',64)],['recipient_hash'=>str_repeat('f',64)],['stage'=>'delivered'],['dsn'=>'2.0.0'],
    ['observed_at'=>$now+1],['expires_at'=>$now-1],['observed_at'=>'1800000000']] as $changes){
    $bad=$snapshot;$bad['messages'][$key]=array_replace($bad['messages'][$key],$changes);mailCheck(MailEvidence::project($receipt,$bad,$now)===$receipt,'Invalid or unrelated evidence changed a delivery row.');
}
mailCheck(MailEvidence::project($receipt,[],$now)===$receipt,'Missing evidence erased local submission.');
$old=$receipt;unset($old['mail_recipient_hash']);mailCheck(MailEvidence::project($old,$snapshot,$now)===$old,'Legacy row guessed a changed recipient address.');
mailCheck(MailEvidence::project($receipt,$snapshot,$now+7*86400)===$receipt,'Expired evidence was treated as current.');
echo "Postfix evidence: $checks stage, recipient isolation, stale history, bounce, uncertainty and no-replay checks passed.\n";
