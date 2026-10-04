<?php
/** Real address migration/normalization and probe code with inert network discovery/cURL. */
namespace FreePBX\modules {
    function exec($command, &$output, &$status) {
        $output = ['*:8443 pbx.example.test (/fixture.conf:1)', '*:9443 other.example.test (/fixture.conf:2)'];
        $status = 0;
    }
    function curl_init($url) { $GLOBALS['address_requests'][] = ['url' => $url]; return count($GLOBALS['address_requests']) - 1; }
    function curl_setopt_array($handle, $options) { $GLOBALS['address_requests'][$handle]['options'] = $options; return true; }
    function curl_setopt($handle, $option, $value) { $GLOBALS['address_requests'][$handle]['options'][$option] = $value; return true; }
    function curl_exec($handle) {
        $callback = $GLOBALS['address_requests'][$handle]['options'][CURLOPT_WRITEFUNCTION];
        $body = $GLOBALS['address_body'] ?? '{"error":"unauthorized"}';
        $GLOBALS['address_requests'][$handle]['written'] = $callback($handle, $body);
        return true;
    }
    function curl_errno($handle) { return $GLOBALS['address_curl_error'] ?? 0; }
    function curl_getinfo($handle, $option) { return $option === CURLINFO_CERTINFO ? ($GLOBALS['address_certificate'] ?? []) : ($GLOBALS['address_status'] ?? 401); }
    function curl_close($handle) {}
}
namespace {
    interface BMO {}
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    use FreePBX\modules\SlsAdvertisedAddress as Address;
    $checks = 0;
    function check($value, $message) {
        $GLOBALS['checks']++;
        if (!$value) { throw new RuntimeException($message); }
    }
    $original = ['public_pbx_host' => 'old.example.test',
        'sipnotify' => ['pbx_host' => 'old.example.test', 'base_url' => 'https://old.example.test:8443/api/sipnotify',
            'media_scheme' => 'http', 'media_base_url' => 'http://old.example.test:8080/sls_mass_notify',
            'format_overrides' => ['1000' => 'yealink']],
        'control_api' => ['base_url' => 'https://old.example.test:8443/api/sls-mass-notify', 'api_key' => 'fixture-secret', 'enabled' => '1'],
        'mail_from_domain' => 'unchanged.example.test', 'mail_to' => 'fixture@example.test',
        'desktop_clients' => [['id' => 'fixture', 'username' => 'test', 'password_hash' => 'unchanged']],
        'announcement_groups' => [['id' => 'group', 'extensions' => ['1000']]],
        'opening_tone' => 'original.wav'];
    $input = ['advertised_pbx_host' => ' PBX.Example.Test ', 'advertised_api_port' => '9443',
        'advertised_control_port' => '7443', 'advertised_media_port' => '8443', 'sipnotify_media_scheme' => 'https'];
    $before = serialize($original);
    $changed = Address::migrate($original, $input);
    check(serialize($original) === $before, 'Preview mutated its source settings.');
    $expected = $original;
    $expected['public_pbx_host'] = $expected['sipnotify']['pbx_host'] = 'pbx.example.test';
    $expected['sipnotify']['base_url'] = 'https://pbx.example.test:9443/api/sipnotify';
    $expected['sipnotify']['media_base_url'] = 'https://pbx.example.test:8443/sls_mass_notify';
    $expected['sipnotify']['media_scheme'] = 'https';
    $expected['control_api']['base_url'] = 'https://pbx.example.test:7443/api/sls-mass-notify';
    check($changed === $expected, 'Migration changed unrelated credentials, email, recipients or tones.');
    check(Address::fields($changed) === ['host' => 'pbx.example.test', 'api_port' => 9443, 'control_port' => 7443, 'media_port' => 8443, 'media_scheme' => 'https'], 'Custom ports were lost.');
    check(Address::preview($changed)['desktop_url'] === 'https://pbx.example.test:9443/api/sipnotify/desktop', 'Desktop preview disagrees with saved URL.');
    foreach (['https://pbx.example.test', 'host:443', 'user@host', 'host/path', "host\r\nInjected: true", '-host', 'host-', 'a..b', str_repeat('a', 64), '999.1.1.1', '[::1]', [], null] as $invalid) {
        try { Address::migrate($original, array_replace($input, ['advertised_pbx_host' => $invalid])); throw new LogicException('Invalid hostname accepted.'); }
        catch (DomainException $error) { check($error->getMessage() !== '', 'Missing hostname explanation.'); }
    }
    foreach ([0, 65536, -1, 1.2, true, [], '443x', '443\n', null] as $invalid) {
        foreach (['advertised_api_port', 'advertised_control_port', 'advertised_media_port'] as $field) {
            try { Address::migrate($original, array_replace($input, [$field => $invalid])); throw new LogicException('Invalid port accepted.'); }
            catch (DomainException $error) { check(strpos($error->getMessage(), '65535') !== false, 'Missing port range explanation.'); }
        }
    }
    $reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
    $module = $reflection->newInstanceWithoutConstructor();
    $_SERVER['HTTP_HOST'] = 'attacker.invalid:6666';
    foreach (['normalizeSipNotifySettings', 'getControlApiUrl'] as $name) {
        $method = $reflection->getMethod($name); $method->setAccessible(true);
        $result = $method->invoke($module, $name === 'normalizeSipNotifySettings' ? $changed['sipnotify'] : $changed);
        check($name === 'normalizeSipNotifySettings' ? $result['base_url'] === $expected['sipnotify']['base_url'] : $result === $expected['control_api']['base_url'], 'Normalization followed request host or dropped API port.');
    }
    $method = $reflection->getMethod('normalizeSipNotifySettings');
    foreach (['https://evil.invalid:9443/api/sipnotify', 'http://pbx.example.test:9443/api/sipnotify', 'https://user@pbx.example.test:9443/api/sipnotify', 'https://pbx.example.test:9443/api/sipnotify?query', 'https://pbx.example.test:65536/api/sipnotify'] as $invalid) {
        $result = $method->invoke($module, array_replace($changed['sipnotify'], ['base_url' => $invalid]));
        check($result['base_url'] === 'https://pbx.example.test/api/sipnotify', 'Unsafe saved API URL survived normalization.');
    }
    foreach ([1, 443, 8443, 65535] as $port) {
        $result = Address::migrate($original, array_replace($input, ['advertised_api_port' => $port]));
        check(Address::fields($result)['api_port'] === $port, 'Valid boundary/custom port did not round-trip.');
    }
    $GLOBALS['address_requests'] = [];
    check(Address::checkLocalHttps($changed)['ok'] === true, 'Valid local TLS/API result was not recognized.');
    check(count($GLOBALS['address_requests']) === 1, 'Successful probe continued probing.');
    $request = $GLOBALS['address_requests'][0]; $options = $request['options'];
    check($request['url'] === 'https://pbx.example.test:9443/api/sipnotify/desktop', 'Probe changed advertised SNI/port.');
    check($options[CURLOPT_CONNECT_TO] === ['pbx.example.test:9443:127.0.0.1:8443'], 'Probe escaped discovered loopback listener.');
    check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'Probe skipped certificate verification.');
    check($options[CURLOPT_HTTPHEADER] === ['Accept: application/json'] && !isset($options[CURLOPT_USERPWD]), 'Probe sent credentials.');
    check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_NOPROXY] === '*', 'Probe can redirect or use a proxy.');
    check($options[CURLOPT_TIMEOUT_MS] <= 1500 && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 500, 'Probe lacks request deadlines.');
    $key = openssl_pkey_new(['private_key_bits'=>2048]);
    $request = openssl_csr_new(['commonName'=>'pbx.example.test'], $key);
    $certificate = openssl_csr_sign($request, null, $key, 45);
    openssl_x509_export($certificate, $pem);
    $GLOBALS['address_certificate'] = [['Cert'=>$pem]];
    $probe = Address::checkLocalHttps($changed);
    check($probe['certificate_expires_at'] === openssl_x509_parse($pem)['validTo_time_t'], 'Probe lost the served certificate expiry.');
    check(strpos(json_encode($probe), 'BEGIN CERTIFICATE') === false, 'Probe exposed the certificate body.');
    unset($GLOBALS['address_certificate']);
    $GLOBALS['address_curl_error'] = 60;
    check(strpos(Address::checkLocalHttps($changed)['message'], 'certificate') !== false, 'Certificate failure is generic.');
    unset($GLOBALS['address_curl_error']); $GLOBALS['address_status'] = 200;
    check(Address::checkLocalHttps($changed)['ok'] === false, 'Unrelated public page passed API check.');
    $GLOBALS['address_status'] = 401; $GLOBALS['address_body'] = str_repeat('x', 8193);
    $GLOBALS['address_requests'] = [];
    check(Address::checkLocalHttps($changed)['ok'] === false, 'Oversized response passed API check.');
    foreach ($GLOBALS['address_requests'] as $request) { check($request['written'] === 0, 'Probe did not bound the response body.'); }
    // Execute the actual page handler in a child: invalid CSRF never invokes
    // the probe, valid preview invokes only the read-only check method.
    $page = dirname(__DIR__) . '/slsmassnotifyserver/page.slsmassnotifyserver_other.php';
    foreach ([false, true] as $valid) {
        $code = '<?php class FreePBX { static function create(){ return (object)["Slsmassnotifyserver"=>new Fixture]; } }
            class Fixture { function enforceOperatorPageAccess($page){} function validateCsrfToken($value){ return ' . ($valid ? 'true' : 'false') . '; }
            function checkAdvertisedAddress($input){ return ["success"=>true,"preview_only"=>true]; }
            function __call($name,$args){ throw new Exception("Unexpected mutation: ".$name); } }
            $_SESSION=[]; $_SERVER["REQUEST_METHOD"]="POST";
            $_POST=["slsmassnotifyserver_action"=>"check_advertised_address","slsmassnotifyserver_csrf"=>"fixture"];
            require ' . var_export($page, true) . ';';
        $process = proc_open([PHP_BINARY], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fwrite($pipes[0], $code); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $err === '', 'Preview page failed: ' . $err);
        check($valid ? json_decode($out, true) === ['success'=>true,'preview_only'=>true] : $out === '', 'Preview bypassed CSRF or did not remain read-only.');
    }
    echo "Advertised address: $checks migration, normalization, probe and CSRF checks passed.\n";
}
