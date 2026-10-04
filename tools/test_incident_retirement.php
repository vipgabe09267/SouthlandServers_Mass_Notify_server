<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/IncidentService.php';
use SLS\MassNotify\IncidentConfig;
use SLS\MassNotify\IncidentStore;
use SLS\MassNotify\IncidentService;
function retirement_check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function retirement_reject(callable $work): void { try { $work(); } catch (InvalidArgumentException | RuntimeException $expected) { return; } throw new LogicException('An unsafe retirement operation succeeded.'); }
$root = sys_get_temp_dir() . '/sls-incident-retirement-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
$parentPid = getmypid();
register_shutdown_function(static function () use ($root, $parentPid): void {
    if (getmypid() !== $parentPid) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});
$now = 1800000000; $sent = 0; $directory = $root . '/records'; $store = new IncidentStore($directory);
$template = IncidentConfig::template(['id'=>'tpl_' . str_repeat('a', 24), 'name'=>'Isolated fixture', 'title'=>'Fixture drill', 'message'=>'Fixture only',
    'delivery'=>['desktop_clients'=>['fixture.desktop']], 'checklist'=>['Fixture observation']]);
$actor = ['identity'=>'Fixture', 'source'=>'test'];
$makeService = static function (IncidentStore $store) use (&$now, &$sent, $template): IncidentService {
    return new IncidentService($store, static fn()=>$template, static fn(array $d):array=>$d,
        static function () use (&$sent):array { $sent++; return ['success'=>true, 'job_id'=>'job_' . str_pad(dechex($sent), 32, '0', STR_PAD_LEFT)]; },
        static fn(string $id):array=>['success'=>true,'job_id'=>$id,'state'=>'completed'], static function () use (&$now):int { return $now; });
};
$service = $makeService($store); $policy = ['enabled'=>true, 'after_days'=>1, 'max_archive_mib'=>64];
retirement_check(IncidentConfig::normalize([]) === IncidentConfig::defaults(), 'Legacy settings changed.');
retirement_check(IncidentConfig::retention([])['enabled'] === false, 'Archival enabled itself.');
foreach ([['enabled'=>'true'], ['after_days'=>0], ['after_days'=>1.5], ['after_days'=>3651], ['max_archive_mib'=>63], ['max_archive_mib'=>4097], ['delete'=>true]] as $bad) {
    retirement_reject(static fn()=>IncidentConfig::retention($bad));
}
$requests = [];
for ($i = 0; $i < 13; $i++) {
    $request = ['template_id'=>$template['id'], 'request_id'=>str_pad(dechex($i+1), 32, '0', STR_PAD_LEFT), 'is_test'=>true];
    $created = $service->start($request, $actor); $requests[$created['id']] = $request;
    $service->update($created['id'], ['request_id'=>str_pad(dechex($i+100), 32, '0', STR_PAD_LEFT), 'kind'=>'all_clear', 'message'=>'Fixture closed'], $actor);
}
$before = $sent; $now += 86401;
retirement_check($store->retire([], $now)['retired'] === 0 && !file_exists($directory . '/archive'), 'Disabled cleanup wrote storage.');
$first = $store->retire($policy, $now);
retirement_check($first['retired'] === 10 && !$first['errors'] && $sent === $before, 'Retirement exceeded its batch bound or sent an alert.');
$second = $store->retire($policy, $now);
retirement_check($second['retired'] === 3 && !$second['errors'] && !$store->ids(), 'Remaining closed records were not archived.');
$seen = []; $cursor = '';
do {
    $page = $service->listing(4, $cursor, true); retirement_check($page['total'] === 13, 'Archived count changed.');
    foreach ($page['incidents'] as $row) { retirement_check(!isset($seen[$row['id']]) && $row['archived'], 'Archived pagination repeated a record.'); $seen[$row['id']] = true; }
    $cursor = $page['next_cursor'];
    if ($cursor !== '') { retirement_reject(static fn()=>$service->listing(4, $cursor, false)); }
} while ($cursor !== '');
retirement_check(count($seen) === 13 && $service->listing()['total'] === 0, 'Working and archived views were mixed.');
$id = array_key_first($requests);
$reopened = $service->start($requests[$id], $actor);
retirement_check($reopened['id'] === $id && $reopened['archived'] && $sent === $before, 'An archived start replayed its delivery.');
$old = $store->read($id); $report = $service->report($id);
retirement_check($report['complete'] && count($report['incident']['operations']) === 2 && $report['incident']['timeline'] === $old['timeline'], 'Archival discarded delivery or timeline records.');
retirement_check($service->report($id, 0, $report['revision'])['revision'] === $report['revision'], 'Archived report revisions drifted.');
retirement_reject(static fn()=>$service->start(array_replace($requests[$id], ['is_test'=>false]), $actor));
retirement_reject(static fn()=>$service->checklist($id, ['request_id'=>str_repeat('b',32), 'item'=>0, 'complete'=>true, 'note'=>''], $actor));
retirement_check($store->transaction($id, static fn(array $r):array=>$r) === $old, 'Archived no-op retry was not idempotent.');
retirement_check(!file_exists($directory . '/' . $id . '.lock'), 'Archived retries accumulated working locks.');
$open = $service->start(['template_id'=>$template['id'],'request_id'=>str_repeat('c',32),'is_test'=>true], $actor);
retirement_check($store->retire($policy, $now + 86401)['retired'] === 0 && $store->read($open['id'])['state'] === 'open', 'Open incident was retired.');
$store->transaction($open['id'], static function (array $r):array { $r['state']='closed'; $r['updated_at']=gmdate('c', 1800000000); $r['operations'][array_key_first($r['operations'])]['state']='uncertain'; return $r; });
retirement_check($store->retire($policy, $now)['retired'] === 0, 'Uncertain submission was retired.');

// Kill an actual writer after SQLite commit and before removing the source.
// Restart must compare the two copies and finish without another send.
class InterruptedIncidentRetirement extends IncidentStore
{
    protected function archiveCommitted(string $id): void { posix_kill(getmypid(), SIGKILL); }
}
$crashRequest = ['template_id'=>$template['id'],'request_id'=>str_repeat('d',32),'is_test'=>true];
$crash = $service->start($crashRequest, $actor);
$service->update($crash['id'], ['request_id'=>str_repeat('e',32),'kind'=>'all_clear','message'=>'Closed fixture'], $actor);
$now += 86401; $before = $sent;
$child = pcntl_fork();
if ($child === 0) { (new InterruptedIncidentRetirement($directory))->retire($policy, $now); exit(2); }
pcntl_waitpid($child, $status);
retirement_check(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'Crash boundary did not kill the writer.');
retirement_check(file_exists($directory . '/' . $crash['id'] . '.json'), 'Crash discarded the active copy.');
$store = new IncidentStore($directory); $service = $makeService($store);
retirement_check($store->retire($policy, $now)['retired'] === 1 && $service->start($crashRequest, $actor)['archived'] && $sent === $before, 'Post-crash retirement replayed or lost the record.');

// Kill after active-file removal but before derived lock/cache cleanup.
// A later pass must reclaim those orphans without deleting replay evidence.
class InterruptedIncidentCleanup extends IncidentStore
{
    protected function activeCopyRetired(string $id): void { posix_kill(getmypid(), SIGKILL); }
}
$cleanupRequest = ['template_id'=>$template['id'],'request_id'=>str_repeat('4',32),'is_test'=>true];
$cleanup = $service->start($cleanupRequest, $actor);
$service->update($cleanup['id'], ['request_id'=>str_repeat('5',32),'kind'=>'all_clear','message'=>'Closed fixture'], $actor); $now += 86401;
$cleanupBefore = $sent;
$child = pcntl_fork(); if ($child === 0) { (new InterruptedIncidentCleanup($directory))->retire($policy, $now); exit(2); } pcntl_waitpid($child, $status);
retirement_check(pcntl_wifsignaled($status) && !file_exists($directory . '/' . $cleanup['id'] . '.json') && file_exists($directory . '/' . $cleanup['id'] . '.lock'), 'Cleanup crash boundary was not exercised.');
$store = new IncidentStore($directory); $service = $makeService($store);
$cleaned = $store->retire($policy, $now);
retirement_check($cleaned['orphan_pairs_removed'] === 1 && !$cleaned['errors'] && !file_exists($directory . '/' . $cleanup['id'] . '.lock'), 'Interrupted retirement left orphan locks permanently.');
retirement_check($service->start($cleanupRequest, $actor)['archived'] && $sent === $cleanupBefore, 'Orphan cleanup discarded replay evidence.');

// A mismatched duplicate must preserve the active report, even if a prior
// archive commit completed before process failure.
$mismatch = $service->start(['template_id'=>$template['id'],'request_id'=>str_repeat('f',32),'is_test'=>true], $actor);
$service->update($mismatch['id'], ['request_id'=>str_repeat('1',32),'kind'=>'all_clear','message'=>'Closed'], $actor); $now += 86401;
$child = pcntl_fork(); if ($child === 0) { (new InterruptedIncidentRetirement($directory))->retire($policy, $now); exit(2); } pcntl_waitpid($child, $status);
$store->transaction($mismatch['id'], static function (array $r):array { $r['title']='Changed after interruption'; return $r; });
$failed = $store->retire($policy, $now);
retirement_check(count($failed['errors']) === 1 && file_exists($directory . '/' . $mismatch['id'] . '.json'), 'Conflicting copies were silently discarded.');

// Bound the database; no archive deletion or partial working-list retirement.
$dbPath = $directory . '/archive/reports.sqlite';
$db = new PDO('sqlite:' . $dbPath); $db->exec("CREATE TABLE fixture_padding (body BLOB); INSERT INTO fixture_padding VALUES (zeroblob(67108864));");
$limited = $service->start(['template_id'=>$template['id'],'request_id'=>str_repeat('2',32),'is_test'=>true], $actor);
$service->update($limited['id'], ['request_id'=>str_repeat('3',32),'kind'=>'all_clear','message'=>'Closed'], $actor); $now += 86401;
// Resolve only the conflicting disposable source by restoring its original
// immutable bytes, so capacity testing reaches the newly eligible report.
$archived = (new \SLS\MassNotify\IncidentArchive($directory . '/archive'))->read($mismatch['id']); unset($archived['archived'],$archived['archived_at']);
$store->transaction($mismatch['id'], static fn(array $r):array=>$archived);
$limitedResult = $store->retire($policy, $now);
retirement_check($limitedResult['errors'] && str_contains($limitedResult['errors'][0]['message'], 'full') && file_exists($directory . '/' . $limited['id'] . '.json'), 'Full archive lost active evidence.');
$db->exec('DROP TABLE fixture_padding; VACUUM'); $db = null;

// Permissions, links, missing stores and corrupt bodies fail closed.
$marker = $directory . '/.archive-required'; rename($marker, $marker . '.saved');
retirement_reject(static fn()=>(new IncidentStore($directory))->read('inc_' . str_repeat('0',32))); rename($marker . '.saved', $marker);
rename($dbPath, $dbPath . '.saved'); retirement_reject(static fn()=>(new IncidentStore($directory))->read($id)); rename($dbPath . '.saved', $dbPath);
chmod($dbPath, 0644); retirement_reject(static fn()=>(new IncidentStore($directory))->read($id)); chmod($dbPath, 0600);
$db = new PDO('sqlite:' . $dbPath); $q=$db->prepare('UPDATE reports SET body=? WHERE id=?'); $q->execute(['damaged', $id]); $db=null;
retirement_reject(static fn()=>(new IncidentStore($directory))->read($id));
// Real recovery helper + IncidentStore integration blocks restored IDs while
// leaving historical workflows in the private evidence archive only.
$recoveryRoot = $root . '/recovery-data'; mkdir($recoveryRoot, 0700);
$backup = $root . '/operational.jsonl';
$backupHelper = dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify/sls_operational_backup.py';
$guardId = 'inc_' . str_repeat('a', 32);
$guard = json_encode(['schema'=>1,'ids'=>[$guardId]], JSON_THROW_ON_ERROR);
file_put_contents($root . '/.incident-replay-blocked.json', $guard); chmod($root . '/.incident-replay-blocked.json', 0600);
file_put_contents($root . '/.incident-replay-required', "SLS_RESTORED_INCIDENT_REPLAY_V1\n"); chmod($root . '/.incident-replay-required', 0600);
$unknownStore = new IncidentStore($root . '/new-records');
retirement_reject(static fn()=>$unknownStore->transaction($guardId, static fn() => ['id'=>$guardId], true));
retirement_check($unknownStore->read('inc_' . str_repeat('b',32)) === null, 'Restored replay history blocked a genuinely new identity.');
rename($root . '/.incident-replay-blocked.json', $root . '/guard.saved');
retirement_reject(static fn()=>$unknownStore->read('inc_' . str_repeat('b',32)));
rename($root . '/guard.saved', $root . '/.incident-replay-blocked.json');
$recoveryBefore = $sent;
$commands = [[$backupHelper, 'snapshot', '--data', $root, '--file', $backup], [$backupHelper, 'archive', '--data', $recoveryRoot, '--file', $backup]];
foreach ($commands as $args) {
    $process = proc_open(array_merge(['/usr/bin/python3', '-I'], $args), [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    retirement_check(proc_close($process) === 0 && !empty(json_decode($output,true)['ok']) && $errors === '', 'Actual recovery helper failed: ' . $errors);
}
$recoveredStore = new IncidentStore($recoveryRoot . '/incidents');
retirement_reject(static fn()=>$recoveredStore->read($guardId));
retirement_check($recoveredStore->ids() === [] && $sent === $recoveryBefore, 'Recovery loaded historical workflows or sent an alert.');
echo "Incident archival: bounded batches/pagination, immutable full reports, duplicate replay protection, actual SIGKILL/restart, conflicting-copy preservation, capacity and damaged/missing-storage checks passed.\n";
