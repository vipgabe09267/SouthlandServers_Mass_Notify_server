<?php
namespace FreePBX\modules;

/** Authenticated operator previews. This path never queues a delivery. */
trait SlsAnnouncementPreview
{
    public function previewAnnouncement(array $input): array
    {
        $lock = null; $activity = null; $audioFile = '';
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
            if ($message === '' || mb_strlen($message) > 500 || mb_strlen($title) > 80) {
                throw new \DomainException(_('Enter a message of 1–500 characters and a title of at most 80 characters before previewing.'));
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
            $data = $this->readNativeBackupFile($audioFile, 10 * 1024 * 1024, _('The generated speech preview could not be read safely. Check the audio workspace and free space.'));
            if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') { throw new \RuntimeException('Invalid preview audio'); }
            return ['success'=>true, 'mime'=>'audio/wav', 'data'=>base64_encode($data),
                'speech_text'=>$this->buildAnnouncementTtsText($message, $settings['tts_max_seconds'] ?? 30, $settings['announcement_pronunciation'] ?? [], (string)$voice), 'voice'=>basename($voice)];
        } catch (\DomainException $error) {
            return ['success'=>false, 'message'=>$error->getMessage()];
        } catch (\Throwable $error) {
            return ['success'=>false, 'message'=>_('The preview could not be prepared. Check the selected voice, audio workspace permissions, temporary disk space and installation diagnostics.')];
        } finally {
            if ($audioFile !== '') { @unlink($audioFile); }
            \SlsAnnouncementJobStore::unlock($lock);
            $this->releaseNativeBackupFileLock($activity);
        }
    }

    private function renderPhoneImagePreview(string $message, string $title, string $color, bool $mms=false): array
    {
        $payload = json_encode(['message'=>$message, 'title'=>$title, 'color'=>$color], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $process = proc_open(['/usr/bin/timeout', '--kill-after=2', '15', '/usr/bin/python3', '-I', self::RUNTIME_DIR . '/sls_notify.py', $mms?'--preview-mms-image':'--preview-image'],
            [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \DomainException(_('The phone image renderer could not start. Run installation diagnostics.')); }
        $written = fwrite($pipes[0], $payload); fclose($pipes[0]);
        $raw = stream_get_contents($pipes[1], 1400001); fclose($pipes[1]);
        if ($written !== strlen($payload) || !is_string($raw) || strlen($raw) > 1400000) { proc_terminate($process); }
        $exit = proc_close($process);
        $result = is_string($raw) ? json_decode($raw, true) : null;
        if ($exit !== 0 || !is_array($result) || ($result['success'] ?? false) !== true || ($result['mime'] ?? '') !== 'image/png'
            || ($result['width'] ?? null) !== ($mms?1440:480) || ($result['height'] ?? null) !== ($mms?816:272)
            || !is_string($result['data'] ?? null) || !is_bool($result['text_clipped'] ?? null)) {
            throw new \DomainException(_('The phone image could not be rendered within 15 seconds. Check ImageMagick, PBX load and temporary disk space.'));
        }
        return $result;
    }
}
