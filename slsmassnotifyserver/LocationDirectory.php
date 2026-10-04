<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/GeographicTargeting.php';

/** Configuration only. No discovery, network access, or delivery side effects. */
final class LocationDirectory
{
    public const MAX_NODES = 500;
    public const MAX_BYTES = 524288;
    public const DEFAULT_SITE_ID = 'loc_000000000000000000000001';
    public const MEMBERS = [
        'extensions' => '/^[0-9]{1,20}$/D',
        'desktop_client_ids' => '/^[a-z0-9_-]{1,32}$/D',
        'voice_recipient_ids' => '/^voice_[a-f0-9]{24}$/D',
        'email_recipient_ids' => '/^email_[a-f0-9]{24}$/D',
        'sms_recipient_ids' => '/^sms_[a-f0-9]{24}$/D',
        'webhook_ids' => '/^[A-Za-z0-9_-]{1,64}$/D',
    ];
    public const PARENTS = ['site' => '', 'building' => 'site', 'floor' => 'building', 'room' => 'floor'];

    public static function defaults(): array { return ['schema' => 1, 'nodes' => []]; }

    private static function object($value, array $keys, string $label): array
    {
        if (!is_array($value) || ($value && array_is_list($value)) || array_diff(array_keys($value), $keys)) {
            throw new \InvalidArgumentException($label . ' contains unsupported fields or is not an object.');
        }
        return $value;
    }

    public static function text($value, int $bytes, string $label, bool $required = true): string
    {
        if (!is_string($value) || strlen($value) > $bytes || preg_match('//u', $value) !== 1
            || preg_match('/[\p{C}]/u', $value) || ($required && trim($value) === '')) {
            throw new \InvalidArgumentException($label . ' must be readable text, at most ' . $bytes . ' bytes.');
        }
        return trim($value);
    }

    public static function id($value): string
    {
        if (!is_string($value) || !preg_match('/^loc_[a-f0-9]{24}$/D', $value)) {
            throw new \InvalidArgumentException('Select a saved location. Its identifier is invalid.');
        }
        return $value;
    }

    public static function node($value): array
    {
        $row = self::object($value, ['id', 'type', 'parent_id', 'name', 'description', 'members', 'position', 'weather_zone'], 'Location');
        $type = $row['type'] ?? null;
        if (!is_string($type) || !array_key_exists($type, self::PARENTS)) {
            throw new \InvalidArgumentException('Location type must be site, building, floor, or room.');
        }
        $parent = $type === 'site' && (array_key_exists('parent_id', $row) ? $row['parent_id'] : '') === '' ? '' : self::id($row['parent_id'] ?? null);
        if ($type === 'site' && $parent !== '') { throw new \InvalidArgumentException('A site cannot have a parent location.'); }
        $members = self::object(array_key_exists('members', $row) ? $row['members'] : [], array_keys(self::MEMBERS), 'Location membership');
        foreach (self::MEMBERS as $key => $pattern) {
            $list = array_key_exists($key, $members) ? $members[$key] : [];
            $limit = $key === 'webhook_ids' ? 10 : (in_array($key, ['extensions', 'desktop_client_ids', 'voice_recipient_ids'], true) ? 1000 : 50);
            if (!is_array($list) || !array_is_list($list) || count($list) > $limit) {
                throw new \InvalidArgumentException('Location ' . $key . ' must be a list of at most ' . $limit . ' recipients.');
            }
            $seen = [];
            foreach ($list as $id) {
                if (!is_string($id) || !preg_match($pattern, $id) || isset($seen[$id])) {
                    throw new \InvalidArgumentException('Location ' . $key . ' contains an invalid or duplicate recipient identifier.');
                }
                $seen[$id] = true;
            }
            sort($list, SORT_STRING); $members[$key] = $list;
        }
        $zone = $row['weather_zone'] ?? '';
        if (!is_string($zone) || ($zone !== '' && !preg_match('/^[A-Z]{2}[CZ][0-9]{3}$/D', $zone))) { throw new \InvalidArgumentException('Weather coverage must be a Weather.gov county or forecast zone, such as TXC491.'); }
        return ['id' => self::id($row['id'] ?? null), 'type' => $type, 'parent_id' => $parent,
            'name' => self::text($row['name'] ?? null, 160, 'Location name'),
            'description' => self::text(array_key_exists('description', $row) ? $row['description'] : '', 400, 'Location description', false),
            'members' => array_replace(array_fill_keys(array_keys(self::MEMBERS), []), $members)]
            + ($zone !== '' ? ['weather_zone' => $zone] : [])
            + (array_key_exists('position', $row) ? ['position' => GeographicTargeting::position($row['position'])] : []);
    }

    public static function normalize($value): array
    {
        $value = self::object($value, ['schema', 'nodes', 'default_site_id'], 'Location directory');
        if ((array_key_exists('schema', $value) ? $value['schema'] : 1) !== 1) { throw new \InvalidArgumentException('Unsupported location directory schema.'); }
        $nodes = array_key_exists('nodes', $value) ? $value['nodes'] : [];
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > self::MAX_NODES) {
            throw new \InvalidArgumentException('The location directory supports up to 500 saved locations.');
        }
        $byId = []; $names = []; $assigned = []; $bytes = 32;
        foreach ($nodes as $nodeValue) {
            $row = self::node($nodeValue);
            $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes > self::MAX_BYTES) { throw new \InvalidArgumentException('The location directory exceeds its 512 KiB configuration limit.'); }
            if (isset($byId[$row['id']])) { throw new \InvalidArgumentException('A location identifier occurs more than once.'); }
            $key = $row['parent_id'] . ':' . $row['type'] . ':' . (function_exists('mb_strtolower') ? mb_strtolower($row['name'], 'UTF-8') : strtolower($row['name']));
            if (isset($names[$key])) { throw new \InvalidArgumentException('Locations of the same type under one parent must have distinct names.'); }
            $names[$key] = true; $byId[$row['id']] = $row;
            foreach ($row['members'] as $channel => $members) {
                foreach ($members as $id) {
                    if ($channel !== 'webhook_ids' && isset($assigned[$channel][$id])) {
                        throw new \InvalidArgumentException('Recipient ' . $id . ' is already assigned to ' . $assigned[$channel][$id] . '. Remove that assignment before moving it.');
                    }
                    $assigned[$channel][$id] = $row['name'];
                    $limit = $channel === 'webhook_ids' ? 10 : (in_array($channel, ['extensions', 'desktop_client_ids', 'voice_recipient_ids'], true) ? 1000 : 50);
                    if (count($assigned[$channel]) > $limit) { throw new \InvalidArgumentException('The directory exceeds the ' . $limit . '-recipient limit for ' . $channel . '.'); }
                }
            }
        }
        foreach ($byId as $row) {
            if ($row['type'] !== 'site' && ($byId[$row['parent_id']]['type'] ?? null) !== self::PARENTS[$row['type']]) {
                throw new \InvalidArgumentException('Each building needs a site, each floor a building, and each room a floor. Save its parent first.');
            }
        }
        // Strict parent types make cycles and paths deeper than four impossible.
        ksort($byId, SORT_STRING);
        $result = ['schema' => 1, 'nodes' => array_values($byId)];
        if (array_key_exists('default_site_id', $value)) {
            $id = self::id($value['default_site_id']);
            if (($byId[$id]['type'] ?? '') !== 'site') { throw new \InvalidArgumentException('The default site must be a saved site.'); }
            $result['default_site_id'] = $id;
        }
        return $result;
    }

    /** A useful first site without writing configuration while opening a page. */
    public static function effective($value): array
    {
        $directory = self::normalize($value);
        if (!$directory['nodes']) {
            $directory['nodes'][] = self::node(['id' => self::DEFAULT_SITE_ID, 'type' => 'site', 'name' => 'Default site']);
        }
        if (!isset($directory['default_site_id'])) {
            foreach ($directory['nodes'] as $node) {
                if ($node['type'] === 'site') { $directory['default_site_id'] = $node['id']; break; }
            }
        }
        return $directory;
    }

    public static function path(array $directory, string $id): string
    {
        $nodes = array_column($directory['nodes'], null, 'id'); $names = [];
        for ($depth = 0; $id !== '' && $depth < 4; $depth++) {
            if (!isset($nodes[$id])) { throw new \InvalidArgumentException('The selected location no longer exists. Reload Locations.'); }
            array_unshift($names, $nodes[$id]['name']); $id = $nodes[$id]['parent_id'];
        }
        if ($id !== '') { throw new \InvalidArgumentException('The location hierarchy exceeds four levels. Review its parent relationships.'); }
        return implode(' / ', $names);
    }

    public static function selected(array $directory, string $id, bool $descendants): array
    {
        self::id($id); $nodes = array_column($directory['nodes'], null, 'id');
        if (!isset($nodes[$id])) { throw new \InvalidArgumentException('The selected location no longer exists. Reload Locations.'); }
        $selected = [$id => true];
        if ($descendants) {
            for ($depth = 0; $depth < 3; $depth++) {
                foreach ($nodes as $node) { if (isset($selected[$node['parent_id']])) { $selected[$node['id']] = true; } }
            }
        }
        return array_values(array_filter($directory['nodes'], static fn(array $node): bool => isset($selected[$node['id']])));
    }

    /** Keep unavailable identities visible. Never silently shrink a reviewed audience. */
    public static function audience(array $directory, string $id, bool $descendants, array $catalog): array
    {
        $selected = self::selected($directory, $id, $descendants);
        return self::members($directory, $selected, $catalog) + ['path' => self::path($directory, $id)];
    }

    public static function members(array $directory, array $selected, array $catalog): array
    {
        $members = array_fill_keys(array_keys(self::MEMBERS), []); $unavailable = [];
        foreach ($selected as $node) {
            foreach ($node['members'] as $channel => $ids) {
                foreach ($ids as $member) {
                    $choice = $catalog[$channel][$member] ?? null;
                    $members[$channel][] = $member;
                    if (!is_array($choice) || empty($choice['available'])) {
                        $unavailable[] = ['channel' => $channel, 'id' => $member, 'location' => self::path($directory, $node['id']),
                            'reason' => $choice['reason'] ?? 'Recipient no longer exists. Review the location assignment.'];
                    }
                }
            }
        }
        foreach ($members as &$ids) { $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); } unset($ids);
        return ['location_count' => count($selected), 'members' => $members, 'unavailable' => $unavailable];
    }

    public static function snapshot($value, array $desktops): array
    {
        if (!array_is_list($desktops) || count($desktops) > 1000) { throw new \InvalidArgumentException('Location snapshot desktop recipients must be a bounded list.'); }
        foreach ($desktops as $username) {
            if (!is_string($username) || !preg_match('/^[a-z0-9_.-]{1,48}$/D', $username)) { throw new \InvalidArgumentException('Location snapshot desktop username is invalid.'); }
        }
        $row = self::object($value, ['schema', 'location_id', 'include_descendants', 'geographic', 'path', 'created_at', 'directory_revision', 'desktop_bindings'], 'Location audience snapshot');
        if (!in_array($row['schema'] ?? null, [1, 2], true)
            || !is_int($row['created_at'] ?? null) || $row['created_at'] < 1
            || !is_string($row['directory_revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['directory_revision'])) {
            throw new \InvalidArgumentException('Location audience snapshot metadata is invalid.');
        }
        if ($row['schema'] === 1) {
            if (!is_bool($row['include_descendants'] ?? null) || array_key_exists('geographic', $row)) { throw new \InvalidArgumentException('Location snapshot selection is invalid.'); }
            self::id($row['location_id'] ?? null);
        } else {
            if (array_key_exists('location_id', $row) || array_key_exists('include_descendants', $row)) { throw new \InvalidArgumentException('Geographic snapshot cannot also select a location subtree.'); }
            $row['geographic'] = GeographicTargeting::snapshot($row['geographic'] ?? null);
            foreach ($row['geographic']['locations'] as $location) {
                $reviewed = $location['position']['reviewed_at'];
                if ($reviewed > $row['created_at'] + 60 || $reviewed < $row['created_at'] - $row['geographic']['selection']['max_age_days'] * 86400) {
                    throw new \InvalidArgumentException('Geographic snapshot coordinates were not current when the audience was created.');
                }
            }
        }
        self::text($row['path'] ?? null, 649, 'Location path');
        self::bindings($row['desktop_bindings'] ?? null, $desktops);
        return $row;
    }

    public static function bindings($bindings, array $desktops): array
    {
        if (!array_is_list($desktops) || count($desktops) > 1000) { throw new \InvalidArgumentException('Audience desktop identities must be a list of at most 1000 recipients.'); }
        foreach ($desktops as $username) {
            if (!is_string($username) || !preg_match('/^[a-z0-9_.-]{1,48}$/D', $username)) { throw new \InvalidArgumentException('Audience desktop username is invalid.'); }
        }
        $seen = []; $ids = [];
        if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) > 1000) {
            throw new \InvalidArgumentException('Location snapshot desktop bindings must be a bounded list.');
        }
        foreach ($bindings as $binding) {
            $binding = self::object($binding, ['username', 'client_id'], 'Desktop binding');
            if (!is_string($binding['username'] ?? null) || !preg_match('/^[a-z0-9_.-]{1,48}$/D', $binding['username'])
                || !is_string($binding['client_id'] ?? null) || !preg_match(self::MEMBERS['desktop_client_ids'], $binding['client_id'])
                || isset($seen[$binding['username']]) || isset($ids[$binding['client_id']])) {
                throw new \InvalidArgumentException('Location snapshot desktop identity is invalid or duplicated.');
            }
            $seen[$binding['username']] = true; $ids[$binding['client_id']] = true;
        }
        if (count($seen) !== count($desktops) || array_diff($desktops, array_keys($seen))) {
            throw new \InvalidArgumentException('Location snapshot desktop recipients do not match their saved identities. Create a new audience from Locations.');
        }
        return $bindings;
    }
}
