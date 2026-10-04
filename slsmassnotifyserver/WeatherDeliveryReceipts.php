<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Read-only projection of weather transport evidence; never exposes addresses or URLs. */
final class WeatherDeliveryReceipts
{
    private static function read(string $path, int $owner, bool $weather = true): array
    {
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || ($before['mode'] & 0022) || !in_array($before['uid'], [0, $owner], true) || $before['size'] > 4194304) {
            throw new \RuntimeException('Unsafe or oversized weather delivery record.');
        }
        $handle = @fopen($path, 'r+b');
        if (!$handle) { throw new \RuntimeException('Weather delivery record could not be opened.'); }
        try {
            $opened = fstat($handle); $after = @lstat($path);
            if (!$opened || !$after || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino'] || $after['dev'] !== $opened['dev'] || !flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Weather delivery record identity changed.');
            }
            $raw = stream_get_contents($handle, 4194305);
            clearstatcache(true, $path); $after = @lstat($path); $opened = fstat($handle);
            foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
                if (!$opened || !$after || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]) {
                    throw new \RuntimeException('Weather delivery record changed while reading.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $before['size'] || strlen($raw) > 4194304) {
                throw new \RuntimeException('Weather delivery record is incomplete or oversized.');
            }
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!$weather) {
                if (!is_array($value) || !preg_match('/^job_[a-f0-9]{32}$/D', (string)($value['id']??'')) || !is_array($value['receipts']??[]) || count($value['receipts']??[])>3000) { throw new \RuntimeException('Invalid Weather channel job.'); }
                return $value;
            }
            if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_array($value['deliveries'] ?? null)
                || count($value['deliveries']) > 500) {
                throw new \RuntimeException('Weather delivery record has an unsupported format.');
            }
            foreach ($value['deliveries'] as $record) {
                if (!is_array($record) || !is_array($record['payload'] ?? null)
                    || !is_array($record['source_validity'] ?? null) || !is_array($record['routing_snapshot'] ?? null)
                    || !is_array($record['routing_snapshot']['webhook_ids'] ?? [])
                    || !is_array($record['destination_receipts'] ?? [])) {
                    throw new \RuntimeException('Weather delivery record contains invalid destination evidence.');
                }
            }
            return $value;
        } finally { fclose($handle); }
    }

    public static function forEvent(string $directory, array $event, array $settings = []): array
    {
        $result = ['rows'=>[], 'warnings'=>[]];
        $provider = ['nws'=>'nws', 'xweather'=>'xweather'][$event['type'] ?? ''] ?? '';
        $identifier = $provider === 'nws' ? ($event['alert_id'] ?? '') : ($event['event_id'] ?? '');
        if ($provider === '' || !is_string($identifier) || $identifier === '' || strlen($identifier) > 2048) { return $result; }
        $parent = @lstat($directory);
        if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0022) || realpath($directory) !== $directory) {
            $result['warnings'][] = 'Weather delivery history could not be read safely. Check its protected storage directory.';
            return $result;
        }
        $names = $provider === 'nws' ? ['nws-external-deliveries.json'] : ['xweather-external-deliveries.json'];
        $deadline = hrtime(true) + 1000000000; $bytes = 0;
        if ($provider === 'nws') {
            $scan = @opendir($directory);
            if (!$scan) { $result['warnings'][] = 'Weather delivery history directory is temporarily unavailable.'; return $result; }
            try {
                $scanned = 0;
                while (($name = readdir($scan)) !== false) {
                    if (++$scanned > 512 || hrtime(true) > $deadline || count($names) > 64) {
                        $result['warnings'][] = 'Weather delivery history reached its directory limit. Some destinations may be missing.'; break;
                    }
                    if (preg_match('/^external-deliveries-[A-Za-z0-9_-]{1,64}\.json$/D', $name)) { $names[] = $name; }
                }
            } finally { closedir($scan); }
        }
        $groups = array_column($provider === 'nws' ? ($settings['nws_zones'] ?? []) : ($settings['xweather']['groups'] ?? []), 'name', 'id');
        foreach (array_slice(array_unique($names), 0, 64) as $name) {
            $path = $directory.'/'.$name;
            if (!file_exists($path) && !is_link($path)) { continue; }
            if (hrtime(true) > $deadline || $bytes > 33554432) {
                $result['warnings'][] = 'Weather delivery history reached its read limit. Some destinations may be missing.'; break;
            }
            try { $state = self::read($path, $parent['uid']); $bytes += (int)filesize($path); }
            catch (\Throwable $error) { $result['warnings'][] = 'A weather delivery history file is unreadable. Review protected storage and the maintenance log.'; continue; }
            foreach ($state['deliveries'] as $record) {
                if (!is_array($record) || ($record['payload']['source'] ?? '') !== $provider
                    || ($record['payload']['event_id'] ?? '') !== $identifier) { continue; }
                if ($provider === 'nws' && ($record['source_validity']['zone'] ?? '') !== ($event['zone'] ?? '')) { continue; }
                $group = $record['source_validity']['group_id'] ?? '';
                $label = is_string($group) ? ($groups[$group] ?? $group) : '';
                $jobId = $record['channel_job_id'] ?? '';
                if (is_string($jobId) && preg_match('/^job_[a-f0-9]{32}$/D', $jobId)) {
                    $jobPath=$directory.'/announcement-jobs/'.$jobId.'.json';
                    try {
                        $jobDirectory=$directory.'/announcement-jobs'; $jobParent=@lstat($jobDirectory);
                        if (!$jobParent || ($jobParent['mode']&0170000)!==0040000 || ($jobParent['mode']&0022) || $jobParent['uid']!==$parent['uid'] || realpath($jobDirectory)!==$jobDirectory) { throw new \RuntimeException('Unsafe Weather job directory.'); }
                        $job=self::read($jobPath,$parent['uid'],false);$bytes+=(int)filesize($jobPath);
                        if (($job['request']['weather_context']['source_validity']??null)!==$record['source_validity']) { throw new \RuntimeException('Weather channel job identity differs from source.'); }
                        foreach ($job['receipts']??[] as $channelReceipt) {
                            if (!is_array($channelReceipt) || !in_array($channelReceipt['channel']??'', ['sms','external_voice'],true)) { continue; }
                            $channelStatus=$channelReceipt['state']??'not_recorded';
                            if (!is_string($channelStatus) || !preg_match('/^[a-z_]{1,40}$/D',$channelStatus)) { $channelStatus='not_recorded'; }
                            $result['rows'][]=['channel'=>$channelReceipt['channel']==='sms'?'SMS':'External call', 'group'=>substr((string)$label,0,100),
                                'status'=>$channelStatus,'http_status'=>null,'error'=>'','recorded_at'=>$job['finished_at']??'', 'queue_state'=>$job['state']??'pending'];
                            if (count($result['rows'])>=100) { break; }
                        }
                    } catch (\Throwable $error) { $result['warnings'][]='Weather SMS/call job evidence is temporarily unavailable.'; }
                }
                $keys = $record['routing_snapshot']['webhook_ids'] ?? [];
                if (!empty($record['routing_snapshot']['email_recipients'])) { $keys[] = 'email:email'; }
                $receipts = $record['destination_receipts'] ?? [];
                foreach (array_unique(array_merge($keys, array_keys(is_array($receipts) ? $receipts : []))) as $key) {
                    if (!is_string($key) || !preg_match('/^(email|discord|generic|channels):[A-Za-z0-9_-]{1,64}$/D', $key, $match)) { continue; }
                    $receipt = $receipts[$key] ?? [];
                    $status = $receipt['status'] ?? 'not_recorded';
                    if (!in_array($status, ['accepted','failed','uncertain','cancelled','queued','deferred'], true)) { $status = 'not_recorded'; }
                    $error = $receipt['error'] ?? '';
                    if (!is_string($error) || !preg_match('/^[a-z0-9_]{0,80}$/D', $error)) { $error = ''; }
                    $http = $receipt['http_status'] ?? null;
                    $result['rows'][] = ['channel'=>$match[1] === 'generic' ? 'Webhook' : ($match[1]==='channels'?'SMS/external-call job':ucfirst($match[1])),
                        'group'=>is_string($label) ? substr($label, 0, 100) : '', 'status'=>$status,
                        'http_status'=>is_int($http) && $http >= 100 && $http <= 599 ? $http : null,
                        'error'=>$error, 'recorded_at'=>is_int($receipt['recorded_at'] ?? null) ? gmdate('c', $receipt['recorded_at']) : '',
                        'queue_state'=>in_array($record['terminal_status'] ?? '', ['complete','expired','cancelled','uncertain','failed'], true)
                            ? $record['terminal_status'] : (empty($record['completed_at']) ? 'pending' : 'complete')];
                    if (count($result['rows']) >= 100) { $result['warnings'][] = 'Only the first 100 matching destination results are shown.'; break 3; }
                }
            }
        }
        $result['warnings'] = array_values(array_unique($result['warnings']));
        return $result;
    }
}
