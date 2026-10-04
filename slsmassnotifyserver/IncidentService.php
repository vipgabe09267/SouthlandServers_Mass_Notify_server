<?php
declare(strict_types=1);
namespace SLS\MassNotify;
require_once __DIR__ . '/IncidentConfig.php';
require_once __DIR__ . '/IncidentStore.php';

/** Incident state never mutates an existing announcement or extends popup expiry. */
final class IncidentService
{
    private IncidentStore $store;
    private $template; private $freeze; private $send; private $job; private $clock;
    public function __construct(IncidentStore $store, callable $template, callable $freeze, callable $send, callable $job, ?callable $clock = null)
    {
        $this->store = $store; $this->template = $template; $this->freeze = $freeze; $this->send = $send; $this->job = $job;
        $this->clock = $clock ?? static fn(): int => time();
    }
    private function now(): int { return (int)($this->clock)(); }
    private function at(): string { return gmdate('c', $this->now()); }
    private static function requestId($value): string { return IncidentConfig::identifier($value, '/^[a-f0-9]{32}$/D', 'Request identifier'); }
    private static function actor(array $actor): array
    {
        $normalized = ['identity' => IncidentConfig::text($actor['identity'] ?? '', 100, 'Operator identity'),
            'source' => IncidentConfig::text($actor['source'] ?? 'administrator', 40, 'Operator source')];
        if (isset($actor['credential_id'])) {
            $normalized['credential_id'] = IncidentConfig::identifier($actor['credential_id'], '/^api_[a-f0-9]{24}$/D', 'Originating API credential');
        }
        if (isset($actor['automation_context'])) {
            $context=IncidentConfig::object($actor['automation_context'],['rule_id','revision','event_id','expires_at'],'Trigger provenance');
            foreach (['revision','event_id'] as $key) { IncidentConfig::identifier($context[$key] ?? '', '/^[a-f0-9]{64}$/D','Trigger provenance'); }
            IncidentConfig::identifier($context['rule_id'] ?? '', '/^trg_[a-f0-9]{24}$/D','Trigger rule');
            if (!is_int($context['expires_at'] ?? null)) { throw new \InvalidArgumentException('Trigger deadline is invalid.'); }
            $normalized['automation_context']=$context;
        }
        return $normalized;
    }
    private static function fingerprint(array $payload): string { return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)); }
    private function event(array &$record, string $kind, array $actor, array $details = []): void
    {
        $record['timeline'][] = ['at' => $this->at(), 'kind' => $kind, 'actor' => $actor, 'details' => $details];
        $record['updated_at'] = $this->at();
    }
    public function start(array $input, array $actor): array
    {
        $input = IncidentConfig::object($input, ['template_id', 'request_id', 'fields', 'is_test', 'planned_at', 'language_variant'], 'Start incident');
        $request = self::requestId($input['request_id'] ?? ''); $id = 'inc_' . substr(hash('sha256', $request), 0, 32);
        $actor = self::actor($actor); $fingerprint = self::fingerprint($input + ['actor' => $actor]);
        $existing = $this->store->read($id);
        if ($existing) {
            if (!hash_equals($existing['start_fingerprint'] ?? '', $fingerprint)) { throw new \InvalidArgumentException('That request identifier was used for different incident details.'); }
            return $this->summary($existing);
        }
        $template = IncidentConfig::template(($this->template)($input['template_id'] ?? ''));
        $rendered = IncidentConfig::render($template, $input['fields'] ?? [], array_key_exists('language_variant', $input) ? $input['language_variant'] : '');
        $language = strtolower($input['language_variant'] ?? '');
        $test = IncidentConfig::flag($input['is_test'] ?? false, 'Drill flag');
        if ($test) { $rendered['title'] = IncidentConfig::text('DRILL: ' . $rendered['title'], 80, 'Drill title including its required label'); }
        $planned = '';
        if (($input['planned_at'] ?? '') !== '') {
            if (!$test || !is_string($input['planned_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $input['planned_at'])) {
                throw new \InvalidArgumentException('Only drills can be scheduled; provide a timestamp with its timezone.');
            }
            try { $date = new \DateTimeImmutable($input['planned_at']); } catch (\Exception $error) { throw new \InvalidArgumentException('The drill start time is invalid.'); }
            $dateErrors = \DateTimeImmutable::getLastErrors();
            if (is_array($dateErrors) && ($dateErrors['warning_count'] || $dateErrors['error_count'])) { throw new \InvalidArgumentException('The drill start date is invalid.'); }
            $timestamp = $date->getTimestamp();
            if ($timestamp < $this->now() + 30 || $timestamp > $this->now() + 366 * 86400) { throw new \InvalidArgumentException('Schedule a drill at least 30 seconds and no more than one year ahead.'); }
            $planned = gmdate('c', $timestamp);
        }
        $template['delivery'] = ($this->freeze)($template['delivery'], $language);
        if ($template['escalation']['enabled']) {
            foreach ($template['escalation']['steps'] as &$step) { $step['delivery'] = ($this->freeze)($step['delivery'], $language); } unset($step);
            $template['escalation']['delivery'] = $template['escalation']['steps'][0]['delivery'];
        }
        foreach ($template['roster'] as $person) {
            if ($person['desktop_username'] !== '' && !in_array($person['desktop_username'], $template['delivery']['desktop_clients'], true)) {
                throw new \InvalidArgumentException('A roster desktop is outside the incident audience. Update the roster or destination selection.');
            }
        }
        $created = false;
        $record = $this->store->transaction($id, function (?array $record) use (&$created, $id, $request, $fingerprint, $template, $rendered, $test, $planned, $actor, $language): array {
            if ($record !== null) {
                if (!hash_equals($record['start_fingerprint'], $fingerprint)) { throw new \InvalidArgumentException('Request identifier collision.'); }
                return $record;
            }
            $created = true;
            $record = ['schema' => 1, 'id' => $id, 'start_fingerprint' => $fingerprint, 'template' => $template,
                'title' => $rendered['title'], 'message' => $rendered['message'], 'severity' => $template['severity'], 'is_test' => $test,
                'language_variant' => $language,
                'state' => $planned === '' ? 'open' : 'planned', 'created_at' => $this->at(), 'updated_at' => $this->at(),
                'opened_at' => '', 'closed_at' => '', 'planned_at' => $planned, 'created_by' => $actor,
                'operations' => [], 'responses' => [], 'actions' => [], 'checklist_results' => [], 'timeline' => []];
            $this->event($record, $planned === '' ? 'incident_created' : 'drill_scheduled', $actor);
            if ($planned === '') { $this->claim($record, $request, 'initial', $rendered['message'], $actor, $fingerprint); }
            return $record;
        }, true);
        if ($created && $planned === '') { $record = $this->dispatch($id, $request); }
        return $this->summary($record);
    }
    private function claim(array &$record, string $request, string $kind, string $message, array $actor, string $fingerprint): void
    {
        if (count($record['operations']) >= 250 || count($record['timeline']) > 3995
            || strlen(json_encode($record, JSON_THROW_ON_ERROR)) > IncidentStore::MAX_BYTES - 16384) {
            throw new \RuntimeException('Incident delivery history is full. Export it and start a new incident.');
        }
        foreach ($record['operations'] as $operation) {
            if ($operation['state'] === 'submitting') { throw new \RuntimeException('An incident submission has not confirmed its job. Refresh the incident and review uncertainty before sending again.'); }
            if ($operation['state'] === 'awaiting_approval') { throw new \DomainException('This incident has a message awaiting approval. Submit or reject that review before sending another update.'); }
        }
        if ($record['is_test'] && !str_starts_with($message, 'DRILL / TEST. ')) { $message = IncidentConfig::text('DRILL / TEST. ' . $message, 500, 'Drill message including its required label'); }
        $record['operations'][$request] = ['request_id' => $request, 'fingerprint' => $fingerprint,
            'sequence' => count($record['operations']) + 1, 'kind' => $kind, 'message' => $message,
            'state' => 'submitting', 'claimed_at' => $this->at(), 'actor' => $actor, 'job_id' => '', 'error' => ''];
        $this->event($record, 'submission_claimed', $actor, ['request_id' => $request, 'kind' => $kind]);
    }
    private function dispatch(string $id, string $request): array
    {
        $record = $this->store->read($id); $operation = $record['operations'][$request];
        $delivery = $record['template']['delivery'];
        if ($operation['kind'] === 'escalation') {
            $steps = IncidentConfig::escalationSteps($record['template']['escalation']);
            $number = $operation['escalation_step'] ?? 1;
            if (!is_int($number) || !isset($steps[$number - 1])) { throw new \RuntimeException('The frozen escalation step is unavailable. No automatic replay is allowed.'); }
            $delivery = $steps[$number - 1]['delivery'];
        }
        $context = ['schema' => 1, 'incident_id' => $id, 'sequence' => $operation['sequence'], 'kind' => $operation['kind'],
            'severity' => $record['severity'], 'is_test' => $record['is_test']];
        try {
            $result = ($this->send)($delivery, $operation['message'], $record['title'], $context, $operation['actor']);
            if (!is_array($result)) { throw new \RuntimeException('Invalid announcement response.'); }
        } catch (\Throwable $error) {
            $result = ['success' => false, 'message' => 'Announcement submission could not confirm its result. Review delivery jobs before taking another action.'];
        }
        return $this->store->transaction($id, function (array $record) use ($request, $result): array {
            $operation = &$record['operations'][$request];
            if ($operation['state'] !== 'submitting') { return $record; }
            $job = $result['job_id'] ?? '';
            if (is_string($job) && preg_match('/^job_[a-f0-9]{32}$/D', $job)) {
                $operation['job_id'] = $job; $operation['state'] = 'queued';
                if ($record['opened_at'] === '' && $operation['kind'] === 'initial') { $record['opened_at'] = $this->at(); }
                if ($operation['kind'] === 'all_clear') { $record['state'] = 'closed'; $record['closed_at'] = $this->at(); }
            } elseif (($result['awaiting_approval'] ?? false) === true && is_string($result['review_id'] ?? null)
                && preg_match('/^review_[a-f0-9]{32}$/D', $result['review_id'])) {
                $operation['state'] = 'awaiting_approval'; $operation['review_id'] = $result['review_id'];
            } else {
                $operation['state'] = ($result['delivery_started'] ?? null) === false ? 'not_submitted' : 'uncertain';
                $operation['error'] = IncidentConfig::text(is_string($result['message'] ?? null) ? $result['message'] : 'Announcement submission did not return a durable job identifier.', 1000, 'Delivery error');
            }
            $operation['finished_at'] = $this->at(); $actor = $operation['actor']; $kind = $operation['kind']; $state = $operation['state'];
            unset($operation);
            $this->event($record, 'submission_' . $state, $actor, ['request_id' => $request, 'kind' => $kind, 'job_id' => $job]);
            return $record;
        });
    }
    public function update(string $id, array $input, array $actor): array
    {
        $input = IncidentConfig::object($input, ['request_id', 'kind', 'message'], 'Incident update');
        $request = self::requestId($input['request_id'] ?? ''); $actor = self::actor($actor);
        $kind = $input['kind'] ?? 'update';
        if (!in_array($kind, ['update', 'all_clear'], true)) { throw new \InvalidArgumentException('Send a separate update or explicit all-clear. Sent announcements cannot be edited or cancelled.'); }
        $message = IncidentConfig::text($input['message'] ?? '', 500, 'Update message');
        $fingerprint = self::fingerprint($input + ['actor' => $actor]); $claimed = false;
        $record = $this->store->transaction($id, function (array $record) use (&$claimed, $request, $kind, $message, $actor, $fingerprint): array {
            if (isset($record['operations'][$request])) {
                if (!hash_equals($record['operations'][$request]['fingerprint'], $fingerprint)) { throw new \InvalidArgumentException('Request identifier already belongs to another update.'); }
                return $record;
            }
            if ($record['state'] !== 'open') { throw new \InvalidArgumentException('Only an open incident accepts new announcements.'); }
            $this->claim($record, $request, $kind, $message, $actor, $fingerprint); $claimed = true;
            return $record;
        });
        if ($claimed) { $record = $this->dispatch($id, $request); }
        return $this->summary($record);
    }
    private function action(array &$record, string $request, array $input, array $actor): bool
    {
        $fingerprint = self::fingerprint($input + ['actor' => $actor]);
        if (isset($record['actions'][$request])) {
            if (!hash_equals($record['actions'][$request], $fingerprint)) { throw new \InvalidArgumentException('Request identifier belongs to a different response.'); }
            return false;
        }
        if (count($record['actions']) >= 3500) { throw new \RuntimeException('Incident response history is full. Export this incident before starting another.'); }
        $record['actions'][$request] = $fingerprint; return true;
    }
    public function respond(string $id, array $input, array $actor, ?string $desktop = null, ?callable $authorize = null): array
    {
        $input = IncidentConfig::object($input, ['request_id', 'person_id', 'response', 'note'], 'Human response');
        $request = self::requestId($input['request_id'] ?? ''); $actor = self::actor($actor);
        $response = $input['response'] ?? '';
        if (!in_array($response, $desktop === null ? ['received', 'safe', 'needs_assistance', 'missing'] : ['received', 'safe', 'needs_assistance'], true)) {
            throw new \InvalidArgumentException('Choose a supported human response.');
        }
        $note = IncidentConfig::text($input['note'] ?? '', 500, 'Response note', true);
        $record = $this->store->transaction($id, function (array $record) use ($input, $request, $actor, $desktop, $response, $note, $authorize): array {
            $personId = '';
            foreach ($record['template']['roster'] as $person) {
                if (($desktop !== null && $person['desktop_username'] === $desktop && in_array($desktop, $record['template']['delivery']['desktop_clients'], true))
                    || ($desktop === null && $person['id'] === ($input['person_id'] ?? ''))) { $personId = $person['id']; break; }
            }
            if ($personId === '' || ($desktop !== null && isset($input['person_id']) && $input['person_id'] !== $personId)) {
                throw new \InvalidArgumentException('This identity is not authorized to answer for that incident participant.');
            }
            if ($authorize !== null && !$authorize($record, $person)) { throw new \InvalidArgumentException('Your current assignment does not allow a response for this participant.'); }
            if (!$this->action($record, $request, $input, $actor)) { return $record; }
            if ($record['state'] !== 'open') { throw new \InvalidArgumentException('Human responses are accepted only while the incident is open.'); }
            $record['responses'][$personId] = ['response' => $response, 'note' => $note, 'at' => $this->at(), 'actor' => $actor,
                'source' => $desktop === null ? 'operator_roll_call' : 'human_desktop_response'];
            $this->event($record, 'human_response', $actor, ['person_id' => $personId, 'response' => $response, 'note' => $note]);
            return $record;
        });
        return $desktop === null ? $this->summary($record) : $this->desktop($record, $desktop);
    }
    public function checklist(string $id, array $input, array $actor): array
    {
        $input = IncidentConfig::object($input, ['request_id', 'item', 'complete', 'note'], 'Observer result');
        $request = self::requestId($input['request_id'] ?? ''); $actor = self::actor($actor);
        if (!is_int($input['item'] ?? null) || $input['item'] < 0 || $input['item'] > 24) { throw new \InvalidArgumentException('Unknown observer checklist item.'); }
        $complete = IncidentConfig::flag($input['complete'] ?? false, 'Checklist completion');
        $note = IncidentConfig::text($input['note'] ?? '', 500, 'Observer note', true);
        $record = $this->store->transaction($id, function (array $record) use ($request, $input, $actor, $complete, $note): array {
            if (!isset($record['template']['checklist'][$input['item']])) { throw new \InvalidArgumentException('Unknown observer checklist item.'); }
            if (!$this->action($record, $request, $input, $actor)) { return $record; }
            $record['checklist_results'][(string)$input['item']] = ['complete' => $complete, 'note' => $note, 'at' => $this->at(), 'actor' => $actor];
            $this->event($record, 'observer_result', $actor, ['item' => $input['item'], 'complete' => $complete, 'note' => $note]);
            return $record;
        });
        return $this->summary($record);
    }
    private function counts(array $record): array
    {
        $counts = ['expected' => count($record['template']['roster']), 'no_response' => 0, 'received' => 0, 'safe' => 0, 'needs_assistance' => 0, 'missing' => 0];
        foreach ($record['template']['roster'] as $person) { $counts[$record['responses'][$person['id']]['response'] ?? 'no_response']++; }
        return $counts;
    }
    private function summary(array $record): array
    {
        $record['response_counts'] = $this->counts($record); $record['success'] = true;
        unset($record['start_fingerprint'], $record['actions']);
        foreach ($record['operations'] as &$operation) { unset($operation['fingerprint']); }
        unset($operation);
        return $record;
    }
    public function get(string $id, bool $jobs = true): array
    {
        $record = $this->store->read($id); if (!$record) { throw new \InvalidArgumentException('Incident was not found.'); }
        $out = $this->summary($record); $deadline = microtime(true) + 3; $count = 0;
        $operationKeys = array_reverse(array_keys($out['operations']));
        foreach ($operationKeys as $operationKey) {
            $operation = &$out['operations'][$operationKey];
            if ($operation['state'] === 'submitting' && $this->now() - (strtotime($operation['claimed_at']) ?: 0) > 120) {
                $operation['state'] = 'uncertain'; $operation['error'] = 'Submission was interrupted before its incident linkage was confirmed. Do not resend automatically; inspect announcement jobs.';
            }
            if ($jobs && $operation['job_id'] !== '') {
                if (++$count > 10 || microtime(true) > $deadline) { $out['delivery_details_incomplete'] = true; continue; }
                try { $operation['delivery'] = ($this->job)($operation['job_id']); }
                catch (\Throwable $error) { $operation['delivery'] = ['success' => false, 'message' => 'Announcement job details are temporarily unavailable.']; }
            }
        }
        unset($operation); return $out;
    }
    private function desktop(array $record, string $username): array
    {
        $person = null;
        foreach ($record['template']['roster'] as $row) { if ($row['desktop_username'] === $username) { $person = $row; break; } }
        if (!$person || !in_array($username, $record['template']['delivery']['desktop_clients'], true)) { throw new \InvalidArgumentException('This desktop is not a participant in the incident.'); }
        $events = [];
        foreach ($record['operations'] as $operation) {
            if ($operation['kind'] !== 'escalation') { $events[] = array_intersect_key($operation, array_flip(['sequence', 'kind', 'message', 'state', 'claimed_at', 'job_id'])); }
        }
        return ['success' => true, 'schema' => 1, 'id' => $record['id'], 'title' => $record['title'], 'severity' => $record['severity'],
            'state' => $record['state'], 'is_test' => $record['is_test'], 'created_at' => $record['created_at'],
            'person' => $person, 'response' => $record['responses'][$person['id']] ?? null, 'updates' => $events];
    }
    public function forDesktop(string $id, string $username): array
    {
        $record = $this->store->read($id); if (!$record) { throw new \InvalidArgumentException('Incident was not found.'); }
        return $this->desktop($record, $username);
    }
    public function listing(int $limit = 50, string $cursor = '', bool $archived = false): array
    {
        if ($limit < 1 || $limit > 200) { throw new \InvalidArgumentException('Incident list limit must be 1–200.'); }
        $after = null;
        if ($cursor !== '') {
            if (strlen($cursor) > 300 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { throw new \InvalidArgumentException('Incident history cursor is invalid.'); }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $after = is_string($decoded) ? json_decode($decoded, true) : null;
            if (!is_array($after) || count($after) !== ($archived ? 3 : 2) || ($archived && ($after['archived'] ?? null) !== true) || !is_string($after['created_at'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $after['created_at']) || !IncidentStore::validId($after['id'] ?? null)) {
                throw new \InvalidArgumentException('Incident history cursor is invalid.');
            }
        }
        $rows = []; $total = 0; $deadline = microtime(true) + 3;
        if ($archived) {
            $history = $this->store->archivedListing($limit, $after); $rows = $history['rows']; $total = $history['total'];
        }
        foreach ($archived ? [] : $this->store->ids() as $id) {
            if (microtime(true) > $deadline) { throw new \RuntimeException('Incident listing exceeded its read deadline; no incomplete result was presented as complete.'); }
            $record = $this->store->metadata($id)['row']; $total++;
            if ($after !== null && (strcmp($record['created_at'], $after['created_at']) > 0
                || ($record['created_at'] === $after['created_at'] && strcmp($record['id'], $after['id']) >= 0))) { continue; }
            $rows[] = $record;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
        $page = array_slice($rows, 0, $limit); $next = '';
        if (count($rows) > $limit && $page) {
            $last = $page[count($page) - 1];
            $position = ['created_at' => $last['created_at'], 'id' => $last['id']];
            if ($archived) { $position['archived'] = true; }
            $next = rtrim(strtr(base64_encode(json_encode($position, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        }
        return ['success' => true, 'total' => $total, 'has_more' => $next !== '', 'next_cursor' => $next, 'incidents' => $page, 'archived' => $archived];
    }
    /** Complete report data in bounded pages; a revision prevents mixed incident histories. */
    public function report(string $id, int $offset = 0, string $revision = ''): array
    {
        if ($offset < 0 || $offset > 250 || ($revision !== '' && !preg_match('/^[a-f0-9]{64}$/D', $revision))) {
            throw new \InvalidArgumentException('Incident report position or revision is invalid.');
        }
        $record = $this->store->read($id); if (!$record) { throw new \InvalidArgumentException('Incident was not found.'); }
        $current = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
        if (($offset > 0 && $revision === '') || ($revision !== '' && !hash_equals($current, $revision))) {
            throw new \InvalidArgumentException('Incident history changed during export. Restart the export for a consistent report.');
        }
        $out = $this->summary($record); $keys = array_keys($out['operations']);
        if ($offset > count($keys)) { throw new \InvalidArgumentException('Incident report position is beyond its history.'); }
        $deadline = microtime(true) + 3; $read = 0; $next = $offset; $available = true;
        while ($next < count($keys)) {
            if ($read >= 10 || ($read > 0 && microtime(true) >= $deadline)) { break; }
            $operation = &$out['operations'][$keys[$next]];
            if ($operation['job_id'] !== '') {
                try { $operation['delivery'] = ($this->job)($operation['job_id']); }
                catch (\Throwable $error) { $operation['delivery'] = ['success' => false, 'state' => 'missing', 'message' => 'Delivery history is unavailable; it may have expired under its separate retention policy.']; }
                if (($operation['delivery']['job_id'] ?? '') !== $operation['job_id']) { $available = false; }
                $read++;
            }
            unset($operation); $next++;
        }
        return ['success' => true, 'schema' => 1, 'revision' => $current, 'job_offset' => $offset,
            'next_job_offset' => $next < count($keys) ? $next : null, 'complete' => $next >= count($keys),
            'delivery_results_available' => $available, 'incident' => $out];
    }
    /** Minute worker: bounded, opt-in supervisor escalations and explicit drill plans only. */
    public function process(): array
    {
        $result = ['success' => true, 'processed' => 0, 'expired' => 0, 'errors' => []]; $deadline = microtime(true) + 8;
        $due = [];
        foreach ($this->store->ids() as $id) {
            if (microtime(true) > $deadline - 3) { $result['more_pending'] = true; $result['metadata_scan_incomplete'] = true; break; }
            try {
                $metadata = $this->store->metadata($id);
                if ($metadata['due_at'] !== null && $metadata['due_at'] <= $this->now()) { $due[$id] = $metadata['due_at']; }
            } catch (\Throwable $error) {
                $result['errors'][] = ['incident_id' => $id, 'message' => 'Incident metadata could not be read safely. Inspect its record and storage health.'];
            }
        }
        // Oldest due work first; closed histories and future drills never load
        // their full multi-megabyte records on every minute worker invocation.
        asort($due, SORT_NUMERIC);
        foreach ($due as $id => $dueAt) {
            if ($result['processed'] >= 5 || microtime(true) > $deadline) { $result['more_pending'] = true; break; }
            try {
                $claimed = ''; $record = $this->store->read($id);
                $stale = array_filter($record['operations'], fn(array $op): bool => $op['state'] === 'submitting' && $this->now() - (strtotime($op['claimed_at']) ?: 0) > 120);
                if ($stale) {
                    $record = $this->store->transaction($id, function (array $record): array {
                        $changed = [];
                        foreach ($record['operations'] as &$operation) {
                            if ($operation['state'] === 'submitting' && $this->now() - (strtotime($operation['claimed_at']) ?: 0) > 120) {
                                $operation['state'] = 'uncertain'; $operation['error'] = 'Submission was interrupted before its job linkage was confirmed. Inspect announcement jobs; automatic replay is prohibited.';
                                $changed[] = $operation['request_id'];
                            }
                        }
                        unset($operation);
                        foreach ($changed as $requestId) { $this->event($record, 'interrupted_submission_uncertain', ['identity' => 'SLS incident worker', 'source' => 'incident_policy'], ['request_id' => $requestId]); }
                        return $record;
                    });
                }
                if ($record['state'] !== 'planned' && ($record['state'] !== 'open' || !$record['template']['escalation']['enabled'] || $record['opened_at'] === '')) { continue; }
                $this->store->transaction($id, function (array $record) use (&$claimed, &$result): array {
                    $actor = ['identity' => 'SLS incident worker', 'source' => 'incident_policy'];
                    if (isset($record['created_by']['credential_id'])) { $actor['credential_id'] = $record['created_by']['credential_id']; }
                    elseif (($record['created_by']['source'] ?? '') === 'control_api') { throw new \RuntimeException('Incident policy lacks its originating revocable API identity.'); }
                    if (isset($record['created_by']['automation_context'])) { $actor['automation_context']=$record['created_by']['automation_context']; }
                    if ($record['state'] === 'planned' && (strtotime($record['planned_at']) ?: PHP_INT_MAX) <= $this->now()) {
                        if ($this->now() - strtotime($record['planned_at']) > 900) {
                            $record['state'] = 'missed'; $this->event($record, 'scheduled_drill_missed', $actor);
                            $result['expired']++; return $record;
                        }
                        $claimed = substr(hash('sha256', $record['id'] . ':scheduled'), 0, 32); $record['state'] = 'open';
                        $this->claim($record, $claimed, 'initial', $record['message'], $actor, self::fingerprint(['scheduled' => $record['id']]));
                    } elseif (($step = IncidentConfig::nextEscalation($record)) !== null && $this->now() >= $step['due_at']) {
                        $counts = $this->counts($record);
                        $claimed = substr(hash('sha256', $record['id'] . ':supervisor' . ($step['number'] === 1 ? '' : ':' . $step['number'])), 0, 32);
                        $message = IncidentConfig::escalationMessage($record['title'], $counts, $record['language_variant'] ?? '');
                        $this->claim($record, $claimed, 'escalation', $message, $actor, self::fingerprint(['supervisor' => $record['id'], 'step' => $step['number']]));
                        $record['operations'][$claimed]['escalation_step'] = $step['number'];
                        $record['operations'][$claimed]['escalation_name'] = $step['name'];
                        $record['timeline'][array_key_last($record['timeline'])]['details']['escalation_step'] = $step['number'];
                    }
                    return $record;
                });
                if ($claimed !== '') { $this->dispatch($id, $claimed); $result['processed']++; }
            } catch (\Throwable $error) { $result['errors'][] = ['incident_id' => $id, 'message' => 'Incident policy could not complete safely. Inspect its record and storage health.']; }
        }
        $result['success'] = !$result['errors'];
        return $result;
    }
}
