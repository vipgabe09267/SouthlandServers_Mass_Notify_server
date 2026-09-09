#!/usr/bin/env python3
"""Verify private installer probe storage with fixture-only AMI/HTTP commands."""

import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = Path(os.environ.get(
    "SLS_INSTALLER_UNDER_TEST", ROOT / "tools/install_release.sh"
)).resolve()
SOURCE = INSTALLER.read_text(encoding="utf-8")
LEGACY_NAMES = (
    "sls-mass-notify-ami-health.json", "sls-mass-notify-ami-health.err",
    "sls-mass-notify-pjsip-contacts.out", "sls-sipnotify-api.out",
    "sls-sipnotify-stream-api.out", "sls-control-api.out",
)


class InstallerProbeStorage(unittest.TestCase):
    def setUp(self):
        # Refuse to execute an old helper that could touch a predictable real
        # /tmp path even when its network commands are replaced by fixtures.
        for name in LEGACY_NAMES:
            self.assertNotIn("/tmp/" + name, SOURCE)
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-probe-storage-test-")
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.log = self.base / "installer.log"
        self.trace = self.base / "trace"
        self.log.write_text("")
        self.trace.write_text("")
        self.sentinel = self.base / "sentinel"
        self.sentinel.write_text("do not change\n")
        for name in LEGACY_NAMES:
            (self.base / name).symlink_to(self.sentinel)

    def run_helper(self, body, **overrides):
        environment = {
            key: value for key, value in os.environ.items()
            if not key.startswith("SLS_") and key not in {"GITHUB_TOKEN", "BASH_ENV", "ENV"}
        }
        environment.update(FIXTURE_ROOT=str(self.base), FAKE_AMI="ok", FAKE_HTTP="401",
                           FAKE_CONTACTS="ok", FAKE_TEMP="ok")
        environment.update(overrides)
        prefix = r'''
source "$1"
LOG_FILE="$FIXTURE_ROOT/installer.log"
INSTALL_LOG_OUTPUT="$LOG_FILE"
mktemp() {
  [ "$FAKE_TEMP" = "ok" ] || return 1
  [ "$1" = "-d" ] || return 91
  /usr/bin/mktemp -d "$FIXTURE_ROOT/probe.XXXXXX"
}
function /usr/bin/timeout {
  printf 'ami\n' >>"$FIXTURE_ROOT/trace"
  if [ "$FAKE_AMI" = "fail" ]; then
    printf 'fixture AMI authorization failed\n' >&2
    return 3
  elif [ "$FAKE_AMI" = "invalid" ]; then
    printf '{}\n'
  else
    printf '%s\n' '{"status":"ok","ami":"authenticated","ping":"pong","pjsip_show_contacts":"authorized_empty","pjsip_notify":"authorized"}'
  fi
}
asterisk() {
  case "$*" in
    '-rx manager reload') printf 'reload\n' >>"$FIXTURE_ROOT/trace" ;;
    '-rx pjsip show contacts')
      printf 'contacts\n' >>"$FIXTURE_ROOT/trace"
      if [ "$FAKE_CONTACTS" = "fail" ]; then
        printf 'fixture CLI inventory failure\n' >&2
        return 4
      fi
      printf 'No objects found.\n'
      ;;
    *) return 92 ;;
  esac
}
refresh_module_install() { printf 'refresh\n' >>"$FIXTURE_ROOT/trace"; }
sleep() { :; }
php() { :; }
curl() {
  local output=""
  while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
      output="$2"
      shift 2
    else
      shift
    fi
  done
  [[ "$output" == "$FIXTURE_ROOT"/probe.*/response.out ]] || return 93
  [ "$(stat -c '%a' "$(dirname "$output")")" = "700" ] || return 94
  printf 'private response body\n' >"$output"
  printf 'http:%s\n' "$output" >>"$FIXTURE_ROOT/trace"
  printf '%s' "$FAKE_HTTP"
}
'''
        result = subprocess.run(
            ["/bin/bash", "--noprofile", "--norc", "-c", prefix + body,
             "fixture", str(INSTALLER)],
            env=environment, cwd="/", capture_output=True, text=True, timeout=8,
            check=False,
        )
        self.assertEqual(list(self.base.glob("probe.*")), [], "Private probe directory leaked")
        self.assertEqual(self.sentinel.read_text(), "do not change\n")
        self.assertTrue(all((self.base / name).is_symlink() for name in LEGACY_NAMES))
        return result

    def test_ami_success_preserves_parent_exit_trap_and_cleans_files(self):
        result = self.run_helper("""
trap 'printf "parent trap\\n" >>"$FIXTURE_ROOT/trace"' EXIT
verify_ami_with_repair
""")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.trace.read_text().splitlines(), ["ami", "parent trap"])

    def test_ami_failed_command_retries_and_logs_before_cleanup(self):
        result = self.run_helper("verify_ami_with_repair\n", FAKE_AMI="fail")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(self.trace.read_text().splitlines().count("ami"), 3)
        self.assertEqual(self.trace.read_text().splitlines().count("refresh"), 3)
        self.assertIn("fixture AMI authorization failed", self.log.read_text())
        self.assertIn("failed after automatic repair", result.stdout)

    def test_invalid_ami_schema_cannot_pass(self):
        result = self.run_helper("verify_ami_with_repair\n", FAKE_AMI="invalid")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(self.trace.read_text().splitlines().count("ami"), 3)
        self.assertIn("AMI health result is incomplete", self.log.read_text())

    def test_cli_inventory_success_and_failure_clean_private_output(self):
        for mode in ("ok", "fail"):
            with self.subTest(mode=mode):
                result = self.run_helper("verify_pjsip_contact_inventory\n", FAKE_CONTACTS=mode)
                self.assertEqual(result.returncode, 0 if mode == "ok" else 1, result.stderr)
                if mode == "fail":
                    self.assertIn("fixture CLI inventory failure", self.log.read_text())
                    self.assertIn(str(self.log), result.stdout)

    def test_each_api_route_uses_private_output_and_accepts_expected_status(self):
        for path, pattern, codes, status in (
            ("/api/sipnotify/desktop", "^(401|429)$", "401/429", "401"),
            ("/api/sipnotify/desktop/stream", "^(401|429)$", "401/429", "429"),
            ("/api/sls-mass-notify/", "^(401|403|405|429)$", "401/403/405/429", "403"),
        ):
            with self.subTest(path=path):
                result = self.run_helper(
                    f"verify_local_api_route '{path}' '{pattern}' '{codes}' 'Fixture API'\n",
                    FAKE_HTTP=status,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn("private response body", self.log.read_text() + result.stdout)

    def test_api_failure_reports_route_and_status_without_response_body(self):
        result = self.run_helper(
            "verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Desktop API'\n",
            FAKE_HTTP="500",
        )
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("/api/sipnotify/desktop, got 500", result.stdout)
        self.assertNotIn("private response body", result.stdout + result.stderr + self.log.read_text())

    def test_all_helpers_stop_without_probes_if_temp_allocation_fails(self):
        for command in (
            "verify_ami_with_repair", "verify_pjsip_contact_inventory",
            "verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Desktop API'",
        ):
            with self.subTest(command=command):
                result = self.run_helper(command + "\n", FAKE_TEMP="fail")
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertIn("Unable to create private", result.stdout)
                self.assertEqual(self.trace.read_text(), "")

    def test_final_verification_calls_private_helpers_and_keeps_media_temp_reserved(self):
        self.assertIn("verify_pjsip_contact_inventory || exit 1", SOURCE)
        self.assertEqual(len(re.findall(r"^  verify_local_api_route /api/", SOURCE, re.M)), 3)
        media = SOURCE.split('  media_probe="', 1)[1].split('  if ! runuser', 1)[0]
        self.assertIn('media_fetch="$(mktemp /tmp/sls-mass-notify-render-fetch.XXXXXX)"', media)
        self.assertNotRegex(media, r'rm[^\n]*\$media_fetch')


if __name__ == "__main__":
    unittest.main(verbosity=2)
