#!/usr/bin/env python3
"""Run real installer PHP helper loading across root and asterisk boundaries."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = Path(os.environ.get("SLS_INSTALLER_UNDER_TEST", ROOT / "tools/install_release.sh"))
SOURCE = INSTALLER.read_text(encoding="utf-8")
CANONICAL = ROOT / "slsmassnotifyserver/api/sls-mass-notify/config-crypto.php"
RUNTIME_PATH = "/usr/local/bin/sls_mass_notify/sls_config_crypto.php"
UNPRIVILEGED = ("local_web_probe", "verify_desktop_sse_handshake", "verify_media_access",
                "verify_control_api_authentication", "verify_fresh_install_defaults")
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("sls_installer_crypto_fixture", ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py")
crypto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(crypto)


def helper_statement(function):
    match = re.search(r"^" + re.escape(function) + r"\(\) \{\n(.*?)(?=^[A-Za-z_][A-Za-z0-9_]*\(\) \{|\Z)",
                      SOURCE, re.MULTILINE | re.DOTALL)
    if not match:
        raise AssertionError("Installer function is missing: " + function)
    statements = [line.strip() for line in match.group(1).splitlines()
                  if line.strip().startswith("require_once ")
                  and ("SLS_CONFIG_CRYPTO_PHP" in line or "sls_config_crypto.php" in line)]
    if len(statements) != 1:
        raise AssertionError("Expected one configuration crypto helper require in " + function)
    return statements[0]


class InstallerCryptoHelperTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() != 0:
            raise RuntimeError("This isolated account-boundary test requires root to run PHP as asterisk.")
        cls.account = pwd.getpwnam("asterisk")

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-installer-crypto-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        os.chown(self.root, 0, self.account.pw_gid)
        self.root.chmod(0o750)
        self.stage = self.root / "private-stage"
        self.stage.mkdir(mode=0o700)
        self.runtime = self.root / "installed-runtime"
        self.runtime.mkdir(mode=0o755)
        self.staged_helper = self.stage / "config-crypto.php"
        self.installed_helper = self.runtime / "sls_config_crypto.php"
        expected = hashlib.sha256(CANONICAL.read_bytes()).hexdigest()
        for path in (self.staged_helper, self.installed_helper):
            shutil.copyfile(CANONICAL, path)
            path.chmod(0o644)
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected)
        self.keyring = self.root / "keys/config-keys.json"
        ring = crypto.initialize_keyring(self.keyring, self.account.pw_gid)
        # The release builder uses a private umask; code-directory permissions
        # must remain searchable independently of that mask or runtime groups.
        self.runtime.chmod(0o755)
        data = self.root / "data"
        data.mkdir(mode=0o700)
        os.chown(data, self.account.pw_uid, self.account.pw_gid)
        self.configuration = data / "mass-notifications.config"
        self.configuration.write_bytes(crypto.encode_with_keyring({"enabled": "0", "marker": "fixture-preserved"}, ring))
        os.chown(self.configuration, self.account.pw_uid, self.account.pw_gid)
        self.configuration.chmod(0o640)

    def php(self, statement, as_asterisk):
        # Only replace the deployment path. The account decision and require
        # expression are the actual installer bytes being tested.
        statement = statement.replace(RUNTIME_PATH, str(self.installed_helper))
        program = statement + r'''
        $settings=\FreePBX\modules\SlsConfigCrypto::readFile($argv[1],$argv[2],false);
        echo json_encode(['uid'=>posix_geteuid(),'settings'=>$settings],JSON_THROW_ON_ERROR);
        '''
        def drop_privileges():
            os.setgroups([self.account.pw_gid])
            os.setgid(self.account.pw_gid)
            os.setuid(self.account.pw_uid)
        return subprocess.run(["php", "-r", program, str(self.configuration), str(self.keyring)],
                              env={**os.environ, "SLS_CONFIG_CRYPTO_PHP": str(self.staged_helper)},
                              preexec_fn=drop_privileges if as_asterisk else None,
                              capture_output=True, text=True, timeout=5)

    def test_each_unprivileged_verifier_uses_readable_installed_helper(self):
        for function in UNPRIVILEGED:
            with self.subTest(function=function):
                result = self.php(helper_statement(function), True)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stderr, "")
                payload = json.loads(result.stdout)
                self.assertEqual(payload["uid"], self.account.pw_uid)
                self.assertEqual(payload["settings"]["marker"], "fixture-preserved")
        self.assertEqual(self.stage.stat().st_mode & 0o777, 0o700)

    def test_root_preflight_uses_verified_private_stage_before_activation(self):
        # An old runtime is not eligible to supply executable code to root.
        self.installed_helper.write_text('<?php throw new RuntimeException("Old runtime must not run as root.");', encoding="ascii")
        result = self.php(helper_statement("validate_preserved_config_prerequisites"), False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)["uid"], 0)
        self.assertEqual(json.loads(result.stdout)["settings"]["marker"], "fixture-preserved")

    def test_original_inherited_stage_selection_is_actually_inaccessible_to_asterisk(self):
        original = 'require_once (getenv("SLS_CONFIG_CRYPTO_PHP") ?: "' + RUNTIME_PATH + '");'
        result = self.php(original, True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Permission denied", result.stderr)
        self.assertNotIn("fixture-preserved", result.stdout)
        self.assertEqual(self.stage.stat().st_mode & 0o777, 0o700)

    def test_unprivileged_missing_runtime_fails_without_opening_private_stage(self):
        self.installed_helper.unlink()
        for function in UNPRIVILEGED:
            with self.subTest(function=function):
                result = self.php(helper_statement(function), True)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("fixture-preserved", result.stdout)
                self.assertNotIn("Permission denied", result.stderr)
                self.assertIn(str(self.installed_helper), result.stderr)
        self.assertEqual(self.stage.stat().st_mode & 0o777, 0o700)


if __name__ == "__main__":
    unittest.main(verbosity=2)
