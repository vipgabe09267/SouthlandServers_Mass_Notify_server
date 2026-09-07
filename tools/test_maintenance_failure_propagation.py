#!/usr/bin/env python3
"""Behavioral updater/maintenance regressions; every external action is mocked.

SLS_RELIABILITY_ROOT may point to a staged tree containing slsmassnotifyserver/
and tools/. No installed worker, installer, config, API, or network is used.
"""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


ROOT = Path(os.environ.get("SLS_RELIABILITY_ROOT", Path(__file__).resolve().parents[1]))
BIN = ROOT / "slsmassnotifyserver" / "bin"
SERVICE_ACCOUNT = 'account = pwd.getpwnam("asterisk")'
FIXTURE_ACCOUNT = 'account = type("Account", (), {"pw_uid": os.getuid(), "pw_gid": os.getgid()})()'


def executable(path, text):
    path.write_text(text, encoding="utf-8")
    path.chmod(0o755)


class UpdaterFailures(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="sls-update-regression-")
        self.addCleanup(self.fixture.cleanup)
        self.base = Path(self.fixture.name)
        self.mock_bin = self.base / "bin"
        self.mock_bin.mkdir()
        self.env = dict(os.environ, MOCK_BIN=str(self.mock_bin), MOCK_FAILURE="",
                        MOCK_SENTINEL=str(self.base / "installer-called"),
                        CONFIG_LOADER=str(self.base / "config-loader.py"),
                        CONFIG_JSON_FILE=str(self.base / "fixture.config"),
                        STATUS_FILE=str(self.base / "status.json"),
                        UPDATE_PROGRESS_FILE=str(self.base / "progress.json"),
                        LOG_FILE=str(self.base / "log"), LOCK_FILE=str(self.base / "lock"),
                        SLS_MASS_NOTIFY_MANUAL_UPDATE="1", SLS_MASS_NOTIFY_CHECK_ONLY="0")
        executable(Path(self.env["CONFIG_LOADER"]), "# fixture loader; intercepted by mock Python\n")
        executable(self.mock_bin / "python3", '''#!/usr/bin/python3
import json, os, subprocess, sys
failure = os.environ["MOCK_FAILURE"]
if len(sys.argv) > 1 and sys.argv[1] == os.environ["CONFIG_LOADER"]:
    if failure == "config": raise SystemExit(23)
    sys.stdout.buffer.write(b"GITHUB_UPDATES_ENABLED\\0" + b"1\\0")
    raise SystemExit(0)
if len(sys.argv) > 1 and sys.argv[1].endswith("/sls_release_verify.py"):
    raise SystemExit(4 if failure == "verification" else 0)
data = sys.stdin.read() if len(sys.argv) > 1 and sys.argv[1] == "-" else None
if data and 'repo = os.environ.get("REPOSITORY"' in data:
    if failure == "feed":
        print(json.dumps({"ok": False, "message": "secret-fixture-must-not-leak"}))
    elif failure == "unexpected":
        raise SystemExit(19)
    else:
        print(json.dumps({"ok": True, "update_available": failure != "current",
            "latest_version": "99.0.0", "tgz_url": "https://fixture.invalid/package.tgz",
            "sha256": "a" * 64, "installer_url": "https://fixture.invalid/install_release.sh"}))
    raise SystemExit(0)
raise SystemExit(subprocess.run([sys.executable, *sys.argv[1:]], input=data, text=True).returncode)
''')
        executable(self.mock_bin / "curl", '''#!/usr/bin/python3
import os, pathlib, sys
failure = os.environ["MOCK_FAILURE"]
if failure == "download": raise SystemExit(22)
output = pathlib.Path(sys.argv[sys.argv.index("-o") + 1])
if output.name == "install_release.sh":
    content = "#!/usr/bin/env bash\\nprintf 'called' >> \\\"$MOCK_SENTINEL\\\"\\nexit " + ("17" if failure == "installer" else "0") + "\\n"
    if failure == "syntax": content = "#!/usr/bin/env bash\\necho 'unclosed\\n"
    output.write_text(content)
else:
    output.write_text("verified fixture bytes")
''')
        source = (BIN / "sls_mass_notify_update.sh").read_text()
        source = source.replace('PATH="/usr/local/sbin:', 'PATH="$MOCK_BIN:/usr/local/sbin:')
        source = source.replace("/usr/bin/python3", str(self.mock_bin / "python3"))
        source = source.replace("/run/lock/sls-mass-notify-update-status.lock", str(self.base / "status.lock"))
        source = source.replace(SERVICE_ACCOUNT, FIXTURE_ACCOUNT)
        source = source.replace('if [ "${EUID:-$(id -u)}" -ne 0 ]; then', 'if false; then')
        executable(self.base / "update.sh", source)

    def run_update(self, failure, check_only=False):
        self.env["MOCK_FAILURE"] = failure
        self.env["SLS_MASS_NOTIFY_CHECK_ONLY"] = "1" if check_only else "0"
        result = subprocess.run(["bash", str(self.base / "update.sh")], env=self.env,
                                capture_output=True, text=True, timeout=10)
        progress = json.loads((self.base / "progress.json").read_text()) if (self.base / "progress.json").exists() else {}
        return result, progress

    def test_failure_codes_categories_and_no_false_completion(self):
        cases = [("config", 2, "protected_config_validation_failed"),
                 ("feed", 1, "release_check_failed"),
                 ("download", 1, "download_failed"),
                 ("verification", 1, "release_verification_failed"),
                 ("syntax", 2, "update_script_syntax_error"),
                 ("installer", 17, "install_command_failed"),
                 ("unexpected", 19, "process_failed")]
        for failure, code, category in cases:
            with self.subTest(failure=failure):
                (self.base / "installer-called").unlink(missing_ok=True)
                result, progress = self.run_update(failure)
                self.assertEqual(result.returncode, code, result.stderr)
                self.assertEqual(progress.get("state"), "failed")
                self.assertEqual(progress.get("error_category"), category)
                self.assertEqual(progress.get("exit_code"), code)
                status = json.loads((self.base / "status.json").read_text())
                self.assertIs(status.get("ok"), False)
                self.assertEqual(status.get("error_category"), category)
                self.assertNotIn("secret-fixture", json.dumps(progress) + json.dumps(status))
                self.assertNotIn("Automatic update completed", (self.base / "log").read_text())
                self.assertEqual((self.base / "installer-called").exists(), failure == "installer")

    def test_failed_check_only_is_nonzero(self):
        result, progress = self.run_update("feed", check_only=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(progress.get("state"), "failed")
        self.assertFalse((self.base / "installer-called").exists())

    def test_success_and_current_report_complete(self):
        for failure in ("", "current"):
            with self.subTest(failure=failure):
                result, progress = self.run_update(failure)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(progress.get("state"), "complete")
                self.assertEqual(progress.get("exit_code"), 0)


class MaintenanceFailures(unittest.TestCase):
    def run_fixture(self, updater):
        with tempfile.TemporaryDirectory(prefix="sls-maintenance-regression-") as directory:
            base = Path(directory)
            runtime = base / "runtime"
            runtime.mkdir()
            executable(runtime / "sls_mass_notify_update.sh", updater)
            executable(runtime / "sls_storage_maintenance.py", "raise SystemExit(0)\n")
            (base / "update.request").touch()
            source = (BIN / "sls_mass_notify_maintenance.sh").read_text()
            for old, new in {
                "/var/lib/asterisk/SLS_Mass_Notifications_Plugin": str(base),
                "/usr/local/bin/sls_mass_notify": str(runtime),
                "/var/log/sls_mass_notify.log": str(base / "log"),
                "/run/lock/sls-mass-notify-maintenance.lock": str(base / "lock"),
                "/run/asterisk/sls-mass-notify-maintenance-progress.json": str(base / "maintenance.json"),
                "/var/www/html/admin/modules/slsmassnotifyserver": str(base / "module"),
                "/var/www/html/admin/modules/dashboard": str(base / "dashboard"),
                "/var/www/html/admin/views/menu_items.php": str(base / "menu.php"),
            }.items():
                source = source.replace(old, new)
            source = source.replace(SERVICE_ACCOUNT, FIXTURE_ACCOUNT)
            source = source.replace('[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1', "secure_central_config() { :; }\n")
            # Fixture-owned markers are valid even when this test is not root.
            source = source.replace('[ "$owner" != "asterisk" ] && [ "$owner" != "root" ]', "false")
            executable(base / "maintenance.sh", source)
            result = subprocess.run(["bash", str(base / "maintenance.sh")], capture_output=True,
                                    text=True, timeout=10, env=dict(os.environ, UPDATE_PROGRESS_FILE=str(base / "update-progress.json")))
            progress = json.loads((base / "update-progress.json").read_text())
            log = (base / "log").read_text()
            return result, progress, log

    def test_syntax_error_is_fatal_before_updater_execution(self):
        result, progress, log = self.run_fixture("#!/bin/bash\necho 'unclosed\n")
        self.assertEqual(result.returncode, 2, result.stderr)
        self.assertEqual(progress.get("error_category"), "update_script_syntax_error")
        self.assertNotIn("completed successfully", log)

    def test_child_failure_preserves_exit_and_specific_category(self):
        script = "#!/bin/bash\nprintf '%s' '{\"state\":\"failed\",\"error_category\":\"protected_config_validation_failed\",\"exit_code\":23}' > \"$UPDATE_PROGRESS_FILE\"\nexit 23\n"
        result, progress, log = self.run_fixture(script)
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertEqual(progress.get("error_category"), "protected_config_validation_failed")
        self.assertNotIn("completed successfully", log)

    def test_silent_zero_exit_is_not_success(self):
        result, progress, log = self.run_fixture("#!/bin/bash\nexit 0\n")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(progress.get("state"), "failed")
        self.assertNotIn("completed successfully", log)

    def test_confirmed_completion_is_success(self):
        script = "#!/bin/bash\nprintf '%s' '{\"state\":\"complete\"}' > \"$UPDATE_PROGRESS_FILE\"\nexit 0\n"
        result, progress, log = self.run_fixture(script)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(progress.get("state"), "complete")
        self.assertIn("completed successfully", log)


class InstallerFailures(unittest.TestCase):
    def test_registered_module_does_not_hide_failed_install_command(self):
        with tempfile.TemporaryDirectory(prefix="sls-installer-regression-") as directory:
            base = Path(directory)
            source = (ROOT / "tools" / "install_release.sh").read_text()
            # Define real installer functions but replace every operational
            # helper before main runs; absolute runtime commands are fenced too.
            source = source.replace("/usr/local/", str(base / "not-installed") + "/")
            executable(base / "installer.sh", source)
            functions = re.findall(r"^([a-z_][a-z0-9_]*)\(\) \{", source, re.M)
            overrides = "\n".join(name + "() { :; }" for name in functions if name not in {"main", "open_root_owned_file"})
            command = '''source "$1"
eval "$2"
LOG_FILE="$3"
guard_config_on_exit() { exit $?; }
log() { printf '%s\\n' "$*"; }
asterisk() { :; }
install_module_with_autoenable() { return 17; }
module_registered_at_expected_version() { return 0; }
main
'''
            result = subprocess.run(["bash", "-c", command, "_", str(base / "installer.sh"),
                                     overrides, str(base / "log")], capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 17, result.stderr)
            self.assertNotIn("install finished", result.stdout)
            self.assertNotIn("nonfatal", result.stdout)

    def test_installed_updater_syntax_is_a_fatal_verification_gate(self):
        with tempfile.TemporaryDirectory(prefix="sls-shell-gate-regression-") as directory:
            base = Path(directory)
            for name in ("nws_poll", "weather_poll", "test", "update", "maintenance",
                         "uninstall", "install_piper_voices"):
                executable(base / ("sls_mass_notify_" + name + ".sh"), "#!/bin/bash\nexit 0\n")
            executable(base / "sls_mass_notify_update.sh", "#!/bin/bash\necho 'unclosed\n")
            command = 'source "$1"; LOG_FILE="$2/log"; verify_runtime_shell_syntax "$2"'
            result = subprocess.run(["bash", "-c", command, "_", str(ROOT / "tools" / "install_release.sh"), str(base)],
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertIn("invalid", result.stdout)

    def test_lock_and_log_open_rejects_links_and_non_regular_files(self):
        with tempfile.TemporaryDirectory(prefix="sls-root-file-regression-") as directory:
            base = Path(directory)
            sentinel = base / "sentinel"
            sentinel.write_text("do-not-truncate")
            sentinel.chmod(0o600)
            symlink = base / "symlink"
            symlink.symlink_to(sentinel)
            fifo = base / "fifo"
            os.mkfifo(fifo, 0o600)
            command = 'source "$1"; ROOT_FILE_FD=""; open_root_owned_file ROOT_FILE_FD "$2"'
            for unsafe in (symlink, fifo):
                result = subprocess.run(["bash", "-c", command, "_", str(ROOT / "tools" / "install_release.sh"), str(unsafe)],
                                        capture_output=True, text=True, timeout=5)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(sentinel.read_text(), "do-not-truncate")
            os.link(sentinel, base / "hardlink")
            result = subprocess.run(["bash", "-c", command, "_", str(ROOT / "tools" / "install_release.sh"), str(base / "hardlink")],
                                    capture_output=True, text=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(sentinel.read_text(), "do-not-truncate")

    def test_full_staged_config_validation_is_fatal(self):
        with tempfile.TemporaryDirectory(prefix="sls-staged-config-regression-") as directory:
            base = Path(directory)
            loader = base / "slsmassnotifyserver" / "bin" / "sls_mass_notify" / "sls_config.py"
            loader.parent.mkdir(parents=True)
            executable(loader, "raise SystemExit(23)\n")
            command = 'source "$1"; LOG_FILE="$2/log"; STAGING_DIR="$2"; CONFIG_HASH_BEFORE=fixture; CONFIG_FILE="$2/unused.config"; validate_staged_central_config'
            result = subprocess.run(["bash", "-c", command, "_", str(ROOT / "tools" / "install_release.sh"), str(base)],
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 23, result.stderr)
            self.assertIn("activation was not started", result.stdout)

    def test_apache_configtest_failure_block_is_fatal(self):
        source = (ROOT / "tools" / "install_release.sh").read_text()
        block = re.search(r'  /usr/sbin/apache2ctl configtest >>"\$LOG_FILE" 2>&1 \|\| \{.*?\n  \}', source, re.S)
        self.assertIsNotNone(block)
        command = 'log() { printf "%s\\n" "$*"; }; LOG_FILE=/dev/null;\n'
        command += block.group().replace("/usr/sbin/apache2ctl configtest", "false")
        command += '\nprintf "install finished\\n"\n'
        result = subprocess.run(["bash", "-c", command], capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertNotIn("install finished", result.stdout)


if __name__ == "__main__":
    unittest.main()
