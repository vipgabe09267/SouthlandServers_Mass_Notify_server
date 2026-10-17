<?php
declare(strict_types=1);
/** Real address migration/normalization and probe code with inert network discovery/cURL. */
namespace FreePBX\modules {
    function gethostname() { return 'system-host.example.test'; }
    function exec($command, &$output, &$status) {
        $output = ['*:8443 pbx.example.test (/fixture.conf:1)', '*:9443 other.example.test (/fixture.conf:2)'];
        $status = 0;
    }
    function curl_init($url) { if (!empty($GLOBALS['address_init_failure'])) { return false; } $GLOBALS['address_requests'][] = ['url' => $url]; return count($GLOBALS['address_requests']) - 1; }
    function curl_setopt_array($handle, $options) { $GLOBALS['address_requests'][$handle]['options'] = $options; return empty($GLOBALS['address_option_failure']); }
    function curl_setopt($handle, $option, $value) { $GLOBALS['address_requests'][$handle]['options'][$option] = $value; return empty($GLOBALS['address_certificate_option_failure']); }
    function curl_exec($handle) {
        $callback = $GLOBALS['address_requests'][$handle]['options'][CURLOPT_WRITEFUNCTION];
        $body = $GLOBALS['address_body'] ?? '{"error":"unauthorized"}';
        $GLOBALS['address_requests'][$handle]['written'] = $callback($handle, $body);
        return true;
    }
    function curl_errno($handle) { return $GLOBALS['address_curl_error'] ?? 0; }
    function curl_getinfo($handle, $option) { return $option === CURLINFO_CERTINFO ? ($GLOBALS['address_certificate'] ?? []) : ($GLOBALS['address_status'] ?? 401); }
    function curl_close($handle) { $GLOBALS['address_closed'][] = $handle; }
}
namespace {
    interface BMO {}
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    use FreePBX\modules\SlsAdvertisedAddress as Address;
    $checks = 0;
    function check(bool $value, string $message): void {
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
    foreach (['https://pbx.example.test', 'host:443', 'user@host', 'host/path', "host\r\nInjected: true", '-host', 'host-', 'a..b', str_repeat('a', 64), '999.1.1.1', '[::1]',
        'localhost','localhost.localdomain','pbx.localhost','0.0.0.0','0.10.20.30','127.0.0.1','127.255.255.255','169.254.1.2','224.0.0.1','239.255.255.255','240.0.0.1','255.255.255.255',[], null] as $invalid) {
        try { Address::migrate($original, array_replace($input, ['advertised_pbx_host' => $invalid])); throw new LogicException('Invalid hostname accepted.'); }
        catch (DomainException $error) { check($error->getMessage() !== '', 'Missing hostname explanation.'); }
    }
    foreach (['10.0.0.1','172.16.4.2','192.168.1.10','100.64.0.1','pbx.internal.example'] as $validHost) {
        $result=Address::migrate($original,array_replace($input,['advertised_pbx_host'=>$validHost]));
        check($result['public_pbx_host']===$validHost,'A reachable private/site advertised address was rejected.');
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
    $_SERVER['SERVER_NAME'] = 'attacker-server-name.invalid';
    $detect = $reflection->getMethod('detectPbxHost'); $detect->setAccessible(true);
    check($detect->invoke($module) === 'system-host.example.test', 'PBX host detection trusted a request-controlled host value.');
    $normalizeHost = $reflection->getMethod('normalizePbxHost'); $normalizeHost->setAccessible(true);
    foreach (["pbx.example.test\r\nInjected: true", 'user@pbx.example.test', 'pbx..example.test', '-pbx.example.test', 'pbx.example.test:65536'] as $unsafeHost) {
        check($normalizeHost->invoke($module, $unsafeHost) === '', 'Unsafe legacy PBX host normalization was accepted.');
    }
    check($normalizeHost->invoke($module, 'HTTPS://PBX.EXAMPLE.TEST:8443/') === 'pbx.example.test', 'A valid legacy advertised host was not normalized.');
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
    check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0 && $options[CURLOPT_NOPROXY] === '*' && $options[CURLOPT_PROXY] === '', 'Probe can redirect or use a proxy.');
    check($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $options[CURLOPT_SSLVERSION] === CURL_SSLVERSION_TLSv1_2, 'Probe did not constrain protocol and TLS setup.');
    check($options[CURLOPT_TIMEOUT_MS] <= 1500 && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 500, 'Probe lacks request deadlines.');
    $key = openssl_pkey_new(['private_key_bits'=>2048]);
    check($key !== false, 'Unable to create the certificate-test key.');
    $request = openssl_csr_new(['commonName'=>'pbx.example.test'], $key);
    check($request !== false, 'Unable to create the certificate-test request.');
    $certificate = openssl_csr_sign($request, null, $key, 45);
    check($certificate !== false, 'Unable to create the certificate-test certificate.');
    check(openssl_x509_export($certificate, $pem) && is_string($pem), 'Unable to export the certificate-test certificate.');
    $GLOBALS['address_certificate'] = [['Cert'=>$pem]];
    $probe = Address::checkLocalHttps($changed);
    $certificateDetails=openssl_x509_parse($pem);
    check(is_array($certificateDetails)&&is_int($certificateDetails['validTo_time_t']??null), 'Unable to parse the certificate-test expiry.');
    check($probe['certificate_expires_at'] === $certificateDetails['validTo_time_t'], 'Probe lost the served certificate expiry.');
    check(strpos(json_encode($probe, JSON_THROW_ON_ERROR), 'BEGIN CERTIFICATE') === false, 'Probe exposed the certificate body.');
    unset($GLOBALS['address_certificate']);
    $GLOBALS['address_curl_error'] = 60;
    check(strpos(Address::checkLocalHttps($changed)['message'], 'certificate') !== false, 'Certificate failure is generic.');
    unset($GLOBALS['address_curl_error']); $GLOBALS['address_status'] = 200;
    check(Address::checkLocalHttps($changed)['ok'] === false, 'Unrelated public page passed API check.');
    $GLOBALS['address_status'] = 401; $GLOBALS['address_body'] = str_repeat('x', 8193);
    $GLOBALS['address_requests'] = [];
    check(Address::checkLocalHttps($changed)['ok'] === false, 'Oversized response passed API check.');
    foreach ($GLOBALS['address_requests'] as $request) { check($request['written'] === 0, 'Probe did not bound the response body.'); }
    unset($GLOBALS['address_body']);
    $GLOBALS['address_init_failure'] = true; $GLOBALS['address_requests'] = [];
    check(Address::checkLocalHttps($changed)['ok'] === false && $GLOBALS['address_requests'] === [], 'Failed cURL initialization was not bounded.');
    unset($GLOBALS['address_init_failure']); $GLOBALS['address_option_failure'] = true; $GLOBALS['address_requests'] = []; $GLOBALS['address_closed'] = [];
    check(Address::checkLocalHttps($changed)['ok'] === false && count($GLOBALS['address_closed']) === count($GLOBALS['address_requests']), 'Failed cURL safeguard setup leaked a handle or reached transport.');
    unset($GLOBALS['address_option_failure']); $GLOBALS['address_certificate_option_failure'] = true; $GLOBALS['address_requests'] = [];
    $withoutCertificate = Address::checkLocalHttps($changed);
    check($withoutCertificate['ok'] === true && $withoutCertificate['certificate_expires_at'] === null, 'Optional certificate inspection failure blocked the authenticated API check or fabricated certificate data.');
    unset($GLOBALS['address_certificate_option_failure']);
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
        $environment=['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
        $process = proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=2s','10s',PHP_BINARY], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $environment, ['bypass_shell'=>true]);
        check(is_resource($process), 'Preview page fixture could not start.');
        $offset=0;while($offset<strlen($code)){$written=fwrite($pipes[0],substr($code,$offset));if(!is_int($written)||$written<1){break;}$offset+=$written;}
        $inputClosed=fclose($pipes[0]);check($offset===strlen($code)&&$inputClosed, 'Preview page fixture input was incomplete.');
        $out = stream_get_contents($pipes[1],65537); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2],4097); fclose($pipes[2]);
        check(is_string($out)&&strlen($out)<=65536&&is_string($err)&&strlen($err)<=4096&&proc_close($process) === 0 && $err === '', 'Preview page failed or exceeded its output bound.');
        check($valid ? json_decode($out, true, 8, JSON_THROW_ON_ERROR) === ['success'=>true,'preview_only'=>true] : $out === '', 'Preview bypassed CSRF or did not remain read-only.');
    }
    echo "Advertised address: $checks migration, normalization, probe and CSRF checks passed.\n";
}
