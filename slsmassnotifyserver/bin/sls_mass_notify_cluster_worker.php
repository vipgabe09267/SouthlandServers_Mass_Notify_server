#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(64); }
$args=array_slice($argv,1);
if ($args===['--help']) { echo "Usage: sls_mass_notify_cluster_worker.php --once | --connected-once | --watch | --edge-once | --edge-watch | --initialize | --status | --apply-mirrors\nWatch attempts witness renewal every lease/3 seconds between dispatch cycles. Stop external watch supervisors before module upgrades or protected maintenance. Edge, initialization and status modes require no FreePBX bootstrap. No listener or service is started.\n"; exit(0); }
if (!in_array($args,[['--once'],['--connected-once'],['--watch'],['--edge-once'],['--edge-watch'],['--initialize'],['--status'],['--apply-mirrors']],true)) { fwrite(STDERR,"Use --help for supported commands.\n"); exit(64); }
/** Acquire before loading mutable module code; validate pathname and descriptor. */
function slsClusterWorkerMaintenanceLock(string $path='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-worker.lock')
{
    $directory=dirname($path);clearstatcache(true,$directory);$parent=@lstat($directory);$account=posix_getpwnam('asterisk');
    $owners=array_unique([0,posix_geteuid(),$account['uid']??-1]);
    if (!$parent || ($parent['mode']&0170000)!==0040000 || ($parent['mode']&0022) || !in_array($parent['uid'],$owners,true)
        || realpath($directory)!==$directory) { throw new RuntimeException('Cluster worker directory is unsafe.'); }
    $regular=static fn($m): bool=>is_array($m) && ($m['mode']&0170000)===0100000 && $m['nlink']===1
        && in_array($m['uid'],$owners,true) && ($m['mode']&0027)===0 && $m['size']<=4096;
    clearstatcache(true,$path);$before=@lstat($path);
    if ($before!==false&&!$regular($before)) { throw new RuntimeException('Cluster worker file is unsafe.'); }
    $mask=umask(0037);
    try {
        $handle=@fopen($path,$before===false?'x+b':'r+b');
        if (!$handle&&$before===false) { clearstatcache(true,$path);$before=@lstat($path);if ($regular($before)) { $handle=@fopen($path,'r+b'); } }
    } finally { umask($mask); }
    if (!is_resource($handle)) { throw new RuntimeException('Cluster worker file is unavailable.'); }
    $accepted=false;
    try {
        $identity=static function() use($path,$handle,$before,$regular): bool {
            clearstatcache(true,$path);$named=@lstat($path);$opened=fstat($handle);
            return $regular($named)&&$regular($opened)&&$named['dev']===$opened['dev']&&$named['ino']===$opened['ino']
                &&($before===false||($before['dev']===$opened['dev']&&$before['ino']===$opened['ino']));
        };
        if (!$identity()) { throw new RuntimeException('Cluster worker identity changed.'); }
        if (!@flock($handle,LOCK_SH|LOCK_NB)) { throw new RuntimeException('Protected maintenance blocks this worker. Stop external watch supervisors before module upgrades or maintenance.'); }
        if (!$identity()) { throw new RuntimeException('Cluster worker identity changed after locking.'); }
        $accepted=true;return $handle;
    } finally { if (!$accepted) { fclose($handle); } }
}
$slsClusterMaintenance=null;
try {
    if (function_exists('posix_geteuid') && posix_geteuid()===0) {
        $u=posix_getpwnam('asterisk');
        if (!$u || !posix_initgroups('asterisk',$u['gid']) || !posix_setgid($u['gid']) || !posix_setuid($u['uid'])) { throw new RuntimeException('Runtime account unavailable.'); }
    }
    // Installer acquires this exclusive lease before ordinary activity locks.
    // It covers mutable module bootstrap through watch sleep and process exit;
    // settings and mirrors retain their separate, bounded activity leases.
    $slsClusterMaintenance=slsClusterWorkerMaintenanceLock();
    register_shutdown_function(static function() use($slsClusterMaintenance): void {
        if (is_resource($slsClusterMaintenance)) { @flock($slsClusterMaintenance,LOCK_UN);@fclose($slsClusterMaintenance); }
    });
    $watch=in_array($args,[['--watch'],['--edge-watch']],true); $running=true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true); pcntl_signal(SIGTERM,static function() use(&$running): void { $running=false; });
        pcntl_signal(SIGINT,static function() use(&$running): void { $running=false; });
    }
    $edge=in_array($args,[['--edge-once'],['--edge-watch']],true); $administration=in_array($args,[['--initialize'],['--status']],true);
    if ($edge || $administration) {
        $library=dirname(__DIR__).'/EnterpriseClusterEdge.php';
        if (!is_file($library)) { $library='/var/www/html/admin/modules/slsmassnotifyserver/EnterpriseClusterEdge.php'; }
        require_once $library; require_once dirname($library).'/ConfigCrypto.php';
    } else { require '/etc/freepbx.conf'; $module=\FreePBX::Create()->Slsmassnotifyserver; }
    if ($args===['--apply-mirrors']) { echo json_encode(['mirrors'=>$module->enterpriseApplyPendingMirrors()],JSON_THROW_ON_ERROR),"\n";exit(0); }
    if ($administration) {
        $settings=\FreePBX\modules\SlsConfigCrypto::readFile('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
        $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($settings);
        if ($args===['--initialize']) { $runtime->initialize(); echo '{"initialized":true}',"\n"; }
        else { $s=$runtime->store()->read(); echo json_encode(['node_id'=>$runtime->config()['node_id'],'revision'=>$s['revision'],'lease'=>$s['lease'],'control'=>$s['control'],'queue_entries'=>count($s['queue']),'hardware_qualified'=>false],JSON_THROW_ON_ERROR),"\n"; }
        exit(0);
    }
    do {
        $delay=5;
        try {
            if ($edge) {
                $settings=\FreePBX\modules\SlsConfigCrypto::readFile('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config');
                if (!\SLS\MassNotify\EnterpriseClusterConfig::enabled($settings)) { break; }
                $runtime=new \SLS\MassNotify\EnterpriseClusterRuntime($settings); $connected=false;
                foreach ($runtime->config()['peers'] as $peer) {
                    if ($peer['role']!=='coordinator') { continue; }
                    try { $runtime->request($peer['node_id'],'status',[]); $connected=true; break; } catch (Throwable $ignored) {}
                }
                $result=['edge_jobs'=>(new \SLS\MassNotify\EnterpriseClusterEdge($runtime))->drain($connected)];
            } else {
                $state=$module->enterpriseClusterWorkerState(); if (!$state['enabled']) { break; }
                $delay=max(1,intdiv($state['lease_seconds'],3));
                $result=$watch ? $module->enterpriseClusterCycle() : ['mirrors'=>$module->enterpriseApplyPendingMirrors(),
                    'recovery'=>$module->enterpriseRecoverPendingJobs(),'jobs'=>$module->enterpriseProcessRemoteJobs($args===['--once']?false:null)];
            }
            echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
        } catch (Throwable $error) {
            fwrite(STDERR,"SLS cluster worker: exclusive authority or protected state unavailable; this cycle was fenced.\n");
            if (!$watch) { exit(1); }
        }
        if ($watch && $running) { sleep($delay); }
    } while ($watch && $running);
} catch (Throwable $error) { fwrite(STDERR,"SLS cluster worker: protected state or authority unavailable; dispatch stopped.\n"); exit(1); }
