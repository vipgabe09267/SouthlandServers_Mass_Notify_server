<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/OperatorAccess.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/IncidentConfig.php';
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/contract.php';

use SLS\MassNotify\ApiSecurity;
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\LocationDirectory;
use SLS\MassNotify\OperatorAccess;

function audienceCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$browser = 'sub_' . str_repeat('a', 24);
$connector = 'conn_' . str_repeat('b', 24);
$settings = [
    'subscriber_browser' => [
        'enabled' => true,
        'push' => ['enabled' => true],
        'people' => [[
            'id' => $browser,
            'person_id' => 'fixture-person',
            'name' => 'Fixture browser',
            'email' => 'fixture@example.test',
            'enabled' => true,
            'version' => str_repeat('c', 64),
            'registered_at' => 1000,
        ]],
    ],
    'enterprise_integrations' => [
        'enabled' => '1',
        'connectors' => [
            'enabled' => '1',
            'destinations' => [[
                'id' => $connector,
                'name' => 'Fixture connector',
                'provider' => 'ntfy',
                'origin' => 'https://notify.example.test',
                'pinned_ipv4' => '192.168.1.20',
                'token' => 'synthetic-token',
                'topic' => 'fixture',
                'enabled' => '1',
            ]],
        ],
    ],
];
$members = [
    'browser_recipient_ids' => [$browser],
    'connector_ids' => [$connector],
];
$principal = [
    'id' => 'fixture-named',
    'scopes' => ['read', 'send'],
    'audience' => ['unrestricted' => false] + $members,
    'allowed_channels' => ['browser_recipient_ids', 'connector_ids'],
];
$request = [
    'phones' => [],
    'desktops' => [],
    'webhooks' => [],
    'voice_recipient_ids' => [],
    'email_recipient_ids' => [],
    'sms_recipient_ids' => [],
] + $members;

audienceCheck(
    ApiSecurity::permitsResolvedAnnouncement($principal, $request, $settings),
    'Explicit named Labs permissions were rejected.'
);
$discovery = \SLS\MassNotify\ControlContract::audiences($principal, $settings);
audienceCheck(
    array_column($discovery['browser_recipients'], 'id') === [$browser]
        && array_column($discovery['connectors'], 'id') === [$connector],
    'Restricted discovery omitted authorized Labs recipients.'
);
$encodedDiscovery = json_encode($discovery, JSON_THROW_ON_ERROR);
audienceCheck(
    !str_contains($encodedDiscovery, 'synthetic-token')
        && !str_contains($encodedDiscovery, 'fixture@example.test'),
    'Audience discovery exposed secrets or subscriber addresses.'
);

$other = $request;
$other['browser_recipient_ids'][] = 'sub_' . str_repeat('d', 24);
audienceCheck(
    !ApiSecurity::permitsResolvedAnnouncement($principal, $other, $settings),
    'Unassigned browser received permission.'
);
$withoutChannel = $principal;
$withoutChannel['allowed_channels'] = ['extensions'];
audienceCheck(
    !ApiSecurity::permitsResolvedAnnouncement($withoutChannel, $request, $settings),
    'Allowed-channel restriction was bypassed.'
);

$group = [
    'id' => 'grp_' . str_repeat('e', 24),
    'name' => 'Fixture audience',
    'desktop_clients' => [],
] + $members;
$group['labs_bindings'] = ApiSecurity::labsBindings($members, $settings);
audienceCheck(
    ApiSecurity::groupLabsBindingsValid($group, $settings),
    'Original enrollment bindings were invalid.'
);
$unbound = $group;
unset($unbound['labs_bindings']);
audienceCheck(
    !ApiSecurity::groupLabsBindingsValid($unbound, $settings),
    'Missing bindings adopted a current enrollment.'
);

$settings['announcement_groups'] = [$group];
$groupGrant = [
    'id' => 'fixture-group',
    'scopes' => ['send'],
    'audience' => [
        'unrestricted' => false,
        'announcement_group_ids' => [$group['id']],
    ],
    'allowed_channels' => ['browser_recipient_ids', 'connector_ids'],
];
audienceCheck(
    ApiSecurity::permitsResolvedAnnouncement($groupGrant, $request, $settings),
    'A bound saved audience did not grant its explicit recipients.'
);

$settings['subscriber_browser']['people'][0]['version'] = str_repeat('f', 64);
audienceCheck(
    !ApiSecurity::groupLabsBindingsValid($group, $settings),
    'Reenrollment retained the original saved audience binding.'
);
audienceCheck(
    !ApiSecurity::permitsResolvedAnnouncement($groupGrant, $request, $settings),
    'Stale saved audience granted replacement recipients.'
);
$settings['subscriber_browser']['people'][0]['version'] = str_repeat('c', 64);
$settings['enterprise_integrations']['connectors']['destinations'][0]['topic'] = 'changed';
audienceCheck(
    !ApiSecurity::groupLabsBindingsValid($group, $settings),
    'Changed connector topic retained a frozen audience.'
);
$settings['enterprise_integrations']['connectors']['destinations'][0]['topic'] = 'fixture';
$settings['subscriber_browser']['push']['enabled'] = false;
audienceCheck(
    !ApiSecurity::permitsResolvedAnnouncement($principal, $request, $settings),
    'Disabled browser push retained authorization.'
);

$node = LocationDirectory::node([
    'id' => LocationDirectory::DEFAULT_SITE_ID,
    'name' => 'Fixture site',
    'type' => 'site',
    'members' => $members,
]);
audienceCheck(
    $node['members']['browser_recipient_ids'] === [$browser]
        && $node['members']['connector_ids'] === [$connector],
    'Location members lost Labs identities.'
);
$account = [
    'id' => 'api_' . str_repeat('1', 24),
    'username' => 'fixture',
    'name' => 'Fixture',
    'role' => 'sender',
    'enabled' => true,
    'source' => 'database',
    'identity' => str_repeat('2', 64),
    'personal_members' => $members,
];
$legacy = OperatorAccess::normalize(['enabled' => true, 'accounts' => [$account]])['accounts'][0];
audienceCheck(
    !in_array('browser_recipient_ids', $legacy['channels'], true)
        && !in_array('connector_ids', $legacy['channels'], true),
    'An existing account implicitly gained new channel permissions.'
);
$normalized = IncidentConfig::delivery($members);
audienceCheck(
    IncidentConfig::hasAudience($normalized) && $normalized['connector_ids'] === [$connector],
    'Incident delivery omitted new audience channels.'
);
echo "Labs audiences: explicit grants, channel restrictions, frozen enrollment, revocation, locations and legacy defaults passed.\n";
