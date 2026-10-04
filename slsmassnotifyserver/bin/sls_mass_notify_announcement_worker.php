#!/usr/bin/php
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "Usage: sls_mass_notify_announcement_worker.php job_ID | --supervise job_ID | --health-check [--record-health] | --reconcile\n";
    exit(0);
}
$supervise = count($arguments) === 2 && $arguments[0] === '--supervise';
$id = $supervise ? $arguments[1] : ($arguments[0] ?? '');
$health = $arguments === ['--health-check'] || $arguments === ['--health-check', '--record-health'];
$reconcile = $arguments === ['--reconcile'];
if (!$health && !$reconcile && !(preg_match('/^job_[a-f0-9]{32}$/D', $id) && ($supervise || count($arguments) === 1))) {
    fwrite(STDERR, "Usage: sls_mass_notify_announcement_worker.php --help\n"); exit(2);
}
ini_set('display_errors', '0');
/** Standby supervision must not retire work owned by an active peer. */
function slsAnnouncementJournalAuthority(): bool
{
    $helper=__DIR__.'/sls_mass_notify_cluster_effect.php';
    if (!is_file($helper)) {
        // Disposable lifecycle fixtures copy this worker into a private folder.
        // A complete installed runtime must contain the authenticated guard.
        return __DIR__ !== '/usr/local/bin/sls_mass_notify';
    }
    $pipes=[];
    $process=@proc_open([PHP_BINARY,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,
        '/', ['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8'], ['bypass_shell'=>true]);
    if (!is_resource($process)) { return false; }
    fwrite($pipes[0],'{"action":"authority"}');fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);$body='';$deadline=microtime(true)+16;$status=null;
    do {
        $body.=(string)stream_get_contents($pipes[1],4097-strlen($body));
        $status=proc_get_status($process);
        if(strlen($body)>4096||microtime(true)>$deadline){proc_terminate($process,9);break;}
        if($status['running']){usleep(20000);}
    } while ($status['running']);
    fclose($pipes[1]);$closed=proc_close($process);$code=$status['exitcode']>=0?$status['exitcode']:$closed;
    $reply=json_decode($body,true);
    return $code===0&&is_array($reply)&&($reply['ok']??null)===true;
}
$phase = 'worker_start_failed'; $finished = false; $store = null;
register_shutdown_function(static function () use (&$finished, &$store, &$phase, $id, $health, $reconcile, $arguments) {
    if ($finished) { return; }
    if ($health) {
        $probe = ['ok' => false, 'checked_at' => gmdate('c'), 'failure_category' => $phase];
        if ($store && in_array('--record-health', $arguments, true)) {
            try { $store->atomic('worker-probe.json', $probe); } catch (Throwable $ignored) {}
        }
        echo json_encode($probe), "\n";
        error_log('SLS announcement health: ' . $phase);
        exit(1);
    }
    if ($store && !$health && !$reconcile && slsAnnouncementJournalAuthority()) {
        try { $store->fail($id, $phase); } catch (Throwable $ignored) {}
    }
    error_log('SLS announcement worker: ' . $phase);
});
try {
    require_once __DIR__ . '/sls_announcement_jobs.php';
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $account = posix_getpwnam('asterisk');
        if (!$account || !posix_initgroups('asterisk', $account['gid'])
            || !posix_setgid($account['gid']) || !posix_setuid($account['uid'])) {
            throw new RuntimeException('Unable to select the PBX runtime account.');
        }
    }
    $store = new SlsAnnouncementJobStore();
    if (!$health && !slsAnnouncementJournalAuthority()) {
        error_log('SLS announcement worker: cluster_authority_unavailable; queued work preserved');
        $finished=true;exit(1);
    }
    if ($reconcile) {
        $ok = true;
        foreach ($store->pendingIds() as $pending) {
            try { $store->reconcile($pending); }
            catch (Throwable $error) { $ok = false; error_log('SLS announcement reconcile: unsafe_job_state'); }
        }
        $finished = true; exit($ok ? 0 : 1);
    }
    if ($supervise) {
        // Capture child failure even if PHP parsing or FreePBX bootstrap fails.
        $pipes = [];
        $process = @proc_open(['/usr/bin/timeout', '--kill-after=5s', '900', PHP_BINARY, __FILE__, $id],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { if(slsAnnouncementJournalAuthority()){$store->fail($id, 'worker_start_failed');} $finished = true; exit(1); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        $deadline = microtime(true) + 910; $diagnostic = ''; $exitCode = -1; $timedOut = false;
        while (true) {
            foreach ($pipes as $pipe) {
                $chunk = stream_get_contents($pipe, 8192);
                if (strlen($diagnostic) < 4096) { $diagnostic .= substr((string)$chunk, 0, 4096 - strlen($diagnostic)); }
            }
            $status = proc_get_status($process);
            if (!$status['running']) { $exitCode = $status['exitcode']; break; }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                if (function_exists('posix_kill')) { posix_kill(-$status['pid'], 15); }
                proc_terminate($process); usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    if (function_exists('posix_kill')) { posix_kill(-$status['pid'], 9); }
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(50000);
        }
        foreach ($pipes as $pipe) { fclose($pipe); }
        $closed = proc_close($process); if ($exitCode < 0) { $exitCode = $closed; }
        $timedOut = $timedOut || in_array($exitCode, [124, 137], true);
        $job = $store->read($id);
        if ($job && in_array($job['state'] ?? '', SlsAnnouncementJobStore::PENDING, true)) {
            $category = $timedOut ? 'worker_timeout' : (($job['state'] ?? '') === 'running' ? 'worker_runtime_failed' : 'worker_start_failed');
            if(slsAnnouncementJournalAuthority()){$store->fail($id, $category);}
            if ($exitCode === 0) { $exitCode = 1; }
        }
        $job = $store->read($id);
        if (!$job || ($job['state'] ?? '') !== 'complete') { if ($exitCode === 0) { $exitCode = 1; } }
        // Raw diagnostics may contain credentials. Persist categories, not raw output.
        if ($exitCode !== 0 || $timedOut) {
            error_log('SLS announcement child ' . $id . ': exit=' . (int)$exitCode . ' captured_bytes=' . strlen($diagnostic));
        }
        $finished = true; exit($exitCode === 0 && !$timedOut ? 0 : 1);
    }
    $phase = 'worker_bootstrap_failed';
    require '/etc/freepbx.conf';
    $phase = 'worker_module_load_failed';
    $module = \FreePBX::Slsmassnotifyserver();
    if (!is_object($module) || !method_exists($module, 'processAnnouncementJobs')) {
        throw new RuntimeException('Mass Notify worker methods are unavailable.');
    }
    if ($health) {
        if (!is_callable('proc_open') || !is_executable('/usr/bin/timeout') || !is_executable('/usr/bin/nohup')) {
            $phase = 'worker_start_failed';
            throw new RuntimeException('Announcement process supervision is unavailable.');
        }
        $probe = ['ok' => $store->probeStorage(), 'checked_at' => gmdate('c'),
            'bootstrap' => true, 'module_loaded' => true, 'storage_writable' => true, 'failure_category' => ''];
        if (in_array('--record-health', $arguments, true)) { $store->atomic('worker-probe.json', $probe); }
        echo json_encode($probe, JSON_UNESCAPED_SLASHES), "\n";
        $finished = true; exit($probe['ok'] ? 0 : 1);
    }
    $phase = 'worker_runtime_failed';
    $ok = $module->processAnnouncementJobs($id);
    $finished = true; exit($ok ? 0 : 1);
} catch (Throwable $error) {
    if ($store && !$health && !$reconcile && slsAnnouncementJournalAuthority()) {
        try { $store->fail($id, $phase); } catch (Throwable $ignored) {}
    }
    if ($health) {
        $probe = ['ok' => false, 'checked_at' => gmdate('c'), 'failure_category' => $phase];
        if ($store && in_array('--record-health', $arguments, true)) {
            try { $store->atomic('worker-probe.json', $probe); } catch (Throwable $ignored) {}
        }
        echo json_encode($probe), "\n";
    }
    error_log('SLS announcement worker: ' . $phase);
    $finished = true; exit(1);
}
