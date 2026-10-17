<?php
declare(strict_types=1);
namespace FreePBX\modules;

/** Authenticated operator previews. This path never queues a delivery. */
trait SlsAnnouncementPreview
{
    public function previewAnnouncement(array $input): array
    {
        $lock = null; $activity = null; $audioFile = ''; $audioIdentity = null;
        try {
            $message = $input['message'] ?? null;
            $title = $input['title'] ?? 'Announcement';
            $color = $input['color'] ?? '#1f2937';
            $kind = $input['kind'] ?? '';
            if (!is_string($message) || !preg_match('//u', $message) || !is_string($title) || !preg_match('//u', $title)
                || !is_string($color) || !preg_match('/^#[0-9A-Fa-f]{6}$/D', $color)
                || !in_array($kind, ['image', 'internal_speech', 'external_speech', 'sms'], true)) {
                throw new \DomainException(_('The preview request contains invalid text, color or preview type. Reload the page and retry.'));
            }
            $message = preg_replace('/[^\P{C}\r\n\t]/u', '', trim($message));
            $title = preg_replace('/\p{C}/u', '', trim($title));
            if (!is_string($message) || !is_string($title) || $message === '' || $title === ''
                || mb_strlen($message) > 500 || mb_strlen($title) > 80) {
                throw new \DomainException(_('Enter a message of 1–500 characters and a title of 1–80 characters before previewing.'));
            }
            if ($kind === 'sms') { return $this->previewSmsMessage(['title'=>$title, 'message'=>$message]); }
            $activity = $this->acquireAnnouncementActivityLock(false, 1);
            $lock = (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->previewLock();
            if (!$lock) { throw new \DomainException(_('Another preview is being prepared. Wait for it to finish, then try again.')); }
            if ($kind === 'image') { return $this->renderPhoneImagePreview($message, $title, $color); }
            $settings = $this->getActiveSettings();
            $settings['_tts_preview'] = true;
            if ($kind === 'external_speech' && ($settings['outbound_voice']['piper_voice'] ?? '') !== '') {
                $settings['announcement_piper_voice'] = $settings['outbound_voice']['piper_voice'];
            }
            $voice = $settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE;
            if (!in_array($voice, array_column($this->getAvailablePiperVoices(), 'path'), true) || !$this->isValidPiperVoiceFile($voice)) {
                throw new \DomainException(_('The selected speech voice is unavailable. Review General Settings or repair the voice installation.'));
            }
            $base = $this->generateAnnouncementTtsFile($message, $settings);
            if (!preg_match('/^announcement_tts_[0-9]{14}_[a-f0-9]{32}$/D', $base)) { throw new \RuntimeException('Unexpected preview output'); }
            $audioFile = self::TTS_DIR . '/' . $base . '.wav';
            clearstatcache(true, $audioFile); $audioIdentity = @lstat($audioFile);
            $data = $this->readNativeBackupFile($audioFile, 10 * 1024 * 1024, _('The generated speech preview could not be read safely. Check the audio workspace and free space.'));
            clearstatcache(true, $audioFile); $afterAudio = @lstat($audioFile);
            if (!is_array($audioIdentity) || !is_array($afterAudio)
                || $audioIdentity['dev'] !== $afterAudio['dev'] || $audioIdentity['ino'] !== $afterAudio['ino']) {
                throw new \RuntimeException('Preview audio changed after generation');
            }
            if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') { throw new \RuntimeException('Invalid preview audio'); }
            return ['success'=>true, 'mime'=>'audio/wav', 'data'=>base64_encode($data),
                'speech_text'=>$this->buildAnnouncementTtsText($message, $settings['tts_max_seconds'] ?? 30, $settings['announcement_pronunciation'] ?? [], (string)$voice), 'voice'=>basename($voice)];
        } catch (\DomainException $error) {
            return ['success'=>false, 'message'=>$error->getMessage()];
        } catch (\Throwable $error) {
            return ['success'=>false, 'message'=>_('The preview could not be prepared. Check the selected voice, audio workspace permissions, temporary disk space and installation diagnostics.')];
        } finally {
            if ($audioFile !== '') { $this->unlinkNamedFileIfIdentity($audioFile, $audioIdentity); }
            \SlsAnnouncementJobStore::unlock($lock);
            $this->releaseNativeBackupFileLock($activity);
        }
    }

    private function renderPhoneImagePreview(string $message, string $title, string $color, bool $mms=false): array
    {
        $payload = json_encode(['message'=>$message, 'title'=>$title, 'color'=>$color], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $environment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
        $process = proc_open(['/usr/bin/timeout', '--kill-after=2', '15', '/usr/bin/python3', '-I', self::RUNTIME_DIR . '/sls_notify.py', $mms?'--preview-mms-image':'--preview-image'],
            [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['file', '/dev/null', 'w']], $pipes, null, $environment, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \DomainException(_('The phone image renderer could not start. Run installation diagnostics.')); }
        $written=0;
        while($written<strlen($payload)){$count=fwrite($pipes[0],substr($payload,$written));if(!is_int($count)||$count<1){break;}$written+=$count;}
        $inputClosed=fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1], 1400001); fclose($pipes[1]);
        $complete=$written===strlen($payload)&&$inputClosed&&is_string($raw)&&strlen($raw)<=1400000;
        if (!$complete) { proc_terminate($process); }
        $exit = proc_close($process);
        try{$result=$complete?json_decode($raw,true,8,JSON_THROW_ON_ERROR):null;}catch(\JsonException $error){$result=null;}
        if ($exit !== 0 || !is_array($result) || array_is_list($result) || ($result['success'] ?? false) !== true || ($result['mime'] ?? '') !== 'image/png'
            || ($result['width'] ?? null) !== ($mms?1440:480) || ($result['height'] ?? null) !== ($mms?816:272)
            || !is_string($result['data'] ?? null) || !is_bool($result['text_clipped'] ?? null)) {
            throw new \DomainException(_('The phone image could not be rendered within 15 seconds. Check ImageMagick, PBX load and temporary disk space.'));
        }
        return self::validateRenderedPngResponse($result,$mms?1440:480,$mms?816:272);
    }

    private static function validateRenderedPngResponse(array $result, int $width, int $height): array
    {
        $encoded=$result['data']??null;
        $bytes=is_string($encoded)?base64_decode($encoded,true):false;
        if (!is_string($bytes) || $bytes==='' || strlen($bytes)>1048576 || base64_encode($bytes)!==$encoded
            || strlen($bytes)<33 || substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n"
            || unpack('Nlength',substr($bytes,8,4))['length']!==13 || substr($bytes,12,4)!=='IHDR') {
            throw new \DomainException(_('The image renderer returned invalid PNG data. Run installation diagnostics.'));
        }
        $offset=8;$chunks=0;$sawHeader=false;$sawData=false;$sawEnd=false;$total=strlen($bytes);
        while($offset<$total&&++$chunks<=256){
            if($total-$offset<12){break;}$length=unpack('Nvalue',substr($bytes,$offset,4))['value'];$type=substr($bytes,$offset+4,4);
            if($length>$total-$offset-12||!preg_match('/^[A-Za-z]{4}$/D',$type)){break;}
            $data=substr($bytes,$offset+8,$length);$crc=substr($bytes,$offset+8+$length,4);
            if(!hash_equals(hash('crc32b',$type.$data,true),$crc)){break;}
            if($chunks===1&&$type==='IHDR'&&$length===13){$sawHeader=true;}
            elseif($type==='IHDR'){break;}
            if($type==='IDAT'){$sawData=true;}
            if($type==='IEND'){$sawEnd=$length===0&&$offset+12===$total;$offset=$total;break;}
            $offset+=12+$length;
        }
        if(!$sawHeader||!$sawData||!$sawEnd||$offset!==$total){
            throw new \DomainException(_('The image renderer returned an incomplete PNG. Run installation diagnostics.'));
        }
        $dimensions=unpack('Nwidth/Nheight',substr($bytes,16,8));
        $info=@getimagesizefromstring($bytes);
        if (($dimensions['width']??0)!==$width || ($dimensions['height']??0)!==$height
            || !is_array($info) || ($info[0]??0)!==$width || ($info[1]??0)!==$height || ($info['mime']??'')!=='image/png') {
            throw new \DomainException(_('The image renderer returned the wrong PNG dimensions. Run installation diagnostics.'));
        }
        return ['success'=>true,'mime'=>'image/png','width'=>$width,'height'=>$height,
            'data'=>$encoded,'text_clipped'=>(bool)($result['text_clipped']??false)];
    }
}
