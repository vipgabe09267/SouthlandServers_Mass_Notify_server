<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/Incidents.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentStore;
use SLS\MassNotify\IncidentService;
function incident_check($value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function incident_reject(callable $work, string $message): void
{
    try { $work(); } catch (InvalidArgumentException | RuntimeException $error) { return; }
    throw new LogicException($message);
}
$root = sys_get_temp_dir() . '/sls-incidents-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
});
$request = static fn(int $number): string => str_pad(dechex($number), 32, '0', STR_PAD_LEFT);
$actor = ['identity' => 'Fixture operator', 'source' => 'test'];
$template = IncidentConfig::template(['id' => 'tpl_' . str_repeat('a', 24), 'name' => 'Evacuation', 'title' => 'Evacuate {{location}}',
    'message' => 'Leave {{location}} using the marked exit.', 'fields' => [['key' => 'location', 'label' => 'Affected location']],
    'severity' => 'critical', 'delivery' => ['extensions' => ['1000'], 'desktop_clients' => ['fixture.desktop']],
    'roster' => [['id' => 'person_1', 'name' => 'Person One', 'location' => 'Office', 'desktop_username' => 'fixture.desktop'],
        ['id' => 'person_2', 'name' => 'Person Two', 'location' => 'Workshop', 'desktop_username' => '']],
    'checklist' => ['Check exit route', 'Record assembly time'],
    'resources' => [['kind'=>'map', 'label'=>'Approved floor plan', 'url'=>'https://maps.example.test/evacuation/revision-2.pdf',
        'revision'=>'Facilities review, revision 2', 'reviewed'=>true]],
    'language_variants'=>[['locale'=>'es-MX','label'=>'Spanish (fixture)','title'=>'Evacuar {{location}}',
        'message'=>'Salga de {{location}}.','review_note'=>'Test fixture wording only','reviewed'=>true]]]);
incident_check(IncidentConfig::normalize([]) === ['schema' => 1, 'templates' => []], 'Default incident settings were active.');
incident_check($template['escalation']['enabled'] === false, 'Escalation enabled by default.');
foreach (['http://example.test/map', 'javascript:alert(1)', '//example.test/map', 'https://user:secret@example.test/map',
    'https://example.test/map%0aInjected', "https://example.test/map\nInjected", 'https://example.test/map\\evil', 'https://example.test:99999/map'] as $url) {
    $resource = array_replace($template['resources'][0], ['url'=>$url]);
    incident_reject(static fn()=>IncidentConfig::resources([$resource]), 'Unsafe resource URL accepted.');
}
foreach ([['reviewed'=>false], ['reviewed'=>'true'], ['revision'=>''], ['kind'=>'iframe'], ['label'=>str_repeat('x',81)], ['secret'=>'invalid']] as $change) {
    incident_reject(static fn()=>IncidentConfig::resources([array_replace($template['resources'][0],$change)]), 'Unreviewed or invalid resource accepted.');
}
incident_reject(static fn()=>IncidentConfig::resources(array_fill(0,11,$template['resources'][0])), 'Unbounded resources accepted.');
incident_reject(static fn()=>IncidentConfig::resources(array_fill(0,2,$template['resources'][0])), 'Duplicate resource accepted.');
incident_check(IncidentConfig::resources([])===[], 'Legacy resource-free template changed.');
incident_check($template['language_variants'][0]['locale']==='es-mx', 'Language tags were not normalized.');
incident_check(IncidentConfig::render($template,['location'=>'Norte'],'es-MX')['message']==='Salga de Norte.', 'Reviewed translation or operator field lost.');
foreach ([['reviewed'=>false], ['reviewed'=>'true'], ['locale'=>'../config'], ['locale'=>str_repeat('a',100)], ['title'=>'Unknown {{undeclared}}'],
    ['title'=>'Title', 'message'=>'Missing required fields'], ['message'=>str_repeat('á',501)], ['review_note'=>'']] as $change) {
    incident_reject(static fn()=>IncidentConfig::languageVariants([array_replace($template['language_variants'][0],$change)],['location']), 'Invalid or unreviewed language accepted.');
}
incident_reject(static fn()=>IncidentConfig::languageVariants(array_fill(0,7,$template['language_variants'][0]),['location']), 'Unbounded language list accepted.');
incident_reject(static fn()=>IncidentConfig::languageVariants(array_fill(0,2,$template['language_variants'][0]),['location']), 'Duplicate language accepted.');
foreach (['missing', [], null] as $language) {
    incident_reject(static fn()=>IncidentConfig::render($template,['location'=>'North'],$language), 'Unknown language silently used default wording.');
}
$spoken = $template; $spoken['delivery']['audio_mode']='tts';
incident_check(IncidentConfig::render($spoken,['location'=>'Norte'],'es-mx')['message']==='Salga de Norte.', 'Reviewed Spanish speech changed or lost its field.');
$unsupported = $spoken; $unsupported['language_variants'][0]['locale']='ja';
incident_reject(static fn()=>IncidentConfig::render($unsupported,['location'=>'North'],'ja'), 'Unsupported speech language was silently sent to another voice.');
$spoken['language_variants'][0]['locale']='en-us'; $spoken['language_variants'][0]['title']='Leave {{location}}';
incident_check(IncidentConfig::render($spoken,['location'=>'North'],'en-us')['title']==='Leave North', 'Language selection started silently guessing or translating content.');
foreach ([['schema' => 2], ['templates' => [['id' => 'bad']]], ['extra' => true], ['templates' => 'bad']] as $bad) {
    incident_reject(static fn() => IncidentConfig::normalize($bad), 'Malformed incident config accepted.');
}
foreach ([['delivery' => ['extensions' => ['1000;System(bad)']]], ['roster' => [$template['roster'][0], $template['roster'][0]]],
    ['severity' => 'urgent'], ['message' => 'Unknown {{bad}}'], ['fields' => []], ['escalation' => ['enabled' => true]],
    ['message' => str_repeat('a', 501)], ['delivery' => ['desktop_all' => true]], ['escalation' => ['enabled' => '1']]] as $change) {
    incident_reject(static fn() => IncidentConfig::template(array_replace($template, $change)), 'Unsafe template accepted.');
}
incident_reject(static fn() => IncidentConfig::render($template, ['location' => '{{nested}}']), 'Nested placeholders accepted.');
incident_reject(static fn() => IncidentConfig::render($template, ['location' => str_repeat('X', 121)]), 'Unbounded operator value accepted.');
$unicode = IncidentConfig::render($template, ['location' => '北館']);
incident_check(strpos($unicode['message'], '北館') !== false, 'Unicode field lost.');
$context = ['schema' => 1, 'incident_id' => 'inc_' . str_repeat('a', 32), 'sequence' => 1, 'kind' => 'initial', 'severity' => 'warning', 'is_test' => true];
incident_check(IncidentConfig::context($context) === $context, 'Valid publication context changed.');
foreach ([['sequence' => '1'], ['is_test' => 'true'], ['kind' => 'cancel'], ['sequence' => 251], ['incident_id' => '../config'], ['extra' => true]] as $change) {
    incident_reject(static fn() => IncidentConfig::context(array_replace($context, $change)), 'Malformed publication context accepted.');
}
$now = 1800000000; $sent = []; $latestTemplate = $template; $failSend = false; $throwSend = false;
$store = new IncidentStore($root . '/main');
$make = static function (IncidentStore $store) use (&$now, &$sent, &$latestTemplate, &$failSend, &$throwSend): IncidentService {
    return new IncidentService($store, static function ($id) use (&$latestTemplate): array {
        if ($id !== $latestTemplate['id']) { throw new InvalidArgumentException('Unknown template.'); } return $latestTemplate;
    }, static function (array $delivery): array { $delivery['group_ids'] = []; return $delivery; },
        static function (array $delivery, string $message, string $title, array $context, array $actor) use (&$sent, &$failSend, &$throwSend): array {
            if ($throwSend) { throw new RuntimeException('fixture result unavailable'); }
            if ($failSend) { return ['success' => false, 'delivery_started' => false, 'message' => 'Fixture cooldown.']; }
            $job = 'job_' . str_pad(dechex(count($sent) + 1), 32, '0', STR_PAD_LEFT);
            $sent[] = compact('delivery', 'message', 'title', 'context', 'actor', 'job');
            return ['success' => true, 'job_id' => $job, 'queued' => true];
        }, static fn(string $id): array => ['success' => true, 'job_id' => $id, 'receipts' => [['channel' => 'desktop', 'target' => 'fixture.desktop', 'state' => 'received', 'detail' => 'Received by desktop app']]],
        static function () use (&$now): int { return $now; });
};
$service = $make($store); $start = ['template_id' => $template['id'], 'request_id' => $request(1), 'fields' => ['location' => 'North wing'], 'is_test' => false];
$incident = $service->start($start, $actor); $id = $incident['id'];
incident_check(count($sent) === 1 && $incident['state'] === 'open' && $incident['operations'][$request(1)]['job_id'] === $sent[0]['job'], 'Initial incident did not bind its exact durable job.');
incident_check($sent[0]['context']['incident_id'] === $id && $sent[0]['context']['sequence'] === 1 && $sent[0]['context']['is_test'] === false, 'Incident publication metadata lost.');
incident_check($sent[0]['message'] === 'Leave North wing using the marked exit.', 'Rendered instructions changed.');
$originalJob = $sent[0]; $service->start($start, $actor);
incident_check(count($sent) === 1, 'Duplicate start sent twice.');
incident_reject(static fn() => $service->start(array_replace($start, ['fields' => ['location' => 'Other']]), $actor), 'Reused start key changed content.');
$latestTemplate['delivery']['extensions'][] = '2000'; $latestTemplate['message'] = 'Changed template {{location}}';
$latestTemplate['resources'][0]['url'] = 'https://maps.example.test/revision-3.pdf';
$update = ['request_id' => $request(2), 'kind' => 'update', 'message' => 'Use the east stairwell.'];
$updated = $service->update($id, $update, $actor); $service->update($id, $update, $actor);
incident_check(count($sent) === 2 && $sent[0] === $originalJob && $sent[1]['delivery']['extensions'] === ['1000'], 'Updates altered old sends or expanded their audience.');
incident_check($updated['operations'][$request(2)]['sequence'] === 2 && $sent[1]['context']['kind'] === 'update', 'Update lost sequence/job linkage.');
incident_check($updated['template']['resources'] === $template['resources'], 'Existing incident adopted a later resource revision.');
incident_check($service->report($id)['incident']['template']['resources'] === $template['resources'], 'Incident report omitted its original approved resources.');
incident_reject(static fn() => $service->update($id, ['request_id' => $request(3), 'kind' => 'cancel', 'message' => 'cancel'], $actor), 'Sent incident cancellation accepted.');
incident_reject(static fn() => $service->update($id, $update + ['desktop_clients' => ['attacker']], $actor), 'Update audience override accepted.');
$beforeResponse = $service->get($id);
incident_check($beforeResponse['response_counts']['no_response'] === 2 && $beforeResponse['response_counts']['safe'] === 0, 'Software ACK marked a human safe.');
$response = ['request_id' => $request(4), 'response' => 'safe', 'note' => 'At assembly point'];
$view = $service->respond($id, $response, ['identity' => 'fixture.desktop', 'source' => 'desktop'], 'fixture.desktop');
incident_check($view['person']['id'] === 'person_1' && !isset($view['template'], $view['responses']) && $view['response']['response'] === 'safe', 'Desktop projection leaked full roster or lost response.');
$firstAt = $view['response']['at']; $now += 20; $duplicate = $service->respond($id, $response, ['identity' => 'fixture.desktop', 'source' => 'desktop'], 'fixture.desktop');
incident_check($duplicate['response']['at'] === $firstAt, 'Duplicate response changed timestamp.');
incident_reject(static fn() => $service->respond($id, ['request_id' => $request(5), 'person_id' => 'person_2', 'response' => 'safe'], ['identity' => 'fixture.desktop', 'source' => 'desktop'], 'fixture.desktop'), 'Desktop answered for another person.');
incident_reject(static fn() => $service->forDesktop($id, 'stranger'), 'Unrelated desktop read incident.');
incident_reject(static fn() => $service->respond($id, ['request_id' => $request(6), 'response' => 'safe'], ['identity' => 'stranger', 'source' => 'desktop'], 'stranger'), 'Unrelated desktop answered.');
$service->respond($id, ['request_id' => $request(7), 'person_id' => 'person_2', 'response' => 'needs_assistance', 'note' => 'Operator confirmed by phone'], $actor);
$service->checklist($id, ['request_id' => $request(8), 'item' => 0, 'complete' => true, 'note' => 'Exit checked'], $actor);
$report = $service->get($id);
incident_check($report['response_counts']['safe'] === 1 && $report['response_counts']['needs_assistance'] === 1 && $report['checklist_results'][0]['complete'], 'Roll call or observer result missing.');
incident_check($report['responses']['person_2']['source'] === 'operator_roll_call', 'Operator observation masqueraded as self-response.');
$clear = ['request_id' => $request(9), 'kind' => 'all_clear', 'message' => 'All clear. Return when instructed by staff.'];
$closed = $service->update($id, $clear, $actor); $service->update($id, $clear, $actor);
incident_check($closed['state'] === 'closed' && count($sent) === 3 && $sent[2]['context']['kind'] === 'all_clear' && $sent[0] === $originalJob, 'All-clear replayed or changed old instructions.');
incident_reject(static fn() => $service->update($id, ['request_id' => $request(10), 'message' => 'late'], $actor), 'Closed incident accepted new send.');
incident_reject(static fn() => $service->respond($id, ['request_id' => $request(11), 'person_id' => 'person_1', 'response' => 'missing'], $actor), 'Closed incident accepted new human response.');
incident_check($service->listing()['total'] === 1, 'Incident listing lost records.');
// Planned drills remain inert until due, preserve audience snapshots, and do not catch up stale sends.
$latestTemplate = $template; $now += 1000; $before = count($sent);
$plan = $start; $plan['request_id'] = $request(20); $plan['is_test'] = true; $plan['planned_at'] = gmdate('c', $now + 120);
$planned = $service->start($plan, $actor); $service->process();
incident_check(count($sent) === $before && $planned['state'] === 'planned', 'Planning a drill sent immediately.');
$latestTemplate['delivery']['extensions'][] = '2000'; $now += 120; $service->process(); $service->process();
incident_check(count($sent) === $before + 1 && end($sent)['context']['is_test'] === true && end($sent)['delivery']['extensions'] === ['1000'], 'Scheduled drill duplicated or expanded audience.');
$plan['request_id'] = $request(21); $plan['planned_at'] = gmdate('c', $now + 60); $missed = $service->start($plan, $actor); $now += 961;
$service->process(); incident_check($service->get($missed['id'])['state'] === 'missed' && count($sent) === $before + 1, 'Late drill replayed.');
incident_reject(static fn() => $service->start(array_replace($plan, ['request_id' => $request(22), 'is_test' => false]), $actor), 'Real incident silently scheduled.');
// One configured supervisor escalation; software ACK never satisfies it.
$latestTemplate = $template; $latestTemplate['escalation'] = ['enabled' => true, 'after_seconds' => 60, 'delivery' => IncidentConfig::delivery(['desktop_clients' => ['supervisor']])];
$escalated = $service->start(array_replace($start, ['request_id' => $request(30)]), $actor); $before = count($sent);
$now += 59; $service->process(); incident_check(count($sent) === $before, 'Escalation fired before deadline.');
$now++; $service->process(); $service->process();
incident_check(count($sent) === $before + 1 && end($sent)['context']['kind'] === 'escalation' && end($sent)['delivery']['desktop_clients'] === ['supervisor'], 'Supervisor escalation missing, duplicated or misrouted.');
// Definite non-submission and uncertain submission retain immutable attempts and never auto-retry.
$latestTemplate = $template; $failSend = true; $failed = $service->start(array_replace($start, ['request_id' => $request(40)]), $actor);
incident_check($failed['operations'][$request(40)]['state'] === 'not_submitted', 'Known pre-send failure became success.');
$failSend = false; $throwSend = true; $uncertainInput = array_replace($start, ['request_id' => $request(41)]); $uncertain = $service->start($uncertainInput, $actor); $before = count($sent);
$throwSend = false; $service->start($uncertainInput, $actor); $service->process();
incident_check($uncertain['operations'][$request(41)]['state'] === 'uncertain' && count($sent) === $before, 'Uncertain send was replayed.');
// Stable history cursors and complete reports remain bounded and do not silently omit older jobs.
$latestTemplate = $template; $many = $service->start(array_replace($start, ['request_id' => $request(100)]), $actor);
for ($i = 101; $i <= 114; $i++) { $service->update($many['id'], ['request_id' => $request($i), 'kind' => 'update', 'message' => 'Separate update ' . $i], $actor); }
$pageOne = $service->report($many['id']);
incident_check(!$pageOne['complete'] && $pageOne['next_job_offset'] === 10, 'Large report was falsely complete or exceeded its job read bound.');
$pageTwo = $service->report($many['id'], $pageOne['next_job_offset'], $pageOne['revision']);
incident_check($pageTwo['complete'] && $pageTwo['next_job_offset'] === null, 'Report pagination did not finish.');
$receipts = [];
foreach ([$pageOne, $pageTwo] as $page) { foreach ($page['incident']['operations'] as $op) { if (isset($op['delivery'])) { $receipts[$op['job_id']] = $op['delivery']; } } }
incident_check(count($receipts) === 15, 'Paged report omitted a historical announcement receipt.');
$service->checklist($many['id'], ['request_id' => $request(115), 'item' => 0, 'complete' => true], $actor);
incident_reject(static fn() => $service->report($many['id'], 10, $pageOne['revision']), 'Mixed-revision report was accepted.');
$firstPage = $service->listing(2); incident_check($firstPage['has_more'] && $firstPage['next_cursor'] !== '', 'History cursor missing.');
$secondPage = $service->listing(2, $firstPage['next_cursor']);
incident_check(!array_intersect(array_column($firstPage['incidents'], 'id'), array_column($secondPage['incidents'], 'id')), 'History pages repeated incidents.');
$expectedSecond = array_column($secondPage['incidents'], 'id'); $now++;
$service->start(array_replace($start, ['request_id' => $request(116)]), $actor);
incident_check(array_column($service->listing(2, $firstPage['next_cursor'])['incidents'], 'id') === $expectedSecond, 'New incident shifted an existing history cursor.');
incident_reject(static fn() => $service->listing(2, '../invalid'), 'Malformed history cursor accepted.');

// File safety rejects symlink, hardlink and FIFO without touching their targets.
$unsafe = new IncidentStore($root . '/unsafe'); $unsafeId = 'inc_' . str_repeat('d', 32); $path = $root . '/unsafe/' . $unsafeId . '.json';
$target = $root . '/outside'; file_put_contents($target, 'protected fixture'); chmod($target, 0600);
symlink($target, $path); incident_reject(static fn() => $unsafe->read($unsafeId), 'Symlink read accepted.'); unlink($path);
link($target, $path); incident_reject(static fn() => $unsafe->read($unsafeId), 'Hardlink read accepted.'); unlink($path);
if (function_exists('posix_mkfifo')) { posix_mkfifo($path, 0600); $begin = microtime(true); incident_reject(static fn() => $unsafe->read($unsafeId), 'FIFO read accepted.'); incident_check(microtime(true) - $begin < .5, 'FIFO access blocked.'); unlink($path); }
incident_check(file_get_contents($target) === 'protected fixture', 'Unsafe state changed another file.');
// Atomic claim must survive complete short writes; failed claims cannot invoke sender.
class IncidentShortWriteStore extends IncidentStore { protected function writeBytes($handle, string $bytes) { return fwrite($handle, substr($bytes, 0, 17)); } }
class IncidentFailedSyncStore extends IncidentStore { protected function syncFile($handle): bool { return false; } }
$latestTemplate = $template; $before = count($sent);
$short = $make(new IncidentShortWriteStore($root . '/short')); $short->start(array_replace($start, ['request_id' => $request(50)]), $actor);
incident_check(count($sent) === $before + 1, 'Complete short writes were not handled.');
$failedStore = $make(new IncidentFailedSyncStore($root . '/failed')); $before = count($sent);
incident_reject(static fn() => $failedStore->start(array_replace($start, ['request_id' => $request(51)]), $actor), 'Unsynchronized claim accepted.');
incident_check(count($sent) === $before, 'Failed durable claim still sent.');
// Real lock contention has a total bounded wait and cannot send around the lock.
$recordPath = $root . '/main/' . $id . '.lock'; $lock = fopen($recordPath, 'c+b'); flock($lock, LOCK_EX);
$begin = microtime(true); incident_reject(static fn() => $store->transaction($id, static fn(array $r): array => $r), 'Contended record lock bypassed.');
incident_check(microtime(true) - $begin < .7, 'Record lock wait was unbounded.'); flock($lock, LOCK_UN); fclose($lock);
// Unknown IDs must not create unbounded lock files.
$beforeFiles = count(scandir($root . '/main'));
incident_reject(static fn() => $store->transaction('inc_' . str_repeat('f', 32), static fn(array $r): array => $r), 'Missing incident transaction accepted.');
incident_check(count(scandir($root . '/main')) === $beforeFiles, 'Unknown incident created orphan lock files.');
// Submission may finish before its incident result write. Preserve uncertainty; never replay.
class IncidentResultFailureStore extends IncidentStore
{
    public int $writes = 0;
    protected function replace(string $source, string $destination): bool { if (++$this->writes === 2) { return false; } return parent::replace($source, $destination); }
}
$resultStore = new IncidentResultFailureStore($root . '/result-failure'); $resultService = $make($resultStore); $lostInput = array_replace($start, ['request_id' => $request(60)]); $before = count($sent);
incident_reject(static fn() => $resultService->start($lostInput, $actor), 'Post-submission storage failure was hidden.');
$lost = $resultService->start($lostInput, $actor);
incident_check(count($sent) === $before + 1 && $lost['operations'][$request(60)]['state'] === 'submitting', 'Interrupted result was automatically replayed.');
$now += 121; $resultService->process();
incident_check($resultService->get($lost['id'])['operations'][$request(60)]['state'] === 'uncertain' && count($sent) === $before + 1, 'Interrupted claim did not become explicit uncertainty.');
// A directory sync failure may have replaced the record: retrying the same key must still not submit.
class IncidentDirectoryFailureStore extends IncidentStore { protected function syncDirectory(): bool { return false; } }
$directoryStore = $make(new IncidentDirectoryFailureStore($root . '/directory-failure')); $directoryInput = array_replace($start, ['request_id' => $request(61)]); $before = count($sent);
incident_reject(static fn() => $directoryStore->start($directoryInput, $actor), 'Directory synchronization failure hidden.');
$directoryStore->start($directoryInput, $actor);
incident_check(count($sent) === $before, 'Ambiguous claim durability permitted submission/replay.');
// Same initial request submitted by two real processes creates one announcement.
if (function_exists('pcntl_fork')) {
    $forkStorePath = $root . '/fork'; new IncidentStore($forkStorePath); $sentPath = $root . '/fork-sent'; file_put_contents($sentPath, ''); $children = [];
    foreach ([1, 2] as $child) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $svc = new IncidentService(new IncidentStore($forkStorePath), static fn() => $template, static fn(array $d): array => $d,
                static function () use ($sentPath): array { usleep(100000); file_put_contents($sentPath, "sent\n", FILE_APPEND | LOCK_EX); return ['success' => true, 'job_id' => 'job_' . str_repeat('b', 32)]; }, static fn(): array => []);
            try { $svc->start(array_replace($start, ['request_id' => $request(70)]), $actor); $code = 0; } catch (Throwable $error) { $code = 1; }
            // The parent owns fixture cleanup; children must not run its registered shutdown function.
            posix_kill(getmypid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
    incident_check(file_get_contents($sentPath) === "sent\n", 'Concurrent initial requests did not produce exactly one send.');
}

// Real module facade freezes identity fingerprints, excludes secrets, and scopes trusted context to one call.
class IncidentModuleFixture
{
    use \FreePBX\modules\SlsIncidents;
    private function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    const INCIDENT_JOB_CONTRACT = 1;
    public array $settings = ['desktop_clients' => [['enabled' => true, 'username' => 'fixture.desktop', 'client_id' => 'cli_original', 'password' => 'PRIVATE_FIXTURE']],
        'announcement_webhooks' => [['id' => 'hook_1', 'enabled' => true, 'url' => 'https://example.invalid/PRIVATE_TOKEN']]];
    public array $voice = []; public array $calls = []; public bool $throw = false;
    private function getActiveSettings(): array { return $this->settings; }
    public function getAnnouncementGroups(): array { return [['id' => 'grp_1', 'extensions' => ['1000'], 'desktop_clients' => ['fixture.desktop']]]; }
    public function getConfiguredPjsipExtensionNumbers(): array { return ['1000', '2000']; }
    private function getDesktopClients(array $settings): array { return $settings['desktop_clients']; }
    private function outboundVoiceTargets(array $settings): array { return $this->voice; }
    private function outboundVoiceFingerprint(array $row): string { return hash('sha256', json_encode($row)); }
    private function normalizeWebhookDestinations(array $rows, string $type): array { return $rows; }
    private function webhookDestinationFingerprint(array $row): string { return hash('sha256', $row['url']); }
    public function getAvailableTones(): array { return []; }
    public function freeze(array $delivery): array { return $this->freezeIncidentDelivery($delivery); }
    public function submit(array $delivery, array $context): array { return $this->submitIncidentAnnouncement($delivery, 'Fixture', 'Fixture', $context, ['identity' => 'Tester', 'source' => 'fixture']); }
    public function current(): ?array { return $this->currentIncidentAnnouncementContext(); }
    public function sendSipNotifyAnnouncement(...$arguments): array {
        $this->calls[] = ['context' => $this->currentIncidentAnnouncementContext(), 'arguments' => $arguments];
        if ($this->throw) { throw new RuntimeException('Fixture send failure'); }
        return ['success' => true, 'job_id' => 'job_' . str_repeat('c', 32)];
    }
}
$module = new IncidentModuleFixture(); $frozen = $module->freeze(['group_ids' => ['grp_1'], 'webhook_ids' => ['hook_1']]);
incident_check($frozen['group_ids'] === [] && $frozen['extensions'] === ['1000'], 'Facade did not freeze group membership.');
incident_check(strpos(json_encode($frozen), 'PRIVATE') === false && isset($frozen['_identities']['webhook_ids']['hook_1']), 'Frozen incident leaked secrets or omitted destination identity.');
$module->submit($frozen, $context);
incident_check(count($module->calls) === 1 && $module->calls[0]['context'] === $context && $module->current() === null, 'Trusted context did not stay inside its announcement call.');
$module->throw = true; incident_reject(static fn() => $module->submit($frozen, $context), 'Expected fixture failure absent.');
incident_check($module->current() === null, 'Exception leaked incident context into a later send.'); $module->throw = false;
$before = count($module->calls); $module->settings['desktop_clients'][0]['client_id'] = 'cli_replacement';
$result = $module->submit($frozen, $context);
incident_check(!$result['success'] && $result['delivery_started'] === false && count($module->calls) === $before, 'Reused desktop username changed the frozen recipient.');
$module->settings['desktop_clients'][0]['client_id'] = 'cli_original'; $module->settings['announcement_webhooks'][0]['url'] = 'https://example.invalid/REPLACEMENT';
incident_check(!$module->submit($frozen, $context)['success'] && count($module->calls) === $before, 'Reused webhook ID redirected an incident update.');

// Selected reviewed wording remains immutable, including scheduled drills.
$latestTemplate=$template; $before=count($sent);
$translated=$service->start(array_replace($start,['request_id'=>$request(777),'language_variant'=>'es-mx']),$actor);
incident_check(count($sent)===$before+1 && end($sent)['message']==='Salga de North wing.' && $translated['language_variant']==='es-mx','Selected wording did not reach its immutable job.');
incident_reject(static fn()=>$service->start(array_replace($start,['request_id'=>$request(777),'language_variant'=>'']),$actor),'Reused request identifier changed language.');
$planInput=array_replace($start,['request_id'=>$request(778),'language_variant'=>'es-mx','is_test'=>true,'planned_at'=>gmdate('c',$now+60)]);
$translatedPlan=$service->start($planInput,$actor);$latestTemplate['language_variants'][0]['message']='Changed {{location}}';
$now+=60;$before=count($sent);$service->process();
$plannedRecord=$service->get($translatedPlan['id']);
incident_check($plannedRecord['message']==='Salga de North wing.' && $plannedRecord['language_variant']==='es-mx','Planned drill adopted a later translation.');
$lastOp=end($plannedRecord['operations']);incident_check($lastOp['message']==='DRILL / TEST. Salga de North wing.','Selected drill lost its explicit test marker.');
$latestTemplate=$template;

// Render real view with escaped fixture values. No production bootstrap or network.
if (!function_exists('load_view')) { function load_view(string $path, array $vars = []): string { extract($vars, EXTR_SKIP); ob_start(); include $path; return ob_get_clean(); } }
$html = load_view(dirname(__DIR__) . '/slsmassnotifyserver/views/incidents.php', ['templates' => [array_replace($template, ['name' => '<script>bad()</script>'])], 'active_templates' => [$template],
    'choices' => ['extensions' => [['id' => '1000', 'name' => 'Fixture phone']], 'desktop_clients' => [['id' => 'fixture.desktop', 'name' => 'Fixture desktop']]],
    'tones' => [], 'listing' => ['success' => true, 'incidents' => []], 'csrf_token' => 'fixture', 'hero_image' => 'fixture.png']);
incident_check(strpos($html, '<script>bad()</script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Template name injected HTML.');
$resourceHtml=load_view(dirname(__DIR__).'/slsmassnotifyserver/views/incident_resources.php',[
 'incident'=>['template'=>['resources'=>[array_replace($template['resources'][0],['label'=>'<img src=x onerror=bad()>'])]]],
 'e'=>static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
incident_check(strpos($resourceHtml,'<img src=x')===false && strpos($resourceHtml,'&lt;img')!==false,'Resource label injected HTML.');
incident_check(strpos($resourceHtml,'rel="noopener noreferrer"')!==false && strpos($resourceHtml,'referrerpolicy="no-referrer"')!==false,'Resource navigation leaked incident referer or opener.');
$unsafeResourceHtml=load_view(dirname(__DIR__).'/slsmassnotifyserver/views/incident_resources.php',[
 'incident'=>['template'=>['resources'=>[array_replace($template['resources'][0],['url'=>'javascript:bad()'])]]],
 'e'=>static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
incident_check(strpos($unsafeResourceHtml,'href=')===false,'Tampered resource produced an unsafe link.');
incident_check(strpos($html, 'role="dialog"') !== false && strpos($html, 'aria-modal="true"') !== false, 'Editor accessibility hooks missing.');
incident_check(strpos($html, 'crypto.getRandomValues') !== false && strpos($html, 'request_id:request') !== false, 'UI lost stable request identifiers.');
preg_match_all('/<script>(.*?)<\/script>/s', $html, $scripts);
foreach ($scripts[1] as $index => $source) {
    $script = $root . '/view-' . $index . '.js'; file_put_contents($script, $source);
    exec('/usr/bin/node --check ' . escapeshellarg($script) . ' 2>&1', $output, $exit); incident_check($exit === 0, 'Incident UI JavaScript failed syntax check.');
}
echo "Incident configuration, immutable jobs, idempotency, audience snapshots, human authorization, all-clear, drills, escalation, durable storage and escaped UI fixtures passed.\n";
