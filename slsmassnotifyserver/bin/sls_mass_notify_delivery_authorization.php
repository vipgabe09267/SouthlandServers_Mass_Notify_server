#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
require_once '/var/www/html/admin/modules/slsmassnotifyserver/DeliveryAuthorization.php';
try {
    if ($argc !== 1) { throw new RuntimeException('Unexpected arguments.'); }
    $raw = stream_get_contents(STDIN, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) { throw new RuntimeException('Invalid authorization input.'); }
    $input = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    $settings = \SLS\MassNotify\DeliveryAuthorization::settings();
    if (is_array($input) && in_array($input['api_credential_id'] ?? '', array_column($settings['operator_access']['accounts'] ?? [], 'id'), true)) {
        // Local operator jobs recheck the actual PBX account, not just a name
        // cached in .config. Ordinary automation/API retries avoid this bootstrap.
        require_once '/etc/freepbx.conf';
        require_once '/var/www/html/admin/libraries/ampuser.class.php';
    }
    $allowed = is_array($input) && !array_is_list($input) && \SLS\MassNotify\DeliveryAuthorization::permits(
        $settings, $input);
} catch (Throwable $error) { $allowed = false; }
echo json_encode(['allowed' => $allowed]), "\n";
exit($allowed ? 0 : 1);
