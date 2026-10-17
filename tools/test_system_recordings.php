<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/SystemRecordings.php';
use SLS\MassNotify\SystemRecordings as Recordings;
$root=sys_get_temp_dir().'/sls-recordings-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach (['sounds/custom/uploads','sounds/es/custom','tones'] as $part) { mkdir($root.'/'.$part,0700,true); }
function recordingCheck($value,$message) { if(!$value)throw new RuntimeException($message); }
function importRecording(string $selection, string $directory, string $tones): string {
    return Recordings::import($selection,$directory,$tones,static function(int $amount):void {
        recordingCheck($amount>20*1024*1024 && $amount<=40*1024*1024,'Recording workspace must cover its source copy and bounded decoded output');
    });
}
try {
    $source=$root.'/sounds/custom/uploads/Fire drill.wav';
    exec('/usr/bin/sox -n -r 16000 -c 1 '.escapeshellarg($source).' synth 0.1 sine 440 2>/dev/null',$unused,$code);
    recordingCheck($code===0,'Fixture recording failed');
    copy($source,$root.'/sounds/es/custom/Spanish alert.wav');
    symlink($source,$root.'/sounds/custom/linked.wav');
    $list=Recordings::catalogue($root.'/sounds',['custom/uploads/Fire drill'=>'Fire drill']);
    recordingCheck(count($list)===2 && in_array('Fire drill',array_column($list,'label'),true),'Nested uploads, language and display name missing');
    $normalized=Recordings::catalogue($root.'/sounds',['custom/uploads/Fire drill'=>['invalid'], 'es/custom/Spanish alert'=>str_repeat('x',400)]);
    recordingCheck($normalized[0]['label']==='custom/uploads/Fire drill' || $normalized[1]['label']==='custom/uploads/Fire drill','Non-string recording label was not replaced safely');
    recordingCheck(max(array_map('strlen',array_column($normalized,'label')))<=240,'Recording label exceeded its UI bound');
    $cache = $root.'/recordings-cache.json';
    $page = Recordings::cataloguePage($root.'/sounds', [], '', 0, 1, $cache);
    recordingCheck($page['total']===2 && $page['has_more'] && $page['next_offset']===1 && $page['scan_complete'], 'Recording pagination metadata incorrect');
    $next = Recordings::cataloguePage($root.'/sounds', [], '', $page['next_offset'], 1, $cache);
    recordingCheck(!$next['has_more'] && $next['items'][0]['value']!==$page['items'][0]['value'], 'Recording second page repeated a result');
    $search = Recordings::cataloguePage($root.'/sounds', [], 'Spanish', 0, 50, $cache);
    recordingCheck($search['total']===1 && str_contains($search['items'][0]['value'], 'Spanish'), 'Cached recording search lost a matching item');
    $absent = Recordings::cataloguePage($root.'/missing');
    recordingCheck(!$absent['scan_complete'] && $absent['warning']!=='', 'Unavailable catalogue silently returned an empty list');
    file_put_contents($cache, '{broken');
    recordingCheck(Recordings::cataloguePage($root.'/sounds', [], '', 0, 50, $cache)['total']===2, 'Corrupt catalogue cache was trusted');
    $tone=importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones');
    $wave=file_get_contents($root.'/tones/'.$tone.'.wav');
    recordingCheck(strlen($wave)>44 && unpack('V',substr($wave,24,4))[1]===8000,'WAV import lost samples or retained the source rate');
    $hash=hash_file('sha256',$root.'/tones/'.$tone.'.wav');
    recordingCheck($tone===Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones',static function():void{throw new RuntimeException('Cached audio must not reserve new rendering space');}), 'Cached recording unnecessarily requested a new media reservation');
    recordingCheck($tone===importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones'),'Repeated selection changed the frozen tone');
    exec('/usr/bin/sox -n -r 16000 -c 1 '.escapeshellarg($source).' synth 0.2 sine 660 2>/dev/null',$unused,$code);
    $blocked=false;try{Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones',static function():void{throw new RuntimeException('fixture quota exhausted');});}catch(RuntimeException $error){$blocked=$error->getMessage()==='fixture quota exhausted';}
    recordingCheck($blocked,'New recording conversion bypassed generated-media admission');
    $new=importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones');
    recordingCheck($new!==$tone && hash_file('sha256',$root.'/tones/'.$tone.'.wav')===$hash,'New upload changed a queued recording');
    $formats=['wav49'=>'-t wav -e gsm-full-rate','gsm'=>'-t gsm','ulaw'=>'-t ul','alaw'=>'-t al',
        'sln'=>'-t raw -e signed-integer -b 16 -L','sln16'=>'-t raw -e signed-integer -b 16 -L'];
    foreach($formats as $format=>$arguments){
        $fixture=$root.'/sounds/custom/Codec.'.$format;$rate=$format==='sln16'?16000:8000;
        exec('/usr/bin/sox '.escapeshellarg($source).' -r '.$rate.' -c 1 '.$arguments.' '.escapeshellarg($fixture).' 2>/dev/null',$unused,$code);
        recordingCheck($code===0,'Cannot create '.$format.' recording fixture');
        $imported=importRecording('system:custom/Codec.'.$format,$root.'/sounds',$root.'/tones');
        $bytes=file_get_contents($root.'/tones/'.$imported.'.wav');
        recordingCheck(substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WAVE'&&strlen($bytes)>44,'Valid '.$format.' recording lost its samples or did not import');
    }
    // Repeating a valid GSM frame yields >20MiB PCM from <3MiB input. Verify
    // the real decoder cannot write beyond its kernel limit before validation.
    $frame = substr(file_get_contents($root.'/sounds/custom/Codec.gsm'), 0, 33);
    recordingCheck(strlen($frame) === 33, 'Missing GSM expansion fixture frame');
    $expandedSource = $root.'/sounds/custom/Expanded.gsm';
    file_put_contents($expandedSource, str_repeat($frame, (int)ceil(22*1024*1024/320)));
    $boundedOutput = $root.'/bounded-output.wav';
    $convert = new ReflectionMethod(Recordings::class, 'convertBounded');
    $code = $convert->invoke(null, ['/usr/bin/sox','-t','gsm',$expandedSource,'-r','8000','-c','1','-b','16',$boundedOutput]);
    clearstatcache(true, $boundedOutput);
    recordingCheck($code !== 0 && filesize($boundedOutput) === 20*1024*1024, 'Compressed recording exceeded its enforced output bound or returned success (code='.$code.', bytes='.filesize($boundedOutput).')');
    $reserved = 0; $rejected = false;
    try { Recordings::import('system:custom/Expanded.gsm', $root.'/sounds', $root.'/tones', static function(int $amount) use (&$reserved): void { $reserved = $amount; }); }
    catch (InvalidArgumentException $error) { $rejected = str_contains($error->getMessage(), 'exceeds the 20 MiB audio limit'); }
    recordingCheck($rejected && $reserved === filesize($expandedSource)+20*1024*1024, 'Compressed import did not reserve both copies or explain its byte limit');
    recordingCheck((glob($root.'/tones/.import-*') ?: []) === [], 'Rejected compressed import retained source or partial decoded audio');
    unlink($boundedOutput);
    if (is_executable('/usr/bin/ffmpeg')) {
        $g722 = $root.'/sounds/custom/Expanded.g722';
        exec('/usr/bin/ffmpeg -nostdin -v error -i '.escapeshellarg($source).' -f g722 '.escapeshellarg($g722).' 2>/dev/null', $unused, $code);
        recordingCheck($code===0 && filesize($g722)>0, 'Cannot create the G.722 expansion fixture');
        $packet=file_get_contents($g722);
        file_put_contents($g722, str_repeat($packet, (int)ceil(11*1024*1024/strlen($packet))));
        $code=$convert->invoke(null, ['/usr/bin/ffmpeg','-nostdin','-v','error','-f','g722','-i',$g722,'-ac','1','-ar','8000','-c:a','pcm_s16le',$boundedOutput]);
        clearstatcache(true,$boundedOutput);
        recordingCheck($code===65 && filesize($boundedOutput)===20*1024*1024, 'G.722 decoder accepted partial audio or exceeded its kernel output bound');
        $rejected=false;
        try { importRecording('system:custom/Expanded.g722',$root.'/sounds',$root.'/tones'); }
        catch (InvalidArgumentException $error) { $rejected=str_contains($error->getMessage(),'exceeds the 20 MiB audio limit'); }
        recordingCheck($rejected && (glob($root.'/tones/.import-*') ?: [])===[], 'Oversized G.722 import did not explain its limit or discarded workspace incorrectly');
        unlink($boundedOutput);
    }
    $cached=$root.'/tones/'.$new.'.wav';$alias=$root.'/linked-cache.wav';
    link($cached,$alias);$linkedHash=hash_file('sha256',$alias);
    recordingCheck(importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new,'Unsafe cache changed recording identity');
    clearstatcache(true,$cached);
    recordingCheck(lstat($cached)['nlink']===1&&hash_file('sha256',$alias)===$linkedHash,'Multiply linked cache was reused or its other link changed');
    $valid=file_get_contents($cached);$inode=lstat($cached)['ino'];
    recordingCheck(importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new && lstat($cached)['ino']===$inode,'Valid recording cache was rebuilt unnecessarily');
    foreach ([str_repeat('invalid',20),substr($valid,0,strlen($valid)-10)] as $damaged) {
        file_put_contents($cached,$damaged);
        recordingCheck(importRecording('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new,'Corrupt recording repair changed its frozen identity');
        $repaired=file_get_contents($cached);
        recordingCheck(strlen($repaired)===strlen($valid) && substr($repaired,0,4)==='RIFF'
            && unpack('V',substr($repaired,24,4))[1]===8000,'Corrupt or truncated imported recording cache was reused');
    }
    foreach (['system:custom/../outside.wav','system:custom/linked.wav','system:custom/missing.wav','https://example.test/tone.wav','system:custom/a.wav;touch pwn'] as $selection) {
        $rejected=false;try{importRecording($selection,$root.'/sounds',$root.'/tones');}catch(Throwable $error){$rejected=true;}
        recordingCheck($rejected,'Unsafe or missing recording accepted');
    }
    $pipe=$root.'/sounds/custom/pipe.wav'; posix_mkfifo($pipe,0600);
    $probe='require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/SystemRecordings.php',true).'; try { \\SLS\\MassNotify\\SystemRecordings::import("system:custom/pipe.wav",'
        .var_export($root.'/sounds',true).','.var_export($root.'/tones',true).',static function(int $bytes):void{}); exit(2); } catch (InvalidArgumentException $error) { exit(0); }';
    $process=proc_open(['/usr/bin/timeout','--kill-after=1','3',PHP_BINARY,'-r',$probe],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    recordingCheck(is_resource($process)&&proc_close($process)===0,'System Recording import blocked on a FIFO source');
    $readonly=$root.'/sounds/custom/readonly.wav';copy($source,$readonly);chmod($readonly,0444);
    recordingCheck(importRecording('system:custom/readonly.wav',$root.'/sounds',$root.'/tones')!=='','Read-only valid System Recording was rejected');
    echo "PASS: System Recording search, normal codecs, kernel-bounded compressed expansion, workspace admission, immutable audio and unsafe source/cache rejection.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }rmdir($root);
}
