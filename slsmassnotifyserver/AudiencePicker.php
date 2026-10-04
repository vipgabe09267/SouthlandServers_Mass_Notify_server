<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/LocationDirectory.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Public editor projection. Copies are explicit; no runtime routing or discovery. */
final class AudiencePicker
{
    public static function choices(array $settings, bool $includeEmailAddresses = false): array
    {
        $directory = LocationDirectory::effective($settings['location_directory'] ?? []);
        $desktops = array_column($settings['desktop_clients'] ?? [], null, 'client_id');
        $byUsername = array_column($settings['desktop_clients'] ?? [], null, 'username');
        $rows = [];
        foreach ($directory['nodes'] as $node) {
            $members = array_fill_keys(array_keys(LocationDirectory::MEMBERS), []);
            foreach (LocationDirectory::selected($directory, $node['id'], true) as $child) {
                foreach ($child['members'] as $key => $ids) { $members[$key] = array_merge($members[$key], $ids); }
            }
            $coverage = []; $siteId = ''; $ancestor = $node;
            while ($ancestor) {
                if (empty($coverage['weather_zone']) && !empty($ancestor['weather_zone'])) { $coverage['weather_zone'] = $ancestor['weather_zone']; }
                if (empty($coverage['lightning_location']) && !empty($ancestor['position'])) { $coverage['lightning_location'] = $ancestor['position']['latitude'] . ',' . $ancestor['position']['longitude']; }
                if ($ancestor['type'] === 'site') { $siteId = $ancestor['id']; break; }
                $ancestor = array_column($directory['nodes'], null, 'id')[$ancestor['parent_id']] ?? null;
            }
            $rows[] = ['id' => 'location:' . $node['id'], 'label' => LocationDirectory::path($directory, $node['id']),
                'kind' => 'Locations', 'members' => $members, 'identity_error' => false, 'site_id' => $siteId, 'coverage' => $coverage];
        }
        foreach ($settings['announcement_groups'] ?? [] as $group) {
            $members = array_fill_keys(array_keys(LocationDirectory::MEMBERS), []);
            foreach ($members as $key => $_) { if ($key !== 'desktop_client_ids') { $members[$key] = $group[$key] ?? []; } }
            foreach ($group['desktop_clients'] ?? [] as $username) {
                $members['desktop_client_ids'][] = $byUsername[$username]['client_id'] ?? 'missing';
            }
            $rows[] = ['id' => 'audience:' . $group['id'], 'label' => $group['name'], 'kind' => 'Saved audiences',
                'members' => $members, 'identity_error' => !ApiSecurity::groupDesktopBindingsValid($group, $settings)];
        }
        $email = array_column($settings['announcement_email']['recipients'] ?? [], null, 'id');
        $hooks = array_column($settings['announcement_webhooks'] ?? [], null, 'id');
        foreach ($rows as &$row) {
            foreach ($row['members'] as &$ids) { $ids = array_values(array_unique($ids)); } unset($ids);
            $row['members']['desktop_clients'] = [];
            $row['members']['email_addresses'] = [];
            $row['members']['discord_webhook_ids'] = [];
            $row['members']['generic_webhook_ids'] = [];
            $row['unavailable'] = [];
            foreach ($row['members']['desktop_client_ids'] as $id) {
                if (empty($desktops[$id]['enabled'])) { $row['unavailable'][] = 'A desktop was disabled or removed.'; continue; }
                $row['members']['desktop_clients'][] = $desktops[$id]['username'];
            }
            foreach ($row['members']['email_recipient_ids'] as $id) {
                if (empty($email[$id]['enabled'])) { $row['unavailable'][] = 'An email recipient was disabled or removed.'; continue; }
                if ($includeEmailAddresses) { $row['members']['email_addresses'][] = $email[$id]['address']; }
            }
            $row['weather_unmapped_webhooks'] = 0;
            foreach ($row['members']['webhook_ids'] as $id) {
                $hook = $hooks[$id] ?? null;
                if (!$hook || empty($hook['enabled'])) { $row['unavailable'][] = 'A webhook was disabled or removed.'; continue; }
                $matched = false;
                foreach (['discord', 'generic'] as $kind) {
                    foreach ($settings[$kind . '_webhooks'] ?? [] as $destination) {
                        if (!empty($destination['enabled']) && ($destination['url'] ?? '') === ($hook['url'] ?? '')
                            && ($destination['payload_format'] ?? 'native') === ($hook['payload_format'] ?? 'native')) {
                            $row['members'][$kind . '_webhook_ids'][] = $destination['id']; $matched = true;
                        }
                    }
                }
                if (!$matched) { $row['weather_unmapped_webhooks']++; }
            }
            $row['unavailable'] = array_values(array_unique($row['unavailable']));
        } unset($row);
        return $rows;
    }
}
