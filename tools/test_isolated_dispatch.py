#!/usr/bin/python3
import importlib.util
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "isolated_dispatch_fixture", ROOT / "tools/isolated_dispatch.py"
)
dispatch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dispatch)


class DispatchTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which('asterisk'), 'Asterisk is unavailable for the nested playback fixture.')
    def test_nested_fixture_preserves_only_public_asterisk_metadata(self):
        environment = {key:value for key,value in os.environ.items()
            if key != 'SLS_TEST_NAMESPACE' and not key.startswith('SLS_TEST_PARENT_')}
        result = subprocess.run(['bash', str(ROOT / 'tools/run_isolated_tests.sh'), '--single',
            'tools/test_outbound_playback_asterisk.py'], cwd=ROOT, env=environment,
            capture_output=True, text=True, timeout=120)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('real Local call-file/AGI answer gating', result.stdout)

    def test_nested_fresh_fixture_can_read_source_without_changing_parent_state(self):
        config = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')
        before = config.read_bytes()
        inode = config.stat().st_ino
        environment = {key:value for key,value in os.environ.items()
            if key != 'SLS_TEST_NAMESPACE' and not key.startswith('SLS_TEST_PARENT_')}
        result = subprocess.run(['bash', str(ROOT / 'tools/run_isolated_tests.sh'), '--single', 'tools/test_config_crypto.py'],
            cwd=ROOT, env=environment, capture_output=True, text=True, timeout=90)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(config.read_bytes(), before)
        self.assertEqual(config.stat().st_ino, inode)

    def test_fixture_catalog_remains_an_executable_build_script(self):
        subprocess.run(["bash", "-n", str(ROOT / "tools/build_tgz.sh")], check=True)

    def test_actual_ci_linter_accepts_the_verified_upstream_php_branch(self):
        result = subprocess.run(['bash',str(ROOT/'tools/lint_source.sh')],
            cwd=ROOT,capture_output=True,text=True,timeout=90)
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        self.assertIn('PHP, shell, Python and mirrored documentation checks passed.',result.stdout)

    def test_hygiene_allows_only_the_pinned_psr_log_directory(self):
        source=(ROOT/'tools/build_tgz.sh').read_text()
        start=source.index('if find "${ROOT_DIR}/${MODULE}" -type d')
        end=source.index('\nfi\n',start)+len('\nfi\n')
        with tempfile.TemporaryDirectory(prefix='sls-hygiene-') as folder:
            module=Path(folder)/'slsmassnotifyserver'
            allowed=module/'passkey-libs/vendor/psr/log';allowed.mkdir(parents=True)
            script='ROOT_DIR="$1"; MODULE=slsmassnotifyserver\n'+source[start:end]
            def check():
                return subprocess.run(['bash','-euc',script,'fixture',folder],
                    capture_output=True,text=True,timeout=5)
            self.assertEqual(check().returncode,0)
            for name in ['log','cache','__pycache__','passkey-libs/vendor/psr/log/log']:
                generated=module/name;generated.mkdir(parents=True)
                try:self.assertNotEqual(check().returncode,0,name)
                finally:generated.rmdir()

    def test_actual_post_fixture_checks_build_the_pinned_unsigned_archive(self):
        source=(ROOT/'tools/build_tgz.sh').read_text()
        start=source.index('if find "${ROOT_DIR}/${MODULE}" -type f')
        version=ET.parse(ROOT/'slsmassnotifyserver/module.xml').findtext('version')
        script='''ROOT_DIR="$1"; MODULE=slsmassnotifyserver; VERSION="$2"; PACKAGE="$3"; UNSIGNED=1
command python3 -I "$ROOT_DIR/tools/verify_passkey_vendor.py"
'''+source[start:]
        with tempfile.TemporaryDirectory(prefix='sls-actual-package-') as folder:
            package=Path(folder)/'candidate.tgz'
            result=subprocess.run(['bash','-euc',script,'fixture',str(ROOT),version,str(package)],
                capture_output=True,text=True,timeout=60)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            self.assertTrue(package.is_file())
            self.assertIn(str(package),result.stdout)

    def test_staged_cli_worker_is_readable_after_runtime_account_handoff(self):
        result = subprocess.run(['/usr/sbin/runuser','-u','asterisk','--','/usr/bin/php',
            '/usr/local/bin/sls_mass_notify/sls_mass_notify_cluster_worker.php','--help'],
            capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('Usage: sls_mass_notify_cluster_worker.php',result.stdout)

    def test_build_dispatch_is_defined_early_and_preserves_non_test_calls(self):
        source = (ROOT / "tools/build_tgz.sh").read_text()
        start = source.index('sls_build_fixture() {')
        self.assertLess(start, source.index('VERSION='))
        end = source.index('\nnode() {', start)
        end = source.index('\n', end + 1)
        script = source[start:end] + '''
ROOT_DIR=/unused-fixture-root
php -n -r 'echo "php-ok\\n";'
python3 -I -c 'print("python-ok")'
bash -c 'printf "bash-ok\\n"'
node -e 'console.log("node-ok")'
'''
        result = subprocess.run(['bash', '-euc', script], capture_output=True,
                                text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), ['php-ok','python-ok','bash-ok','node-ok'])

    def test_catalog_is_complete_unique_and_subset_keeps_exact_paths(self):
        all_tests = dispatch.suites(ROOT, [], None)
        self.assertGreater(len(all_tests), 350)
        self.assertEqual(len(all_tests), len(set(all_tests)))
        self.assertEqual(
            dispatch.suites(ROOT, ["tools/test_operator_auth.php"], None),
            [Path("tools/test_operator_auth.php")],
        )

    def test_shuffle_is_reproducible_and_does_not_omit_suites(self):
        first = dispatch.suites(ROOT, [], 1)
        again = dispatch.suites(ROOT, [], 1)
        other = dispatch.suites(ROOT, [], 2)
        self.assertEqual(first, again)
        self.assertNotEqual(first, other)
        self.assertEqual(set(first), set(other))

    def test_unknown_or_ambiguous_selection_fails_before_any_fixture_execution(self):
        for paths in [
            ["../tools/test_operator_auth.php"],
            ["tools/no-test.php"],
            ["tools/test_missing.php"],
            ["tools/test_operator_auth.php"] * 2,
        ]:
            with self.subTest(paths=paths), self.assertRaises(ValueError):
                dispatch.suites(ROOT, paths, None)


if __name__ == "__main__":
    unittest.main()
