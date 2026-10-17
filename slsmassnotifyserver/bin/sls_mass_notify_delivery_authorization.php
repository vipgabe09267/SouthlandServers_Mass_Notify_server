#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
require_once '/var/www/html/admin/modules/slsmassnotifyserver/DeliveryAuthorization.php';
try {
    if ($argc !== 1) { throw new RuntimeException('Unexpected arguments.'); }
    $raw = stream_get_contents(STDIN, 16385);
    if (!is_string($raw)) { throw new RuntimeException('Invalid authorization input.'); }
    $input = \SLS\MassNotify\DeliveryAuthorization::parseInput($raw);
    $settings = \SLS\MassNotify\DeliveryAuthorization::settings();
    if (is_array($input) && in_array($input['api_credential_id'] ?? '', array_column($settings['operator_access']['accounts'] ?? [], 'id'), true)) {
        // Local operator jobs recheck the actual PBX account, not just a name
        // cached in .config. Ordinary automation/API retries avoid this bootstrap.
        require_once '/etc/freepbx.conf';
        require_once '/var/www/html/admin/libraries/ampuser.class.php';
    }
    $allowed = \SLS\MassNotify\DeliveryAuthorization::permits($settings, $input);
} catch (Throwable $error) { $allowed = false; }
echo json_encode(['allowed' => $allowed]), "\n";
exit($allowed ? 0 : 1);
