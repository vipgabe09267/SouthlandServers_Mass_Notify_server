#!/usr/bin/env python3
"""Exercise the actual final module check with an inert FreePBX inventory."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "tools/install_release.sh").read_text()
START = SOURCE.index('  module_list="$(fwconsole ma list)"', SOURCE.index("verify_install()"))
CHECK = SOURCE[START:SOURCE.index("  for required_module in ", START)]
ROWS = "| Module | Version | Status |\n| framework | 17.0.1 | Enabled |\n| dashboard | 17.0.1 | Enabled |\n"
ENABLED = "| slsmassnotifyserver | 0.1.7-beta | Enabled |\n"


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


if __name__ == "__main__":
    unittest.main()
