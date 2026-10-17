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
function slsAnnouncementJournalAuthority(?int &$expiresAt=null): bool
{
    $expiresAt=0;
    $helper=__DIR__.'/sls_mass_notify_cluster_effect.php';
    if (!is_file($helper)) { return false; }
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
        if(!$status['running']){$body.=(string)stream_get_contents($pipes[1],4097-strlen($body));break;}
        if($status['running']){usleep(20000);}
    } while ($status['running']);
    fclose($pipes[1]);$closed=proc_close($process);$code=$status['exitcode']>=0?$status['exitcode']:$closed;
    $reply=json_decode($body,true);
    if($code!==0||!is_array($reply)||($reply['ok']??null)!==true||!is_bool($reply['enabled']??null)
        || !array_key_exists('claim',$reply)){return false;}
    if($reply['claim']===null){$expiresAt=PHP_INT_MAX;return true;}
    $claim=$reply['claim'];$expiry=$claim['expires_at']??null;
    if(!is_array($claim)||!is_int($expiry)||$expiry<=time()){return false;}
    $expiresAt=$expiry;return true;
}
/** Load mutable PBX/runtime code only as the dedicated service account. */
function slsAnnouncementRequireRuntimeAccount(array $arguments): void
{
    if(!function_exists('posix_geteuid')||!function_exists('posix_getpwnam')){
        throw new RuntimeException('PHP CLI POSIX account checks are required.');
    }
    $uid=posix_geteuid();$account=posix_getpwnam('asterisk');
    if(!is_int($uid)||!is_array($account)||!is_int($account['uid']??null)||$account['uid']<=0){
        throw new RuntimeException('A nonroot asterisk runtime account is required.');
    }
    if($uid===0){
        if(!function_exists('pcntl_exec')){throw new RuntimeException('PHP CLI PCNTL is required to enter the asterisk runtime account.');}
        pcntl_exec('/usr/bin/env',['-i','PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG=C.UTF-8','LC_ALL=C.UTF-8','/usr/sbin/runuser','-u','asterisk','--','/usr/bin/php',__FILE__,...$arguments]);
        throw new RuntimeException('Unable to start the clean runtime-account worker.');
    }
    if($uid!==$account['uid']){throw new RuntimeException('Run the announcement worker only as root or the asterisk runtime account.');}
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
    slsAnnouncementRequireRuntimeAccount($arguments);
    require_once __DIR__ . '/sls_announcement_jobs.php';
    $store = new SlsAnnouncementJobStore();
    $authorityExpiry=0;
    if (!$health && !slsAnnouncementJournalAuthority($authorityExpiry)) {
        error_log('SLS announcement worker: cluster_authority_unavailable; queued work preserved');
        $finished=true;exit(1);
    }
    if ($reconcile) {
        $ok = true;
        foreach ($store->pendingIds() as $pending) {
            if ($authorityExpiry!==PHP_INT_MAX && time()>=$authorityExpiry-1) {
                $ok=false;error_log('SLS announcement reconcile: cluster_authority_expired');break;
            }
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
            $pipes, '/', ['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'], ['bypass_shell' => true]);
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
        $cleanTerminal = $job && (($job['state'] ?? '') === 'complete'
            || (($job['state'] ?? '') === 'cancelled' && ($job['failure_category'] ?? '') === 'weather_source_cancelled'));
        if (!$cleanTerminal && $exitCode === 0) { $exitCode = 1; }
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
        $storage=$store->probeStorage();
        $probe = ['ok' => $storage, 'checked_at' => gmdate('c'),
            'bootstrap' => true, 'module_loaded' => true, 'storage_writable' => $storage,
            'failure_category' => $storage ? '' : 'worker_storage_unavailable'];
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
