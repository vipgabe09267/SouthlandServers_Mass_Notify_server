<?php
declare(strict_types=1);
require_once __DIR__ . '/test_locations.php';
use SLS\MassNotify\GeographicTargeting as Geo;
use SLS\MassNotify\LocationDirectory as Directory;
use SLS\MassNotify\ApiSecurity;

$start = $checks; $now = time();
$site['position'] = ['latitude' => 30.25, 'longitude' => -97.75, 'reviewed_at' => $now - 86400];
$second['position'] = ['latitude' => 32.0, 'longitude' => -97.75, 'reviewed_at' => $now - 86400];
$nodes = [$site, $building, $floor, $room, $second];
$directory = Directory::normalize(['nodes' => $nodes]);
$base['location_directory'] = $directory; $m = new LocationFixture($base);
$area = ['west' => -98.0, 'south' => 30.0, 'east' => -97.0, 'north' => 31.0, 'max_age_days' => 30];
$positions = Geo::positions($directory);
location_check($positions[$room['id']] === $site['position'] + ['source_id' => $site['id']], 'Room did not inherit reviewed site coordinates.');
$catalog = $m->getLocationDirectoryState()['catalog'];
$preview = Geo::audience($directory, $area, $catalog, $now);
location_check(count($preview['included_locations']) === 4 && $preview['members']['extensions'] === ['1000'], 'Geographic audience crossed a boundary.');
location_check($preview['excluded_locations'][0]['reason'] === 'outside_area', 'Outside-area location was not reported.');
location_check(Geo::audience($directory, $area, $catalog, $now + 86400)['included_locations'] === $preview['included_locations'], 'Repeated review changed stable coordinates.');
foreach (['latitude' => [null, true, '30', [], INF, NAN, 91, -91], 'longitude' => [null, true, '30', [], INF, NAN, 181, -181],
    'reviewed_at' => [null, true, '30', 0, -1, 4102444801]] as $field => $values) {
    foreach ($values as $bad) { location_reject(static fn() => Geo::position(array_replace($site['position'], [$field => $bad])), 'Invalid coordinates accepted.'); }
}
foreach ([null, [], $site['position'] + ['tracking' => true]] as $bad) { location_reject(static fn() => Geo::position($bad), 'Unsupported coordinate fields accepted.'); }
foreach ([['west' => -181], ['east' => 181], ['north' => 91], ['south' => -91], ['north' => 30], ['east' => -98.0],
    ['west' => 180, 'east' => -180], ['max_age_days' => 0], ['max_age_days' => 3651], ['max_age_days' => '30'], ['max_age_days' => 1.5], ['extra' => 1]] as $patch) {
    location_reject(static fn() => Geo::selection(array_replace($area, $patch)), 'Invalid map area accepted.');
}
location_check(Geo::contains($area, ['latitude' => 30.0, 'longitude' => -98.0]) && Geo::contains($area, ['latitude' => 31.0, 'longitude' => -97.0]), 'Boundary locations excluded.');
$dateline = Geo::selection(['west' => 170, 'south' => -20, 'east' => -170, 'north' => 20, 'max_age_days' => 30]);
foreach ([179.0, -179.0, -180.0, 180.0, 170.0, -170.0] as $lon) { location_check(Geo::contains($dateline, ['latitude' => 0.0, 'longitude' => $lon]), 'Date-line area lost a location.'); }
location_check(!Geo::contains($dateline, ['latitude' => 0.0, 'longitude' => 0.0]), 'Date-line area included the opposite hemisphere.');
location_check(Geo::contains(Geo::selection(array_replace($area, ['west' => 170.0, 'east' => 180.0])), ['latitude' => 30.0, 'longitude' => -180.0]), 'Equivalent meridian boundary rejected.');
$stale = $nodes; $stale[3]['position'] = ['latitude' => 30.5, 'longitude' => -97.5, 'reviewed_at' => $now - 31 * 86400];
$stale = Directory::normalize(['nodes' => $stale]);
$p = Geo::audience($stale, $area, $catalog, $now);
location_check($p['members']['extensions'] === [] && count(array_filter($p['excluded_locations'], static fn($r) => $r['reason'] === 'stale')) === 1, 'Stale room silently fell back to fresh ancestor.');
$unknown = $nodes; unset($unknown[0]['position']); $unknown[4]['position']['reviewed_at'] = $now + 3600;
$p = Geo::audience(Directory::normalize(['nodes' => $unknown]), $area, $catalog, $now);
location_check(count($p['excluded_locations']) === 5 && !$p['included_locations'], 'Missing/future coordinates included.');
location_check(count(array_filter($p['excluded_locations'], static fn($r) => $r['reason'] === 'unlocated')) === 4, 'Inherited missing positions omitted from review.');

// Real facade: review is required, server stamps coordinates, old groups stay fixed.
$input = ['revision' => $m->getLocationDirectoryState()['revision'], 'selection' => $area, 'channels' => ['extensions', 'desktop_client_ids']];
$p = $m->previewGeographicAudience($input);
location_check($p['success'] && $m->writes === 0 && $p['preview']['members']['desktop_client_ids'] === ['cli_old'], 'Preview mutated or rebound recipients.');
$request = $input + ['name' => 'Reviewed geographic area', 'preview_token' => $p['preview']['token']];
location_check(!$m->createGeographicAudienceGroup($request)['success'], 'Geographic exclusions bypassed explicit review.');
foreach ([['preview_token' => 'bad'], ['selection' => array_replace($area, ['north' => 34.0])], ['channels' => ['extensions']], ['id' => $site['id']], ['exclusions_reviewed' => 'true']] as $patch) {
    location_check(!$m->createGeographicAudienceGroup(array_replace($request + ['exclusions_reviewed' => true], $patch))['success'] && !$m->writes, 'Changed geographic review was accepted.');
}
$result = $m->createGeographicAudienceGroup($request + ['exclusions_reviewed' => true]);
location_check($result['success'] && $m->writes === 1 && $m->active === $base, 'Geographic group did not stage safely.');
$geoGroup = $m->pending['announcement_groups'][0]; $snapshot = $geoGroup['location_snapshot'];
location_check($snapshot['schema'] === 2 && count($snapshot['geographic']['locations']) === 4 && $geoGroup['desktop_clients'] === ['desktop'], 'Geographic snapshot lost coordinates or stable identities.');
location_check(Directory::snapshot($snapshot, ['desktop']) === $snapshot, 'Geographic snapshot normalization changed saved metadata.');
location_check(!$m->createGeographicAudienceGroup($request + ['exclusions_reviewed' => true])['success'] && $m->writes === 1, 'Retried request created another group.');
$normalized = $normalizer->invoke($fixture, [$geoGroup], [], []);
location_check($normalized[0] === $geoGroup, 'Main group normalizer changed geographic recipients or metadata.');
location_check(ApiSecurity::groupDesktopBindingsValid($geoGroup, $m->pending), 'Geographic identity binding rejected.');
$incidentGeo = new LocationIncidentFixture(); $incidentGeo->settings = $m->pending;
$frozenGeo = $incidentGeo->freeze(['group_ids' => [$geoGroup['id']]]);
location_check($frozenGeo['group_ids'] === [] && $frozenGeo['extensions'] === ['1000'] && $frozenGeo['desktop_clients'] === ['desktop'], 'Incident failed to freeze the geographic audience.');
$principalGeo = ['audience' => ['unrestricted' => false, 'announcement_group_ids' => [$geoGroup['id']]]];
$allowedGeo = ApiSecurity::allowedAudience($principalGeo, $m->pending);
location_check($allowedGeo['phones'] === ['1000'] && $allowedGeo['desktops'] === ['desktop'], 'Scoped API geographic group authorization changed its audience.');
$replaced = $m->pending; $replaced['desktop_clients'][0]['client_id'] = 'cli_new';
location_check(!ApiSecurity::groupDesktopBindingsValid($geoGroup, $replaced), 'Reused desktop username retained geographic authority.');
$incidentGeo->settings = $replaced;
location_reject(static fn() => $incidentGeo->freeze(['group_ids' => [$geoGroup['id']]]), 'Incident accepted a reused geographic desktop identity.');
location_check(ApiSecurity::allowedAudience($principalGeo, $replaced)['desktops'] === [], 'Replaced desktop retained group-scoped API authority.');
foreach ([['geographic' => null], ['schema' => 1], ['location_id' => $site['id']], ['include_descendants' => true], ['created_at' => $now - 40 * 86400]] as $patch) {
    location_reject(static fn() => Directory::snapshot(array_replace($snapshot, $patch), ['desktop']), 'Invalid geographic snapshot imported.');
}
$broken = $snapshot; $broken['geographic']['locations'][] = $broken['geographic']['locations'][0];
location_reject(static fn() => Directory::snapshot($broken, ['desktop']), 'Duplicate geographic location imported.');
$broken = $snapshot; $broken['geographic']['locations'][0]['position']['latitude'] = 75.0;
location_reject(static fn() => Directory::snapshot($broken, ['desktop']), 'Out-of-area snapshot location imported.');
// Editing coordinates needs an explicit review and cannot edit an existing audience.
$changedSite = $site; $changedSite['position']['latitude'] = 45.0;
$save = ['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $changedSite];
location_check(!$m->saveLocation($save)['success'] && $m->writes === 1, 'Changed coordinates saved without review.');
location_check(!$m->saveLocation($save + ['position_reviewed' => 'true'])['success'], 'String coordinate confirmation accepted.');
$saved = $m->saveLocation($save + ['position_reviewed' => true]);
location_check($saved['success'] && $m->pending['announcement_groups'][0] === $geoGroup, 'Moving a location changed an existing geographic audience.');
$updated = array_column($m->pending['location_directory']['nodes'], null, 'id')[$site['id']];
location_check($updated['position']['reviewed_at'] >= $now && $updated['position']['reviewed_at'] <= time(), 'Server did not stamp confirmed review.');
$updated['name'] = 'Renamed site';
location_check($m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $updated])['success'], 'Unchanged coordinates required unnecessary re-review.');
$updated['position']['reviewed_at']++;
location_check(!$m->saveLocation(['revision' => $m->getLocationDirectoryState()['revision'], 'node' => $updated])['success'], 'A client forged the review date.');
$m = new LocationFixture($base); $m->active['desktop_clients'][0]['enabled'] = '0';
$input['revision'] = $m->getLocationDirectoryState()['revision']; $p = $m->previewGeographicAudience($input);
location_check(count($p['preview']['unavailable']) === 1 && !$m->createGeographicAudienceGroup($input + ['name' => 'Unsafe', 'preview_token' => $p['preview']['token'], 'exclusions_reviewed' => true])['success'], 'Unavailable recipient silently removed from geographic audience.');
$m = new LocationFixture($base); $m->failWrite = true; $input['revision'] = $m->getLocationDirectoryState()['revision']; $p = $m->previewGeographicAudience($input);
location_check(!$m->createGeographicAudienceGroup($input + ['name' => 'Failure', 'preview_token' => $p['preview']['token'], 'exclusions_reviewed' => true])['success'], 'Failed geographic save reported success.');
echo 'Geographic boundaries, inheritance, freshness, consent, snapshots and isolation: ' . ($checks - $start) . " checks passed.\n";
