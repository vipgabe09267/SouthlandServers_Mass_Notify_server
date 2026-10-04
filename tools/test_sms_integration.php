<?php
declare(strict_types=1);
// Reuse the inert main-class facade (no PBX constructor, private files only).
require __DIR__.'/test_announcement_email_facade.php';
use SLS\MassNotify\Sms\Config as SmsConfig;
use SLS\MassNotify\ApiSecurity as Security;
mkdir($directory,0700); $count=0;
try {
    $a='sms_'.str_repeat('a',24); $b='sms_'.str_repeat('b',24);
    $sms=SmsConfig::normalize(['enabled'=>true,'provider'=>'twilio','from'=>'+15555550100','organization'=>'Fixture',
        'twilio_account_sid'=>'AC'.str_repeat('a',32),'twilio_key_sid'=>'SK'.str_repeat('b',32),
        'twilio_key_secret'=>'fixture-sending-secret','twilio_auth_token'=>str_repeat('c',32),'segment_cost_micros'=>10000,
        'recipients'=>[['id'=>$a,'name'=>'Facilities <b>','number'=>'+15555550101','enabled'=>true,'consent'=>true,'consent_note'=>'Fixture consent','consent_at'=>gmdate('c',time()-3600)],
            ['id'=>$b,'name'=>'Not authorized','number'=>'+15555550102','enabled'=>true,'consent'=>false,'consent_note'=>'','consent_at'=>'']]]);
    $base=$call('getDefaultSettings'); $base['desktop_clients']=[]; $base['announcement_sms']=$sms;
    $base['public_pbx_host']='pbx.example.com'; $base['control_api']['base_url']='https://pbx.example.com:8443/api/sls-mass-notify';
    $base['opening_tone']=''; $base['closing_tone']=''; $base['announcement_groups']=[]; $base['scheduled_announcements']=[];
    $m=new EmailMainFacadeFixture(); $m->call=$call; $m->directory=$directory; $m->settings=$base;
    $send=static fn($ids,$options=[])=>$m->sendSipNotifyAnnouncement([],'Complete SMS announcement',true,false,[],array_replace(['audio_mode'=>'none','sms_recipient_ids'=>$ids],$options));
    $check($send([$a])['success'],'SMS-only main announcement rejected');
    $check(end($m->resolved)['sms_recipient_ids']===[$a],'Saved SMS identity lost');
    foreach ([null,[$b],[$a,$a],['+15555550101'],['sms_'.str_repeat('c',24)]] as $bad) {
        $before=count($m->resolved); $check(!$send($bad)['success'] && count($m->resolved)===$before,'Invalid or unconsented SMS entered delivery');
    }
    foreach (['sms','sms_recipients','sms_numbers'] as $rawField) {
        $check(!$send([$a],[$rawField=>['+15555550102']])['success'],'Raw numbers accepted by facade');
        $check($call('validateControlApiAnnouncementPayload',[$rawField=>['+15555550101']])!==[],'Raw API SMS numbers accepted');
    }
    $check(!$send([$a],['audio_mode'=>'tts'])['success'],'SMS-only audience activated phone audio');
    $group=$m->saveAnnouncementGroup('','SMS group',[],[],[],[],[$a]); $check($group['success'],'SMS-only group rejected');
    $saved=end($m->settings['announcement_groups']); $check($saved['sms_recipient_ids']===[$a],'Group lost saved IDs');
    $groupSend=$m->sendSipNotifyAnnouncement([],'Group SMS',true,false,[$saved['id']],['audio_mode'=>'none']);
    $check($groupSend['success'] && end($m->resolved)['sms_recipient_ids']===[$a],'Group selection not expanded');
    $input=['schedule_name'=>'SMS schedule','schedule_enabled'=>'1','schedule_message'=>'Complete SMS text',
        'schedule_occurrences'=>[gmdate('Y-m-d\TH:i',time()+86400)],'schedule_audio_mode'=>'none','schedule_sms_recipient_ids'=>[$a]];
    $result=$m->saveScheduledAnnouncement($input); $check($result['success'],'SMS-only schedule rejected: '.json_encode($result));
    $schedule=end($m->settings['scheduled_announcements']);
    $check($schedule['targets']['sms_recipient_ids']===[$a] && $m->validate([$schedule])===[] && $m->warnings()===[],'Schedule lost valid SMS or reports missing audience');
    $m->settings['announcement_sms']['recipients'][0]['consent']=false;
    $check(!$send([$a])['success'] && $m->warnings()!==[],'Revoked SMS consent not rechecked');
    $schedule['occurrences'][0]['run_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-5);
    $m->settings['scheduled_announcements']=$m->normalized([$schedule]); $before=count($m->resolved);
    $processed=$m->processScheduledAnnouncements();
    $check($processed['processed']===0 && $processed['attention']===1 && count($m->resolved)===$before,'Due schedule ignored revoked consent');
    $m->processScheduledAnnouncements(); $check(count($m->resolved)===$before,'Rejected scheduled SMS replayed');
    $redacted=$call('redactConfigSecrets',$base);
    foreach (['twilio_key_secret','twilio_auth_token','telnyx_api_key'] as $secretKey) { $check($redacted['announcement_sms'][$secretKey]==='[redacted]','SMS API config leaked secret'); }
    $patch=$call('validateAndNormalizeControlConfigPatch',['announcement_sms'=>$sms]);
    $check(empty($patch['errors']),'Valid SMS protected config patch rejected');
    $check(empty($call('validateAndNormalizeControlConfigPatch',['announcement_sms'=>['enabled'=>true]])['errors']),'Partial SMS enable patch rejected before merging');
    $check(empty($call('validateAndNormalizeControlConfigPatch',['announcement_sms'=>$redacted['announcement_sms']])['errors']),'Redacted SMS roundtrip rejected');
    $check($call('mergeControlConfigPatch',$base,['announcement_sms'=>$redacted['announcement_sms']])['announcement_sms']['twilio_key_secret']===$sms['twilio_key_secret'],'Redacted SMS roundtrip discarded a secret');
    $invalid=$sms; $invalid['recipients'][0]['consent_at']='invalid';
    $check(!empty($call('validateAndNormalizeControlConfigPatch',['announcement_sms'=>$invalid])['errors']),'Invalid consent date accepted by config API');
    $form=['announcement_sms_present'=>'1','announcement_sms_complete'=>'1','announcement_sms_enabled'=>'1',
        'announcement_sms_recipients_json'=>json_encode($sms['recipients'])];
    $savedForm=$call('readAnnouncementSmsForm',$form,$sms);
    $check($savedForm['twilio_key_secret']===$sms['twilio_key_secret'] && $savedForm['recipients'][0]['consent_at']===$sms['recipients'][0]['consent_at'],'Form lost secret or replaced original consent date');
    $rows=$sms['recipients']; $rows[0]['renew_consent']=true;
    $form['announcement_sms_recipients_json']=json_encode($rows); $rejected=false;
    try {$call('readAnnouncementSmsForm',$form,$sms);} catch(DomainException $e){$rejected=true;}
    $check($rejected,'Renewed consent with unchanged evidence accepted');
    $rows[0]['consent_note']='New fixture consent'; $form['announcement_sms_recipients_json']=json_encode($rows);
    $renewed=$call('readAnnouncementSmsForm',$form,$sms);
    $check(strtotime($renewed['recipients'][0]['consent_at'])>strtotime($sms['recipients'][0]['consent_at']),'Renewed consent not timestamped on server');
    $changed=$sms['recipients'];$changed[0]['number']='+15555550103';
    $form['announcement_sms_recipients_json']=json_encode($changed);$rejected=false;
    try {$call('readAnnouncementSmsForm',$form,$sms);} catch(DomainException $e){$rejected=true;}
    $check($rejected,'A changed number inherited old SMS consent without updated evidence');
    $revoked=$sms;$revoked['recipients'][0]['consent']=false;$revoked['recipients'][0]['consent_at']='';
    $form['announcement_sms_recipients_json']=json_encode($sms['recipients']);$rejected=false;
    try {$call('readAnnouncementSmsForm',$form,$revoked);} catch(DomainException $e){$rejected=true;}
    $check($rejected,'Restoring revoked consent reused an old note');
    $issued=Security::issue(['name'=>'SMS sender','scopes'=>['send'],'audience'=>['unrestricted'=>false,'sms_recipient_ids'=>[$a]]]);
    $principal=Security::authenticate(['credentials'=>[$issued['credential']]],$issued['secret']);
    $check(Security::permitsResolvedAnnouncement($principal,['sms_recipient_ids'=>[$a]],$base),'Scoped SMS grant rejected');
    $check(!Security::permitsResolvedAnnouncement($principal,['sms_recipient_ids'=>[$b]],$base),'Scoped grant expanded');
    $old=$principal; unset($old['audience']['sms_recipient_ids']);
    $check(!Security::permitsResolvedAnnouncement($old,['sms_recipient_ids'=>[$a]],$base),'Old API credential gained SMS authority');
    foreach ([null,'all',['+15555550101']] as $bad) {$check(!Security::permitsResolvedAnnouncement($principal,['sms_recipient_ids'=>$bad],$base),'Malformed SMS authority accepted');}
    $settings=$base; $api_credentials=[$issued['credential']]; $csrf_token='fixture';
    ob_start(); include dirname(__DIR__).'/slsmassnotifyserver/views/api_credentials.php'; $html=ob_get_clean();
    $check(str_contains($html,'data-credential-audience="sms_recipient_ids"') && str_contains($html,'value="'.$a.'"'),'SMS grant picker missing');
    $check(!str_contains($html,'+15555550101') && !str_contains($html,'Facilities <b>') && !str_contains($html,$issued['secret']),'Permission picker exposed numbers, unescaped labels or secret');
    echo "SMS main facade/config/consent/API/group/schedule integration: $count checks passed.\n";
} finally { foreach(glob($directory.'/*')?:[] as $path){if(is_file($path))unlink($path);} rmdir($directory); }
