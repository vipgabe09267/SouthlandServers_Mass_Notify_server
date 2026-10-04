#!/usr/bin/php
<?php
declare(strict_types=1);
// Private stdin handoff from the admitted phone AGI. No HTTP route or caller IDs.
if (PHP_SAPI!=='cli' || count($argv)!==1) { exit(64); }
umask(0027); ini_set('display_errors','0');
try {
    $directory='/var/lib/asterisk/SLS_Mass_Notifications_Plugin'; $meta=@lstat($directory);
    if (!function_exists('posix_geteuid') || posix_geteuid()===0 || !$meta || ($meta['mode']&0170000)!==0040000
        || $meta['uid']!==posix_geteuid() || ($meta['mode']&0022) || realpath($directory)!==$directory) { throw new RuntimeException('runtime_identity_invalid'); }
    $raw=stream_get_contents(STDIN,8193); if (!is_string($raw) || strlen($raw)>8192) { throw new InvalidArgumentException('response_too_large'); }
    $input=json_decode($raw,true,10,JSON_THROW_ON_ERROR); if (!is_array($input) || array_is_list($input)) { throw new InvalidArgumentException('response_invalid'); }
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true]; require '/etc/freepbx.conf';
    $result=\FreePBX::Create()->Slsmassnotifyserver->receiveIncidentVoiceResponse($input);
    echo json_encode(['ok'=>true,'state'=>$result['state']],JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) { echo '{"ok":false,"error":"incident_response_unconfirmed"}'; exit(1); }
