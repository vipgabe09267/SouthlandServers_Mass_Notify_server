#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit(1); }
ini_set('display_errors','0');
try {
    if ($argc!==1) { throw new RuntimeException('Unexpected Weather channel arguments.'); }
    $raw=stream_get_contents(STDIN,262145);
    if (strlen($raw)>262144) { throw new RuntimeException('Weather channel request is too large.'); }
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    require '/etc/freepbx.conf';
    echo json_encode(\FreePBX::Slsmassnotifyserver()->queueWeatherChannels($input),JSON_THROW_ON_ERROR),"\n";
} catch (InvalidArgumentException|DomainException $error) {
    echo json_encode(['success'=>false,'status'=>'failed','message'=>$error->getMessage()]),"\n"; exit(1);
} catch (Throwable $error) {
    echo json_encode(['success'=>false,'status'=>'failed','message'=>'Weather SMS/external voice could not be queued. Review source validity, recipient consent, provider and trunk settings, and the channel job log.']),"\n";
    exit(1);
}
