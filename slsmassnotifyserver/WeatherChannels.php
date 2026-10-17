<?php
declare(strict_types=1);
namespace FreePBX\modules;

trait SlsWeatherChannels
{
    private function validateWeatherChannelIds($value, string $kind, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new \InvalidArgumentException('Weather channel recipients are invalid.');
        }
        $pattern = $kind === 'voice' ? '/^voice_[a-f0-9]{24}$/D' : '/^sms_[a-f0-9]{24}$/D';
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match($pattern, $id)) {
                throw new \InvalidArgumentException('Weather channel recipient identity is invalid.');
            }
        }
        if (count(array_unique($value, SORT_STRING)) !== count($value)) {
            throw new \InvalidArgumentException('Weather channel recipients contain duplicates.');
        }
        return $value;
    }

    private function validateWeatherChannelContext(array $context): array
    {
        $keys = ['channels', 'deadline_at', 'event', 'severity', 'source_validity'];
        $actual = array_keys($context);
        sort($actual, SORT_STRING);
        if ($actual !== $keys || !is_array($context['source_validity'])
            || !is_string($context['event']) || strlen($context['event']) > 256
            || !is_string($context['severity']) || strlen($context['severity']) > 64
            || !is_int($context['deadline_at']) || $context['deadline_at'] < 1
            || !is_array($context['channels'])) {
            throw new \InvalidArgumentException('Weather channel context is invalid.');
        }
        $source = $context['source_validity'];
        if (($source['version'] ?? null) !== 1
            || !in_array($source['provider'] ?? null, ['nws', 'xweather'], true)
            || !is_string($source['group_id'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $source['group_id'])) {
            throw new \InvalidArgumentException('Weather source identity is invalid.');
        }
        $channels = $context['channels'];
        $channelKeys = array_keys($channels);
        sort($channelKeys, SORT_STRING);
        if ($channelKeys !== ['fingerprints', 'sms_recipient_ids', 'voice_recipient_ids']
            || !is_array($channels['fingerprints'])) {
            throw new \InvalidArgumentException('Frozen Weather channel recipients are invalid.');
        }
        $voice = $this->validateWeatherChannelIds($channels['voice_recipient_ids'], 'voice', 1000);
        $sms = $this->validateWeatherChannelIds($channels['sms_recipient_ids'], 'sms', 50);
        $expected = [];
        foreach ($voice as $id) { $expected['voice:' . $id] = true; }
        foreach ($sms as $id) { $expected['sms:' . $id] = true; }
        if (count($channels['fingerprints']) !== count($expected)) {
            throw new \InvalidArgumentException('Frozen Weather channel fingerprints are invalid.');
        }
        foreach ($channels['fingerprints'] as $key => $fingerprint) {
            if (!is_string($key) || !isset($expected[$key]) || !is_string($fingerprint)
                || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new \InvalidArgumentException('Frozen Weather channel fingerprint is invalid.');
            }
        }
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 262144) {
            throw new \InvalidArgumentException('Weather channel context exceeds its size limit.');
        }
        return $context;
    }

    protected function startWeatherChannelAuthorizationProcess(&$pipes)
    {
        $pipes = [];
        $helper = self::RUNTIME_DIR . '/sls_weather_channels.py';
        clearstatcache(true, $helper);
        $metadata = @lstat($helper);
        $parent = @lstat(dirname($helper));
        if (!function_exists('proc_open') || !is_executable('/usr/bin/timeout')
            || !is_executable('/usr/bin/python3') || !is_array($metadata) || !is_array($parent)
            || ($metadata['mode'] & 0170000) !== 0100000 || $metadata['nlink'] !== 1
            || $metadata['uid'] !== 0 || ($metadata['mode'] & 0022) !== 0
            || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0
            || ($parent['mode'] & 0022) !== 0 || realpath(dirname($helper)) !== dirname($helper)
            || realpath($helper) !== $helper || !is_readable($helper)) {
            return false;
        }
        return @proc_open(
            ['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '10', '/usr/bin/python3', '-I', $helper, 'authorize'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            '/',
            ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8',
                'HOME' => '/var/lib/asterisk'],
            ['bypass_shell' => true]
        );
    }

    protected function weatherChannelAuthorization(array $context): array
    {
        $body = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > 262144) {
            throw new \RuntimeException('Weather channel authorization request exceeds its size limit.');
        }
        $process = $this->startWeatherChannelAuthorizationProcess($pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Weather channel authorization could not start.'); }
        $output = '';
        $offset = 0;
        $exit = -1;
        $deadline = microtime(true) + 12.0;
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        try {
            while (true) {
                if (isset($pipes[0])) {
                    $written = @fwrite($pipes[0], substr($body, $offset, 8192));
                    if ($written === false) { break; }
                    $offset += $written;
                    if ($offset === strlen($body)) { fclose($pipes[0]); unset($pipes[0]); }
                }
                $chunk = stream_get_contents($pipes[1], 131073);
                if ($chunk === false) { break; }
                $output .= $chunk;
                if (strlen($output) > 131072 || microtime(true) >= $deadline) { break; }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= (string)stream_get_contents($pipes[1], 131073);
                    $exit = (int)$status['exitcode'];
                    break;
                }
                usleep(10000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                if (function_exists('posix_kill') && (int)$status['pid'] > 1) {
                    @posix_kill(-((int)$status['pid']), 9);
                }
                @proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($process);
        }
        if ($offset !== strlen($body) || $exit !== 0 || strlen($output) > 131072) {
            throw new \RuntimeException('Weather channel source or recipient authorization is unavailable.');
        }
        try { $result = \SLS\MassNotify\ApiSecurity::jsonObject(trim($output)); }
        catch (\Throwable $error) {
            throw new \RuntimeException('Weather channel authorization returned invalid data.', 0, $error);
        }
        $keys = is_array($result) ? array_keys($result) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['reason', 'sms_recipient_ids', 'status', 'voice_recipient_ids']
            || !in_array($result['status'] ?? null, ['eligible', 'deferred', 'cancelled'], true)
            || !is_string($result['reason'] ?? null)
            || !preg_match('/^(?:|[a-z][a-z0-9_]{0,95})$/D', $result['reason'])) {
            throw new \RuntimeException('Weather channel authorization returned an invalid result.');
        }
        try {
            $result['voice_recipient_ids'] = $this->validateWeatherChannelIds($result['voice_recipient_ids'], 'voice', 1000);
            $result['sms_recipient_ids'] = $this->validateWeatherChannelIds($result['sms_recipient_ids'], 'sms', 50);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('Weather channel authorization returned invalid recipients.', 0, $error);
        }
        if ($result['status'] !== 'eligible'
            && ($result['voice_recipient_ids'] || $result['sms_recipient_ids'])) {
            throw new \RuntimeException('Weather channel authorization returned recipients for an ineligible source.');
        }
        return $result;
    }

    /** A protected local worker may queue these channels; there is no web action. */
    public function queueWeatherChannels(array $input): array
    {
        $owner = @fileowner(self::PLUGIN_DATA_DIR);
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !is_int($owner)
            || posix_geteuid() !== $owner) {
            throw new \DomainException('Weather channel submission requires the PBX runtime account.');
        }
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if ($keys !== ['body', 'context', 'key', 'title'] || !is_string($input['key'])
            || !preg_match('/^[a-f0-9]{64}$/D', $input['key']) || !is_array($input['context'])
            || !is_string($input['title']) || trim($input['title']) === '' || strlen($input['title']) > 160
            || !is_string($input['body']) || trim($input['body']) === '' || strlen($input['body']) > 3000) {
            throw new \InvalidArgumentException('Weather channel handoff is invalid.');
        }
        $context = $this->validateWeatherChannelContext($input['context']);
        $inputFingerprint = \SlsAnnouncementJobStore::requestFingerprint([
            'context'=>$context, 'title'=>trim($input['title']), 'body'=>trim($input['body']),
        ]);
        $id = 'job_' . substr(hash('sha256', 'weather-channels|' . $context['source_validity']['group_id'] . '|' . $input['key']), 0, 32);
        $activity = $this->acquireAnnouncementActivityLock(false, 10);
        $start = false;
        try {
            $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
            $lock = $store->lock($id);
            if (!$lock) {
                return ['success' => false, 'status' => 'deferred', 'message' => 'Weather channel job is busy.'];
            }
            try {
                $job = $store->read($id);
                if ($job !== null) {
                    $savedFingerprint = $job['weather_input_fingerprint'] ?? null;
                    if (!is_string($savedFingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $savedFingerprint)) {
                        $savedRequest = $job['request'] ?? [];
                        $savedFingerprint = \SlsAnnouncementJobStore::requestFingerprint([
                            'context'=>$savedRequest['weather_context'] ?? null,
                            'title'=>$savedRequest['title'] ?? null,
                            'body'=>$savedRequest['message'] ?? null,
                        ]);
                    }
                    if (!hash_equals($savedFingerprint, $inputFingerprint)) {
                        throw new \DomainException('This Weather delivery identity is already bound to different content or source evidence. Preserve both records for review; no new delivery was queued.');
                    }
                    $state = $job['state'] ?? '';
                    if ($state === 'cancelled') {
                        return ['success' => true, 'status' => 'cancelled', 'job_id' => $id,
                            'message' => $job['message'] ?? 'Weather source cancelled this delivery.'];
                    }
                    $failed = in_array($state, ['failed', 'expired'], true);
                    return ['success' => !$failed, 'status' => $failed ? 'failed' : ($state === 'complete' ? 'accepted' : 'queued'),
                        'job_id' => $id, 'message' => $failed ? ($job['message'] ?? 'Weather channel job failed; see delivery details.') : ''];
                }
                $allowed = $this->weatherChannelAuthorization($context);
                if ($allowed['status'] !== 'eligible') {
                    return ['success' => false, 'status' => $allowed['status'], 'message' => $allowed['reason']];
                }
                if (!$allowed['voice_recipient_ids'] && !$allowed['sms_recipient_ids']) {
                    return ['success' => true, 'status' => 'cancelled',
                        'message' => 'Original Weather channel recipients were removed or disabled.'];
                }
                $settings = $this->getActiveSettings();
                $voice = (bool)$allowed['voice_recipient_ids'];
                $options = ['title' => trim($input['title']), 'audio_mode' => $voice ? 'tts' : 'none',
                    'voice_recipient_ids' => $allowed['voice_recipient_ids'], 'sms_recipient_ids' => $allowed['sms_recipient_ids'],
                    'sender' => 'Weather Alerts', 'trigger_source' => 'Weather Alerts', 'is_test' => false,
                    'piper_voice' => $settings['nws_piper_voice'], 'tts_volume' => $settings['nws_tts_volume']];
                $resolved = $this->resolveAnnouncementRequest([], $input['body'], false, $voice, [], $options, $settings, 3000);
                if (empty($resolved['success'])) {
                    return ['success' => false, 'status' => 'failed',
                        'message' => $resolved['message'] ?? 'Weather channel validation failed.'];
                }
                $request = $this->snapshotAnnouncementRequest($resolved['request'], $settings);
                $request['weather_context'] = $context;
                $request['delivery_id'] = 'announcement-' . substr(hash('sha256', $id), 0, 32);
                $request['delivery_timestamp'] = gmdate('c');
                $request['sms_expires_at'] = $context['deadline_at'];
                $job = ['id' => $id, 'state' => 'queued', 'created_at' => gmdate('c'), 'request' => $request,
                    'weather_input_fingerprint'=>$inputFingerprint,
                    'receipts' => [], 'message' => 'Weather SMS and external voice queued.'];
                $queueGate = (new \SlsAnnouncementJobStore($this->announcementAdmissionDirectory()))->admissionLock();
                if (!$queueGate) {
                    return ['success' => false, 'status' => 'deferred',
                        'message' => 'Weather channel delivery is waiting for the shared queue gate.'];
                }
                try {
                    if (count($store->pendingIds()) >= 25) {
                        return ['success' => false, 'status' => 'deferred',
                            'message' => 'The announcement queue is full. Weather delivery remains pending at its source.'];
                    }
                    $this->writeAnnouncementJob($job);
                    $start = true;
                } finally { \SlsAnnouncementJobStore::unlock($queueGate); }
            } finally { \SlsAnnouncementJobStore::unlock($lock); }
        } finally {
            $this->releaseNativeBackupFileLock($activity);
        }
        if ($start && $this->startAnnouncementWorker($id) === false) {
            return ['success' => false, 'status' => 'deferred', 'job_id' => $id,
                'message' => 'Weather channel worker could not start. The saved job will be checked by maintenance.'];
        }
        return ['success' => true, 'status' => 'queued', 'job_id' => $id];
    }
}
