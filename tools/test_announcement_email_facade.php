<?php
/** Real main-class methods with only private storage/host/submission adapters. */
declare(strict_types=1);
if(!interface_exists('BMO')){interface BMO{}}
require_once dirname(__DIR__).'/slsmassnotifyserver/Slsmassnotifyserver.class.php';
require_once dirname(__DIR__).'/slsmassnotifyserver/AnnouncementEmail.php';
class EmailMainReflectionFixture extends \FreePBX\modules\Slsmassnotifyserver {
    public function getConfiguredPjsipExtensionNumbers(){return ['1000'];}
}
$reflection=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$raw=(new ReflectionClass(EmailMainReflectionFixture::class))->newInstanceWithoutConstructor();
$call=static function($name,...$args)use($reflection,$raw){$method=$reflection->getMethod($name);$method->setAccessible(true);$references=[];foreach($args as &$arg){$references[]=&$arg;}unset($arg);return $method->invokeArgs($raw,$references);};
$count=0;
$check=static function($condition,$message)use(&$count){++$count;if(!$condition)throw new RuntimeException($message);};
$directory=sys_get_temp_dir().'/sls-email-facade-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$methods='';
foreach(['sendSipNotifyAnnouncement','resolveAnnouncementRequest','saveAnnouncementGroup','saveScheduledAnnouncement','normalizeScheduledAnnouncements',
    'getScheduledAnnouncementHealthWarnings','processScheduledAnnouncements','findLiveScheduledOccurrence','scheduleExecutionRecord',
    'validateScheduledAnnouncementRecurrences','sanitizeScheduleText','normalizeScheduleRecurrenceMode','scheduleRecurrenceIntervalDays',
    'buildScheduledOccurrences','scheduleRecurrenceMatchesOccurrences','resolveScheduleLocalDateTime','parseScheduleUtcTimestamp',
    'normalizeAnnouncementAudioMode','normalizeToneName']as $name){
    $method=$reflection->getMethod($name);$lines=file($method->getFileName());
    $methods.=implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
}
$fixture= <<<'FIXTURE'
class EmailMainFacadeFixture {
    use \FreePBX\modules\SlsLocations;
    use \FreePBX\modules\SlsAnnouncementEmail;
    use \FreePBX\modules\SlsOutboundVoice;
    use \FreePBX\modules\SlsScheduledDelivery;
    private $announcementAdmissionLock;
    public $settings=[], $pending=null, $resolved=[], $incidentContext=null, $call, $directory, $store=['occurrences'=>[]], $status=[];
    public function __call($name,$args){return ($this->call)($name,...$args);}
    private function getActiveSettings(){return $this->settings;}
    private function getPendingSettings(){return $this->pending;}
    private function isSetupComplete($settings){return true;}
    private function getSetupRequiredMessage(){return 'inert setup incomplete';}
    private function ensurePluginDataDir(){}
    private function setOwnership($path){}
    private function getAnnouncementCooldownState(){return ['remaining'=>0];}
    private function getAllPjsipExtensions(){return [['extension'=>'1000']];}
    private function getSipNotifyTargets(){return [['extension'=>'1000']];}
    private function getDesktopClients($settings){return [['username'=>'gabe','client_id'=>'fixture-desktop','enabled'=>'1']];}
    private function getAvailableTones(){return [];}
    private function getAvailablePiperVoices(){return [];}
    private function getAnnouncementGroups(){return $this->settings['announcement_groups']??[];}
    private function announcementSender($options=[]){return 'Fixture operator';}
    private function currentIncidentAnnouncementContext(){return $this->incidentContext;}
    public static function validateIncidentContext($value): array{return \FreePBX\modules\Slsmassnotifyserver::validateIncidentContext($value);}
    private function snapshotAnnouncementRequest($request,$settings){return $request;}
    private function deliverResolvedAnnouncement($request){$this->resolved[]=$request;return ['success'=>true,'captured'=>$request];}
    private function persistAppliedSettings($settings){$this->settings=$settings;file_put_contents($this->directory.'/active.config',json_encode($settings));}
    private function persistPendingSettings($settings){$this->pending=$settings;file_put_contents($this->directory.'/pending.config',json_encode($settings));}
    private function persistScheduledAnnouncements($schedules,$expected){$this->settings['scheduled_announcements']=$this->normalizeScheduledAnnouncements($schedules);}
    private function getPbxDateTimeZone(){return new DateTimeZone('UTC');}
    private function acquireSettingsLock(){return null;}
    private function releaseSettingsLock($handle){}
    private function loadSettingsFile($path){return $this->settings;}
    private function normalizeSettings($settings){return $settings;}
    private function loadScheduleExecutionStore($failClosed){return $this->store;}
    private function writeScheduleExecutionStore($store){$this->store=$store;}
    private function updateStatusData($status){$this->status=$status;}
    public function normalized($rows){return $this->normalizeScheduledAnnouncements($rows);}
    public function warnings(){return $this->getScheduledAnnouncementHealthWarnings($this->settings);}
    public function validate($rows){return $this->validateScheduledAnnouncementRecurrences($rows,$this->settings['announcement_groups']??[]);}
FIXTURE;
foreach(['MAX_SCHEDULES','MAX_SCHEDULE_OCCURRENCES','MAX_SCHEDULE_YEARS','SCHEDULE_GRACE_SECONDS','MAX_WEBHOOK_DESTINATIONS']as $name){$fixture.='const '.$name.'='.var_export($reflection->getConstant($name),true).';';}
foreach(['ANNOUNCEMENT_LOCK_FILE'=>'announcement.lock','SCHEDULE_LOCK_FILE'=>'schedule.lock','SETTINGS_JSON'=>'active.config','TONES_DIR'=>'tones']as $name=>$file){$fixture.='const '.$name.'='.var_export($directory.'/'.$file,true).';';}
$fixture.='const PLUGIN_DATA_DIR='.var_export($directory,true).';';
$fixture.='const VISUAL_PUSH_SCRIPT="/bin/true";';
eval($fixture.$methods.'}');
try{
    $a='email_'.str_repeat('a',24);$b='email_'.str_repeat('b',24);
    $email=['enabled'=>'1','recipients'=>[['id'=>$a,'name'=>'Enabled saved recipient','address'=>'one@example.com','enabled'=>'1'],['id'=>$b,'name'=>'Disabled saved recipient','address'=>'two@example.com','enabled'=>'0']]];
    $defaults=$call('getDefaultSettings');$check(($defaults['announcement_email']??null)===['enabled'=>'0','recipients'=>[]],'Fresh email defaults must be disabled and empty');
    $defaults['desktop_clients']=[];
    $base=$defaults;$base['announcement_email']=$email;$base['mail_from_name']='Fixture';$base['mail_from_domain']='example.com';$base['mail_from_local_part']='notify';
    $base['announcement_groups']=[];$base['scheduled_announcements']=[];$base['opening_tone']='';$base['closing_tone']='';
    $m=new EmailMainFacadeFixture();$m->call=$call;$m->directory=$directory;$m->settings=$base;
    foreach([[],['email_recipient_ids'=>[]],['email_recipient_ids'=>[$a]]]as $payload){$check($call('validateControlApiAnnouncementPayload',$payload)===[],'Valid/omitted API email list rejected');}
    foreach([null,'one@example.com',['one@example.com'],[$a,$a],array_fill(0,51,$a),[[$a]]]as $invalid){
        foreach([['email_recipient_ids'=>$invalid],['options'=>['email_recipient_ids'=>$invalid]]]as $payload){$check($call('validateControlApiAnnouncementPayload',$payload)!==[],'Malformed API email list accepted');}
    }
    foreach(['emails','email_recipients']as $rawField){foreach([[$rawField=>['one@example.com']],['options'=>[$rawField=>['one@example.com']]]]as $payload){$check($call('validateControlApiAnnouncementPayload',$payload)!==[],'API arbitrary addresses accepted');}}
    $patch=$call('validateAndNormalizeControlConfigPatch',['announcement_email'=>$email]);$check(empty($patch['errors']),'Valid saved-email API config patch rejected');
    $backup=$call('validateNativeBackupConfig',json_encode($base));$check($backup['announcement_email']===$email,'Native import lost saved email IDs/settings');
    foreach([null,['enabled'=>'1','recipients'=>'one@example.com'],['enabled'=>'1','recipients'=>[['id'=>$a,'name'=>'Bad','address'=>"one@example.com\r\nBcc: x@example.com"]]]]as $bad){
        $check(!empty($call('validateAndNormalizeControlConfigPatch',['announcement_email'=>$bad])['errors']),'Malformed email config patch accepted');
        $invalid=$base;$invalid['announcement_email']=$bad;$thrown=false;try{$call('validateNativeBackupConfig',json_encode($invalid));}catch(RuntimeException|DomainException $e){$thrown=true;}$check($thrown,'Invalid email native config imported');
    }
    $send=static function($ids,$options=[])use($m){return $m->sendSipNotifyAnnouncement([],'Complete announcement',true,false,[],array_replace(['audio_mode'=>'none','email_recipient_ids'=>$ids],$options));};
    $result=$send([$a],['_is_test'=>true]);$check($result['success'],'Email-only facade rejected valid saved recipient: '.json_encode($result));
    $request=end($m->resolved);$check($request['email_recipient_ids']===[$a] && $request['email_severity']==='info' && $request['is_test']===true,'Facade lost explicit email ID/severity/test metadata');
    foreach(['information'=>'info','warning'=>'warning','critical'=>'critical']as $severity=>$expected){
        $m->incidentContext=['schema'=>1,'incident_id'=>'inc_'.str_repeat('a',32),'sequence'=>1,'kind'=>'initial','severity'=>$severity,'is_test'=>false];
        $result=$send([$a],['_is_test'=>true]);
        $check($result['success'] && end($m->resolved)['email_severity']===$expected && end($m->resolved)['is_test']===true,'Trusted incident severity/test metadata lost: '.$severity);
    }
    $m->incidentContext=null;
    foreach([false,0,1,'true','1',null]as $notLiteralTrue){
        $result=$send([$a],['_is_test'=>$notLiteralTrue]);
        $check($result['success'] && end($m->resolved)['is_test']===false && end($m->resolved)['email_severity']==='info','Ordinary test metadata must require literal true');
    }
    $result=$m->sendSipNotifyAnnouncement([],'CRITICAL emergency wording is not trusted incident severity',true,false,[],['audio_mode'=>'none','email_recipient_ids'=>[$a]]);
    $check($result['success'] && end($m->resolved)['email_severity']==='info' && end($m->resolved)['is_test']===false,'Ordinary severity/test defaults must not infer incident state from wording');
    foreach([null,[$b],['email_'.str_repeat('c',24)],['one@example.com'],[$a,$a]]as $bad){$before=count($m->resolved);$check(!$send($bad)['success'] && count($m->resolved)===$before,'Invalid/disabled email send entered delivery');}
    $check(!$send([$a],['audio_mode'=>'tts'])['success'],'Email-only announcement enabled audio without audio recipients');
    $legacy=$m->sendSipNotifyAnnouncement(['1000'],'Existing phone-only announcement',true,false,[],['audio_mode'=>'none']);$check($legacy['success'] && (end($m->resolved)['email_recipient_ids']??[])===[],'No-email phone behavior changed');
    $group=$m->saveAnnouncementGroup('','Email group',[],[],[],[$a]);$check($group['success'],'Email-only saved group rejected');
    $savedGroup=end($m->settings['announcement_groups']);$check($savedGroup['email_recipient_ids']===[$a],'Saved group lost email IDs');
    $groupSend=$m->sendSipNotifyAnnouncement([],'Group announcement',true,false,[$savedGroup['id']],['audio_mode'=>'none']);$check($groupSend['success'] && end($m->resolved)['email_recipient_ids']===[$a],'Group email audience not resolved');
    foreach([[$b],['one@example.com'],null]as $bad){$check(!$m->saveAnnouncementGroup('','Invalid email group',[],[],[],$bad)['success'],'Invalid/disabled group email selection saved');}
    $input=['schedule_name'=>'Email schedule','schedule_enabled'=>'1','schedule_message'=>'Scheduled complete message','schedule_occurrences'=>[gmdate('Y-m-d\TH:i',time()+86400)],'schedule_audio_mode'=>'none','schedule_email_recipient_ids'=>[$a]];
    $result=$m->saveScheduledAnnouncement($input);$check($result['success'],'Email-only schedule rejected: '.json_encode($result));
    $schedule=end($m->settings['scheduled_announcements']);$check($schedule['targets']['email_recipient_ids']===[$a],'Saved schedule lost email IDs');
    $check($m->normalized([$schedule])[0]===$schedule && $m->validate([$schedule])===[],'Email schedule normalization/validation failed');
    $check($m->warnings()===[],'Valid email schedule reports missing targets');
    foreach([null,[$b],['one@example.com']]as $bad){$invalid=$input;$invalid['schedule_email_recipient_ids']=$bad;$check(!$m->saveScheduledAnnouncement($invalid)['success'],'Invalid/disabled scheduled email accepted');}
    $m->settings['announcement_email']['recipients'][0]['enabled']='0';$m->settings['scheduled_announcements']=[$schedule];
    $check($m->normalized([$schedule])[0]['targets']['email_recipient_ids']===[$a],'Removed schedule destination silently discarded');
    $check($m->warnings()!==[],'Disabled schedule email not reported in health');
    $schedule['occurrences'][0]['run_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-5);$m->settings['scheduled_announcements']=$m->normalized([$schedule]);
    $before=count($m->resolved);$processed=$m->processScheduledAnnouncements();
    $check($processed['processed']===0 && $processed['attention']===1 && count($m->resolved)===$before,'Stale scheduled email did not fail before delivery');
    $check(array_values($m->store['occurrences'])[0]['state']==='failed','Stale schedule did not keep explicit failure');
    $m->processScheduledAnnouncements();$check(count($m->resolved)===$before,'Failed scheduled email replayed');
    // Location audiences are frozen and cannot acquire a replacement username.
    $m->settings=$base;
    $m->settings['desktop_clients']=[['username'=>'gabe','client_id'=>'fixture-desktop','enabled'=>'1']];
    $locationGroup=['id'=>'grp_'.str_repeat('f',24),'name'=>'Location snapshot','extensions'=>['1000'],'desktop_clients'=>['gabe'],
        'voice_recipient_ids'=>[],'email_recipient_ids'=>[],'sms_recipient_ids'=>[],
        'location_snapshot'=>['schema'=>1,'location_id'=>'loc_'.str_repeat('f',24),'include_descendants'=>true,'path'=>'Fixture site',
            'created_at'=>time(),'directory_revision'=>str_repeat('a',64),'desktop_bindings'=>[['username'=>'gabe','client_id'=>'fixture-desktop']]]];
    $m->settings['announcement_groups']=[$locationGroup];
    $result=$m->sendSipNotifyAnnouncement([],'Location audience',true,false,[$locationGroup['id']],['audio_mode'=>'none']);
    $check($result['success'] && end($m->resolved)['desktops']===['gabe'],'Current location snapshot could not be sent');
    $check(!$m->saveAnnouncementGroup($locationGroup['id'],'Silent replacement',['1000'],['gabe'])['success'],'Ordinary group editor silently detached a location snapshot');
    $before=count($m->resolved);$m->settings['desktop_clients'][0]['client_id']='replacement-desktop';
    $result=$m->sendSipNotifyAnnouncement([],'Must fail before submission',true,false,[$locationGroup['id']],['audio_mode'=>'none']);
    $check(!$result['success'] && ($result['error_code']??'')==='location_desktop_identity_changed' && empty($result['delivery_started']) && count($m->resolved)===$before,
        'Reused desktop username in a location group entered announcement delivery');
    $schedule['targets']['groups']=[$locationGroup['id']];$schedule['targets']['email_recipient_ids']=[];
    $m->store=['occurrences'=>[]];$m->settings['scheduled_announcements']=[$schedule];
    $processed=$m->processScheduledAnnouncements();
    $check($processed['attention']===1 && count($m->resolved)===$before && array_values($m->store['occurrences'])[0]['state']==='failed',
        'Scheduled location audience sent to a replacement identity');
    echo "Main-class announcement email: $count facade/config/API/group/schedule checks passed with private adapters.\n";
}finally{foreach(glob($directory.'/*')?:[]as $p){if(is_file($p))unlink($p);}rmdir($directory);}
