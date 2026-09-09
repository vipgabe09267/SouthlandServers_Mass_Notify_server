#!/usr/bin/env python3
"""Exercise uninstall logging and locks without running a PBX operation."""

import os
from pathlib import Path
import pwd
import stat
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = Path(os.environ.get("SLS_UNINSTALL_TEST_SCRIPT", ROOT / "tools/uninstall_release.sh"))


@unittest.skipUnless(os.geteuid() == 0, "Root ownership fixtures require root")
class UninstallerProtectedFiles(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory(prefix="sls-uninstall-files-test-")
        self.addCleanup(self.workspace.cleanup)
        self.base = Path(self.workspace.name)
        self.sticky = self.base / "shared"
        self.sticky.mkdir(mode=0o1777)
        self.sticky.chmod(0o1777)
        self.module_log = self.sticky / "module.log"
        self.stock_log = self.sticky / "stock.log"
        self.asterisk = pwd.getpwnam("asterisk")

    def run_shell(self, body):
        prefix = 'source "$1"\nMODULE_UNINSTALL_LOG_PATH="$2"\nSTOCK_RESTORE_LOG_PATH="$3"\n'
        return subprocess.run(
            ["bash", "-c", prefix + body, "fixture", str(SCRIPT),
             str(self.module_log), str(self.stock_log)],
            capture_output=True, text=True, timeout=8,
        )

    def legacy_file(self, path, owner=None, mode=0o664):
        path.write_text("previous log\n", encoding="utf-8")
        account = owner or self.asterisk
        os.chown(path, account.pw_uid, account.pw_gid)
        path.chmod(mode)

    def assert_protected(self, path):
        metadata = path.stat()
        self.assertEqual(metadata.st_uid, 0)
        self.assertEqual(metadata.st_gid, 0)
        self.assertEqual(metadata.st_nlink, 1)
        self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o600)

    def test_new_logs_open_without_changing_shared_directory(self):
        result = self.run_shell('prepare_uninstall_logs\nprintf "module output\\n" >>"$MODULE_UNINSTALL_LOG"\nprintf "stock output\\n" >>"$STOCK_RESTORE_LOG"\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.module_log.read_text(), "module output\n")
        self.assertEqual(self.stock_log.read_text(), "stock output\n")
        self.assert_protected(self.module_log)
        self.assert_protected(self.stock_log)
        self.assertEqual(stat.S_IMODE(self.sticky.stat().st_mode), 0o1777)

    def test_legacy_asterisk_logs_adopted_in_sticky_directory(self):
        self.legacy_file(self.module_log)
        self.legacy_file(self.stock_log, mode=0o640)
        result = self.run_shell('prepare_uninstall_logs\nprintf "module output\\n" >>"$MODULE_UNINSTALL_LOG"\nprintf "stock output\\n" >>"$STOCK_RESTORE_LOG"\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.module_log.read_text(), "module output\n")
        self.assertEqual(self.stock_log.read_text(), "previous log\nstock output\n")
        self.assert_protected(self.module_log)
        self.assert_protected(self.stock_log)

    def test_logs_work_when_prepared_in_sourced_subshell(self):
        result = self.run_shell('''(
prepare_uninstall_logs
printf 'module subshell output\n' >>"$MODULE_UNINSTALL_LOG"
printf 'stock subshell output\n' >>"$STOCK_RESTORE_LOG"
)
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.module_log.read_text(), "module subshell output\n")
        self.assertEqual(self.stock_log.read_text(), "stock subshell output\n")
        self.assert_protected(self.module_log)
        self.assert_protected(self.stock_log)

    def test_writes_keep_original_inode_after_ownership_and_path_changes(self):
        self.legacy_file(self.module_log)
        result = self.run_shell('''prepare_uninstall_logs
chown asterisk:asterisk "$MODULE_UNINSTALL_LOG_PATH"
printf 'after chown\n' >>"$MODULE_UNINSTALL_LOG"
mv "$MODULE_UNINSTALL_LOG_PATH" "$MODULE_UNINSTALL_LOG_PATH.saved"
printf 'after rotation\n' >>"$MODULE_UNINSTALL_LOG"
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(Path(str(self.module_log) + ".saved").read_text(),
                         "after chown\nafter rotation\n")
        self.assertFalse(self.module_log.exists())

    def test_rejects_unrelated_owner_without_mutating_it(self):
        nobody = pwd.getpwnam("nobody")
        self.legacy_file(self.module_log, owner=nobody)
        before = self.module_log.stat()
        result = self.run_shell("prepare_uninstall_logs\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.module_log.read_text(), "previous log\n")
        after = self.module_log.stat()
        self.assertEqual((after.st_uid, after.st_gid, after.st_mode),
                         (before.st_uid, before.st_gid, before.st_mode))

    def test_rejects_symlink_hardlink_fifo_directory_and_symlink_parent(self):
        for kind in ("symlink", "hardlink", "fifo", "directory", "symlink_parent"):
            with self.subTest(kind=kind):
                case = self.base / kind
                case.mkdir()
                target = case / "sentinel"
                target.write_text("do not change\n")
                candidate = case / "candidate"
                if kind == "symlink":
                    candidate.symlink_to(target)
                elif kind == "hardlink":
                    os.link(target, candidate)
                elif kind == "fifo":
                    os.mkfifo(candidate, 0o600)
                elif kind == "directory":
                    candidate.mkdir()
                else:
                    candidate.symlink_to(self.sticky, target_is_directory=True)
                    candidate = candidate / "new.log"
                self.module_log = candidate
                result = self.run_shell("prepare_uninstall_logs\n")
                self.assertNotEqual(result.returncode, 0, kind)
                self.assertEqual(target.read_text(), "do not change\n")

    def test_invalid_second_log_stops_before_removals(self):
        self.stock_log.symlink_to(self.base / "missing")
        result = self.run_shell('''require_freepbx() { :; }
acquire_maintenance_coordination() { :; }
snapshot_local_signer() { printf 'REMOVALS STARTED\n'; }
main
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("REMOVALS STARTED", result.stdout)
        self.assertIn(str(self.stock_log), result.stdout)
        self.assertNotIn("/proc/", result.stdout)

    def test_coordination_lock_does_not_chmod_shared_directory(self):
        result = self.run_shell('''SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$(dirname "$MODULE_UNINSTALL_LOG_PATH")/maintenance.lock"
acquire_maintenance_coordination
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(stat.S_IMODE(self.sticky.stat().st_mode), 0o1777)
        self.assert_protected(self.sticky / "maintenance.lock")

    def test_legacy_service_owned_lock_is_not_adopted(self):
        lock = self.sticky / "maintenance.lock"
        self.legacy_file(lock, mode=0o600)
        result = self.run_shell('''SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$(dirname "$MODULE_UNINSTALL_LOG_PATH")/maintenance.lock"
acquire_maintenance_coordination
''')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(lock.stat().st_uid, self.asterisk.pw_uid)
        self.assertEqual(lock.read_text(), "previous log\n")


if __name__ == "__main__":
    unittest.main()
