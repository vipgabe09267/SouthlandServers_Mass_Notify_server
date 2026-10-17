<?php
namespace FreePBX\modules;

/** Short recipient cooldown reservations; actual phone capacity remains in Asterisk admission. */
trait SlsAnnouncementAdmission
{
    private $announcementAdmissionLock = null;

    private function announcementAdmissionRecipients(array $request): array
    {
        $keys = [];
        foreach (['phones', 'desktops', 'webhooks', 'voice_recipient_ids', 'email_recipient_ids', 'sms_recipient_ids', 'browser_recipient_ids', 'connector_ids'] as $channel) {
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
        clearstatcache(true, $path); $present=@lstat($path);
        if (!$present) {
            if ($marker !== '') { throw new \RuntimeException('Established announcement admission state is missing. Preserve recovery evidence before repair.'); }
            return ['schema' => 1, 'global_stamp' => 0, 'reservations' => []];
        }
        $helper=__DIR__.'/bin/sls_mass_notify/sls_atomic_json.py';
        $process=proc_open(['/usr/bin/timeout','--kill-after=1','3','/usr/bin/python3','-I',$helper,'--read-announcement-admission',$path],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new \RuntimeException('Announcement admission storage reader could not start.');}
        $raw=stream_get_contents($pipes[1],2097153);fclose($pipes[1]);$exit=proc_close($process);
        if($exit!==0||!is_string($raw)||strlen($raw)>2097152){throw new \RuntimeException('Announcement admission storage has unsafe ownership, permissions, type, size, or content.');}
        try{$response=json_decode($raw,true,18,JSON_THROW_ON_ERROR);}catch(\Throwable $error){throw new \RuntimeException('Announcement admission storage reader returned invalid data.',0,$error);}
        if(!is_array($response)||count($response)!==2||($response['ok']??null)!==true||!is_array($response['state']??null)){
            throw new \RuntimeException('Announcement admission storage reader returned invalid data.');
        }
        $state=$response['state'];
        if (!is_array($state) || ($state['schema'] ?? null) !== 1 || !is_int($state['global_stamp'] ?? null)
            || count($state) !== 3 || $state['global_stamp'] < 0 || !is_array($state['reservations'] ?? null) || count($state['reservations']) > 1000) {
            throw new \RuntimeException('Announcement admission storage is corrupt; existing reservations were preserved.');
        }
        foreach ($state['reservations'] as $id => $row) {
            $legacy=is_array($row)&&count($row)===4;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || !is_array($row)
                || (!$legacy&&count($row)!==8)
                || !is_array($row['recipients'] ?? null) || !array_is_list($row['recipients']) || count($row['recipients']) > 2500
                || !is_int($row['until'] ?? null) || !is_int($row['retain_until'] ?? null) || !is_int($row['deadline_at'] ?? null)
                || $row['until'] < 0 || $row['deadline_at'] < 0 || $row['retain_until'] < $row['until']) {
                throw new \RuntimeException('Announcement recipient reservation is corrupt.');
            }
            if(!$legacy&&(!is_string($row['job_id']??null)||($row['job_id']!==''&&!\SlsAnnouncementJobStore::validId($row['job_id']))
                ||!is_string($row['request_fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['request_fingerprint'])
                ||!is_int($row['reserved_at']??null)||$row['reserved_at']<1||$row['reserved_at']>$row['until']
                ||!is_bool($row['committed']??null))){throw new \RuntimeException('Announcement recipient reservation commit evidence is corrupt.');}
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
    private function reserveAnnouncementRecipients(array $request, bool $scheduled, string $jobId='', bool $provisional=false): array
    {
        $now = time();
        $deadline = $scheduled ? ($request['schedule_context']['deadline_at'] ?? null) : 0;
        if ($scheduled && (!is_int($deadline) || $deadline <= $now)) {
            return ['success' => false, 'error_code' => 'schedule_deadline_expired', 'message' => 'The maximum start delay elapsed before admission. No channels were submitted.'];
        }
        $recipients = $this->announcementAdmissionRecipients($request);
        if($scheduled){$jobId=\SlsAnnouncementJobStore::scheduledId($request['schedule_context']??[]);}
        elseif($jobId!==''&&!\SlsAnnouncementJobStore::validId($jobId)){throw new \RuntimeException('Interactive admission requires a valid generated job identity.');}
        $owner = $scheduled ? hash('sha256', 'scheduled:' . ($request['delivery_id'] ?? ''))
            :($jobId!==''?hash('sha256','interactive:'.$jobId):bin2hex(random_bytes(32)));
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
            if (array_key_exists('request_fingerprint', $original)
                && !hash_equals($original['request_fingerprint'], \SlsAnnouncementJobStore::requestFingerprint($request))) {
                throw new \RuntimeException('Scheduled admission does not match the original delivery snapshot.');
            }
            return ['success' => true,'reservation_id'=>$owner,'committed'=>(bool)($original['committed']??true)]; // Reconciliation never renews this reservation.
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
            'retain_until' => max($until, $deadline), 'deadline_at' => $deadline,'job_id'=>$jobId,
            'request_fingerprint'=>\SlsAnnouncementJobStore::requestFingerprint($request),'reserved_at'=>$now,
            'committed'=>!$provisional];
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
        return ['success' => true,'reservation_id'=>$owner,'committed'=>!$provisional];
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

    private function restoreAnnouncementAdmissionState(array $state,int $cooldown): void
    {
        $store=new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR);$store->atomic('announcement-admission.json',$state);$store->admissionTimestamp($cooldown);
    }

    private function finalizeAnnouncementAdmission(string $reservationId,string $jobId): void
    {
        $state=$this->readAnnouncementAdmissionState();$row=$state['reservations'][$reservationId]??null;
        if(!is_array($row)||($row['job_id']??null)!==$jobId){throw new \RuntimeException('Announcement reservation changed before its durable job commit could be confirmed.');}
        if(($row['committed']??null)===true){return;}
        if(($row['committed']??null)!==false){throw new \RuntimeException('Announcement reservation commit evidence is invalid.');}
        $row['committed']=true;$state['reservations'][$reservationId]=$row;(new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR))->atomic('announcement-admission.json',$state);
    }

    private function recordInteractiveAnnouncementAdmission(array $request,?callable $commit=null,string $jobId='')
    {
        $result=$this->withAnnouncementAdmissionLock(function()use($request,$commit,$jobId){
            $before=$this->readAnnouncementAdmissionState();$store=new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR);$cooldown=$store->admissionCooldown();
            try{$reservation=$this->reserveAnnouncementRecipients($request,false,$jobId,$commit!==null);}
            catch(\Throwable $error){$this->restoreAnnouncementAdmissionState($before,$cooldown);throw $error;}
            if(empty($reservation['success'])){return $reservation;}
            if($commit===null){return $reservation;}
            try{$value=$commit();}
            catch(\Throwable $error){$this->restoreAnnouncementAdmissionState($before,$cooldown);throw $error;}
            $this->finalizeAnnouncementAdmission($reservation['reservation_id'],$jobId);
            return ['success'=>true,'value'=>$value];
        });
        if(empty($result['success'])){throw new \RuntimeException($result['message']??'Announcement admission failed before delivery.');}
        return $commit!==null?($result['value']??null):null;
    }

    private function admitScheduledAnnouncementRequest(array $request): array
    {
        return $this->withAnnouncementAdmissionLock(function () use ($request) { return $this->reserveAnnouncementRecipients($request, true); });
    }

    private function commitScheduledAnnouncementAdmission(array $request,callable $commit): array
    {
        return $this->withAnnouncementAdmissionLock(function()use($request,$commit):array{
            $before=$this->readAnnouncementAdmissionState();$store=new \SlsAnnouncementJobStore(self::PLUGIN_DATA_DIR);$cooldown=$store->admissionCooldown();
            try{$reservation=$this->reserveAnnouncementRecipients($request,true,'',true);}
            catch(\Throwable $error){$this->restoreAnnouncementAdmissionState($before,$cooldown);throw $error;}
            if(empty($reservation['success'])){return $reservation;}
            try{$value=$commit();}
            catch(\Throwable $error){$this->restoreAnnouncementAdmissionState($before,$cooldown);throw $error;}
            $jobId=\SlsAnnouncementJobStore::scheduledId($request['schedule_context']);
            $this->finalizeAnnouncementAdmission($reservation['reservation_id'],$jobId);
            return ['success'=>true,'value'=>$value];
        });
    }
}
