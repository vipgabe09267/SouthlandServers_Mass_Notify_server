<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Bounded calendar expansion. Configuration only; no delivery or file access. */
final class ScheduleCalendar
{
    public const MAX_RULES = 100;
    public const MAX_IMPORT_BYTES = 262144;

    private static function date($value): string
    {
        if (!is_string($value) || !preg_match('/^(20\d\d|21\d\d)-(\d{2})-(\d{2})$/D', $value, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \DomainException('Use a valid calendar date in YYYY-MM-DD format, from 2000 through 2199.');
        }
        return $value;
    }

    private static function label($value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 80 || preg_match('/\p{C}/u', $value)) {
            throw new \DomainException('Calendar reasons must be plain text of at most 80 characters.');
        }
        return trim($value);
    }

    public static function fromForm(array $input): array
    {
        $raw = $input['schedule_calendar_json'] ?? null;
        if (($input['schedule_calendar_complete'] ?? '') !== '1' || !is_string($raw) || strlen($raw) > 65536) {
            throw new \DomainException('The calendar editor is incomplete. Reload the page and review its dates before saving.');
        }
        try { $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \DomainException('The calendar settings could not be read. Reload the editor and try again.'); }
        if (!is_array($data)) { throw new \DomainException('Calendar settings must be an object.'); }
        return self::validate($data);
    }

    public static function validate(array $calendar): array
    {
        if (array_diff(array_keys($calendar), ['until', 'weekdays', 'exclusions', 'overrides'])) {
            throw new \DomainException('The calendar contains unsupported settings.');
        }
        $until = self::date($calendar['until'] ?? null);
        $weekdays = $calendar['weekdays'] ?? null;
        if (!is_array($weekdays) || !array_is_list($weekdays) || !$weekdays || count($weekdays) > 7) {
            throw new \DomainException('Select at least one weekday for the calendar pattern.');
        }
        $seen = [];
        foreach ($weekdays as $day) {
            if (!is_int($day) || $day < 1 || $day > 7 || isset($seen[$day])) { throw new \DomainException('Calendar weekdays must be distinct numbers from 1 (Monday) through 7 (Sunday).'); }
            $seen[$day] = true;
        }
        sort($weekdays);
        $result = ['until'=>$until, 'weekdays'=>$weekdays, 'exclusions'=>[], 'overrides'=>[]];
        foreach (['exclusions', 'overrides'] as $kind) {
            $rows = $calendar[$kind] ?? [];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_RULES) {
                throw new \DomainException('A calendar supports at most 100 holiday ranges and 100 date overrides.');
            }
            $seen = [];
            foreach ($rows as $row) {
                $keys = $kind === 'exclusions' ? ['start', 'end', 'reason'] : ['date', 'time', 'reason'];
                if (!is_array($row) || array_diff(array_keys($row), $keys)) { throw new \DomainException('A calendar rule contains unsupported fields.'); }
                $reason = self::label($row['reason'] ?? '');
                if ($kind === 'exclusions') {
                    $start = self::date($row['start'] ?? null); $end = self::date($row['end'] ?? null);
                    if ($end < $start) { throw new \DomainException('A holiday range ends before it starts.'); }
                    $key = $start . '/' . $end;
                    $clean = ['start'=>$start, 'end'=>$end, 'reason'=>$reason];
                } else {
                    $date = self::date($row['date'] ?? null); $time = $row['time'] ?? null;
                    if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                        throw new \DomainException('Choose a valid local time for each date override. Use a holiday range to skip a date.');
                    }
                    $key = $date; $clean = ['date'=>$date, 'time'=>$time, 'reason'=>$reason];
                }
                if (isset($seen[$key])) { throw new \DomainException('The calendar contains a duplicate holiday range or date override.'); }
                $seen[$key] = true; $result[$kind][] = $clean;
            }
            usort($result[$kind], static fn($a, $b) => strcmp($a['date'] ?? $a['start'], $b['date'] ?? $b['start']));
        }
        return $result;
    }

    public static function build(string $id, array $inputs, array $calendar, \DateTimeZone $timezone,
        array $existing, int $minimum, int $maximum, int $limit, callable $resolve): array
    {
        $result = ['occurrences'=>[], 'recurrence'=>['mode'=>'calendar', 'starts_at_local'=>''], 'errors'=>[]];
        try {
            if (count($inputs) !== 1 || !is_string($inputs[0])) { throw new \DomainException('A calendar pattern needs one starting date and time.'); }
            $calendar = self::validate($calendar); $start = $inputs[0];
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})T([0-2]\d:[0-5]\d)$/D', $start, $parts)) { throw new \DomainException('Choose a valid starting date and time for the calendar pattern.'); }
            $first = self::date($parts[1]); $baseTime = $parts[2];
            if ((int)substr($baseTime, 0, 2) > 23) { throw new \DomainException('The starting hour must be from 00 through 23.'); }
            $cursor = new \DateTimeImmutable($first, new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($calendar['until'], new \DateTimeZone('UTC'));
            if ($end < $cursor || $end->getTimestamp() - $cursor->getTimestamp() > 5 * 366 * 86400) {
                throw new \DomainException('The calendar end date must follow its start and be within five years.');
            }
            $overrides = array_column($calendar['overrides'], null, 'date'); $used = [];
            for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                if (!in_array((int)$cursor->format('N'), $calendar['weekdays'], true)) { continue; }
                foreach ($calendar['exclusions'] as $range) {
                    if ($date >= $range['start'] && $date <= $range['end']) { continue 2; }
                }
                $local = $date . 'T' . ($overrides[$date]['time'] ?? $baseTime);
                if (isset($overrides[$date])) { $used[$date] = true; }
                $resolved = $resolve($local, $timezone);
                if (empty($resolved['success'])) { throw new \DomainException((string)($resolved['message'] ?? 'A calendar time is invalid.')); }
                $timestamp = (int)$resolved['timestamp']; $utc = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                if ($timestamp < $minimum && !isset($existing[$utc])) { throw new \DomainException('The calendar would add a past occurrence. Move its starting date forward or preserve the original saved times.'); }
                if ($timestamp > $maximum) { throw new \DomainException('Calendar dates cannot be more than five years in the future.'); }
                $result['occurrences'][] = ['id'=>'occ_' . substr(hash('sha256', $id . '|' . $utc), 0, 20), 'local_datetime'=>$local, 'run_at_utc'=>$utc];
                if (count($result['occurrences']) > $limit) { throw new \DomainException('The calendar creates more than ' . $limit . ' occurrences. Choose an earlier end date. No dates were silently omitted.'); }
            }
            if (array_diff_key($overrides, $used)) { throw new \DomainException('A time override falls outside the calendar pattern or on an excluded date. Remove that override or adjust the weekday/holiday rules.'); }
            if (!$result['occurrences']) { throw new \DomainException('The calendar has no delivery dates after applying its weekday and holiday rules.'); }
            $result['recurrence'] = ['mode'=>'calendar', 'starts_at_local'=>$start, 'calendar'=>$calendar];
        } catch (\DomainException $error) { $result['errors'][] = $error->getMessage(); $result['occurrences'] = []; }
        return $result;
    }

    /** Import reviewed all-day exclusions only. No URLs or recurrence expansion. */
    public static function import(string $raw, string $format): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_IMPORT_BYTES || !mb_check_encoding($raw, 'UTF-8') || strpos($raw, "\0") !== false) {
            throw new \DomainException('Choose a UTF-8 calendar file of at most 256 KiB.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); $rows = [];
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+'); fwrite($stream, $raw); rewind($stream);
            try {
                if (fgetcsv($stream, 8192, ',', '"', '') !== ['start', 'end', 'reason']) { throw new \DomainException('CSV must start with the header start,end,reason. Dates use YYYY-MM-DD and the end date is inclusive.'); }
                while (($row = fgetcsv($stream, 8192, ',', '"', '')) !== false) {
                    if ($row === [null]) { continue; }
                    if (count($row) !== 3) { throw new \DomainException('Each CSV row needs start,end,reason.'); }
                    $rows[] = ['start'=>$row[0], 'end'=>$row[1], 'reason'=>$row[2]];
                    if (count($rows) > self::MAX_RULES) { throw new \DomainException('Import at most 100 holiday ranges at once.'); }
                }
            } finally { fclose($stream); }
        } elseif ($format === 'ics') {
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $raw = preg_replace('/\n[ \t]/', '', $raw); // RFC 5545 line unfolding.
            $lines = explode("\n", trim($raw)); $stack = []; $event = null; $calendarCount = 0;
            foreach ($lines as $line) {
                if ($line === '') { continue; }
                if (strlen($line) > 8192 || strpos($line, ':') === false) { throw new \DomainException('The iCalendar file has a malformed or oversized line.'); }
                [$property, $value] = explode(':', $line, 2); $property = strtoupper($property);
                if ($property === 'BEGIN') {
                    if (($value === 'VCALENDAR' && (!$stack && ++$calendarCount === 1)) || ($value === 'VEVENT' && $stack === ['VCALENDAR'])) {
                        $stack[] = $value; if ($value === 'VEVENT') { $event = []; } continue;
                    }
                    throw new \DomainException('Import a calendar containing only all-day events. Alarms, time zones and nested components are not supported.');
                }
                if ($property === 'END') {
                    if (!$stack || end($stack) !== $value) { throw new \DomainException('The iCalendar component boundaries do not match.'); }
                    if ($value === 'VEVENT') {
                        if (strtoupper($event['STATUS'] ?? '') === 'CANCELLED') { throw new \DomainException('Remove cancelled events before importing holiday dates.'); }
                        if (!isset($event['DTSTART;VALUE=DATE'])) { throw new \DomainException('Import all-day events using DTSTART;VALUE=DATE. Timed events are not holiday ranges.'); }
                        $start = self::icsDate($event['DTSTART;VALUE=DATE']);
                        $end = isset($event['DTEND;VALUE=DATE']) ? self::icsDate($event['DTEND;VALUE=DATE']) : (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
                        if ($end <= $start) { throw new \DomainException('An all-day event has an invalid end date. iCalendar end dates are exclusive.'); }
                        $end = (new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
                        $reason = preg_replace_callback('/\\\\([nN,;\\\\])/', static fn($m) => in_array($m[1], ['n', 'N'], true) ? ' ' : $m[1], $event['SUMMARY'] ?? '');
                        $rows[] = ['start'=>$start, 'end'=>$end, 'reason'=>$reason]; $event = null;
                        if (count($rows) > self::MAX_RULES) { throw new \DomainException('Import at most 100 all-day events at once.'); }
                    }
                    array_pop($stack); continue;
                }
                if (!$stack) { throw new \DomainException('The file contains data outside its calendar.'); }
                $name = explode(';', $property, 2)[0];
                if ($name === 'VERSION' && $value !== '2.0') { throw new \DomainException('Import an iCalendar 2.0 calendar.'); }
                if (in_array($name, ['RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'DURATION'], true)
                    || ($name === 'METHOD' && strtoupper($value) !== 'PUBLISH')) {
                    throw new \DomainException('Recurring events, invitations and duration-based events require explicit all-day dates before import.');
                }
                if ($event !== null && in_array($name, ['DTSTART', 'DTEND', 'SUMMARY', 'STATUS'], true)) {
                    $expected = in_array($name, ['DTSTART', 'DTEND'], true) ? $name . ';VALUE=DATE' : $name;
                    if ($property !== $expected || isset($event[$property])) { throw new \DomainException('An imported event uses unsupported date parameters or duplicate fields.'); }
                    $event[$property] = $value;
                }
                // Descriptions, attachments and URLs are intentionally not fetched or retained.
            }
            if ($stack || $calendarCount !== 1) { throw new \DomainException('The iCalendar file is incomplete.'); }
        } else { throw new \DomainException('Choose an .ics or .csv holiday calendar.'); }
        if (!$rows) { throw new \DomainException('The file contains no holiday dates.'); }
        return self::validate(['until'=>'2199-12-31', 'weekdays'=>[1], 'exclusions'=>$rows])['exclusions'];
    }

    private static function icsDate(string $value): string
    {
        if (!preg_match('/^\d{8}$/D', $value)) { throw new \DomainException('All-day iCalendar dates must use YYYYMMDD.'); }
        return self::date(substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2));
    }
}
