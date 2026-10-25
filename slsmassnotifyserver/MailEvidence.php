<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/DeploymentReadiness.php';

/** Cached transport evidence only. Never resubmits a message or touches Postfix. */
final class MailEvidence
{
    public static function read(string $directory): array
    {
        return \FreePBX\modules\SlsDeploymentReadiness::snapshot($directory.'/mail-evidence.json',4194304)['data']??[];
    }
    public static function project(array $receipt,array $snapshot,int $now): array
    {
        $message=$receipt['message_id']??'';$recipient=$receipt['mail_recipient_hash']??'';
        if(($receipt['channel']??'')!=='email'||!in_array($receipt['state']??'',['accepted','uncertain'],true)
            ||!is_string($message)||!preg_match('/^<sls-[a-f0-9]{32}-[a-f0-9]{24}@[A-Za-z0-9.-]{1,253}>$/D',$message)
            ||!is_string($recipient)||!preg_match('/^[a-f0-9]{64}$/D',$recipient)||($snapshot['schema']??null)!==1
            ||!is_array($snapshot['messages']??null)||count($snapshot['messages'])>5000){return $receipt;}
        $messageHash=hash('sha256',$message);$key=hash('sha256',$messageHash.'|'.$recipient);$record=$snapshot['messages'][$key]??null;
        if(!is_array($record)||($record['message_hash']??null)!==$messageHash||($record['recipient_hash']??null)!==$recipient
            ||!is_int($record['observed_at']??null)||$record['observed_at']<1||$record['observed_at']>$now
            ||($record['expires_at']??0)!==$record['observed_at']+7*86400||$record['expires_at']<=$now
            ||!is_string($record['dsn']??null)||!preg_match('/^[245]\.[0-9]{1,3}\.[0-9]{1,3}$/D',$record['dsn'])
            ||!in_array($record['stage']??null,['relay_accepted','deferred','bounced'],true)){return $receipt;}
        $expected=['relay_accepted'=>'2','deferred'=>'4','bounced'=>'5'];
        if($record['dsn'][0]!==$expected[$record['stage']]){return $receipt;}
        $receipt['postfix_stage']=$record['stage'];$receipt['postfix_dsn']=$record['dsn'];$receipt['postfix_observed_at']=gmdate('c',$record['observed_at']);
        if($receipt['state']==='uncertain'&&$record['stage']!=='bounced'){
            $receipt['local_submission_state']='uncertain';$receipt['state']='accepted';$receipt['retryable']=false;$receipt['submission_started']=true;
        }
        if($record['stage']==='relay_accepted'){
            $receipt['detail']='Postfix reports relay acceptance (DSN '.$record['dsn'].'). This does not confirm mailbox placement or that a person read the message.';
        }elseif($record['stage']==='deferred'){
            $receipt['detail']='Postfix deferred this message (DSN '.$record['dsn'].'). Postfix may retry under its own queue policy; SLS has not resubmitted it.';
        }else{
            $receipt['local_submission_state']=$receipt['state'];$receipt['state']='failed';$receipt['retryable']=false;$receipt['failure_category']='postfix_bounce';$receipt['submission_started']=true;
            $receipt['detail']='Postfix reports a bounce (DSN '.$record['dsn'].'). Review the recipient and mail relay; SLS will not resubmit this message automatically.';
        }
        return $receipt;
    }
}
