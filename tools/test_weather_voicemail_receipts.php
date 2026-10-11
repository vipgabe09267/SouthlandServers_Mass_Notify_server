<?php
declare(strict_types=1);
require dirname(__DIR__).'/slsmassnotifyserver/WeatherDeliveryReceipts.php';
use SLS\MassNotify\WeatherDeliveryReceipts;

$directory = sys_get_temp_dir().'/sls-weather-vm-receipts-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory.'/phone-admission.json';
$checks = 0;
function vmCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
$sourceToken = str_repeat('a', 32);
$childToken = str_repeat('b', 32);
$scope = 'nws_main';
$identifier = 'alert-fixture';
$correlation = 'vm-'.hash('sha256', $scope.'|'.$identifier);
$sound = 'SLS_Mass_Notifications_Plugin/voicemail/'.$sourceToken;
$event = ['type'=>'nws', 'alert_id'=>$identifier, 'zone'=>'TXZ163'];
$source = ['service'=>'nws', 'status'=>'closed', 'correlation'=>$correlation,
    'generation'=>str_repeat('c', 32), 'boot_id'=>'fixture-boot', 'targets'=>['1000'=>['dials'=>['PJSIP/1000/sip:private@192.0.2.1']]],
    'vm_fallback'=>['schema'=>1, 'scope'=>$scope, 'sound'=>$sound,
        'weather_context'=>['source_validity'=>['version'=>1, 'provider'=>'nws', 'group_id'=>$scope,
            'provider_event_id'=>$identifier, 'zone'=>'TXZ163']],
        'targets'=>['1000'=>['dials'=>['PJSIP/1000/sip:private@192.0.2.1'], 'mailbox'=>'1000@default', 'claimed_token'=>$childToken]]]];
$child = ['purpose'=>'voicemail', 'service'=>'nws', 'status'=>'closed', 'correlation'=>$correlation,
    'generation'=>str_repeat('c', 32), 'boot_id'=>'fixture-boot', 'targets'=>['1000'=>['dials'=>['VM/1000@default']]],
    'channels'=>['1.1'=>['recipient'=>'1000', 'kind'=>'origin', 'channel'=>'Local/1000@sls-alert-voicemail-abcd;2']],
    'voicemail'=>['source_token'=>$sourceToken, 'mailbox'=>'1000@default', 'sound'=>$sound,
        'playback_completed'=>true, 'result'=>['status'=>'SUCCESS', 'recorded_at'=>1000]]];
$state = ['schema'=>1, 'batches'=>[$sourceToken=>$source, $childToken=>$child]];
$settings = ['nws_zones'=>[['id'=>$scope, 'name'=>'Main site']]];
$write = static function(array $value) use ($directory, $path): void {
    $temporary = $directory.'/.phone-write-'.bin2hex(random_bytes(8));
    file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR));
    chmod($temporary, 0600);
    rename($temporary, $path);
    clearstatcache(true, $path);
};
$project = static fn(array $requested = []): array => WeatherDeliveryReceipts::forEvent($directory, $requested ?: $event, $settings);
try {
    $write($state);
    $out = $project();
    vmCheck(count($out['rows']) === 1 && !$out['warnings'], 'Native voicemail receipt is missing.');
    vmCheck($out['rows'][0]['status'] === 'saved_in_FreePBX_voicemail', 'Completed native voicemail was not labeled saved.');
    vmCheck($out['rows'][0]['channel'] === 'FreePBX voicemail' && $out['rows'][0]['group'] === 'Main site · Extension 1000', 'Mailbox recipient/group label is incorrect.');
    vmCheck($out['rows'][0]['recorded_at'] === '1970-01-01T00:16:40+00:00', 'Native recorded timestamp was not preserved.');
    foreach (['private@', '192.0.2.', $sourceToken, $childToken, '@default', 'PJSIP/', 'Local/'] as $private) {
        vmCheck(!str_contains(json_encode($out), $private), 'Receipt exposed a protected channel, mailbox context or ticket.');
    }
    vmCheck(!str_contains(json_encode($out), 'heard') && !str_contains(json_encode($out), 'read_by'), 'Voicemail evidence claimed a human response.');
    vmCheck(!$project(array_replace($event, ['alert_id'=>'other']))['rows'], 'A different announcement received this voicemail receipt.');
    vmCheck(!$project(array_replace($event, ['zone'=>'TXZ999']))['rows'], 'A different weather zone received this voicemail receipt.');

    foreach (['playback', 'parent_gap', 'child_gap', 'origin_gap', 'source_token', 'sound', 'mailbox', 'generation'] as $change) {
        $changed = $state;
        if ($change === 'playback') { $changed['batches'][$childToken]['voicemail']['playback_completed'] = false; }
        elseif ($change === 'parent_gap') { $changed['batches'][$sourceToken]['outcome_uncertain'] = true; }
        elseif ($change === 'child_gap') { $changed['batches'][$childToken]['outcome_uncertain'] = true; }
        elseif ($change === 'origin_gap') { $changed['batches'][$childToken]['channels']['1.1']['outcome_uncertain'] = true; }
        elseif ($change === 'source_token') { $changed['batches'][$childToken]['voicemail']['source_token'] = str_repeat('d', 32); }
        elseif ($change === 'sound') { $changed['batches'][$childToken]['voicemail']['sound'] = 'SLS_Mass_Notifications_Plugin/voicemail/'.str_repeat('d', 32); }
        elseif ($change === 'mailbox') { $changed['batches'][$childToken]['voicemail']['mailbox'] = '1000@changed'; }
        else { $changed['batches'][$childToken]['generation'] = str_repeat('d', 32); }
        $write($changed);
        vmCheck($project()['rows'][0]['status'] === 'unconfirmed', 'Mismatched or incomplete native evidence was labeled saved: '.$change);
    }
    foreach (['FAILED', 'USEREXIT'] as $status) {
        $changed = $state;
        $changed['batches'][$childToken]['voicemail']['result']['status'] = $status;
        $write($changed);
        vmCheck($project()['rows'][0]['status'] === 'failed', 'Failed native attempt was labeled saved.');
    }
    $changed = $state;
    $changed['batches'][$childToken]['status'] = 'reserved';
    $changed['batches'][$childToken]['channels'] = [];
    $changed['batches'][$childToken]['voicemail']['result'] = null;
    $write($changed);
    vmCheck($project()['rows'][0]['status'] === 'queued', 'Unstarted one-use native attempt was not labeled queued.');
    $changed['batches'][$childToken]['status'] = 'closed';
    $write($changed);
    vmCheck($project()['rows'][0]['status'] === 'unconfirmed', 'Closed attempt without native results invented success.');
    $changed = $state;
    $changed['batches'][$sourceToken]['vm_fallback']['targets']['1000']['claimed_token'] = null;
    $write($changed);
    vmCheck(!$project()['rows'], 'An unclaimed or answered recipient was labeled queued for voicemail.');
    $changed['batches'][$sourceToken]['voicemail_failures'] = ['1000'=>'phone_voicemail_mailbox_duration'];
    $write($changed);
    vmCheck($project()['rows'][0]['status'] === 'failed' && $project()['rows'][0]['error'] === 'phone_voicemail_mailbox_duration'
        && $project()['rows'][0]['queue_state'] === 'not_submitted', 'A terminal mailbox refusal was hidden or labeled queued.');
    $changed['batches'][$sourceToken]['voicemail_failures'] = ['1000'=>'https://secret.invalid/password'];
    $write($changed);
    vmCheck(!$project()['rows'] && !str_contains(json_encode($project()), 'secret'), 'A private or arbitrary refusal string escaped the projection.');

    $lightning = $state;
    $lightningId = 'xweather-lightning-fixture';
    foreach ([$sourceToken, $childToken] as $token) {
        $lightning['batches'][$token]['service'] = 'lightning';
        $lightning['batches'][$token]['correlation'] = 'vm-'.hash('sha256', 'area1|'.$lightningId);
    }
    $lightning['batches'][$sourceToken]['vm_fallback']['scope'] = 'area1';
    $lightning['batches'][$sourceToken]['vm_fallback']['weather_context']['source_validity'] = ['version'=>1, 'provider'=>'xweather', 'group_id'=>'area1'];
    $write($lightning);
    $out = WeatherDeliveryReceipts::forEvent($directory, ['type'=>'xweather', 'event_id'=>$lightningId], ['xweather'=>['groups'=>[['id'=>'area1', 'name'=>'Outdoor area']]]]);
    vmCheck($out['rows'][0]['status'] === 'saved_in_FreePBX_voicemail' && str_starts_with($out['rows'][0]['group'], 'Outdoor area'), 'Lightning native receipt could not be matched by exact event/group correlation.');
    vmCheck(!$project()['rows'], 'NWS displayed a Lightning receipt.');

    $write($state);
    $start = microtime(true);
    for ($i = 0; $i < 100; ++$i) { vmCheck($project()['rows'][0]['status'] === 'saved_in_FreePBX_voicemail', 'Request snapshot lost its native receipt.'); }
    vmCheck(microtime(true)-$start < 1.0, 'Repeated protected receipt views were not request-cached.');
    foreach ([0644, 0660, 0700] as $mode) {
        chmod($path, $mode); clearstatcache(true, $path);
        $out = $project();
        vmCheck(!$out['rows'] && (bool)$out['warnings'], 'Unsafe phone history permissions were accepted.');
        chmod($path, 0600);
    }
    link($path, $directory.'/linked');
    vmCheck(!$project()['rows'] && (bool)$project()['warnings'], 'Hard-linked phone history was accepted.');
    unlink($directory.'/linked');
    unlink($path);
    symlink('/etc/passwd', $path);
    vmCheck(!$project()['rows'] && (bool)$project()['warnings'], 'Symlink phone history was accepted.');
    unlink($path);
    posix_mkfifo($path, 0600);
    $start = microtime(true);
    vmCheck((bool)$project()['warnings'] && microtime(true)-$start < 0.5, 'FIFO phone history blocked the UI.');
    unlink($path);
    $handle = fopen($path, 'wb'); ftruncate($handle, 16777217); fclose($handle); chmod($path, 0600);
    $start = microtime(true);
    vmCheck((bool)$project()['warnings'] && microtime(true)-$start < 0.5, 'Oversized phone history was not rejected before reading.');
    unlink($path);
    $many = [];
    for ($i = 0; $i < 2001; ++$i) { $many['d'.str_pad(dechex($i), 31, '0', STR_PAD_LEFT)] = []; }
    $write(['schema'=>1, 'batches'=>$many]);
    vmCheck(!$project()['rows'] && (bool)$project()['warnings'], 'Oversized batch inventory was accepted.');
    echo $checks." weather voicemail receipt checks passed.\n";
} finally {
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    foreach (glob($directory.'/.*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}
