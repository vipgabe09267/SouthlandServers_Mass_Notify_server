<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$root = sys_get_temp_dir() . '/sls-phone-producers-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/outgoing'); mkdir($root . '/tmp');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName()); $methods = '';
foreach (['queueAnnouncementAudioCalls', 'requestPhoneAdmission', 'getAudioPageHoldSeconds'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
eval('class PhoneProducerFixture { use \\FreePBX\\modules\\SlsApiPermissionGuards;
    const RUNTIME_DIR = ' . var_export($root, true) . '; const ASTERISK_OUTGOING_SPOOL = ' . var_export($root . '/outgoing', true) . '; const ASTERISK_SPOOL_TMP = ' . var_export($root . '/tmp', true) . ';
    public $lastAudioQueueResults = []; public $lastOutboundQueueResults = []; public $lastPhoneAdmission = [];
    function getActiveSettings(){ return json_decode(file_get_contents(self::RUNTIME_DIR . "/settings.json"), true); }
    ' . $methods . '}');
file_put_contents($root . '/settings.json', '{}');
file_put_contents($root . '/sls_audio_queue.py', 'raise SystemExit(0)');
file_put_contents($root . '/sls_phone_admission.py', <<<'PY'
import json, sys, time
from pathlib import Path
root = Path(__file__).parent
(root / 'request.json').write_text(sys.stdin.read())
if (root / 'expire-during-admission').exists():
    latest = json.loads((root / 'request.json').read_text())['latest_start']
    time.sleep(max(0, latest - time.time()) + 0.02)
reply = json.loads((root / 'reply.json').read_text())
if (root / 'revoke-during-admission').exists():
    settings = json.loads((root / 'settings.json').read_text())
    settings['control_api']['credentials'][0]['revoked_at'] = '2026-09-22T00:00:00+00:00'
    (root / 'settings.json').write_text(json.dumps(settings))
print(json.dumps(reply))
raise SystemExit(0 if reply.get('ok') else 1)
PY
);
function phone_producer_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$fixture = new PhoneProducerFixture();
$queue = new ReflectionMethod($fixture, 'queueAnnouncementAudioCalls');
$voice = ['id' => 'voice_' . str_repeat('b', 24), 'number' => '+15551234567', 'route_mode' => 'pbx_routes', 'trunk_id' => '', 'caller_id' => ''];
$invoke = static function ($reply, $context = []) use ($root, $fixture, $queue, $voice) {
    foreach (glob($root . '/outgoing/*') as $path) { unlink($path); }
    file_put_contents($root . '/reply.json', json_encode($reply));
    return $queue->invoke($fixture, ['1000'], 'SLS_Mass_Notifications_Plugin/tts/fixture', 12, 'normal', [$voice], 'announcement-fixture', $context);
};
try {
    $failed = ['ok' => false, 'failure_code' => 'phone_audience_exceeds_limit', 'detail' => 'Fixture capacity exhausted'];
    phone_producer_check($invoke($failed) === 0 && !glob($root . '/outgoing/*'), 'Admission rejection still submitted a call');
    phone_producer_check($fixture->lastPhoneAdmission['detail'] === $failed['detail'], 'Admission failure lost its actionable explanation');
    $request = json_decode(file_get_contents($root . '/request.json'), true);
    phone_producer_check($request['internal'] === ['1000'] && $request['outbound'] === [$voice] && $request['correlation'] === 'announcement-fixture', 'Mixed audience was not admitted atomically');
    $reply = ['ok' => true, 'token' => str_repeat('a', 32), 'targets' => ['1000'], 'outbound_targets' => [$voice['id']], 'unavailable' => []];
    phone_producer_check($invoke($reply, ['schedule_deadline_at' => time()-1]) === 0 && !glob($root.'/outgoing/*'), 'Expired schedule submitted audio');
    file_put_contents($root.'/expire-during-admission', 'fixture');
    phone_producer_check($invoke($reply, ['schedule_deadline_at' => time()+1]) === 0 && !glob($root.'/outgoing/*'), 'Admission wait extended the schedule window');
    phone_producer_check($fixture->lastPhoneAdmission['failure_code'] === 'schedule_deadline_expired', 'Expired audio lost its distinct outcome');
    unlink($root.'/expire-during-admission');
    phone_producer_check($invoke($reply) === 2, 'Admitted mixed audience was not queued');
    $calls = array_map('file_get_contents', glob($root . '/outgoing/*'));
    foreach ($calls as $call) {
        phone_producer_check(strpos($call, 'Account: slsphone_' . str_repeat('a', 32)) !== false && strpos($call, 'MaxRetries: 0') !== false, 'Ticket or no-retry guard missing from call file');
        phone_producer_check(strpos($call, 'Setvar: __SLS_PHONE_TOKEN=') !== false && strpos($call, '/n' . "\n") !== false, 'Inherited ticket or Local lifecycle preservation missing');
    }
    $combined = implode("\n", $calls);
    phone_producer_check(strpos($combined, 'Application: Wait') !== false && strpos($combined, "Context: sls-outbound-playback\nExtension: s\nPriority: 1\n") !== false
        && strpos($combined, 'Application: Playback') === false, 'External playback bypassed its individual trunk-answer gate');
    phone_producer_check($fixture->lastAudioQueueResults['1000'] === true && $fixture->lastOutboundQueueResults[$voice['id']]['state'] === 'queued', 'Queue evidence schema regressed');
    $partial = $reply; $partial['outbound_targets'] = [];
    $partial['outbound_failures'] = [['id'=>$voice['id'], 'failure_code'=>'outbound_subroutine_unsupported', 'detail'=>'External route fixture refused.', 'retryable'=>false]];
    phone_producer_check($invoke($partial) === 1 && count(glob($root . '/outgoing/*')) === 1, 'External route rejection suppressed the authorized internal phone');
    phone_producer_check($fixture->lastAudioQueueResults['1000'] === true && $fixture->lastOutboundQueueResults[$voice['id']]['detail'] === 'External route fixture refused.', 'Partial audio receipts lost their individual outcomes');
    $invalid = $partial; $invalid['outbound_failures'][0]['id'] = 'voice_' . str_repeat('c',24);
    phone_producer_check($invoke($invalid) === 0, 'Unrequested external rejection identity accepted');
    $credential = \SLS\MassNotify\ApiSecurity::issue(['name' => 'Prepared audio fixture', 'scopes' => ['send'], 'audience' => ['unrestricted' => false, 'extensions' => ['1000']]])['credential'];
    file_put_contents($root . '/settings.json', json_encode(['control_api' => ['enabled' => '1', 'credentials' => [$credential]]]));
    $context = ['api_credential_id' => $credential['id']];
    phone_producer_check($invoke($reply, $context) === 1, 'Current audience narrowing did not preserve the permitted phone');
    phone_producer_check($fixture->lastPhoneAdmission['permission_denied_targets']['voice_recipient_ids'] === [$voice['id']], 'Removed external recipient lost its cancellation evidence');
    file_put_contents($root . '/revoke-during-admission', 'fixture');
    phone_producer_check($invoke($reply, $context) === 0 && !glob($root . '/outgoing/*'), 'Credential revoked during admission still wrote call files');
    phone_producer_check($fixture->lastPhoneAdmission['failure_code'] === 'api_permission_revoked'
        && $fixture->lastPhoneAdmission['permission_denied_targets']['phones'] === ['1000'], 'Revocation did not preserve the cancelled phone and reason');
    unlink($root . '/revoke-during-admission');
    $reply['targets'] = ['911'];
    phone_producer_check($invoke($reply) === 0 && !glob($root . '/outgoing/*'), 'Unrequested helper recipient was accepted');
    echo "Whole-audience admission, frozen tickets, playback paths, narrowed permission and revocation during admission before spool passed.\n";
} finally {
    foreach (['outgoing', 'tmp'] as $dir) { foreach (glob($root . '/' . $dir . '/*') as $path) unlink($path); rmdir($root . '/' . $dir); }
    foreach (glob($root . '/*') as $path) unlink($path);
    rmdir($root);
}
