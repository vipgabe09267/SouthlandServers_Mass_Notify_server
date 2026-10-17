<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/ImmediateSettings.php';

use SLS\MassNotify\ImmediateSettings;

$immediateSource=file_get_contents(dirname(__DIR__).'/slsmassnotifyserver/ImmediateSettings.php');
if(!is_string($immediateSource)||!str_contains($immediateSource,'os.fchown(fd,uid,gid)')||!str_contains($immediateSource,'os.fchmod(fd,mode)')
    ||!str_contains($immediateSource,'3=>$handle')||str_contains($immediateSource,'chown($temporary')||str_contains($immediateSource,'chmod($temporary')){
    throw new RuntimeException('Immediate rollback metadata changes must remain bound to the validated open descriptor.');
}
$immediateReflection=new ReflectionClass(ImmediateSettings::class);$transactionLimit=$immediateReflection->getConstant('TRANSACTION_LIMIT');
$minimumJournal=2*(int)ceil(\FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES*4/3)+4096;
if(!is_int($transactionLimit)||$transactionLimit<$minimumJournal){throw new RuntimeException('Immediate transaction allocation cannot retain two maximum protected snapshots.');}

$checks = 0;
function immediate_check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/sls-immediate-settings-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$active = $root . '/active.config';
$pending = $root . '/pending.config';
$journal = $root . '/.sls-immediate-settings-transaction.json';
$write = static function (string $path, array $settings, bool $backup): void {
    if (file_put_contents($path, json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('fixture write failed');
    }
    chmod($path, 0600);
};
$seed = static function (array $activeValue, array $pendingValue) use ($active, $pending): array {
    file_put_contents($active, json_encode($activeValue, JSON_THROW_ON_ERROR)); chmod($active, 0600);
    file_put_contents($pending, json_encode($pendingValue, JSON_THROW_ON_ERROR)); chmod($pending, 0600);
    return [file_get_contents($active), file_get_contents($pending)];
};
$runChild = static function (string $child): array {
    $process = proc_open(['/usr/bin/timeout', '--kill-after=2', '10', PHP_BINARY, $child],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']], $pipes,
        null, null, ['bypass_shell'=>true]);
    if (!is_resource($process) || !isset($pipes[2]) || !is_resource($pipes[2])) {
        throw new RuntimeException('Crash-recovery fixture could not start its bounded child process.');
    }
    $diagnostic = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), is_string($diagnostic) ? $diagnostic : ''];
};

try {
    foreach ([["section", "section"], ['named' => 'section'], ['immediate_config_revisions']] as $invalidKeys) {
        try {
            ImmediateSettings::prepare([], null, $invalidKeys);
            immediate_check(false, 'Invalid immediate section list was accepted.');
        } catch (DomainException $error) {
            immediate_check($error->getMessage() !== '', 'Invalid immediate section list returned an empty diagnostic.');
        }
    }

    [$activeBefore, $pendingBefore] = $seed(['value'=>'active-before'], ['value'=>'pending-before']);
    ImmediateSettings::commitFiles($active, $pending, ['value'=>'active-after'], ['value'=>'pending-after'], $write);
    immediate_check(json_decode(file_get_contents($active), true)['value']==='active-after'
        && json_decode(file_get_contents($pending), true)['value']==='pending-after' && !file_exists($journal),
        'Successful paired settings update was not committed exactly once.');

    [$activeBefore, $pendingBefore] = $seed(['value'=>'active-before'], ['value'=>'pending-before']);
    try {
        ImmediateSettings::commitFiles($active, $pending, ['value'=>'active-after'], ['value'=>'pending-after'],
            static function (string $path, array $settings, bool $backup) use ($write, $pending): void {
                if ($path === $pending) { throw new RuntimeException('injected pending failure'); }
                $write($path, $settings, $backup);
            });
        immediate_check(false, 'Pending write failure was reported as committed.');
    } catch (RuntimeException $error) { immediate_check(str_contains($error->getMessage(), 'injected pending failure'), 'Pending failure was masked.'); }
    immediate_check(file_get_contents($active)===$activeBefore && file_get_contents($pending)===$pendingBefore
        && !file_exists($journal), 'Pending write failure did not restore byte-identical settings.');

    [$activeBefore, $pendingBefore] = $seed(['value'=>'active-before'], ['value'=>'pending-before']);
    $child = $root . '/crash.php';
    $source = '<?php require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/ImmediateSettings.php',true).';'
        .'$active='.var_export($active,true).';$pending='.var_export($pending,true).';'
        .'\\SLS\\MassNotify\\ImmediateSettings::commitFiles($active,$pending,["value"=>"active-after"],["value"=>"pending-after"],'
        .'static function(string $path,array $settings,bool $backup)use($active,$pending):void{'
        .'file_put_contents($path,json_encode($settings));chmod($path,0600);if($path===$active){exit(77);}});';
    file_put_contents($child, $source); chmod($child, 0600);
    [$status,$diagnostic]=$runChild($child);
    immediate_check($status===77 && file_exists($journal), 'Interrupted partial transaction did not retain recovery evidence: '.$diagnostic);
    immediate_check(ImmediateSettings::recoverFiles($active, $pending)==='rolled_back'
        && file_get_contents($active)===$activeBefore && file_get_contents($pending)===$pendingBefore,
        'Interrupted partial transaction did not restore byte-identical settings.');

    [$status,$diagnostic]=$runChild($child);
    immediate_check($status===77 && file_exists($journal), 'Second interrupted transaction did not retain recovery evidence: '.$diagnostic);
    try {
        ImmediateSettings::commitFiles($active, $pending, ['value'=>'active-only'], null, $write);
        immediate_check(false, 'Active-only update bypassed retained paired-transaction recovery.');
    } catch (RuntimeException $error) {
        immediate_check(str_contains($error->getMessage(), 'interrupted immediate configuration transaction was recovered'),
            'Active-only update did not report retained transaction recovery.');
    }
    immediate_check(file_get_contents($active)===$activeBefore && file_get_contents($pending)===$pendingBefore
        && !file_exists($journal), 'Active-only update did not recover the retained paired transaction before returning.');

    $source = '<?php require '.var_export(dirname(__DIR__).'/slsmassnotifyserver/ImmediateSettings.php',true).';'
        .'$active='.var_export($active,true).';$pending='.var_export($pending,true).';'
        .'\\SLS\\MassNotify\\ImmediateSettings::commitFiles($active,$pending,["value"=>"active-after"],["value"=>"pending-after"],'
        .'static function(string $path,array $settings,bool $backup)use($pending):void{'
        .'file_put_contents($path,json_encode($settings));chmod($path,0600);if($path===$pending){exit(78);}});';
    file_put_contents($child, $source);
    [$status,$diagnostic]=$runChild($child);
    immediate_check($status===78 && file_exists($journal), 'Interrupted completed transaction did not retain commit evidence: '.$diagnostic);
    immediate_check(ImmediateSettings::recoverFiles($active, $pending)==='committed'
        && json_decode(file_get_contents($active),true)['value']==='active-after'
        && json_decode(file_get_contents($pending),true)['value']==='pending-after',
        'Recovery rolled back a transaction whose two desired settings were committed.');

    chmod($active, 0644);
    try { ImmediateSettings::commitFiles($active, $pending, ['value'=>'x'], ['value'=>'y'], $write); immediate_check(false, 'Public settings mode was accepted.'); }
    catch (RuntimeException $error) { immediate_check(str_contains($error->getMessage(),'unsafe ownership, permissions, type or size'), 'Public settings mode was rejected for an unrelated reason.'); }
    chmod($active, 0600);
    link($active, $root.'/hardlink');
    try { ImmediateSettings::commitFiles($active, $pending, ['value'=>'x'], ['value'=>'y'], $write); immediate_check(false, 'Hard-linked settings were accepted.'); }
    catch (RuntimeException $error) { immediate_check(str_contains($error->getMessage(),'unsafe ownership, permissions, type or size'), 'Hard-linked settings were rejected for an unrelated reason.'); }
    unlink($root.'/hardlink');

    chmod($root, 0777);
    try { ImmediateSettings::recoverFiles($active, $pending); immediate_check(false, 'Publicly writable settings directory was accepted.'); }
    catch (RuntimeException $error) { immediate_check(str_contains($error->getMessage(),'storage is unsafe'), 'Unsafe settings directory was rejected for an unrelated reason.'); }
    finally { chmod($root, 0700); }

    $invalidJournal = ['schema'=>1, 'created_at'=>time(), 'active_name'=>'active.config',
        'pending_name'=>'pending.config', 'active_before'=>['present'=>false],
        'pending_before'=>['present'=>false], 'active_after'=>str_repeat('a',64),
        'unexpected'=>str_repeat('b',64)];
    file_put_contents($journal, json_encode($invalidJournal, JSON_THROW_ON_ERROR)); chmod($journal, 0600);
    try { ImmediateSettings::recoverFiles($active, $pending); immediate_check(false, 'Journal with substituted field was accepted.'); }
    catch (RuntimeException $error) { immediate_check(str_contains($error->getMessage(),'evidence is invalid'), 'Malformed journal was rejected for an unrelated reason.'); }
    unlink($journal);

    [$activeBefore, $pendingBefore] = $seed(['value'=>'active-before'], ['value'=>'pending-before']);
    $deep = [];
    for ($index = 0; $index < 66; $index++) { $deep = ['nested' => $deep]; }
    foreach ([['value'=>new stdClass()], ['value'=>$deep],
        ['value'=>str_repeat('x', \FreePBX\modules\SlsConfigCrypto::MAX_FILE_BYTES + 1)]] as $invalidSettings) {
        try {
            ImmediateSettings::commitFiles($active, $pending, $invalidSettings, $invalidSettings, $write);
            immediate_check(false, 'Unsupported immediate configuration structure was accepted.');
        } catch (DomainException $error) {
            immediate_check($error->getMessage() !== '', 'Unsupported immediate configuration returned an empty diagnostic.');
        }
        immediate_check(file_get_contents($active)===$activeBefore && file_get_contents($pending)===$pendingBefore
            && !file_exists($journal), 'Rejected immediate configuration changed protected settings or created recovery evidence.');
    }

    echo "Immediate settings: {$checks} validation, paired commit, rollback, crash recovery and unsafe-file checks passed.\n";
} finally {
    foreach (glob($root.'/*') ?: [] as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($root.'/.sls-*') ?: [] as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    rmdir($root);
}
