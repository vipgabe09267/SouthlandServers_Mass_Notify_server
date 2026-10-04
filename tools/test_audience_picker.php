<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/AudiencePicker.php';
use SLS\MassNotify\{AudiencePicker,LocationDirectory};
$checks = 0;
function pickerCheck(bool $ok, string $detail): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($detail); }
$site = LocationDirectory::DEFAULT_SITE_ID; $desktop = 'cli_office'; $email = 'email_' . str_repeat('a', 24);
$group = 'grp_' . str_repeat('b', 24); $voice = 'voice_' . str_repeat('c', 24); $sms = 'sms_' . str_repeat('d', 24);
$settings = ['desktop_clients' => [['client_id' => $desktop, 'username' => 'office', 'enabled' => '1', 'password_enc' => 'private-secret']],
    'announcement_email' => ['recipients' => [['id' => $email, 'address' => 'office@example.com', 'enabled' => '1']]],
    'announcement_webhooks' => [['id' => 'shared', 'name' => 'Shared', 'url' => 'https://example.com/private-route', 'enabled' => '1', 'bearer_token' => 'secret-token']],
    'generic_webhooks' => [['id' => 'weather', 'url' => 'https://example.com/private-route', 'enabled' => '1']],
    'announcement_groups' => [['id' => $group, 'name' => 'Office devices', 'extensions' => ['1000'], 'desktop_clients' => ['office'],
        'email_recipient_ids' => [$email], 'voice_recipient_ids' => [$voice], 'sms_recipient_ids' => [$sms], 'webhook_ids' => ['shared'],
        'desktop_bindings' => [['client_id' => $desktop, 'username' => 'office']]]],
    'location_directory' => ['nodes' => [LocationDirectory::node(['id' => $site, 'type' => 'site', 'name' => 'Campus',
        'members' => ['extensions' => ['1000'], 'desktop_client_ids' => [$desktop], 'email_recipient_ids' => [$email], 'webhook_ids' => ['shared']]])]]];
$choices = AudiencePicker::choices($settings);
$encoded = json_encode($choices);
foreach (['private-secret', 'secret-token', 'private-route', 'office@example.com'] as $secret) pickerCheck(!str_contains($encoded, $secret), 'Default producer projection exposed a credential or raw email.');
pickerCheck(count($choices) === 2 && $choices[0]['kind'] === 'Locations' && $choices[1]['kind'] === 'Saved audiences', 'Site and person selectors were not both projected.');
pickerCheck($choices[0]['members']['desktop_clients'] === ['office'] && $choices[1]['members']['voice_recipient_ids'] === [$voice], 'Recipient projection altered device membership.');
pickerCheck($choices[1]['members']['sms_recipient_ids'] === [$sms] && $choices[1]['members']['webhook_ids'] === ['shared'], 'SMS or shared webhook identity was dropped.');
$weather = AudiencePicker::choices($settings, true);
pickerCheck($weather[0]['members']['email_addresses'] === ['office@example.com'] && $weather[0]['members']['generic_webhook_ids'] === ['weather'], 'Weather picker lost saved email or equivalent webhook routing.');
pickerCheck($weather[0]['weather_unmapped_webhooks'] === 0, 'Mapped webhook was marked unsupported.');
$settings['generic_webhooks'][0]['payload_format'] = 'slack';
pickerCheck(AudiencePicker::choices($settings, true)[0]['weather_unmapped_webhooks'] === 1, 'A different webhook format was treated as the same destination.');
$settings['desktop_clients'][0]['client_id'] = 'cli_replacement';
$changed = AudiencePicker::choices($settings);
pickerCheck($changed[1]['identity_error'] && $changed[0]['unavailable'], 'Replacement desktop identity was silently copied.');
$empty = AudiencePicker::choices([]);
pickerCheck(count($empty) === 1 && $empty[0]['id'] === 'location:' . $site && $empty[0]['members']['extensions'] === [], 'Default site selected unassigned devices.');
echo "Producer audience projection: $checks identity, channel mapping, default-site and secret-projection checks passed.\n";
