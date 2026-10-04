#!/usr/bin/python3
"""Read-only evidence projection from private synthetic call ledgers."""
import copy
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_phone_admission as phone
import sls_phone_outcomes as outcomes


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-outcomes-')
        self.root = Path(self.temp.name)
        self.store = phone.PhoneAdmissionStore(self.root, clock=lambda: 1000.0)
        self.store.collector_heartbeat('a' * 32)
        inventory = {'complete': True, 'started_tick': 1000.0, 'generation': 'a' * 32,
                     'boot_id': self.store.boot_id, 'channels': []}
        self.dials = ['PJSIP/1000/sip:private@192.0.2.1', 'PJSIP/1000/sips:private@[2001:db8::1];transport=TLS']
        self.token = self.store.admit({'1000': self.dials}, 25, inventory, service='announcement', correlation='announcement-fixture')
        self.store.bind_origin(self.token, '1000', '1.1', 'Local/1000@sls-alert-audio-fixture;2')
        self.store.bind_contact(self.token, '1000', '1.2', 'PJSIP/1000-fixture', self.dials[0])

    def tearDown(self):
        self.temp.cleanup()

    def state(self):
        return phone.read_private_json('phone-admission.json', self.root)

    def test_missing_contact_is_unknown_not_delivered(self):
        result = outcomes.project(self.state(), 'announcement-fixture')
        self.assertTrue(result['active'])
        self.assertTrue(result['uncertain'])
        self.assertEqual(len(result['targets']), 2)
        self.assertFalse(result['targets'][1]['answered'])
        self.assertTrue(result['targets'][1]['uncertain'])
        self.assertFalse(result['playback_confirmed'])

    def test_evidence_is_read_only_redacted_and_does_not_confirm_playback(self):
        for event in [
            {'Event': 'DialEnd', 'DestUniqueid': '1.2', 'DestAccountCode': phone.accountcode(self.token), 'DialStatus': 'ANSWER'},
            {'Event': 'ConfbridgeJoin', 'Uniqueid': '1.2', 'AccountCode': phone.accountcode(self.token)},
            {'Event': 'Hangup', 'Uniqueid': '1.2', 'AccountCode': phone.accountcode(self.token), 'Cause': '16'},
        ]:
            self.store.record_event(event)
        before = (self.root / 'phone-admission.json').read_bytes()
        result = outcomes.project(self.state(), 'announcement-fixture')
        row = result['targets'][0]
        self.assertTrue(row['answered'] and row['joined'] and row['ended'])
        self.assertFalse(result['playback_confirmed'])
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())
        encoded = json.dumps(result)
        for secret in ['192.0.2.1', 'private', 'PJSIP/', self.token, '1.2']:
            self.assertNotIn(secret, encoded)

    def test_closed_missing_events_and_collector_gaps_are_unknown(self):
        state = self.state()
        batch = state['batches'][self.token]
        batch['status'] = 'closed'
        result = outcomes.project(state, 'announcement-fixture')
        self.assertFalse(result['active'])
        self.assertTrue(all(row['uncertain'] for row in result['targets']))
        batch['channels']['1.2']['dial_status'] = 'BUSY'
        self.assertFalse(outcomes.project(state, 'announcement-fixture')['targets'][0]['uncertain'])
        batch['outcome_uncertain'] = True
        self.assertTrue(outcomes.project(state, 'announcement-fixture')['targets'][0]['uncertain'])

    def test_absent_history_and_invalid_identity(self):
        self.assertFalse(outcomes.project(self.state(), 'another-job')['available'])
        self.assertTrue(outcomes.project(self.state(), 'another-job')['uncertain'])
        for invalid in ['', 'x\n', '../file', '${SHELL(x)}']:
            with self.assertRaises(ValueError): outcomes.project(self.state(), invalid)

    def test_external_keypad_evidence_stays_call_specific_and_pending_ends(self):
        recipient = 'voice_' + 'b' * 24
        target = {'id': recipient, 'number': '+15551234567', 'route_mode': 'pbx_routes',
                  'trunk_id': '', 'caller_id': '', 'acknowledgement_required': True, 'ack_timeout_seconds': 10}
        inventory = {'complete': True, 'started_tick': 1000.0, 'generation': 'a' * 32,
                     'boot_id': self.store.boot_id, 'channels': []}
        token = self.store.admit({recipient: ['VOICE/' + target['number']]}, 25, inventory,
            service='announcement', correlation='external-fixture', outbound={recipient: {'target': target,
                'proof': {'fingerprint': 'a' * 64, 'trunk_endpoints': ['fixturetrunk']}}})
        local = 'Local/' + recipient + '@sls-outbound-voice-1;'
        self.store.bind_origin(token, recipient, '2.1', local + '2')
        self.store.record_event({'Event': 'DialBegin', 'Uniqueid': '2.1', 'Channel': local + '2',
            'DestUniqueid': '2.2', 'DestChannel': 'PJSIP/fixturetrunk-00000002'})
        self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': '2.2',
            'DestChannel': 'PJSIP/fixturetrunk-00000002', 'DialStatus': 'ANSWER'})
        self.store.record_outbound_playback(token, recipient, local + '1', 'complete')
        state = self.state()
        pending = outcomes.project(state, 'external-fixture')['targets'][0]
        self.assertTrue(pending['playback_completed'])
        self.assertFalse(pending['keypad_acknowledged'])
        self.assertEqual(pending['acknowledgement_status'], 'pending')
        state['batches'][token]['status'] = 'closed'
        self.assertEqual(outcomes.project(state, 'external-fixture')['targets'][0]['acknowledgement_status'], 'interrupted')
        self.store.record_outbound_playback(token, recipient, local + '1', 'acknowledged')
        before = (self.root / 'phone-admission.json').read_bytes()
        result = outcomes.project(self.state(), 'external-fixture')
        self.assertTrue(result['targets'][0]['keypad_acknowledged'])
        self.assertEqual(result['targets'][0]['acknowledged_at'], 1000.0)
        self.assertFalse(result['playback_confirmed'])
        self.assertNotIn(target['number'], json.dumps(result))
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())
        internal = outcomes.project(self.state(), 'announcement-fixture')
        self.assertTrue(all(not row['keypad_acknowledged'] and not row['playback_completed'] for row in internal['targets']))

    def test_isolated_health_command_does_not_write_root_bytecode(self):
        runtime = self.root / 'runtime'
        runtime.mkdir(mode=0o700)
        source = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
        for path in source.glob('*.py'):
            shutil.copyfile(path, runtime / path.name)
        admission = runtime / 'sls_phone_admission.py'
        original = admission.read_text()
        production_data = "DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')"
        self.assertEqual(original.count(production_data), 1)
        absent_data = self.root / 'absent-ledger'
        admission.write_text(original.replace(production_data, 'DATA = Path(' + repr(str(absent_data)) + ')'))
        before = {path.name: path.read_bytes() for path in runtime.iterdir()}
        result = subprocess.run([sys.executable, '-I', str(runtime / 'sls_phone_events.py'), '--health'],
            cwd=self.root, env={'PATH': '/usr/bin:/bin'}, umask=0o077,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        self.assertEqual(result.returncode, 1, result.stderr.decode())
        self.assertFalse(json.loads(result.stdout)['ok'])
        self.assertEqual(result.stderr, b'')
        self.assertFalse(absent_data.exists())
        self.assertFalse(list(runtime.rglob('__pycache__')))
        self.assertEqual(before, {path.name: path.read_bytes() for path in runtime.iterdir()})

    def test_multiple_attempts_bounded_without_leaking_other_jobs(self):
        state = self.state()
        template = state['batches'][self.token]
        for i in range(502):
            state['batches'][f'{i:032x}'] = copy.deepcopy(template)
        state['batches'][self.token]['correlation'] = 'different-job'
        result = outcomes.project(state, 'announcement-fixture')
        self.assertEqual(len(result['targets']), 1000)
        self.assertTrue(result['truncated'])
        self.assertTrue(result['uncertain'])
        self.assertLess(len(json.dumps(result)), 524288)


if __name__ == '__main__': unittest.main()
