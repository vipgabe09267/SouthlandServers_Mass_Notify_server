<?php
declare(strict_types=1);
// Actual helpers, temporary files only. No module bootstrap or PBX paths.
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !posix_getpwnam('asterisk')) {
    throw new RuntimeException('Run this protected-file fixture with the root isolation wrapper.');
}
$root = sys_get_temp_dir() . '/sls-protected-helper-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$oldCwd = getcwd();
$oldPythonPath = getenv('PYTHONPATH');
$reflection = new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
$module = $reflection->newInstanceWithoutConstructor();
$call = static function (string $name, ...$arguments) use ($reflection, $module) {
    $method = $reflection->getMethod($name);
    $method->setAccessible(true);
    return $method->invokeArgs($module, $arguments);
};
$assert = static function ($condition, $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
try {
    $marker = $root . '/imported-untrusted-code';
    file_put_contents($root . '/sitecustomize.py', "open(" . json_encode($marker) . ", 'w').write('imported')\n");
    putenv('PYTHONPATH=' . $root);
    chdir($root);
    $target = $root . '/protected';
    file_put_contents($target, 'fixture contents');
    chmod($target, 0600);
    $call('setPrivateOwnership', $target);
    $call('ensurePrivateFile', $root . '/created');
    $call('ensureOwnedDirectory', $root . '/directory', 0750);
    mkdir($root . '/data', 0700);
    foreach (['event-log-recovery', 'sms'] as $privateDirectory) {
        mkdir($root . '/data/' . $privateDirectory, 0700);
        file_put_contents($root . '/data/' . $privateDirectory . '/evidence', 'private fixture');
    }
    $call('secureManagedRuntimeTree', $root . '/data', 'data');
    clearstatcache();
    foreach (['event-log-recovery', 'sms'] as $privateDirectory) {
        $path = $root . '/data/' . $privateDirectory;
        $assert((fileperms($path) & 0777) === 0700 && (fileperms($path . '/evidence') & 0777) === 0600,
            'Private recovery or SMS storage permissions were broadened.');
    }
    $assert(!file_exists($marker), 'Privileged helper imported untrusted PYTHONPATH/sitecustomize code.');
    clearstatcache();
    $account = posix_getpwnam('asterisk');
    foreach ([$target, $root . '/created'] as $path) {
        $assert(fileowner($path) === $account['uid'] && (fileperms($path) & 0777) === 0640, 'Protected helper did not secure the temporary file.');
    }
    foreach (['setPrivateOwnership', 'ensurePrivateFile'] as $helper) {
        foreach (['hardlink', 'symlink', 'fifo'] as $kind) {
            $unsafe = $root . '/unsafe';
            if ($kind === 'hardlink') { link($target, $unsafe); }
            elseif ($kind === 'symlink') { symlink($target, $unsafe); }
            else { posix_mkfifo($unsafe, 0600); }
            $before = stat($target);
            $start = microtime(true);
            $rejected = false;
            try { $call($helper, $unsafe); } catch (RuntimeException $expected) { $rejected = true; }
            $assert($rejected && microtime(true) - $start < 2, $helper . ' did not promptly reject ' . $kind);
            clearstatcache();
            $after = stat($target);
            $assert($before['uid'] === $after['uid'] && $before['mode'] === $after['mode'] && file_get_contents($target) === 'fixture contents', 'Unsafe helper path changed its target.');
            unlink($unsafe);
        }
    }
    $reject = static function (callable $work, string $message, float $seconds = 0.5) use ($assert): void {
        $start = microtime(true); $failed = false;
        try { $work(); } catch (Throwable $expected) { $failed = true; }
        $assert($failed && microtime(true) - $start < $seconds, $message);
    };
    $lockPath = $root . '/backup.lock';
    $held = $call('acquireNativeBackupFileLock', $lockPath, 'Fixture lock rejected', 1);
    $assert(is_resource($held), 'A safe backup lock was rejected.');
    $lockBefore = stat($lockPath);
    $reject(fn() => $call('acquireNativeBackupFileLock', $lockPath, 'Fixture contention', 1), 'Backup contention did not have a bounded deadline.', 1.5);
    $call('releaseNativeBackupFileLock', $held);
    $held = $call('acquireNativeBackupFileLock', $lockPath, 'Fixture shared lock', 1, LOCK_SH);
    $second = fopen($lockPath, 'r+b');
    $assert(!flock($second, LOCK_EX | LOCK_NB), 'A returned shared backup lock was not held.');
    fclose($second); $call('releaseNativeBackupFileLock', $held);
    clearstatcache(); $lockAfter = stat($lockPath);
    $assert($lockBefore['ino'] === $lockAfter['ino'] && $lockBefore['uid'] === $lockAfter['uid'], 'A valid backup lock was replaced or its ownership changed.');
    foreach (['hardlink', 'symlink', 'fifo', 'oversized', 'writable', 'foreign-owner'] as $kind) {
        $unsafe = $root . '/unsafe';
        if ($kind === 'hardlink') { link($target, $unsafe); }
        elseif ($kind === 'symlink') { symlink($target, $unsafe); }
        elseif ($kind === 'fifo') { posix_mkfifo($unsafe, 0600); }
        else {
            file_put_contents($unsafe, $kind === 'oversized' ? str_repeat('x', 4097) : 'preserved'); chmod($unsafe, 0600);
            if ($kind === 'writable') { chmod($unsafe, 0660); }
            if ($kind === 'foreign-owner') { chown($unsafe, 65534); }
        }
        $before = lstat($unsafe);
        $reject(fn() => $call('acquireNativeBackupFileLock', $unsafe, 'Fixture unsafe lock', 1), 'Backup lock did not promptly reject ' . $kind);
        clearstatcache(); $after = lstat($unsafe);
        $assert($before['uid'] === $after['uid'] && $before['mode'] === $after['mode'], 'Rejected backup lock changed ownership or permissions.');
        unlink($unsafe);
    }
    mkdir($root . '/unsafe-parent', 0700); chmod($root . '/unsafe-parent', 0777);
    $reject(fn() => $call('acquireNativeBackupFileLock', $root . '/unsafe-parent/lock', 'Unsafe parent', 1), 'Backup lock accepted a writable parent.');
    $assert(!file_exists($root . '/unsafe-parent/lock'), 'Unsafe parent rejection created a lock.');
    chmod($root . '/unsafe-parent', 0700);

    // Extract the actual status method so its fixed production path stays inaccessible.
    $method = $reflection->getMethod('updateStatusData');
    $sourceLines = file($reflection->getFileName());
    $statusMethod = implode('', array_slice($sourceLines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $statusMethod = preg_replace('/private function updateStatusData/', 'public function updateStatusData', $statusMethod, 1, $replaced);
    $assert($replaced === 1, 'Actual status method could not be isolated.');
    $statusPath = $root . '/status.json';
    eval('class ProtectedStatusFixture { const STATUS_JSON=' . var_export($statusPath, true) . ';'
        . 'public function __construct(private $ownership) {} private function ensurePluginDataDir() {}'
        . 'private function setPrivateOwnership($path) { ($this->ownership)($path); }' . $statusMethod . '}');
    $status = new ProtectedStatusFixture(static fn($path) => $call('setPrivateOwnership', $path));
    $status->updateStatusData(['old_evidence' => 'preserved', 'counter' => 1]);
    clearstatcache(); $statusInode = fileinode($statusPath);
    $status->updateStatusData(['counter' => 2]);
    $assert(json_decode(file_get_contents($statusPath), true) === ['old_evidence' => 'preserved', 'counter' => 2]
        && fileinode($statusPath) === $statusInode, 'Status merge lost evidence or changed the shared writer inode.');
    $before = file_get_contents($statusPath);
    $reject(fn() => $status->updateStatusData(['oversized' => str_repeat('x', 1048576)]), 'Oversized status output was accepted.');
    $assert(file_get_contents($statusPath) === $before, 'Rejected status output erased evidence.');
    $held = fopen($statusPath, 'r+b'); flock($held, LOCK_EX);
    $reject(fn() => $status->updateStatusData(['counter' => 3]), 'Status contention did not have a bounded deadline.', 2.5);
    $start = microtime(true); $loaded = $call('loadJsonFile', $statusPath);
    $assert($loaded === [] && microtime(true) - $start < 0.5, 'A busy dashboard JSON reader blocked.');
    fclose($held);
    $assert($call('loadJsonFile', $statusPath)['counter'] === 2, 'Safe dashboard status reading failed.');
    foreach (['', '{broken', '[]', 'null', str_repeat('x', 1048577)] as $raw) {
        file_put_contents($statusPath, $raw);
        $reject(fn() => $status->updateStatusData(['counter' => 4]), 'Incomplete, non-object or oversized status input was reset.');
        $assert(file_get_contents($statusPath) === $raw, 'Invalid status evidence was overwritten.');
    }
    unlink($statusPath);
    foreach (['hardlink', 'symlink', 'fifo', 'writable', 'foreign-owner'] as $kind) {
        if ($kind === 'hardlink') { link($target, $statusPath); }
        elseif ($kind === 'symlink') { symlink($target, $statusPath); }
        elseif ($kind === 'fifo') { posix_mkfifo($statusPath, 0600); }
        else { file_put_contents($statusPath, '{}'); chmod($statusPath, $kind === 'writable' ? 0660 : 0600); if ($kind === 'foreign-owner') { chown($statusPath, 65534); } }
        $before = lstat($statusPath);
        $reject(fn() => $status->updateStatusData(['counter' => 5]), 'Status writer did not promptly reject ' . $kind);
        clearstatcache(); $after = lstat($statusPath);
        $assert($before['uid'] === $after['uid'] && $before['mode'] === $after['mode'], 'Rejected status evidence was modified.');
        if (in_array($kind, ['hardlink', 'symlink', 'fifo'], true)) {
            $start = microtime(true);
            $assert($call('loadJsonFile', $statusPath) === [] && microtime(true) - $start < 0.5, 'Dashboard reader did not promptly reject ' . $kind);
        }
        unlink($statusPath);
    }
    file_put_contents($statusPath, str_repeat('x', 8 * 1024 * 1024 + 1)); chmod($statusPath, 0600);
    $assert($call('loadJsonFile', $statusPath) === [], 'Dashboard reader exceeded its input limit.');
    $assert(file_get_contents($target) === 'fixture contents', 'Rejected storage changed a linked target.');
    echo "Protected helpers reject unsafe paths, preserve evidence, bound input and lock waits, keep shared writer inodes and ignore untrusted Python imports.\n";
} finally {
    chdir($oldCwd);
    putenv($oldPythonPath === false ? 'PYTHONPATH' : 'PYTHONPATH=' . $oldPythonPath);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
