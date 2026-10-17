<?php
/** Exercise the module's actual private atomic writer in an isolated directory. */
declare(strict_types=1);

if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';

$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$method = $reflection->getMethod('writePrivateAtomicFile');
$source = file($reflection->getFileName());
$implementation = implode('', array_slice(
    $source,
    $method->getStartLine() - 1,
    $method->getEndLine() - $method->getStartLine() + 1
));
$eventMethod = $reflection->getMethod('appendOperationalEvent');
$eventImplementation = implode('', array_slice(
    $source,
    $eventMethod->getStartLine() - 1,
    $eventMethod->getEndLine() - $eventMethod->getStartLine() + 1
));
$root = sys_get_temp_dir() . '/sls-private-write-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create private write fixture'); }
$account = posix_getpwnam('asterisk');
if (!is_array($account)) { throw new RuntimeException('Asterisk account is unavailable'); }
$runtime = $root . '/run';
if (!mkdir($runtime, 0770) || !chown($runtime, $account['uid']) || !chgrp($runtime, $account['gid']) || !chmod($runtime, 0770)) {
    throw new RuntimeException('Cannot create Asterisk runtime fixture');
}

eval('class SlsPrivateWriteFixture {
    const PLUGIN_DATA_DIR = ' . var_export($root, true) . ';
    const MAINTENANCE_PROGRESS_FILE = ' . var_export($runtime . '/maintenance.json', true) . ';
    const EVENTS_LOG = ' . var_export($root . '/events.jsonl', true) . ';
    const UI_LOG_LINE_BYTES = 262144;
	    private function setPrivateOwnership($path, $identity = null) {
        $account = posix_getpwnam("asterisk");
        if (!chmod($path, 0640) || !chown($path, $account["uid"]) || !chgrp($path, $account["gid"])) {
            throw new RuntimeException("Unable to secure fixture file");
        }
    }
    public function write($path, $contents, $limit = 65536) {
        return $this->writePrivateAtomicFile($path, $contents, $limit);
    }
    private function ensurePrivateFile($path) {
        if (!file_exists($path)) {
            $mask = umask(0037);
            try { if (file_put_contents($path, "") === false) { throw new RuntimeException("Unable to create fixture log"); } }
            finally { umask($mask); }
        }
        if (!chmod($path, 0640)) { throw new RuntimeException("Unable to secure fixture log"); }
    }
    public function append(array $payload) { return $this->appendOperationalEvent($payload); }
' . $implementation . $eventImplementation . '}');

function sls_private_write_assert($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function sls_private_write_reject(callable $operation, string $message): void {
    try { $operation(); }
    catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}

function sls_private_write_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { sls_private_write_remove($path . '/' . $name); }
        }
        rmdir($path);
    } else { unlink($path); }
}

try {
    $fixture = new SlsPrivateWriteFixture();
    $state = $root . '/state.json';
    sls_private_write_assert($fixture->write($state, "first\n"), 'Initial atomic write failed');
    $firstInode = lstat($state)['ino'];
    sls_private_write_assert($fixture->write($state, "second\n"), 'Atomic replacement failed');
    clearstatcache(true, $state);
    $metadata = lstat($state);
    sls_private_write_assert(file_get_contents($state) === "second\n", 'Replacement exposed incorrect bytes');
    sls_private_write_assert($metadata['ino'] !== $firstInode, 'Replacement reused the old inode');
    sls_private_write_assert(($metadata['mode'] & 0777) === 0640, 'Replacement mode is not private');
    sls_private_write_assert($metadata['uid'] === $account['uid'] && $metadata['gid'] === $account['gid'], 'Replacement ownership is incorrect');
    sls_private_write_assert(glob($state . '.tmp.*') === [], 'Successful write left a staging file');
    sls_private_write_assert($fixture->write(SlsPrivateWriteFixture::MAINTENANCE_PROGRESS_FILE, "{\"state\":\"queued\"}\n"), 'Exact Asterisk runtime progress write failed');
    sls_private_write_assert(file_get_contents(SlsPrivateWriteFixture::MAINTENANCE_PROGRESS_FILE) === "{\"state\":\"queued\"}\n", 'Runtime progress bytes changed');

    sls_private_write_reject(fn() => $fixture->write($root . '/oversized', '12345', 4), 'Oversized content was accepted');
    sls_private_write_reject(fn() => $fixture->write($root . '/../escaped', 'escape'), 'Path traversal was accepted');

    $victim = $root . '/victim';
    file_put_contents($victim, 'victim bytes');
    $link = $root . '/linked';
    link($victim, $link);
    sls_private_write_reject(fn() => $fixture->write($link, 'replacement'), 'Hard-linked destination was accepted');
    sls_private_write_assert(file_get_contents($victim) === 'victim bytes', 'Hard-link rejection changed the victim');
    unlink($link);
    $symbolic = $root . '/symbolic';
    symlink($victim, $symbolic);
    sls_private_write_reject(fn() => $fixture->write($symbolic, 'replacement'), 'Symbolic-link destination was accepted');
    sls_private_write_assert(file_get_contents($victim) === 'victim bytes', 'Symlink rejection changed the victim');
    unlink($symbolic);
    $fifo = $root . '/fifo';
    posix_mkfifo($fifo, 0600);
    sls_private_write_reject(fn() => $fixture->write($fifo, 'replacement'), 'FIFO destination was accepted');

    $writable = $root . '/writable';
    mkdir($writable, 0770);
    chmod($writable, 0770);
    sls_private_write_reject(fn() => $fixture->write($writable . '/state', 'replacement'), 'Group-writable parent was accepted');
    sls_private_write_assert(glob($writable . '/state.tmp.*') === [], 'Rejected parent left a staging file');

    sls_private_write_assert($fixture->append(['event_id'=>'first','message'=>'one']), 'Initial supplemental event append failed');
    sls_private_write_assert($fixture->append(['event_id'=>'second','message'=>'two']), 'Second supplemental event append failed');
    $events = file($root . '/events.jsonl', FILE_IGNORE_NEW_LINES);
    sls_private_write_assert(count($events) === 2 && json_decode($events[0], true)['event_id'] === 'first'
        && json_decode($events[1], true)['event_id'] === 'second', 'Supplemental events were not appended as complete JSON lines');
    sls_private_write_assert((lstat($root . '/events.jsonl')['mode'] & 0777) === 0640, 'Supplemental event log is not private');
    unlink($root . '/events.jsonl');
    link($victim, $root . '/events.jsonl');
    sls_private_write_assert(!$fixture->append(['event_id'=>'linked']), 'Hard-linked supplemental event log was accepted');
    sls_private_write_assert(file_get_contents($victim) === 'victim bytes', 'Hard-linked log rejection changed its victim');
    unlink($root . '/events.jsonl');
    symlink($victim, $root . '/events.jsonl');
    sls_private_write_assert(!$fixture->append(['event_id'=>'symbolic']), 'Symbolic supplemental event log was accepted');
    sls_private_write_assert(file_get_contents($victim) === 'victim bytes', 'Symbolic log rejection changed its victim');
    unlink($root . '/events.jsonl');
    sls_private_write_assert(!$fixture->append(['message'=>str_repeat('x', 262144)]), 'Oversized supplemental event was accepted');

    echo "Private runtime writes and supplemental event appends are durable, bounded, private, and reject unsafe destinations.\n";
} finally {
    sls_private_write_remove($root);
}
