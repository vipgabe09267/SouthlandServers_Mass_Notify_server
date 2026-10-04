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
echo "Saved paging/announcement group identity remains stable across edits.\n";
