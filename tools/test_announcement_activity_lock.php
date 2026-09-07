<?php

declare(strict_types=1);

// Extract only the production locking/wrapper methods: no FreePBX bootstrap,
// installed config access, worker launch, or channel delivery is possible here.
$classPath = $argv[1] ?? dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
$traitPath = $argv[2] ?? dirname(__DIR__) . '/slsmassnotifyserver/AnnouncementDelivery.php';
$classSource = file_get_contents($classPath);
$traitSource = file_get_contents($traitPath);
$extract = static function (string $source, string $method): string {
    if (!preg_match('/^([\t ]*)private function ' . preg_quote($method, '/') . '\([^\n]*\)\n\1\{.*?^\1\}/ms', $source, $match)) {
        throw new RuntimeException('Unable to extract production method: ' . $method);
    }
    return preg_replace('/private function /', 'public function ', $match[0], 1);
};
$directory = sys_get_temp_dir() . '/sls-activity-lock-test-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) { throw new RuntimeException('Unable to create isolated test storage.'); }
$methods = '';
foreach (['acquireAnnouncementActivityLock', 'acquireNativeBackupFileLock', 'releaseNativeBackupFileLock', 'acquireSettingsLock', 'releaseSettingsLock'] as $method) {
    $extracted = $extract($classSource, $method);
    if ($method === 'acquireAnnouncementActivityLock') {
        $extracted = str_replace('function acquireAnnouncementActivityLock(', 'function acquireRealAnnouncementActivityLock(', $extracted);
    }
    $methods .= $extracted . "\n";
}
$methods .= $extract($traitSource, 'executeResolvedAnnouncement');
eval('class AnnouncementActivityFixture {
    const ANNOUNCEMENT_ACTIVITY_LOCK_FILE = ' . var_export($directory . '/activity.lock', true) . ';
    const SETTINGS_LOCK = ' . var_export($directory . '/settings.lock', true) . ';
    private $settingsActivityLocks = [];
    public $executions = 0;
    public $failExecution = false;
    public function setPrivateOwnership($path) { chmod($path, 0600); }
    public function acquireAnnouncementActivityLock($exclusive = false, $timeoutSeconds = 30) {
        return $this->acquireRealAnnouncementActivityLock($exclusive, min(1, $timeoutSeconds));
    }
    public function executeResolvedAnnouncementWithActivity(array $request, $progress = null) {
        $this->executions++;
        if ($this->failExecution) { throw new RuntimeException("Synthetic execution failure"); }
        return ["success" => true, "synthetic" => true];
    }
' . $methods . '}');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$one = new AnnouncementActivityFixture();
$two = new AnnouncementActivityFixture();
$handles = [];
try {
    $handles['first'] = $one->acquireAnnouncementActivityLock(false);
    $start = microtime(true);
    $handles['second'] = $two->acquireAnnouncementActivityLock(false);
    $assert(microtime(true) - $start < 0.5, 'Independent delivery shared locks serialized.');
    $failed = false; $start = microtime(true);
    try { $one->acquireAnnouncementActivityLock(true); }
    catch (RuntimeException $error) { $failed = true; }
    $elapsed = microtime(true) - $start;
    $assert($failed && $elapsed >= 0.9 && $elapsed < 2.5, 'Exclusive maintenance did not time out while deliveries were active.');
    $one->releaseNativeBackupFileLock($handles['first']); unset($handles['first']);
    $two->releaseNativeBackupFileLock($handles['second']); unset($handles['second']);

    $handles['maintenance'] = $one->acquireAnnouncementActivityLock(true);
    $failed = false;
    try { $two->acquireAnnouncementActivityLock(false); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed, 'Delivery entered an active maintenance replacement.');
    $result = $two->executeResolvedAnnouncement([]);
    $assert($result['success'] === false && $result['delivery_started'] === false && $two->executions === 0,
        'Maintenance timeout executed a channel or claimed delivery success.');
    $assert($result['receipts'] === [] && $result['submission_uncertain'] === false
        && $result['error_code'] === 'announcement_activity_timeout', 'Maintenance timeout did not explicitly report no replay/no submission.');
    $one->releaseNativeBackupFileLock($handles['maintenance']); unset($handles['maintenance']);

    $result = $two->executeResolvedAnnouncement([]);
    $assert($result['success'] === true && $two->executions === 1, 'Unlocked delivery did not execute exactly once.');
    $handles['maintenance'] = $one->acquireAnnouncementActivityLock(true);
    $assert(is_resource($handles['maintenance']), 'Successful execution leaked its activity lock.');
    $one->releaseNativeBackupFileLock($handles['maintenance']); unset($handles['maintenance']);
    $two->failExecution = true;
    $failed = false;
    try { $two->executeResolvedAnnouncement([]); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed, 'Synthetic execution exception was unexpectedly swallowed.');
    $handles['maintenance'] = $one->acquireAnnouncementActivityLock(true);
    $assert(is_resource($handles['maintenance']), 'Execution exception leaked its activity lock.');
    $one->releaseNativeBackupFileLock($handles['maintenance']); unset($handles['maintenance']);

    $handles['delivery'] = $one->acquireAnnouncementActivityLock(false);
    $failed = false;
    try { $two->acquireSettingsLock(true); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed, 'Active config replacement ignored a delivery activity lease.');
    $handles['plain_settings'] = $two->acquireSettingsLock();
    $assert(is_resource($handles['plain_settings']), 'Failed replacement acquired/retained settings lock before activity.');
    $two->releaseSettingsLock($handles['plain_settings']); unset($handles['plain_settings']);
    $one->releaseNativeBackupFileLock($handles['delivery']); unset($handles['delivery']);

    $handles['settings'] = $one->acquireSettingsLock(true);
    $failed = false;
    try { $two->acquireAnnouncementActivityLock(false); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed, 'Active replacement settings lease did not hold exclusive activity.');
    $one->releaseSettingsLock($handles['settings']); unset($handles['settings']);
    $handles['delivery'] = $two->acquireAnnouncementActivityLock(false);
    $assert(is_resource($handles['delivery']), 'Releasing settings did not release paired activity.');
    $two->releaseNativeBackupFileLock($handles['delivery']); unset($handles['delivery']);

    unlink(AnnouncementActivityFixture::SETTINGS_LOCK);
    symlink(AnnouncementActivityFixture::ANNOUNCEMENT_ACTIVITY_LOCK_FILE, AnnouncementActivityFixture::SETTINGS_LOCK);
    $failed = false;
    try { $one->acquireSettingsLock(true); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed, 'Unsafe settings lock was accepted.');
    $handles['delivery'] = $two->acquireAnnouncementActivityLock(false);
    $assert(is_resource($handles['delivery']), 'Settings acquisition failure leaked exclusive activity.');
    $two->releaseNativeBackupFileLock($handles['delivery']); unset($handles['delivery']);

    foreach (['backup', 'restore', 'createFreePbxBackupSnapshot', 'applyPendingSettingsTransaction',
        'persistAppliedSettings', 'persistScheduledAnnouncements', 'commitNativeRestorePayload'] as $method) {
        $pattern = '/^\t(?:public|private) function ' . preg_quote($method, '/') . '\([^\n]*\)\n\t\{.*?^\t\}/ms';
        $assert(preg_match($pattern, $classSource, $match) === 1
            && strpos($match[0], 'acquireSettingsLock(true)') !== false,
            $method . ' does not quiesce announcement activity before protected snapshot/replacement.');
    }
    $assert(strpos($extract($traitSource, 'executeResolvedAnnouncement'), 'acquireAnnouncementActivityLock(false, 30)') !== false,
        'Actual delivery does not use the bounded shared activity lease.');
    echo 'Announcement activity locking: ' . $checks . " checks passed.\n";
} finally {
    foreach ($handles as $handle) { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }
    foreach (['settings.lock', 'activity.lock'] as $name) {
        if (file_exists($directory . '/' . $name) || is_link($directory . '/' . $name)) { unlink($directory . '/' . $name); }
    }
    rmdir($directory);
}
