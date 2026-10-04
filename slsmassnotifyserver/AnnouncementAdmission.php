<?php
namespace FreePBX\modules;

/** Short recipient cooldown reservations; actual phone capacity remains in Asterisk admission. */
trait SlsAnnouncementAdmission
{
    private $announcementAdmissionLock = null;

    private function announcementAdmissionRecipients(array $request): array
    {
        $keys = [];
        foreach (['phones', 'desktops', 'webhooks', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids'] as $channel) {
            foreach ($request[$channel] ?? [] as $recipient) {
                if (!is_string($recipient) && !is_int($recipient)) { throw new \RuntimeException('Announcement admission recipient is invalid.'); }
                $keys[hash('sha256', $channel . ':' . (string)$recipient)] = true;
            }
        }
        if (count($keys) > 2500) { throw new \RuntimeException('Announcement recipient admission exceeds its capacity.'); }
        $keys = array_keys($keys); sort($keys, SORT_STRING);
        return $keys;
    }

    private function readAnnouncementAdmissionState(): array
    {
        // Constructor rejects symlinked/unsafe storage parents before any read.
        new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR);
        if (!is_resource($this->announcementAdmissionLock)) { throw new \RuntimeException('Announcement admission requires its exclusive lock.'); }
        rewind($this->announcementAdmissionLock);
        $marker = stream_get_contents($this->announcementAdmissionLock, 65);
        if (!in_array($marker, ['', "SLS_ANNOUNCEMENT_ADMISSION_V1\n"], true)) {
            throw new \RuntimeException('Announcement admission initialization marker is corrupt.');
        }
        $path = self::PLUGIN_DATA_DIR . '/announcement-admission.json';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!$before) {
            if ($marker !== '') { throw new \RuntimeException('Established announcement admission state is missing. Preserve recovery evidence before repair.'); }
            return ['schema' => 1, 'global_stamp' => 0, 'reservations' => []];
        }
        $owner = @fileowner(self::PLUGIN_DATA_DIR);
        if (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || $before['uid'] !== $owner
            || ($before['mode'] & 07022) || $before['size'] < 1 || $before['size'] > 2097152) {
            throw new \RuntimeException('Announcement admission storage has unsafe ownership, permissions, type or size.');
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new \RuntimeException('Announcement admission storage cannot be read.'); }
        try {
            $opened = fstat($handle);
            if (!$opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
                throw new \RuntimeException('Announcement admission storage changed while opening.');
            }
            $raw = stream_get_contents($handle, 2097153);
            if (!is_string($raw) || strlen($raw) !== $opened['size']) { throw new \RuntimeException('Announcement admission storage changed while reading.'); }
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            // This new, private journal has one canonical writer. Reject duplicate
            // object keys or manual/partial rewrites instead of silently merging.
            if (json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" !== $raw) {
                throw new \RuntimeException('Announcement admission state is not a complete canonical journal.');
            }
        } finally { fclose($handle); }
        if (!is_array($state) || ($state['schema'] ?? null) !== 1 || !is_int($state['global_stamp'] ?? null)
            || count($state) !== 3 || $state['global_stamp'] < 0 || !is_array($state['reservations'] ?? null) || count($state['reservations']) > 1000) {
            throw new \RuntimeException('Announcement admission storage is corrupt; existing reservations were preserved.');
        }
        foreach ($state['reservations'] as $id => $row) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || !is_array($row)
                || count($row) !== 4
                || !is_array($row['recipients'] ?? null) || !array_is_list($row['recipients']) || count($row['recipients']) > 2500
                || !is_int($row['until'] ?? null) || !is_int($row['retain_until'] ?? null) || !is_int($row['deadline_at'] ?? null)
                || $row['until'] < 0 || $row['deadline_at'] < 0 || $row['retain_until'] < $row['until']) {
                throw new \RuntimeException('Announcement recipient reservation is corrupt.');
            }
            foreach ($row['recipients'] as $recipient) {
                if (!is_string($recipient) || !preg_match('/^[a-f0-9]{64}$/D', $recipient)) {
                    throw new \RuntimeException('Announcement recipient reservation identity is corrupt.');
                }
            }
            if (count(array_unique($row['recipients'])) !== count($row['recipients'])) { throw new \RuntimeException('Announcement recipient reservations contain duplicate identities.'); }
        }
        return $state;
    }

    /** Caller owns announcement-send.lock. Persist admission before a job becomes runnable. */
    private function reserveAnnouncementRecipients(array $request, bool $scheduled): array
    {
        $now = time();
        $deadline = $scheduled ? ($request['schedule_context']['deadline_at'] ?? null) : 0;
        if ($scheduled && (!is_int($deadline) || $deadline <= $now)) {
            return ['success' => false, 'error_code' => 'schedule_deadline_expired', 'message' => 'The maximum start delay elapsed before admission. No channels were submitted.'];
        }
        $recipients = $this->announcementAdmissionRecipients($request);
        $owner = $scheduled ? hash('sha256', 'scheduled:' . ($request['delivery_id'] ?? '')) : bin2hex(random_bytes(32));
        if ($scheduled && !preg_match('/^announcement-[a-f0-9]{32}$/D', (string)($request['delivery_id'] ?? ''))) {
            throw new \RuntimeException('Scheduled admission requires its original delivery identity.');
        }
        $state = $this->readAnnouncementAdmissionState();
        if ($now < $state['global_stamp']) {
            throw new \RuntimeException('The PBX clock moved backwards since announcement admission. New delivery is blocked until time catches up; existing reservations were preserved.');
        }
        foreach ($state['reservations'] as $id => $row) {
            if ($row['retain_until'] <= $now) { unset($state['reservations'][$id]); }
        }
        if ($scheduled && isset($state['reservations'][$owner])) {
            $original = $state['reservations'][$owner];
            if ($original['recipients'] !== $recipients || $original['deadline_at'] !== $deadline) {
                throw new \RuntimeException('Scheduled admission does not match the original audience or deadline.');
            }
            return ['success' => true]; // Reconciliation never renews this reservation.
        }
        $cooldown = $this->getAnnouncementCooldownState();
        if (!$scheduled) {
            // Recheck inside the send lock, including direct retries that were
            // validated before acquiring it. Keep the historical global policy.
            $remaining = max(0, (int)$cooldown['remaining']);
            foreach ($state['reservations'] as $row) { $remaining = max($remaining, $row['until'] - $now); }
            if ($remaining > 0) {
                return ['success' => false, 'error_code' => 'cooldown', 'cooldown_remaining' => $remaining,
                    'message' => sprintf('Announcements are on cooldown. Wait %d seconds before sending.', $remaining)];
            }
        }
        if ($scheduled) {
            $retryAt = 0;
            // A legacy/untracked timestamp conservatively applies to every recipient.
            if ($cooldown['last_run'] !== $state['global_stamp'] && $cooldown['remaining'] > 0) {
                $retryAt = $now + $cooldown['remaining'];
            }
            $lookup = array_fill_keys($recipients, true);
            foreach ($state['reservations'] as $row) {
                if ($row['until'] > $now && array_intersect_key(array_fill_keys($row['recipients'], true), $lookup)) {
                    $retryAt = max($retryAt, $row['until']);
                }
            }
            if ($retryAt > $now) {
                return ['success' => false, 'deferred' => true, 'retry_at' => $retryAt, 'error_code' => 'recipient_cooldown',
                    'message' => 'One or more selected recipients are still within the announcement cooldown. The scheduler will wait within this occurrence\'s original start window.'];
            }
        }
        if (count($state['reservations']) >= 1000) { throw new \RuntimeException('Announcement admission history reached its protected capacity. Wait for existing reservations to expire.'); }
        $until = $now + (int)$cooldown['duration'];
        $state['reservations'][$owner] = ['recipients' => $recipients, 'until' => $until,
            'retain_until' => max($until, $deadline), 'deadline_at' => $deadline];
        $state['global_stamp'] = $now;
        $store = new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR);
        $store->atomic('announcement-admission.json', $state);
        rewind($this->announcementAdmissionLock);
        $marker = "SLS_ANNOUNCEMENT_ADMISSION_V1\n";
        if (fwrite($this->announcementAdmissionLock, $marker) !== strlen($marker)
            || !fflush($this->announcementAdmissionLock) || !fsync($this->announcementAdmissionLock)) {
            throw new \RuntimeException('Announcement admission initialization could not be confirmed. No delivery was started.');
        }
        // Preserve the existing UI/API cooldown file, using the same durable writer.
        // The JSON integer plus newline is also its historical plain-text format.
        $this->writeAnnouncementCooldownTimestamp($now);
        return ['success' => true];
    }

    private function writeAnnouncementCooldownTimestamp(int $timestamp): void
    {
        (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionTimestamp($timestamp);
    }

    private function withAnnouncementAdmissionLock(callable $callback): array
    {
        if (is_resource($this->announcementAdmissionLock)) { return $callback(); }
        $lock = (new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->admissionLock();
        if (!$lock) {
            return ['success' => false, 'deferred' => true, 'retry_at' => time() + 1,
                'error_code' => 'delivery_busy', 'message' => 'Another announcement is being admitted.'];
        }
        $this->announcementAdmissionLock = $lock;
        try { return $callback(); } finally { $this->announcementAdmissionLock = null; \SlsAnnouncementJobStore::unlock($lock); }
    }

    private function recordInteractiveAnnouncementAdmission(array $request): void
    {
        $result = $this->withAnnouncementAdmissionLock(function () use ($request) { return $this->reserveAnnouncementRecipients($request, false); });
        if (empty($result['success'])) { throw new \RuntimeException($result['message'] ?? 'Announcement admission failed before delivery.'); }
    }

    private function admitScheduledAnnouncementRequest(array $request): array
    {
        return $this->withAnnouncementAdmissionLock(function () use ($request) { return $this->reserveAnnouncementRecipients($request, true); });
    }
}
