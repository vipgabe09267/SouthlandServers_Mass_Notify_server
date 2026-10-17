<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/IncidentConfig.php';
require_once __DIR__ . '/IncidentArchive.php';
require_once __DIR__ . '/api/sls-mass-notify/security.php';

/** Bounded operational history with durable claims before any announcement submission. */
class IncidentStore
{
    public const DIRECTORY = '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/incidents';
    public const MAX_RECORDS = 2000;
    public const MAX_BYTES = 4194304;
    private string $directory;
    private ?IncidentArchive $archive = null;
    private function restoredReplayBlocked(string $id): bool
    {
        $path = dirname($this->directory) . '/.incident-replay-blocked.json';
        $marker = dirname($this->directory) . '/.incident-replay-required';
        $intent = dirname($this->directory) . '/.incident-replay-initializing.json';
        clearstatcache(true, $intent);
        if (@lstat($intent)) { throw new \RuntimeException('Restored incident replay initialization is incomplete. Preserve its intent and resume the checked restore before accepting incident requests.'); }
        clearstatcache(true, $path); clearstatcache(true, $marker);
        $required = @lstat($marker); $present = @lstat($path);
        if (!$required && !$present) { return false; }
        if (!$required || !$present) { throw new \RuntimeException('Restored incident replay protection is incomplete. Restore its permanent marker and identity ledger before accepting new incident requests.'); }
        $valid = $this->readChecked($marker, 32) === "SLS_RESTORED_INCIDENT_REPLAY_V1\n";
        if (!$valid) { throw new \RuntimeException('Restored incident replay protection has a damaged marker.'); }
        try {
            $raw = $this->readChecked($path, self::MAX_BYTES);
            try { $guard = ApiSecurity::jsonObject($raw); }
            catch (\Throwable $error) { throw new \RuntimeException('Restored incident replay history is damaged. Preserve it for recovery.', 0, $error); }
            if (!is_array($guard) || count($guard) !== 2 || ($guard['schema'] ?? null) !== 1 || !is_array($guard['ids'] ?? null)
                || !array_is_list($guard['ids']) || count($guard['ids']) > 100000) { throw new \RuntimeException('Restored incident replay history is damaged. Preserve it for recovery.'); }
            $seen = [];
            foreach ($guard['ids'] as $entry) {
                if (!self::validId($entry) || isset($seen[$entry])) { throw new \RuntimeException('Restored incident replay history contains invalid or repeated identifiers.'); }
                $seen[$entry] = true;
            }
            return isset($seen[$id]);
        } catch (\RuntimeException $error) { throw $error; }
    }
    private function archive(bool $create = false): ?IncidentArchive
    {
        $this->directoryMeta();
        $path = $this->directory . '/archive'; $marker = $this->directory . '/.archive-required';
        clearstatcache(true, $path); clearstatcache(true, $marker);
        $required = @lstat($marker); $present = @lstat($path);
        if ($required) {
            if (!$this->regular($required) || $required['size'] !== 24 || !$present) {
                throw new \RuntimeException('The permanent incident archive is missing or unsafe. Restore it before accepting incident requests.');
            }
            $valid = $this->readChecked($marker, 25) === "SLS_INCIDENT_ARCHIVE_V1\n";
            if (!$valid) { throw new \RuntimeException('The permanent incident archive marker is damaged.'); }
        } elseif (!$create) {
            if ($present) { throw new \RuntimeException('Incident archival initialization is incomplete. Preserve the archive and retry the archival worker.'); }
            return null;
        }
        if (!$this->archive) {
            if (!$present) {
                $this->writer();
                if (!@mkdir($path, 0700)) { throw new \RuntimeException('Incident archive storage could not be created.'); }
                if (!$this->syncDirectory()) { throw new \RuntimeException('Incident archive storage could not be synchronized.'); }
            }
            $this->archive = new IncidentArchive($path);
        }
        if (!$required) {
            $this->writer();
            if ($this->archive->listing(1, null)['total'] !== 0) { throw new \RuntimeException('The permanent incident archive marker is missing from populated history. Preserve the archive for recovery.'); }
            $handle = $this->checked($marker, 'x+b');
            try {
                $bytes = "SLS_INCIDENT_ARCHIVE_V1\n";
                    $offset = 0;
                    while ($offset < strlen($bytes)) {
                        $written = $this->writeBytes($handle, substr($bytes, $offset));
                        if (!is_int($written) || $written < 1) { throw new \RuntimeException('The permanent incident archive marker could not be written.'); }
                        $offset += $written;
                    }
                    if (!$this->syncFile($handle) || !$this->syncDirectory()) {
                    throw new \RuntimeException('The permanent incident archive marker could not be synchronized.');
                }
            } finally { fclose($handle); }
        }
        return $this->archive;
    }
    public function __construct(string $directory = self::DIRECTORY)
    {
        $this->directory = rtrim($directory, '/');
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            if ($this->directory === self::DIRECTORY && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                throw new \RuntimeException('Initialize incident storage as the PBX runtime account.');
            }
            if (!@mkdir($this->directory, 0750) && !is_dir($this->directory)) { throw new \RuntimeException('Incident storage could not be created.'); }
        }
        $this->directoryMeta();
    }
    public static function validId($id): bool { return is_string($id) && (bool)preg_match('/^inc_[a-f0-9]{32}$/D', $id); }
    public static function pendingApprovalCount(array $record): int
    {
        $count = 0;
        foreach (($record['operations'] ?? []) as $operation) {
            if (!is_array($operation)) { continue; }
            if (is_array($operation['language_jobs'] ?? null)) {
                foreach ($operation['language_jobs'] as $batch) { if (is_array($batch) && ($batch['state'] ?? '') === 'awaiting_approval') { $count++; } }
            } elseif (($operation['state'] ?? '') === 'awaiting_approval') { $count++; }
        }
        return $count;
    }
    private static function validTimestamp($value, bool $empty = false): bool
    {
        if ($empty && $value === '') { return true; }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('c') === $value;
    }
    private static function validText($value, int $limit, bool $empty = false): bool
    {
        if (!is_string($value)) { return false; }
        try { return IncidentConfig::text($value, $limit, 'Stored incident value', $empty) === $value; }
        catch (\Throwable $error) { return false; }
    }
    private static function exactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value); sort($keys); sort($expected); return $keys === $expected;
    }
    /** Legacy snapshots may omit later defaults, but every stored value must already be canonical. */
    private static function normalizedSubset($stored, $normalized): bool
    {
        if (!is_array($stored)) { return $stored === $normalized; }
        if (!is_array($normalized) || array_is_list($stored) !== array_is_list($normalized)) { return false; }
        if (array_is_list($stored) && count($stored) !== count($normalized)) { return false; }
        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, $normalized) || !self::normalizedSubset($value, $normalized[$key])) { return false; }
        }
        return true;
    }
    private static function validActor($actor): bool
    {
        if (!is_array($actor) || ($actor !== [] && array_is_list($actor))
            || array_diff(array_keys($actor), ['identity','source','credential_id','automation_context'])
            || !self::validText($actor['identity'] ?? null, 100) || !self::validText($actor['source'] ?? null, 40)) { return false; }
        if (array_key_exists('credential_id', $actor) && (!is_string($actor['credential_id']) || !preg_match('/^api_[a-f0-9]{24}$/D', $actor['credential_id']))) { return false; }
        if (array_key_exists('automation_context', $actor)) {
            $context = $actor['automation_context'];
            if (!is_array($context) || array_is_list($context) || count($context) !== 4
                || !is_string($context['rule_id'] ?? null) || !preg_match('/^trg_[a-f0-9]{24}$/D', $context['rule_id'])
                || !is_string($context['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $context['revision'])
                || !is_string($context['event_id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $context['event_id'])
                || !is_int($context['expires_at'] ?? null)) { return false; }
        }
        return true;
    }
    /** Validate private frozen fields, returning the portable delivery fields. */
    private static function portableDelivery($delivery): array
    {
        if (!is_array($delivery) || ($delivery !== [] && array_is_list($delivery))) { throw new \RuntimeException('Incident delivery snapshot is invalid.'); }
        $portableKeys = ['extensions','desktop_clients','group_ids','voice_recipient_ids','webhook_ids','email_recipient_ids','sms_recipient_ids','browser_recipient_ids','connector_ids','audio_mode','opening_tone','closing_tone','style','background_color'];
        if (array_diff(array_keys($delivery), array_merge($portableKeys, ['_identities','_speech']))) { throw new \RuntimeException('Incident delivery snapshot contains unsupported fields.'); }
        $portable = array_intersect_key($delivery, array_flip($portableKeys));
        $normalized = IncidentConfig::delivery($portable);
        foreach ($portable as $key => $value) { if (($normalized[$key] ?? null) !== $value) { throw new \RuntimeException('Incident delivery snapshot is not normalized.'); } }
        if (array_key_exists('_identities', $delivery)) {
            $maps = $delivery['_identities'];
            $channels = ['desktop_clients','voice_recipient_ids','webhook_ids','email_recipient_ids','sms_recipient_ids','browser_recipient_ids','connector_ids'];
            if (!is_array($maps) || ($maps !== [] && array_is_list($maps)) || array_diff(array_keys($maps), $channels)) { throw new \RuntimeException('Incident identity snapshot is invalid.'); }
            foreach ($maps as $channel => $map) {
                if (!is_array($map) || ($map !== [] && array_is_list($map)) || count($map) > 1000) { throw new \RuntimeException('Incident identity snapshot is invalid.'); }
                foreach ($map as $id => $digest) {
                    if (!is_string($id) || !in_array($id, $normalized[$channel], true) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                        throw new \RuntimeException('Incident identity snapshot is invalid.');
                    }
                }
            }
        }
        if (array_key_exists('_speech', $delivery)) {
            $speech = $delivery['_speech']; $keys = is_array($speech) ? array_keys($speech) : [];
            sort($keys);
            if (!is_array($speech) || $keys !== ['external_path','locale','path']
                || !is_string($speech['locale']) || !preg_match('/^(?:[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3})?$/D', $speech['locale'])
                || !is_string($speech['path']) || $speech['path'] === '' || strlen($speech['path']) > 4096
                || !is_string($speech['external_path']) || $speech['external_path'] === '' || strlen($speech['external_path']) > 4096) {
                throw new \RuntimeException('Incident speech snapshot is invalid.');
            }
        }
        return $portable;
    }
    private static function validateLanguageBatches($batches, bool $mutable): void
    {
        if (!is_array($batches) || !array_is_list($batches) || count($batches) > 7) { throw new \RuntimeException('Incident language batches are invalid.'); }
        $seen = [];
        foreach ($batches as $batch) {
            $allowed = ['locale','title','message','delivery'];
            if ($mutable) { $allowed = array_merge($allowed, ['state','job_id','error','review_id']); }
            if (!is_array($batch) || array_is_list($batch) || array_diff(array_keys($batch), $allowed)
                || !is_string($batch['locale'] ?? null) || !preg_match('/^(?:[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3})?$/D', $batch['locale'])
                || (!$mutable && isset($seen[$batch['locale']])) || !self::validText($batch['title'] ?? null, 80)
                || !self::validText($batch['message'] ?? null, 500)) { throw new \RuntimeException('Incident language batch is invalid.'); }
            $seen[$batch['locale']] = true; self::portableDelivery($batch['delivery'] ?? null);
            if ($mutable) {
                if (!in_array($batch['state'] ?? null, ['submitting','queued','awaiting_approval','not_submitted','uncertain'], true)
                    || !is_string($batch['job_id'] ?? null) || ($batch['job_id'] !== '' && !preg_match('/^job_[a-f0-9]{32}$/D', $batch['job_id']))
                    || !self::validText($batch['error'] ?? null, 1000, true)
                    || (array_key_exists('review_id', $batch) && (!is_string($batch['review_id']) || !preg_match('/^review_[a-f0-9]{32}$/D', $batch['review_id'])))) {
                    throw new \RuntimeException('Incident language job state is invalid.');
                }
            }
        }
    }
    private static function validateTemplate($template): void
    {
        if (!is_array($template) || array_is_list($template)) { throw new \RuntimeException('Incident template snapshot is invalid.'); }
        $portable = $template;
        if (array_key_exists('_language_delivery', $portable)) {
            self::validateLanguageBatches($portable['_language_delivery'], false); unset($portable['_language_delivery']);
        }
        $portable['delivery'] = self::portableDelivery($portable['delivery'] ?? null);
        if (is_array($portable['escalation'] ?? null)) {
            if (array_key_exists('delivery', $portable['escalation'])) { $portable['escalation']['delivery'] = self::portableDelivery($portable['escalation']['delivery']); }
            if (is_array($portable['escalation']['steps'] ?? null)) {
                foreach ($portable['escalation']['steps'] as &$step) {
                    if (is_array($step) && array_key_exists('_language_delivery', $step)) {
                        self::validateLanguageBatches($step['_language_delivery'], false); unset($step['_language_delivery']);
                    }
                    if (is_array($step) && array_key_exists('delivery', $step)) { $step['delivery'] = self::portableDelivery($step['delivery']); }
                }
                unset($step);
            }
        }
        if (!self::normalizedSubset($portable, IncidentConfig::template($portable))) {
            throw new \RuntimeException('Incident template snapshot is not normalized.');
        }
    }
    private static function validateRecord(array $record, string $id): void
    {
        try {
            $rootFields = ['schema','id','start_fingerprint','template','title','message','severity','is_test','language_variant',
                'state','created_at','updated_at','opened_at','closed_at','planned_at','created_by','operations','responses',
                'actions','checklist_results','timeline'];
            $archived = array_key_exists('archived', $record) || array_key_exists('archived_at', $record);
            if ($archived) { $rootFields = array_merge($rootFields, ['archived','archived_at']); }
            if (!self::exactKeys($record, $rootFields)
                || ($archived && (($record['archived'] ?? null) !== true
                    || !self::validTimestamp($record['archived_at'] ?? null)
                    || !in_array($record['state'] ?? null, ['closed','missed'], true)))
                || ($record['schema'] ?? null) !== 1 || ($record['id'] ?? null) !== $id
                || !is_string($record['start_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $record['start_fingerprint'])
                || !self::validText($record['title'] ?? null, 80) || !self::validText($record['message'] ?? null, 500)
                || !in_array($record['severity'] ?? null, ['information','warning','critical'], true)
                || !is_bool($record['is_test'] ?? null) || !is_string($record['language_variant'] ?? null)
                || !preg_match('/^(?:[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3})?$/D', $record['language_variant'])
                || !in_array($record['state'] ?? null, ['planned','open','closed','missed'], true)
                || !self::validTimestamp($record['created_at'] ?? null) || !self::validTimestamp($record['updated_at'] ?? null)
                || !self::validTimestamp($record['opened_at'] ?? null, true) || !self::validTimestamp($record['closed_at'] ?? null, true)
                || !self::validTimestamp($record['planned_at'] ?? null, true) || !self::validActor($record['created_by'] ?? null)) {
                throw new \RuntimeException('Incident record has invalid core fields.');
            }
            self::validateTemplate($record['template'] ?? null);
            foreach (['operations','responses','actions'] as $field) {
                if (!is_array($record[$field] ?? null) || ($record[$field] !== [] && array_is_list($record[$field]))) { throw new \RuntimeException('Incident record has an invalid keyed collection.'); }
            }
            if (!is_array($record['checklist_results'] ?? null)) { throw new \RuntimeException('Incident checklist history is invalid.'); }
            if (!is_array($record['timeline'] ?? null) || !array_is_list($record['timeline']) || count($record['timeline']) > 4000
                || count($record['operations']) > 250 || count($record['responses']) > 1000 || count($record['actions']) > 3500 || count($record['checklist_results']) > 25) {
                throw new \RuntimeException('Incident record exceeds its bounded collections.');
            }
            $sequences = [];
            foreach ($record['operations'] as $request => $operation) {
                $allowed = ['request_id','fingerprint','sequence','kind','message','state','claimed_at','actor','job_id','error','finished_at','review_id','escalation_step','escalation_name','language_messages','language_jobs'];
                if (!is_string($request) || !preg_match('/^[a-f0-9]{32}$/D', $request) || !is_array($operation) || array_is_list($operation)
                    || array_diff(array_keys($operation), $allowed) || ($operation['request_id'] ?? null) !== $request
                    || !is_string($operation['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $operation['fingerprint'])
                    || !is_int($operation['sequence'] ?? null) || $operation['sequence'] < 1 || $operation['sequence'] > 250 || isset($sequences[$operation['sequence']])
                    || !in_array($operation['kind'] ?? null, ['initial','update','all_clear','escalation'], true)
                    || !self::validText($operation['message'] ?? null, 500)
                    || !in_array($operation['state'] ?? null, ['submitting','queued','awaiting_approval','not_submitted','uncertain'], true)
                    || !self::validTimestamp($operation['claimed_at'] ?? null) || !self::validActor($operation['actor'] ?? null)
                    || !is_string($operation['job_id'] ?? null) || ($operation['job_id'] !== '' && !preg_match('/^job_[a-f0-9]{32}$/D', $operation['job_id']))
                    || !self::validText($operation['error'] ?? null, 1000, true)) { throw new \RuntimeException('Incident operation history is invalid.'); }
                $sequences[$operation['sequence']] = true;
                if (array_key_exists('finished_at', $operation) && !self::validTimestamp($operation['finished_at'])) { throw new \RuntimeException('Incident operation completion is invalid.'); }
                if (array_key_exists('review_id', $operation) && (!is_string($operation['review_id']) || !preg_match('/^review_[a-f0-9]{32}$/D', $operation['review_id']))) { throw new \RuntimeException('Incident approval identity is invalid.'); }
                if (array_key_exists('escalation_step', $operation) && (!is_int($operation['escalation_step']) || $operation['escalation_step'] < 1 || $operation['escalation_step'] > IncidentConfig::MAX_ESCALATION_STEPS)) { throw new \RuntimeException('Incident escalation step is invalid.'); }
                if (array_key_exists('escalation_name', $operation) && !self::validText($operation['escalation_name'], 80)) { throw new \RuntimeException('Incident escalation name is invalid.'); }
                if (array_key_exists('language_messages', $operation)) {
                    if (!is_array($operation['language_messages']) || ($operation['language_messages'] !== [] && array_is_list($operation['language_messages'])) || count($operation['language_messages']) > 6) { throw new \RuntimeException('Incident update languages are invalid.'); }
                    foreach ($operation['language_messages'] as $locale => $message) {
                        if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}$/D', $locale) || !is_array($message) || !self::exactKeys($message, ['message','review_note'])
                            || !self::validText($message['message'] ?? null, 500) || !self::validText($message['review_note'] ?? null, 100)) { throw new \RuntimeException('Incident update language is invalid.'); }
                    }
                }
                if (array_key_exists('language_jobs', $operation)) { self::validateLanguageBatches($operation['language_jobs'], true); }
            }
            foreach ($record['timeline'] as $event) {
                if (!is_array($event) || !self::exactKeys($event, ['at','kind','actor','details']) || !self::validTimestamp($event['at'] ?? null)
                    || !is_string($event['kind'] ?? null) || !preg_match('/^[a-z0-9_]{1,80}$/D', $event['kind'])
                    || !self::validActor($event['actor'] ?? null) || !is_array($event['details'] ?? null)) { throw new \RuntimeException('Incident timeline is invalid.'); }
            }
            $people = array_column($record['template']['roster'], null, 'id');
            foreach ($record['responses'] as $person => $response) {
                if (!is_string($person) || !isset($people[$person]) || !is_array($response) || !self::exactKeys($response, ['response','note','at','actor','source'])
                    || !in_array($response['response'] ?? null, ['received','safe','needs_assistance','missing'], true)
                    || !self::validText($response['note'] ?? null, 500, true) || !self::validTimestamp($response['at'] ?? null)
                    || !self::validActor($response['actor'] ?? null) || !in_array($response['source'] ?? null, ['operator_roll_call','human_desktop_response','human_browser_response'], true)) {
                    throw new \RuntimeException('Incident response history is invalid.');
                }
            }
            foreach ($record['actions'] as $request => $digest) {
                if (!is_string($request) || !preg_match('/^[a-f0-9]{32}$/D', $request) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) { throw new \RuntimeException('Incident action history is invalid.'); }
            }
            foreach ($record['checklist_results'] as $item => $result) {
                $itemText = is_int($item) ? (string)$item : $item;
                if (!is_string($itemText) || !preg_match('/^(?:[0-9]|1[0-9]|2[0-4])$/D', $itemText) || !isset($record['template']['checklist'][(int)$itemText])
                    || !is_array($result) || !self::exactKeys($result, ['complete','note','at','actor']) || !is_bool($result['complete'] ?? null)
                    || !self::validText($result['note'] ?? null, 500, true) || !self::validTimestamp($result['at'] ?? null) || !self::validActor($result['actor'] ?? null)) {
                    throw new \RuntimeException('Incident checklist history is invalid.');
                }
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getMessage() === 'Incident record has an unsupported or invalid structure.') { throw $error; }
            throw new \RuntimeException('Incident record has an unsupported or invalid structure. Preserve it for recovery.', 0, $error);
        }
    }
    private function directoryMeta(): array
    {
        clearstatcache(true, $this->directory); $meta = @lstat($this->directory);
        if (!$meta || ($meta['mode'] & 0170000) !== 0040000 || ($meta['mode'] & 0022) || realpath($this->directory) !== $this->directory) {
            throw new \RuntimeException('Incident storage must be a protected local directory without symbolic links.');
        }
        return $meta;
    }
    private function writer(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== $this->directoryMeta()['uid']) {
            throw new \RuntimeException('Incident records must be written by their PBX runtime account.');
        }
    }
    private function path(string $id): string
    {
        if (!self::validId($id)) { throw new \InvalidArgumentException('Invalid incident identifier.'); }
        return $this->directory . '/' . $id . '.json';
    }
    private function regular(array $meta): bool
    {
        return ($meta['mode'] & 0170000) === 0100000 && $meta['nlink'] === 1 && !($meta['mode'] & 0027)
            && in_array($meta['uid'], [0, $this->directoryMeta()['uid']], true);
    }
    private static function sameIdentity($left, $right): bool
    {
        return is_array($left) && is_array($right) && isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }
    private function unlinkSame(string $path, $identity): bool
    {
        if (!is_array($identity)) { return false; }
        clearstatcache(true, $path); $current = @lstat($path);
        return self::sameIdentity($identity, $current) && $this->regular($current) && @unlink($path);
    }
    private function checked(string $path, string $mode)
    {
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before && !$this->regular($before)) { throw new \RuntimeException('Incident state contains an unsafe file or link.'); }
        $old = umask(0077);
        try { $handle = @fopen($path, $mode); } finally { umask($old); }
        if (!$handle) { throw new \RuntimeException('Incident state could not be opened.'); }
        $opened = fstat($handle); clearstatcache(true, $path); $after = @lstat($path);
        if (!$opened || !$after || !$this->regular($opened) || !$this->regular($after)
            || $opened['ino'] !== $after['ino'] || $opened['dev'] !== $after['dev']
            || ($before && ($opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']))) {
            fclose($handle); throw new \RuntimeException('Incident storage identity changed during access.');
        }
        return $handle;
    }
    private function readChecked(string $path, int $maximum): string
    {
        if ($maximum < 1 || $maximum > self::MAX_BYTES) { throw new \InvalidArgumentException('Incident read limit is invalid.'); }
        $handle = $this->checked($path, 'rb');
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['size'] < 0 || $opened['size'] > $maximum) {
                throw new \RuntimeException('Incident state exceeds its protected allocation.');
            }
            $raw = stream_get_contents($handle, $maximum + 1); $after = fstat($handle);
            clearstatcache(true, $path); $named = @lstat($path);
            foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field) {
                if (!is_array($after) || !is_array($named) || $opened[$field] !== $after[$field]
                    || $after[$field] !== $named[$field]) {
                    throw new \RuntimeException('Incident state changed while it was read.');
                }
            }
            if (!is_string($raw) || strlen($raw) !== $opened['size']) {
                throw new \RuntimeException('Incident state could not be read completely.');
            }
            return $raw;
        } finally { fclose($handle); }
    }
    private function acquire(string $name)
    {
        $this->writer(); $path = $this->directory . '/' . $name . '.lock';
        $handle = $this->checked($path, 'c+b'); $deadline = microtime(true) + .25;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $path); $at = @lstat($path); $opened = fstat($handle);
                if (!$at || !$opened || $at['size'] !== 0 || $opened['size'] !== 0 || !$this->regular($at) || $at['ino'] !== $opened['ino'] || $at['dev'] !== $opened['dev']) {
                    fclose($handle); throw new \RuntimeException('Incident lock identity changed.');
                }
                return $handle;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($handle); throw new \RuntimeException('Incident record is busy. Retry the same request identifier.');
    }
    private static function release($handle): void { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    public function read(string $id): ?array
    {
        $path = $this->path($id); $this->directoryMeta();
        // Verify permanent replay history even while the requested record is
        // active. Losing that ledger must not permit a reused start identifier.
        $archive = $this->archive();
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $record = $archive ? $archive->read($id) : null;
            if ($record) { self::validateRecord($record, $id); return $record; }
            if ($this->restoredReplayBlocked($id)) {
                throw new \InvalidArgumentException('This incident identifier belongs to restored history and cannot execute again. Review its report in the recovery archive; use a new request for a new incident.');
            }
            return null;
        }
        // The inode-bound descriptor checks precede all reads. Read-only access
        // also permits a runtime process to inspect protected root-owned records.
        try {
            $raw = $this->readChecked($path, self::MAX_BYTES);
            try { $record = ApiSecurity::jsonObject($raw); }
            catch (\Throwable $error) { throw new \RuntimeException('Incident record is damaged; preserve it for recovery.', 0, $error); }
            unset($record['_storage_revision']);
            self::validateRecord($record, $id);
            return $record;
        } catch (\RuntimeException $error) { throw $error; }
    }
    private static function version(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
    private function metadataVersion(string $path, array $meta): array
    {
        $handle = $this->checked($path, 'rb');
        try {
            $opened = fstat($handle); $header = fread($handle, 96); $after = fstat($handle);
            clearstatcache(true, $path); $named = @lstat($path);
            if (!is_array($opened) || !is_string($header) || self::version($opened) !== self::version($meta)) {
                throw new \RuntimeException('Incident changed during summary lookup.');
            }
            foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $field) {
                if (!is_array($after) || !is_array($named) || $opened[$field] !== $after[$field] || $after[$field] !== $named[$field]) {
                    throw new \RuntimeException('Incident changed during summary lookup.');
                }
            }
        } finally { fclose($handle); }
        $revision = is_string($header) && preg_match('/^\{"_storage_revision":"([a-f0-9]{32})",/', $header, $matches) ? $matches[1] : null;
        return self::version($meta) + ['revision' => $revision];
    }
    private static function projection(array $record): array
    {
        $counts = ['expected' => count($record['template']['roster']), 'no_response' => 0, 'received' => 0, 'safe' => 0, 'needs_assistance' => 0, 'missing' => 0];
        foreach ($record['template']['roster'] as $person) {
            $response = is_array($person) && is_string($person['id'] ?? null)
                ? ($record['responses'][$person['id']]['response'] ?? 'no_response') : null;
            if (!is_string($response) || !array_key_exists($response, $counts) || $response === 'expected') {
                throw new \RuntimeException('Incident response history is invalid. Preserve the record for recovery.');
            }
            $counts[$response]++;
        }
        $row = array_intersect_key($record, array_flip(['id', 'title', 'severity', 'state', 'is_test', 'created_at', 'updated_at', 'planned_at'])) + ['response_counts' => $counts];
        $due = [];
        foreach ($record['operations'] as $operation) {
            if ($operation['state'] === 'submitting') { $due[] = (strtotime($operation['claimed_at']) ?: 0) + 121; }
        }
        if ($record['state'] === 'planned') { $due[] = strtotime($record['planned_at']) ?: 0; }
        $step = IncidentConfig::nextEscalation($record);
        if ($step !== null) { $due[] = $step['due_at']; }
        return ['row' => $row, 'due_at' => $due ? min($due) : null];
    }
    /** Advisory bounded cache. Full records remain the only mutation/auth source. */
    private function cacheMetadata(array $record, array $version): void
    {
        $this->writer(); $path = $this->directory . '/.' . $record['id'] . '.summary';
        $raw = json_encode(['schema' => 1, 'version' => $version] + self::projection($record), JSON_THROW_ON_ERROR);
        if (strlen($raw) > 8192) { throw new \RuntimeException('Incident summary exceeds its bounded cache size.'); }
        clearstatcache(true, $path); $existing = @lstat($path);
        if ($existing && !$this->regular($existing)) { throw new \RuntimeException('Incident summary cache contains an unsafe file.'); }
        $temp = $this->directory . '/.incident-' . bin2hex(random_bytes(16)); $handle = $this->checked($temp, 'x+b');
        $tempIdentity = fstat($handle);
        try {
            $offset = 0;
            while ($offset < strlen($raw)) {
                $written = fwrite($handle, substr($raw, $offset));
                if (!is_int($written) || $written < 1) { throw new \RuntimeException('Incident summary cache write failed.'); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new \RuntimeException('Incident summary cache could not be replaced.'); }
            $opened = fstat($handle); clearstatcache(true, $temp); $staged = @lstat($temp);
            clearstatcache(true, $path); $current = @lstat($path);
            if (!self::sameIdentity($tempIdentity, $opened) || !self::sameIdentity($opened, $staged)
                || !$this->regular($opened) || $opened['size'] !== strlen($raw)
                || (($existing === false) !== ($current === false))
                || ($existing !== false && !self::sameIdentity($existing, $current)) || !rename($temp, $path)) {
                throw new \RuntimeException('Incident summary cache could not be replaced.');
            }
            clearstatcache(true, $path); $committed = @lstat($path);
            if (!self::sameIdentity($tempIdentity, $committed) || !$this->regular($committed) || $committed['size'] !== strlen($raw)) {
                throw new \RuntimeException('Incident summary cache changed during commit.');
            }
        } finally { fclose($handle); $this->unlinkSame($temp, $tempIdentity); }
        // No durability dependency: missing/stale caches are rebuilt from the
        // inode-bound record. Never turn an acknowledged durable claim into a
        // retryable send just because an optional cache could not be persisted.
    }
    public function metadata(string $id): array
    {
        $path = $this->path($id); $cache = $this->directory . '/.' . $id . '.summary';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            clearstatcache(true, $path); $before = @lstat($path);
            if (!$before || !$this->regular($before)) { throw new \RuntimeException('Incident record is unavailable or unsafe.'); }
            $version = $this->metadataVersion($path, $before);
            clearstatcache(true, $cache);
            if (file_exists($cache) || is_link($cache)) {
                clearstatcache(true,$cache);$cacheMeta=@lstat($cache);
                if (!is_array($cacheMeta) || !$this->regular($cacheMeta)) {
                    throw new \RuntimeException('Incident summary cache contains an unsafe file or link.');
                }
                $cached=null;
                if ($cacheMeta['size'] <= 8192) {
                    $raw = $this->readChecked($cache,8192);
                    try { $cached = is_string($raw) ? ApiSecurity::jsonObject($raw) : null; }
                    catch (\Throwable $error) { $cached = null; }
                }
                if ($version['revision'] !== null && is_array($cached) && self::exactKeys($cached, ['schema','version','row','due_at'])
                    && ($cached['schema'] ?? null) === 1 && ($cached['version'] ?? null) === $version
                    && is_array($cached['row'] ?? null) && ($cached['row']['id'] ?? '') === $id
                    && count($cached['row']) === 9 && is_array($cached['row']['response_counts'] ?? null)
                    && self::validCachedProjection($cached['row'])
                    && array_key_exists('due_at', $cached) && ($cached['due_at'] === null || is_int($cached['due_at']))) {
                    return ['row' => $cached['row'], 'due_at' => $cached['due_at']];
                }
            }
            $record = $this->read($id);
            clearstatcache(true, $path); $after = @lstat($path);
            if (!$record || !$after || $this->metadataVersion($path, $after) !== $version) { continue; }
            if ($version['revision'] === null && function_exists('posix_geteuid') && posix_geteuid() === $this->directoryMeta()['uid']) {
                // Upgrade only the storage envelope under the same incident lock.
                // Logical history/idempotency keys stay byte-for-byte equivalent
                // after decoding; no workflow or notification is executed.
                $lock = $this->acquire($id);
                try {
                    $current = $this->read($id);
                    if (!$current) { throw new \RuntimeException('Incident disappeared during summary migration.'); }
                    $this->write($current);
                } finally { self::release($lock); }
                return $this->metadata($id);
            }
            $metadata = self::projection($record);
            try { $this->cacheMetadata($record, $version); } catch (\Throwable $error) { /* Advisory cache only; durable record remains authoritative. */ }
            return $metadata;
        }
        throw new \RuntimeException('Incident changed while its summary was being read. Retry the history request.');
    }
    private static function validCachedProjection(array $row): bool
    {
        $expected=['id','title','severity','state','is_test','created_at','updated_at','planned_at','response_counts'];
        if (array_diff(array_keys($row),$expected)||array_diff($expected,array_keys($row))
            ||!self::validId($row['id']??null)||!self::validText($row['title']??null,80)
            ||!in_array($row['severity']??null,['information','warning','critical'],true)
            ||!in_array($row['state']??null,['planned','open','closed','missed'],true)||!is_bool($row['is_test']??null)
            ||!self::validTimestamp($row['created_at']??null)||!self::validTimestamp($row['updated_at']??null)
            ||!self::validTimestamp($row['planned_at']??null,true)) { return false; }
        $counts=$row['response_counts']??null;$keys=['expected','no_response','received','safe','needs_assistance','missing'];
        if(!is_array($counts)||array_diff(array_keys($counts),$keys)||array_diff($keys,array_keys($counts))){return false;}
        foreach($counts as $value){if(!is_int($value)||$value<0||$value>1000){return false;}}
        return array_sum(array_intersect_key($counts,array_flip(array_slice($keys,1))))===$counts['expected'];
    }
    protected function writeBytes($handle, string $bytes) { return fwrite($handle, $bytes); }
    protected function syncFile($handle): bool { return fflush($handle) && fsync($handle); }
    protected function replace(string $source, string $destination): bool { return rename($source, $destination); }
    protected function syncDirectory(): bool
    {
        $handle = @fopen($this->directory, 'r'); if (!$handle) { return false; }
        try { return fsync($handle); } finally { fclose($handle); }
    }
    private function write(array $record): void
    {
        $this->writer(); $path = $this->path($record['id'] ?? '');
        self::validateRecord($record, $record['id']);
        $revision = bin2hex(random_bytes(16));
        $raw = json_encode(['_storage_revision' => $revision] + $record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($raw) > self::MAX_BYTES || count($record['timeline'] ?? []) > 4000 || count($record['operations'] ?? []) > 250) {
            throw new \RuntimeException('Incident history is full. Export the report and begin a new incident; existing history was preserved.');
        }
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before && !$this->regular($before)) { throw new \RuntimeException('Incident destination is unsafe.'); }
        $temp = $this->directory . '/.incident-' . bin2hex(random_bytes(16)); $handle = $this->checked($temp, 'x+b');
        $tempIdentity = fstat($handle);
        try {
            $offset = 0;
            while ($offset < strlen($raw)) {
                $count = $this->writeBytes($handle, substr($raw, $offset, 1048576));
                if (!is_int($count) || $count <= 0) { throw new \RuntimeException('Incident storage could not complete its write.'); }
                $offset += $count;
            }
            if (!$this->syncFile($handle)) { throw new \RuntimeException('Incident storage could not synchronize its write.'); }
            $opened = fstat($handle); clearstatcache(true, $temp); $staged = @lstat($temp);
            if (!self::sameIdentity($tempIdentity, $opened) || !self::sameIdentity($opened, $staged)
                || !$this->regular($opened) || $opened['size'] !== strlen($raw)) {
                throw new \RuntimeException('Incident staging changed before commit.');
            }
            clearstatcache(true, $path); $at = @lstat($path);
            if (($before === false) !== ($at === false) || ($at && (!$this->regular($at) || $at['ino'] !== $before['ino'] || $at['dev'] !== $before['dev']))) {
                throw new \RuntimeException('Incident destination changed before commit.');
            }
            if (!$this->replace($temp, $path)) { throw new \RuntimeException('Incident storage could not replace its record.'); }
            clearstatcache(true, $path); $committed = @lstat($path);
            if (!self::sameIdentity($tempIdentity, $committed) || !$this->regular($committed) || $committed['size'] !== strlen($raw)) {
                throw new \RuntimeException('Incident storage committed an unexpected file identity. Preserve the record for review.');
            }
            if (!$this->syncDirectory()) { throw new \RuntimeException('Incident write was replaced but directory synchronization failed. Retry the same request identifier; do not create another send.'); }
            if ($committed) { try { $this->cacheMetadata($record, self::version($committed) + ['revision' => $revision]); } catch (\Throwable $error) { /* Derived cache cannot change durable operation outcome. */ } }
        } finally { fclose($handle); $this->unlinkSame($temp, $tempIdentity); }
    }
    public function transaction(string $id, callable $operation, bool $create = false): array
    {
        $this->path($id);
        if (!$create && $this->read($id) === null) { throw new \InvalidArgumentException('Incident was not found.'); }
        $global = $create ? $this->acquire('admission') : null; $lock = null;
        try {
            $existing = $this->read($id);
            if (!empty($existing['archived'])) {
                $next = $operation($existing);
                if ($next !== $existing) { throw new \InvalidArgumentException('This incident is archived and read-only. Its report and previous request identities remain available.'); }
                return $existing;
            }
            if ($create && $this->read($id) === null && count($this->ids()) >= self::MAX_RECORDS) {
                throw new \RuntimeException('Incident history reached 2000 records. Preserve/export history and arrange retention before opening another incident.');
            }
            $lock = $this->acquire($id); $record = $this->read($id);
            if (!$create && $record === null) { throw new \InvalidArgumentException('Incident was not found.'); }
            $next = $operation($record);
            if (!is_array($next) || ($next['id'] ?? '') !== $id) { throw new \LogicException('Incident transaction returned an invalid record.'); }
            if ($next !== $record) {
                if (!empty($record['archived'])) { throw new \InvalidArgumentException('This incident is archived and read-only. Its report and previous request identities remain available.'); }
                $this->write($next);
            }
            return $next;
        } finally { self::release($lock); self::release($global); }
    }
    public function ids(): array
    {
        $this->directoryMeta(); $ids = []; $scanned = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) { continue; }
            if (++$scanned > 6500) { throw new \RuntimeException('Incident storage scan exceeded its safe limit; results are incomplete.'); }
            if (preg_match('/^(inc_[a-f0-9]{32})\.json$/D', $file->getFilename(), $matches)) { $ids[] = $matches[1]; }
        }
        if (count($ids) > self::MAX_RECORDS) { throw new \RuntimeException('Incident history exceeds its supported record limit.'); }
        return $ids;
    }
    public function archivedListing(int $limit, ?array $after): array
    {
        $archive = $this->archive();
        return $archive ? $archive->listing($limit, $after) : ['rows' => [], 'total' => 0];
    }
    protected function archiveCommitted(string $id): void { /* Interruption qualification hook; no production action. */ }
    protected function activeCopyRetired(string $id): void { /* Interruption qualification hook; no production action. */ }
    private function retiredOrphans(IncidentArchive $archive, float $deadline): int
    {
        $removed = 0; $scanned = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) { continue; }
            if (++$scanned > 25000 || $removed >= 10 || microtime(true) >= $deadline) { break; }
            if (!preg_match('/^\.?(inc_[a-f0-9]{32})\.(lock|summary)$/D', $file->getFilename(), $match)) { continue; }
            $expected = $match[2] === 'summary' ? '.' . $match[1] . '.summary' : $match[1] . '.lock';
            if ($file->getFilename() !== $expected) { continue; }
            clearstatcache(true, $file->getPathname()); $candidate = @lstat($file->getPathname());
            if (!$candidate) { continue; }
            $id = $match[1]; $path = $this->path($id); clearstatcache(true, $path);
            if (file_exists($path) || is_link($path) || !$archive->contains($id)) { continue; }
            if (!$this->regular($candidate)) { throw new \RuntimeException('Retired incident cache or lock contains an unsafe file or link.'); }
            $lock = $this->acquire($id);
            try {
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) { continue; }
                foreach (['/.' . $id . '.summary', '/' . $id . '.lock'] as $suffix) {
                    $stale = $this->directory . $suffix; clearstatcache(true, $stale); $at = @lstat($stale);
                    if ($at && (!$this->regular($at) || !$this->unlinkSame($stale, $at))) { throw new \RuntimeException('Retired incident cache or lock cleanup failed. Inspect storage permissions.'); }
                }
                if (!$this->syncDirectory()) { throw new \RuntimeException('Retired incident orphan cleanup could not be synchronized. Replay history remains archived.'); }
                $removed++;
            } finally { self::release($lock); }
        }
        return $removed;
    }
    /** Bounded minute cleanup. Never deletes a report or a replay identity. */
    public function retire(array $policy, int $now): array
    {
        $policy = IncidentConfig::retention($policy);
        $out = ['enabled' => $policy['enabled'], 'retired' => 0, 'errors' => []];
        if (!$policy['enabled']) { return $out; }
        $this->writer(); $deadline = microtime(true) + 3; $cutoff = $now - $policy['after_days'] * 86400;
        // Initialization and retirement use the same admission lock as starts.
        $global = $this->acquire('admission');
        try {
            $archive = $this->archive(true);
            $out['orphan_pairs_removed'] = $this->retiredOrphans($archive, $deadline);
            foreach ($this->ids() as $id) {
                if ($out['retired'] >= 10 || microtime(true) >= $deadline) { $out['more_pending'] = true; break; }
                $lock = null;
                try {
                    $row = $this->metadata($id)['row'];
                    if (!in_array($row['state'], ['closed', 'missed'], true) || (strtotime($row['updated_at']) ?: PHP_INT_MAX) > $cutoff) { continue; }
                    $lock = $this->acquire($id); $record = $this->read($id);
                    if (!$record || !empty($record['archived']) || !in_array($record['state'], ['closed', 'missed'], true)
                        || (strtotime($record['updated_at']) ?: PHP_INT_MAX) > $cutoff) { continue; }
                    // Unresolved submission evidence requires explicit review;
                    // retirement cannot make an uncertain send look terminal.
                    if (array_filter($record['operations'], static fn(array $op): bool => in_array($op['state'], ['submitting', 'uncertain'], true))) { continue; }
                    $archive->put($record, self::projection($record)['row'], gmdate('c', $now), $policy['max_archive_mib']);
                    $this->archiveCommitted($id);
                    $verified = $archive->read($id); unset($verified['archived'], $verified['archived_at']);
                    if ($verified !== $record || $this->read($id) !== $record) {
                        throw new \RuntimeException('The archived and active incident failed their final consistency check. Both copies were preserved.');
                    }
                    $path = $this->path($id); $at = @lstat($path);
                    if (!$at || !$this->regular($at) || !$this->unlinkSame($path, $at) || !$this->syncDirectory()) {
                        throw new \RuntimeException('An incident report was archived but its active copy could not be retired. The next pass will verify both copies before retrying.');
                    }
                    $this->activeCopyRetired($id);
                    // Waiters verify lock inode identity after taking flock;
                    // removing a retired lock cannot create a second writer.
                    foreach (['/.' . $id . '.summary', '/' . $id . '.lock'] as $suffix) {
                        $path = $this->directory . $suffix; $at = @lstat($path);
                        if ($at && (!$this->regular($at) || !$this->unlinkSame($path, $at))) { throw new \RuntimeException('Retired incident cache or lock cleanup failed. Preserve the archive and inspect storage permissions.'); }
                    }
                    if (!$this->syncDirectory()) { throw new \RuntimeException('Retired incident cleanup could not be synchronized. Replay history remains archived.'); }
                    $out['retired']++;
                } catch (\Throwable $error) { $out['errors'][] = ['incident_id' => $id, 'message' => $error->getMessage()]; break; }
                finally { self::release($lock); }
            }
        } finally { self::release($global); }
        return $out;
    }
}
