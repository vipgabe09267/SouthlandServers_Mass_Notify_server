<?php
declare(strict_types=1);
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeAnnouncementGroupsForExtensions');
$group = ['name' => 'Staff', 'extensions' => ['1000'], 'desktop_clients' => []];
$initial = $normalize->invoke($module, [$group], ['1000', '1001'], []);
$edited = $initial;
$edited[0]['name'] = 'Staff and faculty';
$edited[0]['extensions'][] = '1001';
$normalized = $normalize->invoke($module, $edited, ['1000', '1001'], []);
if ($normalized[0]['id'] !== $initial[0]['id'] || $normalized[0]['extensions'] !== ['1000', '1001']) {
    throw new RuntimeException('Changing a group orphaned its identity or lost recipients.');
}
$legacyAgain = $normalize->invoke($module, [$group], ['1000', '1001'], []);
if ($legacyAgain[0]['id'] !== $initial[0]['id']) { throw new RuntimeException('Legacy group migration is not stable.'); }
$unicode = $normalize->invoke($module, [['name'=>str_repeat('é', 31).'🙂', 'extensions'=>['1000']]], ['1000'], []);
if (!mb_check_encoding($unicode[0]['name'], 'UTF-8') || strlen($unicode[0]['name'])>64 || $unicode[0]['name']!==str_repeat('é',31)) {
    throw new RuntimeException('Byte-bounded group names cut a Unicode character.');
}
$labs = $normalize->invoke($module, [
    ['name'=>'Browser', 'browser_recipient_ids'=>['sub_'.str_repeat('a',24)]],
    ['name'=>'Browser', 'browser_recipient_ids'=>['sub_'.str_repeat('b',24)]],
], [], []);
if (count($labs)!==2 || $labs[0]['id']===$labs[1]['id']) { throw new RuntimeException('Different Labs audiences collapsed into one legacy identity.'); }
$source = file($reflection->getFileName());
$availableMethod = $reflection->getMethod('getAvailableAnnouncementGroups');
$availableImplementation = implode('', array_slice($source, $availableMethod->getStartLine() - 1, $availableMethod->getEndLine() - $availableMethod->getStartLine() + 1));
eval('class GroupAvailabilityFixture {
    public array $groups = [];
    public function getSipNotifyTargets() { return []; }
    public function getDesktopClients(array $settings = null, $includePlaintext = false) { return []; }
    private function getActiveSettings() { return []; }
    public function getAnnouncementGroups() { return $this->groups; }
    private function normalizeDesktopUsername($value) { return trim((string)$value); }
' . $availableImplementation . '}');
$availability = new GroupAvailabilityFixture();
$availability->groups = [[
    'id'=>'grp_'.str_repeat('c',24), 'name'=>'Labs only', 'extensions'=>[], 'desktop_clients'=>[],
    'browser_recipient_ids'=>['sub_'.str_repeat('d',24)], 'connector_ids'=>['conn_'.str_repeat('e',24)],
]];
if (array_column($availability->getAvailableAnnouncementGroups(), 'id') !== ['grp_'.str_repeat('c',24)]) {
    throw new RuntimeException('Browser/connector-only audience was hidden as empty.');
}
echo "Saved paging/announcement group identity remains stable across edits.\n";
