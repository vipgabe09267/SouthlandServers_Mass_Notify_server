<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_live_paging.php';
$source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_live_paging.php');
$start = strpos($source, 'class SlsLivePagingNotifyAgi ');
$end = strpos($source, '$agi = new SlsLivePagingNotifyAgi', $start);
if ($start === false || $end === false) { throw new RuntimeException('Missing production visual dispatcher.'); }
eval(substr($source, $start, $end - $start));

class PagingNotifyFixture extends SlsLivePagingNotifyAgi
{
    public array $settings;
    public array $requests = [];
    public int $finishes = 0;
    public bool $ready = true;
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->setOriginEnvironment(['agi_channel'=>'PJSIP/1000-00000001','agi_uniqueid'=>'10.1']);
        $this->setNotificationAdmission(['token'=>str_repeat('a',32)]);
    }
    protected function currentPagingSettings(): array { return $this->settings; }
    protected function pagingConfigHash(): string { return str_repeat('c',64); }
    protected function prepareCallerMenu(array $request): void { $this->requests[]=['menu'=>$request]; }
    protected function startVisualMonitor(array $request, int $duration): bool
    {
        $this->requests[] = ['request'=>$request,'duration'=>$duration];
        return $this->ready;
    }
    protected function finishVisualMonitor(): array
    {
        $this->finishes++;
        return ['status'=>'submitted','attempted_contacts'=>1,'failures'=>0];
    }
}
function visual_check($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

$settings=['announcement_timeout_mode'=>'custom','announcement_timeout_seconds'=>45,'announcement_groups'=>[],
    'live_paging'=>['enabled'=>'1','extension'=>'700','allowed_callers'=>[],
    'groups'=>[['group_id'=>'paging_staff','name'=>'Staff','menu_number'=>1,'require_pin'=>'0',
        'extensions'=>['1000','1001'],'allowed_callers'=>['1000'],'notify_extensions'=>['1000','1002'],
        'text_message'=>'Live staff page: "quoted", $literal and `text`.']]]];
$settings['live_paging']=\SLS\MassNotify\LivePagingConfig::normalize($settings['live_paging']);
$group=array_values(\SLS\MassNotify\LivePagingConfig::resolvedGroups($settings['live_paging'],[]))[0];
$fixture=new PagingNotifyFixture($settings);
visual_check($fixture->notifyGroup($group,'1000',$settings)===['status'=>'monitoring','requested'=>1,'ready_gate'=>true],
    'Monitor startup claimed SIP submission or lost caller exclusion.');
$request=$fixture->requests[0]['request'];
visual_check($request['token']===str_repeat('a',32) && $request['group']==='paging_staff'
    && $request['channel']==='PJSIP/1000-00000001' && $request['uniqueid']==='10.1'
    && $request['caller']==='1000' && $request['external']===false && $request['external_number']===''
    && $request['config_sha256']===str_repeat('c',64) && preg_match('/^[a-f0-9]{32}$/D',$request['owner']),
    'The monitor was not bound to the admitted origin and configuration.');
visual_check(!str_contains(json_encode($request),'quoted') && !str_contains(json_encode($request),'pin_hash'),
    'Popup text or PIN hashes entered the monitor protocol.');
visual_check($fixture->requests[0]['duration']===$settings['live_paging']['max_duration_seconds'],'Monitor lifetime is not bounded by the page.');
visual_check($fixture->finishGroupNotification()===['status'=>'submitted','attempted_contacts'=>1,'failures'=>0,'requested'=>1],
    'Page end did not await the monitor cleanup result.');
visual_check($fixture->finishGroupNotification()===['status'=>'not_requested','requested'=>0] && $fixture->finishes===1,
    'Page-end cleanup was replayed.');

$failed=new PagingNotifyFixture($settings);$failed->ready=false;
visual_check($failed->notifyGroup($group,'1000',$settings)['status']==='failed','Failed monitor readiness was reported as monitoring.');
$changed=new PagingNotifyFixture($settings);$changed->settings['live_paging']['groups'][0]['text_message']='Changed';
visual_check($changed->notifyGroup($group,'1000',$settings)['status']==='configuration_changed' && !$changed->requests,
    'Changed configuration started a monitor.');
$unbound=new PagingNotifyFixture($settings);$unbound->setNotificationAdmission([]);
visual_check($unbound->notifyGroup($group,'1000',$settings)['status']==='failed' && !$unbound->requests,
    'Unadmitted paging started a visual worker.');
$bad=$group;$bad['notify_extensions']=['1002;evil'];
visual_check((new PagingNotifyFixture($settings))->notifyGroup($bad,'1000',$settings)['status']==='configuration_changed',
    'Tampered recipient scope was accepted.');
$empty=$group;$empty['notify_extensions']=['1000'];
visual_check((new PagingNotifyFixture($settings))->notifyGroup($empty,'1000',$settings)===['status'=>'not_requested','requested'=>0],
    'An empty recipient list expanded to a broadcast.');

$audioOnly=$settings;$audioOnly['live_paging']['groups'][0]['notify_extensions']=[];
$audioGroup=\SLS\MassNotify\LivePagingConfig::resolvedGroups($audioOnly['live_paging'],[])[1];
$audioFixture=new PagingNotifyFixture($audioOnly);
$audioFixture->setNotificationAdmission(['token'=>str_repeat('a',32),'live_dial'=>'PJSIP/1001/sip:test@192.0.2.1',
    'paging_deadline'=>time()+300,'voicemail_recording'=>false]);
visual_check($audioFixture->notifyGroup($audioGroup,'1000',$audioOnly)===['status'=>'monitoring','requested'=>0,'ready_gate'=>true],
    'Audio-only paging skipped its caller readiness gate.');
visual_check($audioFixture->requests[0]['menu']['ready_cue']===true
    && $audioFixture->requests[1]['request']['expires_at']<=time()+300,'Caller cue lost its fixed deadline.');
visual_check($audioFixture->finishGroupNotification()['status']==='submitted' && $audioFixture->finishes===1,
    'Zero visual targets skipped monitor shutdown.');

class CallerCueFixture extends PagingNotifyFixture
{
    public array $variables=[];
    public array $commands=[];
    public array $verdicts=[];
    public int $checks=0;
    public bool $failTone=false;
    public function variable(string $expression): string { return (string)($this->variables[$expression]??''); }
    protected function command(string $command,int $timeout=5): string {
        $this->commands[]=$command;
        if ($this->failTone && $command==='EXEC PlayTones "880"') { return '200 result=-1'; }
        if (preg_match('/^SET VARIABLE (SLS_PAGING_CUE_[A-Z]+) "([^"]*)"$/D',$command,$matches)) {
            $this->variables['${'.$matches[1].'}']=$matches[2];
        }
        return '200 result=0';
    }
    protected function checkCallerCue(string $request,string $mode='--cue-stdin'): array { $this->checks++; return array_shift($this->verdicts)??[]; }
}
$cueEnvironment=['agi_channel'=>'PJSIP/1000-00000001','agi_uniqueid'=>'10.1'];
$cueRequest=array_replace($request,['ready_cue'=>true,'voicemail_ready'=>false,'expires_at'=>time()+300]);
$newCue=static function () use($settings,$cueRequest): CallerCueFixture {
    $fixture=new CallerCueFixture($settings);
    $fixture->variables=['${SLS_PAGING_CUE_REQUEST}'=>json_encode($cueRequest),'${SLS_PAGING_CUE_DONE}'=>'0',
        '${SLS_PAGING_CUE_ARM}'=>$cueRequest['owner'],'${PLAYBACKSTATUS}'=>'SUCCESS'];
    $fixture->verdicts=[['ok'=>true,'decision'=>'ready','remaining_seconds'=>290],['ok'=>true,'decision'=>'ready','remaining_seconds'=>289]];
    return $fixture;
};
$cue=$newCue();
visual_check($cue->callerReadyCue($cueEnvironment)==='ready' && $cue->checks===2,'Caller cue skipped its post-playback policy check.');
$beep=array_search('EXEC PlayTones "880"',$cue->commands,true);
$opened=array_search('SET VARIABLE MUTEAUDIO(in) "off"',$cue->commands,true);
visual_check(is_int($beep) && is_int($opened) && $beep<$opened,'Caller microphone opened before cue playback completed.');
visual_check($cue->callerReadyCue($cueEnvironment)==='ignored' && $cue->checks===2,'Duplicate caller cue replayed.');
foreach (['unarmed','wrong_origin','playback_failed','revoked_during_beep','unsettled'] as $case) {
    $cue=$newCue();$environment=$cueEnvironment;
    if ($case==='unarmed') $cue->variables['${SLS_PAGING_CUE_ARM}']='';
    if ($case==='wrong_origin') $environment['agi_uniqueid']='other';
    if ($case==='playback_failed') $cue->failTone=true;
    if ($case==='revoked_during_beep') $cue->verdicts[1]=[];
    if ($case==='unsettled') $cue->verdicts[0]['decision']='pending';
    $result=$cue->callerReadyCue($environment);
    visual_check(!in_array('SET VARIABLE MUTEAUDIO(in) "off"',$cue->commands,true),'Unsafe caller cue opened input: '.$case);
    visual_check($result=== (in_array($case,['unarmed','wrong_origin'],true)?'ignored':'unavailable'),'Unexpected failed cue outcome: '.$case);
}
foreach ([false,true] as $revoke) {
    $cue=new CallerCueFixture($audioOnly);
    $cue->variables=['${PLAYBACKSTATUS}'=>'SUCCESS'];
    $cue->setNotificationAdmission(['token'=>str_repeat('a',32),'live_dial'=>'','paging_deadline'=>time()+300,'voicemail_recording'=>true]);
    visual_check($cue->notifyGroup($audioGroup,'1000',$audioOnly)['status']==='not_requested',
        'Voicemail-only readiness started an intercom monitor.');
    $cue->verdicts=[['ok'=>true,'decision'=>'recording','remaining_seconds'=>290],
        $revoke?[]:['ok'=>true,'decision'=>'recording','remaining_seconds'=>289]];
    visual_check($cue->recordingReadyCue(time()+300)===!$revoke && $cue->checks===2,
        'Voicemail-only cue did not revalidate after playback.');
    visual_check(in_array('SET VARIABLE MUTEAUDIO(in) "off"',$cue->commands,true)===!$revoke,
        'Revoked voicemail-only proof opened the microphone.');
    visual_check(!$cue->recordingReadyCue(time()+300),'Voicemail-only cue replayed.');
}

$external=$settings;$external['live_paging']['external_access']='1';
$external['live_paging']['groups'][0]+= ['allow_external'=>'1','external_callers'=>['+15125550123']];
$external['live_paging']['groups'][0]['pin_hash']=password_hash('0123',PASSWORD_DEFAULT);
$externalGroup=\SLS\MassNotify\LivePagingConfig::resolvedGroups($external['live_paging'],[])[1];
$externalFixture=new PagingNotifyFixture($external);
$externalFixture->setExternalIngress(true,'+15125550123');
visual_check($externalFixture->notifyGroup($externalGroup,'700',$external)['status']==='monitoring',
    'Approved external caller lost its monitor.');
visual_check($externalFixture->requests[0]['request']['external_number']==='+15125550123'
    && $externalFixture->requests[0]['request']['external']===true,'External caller binding was lost.');
$before=count($externalFixture->requests);$externalFixture->setExternalIngress(true,'+15125550124');
visual_check($externalFixture->notifyGroup($externalGroup,'700',$external)['status']==='configuration_changed'
    && count($externalFixture->requests)===$before,'Unlisted external caller started a visual monitor.');
echo "Live paging visual startup: admitted origin/configuration binding, caller/PIN isolation, no eager SIP send, bounded monitoring and one cleanup result passed.\n";
