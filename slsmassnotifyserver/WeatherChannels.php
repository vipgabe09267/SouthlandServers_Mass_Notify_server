<?php
declare(strict_types=1);
namespace FreePBX\modules;

trait SlsWeatherChannels
{
    protected function weatherChannelAuthorization(array $context): array
    {
        $pipes=[];
        $process=proc_open(['/usr/bin/timeout','10','/usr/bin/python3','-I',self::RUNTIME_DIR.'/sls_weather_channels.py','authorize'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Weather channel authorization could not start.'); }
        try {
            $body=json_encode($context,JSON_THROW_ON_ERROR);
            if (strlen($body)>262144 || fwrite($pipes[0],$body)!==strlen($body)) { throw new \RuntimeException('Weather channel authorization request could not be written.'); }
            fclose($pipes[0]);$output=stream_get_contents($pipes[1],131073);fclose($pipes[1]);
            $code=proc_close($process);$process=null;
            $result=json_decode($output,true,12,JSON_THROW_ON_ERROR);
            if ($code!==0 || strlen($output)>131072 || !is_array($result) || !in_array($result['status']??'', ['eligible','deferred','cancelled'],true)) { throw new \RuntimeException('Weather channel source or recipient authorization is unavailable.'); }
            return $result;
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process);proc_close($process); }
        }
    }

    /** A protected local worker may queue these channels; there is no web action. */
    public function queueWeatherChannels(array $input): array
    {
        if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==fileowner(self::PLUGIN_DATA_DIR)) { throw new \DomainException('Weather channel submission requires the PBX runtime account.'); }
        if (array_diff(array_keys($input),['key','context','title','body']) || !is_string($input['key']??null) || !preg_match('/^[a-f0-9]{64}$/D',$input['key'])) { throw new \InvalidArgumentException('Weather delivery identity is invalid.'); }
        $id='job_'.substr(hash('sha256','weather-channels|'.($input['context']['source_validity']['group_id']??'').'|'.$input['key']),0,32);
        $activity=$this->acquireAnnouncementActivityLock(false,10);$store=new \SlsAnnouncementJobStore($this->announcementJobDirectory());$lock=$store->lock($id);$start=false;
        if (!$lock) { $this->releaseNativeBackupFileLock($activity);return ['success'=>false,'status'=>'deferred','message'=>'Weather channel job is busy.']; }
        try {
            $job=$store->read($id);
            if ($job!==null) { $failed=in_array($job['state']??'', ['failed','expired'],true); return ['success'=>!$failed,'status'=>$failed?'failed':'queued','job_id'=>$id,'message'=>$failed?($job['message']??'Weather channel job failed; see delivery details.'):'']; }
            $context=$input['context']??[];$allowed=$this->weatherChannelAuthorization($context);
            if ($allowed['status']!=='eligible') { return ['success'=>false,'status'=>$allowed['status'],'message'=>$allowed['reason']??'Weather source is unavailable.']; }
            if (!$allowed['voice_recipient_ids'] && !$allowed['sms_recipient_ids']) { return ['success'=>true,'status'=>'cancelled','message'=>'Original Weather channel recipients were removed or disabled.']; }
            $message=$input['body']??'';
            if (!is_string($message) || $message==='' || strlen($message)>3000) { throw new \InvalidArgumentException('The Weather message must contain 1–3,000 bytes for SMS/external voice. The message was rejected; no text or speech was truncated.'); }
            $settings=$this->getActiveSettings();$voice=(bool)$allowed['voice_recipient_ids'];
            $options=['title'=>$input['title']??'Weather alert','audio_mode'=>$voice?'tts':'none',
                'voice_recipient_ids'=>$allowed['voice_recipient_ids'],'sms_recipient_ids'=>$allowed['sms_recipient_ids'],
                'sender'=>'Weather Alerts','trigger_source'=>'Weather Alerts','is_test'=>false,
                'piper_voice'=>$settings['nws_piper_voice'],'tts_volume'=>$settings['nws_tts_volume']];
            $resolved=$this->resolveAnnouncementRequest([],$message,false,$voice,[],$options,$settings);
            if (empty($resolved['success'])) { return ['success'=>false,'status'=>'failed','message'=>$resolved['message']??'Weather channel validation failed.']; }
            $request=$this->snapshotAnnouncementRequest($resolved['request'],$settings);
            $request['weather_context']=$context;$request['delivery_id']='weather-channels-'.$input['key'];$request['delivery_timestamp']=gmdate('c');
            $request['sms_expires_at']=$context['deadline_at'];
            $job=['id'=>$id,'state'=>'queued','created_at'=>gmdate('c'),'request'=>$request,'receipts'=>[],
                'message'=>'Weather SMS and external voice queued.'];
            $store->write($job);$start=true;
        } finally { \SlsAnnouncementJobStore::unlock($lock);$this->releaseNativeBackupFileLock($activity); }
        if ($start && $this->startAnnouncementWorker($id)===false) { return ['success'=>false,'status'=>'deferred','job_id'=>$id,'message'=>'Weather channel worker could not start. The saved job will be checked by maintenance.']; }
        return ['success'=>true,'status'=>'queued','job_id'=>$id];
    }
}
