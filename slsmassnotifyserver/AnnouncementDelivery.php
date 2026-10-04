<?php
namespace FreePBX\modules;
require_once __DIR__ . '/api/sls-mass-notify/security.php';
require_once __DIR__ . '/EnterpriseClusterIntegration.php';
if (!class_exists('SlsAnnouncementJobStore', false)) {
    require_once __DIR__ . '/bin/sls_mass_notify/sls_announcement_jobs.php';
}

/** Shared announcement submission and durable, independently reported channels. */
trait SlsAnnouncementDelivery
{
    private $lastAudioQueueResults = [];
    private $lastOutboundQueueResults = [];
    private $lastPhoneAdmission = [];

    /** Advisory presence never substitutes for an event-specific receipt. */
    private function announcementDesktopPresence(): array
    {
        $directory = self::PLUGIN_DATA_DIR; $path = $directory . '/desktop-last-seen.json';
        clearstatcache(true, $path); $parent = @lstat($directory); $before = @lstat($path);
        if (!$parent || realpath($directory) !== $directory || ($parent['mode'] & 0022)
            || !$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || ($before['mode'] & 0022) || !in_array($before['uid'], [0, $parent['uid']], true)
            || $before['size'] > 1048576) { return []; }
        $handle = @fopen($path, 'rb');
        if (!$handle) { return []; }
        try {
            $opened = fstat($handle);
            if (!$opened || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $opened['size'] > 1048576) { return []; }
            $raw = stream_get_contents($handle, 1048577);
            $value = is_string($raw) && strlen($raw) <= 1048576 ? json_decode($raw, true, 16) : null;
            return is_array($value) && count($value) <= 1000 ? $value : [];
        } finally { fclose($handle); }
    }

    /** Read exact durable application receipts without rewriting a running job. */
    protected function refreshAnnouncementDesktopReceipts(array $result, $createdAt = '')
    {
        unset($result['receipt_status_error']);
        $rows = is_array($result['receipts'] ?? null) ? $result['receipts'] : [];
        $ledgers = []; $bytes = 0; $deadline = microtime(true) + 2;
        foreach ($rows as &$row) {
            if (($row['channel'] ?? '') !== 'desktop' || ($row['state'] ?? '') !== 'published'
                || !is_string($row['event_id'] ?? null) || $row['event_id'] === '') { continue; }
            $username = (string)($row['target'] ?? '');
            // Missing IDs in old jobs must never be guessed from last-seen state.
            if (!array_key_exists('client_id', $row)) { continue; }
            $clientId = (string)$row['client_id'];
            $identity = hash('sha256', $clientId !== '' ? 'id:' . $clientId : 'user:' . $username);
            try {
                if (!array_key_exists($identity, $ledgers)) {
                    if (microtime(true) > $deadline || $bytes >= 33554432) { throw new \RuntimeException('Receipt read budget exceeded.'); }
                    $directory = self::PLUGIN_DATA_DIR . '/sipnotify/acknowledgements';
                    $path = $directory . '/' . $identity . '.json';
                    clearstatcache(true, $path);
                    if (!file_exists($path) && !is_link($path)) { $ledgers[$identity] = null; continue; }
                    $directoryMeta = @lstat($directory); $before = @lstat($path);
                    if (!$directoryMeta || realpath($directory) !== $directory || ($directoryMeta['mode'] & 0022)
                        || !$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
                        || ($before['mode'] & 0022) || $before['size'] > 2097152) {
                        throw new \RuntimeException('Unsafe receipt storage.');
                    }
                    $handle = @fopen($path, 'rb');
                    if (!$handle) { throw new \RuntimeException('Receipt storage unreadable.'); }
                    try {
                        $opened = fstat($handle);
                        if (!$opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
                            || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                            || $opened['size'] > 2097152 || $bytes + $opened['size'] > 33554432) {
                            throw new \RuntimeException('Receipt storage changed or exceeded its read budget.');
                        }
                        $raw = stream_get_contents($handle, 2097153); $bytes += strlen((string)$raw);
                    } finally { fclose($handle); }
                    $ledger = is_string($raw) && strlen($raw) <= 2097152 ? json_decode($raw, true, 16) : null;
                    if (!is_array($ledger) || ($ledger['schema'] ?? null) !== 1
                        || !is_array($ledger['acknowledgements'] ?? null) || count($ledger['acknowledgements']) > 1000) {
                        throw new \RuntimeException('Receipt storage is invalid.');
                    }
                    $ledgers[$identity] = $ledger;
                }
                $ledger = $ledgers[$identity];
                if (!is_array($ledger)) { continue; }
                $entry = $ledger['acknowledgements'][hash('sha256', $row['event_id'])] ?? null;
                $at = is_array($entry) ? strtotime((string)($entry['ack_at'] ?? '')) : false;
                if (!is_array($entry) || ($entry['event_id'] ?? null) !== $row['event_id']
                    || ($entry['username'] ?? $ledger['username'] ?? null) !== $username
                    || ($entry['client_id'] ?? $ledger['client_id'] ?? null) !== $clientId
                    || ($entry['receipt_type'] ?? null) !== 'client_acknowledgement' || $at === false || $at > time()) { continue; }
                $row['state'] = 'received'; $row['received_at'] = gmdate('c', $at);
                $row['detail'] = 'Received by desktop app'; $row['retryable'] = false;
            } catch (\Throwable $error) {
                $ledgers[$identity] = null;
                $result['receipt_status_error'] = 'Desktop receipt storage is unavailable, invalid, or exceeds this request\'s read limit; some receipts cannot be confirmed.';
            }
        }
        unset($row);
        $presence = array_filter($rows, static function ($row) {
            return ($row['channel'] ?? '') === 'desktop' && ($row['state'] ?? '') === 'published' && !empty($row['client_id']);
        }) ? $this->announcementDesktopPresence() : [];
        foreach ($rows as &$row) {
            if (($row['channel'] ?? '') !== 'desktop' || ($row['state'] ?? '') !== 'published' || empty($row['client_id'])) { continue; }
            $seen = $presence[(string)($row['target'] ?? '')] ?? null;
            if (!is_array($seen) || ($seen['client_id'] ?? '') !== $row['client_id'] || !is_string($seen['seen_at'] ?? null)
                || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $seen['seen_at'])) { continue; }
            $at = strtotime($seen['seen_at']);
            if ($at === false || $at < 1 || $at > time()) { continue; }
            $row['last_authenticated_request_at'] = gmdate('c', $at);
            if (time() - $at > 60) {
                $row['detail'] = 'Published to the live feed; no recent authenticated request is recorded for this desktop. Check the app\'s PBX connection. Alerts missed while disconnected are not replayed.';
            } elseif (!empty($row['event_id'])) {
                $row['detail'] = 'A recent authenticated request was observed; awaiting this event\'s desktop application receipt.';
            }
        }
        unset($row);
        $result['receipts'] = $rows;
        $age = time() - (strtotime((string)$createdAt) ?: 0);
        $result['receipt_poll_pending'] = $age >= 0 && $age < 600 && count(array_filter($rows, static function ($row) {
            return ($row['channel'] ?? '') === 'desktop' && ($row['state'] ?? '') === 'published'
                && !empty($row['event_id']) && array_key_exists('client_id', $row);
        })) > 0;
        return $result;
    }

    protected function readAnnouncementPhoneOutcomes($correlation)
    {
        $unavailable = ['available' => false, 'active' => false, 'uncertain' => true,
            'targets' => [], 'truncated' => false, 'playback_confirmed' => false];
        if (!is_string($correlation) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $correlation)) { return $unavailable; }
        $helper = self::RUNTIME_DIR . '/sls_phone_outcomes.py';
        if (!is_file($helper)) { return $unavailable; }
        $pipes = [];
        $process = proc_open(['/usr/bin/timeout', '--kill-after=1', '2', '/usr/bin/python3', '-I', $helper, $correlation],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { return $unavailable; }
        $raw = stream_get_contents($pipes[1], 524289); fclose($pipes[1]);
        if (!is_string($raw) || strlen($raw) > 524288) { proc_terminate($process); }
        $exit = proc_close($process);
        $value = is_string($raw) && strlen($raw) <= 524288 ? json_decode($raw, true) : null;
        if ($exit !== 0 || !is_array($value) || ($value['ok'] ?? null) !== true
            || !is_array($value['targets'] ?? null) || count($value['targets']) > 1000) { return $unavailable; }
        foreach (['available', 'active', 'uncertain', 'truncated'] as $key) {
            if (!is_bool($value[$key] ?? null)) { return $unavailable; }
        }
        $rows = [];
        foreach ($value['targets'] as $row) {
            if (!is_array($row) || !is_string($row['recipient_id'] ?? null)
                || !preg_match('/^(?:[0-9]{1,20}|voice_[a-f0-9]{24})$/D', $row['recipient_id'])
                || !is_int($row['attempt'] ?? null) || $row['attempt'] < 1 || $row['attempt'] > 2000
                || !is_int($row['contact_index'] ?? null) || $row['contact_index'] < 1 || $row['contact_index'] > 10000
                || !in_array($row['dial_status'] ?? null, ['', 'ANSWER', 'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'], true)) { return $unavailable; }
            foreach (['answered', 'joined', 'ended', 'uncertain'] as $key) {
                if (!is_bool($row[$key] ?? null)) { return $unavailable; }
            }
            $rows[] = array_intersect_key($row, array_flip(['recipient_id', 'attempt', 'contact_index', 'dial_status', 'answered', 'joined', 'ended', 'uncertain']));
        }
        return ['available' => $value['available'], 'active' => $value['active'], 'uncertain' => $value['uncertain'],
            'truncated' => $value['truncated'], 'targets' => $rows, 'playback_confirmed' => false];
    }

    private function snapshotAnnouncementRequest(array $request, array $settings)
    {
        $ids = $request['email_recipient_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 50) { throw new \DomainException('Select at most 50 saved email recipients.'); }
        $request['email_targets'] = [];
        if ($ids) {
            $known = $this->announcementEmailTargets($settings);
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^email_[a-f0-9]{24}$/D', $id) || isset($request['email_targets'][$id]) || !isset($known[$id])) {
                    throw new \DomainException('A selected saved email recipient is invalid, duplicated or no longer available.');
                }
                $request['email_targets'][$id] = $known[$id] + ['fingerprint' => $this->announcementEmailFingerprint($known[$id])];
            }
        }
        $request['email_recipient_ids'] = $ids;
        $smsIds = $request['sms_recipient_ids'] ?? [];
        if ($smsIds && $this->validateSmsRecipientIds($smsIds)) { throw new \DomainException('Select at most 50 distinct saved SMS recipients.'); }
        $request['sms_media'] = $smsIds ? $this->prepareMmsImage($request,$settings) : [];
        $request['sms_targets'] = $smsIds ? \SLS\MassNotify\Sms\Service::snapshot($settings, $smsIds, $request['title'], $request['message'], ($request['is_test'] ?? false) === true,
            array_diff_key($request['sms_media'],['data'=>true])) : [];
        $request['sms_recipient_ids'] = $smsIds;
        $request['desktop_client_ids'] = [];
        foreach ($this->getDesktopClients($settings) as $client) {
            if (in_array((string)($client['username'] ?? ''), $request['desktops'], true)) {
                $request['desktop_client_ids'][(string)$client['username']] = (string)($client['client_id'] ?? '');
            }
        }
        $request['webhooks'] = array_values(array_unique(array_map('strval', $request['webhooks'])));
        $request['webhook_fingerprints'] = [];
        foreach ($this->normalizeWebhookDestinations($settings['announcement_webhooks'] ?? [], 'announcement') as $destination) {
            $id = (string)$destination['id'];
            if (!empty($destination['enabled']) && in_array($id, $request['webhooks'], true)) {
                $request['webhook_fingerprints'][$id] = $this->webhookDestinationFingerprint($destination);
            }
        }
        $request['outbound_targets'] = [];
        if (!empty($request['voice_recipient_ids'])) {
            $known = $this->outboundVoiceTargets($settings);
            foreach (array_unique($request['voice_recipient_ids']) as $id) {
                if (!isset($known[$id])) { throw new \DomainException('A selected external voice recipient is no longer enabled.'); }
                $request['outbound_targets'][] = $known[$id];
            }
        }
        if (in_array($request['audio_mode'], ['tts', 'tones_tts'], true) && trim((string)$request['voice']) === '') {
            $request['voice'] = (string)($settings['announcement_piper_voice'] ?? $settings['piper_voice'] ?? self::PIPER_VOICE);
        }
        // Freeze both selections. Editing the external voice later must not
        // change queued content or the voice heard by internal recipients.
        $externalVoice = $settings['outbound_voice']['piper_voice'] ?? '';
        if (($request['incident_context'] ?? null) !== null && method_exists($this, 'currentIncidentSpeechVoice')) {
            $externalVoice = $this->currentIncidentSpeechVoice() ?? $externalVoice;
        }
        $request['external_voice'] = $externalVoice !== '' ? $externalVoice : $request['voice'];
        $request['pronunciation_rules'] = $settings['announcement_pronunciation'] ?? [];
        if (method_exists($this, 'snapshotEnterpriseIntegrationResponses')) {
            $request = $this->snapshotEnterpriseIntegrationResponses($request, $settings);
        }
        return $request;
    }

    private function announcementSender(array $options = [])
    {
        if (($options['trigger_source'] ?? '') === 'SLS Operator Portal') {
            $principal = $GLOBALS['sls_control_principal'] ?? null;
            if (is_array($principal) && isset($principal['username'])) {
                return $this->sanitizeScheduleText('Operator: ' . $principal['username'], 80, true);
            }
        }
        if (($options['trigger_source'] ?? '') === 'Control API') {
            $principal = $GLOBALS['sls_control_principal'] ?? null;
            if (is_array($principal) && ($principal['id'] ?? 'legacy') !== 'legacy') {
                return $this->sanitizeScheduleText('API: ' . ($principal['name'] ?? '') . ' (' . $principal['id'] . ')', 80, true);
            }
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

    /** Pure construction; caller must durably link preparing intent before persistence. */
    private function buildScheduledAnnouncementJob(array $request, array $context): array
    {
        $context = \SlsAnnouncementJobStore::scheduleContext($context);
        $id = \SlsAnnouncementJobStore::scheduledId($context);
        foreach (['schedule_context', 'delivery_id', 'delivery_timestamp', 'email_expires_at', 'email_attempt_id', 'sms_expires_at', 'sms_attempt_id', 'retry_of', 'only_channels'] as $protected) {
            if (array_key_exists($protected, $request)) { throw new \RuntimeException('Scheduled request already contains protected delivery metadata.'); }
        }
        $created = gmdate('c');
        $request['schedule_context'] = $context;
        $request['force_queue'] = true;
        $request['delivery_id'] = 'announcement-' . substr($id, 4);
        $request['delivery_timestamp'] = $created;
        if (!empty($request['sms_recipient_ids'])) { $request['sms_expires_at'] = min(time()+900, $context['deadline_at']); }
        if (!empty($request['email_recipient_ids'])) {
            $request['email_expires_at'] = min(time() + 900, $context['deadline_at']);
            foreach ($request['email_targets'] as $recipient => &$target) {
                $domain = strtolower(substr(strrchr($target['sender']['address'], '@'), 1));
                $target['message_id'] = '<sls-' . substr($id, 4) . '-' . substr($recipient, 6) . '@' . $domain . '>';
            }
            unset($target);
        }
        return ['id' => $id, 'state' => 'prepared', 'created_at' => $created, 'request' => $request,
            'request_fingerprint' => \SlsAnnouncementJobStore::requestFingerprint($request),
            'receipts' => [], 'message' => 'Scheduled announcement prepared; awaiting durable activation.'];
    }

    private function persistPreparedScheduledAnnouncementJob(array $job): array
    {
        \SlsAnnouncementJobStore::validateScheduledJob($job);
        if (($job['state'] ?? '') !== 'prepared') { throw new \RuntimeException('Expected a prepared scheduled announcement.'); }
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $lock = $store->lock($job['id']);
        if (!$lock) { throw new \RuntimeException('Scheduled announcement preparation is busy.'); }
        try {
            $existing = $store->read($job['id']);
            if ($existing !== null) {
                if (($existing['request']['schedule_context'] ?? null) !== $job['request']['schedule_context']
                    || !hash_equals($existing['request_fingerprint'] ?? '', $job['request_fingerprint'])) {
                    throw new \RuntimeException('Scheduled announcement preparation conflicts with its saved snapshot.');
                }
                return $existing;
            }
            if (count($store->pendingIds()) >= 25) { throw new \RuntimeException('Announcement queue capacity is reached.'); }
            $this->writeAnnouncementJob($job);
            return $job;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }

    private function scheduledAnnouncementRecordContext(array $record): array
    {
        $context = \SlsAnnouncementJobStore::scheduleContext(['version' => 1,
            'schedule_id' => $record['schedule_id'] ?? null, 'occurrence_id' => $record['occurrence_id'] ?? null,
            'run_at_utc' => $record['run_at_utc'] ?? null, 'deadline_at' => $record['deadline_at'] ?? null]);
        if (($record['job_id'] ?? '') !== \SlsAnnouncementJobStore::scheduledId($context)
            || !is_string($record['request_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $record['request_fingerprint'])) {
            throw new \RuntimeException('Scheduled occurrence delivery link is invalid.');
        }
        return $context;
    }

    private function readScheduledAnnouncementJob(array $record): ?array
    {
        $context = $this->scheduledAnnouncementRecordContext($record);
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        // Verify before reconciliation: never mutate another occurrence on a bad link.
        $job = $store->read($record['job_id']);
        if ($job === null) { return null; }
        if (($job['request']['schedule_context'] ?? null) !== $context
            || !hash_equals($record['request_fingerprint'], $job['request_fingerprint'] ?? '')) {
            throw new \RuntimeException('Scheduled occurrence and delivery snapshot do not match.');
        }
        if ($this->announcementClusterAuthorityAvailable()) { $store->reconcile($record['job_id']); }
        $job = $store->read($record['job_id']);
        if ($job === null || ($job['request']['schedule_context'] ?? null) !== $context
            || !hash_equals($record['request_fingerprint'], $job['request_fingerprint'] ?? '')) {
            throw new \RuntimeException('Scheduled delivery changed during reconciliation.');
        }
        return $job;
    }

    private function activateScheduledAnnouncementJob(array $record): array
    {
        if (!$this->announcementClusterAuthorityAvailable()) { return ['success'=>false,'deferred'=>true,'state'=>'prepared','job_id'=>$record['job_id'],'message'=>'Cluster exclusive authority is unavailable; this node did not change pending delivery.']; }
        $context = $this->scheduledAnnouncementRecordContext($record);
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $lock = $store->lock($record['job_id']);
        if (!$lock) { return ['success' => false, 'deferred' => true, 'state' => 'prepared', 'job_id' => $record['job_id'], 'message' => 'Scheduled delivery is busy.']; }
        $start = false;
        try {
            $job = $store->read($record['job_id']);
            if ($job === null || ($job['request']['schedule_context'] ?? null) !== $context
                || !hash_equals($record['request_fingerprint'], $job['request_fingerprint'] ?? '')) {
                throw new \RuntimeException('Scheduled delivery snapshot is missing or mismatched.');
            }
            if (($job['state'] ?? '') === 'prepared') {
                if (time() >= $context['deadline_at']) { $store->failure($job, 'job_expired'); }
                else {
                    $admission = $this->admitScheduledAnnouncementRequest($job['request']);
                    if (empty($admission['success'])) {
                        return array_merge($admission, ['job_id' => $job['id'], 'state' => 'prepared']);
                    }
                    // A slow durable admission must not move the original deadline.
                    if (time() >= $context['deadline_at']) { $store->failure($job, 'job_expired'); }
                    else {
                        $job['state'] = 'queued'; $job['activated_at'] = gmdate('c');
                        $job['startup_attempted_at'] = $job['activated_at'];
                        $job['message'] = 'Scheduled announcement admitted; awaiting worker startup.';
                        $this->writeAnnouncementJob($job); $start = true;
                    }
                }
            }
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
        if ($start) { $this->startAnnouncementWorker($record['job_id']); }
        return $this->getAnnouncementJob($record['job_id']);
    }

    private function scheduledAnnouncementDeadline(array $request): ?int
    {
        if (!array_key_exists('schedule_context', $request)) { return null; }
        if (!is_array($request['schedule_context'])) { throw new \RuntimeException('Invalid scheduled delivery provenance.'); }
        return \SlsAnnouncementJobStore::scheduleContext($request['schedule_context'])['deadline_at'];
    }

    private function writeAnnouncementJob(array $job)
    {
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $store->write($job);
        try {
            if (method_exists($this, 'getActiveSettings')) { \SLS\MassNotify\EnterpriseClusterIntegration::replicateJob($this->getActiveSettings(), $job); }
        }
        catch (\Throwable $error) {
            // Preserve local evidence and retire a failed admission. A queued
            // job must not unexpectedly wake up after an unconfirmed send UI.
            $store->failure($job, 'worker_runtime_failed', ($job['state'] ?? '') === 'running');
            throw $error;
        }
    }

    public function getAnnouncementJob($id)
    {
        if (!is_string($id) || !preg_match('/^job_[a-f0-9]{32}$/', $id)) {
            return ['success' => false, 'state' => 'missing', 'message' => 'Unknown announcement job.'];
        }
        try {
            $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
            // Polling a standby is read-only. It must not renew a witness lease
            // or retire a pending job as a side effect of reading its status.
            $clusterEnabled=method_exists($this,'getActiveSettings') && \SLS\MassNotify\EnterpriseClusterConfig::enabled($this->getActiveSettings());
            if (!$clusterEnabled && function_exists('posix_geteuid') && posix_geteuid() === fileowner($store->directory())) { $store->reconcile($id); }
            $job = $store->read($id);
        } catch (\Throwable $error) {
            return ['success' => false, 'state' => 'missing', 'message' => 'Announcement job is unavailable.'];
        }
        if (!is_array($job)) {
            return ['success' => false, 'state' => 'failed', 'message' => 'Announcement job is unreadable.'];
        }
        $principal = $GLOBALS['sls_control_principal'] ?? null;
        if (is_array($principal) && ($principal['audience']['unrestricted'] ?? false) !== true
            && ($job['request']['api_credential_id'] ?? '') !== ($principal['id'] ?? '')) {
            return ['success' => false, 'error_code' => 'permission_denied', 'message' => 'This credential cannot access that announcement.'];
        }
        $retryable = in_array($job['state'] ?? '', ['failed', 'partial_or_failed'], true)
            && empty($job['submission_uncertain']) && empty($job['retry_job_id'])
            && (empty($job['request']['email_recipient_ids']) || (is_int($job['request']['email_expires_at'] ?? null) && time() < $job['request']['email_expires_at']))
            && (!isset($job['request']['schedule_context']) || time() < $this->scheduledAnnouncementDeadline($job['request']))
            && time() - (strtotime($job['created_at'] ?? '') ?: 0) <= 900
            && count(array_filter($job['receipts'] ?? [], static function ($row) { return ($row['state'] ?? '') === 'failed' && ($row['retryable'] ?? true); })) > 0;
        // The stored job flag marks a lost worker, whose unrecorded submissions
        // must never be replayed. Explicit uncertain receipts are also exposed
        // publicly, while other confirmed failures may still be retried alone.
        $submissionUncertain = !empty($job['submission_uncertain'])
            || count(array_filter($job['receipts'] ?? [], static function ($row) { return ($row['state'] ?? '') === 'uncertain'; })) > 0;
        $public = array_merge([
            'success' => in_array($job['state'] ?? '', ['prepared', 'queued', 'worker_starting', 'running', 'complete'], true),
            'job_id' => $id, 'state' => $job['state'] ?? 'failed',
            'api_credential_id' => (string)($job['request']['api_credential_id'] ?? ''),
            'sender' => $job['request']['sender'] ?? '',
            'created_at' => $job['created_at'] ?? '',
            'message' => $job['message'] ?? 'Announcement queued.',
            'receipts' => $job['receipts'] ?? [],
            'failure_category' => $job['failure_category'] ?? '',
            'startup_attempted_at' => $job['startup_attempted_at'] ?? '',
            'started_at' => $job['started_at'] ?? '',
            'finished_at' => $job['finished_at'] ?? '',
            'submission_uncertain' => $submissionUncertain,
            'retry_operator' => $job['request']['retry_operator'] ?? '',
            'retryable' => $retryable,
        ], $job['result'] ?? []);
        $public['submission_uncertain'] = $submissionUncertain;
        if (in_array($public['failure_category'] ?? '', ['channel_submission_failed', 'audio_submission_failed', 'sip_notify_submission_failed'], true)) {
            $public['message'] = \SlsAnnouncementJobStore::failureReason($public['failure_category'], $public['receipts'] ?? []);
        }
        if (($job['request']['audio_mode'] ?? 'none') !== 'none') {
            $public['phone_outcomes'] = $this->readAnnouncementPhoneOutcomes($job['request']['delivery_id'] ?? '');
        }
        $public = \SlsAnnouncementJobStore::deliveryProjection($public);
        $public = $this->refreshAnnouncementDesktopReceipts($public, $job['created_at'] ?? '');
        return !empty($job['request']['sms_recipient_ids']) ? $this->refreshAnnouncementSmsReceipts($public) : $public;
    }

    public function retryFailedAnnouncementJob($id, array $options = [])
    {
        $public = $this->getAnnouncementJob($id);
        if (($public['error_code'] ?? '') === 'permission_denied') { return $public; }
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
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $lock = $store->lock($id);
        if (!$lock) {
            return ['success' => false, 'message' => 'This announcement is already being processed.'];
        }
        try {
            if (empty($this->getAnnouncementJob($id)['retryable'])) {
                return ['success' => false, 'message' => 'A retry was already requested.'];
            }
            $job = $store->read($id);
            $request = $job['request'];
            $principal = $GLOBALS['sls_control_principal'] ?? null;
            if (is_array($principal)) {
                $settings = $this->getActiveSettings();
                if (!\SLS\MassNotify\ApiSecurity::permitsResolvedAnnouncement($principal, $request, $settings)) {
                    return ['success' => false, 'error_code' => 'permission_denied', 'message' => 'The original announcement exceeds this credential\'s current permissions.'];
                }
                if (($principal['id'] ?? '') !== 'legacy') { $request['api_credential_id'] = $principal['id']; }
            }
            $request['only_channels'] = [];
            foreach ($job['receipts'] ?? [] as $row) {
                if (($row['state'] ?? '') === 'failed' && ($row['retryable'] ?? true)) {
                    $request['only_channels'][$row['channel']][] = (string)$row['target'];
                }
            }
            $request['retry_operator'] = $this->announcementSender($options);
            $request['retry_of'] = $id;
            $child = ['id' => 'job_' . bin2hex(random_bytes(16)), 'state' => 'queued',
                'created_at' => gmdate('c'), 'request' => $request, 'receipts' => [], 'message' => 'Failed destinations queued for retry.'];
            if (isset($request['schedule_context'])) {
                $child['schedule_origin_job_id'] = \SlsAnnouncementJobStore::scheduledId($request['schedule_context']);
                $child['request_fingerprint'] = \SlsAnnouncementJobStore::requestFingerprint($request);
            }
            // Record the retry claim first. A crash can require operator review,
            // but two clicks cannot create duplicate retry deliveries.
            $job['retry_job_id'] = $child['id'];
            $this->writeAnnouncementJob($job);
            $this->recordInteractiveAnnouncementAdmission($request);
            $this->writeAnnouncementJob($child);
            if ($this->startAnnouncementWorker($child['id']) === false) { return $this->getAnnouncementJob($child['id']); }
            return ['success' => true, 'queued' => true, 'state' => 'worker_starting', 'job_id' => $child['id'],
                'message' => 'Starting announcement worker.'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function startAnnouncementWorker($id)
    {
        if (!$this->announcementClusterAuthorityAvailable()) { return false; }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $lock = $store->lock($id); if (!$lock) { return false; }
        try {
            $job = $store->read($id);
            if (!$job || ($job['state'] ?? '') !== 'queued') { return false; }
            $deadline = $this->scheduledAnnouncementDeadline($job['request']);
            if ($deadline !== null && time() >= $deadline) { $store->failure($job, 'job_expired'); return false; }
            $job['state'] = 'worker_starting'; $job['startup_attempted_at'] = gmdate('c');
            $job['message'] = 'Starting announcement worker.'; $this->writeAnnouncementJob($job);
            $worker = self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php';
            foreach (['/usr/bin/nohup', '/usr/bin/timeout', '/usr/bin/php', $worker] as $required) {
                if (!is_executable($required)) { $store->failure($job, 'worker_start_failed'); return false; }
            }
            if (!is_readable(self::RUNTIME_DIR . '/sls_announcement_jobs.php') || !is_callable('exec')) {
                $store->failure($job, 'worker_start_failed'); return false;
            }
            $output = []; $exit = 1;
            exec('/usr/bin/nohup /usr/bin/timeout --kill-after=5s 930 /usr/bin/php '
                . escapeshellarg($worker) . ' --supervise ' . escapeshellarg($id)
                . ' </dev/null >/dev/null 2>&1 & echo $!', $output, $exit);
            if ($exit !== 0 || !preg_match('/^[1-9][0-9]*$/D', trim(implode('', $output)))) {
                $store->failure($job, 'worker_start_failed'); return false;
            }
            return true;
        } catch (\Throwable $error) {
            if (isset($job) && is_array($job)) { $store->failure($job, 'worker_start_failed'); }
            return false;
        } finally { \SlsAnnouncementJobStore::unlock($lock); }
    }

    private function deliverResolvedAnnouncement(array $request)
    {
        if (!empty($request['automation_context']) && !$this->automationContextPermitted($request['automation_context'])) {
            return ['success'=>false,'delivery_started'=>false,'message'=>'Trigger authorization changed or its source event expired before announcement admission.'];
        }
        $principal = $GLOBALS['sls_control_principal'] ?? null;
        if (is_array($principal)) {
            $settings = $this->getActiveSettings();
            if (($principal['id'] ?? '') !== 'legacy') {
                $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, (string)$principal['id']);
            }
            if (!is_array($principal) || !\SLS\MassNotify\ApiSecurity::permitsResolvedAnnouncement($principal, $request, $settings)) {
                return ['success' => false, 'error_code' => 'permission_denied', 'delivery_started' => false,
                    'message' => 'The selected recipients exceed this API credential\'s current permissions, or the credential was revoked.'];
            }
            if ($principal['id'] !== 'legacy') { $request['api_credential_id'] = $principal['id']; }
        }
        if (!empty($request['preview'])) {
            $inventory = $this->getDeviceOverrideInventory();
            $selectedDevices = array_values(array_filter($inventory['devices'] ?? [], static function ($device) use ($request) {
                return in_array((string)$device['extension'], array_map('strval', $request['phones']), true);
            }));
            return ['success' => true, 'preview' => true, 'sender' => $request['sender'],
                'phones' => $request['phones'], 'desktops' => $request['desktops'],
                'voice_recipient_ids' => $request['voice_recipient_ids'] ?? [],
                'email_recipient_ids' => $request['email_recipient_ids'] ?? [], 'sms_recipient_ids' => $request['sms_recipient_ids'] ?? [],
                'webhooks' => $request['webhooks'], 'audio_mode' => $request['audio_mode'], 'voice' => $request['voice'],
                'unavailable_phones' => $request['unavailable_phones'] ?? [], 'devices' => $selectedDevices];
        }
        $request['delivery_id'] = 'announcement-' . bin2hex(random_bytes(16));
        $request['delivery_timestamp'] = gmdate('c');
        if (!empty($request['sms_recipient_ids'])) { $request['force_queue']=true; $request['sms_expires_at']=time()+900; }
        if (!empty($request['email_recipient_ids'])) {
            $request['force_queue'] = true;
            $request['email_expires_at'] = time() + 900;
            foreach ($request['email_targets'] as $id => &$target) {
                $domain = strtolower(substr(strrchr($target['sender']['address'], '@'), 1));
                $target['message_id'] = '<sls-' . substr($request['delivery_id'], strlen('announcement-')) . '-' . substr($id, 6) . '@' . $domain . '>';
            }
            unset($target);
        }
        $this->recordInteractiveAnnouncementAdmission($request);
        if (PHP_SAPI !== 'cli' || !empty($request['force_queue'])) {
            $this->reconcileAnnouncementJobs();
            // Admission only reads the small pending index, never a month's
            // completed delivery history on an interactive request.
            $pending = count(glob($this->announcementJobDirectory() . '/pending_job_*.mark') ?: []);
            if ($pending >= 25) {
                return ['success' => false, 'message' => 'The announcement queue is full. Wait for pending deliveries.', 'error_code' => 'delivery_busy'];
            }
            $job = ['id' => 'job_' . bin2hex(random_bytes(16)), 'state' => 'queued',
                'created_at' => gmdate('c'), 'request' => $request, 'receipts' => [], 'message' => 'Announcement queued.'];
            $this->writeAnnouncementJob($job);
            // Only a generated identifier enters this fixed command. No message,
            // destination, credential, or supplied executable enters a shell.
            if ($this->startAnnouncementWorker($job['id']) === false) { return $this->getAnnouncementJob($job['id']); }
            return ['success' => true, 'queued' => true, 'state' => 'worker_starting', 'job_id' => $job['id'],
                'message' => 'Announcement queued. Waiting for delivery results.', 'sender' => $request['sender']];
        }
        // The public CLI facade may own admission. Durable reservation is
        // complete; do not hold the send mutex through TTS or transport waits.
        if (isset($this->announcementAdmissionLock) && is_resource($this->announcementAdmissionLock)) {
            \SlsAnnouncementJobStore::unlock($this->announcementAdmissionLock);
            $this->announcementAdmissionLock = null;
        }
        return $this->executeResolvedAnnouncement($request);
    }

    public function processAnnouncementJobs($requestedId = '')
    {
        if (PHP_SAPI !== 'cli') { throw new \RuntimeException('Announcement workers require CLI execution.'); }
        if ($requestedId !== '' && !preg_match('/^job_[a-f0-9]{32}$/', $requestedId)) { return false; }
        if (!$this->announcementClusterAuthorityAvailable()) { return false; }
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        $ids = $requestedId !== '' ? [$requestedId] : $store->pendingIds(); sort($ids);
        $allSucceeded = true;
        foreach ($ids as $id) {
            $lock = $store->lock($id);
            if (!$lock) { continue; }
            $finished = false; $job = null;
            register_shutdown_function(static function () use (&$finished, &$job, $store, $lock) {
                if (!$finished && is_array($job) && is_resource($lock) && ($job['state'] ?? '') === 'running') {
                    try { $store->failure($job, 'worker_runtime_failed', true); } catch (\Throwable $ignored) {}
                }
            });
            try {
                $job = $store->read($id);
                if (!is_array($job) || !is_array($job['request'] ?? null)) { continue; }
                if (($job['state'] ?? '') === 'running') {
                    $store->failure($job, 'worker_runtime_failed', true); $allSucceeded = false;
                    continue;
                }
                if (!in_array($job['state'] ?? '', ['queued', 'worker_starting'], true)) { continue; }
                if (!empty($job['request']['weather_context'])) {
                    if (time() >= ($job['request']['weather_context']['deadline_at'] ?? 0) || time() - (strtotime($job['created_at'] ?? '') ?: 0) > 900) { $store->failure($job,'job_expired'); $finished=true; continue; }
                    try { $weather=$this->weatherChannelAuthorization($job['request']['weather_context']); }
                    catch (\Throwable $error) { $weather=['status'=>'deferred']; }
                    if ($weather['status']==='deferred') {
                        $job['state']='queued'; $job['message']='Waiting for a current Weather observation and permitted quiet-hours policy.';
                        $this->writeAnnouncementJob($job); $finished=true; continue;
                    }
                    if ($weather['status']==='cancelled') { $store->failure($job,'weather_source_cancelled'); $finished=true; continue; }
                }
                $deadline = $this->scheduledAnnouncementDeadline($job['request']);
                if (($deadline !== null && time() >= $deadline) || time() - (strtotime($job['created_at'] ?? '') ?: 0) > 900) {
                    $store->failure($job, 'job_expired'); $allSucceeded = false; continue;
                }
                $job['state'] = 'running'; $job['message'] = 'Preparing and submitting selected channels.';
                // Older queued jobs did not have a transport correlation ID.
                // Derive one from their durable identity before any submission.
                $executionRequest = $job['request'];
                if (empty($executionRequest['delivery_id'])) {
                    $executionRequest['delivery_id'] = 'announcement-' . $job['id'];
                }
                if (empty($executionRequest['delivery_timestamp'])) {
                    $executionRequest['delivery_timestamp'] = $job['created_at'] ?? '';
                }
                $executionRequest['email_attempt_id'] = $job['id'];
                $executionRequest['sms_attempt_id'] = $job['id'];
                $job['started_at'] = gmdate('c');
                $this->writeAnnouncementJob($job);
                $progress = function (array $receipts, string $message) use (&$job) {
                    $next = $job; $next['receipts'] = $receipts; $next['message'] = $message;
                    $this->writeAnnouncementJob($next);
                    $job = $next;
                };
                try {
                    $result = $this->executeResolvedAnnouncement($executionRequest, $progress);
                    $job['state'] = !empty($result['success']) ? 'complete' : ((!empty($result['schedule_expired']) && empty($result['delivery_started'])) ? 'expired' : 'failed');
                    $job['result'] = $result; $job['message'] = $result['message'];
                    $job['receipts'] = $result['receipts'];
                    if (in_array($job['state'], ['failed', 'expired'], true)) {
                        $allSucceeded = false;
                        $failedChannels = array_unique(array_column(array_filter($job['receipts'] ?? [],
                            static function ($receipt) { return in_array($receipt['state'] ?? '', ['failed', 'uncertain'], true); }), 'channel'));
                        $job['failure_category'] = $job['state'] === 'expired' ? 'job_expired' : (($result['failure_category'] ?? '') === 'announcement_activity_timeout' ? 'announcement_activity_timeout'
                            : (count($failedChannels) === 1 && reset($failedChannels) === 'audio' ? 'audio_submission_failed'
                            : (count($failedChannels) === 1 && reset($failedChannels) === 'sip_notify' ? 'sip_notify_submission_failed' : 'channel_submission_failed')));
                    }
                } catch (\Throwable $exception) {
                    $job = $store->failure($job, 'worker_runtime_failed', true); $allSucceeded = false;
                }
                $job['finished_at'] = gmdate('c'); $this->writeAnnouncementJob($job);
            } finally { $finished = true; \SlsAnnouncementJobStore::unlock($lock); }
        }
        return $allSucceeded;
    }

    public function reconcileAnnouncementJobs()
    {
        if (!$this->announcementClusterAuthorityAvailable()) { return; }
        $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
        foreach ($store->pendingIds() as $id) {
            try { $store->reconcile($id); }
            catch (\Throwable $error) { error_log('SLS announcement reconcile: unsafe_job_state'); }
        }
    }

    private function announcementClusterAuthorityAvailable(): bool
    {
        if (!method_exists($this,'getActiveSettings')) { return true; }
        try { \SLS\MassNotify\EnterpriseClusterIntegration::requireAuthority($this->getActiveSettings()); return true; }
        catch (\Throwable $error) { return false; }
    }

    public function getAnnouncementWorkerHealth()
    {
        $worker = self::RUNTIME_DIR . '/sls_mass_notify_announcement_worker.php';
        $result = ['worker_exists' => is_file($worker) && is_readable($worker) && is_executable($worker),
            'php_cli_exists' => is_executable('/usr/bin/php'), 'storage_writable' => false,
            'bootstrap_ok' => false, 'probe_fresh' => false, 'latest_state' => 'idle',
            'failure_category' => '', 'failure_reason' => '', 'historical_failure_reason' => '', 'active_delivery_failure' => false];
        try {
            $store = new \SlsAnnouncementJobStore($this->announcementJobDirectory());
            $result['storage_writable'] = is_readable($store->directory()) && is_writable($store->directory());
            $probe = $store->health(); $state = $store->state();
            if (($state['failure_category'] ?? '') === 'channel_submission_failed' && \SlsAnnouncementJobStore::validId($state['job_id'] ?? null)) {
                $latest = $store->read($state['job_id']);
                if (is_array($latest)) {
                    $projection = \SlsAnnouncementJobStore::deliveryProjection($latest);
                    if (($projection['state'] ?? '') === 'complete') {
                        $state['state'] = 'complete'; $state['failure_category'] = ''; $state['failure_channels'] = [];
                    }
                }
            }
            $result['bootstrap_ok'] = !empty($probe['ok']) && !empty($probe['bootstrap']) && !empty($probe['module_loaded']);
            $now = time(); $probeAt = strtotime($probe['checked_at'] ?? '') ?: 0;
            $result['probe_fresh'] = $probeAt > $now - 600 && $probeAt <= $now + 60;
            $result['latest_state'] = in_array($state['state'] ?? '', ['prepared', 'queued', 'worker_starting', 'running', 'complete', 'failed', 'expired'], true) ? $state['state'] : 'idle';
            $result['active_delivery_failure'] = SlsStatusHealth::announcementFailure($state, $probe, time());
            $category = !$result['bootstrap_ok'] ? ($probe['failure_category'] ?? '') : ($state['failure_category'] ?? '');
            if (isset(\SlsAnnouncementJobStore::REASONS[$category])) {
                $result['failure_category'] = $category;
                $result['failure_reason'] = \SlsAnnouncementJobStore::failureReason($category,
                    $result['bootstrap_ok'] && is_array($state['failure_channels'] ?? null) ? $state['failure_channels'] : []);
                if ($result['bootstrap_ok'] && !$result['active_delivery_failure']) {
                    $result['historical_failure_reason'] = $result['failure_reason'];
                    $result['failure_reason'] = '';
                }
            }
        } catch (\Throwable $error) { $result['failure_reason'] = 'Announcement job storage is unavailable or unsafe.'; }
        $result['ok'] = $result['worker_exists'] && $result['php_cli_exists'] && $result['storage_writable']
            && $result['bootstrap_ok'] && $result['probe_fresh'];
        return $result;
    }

    private function executeResolvedAnnouncement(array $request, $progress = null)
    {
        if ((!empty($request['email_recipient_ids']) || !empty($request['sms_recipient_ids'])) && !is_callable($progress)) {
            return ['success' => false, 'receipts' => [], 'delivery_started' => false, 'partial_delivery' => false,
                'submission_uncertain' => false, 'message' => 'Email and SMS announcements require the durable queue; no channels were submitted.'];
        }
        try {
            $activity = $this->acquireAnnouncementActivityLock(false, 30);
        } catch (\Throwable $error) {
            // No delivery method has run. End this attempt explicitly instead of
            // replaying an expired/blocked announcement after maintenance ends.
            return ['success' => false, 'receipts' => [], 'delivery_started' => false,
                'partial_delivery' => false, 'submission_uncertain' => false,
                'failure_category' => 'announcement_activity_timeout',
                'error_code' => 'announcement_activity_timeout',
                'message' => 'Announcement delivery could not acquire its protected activity lock. No channels were submitted; this request was not replayed.'];
        }
        try {
            $settings = method_exists($this, 'getActiveSettings') ? $this->getActiveSettings() : [];
            if (method_exists($this, 'enterpriseApprovalPermitted') && !$this->enterpriseApprovalPermitted($request)) {
                return ['success'=>false,'receipts'=>[],'delivery_started'=>false,'submission_uncertain'=>false,
                    'message'=>'Enterprise approval changed, expired, or no longer permits this immutable request.'];
            }
            try {
                if (method_exists($this, 'getActiveSettings')) { \SLS\MassNotify\EnterpriseClusterIntegration::prepare($settings, $request); }
            }
            catch (\Throwable $error) {
                return ['success'=>false,'receipts'=>[],'delivery_started'=>false,'submission_uncertain'=>false,
                    'message'=>'Cluster immutable job replication or exclusive authority is unavailable. No channel was started.'];
            }
            if (property_exists($this, 'enterpriseClusterRequest')) { $this->enterpriseClusterRequest = $request; }
            return $this->executeResolvedAnnouncementWithActivity($request, $progress);
        } finally {
            if (property_exists($this, 'enterpriseClusterRequest')) { $this->enterpriseClusterRequest = null; }
            $this->releaseNativeBackupFileLock($activity);
        }
    }

    /** Visual workers do not consume or raise the separate audio contact budget. */
    protected function announcementPhoneVisualLimits(): array
    {
        return ['concurrency' => 4, 'child_seconds' => 12.0, 'phase_seconds' => 90.0];
    }

    protected function startAnnouncementPhoneVisualProcess(string $command, float $seconds)
    {
        if (!function_exists('proc_open') || !is_executable('/usr/bin/timeout')) { return false; }
        $pipes = [];
        // The command is built only by our escaped visual command builder.
        // timeout owns the process group. The builder's managed_deadline option
        // keeps its historical inner timeout in this group so even children
        // ignoring TERM cannot escape the outer deadline and kill grace.
        return @proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', (string)$seconds,
            '/bin/sh', '-c', 'exec ' . $command],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
    }

    private function stopAnnouncementPhoneVisualProcesses(array &$running): void
    {
        $groups = array_column($running, 'pid');
        foreach ($running as $child) { @proc_terminate($child['process'], 15); }
        $deadline = hrtime(true) / 1000000000 + 1.25;
        do {
            foreach ($running as $target => $child) {
                $status = proc_get_status($child['process']);
                if (!$status['running']) { proc_close($child['process']); unset($running[$target]); }
            }
            // A timeout wrapper can exit when its direct child handles TERM,
            // while a grandchild ignores it. Keep tracking the owned group even
            // after its leader exits so the kill grace still covers descendants.
            $groups = array_values(array_filter($groups, static function ($pid) {
                return $pid > 1 && function_exists('posix_kill') && @posix_kill(-$pid, 0);
            }));
            if (!$running && !$groups) { return; }
            usleep(10000);
        } while (hrtime(true) / 1000000000 < $deadline);
        foreach ($groups as $pid) {
            if (function_exists('posix_kill')) { @posix_kill(-$pid, 9); }
        }
        foreach ($running as $child) {
            // Each child is our own GNU timeout process-group leader. Kill the
            // entire group if a subprocess ignores the initial termination.
            if (function_exists('posix_kill') && $child['pid'] > 1) { @posix_kill(-$child['pid'], 9); }
            @proc_terminate($child['process'], 9);
            proc_close($child['process']);
        }
        $running = [];
        // Give signalled descendants a bounded opportunity to leave runnable
        // state. Zombies may keep a group identifier until the host reaps them.
        $settle = hrtime(true) / 1000000000 + 0.1;
        while ($groups && hrtime(true) / 1000000000 < $settle) {
            $groups = array_values(array_filter($groups, static function ($pid) { return @posix_kill(-$pid, 0); }));
            if ($groups) { usleep(10000); }
        }
    }

    /** Report every target exactly once; a started child is never auto-replayed. */
    protected function executeAnnouncementPhoneVisualBatch(array $commands, callable $completed, ?int $submissionDeadlineAt = null, ?callable $authorized = null): void
    {
        $limits = $this->announcementPhoneVisualLimits();
        $concurrency = min(4, max(1, (int)($limits['concurrency'] ?? 4)));
        $childSeconds = min(12.0, max(0.05, (float)($limits['child_seconds'] ?? 12)));
        $phaseSeconds = min(90.0, max(0.05, (float)($limits['phase_seconds'] ?? 90)));
        $deadline = hrtime(true) / 1000000000 + $phaseSeconds;
        $latestStart = $submissionDeadlineAt === null ? null : hrtime(true) / 1000000000 + max(0, $submissionDeadlineAt - time());
        $pending = $commands; $running = [];
        try {
            while ($pending || $running) {
                // Persist completed receipts before launching replacements.
                foreach ($running as $target => $child) {
                    $status = proc_get_status($child['process']);
                    if ($status['running']) { continue; }
                    $closed = proc_close($child['process']);
                    unset($running[$target]);
                    $exit = $status['exitcode'] >= 0 ? $status['exitcode']
                        : (!empty($status['signaled']) ? 128 + (int)$status['termsig'] : $closed);
                    $completed((string)$target, ['success' => $exit === 0, 'started' => true,
                        'exit_code' => $exit, 'reason' => in_array($exit, [124, 137, 143], true) ? 'child_timeout' : 'child_finished']);
                }
                $remaining = $deadline - hrtime(true) / 1000000000;
                if ($remaining <= 0) {
                    $inflight = array_keys($running);
                    $this->stopAnnouncementPhoneVisualProcesses($running);
                    foreach ($inflight as $target) { $completed((string)$target, ['success' => false, 'started' => true, 'reason' => 'phase_timeout']); }
                    foreach (array_keys($pending) as $target) { $completed((string)$target, ['success' => false, 'started' => false, 'reason' => 'phase_timeout']); }
                    $pending = [];
                    break;
                }
                while ($pending && count($running) < $concurrency && ($remaining = $deadline - hrtime(true) / 1000000000) > 0) {
                    if ($latestStart !== null && (time() >= $submissionDeadlineAt || hrtime(true) / 1000000000 >= $latestStart)) {
                        foreach (array_keys($pending) as $target) { $completed((string)$target, ['success' => false, 'started' => false, 'reason' => 'schedule_deadline']); }
                        $pending = []; break;
                    }
                    $target = array_key_first($pending); $command = $pending[$target]; unset($pending[$target]);
                    if ($authorized !== null && !$authorized((string)$target)) {
                        $completed((string)$target,['success'=>false,'started'=>false,'reason'=>'permission_changed']); continue;
                    }
                    try { $process = is_string($command) && $command !== ''
                        ? $this->startAnnouncementPhoneVisualProcess($command, min($childSeconds, $remaining)) : false; }
                    catch (\Throwable $error) { $process = false; }
                    if (!is_resource($process)) {
                        $completed((string)$target, ['success' => false, 'started' => false, 'reason' => 'start_failed']);
                        continue;
                    }
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        $closed = proc_close($process);
                        $exit = $status['exitcode'] >= 0 ? $status['exitcode']
                            : (!empty($status['signaled']) ? 128 + (int)$status['termsig'] : $closed);
                        $completed((string)$target, ['success' => $exit === 0, 'started' => true,
                            'exit_code' => $exit, 'reason' => in_array($exit, [124, 137, 143], true) ? 'child_timeout' : 'child_finished']);
                        continue;
                    }
                    $running[$target] = ['process' => $process, 'pid' => (int)$status['pid']];
                }
                if ($running) { usleep(10000); }
            }
        } finally {
            if ($running) { $this->stopAnnouncementPhoneVisualProcesses($running); }
        }
    }

    private function executeResolvedAnnouncementWithActivity(array $request, $progress = null)
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
        $record = function ($channel, $target, $state, $detail = '', $retryable = true, array $metadata = []) use (&$receipts, $progress) {
            $receipts[] = array_merge(['channel' => $channel, 'target' => (string)$target, 'state' => $state, 'detail' => $detail,
                'retryable' => $state === 'failed' && $retryable], $metadata);
            if ($progress) { $progress($receipts, 'Delivering announcement…'); }
        };
        $scheduleDeadline = $this->scheduledAnnouncementDeadline($request);
        if (!empty($request['automation_context'])) {
            $expires=$request['automation_context']['expires_at'];
            $scheduleDeadline=$scheduleDeadline===null ? $expires : min($scheduleDeadline,$expires);
        }
        $filterDeadlineTargets = function (string $channel, array $targets) use ($scheduleDeadline, $record): array {
            if ($scheduleDeadline === null || time() < $scheduleDeadline) { return $targets; }
            foreach ($targets as $target) { $record($channel, $target, 'failed', 'The scheduled delivery deadline passed before submission. No delivery was attempted.', false,
                ['submission_started' => false, 'failure_category' => 'schedule_deadline']); }
            return [];
        };
        $filterCredentialTargets = function (string $channel, string $key, array $targets) use ($request, $record): array {
            if (!empty($request['weather_context'])) {
                try { $allowed = $this->weatherChannelAuthorization($request['weather_context']); }
                catch (\Throwable $error) { $allowed = ['status'=>'deferred']; }
                $eligible = $allowed['status'] === 'eligible' ? ($allowed[$key === 'voice_recipient_ids' ? 'voice_recipient_ids' : 'sms_recipient_ids'] ?? []) : [];
                if (in_array($key, ['voice_recipient_ids', 'sms_recipient_ids'], true)) {
                    foreach (array_diff($targets, $eligible) as $target) { $record($channel, $target, 'cancelled', 'Weather source, current quiet hours or original recipient authorization no longer permits this delivery.', false); }
                    $targets = array_values(array_intersect($targets, $eligible));
                }
            }
            if (!empty($request['automation_context']) && !$this->automationContextPermitted($request['automation_context'])) {
                foreach ($targets as $target) { $record($channel,$target,'cancelled','Trigger expired, was revoked, changed or superseded before this destination.',false); }
                return [];
            }
            if (!isset($request['api_credential_id']) || !$targets) { return $targets; }
            $settings = $this->getActiveSettings();
            $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, (string)$request['api_credential_id']);
            $allowed = [];
            if (is_array($principal) && \SLS\MassNotify\ApiSecurity::permits($principal, 'send')) {
                $allowed = ($principal['audience']['unrestricted'] ?? false) === true ? $targets
                    : (\SLS\MassNotify\ApiSecurity::allowedAudience($principal, $settings)[$key] ?? []);
            }
            if ($key === 'desktops') {
                $currentIds = [];
                foreach ($settings['desktop_clients'] ?? [] as $client) {
                    if (is_array($client) && !empty($client['enabled'])) { $currentIds[(string)($client['username'] ?? '')] = (string)($client['client_id'] ?? ''); }
                }
                $allowed = array_values(array_filter($allowed, static function ($username) use ($request, $currentIds) {
                    return isset($request['desktop_client_ids'][$username], $currentIds[$username])
                        && hash_equals((string)$request['desktop_client_ids'][$username], $currentIds[$username]);
                }));
            }
            foreach ($targets as $target) {
                if (!in_array((string)$target, $allowed, true)) { $record($channel, $target, 'cancelled', 'API credential was revoked or no longer permits this destination.', false); }
            }
            return array_values(array_intersect(array_map('strval', $targets), $allowed));
        };
        // A queued request never acquires newly allowed recipients. Revocation,
        // removed scopes and audience reductions take effect before submission.
        $credentialVoiceIds = null;
        if (isset($request['api_credential_id'])) {
            $settings = $this->getActiveSettings();
            $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, (string)$request['api_credential_id']);
            $allowed = ['phones' => [], 'desktops' => [], 'webhooks' => [], 'voice_recipient_ids' => []];
            if (is_array($principal) && \SLS\MassNotify\ApiSecurity::permits($principal, 'send')) {
                $allowed = ($principal['audience']['unrestricted'] ?? false) === true
                    ? array_intersect_key($request, $allowed) : \SLS\MassNotify\ApiSecurity::allowedAudience($principal, $settings);
            }
            foreach ([['audio', &$audioPhones, 'phones'], ['sip_notify', &$visualPhones, 'phones'],
                      ['desktop', &$request['desktops'], 'desktops'], ['webhook', &$request['webhooks'], 'webhooks']] as &$channel) {
                foreach ($channel[1] as $target) {
                    if (!in_array((string)$target, $allowed[$channel[2]] ?? [], true)) {
                        if ($channel[0] !== 'audio' || $request['audio_mode'] !== 'none') {
                            $record($channel[0], $target, 'cancelled', 'API credential was revoked or no longer permits this destination.', false);
                        }
                    }
                }
                $channel[1] = array_values(array_intersect(array_map('strval', $channel[1]), $allowed[$channel[2]] ?? []));
            }
            unset($channel);
            $credentialVoiceIds = $allowed['voice_recipient_ids'] ?? [];
        }
        // Keep unavailable members of the requested audience in the outcome.
        // They must not disappear just because other destinations accepted it.
        $unavailable = array_values(array_unique(array_map('strval', $request['unavailable_phones'] ?? [])));
        foreach ($channelTargets('sip_notify', $unavailable) as $target) {
            $record('sip_notify', $target, 'unavailable', 'Phone was unavailable when this announcement was submitted.');
        }
        if ($request['audio_mode'] !== 'none') {
            foreach ($channelTargets('audio', $unavailable) as $target) {
                $record('audio', $target, 'unavailable', 'Phone was unavailable when this announcement was submitted.');
            }
        }
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
        $outboundTargets = [];
        $voiceIds = $channelTargets('external_voice', $request['voice_recipient_ids'] ?? []);
        if ($credentialVoiceIds !== null) {
            foreach ($voiceIds as $target) {
                if (!in_array((string)$target, $credentialVoiceIds, true)) { $record('external_voice', $target, 'cancelled', 'API credential was revoked or no longer permits this destination.', false); }
            }
            $voiceIds = array_values(array_intersect($voiceIds, $credentialVoiceIds));
        }
        if ($voiceIds) {
            $currentVoice = $this->outboundVoiceTargets($this->getActiveSettings());
            $snapshots = [];
            foreach (($request['outbound_targets'] ?? []) as $target) {
                if (is_array($target) && is_string($target['id'] ?? null)) { $snapshots[$target['id']] = $target; }
            }
            foreach ($voiceIds as $id) {
                if (!isset($snapshots[$id])) {
                    $record('external_voice', $id, 'failed', 'The original external voice destination snapshot is missing. Review the alert and send a new request.', false);
                } elseif (!isset($currentVoice[$id])) {
                    $record('external_voice', $id, 'cancelled', 'External voice or this saved recipient is no longer enabled.', false);
                } elseif (!hash_equals($this->outboundVoiceFingerprint($snapshots[$id]), $this->outboundVoiceFingerprint($currentVoice[$id]))) {
                    $record('external_voice', $id, 'cancelled', 'The destination number, trunk selection, or caller ID changed after Send. No call was queued.', false);
                } else { $outboundTargets[] = $snapshots[$id]; }
            }
        }
        $sender = $this->sanitizeScheduleText($request['sender'] ?? 'System', 80, true);
        $visualMessage = $request['message'] . "\nSent by: " . $sender;
        $displayTimeout = (int)$request['display_timeout'];
        $audioDuration = 0; $audioQueued = false;
        $desktopPublished = false;
        $publishDesktop = function () use (&$desktopPublished, &$displayTimeout, $request, $visualMessage, $record, $sender, $filterCredentialTargets, $filterDeadlineTargets, $scheduleDeadline) {
            $request['desktops'] = $filterDeadlineTargets('desktop', $filterCredentialTargets('desktop', 'desktops', $request['desktops']));
            if (empty($request['desktops'])) { return; }
            $command = $this->buildAnnouncementVisualPushCommand($visualMessage, [], $displayTimeout, [
                'mode' => 'api_only', 'desktop_clients' => $request['desktops'],
                'schedule_deadline_at' => $scheduleDeadline,
                'incident_context' => $request['incident_context'] ?? null,
                'is_test' => ($request['is_test'] ?? false) === true,
                'image' => $request['image'], 'title' => $request['title'], 'background_color' => $request['background_color'], 'sender' => $sender,
            ]);
            $command = \SLS\MassNotify\EnterpriseClusterIntegration::command($request, $command, method_exists($this,'getActiveSettings') ? $this->getActiveSettings() : []);
            try { $result = $this->executeAnnouncementVisualPushCommand($command); }
            catch (\Throwable $error) { $result = ['success' => false]; }
            $desktopPublished = !empty($result['success']);
            $expiredBeforePublication = !$desktopPublished && ($result['exit_code'] ?? null) === 78
                && in_array('SLS_SUBMISSION_REJECTED schedule_deadline_expired', $result['output'] ?? [], true);
            $eventId = '';
            foreach (($result['output'] ?? []) as $line) {
                if (strpos((string)$line, 'SLS_DESKTOP_PUBLICATION ') !== 0) { continue; }
                $publication = json_decode(substr($line, strlen('SLS_DESKTOP_PUBLICATION ')), true);
                if (is_string($publication['event_id'] ?? null)
                    && preg_match('/^announcement-[0-9]{20}-[a-f0-9]{32}$/D', $publication['event_id'])) { $eventId = $publication['event_id']; }
            }
            foreach ($request['desktops'] as $target) {
                if ($expiredBeforePublication) {
                    $record('desktop', $target, 'failed', 'The scheduled start deadline elapsed before the desktop event was published. No event was submitted.', false,
                        ['submission_started' => false, 'failure_category' => 'schedule_deadline']);
                    continue;
                }
                $metadata = $desktopPublished && $eventId !== '' ? ['event_id' => $eventId] : [];
                if (array_key_exists($target, $request['desktop_client_ids'] ?? [])) { $metadata['client_id'] = $request['desktop_client_ids'][$target]; }
                $record('desktop', $target, $desktopPublished ? 'published' : 'uncertain',
                    $desktopPublished ? ($eventId !== '' ? 'Awaiting desktop application receipt.' : 'Published; exact receipt tracking is unavailable for this event.')
                        : 'Desktop journal publication could not be confirmed.', false, $metadata);
            }
        };
        $needsDuration = $request['timeout_mode'] === 'audio' && $request['audio_mode'] !== 'none';
        if (!$needsDuration) { $publishDesktop(); }
        if ($request['audio_mode'] !== 'none') { $audioPhones = $filterDeadlineTargets('audio', $filterCredentialTargets('audio', 'phones', $audioPhones)); }
        $permittedVoice = $filterDeadlineTargets('external_voice', $filterCredentialTargets('external_voice', 'voice_recipient_ids', array_column($outboundTargets, 'id')));
        $outboundTargets = array_values(array_filter($outboundTargets, static function ($row) use ($permittedVoice) { return in_array($row['id'], $permittedVoice, true); }));
        if ($request['audio_mode'] !== 'none' && ($audioPhones || $outboundTargets)) {
            $this->lastAudioQueueResults = [];
            $this->lastOutboundQueueResults = [];
            $externalVoice = $request['external_voice'] ?? $request['voice'];
            $batches = [['phones' => $audioPhones, 'outbound' => $outboundTargets, 'voice' => $request['voice']]];
            if ($outboundTargets && in_array($request['audio_mode'], ['tts', 'tones_tts'], true) && $externalVoice !== $request['voice']) {
                $batches = $audioPhones ? [['phones' => $audioPhones, 'outbound' => [], 'voice' => $request['voice']]] : [];
                $batches[] = ['phones' => [], 'outbound' => $outboundTargets, 'voice' => $externalVoice];
            }
            $phoneAudio = []; $externalAudio = [];
            foreach ($batches as $batch) {
                try { $audio = $this->sendAnnouncementTtsAudio($batch['phones'], $request['message'], [
                    'audio_mode' => $request['audio_mode'], 'opening_tone' => $request['opening_tone'],
                    'closing_tone' => $request['closing_tone'], 'piper_voice' => $batch['voice'],
                    'pronunciation_rules' => $request['pronunciation_rules'] ?? [],
                    'tts_volume' => $request['volume'], 'trigger_source' => $request['trigger_source'], 'sender' => $sender,
                    'priority' => $request['priority'] ?? 'normal',
                    'api_credential_id' => $request['api_credential_id'] ?? '',
                    'automation_context' => $request['automation_context'] ?? null,
                    'outbound_targets' => $batch['outbound'], 'correlation' => $request['delivery_id'] ?? '',
                    'schedule_deadline_at' => $scheduleDeadline,
                ]); } catch (\Throwable $error) { $audio = ['success' => false, 'message' => 'Audio preparation or queueing failed.']; }
                $audioDuration = max($audioDuration, (int)ceil($audio['audio_duration_seconds'] ?? 0));
                $audioQueued = $audioQueued || !empty($audio['delivery_started']);
                if ($batch['phones']) { $phoneAudio = $audio; }
                if ($batch['outbound']) { $externalAudio = $audio; }
            }
            $audio = $phoneAudio;
            foreach ($audioPhones as $target) {
                if (in_array((string)$target, $audio['permission_denied_targets']['phones'] ?? [], true)) {
                    $record('audio', $target, 'cancelled', 'API permission changed during speech preparation. No audio was queued for this recipient.', false);
                    continue;
                }
                $accepted = !empty($this->lastAudioQueueResults[(string)$target]);
                $uncertain = $accepted && !empty($audio['submission_uncertain']);
                $record('audio', $target, $uncertain ? 'uncertain' : ($accepted ? 'queued' : 'failed'),
                    $uncertain ? 'A call file was submitted, but durable spool confirmation failed. Review the outcome before resubmitting.'
                        : ($accepted ? 'Queued for Asterisk; handset answer and playback are unverified.' : (string)($audio['message'] ?? 'Audio queue failed.')),
                    !$uncertain && ($audio['retryable'] ?? true),
                    !$accepted && ($audio['error'] ?? '') === 'schedule_deadline_expired' ? ['submission_started' => false, 'failure_category' => 'schedule_deadline'] : []);
            }
            $audio = $externalAudio;
            foreach ($outboundTargets as $target) {
                if (in_array((string)$target['id'], $audio['permission_denied_targets']['voice_recipient_ids'] ?? [], true)) {
                    $record('external_voice', $target['id'], 'cancelled', 'API permission changed during speech preparation. No external call was queued for this recipient.', false);
                    continue;
                }
                $row = $this->lastOutboundQueueResults[$target['id']] ?? [];
                $state = ($row['state'] ?? '') === 'queued' ? 'queued'
                    : (($row['state'] ?? '') === 'submission_uncertain' || !empty($audio['submission_uncertain']) ? 'uncertain' : 'failed');
                $metadata = $state === 'failed' && ($audio['error'] ?? '') === 'schedule_deadline_expired'
                    ? ['submission_started' => false, 'failure_category' => 'schedule_deadline'] : [];
                if (is_string($row['failure_code'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $row['failure_code'])) {
                    $metadata['failure_code'] = $row['failure_code'];
                }
                $record('external_voice', $target['id'], $state,
                    $row['detail'] ?? ($state === 'queued' ? 'Queued through FreePBX; remote answer and playback are unverified.' : ($audio['message'] ?? 'External voice queueing failed.')),
                    !empty($row['retryable']) && empty($audio['submission_uncertain']),
                    $metadata);
            }
            if ($needsDuration) { $displayTimeout = max(1, $audioDuration); }
        }
        if ($needsDuration) { $publishDesktop(); }
        $request['webhooks'] = $filterDeadlineTargets('webhook', $filterCredentialTargets('webhook', 'webhooks', $request['webhooks']));
        $webhookUncertain = false;
        try {
            $webhooks = $this->dispatchAnnouncementWebhooks($request['webhooks'], $request['message'], $request['title'],
                $request['background_color'], [['Sent by', $sender]], $request['delivery_id'] ?? '',
                $request['webhook_fingerprints'] ?? [], $request['delivery_timestamp'] ?? '', $scheduleDeadline, $request['incident_context'] ?? null,
                array_filter(array_intersect_key($request, array_flip(['api_credential_id','automation_context'])), static fn($value)=>$value !== null));
        } catch (\Throwable $error) {
            $webhookUncertain = true;
            $webhooks = ['accepted' => [], 'failed' => array_map(static function ($id) {
                return ['id' => $id, 'name' => $id, 'error' => 'Webhook submission could not be confirmed.'];
            }, $request['webhooks'])];
        }
        foreach ($webhooks['accepted'] as $row) {
            $record('webhook', $row['id'], 'accepted', $row['name'] . ': Accepted by webhook service. Human receipt is not tracked.', false,
                ['http_status' => (int)($row['http_status'] ?? 0)]);
        }
        foreach ($webhooks['failed'] as $row) {
            // An HTTP timeout or dispatcher interruption can happen after the
            // receiver accepted the body. Never offer an automatic replay.
            $uncertain = $webhookUncertain || in_array($row['error'] ?? '', ['delivery_timeout', 'delivery_unconfirmed', 'dispatcher_failed'], true);
            $state = $uncertain ? 'uncertain' : (($row['status'] ?? '') === 'cancelled' ? 'cancelled' : 'failed');
            $safeDetails = [
                'authorization_changed' => 'The originating trigger or operator authorization changed before this webhook transmission. No further request was sent.',
                'schedule_deadline_expired' => 'The scheduled start deadline elapsed before this webhook submission. No further request was sent.',
                'tls_failure' => 'The webhook HTTPS connection failed certificate or TLS verification. Check the destination certificate chain, hostname and PBX clock. No request body was sent.',
                'dns_failure' => 'The webhook hostname could not be resolved. Check the destination hostname and PBX DNS service. No request body was sent.',
                'network_failure' => 'The webhook server could not be reached before transmission. Check its address, availability and outbound HTTPS access.',
                'legacy_route_snapshot_missing' => 'Original destination snapshot is missing. No webhook request was sent. Review the announcement and submit a new request.',
                'legacy_payload_snapshot_missing' => 'Original announcement identity or timestamp is missing. No webhook request was sent. Review the announcement and submit a new request.',
                'destination_changed' => 'Destination URL or integration format changed after Send. No webhook request was sent.',
                'destination_unavailable' => 'Destination is no longer enabled. No webhook request was sent.',
                'payload_too_large' => 'The message exceeds the webhook integration size limit. No request was sent. Review and shorten the announcement before submitting a new request.',
                'invalid_payload_format' => 'The destination integration format is unsupported. No request was sent. Correct the destination settings before submitting a new request.',
            ];
            $httpStatus = (int)($row['http_status'] ?? 0);
            $failureCode = (string)($row['failure_reason'] ?? $row['error'] ?? '');
            $detail = $safeDetails[$row['error'] ?? ''] ?? ($row['error'] ?? 'Webhook result is unavailable.');
            if ($httpStatus >= 400) { $detail = 'Webhook service returned HTTP ' . $httpStatus . '. Review the destination configuration and provider status.'; }
            elseif ($uncertain) { $detail = 'Webhook response was not confirmed. Human receipt is not tracked; this submission will not be replayed automatically.'; }
            $metadata = ['http_status' => $httpStatus, 'failure_code' => $failureCode];
            if (($row['error'] ?? '') === 'schedule_deadline_expired') { $metadata += ['submission_started' => false, 'failure_category' => 'schedule_deadline']; }
            $record('webhook', $row['id'], $state, $row['name'] . ': ' . $detail, $uncertain ? false : ($row['retryable'] ?? true), $metadata);
        }
        // Webhook dispatch above has a six-second provider budget and an
        // outer ten-second timeout plus one-second kill grace. It cannot wait
        // behind the phone visual audience, including slow/unresponsive phones.
        if ($audioQueued) { sleep(1); }
        $visualPhones = $filterCredentialTargets('sip_notify', 'phones', $visualPhones);
        $visualCommands = [];
        foreach ($visualPhones as $target) {
            try {
                $visualCommands[(string)$target] = $this->buildAnnouncementVisualPushCommand($visualMessage, [(string)$target], $displayTimeout, [
                    'mode' => 'phone_only', 'managed_deadline' => true, 'image' => $request['image'], 'title' => $request['title'],
                    'is_test' => ($request['is_test'] ?? false) === true,
                    'background_color' => $request['background_color'], 'sender' => $sender,
                ]);
                $visualCommands[(string)$target] = \SLS\MassNotify\EnterpriseClusterIntegration::command($request, $visualCommands[(string)$target], method_exists($this,'getActiveSettings') ? $this->getActiveSettings() : []);
            } catch (\Throwable $error) {
                $record('sip_notify', $target, 'failed', 'Phone visual preparation failed before a sender was started.');
            }
        }
        $this->executeAnnouncementPhoneVisualBatch($visualCommands, static function (string $target, array $result) use ($record): void {
            $accepted = !empty($result['success']);
            $started = !empty($result['started']);
            $state = $accepted ? 'submitted' : ($started ? 'uncertain' : 'failed');
            $detail = $accepted ? 'Asterisk accepted the request; handset display is not confirmed.'
                : ($started ? 'The visual sender started, but its submission could not be confirmed. It will not be replayed automatically.'
                    : (($result['reason'] ?? '') === 'phase_timeout'
                        ? 'The phone visual time budget ended before this sender started. No submission was attempted.'
                        : 'The phone visual sender could not be started. No submission was attempted.'));
            if (($result['reason'] ?? '')==='permission_changed') {
                $record('sip_notify',$target,'cancelled','Authorization changed before this phone visual request. No request was submitted.',false); return;
            }
            $record('sip_notify', $target, $state, ($result['reason'] ?? '') === 'schedule_deadline' ? 'The scheduled deadline passed before this visual sender started. No submission was attempted.' : $detail, !$started && ($result['reason'] ?? '') !== 'schedule_deadline',
                ['submission_started' => $started, 'failure_category' => $accepted ? '' : ($result['reason'] ?? 'sender_failed')]);
        }, $scheduleDeadline, function(string $target) use($request): bool {
            if (!empty($request['automation_context']) && !$this->automationContextPermitted($request['automation_context'])) { return false; }
            if (isset($request['api_credential_id'])) {
                $settings=$this->getActiveSettings(); $principal=\SLS\MassNotify\ApiSecurity::currentCredential($settings,$request['api_credential_id']);
                return $principal && \SLS\MassNotify\ApiSecurity::permits($principal,'send') && (($principal['audience']['unrestricted'] ?? false)===true || in_array($target,\SLS\MassNotify\ApiSecurity::allowedAudience($principal,$settings)['phones'],true));
            }
            return true;
        });
        if (!empty($request['email_recipient_ids'])) { $this->deliverAnnouncementEmailTargets($request, $receipts, $progress); }
        if (!empty($request['sms_recipient_ids'])) { $this->deliverAnnouncementSmsTargets($request, $receipts, $progress); }
        $receiptResult = $this->refreshAnnouncementDesktopReceipts(['receipts' => $receipts], $request['delivery_timestamp'] ?? '');
        $receipts = $receiptResult['receipts'];
        $failed = array_filter($receipts, [\SlsAnnouncementJobStore::class, 'receiptNeedsAttention']);
        $uncertainCount = count(array_filter($receipts, static function ($receipt) { return $receipt['state'] === 'uncertain'; }));
        $unconfirmedWebhooks = count(array_filter($receipts, [\SlsAnnouncementJobStore::class, 'unconfirmedWebhook']));
        $accepted = count($receipts) - count($failed) - $unconfirmedWebhooks;
        $deadlineExpired = count(array_filter($receipts, static function ($row) { return ($row['failure_category'] ?? '') === 'schedule_deadline'; })) > 0;
        $this->appendAnnouncementNotifyLog($request['message'], [
            'status' => $failed ? 'partial_failure' : 'submitted', 'trigger_source' => $request['trigger_source'],
            'sender' => $sender, 'phones' => $request['phones'], 'desktop_clients' => $request['desktops'],
            'retry_operator' => $request['retry_operator'] ?? '',
            'unavailable_phones' => $unavailable,
            'voice_recipient_ids' => $voiceIds,
            'priority' => $request['priority'] ?? 'normal',
            'webhook_destination_ids' => $request['webhooks'], 'email_recipient_ids' => $request['email_recipient_ids'] ?? [], 'sms_recipient_ids' => $request['sms_recipient_ids'] ?? [], 'delivery_receipts' => $receipts,
        ]);
        return array_merge($receiptResult, ['success' => !$failed && ($accepted > 0 || $unconfirmedWebhooks > 0), 'receipts' => $receipts, 'sender' => $sender,
            'message' => sprintf('%d channel destination(s) accepted; %d need attention.', $accepted, count($failed))
                . ($unconfirmedWebhooks ? sprintf(' %d webhook response(s) unconfirmed; human receipt is not tracked.', $unconfirmedWebhooks) : '')
                . ($uncertainCount ? sprintf(' %d submission(s) may have been delivered; review their receipts before sending again.', $uncertainCount) : ''),
            'schedule_expired' => $deadlineExpired, 'partial_delivery' => $accepted > 0 && count($failed) > 0, 'delivery_started' => $accepted > 0 || $uncertainCount > 0,
            'submission_uncertain' => $uncertainCount > 0,
            'desktop_published' => $desktopPublished, 'cooldown_remaining' => $this->getAnnouncementCooldownState()['remaining'],
            'display_timeout_seconds' => $displayTimeout]);
    }

    /** One bounded, shell-free invocation; addresses and message travel on stdin. */
    protected function submitAnnouncementEmail(array $envelope): array
    {
        $uncertain = ['state' => 'uncertain', 'retryable' => false, 'detail' => 'Email submission could not be confirmed. It will not be replayed automatically.'];
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > 131072) { return ['state' => 'failed', 'retryable' => false, 'submission_started' => false, 'detail' => 'Email envelope exceeds its size limit; no submission was attempted.']; }
        $process = $this->startAnnouncementEmailProcess($pipes);
        if (!is_resource($process)) { return ['state' => 'failed', 'retryable' => true, 'submission_started' => false, 'detail' => 'The email sender could not start; no submission was attempted.']; }
        $output = ''; $offset = 0; $deadline = microtime(true) + 12; $exit = -1;
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        try {
            while (true) {
                if (isset($pipes[0])) {
                    $written = @fwrite($pipes[0], substr($body, $offset, 8192));
                    if ($written === false) { break; }
                    $offset += $written;
                    if ($offset === strlen($body)) { fclose($pipes[0]); unset($pipes[0]); }
                }
                $chunk = stream_get_contents($pipes[1], 8193);
                if ($chunk === false) { break; }
                $output .= $chunk;
                if (strlen($output) > 8192 || microtime(true) >= $deadline) { break; }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= (string)stream_get_contents($pipes[1], 8193);
                    $exit = (int)$status['exitcode'];
                    break;
                }
                usleep(10000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                if (function_exists('posix_kill')) { @posix_kill(-((int)$status['pid']), 9); }
                @proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($process);
        }
        $result = strlen($output) <= 8192 ? json_decode(trim($output), true, 8) : null;
        if ($exit === 2 && is_array($result) && ($result['ok'] ?? null) === false
            && ($result['state'] ?? null) === 'rejected' && ($result['error_code'] ?? null) === 'invalid_envelope'
            && ($result['retryable'] ?? null) === false && !array_key_exists('recipient_id', $result) && !array_key_exists('message_id', $result)) {
            return ['state' => 'failed', 'retryable' => false, 'submission_started' => false,
                'detail' => 'The email sender rejected the envelope before submission. Review the message and saved sender settings.'];
        }
        if ($exit !== 0 || !is_array($result)
            || ($result['recipient_id'] ?? null) !== $envelope['recipient_id']
            || ($result['message_id'] ?? null) !== $envelope['message_id']
            || !is_bool($result['ok'] ?? null) || !is_bool($result['retryable'] ?? null)) { return $uncertain; }
        if (($result['state'] ?? '') === 'accepted' && $result['ok'] === true && $result['retryable'] === false) {
            return ['state' => 'accepted', 'retryable' => false, 'detail' => 'Accepted by PBX mail service. This does not confirm inbox delivery or that a person read it.'];
        }
        if (($result['state'] ?? '') === 'rejected' && $result['ok'] === false) {
            $code = $result['error_code'] ?? '';
            if (!in_array($code, ['sendmail_unavailable', 'sendmail_temporary_failure', 'sendmail_rejected'], true)) { return $uncertain; }
            return ['state' => 'failed', 'retryable' => $result['retryable'] === true && in_array($code, ['sendmail_unavailable', 'sendmail_temporary_failure'], true),
                'detail' => 'PBX mail service rejected this submission. Review PBX mail logs before an eligible explicit retry.'];
        }
        return $uncertain;
    }

    protected function startAnnouncementEmailProcess(&$pipes)
    {
        $pipes = [];
        if (!function_exists('proc_open') || !is_executable('/usr/bin/timeout')) { return false; }
        return @proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=1', '10', '/usr/bin/python3', '-I',
            self::RUNTIME_DIR . '/sls_announcement_email.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
            ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8'], ['bypass_shell' => true]);
    }

    protected function announcementEmailMonotonic(): float { return hrtime(true) / 1000000000; }

    private function deliverAnnouncementEmailTargets(array $request, array &$receipts, callable $progress): void
    {
        $ids = $request['email_recipient_ids'] ?? [];
        if (isset($request['only_channels'])) { $ids = array_values(array_intersect($ids, $request['only_channels']['email'] ?? [])); }
        if (!$ids) { return; }
        $scheduleDeadline = $this->scheduledAnnouncementDeadline($request);
        $started = $this->announcementEmailMonotonic();
        $phaseDeadline = gmdate('c', time() + 90);
        $attempt = (string)($request['email_attempt_id'] ?? '');
        foreach ($ids as $id) {
            $index = count($receipts);
            $frozen = $request['email_targets'][$id] ?? null;
            $row = ['channel' => 'email', 'target' => $id, 'state' => 'cancelled', 'retryable' => false,
                'attempt_id' => $attempt, 'message_id' => is_array($frozen) ? ($frozen['message_id'] ?? '') : '',
                'email_phase_deadline' => $phaseDeadline, 'submission_started' => false];
            $allowed = false; $settings = $this->getActiveSettings();
            try {
                $current = $this->announcementEmailTargets($settings)[$id] ?? null;
                $allowed = is_array($frozen) && is_array($current)
                    && is_string($frozen['fingerprint'] ?? null)
                    && hash_equals($frozen['fingerprint'], $this->announcementEmailFingerprint($current))
                    && hash_equals($frozen['fingerprint'], $this->announcementEmailFingerprint($frozen));
            } catch (\Throwable $error) { $allowed = false; }
            if ($allowed && isset($request['api_credential_id'])) {
                $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, (string)$request['api_credential_id']);
                $allowed = is_array($principal) && \SLS\MassNotify\ApiSecurity::permits($principal, 'send')
                    && (($principal['audience']['unrestricted'] ?? false) === true
                        || in_array($id, \SLS\MassNotify\ApiSecurity::allowedAudience($principal, $settings)['email_recipient_ids'] ?? [], true));
            }
            if ($allowed && !empty($request['automation_context'])) { $allowed=$this->automationContextPermitted($request['automation_context']); }
            if (!$allowed) { $row['detail'] = 'Email destination, sender, channel availability or API permission changed after Send. No email was submitted.'; }
            elseif ($scheduleDeadline !== null && time() >= $scheduleDeadline) {
                $row['state'] = 'failed'; $row['failure_category'] = 'schedule_deadline';
                $row['detail'] = 'The scheduled deadline passed before email submission. No email was submitted.';
            }
            elseif (!is_int($request['email_expires_at'] ?? null) || time() >= $request['email_expires_at']) {
                $row['detail'] = 'The original announcement email lifetime expired. No email was submitted.';
            } elseif (!preg_match('/^job_[a-f0-9]{32}$/D', $attempt) || !is_string($frozen['message_id'] ?? null)) {
                $row['detail'] = 'Original durable email identity is unavailable. No email was submitted.';
            } elseif ($this->announcementEmailMonotonic() - $started > 78) {
                $row['state'] = 'failed'; $row['retryable'] = true;
                $row['detail'] = 'The 90-second email batch budget ended before this destination started. No email was submitted; explicit retry is eligible within the original lifetime.';
            } else {
                $envelope = ['recipient_id' => $id, 'address' => $frozen['address'], 'sender' => $frozen['sender'],
                    'title' => $request['title'], 'message' => $request['message'], 'is_test' => ($request['is_test'] ?? false) === true,
                    'severity' => $request['email_severity'] ?? 'info', 'message_id' => $frozen['message_id'],
                    'created_at' => $request['delivery_timestamp']];
                $row['state'] = 'submitting'; $row['submission_started'] = true;
                $row['detail'] = 'Durable email submission intent recorded; acceptance is not yet confirmed.';
                $receipts[] = $row;
                // A failed intent write throws before any sender can launch.
                $progress($receipts, 'Submitting saved email destination…');
                try { $outcome = $scheduleDeadline !== null && time() >= $scheduleDeadline
                    ? ['state' => 'failed', 'retryable' => false, 'submission_started' => false, 'failure_category' => 'schedule_deadline',
                        'detail' => 'The scheduled deadline passed while recording intent. No email was submitted.']
                    : $this->submitAnnouncementEmail($envelope); }
                catch (\Throwable $error) { $outcome = ['state' => 'uncertain', 'retryable' => false, 'detail' => 'Email submission could not be confirmed. It will not be replayed automatically.']; }
                $receipts[$index] = array_merge($row, $outcome);
                // Persist this result before considering another destination.
                $progress($receipts, 'Recorded email submission result.');
                continue;
            }
            $receipts[] = $row;
            $progress($receipts, 'Recorded email destination outcome.');
        }
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
