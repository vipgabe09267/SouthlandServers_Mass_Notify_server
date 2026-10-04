<?php
/** Fixture-only discovery and request tests: no sockets, Apache, or PBX access. */
namespace FreePBX\modules {
	function exec($command, &$output, &$status) {
		$GLOBALS['discovery_commands'][] = $command;
		$output = explode("\n", $GLOBALS['vhost_fixture']);
		$status = 0;
	}
	function file_get_contents($url, $includePath, $context, $offset, $length) {
		$GLOBALS['probe_requests'][] = [$url, stream_context_get_options($context), $length];
		return 'fixture response';
	}
}
namespace {
	require_once dirname(__DIR__) . '/slsmassnotifyserver/LocalWebProbe.php';
	require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/media-policy.php';
	use FreePBX\modules\SlsLocalWebProbe;
	function check($condition, $message) {
		if (!$condition) { throw new \RuntimeException($message); }
	}
	$GLOBALS['vhost_fixture'] = "VirtualHost configuration:\n"
		. "*:8080 is a NameVirtualHost\n"
		. " port 8080 namevhost other.invalid (/etc/apache2/sites-enabled/other.conf:1)\n"
		. "*:8443 is a NameVirtualHost\n"
		. " port 8443 namevhost pbx.example.test (/etc/apache2/sites-enabled/pbx.conf:3)\n"
		. "[::]:9443 ipv6.example.test (/etc/apache2/sites-enabled/ipv6.conf:1)\n"
		. " port 9443 namevhost ipv6.example.test (/etc/apache2/sites-enabled/ipv6.conf:1)\n"
		. "*:0 invalid\n*:65536 invalid\nmalicious.example.test:9000 invalid\n"
		. "https://external.invalid:9999 ignored\n";
	$GLOBALS['discovery_commands'] = [];
	$targets = SlsLocalWebProbe::targets('pbx.example.test');
	check(count($GLOBALS['discovery_commands']) === 1, 'Apache discovery was not called exactly once');
	check(strpos($GLOBALS['discovery_commands'][0], '/usr/bin/timeout 5 ') === 0, 'Discovery lacks a time bound');
	check($targets[0]['url'] === 'https://pbx.example.test:8443', 'Configured hostname listener was not prioritized');
	check($targets[0]['resolve'] === 'pbx.example.test:8443:127.0.0.1', 'Custom HTTPS host was not pinned to loopback');
	check($targets[0]['host_header'] === 'pbx.example.test:8443', 'Custom port Host header was lost');
	$ports = [];
	$ipv6 = false;
	foreach ($targets as $target) {
		$ports[(int)parse_url($target['url'], PHP_URL_PORT)] = true;
		check(in_array(parse_url($target['connect_url'], PHP_URL_HOST), ['127.0.0.1', '[::1]'], true), 'Non-loopback connection target');
		$ipv6 = $ipv6 || strpos($target['connect_url'], '[::1]:9443') !== false;
	}
	check(array_keys($ports) === [8443, 80, 443, 8080, 9443], 'Unexpected or malformed Apache port was accepted');
	check($ipv6, 'IPv6 vhost listener was lost when its namevhost line followed');
	$many = '';
	for ($port = 10000; $port < 10100; $port++) { $many .= '*:' . $port . " fixture\n"; }
	$bounded = SlsLocalWebProbe::targets('pbx.example.test', $many);
	check(count(array_unique(array_map(function ($target) { return parse_url($target['url'], PHP_URL_PORT); }, $bounded))) === 16, 'Listener count is not bounded');
	foreach (["pbx.example.test\r\nAuthorization: injected", 'https://external.invalid', 'external.invalid:8443'] as $invalidHost) {
		foreach (SlsLocalWebProbe::targets($invalidHost, '') as $target) {
			check($target['host'] === '127.0.0.1', 'Invalid configured host reached a probe');
		}
	}
	$GLOBALS['probe_requests'] = [];
	$responses = SlsLocalWebProbe::responses('pbx.example.test', '/api/sipnotify/desktop/stream?stream_seconds=1', ['Authorization: Basic fixture', 'Accept: text/event-stream'], 8, $GLOBALS['vhost_fixture']);
	foreach ($responses as $response) {
		check($response['body'] === 'fixture response', 'Probe did not return the fixture body');
		break;
	}
	check(count($GLOBALS['probe_requests']) === 1, 'Probe did not remain lazy after success');
	[$url, $options, $length] = $GLOBALS['probe_requests'][0];
	check($url === 'https://127.0.0.1:8443/api/sipnotify/desktop/stream?stream_seconds=1', 'Authenticated probe did not use the discovered local port');
	check(strpos($options['http']['header'], "Host: pbx.example.test:8443\r\nAuthorization: Basic fixture\r\n") === 0, 'Host or credential header changed');
	check($options['ssl']['peer_name'] === 'pbx.example.test' && $options['ssl']['SNI_enabled'], 'Configured SNI was lost');
	check($options['http']['follow_location'] === 0 && $options['http']['max_redirects'] === 0, 'Redirects could disclose credentials');
	check($options['http']['timeout'] <= 8 && $length === 262144, 'Probe response bounds changed');
	foreach ([['https://external.invalid', ['Accept: text/plain']], ['/api/test', ["Authorization: test\r\nInjected: value"]]] as $invalid) {
		$rejected = false;
		try { iterator_to_array(SlsLocalWebProbe::responses('pbx.example.test', $invalid[0], $invalid[1], 8, '')); }
		catch (\RuntimeException $exception) { $rejected = true; }
		check($rejected, 'Invalid request input was accepted');
	}
	// Execute the installer's actual enabled-Control-API PHP with private files
	// and an inert HTTP helper, so wrong credential variables cannot pass lint.
	$fixture = sys_get_temp_dir() . '/sls-web-probe-' . bin2hex(random_bytes(8));
	check(mkdir($fixture, 0700), 'Cannot create private installer fixture');
	try {
		file_put_contents($fixture . '/config.json', json_encode(['public_pbx_host' => 'pbx.example.test', 'control_api' => ['enabled' => '1', 'api_key' => 'fixture-control-key']]));
		file_put_contents($fixture . '/helper.php', '<?php namespace FreePBX\\modules; final class SlsLocalWebProbe { public static function responses($host, $path, $headers, $timeout) { if ($host !== "pbx.example.test" || $path !== "/api/sls-mass-notify/?resource=status" || $headers !== ["Authorization: Bearer fixture-control-key", "Accept: application/json"]) { throw new \\RuntimeException("Installer authentication request was altered"); } yield ["status" => 200, "body" => "{\\"ok\\":true,\\"resource\\":\\"status\\"}"]; } }');
		$installer = file_get_contents(dirname(__DIR__) . '/tools/install_release.sh');
		$block = explode('verify_control_api_authentication() {', $installer, 2)[1];
		$block = explode("php -r '\n", $block, 2)[1];
		$block = explode("\n' >>", $block, 2)[0];
		$block = str_replace('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config', $fixture . '/config.json', $block);
		$block = str_replace('/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php', $fixture . '/helper.php', $block);
		$block = str_replace('/usr/local/bin/sls_mass_notify/sls_config_crypto.php', dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/config-crypto.php', $block);
		$pipes = [];
		$process = proc_open(['/usr/bin/php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		check(is_resource($process), 'Cannot launch inert installer fixture');
		fwrite($pipes[0], "<?php\n" . $block); fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
		$error = stream_get_contents($pipes[2]); fclose($pipes[2]);
		check(proc_close($process) === 0 && $error === '', 'Enabled Control API installer fixture failed: ' . $error);
	} finally {
		@unlink($fixture . '/config.json'); @unlink($fixture . '/helper.php'); @rmdir($fixture);
	}
	foreach ([[404,'media_not_found'],[403,'media_network_denied']] as [$code,$error]) {
		$result = SlsLocalWebProbe::mediaAccess([], [['status'=>$code,'headers'=>['X-SLS-Media-Policy: 1'], 'body'=>json_encode(['error'=>$error])]]);
		check($result['ok'], 'Healthy media gate was not recognized.');
	}
	check(!SlsLocalWebProbe::mediaAccess([], [['status'=>404,'headers'=>[], 'body'=>'Not found']])['ok'], 'Static Apache 404 falsely proved media policy enforcement.');
	$result = SlsLocalWebProbe::mediaAccess([], [['status'=>503,'headers'=>['X-SLS-Media-Policy: 1'], 'body'=>'{"error":"media_access_configuration_unavailable"}']]);
	check(!$result['ok'] && str_contains($result['message'], 'protected configuration'), 'Policy read error was not actionable.');
	check(!SlsLocalWebProbe::mediaAccess(['media_access'=>null], [])['ok'], 'Malformed policy passed readiness.');
	echo "Local Apache discovery, custom-port authentication, loopback pinning, Host/SNI, request bounds, media policy and invalid-input fixtures passed.\n";
}
