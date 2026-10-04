<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
use SLS\MassNotify\LocationDirectory as Directory;
use SLS\MassNotify\ApiSecurity;
$checks = 0;
function location_check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function location_reject(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException | DomainException $error) { location_check(true, $message); return; }
    throw new RuntimeException($message);
}
function location_node(string $letter, string $type, string $parent = '', array $members = []): array {
    return Directory::node(['id' => 'loc_' . str_repeat($letter, 24), 'type' => $type, 'parent_id' => $parent,
        'name' => ucfirst($type) . ' ' . $letter, 'members' => $members]);
}
$site = location_node('a', 'site'); $building = location_node('b', 'building', $site['id']);
$floor = location_node('c', 'floor', $building['id']);
$room = location_node('d', 'room', $floor['id'], ['extensions' => ['1000'], 'desktop_client_ids' => ['cli_old']]);
$second = location_node('e', 'site', '', ['extensions' => ['2000']]);
$directory = Directory::normalize(['nodes' => [$room, $floor, $building, $second, $site]]);
location_check(Directory::normalize($directory) === $directory, 'Directory normalization is not stable.');
location_check(Directory::path($directory, $room['id']) === 'Site a / Building b / Floor c / Room d', 'Location path lost hierarchy.');
location_check(count(Directory::selected($directory, $site['id'], true)) === 4, 'Descendant audience crossed a site boundary or omitted a room.');
location_check(count(Directory::selected($directory, $site['id'], false)) === 1, 'Exact-location selection included children.');
foreach ([null, 'data', ['schema' => 2], ['nodes' => null], ['nodes' => ['not-a-list' => $site]], ['unknown' => true], ['nodes' => [$site, $site]], ['nodes' => array_fill(0, 501, $site)]] as $bad) {
    location_reject(static fn() => Directory::normalize($bad), 'Invalid directory was accepted.');
}
foreach ([['id' => '../config'], ['type' => 'ward'], ['parent_id' => $room['id']], ['name' => "bad\0name"], ['name' => "\xff"], ['name' => str_repeat('é', 81)],
    ['members' => ['extensions' => [1000]]], ['members' => ['extensions' => ['1000', '1000']]], ['members' => ['desktop_client_ids' => ['secret/user']]],
    ['members' => ['email_recipient_ids' => ['raw@example.com']]], ['members' => ['extensions' => array_fill(0, 1001, '1000')]], ['notes' => 'unknown']] as $patch) {
    location_reject(static fn() => Directory::normalize(['nodes' => [array_replace($site, $patch)]]), 'Invalid node was accepted.');
}
foreach ([[$site, $room], [$site, array_replace($building, ['parent_id' => $room['id']]), $floor, $room],
    [$site, array_replace($second, ['name' => 'SITE A'])], [$site, $building, $floor, $room, array_replace($second, ['members' => $room['members']])]] as $bad) {
    location_reject(static fn() => Directory::normalize(['nodes' => $bad]), 'Invalid hierarchy or duplicate assignment accepted.');
}
$long = [];
for ($index = 0; $index < 500; $index++) {
    $row = $site; $row['id'] = 'loc_' . str_pad(dechex($index), 24, '0', STR_PAD_LEFT); $row['name'] = 'Site ' . $index;
    $row['description'] = str_repeat('é', 200); $long[] = $row;
}
location_reject(static fn() => Directory::normalize(['nodes' => $long]), 'Encoded directory size bound was not enforced.');

class LocationFixture {
    use \FreePBX\modules\SlsLocations;
    public array $active; public ?array $pending = null; public int $writes = 0; public bool $failWrite = false;
    public function __construct(array $settings) { $this->active = $settings; }
    private function getActiveSettings(): array { return $this->active; }
    private function getPendingSettings(): ?array { return $this->pending; }
    private function isSetupComplete(array $settings): bool { return true; }
    private function getSetupRequiredMessage(): string { return 'Complete setup.'; }
    protected function getAllPjsipExtensions(): array { return [['extension' => '1000', 'name' => 'Staff phone'], ['extension' => '2000', 'name' => 'Other site']]; }
    private function getOutboundVoiceRecipients(array $settings): array { return []; }
    private function getAnnouncementEmailRecipients(array $settings): array { return []; }
    private function getAnnouncementSmsRecipients(array $settings): array { return []; }
    private function persistPendingSettings(array $settings): void {
        if ($this->failWrite) { throw new RuntimeException('Fixture write failure with a secret that must not escape.'); }
        $this->pending = $settings; $this->writes++;
    }
}
$base = ['location_directory' => $directory, 'desktop_clients' => [['client_id' => 'cli_old', 'username' => 'desktop', 'name' => 'Front office', 'enabled' => '1', 'password_enc' => 'do-not-expose']],
    'announcement_groups' => [], 'scheduled_announcements' => [['id' => 'unrelated']], 'protected' => 'preserve',
    'outbound_voice' => ['recipients' => [['id' => 'voice_' . str_repeat('b', 24), 'name' => 'Disabled external', 'number' => '+15555550123', 'secret' => 'not-exposed']]]];
$empty = new LocationFixture(['announcement_groups' => []]);
$initial = $empty->getLocationDirectoryState();
location_check(count($initial['directory']['nodes']) === 1 && $initial['directory']['default_site_id'] === Directory::DEFAULT_SITE_ID && $empty->writes === 0, 'Opening an empty directory must show a default site without changing configuration.');
location_check(!$empty->deleteLocation(['revision' => $initial['revision'], 'id' => Directory::DEFAULT_SITE_ID])['success'], 'The last/default site was removable.');
$rename = $initial['directory']['nodes'][0]; $rename['name'] = 'Main campus';
location_check($empty->saveLocation(['revision' => $initial['revision'], 'node' => $rename])['success'] && $empty->pending['location_directory']['nodes'][0]['name'] === 'Main campus', 'Default site cannot be named and saved.');
$defaultFixture = new LocationFixture($base); $defaultState = $defaultFixture->getLocationDirectoryState();
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $room['id']])['success'], 'A room became the default site.');
location_check($defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $second['id']])['success'] && $defaultFixture->pending['location_directory']['default_site_id'] === $second['id'], 'Choosing another default site failed.');
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $site['id']])['success'], 'A stale default site edit was accepted.');
location_reject(static fn() => Directory::normalize(['nodes' => [$site], 'default_site_id' => $second['id']]), 'A missing default site was accepted.');
$m = new LocationFixture($base); $state = $m->getLocationDirectoryState();
location_check(!str_contains(json_encode($state), 'do-not-expose') && !str_contains(json_encode($state), 'not-exposed'), 'Catalog exposed protected fields.');
location_check($m->writes === 0, 'Read-only directory view wrote configuration.');
$input = ['revision' => $state['revision'], 'id' => $site['id'], 'include_descendants' => true, 'channels' => ['extensions', 'desktop_client_ids']];
$preview = $m->previewLocationAudience($input);
location_check($preview['success'] && $preview['preview']['members']['extensions'] === ['1000'] && $preview['preview']['members']['desktop_client_ids'] === ['cli_old'], 'Location preview selected the wrong site or identity.');
location_check($preview['preview']['unavailable'] === [] && $m->writes === 0, 'Preview changed configuration or invented unavailable recipients.');
$create = $input + ['name' => 'Reviewed north audience', 'preview_token' => $preview['preview']['token']];
$result = $m->createLocationAudienceGroup($create);
location_check($result['success'] && $m->writes === 1 && $m->active === $base, 'Audience creation changed active settings or failed to stage.');
location_check($m->pending['protected'] === 'preserve' && $m->pending['scheduled_announcements'] === $base['scheduled_announcements'], 'Audience creation lost unrelated configuration.');
$group = $m->pending['announcement_groups'][0];
location_check($group['desktop_clients'] === ['desktop'] && $group['location_snapshot']['desktop_bindings'] === [['username' => 'desktop', 'client_id' => 'cli_old']], 'Snapshot lost the stable desktop identity.');
location_check(ApiSecurity::groupDesktopBindingsValid($group, $m->pending), 'Current identity rejected.');
location_check(!$m->createLocationAudienceGroup($create)['success'] && $m->writes === 1, 'Retried stale create duplicated an audience.');
$oldGroup = $group;
$edit = $room; $edit['name'] = 'Renamed room'; $edit['members']['extensions'] = [];
$editResult = $m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $edit]);
location_check($editResult['success'] && $m->pending['announcement_groups'][0] === $oldGroup, 'Location edit changed an existing audience snapshot.');
location_check(!$m->saveLocation(['revision' => $state['revision'], 'node' => $edit])['success'], 'Stale edit overwrote a newer configuration.');
$before = $m->pending;
$delete = $m->deleteLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'id' => $building['id']]);
location_check(!$delete['success'] && $m->pending === $before, 'Parent with children was deleted.');
$delete = $m->deleteLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'id' => $room['id']]);
location_check($delete['success'] && $m->pending['announcement_groups'][0] === $oldGroup, 'Deleting a location erased delivery audiences.');

foreach ([['client_id' => 'cli_replacement'], ['username' => 'renamed'], ['enabled' => '0']] as $change) {
    $changed = $base; $changed['desktop_clients'][0] = array_replace($changed['desktop_clients'][0], $change);
    location_check(!ApiSecurity::groupDesktopBindingsValid($group, $changed), 'Replaced/renamed/disabled desktop retained group authority.');
    $principal = ['audience' => ['unrestricted' => false, 'announcement_group_ids' => [$group['id']]]]; $changed['announcement_groups'] = [$group];
    location_check(ApiSecurity::allowedAudience($principal, $changed)['desktops'] === [], 'Location group granted an API key access to a changed desktop.');
}
$replaced = $base; $replaced['desktop_clients'][0]['client_id'] = 'cli_replacement'; $m = new LocationFixture($replaced);
$input['revision'] = $m->getLocationDirectoryState()['revision']; $preview = $m->previewLocationAudience($input);
location_check($preview['success'] && count($preview['preview']['unavailable']) === 1 && $preview['preview']['members']['desktop_client_ids'] === ['cli_old'], 'A removed client vanished or was rebound by username.');
location_check(!$m->createLocationAudienceGroup($input + ['name' => 'Unsafe', 'preview_token' => $preview['preview']['token']])['success'] && $m->writes === 0, 'Unavailable audience was saved.');
$input['channels'] = ['extensions']; $preview = $m->previewLocationAudience($input);
location_check($preview['success'] && $preview['preview']['unavailable'] === [], 'Excluded recipient type blocked an otherwise valid audience.');
location_check($m->createLocationAudienceGroup($input + ['name' => 'Phones only', 'preview_token' => $preview['preview']['token']])['success'], 'Explicit phone-only audience failed.');
$empty = new LocationFixture(['announcement_groups' => []]);
$initial = $empty->getLocationDirectoryState();
location_check(count($initial['directory']['nodes']) === 1 && $initial['directory']['default_site_id'] === Directory::DEFAULT_SITE_ID && $empty->writes === 0, 'Opening an empty directory must show a default site without changing configuration.');
location_check(!$empty->deleteLocation(['revision' => $initial['revision'], 'id' => Directory::DEFAULT_SITE_ID])['success'], 'The last/default site was removable.');
$rename = $initial['directory']['nodes'][0]; $rename['name'] = 'Main campus';
location_check($empty->saveLocation(['revision' => $initial['revision'], 'node' => $rename])['success'] && $empty->pending['location_directory']['nodes'][0]['name'] === 'Main campus', 'Default site cannot be named and saved.');
$defaultFixture = new LocationFixture($base); $defaultState = $defaultFixture->getLocationDirectoryState();
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $room['id']])['success'], 'A room became the default site.');
location_check($defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $second['id']])['success'] && $defaultFixture->pending['location_directory']['default_site_id'] === $second['id'], 'Choosing another default site failed.');
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $site['id']])['success'], 'A stale default site edit was accepted.');
location_reject(static fn() => Directory::normalize(['nodes' => [$site], 'default_site_id' => $second['id']]), 'A missing default site was accepted.');
$m = new LocationFixture($base); $m->failWrite = true;
$badWrite = $m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $edit]);
location_check(!$badWrite['success'] && $m->writes === 0 && !str_contains($badWrite['message'], 'secret'), 'Storage failure reported success or exposed internals.');
$empty = new LocationFixture(['announcement_groups' => []]);
$initial = $empty->getLocationDirectoryState();
location_check(count($initial['directory']['nodes']) === 1 && $initial['directory']['default_site_id'] === Directory::DEFAULT_SITE_ID && $empty->writes === 0, 'Opening an empty directory must show a default site without changing configuration.');
location_check(!$empty->deleteLocation(['revision' => $initial['revision'], 'id' => Directory::DEFAULT_SITE_ID])['success'], 'The last/default site was removable.');
$rename = $initial['directory']['nodes'][0]; $rename['name'] = 'Main campus';
location_check($empty->saveLocation(['revision' => $initial['revision'], 'node' => $rename])['success'] && $empty->pending['location_directory']['nodes'][0]['name'] === 'Main campus', 'Default site cannot be named and saved.');
$defaultFixture = new LocationFixture($base); $defaultState = $defaultFixture->getLocationDirectoryState();
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $room['id']])['success'], 'A room became the default site.');
location_check($defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $second['id']])['success'] && $defaultFixture->pending['location_directory']['default_site_id'] === $second['id'], 'Choosing another default site failed.');
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $site['id']])['success'], 'A stale default site edit was accepted.');
location_reject(static fn() => Directory::normalize(['nodes' => [$site], 'default_site_id' => $second['id']]), 'A missing default site was accepted.');
$m = new LocationFixture($base); $unknown = $room; $unknown['members']['desktop_client_ids'] = ['cli_unknown'];
location_check(!$m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $unknown])['success'], 'A fabricated recipient was assigned.');
$existing = $room; $existing['name'] = 'Unchanged stale assignment';
$m = new LocationFixture($replaced);
location_check($m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $existing])['success'], 'Renaming silently discarded or forbade review of a stale assignment.');
$empty = new LocationFixture(['announcement_groups' => []]);
$initial = $empty->getLocationDirectoryState();
location_check(count($initial['directory']['nodes']) === 1 && $initial['directory']['default_site_id'] === Directory::DEFAULT_SITE_ID && $empty->writes === 0, 'Opening an empty directory must show a default site without changing configuration.');
location_check(!$empty->deleteLocation(['revision' => $initial['revision'], 'id' => Directory::DEFAULT_SITE_ID])['success'], 'The last/default site was removable.');
$rename = $initial['directory']['nodes'][0]; $rename['name'] = 'Main campus';
location_check($empty->saveLocation(['revision' => $initial['revision'], 'node' => $rename])['success'] && $empty->pending['location_directory']['nodes'][0]['name'] === 'Main campus', 'Default site cannot be named and saved.');
$defaultFixture = new LocationFixture($base); $defaultState = $defaultFixture->getLocationDirectoryState();
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $room['id']])['success'], 'A room became the default site.');
location_check($defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $second['id']])['success'] && $defaultFixture->pending['location_directory']['default_site_id'] === $second['id'], 'Choosing another default site failed.');
location_check(!$defaultFixture->setDefaultSite(['revision' => $defaultState['revision'], 'id' => $site['id']])['success'], 'A stale default site edit was accepted.');
location_reject(static fn() => Directory::normalize(['nodes' => [$site], 'default_site_id' => $second['id']]), 'A missing default site was accepted.');
$m = new LocationFixture($base); $input['revision'] = $m->getLocationDirectoryState()['revision'];
$preview = $m->previewLocationAudience($input);
foreach ([['preview_token' => str_repeat('0', 64)], ['include_descendants' => false], ['channels' => ['desktop_client_ids']]] as $patch) {
    $request = array_replace($input + ['name' => 'Review', 'preview_token' => $preview['preview']['token']], $patch);
    location_check(!$m->createLocationAudienceGroup($request)['success'] && $m->writes === 0, 'Changed selection bypassed preview verification.');
}
foreach ([['include_descendants' => 'true'], ['channels' => []], ['channels' => [null]], ['channels' => [['bad']]], ['channels' => ['extensions', 'extensions']], ['id' => '../bad']] as $patch) {
    location_check(!$m->previewLocationAudience(array_replace($input, $patch))['success'], 'Malformed audience request accepted.');
}
$m->active['announcement_groups'] = array_fill(0, 20, $group); $input['revision'] = $m->getLocationDirectoryState()['revision']; $preview = $m->previewLocationAudience($input);
location_check(!$m->createLocationAudienceGroup($input + ['name' => 'Overflow', 'preview_token' => $preview['preview']['token']])['success'], 'Group capacity overflow accepted.');

// Execute the actual main-class normalizer with inert inventory only.
class LocationNormalizationFixture extends \FreePBX\modules\Slsmassnotifyserver { public function getConfiguredPjsipExtensionNumbers() { return ['1000']; } }
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$normalizer = $reflection->getMethod('normalizeAnnouncementGroupsForExtensions'); $normalizer->setAccessible(true);
$fixture = (new ReflectionClass(LocationNormalizationFixture::class))->newInstanceWithoutConstructor();
$normalized = $normalizer->invoke($fixture, [$group], [], []);
location_check(count($normalized) === 1 && $normalized[0]['location_snapshot'] === $group['location_snapshot']
    && $normalized[0]['extensions'] === ['1000'] && $normalized[0]['desktop_clients'] === ['desktop'], 'Normalization silently pruned a frozen location audience.');
$tampered = $group; $tampered['desktop_clients'] = ['someoneelse'];
location_reject(static fn() => $normalizer->invoke($fixture, [$tampered], ['1000'], ['someoneelse']), 'Inconsistent desktop binding imported.');
class LocationIncidentFixture {
    use \FreePBX\modules\SlsIncidents;
    public array $settings;
    public function freeze(array $delivery): array { return $this->freezeIncidentDelivery($delivery); }
    private function getActiveSettings(): array { return $this->settings; }
    private function getAnnouncementGroups(): array { return $this->settings['announcement_groups']; }
    private function getConfiguredPjsipExtensionNumbers(): array { return ['1000']; }
    private function getDesktopClients(array $settings): array { return $settings['desktop_clients']; }
    private function outboundVoiceTargets(array $settings): array { return []; }
    private function normalizeWebhookDestinations($rows, $type): array { return []; }
}
$incident = new LocationIncidentFixture(); $incident->settings = $base; $incident->settings['announcement_groups'] = [$group];
$frozen = $incident->freeze(['group_ids' => [$group['id']]]);
location_check($frozen['group_ids'] === [] && $frozen['extensions'] === ['1000'] && $frozen['desktop_clients'] === ['desktop'], 'Incident did not freeze the reviewed audience.');
$incident->settings['desktop_clients'][0]['client_id'] = 'cli_replacement';
location_reject(static fn() => $incident->freeze(['group_ids' => [$group['id']]]), 'Incident accepted a replacement desktop identity.');
echo "Location hierarchy, stable identities, audience previews, stale edits, snapshot isolation and protected-field projection: $checks checks passed.\n";
