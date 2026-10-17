#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || count($argv)!==2 || (!in_array($argv[1],['--sources','--observe'],true) && !preg_match('/^[a-f0-9]{64}$/D',$argv[1]))) { exit(64); }
ini_set('display_errors','0'); umask(0027);
$startupLease=null;
try {
    if (posix_geteuid()===0) {
        $user=posix_getpwnam('asterisk');
        if (!$user || $user['uid']<=0 || !function_exists('pcntl_exec')) { throw new RuntimeException('A nonroot asterisk account and PHP CLI PCNTL are required.'); }
        pcntl_exec('/usr/bin/env',['-i','PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG=C.UTF-8','LC_ALL=C.UTF-8','/usr/sbin/runuser','-u','asterisk','--','/usr/bin/php',__FILE__,...array_slice($argv,1)]);
        throw new RuntimeException('Unable to start the clean runtime-account worker.');
    }
    $account=posix_getpwnam('asterisk');
    if (!$account || posix_geteuid()!==$account['uid']) { throw new RuntimeException('runtime_account_unavailable'); }
    $storeFile=__DIR__.'/sls_announcement_jobs.php';
    require_once is_file($storeFile)?$storeFile:__DIR__.'/sls_mass_notify/sls_announcement_jobs.php';
    $base='/var/lib/asterisk/SLS_Mass_Notifications_Plugin/automations';
    new SlsAnnouncementJobStore($base);
    $startupLease=(new SlsAnnouncementJobStore($base.'/workers'))->workerLease($argv[1]);
    if (!$startupLease) { exit(75); }
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true]; require '/etc/freepbx.conf';
    $module=\FreePBX::create()->Slsmassnotifyserver;
    $result=in_array($argv[1],['--sources','--observe'],true) ? $module->processAutomationSources($argv[1]==='--sources') : $module->processAutomationEvents($argv[1]);
    exit(!empty($result['success']) ? 0 : 1);
} catch (Throwable $error) { error_log('SLS trigger worker could not confirm completion; preserve the trigger journal and review its event results.'); exit(1); }
finally { if ($startupLease!==null) { SlsAnnouncementJobStore::unlock($startupLease); } }
