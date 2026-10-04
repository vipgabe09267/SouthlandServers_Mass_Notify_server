#!/usr/bin/env python3
"""Exercise the real manual Weather queue function against temporary reservations."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_test.sh').read_text()
FUNCTION = SOURCE[SOURCE.index('queue_test_audio_to_recipients() {'):SOURCE.index('\nwait_for_test_call_pickup() {')]
PERMISSION_FUNCTION = SOURCE[SOURCE.index('refresh_api_test_permissions() {'):SOURCE.index('claim_test_cooldown() {')]
HELPER_MODULES = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'


class ManualWeatherReservationTests(unittest.TestCase):
    def run_queue(self, *, blocked=False, recipients=None, invalid_count=0, all_invalid=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'outgoing').mkdir()
            (root / 'tmp').mkdir()
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
                'AUDIO_QUEUE_HELPER=' + shlex.quote(str(helper)),
                'SLS_AUDIO_CONTEXT=sls-alert-audio',
                'SLS_CALLERID_NAME=Fixture SLS_CALLERID_NUM=SLS',
                'NWS_ALERT_RECIPIENTS=(' + recipient_words + ')',
                'TEST_CALL_QUEUE_PATHS=() TEST_CALL_QUEUE_FAILURES=0',
                'declare -A TEST_CALL_RECIPIENTS=()',
                'audio_page_hold_seconds() { printf "15\\n"; }',
                'report_fault() { printf "FAULT %s\\n" "$*"; }',
                'chown() { :; }',
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
