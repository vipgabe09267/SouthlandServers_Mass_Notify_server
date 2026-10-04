<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

$directory = sys_get_temp_dir() . '/sls-speech-fixture-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
$methods = '';
foreach (['generateAnnouncementTtsFile', 'buildAnnouncementTtsText', 'normalizeTtsMaxSeconds', 'normalizeTtsVolume', 'volumePercentToScalar', 'readNativeBackupFile', 'announcementAudioFileMetadata', 'isValidAnnouncementAudioCache', 'combineAudioParts', 'createQuietAnnouncementTone', 'normalizeToneName', 'getAnnouncementSequenceDuration'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
// Use the real methods with a disposable output directory, never PBX state.
eval('class SpeechFixture { use \\FreePBX\\modules\\SlsAnnouncementPreview; const TTS_DIR = ' . var_export($directory, true) . '; const PLUGIN_DATA_DIR = self::TTS_DIR;
const SOUNDS_DIR = self::TTS_DIR; const TONES_DIR = self::TTS_DIR; const ASTERISK_SOUND_PREFIX = "fixture_audio";
const PIPER_BIN = "unused"; const PIPER_VOICE = "unused"; public $settings = []; public $invalidVoices = [];
private function getActiveSettings() { return $this->settings; }
public function getAvailablePiperVoices() { return [["path"=>"2"],["path"=>"3"],["path"=>"30.25"]]; }
private function isValidPiperVoiceFile($voice) { return !in_array($voice, $this->invalidVoices, true); }
private function acquireAnnouncementActivityLock($exclusive, $timeout) { return null; }
private function releaseNativeBackupFileLock($handle) {}
' . $methods . '}');
$fixture = new SpeechFixture();
$generate = new ReflectionMethod($fixture, 'generateAnnouncementTtsFile');
$build = new ReflectionMethod($fixture, 'buildAnnouncementTtsText');
$piper = $directory . '/piper-fixture';
file_put_contents($piper, <<<'PY'
#!/usr/bin/python3
import argparse
from pathlib import Path
import sys
import wave
p = argparse.ArgumentParser()
p.add_argument('--model'); p.add_argument('--volume'); p.add_argument('--input-file'); p.add_argument('--output-file')
a = p.parse_args()
if a.model == 'failure': sys.exit(7)
assert Path(a.input_file).read_text().strip().endswith('finalword'), 'speech input was truncated'
with wave.open(a.output_file, 'wb') as out:
    out.setnchannels(1); out.setsampwidth(2); out.setframerate(8000)
    out.writeframes(b'\0\0' * int(float(a.model) * 8000))
PY
);
chmod($piper, 0700);
$message = str_repeat('go ', 80) . 'finalword';
$settings = ['piper_bin' => $piper, 'announcement_piper_voice' => '2', 'tts_max_seconds' => 30, 'announcement_tts_volume' => 25];
try {
    foreach (['2', '2.json'] as $invalid) {
        $fixture->invalidVoices = [$invalid];
        try {
            $generate->invoke($fixture, $message, $settings);
            throw new RuntimeException('Speech synthesis accepted an unverified model or configuration.');
        } catch (DomainException $error) {
            if (strpos($error->getMessage(), 'checksum verification') === false || glob($directory . '/*.wav')) {
                throw new RuntimeException('Checksum failure was concealed or produced speech audio.');
            }
        }
    }
    $fixture->invalidVoices = [];
    if ($build->invoke($fixture, $message, 1) !== 'Announcement. ' . $message) { throw new RuntimeException('Speech text was shortened before synthesis.'); }
    foreach (['es_ES-davefx-medium.onnx'=>'Aviso. ', 'fr_FR-siwis-medium.onnx'=>'Annonce. ', 'de_DE-thorsten-low.onnx'=>'Durchsage. ', 'pt_BR-faber-medium.onnx'=>'Comunicado. '] as $model=>$prefix) {
        if ($build->invoke($fixture, 'Texto finalword', 1, [], '/voices/'.$model) !== $prefix.'Texto finalword') { throw new RuntimeException('Native announcement prefix or complete message was lost.'); }
    }
    foreach (['2', '30'] as $seconds) {
        $settings['announcement_piper_voice'] = $seconds;
        $base = $generate->invoke($fixture, $message, $settings);
        if (!is_file($directory . '/' . $base . '.wav')) { throw new RuntimeException('Speech within limit was rejected.'); }
        unlink($directory . '/' . $base . '.wav');
    }
    foreach (['30.25' => '30.25 seconds', '0' => 'empty duration', 'failure' => 'exit code 7'] as $model => $expected) {
        $settings['announcement_piper_voice'] = $model;
        try {
            $generate->invoke($fixture, $message, $settings);
            throw new RuntimeException('Invalid speech was accepted: ' . $model);
        } catch (DomainException $error) {
            if (strpos($error->getMessage(), $expected) === false) { throw new RuntimeException('Speech rejection did not explain the cause: ' . $error->getMessage()); }
        }
        if (glob($directory . '/*.wav')) { throw new RuntimeException('Rejected speech remained available for playback.'); }
    }
    $fixture->settings = array_replace($settings, ['announcement_piper_voice'=>'2', 'outbound_voice'=>['piper_voice'=>'3']]);
    $input = ['message'=>'Keep the complete finalword', 'kind'=>'internal_speech'];
    foreach (['internal_speech'=>'2', 'external_speech'=>'3'] as $kind=>$voice) {
        $preview = $fixture->previewAnnouncement(array_replace($input, ['kind'=>$kind]));
        if (!$preview['success'] || $preview['voice'] !== $voice || $preview['speech_text'] !== 'Announcement. Keep the complete finalword'
            || substr(base64_decode($preview['data']), 0, 4) !== 'RIFF' || glob($directory . '/*.wav')) {
            throw new RuntimeException('Preview used the wrong voice/text, failed to return audio or left a generated file: '.json_encode(array_diff_key($preview, ['data'=>true])));
        }
    }
    $store = new SlsAnnouncementJobStore($directory); $held = $store->previewLock();
    if ($fixture->previewAnnouncement($input)['success']) { throw new RuntimeException('Concurrent preview bypassed the render lock.'); }
    $sendLock = $store->admissionLock();
    if (!$sendLock) { throw new RuntimeException('Preview blocked announcement admission.'); }
    SlsAnnouncementJobStore::unlock($sendLock); SlsAnnouncementJobStore::unlock($held);
    foreach (['message'=>['bad'], 'kind'=>'send', 'color'=>'file:///etc/passwd', 'title'=>str_repeat('x',81)] as $key=>$value) {
        if ($fixture->previewAnnouncement(array_replace($input, [$key=>$value]))['success']) { throw new RuntimeException('Invalid preview field accepted: '.$key); }
    }
    $fixture->settings['announcement_piper_voice'] = '30.25';
    $rejected = $fixture->previewAnnouncement($input);
    if ($rejected['success'] || strpos($rejected['message'], '30.25 seconds') === false || glob($directory . '/*.wav')) { throw new RuntimeException('Preview concealed a duration error.'); }
    $settings['announcement_piper_voice']='2';$base=$generate->invoke($fixture,$message,$settings);
    rename($directory.'/'.$base.'.wav',$directory.'/fixture-tone.wav');
    $quiet=(new ReflectionMethod($fixture,'createQuietAnnouncementTone'))->invoke($fixture,'fixture-tone',25);
    if ($quiet==='') { throw new RuntimeException('Bounded tone conversion failed.'); }
    $combine=new ReflectionMethod($fixture,'combineAudioParts');
    $parts=[$directory.'/fixture-tone.wav',$directory.'/'.$quiet.'.wav'];
    $combined=$combine->invoke($fixture,'roundtrip',$parts,'fixture');
    $measure=new ReflectionMethod($fixture,'getAnnouncementSequenceDuration');
    $duration=$measure->invoke($fixture,'fixture_audio/'.$combined);
    if ($combined==='' || abs($duration-5.0)>.01) { throw new RuntimeException('Bounded concatenation lost a complete tone or its one-second opening silence.'); }
    $inode=fileinode($directory.'/'.$combined.'.wav');
    if ($combine->invoke($fixture,'roundtrip',$parts,'fixture')!==$combined || fileinode($directory.'/'.$combined.'.wav')!==$inode) {
        throw new RuntimeException('Audio concatenation unnecessarily regenerated its current cache.');
    }
    foreach (['', 'not a WAV', substr(file_get_contents($directory.'/'.$combined.'.wav'),0,48)] as $invalid) {
        file_put_contents($directory.'/'.$combined.'.wav',$invalid); touch($directory.'/'.$combined.'.wav',time()+10);
        if ($combine->invoke($fixture,'roundtrip',$parts,'fixture')!==$combined
            || substr(file_get_contents($directory.'/'.$combined.'.wav'),0,4)!=='RIFF'
            || abs($measure->invoke($fixture,'fixture_audio/'.$combined)-5.0)>.01) {
            throw new RuntimeException('A corrupt or truncated sequence cache was accepted without repair.');
        }
    }
    file_put_contents($directory.'/'.$quiet.'.wav',''); touch($directory.'/'.$quiet.'.wav',time()+10);
    if ((new ReflectionMethod($fixture,'createQuietAnnouncementTone'))->invoke($fixture,'fixture-tone',25)!==$quiet
        || filesize($directory.'/'.$quiet.'.wav')<44) { throw new RuntimeException('An empty tone cache was accepted.'); }
    $outside=$directory.'/preserved.wav'; copy($directory.'/fixture-tone.wav',$outside); $original=file_get_contents($outside);
    unlink($directory.'/'.$combined.'.wav'); symlink($outside,$directory.'/'.$combined.'.wav');
    try { $combine->invoke($fixture,'roundtrip',$parts,'fixture'); throw new RuntimeException('A linked cache was accepted.'); }
    catch (DomainException $error) {}
    if (file_get_contents($outside)!==$original || !is_link($directory.'/'.$combined.'.wav')) { throw new RuntimeException('Unsafe cache evidence was changed.'); }
    unlink($directory.'/'.$combined.'.wav');
    $input=$directory.'/linked.wav'; symlink($outside,$input);
    try { $combine->invoke($fixture,'linked',[$input],'fixture'); throw new RuntimeException('A linked audio input was accepted.'); }
    catch (DomainException $error) {}
    unlink($input); link($outside,$input);
    try { $combine->invoke($fixture,'shared',[$input],'fixture'); throw new RuntimeException('A shared-inode audio input was accepted.'); }
    catch (DomainException $error) {}
    unlink($input); posix_mkfifo($input,0600); $began=microtime(true);
    try { $combine->invoke($fixture,'pipe',[$input],'fixture'); throw new RuntimeException('A FIFO audio input was accepted.'); }
    catch (DomainException $error) {}
    if (microtime(true)-$began>.5) { throw new RuntimeException('Audio validation blocked on a FIFO.'); }
    echo "Complete speech, measured duration rejection, bounded tone/sequence conversion, preview voice isolation, cleanup, independent locks and actionable synthesis failures passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}
