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
    if (!class_exists('SlsAtomicJson', false)) { require_once __DIR__.'/sls_atomic_json.php'; }
    $input=\SlsAtomicJson::decodeObject($raw,10);
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true]; require '/etc/freepbx.conf';
    $result=\FreePBX::Create()->Slsmassnotifyserver->receiveIncidentVoiceResponse($input);
    if (!is_array($result) || !is_string($result['state']??null)
        || !in_array($result['state'],['disabled','ignored','duplicate','recorded'],true)) {
        throw new RuntimeException('incident_response_state_invalid');
    }
    echo json_encode(['ok'=>true,'state'=>$result['state']],JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) { echo '{"ok":false,"error":"incident_response_unconfirmed"}'; exit(1); }
