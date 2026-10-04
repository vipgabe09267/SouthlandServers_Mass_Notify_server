<?php
declare(strict_types=1);
require __DIR__ . '/test_locations.php';

$audienceSettings = $base;
$audienceSettings['announcement_groups'] = [];
$audienceSettings['announcement_webhooks'] = [['id' => 'shared_hook', 'name' => 'Everyone', 'enabled' => '1',
    'url' => 'https://example.com/private-url', 'bearer_token' => 'do-not-project']];
$module = new LocationFixture($audienceSettings);
$state = $module->getLocationDirectoryState();
location_check(!str_contains(json_encode($state), 'private-url') && !str_contains(json_encode($state), 'do-not-project'), 'Audience catalog exposed webhook credentials.');
$request = ['revision' => $state['revision'], 'id' => '', 'name' => 'Gabe Williams',
    'members' => ['extensions' => ['1000'], 'desktop_client_ids' => ['cli_old'], 'webhook_ids' => ['shared_hook']]];
$result = $module->saveLocationAudience($request);
location_check($result['success'] && $module->active === $audienceSettings && $module->writes === 1, 'Person audience was not safely staged.');
$person = $module->pending['announcement_groups'][0];
location_check($person['desktop_bindings'] === [['username' => 'desktop', 'client_id' => 'cli_old']] && $person['webhook_ids'] === ['shared_hook'], 'Person audience lost device identity or webhook.');
location_check(!$module->saveLocationAudience($request)['success'] && $module->writes === 1, 'Stale/retried creation duplicated a person.');
$shared = ['revision' => $result['state']['revision'], 'id' => '', 'name' => 'Everyone', 'members' => ['webhook_ids' => ['shared_hook']]];
$everyone = $module->saveLocationAudience($shared);
location_check($everyone['success'] && count($module->pending['announcement_groups']) === 2, 'A shared webhook-only audience could not be saved.');
$personCard = $everyone['state']['audiences'][0];
location_check($personCard['members']['desktop_client_ids'] === ['cli_old'] && $personCard['identities_current'], 'Saved card lost stable desktop identities.');
$module->pending['desktop_clients'][0]['client_id'] = 'cli_replaced';
$state = $module->getLocationDirectoryState();
location_check(!$state['audiences'][0]['identities_current'] && $state['audiences'][0]['members']['desktop_client_ids'] === ['cli_old'], 'An audience silently rebound to a replacement desktop.');
location_check(!$module->saveLocationAudience(['revision' => $state['revision'], 'id' => $person['id'], 'name' => 'Changed', 'members' => $personCard['members']])['success'], 'Removed desktop was accepted by the editor.');
$module->pending['desktop_clients'][0]['client_id'] = 'cli_old';
$state = $module->getLocationDirectoryState();
$edit = $module->saveLocationAudience(['revision' => $state['revision'], 'id' => $person['id'], 'name' => 'Office devices', 'members' => ['extensions' => ['2000'], 'webhook_ids' => ['shared_hook']]]);
location_check($edit['success'] && count($module->pending['announcement_groups']) === 2 && $module->pending['announcement_groups'][0]['extensions'] === ['2000'], 'Editing a person duplicated or misrouted the audience.');
$module->pending['scheduled_announcements'] = [['targets' => ['groups' => [$person['id']]]]];
location_check(!$module->deleteLocationAudience(['revision' => $module->getLocationDirectoryState()['revision'], 'id' => $person['id']])['success'], 'An audience referenced by a schedule was removed.');
$module->pending['scheduled_announcements'] = [];
location_check($module->deleteLocationAudience(['revision' => $module->getLocationDirectoryState()['revision'], 'id' => $person['id']])['success'], 'An unused audience could not be removed.');
location_check(!\SLS\MassNotify\ApiSecurity::groupDesktopBindingsValid($person, ['desktop_clients' => [['username' => 'desktop', 'client_id' => 'other', 'enabled' => '1']]]), 'Control API accepted a rebound person audience.');
$sourceDirectory = \SLS\MassNotify\LocationDirectory::normalize(['nodes' => [
    location_node('1', 'site', '', ['webhook_ids' => ['shared_hook']]), location_node('2', 'site', '', ['webhook_ids' => ['shared_hook']])]]);
location_check(count($sourceDirectory['nodes']) === 2, 'A shared webhook could not belong to two sites.');

require __DIR__ . '/test_announcement_email_facade.php';
mkdir($directory, 0700);
try {
    $m->settings = $base; $m->pending = null; $m->resolved = [];
    $m->settings['announcement_webhooks'] = [['id' => 'shared_hook', 'name' => 'Everyone', 'enabled' => '1', 'url' => 'https://example.com/notify', 'payload_format' => 'native']];
    $saved = $m->saveAnnouncementGroup('', 'Shared webhook', [], [], [], [], [], ['shared_hook']);
    $check($saved['success'], 'Dashboard could not save webhook-only audience');
    $groupId = $saved['groups'][0]['id'];
    $sent = $m->sendSipNotifyAnnouncement([], 'Fixture only', false, false, [$groupId], ['audio_mode' => 'none', 'webhook_ids' => ['shared_hook']]);
    $check($sent['success'] && end($m->resolved)['webhooks'] === ['shared_hook'], 'Group plus explicit webhook duplicated or lost a destination');
    $principal = ['scopes' => ['send'], 'audience' => ['unrestricted' => false, 'announcement_group_ids' => [$groupId]]];
    $check(\SLS\MassNotify\ApiSecurity::allowedAudience($principal, $m->settings)['webhooks'] === ['shared_hook'], 'Scoped group failed to authorize its webhook');
    $m->settings['announcement_webhooks'][0]['enabled'] = '0'; $before = count($m->resolved);
    $sent = $m->sendSipNotifyAnnouncement([], 'Must not submit', false, false, [$groupId], ['audio_mode' => 'none']);
    $check(!$sent['success'] && empty($sent['delivery_started']) && count($m->resolved) === $before, 'Disabled group webhook was silently dropped or sent');
    foreach ([null, ['bad/id'], ['shared_hook', 'shared_hook'], array_fill(0, 11, 'shared_hook')] as $bad) {
        $check($call('validateAudienceWebhookIds', $bad) !== [], 'Invalid webhook membership was accepted');
    }
    $m->settings['announcement_webhooks'][0]['enabled'] = '1';
    $future = gmdate('Y-m-d\TH:i', time() + 3600);
    $scheduleResult = $m->saveScheduledAnnouncement(['schedule_name' => 'Webhook check', 'schedule_enabled' => '1',
        'schedule_message' => 'Fixture only', 'schedule_audio_mode' => 'none', 'schedule_occurrences' => [$future], 'schedule_webhook_ids' => ['shared_hook']]);
    $check($scheduleResult['success'], 'Webhook-only schedule could not be saved: ' . json_encode($scheduleResult['errors'] ?? []));
    $check($m->settings['scheduled_announcements'][0]['targets']['webhook_ids'] === ['shared_hook'], 'Schedule normalization lost its exact webhook');
    $m->settings['outbound_voice']['daily_call_limit'] = 99;
    $before = $m->settings;
    $recipientRequest = ['revision' => $m->getLocationDirectoryState()['revision'],
        'voice_json' => json_encode([['id' => '', 'name' => 'Fixture call', 'number' => '+15555550100', 'enabled' => '1']]),
        'email_json' => json_encode([['id' => '', 'name' => 'Fixture email', 'address' => 'directory@example.com', 'enabled' => '1']]),
        'sms_json' => json_encode([['id' => '', 'name' => 'Fixture SMS', 'number' => '+15555550101', 'enabled' => true, 'consent' => true, 'consent_note' => 'Fixture consent']])];
    $recipients = $m->saveLocationRecipients($recipientRequest);
    $check($recipients['success'] && $m->settings === $before && count($m->pending['announcement_sms']['recipients']) === 1, 'Directory recipient save failed or changed active settings');
    foreach (['outbound_voice','announcement_email','announcement_sms'] as $channel) {
        $oldPolicy = $before[$channel]; $newPolicy = $m->pending[$channel]; unset($oldPolicy['recipients'], $newPolicy['recipients']);
        $check($oldPolicy === $newPolicy, 'Recipient save changed provider policy or credentials');
    }
    $check(!$m->saveLocationRecipients($recipientRequest)['success'], 'Stale recipient form overwrote newer identities');
    $consented = $m->pending['announcement_sms']['recipients'][0];
    $current = ['revision' => $m->getLocationDirectoryState()['revision'], 'voice_json' => json_encode($m->pending['outbound_voice']['recipients']),
        'email_json' => json_encode($m->pending['announcement_email']['recipients']), 'sms_json' => json_encode([$consented])];
    $check($m->saveLocationRecipients($current)['success'] && $m->pending['announcement_sms']['recipients'][0] === $consented, 'Saving unchanged recipients changed their ID or consent time');
    $renewed = $consented; $renewed['renew_consent'] = true;
    $check(!$m->saveLocationRecipients(array_replace($current, ['revision' => $m->getLocationDirectoryState()['revision'], 'sms_json' => json_encode([$renewed])]))['success'], 'Consent renewal without a new note was accepted');
    $check(!$m->saveLocationRecipients(array_replace($current, ['revision' => $m->getLocationDirectoryState()['revision'], 'voice_json' => '{}']))['success'], 'Incomplete voice list replaced recipients');
    echo "Saved audiences: default site, device identity, shared webhooks, edits, pending isolation, scope and scheduled routing passed.\n";
} finally { foreach (glob($directory . '/*') ?: [] as $path) { if (is_file($path)) unlink($path); } rmdir($directory); }
