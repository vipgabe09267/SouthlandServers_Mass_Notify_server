<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Bounded, newest-first JSONL pages. A cursor refers to one frozen file prefix. */
final class EventLog
{
    private const MAX_BYTES = 524288;

    private static function bytes($handle, int $offset, int $length): string
    {
        if ($length === 0) { return ''; }
        if (fseek($handle, $offset) !== 0) { throw new \RuntimeException('event_log_read_failed'); }
        $value = fread($handle, $length);
        if (!is_string($value) || strlen($value) !== $length) { throw new \RuntimeException('event_log_changed'); }
        return $value;
    }

    private static function fingerprint($handle, int $ceiling): string
    {
        $length = min(256, $ceiling);
        return hash('sha256', self::bytes($handle, 0, $length) . self::bytes($handle, $ceiling - $length, $length));
    }

    private static function decode(string $cursor): array
    {
        if (strlen($cursor) > 768 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
            throw new \InvalidArgumentException('invalid_event_cursor');
        }
        $value = json_decode((string)base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (!is_array($value) || ($value['v'] ?? null) !== 1 || count($value) !== 7) {
            throw new \InvalidArgumentException('invalid_event_cursor');
        }
        foreach (['dev', 'ino', 'end', 'before'] as $key) {
            if (!is_int($value[$key] ?? null) || $value[$key] < 0) { throw new \InvalidArgumentException('invalid_event_cursor'); }
        }
        foreach (['prefix', 'boundary'] as $key) {
            if (!is_string($value[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $value[$key])) {
                throw new \InvalidArgumentException('invalid_event_cursor');
            }
        }
        if ($value['before'] > $value['end']) { throw new \InvalidArgumentException('invalid_event_cursor'); }
        return $value;
    }

    public static function page(string $path, int $limit = 25, string $cursor = ''): array
    {
        $limit = min(100, max(1, $limit));
        $position = $cursor !== '' ? self::decode($cursor) : null;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            if ($position !== null) { throw new \RuntimeException('event_log_changed'); }
            return ['events'=>[], 'next_cursor'=>null, 'has_more'=>false, 'scan_limited'=>false];
        }
        if (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) {
            throw new \RuntimeException('event_log_unsafe');
        }
        // Opening read/write cannot wait indefinitely on a substituted FIFO.
        // This reader never writes; normal PBX logs are owned by the web user.
        $handle = @fopen($path, 'r+b');
        if ($handle === false) { throw new \RuntimeException('event_log_unavailable'); }
        try {
            $metadata = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($metadata) || !is_array($current)
                || ($metadata['mode'] & 0170000) !== 0100000 || $metadata['nlink'] !== 1
                || $metadata['dev'] !== $before['dev'] || $metadata['ino'] !== $before['ino']
                || $current['dev'] !== $metadata['dev'] || $current['ino'] !== $metadata['ino']) {
                throw new \RuntimeException('event_log_changed');
            }
            if (!flock($handle, LOCK_SH | LOCK_NB)) { throw new \RuntimeException('event_log_busy'); }
            $metadata = fstat($handle);
            if ($position !== null && ($position['dev'] !== $metadata['dev'] || $position['ino'] !== $metadata['ino']
                || $position['end'] > $metadata['size'] || $position['prefix'] !== self::fingerprint($handle, $position['end'])
                || $position['boundary'] !== hash('sha256', self::bytes($handle, max(0, $position['before'] - 256), min(256, $position['before']))))) {
                throw new \RuntimeException('event_log_changed');
            }
            $end = $position['end'] ?? (int)$metadata['size'];
            $offset = $position['before'] ?? $end;
            $data = ''; $start = $offset;
            while ($start > 0 && strlen($data) < self::MAX_BYTES && substr_count($data, "\n") <= $limit) {
                $length = min(8192, $start, self::MAX_BYTES - strlen($data));
                $start -= $length;
                $data = self::bytes($handle, $start, $length) . $data;
            }
            $limited = strlen($data) === self::MAX_BYTES && $start > 0;
            // Skip only an incomplete leading record, and the unfinished last
            // record of an initial snapshot. Never decode either fragment.
            $first = $start > 0 ? strpos($data, "\n") : -1;
            $begin = $first === false ? strlen($data) : $first + 1;
            $last = strrpos($data, "\n");
            $finish = $last === false ? 0 : $last + 1;
            $events = []; $next = $start + $begin;
            while ($finish > $begin && count($events) < $limit) {
                $prior = strrpos(substr($data, $begin, $finish - $begin - 1), "\n");
                $lineStart = $prior === false ? $begin : $begin + $prior + 1;
                $line = substr($data, $lineStart, $finish - $lineStart - 1);
                $decoded = strlen($line) <= 65536 ? json_decode($line, true, 32) : null;
                if (is_array($decoded)) { $events[] = $decoded; }
                $next = $start + $lineStart;
                $finish = $lineStart;
            }
            // An oversized line may fill a whole window. Advance through it
            // explicitly, reporting that the page hit its scan budget.
            if ($next >= $offset && $start < $offset) { $next = $start; $limited = true; }
            $token = null;
            if ($next > 0) {
                $value = ['v'=>1, 'dev'=>$metadata['dev'], 'ino'=>$metadata['ino'], 'end'=>$end,
                    'before'=>$next, 'prefix'=>self::fingerprint($handle, $end),
                    'boundary'=>hash('sha256', self::bytes($handle, max(0, $next - 256), min(256, $next)))];
                $token = rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            }
            return ['events'=>$events, 'next_cursor'=>$token, 'has_more'=>$token !== null, 'scan_limited'=>$limited];
        } finally { fclose($handle); }
    }
}
