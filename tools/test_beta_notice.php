<?php
declare(strict_types=1);
require dirname(__DIR__) . '/slsmassnotifyserver/BetaNoticeService.php';
require dirname(__DIR__) . '/slsmassnotifyserver/EnterpriseAdministration.php';
require dirname(__DIR__) . '/slsmassnotifyserver/ConfigCrypto.php';
use SLS\MassNotify\BetaNotice as Notice;
use FreePBX\modules\SlsConfigCrypto;
$checks = 0;
function checkNotice(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
function rejectNotice(callable $work, string $message): void {
    try { $work(); } catch (DomainException | InvalidArgumentException $error) { checkNotice($error->getMessage() !== '', $message); return; }
    throw new RuntimeException($message);
}
$session = []; $now = time(); $clock = 10000000000;
$challenge = Notice::begin($session, 'owner', 'private-session', $now, $clock);
$input = ['challenge' => $challenge['challenge'], 'agree' => true];
$storedChallenge=$session['_sls_beta_notice'];
$damagedSession=$session;unset($damagedSession['_sls_beta_notice']['nonce']);
rejectNotice(fn()=>Notice::accept($damagedSession,$input,'owner','private-session',$now+5,$clock+5000000000),'Incomplete warning session state escaped the documented refusal');
foreach ([['nonce'=>[]], ['actor'=>[]], ['session'=>[]], ['issued_at'=>[]], ['monotonic'=>'0'], ['revision'=>null], ['extra'=>true]] as $change) {
    $damagedSession=$session;$damagedSession['_sls_beta_notice']=array_replace($storedChallenge,$change);
    rejectNotice(fn()=>Notice::accept($damagedSession,$input,'owner','private-session',$now+5,$clock+5000000000),'Malformed warning session state escaped the documented refusal');
}
rejectNotice(fn()=>Notice::begin($damagedSession,'owner','private-session',0,$clock),'Invalid wall clock opened a warning challenge');
rejectNotice(fn()=>Notice::begin($damagedSession,'owner','private-session',$now,-1),'Invalid monotonic clock opened a warning challenge');
foreach ([0, 4999999999] as $elapsed) {
    rejectNotice(fn() => Notice::accept($session, $input, 'owner', 'private-session', $now + 5, $clock + $elapsed), 'Early acceptance bypassed five-second delay');
}
rejectNotice(fn() => Notice::accept($session, $input, 'other', 'private-session', $now + 5, $clock + 5000000000), 'Another actor accepted challenge');
rejectNotice(fn() => Notice::accept($session, $input, 'owner', 'another-session', $now + 5, $clock + 5000000000), 'Another session accepted challenge');
rejectNotice(fn() => Notice::accept($session, array_replace($input, ['agree' => 'true']), 'owner', 'private-session', $now + 5, $clock + 5000000000), 'Nonboolean agreement accepted');
rejectNotice(fn() => Notice::accept($session, $input + ['receipt' => []], 'owner', 'private-session', $now + 5, $clock + 5000000000), 'Client-supplied receipt accepted');
rejectNotice(fn() => Notice::accept($session, $input, 'owner', 'private-session', $now + 601, $clock + 601000000000), 'Expired challenge accepted');
rejectNotice(fn() => Notice::accept($session, $input, 'owner', 'private-session', $now - 1, $clock + 5000000000), 'Clock rollback accepted');
$receipt = Notice::accept($session, $input, 'owner', 'private-session', $now, $clock + 5000000000);
checkNotice(Notice::acknowledged(['beta_notice' => $receipt], 'owner'), 'Valid current warning receipt rejected');
checkNotice(!Notice::acknowledged(['beta_notice' => $receipt], 'other'), 'Legacy global receipt suppressed another administrator');
rejectNotice(fn() => Notice::accept($session, $input, 'owner', 'private-session', $now + 5, $clock + 5000000000), 'Consumed challenge replayed');
$old = $receipt; $old['receipt']['revision'] = str_repeat('a', 64);
checkNotice(!Notice::acknowledged(['beta_notice' => $old], 'owner'), 'Earlier warning revision bypassed current notice');
foreach (['0.1.6-beta','0.1.7-beta'] as $version) {
    $legacyReceipt = $receipt;
    $legacyReceipt['receipt']['revision'] = hash('sha256', $version . '\n' . Notice::TITLE . "\n" . Notice::BODY);
    checkNotice(Notice::acknowledged(['beta_notice'=>$legacyReceipt], 'owner'), 'An upgrade forgot the original administrator acceptance');
    checkNotice(!Notice::acknowledged(['beta_notice'=>$legacyReceipt], 'other'), 'An upgrade transferred acceptance to another administrator');
    $mappedLegacy = Notice::normalize($legacyReceipt);
    checkNotice(Notice::acknowledged(['beta_notice'=>$mappedLegacy], 'owner'), 'A mapped legacy receipt was forgotten');
    $legacyReceipt['receipt']['accepted_at'] = time() + 1000;
    checkNotice(!Notice::acknowledged(['beta_notice'=>$legacyReceipt], 'owner'), 'A legacy receipt bypassed future-time validation');
}
checkNotice(Notice::revision() === hash('sha256', 'sls-mass-notify-beta-warning-v1'), 'One-time acknowledgment became tied to a release or wording again');
$future = $receipt; $future['receipt']['accepted_at'] = time() + 1000;
checkNotice(!Notice::acknowledged(['beta_notice' => $future], 'owner'), 'Future receipt bypassed current notice');
checkNotice(!Notice::acknowledged(['labs_safety' => ['receipts' => ['enterprise_labs' => $receipt['receipt']]]], 'owner'), 'Labs acknowledgment bypassed beta notice');
foreach ([['schema' => 2], ['receipt' => ['actor' => 'plain']], ['receipt' => $receipt['receipt'] + ['extra' => true]]] as $bad) {
    rejectNotice(fn() => Notice::normalize($bad), 'Malformed receipt accepted');
}

$otherSession = []; $otherChallenge = Notice::begin($otherSession, 'other', 'other-session', $now, $clock);
$otherReceipt = Notice::accept($otherSession, ['challenge'=>$otherChallenge['challenge'], 'agree'=>true], 'other', 'other-session', $now, $clock + 5000000000);
$both = Notice::merge($receipt, $otherReceipt);
checkNotice(count($both['receipts']) === 2 && $both['receipt'] === null, 'Legacy migration did not retain two separate administrator receipts');
foreach (['owner','other'] as $actor) { checkNotice(Notice::acknowledged(['beta_notice'=>$both], $actor), 'Merged receipt did not acknowledge its administrator'); }
checkNotice(!Notice::acknowledged(['beta_notice'=>$both], 'Owner'), 'Username case folding reused another administrator receipt');
checkNotice(Notice::normalize($both) === $both, 'Migrated administrator receipts were not stable across normalization');
$oldAndOther = Notice::merge($old, $otherReceipt);
checkNotice(!Notice::acknowledged(['beta_notice'=>$oldAndOther], 'owner') && Notice::acknowledged(['beta_notice'=>$oldAndOther], 'other'), 'An old revision changed another administrator acknowledgment');
checkNotice(Notice::merge($oldAndOther, $receipt) === $both, 'Reacknowledging a new revision removed another administrator');
rejectNotice(fn() => Notice::normalize(['receipts'=>['admin_'.hash('sha256','other')=>$receipt['receipt']]]), 'Receipt key accepted a different administrator identity');
rejectNotice(fn() => Notice::normalize(['receipt'=>$receipt['receipt'], 'receipts'=>['admin_'.hash('sha256','owner')=>$old['receipt']]]), 'Conflicting legacy and mapped identities were accepted');
foreach (['', ' owner', 'owner ', "owner\n", str_repeat('a',201), "\xff"] as $actor) {
    rejectNotice(fn() => Notice::begin($otherSession, $actor, 'private-session', $now, $clock), 'Ambiguous or malformed administrator identity opened a warning');
}
$bounded = [];
for ($index=0; $index<Notice::MAX_RECEIPTS; $index++) {
    $actor='fixture-admin-'.$index;
    $bounded['admin_'.hash('sha256',$actor)] = array_replace($receipt['receipt'], ['actor'=>$actor]);
}
checkNotice(count(Notice::normalize(['receipts'=>$bounded])['receipts']) === Notice::MAX_RECEIPTS, 'Supported administrator receipt boundary was rejected');
rejectNotice(fn() => Notice::merge(['receipts'=>$bounded], $otherReceipt), 'Receipt map grew beyond its bounded administrator limit');

$root = sys_get_temp_dir() . '/sls-beta-notice-' . bin2hex(random_bytes(8));
if(!mkdir($root,0700)||!chmod($root,0700)){throw new RuntimeException('Unable to create the private beta-notice fixture.');}
function betaFixtureRead(string $path):string{$raw=file_get_contents($path);if(!is_string($raw)){throw new RuntimeException('Beta-notice fixture could not be read.');}return $raw;}
function betaFixtureWrite(string $path,string $bytes,int $mode=0640):void{if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($path,$mode)){throw new RuntimeException('Beta-notice fixture could not be written privately.');}}
function betaFixtureCleanup(string $root,bool $strict):void{foreach(scandir($root)?:[] as $name){if($name==='.'||$name==='..'){continue;}$path=$root.'/'.$name;$removed=is_dir($path)&&!is_link($path)?rmdir($path):unlink($path);if(!$removed&&$strict){throw new RuntimeException('Beta-notice fixture cleanup failed.');}}if(!rmdir($root)&&$strict){throw new RuntimeException('Beta-notice fixture directory cleanup failed.');}}
define('SLS_BETA_FIXTURE', $root);
class BetaNoticeFixture {
    use \FreePBX\modules\SlsBetaNotice;
    use \FreePBX\modules\SlsEnterpriseAdministration;
    const SETTINGS_JSON = SLS_BETA_FIXTURE . '/active.config';
    const PENDING_SETTINGS_JSON = SLS_BETA_FIXTURE . '/pending.config';
    public bool $admin = true, $setup = true;
    public string $username = 'owner';
    public function currentOperator(): array { return ['id' => $this->admin ? 'recovery_admin' : 'operator', 'username' => $this->username, 'operator_role' => $this->admin ? 'administrator' : 'sender']; }
    public function isSetupWizardComplete(): bool { return $this->setup; }
    public function getActiveSettings(): array { return $this->loadSettingsFile(self::SETTINGS_JSON); }
    private function loadSettingsFile($path): array { return SlsConfigCrypto::decode(betaFixtureRead($path)); }
    private function normalizeSettings(array $settings): array { if (isset($settings['beta_notice'])) { $settings['beta_notice'] = Notice::normalize($settings['beta_notice']); } return $settings; }
    private function configurationPathMetadata($path) { return file_exists($path) ? lstat($path) : null; }
    private function acquireAnnouncementActivityLock(...$args) { $fd = fopen(SLS_BETA_FIXTURE . '/activity.lock', 'c+b'); if(!is_resource($fd)||!flock($fd,LOCK_EX)){if(is_resource($fd))fclose($fd);throw new RuntimeException('Fixture activity lock failed');} return $fd; }
    private function releaseNativeBackupFileLock($fd): void { fclose($fd); }
    private function acquireSettingsLock(...$args) { $fd = fopen(SLS_BETA_FIXTURE . '/settings.lock', 'c+b'); if(!is_resource($fd)||!flock($fd,LOCK_EX)){if(is_resource($fd))fclose($fd);throw new RuntimeException('Fixture settings lock failed');} return $fd; }
    private function releaseSettingsLock($fd): void { fclose($fd); }
    private function rememberSettingsFingerprint($path): void {}
    private function writeSettingsFileUnlocked($path, $settings, $active): void { betaFixtureWrite($path,SlsConfigCrypto::encode($settings)); }
    public function savePreparedReceipt(array $notice): void { $this->saveEnterpriseNamespace('beta_notice', $notice); }
}
$passed=false;
try {
    session_save_path($root);if(session_save_path()!==$root){throw new RuntimeException('Unable to select the private beta-notice session path.');}
    session_id('sls-beta-private-' . bin2hex(random_bytes(8)));if(!session_start()){throw new RuntimeException('Unable to start the beta-notice fixture session.');}
    $active = ['existing' => ['secret' => 'preserve', 'enabled' => false]];
    $pending = $active + ['pending_only' => 'preserve'];
    betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,SlsConfigCrypto::encode($active));
    betaFixtureWrite(BetaNoticeFixture::PENDING_SETTINGS_JSON,SlsConfigCrypto::encode($pending));
    $module = new BetaNoticeFixture();
    $required=(new ReflectionClass($module))->getMethod('betaNoticeRequired');$required->setAccessible(true);
    betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,'damaged encrypted configuration');
    checkNotice($required->invoke($module)===true,'Unreadable protected settings suppressed the mandatory warning');
    betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,SlsConfigCrypto::encode($active));
    $damaged=$active+['beta_notice'=>['schema'=>99]];betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,SlsConfigCrypto::encode($damaged));
    checkNotice($required->invoke($module)===true,'Malformed receipt state suppressed the mandatory warning');
    betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,SlsConfigCrypto::encode($active));
    $module->admin = false; rejectNotice(fn() => $module->betaNoticeAction('beta_notice_begin', []), 'Restricted operator opened admin warning');
    checkNotice($required->invoke($module)===false,'Restricted operator was shown an administrator-only warning');
    $module->admin = true; $GLOBALS['sls_control_principal'] = [];
    rejectNotice(fn() => $module->betaNoticeAction('beta_notice_begin', []), 'API principal opened admin warning'); unset($GLOBALS['sls_control_principal']);
    $GLOBALS['sls_operator_portal_principal'] = [];
    rejectNotice(fn() => $module->betaNoticeAction('beta_notice_begin', []), 'Portal principal opened admin warning'); unset($GLOBALS['sls_operator_portal_principal']);
    $module->setup = false; rejectNotice(fn() => $module->betaNoticeAction('beta_notice_begin', []), 'Unfinished setup acknowledged warning'); $module->setup = true;
    $begin = $module->betaNoticeAction('beta_notice_begin', []);
    rejectNotice(fn() => $module->betaNoticeAction('beta_notice_accept', ['challenge' => $begin['challenge'], 'agree' => true]), 'Admin endpoint accepted immediately');
    $_SESSION['_sls_beta_notice']['monotonic'] -= 5000000000;
    $result = $module->betaNoticeAction('beta_notice_accept', ['challenge' => $begin['challenge'], 'agree' => true]);
    $saved = $module->getActiveSettings(); $staged = SlsConfigCrypto::decode(betaFixtureRead(BetaNoticeFixture::PENDING_SETTINGS_JSON));
    checkNotice($result['success'] && Notice::acknowledged($saved, 'owner'), 'Admin endpoint did not save acknowledgment');
    checkNotice($saved['existing'] === $active['existing'] && $staged['pending_only'] === 'preserve' && $staged['beta_notice'] === $saved['beta_notice'], 'Acknowledgment changed unrelated active or staged settings');
    checkNotice(!str_contains(betaFixtureRead(BetaNoticeFixture::SETTINGS_JSON), 'preserve'), 'Acknowledgment saved plaintext configuration');
    checkNotice($module->betaNoticeAction('beta_notice_begin', [])['acknowledged'] === true, 'Saved acknowledgment did not suppress next notice');
    $module->username = 'other';
    $beginOther = $module->betaNoticeAction('beta_notice_begin', []);
    checkNotice($beginOther['acknowledged'] === false, 'First administrator acceptance suppressed the second administrator warning');
    $_SESSION['_sls_beta_notice']['monotonic'] -= 5000000000;
    $module->betaNoticeAction('beta_notice_accept', ['challenge'=>$beginOther['challenge'], 'agree'=>true]);
    $saved = $module->getActiveSettings(); $staged = SlsConfigCrypto::decode(betaFixtureRead(BetaNoticeFixture::PENDING_SETTINGS_JSON));
    checkNotice(Notice::acknowledged($saved,'owner') && Notice::acknowledged($saved,'other') && $saved['beta_notice'] === $staged['beta_notice'], 'Second acceptance replaced the first administrator or its staged receipt');
    if(!session_write_close()){throw new RuntimeException('Unable to close the first beta-notice fixture session.');} session_id('sls-beta-repeat-' . bin2hex(random_bytes(8)));if(!session_start()){throw new RuntimeException('Unable to start the repeated beta-notice fixture session.');}
    foreach (['owner','other'] as $actor) {
        $module->username=$actor;
        checkNotice($module->betaNoticeAction('beta_notice_begin', [])['acknowledged'] === true, 'A new login lost durable administrator acceptance');
    }
    $module->username='third'; $third=$module->betaNoticeAction('beta_notice_begin', []);
    $_SESSION['_sls_beta_notice']['monotonic'] -= 5000000000;
    $beforeMismatch=betaFixtureRead(BetaNoticeFixture::SETTINGS_JSON); $module->username='owner';
    rejectNotice(fn() => $module->betaNoticeAction('beta_notice_accept', ['challenge'=>$third['challenge'],'agree'=>true]), 'A changed administrator identity accepted another user challenge');
    checkNotice(betaFixtureRead(BetaNoticeFixture::SETTINGS_JSON) === $beforeMismatch, 'Rejected identity mismatch changed protected receipt state');
    // These deltas were created before either save, as in simultaneous requests.
    // The real protected writer must merge against the latest locked contents.
    $fresh = $active + ['beta_notice'=>Notice::defaults()];
    betaFixtureWrite(BetaNoticeFixture::SETTINGS_JSON,SlsConfigCrypto::encode($fresh));
    betaFixtureWrite(BetaNoticeFixture::PENDING_SETTINGS_JSON,SlsConfigCrypto::encode($fresh + ['pending_only'=>'preserve']));
    $module->username='owner'; $module->savePreparedReceipt($receipt);
    $module->username='other'; $module->savePreparedReceipt($otherReceipt);
    $saved = $module->getActiveSettings(); $staged = SlsConfigCrypto::decode(betaFixtureRead(BetaNoticeFixture::PENDING_SETTINGS_JSON));
    checkNotice($saved['beta_notice'] === $both && $staged['beta_notice'] === $both, 'Stale simultaneous acknowledgment deltas lost an administrator receipt');
    checkNotice($saved['existing'] === $active['existing'] && $staged['pending_only'] === 'preserve', 'Per-user receipt merge changed unrelated encrypted settings');
    $passed=true;echo "$checks beta notice delay, per-administrator persistence, identity, replay and encrypted configuration checks passed.\n";
} finally { if(session_status()===PHP_SESSION_ACTIVE){session_write_close();} betaFixtureCleanup($root,$passed); }
