<?php
namespace FreePBX\modules;

/** Shared announcement submission and durable, independently reported channels. */
trait SlsAnnouncementDelivery
{
    private $lastAudioQueueResults = [];

    private function announcementSender(array $options = [])
    {
        if (($options['trigger_source'] ?? '') === 'Control API') {
            return 'Control API';
        }
        if (PHP_SAPI === 'cli' && !empty($options['sender'])) {
            return $this->sanitizeScheduleText($options['sender'], 80, true);
        }
        $user = $_SESSION['AMP_user'] ?? null;
        return is_object($user) && !empty($user->username)
            ? $this->sanitizeScheduleText($user->username, 80, true)
            : (PHP_SAPI === 'cli' ? 'Scheduled announcement' : 'FreePBX administrator');
    }

    private function announcementJobDirectory()
    {
        $directory = self::PLUGIN_DATA_DIR . '/announcement-jobs';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750))) {
            throw new \RuntimeException('Announcement job storage is unavailable.');
        }
        return $directory;
    }

    private function writeAnnouncementJob(array $job)
    {
        if (!preg_match('/^job_[a-f0-9]{32}$/', (string)($job['id'] ?? ''))) {
            throw new \RuntimeException('Invalid announcement job identifier.');
        }
        $directory = $this->announcementJobDirectory();
        $temporary = tempnam($directory, '.job-');
        if ($temporary === false || dirname($temporary) !== $directory) {
            throw new \RuntimeException('Unable to stage announcement job.');
        }
        try {
            $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > 2 * 1024 * 1024) {
                throw new \RuntimeException('Announcement job exceeds its storage limit.');
            }
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
                throw new \RuntimeException('Unable to persist announcement job.');
            }
            chmod($temporary, 0640);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown($temporary, 'asterisk');
                chgrp($temporary, 'asterisk');
            }
            if (!rename($temporary, $directory . '/' . $job['id'] . '.json')) {
                throw new \RuntimeException('Unable to commit announcement job.');
            }
            $pendingPath = $directory . '/pending_' . $job['id'] . '.mark';
            if (is_link($pendingPath)) { throw new \RuntimeException('Unsafe announcement queue marker.'); }
            if (in_array($job['state'] ?? '', ['queued', 'running'], true)) {
                if (!file_exists($pendingPath)) {
                    $pendingHandle = fopen($pendingPath, 'x');
                    if (!$pendingHandle) { throw new \RuntimeException('Unable to index the pending announcement.'); }
                    fclose($pendingHandle); chmod($pendingPath, 0640);
                }
            } elseif (file_exists($pendingPath)) {
                unlink($pendingPath);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function getAnnouncementJob($id)
    {
        if (!is_string($id) || !preg_match('/^job_[a-f0-9]{32}$/', $id)) {
            return ['success' => false, 'state' => 'missing', 'message' => 'Unknown announcement job.'];
        }
        $path = $this->announcementJobDirectory() . '/' . $id . '.json';
        if (is_link($path) || !is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return ['success' => false, 'state' => 'missing', 'message' => 'Announcement job is unavailable.'];
        }
        $job = json_decode((string)file_get_contents($path), true);
        if (!is_array($job)) {
            return ['success' => false, 'state' => 'failed', 'message' => 'Announcement job is unreadable.'];
        }
        $retryable = ($job['state'] ?? '') === 'partial_or_failed' && empty($job['retry_job_id'])
            && time() - (strtotime($job['created_at'] ?? '') ?: 0) <= 900
            && count(array_filter($job['receipts'] ?? [], static function ($row) { return ($row['state'] ?? '') === 'failed'; })) > 0;
        return array_merge([
            'success' => in_array($job['state'] ?? '', ['queued', 'running', 'complete'], true),
            'job_id' => $id, 'state' => $job['state'] ?? 'failed',
            'sender' => $job['request']['sender'] ?? '',
            'created_at' => $job['created_at'] ?? '',
            'message' => $job['message'] ?? 'Announcement queued.',
            'receipts' => $job['receipts'] ?? [],
            'retryable' => $retryable,
        ], $job['result'] ?? []);
    }

    public function retryFailedAnnouncementJob($id, array $options = [])
    {
        $public = $this->getAnnouncementJob($id);
        if (empty($public['retryable'])) {
            return ['success' => false, 'message' => 'No confirmed failed destinations are eligible for retry. Uncertain submissions are not replayed.'];
        }
        if ($this->getAnnouncementCooldownState()['remaining'] > 0) {
            return ['success' => false, 'message' => 'Wait for the announcement cooldown before retrying.'];
        }
        if (count(glob($this->announcementJobDirectory() . '/pending_job_*.mark') ?: []) >= 25) {
            return ['success' => false, 'message' => 'The announcement queue is full. Wait for pending deliveries.'];
        }
        $path = $this->announcementJobDirectory() . '/' . $id . '.json';
        if (is_link($path . '.lock')) { throw new \RuntimeException('Unsafe announcement lock.'); }
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            return ['success' => false, 'message' => 'This announcement is already being processed.'];
        }
        try {
            if (empty($this->getAnnouncementJob($id)['retryable'])) {
                return ['success' => false, 'message' => 'A retry was already requested.'];
            }
            $job = json_decode((string)file_get_contents($path), true);
            $request = $job['request'];
            $request['only_channels'] = [];
            foreach ($job['receipts'] ?? [] as $row) {
                if (($row['state'] ?? '') === 'failed') {
                    $request['only_channels'][$row['channel']][] = (string)$row['target'];
                }
            }
            $request['sender'] = $this->announcementSender($options);
            $request['trigger_source'] = 'Failed-destination retry';
            $request['retry_of'] = $id;
            $child = ['id' => 'job_' . bin2hex(random_bytes(16)), 'state' => 'queued',
                'created_at' => gmdate('c'), 'request' => $request, 'receipts' => [], 'message' => 'Failed destinations queued for retry.'];
            // Record the retry claim first. A crash can require operator review,
            // but two clicks cannot create duplicate retry deliveries.
            $job['retry_job_id'] = $child['id'];
            $this->writeAnnouncementJob($job);
            $this->writeAnnouncementJob($child);
            $this->setAnnouncementCooldown();
            $this->startAnnouncementWorker($child['id']);
            return ['success' => true, 'queued' => true, 'state' => 'queued', 'job_id' => $child['id'],
                'message' => $child['message']];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function startAnnouncementWorker($id)
    {
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        exec('/usr/bin/nohup /usr/bin/timeout 900 /usr/bin/php '
            . escapeshellarg(self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php')
            . ' ' . escapeshellarg($id) . ' </dev/null >/dev/null 2>&1 &');
    }

    private function deliverResolvedAnnouncement(array $request)
    {
        if (!empty($request['preview'])) {
            $inventory = $this->getDeviceOverrideInventory();
            $selectedDevices = array_values(array_filter($inventory['devices'] ?? [], static function ($device) use ($request) {
                return in_array((string)$device['extension'], array_map('strval', $request['phones']), true);
            }));
            return ['success' => true, 'preview' => true, 'sender' => $request['sender'],
                'phones' => $request['phones'], 'desktops' => $request['desktops'],
                'webhooks' => $request['webhooks'], 'audio_mode' => $request['audio_mode'],
                'unavailable_phones' => $request['unavailable_phones'] ?? [], 'devices' => $selectedDevices];
        }
        if (PHP_SAPI !== 'cli') {
            // Admission only reads the small pending index, never a month's
            // completed delivery history on an interactive request.
            $pending = count(glob($this->announcementJobDirectory() . '/pending_job_*.mark') ?: []);
            if ($pending >= 25) {
                return ['success' => false, 'message' => 'The announcement queue is full. Wait for pending deliveries.', 'error_code' => 'delivery_busy'];
            }
            $job = ['id' => 'job_' . bin2hex(random_bytes(16)), 'state' => 'queued',
                'created_at' => gmdate('c'), 'request' => $request, 'receipts' => [], 'message' => 'Announcement queued.'];
            $this->writeAnnouncementJob($job);
            $this->setAnnouncementCooldown();
            // Only a generated identifier enters this fixed command. No message,
            // destination, credential, or supplied executable enters a shell.
            $this->startAnnouncementWorker($job['id']);
            return ['success' => true, 'queued' => true, 'state' => 'queued', 'job_id' => $job['id'],
                'message' => 'Announcement queued. Waiting for delivery results.', 'sender' => $request['sender']];
        }
        return $this->executeResolvedAnnouncement($request);
    }

    public function processAnnouncementJobs($requestedId = '')
    {
        if (PHP_SAPI !== 'cli') { throw new \RuntimeException('Announcement workers require CLI execution.'); }
        if ($requestedId !== '' && !preg_match('/^job_[a-f0-9]{32}$/', $requestedId)) { return false; }
        $paths = $requestedId !== '' ? [$this->announcementJobDirectory() . '/' . $requestedId . '.json']
            : array_map(static function ($path) {
                return dirname($path) . '/' . substr(basename($path), 8, -5) . '.json';
            }, glob($this->announcementJobDirectory() . '/pending_job_*.mark') ?: []);
        sort($paths);
        foreach ($paths as $path) {
            if (is_link($path) || !is_file($path) || filesize($path) > 2 * 1024 * 1024) { continue; }
            $lockPath = $path . '.lock';
            if (is_link($lockPath)) { continue; }
            $lock = fopen($lockPath, 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) { fclose($lock); } continue; }
            try {
                $job = json_decode((string)file_get_contents($path), true);
                if (!is_array($job) || !is_array($job['request'] ?? null)) { continue; }
                if (($job['state'] ?? '') === 'running') {
                    $job['state'] = 'uncertain';
                    $job['message'] = 'The delivery worker stopped before confirming its result. Check receipts before sending again.';
                    $this->writeAnnouncementJob($job);
                    continue;
                }
                if (($job['state'] ?? '') !== 'queued') { continue; }
                if (time() - (strtotime($job['created_at'] ?? '') ?: 0) > 900) {
                    $job['state'] = 'expired'; $job['message'] = 'Announcement expired before delivery started.';
                    $this->writeAnnouncementJob($job); continue;
                }
                $job['state'] = 'running'; $job['message'] = 'Preparing and submitting selected channels.';
                $this->writeAnnouncementJob($job);
                $progress = function (array $receipts, string $message) use (&$job) {
                    $job['receipts'] = $receipts; $job['message'] = $message;
                    $this->writeAnnouncementJob($job);
                };
                try {
                    $result = $this->executeResolvedAnnouncement($job['request'], $progress);
                    $job['state'] = !empty($result['success']) ? 'complete' : 'partial_or_failed';
                    $job['result'] = $result; $job['message'] = $result['message'];
                } catch (\Throwable $exception) {
                    $job['state'] = 'uncertain';
                    $job['message'] = 'Delivery could not be fully confirmed. Review the recorded channel results.';
                    error_log('SLS announcement job ' . $job['id'] . ': ' . $exception->getMessage());
                }
                $job['finished_at'] = gmdate('c'); $this->writeAnnouncementJob($job);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return true;
    }

    private function executeResolvedAnnouncement(array $request, $progress = null)
    {
        $channelTargets = function ($channel, array $targets) use ($request) {
            if (!isset($request['only_channels'])) { return $targets; }
            return array_values(array_intersect(array_map('strval', $targets), $request['only_channels'][$channel] ?? []));
        };
        $audioPhones = $channelTargets('audio', $request['phones']);
        $visualPhones = $channelTargets('sip_notify', $request['phones']);
        $request['desktops'] = $channelTargets('desktop', $request['desktops']);
        $request['webhooks'] = $channelTargets('webhook', $request['webhooks']);
        $receipts = [];
        $record = function ($channel, $target, $state, $detail = '') use (&$receipts, $progress) {
            $receipts[] = ['channel' => $channel, 'target' => (string)$target, 'state' => $state, 'detail' => $detail];
            if ($progress) { $progress($receipts, 'Delivering announcement…'); }
        };
        $enabled = $this->currentAnnouncementDestinationIds();
        foreach ([['audio', &$audioPhones, 'phones'], ['sip_notify', &$visualPhones, 'phones'],
                  ['desktop', &$request['desktops'], 'desktops'], ['webhook', &$request['webhooks'], 'webhooks']] as &$channel) {
            foreach ($channel[1] as $target) {
                if (!in_array((string)$target, $enabled[$channel[2]], true)) {
                    $record($channel[0], $target, 'cancelled', 'Destination is no longer enabled or registered.');
                }
            }
            $channel[1] = array_values(array_intersect(array_map('strval', $channel[1]), $enabled[$channel[2]]));
        }
        unset($channel);
        $sender = $this->sanitizeScheduleText($request['sender'] ?? 'System', 80, true);
        $visualMessage = $request['message'] . "\nSent by: " . $sender;
        $displayTimeout = (int)$request['display_timeout'];
        $audioDuration = 0; $audioQueued = false;
        $desktopPublished = false;
        $publishDesktop = function () use (&$desktopPublished, &$displayTimeout, $request, $visualMessage, $record, $sender) {
            if (empty($request['desktops'])) { return; }
            $command = $this->buildAnnouncementVisualPushCommand($visualMessage, [], $displayTimeout, [
                'mode' => 'api_only', 'desktop_clients' => $request['desktops'],
                'image' => $request['image'], 'title' => $request['title'], 'background_color' => $request['background_color'], 'sender' => $sender,
            ]);
            try { $result = $this->executeAnnouncementVisualPushCommand($command); }
            catch (\Throwable $error) { $result = ['success' => false]; }
            $desktopPublished = !empty($result['success']);
            foreach ($request['desktops'] as $target) {
                $record('desktop', $target, $desktopPublished ? 'published' : 'uncertain', $desktopPublished ? 'Awaiting optional client acknowledgement.' : 'Desktop journal publication could not be confirmed.');
            }
        };
        $needsDuration = $request['timeout_mode'] === 'audio' && $request['audio_mode'] !== 'none';
        if (!$needsDuration) { $publishDesktop(); }
        if ($request['audio_mode'] !== 'none' && $audioPhones) {
            $this->lastAudioQueueResults = [];
            try { $audio = $this->sendAnnouncementTtsAudio($audioPhones, $request['message'], [
                'audio_mode' => $request['audio_mode'], 'opening_tone' => $request['opening_tone'],
                'closing_tone' => $request['closing_tone'], 'piper_voice' => $request['voice'],
                'tts_volume' => $request['volume'], 'trigger_source' => $request['trigger_source'], 'sender' => $sender,
                'priority' => $request['priority'] ?? 'normal',
            ]); } catch (\Throwable $error) { $audio = ['success' => false, 'message' => 'Audio preparation or queueing failed.']; }
            $audioDuration = (int)ceil($audio['audio_duration_seconds'] ?? 0);
            $audioQueued = !empty($audio['delivery_started']);
            foreach ($audioPhones as $target) {
                $accepted = !empty($this->lastAudioQueueResults[(string)$target]);
                $record('audio', $target, $accepted ? 'queued' : 'failed', $accepted ? 'Awaiting Asterisk playback result.' : (string)($audio['message'] ?? 'Audio queue failed.'));
            }
            if ($needsDuration) { $displayTimeout = max(1, $audioDuration); }
        }
        if ($needsDuration) { $publishDesktop(); }
        if ($audioQueued) { sleep(1); }
        foreach ($visualPhones as $target) {
            $command = $this->buildAnnouncementVisualPushCommand($visualMessage, [(string)$target], $displayTimeout, [
                'mode' => 'phone_only', 'image' => $request['image'], 'title' => $request['title'],
                'background_color' => $request['background_color'], 'sender' => $sender,
            ]);
            try { $result = $this->executeAnnouncementVisualPushCommand($command); }
            catch (\Throwable $error) { $result = ['success' => false]; }
            $record('sip_notify', $target, !empty($result['success']) ? 'submitted' : 'uncertain',
                !empty($result['success']) ? 'Asterisk accepted the request; handset display is not confirmed.' : 'One or more device submissions failed; inspect device delivery logs.');
        }
        $webhookUncertain = false;
        try {
            $webhooks = $this->dispatchAnnouncementWebhooks($request['webhooks'], $request['message'], $request['title'],
                $request['background_color'], [['Sent by', $sender]]);
        } catch (\Throwable $error) {
            $webhookUncertain = true;
            $webhooks = ['accepted' => [], 'failed' => array_map(static function ($id) {
                return ['id' => $id, 'name' => $id, 'error' => 'Webhook submission could not be confirmed.'];
            }, $request['webhooks'])];
        }
        foreach ($webhooks['accepted'] as $row) { $record('webhook', $row['id'], 'accepted', $row['name']); }
        foreach ($webhooks['failed'] as $row) {
            // An HTTP timeout or dispatcher interruption can happen after the
            // receiver accepted the body. Never offer an automatic replay.
            $uncertain = $webhookUncertain || in_array($row['error'] ?? '', ['delivery_timeout', 'delivery_unconfirmed', 'dispatcher_failed'], true);
            $record('webhook', $row['id'], $uncertain ? 'uncertain' : 'failed', $row['name'] . ': ' . $row['error']);
        }
        $failed = array_filter($receipts, static function ($receipt) { return in_array($receipt['state'], ['failed', 'uncertain', 'cancelled'], true); });
        $accepted = count($receipts) - count($failed);
        if ($accepted) { $this->setAnnouncementCooldown(); }
        $this->appendAnnouncementNotifyLog($request['message'], [
            'status' => $failed ? 'partial_failure' : 'submitted', 'trigger_source' => $request['trigger_source'],
            'sender' => $sender, 'phones' => $request['phones'], 'desktop_clients' => $request['desktops'],
            'priority' => $request['priority'] ?? 'normal',
            'webhook_destination_ids' => $request['webhooks'], 'delivery_receipts' => $receipts,
        ]);
        return ['success' => !$failed && $accepted > 0, 'receipts' => $receipts, 'sender' => $sender,
            'message' => sprintf('%d channel destination(s) accepted; %d need attention.', $accepted, count($failed)),
            'partial_delivery' => $accepted > 0 && count($failed) > 0, 'delivery_started' => $accepted > 0,
            'desktop_published' => $desktopPublished, 'cooldown_remaining' => $this->getAnnouncementCooldownState()['remaining'],
            'display_timeout_seconds' => $displayTimeout];
    }

    private function currentAnnouncementDestinationIds()
    {
        $settings = $this->getActiveSettings();
        $desktops = array_filter($this->getDesktopClients($settings), static function ($row) { return !empty($row['enabled']); });
        $webhooks = array_filter($this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement'),
            static function ($row) { return !empty($row['enabled']); });
        return [
            'phones' => array_map('strval', array_column($this->getSipNotifyTargets(), 'extension')),
            'desktops' => array_map('strval', array_column($desktops, 'username')),
            'webhooks' => array_map('strval', array_column($webhooks, 'id')),
        ];
    }
}
