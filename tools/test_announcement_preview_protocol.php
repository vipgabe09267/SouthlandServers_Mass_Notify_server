<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/slsmassnotifyserver/AnnouncementPreview.php';

final class PreviewProtocolFixture { use \FreePBX\modules\SlsAnnouncementPreview; }

function previewProtocolCheck(bool $ok,string $message):void { if(!$ok){throw new RuntimeException($message);} }
function previewProtocolRejects(callable $work,string $message):void { try{$work();}catch(DomainException $expected){previewProtocolCheck($expected->getMessage()!=='',$message);return;}throw new RuntimeException($message); }
function previewProtocolChunk(string $type,string $data):string
{
    return pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
}
function previewProtocolPng(int $width,int $height):string
{
    $row="\0".str_repeat("\0",$width*3);
    return "\x89PNG\r\n\x1a\n"
        .previewProtocolChunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0))
        .previewProtocolChunk('IDAT',gzcompress(str_repeat($row,$height),9))
        .previewProtocolChunk('IEND','');
}

$method=(new ReflectionClass(PreviewProtocolFixture::class))->getMethod('validateRenderedPngResponse');
$method->setAccessible(true);
$valid=previewProtocolPng(480,272);
$response=['success'=>true,'mime'=>'image/png','width'=>480,'height'=>272,'data'=>base64_encode($valid),'text_clipped'=>false];
previewProtocolCheck($method->invoke(null,$response,480,272)===$response,'A valid canonical PNG response was rejected.');
$withPrivateField=$response+['private'=>'renderer-internal'];
previewProtocolCheck($method->invoke(null,$withPrivateField,480,272)===$response,'Renderer-only fields reached the public preview response.');
$cases=[];
$cases[]=array_replace($response,['data'=>'%%%']);
$cases[]=array_replace($response,['data'=>rtrim($response['data'],'=')]);
$cases[]=array_replace($response,['data'=>base64_encode(substr($valid,0,-12))]);
$cases[]=array_replace($response,['data'=>base64_encode($valid.'trailing')]);
$damaged=$valid;$damaged[29]=chr(ord($damaged[29])^1);$cases[]=array_replace($response,['data'=>base64_encode($damaged)]);
$cases[]=array_replace($response,['data'=>base64_encode(previewProtocolPng(481,272))]);
foreach($cases as $case){previewProtocolRejects(static fn()=>$method->invoke(null,$case,480,272),'A malformed or wrong-size PNG response was accepted.');}
$mms=previewProtocolPng(1440,816);$mmsResponse=array_replace($response,['width'=>1440,'height'=>816,'data'=>base64_encode($mms)]);
previewProtocolCheck($method->invoke(null,$mmsResponse,1440,816)===$mmsResponse,'A valid MMS PNG response was rejected.');
$runtime=sys_get_temp_dir().'/sls-preview-protocol-'.bin2hex(random_bytes(8));
previewProtocolCheck(mkdir($runtime,0700)&&chmod($runtime,0700),'Unable to create the private preview-process fixture.');
try{
    $wire=json_encode($response+['private'=>'renderer-internal'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $script="import base64,os,sys\nif os.environ.get('SLS_PREVIEW_SECRET'): raise SystemExit(9)\nsys.stdout.write(base64.b64decode('".base64_encode($wire)."').decode('utf-8'))\n";
    previewProtocolCheck(file_put_contents($runtime.'/sls_notify.py',$script,LOCK_EX)===strlen($script)&&chmod($runtime.'/sls_notify.py',0600),'Unable to create the private preview-process helper.');
    eval('final class PreviewProcessFixture { use \\FreePBX\\modules\\SlsAnnouncementPreview; const RUNTIME_DIR='.var_export($runtime,true).'; }');
    $render=(new ReflectionClass(PreviewProcessFixture::class))->getMethod('renderPhoneImagePreview');$render->setAccessible(true);
    putenv('SLS_PREVIEW_SECRET=must-not-cross');
    $rendered=$render->invoke(new PreviewProcessFixture,'Fixture message','Fixture title','#1f2937',false);
    previewProtocolCheck($rendered===$response,'Preview child inherited a private environment value or exposed an internal field.');
}finally{
    putenv('SLS_PREVIEW_SECRET');
    if(is_file($runtime.'/sls_notify.py')){previewProtocolCheck(unlink($runtime.'/sls_notify.py'),'Unable to remove the preview-process helper.');}
    previewProtocolCheck(rmdir($runtime),'Unable to remove the preview-process fixture.');
}
echo "Announcement preview protocol: strict base64, PNG chunks, CRC, complete IEND and exact phone/MMS dimensions passed.\n";
