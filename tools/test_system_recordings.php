<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/SystemRecordings.php';
use SLS\MassNotify\SystemRecordings as Recordings;
$root=sys_get_temp_dir().'/sls-recordings-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach (['sounds/custom/uploads','sounds/es/custom','tones'] as $part) { mkdir($root.'/'.$part,0700,true); }
function recordingCheck($value,$message) { if(!$value)throw new RuntimeException($message); }
try {
    $source=$root.'/sounds/custom/uploads/Fire drill.wav';
    exec('/usr/bin/sox -n -r 16000 -c 1 '.escapeshellarg($source).' synth 0.1 sine 440 2>/dev/null',$unused,$code);
    recordingCheck($code===0,'Fixture recording failed');
    copy($source,$root.'/sounds/es/custom/Spanish alert.wav');
    symlink($source,$root.'/sounds/custom/linked.wav');
    $list=Recordings::catalogue($root.'/sounds',['custom/uploads/Fire drill'=>'Fire drill']);
    recordingCheck(count($list)===2 && in_array('Fire drill',array_column($list,'label'),true),'Nested uploads, language and display name missing');
    $tone=Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones');
    $wave=file_get_contents($root.'/tones/'.$tone.'.wav');
    recordingCheck(strlen($wave)>44 && unpack('V',substr($wave,24,4))[1]===8000,'WAV import lost samples or retained the source rate');
    $hash=hash_file('sha256',$root.'/tones/'.$tone.'.wav');
    recordingCheck($tone===Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones'),'Repeated selection changed the frozen tone');
    exec('/usr/bin/sox -n -r 16000 -c 1 '.escapeshellarg($source).' synth 0.2 sine 660 2>/dev/null',$unused,$code);
    $new=Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones');
    recordingCheck($new!==$tone && hash_file('sha256',$root.'/tones/'.$tone.'.wav')===$hash,'New upload changed a queued recording');
    $formats=['wav49'=>'-t wav -e gsm-full-rate','gsm'=>'-t gsm','ulaw'=>'-t ul','alaw'=>'-t al',
        'sln'=>'-t raw -e signed-integer -b 16 -L','sln16'=>'-t raw -e signed-integer -b 16 -L'];
    foreach($formats as $format=>$arguments){
        $fixture=$root.'/sounds/custom/Codec.'.$format;$rate=$format==='sln16'?16000:8000;
        exec('/usr/bin/sox '.escapeshellarg($source).' -r '.$rate.' -c 1 '.$arguments.' '.escapeshellarg($fixture).' 2>/dev/null',$unused,$code);
        recordingCheck($code===0,'Cannot create '.$format.' recording fixture');
        $imported=Recordings::import('system:custom/Codec.'.$format,$root.'/sounds',$root.'/tones');
        $bytes=file_get_contents($root.'/tones/'.$imported.'.wav');
        recordingCheck(substr($bytes,0,4)==='RIFF'&&substr($bytes,8,4)==='WAVE'&&strlen($bytes)>44,'Valid '.$format.' recording lost its samples or did not import');
    }
    $cached=$root.'/tones/'.$new.'.wav';$alias=$root.'/linked-cache.wav';
    link($cached,$alias);$linkedHash=hash_file('sha256',$alias);
    recordingCheck(Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new,'Unsafe cache changed recording identity');
    clearstatcache(true,$cached);
    recordingCheck(lstat($cached)['nlink']===1&&hash_file('sha256',$alias)===$linkedHash,'Multiply linked cache was reused or its other link changed');
    $valid=file_get_contents($cached);$inode=lstat($cached)['ino'];
    recordingCheck(Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new && lstat($cached)['ino']===$inode,'Valid recording cache was rebuilt unnecessarily');
    foreach ([str_repeat('invalid',20),substr($valid,0,strlen($valid)-10)] as $damaged) {
        file_put_contents($cached,$damaged);
        recordingCheck(Recordings::import('system:custom/uploads/Fire drill.wav',$root.'/sounds',$root.'/tones')===$new,'Corrupt recording repair changed its frozen identity');
        $repaired=file_get_contents($cached);
        recordingCheck(strlen($repaired)===strlen($valid) && substr($repaired,0,4)==='RIFF'
            && unpack('V',substr($repaired,24,4))[1]===8000,'Corrupt or truncated imported recording cache was reused');
    }
    foreach (['system:custom/../outside.wav','system:custom/linked.wav','system:custom/missing.wav','https://example.test/tone.wav','system:custom/a.wav;touch pwn'] as $selection) {
        $rejected=false;try{Recordings::import($selection,$root.'/sounds',$root.'/tones');}catch(Throwable $error){$rejected=true;}
        recordingCheck($rejected,'Unsafe or missing recording accepted');
    }
    $pipe=$root.'/sounds/custom/pipe.wav'; posix_mkfifo($pipe,0600);
    $probe='require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/SystemRecordings.php',true).'; try { \\SLS\\MassNotify\\SystemRecordings::import("system:custom/pipe.wav",'
        .var_export($root.'/sounds',true).','.var_export($root.'/tones',true).'); exit(2); } catch (Throwable $error) { exit(0); }';
    $process=proc_open(['/usr/bin/timeout','--kill-after=1','3',PHP_BINARY,'-r',$probe],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    recordingCheck(is_resource($process)&&proc_close($process)===0,'System Recording import blocked on a FIFO source');
    $readonly=$root.'/sounds/custom/readonly.wav';copy($source,$readonly);chmod($readonly,0444);
    recordingCheck(Recordings::import('system:custom/readonly.wav',$root.'/sounds',$root.'/tones')!=='','Read-only valid System Recording was rejected');
    echo "PASS: nested/language System Recordings, WAV/WAV49/GSM/mu-law/A-law/SLN conversions, immutable queued audio and unsafe source/cache rejection.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }rmdir($root);
}
