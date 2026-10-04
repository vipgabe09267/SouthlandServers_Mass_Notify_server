<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/IncidentService.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentService;
use SLS\MassNotify\IncidentStore;
$checks = 0;
function ladder_check(bool $ok, string $message): void { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($message); } }
function ladder_reject(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException $error) { ladder_check($error->getMessage() !== '', 'Missing actionable validation message.'); return; }
    throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/sls-ladder-' . bin2hex(random_bytes(10)); mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    } rmdir($root);
});
$request = static fn(int $n): string => str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
$actor = ['identity' => 'Fixture operator', 'source' => 'test'];
$step = static fn(int $delay, string $target): array => ['name' => 'Follow-up ' . $target, 'after_seconds' => $delay, 'delivery' => ['desktop_clients' => [$target]]];
$base = ['id' => 'tpl_' . str_repeat('a', 24), 'name' => 'Fixture ladder', 'title' => 'Fixture incident', 'message' => 'Fixture instructions',
    'delivery' => ['desktop_clients' => ['person.desktop']], 'roster' => [['id' => 'person', 'name' => 'Fixture person', 'desktop_username' => 'person.desktop']]];
$steps = [$step(60, 'supervisor.one'), $step(120, 'supervisor.two'), $step(180, 'supervisor.three')];
$template = IncidentConfig::template($base + ['escalation' => ['enabled' => true, 'steps' => $steps]]);
ladder_check(count($template['escalation']['steps']) === 3 && $template['escalation']['delivery'] === $template['escalation']['steps'][0]['delivery'], 'Normalized ladder lost steps or first-step compatibility.');
ladder_check(IncidentConfig::template($template) === $template, 'Normalization was not idempotent.');
foreach ([['enabled' => true, 'steps' => []], ['enabled' => true, 'steps' => array_fill(0, 6, $step(60, 'x'))],
    ['enabled' => true, 'steps' => [$step(59, 'x')]], ['enabled' => true, 'steps' => [$step(86401, 'x')]],
    ['enabled' => true, 'steps' => [$step(120, 'x'), $step(60, 'y')]], ['enabled' => true, 'steps' => [$step(60, 'x'), $step(60, 'y')]],
    ['enabled' => true, 'steps' => [['name' => 'Empty', 'after_seconds' => 60, 'delivery' => []]]],
    ['enabled' => true, 'steps' => [$step(60, 'x')], 'after_seconds' => 61],
    ['enabled' => true, 'steps' => [$step(60, 'x')], 'delivery' => ['desktop_clients' => ['hidden']]],
    ['enabled' => true, 'steps' => [array_replace($step(60, 'x'), ['after_seconds' => '60'])]],
    ['enabled' => true, 'steps' => [array_replace($step(60, 'x'), ['name' => ''])]],
    ['enabled' => 'yes', 'steps' => [$step(60, 'x')]]] as $policy) {
    ladder_reject(static fn() => IncidentConfig::template($base + ['escalation' => $policy]), 'Invalid or ambiguous ladder accepted.');
}
ladder_reject(static fn() => IncidentConfig::template(array_replace($base, ['roster' => [], 'escalation' => ['enabled' => true, 'steps' => $steps]])), 'Enabled ladder without a roster accepted.');
$legacy = IncidentConfig::template($base + ['escalation' => ['enabled' => true, 'after_seconds' => 60, 'delivery' => ['desktop_clients' => ['legacy.supervisor']]]]);
ladder_check(count($legacy['escalation']['steps']) === 1 && $legacy['escalation']['steps'][0]['after_seconds'] === 60, 'Legacy one-step policy did not retain its timing.');
$now = 1800000000; $sent = []; $frozen = []; $mode = 'ok';
$store = new IncidentStore($root . '/records');
$service = new IncidentService($store, static function ($id) use (&$template) { return $template; },
    static function ($delivery) use (&$frozen) { $frozen[] = $delivery; return $delivery + ['_identities' => ['fixture' => hash('sha256', json_encode($delivery))]]; },
    static function ($delivery, $message, $title, $context, $origin) use (&$sent, &$mode) {
        if ($mode === 'uncertain') { throw new RuntimeException('Fixture transport interruption.'); }
        if ($mode === 'denied') { return ['success' => false, 'delivery_started' => false, 'message' => 'Fixture current grant revoked.']; }
        $sent[] = compact('delivery', 'message', 'context', 'origin');
        return ['success' => true, 'job_id' => 'job_' . str_pad(dechex(count($sent)), 32, '0', STR_PAD_LEFT)];
    }, static fn($id) => ['success' => true], static function () use (&$now) { return $now; });
$start = static fn(int $id) => ['template_id' => $base['id'], 'request_id' => $request($id), 'fields' => [], 'is_test' => true];
$record = $service->start($start(1), $actor); $opened = $now;
ladder_check(count($frozen) === 4, 'Not every step was frozen before initial delivery.');
$template['escalation']['steps'][1]['delivery']['desktop_clients'][] = 'newly.added';
$template['escalation']['steps'][1]['name'] = 'Changed after send';
ladder_check($store->metadata($record['id'])['due_at'] === $opened + 60, 'First due metadata is inaccurate.');
$now += 59; $service->process(); ladder_check(count($sent) === 1, 'Early follow-up sent.');
$now++; $service->process(); $service->process();
ladder_check(count($sent) === 2 && end($sent)['delivery']['desktop_clients'] === ['supervisor.one'], 'First follow-up misrouted or duplicated.');
ladder_check($store->metadata($record['id'])['due_at'] === $opened + 120, 'Next level disappeared from the worker index.');
$now += 60; $service->process(); $service->process();
ladder_check(count($sent) === 3 && end($sent)['delivery']['desktop_clients'] === ['supervisor.two'] && str_starts_with(end($sent)['message'], 'DRILL / TEST. '), 'Second step changed its frozen audience or drill marking.');
$snapshot = $store->read($record['id']); $ops = array_values(array_filter($snapshot['operations'], static fn($op) => $op['kind'] === 'escalation'));
ladder_check($ops[1]['escalation_step'] === 2 && $ops[1]['escalation_name'] === 'Follow-up supervisor.two' && $ops[0]['request_id'] !== $ops[1]['request_id'], 'Step identity/provenance is inaccurate.');
$service->respond($record['id'], ['request_id' => $request(2), 'person_id' => 'person', 'response' => 'safe'], $actor);
ladder_check($store->metadata($record['id'])['due_at'] === null, 'Accounted-for roster still has scheduled follow-ups.');
$now += 60; $service->process(); ladder_check(count($sent) === 3, 'Follow-up sent after everyone was accounted for.');
$service->respond($record['id'], ['request_id' => $request(3), 'person_id' => 'person', 'response' => 'needs_assistance'], $actor);
$service->process(); ladder_check(count($sent) === 4 && end($sent)['delivery']['desktop_clients'] === ['supervisor.three'], 'New assistance requirement did not resume the remaining ladder.');
ladder_check($store->metadata($record['id'])['due_at'] === null, 'Exhausted ladder was left perpetually due.');
$template = IncidentConfig::template($base + ['escalation' => ['enabled' => true, 'steps' => $steps]]);
// A worker outage must not dispatch multiple already-due levels back to back.
$late = $service->start($start(10), $actor); $before = count($sent); $now += 1000;
$service->process(); $service->process(); ladder_check(count($sent) === $before + 1, 'Late worker burst several escalation levels.');
ladder_check($store->metadata($late['id'])['due_at'] === $now + 60, 'Late worker discarded the interval between follow-ups.');
$now += 60; $service->process(); ladder_check(count($sent) === $before + 2, 'Spaced follow-up never became due.');
$service->update($late['id'], ['request_id' => $request(11), 'kind' => 'all_clear', 'message' => 'Fixture all clear'], $actor);
$before = count($sent); $now += 200; $service->process(); ladder_check(count($sent) === $before && $store->metadata($late['id'])['due_at'] === null, 'All-clear did not stop unsent follow-ups.');
// An unknown outcome stops the whole ladder; known pre-send rejection does not
// replay that step and can progress to a separately configured next audience.
$uncertain = $service->start($start(20), $actor); $now += 60; $mode = 'uncertain'; $service->process();
$mode = 'ok'; $before = count($sent); $now += 1000; $service->process();
ladder_check(count($sent) === $before && $store->metadata($uncertain['id'])['due_at'] === null, 'Uncertain escalation was replayed or another level ran automatically.');
$denied = $service->start($start(30), $actor); $now += 60; $mode = 'denied'; $service->process();
$mode = 'ok'; $before = count($sent); $service->process(); ladder_check(count($sent) === $before, 'Known rejected step retried.');
$now += 60; $service->process(); ladder_check(count($sent) === $before + 1 && end($sent)['delivery']['desktop_clients'] === ['supervisor.two'], 'Separate next level did not progress after definite non-submission.');
$service->update($denied['id'], ['request_id' => $request(31), 'kind' => 'all_clear', 'message' => 'Fixture all clear'], $actor);
// Existing frozen histories and old first-step request IDs remain compatible.
$template = $legacy; $old = $service->start($start(40), $actor);
$store->transaction($old['id'], static function ($r) { unset($r['template']['escalation']['steps']); return $r; });
$now += 60; $before = count($sent); $service->process();
$oldState = $store->read($old['id']); $oldRequest = substr(hash('sha256', $old['id'] . ':supervisor'), 0, 32);
ladder_check(count($sent) === $before + 1 && isset($oldState['operations'][$oldRequest]), 'Legacy request identity or audience changed.');
$store->transaction($old['id'], static function ($r) { foreach ($r['operations'] as &$op) { if ($op['kind'] === 'escalation') unset($op['escalation_step'], $op['escalation_name']); } unset($op); return $r; });
$before = count($sent); $service->process(); ladder_check(count($sent) === $before, 'Legacy completed escalation repeated.');
// The model-language guard must inspect later steps, not only the compatibility copy.
$template = IncidentConfig::template($base + ['escalation' => ['enabled' => true, 'steps' => $steps], 'language_variants' => [
    ['locale' => 'es', 'label' => 'Spanish fixture', 'title' => 'Aviso', 'message' => 'Texto de prueba', 'review_note' => 'Fixture only', 'reviewed' => true]]]);
$template['escalation']['steps'][2]['delivery']['audio_mode'] = 'tts';
ladder_check(IncidentConfig::render($template, [], 'es')['message'] === 'Texto de prueba', 'A supported language lost its reviewed wording.');
$template['language_variants'][0]['locale'] = 'ja';
ladder_reject(static fn() => IncidentConfig::render($template, [], 'ja'), 'Unsupported voice guard missed a later escalation step.');
// Two real processes compete for the same next step using durable store locks.
$template = IncidentConfig::template($base + ['escalation' => ['enabled' => true, 'steps' => $steps]]);
$race = $service->start($start(50), $actor); $now += 60;
$worker = $root . '/race.php'; $capture = $root . '/sends.jsonl';
file_put_contents($worker, '<?php require ' . var_export(dirname(__DIR__) . '/slsmassnotifyserver/IncidentService.php', true) . ';
 $store=new \\SLS\\MassNotify\\IncidentStore($argv[1]);
 $service=new \\SLS\\MassNotify\\IncidentService($store,fn()=>[],fn($d)=>$d,function($d,$m,$t,$c){usleep(100000);file_put_contents($GLOBALS["argv"][3],json_encode($c)."\\n",FILE_APPEND|LOCK_EX);return ["success"=>true,"job_id"=>"job_".str_repeat("f",32)];},fn()=>[],fn()=>(int)$GLOBALS["argv"][2]);
 $r=$service->process();exit($r["success"]?0:1);');
$workers = [];
for ($i = 0; $i < 2; $i++) { $workers[] = proc_open([PHP_BINARY, $worker, $root . '/records', (string)$now, $capture], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $root . '/race-errors', 'a']], $pipes); }
foreach ($workers as $process) { ladder_check(proc_close($process) === 0, 'Concurrent worker failed.'); }
$captured = array_filter(explode("\n", file_get_contents($capture)));
ladder_check(count($captured) === 1 && json_decode(reset($captured), true)['incident_id'] === $race['id'], 'Concurrent workers duplicated or misrouted an escalation.');
echo "Escalation ladder: $checks timing, immutable audience, drill, response, uncertainty, legacy and concurrency checks passed.\n";
