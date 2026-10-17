<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/security.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/WeatherChannels.php';

$directory = sys_get_temp_dir() . '/sls-weather-authorization-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) { throw new RuntimeException('Unable to create fixture directory.'); }
$helper = $directory . '/sls_weather_channels.py';
file_put_contents($helper, <<<'PY'
#!/usr/bin/python3
import json
import os
import sys

raw = sys.stdin.buffer.read()
request = json.loads(raw)
case = request.get('case', 'valid')
if os.environ.get('SLS_WEATHER_FORGE'):
    case = 'environment_leaked'
voice = 'voice_' + ('a' * 24)
sms = 'sms_' + ('b' * 24)
if case == 'duplicate_key':
    print('{"status":"eligible","status":"cancelled","reason":"","voice_recipient_ids":[],"sms_recipient_ids":[]}')
elif case == 'extra_key':
    print(json.dumps({'status':'eligible','reason':'','voice_recipient_ids':[],'sms_recipient_ids':[],'extra':True}))
elif case == 'bad_recipient':
    print(json.dumps({'status':'eligible','reason':'','voice_recipient_ids':['voice_bad'],'sms_recipient_ids':[]}))
elif case == 'ineligible_recipients':
    print(json.dumps({'status':'cancelled','reason':'source_cancelled','voice_recipient_ids':[voice],'sms_recipient_ids':[]}))
elif case == 'oversized_output':
    sys.stdout.write('x' * 131073)
elif case == 'environment_leaked':
    print(json.dumps({'status':'forged','reason':'','voice_recipient_ids':[],'sms_recipient_ids':[]}))
else:
    print(json.dumps({'status':'eligible','reason':'','voice_recipient_ids':[voice],'sms_recipient_ids':[sms]}))
PY
);
chmod($helper, 0700);

$source = <<<'PHP'
class WeatherAuthorizationFixture {
    use \FreePBX\modules\SlsWeatherChannels;
    public function authorize(array $context): array { return $this->weatherChannelAuthorization($context); }
PHP;
eval($source . ' const RUNTIME_DIR = ' . var_export($directory, true) . '; }');
$fixture = new WeatherAuthorizationFixture();

try {
    if (!function_exists('proc_open')) {
        $disabledRejected = false;
        try { $fixture->authorize(['case' => 'valid']); }
        catch (RuntimeException $error) { $disabledRejected = true; }
        if (!$disabledRejected) { throw new RuntimeException('Disabled process support caused an unsafe authorization attempt.'); }
        echo "Disabled process support fails closed before Weather authorization.\n";
        return;
    }
    putenv('SLS_WEATHER_FORGE=1');
    $valid = $fixture->authorize(['case' => 'valid', 'padding' => str_repeat('x', 200000)]);
    putenv('SLS_WEATHER_FORGE');
    if ($valid !== ['status' => 'eligible', 'reason' => '',
        'voice_recipient_ids' => ['voice_' . str_repeat('a', 24)],
        'sms_recipient_ids' => ['sms_' . str_repeat('b', 24)]]) {
        throw new RuntimeException('A valid bounded authorization result or complete large request was lost.');
    }
    foreach (['duplicate_key', 'extra_key', 'bad_recipient', 'ineligible_recipients', 'oversized_output'] as $case) {
        $rejected = false;
        try { $fixture->authorize(['case' => $case]); }
        catch (RuntimeException $error) { $rejected = true; }
        if (!$rejected) { throw new RuntimeException('Unsafe authorization result was accepted: ' . $case); }
    }
    chmod($helper, 0720);
    $writableRejected = false;
    try { $fixture->authorize(['case' => 'valid']); }
    catch (RuntimeException $error) { $writableRejected = true; }
    if (!$writableRejected) { throw new RuntimeException('Group-writable authorization helper was executed.'); }
    chmod($helper, 0700);
    rename($helper, $helper . '.missing');
    $missingRejected = false;
    try { $fixture->authorize(['case' => 'valid']); }
    catch (RuntimeException $error) { $missingRejected = true; }
    if (!$missingRejected) { throw new RuntimeException('Missing authorization helper was not rejected before submission.'); }
    echo "Weather authorization process integrity, isolation, complete writes and strict result validation passed.\n";
} finally {
    putenv('SLS_WEATHER_FORGE');
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}
