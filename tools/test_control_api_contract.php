<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/api/sls-mass-notify/contract.php';
use SLS\MassNotify\{ApiSecurity, ControlContract};
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    $checks++; if (!$ok) { throw new RuntimeException($message); }
};
$principal = ApiSecurity::issue(['name' => 'Fixture', 'scopes' => ['read', 'send'],
    'audience' => ['unrestricted' => false, 'announcement_group_ids' => ['group_a']]])['credential'];
unset($principal['secret_hash']);
$settings = ['desktop_clients' => [['client_id' => 'desktop_a', 'username' => 'alice', 'name' => 'Alice', 'enabled' => true]],
    'announcement_groups' => [['id' => 'group_a', 'name' => str_repeat('é', 150), 'extensions' => ['1000'], 'desktop_clients' => ['alice'],
        'location_snapshot' => ['schema' => 1, 'desktop_bindings' => [['client_id' => 'desktop_a', 'username' => 'alice']]]]],
    'announcement_webhooks' => [['id' => 'hook_a', 'name' => 'Hook', 'enabled' => true, 'url' => 'https://private.example.com/secret']]];
$audience = ControlContract::audiences($principal, $settings);
$check($audience['phone_targets'] === ['1000'] && count($audience['desktop_clients']) === 1, 'Permitted saved audiences were not expanded.');
$check(preg_match('//u', $audience['announcement_groups'][0]['name']) === 1, 'A projected label was cut inside a UTF-8 character.');
$check($audience['webhooks'] === [] && !str_contains(json_encode($audience), 'https://'), 'Discovery disclosed an unpermitted webhook or address.');
$settings['desktop_clients'][0]['client_id'] = 'replacement';
$changed = ControlContract::audiences($principal, $settings);
$check($changed['announcement_groups'] === [] && $changed['desktop_clients'] === [] && $changed['phone_targets'] === [], 'A stale desktop binding authorized discovery of a changed saved audience.');
$restricted = ControlContract::capabilities($principal);
$check(!in_array('events', $restricted['resources'], true), 'Restricted discovery offered unrestricted audit history.');
$check(!in_array('start_incident', array_column($restricted['actions'], 'action'), true), 'Restricted discovery offered incident mutation.');
$principal['audience']['unrestricted'] = true;
$principal['scopes'][] = 'config';
$check(in_array('start_incident', array_column(ControlContract::capabilities($principal)['actions'], 'action'), true), 'Named unrestricted incident sender is missing discovery.');
$principal['id'] = 'legacy';
$check(!in_array('start_incident', array_column(ControlContract::capabilities($principal)['actions'], 'action'), true), 'Discovery offered incident mutation to a legacy key.');
$input = ControlContract::announcementInput(['action' => 'preview_announcement', 'message' => 'Fixture', 'is_test' => true,
    'webhook_ids' => ['hook_a'], 'tts_volume' => 100, 'options' => ['preview' => false]]);
$check($input['options']['preview'] === true && $input['options']['_is_test'] === true, 'Preview or explicit test metadata was not normalized.');
$check($input['options']['webhook_ids'] === ['hook_a'] && !isset($input['webhook_ids']), 'Top-level webhook selection was not routed to the resolver.');
$input = ControlContract::announcementInput(['action' => 'send_announcement', 'message' => 'Fixture', 'is_test' => false, 'options' => ['is_test' => true]]);
$check($input['options']['_is_test'] === false, 'Top-level explicit false did not take precedence over an option.');
foreach ([['options' => ['_test_channels' => ['sms']]], ['options' => ['_is_test' => true]], ['is_test' => 'true'],
    ['preview' => 1], ['webhook_ids' => [false]], ['webhook_ids' => array_fill(0, 11, 'hook')],
    ['tts_volume' => 0], ['tts_volume' => 201], ['tts_volume' => '100'], ['piper_voice' => []], ['undocumented' => true]] as $invalid) {
    try { ControlContract::announcementInput(['action' => 'send_announcement', 'message' => 'Fixture'] + $invalid); }
    catch (InvalidArgumentException $expected) { $checks++; continue; }
    throw new RuntimeException('An internal option or malformed announcement field was accepted.');
}
echo "Control API discovery, recipient isolation, stale identities, preview and explicit test metadata: $checks checks passed.\n";
