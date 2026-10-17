<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__.'/api/sls-mass-notify/security.php';

/** Display snapshot only. Sending still checks current registration and authorization. */
final class PhoneInventory
{
    private const FORMATS = ['yealink','yealink_text','yealink_image','cisco','poly','grandstream','fanvil','snom','aastra','sangoma','avaya','vtech','ale','panasonic','generic','unknown'];

    public static function read(string $path, ?int $now = null): array
    {
        $now ??= time();
        $unavailable = ['available'=>false, 'display_only'=>true, 'observed_at'=>'', 'endpoints'=>[]];
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || $before['size'] > 4*1024*1024 || ($before['mode'] & 0027) !== 0) { return $unavailable; }
        $handle = @fopen($path, 'rb');
        if (!$handle) { return $unavailable; }
        try {
            $actual = fstat($handle);
            if (!$actual || $actual['dev'] !== $before['dev'] || $actual['ino'] !== $before['ino']
                || ($actual['mode'] & 0170000) !== 0100000 || $actual['nlink'] !== 1
                || ($actual['mode'] & 0027) !== 0 || $actual['size'] > 4*1024*1024
                || !flock($handle, LOCK_SH | LOCK_NB)) { return $unavailable; }
            $raw = stream_get_contents($handle, 4*1024*1024+1);
            $after = fstat($handle); clearstatcache(true, $path); $named = @lstat($path);
            foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field) {
                if (!is_array($after) || !is_array($named) || $actual[$field] !== $after[$field]
                    || $after[$field] !== $named[$field]) { return $unavailable; }
            }
        } finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > 4*1024*1024 || strlen($raw) !== $actual['size']) { return $unavailable; }
        try { $value = ApiSecurity::jsonObject($raw, false, 16); }
        catch (\JsonException | \InvalidArgumentException $error) { return $unavailable; }
        if (array_diff(array_keys($value), ['schema','display_only','observed_at','expires_at','endpoints'])
            || ($value['schema'] ?? null) !== 1 || ($value['display_only'] ?? null) !== true
            || !is_array($value['endpoints'] ?? null) || count($value['endpoints']) > 2000
            || !is_string($value['observed_at'] ?? null)
            || !(is_int($value['expires_at'] ?? null) || is_float($value['expires_at'] ?? null))
            || !is_finite((float)$value['expires_at']) || $value['expires_at'] <= $now || $value['expires_at'] > $now+100) { return $unavailable; }
        $fractional = str_contains($value['observed_at'], '.');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{6})?\+00:00$/D', $value['observed_at'])) { return $unavailable; }
        $observedDate = \DateTimeImmutable::createFromFormat($fractional ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $value['observed_at']);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$observedDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $observedDate->format($fractional ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP') !== $value['observed_at']) { return $unavailable; }
        $observed = $observedDate->getTimestamp();
        if ($observed > $now+5 || $observed < $now-100) { return $unavailable; }
        foreach ($value['endpoints'] as $extension=>$row) {
            if (!preg_match('/^[0-9]{1,20}$/D', (string)$extension) || !is_array($row)
                || !is_array($row['contacts'] ?? null) || count($row['contacts']) > 1000
                || !is_array($row['formats'] ?? null) || count($row['formats']) > 32
                || !is_string($row['format'] ?? null) || !in_array($row['format'],self::FORMATS,true)
                || !is_string($row['user_agent'] ?? null) || strlen($row['user_agent']) > 65536
                || preg_match('/[\x00-\x1f\x7f]/',$row['user_agent']) || !is_bool($row['override'] ?? null)) { return $unavailable; }
            $contacts = [];
            foreach ($row['contacts'] as $contact) {
                if (!is_array($contact) || !is_string($contact['format'] ?? null)
                    || !in_array($contact['format'],self::FORMATS,true)
                    || !is_string($contact['user_agent'] ?? null) || strlen($contact['user_agent']) > 8192
                    || preg_match('/[\x00-\x1f\x7f]/',$contact['user_agent'])
                    || !is_string($contact['status'] ?? null) || strlen($contact['status']) > 128 || preg_match('/[\x00-\x1f\x7f]/',$contact['status'])
                    || !is_string($contact['detected_format'] ?? null) || !in_array($contact['detected_format'],self::FORMATS,true)
                    || !is_string($contact['device_key'] ?? null) || !preg_match('/^(?:[a-f0-9]{32})?$/D',$contact['device_key'])
                    || !is_bool($contact['override'] ?? null)
                    || !in_array($contact['capability'] ?? null,['unverified_generic','vendor_template'],true)) { return $unavailable; }
                $transport = strtolower((string)($contact['transport'] ?? ''));
                if ($transport === '' && is_string($contact['contact'] ?? null) && strlen($contact['contact']) <= 8192
                    && !preg_match('/[\x00-\x1f\x7f]/', $contact['contact'])) {
                    if (preg_match('/;transport=([a-z]+)(?:[;?]|$)/i', $contact['contact'], $match)) {
                        $transport = strtolower($match[1]);
                    } elseif (stripos($contact['contact'], 'sips:') === 0) {
                        $transport = 'tls';
                    } elseif (stripos($contact['contact'], 'sip:') === 0) {
                        $transport = 'udp';
                    }
                }
                if (!in_array($transport, ['udp','tcp','tls','ws','wss'], true)) { $transport = 'unresolved'; }
                $contacts[] = [
                    'format' => $contact['format'], 'detected_format' => $contact['detected_format'],
                    'user_agent' => $contact['user_agent'], 'status' => $contact['status'],
                    'device_key' => $contact['device_key'], 'override' => $contact['override'],
                    'capability' => $contact['capability'], 'transport' => $transport,
                ];
            }
            $formats=[];
            foreach ($row['formats'] as $format) {
                if (!is_string($format) || !in_array($format,self::FORMATS,true) || isset($formats[$format])) { return $unavailable; }
                $formats[$format]=true;
            }
            if (!isset($formats[$row['format']])) { return $unavailable; }
            $value['endpoints'][$extension] = [
                'format' => $row['format'], 'formats' => array_keys($formats),
                'user_agent' => $row['user_agent'], 'override' => $row['override'],
                'contacts' => $contacts,
            ];
        }
        return $value + ['available'=>true];
    }
}
