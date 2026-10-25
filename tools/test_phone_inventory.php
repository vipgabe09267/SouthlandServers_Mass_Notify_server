<?php
declare(strict_types=1);

require dirname(__DIR__) . '/slsmassnotifyserver/PhoneInventory.php';

use SLS\MassNotify\PhoneInventory;

$root = sys_get_temp_dir() . '/sls-phone-display-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || !chmod($root, 0700)) {
    throw new RuntimeException('Unable to create the private phone-inventory fixture.');
}
$path = $root . '/inventory.json';
$now = 1800000000;
$checks = 0;

function check_inventory(bool $condition): void
{
    ++$GLOBALS['checks'];
    if (!$condition) {
        throw new RuntimeException('Phone display snapshot validation failed.');
    }
}

function write_inventory_fixture(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $encoded) !== strlen($encoded) || !chmod($path, 0640)) {
        throw new RuntimeException('Unable to write the phone-inventory fixture.');
    }
    clearstatcache(true, $path);
}

function write_raw_inventory_fixture(string $path, string $value): void
{
    if (file_put_contents($path, $value) !== strlen($value) || !chmod($path, 0640)) {
        throw new RuntimeException('Unable to write the raw phone-inventory fixture.');
    }
    clearstatcache(true, $path);
}

$value = [
    'schema' => 1,
    'display_only' => true,
    'observed_at' => gmdate('c', $now),
    'expires_at' => $now + 90,
    'endpoints' => [
        '1000' => [
            'format' => 'yealink',
            'formats' => ['yealink', 'cisco'],
            'user_agent' => 'Mixed devices',
            'override' => false,
            'contacts' => [
                ['format' => 'yealink', 'detected_format' => 'yealink', 'user_agent' => 'Yealink T48G', 'status' => 'Avail', 'device_key' => str_repeat('a', 32), 'override' => false, 'capability' => 'vendor_template', 'contact' => 'sips:1000@192.0.2.10;transport=tls'],
                ['format' => 'cisco', 'detected_format' => 'cisco', 'user_agent' => 'Cisco', 'status' => 'Avail', 'device_key' => str_repeat('b', 32), 'override' => false, 'capability' => 'vendor_template', 'transport' => 'tcp'],
            ],
        ],
    ],
];

try {
    check_inventory(!PhoneInventory::read($path, $now)['available']);
    write_inventory_fixture($path, $value);
    check_inventory(PhoneInventory::read($path, $now)['available']);
    $contacts = PhoneInventory::read($path, $now)['endpoints'][1000]['contacts'];
    check_inventory(count($contacts) === 2);
    check_inventory($contacts[0]['format'] === 'yealink' && $contacts[0]['transport'] === 'tls');
    check_inventory($contacts[1]['format'] === 'cisco' && $contacts[1]['transport'] === 'tcp');
    check_inventory(!array_key_exists('contact', $contacts[0]));

    $fractional = $value;
    $fractional['observed_at'] = gmdate('Y-m-d\TH:i:s', $now) . '.123456+00:00';
    $fractional['expires_at'] = $now + 90.5;
    write_inventory_fixture($path, $fractional);
    check_inventory(PhoneInventory::read($path, $now)['available']);
    write_inventory_fixture($path, $value);
    check_inventory(!PhoneInventory::read($path, $now + 90)['available']);

    foreach ([[], true, 123, null] as $invalidTransport) {
        $invalid = $value;
        $invalid['endpoints'][1000]['contacts'][0]['transport'] = $invalidTransport;
        write_inventory_fixture($path, $invalid);
        check_inventory(!PhoneInventory::read($path, $now)['available']);
    }

    foreach ([
        ['expires_at' => NAN],
        ['expires_at' => $now + 1000],
        ['observed_at' => gmdate('c', $now + 10)],
        ['display_only' => false],
        ['expires_at' => (string) ($now + 90)],
        ['observed_at' => 'tomorrow'],
        ['available' => false],
        ['endpoints' => ['bad' => $value['endpoints'][1000]]],
        ['endpoints' => ['1000' => ['contacts' => 'bad']]],
        ['endpoints' => ['1000' => array_replace($value['endpoints'][1000], ['formats' => ['yealink', 'yealink']])]],
        ['endpoints' => ['1000' => array_replace($value['endpoints'][1000], ['format' => 'fanvil'])]],
        ['endpoints' => ['1000' => array_replace($value['endpoints'][1000], ['user_agent' => "bad\nagent"])]],
    ] as $patch) {
        if (isset($patch['expires_at']) && is_float($patch['expires_at']) && is_nan($patch['expires_at'])) {
            write_raw_inventory_fixture($path, '{"expires_at":NaN}');
        } else {
            write_inventory_fixture($path, array_replace($value, $patch));
        }
        check_inventory(!PhoneInventory::read($path, $now)['available']);
    }

    write_raw_inventory_fixture($path, '{"schema":1,"schema":1,"display_only":true,"observed_at":"' . gmdate('c', $now) . '","expires_at":' . ($now + 90) . ',"endpoints":{}}');
    check_inventory(!PhoneInventory::read($path, $now)['available']);

    write_inventory_fixture($path, $value);
    if (!chmod($path, 0644)) {
        throw new RuntimeException('Unable to make the fixture publicly readable.');
    }
    clearstatcache(true, $path);
    check_inventory(!PhoneInventory::read($path, $now)['available']);

    if (!chmod($path, 0660)) {
        throw new RuntimeException('Unable to make the fixture group-writable.');
    }
    clearstatcache(true, $path);
    check_inventory(!PhoneInventory::read($path, $now)['available']);

    if (!chmod($path, 0640) || !link($path, $root . '/hardlink')) {
        throw new RuntimeException('Unable to create the inventory hard-link fixture.');
    }
    clearstatcache(true, $path);
    check_inventory(!PhoneInventory::read($path, $now)['available']);
    if (!unlink($root . '/hardlink') || !rename($path, $root . '/target') || !symlink($root . '/target', $path)) {
        throw new RuntimeException('Unable to create the inventory symbolic-link fixture.');
    }
    clearstatcache(true, $path);
    check_inventory(!PhoneInventory::read($path, $now)['available']);
} finally {
    $entries = scandir($root);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($root . '/' . $entry);
            }
        }
    }
    @rmdir($root);
}

echo "Phone display snapshots: {$checks} availability, mixed-device, freshness and filesystem checks passed.\n";
