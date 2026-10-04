#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argc ?? 0)!==1) { exit(64); }
ini_set('display_errors','0');umask(0027);
require_once '/var/www/html/admin/modules/slsmassnotifyserver/AutomationPhone.php';
use SLS\MassNotify\{AutomationPhone,AutomationStore,LivePagingAgi,LivePagingState,LivePagingSession};
$agi=new LivePagingAgi(STDIN,STDOUT);
try {
    if (posix_geteuid()===0) { throw new RuntimeException('Panic AGI requires the PBX runtime account.'); }
    $environment=$agi->environment();
    if (($environment['agi_request'] ?? '')==='') { exit(64); }
    $directory='/var/lib/asterisk/SLS_Mass_Notifications_Plugin';
    $session=new AutomationPhone($agi,new LivePagingState($directory),
        static fn()=>LivePagingSession::loadSettings($directory.'/mass-notifications.config'),static fn()=>new AutomationStore(),
        static function(string $id): void {
            if (!preg_match('/^[a-f0-9]{64}$/D',$id)) { throw new RuntimeException('Invalid queued panic event.'); }
            exec('/usr/bin/timeout --kill-after=5 180 /usr/bin/php /usr/local/bin/sls_mass_notify/sls_mass_notify_automation_worker.php '.escapeshellarg($id).' >/dev/null 2>&1 &');
        }, static function(string $sound) use($directory): bool {
            if (!preg_match('/^SLS_Mass_Notifications_Plugin\/paging\/[a-f0-9]{64}$/D',$sound)) { return false; }
            $path=$directory.'/sounds/paging/'.basename($sound).'.wav'; $m=@lstat($path);
            return $m && realpath($path)===$path && ($m['mode']&0170000)===0100000 && $m['nlink']===1 && !($m['mode']&0022) && $m['size']>44 && is_readable($path);
        });
    $session->run($environment);
} catch (Throwable $error) { error_log('SLS panic shortcut could not confirm activation; review Trigger history and prompt/configuration readiness.'); }
try { $agi->hangup(); } catch (Throwable $ignored) {}
