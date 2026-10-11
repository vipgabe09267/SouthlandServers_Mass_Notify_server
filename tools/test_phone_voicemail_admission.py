#!/usr/bin/python3
"""Private ledgers and synthetic AMI evidence; no PBX or mailbox is contacted."""
import copy
import json
from pathlib import Path
import stat
import sys
import tempfile
import time
import types
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_phone_admission as phone
import sls_phone_outcomes as outcomes


class VoicemailAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-vm-admission-')
        self.directory = Path(self.temp.name)
        self.now = 1000.0
        self.store = phone.PhoneAdmissionStore(self.directory, clock=lambda: self.now)
        self.generation = 'a' * 32
        self.store.collector_heartbeat(self.generation)
        self.dials = ['PJSIP/1000/sip:desk@192.0.2.10', 'PJSIP/1000/sips:other@192.0.2.11;transport=TLS']
        self.original_sound = 'SLS_Mass_Notifications_Plugin/tts/weather_fixture'

    def tearDown(self):
        self.temp.cleanup()

    def inventory(self, channels=None):
        return {'complete': True, 'started_tick': self.now, 'generation': self.generation,
                'boot_id': self.store.boot_id, 'channels': channels or []}

    def state(self):
        return phone.read_private_json('phone-admission.json', self.directory)

    def fallback(self, dials=None, *, service='nws'):
        if dials is None:
            dials = self.dials
        return {'schema': 1, 'scope': 'zone1', 'scope_hash': 'c' * 64,
            'source_sound': self.original_sound, 'sound': None,
            'weather_context': {'event': 'Tornado Warning', 'severity': 'Extreme',
                'source_validity': {'version': 1, 'provider': 'nws' if service == 'nws' else 'xweather',
                    'group_id': 'zone1', 'observed_at': 1000, 'expires_at': 2000}},
            'live_origin': None, 'live_group': None,
            'expires_at': 1900.0, 'expires_tick': 1900.0,
            'targets': {'1000': {'dials': list(dials), 'mailbox': '1000@default', 'claimed_token': None}}}

    def source(self, dials=None, *, service='nws'):
        if dials is None:
            dials = self.dials
        token = self.store.admit({'1000': dials}, 25, self.inventory(), service=service,
                                 correlation='weather-fixture', vm_fallback=self.fallback(dials, service=service))
        sound = 'SLS_Mass_Notifications_Plugin/voicemail/' + token
        self.store.attach_voicemail_sound(token, self.original_sound, sound)
        return token, sound

    def end_unanswered(self, token, statuses=None):
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        for index, dial in enumerate(self.dials, 2):
            uid = '1.' + str(index)
            self.store.bind_contact(token, '1000', uid, 'PJSIP/1000-' + str(index), dial)
            self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': uid,
                'DestAccountCode': phone.accountcode(token), 'DialStatus': (statuses or {}).get(index, 'NOANSWER')})
            self.store.ended(token, uid, 19)
        self.store.ended(token, '1.1', 16)
        self.now += 1

    def claim(self, token, sound, **options):
        return self.store.admit_voicemail(token, '1000', options.get('limit', 25),
            options.get('inventory', self.inventory()), options.get('mailbox', '1000@default'), sound)

    def test_plain_phone_requests_cannot_introduce_mailbox_targets(self):
        for service in ('manual', 'live', 'nws', 'lightning'):
            with self.subTest(service=service), self.assertRaises(phone.PhoneAdmissionError):
                self.store.admit({'1000': ['VM/1000@default']}, 25, self.inventory(), service=service)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'source_invalid'):
            self.store.admit({'1000': []}, 25, self.inventory(), service='announcement', vm_fallback=self.fallback([]))
        self.assertEqual(self.state()['batches'], {})

    def test_two_unanswered_contacts_create_one_durable_mailbox_claim(self):
        token, sound = self.source()
        self.end_unanswered(token)
        vm = self.claim(token, sound)
        state = self.state()
        self.assertEqual(state['batches'][token]['status'], 'closed')
        self.assertEqual(state['batches'][token]['vm_fallback']['targets']['1000']['claimed_token'], vm)
        self.assertEqual(state['batches'][vm]['targets'], {'1000': {'dials': ['VM/1000@default']}})
        self.assertEqual(self.store.used_contacts(state), 1)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'already_attempted'):
            self.claim(token, sound)

    def test_any_answer_suppresses_mailbox_even_if_other_handset_did_not_answer(self):
        token, sound = self.source()
        self.end_unanswered(token, {2: 'ANSWER', 3: 'NOANSWER'})
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'noanswer_unconfirmed'):
            self.claim(token, sound)
        self.assertIsNone(self.state()['batches'][token]['vm_fallback']['targets']['1000']['claimed_token'])

    def test_missing_contact_or_dialend_is_not_proof_of_no_answer(self):
        for missing in ('contact', 'dialend'):
            with self.subTest(missing=missing):
                token, sound = self.source()
                self.store.bind_origin(token, '1000', token[:8] + '.1', 'Local/1000@sls-alert-audio-1;2')
                if missing == 'dialend':
                    self.store.bind_contact(token, '1000', token[:8] + '.2', 'PJSIP/1000-1', self.dials[0])
                    self.store.ended(token, token[:8] + '.2', 19)
                self.store.ended(token, token[:8] + '.1', 16)
                self.now += 1
                with self.assertRaisesRegex(phone.PhoneAdmissionError, 'noanswer_unconfirmed'):
                    self.claim(token, sound)
                # Close this fixture's original source before another admission.
                with self.store.locked() as state:
                    state['batches'][token]['status'] = 'closed'

    def test_offline_mailbox_source_reserves_no_fake_phone_contacts(self):
        token, sound = self.source([])
        self.assertEqual(self.store.used_contacts(self.state()), 0)
        self.assertEqual(self.state()['batches'][token]['targets']['1000']['dials'], [])
        self.now += 1
        vm = self.claim(token, sound)
        self.assertEqual(self.store.used_contacts(self.state()), 1)
        self.assertEqual(self.state()['batches'][vm]['purpose'], 'voicemail')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'no_registered_contacts'):
            self.store.admit({'1001': []}, 25, self.inventory(), service='manual')

    def test_claim_requires_current_complete_inventory_and_frozen_mailbox(self):
        token, sound = self.source()
        self.end_unanswered(token)
        for options, code in [({'inventory': {'complete': False}}, 'inventory_unavailable'),
                              ({'inventory': {**self.inventory(), 'started_tick': self.now - 11}}, 'inventory_stale'),
                              ({'mailbox': '1000@changed'}, 'source_unavailable')]:
            with self.subTest(options=options), self.assertRaisesRegex(phone.PhoneAdmissionError, code):
                self.claim(token, sound, **options)
        self.assertIsNone(self.state()['batches'][token]['vm_fallback']['targets']['1000']['claimed_token'])

    def test_collector_restart_uncertainty_and_expiry_never_authorize_deposit(self):
        token, sound = self.source([])
        self.now += 1
        self.store.mark_event_gap()
        self.generation = 'b' * 32
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'source_unavailable'):
            self.claim(token, sound)
        token2, sound2 = self.source([])
        self.now = 1901
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'source_unavailable'):
            self.claim(token2, sound2)

    def test_exact_native_channel_and_completed_audio_required_for_saved_result(self):
        token, sound = self.source([])
        self.now += 1
        vm = self.claim(token, sound)
        channel = 'Local/1000@sls-alert-voicemail-abcdef;2'
        for wrong in ('PJSIP/1000-1', 'Local/1000@sls-alert-audio-abcdef;2', 'Local/1001@sls-alert-voicemail-abcdef;2'):
            with self.subTest(wrong=wrong), self.assertRaisesRegex(phone.PhoneAdmissionError, 'origin_invalid'):
                self.store.bind_voicemail(vm, '1000', '2.1', wrong)
        self.store.bind_voicemail(vm, '1000', '2.1', channel)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'contact_not_admitted|origin_invalid'):
            self.store.bind_contact(vm, '1000', '2.2', 'PJSIP/1000-1', self.dials[0])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'result_unconfirmed'):
            self.store.record_voicemail_result(vm, '1000', channel, 'SUCCESS')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'result_unconfirmed'):
            self.store.record_voicemail_playback(vm, '1000', 'Local/1001@sls-alert-voicemail-abcdef;1', 'SUCCESS')
        self.store.record_voicemail_playback(vm, '1000', channel[:-1] + '1', 'SUCCESS')
        self.store.ended(vm, '2.1', 16)
        self.store.record_voicemail_result(vm, '1000', channel, 'SUCCESS')
        original = copy.deepcopy(self.state()['batches'][vm]['voicemail']['result'])
        self.now += 1
        self.store.record_voicemail_result(vm, '1000', channel, 'SUCCESS')
        self.assertEqual(self.state()['batches'][vm]['voicemail']['result'], original)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'result_invalid'):
            self.store.record_voicemail_result(vm, '1000', channel, 'FAILED')
        before = (self.directory / 'phone-admission.json').read_bytes()
        result = outcomes.project(self.state(), 'weather-fixture')
        deposit = next(row for row in result['targets'] if row.get('delivery_kind') == 'voicemail')
        self.assertTrue(deposit['voicemail_saved'])
        self.assertFalse(deposit['answered'] or deposit['joined'] or deposit['playback_completed'])
        self.assertEqual(result['voicemail_saved_count'], 1)
        self.assertFalse(result['playback_confirmed'])
        self.assertEqual(before, (self.directory / 'phone-admission.json').read_bytes())
        for private in ('default', token, vm, 'PJSIP/', '192.0.2.', channel):
            self.assertNotIn(private, json.dumps(result))

    def test_failed_deposit_is_final_and_is_not_reported_saved(self):
        token, sound = self.source([])
        self.now += 1
        vm = self.claim(token, sound)
        channel = 'Local/1000@sls-alert-voicemail-a;2'
        self.store.bind_voicemail(vm, '1000', '2.1', channel)
        self.store.record_voicemail_result(vm, '1000', channel, 'FAILED')
        result = outcomes.project(self.state(), 'weather-fixture')
        self.assertFalse(result['targets'][0]['voicemail_saved'])
        self.assertEqual(result['targets'][0]['voicemail_status'], 'failed')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'already_attempted'):
            self.claim(token, sound)

    def test_capacity_failure_does_not_consume_the_one_use_claim(self):
        token, sound = self.source([])
        other = self.store.admit({'1001': ['PJSIP/1001/sip:desk@192.0.2.1']}, 1, self.inventory(), service='manual')
        self.store.bind_origin(other, '1001', '3.1', 'Local/1001@sls-alert-audio-a;2')
        self.now += 1
        active = self.inventory([{'uniqueid': '3.1', 'accountcode': phone.accountcode(other)}])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.claim(token, sound, limit=1, inventory=active)
        self.assertIsNone(self.state()['batches'][token]['vm_fallback']['targets']['1000']['claimed_token'])
        self.store.ended(other, '3.1', 16)
        self.now += 1
        self.claim(token, sound, limit=1)

    def test_uncertain_commit_cannot_replay_and_active_claim_retains_parent(self):
        token, sound = self.source([])
        self.now += 1
        real_fsync = phone.os.fsync
        def fail_directory(fd):
            if stat.S_ISDIR(phone.os.fstat(fd).st_mode):
                raise OSError('fixture durable-directory failure')
            return real_fsync(fd)
        with mock.patch.object(phone.os, 'fsync', side_effect=fail_directory):
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'commit_uncertain'):
                self.claim(token, sound)
        self.assertIsNotNone(self.state()['batches'][token]['vm_fallback']['targets']['1000']['claimed_token'])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'already_attempted'):
            self.claim(token, sound)
        with mock.patch.object(phone, 'MAX_CLOSED_BYTES', 1):
            with self.store.locked():
                pass
        self.assertIn(token, self.state()['batches'])

    def test_live_capture_excludes_caller_and_binds_recording_to_exact_origin(self):
        dials = self.dials[:1]
        fallback = {'schema': 1, 'scope': 'staff', 'scope_hash': 'd' * 64, 'source_sound': None,
            'sound': None, 'weather_context': None, 'live_origin': '1999', 'live_group': 'staff',
            'expires_at': 1900.0, 'expires_tick': 1900.0,
            'targets': {'1000': {'dials': dials, 'mailbox': '1000@default', 'claimed_token': None}}}
        token = self.store.admit({'1999': dials}, 25, self.inventory(), service='live', vm_fallback=fallback)
        channel = 'PJSIP/1999-00000001'
        self.store.bind_origin(token, '1999', '4.1', channel)
        sound = 'SLS_Mass_Notifications_Plugin/voicemail/' + token
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'source_invalid'):
            self.store.attach_live_voicemail_sound(token, 'PJSIP/1999-00000002', sound)
        self.store.attach_live_voicemail_sound(token, channel, sound)
        self.store.bind_contact(token, '1999', '4.2', 'PJSIP/1000-00000002', dials[0])
        self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': '4.2',
            'DestAccountCode': phone.accountcode(token), 'DialStatus': 'NOANSWER'})
        self.store.ended(token, '4.2', 19)
        self.store.ended(token, '4.1', 16)
        self.now += 1
        self.claim(token, sound)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'source_invalid'):
            bad = copy.deepcopy(fallback)
            bad['targets']['1999'] = {'dials': [], 'mailbox': '1999@default', 'claimed_token': None}
            phone.checked_voicemail_fallback(bad, 'live', {'1999': dials})

    def test_mailboxes_are_resolved_from_freepbx_and_reject_injected_contexts(self):
        values = {'1000/voicemail': 'default', 'VM_INFO(1000@default,exists)': '1',
                  '1001/voicemail': 'novm', '1002/voicemail': 'default),SHELL(x)',
                  '1003/voicemail': 'custom', 'VM_INFO(1003@custom,exists)': '0'}
        seen = []
        ami = phone.PhoneAmi({})
        def variable(expression):
            seen.append(expression)
            return values.get(expression, '')
        ami.variable = variable
        ami.database = lambda family, key: values.get(key, '')
        self.assertEqual(ami.mailboxes(['1000', '1001', '1002', '1003']), {'1000': '1000@default'})
        self.assertFalse(any('SHELL' in expression for expression in seen))
        with self.assertRaises(phone.PhoneAdmissionError):
            ami.mailboxes(['1000/voicemail)'])

    def database_fixture(self, messages):
        ami = phone.PhoneAmi({})
        sent = []
        ami.send = lambda fields: sent.append(fields)
        rows = iter(messages)
        def receive(deadline):
            message = dict(next(rows))
            if message.get('ActionID') == 'current':
                message['ActionID'] = sent[-1]['ActionID']
            return message
        ami.receive = receive
        return ami, sent

    def test_dbget_correlates_literal_key_and_ignores_another_action(self):
        ami, sent = self.database_fixture([
            {'Event': 'DBGetResponse', 'ActionID': 'other', 'Family': 'AMPUSER', 'Key': '1000/voicemail', 'Val': 'wrong'},
            {'Response': 'Success', 'ActionID': 'current'},
            {'Event': 'DBGetResponse', 'ActionID': 'current', 'Family': 'AMPUSER', 'Key': '1000/voicemail', 'Val': 'default'}])
        self.assertEqual(ami.database('AMPUSER', '1000/voicemail'), 'default')
        self.assertEqual(sent[0]['Action'], 'DBGet')
        self.assertEqual(sent[0]['Family'], 'AMPUSER')
        self.assertEqual(sent[0]['Key'], '1000/voicemail')
        self.assertNotIn('Variable', sent[0])
        for family, key in [('AMPUSER', '1000/voicemail\r\nAction: DBPut'), ('DEVICE', '1000/dial'), ('AMPUSER', '../secret')]:
            with self.subTest(family=family, key=key), self.assertRaisesRegex(phone.PhoneAdmissionError, 'request_invalid'):
                ami.database(family, key)
        self.assertEqual(len(sent), 1)

    def test_dbget_missing_is_empty_but_permissions_and_mismatches_fail_closed(self):
        ami, _ = self.database_fixture([{'Response': 'Error', 'ActionID': 'current', 'Message': 'Database entry not found'}])
        self.assertEqual(ami.database('AMPUSER', '1000/voicemail'), '')
        ami, _ = self.database_fixture([{'Response': 'Error', 'ActionID': 'current', 'Message': 'Permission denied'}])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'action_rejected'):
            ami.database('AMPUSER', '1000/voicemail')
        for field, value in [('Family', 'DEVICE'), ('Key', '1001/voicemail'), ('Val', 'default\r\nAction: DBPut')]:
            response = {'Event': 'DBGetResponse', 'ActionID': 'current', 'Family': 'AMPUSER', 'Key': '1000/voicemail', 'Val': 'default'}
            response[field] = value
            ami, _ = self.database_fixture([{'Response': 'Success', 'ActionID': 'current'}, response])
            with self.subTest(field=field), self.assertRaisesRegex(phone.PhoneAdmissionError, 'database_response_invalid'):
                ami.database('AMPUSER', '1000/voicemail')

    def test_duplicate_actual_weather_admission_cannot_deposit_twice(self):
        correlation = 'vm-' + 'd' * 64
        token, sound = self.source([])
        with self.store.locked() as state:
            state['batches'][token]['correlation'] = correlation
        self.now += 1
        self.claim(token, sound)
        self.now += 61
        self.store.collector_heartbeat(self.generation)
        token2, sound2 = self.source([])
        with self.store.locked() as state:
            state['batches'][token2]['correlation'] = correlation
        self.now += 1
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'already_attempted'):
            self.claim(token2, sound2)

    def test_terminal_refusal_is_bounded_final_and_cannot_override_a_claim(self):
        token, sound = self.source([])
        self.assertTrue(self.store.record_voicemail_refusal(token, '1000', 'phone_voicemail_mailbox_duration'))
        self.assertTrue(self.store.record_voicemail_refusal(token, '1000', 'phone_voicemail_mailbox_changed'))
        self.assertEqual(self.state()['batches'][token]['voicemail_failures'], {'1000': 'phone_voicemail_mailbox_duration'})
        self.now += 1
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'already_attempted'):
            self.claim(token, sound)
        with self.assertRaises(phone.PhoneAdmissionError):
            self.store.record_voicemail_refusal(token, '1000', 'provider supplied password or URL')
        with self.assertRaises(phone.PhoneAdmissionError):
            self.store.record_voicemail_refusal(token, '1001', 'phone_voicemail_mailbox_duration')
        token2, sound2 = self.source([])
        self.now += 1
        self.claim(token2, sound2)
        self.assertFalse(self.store.record_voicemail_refusal(token2, '1000', 'phone_voicemail_mailbox_changed'))

    def test_bulk_refusal_is_one_atomic_write_and_preserves_claims_and_first_failure(self):
        targets = {str(1000 + index): [] for index in range(phone.MAX_CONTACTS)}
        fallback = self.fallback([])
        fallback['targets'] = {recipient: {'dials': [], 'mailbox': recipient + '@default', 'claimed_token': None}
                               for recipient in targets}
        token = self.store.admit(targets, phone.MAX_CONTACTS, self.inventory(), service='nws', vm_fallback=fallback)
        sound = 'SLS_Mass_Notifications_Plugin/voicemail/' + token
        self.store.attach_voicemail_sound(token, self.original_sound, sound)
        claimed = self.claim(token, sound, limit=phone.MAX_CONTACTS)
        self.store.record_voicemail_refusal(token, '1001', 'phone_voicemail_mailbox_duration')
        with mock.patch.object(phone.os, 'rename', wraps=phone.os.rename) as writes:
            changed = self.store.record_voicemail_refusals(token, list(targets), 'phone_voicemail_recording_unconfirmed')
        self.assertEqual(changed, phone.MAX_CONTACTS - 2)
        self.assertEqual(writes.call_count, 1)
        source = self.state()['batches'][token]
        self.assertEqual(source['vm_fallback']['targets']['1000']['claimed_token'], claimed)
        self.assertNotIn('1000', source['voicemail_failures'])
        self.assertEqual(source['voicemail_failures']['1001'], 'phone_voicemail_mailbox_duration')
        self.assertEqual(len(source['voicemail_failures']), phone.MAX_CONTACTS - 1)
        before = copy.deepcopy(source)
        self.assertEqual(self.store.record_voicemail_refusals(token, tuple(targets), 'phone_voicemail_mailbox_changed'), 0)
        self.assertEqual(self.state()['batches'][token], before)

    def test_bulk_refusal_rejects_the_complete_invalid_batch_without_a_write(self):
        token, _ = self.source([])
        before = self.state()
        invalid = ([], '1000', {'1000'}, ['1000', '1000'], ['1000', '1001'], ['1000', 1001],
                   ['1000'] * (phone.MAX_CONTACTS + 1))
        with mock.patch.object(phone.os, 'rename', wraps=phone.os.rename) as writes:
            for recipients in invalid:
                with self.subTest(recipients=repr(recipients)[:100]), self.assertRaises(phone.PhoneAdmissionError):
                    self.store.record_voicemail_refusals(token, recipients, 'phone_voicemail_recording_unconfirmed')
            with self.assertRaises(phone.PhoneAdmissionError):
                self.store.record_voicemail_refusals(token, ['1000'], 'arbitrary provider text')
        self.assertEqual(writes.call_count, 0)
        self.assertEqual(self.state(), before)

    def test_default_switches_are_explicit_and_invalid_values_fail_closed(self):
        self.assertTrue(phone.voicemail_enabled({}, 'nws'))
        self.assertTrue(phone.voicemail_enabled({}, 'lightning'))
        self.assertTrue(phone.voicemail_enabled({}, 'live'))
        self.assertFalse(phone.voicemail_enabled({}, 'announcement'))
        for disabled in ('0', 0, False, 'true', 'yes', [], {}, 1.0):
            self.assertFalse(phone.voicemail_enabled({'nws_voicemail_enabled': disabled}, 'nws'))

    def producer(self, dials, *, fail_snapshot=False, settings=None):
        store = phone.PhoneAdmissionStore(self.directory)
        store.collector_heartbeat(self.generation)
        calls = []
        class AmiFixture:
            def __init__(self, settings):
                pass
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def contacts(self, extensions):
                return {extension: list(dials) for extension in extensions}
            def mailboxes(self, extensions):
                calls.append('mailboxes')
                return {extension: extension + '@default' for extension in extensions}
            def inventory(self, generation, boot):
                return {'complete': True, 'started_tick': time.monotonic(), 'generation': generation,
                        'boot_id': boot, 'channels': []}
        def snapshot(token, sound, duration, directory):
            calls.append('snapshot')
            if fail_snapshot:
                raise phone.PhoneAdmissionError('phone_voicemail_audio_capacity')
            return 'SLS_Mass_Notifications_Plugin/voicemail/' + token
        helper = types.SimpleNamespace(scope_fingerprint=lambda *args: 'c' * 64, snapshot_audio=snapshot)
        context = {'event': 'Tornado Warning', 'severity': 'Extreme', 'source_validity': {
            'version': 1, 'provider': 'nws', 'group_id': 'zone1', 'observed_at': int(time.time()),
            'expires_at': int(time.time()) + 900}}
        with mock.patch.dict(sys.modules, {'sls_voicemail': helper}), mock.patch.object(phone, 'preserve_audio_media'):
            result = phone.admit_audience(['1000'], service='nws', directory=self.directory,
                settings=settings or {'phone_device_limit': 25}, ami_factory=AmiFixture,
                voicemail=True, voicemail_scope='zone1', voicemail_context=context,
                duration=2, sound=self.original_sound, wait_seconds=0)
        return result, calls

    def test_producer_offline_response_is_vm_only_and_immediately_closed(self):
        result, calls = self.producer([])
        self.assertEqual(result['targets'], [])
        self.assertEqual(result['contacts'], 0)
        self.assertTrue(result['vm_only'])
        self.assertEqual(result['voicemail_targets'], ['1000'])
        self.assertEqual(calls, ['mailboxes', 'snapshot'])
        self.assertEqual(self.state()['batches'][result['token']]['status'], 'closed')

    def test_copy_failure_keeps_existing_normal_audio_reservation_and_blocks_vm(self):
        result, _ = self.producer(self.dials, fail_snapshot=True)
        self.assertEqual(result['targets'], ['1000'])
        self.assertEqual(result['contacts'], 2)
        self.assertFalse(result['vm_only'])
        self.assertEqual(result['voicemail_failure_code'], 'phone_voicemail_audio_capacity')
        self.assertIsNone(self.state()['batches'][result['token']]['vm_fallback']['sound'])
        self.assertEqual(self.state()['batches'][result['token']]['voicemail_failures'], {'1000': 'phone_voicemail_audio_capacity'})

    def test_disabled_weather_checkbox_never_resolves_mailboxes(self):
        result, calls = self.producer(self.dials, settings={'phone_device_limit': 25, 'nws_voicemail_enabled': '0'})
        self.assertEqual(calls, [])
        self.assertEqual(result['voicemail_targets'], [])
        self.assertNotIn('vm_fallback', self.state()['batches'][result['token']])

    def test_live_recording_duration_does_not_require_generated_audio(self):
        store = phone.PhoneAdmissionStore(self.directory)
        store.collector_heartbeat(self.generation)
        class LiveAmiFixture:
            def __init__(self, settings):
                pass
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def contacts(self, extensions):
                return {extension: [] for extension in extensions}
            def mailboxes(self, extensions):
                return {extension: extension + '@default' for extension in extensions}
            def inventory(self, generation, boot):
                return {'complete': True, 'started_tick': time.monotonic(), 'generation': generation,
                        'boot_id': boot, 'channels': []}
        settings = {'phone_device_limit': 25, 'live_paging': {'enabled': '1', 'groups': [{
            'group_id': 'staff', 'name': 'Staff', 'extensions': ['1000'], 'allowed_callers': ['1001']}]}}
        helper = types.SimpleNamespace(scope_fingerprint=lambda *args: 'c' * 64)
        with mock.patch.dict(sys.modules, {'sls_voicemail': helper}), mock.patch.object(phone, 'preserve_audio_media') as generated:
            result = phone.admit_audience(['1000'], service='live', live_origin='1001', live_group='staff',
                directory=self.directory, settings=settings, ami_factory=LiveAmiFixture,
                voicemail=True, duration=30, sound=None, wait_seconds=0)
        generated.assert_not_called()
        self.assertTrue(result['vm_only'])
        self.assertEqual(result['live_dial'], '')
        self.assertEqual(self.state()['batches'][result['token']]['status'], 'reserved')


if __name__ == '__main__':
    unittest.main()
