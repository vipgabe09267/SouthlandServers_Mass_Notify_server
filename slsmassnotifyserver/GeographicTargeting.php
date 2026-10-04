<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Fixed, operator-reviewed locations only. No geolocation or network requests. */
final class GeographicTargeting
{
    private static function object($value, array $keys, string $label): array
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys)
            || array_diff($keys, array_keys($value))) {
            throw new \InvalidArgumentException($label . ' must contain exactly the documented fields.');
        }
        return $value;
    }

    public static function coordinate($value, bool $latitude): float
    {
        $limit = $latitude ? 90 : 180;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < -$limit || $value > $limit) {
            throw new \InvalidArgumentException(($latitude ? 'Latitude' : 'Longitude') . ' must be a number between -' . $limit . ' and ' . $limit . ' degrees.');
        }
        return (float)$value;
    }

    public static function position($value): array
    {
        $row = self::object($value, ['latitude', 'longitude', 'reviewed_at'], 'Location coordinates');
        if (!is_int($row['reviewed_at']) || $row['reviewed_at'] < 1 || $row['reviewed_at'] > 4102444800) {
            throw new \InvalidArgumentException('Location coordinates need a valid review timestamp. Review and save them in Locations.');
        }
        return ['latitude' => self::coordinate($row['latitude'], true), 'longitude' => self::coordinate($row['longitude'], false),
            'reviewed_at' => $row['reviewed_at']];
    }

    public static function selection($value): array
    {
        $row = self::object($value, ['west', 'south', 'east', 'north', 'max_age_days'], 'Geographic area');
        $result = [];
        foreach (['west', 'south', 'east', 'north'] as $key) { $result[$key] = self::coordinate($row[$key], in_array($key, ['south', 'north'], true)); }
        if ($result['south'] >= $result['north'] || $result['west'] === $result['east']
            || ($result['west'] === 180.0 && $result['east'] === -180.0)) {
            throw new \InvalidArgumentException('Select an area with nonzero width and height. South must be below north; west greater than east crosses the date line.');
        }
        if (!is_int($row['max_age_days']) || $row['max_age_days'] < 1 || $row['max_age_days'] > 3650) {
            throw new \InvalidArgumentException('Coordinate review age must be a whole number of days from 1 to 3650.');
        }
        $result['max_age_days'] = $row['max_age_days'];
        return $result;
    }

    public static function contains(array $area, array $position): bool
    {
        $lat = $position['latitude']; $lon = $position['longitude'];
        if ($lat < $area['south'] || $lat > $area['north']) { return false; }
        // +180 and -180 describe the same meridian, including boundary points.
        $longitudes = abs((float)$lon) === 180.0 ? [-180.0, 180.0] : [$lon];
        foreach ($longitudes as $value) {
            if ($area['west'] < $area['east'] ? ($value >= $area['west'] && $value <= $area['east'])
                : ($value >= $area['west'] || $value <= $area['east'])) { return true; }
        }
        return false;
    }

    /** A more specific position overrides its ancestors, even when it is stale. */
    public static function positions(array $directory): array
    {
        $nodes = array_column($directory['nodes'], null, 'id'); $result = [];
        foreach ($nodes as $node) {
            $source = $node; $position = null;
            for ($depth = 0; $depth < 4; $depth++) {
                if (array_key_exists('position', $source)) {
                    $position = self::position($source['position']) + ['source_id' => $source['id']]; break;
                }
                if ($source['parent_id'] === '') { break; }
                $source = $nodes[$source['parent_id']];
            }
            $result[$node['id']] = $position;
        }
        return $result;
    }

    public static function audience(array $directory, $selection, array $catalog, int $now): array
    {
        $area = self::selection($selection); $positions = self::positions($directory);
        $selected = []; $included = []; $excluded = [];
        foreach ($directory['nodes'] as $node) {
            $position = $positions[$node['id']];
            $reason = $position === null ? 'unlocated' : ($position['reviewed_at'] > $now + 60 ? 'future_review'
                : ($position['reviewed_at'] < $now - $area['max_age_days'] * 86400 ? 'stale'
                    : (self::contains($area, $position) ? '' : 'outside_area')));
            $row = ['id' => $node['id'], 'path' => LocationDirectory::path($directory, $node['id']), 'position' => $position];
            if ($reason !== '') { $excluded[] = $row + ['reason' => $reason]; continue; }
            $selected[] = $node; $included[] = $row;
        }
        return LocationDirectory::members($directory, $selected, $catalog) + [
            'path' => 'Geographic area', 'selection' => $area, 'included_locations' => $included, 'excluded_locations' => $excluded];
    }

    public static function snapshot($value): array
    {
        $row = self::object($value, ['selection', 'locations'], 'Geographic audience snapshot');
        $row['selection'] = self::selection($row['selection']); $locations = $row['locations']; $seen = [];
        if (!is_array($locations) || !array_is_list($locations) || !$locations || count($locations) > LocationDirectory::MAX_NODES) {
            throw new \InvalidArgumentException('Geographic snapshot must contain 1 to 500 selected locations.');
        }
        foreach ($locations as &$location) {
            $location = self::object($location, ['id', 'position'], 'Geographic snapshot location');
            $id = LocationDirectory::id($location['id']);
            if (isset($seen[$id])) { throw new \InvalidArgumentException('Geographic snapshot contains a duplicate location.'); }
            $seen[$id] = true;
            $position = self::object($location['position'], ['latitude', 'longitude', 'reviewed_at', 'source_id'], 'Geographic snapshot coordinates');
            $sourceId = LocationDirectory::id($position['source_id']); unset($position['source_id']);
            $location['position'] = self::position($position) + ['source_id' => $sourceId];
            if (!self::contains($row['selection'], $location['position'])) { throw new \InvalidArgumentException('Geographic snapshot contains a location outside its selected area.'); }
        }
        unset($location); $row['locations'] = $locations;
        return $row;
    }
}
