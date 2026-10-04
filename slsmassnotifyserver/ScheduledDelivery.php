<?php
namespace FreePBX\modules;
require_once __DIR__.'/EnterpriseClusterIntegration.php';

/** Durable occurrence admission. Only the scheduler writes the execution ledger. */
trait SlsScheduledDelivery
{
    private function scheduledTerminalState($state): bool
    {
        return in_array($state, ['success', 'failed', 'missed', 'uncertain'], true);
    }

    private function scheduledRecordFromExisting(array $record, string $state, string $message): array
    {
        return $this->scheduleExecutionRecord(
            ['id' => $record['schedule_id'] ?? '', 'name' => $record['schedule_name'] ?? ''],
            ['id' => $record['occurrence_id'] ?? '', 'run_at_utc' => $record['run_at_utc'] ?? ''],
            $state, $message, $record
        );
    }

    private function reconcileScheduledDeliveryRecord(array $record): array
    {
        try {
            $job = $this->readScheduledAnnouncementJob($record);
        } catch (\Throwable $error) {
            return $this->scheduledRecordFromExisting($record, 'uncertain',
                _('The linked announcement job could not be verified. It was not recreated or replayed; review delivery history and protected storage.'));
        }
        if ($job === null) {
            return $this->scheduledRecordFromExisting($record,
                ($record['state'] ?? '') === 'preparing' ? 'failed' : 'uncertain',
                ($record['state'] ?? '') === 'preparing'
                    ? _('Preparation stopped before a durable announcement job was saved. No delivery was started; add a new future occurrence to try again.')
                    : _('The linked announcement job is missing. Its delivery status is uncertain and it was not recreated or replayed.'));
        }
        $record['job_state'] = (string)($job['state'] ?? '');
        $state = $record['job_state'];
        if (in_array($state, ['prepared', 'queued', 'worker_starting'], true)) {
            return $this->scheduledRecordFromExisting($record, 'queued',
                $state === 'prepared' ? _('Announcement prepared; waiting for protected delivery admission.')
                    : _('Announcement queued; waiting for delivery results.'));
        }
        if ($state === 'running') {
            return $this->scheduledRecordFromExisting($record, 'running', _('Announcement delivery is in progress.'));
        }
        $uncertain = !empty($job['submission_uncertain']) || !empty($job['result']['submission_uncertain']);
        foreach ((array)($job['receipts'] ?? []) as $receipt) {
            if (in_array($receipt['state'] ?? '', ['uncertain', 'submitting'], true)) { $uncertain = true; }
        }
        if ($uncertain) {
            return $this->scheduledRecordFromExisting($record, 'uncertain',
                _('Some submissions could not be confirmed. Review the linked job receipts; this occurrence will not be replayed.'));
        }
        if ($state === 'complete') {
            return $this->scheduledRecordFromExisting($record, 'success',
                (string)($job['message'] ?? _('Announcement submitted. Review channel receipts for delivery details.')));
        }
        if (in_array($state, ['expired', 'failed', 'partial_or_failed'], true)) {
            $submitted = !empty($job['result']['delivery_started']);
            foreach ((array)($job['receipts'] ?? []) as $receipt) {
                if (!in_array($receipt['state'] ?? '', ['failed', 'cancelled', 'expired', 'skipped'], true)) { $submitted = true; }
            }
            return $this->scheduledRecordFromExisting($record, $state === 'expired' && !$submitted ? 'missed' : 'failed',
                (string)($job['message'] ?? _('Announcement delivery failed. Review the linked job receipts.')));
        }
        return $this->scheduledRecordFromExisting($record, 'uncertain',
            _('The linked announcement has an unsupported state and was not replayed.'));
    }

    private function scheduledRequestOptions(array $schedule): array
    {
        $targets = $schedule['targets'] ?? [];
        $delivery = $schedule['delivery'] ?? [];
        return [
            'phones_all' => !empty($targets['phones_all']), 'desktop_all' => !empty($targets['desktop_all']),
            'desktop_clients' => (array)($targets['desktop_clients'] ?? []),
            'voice_recipient_ids' => $targets['voice_recipient_ids'] ?? [],
            'email_recipient_ids' => $targets['email_recipient_ids'] ?? [],
            'sms_recipient_ids' => $targets['sms_recipient_ids'] ?? [],
            'webhook_ids' => $targets['webhook_ids'] ?? [],
            'style' => (string)($delivery['style'] ?? 'standard'), 'title' => (string)($delivery['title'] ?? 'Announcement'),
            'background_color' => (string)($delivery['background_color'] ?? '#1f2937'),
            'audio_mode' => $this->normalizeAnnouncementAudioMode($delivery['audio_mode'] ?? 'none'),
            'opening_tone' => (string)($delivery['opening_tone'] ?? ''), 'closing_tone' => (string)($delivery['closing_tone'] ?? ''),
            'piper_voice' => (string)($delivery['voice'] ?? ''), 'tts_volume' => $delivery['tts_volume'] ?? 25,
            'trigger_source' => 'Scheduled: ' . (string)($schedule['name'] ?? 'Announcement'),
            'sender' => (string)($schedule['created_by'] ?? 'Scheduled announcement'),
        ];
    }

    private function runDurableScheduledAnnouncements(): array
    {
        try { \SLS\MassNotify\EnterpriseClusterIntegration::requireAuthority($this->getActiveSettings()); }
        catch (\Throwable $error) {
            return ['success'=>false,'processed'=>0,'cluster_fenced'=>true,
                'message'=>'Scheduling state was not changed because exclusive cluster authority could not be proved.'];
        }
        $this->ensurePluginDataDir();
        $worker = $this->acquireNativeBackupFileLock(self::SCHEDULE_LOCK_FILE,
            _('The scheduler could not acquire its protected worker lock.'), 1);
        $processed = 0;
        try {
            $store = $this->loadScheduleExecutionStore(true);
            // Reconcile durable links independently of mutable schedule definitions.
            foreach ($store['occurrences'] as $id => $record) {
                if (!is_array($record) || $this->scheduledTerminalState($record['state'] ?? '')) { continue; }
                if (!empty($record['job_id'])) {
                    $record = $this->reconcileScheduledDeliveryRecord($record);
                    $store['occurrences'][$id] = $record;
                    $this->writeScheduleExecutionStore($store);
                    if (($record['job_state'] ?? '') === 'prepared' && !$this->scheduledTerminalState($record['state'])) {
                        // The durable queued ledger link must precede any worker activation.
                        $this->activateScheduledAnnouncementJob($record);
                        $store['occurrences'][$id] = $this->reconcileScheduledDeliveryRecord($record);
                        $this->writeScheduleExecutionStore($store);
                    }
                } elseif (($record['state'] ?? '') === 'claimed'
                    && time() - (strtotime($record['claimed_at'] ?? '') ?: 0) > 300) {
                    $store['occurrences'][$id] = $this->scheduledRecordFromExisting($record, 'uncertain',
                        _('The previous worker stopped after claiming this delivery. It was not replayed to prevent duplicate announcements.'));
                    $this->writeScheduleExecutionStore($store);
                }
            }
            $due = [];
            foreach ((array)($this->getActiveSettings()['scheduled_announcements'] ?? []) as $schedule) {
                if (empty($schedule['enabled'])) { continue; }
                foreach ((array)($schedule['occurrences'] ?? []) as $occurrence) {
                    $runAt = $this->parseScheduleUtcTimestamp($occurrence['run_at_utc'] ?? '');
                    if ($runAt !== false && $runAt <= time()) { $due[] = [$runAt, $schedule, $occurrence]; }
                }
            }
            usort($due, static function ($a, $b) { return ($a[0] <=> $b[0]) ?: strcmp($a[2]['id'], $b[2]['id']); });
            foreach ($due as [$runAt, $scanned, $occurrence]) {
                $id = (string)$occurrence['id'];
                $current = $store['occurrences'][$id] ?? [];
                if ($this->scheduledTerminalState($current['state'] ?? '') || !empty($current['job_id'])
                    || ($current['state'] ?? '') === 'claimed') { continue; }
                $activity = $this->acquireAnnouncementActivityLock(false, 30);
                try {
                    // No settings-lock upgrade under this SH lease. All resolution uses this one snapshot.
                    $settings = $this->getActiveSettings();
                    $schedule = null;
                    foreach ((array)($settings['scheduled_announcements'] ?? []) as $candidate) {
                        if (($candidate['id'] ?? '') !== ($scanned['id'] ?? '') || empty($candidate['enabled'])) { continue; }
                        foreach ((array)($candidate['occurrences'] ?? []) as $liveOccurrence) {
                            if (($liveOccurrence['id'] ?? '') === $id && ($liveOccurrence['run_at_utc'] ?? '') === ($occurrence['run_at_utc'] ?? '')) {
                                $schedule = $candidate; break 2;
                            }
                        }
                    }
                    if ($schedule === null) { continue; }
                    $lateness = $schedule['max_lateness_minutes'] ?? 15;
                    if (!is_int($lateness) || $lateness < 1 || $lateness > 15) { throw new \RuntimeException(_('The schedule has an invalid lateness policy.')); }
                    $deadline = $runAt + $lateness * 60;
                    $current['deadline_at'] = $deadline;
                    if (time() >= $deadline) {
                        $store['occurrences'][$id] = $this->scheduleExecutionRecord($schedule, $occurrence, 'missed',
                            _('The allowed lateness window expired before delivery could start. Add a new future occurrence to try again.'), $current);
                        $this->writeScheduleExecutionStore($store); continue;
                    }
                    $options = $this->scheduledRequestOptions($schedule);
                    $targets = $schedule['targets'] ?? [];
                    $resolved = $this->resolveAnnouncementRequest((array)($targets['extensions'] ?? []), (string)($schedule['message'] ?? ''),
                        false, in_array($options['audio_mode'], ['tts', 'tones_tts'], true), (array)($targets['groups'] ?? []), $options, $settings);
                    if (empty($resolved['success']) || !is_array($resolved['request'] ?? null)) {
                        $store['occurrences'][$id] = $this->scheduleExecutionRecord($schedule, $occurrence, 'failed',
                            (string)($resolved['message'] ?? _('The scheduled request could not be validated. No delivery was started.')), $current);
                        $this->writeScheduleExecutionStore($store); continue;
                    }
                    if (array_key_exists('operator_credential_id', $schedule)) {
                        $principal = \SLS\MassNotify\ApiSecurity::currentCredential($settings, (string)$schedule['operator_credential_id']);
                        if (!$principal || !\SLS\MassNotify\OperatorAccess::may($principal, 'schedule')
                            || !\SLS\MassNotify\ApiSecurity::permitsResolvedAnnouncement($principal, $resolved['request'], $settings)) {
                            $store['occurrences'][$id] = $this->scheduleExecutionRecord($schedule, $occurrence, 'failed',
                                _('The originating operator login, role or audience is no longer authorized. No channels were submitted.'), $current);
                            $this->writeScheduleExecutionStore($store); continue;
                        }
                        $resolved['request']['api_credential_id'] = $principal['id'];
                    }
                    $context = ['version' => 1, 'schedule_id' => (string)$schedule['id'], 'occurrence_id' => $id,
                        'run_at_utc' => (string)$occurrence['run_at_utc'], 'deadline_at' => $deadline];
                    $job = $this->buildScheduledAnnouncementJob($resolved['request'], $context);
                    $current['attempts'] = max(0, (int)($current['attempts'] ?? 0)) + 1;
                    $current['claimed_at'] = gmdate('c');
                    $current['job_id'] = $job['id']; $current['request_fingerprint'] = $job['request_fingerprint'];
                    $current['job_state'] = 'prepared';
                    $store['occurrences'][$id] = $this->scheduleExecutionRecord($schedule, $occurrence, 'preparing',
                        _('Saving the immutable scheduled announcement. No delivery has started.'), $current);
                    $this->writeScheduleExecutionStore($store);
                    $this->persistPreparedScheduledAnnouncementJob($job);
                    $record = $this->scheduledRecordFromExisting($store['occurrences'][$id], 'queued',
                        _('Announcement prepared; waiting for protected delivery admission.'));
                    $store['occurrences'][$id] = $record;
                    $this->writeScheduleExecutionStore($store);
                } finally { $this->releaseNativeBackupFileLock($activity); }
                $processed++;
                $this->activateScheduledAnnouncementJob($record);
                $store['occurrences'][$id] = $this->reconcileScheduledDeliveryRecord($record);
                $this->writeScheduleExecutionStore($store);
            }
            $attention = count(array_filter($store['occurrences'], static function ($record) {
                return in_array($record['state'] ?? '', ['failed', 'missed', 'uncertain'], true);
            }));
            $this->updateStatusData(['last_schedule_worker_at' => gmdate('c'),
                'last_schedule_worker_status' => $attention ? 'warning' : 'ok',
                'last_schedule_worker_message' => sprintf(_('Scheduler prepared %d announcement(s); %d occurrence(s) need attention.'), $processed, $attention)]);
            return ['success' => true, 'processed' => $processed, 'attention' => $attention];
        } catch (\Throwable $error) {
            $this->updateStatusData(['last_schedule_worker_at' => gmdate('c'), 'last_schedule_worker_status' => 'fault',
                'last_schedule_worker_message' => _('Scheduled delivery stopped because protected preparation or storage failed. No incomplete occurrence was recreated; review the scheduling journal and worker health.')]);
            throw $error;
        } finally { $this->releaseNativeBackupFileLock($worker); }
    }
}
