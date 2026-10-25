#!/usr/bin/env python3
"""Verify private installer probe storage with fixture-only AMI/HTTP commands."""

import json
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
/usr/bin/env() { while [[ "${1:-}" = -u ]]; do shift 2; done; if [[ "${1:-}" = /usr/sbin/runuser ]]; then shift; runuser "$@"; else command /usr/bin/env "$@"; fi; }
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
  elif [ "$FAKE_AMI" = "paging_missing" ]; then
    printf '%s\n' '{"status":"ok","ami":"authenticated","ping":"pong","pjsip_show_contacts":"authorized_empty","pjsip_notify":"authorized","live_paging_actions":{"PlayDTMF":"authorized","Setvar":"authorized","Getvar":"authorized"}}'
  else
    printf '%s\n' '{"status":"ok","ami":"authenticated","ping":"pong","pjsip_show_contacts":"authorized_empty","pjsip_notify":"authorized","live_paging_actions":{"MuteAudio":"authorized","PlayDTMF":"authorized","Setvar":"authorized","Getvar":"authorized"}}'
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
runuser() {
  [[ "$1 $2 $3 $4 $5" == '-u asterisk -- /usr/bin/php -r' ]] || return 95
  printf 'media-policy\n' >>"$FIXTURE_ROOT/trace"
  [ "${FAKE_MEDIA:-ok}" = ok ] || { printf 'Fixture media route cannot read protected configuration.\n' >&2; return 1; }
}
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

    def desktop_probe(self, stream, poll=None, enabled=True, corrupt_password=False):
        fixture = self.base / 'desktop-responses.json'
        fixture.write_text(json.dumps({'stream': stream, 'poll': poll or []}))
        probe = self.base / 'LocalWebProbe.php'
        probe.write_text('''<?php
namespace FreePBX\\modules;
require_once ''' + json.dumps(str(ROOT / 'slsmassnotifyserver/api/sls-mass-notify/security.php')) + ''';
final class SlsLocalWebProbe {
    public static function responses($host, $path, $headers, $timeout) {
        if ($host !== "pbx.example.test" || $timeout !== 8
            || ($headers[0] ?? "") !== "Authorization: Basic " . base64_encode("fixture-client:fixture-password")) {
            throw new \\RuntimeException("Unexpected fixture authentication or probe bounds.");
        }
        file_put_contents(getenv("DESKTOP_PROBE_TRACE"), $path . "\\n", FILE_APPEND);
        $stream = $path === "/api/sipnotify/desktop/stream?stream_seconds=1";
        if (!$stream && $path !== "/api/sipnotify/desktop?last_event_id=&limit=1") {
            throw new \\RuntimeException("Unapproved fixture URL.");
        }
        if (($headers[1] ?? "") !== ($stream ? "Accept: text/event-stream" : "Accept: application/json")) {
            throw new \\RuntimeException("Unexpected fixture transport.");
        }
        $fixture = json_decode(file_get_contents(getenv("DESKTOP_PROBE_RESPONSES")), true, 32, JSON_THROW_ON_ERROR);
        return $fixture[$stream ? "stream" : "poll"];
    }
}
''')
        block = SOURCE.split('verify_desktop_transport() {', 1)[1].split('\nverify_media_access() {', 1)[0]
        program = block.split("php -r '\n", 1)[1].split("\n' >>", 1)[0]
        config = self.base / 'desktop-config.json'
        program = program.replace('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config', str(config))
        program = program.replace('/usr/local/bin/sls_mass_notify/sls_config_crypto.php',
                                  str(ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.php'))
        program = program.replace('/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php', str(probe))
        setup = r'''
        $key = str_repeat("K", 32); $iv = str_repeat("I", 12); $tag = "";
        $cipher = openssl_encrypt("fixture-password", "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
        $settings = ["public_pbx_host"=>"pbx.example.test", "desktop_auth_key"=>base64_encode($key),
            "desktop_clients"=>[["client_id"=>"device-a", "username"=>"fixture-client",
                "enabled"=>$argv[2] === "1", "password_enc"=>$argv[3] === "1" ? "corrupt" : "v1:" . base64_encode($iv . $tag . $cipher)]]];
        file_put_contents($argv[1], json_encode($settings, JSON_THROW_ON_ERROR)); chmod($argv[1], 0600);
        '''
        self.trace.write_text('')
        result = subprocess.run(['php', '-r', setup + program, str(config), '1' if enabled else '0',
                                 '1' if corrupt_password else '0'],
                                env={**os.environ, 'DESKTOP_PROBE_RESPONSES': str(fixture),
                                     'DESKTOP_PROBE_TRACE': str(self.trace)},
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, '')
        return result.returncode, self.trace.read_text().splitlines()

    @staticmethod
    def stream_fallback():
        return {'status': 429, 'headers': ['Content-Type: application/json'],
                'body': json.dumps({'ok': False, 'error': 'stream_capacity_reached',
                    'reason': 'php_fpm_worker_pool', 'fallback': {'transport': 'json_poll',
                    'path': '/api/sipnotify/desktop', 'cursor_parameter': 'last_event_id',
                    'poll_interval_seconds': 5}})}

    @staticmethod
    def polling_baseline():
        return {'status': 200, 'headers': ['Content-Type: application/json; charset=utf-8',
                                         'Cache-Control: no-cache, no-store, must-revalidate'],
                'body': json.dumps({'ok': True, 'transport': 'json_poll', 'client': {'client_id': 'device-a'},
                    'events': [], 'count': 0, 'has_more': False, 'limit': 1, 'last_event_id': '@sls:empty',
                    'cursor_parameter': 'last_event_id', 'poll_interval_seconds': 5})}

    def test_desktop_stream_handshake_still_requires_authenticated_sse(self):
        response = {'status': 200, 'headers': ['Content-Type: text/event-stream; charset=utf-8'],
                    'body': 'event: authenticated\ndata: {"ok":true,"transport":"live_sse"}\n\n'}
        status, calls = self.desktop_probe([response])
        self.assertEqual(status, 0)
        self.assertEqual(calls, ['/api/sipnotify/desktop/stream?stream_seconds=1'])

    def test_desktop_fpm_and_full_stream_capacity_verify_authenticated_cursor_polling(self):
        for reason in ('php_fpm_worker_pool', None):
            with self.subTest(reason=reason):
                stream = self.stream_fallback()
                if reason is None:
                    body = json.loads(stream['body']); body.pop('reason'); stream['body'] = json.dumps(body)
                status, calls = self.desktop_probe([stream], [self.polling_baseline()])
                self.assertEqual(status, 4)
                self.assertEqual(calls, ['/api/sipnotify/desktop/stream?stream_seconds=1',
                                         '/api/sipnotify/desktop?last_event_id=&limit=1'])

    def test_desktop_probe_never_substitutes_polling_for_failed_auth_or_stalled_sse(self):
        for code, content_type, body in ((401, 'application/json', '{}'), (500, 'application/json', '{}'),
                (429, 'application/json', '{"ok":false,"error":"rate_limited"}'),
                (200, 'text/event-stream', ': stalled\n'), (200, 'text/html', 'event: authenticated "transport":"live_sse"')):
            with self.subTest(status=code, body=body):
                status, calls = self.desktop_probe([{'status': code, 'headers': ['Content-Type: ' + content_type], 'body': body}],
                                                    [self.polling_baseline()])
                self.assertEqual(status, 1)
                self.assertEqual(len(calls), 1)

    def test_desktop_polling_fallback_requires_exact_safe_contract(self):
        for field, value in (('transport', 'html'), ('path', 'https://attacker.invalid/'),
                             ('cursor_parameter', 'offset'), ('poll_interval_seconds', True)):
            with self.subTest(field=field):
                stream = self.stream_fallback(); body = json.loads(stream['body'])
                body['fallback'][field] = value; stream['body'] = json.dumps(body)
                status, calls = self.desktop_probe([stream], [self.polling_baseline()])
                self.assertEqual((status, len(calls)), (1, 1))
        for raw in ('[]', '{"ok":false,"ok":true}', '{"error":'):
            stream = self.stream_fallback(); stream['body'] = raw
            status, calls = self.desktop_probe([stream], [self.polling_baseline()])
            self.assertEqual((status, len(calls)), (1, 1))

    def test_desktop_polling_rejects_wrong_identity_history_and_malformed_baselines(self):
        for field, value in (('ok', False), ('client', {'client_id': 'another-device'}),
                ('transport', 'live_sse'), ('events', [{'id': 'old-alert'}]), ('count', 1),
                ('has_more', True), ('limit', 100), ('last_event_id', ''), ('last_event_id', 'bad\ncursor'),
                ('last_event_id', 'x' * 2049), ('poll_interval_seconds', 5.0)):
            with self.subTest(field=field, value=str(value)[:50]):
                poll = self.polling_baseline(); body = json.loads(poll['body']); body[field] = value
                poll['body'] = json.dumps(body)
                status, calls = self.desktop_probe([self.stream_fallback()], [poll])
                self.assertEqual((status, len(calls)), (1, 2))
        for raw in ('[]', '{"ok":true,"ok":false}', '{"ok":'):
            poll = self.polling_baseline(); poll['body'] = raw
            status, calls = self.desktop_probe([self.stream_fallback()], [poll])
            self.assertEqual((status, len(calls)), (1, 2))

    def test_desktop_polling_requires_success_json_and_uncached_response(self):
        for code, headers in ((401, ['Content-Type: application/json', 'Cache-Control: no-store']),
                (429, ['Content-Type: application/json', 'Cache-Control: no-store']),
                (500, ['Content-Type: application/json', 'Cache-Control: no-store']),
                (200, ['Content-Type: text/html', 'Cache-Control: no-store']),
                (200, ['Content-Type: application/json']),
                (200, ['Content-Type: application/json', 'Cache-Control: max-age=60'])):
            with self.subTest(status=code, headers=headers):
                poll = self.polling_baseline(); poll.update(status=code, headers=headers)
                status, calls = self.desktop_probe([self.stream_fallback()], [poll])
                self.assertEqual((status, len(calls)), (1, 2))

    def test_desktop_probe_disabled_or_invalid_credentials_start_no_transport(self):
        for enabled, corrupt, expected in ((False, False, 3), (True, True, 1)):
            status, calls = self.desktop_probe([], enabled=enabled, corrupt_password=corrupt)
            self.assertEqual((status, calls), (expected, []))

    def test_desktop_polling_success_is_reported_without_false_sse_claim(self):
        result = self.run_helper('php() { return 4; }\nverify_desktop_transport\n')
        self.assertEqual(result.returncode, 0)
        self.assertIn('JSON polling authentication and live cursor baseline verified', result.stdout)
        self.assertNotIn('live SSE authentication handshake verified', result.stdout)

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
            ("/api/sls-mass-notify/sms-callback.php", "^405$", "405", "405"),
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

    def test_media_policy_probe_runs_unprivileged_and_reports_failures(self):
        result = self.run_helper('verify_media_access\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.trace.read_text(), 'media-policy\n')
        result = self.run_helper('verify_media_access\n', FAKE_MEDIA='failed')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Fixture media route cannot read protected configuration.', self.log.read_text())
        self.assertIn('Generated-media access enforcement could not be verified', result.stdout)

    def test_missing_caller_ready_action_cannot_pass_authenticated_health(self):
        result = self.run_helper("verify_ami_with_repair\n", FAKE_AMI="paging_missing")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("live-paging authorization", self.log.read_text())

    def test_render_probe_accepts_only_exact_bytes_or_explicit_policy_denial(self):
        block = SOURCE.split('  verify_media_access || { rm -f "$media_probe" "$media_fetch"; exit 1; }', 1)[1]
        block = block.split('  log "Verifying Asterisk PJSIP contact inventory', 1)[0]
        config = self.base / 'fixture.config'
        block = block.replace('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config', str(config))
        block = block.replace(
            '/usr/local/bin/sls_mass_notify/sls_config_crypto.py',
            str(ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py'),
        )
        for code, body, restricted, expected in (
            ('200', 'fixture-image', False, 0), ('200', 'wrong-image', False, 1),
            ('403', '{"error":"media_network_denied"}', True, 0),
            ('403', '{"error":"media_network_denied"}', False, 1),
            ('403', 'Apache denied this path', True, 1), ('404', 'missing', False, 1),
        ):
            with self.subTest(code=code, restricted=restricted, body=body):
                import json
                config.write_text(json.dumps({'media_access': {'network_restricted': restricted}}))
                config.chmod(0o600)
                (self.base / 'media.png').write_text('fixture-image')
                (self.base / 'http-body').write_text(body)
                script = '''
media_probe="$FIXTURE_ROOT/media.png"
media_fetch="$FIXTURE_ROOT/fetch"
local_web_probe() { cat "$FIXTURE_ROOT/http-body" >"$2"; printf '%s' "$FAKE_HTTP"; }
identify() { printf '480 272 8 sRGB None'; }
'''
                result = self.run_helper(script + block, FAKE_HTTP=code)
                self.assertEqual(result.returncode, expected, result.stdout + result.stderr)
                self.assertFalse((self.base / 'media.png').exists())
                self.assertFalse((self.base / 'fetch').exists())

    def test_final_verification_calls_private_helpers_and_keeps_media_temp_reserved(self):
        self.assertIn("verify_pjsip_contact_inventory || exit 1", SOURCE)
        self.assertEqual(len(re.findall(r"^  verify_local_api_route /api/", SOURCE, re.M)), 4)
        media = SOURCE.split('  media_probe="', 1)[1].split('  if ! /usr/bin/env', 1)[0]
        self.assertIn('media_fetch="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-render-fetch.XXXXXX")"', media)
        self.assertNotRegex(media, r'rm[^\n]*\$media_fetch')


if __name__ == "__main__":
    unittest.main(verbosity=2)
