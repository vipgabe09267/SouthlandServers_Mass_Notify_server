<?php
declare(strict_types=1);
namespace SlsScheduleLockFixture {
    // Exercise a substitution after lstat and before open without touching PBX state.
    function fopen($path, $mode) {
        if (($GLOBALS['replace_lock_on_open'] ?? '') !== '') {
            @unlink($path);
            if ($GLOBALS['replace_lock_on_open'] === 'fifo') { \posix_mkfifo($path, 0600); }
            else { \symlink($GLOBALS['lock_victim'], $path); }
            $GLOBALS['replace_lock_on_open'] = '';
        }
        return \fopen($path, $mode);
    }
}
namespace {
    $source = file_get_contents(dirname(__DIR__) . '/slsmassnotifyserver/bin/sls_mass_notify_schedule_worker.php');
    $start = strpos($source, 'function sls_schedule_runner_lock(');
    $end = strpos($source, "\n\$runnerLock = null;", $start);
    if ($start === false || $end === false) { throw new RuntimeException('Runner lock extraction failed.'); }
    eval('namespace SlsScheduleLockFixture; ' . substr($source, $start, $end - $start));
    function assert_lock($condition, $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function rejected_lock(string $directory): void {
        try { $held = SlsScheduleLockFixture\sls_schedule_runner_lock($directory); }
        catch (RuntimeException $expected) { return; }
        if (is_resource($held)) { fclose($held); }
        throw new RuntimeException('Unsafe lock location was accepted.');
    }
    $base = sys_get_temp_dir() . '/sls-schedule-lock-' . bin2hex(random_bytes(8));
    mkdir($base, 0700); $directory = $base . '/data'; mkdir($directory, 0700);
    $path = $directory . '/schedule-runner.lock';
    $victim = $base . '/victim'; file_put_contents($victim, 'preserve existing contents'); chmod($victim, 0640);
    $GLOBALS['lock_victim'] = $victim;
    try {
        $held = SlsScheduleLockFixture\sls_schedule_runner_lock($directory);
        assert_lock(is_resource($held), 'First runner could not acquire its lease.');
        assert_lock(SlsScheduleLockFixture\sls_schedule_runner_lock($directory) === null, 'Overlapping runner was not excluded.');
        fclose($held); file_put_contents($path, 'existing lock contents');
        $held = SlsScheduleLockFixture\sls_schedule_runner_lock($directory);
        assert_lock(file_get_contents($path) === 'existing lock contents', 'Acquiring a lease truncated its file.');
        fclose($held); unlink($path);
        symlink($victim, $path); rejected_lock($directory); unlink($path);
        link($victim, $path); rejected_lock($directory); unlink($path);
        posix_mkfifo($path, 0600); rejected_lock($directory); unlink($path);
        file_put_contents($path, 'unsafe mode'); chmod($path, 0660); rejected_lock($directory); unlink($path);
        chmod($directory, 0770); rejected_lock($directory); chmod($directory, 0700);
        symlink($directory, $base . '/alias'); rejected_lock($base . '/alias'); unlink($base . '/alias');
        foreach (['fifo', 'symlink'] as $replacement) {
            file_put_contents($path, 'original'); chmod($path, 0600);
            $GLOBALS['replace_lock_on_open'] = $replacement;
            $begin = microtime(true); rejected_lock($directory);
            assert_lock(microtime(true) - $begin < 2, 'Unsafe substituted file blocked acquisition.');
            unlink($path);
        }
        assert_lock(file_get_contents($victim) === 'preserve existing contents', 'Victim content changed.');
        clearstatcache(true, $victim);
        assert_lock((fileperms($victim) & 0777) === 0640 && stat($victim)['nlink'] === 1, 'Victim metadata changed.');
        $lockAt = strpos($source, '$runnerLock = sls_schedule_runner_lock($dataDirectory)');
        assert_lock($lockAt !== false && $lockAt < strpos($source, 'require_once $freepbxConfig;'), 'Runner starts FreePBX before acquiring its lease.');
        assert_lock(strpos($source, 'if (!$selfTest) {') < $lockAt, 'Readiness self-test cannot bypass maintenance lease.');
        echo "Scheduling runner lease excludes overlap, preserves contents and rejects unsafe files, links, modes and substitutions.\n";
    } finally {
        foreach (glob($directory . '/*') ?: [] as $item) { @unlink($item); }
        @rmdir($directory); @unlink($victim); @unlink($base . '/alias'); @rmdir($base);
    }
}
