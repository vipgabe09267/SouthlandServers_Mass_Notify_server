<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$root = sys_get_temp_dir() . '/sls-phone-producers-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/outgoing'); mkdir($root . '/tmp');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName()); $methods = '';
foreach (['queueAnnouncementAudioCalls', 'requestPhoneAdmission', 'stageAsteriskCallFile', 'getAudioPageHoldSeconds', 'collectBoundedProcessOutput'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
eval('class PhoneProducerFixture { use \\FreePBX\\modules\\SlsApiPermissionGuards;
    const RUNTIME_DIR = ' . var_export($root, true) . '; const ASTERISK_OUTGOING_SPOOL = ' . var_export($root . '/outgoing', true) . '; const ASTERISK_SPOOL_TMP = ' . var_export($root . '/tmp', true) . ';
    public $lastAudioQueueResults = []; public $lastOutboundQueueResults = []; public $lastPhoneAdmission = [];
    function getActiveSettings(){ return json_decode(file_get_contents(self::RUNTIME_DIR . "/settings.json"), true); }
    ' . $methods . '}');
file_put_contents($root . '/settings.json', '{}');
file_put_contents($root . '/sls_audio_queue.py', <<<'PY'
import os
import sys
from pathlib import Path
root = Path(__file__).parent
if 'SLS_PHONE_TEST_SECRET' in os.environ:
    (root / 'queue-inherited-secret').write_text(os.environ['SLS_PHONE_TEST_SECRET'])
if (root / 'large-queue-stderr').exists():
    sys.stderr.write('e' * 32768)
raise SystemExit(0)
PY
);
file_put_contents($root . '/sls_phone_admission.py', <<<'PY'
import json, os, sys, time
from pathlib import Path
root = Path(__file__).parent
(root / 'inherited-secret').write_text(os.environ['SLS_PHONE_TEST_SECRET']) if 'SLS_PHONE_TEST_SECRET' in os.environ else None
(root / 'request.json').write_text(sys.stdin.read())
if (root / 'large-admission-stdout').exists():
    sys.stdout.write('x' * 262144)
    raise SystemExit(0)
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
$admission = new ReflectionMethod($fixture, 'requestPhoneAdmission');
$admission->setAccessible(true);
$voice = ['id' => 'voice_' . str_repeat('b', 24), 'number' => '+15551234567', 'route_mode' => 'pbx_routes', 'trunk_id' => '', 'caller_id' => ''];
$oldPhoneTestSecret = getenv('SLS_PHONE_TEST_SECRET');
putenv('SLS_PHONE_TEST_SECRET');
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
	foreach (glob($root . '/outgoing/*') as $callPath) {
		$metadata = lstat($callPath);
		phone_producer_check(($metadata['mode'] & 0777) === 0640 && $metadata['nlink'] === 1, 'Queued call file was not private and single-linked');
	}
	phone_producer_check(glob($root . '/tmp/*') === [], 'Successful queue left a call-file staging artifact');
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
	$missingInternal = $reply; $missingInternal['targets'] = [];
	phone_producer_check($invoke($missingInternal) === 0, 'A helper response that omitted an internal recipient was accepted');
	$overlapInternal = $reply; $overlapInternal['unavailable'] = ['1000'];
	phone_producer_check($invoke($overlapInternal) === 0, 'A helper response admitted and rejected the same internal recipient');
	$duplicateInternal = $reply; $duplicateInternal['targets'] = ['1000', '1000'];
	phone_producer_check($invoke($duplicateInternal) === 0, 'A helper response duplicated an internal recipient');
	$missingExternal = $reply; $missingExternal['outbound_targets'] = [];
	phone_producer_check($invoke($missingExternal) === 0, 'A helper response that omitted an external recipient was accepted');
	$invalidRetry = $partial; $invalidRetry['outbound_failures'][0]['retryable'] = 'false';
	phone_producer_check($invoke($invalidRetry) === 0, 'A non-boolean external retry decision was accepted');
	file_put_contents($root . '/reply.json', json_encode($reply));
	putenv('SLS_PHONE_TEST_SECRET=must-not-cross-boundary');
	$largeAdmission = $admission->invoke($fixture, [
		'internal' => ['1000'],
		'outbound' => [$voice],
		'service' => 'announcement',
		'correlation' => 'large-request-fixture',
		'padding' => str_repeat('x', 262144),
	]);
	phone_producer_check(!empty($largeAdmission['ok']) && filesize($root . '/request.json') > 262144,
		'Phone admission did not completely write a large bounded request');
	phone_producer_check(!file_exists($root . '/inherited-secret'), 'Phone admission inherited an unrelated caller secret');
	file_put_contents($root . '/large-admission-stdout', 'fixture');
	$started = microtime(true);
	$oversizedAdmission = $admission->invoke($fixture, [
		'internal' => ['1000'], 'outbound' => [], 'service' => 'announcement',
		'correlation' => 'oversized-output-fixture',
	]);
	phone_producer_check(empty($oversizedAdmission['ok']) && microtime(true) - $started < 5,
		'Phone admission did not reject oversized child output promptly');
	unlink($root . '/large-admission-stdout');
	file_put_contents($root . '/large-queue-stderr', 'fixture');
	$started = microtime(true);
	phone_producer_check($invoke($reply) === 0 && microtime(true) - $started < 5,
		'Audio queue did not reject oversized child diagnostics promptly');
	unlink($root . '/large-queue-stderr');
	phone_producer_check(!file_exists($root . '/queue-inherited-secret'),
		'Audio queue inherited an unrelated caller secret');
	putenv('SLS_PHONE_TEST_SECRET');
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
	$reply['targets'] = ['1000'];
	rename($root . '/tmp', $root . '/tmp-real'); symlink($root . '/tmp-real', $root . '/tmp');
	phone_producer_check($invoke($reply) === 0 && !glob($root . '/outgoing/*'), 'Symbolic-link call-file workspace was accepted');
	unlink($root . '/tmp'); rename($root . '/tmp-real', $root . '/tmp');
		rename($root . '/sls_phone_admission.py', $root . '/sls_phone_admission.py.offline');
		$unavailable = $admission->invoke($fixture, ['internal'=>['1000'], 'outbound'=>[], 'service'=>'announcement']);
		phone_producer_check(empty($unavailable['ok']) && ($unavailable['failure_code'] ?? '') === 'phone_admission_unavailable',
			'Missing phone-admission runtime did not fail closed with an actionable outcome');
		rename($root . '/sls_phone_admission.py.offline', $root . '/sls_phone_admission.py');
    echo "Whole-audience admission, frozen tickets, playback paths, narrowed permission and revocation during admission before spool passed.\n";
} finally {
	putenv($oldPhoneTestSecret === false ? 'SLS_PHONE_TEST_SECRET' : 'SLS_PHONE_TEST_SECRET=' . $oldPhoneTestSecret);
    foreach (['outgoing', 'tmp'] as $dir) { foreach (glob($root . '/' . $dir . '/*') as $path) unlink($path); rmdir($root . '/' . $dir); }
    foreach (glob($root . '/*') as $path) unlink($path);
    rmdir($root);
}
