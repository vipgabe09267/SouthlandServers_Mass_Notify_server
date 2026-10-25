<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/media-policy.php';

// Authentication and phone capabilities are opt-in. Requests never select a filesystem path.
$path = rawurldecode(explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
$file = str_starts_with($path, '/sls_mass_notify/') ? substr($path, strlen('/sls_mass_notify/')) : ($_GET['file'] ?? null);
$result = \SLS\MassNotify\MediaAccess::response($_SERVER, $file,
    '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config', '/var/www/html/sls_mass_notify', null,
    ['expires'=>$_GET['sls_exp']??null,'signature'=>$_GET['sls_sig']??null,'generation'=>$_GET['sls_gen']??null]);
http_response_code($result['status']);
header('Content-Type: ' . $result['type']);
header('Content-Length: ' . strlen($result['body']));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-SLS-Media-Policy: 1');
if ($result['status'] === 405) { header('Allow: GET, HEAD'); }
if ($result['status'] === 503) { header('Retry-After: 5'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') { echo $result['body']; }
