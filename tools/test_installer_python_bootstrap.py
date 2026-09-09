#!/usr/bin/env python3
"""Test the pre-log Python bootstrap with fixture-only binaries and apt calls."""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = Path(os.environ.get("SLS_INSTALLER_TEST_SCRIPT", ROOT / "tools/install_release.sh"))


class PythonBootstrap(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="sls-python-bootstrap-test-")
        self.addCleanup(self.fixture.cleanup)
        self.base = Path(self.fixture.name)
        self.python = self.base / "python3"
        self.apt = self.base / "apt-get"
        self.calls = self.base / "apt-calls.jsonl"
        self.platform = self.base / "os-release"
        self.platform.write_text('ID=debian\nVERSION_ID="12"\n')
        self.env = dict(os.environ, FIXTURE_APT_CALLS=str(self.calls),
                        FIXTURE_PYTHON=str(self.python), FIXTURE_APT_FAILURE="")
        self.source = SCRIPT.read_text()
        self.helper = re.search(r'^ensure_installer_log_prerequisites\(\) \{.*?^\}',
                                self.source, re.M | re.S).group()
        paths = {
            "/usr/bin/python3": self.python,
            "/usr/bin/apt-get": self.apt,
            "/etc/os-release": self.platform,
            "/usr/sbin/fwconsole": self.base / "fwconsole",
            "/usr/sbin/asterisk": self.base / "asterisk",
            "/etc/freepbx.conf": self.base / "freepbx.conf",
            "/var/www/html/admin/modules": self.base / "modules",
        }
        for original, fixture_path in paths.items():
            self.helper = self.helper.replace(original, str(fixture_path))
        for name in ("fwconsole", "asterisk"):
            self.executable(self.base / name, "#!/bin/sh\nexit 88\n")
        (self.base / "freepbx.conf").touch()
        (self.base / "modules").mkdir()
        self.executable(self.apt, '''#!/usr/bin/python3
import json, os, pathlib, sys
with open(os.environ["FIXTURE_APT_CALLS"], "a") as handle:
    handle.write(json.dumps(sys.argv[1:]) + "\\n")
failure = os.environ["FIXTURE_APT_FAILURE"]
if failure == sys.argv[1]:
    raise SystemExit(31)
if sys.argv[1] == "install" and failure != "missing_after_install":
    path = pathlib.Path(os.environ["FIXTURE_PYTHON"])
    path.write_text("#!/bin/sh\\nexit " + ("3" if failure == "broken_after_install" else "0") + "\\n")
    path.chmod(0o755)
''')

    def executable(self, path, content):
        path.write_text(content)
        path.chmod(0o755)

    def run_bootstrap(self):
        command = ('set -euo pipefail\nAPT_METADATA_REFRESHED=0\n' + self.helper +
                   '\nensure_installer_log_prerequisites\nprintf "metadata=%s\\n" "$APT_METADATA_REFRESHED"\n')
        return subprocess.run(["bash", "-c", command], env=self.env,
                              capture_output=True, text=True, timeout=5)

    def apt_calls(self):
        return [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []

    def test_working_existing_python_does_not_use_apt_or_probe_freepbx(self):
        self.executable(self.python, "#!/bin/sh\nexit 0\n")
        (self.base / "fwconsole").unlink()
        self.platform.write_text("ID=unrelated\n")
        result = self.run_bootstrap()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.apt_calls(), [])
        self.assertIn("metadata=0", result.stdout)

    def test_missing_python_installs_only_python_then_checks_it(self):
        result = self.run_bootstrap()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.apt_calls(), [
            ["update"], ["install", "-y", "--no-install-recommends", "--no-remove", "python3"],
        ])
        self.assertIn("metadata=1", result.stdout)
        self.assertTrue(os.access(self.python, os.X_OK))

    def test_broken_existing_python_is_not_replaced(self):
        self.executable(self.python, "#!/bin/sh\nexit 3\n")
        original = self.python.read_bytes()
        result = self.run_bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("standard library", result.stderr)
        self.assertEqual(self.python.read_bytes(), original)
        self.assertEqual(self.apt_calls(), [])

    def test_non_executable_or_broken_link_is_not_replaced(self):
        self.python.write_text("custom binary fixture")
        result = self.run_bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.python.read_text(), "custom binary fixture")
        self.assertEqual(self.apt_calls(), [])
        self.python.unlink()
        self.python.symlink_to(self.base / "missing-binary")
        result = self.run_bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(self.python.is_symlink())
        self.assertEqual(self.apt_calls(), [])

    def test_missing_python_does_not_install_on_unidentified_platform(self):
        for platform in ("ID=ubuntu\nVERSION_ID=22.04\n", "ID=debian\nVERSION_ID=11\n", ""):
            with self.subTest(platform=platform):
                self.platform.write_text(platform)
                result = self.run_bootstrap()
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(self.apt_calls(), [])
        self.platform.write_text('ID="debian"\nVERSION_ID=12\n')
        (self.base / "freepbx.conf").unlink()
        result = self.run_bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.apt_calls(), [])

    def test_apt_and_post_install_probe_failures_are_not_success(self):
        for failure in ("update", "install", "missing_after_install", "broken_after_install"):
            with self.subTest(failure=failure):
                self.env["FIXTURE_APT_FAILURE"] = failure
                self.python.unlink(missing_ok=True)
                self.calls.unlink(missing_ok=True)
                result = self.run_bootstrap()
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("metadata=", result.stdout)
                self.assertIn("retry", result.stderr)
                self.assertEqual(len(self.apt_calls()), 1 if failure == "update" else 2)

    def test_bootstrap_runs_after_root_check_and_before_log_open(self):
        main = self.source.split("\nmain() {", 1)[1]
        root_check = main.index('if [ "${EUID:-$(id -u)}" -ne 0 ]')
        bootstrap = main.index("ensure_installer_log_prerequisites || exit 1")
        log_open = main.index('open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log')
        self.assertLess(root_check, bootstrap)
        self.assertLess(bootstrap, log_open)
        self.assertNotIn("LOG_FILE", self.helper)
        self.assertNotIn("require_freepbx", self.helper)


if __name__ == "__main__":
    unittest.main()
