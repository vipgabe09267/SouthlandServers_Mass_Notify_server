#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || count($argv)!==2 || (!in_array($argv[1],['--sources','--observe'],true) && !preg_match('/^[a-f0-9]{64}$/D',$argv[1]))) { exit(64); }
ini_set('display_errors','0'); umask(0027);
try {
    if (posix_geteuid()===0) {
        $user=posix_getpwnam('asterisk');
        if (!$user || !posix_initgroups('asterisk',$user['gid']) || !posix_setgid($user['gid']) || !posix_setuid($user['uid'])) { throw new RuntimeException('runtime_account_unavailable'); }
    }
    $bootstrap_settings=['freepbx_auth'=>false,'skip_astman'=>true]; require '/etc/freepbx.conf';
    $module=\FreePBX::create()->Slsmassnotifyserver;
    $result=in_array($argv[1],['--sources','--observe'],true) ? $module->processAutomationSources($argv[1]==='--sources') : $module->processAutomationEvents($argv[1]);
    exit(!empty($result['success']) ? 0 : 1);
} catch (Throwable $error) { error_log('SLS trigger worker could not confirm completion; preserve the trigger journal and review its event results.'); exit(1); }
