<?php
declare(strict_types=1);
namespace SLS\MassNotify;

/** Read-only projection of weather transport evidence; never exposes addresses or URLs. */
final class WeatherDeliveryReceipts
{
    private static array $phoneCache = [];

    private static function phoneState(string $path, int $owner, int $group): array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!$before) { return []; }
        if (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || ($before['mode'] & 0137)
            || !in_array($before['uid'], [0, $owner], true) || $before['gid'] !== $group || $before['size'] > 16777216) {
            throw new \RuntimeException('Unsafe phone delivery record.');
        }
        $fields = ['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size', 'mtime', 'ctime'];
        $identity = array_map(static fn(string $field): int => $before[$field], $fields);
        // The native writer atomically replaces the ledger. Reuse only this
        // request's protected inode snapshot; no permission decision is cached.
        if ((self::$phoneCache[$path]['identity'] ?? null) === $identity) { return self::$phoneCache[$path]['state']; }
        $limit = trim((string)ini_get('memory_limit'));
        if (preg_match('/^([0-9]+)([KMG]?)$/iD', $limit, $match)) {
            $factor = [''=>1, 'K'=>1024, 'M'=>1048576, 'G'=>1073741824][strtoupper($match[2])];
            if ((int)$match[1] * $factor - memory_get_usage(true) < $before['size'] * 20 + 1048576) {
                throw new \RuntimeException('Phone history exceeds this request memory budget.');
            }
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new \RuntimeException('Phone delivery record could not be opened.'); }
        try {
            $opened = fstat($handle);
            $after = @lstat($path);
            if (!$opened || !$after || !flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Phone delivery record is unavailable.');
            }
            foreach ($fields as $field) {
                if ($before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]) {
                    throw new \RuntimeException('Phone delivery record identity changed.');
                }
            }
            $raw = stream_get_contents($handle, 16777217);
            clearstatcache(true, $path); $opened = fstat($handle); $after = @lstat($path);
            foreach ($fields as $field) {
                if (!$opened || !$after || $before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]) {
                    throw new \RuntimeException('Phone delivery record changed while reading.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $before['size'] || strlen($raw) > 16777216) {
                throw new \RuntimeException('Phone delivery record is incomplete.');
            }
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['schema'] ?? null) !== 1 || !is_array($state['batches'] ?? null)
                || count($state['batches']) > 2000) {
                throw new \RuntimeException('Invalid phone delivery record.');
            }
            foreach ($state['batches'] as $token => $batch) {
                if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($batch)) {
                    throw new \RuntimeException('Invalid phone delivery batch.');
                }
            }
            if (count(self::$phoneCache) >= 4) { array_shift(self::$phoneCache); }
            self::$phoneCache[$path] = ['identity'=>$identity, 'state'=>$state];
            return $state;
        } finally { fclose($handle); }
    }

    private static function voicemailRows(string $directory, array $parent, array $event, string $provider,
                                          string $identifier, array $groups, int $deadline, array &$result): void
    {
        try { $state = self::phoneState($directory.'/phone-admission.json', $parent['uid'], $parent['gid']); }
        catch (\Throwable $error) {
            $result['warnings'][] = 'Voicemail delivery evidence is unavailable. Review protected phone history and its size.';
            return;
        }
        foreach ($state['batches'] ?? [] as $sourceToken => $source) {
            if (hrtime(true) > $deadline) {
                $result['warnings'][] = 'Voicemail delivery history reached its read limit. Some results may be missing.';
                return;
            }
            $fallback = $source['vm_fallback'] ?? null;
            $scope = is_array($fallback) ? ($fallback['scope'] ?? '') : '';
            $context = is_array($fallback) ? ($fallback['weather_context']['source_validity'] ?? null) : null;
            if (($source['purpose'] ?? 'audio') !== 'audio' || ($source['status'] ?? '') !== 'closed'
                || ($source['service'] ?? '') !== ($provider === 'nws' ? 'nws' : 'lightning')
                || !is_string($scope) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $scope)
                || !is_array($context) || ($context['provider'] ?? '') !== $provider || ($context['group_id'] ?? '') !== $scope
                || ($context['version'] ?? null) !== 1 || ($fallback['schema'] ?? null) !== 1
                || !is_string($source['generation'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $source['generation'])
                || !is_string($source['boot_id'] ?? null) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $source['boot_id'])
                || ($source['correlation'] ?? '') !== 'vm-'.hash('sha256', $scope.'|'.$identifier)
                || !is_array($fallback['targets'] ?? null) || count($fallback['targets']) > 1000) { continue; }
            if ($provider === 'nws' && (($context['provider_event_id'] ?? '') !== $identifier
                || ($context['zone'] ?? '') !== ($event['zone'] ?? ''))) { continue; }
            foreach ($fallback['targets'] as $extension => $selected) {
                if (count($result['rows']) >= 100) {
                    $result['warnings'][] = 'Only the first 100 matching destination results are shown.';
                    return;
                }
                $recipient = (string)$extension;
                $claim = is_array($selected) ? ($selected['claimed_token'] ?? null) : null;
                $label = is_string($groups[$scope] ?? null) ? substr($groups[$scope], 0, 100) : $scope;
                $refusal = $source['voicemail_failures'][$extension] ?? '';
                if (preg_match('/^[0-9]{1,20}$/D', $recipient) && $claim === null && is_string($refusal)
                    && in_array($refusal, ['phone_voicemail_audio_invalid', 'phone_voicemail_audio_unavailable', 'phone_voicemail_audio_capacity',
                        'phone_voicemail_mailbox_duration', 'phone_voicemail_mailbox_silence', 'phone_voicemail_mailbox_policy_unavailable',
                        'phone_voicemail_mailbox_changed', 'phone_voicemail_recording_unconfirmed', 'phone_voicemail_cluster_fenced'], true)) {
                    $result['rows'][] = ['channel'=>'FreePBX voicemail', 'group'=>$label.' · Extension '.$recipient,
                        'status'=>'failed', 'http_status'=>null, 'error'=>$refusal, 'recorded_at'=>'', 'queue_state'=>'not_submitted'];
                    continue;
                }
                if (!preg_match('/^[0-9]{1,20}$/D', $recipient) || !is_string($claim)
                    || !preg_match('/^[a-f0-9]{32}$/D', $claim)) { continue; }
                $child = $state['batches'][$claim] ?? [];
                $proof = is_array($child) ? ($child['voicemail'] ?? null) : null;
                $expectedSound = 'SLS_Mass_Notifications_Plugin/voicemail/'.$sourceToken;
                $valid = is_array($proof) && ($child['purpose'] ?? '') === 'voicemail'
                    && ($child['service'] ?? '') === $source['service'] && ($child['correlation'] ?? '') === $source['correlation']
                    && ($child['generation'] ?? null) === ($source['generation'] ?? null)
                    && ($child['boot_id'] ?? null) === ($source['boot_id'] ?? null)
                    && ($proof['source_token'] ?? '') === $sourceToken && ($proof['sound'] ?? '') === $expectedSound
                    && ($fallback['sound'] ?? '') === $expectedSound
                    && is_string($selected['mailbox'] ?? null)
                    && preg_match('/^'.preg_quote($recipient, '/').'@[A-Za-z0-9_-]{1,80}$/D', $selected['mailbox'])
                    && ($proof['mailbox'] ?? '') === $selected['mailbox'] && is_array($child['targets'] ?? null)
                    && count($child['targets']) === 1 && ($child['targets'][$extension]['dials'] ?? null) === ['VM/'.$selected['mailbox']]
                    && is_array($child['channels'] ?? null) && count($child['channels']) <= 4000;
                $native = $valid && is_array($proof['result'] ?? null) ? $proof['result'] : [];
                $nativeStatus = $native['status'] ?? '';
                $recorded = $native['recorded_at'] ?? null;
                $nativeValid = (is_int($recorded) || is_float($recorded)) && is_finite((float)$recorded)
                    && $recorded > 0 && $recorded <= 253402300799;
                $origins = [];
                foreach ($valid && is_array($child['channels'] ?? null) ? $child['channels'] : [] as $channel) {
                    if (is_array($channel) && ($channel['kind'] ?? '') === 'origin'
                        && (string)($channel['recipient'] ?? '') === $recipient) { $origins[] = $channel; }
                }
                $certain = $valid && empty($source['outcome_uncertain']) && empty($child['outcome_uncertain'])
                    && empty($source['external_event_gap']) && empty($source['runtime_fault']) && empty($child['runtime_fault'])
                    && count($origins) === 1 && empty($origins[0]['outcome_uncertain'])
                    && is_string($origins[0]['channel'] ?? null)
                    && preg_match('/^Local\/'.preg_quote($recipient, '/').'@sls-alert-voicemail-[a-f0-9]{1,32};2$/D', $origins[0]['channel']);
                $saved = $certain && $nativeValid && $nativeStatus === 'SUCCESS' && ($proof['playback_completed'] ?? false) === true;
                $failed = $valid && $nativeValid && in_array($nativeStatus, ['FAILED', 'USEREXIT'], true);
                $pending = $valid && !$native && in_array($child['status'] ?? '', ['reserved', 'active'], true);
                $result['rows'][] = ['channel'=>'FreePBX voicemail', 'group'=>$label.' · Extension '.$recipient,
                    'status'=>$saved ? 'saved_in_FreePBX_voicemail' : ($failed ? 'failed' : ($pending ? 'queued' : 'unconfirmed')),
                    'http_status'=>null, 'error'=>$failed ? 'voicemail_deposit_failed' : ($saved || $pending ? '' : 'voicemail_evidence_unavailable'),
                    'recorded_at'=>$nativeValid ? gmdate('c', (int)$recorded) : '',
                    'queue_state'=>in_array($child['status'] ?? '', ['reserved', 'active', 'closed'], true) ? $child['status'] : 'unknown'];
            }
        }
    }

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
        self::voicemailRows($directory, $parent, $event, $provider, $identifier, $groups, $deadline, $result);
        $result['warnings'] = array_values(array_unique($result['warnings']));
        return $result;
    }
}
