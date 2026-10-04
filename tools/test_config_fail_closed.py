#!/usr/bin/env python3
"""Exercise protected settings guards as the actual unprivileged PBX account."""

import base64
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MAIN = ROOT / "slsmassnotifyserver/Slsmassnotifyserver.class.php"
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("sls_fail_closed_crypto", ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py")
crypto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(crypto)

PHP = r'''<?php
declare(strict_types=1);
namespace SlsConfigFailClosedFixture {
    final class SlsConfigCrypto {
        const MAX_FILE_BYTES = 3145728;
        static function readFile($path) {
            return \FreePBX\modules\SlsConfigCrypto::readFile($path, $GLOBALS['fixture_keyring']);
        }
        static function encode($settings) {
            return \FreePBX\modules\SlsConfigCrypto::encode($settings, $GLOBALS['fixture_keyring']);
        }
    }
}
namespace {
    require $argv[1] . '/config-crypto.php';
    require $argv[1] . '/EnterpriseAdministration.php';
    $root = $argv[2]; $operation = $argv[3]; $data = $argv[4];
    $GLOBALS['fixture_keyring'] = $root . '/keys/config-keys.json';
    $extracted = json_decode(file_get_contents($argv[1] . '/methods.json'), true, 64, JSON_THROW_ON_ERROR);
    eval('namespace FreePBX\\modules; ' . $extracted['exception']);
    $methods = str_replace('private function', 'public function', $extracted['methods']);
    $methods = str_replace('function persistAppliedSettings(', 'function persistAppliedSettingsProduction(', $methods);
    eval('namespace SlsConfigFailClosedFixture; class Module {
        use \\FreePBX\\modules\\SlsEnterpriseAdministration;
        const SETTINGS_JSON = ' . var_export($data . '/mass-notifications.config', true) . ';
        const PENDING_SETTINGS_JSON = ' . var_export($data . '/mass-notifications.pending.config', true) . ';
        const LEGACY_SETTINGS_JSON = ' . var_export($data . '/mass-notifications-settings.json', true) . ';
        const LEGACY_PENDING_SETTINGS_JSON = ' . var_export($data . '/mass-notifications-settings.pending.json', true) . ';
        const LEGACY_OLD_SETTINGS_JSON = ' . var_export($root . '/old/active.json', true) . ';
        const LEGACY_OLD_PENDING_SETTINGS_JSON = ' . var_export($root . '/old/pending.json', true) . ';
        const NATIVE_BACKUP_MAX_CONFIG_BYTES = 2097152;
        const DEFAULT_NWS_OPENING_TONE = "fixture-tone";
        public $calls = []; public $normalizedSettingsCache = []; public $settingsReadFingerprints = [];
        public function getDefaultSettings() { return ["enabled" => "0", "marker" => "fresh-default"]; }
        public function normalizeSettings($settings) { return $settings; }
        public function normalizeEmailSenderDomain($domain) { return $domain; }
        public function ensurePluginDataDir() {
            $this->calls[] = "ensurePluginDataDir";
            if (!is_dir(dirname(self::SETTINGS_JSON)) && !mkdir(dirname(self::SETTINGS_JSON), 0700, true)) {
                throw new \\RuntimeException("Fixture directory creation failed.");
            }
        }
        public function persistAppliedSettings($settings, $replaceSchedules=false, $clearPending=false) {
            $this->calls[] = "persistAppliedSettings";
            return $this->persistAppliedSettingsProduction($settings, $replaceSchedules, $clearPending);
        }
        public function assertDesktopCapacityChange($settings, $active) {}
        public function assertLivePagingPromptsReady($settings) {}
        public function acquireSettingsLock($quiesce=false) { return null; }
        public function releaseSettingsLock($lock) {}
        public function isSetupComplete($settings) { return false; }
        public function automationDependencyChecks($settings) { return []; }
        public function getPanicExtensionUsage($extensions, $settings) { return []; }
        public function normalizeLivePagingSettings($settings) { return ["enabled" => "0"]; }
        public function backupAppliedSettings() { $this->calls[] = "backupAppliedSettings"; }
        public function setPrivateOwnership($path) { chmod($path, 0640); }
        public function __call($name, $arguments) {
            if (!in_array($name, ["migrateLegacyTestStatus", "ensureBundledSystemRecordings", "ensureAmiUser",
                "ensureDialplan", "ensureSipNotifyTemplates", "ensureMenuPlacement", "ensureDashboardWidget",
                "ensureCronJob", "ensureFreePbxBackupEnrollment"], true)) { throw new \\LogicException($name); }
            $this->calls[] = $name;
            return $name === "ensureFreePbxBackupEnrollment" ? ["success" => true] : null;
        }
        ' . $methods . '}');
    $module = new \SlsConfigFailClosedFixture\Module();
    try {
        switch ($operation) {
            case 'load': $result = $module->loadSettingsFile($module::SETTINGS_JSON); break;
            case 'active': $result = $module->getActiveSettings(); break;
            case 'pending': $result = $module->getPendingSettings(); break;
            case 'fingerprint': $result = $module->settingsFileFingerprint($module::SETTINGS_JSON); break;
            case 'install': $result = $module->installUnprivilegedPhase(); break;
            case 'apply': $result = $module->applySettings(); break;
            case 'write': $result = $module->writeSettingsFileUnlocked($module::SETTINGS_JSON, ['enabled'=>'1','marker'=>'replacement'], false); break;
            case 'stage': $result = $module->writeSettingsFileUnlocked($module::PENDING_SETTINGS_JSON, ['enabled'=>'1','marker'=>'replacement'], false); break;
            case 'persist_active': $result = $module->persistAppliedSettings(['enabled'=>'1','marker'=>'replacement']); break;
            case 'persist_active_replace': $result = $module->persistAppliedSettings(['enabled'=>'1','marker'=>'replacement'], true); break;
            case 'persist_clear_pending': $result = $module->persistAppliedSettings(['enabled'=>'1','marker'=>'replacement'], false, true); break;
            case 'persist_replace_clear_pending': $result = $module->persistAppliedSettings(['enabled'=>'1','marker'=>'replacement'], true, true); break;
            case 'persist_pending': $result = $module->persistPendingSettings(['enabled'=>'1','marker'=>'replacement']); break;
            case 'cache_unreadable':
                $module->getActiveSettings(); chmod($module::SETTINGS_JSON, 0000);
                $result = $module->getActiveSettings(); break;
            case 'cache_pending_unreadable':
                $module->getPendingSettings(); chmod($module::PENDING_SETTINGS_JSON, 0000);
                $result = $module->getPendingSettings(); break;
            default: throw new \LogicException('Unknown fixture operation.');
        }
        echo json_encode(['ok'=>true, 'result'=>$result, 'calls'=>$module->calls], JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        echo json_encode(['ok'=>false, 'message'=>$error->getMessage(), 'calls'=>$module->calls], JSON_THROW_ON_ERROR);
    }
}
'''


class ConfigFailClosedTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() != 0:
            raise RuntimeError("This isolated permission fixture requires root to switch to the actual asterisk account.")
        cls.account = pwd.getpwnam("asterisk")
        # Extract exact production methods as root; the repository's home directory
        # deliberately remains private. All actual file access runs as asterisk.
        extract = r'''
        interface BMO {} require $argv[1];
        $class=new ReflectionClass(\FreePBX\modules\Slsmassnotifyserver::class);
        $source=file($class->getFileName()); $methods='';
        foreach(['loadSettingsFile','settingsFileFingerprint','configurationPathMetadata','rememberSettingsFingerprint',
                 'settingsCacheFingerprint','getActiveSettings','getPendingSettings','readNativeBackupFile',
                 'installUnprivilegedPhase','requireRuntimeAccount','writeSettingsFileUnlocked',
                 'applySettings','applyPendingSettingsTransaction','persistAppliedSettings','persistPendingSettings'] as $name){
            $method=$class->getMethod($name);
            $methods.=implode('',array_slice($source,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
        }
        $exception=new ReflectionClass(\FreePBX\modules\SlsConfigurationWriteException::class);
        echo json_encode(['methods'=>$methods,'exception'=>implode('',array_slice($source,
            $exception->getStartLine()-1,$exception->getEndLine()-$exception->getStartLine()+1))],JSON_THROW_ON_ERROR);
        '''
        extracted = subprocess.run(["php", "-r", extract, str(MAIN)], capture_output=True, text=True, timeout=5, check=True)
        if extracted.stderr:
            raise RuntimeError(extracted.stderr)
        json.loads(extracted.stdout)
        cls.source_temporary = tempfile.TemporaryDirectory(prefix="sls-config-fail-closed-source-")
        cls.source = Path(cls.source_temporary.name)
        os.chown(cls.source, 0, cls.account.pw_gid)
        cls.source.chmod(0o750)
        (cls.source / "methods.json").write_text(extracted.stdout, encoding="utf-8")
        shutil.copyfile(ROOT / "slsmassnotifyserver/api/sls-mass-notify/config-crypto.php", cls.source / "config-crypto.php")
        for name in ("EnterpriseAdministration.php", "LabsSafety.php"):
            shutil.copyfile(ROOT / "slsmassnotifyserver" / name, cls.source / name)
        for path in cls.source.iterdir():
            os.chown(path, 0, cls.account.pw_gid)
            path.chmod(0o640)

    @classmethod
    def tearDownClass(cls):
        cls.source_temporary.cleanup()

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-config-fail-closed-")
        self.root = Path(self.temporary.name)
        os.chown(self.root, 0, self.account.pw_gid)
        self.root.chmod(0o750)
        self.data = self.root / "data"
        self.old = self.root / "old"
        for directory in (self.data, self.old):
            directory.mkdir(mode=0o700)
            os.chown(directory, self.account.pw_uid, self.account.pw_gid)
        self.keyring = self.root / "keys/config-keys.json"
        self.ring = crypto.initialize_keyring(self.keyring, self.account.pw_gid)
        self.script = self.root / "fixture.php"
        self.script.write_text(PHP, encoding="utf-8")
        os.chown(self.script, 0, self.account.pw_gid)
        self.script.chmod(0o640)
        self.active = self.data / "mass-notifications.config"
        self.pending = self.data / "mass-notifications.pending.config"
        self.settings = {"enabled": "0", "marker": "protected-existing", "system_notification_emails": ""}
        self.original = crypto.encode_with_keyring(self.settings, self.ring)
        self.write_private(self.active, self.original)

    def tearDown(self):
        self.temporary.cleanup()

    def write_private(self, path, contents, mode=0o640):
        path.write_bytes(contents if isinstance(contents, bytes) else contents.encode("utf-8"))
        os.chown(path, self.account.pw_uid, self.account.pw_gid)
        path.chmod(mode)

    def run_php(self, operation, data=None):
        def drop_privileges():
            os.setgroups([self.account.pw_gid])
            os.setgid(self.account.pw_gid)
            os.setuid(self.account.pw_uid)
        process = subprocess.run(["php", str(self.script), str(self.source), str(self.root), operation, str(data or self.data)],
                                 preexec_fn=drop_privileges, capture_output=True, text=True, timeout=5)
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(process.stderr, "")
        return json.loads(process.stdout)

    def assert_preserved_rejection(self, operations, data=None):
        active_bytes = self.active.read_bytes()
        pending_bytes = self.pending.read_bytes() if self.pending.exists() else None
        for operation in operations:
            with self.subTest(operation=operation):
                result = self.run_php(operation, data)
                self.assertFalse(result["ok"], result)
                self.assertEqual(result["calls"], [])
                self.assertEqual(self.active.read_bytes(), active_bytes)
                self.assertEqual(self.pending.read_bytes() if self.pending.exists() else None, pending_bytes)
                self.assertEqual(list(self.data.glob("*.tmp.*")), [])

    def test_unreadable_existing_active_never_falls_back_or_overwrites(self):
        self.write_private(self.old / "active.json", '{"enabled":"1","marker":"legacy-fallback"}')
        self.active.chmod(0)
        self.assertEqual(self.run_php("fingerprint")["result"], "unreadable")
        self.assert_preserved_rejection(("load", "active", "install", "write", "stage"))

    def test_nonsearchable_and_inaccessible_directory_never_looks_absent(self):
        self.write_private(self.old / "active.json", '{"enabled":"1","marker":"legacy-fallback"}')
        for mode in (0o444, 0o000):
            with self.subTest(mode=oct(mode)):
                self.data.chmod(mode)
                try:
                    self.assert_preserved_rejection(("fingerprint", "load", "active", "install", "write", "stage"))
                finally:
                    self.data.chmod(0o700)

    def test_invalid_and_tampered_existing_active_never_falls_back(self):
        self.write_private(self.old / "active.json", '{"enabled":"1","marker":"legacy-fallback"}')
        envelope = json.loads(self.original)
        ciphertext = bytearray(base64.b64decode(envelope["ciphertext"]))
        ciphertext[0] ^= 1
        envelope["ciphertext"] = base64.b64encode(ciphertext).decode("ascii")
        for contents in ('{"broken":', '{"format":null,"enabled":"1"}', json.dumps(envelope)):
            self.write_private(self.active, contents)
            self.assert_preserved_rejection(("load", "active", "install", "write", "stage"))

    def test_missing_encryption_key_never_substitutes_or_overwrites_settings(self):
        self.keyring.unlink()
        self.assert_preserved_rejection(("load", "active", "install", "write", "stage"))

    def test_safe_existing_key_directory_and_key_read_permissions_are_repaired(self):
        original_keys = self.keyring.read_bytes()
        os.chown(self.keyring.parent, 0, 0)
        self.keyring.parent.chmod(0o700)
        os.chown(self.keyring, 0, 0)
        self.keyring.chmod(0o600)
        self.assertFalse(self.run_php("load")["ok"])
        self.assertEqual(crypto.initialize_keyring(self.keyring, self.account.pw_gid), self.ring)
        self.assertEqual(self.keyring.read_bytes(), original_keys)
        self.assertEqual(self.keyring.parent.stat().st_gid, self.account.pw_gid)
        self.assertEqual(self.keyring.parent.stat().st_mode & 0o777, 0o750)
        self.assertEqual(self.keyring.stat().st_gid, self.account.pw_gid)
        self.assertEqual(self.keyring.stat().st_mode & 0o777, 0o640)
        self.assertEqual(self.run_php("load")["result"]["marker"], "protected-existing")

    def test_unreadable_existing_pending_never_falls_back_or_is_overwritten(self):
        self.write_private(self.pending, self.original, 0)
        self.write_private(self.old / "pending.json", '{"enabled":"1","marker":"legacy-fallback"}')
        self.assert_preserved_rejection(("pending", "stage"))

    def test_direct_ui_apply_never_discards_unreadable_or_invalid_pending_settings(self):
        for contents, mode in ((self.original, 0o000), (b'{"broken":', 0o640)):
            self.write_private(self.pending, contents, mode)
            active_bytes = self.active.read_bytes()
            pending_bytes = self.pending.read_bytes()
            result = self.run_php("apply")
            self.assertTrue(result["ok"], result)
            self.assertFalse(result["result"]["success"], result)
            self.assertTrue(result["result"]["errors"])
            self.assertNotIn("backupAppliedSettings", result["calls"])
            self.assertEqual(self.active.read_bytes(), active_bytes)
            self.assertEqual(self.pending.read_bytes(), pending_bytes)
            self.assertEqual(list(self.data.glob("*.tmp.*")), [])

    def test_persist_mutations_preserve_unreadable_active_and_invalid_pending_settings(self):
        cases = [
            (0o000, self.original, 0o640, ("persist_active", "persist_active_replace", "persist_pending", "persist_clear_pending")),
            (0o640, self.original, 0o000, ("persist_pending", "persist_clear_pending", "persist_replace_clear_pending")),
            (0o640, b'{"broken":', 0o640, ("persist_pending", "persist_clear_pending", "persist_replace_clear_pending")),
        ]
        for active_mode, pending_bytes, pending_mode, operations in cases:
            self.active.chmod(active_mode)
            self.write_private(self.pending, pending_bytes, pending_mode)
            active_bytes = self.active.read_bytes()
            for operation in operations:
                with self.subTest(operation=operation, active_mode=oct(active_mode), pending_mode=oct(pending_mode)):
                    result = self.run_php(operation)
                    self.assertFalse(result["ok"], result)
                    self.assertNotIn("backupAppliedSettings", result["calls"])
                    self.assertEqual(self.active.read_bytes(), active_bytes)
                    self.assertEqual(self.pending.read_bytes(), pending_bytes)
                    self.assertEqual(list(self.data.glob("*.tmp.*")), [])

    def test_request_cache_does_not_hide_new_permission_failure(self):
        self.assertFalse(self.run_php("cache_unreadable")["ok"])
        self.assertEqual(self.active.read_bytes(), self.original)
        self.active.chmod(0o640)
        self.write_private(self.pending, self.original)
        self.assertFalse(self.run_php("cache_pending_unreadable")["ok"])
        self.assertEqual(self.pending.read_bytes(), self.original)

    def test_genuinely_absent_files_and_directories_remain_safe_for_fresh_install(self):
        self.active.unlink()
        self.assertEqual(self.run_php("load")["result"]["marker"], "fresh-default")
        self.assertIsNone(self.run_php("pending")["result"])
        self.assertEqual(self.run_php("fingerprint")["result"], "missing")
        result = self.run_php("install")
        self.assertTrue(result["ok"], result)
        self.assertEqual(result["calls"].count("persistAppliedSettings"), 1)
        self.assertEqual(crypto.decode_config(self.active.read_bytes(), self.keyring)["marker"], "fresh-default")
        self.assertNotIn(b"fresh-default", self.active.read_bytes())

        # Absent parents are distinct from existing parents without search access.
        nested = self.data / "absent-parent/nested"
        self.assertEqual(self.run_php("load", nested)["result"]["marker"], "fresh-default")
        self.assertTrue(self.run_php("install", nested)["ok"])
        self.assertEqual(crypto.read_config(nested / "mass-notifications.config", self.keyring)["marker"], "fresh-default")

    def test_legacy_is_used_only_when_canonical_is_genuinely_absent(self):
        self.active.unlink()
        self.write_private(self.old / "active.json", '{"enabled":"0","marker":"legacy-migration"}')
        self.assertEqual(self.run_php("active")["result"]["marker"], "legacy-migration")
        self.assertTrue(self.run_php("install")["ok"])
        self.assertEqual(crypto.read_config(self.active, self.keyring)["marker"], "legacy-migration")

    def test_symlinked_or_invalid_parent_cannot_redirect_configuration(self):
        alias = self.root / "alias"
        alias.symlink_to(self.data, target_is_directory=True)
        self.assert_preserved_rejection(("load", "active", "install", "write", "stage"), alias)
        invalid = self.root / "not-a-directory"
        invalid.write_text("fixture", encoding="ascii")
        invalid.chmod(0o640)
        os.chown(invalid, 0, self.account.pw_gid)
        self.assert_preserved_rejection(("load", "install", "write"), invalid / "nested")


if __name__ == "__main__":
    unittest.main(verbosity=2)
