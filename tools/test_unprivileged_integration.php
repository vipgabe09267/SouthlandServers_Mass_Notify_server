<?php
declare(strict_types=1);
namespace SlsInstallBoundaryFixture {
    function posix_getpwnam($name) { return ['uid' => 999]; }
    function posix_geteuid() { return $GLOBALS['fixture_uid']; }
    function is_link($path) { return false; }
    function is_readable($path) { return $GLOBALS['fixture_config_exists']; }
    function exec(...$args) { throw new \RuntimeException('Unexpected privileged command in FreePBX integration.'); }
}
namespace {
    interface BMO {}
    require dirname(__DIR__) . '/slsmassnotifyserver/Slsmassnotifyserver.class.php';
    $reflection = new ReflectionClass(FreePBX\modules\Slsmassnotifyserver::class);
    $source = file($reflection->getFileName()); $methods = '';
    foreach (['install', 'installUnprivilegedPhase', 'requireRuntimeAccount', 'ensureCronJob', 'verifyUnprivilegedIntegration'] as $name) {
        $method = $reflection->getMethod($name);
        $methods .= implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
    eval('namespace SlsInstallBoundaryFixture; class Module {
        const SETTINGS_JSON = "/fixture/config";
        public $calls = []; public $FreePBX;
        private function configurationPathMetadata($path) { return $GLOBALS["fixture_config_exists"] ? ["mode" => 0100640] : null; }
        private function loadSettingsFile($path) { $this->calls[] = "loadSettingsFile"; return []; }
        function __call($name, $arguments) {
            $allowed = ["ensurePluginDataDir", "migrateLegacyTestStatus", "getActiveSettings", "persistAppliedSettings",
                "ensureBundledSystemRecordings", "ensureAmiUser", "ensureDialplan", "ensureSipNotifyTemplates",
                "ensureMenuPlacement", "ensureDashboardWidget", "ensureFreePbxBackupEnrollment", "removeLegacyNwsCronJob"];
            if (!in_array($name, $allowed, true)) { throw new \\RuntimeException("Unexpected privileged integration operation: " . $name); }
            $this->calls[] = $name;
            return $name === "ensureFreePbxBackupEnrollment" ? ["success" => true] : [];
        }
        function verifyNativePostRestoreIntegration($rootChecks) {
            if ($rootChecks !== false) { throw new \\RuntimeException("Unprivileged verification requested root operations."); }
            $this->calls[] = "verify-runtime"; return true;
        }
        ' . $methods . '}');
    class FixtureCron {
        public array $lines = [
            '* * * * * /usr/bin/timeout 99 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh',
            '17 */6 * * * /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh',
            '* * * * * /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php',
            '30 2 * * * /fixture/unrelated-job',
        ];
        function getAll() { return $this->lines; }
        function remove($line) { $this->lines = array_values(array_diff($this->lines, [$line])); }
        function addLine($line) { $this->lines[] = $line; }
    }
    class FixturePbx { public $cron; function Cron() { return $this->cron; } }
    function verify_boundary($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    $module = new SlsInstallBoundaryFixture\Module(); $module->FreePBX = new FixturePbx(); $module->FreePBX->cron = new FixtureCron();
    $GLOBALS['fixture_config_exists'] = true;
    foreach ([0, -1, 1001] as $uid) {
        $GLOBALS['fixture_uid'] = $uid;
        foreach (['install', 'verifyUnprivilegedIntegration'] as $method) {
            try { $module->$method(); throw new RuntimeException('An unauthorized service identity was accepted.'); }
            catch (RuntimeException $error) { verify_boundary(strpos($error->getMessage(), 'asterisk service account') !== false, 'Wrong rejection reason.'); }
        }
        verify_boundary($module->calls === [], 'Rejected identity caused integration mutations.');
    }
    $GLOBALS['fixture_uid'] = 999;
    verify_boundary($module->install() === true, 'Runtime account could not install its integration.');
    verify_boundary(!in_array('persistAppliedSettings', $module->calls, true), 'Existing central configuration was rewritten.');
    verify_boundary(in_array('ensureFreePbxBackupEnrollment', $module->calls, true), 'Native backup integration was skipped.');
    $lines = $module->FreePBX->cron->getAll();
    verify_boundary(in_array('30 2 * * * /fixture/unrelated-job', $lines, true), 'Unrelated cron entry was changed.');
    verify_boundary(strpos(implode("\n", $lines), 'sls_mass_notify_update.sh') === false
        && strpos(implode("\n", $lines), 'sls_mass_notify_maintenance.sh') === false, 'Root tasks were installed in user cron.');
    $module->install();
    verify_boundary($module->FreePBX->cron->getAll() === $lines, 'Repeated integration duplicated or changed cron entries.');
    verify_boundary($module->verifyUnprivilegedIntegration() === true, 'Unprivileged verification failed.');
    $GLOBALS['fixture_config_exists'] = false; $module->calls = []; $module->install();
    verify_boundary(count(array_filter($module->calls, static fn($name) => $name === 'persistAppliedSettings')) === 1, 'Fresh configuration was not initialized exactly once.');
    echo "FreePBX install account boundary, preserved configuration, idempotent user cron and unprivileged verification passed.\n";
}
