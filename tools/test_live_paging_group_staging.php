<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

// Execute the real group methods with in-memory settings and persistence only.
// No module constructor, FreePBX bootstrap, TTS or PBX paths are used.
class PagingGroupStagingFixtureBase
{
    public $active;
    public $pending;
    public $activeWrites = 0;
    public $pendingWrites = 0;
    public function __construct(array $active, ?array $pending = null) { $this->active = $active; $this->pending = $pending; }
    protected function getActiveSettings() { return $this->active; }
    protected function getPendingSettings() { return $this->pending; }
    protected function isSetupComplete($settings) { return true; }
    public function getAnnouncementGroups() { return $this->active['announcement_groups']; }
    protected function getDesktopClients($settings) { return [['username' => 'desktop']]; }
    public function getConfiguredPjsipExtensionNumbers() { return ['1000', '1001', '1002', '1003']; }
    public function getAllPjsipExtensions() { return array_map(static function ($extension) { return ['extension' => $extension]; }, $this->getConfiguredPjsipExtensionNumbers()); }
    protected function normalizeDesktopUsername($value) { return strtolower(trim((string)$value)); }
    protected function persistAppliedSettings(array $settings) { $this->active = $settings; $this->activeWrites++; }
    protected function persistPendingSettings(array $settings) { $this->pending = $settings; $this->pendingWrites++; }
}
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$source = file($reflection->getFileName());
$methods = '';
foreach (['saveAnnouncementGroup', 'deleteAnnouncementGroup', 'syncPendingAnnouncementGroups', 'normalizeAnnouncementGroups', 'normalizeAnnouncementGroupsForExtensions', 'validateAudienceWebhookIds'] as $name) {
    $method = $reflection->getMethod($name);
    $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
eval('class PagingGroupStagingFixture extends PagingGroupStagingFixtureBase { const MAX_WEBHOOK_DESTINATIONS=10; use \\FreePBX\\modules\\SlsLivePaging; use \\FreePBX\\modules\\SlsOutboundVoice; use \\FreePBX\\modules\\SlsAnnouncementEmail; use \\FreePBX\\modules\\SlsAnnouncementSms; ' . $methods . '}');
function paging_group_check($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function paging_group_name(array $settings, string $id): ?string { return array_column($settings['announcement_groups'], 'name', 'id')[$id] ?? null; }

$groupA = 'grp_aaaaaaaaaaaa'; $groupB = 'grp_bbbbbbbbbbbb';
$active = ['announcement_piper_voice' => 'active-voice', 'unrelated' => 'active value', 'scheduled_announcements' => [['id' => 'schedule-active']],
    'live_paging' => ['enabled' => '1', 'extension' => '700', 'allowed_callers' => ['1000'], 'groups' => [['group_id' => $groupA, 'menu_number' => 1, 'require_pin' => '0']]],
    'announcement_groups' => [
        ['id' => $groupA, 'name' => 'Active A', 'extensions' => ['1001'], 'desktop_clients' => []],
        ['id' => $groupB, 'name' => 'Active B', 'extensions' => ['1002'], 'desktop_clients' => []],
    ]];
$pending = $active;
$pending['announcement_piper_voice'] = 'pending-voice';
$pending['unrelated'] = 'preserved pending value';
$pending['scheduled_announcements'][] = ['id' => 'pending-schedule'];
$pending['announcement_groups'][1]['name'] = 'Earlier staged B';
$fixture = new PagingGroupStagingFixture($active, $pending);
$beforePrompts = \SLS\MassNotify\LivePagingConfig::promptFiles($active);
$result = $fixture->saveAnnouncementGroup($groupA, 'Staged A', ['1003']);
paging_group_check($result['success'] && ($result['apply_required'] ?? false) && strpos($result['message'], 'Apply Config') !== false, 'Paging group edit did not clearly require Apply Config');
paging_group_check($fixture->activeWrites === 0 && $fixture->active === $active && $result['groups'] === $active['announcement_groups'], 'Staged edit changed active announcement targets');
paging_group_check(\SLS\MassNotify\LivePagingConfig::promptFiles($fixture->active) === $beforePrompts, 'Staged rename introduced an active missing-prompt gap');
paging_group_check(paging_group_name($fixture->pending, $groupA) === 'Staged A' && paging_group_name($fixture->pending, $groupB) === 'Earlier staged B', 'Staging one group erased another pending group');
foreach (['announcement_piper_voice', 'unrelated', 'scheduled_announcements'] as $field) { paging_group_check($fixture->pending[$field] === $pending[$field], 'Group edit replaced unrelated pending settings'); }

$result = $fixture->saveAnnouncementGroup($groupB, 'Immediate B', ['1002']);
paging_group_check($result['success'] && empty($result['apply_required']) && paging_group_name($fixture->active, $groupB) === 'Immediate B', 'Unreferenced group edit stopped applying immediately');
paging_group_check(paging_group_name($fixture->pending, $groupA) === 'Staged A' && paging_group_name($fixture->pending, $groupB) === 'Immediate B', 'Immediate group edit erased staged paging edits');
$result = $fixture->saveAnnouncementGroup('', 'Immediate C', ['1002']);
paging_group_check($result['success'] && count($fixture->active['announcement_groups']) === 3 && paging_group_name($fixture->pending, $groupA) === 'Staged A', 'Adding a group erased staged paging edits');
$groupC = $fixture->active['announcement_groups'][2]['id'];
paging_group_check($fixture->deleteAnnouncementGroup($groupC)['success'] && paging_group_name($fixture->pending, $groupC) === null && paging_group_name($fixture->pending, $groupA) === 'Staged A', 'Deleting a newly added group erased staged paging edits');
paging_group_check($fixture->deleteAnnouncementGroup($groupB)['success'] && paging_group_name($fixture->pending, $groupB) === null && paging_group_name($fixture->pending, $groupA) === 'Staged A', 'Deleting an ordinary group erased staged paging edits');

$fixture = new PagingGroupStagingFixture($active);
$result = $fixture->saveAnnouncementGroup($groupA, 'First staged A', ['1003']);
paging_group_check($result['success'] && ($result['apply_required'] ?? false) && $fixture->activeWrites === 0 && $fixture->pending['unrelated'] === $active['unrelated'], 'First staged group edit did not preserve the active configuration');

$pending = $active;
$pending['live_paging']['groups'][] = ['group_id' => $groupB, 'menu_number' => 2, 'require_pin' => '0'];
$fixture = new PagingGroupStagingFixture($active, $pending);
$result = $fixture->saveAnnouncementGroup($groupB, 'Desktop only B', [], ['desktop']);
paging_group_check(!$result['success'] && $fixture->activeWrites === 0 && $fixture->pendingWrites === 0, 'Invalid pending paging group references allowed an active mutation');
$result = $fixture->saveAnnouncementGroup($groupB, 'Empty B', [], []);
paging_group_check(!$result['success'] && $fixture->activeWrites === 0, 'Empty group was silently removed during save');

$disabled = $active; $disabled['live_paging']['enabled'] = '0';
$fixture = new PagingGroupStagingFixture($disabled);
$result = $fixture->saveAnnouncementGroup($groupA, 'Immediate disabled A', ['1003']);
paging_group_check($result['success'] && empty($result['apply_required']) && $fixture->activeWrites === 1 && $fixture->pendingWrites === 0, 'Disabled paging altered ordinary immediate-save behavior');
// Conversion preserves stable IDs, but removes the announcement dependency.
$converted = $active;
$converted['desktop_clients'] = [['username' => 'desktop', 'client_id' => 'cli_fixture', 'enabled' => '1']];
$converted['live_paging']['groups'][0] += ['name' => 'Independent A', 'extensions' => ['1003'],
    'notify_extensions' => ['1002'], 'allowed_callers' => ['1000'], 'text_message' => 'Independent page'];
$convertedPending = $converted;
$convertedPending['unrelated'] = 'pending setting to preserve';
$convertedPending['live_paging']['groups'][0]['text_message'] = 'Pending independent message';
$fixture = new PagingGroupStagingFixture($converted, $convertedPending);
$beforePrompts = \SLS\MassNotify\LivePagingConfig::promptFiles($converted);
$result = $fixture->saveAnnouncementGroup($groupA, 'Unrelated announcement A', [], ['desktop']);
paging_group_check($result['success'] && empty($result['apply_required']) && $fixture->activeWrites === 1,
    'An announcement sharing a converted group ID was still staged or required audio recipients');
paging_group_check($fixture->active['live_paging'] === $converted['live_paging']
    && $fixture->pending['live_paging'] === $convertedPending['live_paging']
    && $fixture->pending['unrelated'] === $convertedPending['unrelated'], 'Related announcement edit changed independent active or pending paging settings');
paging_group_check(\SLS\MassNotify\LivePagingConfig::promptFiles($fixture->active) === $beforePrompts,
    'Related announcement edit changed independent paging prompts');
$result = $fixture->deleteAnnouncementGroup($groupA);
paging_group_check($result['success'] && paging_group_name($fixture->active, $groupA) === null
    && paging_group_name($fixture->pending, $groupA) === null, 'A converted paging group still prevented announcement deletion');
paging_group_check($fixture->active['live_paging'] === $converted['live_paging']
    && $fixture->pending['live_paging'] === $convertedPending['live_paging']
    && \SLS\MassNotify\LivePagingConfig::promptFiles($fixture->active) === $beforePrompts,
    'Announcement deletion altered independent paging settings or prompts');
foreach ([[$active, null], [$active, $converted], [$converted, $active]] as [$guardedActive, $guardedPending]) {
    $fixture = new PagingGroupStagingFixture($guardedActive, $guardedPending);
    $result = $fixture->deleteAnnouncementGroup($groupA);
    paging_group_check(!$result['success'] && $fixture->activeWrites === 0 && $fixture->pendingWrites === 0,
        'An active or pending legacy paging reference no longer blocked announcement deletion');
}
echo "Converted paging groups remain independent of same-ID announcement edits/deletion; active and pending legacy references retain their guards.\n";
echo "Paging group edits stage without active prompt gaps; ordinary save/add/delete preserve pending edits and validate paging references.\n";
