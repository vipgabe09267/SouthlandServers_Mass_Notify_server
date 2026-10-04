<?php
namespace FreePBX\modules;

/** Read-only local Apache probes. Never resolve a public address or follow redirects. */
final class SlsLocalWebProbe
{
	public static function mediaAccess(array $settings, ?iterable $observations = null): array
	{
		try { \SLS\MassNotify\MediaAccess::normalize(array_key_exists('media_access', $settings) ? $settings['media_access'] : []); }
		catch (\DomainException $error) { return ['ok'=>false, 'message'=>$error->getMessage()]; }
		$path = '/sls_mass_notify/media_probe_' . bin2hex(random_bytes(12)) . '.png';
		$observations = $observations ?? self::responses($settings['public_pbx_host'] ?? '', $path, ['Accept: application/json'], 2);
		foreach ($observations as $response) {
			$marked = false;
			foreach ($response['headers'] ?? [] as $header) { if (preg_match('/^X-SLS-Media-Policy:\s*1\s*$/iD', $header)) { $marked = true; break; } }
			if (!$marked) { continue; }
			$body = json_decode($response['body'] ?? '', true);
			if (($response['status'] === 404 && ($body['error'] ?? '') === 'media_not_found')
				|| ($response['status'] === 403 && ($body['error'] ?? '') === 'media_network_denied')) {
				return ['ok'=>true, 'message'=>_('Generated media passes through the configured access policy. No file was created or notification sent.')];
			}
			if ($response['status'] === 503 && ($body['error'] ?? '') === 'media_access_configuration_unavailable') {
				return ['ok'=>false, 'message'=>_('The media route cannot read a valid protected configuration. Review Media Access settings and use Repair Installation to restore file permissions.')];
			}
		}
		return ['ok'=>false, 'message'=>_('The generated-media policy route could not be verified. Run Repair Installation and inspect any custom Apache alias or virtual-host overrides.')];
	}

	public static function targets($publicHost, $vhostDump = null)
	{
		$publicHost = strtolower(trim((string)$publicHost));
		$validHost = preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $publicHost)
			&& !in_array($publicHost, ['127.0.0.1', 'localhost'], true);
		if ($vhostDump === null) {
			$output = [];
			$status = 1;
			@exec('/usr/bin/timeout 5 /usr/sbin/apache2ctl -t -D DUMP_VHOSTS 2>/dev/null', $output, $status);
			$vhostDump = $status === 0 ? implode("\n", $output) : '';
		}
		$ports = [80 => ['127.0.0.1'], 443 => ['127.0.0.1']];
		$preferred = [];
		foreach (explode("\n", substr((string)$vhostDump, 0, 262144)) as $line) {
			if (!preg_match('/^\s*(?:(\*|[0-9.]+|\[[0-9a-f:]+\]):([0-9]+)\s+|port\s+([0-9]+)\s+namevhost\b)/i', $line, $match)) {
				continue;
			}
			$port = (int)($match[2] !== '' ? $match[2] : ($match[3] ?? 0));
			if ($port < 1 || $port > 65535 || (!isset($ports[$port]) && count($ports) >= 16)) {
				continue;
			}
			$ports[$port] = $ports[$port] ?? ['127.0.0.1'];
			if (strpos((string)($match[1] ?? ''), '[') === 0) {
				$ports[$port] = array_values(array_unique(array_merge($ports[$port], ['::1'])));
			}
			if ($validHost && preg_match('/(?:^|\s)' . preg_quote($publicHost, '/') . '(?:\s|$)/i', $line)) {
				$preferred[$port] = true;
			}
		}
		$ports = array_intersect_key($ports, $preferred) + $ports;
		$hosts = $validHost ? [$publicHost, '127.0.0.1'] : ['127.0.0.1'];
		$targets = [];
		foreach ($ports as $port => $addresses) {
			$schemes = in_array($port, [443, 8443], true) ? ['https', 'http'] : ['http', 'https'];
			foreach ($hosts as $host) {
				foreach ($schemes as $scheme) {
					foreach ($addresses as $address) {
						$connectHost = $address === '::1' ? '[::1]' : $address;
						$targets[] = [
							'url' => $scheme . '://' . $host . ':' . $port,
							'connect_url' => $scheme . '://' . $connectHost . ':' . $port,
							'host' => $host,
							'host_header' => $host . (in_array($scheme . ':' . $port, ['http:80', 'https:443'], true) ? '' : ':' . $port),
							'resolve' => $host . ':' . $port . ':' . $connectHost,
						];
					}
				}
			}
		}
		return $targets;
	}

	public static function responses($publicHost, $path, array $headers, $timeoutSeconds, $vhostDump = null)
	{
		if (!preg_match('#^/[A-Za-z0-9/_?&=.-]{1,511}$#D', (string)$path)) {
			throw new \RuntimeException('Local Apache verification rejected an invalid probe path.');
		}
		$headerText = '';
		foreach ($headers as $header) {
			$header = trim((string)$header);
			if ($header === '' || strpos($header, "\r") !== false || strpos($header, "\n") !== false) {
				throw new \RuntimeException('Local Apache verification rejected an invalid probe header.');
			}
			$headerText .= $header . "\r\n";
		}
		$deadline = microtime(true) + 25;
		foreach (self::targets($publicHost, $vhostDump) as $target) {
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				break;
			}
			$context = stream_context_create([
				'http' => [
					'method' => 'GET',
					'header' => 'Host: ' . $target['host_header'] . "\r\n" . $headerText . "Connection: close\r\n",
					'timeout' => min($remaining, max(1, min(10, (int)$timeoutSeconds))),
					'ignore_errors' => true,
					'follow_location' => 0,
					'max_redirects' => 0,
				],
				'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $target['host'], 'SNI_enabled' => true],
			]);
			$http_response_header = [];
			$body = @file_get_contents($target['connect_url'] . $path, false, $context, 0, 262144);
			$responseHeaders = is_array($http_response_header) ? $http_response_header : [];
			$status = 0;
			foreach ($responseHeaders as $responseHeader) {
				if (preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#i', (string)$responseHeader, $match)) {
					$status = (int)$match[1];
				}
			}
			yield ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($body) ? $body : ''];
		}
	}
}
