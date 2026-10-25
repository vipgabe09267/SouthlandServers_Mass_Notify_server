#!/usr/bin/env python3
"""Exercise the actual final module check with an inert FreePBX inventory."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "tools/install_release.sh").read_text()
START = SOURCE.index('  module_list="$(fwconsole ma list)"', SOURCE.index("verify_install()"))
CHECK = SOURCE[START:SOURCE.index("  for required_module in ", START)]
ROWS = "| Module | Version | Status |\n| framework | 17.0.1 | Enabled |\n| dashboard | 17.0.1 | Enabled |\n"
ENABLED = "| slsmassnotifyserver | 0.1.7-beta | Enabled |\n"
PHP_LINT = 'lint_staged_module_php() {' + SOURCE.split('lint_staged_module_php() {',1)[1].split('\n}\n\nstage_module_directory()',1)[0] + '\n}\n'


class InstallerModuleEnabled(unittest.TestCase):
    def check(self, listing, stdin=""):
        script = (
            "set -euo pipefail\n"
            'fwconsole() { printf "%s" "$SLS_FIXTURE_LIST"; }\n'
            'log() { printf "%s\\n" "$*"; }\n'
            + CHECK + '\nprintf "VERIFIED\\n"\n'
        )
        return subprocess.run(
            ["/bin/bash", "-c", script],
            env={**os.environ, "SLS_FIXTURE_LIST": listing}, input=stdin,
            capture_output=True, text=True, timeout=3,
        )

    def test_enabled_inventory_succeeds_with_closed_or_unrelated_stdin(self):
        for stdin in ("", "a terminal response is not module evidence\n"):
            with self.subTest(stdin=stdin):
                result = self.check(ROWS + ENABLED, stdin)
                self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
                self.assertIn("VERIFIED", result.stdout)

    def test_missing_disabled_and_unrelated_module_reject_forged_stdin(self):
        for module in ("", ENABLED.replace("Enabled", "Disabled"), ENABLED.replace("slsmassnotifyserver", "slsmassnotifyserver2")):
            with self.subTest(module=module):
                result = self.check(ROWS + module, ENABLED)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("VERIFIED", result.stdout)

    def test_large_inventory_preserves_existing_module_and_enabled_upgrade(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            listing = root / 'listing'
            listing.write_text(ROWS + ENABLED + '| unused | 17.0.1 | Enabled |\n' * 10000)
            config = root / 'config'
            config.write_text('{}')
            script = r'''source "$1"
fixture="$2"; inventory="$fixture/listing"; CONFIG_FILE="$fixture/config"
fwconsole() { cat "$inventory"; }
module_known slsmassnotifyserver || { printf 'KNOWN_CHECK_FAILED\n'; exit 90; }
INSTALL_TEMP_ROOT="$fixture"; TGZ="$fixture/archive"; SLS_MASS_NOTIFY_INVENTORY_ONLY=0
tar() { mkdir "$STAGING_DIR/slsmassnotifyserver"; printf '<module/>' >"$STAGING_DIR/slsmassnotifyserver/module.xml"; }
stage_module_directory
[ "$HAD_EXISTING_MODULE" = 1 ] && [ "$MODULE_WAS_ENABLED" = 1 ]
'''
            result = subprocess.run(['/bin/bash','-c',script,'fixture',str(ROOT/'tools/install_release.sh'),str(root)],
                                    capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_failed_inventory_does_not_authorize_a_matching_upgrade(self):
        script = r'''source "$1"
fwconsole() { printf '| slsmassnotifyserver | 0.1.6-beta | Enabled |\n'; return 17; }
if module_known slsmassnotifyserver; then exit 90; fi
'''
        result = subprocess.run(['/bin/bash','-c',script,'fixture',str(ROOT/'tools/install_release.sh')],
                                capture_output=True,text=True,timeout=5)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

        with tempfile.TemporaryDirectory() as directory:
            script = r'''source "$1"
fixture="$2"; CONFIG_FILE="$fixture/config"; printf '{}' >"$CONFIG_FILE"
INSTALL_TEMP_ROOT="$fixture"; TGZ="$fixture/archive"
fwconsole() { printf '| slsmassnotifyserver | 0.1.6-beta | Enabled |\n'; return 17; }
tar() { printf 'unexpected extraction' >"$fixture/mutated"; }
stage_module_directory
'''
            result = subprocess.run(['/bin/bash','-c',script,'fixture',str(ROOT/'tools/install_release.sh'),directory],
                                    capture_output=True,text=True,timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((Path(directory)/'mutated').exists())

    def test_final_prerequisite_loop_handles_large_complete_inventory(self):
        start = SOURCE.index('  for required_module in ', SOURCE.index('verify_install()'))
        loop = SOURCE[start:SOURCE.index("  if ! php -r '", start)]
        with tempfile.TemporaryDirectory() as directory:
            listing = Path(directory) / 'listing'
            listing.write_text(ROWS + ENABLED + '| backup | 17.0.1 | Enabled |\n| recordings | 17.0.1 | Enabled |\n'
                               + '| unused | 17.0.1 | Enabled |\n' * 10000)
            script = 'set -euo pipefail\nmodule_list="$(cat "$1")"\nlog(){ printf "%s\\n" "$*"; }\n' + loop
            result = subprocess.run(['/bin/bash','-c',script,'fixture',str(listing)], capture_output=True,text=True,timeout=5)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_large_inventory_does_not_fail_from_a_closed_pipeline(self):
        listing = ROWS + ENABLED + "| unused | 17.0.1 | Enabled |\n" * 3000
        result = self.check(listing)
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertIn("VERIFIED", result.stdout)


class StagedPhpCompatibility(unittest.TestCase):
    def lint(self, root):
        script='set -euo pipefail\nlog(){ printf "%s\\n" "$*"; }\n'+PHP_LINT+'\nlint_staged_module_php "$1"\n'
        return subprocess.run(['/bin/bash','-c',script,'fixture',str(root)],
                              capture_output=True,text=True,timeout=90)

    def fixture(self, root):
        library=Path('passkey-libs/vendor/symfony/polyfill-uuid')
        (root/library).mkdir(parents=True)
        for name in ('bootstrap80.php','bootstrap85.php'):
            shutil.copyfile(ROOT/'slsmassnotifyserver'/library/name,root/library/name)
            (root/library/name).chmod(0o644)
        return root/library

    def older_php(self):
        return int(subprocess.check_output(['/usr/bin/php','-n','-r','echo PHP_VERSION_ID;']))<80500

    def test_actual_complete_candidate_tree_stages_on_current_php(self):
        result=self.lint(ROOT/'slsmassnotifyserver')
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)

    def test_modified_loader_or_conditional_file_is_refused(self):
        if not self.older_php():self.skipTest('The conditional lint exception is only used before PHP 8.5.')
        for name in ('bootstrap80.php','bootstrap85.php'):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as folder:
                root=Path(folder);library=self.fixture(root)
                with (library/name).open('ab') as output:output.write(b'\n// changed bytes\n')
                result=self.lint(root)
                self.assertNotEqual(result.returncode,0)
                self.assertIn('compatibility profile differs',result.stdout+result.stderr)

    def test_unrelated_invalid_php_is_not_exempted(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);self.fixture(root)
            (root/'broken.php').write_text('<?php function (')
            result=self.lint(root)
            self.assertNotEqual(result.returncode,0)
            self.assertIn('invalid PHP file',result.stdout+result.stderr)

    def test_same_filename_outside_the_verified_branch_is_not_exempted(self):
        if not self.older_php():self.skipTest('The conditional syntax is valid on PHP 8.5.')
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);library=self.fixture(root)
            shutil.copyfile(library/'bootstrap85.php',root/'bootstrap85.php')
            result=self.lint(root)
            self.assertNotEqual(result.returncode,0)
            self.assertIn('invalid PHP file',result.stdout+result.stderr)

    def test_linked_conditional_file_cannot_authorize_an_exception(self):
        if not self.older_php():self.skipTest('The conditional lint exception is only used before PHP 8.5.')
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);library=self.fixture(root);path=library/'bootstrap85.php';path.unlink()
            path.symlink_to(ROOT/'slsmassnotifyserver/passkey-libs/vendor/symfony/polyfill-uuid/bootstrap85.php')
            result=self.lint(root)
            self.assertNotEqual(result.returncode,0)
            self.assertIn('compatibility profile differs',result.stdout+result.stderr)


if __name__ == "__main__":
    unittest.main()
