<?php
namespace FreePBX\modules;

/** Explicit advertised addresses; never follow request headers or change DNS. */
final class SlsAdvertisedAddress
{
    public static function host($value): string
    {
        if (!is_string($value)) { throw new \DomainException(_('Enter the PBX hostname without a URL, path, or port.')); }
        $host = strtolower(trim($value));
        if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)) {
            throw new \DomainException(_('Enter a valid PBX DNS hostname or IPv4 address without a URL, path, or port.'));
        }
        if (preg_match('/^[0-9.]+$/D', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \DomainException(_('The advertised IPv4 address is invalid.'));
        }
        return $host;
    }

    public static function port($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,5}$/D', (string)$value)
            || (int)$value < 1 || (int)$value > 65535) {
            throw new \DomainException(_('Advertised ports must be whole numbers from 1 through 65535.'));
        }
        return (int)$value;
    }

    public static function savedPort($url, string $host, string $path, int $fallback): int
    {
        if (is_string($url) && preg_match('#\Ahttps?://' . preg_quote($host, '#') . '(?::([0-9]{1,5}))?' . preg_quote($path, '#') . '/?\z#i', $url, $match)) {
            if (!isset($match[1]) || $match[1] === '') { return stripos($url, 'https:') === 0 ? 443 : 80; }
            $port = (int)$match[1];
            if ($port >= 1 && $port <= 65535) { return $port; }
        }
        return $fallback;
    }

    public static function url(string $scheme, string $host, int $port, string $path): string
    {
        return $scheme . '://' . $host . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port) . $path;
    }

    public static function fields(array $settings): array
    {
        $host = (string)($settings['public_pbx_host'] ?? $settings['sipnotify']['pbx_host'] ?? '');
        $scheme = ($settings['sipnotify']['media_scheme'] ?? 'https') === 'https' ? 'https' : 'http';
        $port = self::savedPort($settings['control_api']['base_url'] ?? '', $host, '/api/sls-mass-notify', 443);
        return ['host' => $host,
            'api_port' => self::savedPort($settings['sipnotify']['base_url'] ?? '', $host, '/api/sipnotify', $port),
            'control_port' => $port,
            'media_port' => self::savedPort($settings['sipnotify']['media_base_url'] ?? '', $host, '/sls_mass_notify', $scheme === 'https' ? 443 : 80),
            'media_scheme' => $scheme];
    }

    public static function migrate(array $settings, array $input): array
    {
        $host = self::host($input['advertised_pbx_host'] ?? null);
        $port = self::port($input['advertised_api_port'] ?? null);
        $controlPort = self::port($input['advertised_control_port'] ?? null);
        $mediaPort = self::port($input['advertised_media_port'] ?? null);
        $scheme = $input['sipnotify_media_scheme'] ?? ($settings['sipnotify']['media_scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) { throw new \DomainException(_('Select HTTP or HTTPS for phone images.')); }
        $settings['public_pbx_host'] = $host;
        $settings['sipnotify']['pbx_host'] = $host;
        $settings['sipnotify']['base_url'] = self::url('https', $host, $port, '/api/sipnotify');
        $settings['sipnotify']['media_scheme'] = $scheme;
        $settings['sipnotify']['media_base_url'] = self::url($scheme, $host, $mediaPort, '/sls_mass_notify');
        $settings['control_api']['base_url'] = self::url('https', $host, $controlPort, '/api/sls-mass-notify');
        return $settings;
    }

    public static function preview(array $settings): array
    {
        return ['desktop_url' => $settings['sipnotify']['base_url'] . '/desktop',
            'stream_url' => $settings['sipnotify']['base_url'] . '/desktop/stream',
            'control_url' => $settings['control_api']['base_url'],
            'media_url' => $settings['sipnotify']['media_base_url']];
    }

    /** Local-origin certificate/API check only; no credentials, redirects or public connections. */
    public static function checkLocalHttps(array $settings): array
    {
        if (!function_exists('curl_init') || !defined('CURLOPT_CONNECT_TO')) {
            return ['ok' => false, 'message' => _('The PHP cURL HTTPS probe is unavailable. Check the advertised address from a desktop before applying it.')];
        }
        $fields = self::fields($settings); $host = self::host($fields['host']);
        $url = self::url('https', $host, $fields['api_port'], '/api/sipnotify/desktop');
        $targets = SlsLocalWebProbe::targets($host);
        $deadline = microtime(true) + 6; $seen = []; $codes = [];
        foreach ($targets as $target) {
            $parts = parse_url($target['connect_url']);
            if (($parts['scheme'] ?? '') !== 'https') { continue; }
            $address = trim((string)($parts['host'] ?? ''), '[]'); $port = (int)($parts['port'] ?? 0);
            if (!in_array($address, ['127.0.0.1', '::1'], true) || $port < 1 || $port > 65535) { continue; }
            $key = $address . ':' . $port;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true; $remaining = (int)(($deadline - microtime(true)) * 1000);
            if ($remaining < 100) { break; }
            $body = ''; $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_CONNECT_TO => [$host . ':' . $fields['api_port'] . ':' . ($address === '::1' ? '[::1]' : $address) . ':' . $port],
                CURLOPT_NOPROXY => '*', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(500, $remaining), CURLOPT_TIMEOUT_MS => min(1500, $remaining),
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                    if (strlen($body) + strlen($chunk) > 8192) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            $readCertificate = defined('CURLOPT_CERTINFO') && defined('CURLINFO_CERTINFO') && function_exists('openssl_x509_parse');
            if ($readCertificate) { curl_setopt($curl, CURLOPT_CERTINFO, true); }
            curl_exec($curl); $error = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $certificate = $readCertificate ? curl_getinfo($curl, CURLINFO_CERTINFO) : [];
            curl_close($curl);
            $codes[] = $error; $payload = json_decode($body, true);
            if ($error === 0 && $status === 401 && is_array($payload) && ($payload['error'] ?? '') === 'unauthorized') {
                $leaf = is_array($certificate) ? ($certificate[0]['Cert'] ?? '') : '';
                $details = is_string($leaf) && $leaf !== '' && strlen($leaf) <= 65536 ? @openssl_x509_parse($leaf) : false;
                return ['ok' => true, 'certificate_expires_at' => is_array($details) && is_int($details['validTo_time_t'] ?? null) ? $details['validTo_time_t'] : null,
                    'message' => _('The local PBX presents a trusted certificate for this hostname and serves the desktop API. Check the advertised port from a desktop to verify external routing.')];
            }
        }
        return ['ok' => false, 'message' => in_array(60, $codes, true)
            ? _('The local PBX certificate is not trusted for this hostname. Check its certificate before applying. A separate HTTPS proxy may require testing from a desktop instead.')
            : _('The local HTTPS origin could not confirm this desktop API address. Check the PBX HTTPS listener and certificate, or test from a desktop if an external proxy terminates HTTPS.')];
    }
}

trait SlsAdvertisedAddressEditor
{
    public function checkAdvertisedAddress(array $input): array
    {
        try {
            $settings = SlsAdvertisedAddress::migrate($this->getPendingSettings() ?? $this->getActiveSettings(), $input);
            return ['success' => true, 'preview' => SlsAdvertisedAddress::preview($settings), 'connection' => SlsAdvertisedAddress::checkLocalHttps($settings)];
        } catch (\DomainException $error) {
            return ['success' => false, 'message' => $error->getMessage()];
        }
    }
}
