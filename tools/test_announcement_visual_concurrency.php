<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php';

function checkVisual($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

class VisualPoolFixture {
    use \FreePBX\modules\SlsAnnouncementDelivery {
        startAnnouncementPhoneVisualProcess as private startRealVisualProcess;
    }
    public $directory;
    public $limits = ['concurrency' => 2, 'child_seconds' => 1, 'phase_seconds' => 2];
    public $durations = [];
    public $currentTargets = ['phones' => ['1001', '1002', '1003', '1004'], 'desktops' => ['desktop'], 'webhooks' => ['hook']];
    public function __construct(string $directory) { $this->directory = $directory; }
    protected function announcementPhoneVisualLimits(): array { return $this->limits; }
    protected function startAnnouncementPhoneVisualProcess(string $command, float $seconds) {
        if ($command === 'simulate_start_failure') { return false; }
        return $this->startRealVisualProcess($command, $seconds);
    }
    public function batch(array $commands, callable $completed, ?int $deadline=null): void { $this->executeAnnouncementPhoneVisualBatch($commands, $completed, $deadline); }
    public function command(string $target, float $delay, array $extra = []): string {
        $args = array_merge(['target' => $target, 'delay' => $delay, 'log' => $this->directory . '/events.jsonl'], $extra);
        return '/usr/bin/timeout --foreground --signal=TERM --kill-after=1 90 /usr/bin/python3 -I '
            . escapeshellarg($this->directory . '/child.py') . ' ' . escapeshellarg(json_encode($args));
    }
    private function currentAnnouncementDestinationIds() { return $this->currentTargets; }
    private function startAnnouncementWorker($id) {}
    private function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30) { return null; }
    private function releaseNativeBackupFileLock($lock) {}
    private function announcementJobDirectory() { return $this->directory . '/jobs'; }
    protected function readAnnouncementPhoneOutcomes($correlation) { return ['available' => false, 'active' => false, 'uncertain' => true, 'targets' => [], 'truncated' => false, 'playback_confirmed' => false]; }
    private function sanitizeScheduleText($text, $limit, $single) { return substr($text, 0, $limit); }
    private function buildAnnouncementVisualPushCommand($message, $targets, $timeout, $options) {
        if ($options['mode'] === 'api_only') { return 'fixture_desktop'; }
        checkVisual(($options['managed_deadline'] ?? false) === true, 'Phone pool did not request a managed child process group.');
        $target = (string)$targets[0];
        return $this->command($target, $this->durations[$target] ?? 0.02,
            ['job_path' => $this->directory . '/jobs/job_' . str_repeat('a', 32) . '.json']);
    }
    private function executeAnnouncementVisualPushCommand($command) {
        checkVisual($command === 'fixture_desktop', 'Phone command escaped the bounded pool.');
        return ['success' => true];
    }
    private function sendAnnouncementTtsAudio($phones, $message, $context) {
        $this->lastAudioQueueResults = array_fill_keys($phones, true);
        return ['success' => true, 'delivery_started' => false];
    }
    private function dispatchAnnouncementWebhooks(...$args) { return ['accepted' => $args[0] ? [['id' => 'hook', 'name' => 'Fixture']] : [], 'failed' => []]; }
    private function setAnnouncementCooldown() {}
    private function recordInteractiveAnnouncementAdmission(array $request): void {}
    private function getAnnouncementCooldownState() { return ['remaining' => 0]; }
    private function appendAnnouncementNotifyLog(...$args) {}
}

function visualEvents(string $directory): array {
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/events.jsonl') ? file($directory . '/events.jsonl', FILE_IGNORE_NEW_LINES) : []);
}
function clearVisualEvents(string $directory): void { file_put_contents($directory . '/events.jsonl', ''); }
function assertVisualChildrenStopped(array $events): void {
    foreach ($events as $event) {
        if (($event['event'] ?? '') !== 'start') { continue; }
        $stat = @file_get_contents('/proc/' . $event['pid'] . '/stat');
        checkVisual($stat === false || preg_match('/\) [ZX] /', $stat), 'A visual descendant survived its deadline or callback failure.');
    }
}

$directory = sys_get_temp_dir() . '/sls-visual-pool-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); mkdir($directory . '/jobs', 0700);
file_put_contents($directory . '/child.py', <<<'PY'
import fcntl, json, os, signal, sys, time
args = json.loads(sys.argv[1])
if args.get('ignore_term'):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
def emit(event):
    row = {'event': event, 'target': args['target'], 'pid': os.getpid(), 'at': time.monotonic()}
    if event == 'start' and args.get('job_path'):
        with open(args['job_path'], encoding='utf-8') as stream:
            job = json.load(stream)
        row.update(receipts=job.get('receipts', []), job_state=job['state'])
    with open(args['log'], 'a', encoding='utf-8') as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        stream.write(json.dumps(row) + '\n')
        stream.flush()
emit('start')
if args.get('flood'):
    sys.stdout.write('x' * 1048576)
    sys.stderr.write('y' * 1048576)
time.sleep(args['delay'])
emit('end')
sys.exit(args.get('exit', 0))
PY
);

try {
    $fixture = new VisualPoolFixture($directory);
    $results = []; $order = [];
    $fixture->batch(['slow' => $fixture->command('slow', 0.35),
        'fast1' => $fixture->command('fast1', 0.02, ['flood' => true]),
        'fast2' => $fixture->command('fast2', 0.02), 'fast3' => $fixture->command('fast3', 0.02)],
        static function ($target, $result) use (&$results, &$order) { $results[$target] = $result; $order[] = $target; });
    checkVisual(count($results) === 4 && count(array_filter($results, static function ($row) { return $row['success']; })) === 4,
        'Concurrent visual sends did not all finish successfully.');
    checkVisual($order[0] === 'fast1' && end($order) === 'slow', 'A slow first phone blocked later phone progress.');
    $active = 0; $maximum = 0;
    foreach (visualEvents($directory) as $event) { $active += $event['event'] === 'start' ? 1 : -1; $maximum = max($maximum, $active); }
    checkVisual($active === 0 && $maximum === 2, 'The visual process concurrency bound was not respected.');
    echo "Concurrent progress, bounded pool and pipe-flood checks passed.\n";

    clearVisualEvents($directory);
    $fixture->limits = ['concurrency' => 1, 'child_seconds' => 0.1, 'phase_seconds' => 3];
    $results = []; $started = microtime(true);
    $fixture->batch(['timeout' => $fixture->command('timeout', 5, ['ignore_term' => true]),
        'after' => $fixture->command('after', 0.01)],
        static function ($target, $result) use (&$results) { $results[$target] = $result; });
    checkVisual(microtime(true) - $started < 2.5, 'A TERM-resistant descendant exceeded its kill grace.');
    checkVisual(!$results['timeout']['success'] && $results['timeout']['started'] && $results['timeout']['reason'] === 'child_timeout'
        && $results['after']['success'], 'A timed out child blocked the next independent attempt.');
    assertVisualChildrenStopped(visualEvents($directory));
    echo "Per-phone deadline, descendant termination and subsequent progress checks passed.\n";

    clearVisualEvents($directory);
    $fixture->limits = ['concurrency' => 1, 'child_seconds' => 1, 'phase_seconds' => 0.2];
    $results = [];
    $fixture->batch(['started' => $fixture->command('started', 3), 'pending1' => $fixture->command('pending1', 0),
        'pending2' => $fixture->command('pending2', 0)],
        static function ($target, $result) use (&$results) { checkVisual(!isset($results[$target]), 'Duplicate target callback.'); $results[$target] = $result; });
    checkVisual(count($results) === 3 && $results['started']['started'] && !$results['started']['success']
        && !$results['pending1']['started'] && !$results['pending2']['started'], 'Phase deadline confused started and never-started targets.');
    checkVisual(count(array_filter(visualEvents($directory), static function ($row) { return $row['event'] === 'start'; })) === 1,
        'A pending sender ran after the shared phase deadline.');
    assertVisualChildrenStopped(visualEvents($directory));
    echo "Shared deadline and exact started-versus-pending outcome checks passed.\n";

    $fixture->limits = ['concurrency' => 4, 'child_seconds' => 1, 'phase_seconds' => 3];
    $results = [];
    $fixture->batch(['failed_start' => 'simulate_start_failure', 'nonzero' => '/bin/false'] + array_fill_keys(range(2001, 2032), '/bin/true'),
        static function ($target, $result) use (&$results) { $results[$target] = $result; });
    checkVisual(!$results['failed_start']['started'] && !$results['failed_start']['success']
        && $results['nonzero']['started'] && !$results['nonzero']['success'], 'Launch failure and nonzero exit lost their distinct outcomes.');
    checkVisual(count(array_filter($results, static function ($row) { return $row['success']; })) === 32, 'A short-lived successful child lost its exit status.');
    echo "Launch failures, nonzero exit and fast-child exit-status checks passed.\n";

    clearVisualEvents($directory);
    $fixture->limits = ['concurrency' => 3, 'child_seconds' => 4, 'phase_seconds' => 5];
    $threw = false; $started = microtime(true);
    try {
        $fixture->batch(['fast' => $fixture->command('fast', 0.1), 'stubborn1' => $fixture->command('stubborn1', 5, ['ignore_term' => true]),
            'stubborn2' => $fixture->command('stubborn2', 5, ['ignore_term' => true])],
            static function () { throw new RuntimeException('simulated progress storage failure'); });
    } catch (RuntimeException $error) { $threw = $error->getMessage() === 'simulated progress storage failure'; }
    checkVisual($threw && microtime(true) - $started < 2.5, 'Progress failure did not bound cleanup of active children.');
    assertVisualChildrenStopped(visualEvents($directory));
    echo "Progress storage exception cancels active descendants without replay.\n";

    clearVisualEvents($directory);
    $fixture->limits = ['concurrency'=>1, 'child_seconds'=>4, 'phase_seconds'=>5];
    $results=[];
    $fixture->batch(['admitted'=>$fixture->command('admitted',1.15), 'late'=>$fixture->command('late',0)],
        static function($target,$result)use(&$results){$results[$target]=$result;},time()+1);
    checkVisual($results['admitted']['success'] && !$results['late']['started'] && $results['late']['reason']==='schedule_deadline',
        'Scheduled start deadline interrupted an active sender or started a late replacement.');
    checkVisual(count(visualEvents($directory))===2,'Late pending phone executed after scheduled deadline');
    echo "Scheduled deadline preserves active sends and refuses pending replacements.\n";

    clearVisualEvents($directory);
    $fixture->limits = ['concurrency' => 2, 'child_seconds' => 1, 'phase_seconds' => 0.25];
    $fixture->durations = ['1001' => 3, '1002' => 0.01, '1003' => 3, '1004' => 3];
    $request = ['message' => 'Isolated fixture', 'sender' => 'Fixture', 'phones' => ['1001', '1002', '1003', '1004', '1005'],
        'unavailable_phones' => ['1006'], 'desktops' => ['desktop'], 'webhooks' => ['hook'],
        'audio_mode' => 'tts', 'timeout_mode' => 'none', 'display_timeout' => 0, 'opening_tone' => '', 'closing_tone' => '',
        'voice' => 'fixture', 'volume' => 25, 'trigger_source' => 'fixture', 'image' => false, 'title' => 'Fixture', 'background_color' => '#000000',
        'delivery_id' => 'announcement-fixture-visual', 'delivery_timestamp' => gmdate('c')];
    $id = 'job_' . str_repeat('a', 32);
    $store = new SlsAnnouncementJobStore($directory . '/jobs');
    $store->write(['id' => $id, 'state' => 'queued', 'created_at' => gmdate('c'), 'request' => $request]);
    $fixture->processAnnouncementJobs($id);
    $job = $store->read($id); $byChannel = [];
    foreach ($job['receipts'] as $row) {
        $key = $row['channel'] . ':' . $row['target'];
        checkVisual(!isset($byChannel[$key]), 'Duplicate durable receipt for ' . $key);
        $byChannel[$key] = $row;
    }
    checkVisual($job['state'] === 'failed' && count($byChannel) === 14, 'Complete requested audience did not retain one receipt per channel.');
    checkVisual($byChannel['sip_notify:1002']['state'] === 'submitted'
        && $byChannel['sip_notify:1001']['state'] === 'uncertain' && !$byChannel['sip_notify:1001']['retryable']
        && $byChannel['sip_notify:1003']['state'] === 'uncertain' && !$byChannel['sip_notify:1003']['retryable']
        && $byChannel['sip_notify:1004']['state'] === 'failed' && $byChannel['sip_notify:1004']['retryable']
        && !$byChannel['sip_notify:1004']['submission_started']
        && $byChannel['sip_notify:1005']['state'] === 'cancelled' && $byChannel['sip_notify:1006']['state'] === 'unavailable',
        'Durable phone outcome/retry semantics changed.');
    $events = visualEvents($directory); $sawIncrementalReceipt = false;
    foreach ($events as $event) {
        if ($event['event'] !== 'start') { continue; }
        $prior = [];
        foreach ($event['receipts'] as $row) { $prior[$row['channel'] . ':' . $row['target']] = $row['state']; }
        checkVisual($event['job_state'] === 'running' && ($prior['desktop:desktop'] ?? '') === 'published'
            && ($prior['webhook:hook'] ?? '') === 'accepted' && ($prior['audio:1001'] ?? '') === 'queued',
            'Phone visual work blocked an independent channel or its persisted result.');
        if ($event['target'] === '1003') { $sawIncrementalReceipt = ($prior['sip_notify:1002'] ?? '') === 'submitted'; }
    }
    checkVisual($sawIncrementalReceipt, 'Completed phone receipt was not durable before launching a replacement.');
    $before = count($events); $fixture->processAnnouncementJobs($id);
    checkVisual(count(visualEvents($directory)) === $before, 'A finished partial job was automatically replayed.');
    $retry = $fixture->retryFailedAnnouncementJob($id, ['sender' => 'Fixture retry']);
    checkVisual(!empty($retry['queued']), 'Never-started phone did not offer an explicit operator retry.');
    $retryJob = $store->read($retry['job_id']);
    checkVisual($retryJob['request']['only_channels'] === ['sip_notify' => ['1004']], 'Retry replayed a submitted, uncertain, cancelled or unavailable destination.');
    echo "Actual worker persisted independent channels, incremental phone progress, complete outcomes and safe retry selection.\n";

    // Extract only the production command builder: execute an inert argparse
    // fixture, never the installed sender or a FreePBX bootstrap.
    if (!interface_exists('BMO')) { interface BMO {} }
    require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    $method = new ReflectionMethod(\FreePBX\modules\Slsmassnotifyserver::class, 'buildAnnouncementVisualPushCommand');
    $source = file($method->getFileName());
    $builder = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    eval('class VisualCommandFixture { const VISUAL_PUSH_SCRIPT = ' . var_export($directory . '/argv.py', true) . ';
        private function sanitizeScheduleText($value, $limit, $single) { return substr($value, 0, $limit); }
        public function command($message, $options) { return $this->buildAnnouncementVisualPushCommand($message, ["1001"], 17, $options); }
        ' . $builder . '}');
    file_put_contents($directory . '/argv.py', <<<'PY'
import argparse, json, os
parser = argparse.ArgumentParser()
parser.add_argument('--announcement', required=True)
parser.add_argument('--announcement-timeout-seconds', type=int)
parser.add_argument('--targets')
parser.add_argument('--no-api', action='store_true')
parser.add_argument('--api-only', action='store_true')
parser.add_argument('--desktop-targets')
args = parser.parse_args()
PY
        . "\nwith open(" . json_encode($directory . '/captured.json', JSON_UNESCAPED_SLASHES) . ", 'w', encoding='utf-8') as stream:\n"
        . "    json.dump({'args': vars(args), 'sender': os.environ.get('SLS_ANNOUNCEMENT_SENDER')}, stream)\n");
    $message = "--no-api '$(touch " . $directory . "/unexpected)'; echo wrong\n& literal";
    $sender = "Fixture ' $(echo unsafe)";
    $commandFixture = new VisualCommandFixture();
    $command = $commandFixture->command($message, ['managed_deadline' => true, 'sender' => $sender]);
    checkVisual(strpos($command, ' --foreground ') !== false, 'Managed child timeout starts an escaping process group.');
    $fixture->limits = ['concurrency' => 1, 'child_seconds' => 2, 'phase_seconds' => 3];
    $results = [];
    $fixture->batch(['argv' => $command], static function ($target, $result) use (&$results) { $results[$target] = $result; });
    $captured = json_decode((string)@file_get_contents($directory . '/captured.json'), true);
    checkVisual($results['argv']['success'] && ($captured['args']['announcement'] ?? null) === $message
        && ($captured['args']['targets'] ?? null) === '1001' && ($captured['args']['no_api'] ?? false)
        && ($captured['args']['announcement_timeout_seconds'] ?? null) === 17 && ($captured['sender'] ?? null) === $sender
        && !file_exists($directory . '/unexpected'), 'Production builder changed literal message, sender, targets or display timeout: ' . json_encode([$results, $captured]));
    checkVisual(strpos($commandFixture->command('desktop', ['mode' => 'api_only', 'desktop_clients' => ['desktop']]), ' --foreground ') === false,
        'Independent desktop sender unexpectedly lost its own timeout process group.');
    echo "Production command builder preserves flag-shaped/shell-shaped text and managed process-group ownership.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
