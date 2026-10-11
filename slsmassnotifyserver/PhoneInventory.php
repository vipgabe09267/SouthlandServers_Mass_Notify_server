<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Display snapshot only. Sending still checks current registration and authorization. */
final class PhoneInventory
{
    public static function read(string $path, ?int $now = null): array
    {
        $now ??= time();
        $unavailable = ['available'=>false, 'display_only'=>true, 'observed_at'=>'', 'endpoints'=>[]];
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || $before['size'] > 4*1024*1024 || ($before['mode'] & 0007) !== 0) { return $unavailable; }
        $handle = @fopen($path, 'rb');
        if (!$handle) { return $unavailable; }
        try {
            $actual = fstat($handle);
            if (!$actual || $actual['dev'] !== $before['dev'] || $actual['ino'] !== $before['ino']
                || ($actual['mode'] & 0170000) !== 0100000 || $actual['nlink'] !== 1
                || ($actual['mode'] & 0007) !== 0 || $actual['size'] > 4*1024*1024) { return $unavailable; }
            $raw = stream_get_contents($handle, 4*1024*1024+1);
        } finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > 4*1024*1024) { return $unavailable; }
        $value = json_decode($raw, true);
        if (!is_array($value) || ($value['schema'] ?? null) !== 1 || ($value['display_only'] ?? null) !== true
            || !is_array($value['endpoints'] ?? null) || count($value['endpoints']) > 10000
            || !is_string($value['observed_at'] ?? null) || !is_numeric($value['expires_at'] ?? null)
            || !is_finite((float)$value['expires_at']) || $value['expires_at'] <= $now || $value['expires_at'] > $now+100) { return $unavailable; }
        $observed = strtotime($value['observed_at']);
        if ($observed === false || $observed > $now+5 || $observed < $now-100) { return $unavailable; }
        foreach ($value['endpoints'] as $extension=>$row) {
            if (!preg_match('/^[0-9]{1,20}$/D', (string)$extension) || !is_array($row)
                || !is_array($row['contacts'] ?? null) || count($row['contacts']) > 1000
                || !is_array($row['formats'] ?? null) || count($row['formats']) > 1000
                || !is_string($row['format'] ?? null) || !is_string($row['user_agent'] ?? null)) { return $unavailable; }
            foreach ($row['contacts'] as $contact) {
                if (!is_array($contact) || !is_string($contact['format'] ?? null)
                    || !is_string($contact['user_agent'] ?? null)) { return $unavailable; }
            }
            foreach ($row['formats'] as $format) { if (!is_string($format)) { return $unavailable; } }
        }
        return $value + ['available'=>true];
    }
}
