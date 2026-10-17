#!/usr/bin/env python3
"""Exercise the real manual Weather queue function against temporary reservations."""
import json
import fcntl
import concurrent.futures
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_test.sh').read_text()
FUNCTION = SOURCE[SOURCE.index('queue_test_audio_to_recipients() {'):SOURCE.index('\nwait_for_test_call_pickup() {')]
AUTHORITY_FUNCTION = SOURCE[SOURCE.index('require_direct_test_authority() {'):SOURCE.index('refresh_api_test_permissions() {')]
PERMISSION_FUNCTION = SOURCE[SOURCE.index('refresh_api_test_permissions() {'):SOURCE.index('claim_test_cooldown() {')]
HELPER_MODULES = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
COOLDOWN_FUNCTION = SOURCE[SOURCE.index('claim_test_cooldown() {'):SOURCE.index('\n# Periodic storage maintenance', SOURCE.index('claim_test_cooldown() {'))]


class ManualWeatherReservationTests(unittest.TestCase):
    def test_status_writer_returns_helper_failure(self):
        function = SOURCE[SOURCE.index('update_status() {'):SOURCE.index('\nreport_fault() {')]
        script = 'STATUS_FILE=/unused/private-fixture\npython3() { return 9; }\nchmod() { :; }\nchown() { :; }\n'
        script += function + '\nupdate_status "{}"\nexit "$?"'
        result = subprocess.run(['bash'], input=script, text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 9, 'Status helper failure was hidden by metadata commands')

    def test_final_status_failure_marks_test_result_as_partial(self):
        start = SOURCE.index("\nif ! update_status \"$(printf '{\"last_delivery_at")
        end = SOURCE.index('\nCURRENT_TIME=', start)
        block = SOURCE[start:end]
        script = '''
DELIVERY_TS=fixture TEST_DELIVERY_STATUS=submitted TEST_AUDIO_LABEL=fixture TEST_DELIVERY_MESSAGE=fixture
DELIVERY_TARGETS=1000 DESKTOP_DELIVERY_TARGETS=fixture TEST_DELIVERY_STAGE=
NWS_ALERT_RECIPIENTS=(1000) NWS_DESKTOP_CLIENTS=(fixture) DELIVERY_FAILURES=()
update_status() { return 9; }
'''
        script += block + '\n[ "${#DELIVERY_FAILURES[@]}" -gt 0 ]'
        result = subprocess.run(['bash'], input=script, text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, 'Failed status persistence still presented a successful test')

    def test_status_writer_does_not_change_a_substituted_target(self):
        function = SOURCE[SOURCE.index('update_status() {'):SOURCE.index('\nreport_fault() {')]
        with tempfile.TemporaryDirectory(prefix='sls-test-status-substitution-') as temporary:
            root = Path(temporary)
            target = root / 'evidence'
            target.write_bytes(b'preserve'); target.chmod(0o600)
            before = target.stat()
            status = root / 'status.json'
            script = 'STATUS_FILE=' + shlex.quote(str(status)) + '\nTARGET=' + shlex.quote(str(target))
            script += '\npython3() { ln -s "$TARGET" "$STATUS_FILE"; }\n' + function + '\nupdate_status "{}"'
            result = subprocess.run(['bash'], input=script, text=True, capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            after = target.stat()
            self.assertEqual((before.st_mode, before.st_uid, before.st_gid), (after.st_mode, after.st_uid, after.st_gid))
            self.assertEqual(target.read_bytes(), b'preserve')

    def cooldown(self, path):
        script = 'COOLDOWN_FILE=' + shlex.quote(str(path)) + '\nCOOLDOWN_SECONDS=60\n' + COOLDOWN_FUNCTION + '\nclaim_test_cooldown'
        return subprocess.run(['/usr/bin/timeout', '--kill-after=1', '4', 'bash'], input=script,
                              text=True, capture_output=True, timeout=6)

    def test_cooldown_refuses_malformed_history_without_reopening_admission(self):
        with tempfile.TemporaryDirectory(prefix='sls-manual-cooldown-history-') as temporary:
            path = Path(temporary) / 'cooldown.ts'
            for raw in [b'broken evidence', b'123\xff\n', b'0' * 65 + b'\n']:
                path.write_bytes(raw); path.chmod(0o640)
                result = self.cooldown(path)
                self.assertNotEqual(result.returncode, 0, 'Malformed cooldown became a new test claim')
                self.assertEqual(path.read_bytes(), raw)

    def test_cooldown_rejects_hard_links_and_unknown_modes_before_repair(self):
        with tempfile.TemporaryDirectory(prefix='sls-manual-cooldown-metadata-') as temporary:
            root = Path(temporary)
            for kind in ['hardlink', 'mode']:
                path = root / (kind + '.ts')
                path.write_bytes(b'0\n'); path.chmod(0o640)
                if kind == 'hardlink': os.link(path, root / 'other-name')
                else: path.chmod(0o666)
                before = path.stat()
                result = self.cooldown(path)
                self.assertNotEqual(result.returncode, 0, 'Unknown cooldown file was adopted')
                self.assertEqual(path.read_bytes(), b'0\n')
                self.assertEqual(path.stat().st_mode, before.st_mode)

    def test_busy_cooldown_is_bounded_and_does_not_repair_an_unleased_lock(self):
        with tempfile.TemporaryDirectory(prefix='sls-manual-cooldown-busy-') as temporary:
            path = Path(temporary) / 'cooldown.ts'
            lock = Path(str(path) + '.lock')
            lock.touch(mode=0o644)
            with lock.open('r+') as descriptor:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                before = lock.stat()
                result = self.cooldown(path)
                self.assertNotEqual(result.returncode, 124, 'Cooldown waited without its own deadline')
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(lock.stat().st_mode, before.st_mode)
                self.assertFalse(path.exists())

    def test_cooldown_preserves_valid_legacy_history_and_required_lock_inode(self):
        with tempfile.TemporaryDirectory(prefix='sls-manual-cooldown-marker-') as temporary:
            path = Path(temporary) / 'cooldown.ts'
            path.write_bytes(b'0\n'); path.chmod(0o640)
            first = self.cooldown(path)
            self.assertEqual(first.returncode, 0, first.stderr)
            self.assertEqual(first.stdout.strip(), 'CLAIMED')
            lock = Path(str(path) + '.lock')
            inode = lock.stat().st_ino
            self.assertEqual(lock.read_bytes(), b'SLS_MANUAL_TEST_COOLDOWN_V1\n')
            before = path.read_bytes()
            second = self.cooldown(path)
            self.assertEqual(second.returncode, 3, second.stderr)
            self.assertTrue(second.stdout.startswith('COOLDOWN '))
            self.assertEqual(path.read_bytes(), before)
            self.assertEqual(lock.stat().st_ino, inode)
            path.unlink()
            missing = self.cooldown(path)
            self.assertEqual(missing.returncode, 2, missing.stderr)
            self.assertFalse(path.exists())
            self.assertEqual(lock.stat().st_ino, inode)

    def test_concurrent_cooldown_claims_allow_only_one_test(self):
        with tempfile.TemporaryDirectory(prefix='sls-manual-cooldown-concurrent-') as temporary:
            path = Path(temporary) / 'cooldown.ts'
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
                results = list(workers.map(lambda _: self.cooldown(path), range(2)))
            self.assertEqual(sorted(result.returncode for result in results), [0, 3])

    def run_queue(self, *, blocked=False, recipients=None, invalid_count=0, all_invalid=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'outgoing').mkdir()
            (root / 'tmp').mkdir()
            # Include the queue function's actual policy dependency. The full
            # manual-test contract separately exercises HA and stopped rejection.
            config = root / 'mass-notifications.config'
            config.write_text(json.dumps({'runtime_enabled': True, 'enterprise_cluster': {'enabled': False}}))
            config.chmod(0o640)
            guard = root / 'sls_cluster_guard.py'
            guard_source = (HELPER_MODULES / 'sls_cluster_guard.py').read_text()
            guard_marker = "CONFIG = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')"
            self.assertEqual(guard_source.count(guard_marker), 1)
            guard.write_text(guard_source.replace(guard_marker, f'CONFIG = Path({str(config)!r})'))
            (root / 'sls_config_crypto.py').write_bytes((HELPER_MODULES / 'sls_config_crypto.py').read_bytes())
            # An existing live page reserves this phone until virtual time1005.
            (root / 'audio-reservations.json').write_text(json.dumps({
                'recipients': {'1000': 1005}, 'media': {}, 'waiting': {}}))
            helper = root / 'queue-fixture.py'
            helper.write_text('''
import argparse, json, os, sys
from pathlib import Path
sys.dont_write_bytecode = True
sys.path.insert(0, os.environ['FIXTURE_MODULES'])
import sls_audio_queue as queue
root = Path(os.environ['FIXTURE_ROOT'])
(root / 'reservation-called').touch()
p = argparse.ArgumentParser()
p.add_argument('--recipients'); p.add_argument('--duration', type=float)
p.add_argument('--sound'); p.add_argument('--priority')
args = p.parse_args()
ticket = queue.request_ticket(args.recipients.split(','), args.duration, args.sound, args.priority, directory=root, now=1000)
try:
    assert not queue.claim_ticket(ticket, directory=root, now=1000), 'Active live audio was displaced'
    assert not list((root / 'outgoing').iterdir()), 'Call file appeared before recipient reservation'
    if os.environ['FIXTURE_BLOCKED'] == '1':
        raise SystemExit(1)
    assert queue.claim_ticket(ticket, directory=root, now=1005)
    (root / 'admission.json').write_text(json.dumps(vars(args)))
finally:
    with queue.ticket_state(root) as state:
        state['waiting'].pop(ticket, None)
''')
            phone_helper = root / 'phone-fixture.py'
            phone_helper.write_text("import sys\nprint('a' * 32)\nprint(sys.argv[sys.argv.index('--recipients') + 1].replace(',', '\\n'))\n")
            isolated_function = FUNCTION.replace('/usr/local/bin/sls_mass_notify/sls_phone_admission.py', shlex.quote(str(phone_helper)))
            recipient_words = ' '.join(shlex.quote(value) for value in (recipients or ['1000']))
            script = '\n'.join([
                'set -u',
                'SPOOL=' + shlex.quote(str(root / 'outgoing')),
                'SPOOL_TMP=' + shlex.quote(str(root / 'tmp')),
                'LOG=' + shlex.quote(str(root / 'log')),
                'CLUSTER_GUARD=' + shlex.quote(str(guard)),
                'CONFIG_JSON_FILE=' + shlex.quote(str(config)),
                'AUDIO_QUEUE_HELPER=' + shlex.quote(str(helper)),
                'SLS_AUDIO_CONTEXT=sls-alert-audio',
                'SLS_CALLERID_NAME=Fixture SLS_CALLERID_NUM=SLS',
                'NWS_ALERT_RECIPIENTS=(' + recipient_words + ')',
                'TEST_CALL_QUEUE_PATHS=() TEST_CALL_QUEUE_FAILURES=0',
                'declare -A TEST_CALL_RECIPIENTS=()',
                'audio_page_hold_seconds() { printf "15\\n"; }',
                'report_fault() { printf "FAULT %s\\n" "$*"; }',
                'chown() { :; }',
                AUTHORITY_FUNCTION,
                PERMISSION_FUNCTION,
                isolated_function,
                'status=0',
                'queue_test_audio_to_recipients SLS_Mass_Notifications_Plugin/tts/fixture || status=$?',
                'printf "RESULT %s %s %s\\n" "$status" "$TEST_CALL_QUEUE_FAILURES" "${#TEST_CALL_QUEUE_PATHS[@]}"',
                # Matching the caller, a reservation failure returns control so
                # independently requested visual delivery can still proceed.
                'printf "VISUAL_CHANNEL_CONTINUES\\n"',
            ])
            result = subprocess.run(['bash'], input=script, text=True, capture_output=True, timeout=5,
                                    env=dict(os.environ, FIXTURE_ROOT=str(root), FIXTURE_MODULES=str(HELPER_MODULES), FIXTURE_BLOCKED=str(int(blocked))))
            self.assertEqual(result.returncode, 0, result.stderr)
            files = list((root / 'outgoing').iterdir())
            state = json.loads((root / 'audio-reservations.json').read_text())
            self.assertIn('VISUAL_CHANNEL_CONTINUES', result.stdout)
            if blocked or all_invalid:
                self.assertEqual(files, [])
                self.assertIn(f'RESULT 1 {invalid_count + (1 if blocked else 0)} 0', result.stdout)
                self.assertIn('no test audio was submitted', result.stdout)
                self.assertEqual(state['recipients'], {'1000': 1005})
                self.assertEqual(state['media'], {})
                self.assertEqual(state['waiting'], {})
                self.assertEqual((root / 'reservation-called').exists(), not all_invalid)
                self.assertFalse((root / 'admission.json').exists())
            else:
                self.assertIn(f'RESULT {int(invalid_count > 0)} {invalid_count} 1', result.stdout)
                self.assertEqual(len(files), 1)
                call = files[0].read_text()
                self.assertIn('Channel: Local/1000@sls-alert-audio/n\n', call)
                self.assertIn('WaitTime: 45\n', call)
                self.assertIn('Data: 15\n', call)
                self.assertEqual(state['recipients'], {'1000': 1025})
                self.assertEqual(state['media'], {'fixture.wav': 1925})
                self.assertEqual(state['waiting'], {})
                admission = json.loads((root / 'admission.json').read_text())
                self.assertEqual(admission['priority'], 'normal')
                self.assertEqual(admission['duration'], 15)
                self.assertEqual(admission['recipients'], '1000')

    def test_reservation_precedes_spooling_and_retention_starts_after_wait(self):
        self.run_queue()

    def test_busy_reservation_prevents_spooling_and_preserves_visual_progress(self):
        self.run_queue(blocked=True)

    def test_mixed_invalid_recipients_reserve_only_valid_extensions_and_report_partial_failure(self):
        self.run_queue(recipients=['1000', 'bad1001', 'invalid'], invalid_count=2)

    def test_all_invalid_recipients_cannot_invoke_reservation_or_spool(self):
        self.run_queue(recipients=['invalid', 'bad1000', '1000/other', '1' * 21], invalid_count=4, all_invalid=True)

    def test_duplicate_recipient_is_reserved_and_spooled_only_once(self):
        self.run_queue(recipients=['1000', '1000'])


if __name__ == '__main__':
    unittest.main()
