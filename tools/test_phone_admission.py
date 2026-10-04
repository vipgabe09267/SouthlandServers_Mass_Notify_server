#!/usr/bin/python3
"""Private-store and synthetic Asterisk fixtures; never contacts a real PBX."""
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_phone_admission as phone


class PhoneAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-phone-admission-')
        self.root = Path(self.temp.name)
        self.now = 1000.0
        self.store = phone.PhoneAdmissionStore(self.root, clock=lambda: self.now, lock_seconds=0.03)
        self.generation = 'a' * 32
        self.store.collector_heartbeat(self.generation)
        self.targets = {'1000': ['PJSIP/1000/sip:one@192.0.2.1;transport=UDP',
                                 'PJSIP/1000/sips:two@[2001:db8::1]:5061;transport=TLS']}

    def tearDown(self):
        self.temp.cleanup()

    def inventory(self, rows=None):
        return {'complete': True, 'started_tick': self.now, 'generation': self.generation,
                'boot_id': self.store.boot_id, 'channels': rows or []}

    def admit(self, targets=None, limit=25):
        return self.store.admit(targets or self.targets, limit, self.inventory(), service='manual')

    def contents(self):
        return json.loads((self.root / 'phone-admission.json').read_text())

    def test_counts_contacts_and_rejects_whole_audience_without_mutation(self):
        self.admit()
        before = (self.root / 'phone-admission.json').read_bytes()
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'audience_exceeds'):
            self.admit(limit=1)
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.admit(limit=3)
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())

    def test_frozen_dials_retain_transports_and_reject_dynamic_endpoint_fallback(self):
        token = self.admit()
        self.assertEqual(self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2'), self.targets['1000'])
        for dial in ('PJSIP/1000', 'PJSIP/1000/sip:a@host&Local/911@from-internal', 'PJSIP/1000/sip:a@host\n', 'PJSIP/1000/${SHELL(secret)}'):
            with self.subTest(dial=dial), self.assertRaises(phone.PhoneAdmissionError):
                phone.checked_targets({'1000': [dial]})

    def test_active_capacity_does_not_expire_after_nominal_duration(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.store.bind_contact(token, '1000', '1.2', 'PJSIP/1000-1', self.targets['1000'][0])
        self.now += 3600
        self.store.collector_heartbeat(self.generation)
        live = self.inventory([{'uniqueid': '1.2', 'accountcode': phone.accountcode(token)}])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.store.admit(self.targets, 3, live, service='nws')
        self.assertEqual(self.contents()['batches'][token]['status'], 'active')

    def test_release_requires_complete_current_inventory_and_marks_missed_end_unknown(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.now += 61
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'inventory_unavailable'):
            self.store.admit(self.targets, 2, {'complete': False}, service='nws')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'inventory_stale'):
            self.store.admit(self.targets, 2, {**self.inventory(), 'started_tick': self.now-11}, service='nws')
        self.admit(limit=2)
        closed = self.contents()['batches'][token]
        self.assertEqual(closed['status'], 'closed')
        self.assertTrue(closed['channels']['1.1']['outcome_uncertain'])

    def test_late_call_file_cannot_start_after_reservation_expiry(self):
        token = self.admit()
        self.now += 61
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'expired_or_used'):
            self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.admit(limit=2)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'ticket_unavailable'):
            self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')

    def test_inventory_cannot_erase_newer_channel_registration(self):
        token = self.admit()
        snapshot = self.inventory()
        self.now += 1
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.store.admit(self.targets, 2, snapshot, service='nws')

    def test_origin_and_contact_bindings_cannot_replay_or_expand(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'expired_or_used'):
            self.store.bind_origin(token, '1000', '1.9', 'Local/1000@sls-alert-audio-2;2')
        for uid, dial in zip(('1.2', '1.3'), self.targets['1000']):
            self.store.bind_contact(token, '1000', uid, 'PJSIP/1000-' + uid, dial)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'not_admitted'):
            self.store.bind_contact(token, '1000', '1.4', 'PJSIP/1000-4', 'PJSIP/1000/sip:other@192.0.2.2')
        self.store.ended(token, '1.2', 16)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'not_admitted'):
            self.store.bind_contact(token, '1000', '1.5', 'PJSIP/1000-5', self.targets['1000'][0])

    def test_outcomes_record_evidence_without_claiming_playback_or_receipt(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.store.bind_contact(token, '1000', '1.2', 'PJSIP/1000-1', self.targets['1000'][0])
        self.assertTrue(self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': '1.2',
            'DestAccountCode': phone.accountcode(token), 'DialStatus': 'ANSWER', 'Secret': 'never store'}))
        self.assertTrue(self.store.record_event({'Event': 'ConfbridgeJoin', 'Uniqueid': '1.2', 'AccountCode': phone.accountcode(token)}))
        self.assertTrue(self.store.record_event({'Event': 'Hangup', 'Uniqueid': '1.2', 'AccountCode': phone.accountcode(token), 'Cause': '16'}))
        self.assertFalse(self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': 'OTHER',
            'DestAccountCode': phone.accountcode(token), 'DialStatus': 'ANSWER'}))
        row = self.contents()['batches'][token]['channels']['1.2']
        self.assertEqual(row['dial_status'], 'ANSWER')
        self.assertIsNotNone(row['joined_at'])
        self.assertEqual(row['hangup_cause'], 16)
        raw = (self.root / 'phone-admission.json').read_text()
        self.assertNotIn('never store', raw)
        self.assertNotIn('playback_complete', raw)
        self.store.mark_event_gap()
        self.assertTrue(self.contents()['batches'][token]['outcome_uncertain'])

    def test_unsafe_and_locked_storage_fail_closed(self):
        external = self.root / 'outside'; external.write_text('{}')
        state = self.root / 'phone-admission.json'; state.unlink(); state.symlink_to(external)
        with self.assertRaises(OSError): self.admit()
        state.unlink(); os.link(external, state)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'state_unsafe'): self.admit()
        state.unlink()
        with (self.root / 'phone-admission.lock').open('a') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX)
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'state_busy'): self.admit()
        self.assertEqual(external.read_text(), '{}')

    def test_collector_gap_fences_old_tickets_and_unknown_outcomes(self):
        token = self.admit()
        self.store.mark_event_gap()
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'collector_unavailable'):
            self.admit()
        self.generation = 'b' * 32
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'generation_changed'):
            self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.assertTrue(self.contents()['batches'][token]['outcome_uncertain'])

    def test_channel_id_reuse_conservatively_holds_capacity_and_cannot_authorize_old_ticket(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.now += 1
        self.generation = 'b' * 32
        self.store.collector_heartbeat(self.generation)
        reused = self.inventory([{'uniqueid': '1.1', 'accountcode': 'unrelated-call'}])
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.store.admit(self.targets, 2, reused, service='manual')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'generation_changed'):
            self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')

    def test_missing_history_and_legacy_calls_block_new_admission(self):
        for rows, expected in [
            ([{'uniqueid': '1.1', 'accountcode': phone.accountcode('e' * 32)}], 'orphaned_channels'),
            ([{'uniqueid': '1.1', 'accountcode': '', 'channel': 'Local/1000@sls-alert-audio-00001;2'}], 'legacy_channels'),
        ]:
            with self.subTest(expected=expected), self.assertRaisesRegex(phone.PhoneAdmissionError, expected):
                self.store.admit(self.targets, 25, self.inventory(rows), service='manual')
        self.assertEqual(self.contents()['batches'], {})

    def test_malformed_target_and_public_state_fail_closed(self):
        token = self.admit()
        state = self.contents(); state['batches'][token]['targets']['1001'] = None
        path = self.root / 'phone-admission.json'
        path.write_text(json.dumps(state))
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'state_invalid'): self.admit()
        del state['batches'][token]['targets']['1001']; path.write_text(json.dumps(state)); path.chmod(0o644)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'state_unsafe'): self.admit()

    def test_postrename_fsync_failure_reports_uncertainty_without_returning_token(self):
        actual = phone.os.fsync
        def fail_directory(fd):
            if __import__('stat').S_ISDIR(os.fstat(fd).st_mode):
                raise OSError('fixture failure')
            return actual(fd)
        with mock.patch.object(phone.os, 'fsync', side_effect=fail_directory):
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'commit_uncertain'):
                self.admit()
        self.assertEqual(len(self.contents()['batches']), 1)
        self.assertTrue(all(row['status'] == 'reserved' for row in self.contents()['batches'].values()))

    def test_closed_history_is_bounded_by_bytes_without_evicting_active_work(self):
        first = self.admit()
        with self.store.locked() as state:
            state['batches'][first].update(status='closed', closed_at=self.now)
        active = self.admit()
        with mock.patch.object(phone, 'MAX_CLOSED_BYTES', 1):
            with self.store.locked(): pass
        self.assertNotIn(first, self.contents()['batches'])
        self.assertIn(active, self.contents()['batches'])

    def test_origin_hangup_does_not_release_still_active_child(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.store.bind_contact(token, '1000', '1.2', 'PJSIP/1000-1', self.targets['1000'][0])
        self.store.ended(token, '1.1', 16)
        self.now += 1
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.store.admit(self.targets, 2, self.inventory([{
                'uniqueid': '1.2', 'accountcode': phone.accountcode(token)}]), service='manual')

    def external_origin(self, acknowledgement=False):
        recipient = 'voice_' + 'b' * 24
        target = {'id': recipient, 'number': '+15551234567', 'route_mode': 'pbx_routes', 'trunk_id': '', 'caller_id': ''}
        if acknowledgement: target.update(acknowledgement_required=True, ack_timeout_seconds=10)
        token = self.store.admit({recipient: ['VOICE/' + target['number']]}, 25, self.inventory(), service='announcement',
                                 outbound={recipient: {'target': target, 'proof': {'fingerprint': 'a' * 64, 'trunk_endpoints': ['fixturetrunk']}}})
        self.store.bind_origin(token, recipient, '1.1', 'Local/' + recipient + '@sls-outbound-voice-1;2')
        return token, recipient

    def answered_external(self, acknowledgement=True):
        token, recipient = self.external_origin(acknowledgement)
        self.store.record_event(self.external_begin(recipient))
        self.store.record_event({'Event':'DialEnd', 'DestUniqueid':'1.2', 'DestChannel':'PJSIP/fixturetrunk-00000002', 'DialStatus':'ANSWER'})
        return token, recipient, 'Local/' + recipient + '@sls-outbound-voice-1;1'

    def test_keypad_requires_complete_playback_and_exact_call_identity(self):
        token, recipient, channel = self.answered_external()
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'acknowledgement_invalid'):
            self.store.record_outbound_playback(token, recipient, channel, 'acknowledged')
        self.store.record_outbound_playback(token, recipient, channel, 'complete')
        self.store.record_outbound_playback(token, recipient, channel, 'acknowledged')
        before = self.contents()['batches'][token]['targets'][recipient]['playback']
        self.now += 1
        self.store.record_outbound_playback(token, recipient, channel, 'acknowledged')
        self.store.record_outbound_playback(token, recipient, channel, 'complete')
        self.assertEqual(self.contents()['batches'][token]['targets'][recipient]['playback'], before)
        self.assertEqual(before['ack_status'], 'acknowledged')
        for wrong in (channel[:-1]+'2', channel.replace('-1;', '-2;')):
            with self.assertRaises(phone.PhoneAdmissionError):
                self.store.record_outbound_playback(token, recipient, wrong, 'acknowledged')
        self.store.mark_event_gap()
        with self.assertRaises(phone.PhoneAdmissionError): self.store.record_outbound_playback(token, recipient, channel, 'complete')

    def test_unrequested_or_failed_playback_cannot_create_keypad_receipt(self):
        token, recipient, channel = self.answered_external(False)
        self.store.record_outbound_playback(token, recipient, channel, 'complete')
        with self.assertRaises(phone.PhoneAdmissionError): self.store.record_outbound_playback(token, recipient, channel, 'acknowledged')
        record=self.contents()['batches'][token]['targets'][recipient]['playback']
        self.assertEqual(record['ack_status'],'not_requested');self.assertIsNone(record['acknowledged_at'])

    def test_failed_audio_has_no_completed_or_acknowledged_evidence(self):
        token, recipient, channel = self.answered_external()
        self.store.record_outbound_playback(token, recipient, channel, 'failed')
        with self.assertRaises(phone.PhoneAdmissionError): self.store.record_outbound_playback(token, recipient, channel, 'acknowledged')
        record=self.contents()['batches'][token]['targets'][recipient]['playback']
        self.assertEqual(record['ack_status'],'not_played');self.assertIsNone(record['completed_at'])

    def non_ack_result(self, result):
        token,recipient,channel=self.answered_external()
        self.store.record_outbound_playback(token,recipient,channel,'complete')
        self.store.record_outbound_playback(token,recipient,channel,result)
        record=self.contents()['batches'][token]['targets'][recipient]['playback']
        self.assertEqual(record['ack_status'],result);self.assertIsNone(record['acknowledged_at'])

    def test_timeout_does_not_count_as_acknowledged(self): self.non_ack_result('timeout')
    def test_other_key_does_not_count_as_acknowledged(self): self.non_ack_result('other_key')
    def test_failed_prompt_does_not_count_as_acknowledged(self): self.non_ack_result('prompt_failed')

    def reserve_budget(self, count, limit):
        with self.store.locked() as state:
            phone.PhoneAdmissionStore.reserve_outbound_budget(state,count,limit,self.now)

    def test_external_budget_is_durable_and_not_rewound_by_history_compaction(self):
        self.reserve_budget(2,3);self.reserve_budget(1,3)
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'budget_exceeded'): self.reserve_budget(1,3)
        restarted=phone.PhoneAdmissionStore(self.root,clock=lambda:self.now)
        with restarted.locked() as state:
            restarted.compact(state)
            self.assertEqual(state['outbound_budget']['used'],3)
        # A rejected reservation cannot consume or reset the existing counter.
        self.assertEqual(self.contents()['outbound_budget']['used'],3)
        self.now=86400
        self.reserve_budget(1,3);self.assertEqual(self.contents()['outbound_budget']['used'],1)
        self.now=1000
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'clock_rollback'): self.reserve_budget(1,3)

    def test_unlimited_policy_still_accounts_and_malformed_budget_never_resets(self):
        self.reserve_budget(3,0)
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'budget_exceeded'): self.reserve_budget(1,3)
        with self.store.locked() as state: state['outbound_budget']['used']=-1
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'budget_invalid'): self.reserve_budget(1,0)
        self.assertEqual(self.contents()['outbound_budget']['used'],-1)

    def test_budget_contention_has_exactly_one_available_reservation(self):
        import concurrent.futures
        def attempt(_):
            store=phone.PhoneAdmissionStore(self.root,clock=lambda:self.now,lock_seconds=3)
            try:
                with store.locked() as state: store.reserve_outbound_budget(state,1,1,self.now)
                return True
            except phone.PhoneAdmissionError as error:
                self.assertIn(str(error),('outbound_call_budget_exceeded','phone_state_busy'));return False
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            self.assertEqual(sum(pool.map(attempt,range(8))),1)
        self.assertEqual(self.contents()['outbound_budget']['used'],1)

    def test_failed_budget_commit_cannot_return_an_admission_token(self):
        with mock.patch.object(phone.os,'fsync',side_effect=OSError('private injected write failure')):
            with self.assertRaises(OSError): self.reserve_budget(1,1)
        self.assertNotIn('outbound_budget',self.contents())

    def test_signed_ack_prompt_rejects_missing_changed_linked_and_writable_files(self):
        path=Path(__file__).resolve().parents[1]/'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py'
        spec=importlib.util.spec_from_file_location('ack_prompt_fixture',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        module.check_ack_prompt()
        payload=module.ACK_PROMPT.read_bytes();fixture=self.root/'ack.wav'
        for kind in ('missing','changed','symlink','hardlink','writable'):
            with self.subTest(kind=kind),mock.patch.object(module,'ACK_PROMPT',fixture):
                if kind=='changed': fixture.write_bytes(payload[:-1]+bytes([payload[-1]^1]))
                elif kind=='symlink': fixture.symlink_to(path)
                elif kind=='hardlink': (self.root/'other.wav').write_bytes(payload);os.link(self.root/'other.wav',fixture)
                elif kind=='writable': fixture.write_bytes(payload);fixture.chmod(0o666)
                with self.assertRaisesRegex(phone.PhoneAdmissionError,'ack_prompt_unavailable'): module.check_ack_prompt()
                if fixture.exists() or fixture.is_symlink(): fixture.unlink()

    def external_begin(self, recipient, **changes):
        return {'Event': 'DialBegin', 'Uniqueid': '1.1', 'Channel': 'Local/' + recipient + '@sls-outbound-voice-1;2',
                'AccountCode': 'pbx-replaced-accountcode', 'DestAccountCode': '',
                'DestUniqueid': '1.2', 'DestChannel': 'PJSIP/fixturetrunk-00000002', **changes}

    def test_external_playback_requires_matching_trunk_answer_and_reads_without_mutation(self):
        token, recipient = self.external_origin()
        local = 'Local/' + recipient + '@sls-outbound-voice-1;1'
        self.assertFalse(self.store.outbound_answered(token, recipient, local))
        self.store.record_event(self.external_begin(recipient))
        self.assertFalse(self.store.outbound_answered(token, recipient, local))
        for status in ['BUSY', 'NOANSWER', 'CONGESTION']:
            self.store.record_event({'Event':'DialEnd', 'DestUniqueid':'1.2', 'DestChannel':'PJSIP/fixturetrunk-00000002', 'DialStatus':status})
            self.assertFalse(self.store.outbound_answered(token, recipient, local))
        self.store.record_event({'Event':'DialEnd', 'DestUniqueid':'1.2', 'DestChannel':'PJSIP/fixturetrunk-00000002', 'DialStatus':'ANSWER'})
        before = (self.root / 'phone-admission.json').read_bytes()
        self.assertTrue(self.store.outbound_answered(token, recipient, local))
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())
        for wrong in [local[:-1] + '2', local.replace('-1;', '-2;'), 'PJSIP/fixturetrunk-00000002']:
            with self.subTest(channel=wrong), self.assertRaises(phone.PhoneAdmissionError):
                self.store.outbound_answered(token, recipient, wrong)
        self.store.ended(token, '1.2')
        self.assertFalse(self.store.outbound_answered(token, recipient, local))
        self.store.ended(token, '1.1')
        with self.assertRaises(phone.PhoneAdmissionError): self.store.outbound_answered(token, recipient, local)

    def test_answer_of_one_external_recipient_never_starts_another_and_gaps_deny_playback(self):
        recipients = ['voice_' + digit * 24 for digit in ['b', 'c']]
        metadata = {recipient: {'target': {'id':recipient, 'number':'+1555123456'+str(index), 'route_mode':'trunk', 'trunk_id':'1', 'caller_id':''},
                               'proof': {'fingerprint':'a'*64, 'trunk_endpoints':['fixturetrunk']}} for index, recipient in enumerate(recipients)}
        token = self.store.admit({key:['VOICE/'+row['target']['number']] for key,row in metadata.items()},25,self.inventory(),service='announcement',outbound=metadata)
        for index, recipient in enumerate(recipients):
            origin = str(index)+'.1'; child = str(index)+'.2'
            local = 'Local/'+recipient+'@sls-outbound-voice-1;2'
            self.store.bind_origin(token,recipient,origin,local)
            self.store.record_event(self.external_begin(recipient,Uniqueid=origin,Channel=local,DestUniqueid=child,DestChannel='PJSIP/fixturetrunk-0000000'+str(index)))
        self.store.record_event({'Event':'DialEnd','DestUniqueid':'1.2','DestChannel':'PJSIP/fixturetrunk-00000001','DialStatus':'ANSWER'})
        self.assertFalse(self.store.outbound_answered(token,recipients[0],'Local/'+recipients[0]+'@sls-outbound-voice-1;1'))
        self.assertTrue(self.store.outbound_answered(token,recipients[1],'Local/'+recipients[1]+'@sls-outbound-voice-1;1'))
        self.store.mark_event_gap()
        with self.assertRaises(phone.PhoneAdmissionError):
            self.store.outbound_answered(token,recipients[1],'Local/'+recipients[1]+'@sls-outbound-voice-1;1')
        self.store.collector_heartbeat('d'*32)
        with self.assertRaisesRegex(phone.PhoneAdmissionError,'generation_changed'):
            self.store.outbound_answered(token,recipients[1],'Local/'+recipients[1]+'@sls-outbound-voice-1;1')

    def test_agi_playback_waits_for_answer_and_times_out_without_releasing_audio(self):
        path = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py'
        spec = importlib.util.spec_from_file_location('playback_agi_fixture', path)
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        token, recipient = self.external_origin()
        class Agi:
            environment = {'agi_uniqueid':'1.0','agi_channel':'Local/'+recipient+'@sls-outbound-voice-1;1'}
            values = {'${SLS_PHONE_TOKEN}':token,'${SLS_PHONE_RECIPIENT}':recipient,'${SLS_SOUND}':'SLS_Mass_Notifications_Plugin/tts/fixture'}
            def __init__(self): self.writes = {}
            def variable(self, name): return self.values.get(name,'')
            def set(self, name, value): self.writes[name] = value
        agi = Agi()
        with mock.patch.object(self.store,'outbound_answered',side_effect=[False,True]), mock.patch.object(module.time,'sleep') as pause:
            self.assertTrue(module.handle('playback',agi,self.store))
        self.assertEqual(agi.writes['SLS_SAFE_SOUND'],agi.values['${SLS_SOUND}'])
        self.assertEqual(agi.writes['SLS_PHONE_ALLOW'],'1'); pause.assert_called_once_with(0.1)
        agi = Agi()
        with mock.patch.object(self.store,'outbound_answered',return_value=False), mock.patch.object(module.time,'monotonic',side_effect=[0,5.1]):
            with self.assertRaisesRegex(phone.PhoneAdmissionError,'answer_unconfirmed'): module.handle('playback',agi,self.store)
        self.assertEqual(agi.writes['SLS_PHONE_ALLOW'],'0'); self.assertNotIn('SLS_SAFE_SOUND',agi.writes)
        agi.values = {**agi.values,'${SLS_SOUND}':'../../outside'}
        with self.assertRaises(phone.PhoneAdmissionError): module.handle('playback',agi,self.store)

    def test_replaced_accountcode_uses_known_uid_and_exact_channel_and_keeps_child_capacity(self):
        token, recipient = self.external_origin()
        self.assertTrue(self.store.record_event(self.external_begin(recipient)))
        self.assertTrue(self.store.record_event({'Event': 'DialEnd', 'DestUniqueid': '1.2',
            'DestChannel': 'PJSIP/fixturetrunk-00000002', 'DestAccountCode': 'pbx-replaced-accountcode', 'DialStatus': 'ANSWER'}))
        self.store.ended(token, '1.1', 16)
        self.now += 1
        child = {'uniqueid': '1.2', 'accountcode': 'pbx-replaced-accountcode', 'channel': 'PJSIP/fixturetrunk-00000002'}
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'capacity_busy'):
            self.store.admit(self.targets, 2, self.inventory([child]), service='manual')
        self.assertEqual(self.contents()['batches'][token]['channels']['1.2']['dial_status'], 'ANSWER')
        self.assertTrue(self.store.record_event({'Event': 'Hangup', 'Uniqueid': '1.2', 'Channel': child['channel'],
                                                'AccountCode': '', 'Cause': '16'}))
        self.now += 1
        self.admit(limit=2)
        self.assertEqual(self.contents()['batches'][token]['status'], 'closed')

    def test_unknown_uid_wrong_channel_and_previous_generation_cannot_establish_outcome_identity(self):
        token, recipient = self.external_origin()
        for changes in [{'Uniqueid': '7.9', 'AccountCode': phone.accountcode(token)},
                        {'Channel': 'Local/unrelated-1;2', 'AccountCode': phone.accountcode(token)},
                        {'Uniqueid': '7.9', 'AccountCode': ''}]:
            self.assertFalse(self.store.record_event(self.external_begin(recipient, **changes)))
        self.store.mark_event_gap()
        self.generation = 'c' * 32
        self.store.collector_heartbeat(self.generation)
        self.assertFalse(self.store.record_event(self.external_begin(recipient, AccountCode=phone.accountcode(token))))
        self.assertEqual(set(self.contents()['batches'][token]['channels']), {'1.1'})
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'generation_changed'):
            self.store.bind_origin(token, recipient, '1.1', 'Local/' + recipient + '@sls-outbound-voice-1;2')

    def test_external_gap_retains_unknown_untagged_child_until_completely_empty_inventory(self):
        token, recipient = self.external_origin()
        self.store.mark_event_gap()
        self.generation = 'c' * 32
        self.store.collector_heartbeat(self.generation)
        self.now += 1
        unknown_child = {'uniqueid': '8.2', 'accountcode': 'changed-by-pbx', 'channel': 'PJSIP/fixturetrunk-00000002'}
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'outbound_evidence_gap'):
            self.store.admit(self.targets, 25, self.inventory([unknown_child]), service='manual')
        self.assertEqual(self.contents()['batches'][token]['status'], 'active')
        self.admit(limit=2)
        closed = self.contents()['batches'][token]
        self.assertEqual(closed['status'], 'closed')
        self.assertTrue(closed['outcome_uncertain'])
        self.assertEqual(closed['channels']['1.1']['dial_status'], '')

    def test_internal_gap_does_not_wait_for_unrelated_pbx_calls_to_end(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        self.store.mark_event_gap()
        self.generation = 'c' * 32
        self.store.collector_heartbeat(self.generation)
        self.now += 1
        self.store.admit(self.targets, 2, self.inventory([{'uniqueid': '99.9', 'accountcode': '', 'channel': 'PJSIP/unrelated-9'}]), service='manual')
        self.assertEqual(self.contents()['batches'][token]['status'], 'closed')

    def test_unrelated_pbx_events_do_not_rewrite_the_admission_ledger(self):
        self.external_origin()
        before = (self.root / 'phone-admission.json').read_bytes()
        with mock.patch.object(phone.os, 'fsync', wraps=phone.os.fsync) as sync:
            self.assertFalse(self.store.record_event({'Event': 'Hangup', 'Uniqueid': '9.9',
                                                      'Channel': 'PJSIP/unrelated-9', 'AccountCode': ''}))
            sync.assert_not_called()
        self.assertEqual(before, (self.root / 'phone-admission.json').read_bytes())

    def test_unexpected_external_branch_remains_quarantined_after_origin_and_first_child_exit(self):
        token, recipient = self.external_origin()
        self.assertTrue(self.store.record_event(self.external_begin(recipient, DestChannel='Local/unsupported@custom-1;1')))
        self.store.ended(token, '1.1', 16)
        self.store.ended(token, '1.2', 16)
        self.now += 1
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'outbound_channel_unexpected'):
            self.store.admit(self.targets, 25, self.inventory([{'uniqueid': '9.9', 'accountcode': '', 'channel': 'PJSIP/unknown-descendant-9'}]), service='manual')
        self.assertEqual(self.contents()['batches'][token]['status'], 'active')
        self.admit(limit=2)
        self.assertEqual(self.contents()['batches'][token]['status'], 'closed')

    def test_wall_clock_rollback_cannot_extend_start_deadline(self):
        tick = [1000.0]
        self.store.monotonic = lambda: tick[0]
        token = self.admit()
        self.now -= 3600
        tick[0] += 61
        self.store.collector_heartbeat(self.generation)
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'expired_or_used'):
            self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')

    def test_per_contact_guard_uses_exact_admitted_transport_target(self):
        token = self.admit()
        self.store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'not_admitted'):
            self.store.bind_contact(token, '1000', '1.2', 'PJSIP/1000-1',
                self.targets['1000'][1].replace('transport=TLS', 'transport=TCP'))

    def test_agi_origin_supplies_frozen_contacts_and_denies_wrong_child(self):
        path = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py'
        spec = importlib.util.spec_from_file_location('phone_agi_fixture', path)
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        token = self.admit()
        class FakeAgi:
            environment = {'agi_uniqueid': '1.1', 'agi_channel': 'Local/1000@sls-alert-audio-1;2'}
            values = {'${SLS_PHONE_TOKEN}': token, '${SLS_PHONE_RECIPIENT}': '1000',
                      '${CHANNEL(endpoint)}': '1000', '${CHANNEL(pjsip,target_uri)}': 'sip:wrong@192.0.2.44'}
            def __init__(self): self.writes = {}
            def variable(self, name): return self.values.get(name, '')
            def set(self, name, value): self.writes[name] = value
        origin = FakeAgi()
        self.assertTrue(module.handle('origin', origin, self.store))
        self.assertEqual(origin.writes['SLS_DIAL'], '&'.join(self.targets['1000']))
        child = FakeAgi(); child.environment = {'agi_uniqueid': '1.2', 'agi_channel': 'PJSIP/1000-1'}
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'not_admitted'):
            module.handle('contact', child, self.store)
        self.assertEqual(child.writes['SLS_PHONE_ALLOW'], '0')


class LiveGroupAdmissionTests(unittest.TestCase):
    def test_external_group_requires_both_opt_ins_exact_origin_and_exact_members(self):
        settings = self.settings()
        paging = settings['live_paging']
        paging.update(extension='799', external_access='1')
        group = paging['groups'][0]
        group.update(allow_external='1', pin_hash='fixture-only-protected-hash', external_callers=['+15125550123'])
        members = ['1000', '1001', '1002']
        phone.check_live_audience(settings, members, '799', 'paging_staff', True, '+15125550123')
        for origin, flag, targets in [('1000', True, members), ('799', False, members),
                                     ('799', '1', members), ('799', True, ['1001'])]:
            with self.subTest(origin=origin, flag=flag, targets=targets), self.assertRaises(phone.PhoneAdmissionError):
                phone.check_live_audience(settings, targets, origin, 'paging_staff', flag, '+15125550123')
        for number in (None, '', '+15125550124', '5125550123', ['+15125550123']):
            with self.subTest(number=number), self.assertRaisesRegex(phone.PhoneAdmissionError, 'origin_invalid'):
                phone.check_live_audience(settings, members, '799', 'paging_staff', True, number)
        for obj, key in [(paging, 'external_access'), (group, 'allow_external'), (group, 'pin_hash'), (group, 'external_callers')]:
            saved = obj[key]
            obj[key] = ''
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'origin_invalid'):
                phone.check_live_audience(settings, members, '799', 'paging_staff', True, '+15125550123')
            obj[key] = saved

    def settings(self):
        return {'live_paging': {'enabled': '1', 'allowed_callers': ['9999'], 'groups': [
            {'group_id': 'paging_staff', 'name': 'Staff', 'allowed_callers': ['1000'],
             'extensions': ['1000', '1001', '1002'], 'notify_extensions': ['1003']}]}}

    def test_independent_group_accepts_its_caller_and_exact_audio_audience(self):
        phone.check_live_audience(self.settings(), ['1001', '1002'], '1000', 'paging_staff')

    def test_live_service_requires_both_identity_fields_before_ami_or_state_mutation(self):
        for identity in ({}, {'live_origin': '1000'}, {'live_group': 'paging_staff'},
                         {'live_origin': None, 'live_group': None}):
            with self.subTest(identity=identity), mock.patch.object(phone, 'PhoneAdmissionStore') as store:
                ami = mock.Mock(side_effect=AssertionError('Malformed request reached AMI'))
                with self.assertRaisesRegex(phone.PhoneAdmissionError, 'phone_live_origin_invalid'):
                    phone.admit_audience(['1001', '1002'], service='live', settings=self.settings(),
                                         ami_factory=ami, **identity)
                ami.assert_not_called()
                self.assertEqual(store.return_value.mock_calls, [])

    def test_malformed_live_stdin_request_returns_rejection_without_contacting_pbx(self):
        request = {'internal': ['1001', '1002'], 'service': 'live', 'wait_seconds': 0}
        source = mock.Mock(buffer=io.BytesIO(json.dumps(request).encode()))
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', ['sls_phone_admission.py', '--request-stdin']), \
                mock.patch.object(sys, 'stdin', source), mock.patch.object(sys, 'stdout', output), \
                mock.patch.object(phone, 'read_settings', return_value=self.settings()), \
                mock.patch.object(phone, 'PhoneAdmissionStore') as store, \
                mock.patch.object(phone.PhoneAmi, '__enter__', side_effect=AssertionError('Malformed request reached AMI')) as connect:
            self.assertEqual(phone.main(), 1)
            connect.assert_not_called()
            self.assertEqual(store.return_value.mock_calls, [])
        result = json.loads(output.getvalue())
        self.assertFalse(result['ok'])
        self.assertEqual(result['failure_code'], 'phone_live_origin_invalid')

    def test_global_caller_cannot_bypass_group_authorization(self):
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'origin_invalid'):
            phone.check_live_audience(self.settings(), ['1000', '1001', '1002'], '9999', 'paging_staff')

    def test_changed_or_expanded_audience_and_unknown_group_are_rejected(self):
        for recipients in (['1001'], ['1001', '1002', '1003'], ['1000', '1001', '1002'], ['1001', '1002', '1002']):
            with self.subTest(recipients=recipients), self.assertRaisesRegex(phone.PhoneAdmissionError, 'audience_changed'):
                phone.check_live_audience(self.settings(), recipients, '1000', 'paging_staff')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'group_invalid'):
            phone.check_live_audience(self.settings(), ['1001', '1002'], '1000', 'deleted')

    def test_legacy_reference_preserves_global_callers_and_exact_members(self):
        settings = {'live_paging': {'enabled': '1', 'allowed_callers': ['1000'],
                    'groups': [{'group_id': 'grp_staff'}]},
                    'announcement_groups': [{'id': 'grp_staff', 'extensions': ['1000', '1001']}]}
        phone.check_live_audience(settings, ['1001'], '1000', 'grp_staff')
        settings['announcement_groups'][0]['extensions'].append('1002')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'audience_changed'):
            phone.check_live_audience(settings, ['1001'], '1000', 'grp_staff')


class PhoneAmiTests(unittest.TestCase):
    class Socket:
        def __init__(self, respond): self.respond = respond; self.buffer = b''; self.sent = []
        def settimeout(self, value): pass
        def close(self): pass
        def sendall(self, raw):
            fields = dict(line.split(': ', 1) for line in raw.decode().strip().split('\r\n'))
            self.sent.append(fields)
            messages = self.respond(fields)
            self.buffer += b''.join(('\r\n'.join(key + ': ' + str(value) for key, value in message.items()) + '\r\n\r\n').encode() for message in messages)
        def recv(self, maximum):
            value, self.buffer = self.buffer[:maximum], self.buffer[maximum:]
            return value

    def test_action_correlation_complete_inventory_and_start_fence(self):
        def responses(fields):
            action_id = fields['ActionID']
            success = {'Response': 'Success', 'ActionID': action_id}
            if fields['Action'] != 'CoreShowChannels': return [success]
            return [{'Event': 'Hangup', 'Uniqueid': 'unrelated'}, success,
                    {'Event': 'CoreShowChannel', 'ActionID': action_id, 'Uniqueid': '1.1', 'AccountCode': 'fixture',
                     'Channel': 'PJSIP/1000-1', 'Context': 'default'},
                    {'Event': 'CoreShowChannelsComplete', 'ActionID': action_id, 'ListItems': '1'}]
        sock = self.Socket(responses)
        with phone.PhoneAmi({'ami': {'username': 'fixture', 'password': 'not-logged'}},
                connect=lambda *a, **kw: sock) as ami:
            before = time.monotonic()
            inventory = ami.inventory('a' * 32, 'fixture-boot')
            self.assertLessEqual(before, inventory['started_tick'])
            self.assertLessEqual(inventory['started_tick'], time.monotonic())
        self.assertEqual(inventory['channels'], [{'uniqueid': '1.1', 'accountcode': 'fixture', 'channel': 'PJSIP/1000-1', 'context': 'default'}])
        self.assertEqual(sock.sent[0]['Events'], 'off')

    def test_truncated_inventory_and_malformed_messages_fail_closed(self):
        def responses(fields):
            success = {'Response': 'Success', 'ActionID': fields['ActionID']}
            return [success] if fields['Action'] == 'Login' else [success,
                {'Event': 'CoreShowChannelsComplete', 'ActionID': fields['ActionID'], 'ListItems': '2'}]
        with phone.PhoneAmi({'ami': {'username': 'fixture', 'password': 'not-logged'}},
                connect=lambda *a, **kw: self.Socket(responses)) as ami:
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'inventory_incomplete'):
                ami.inventory('a' * 32, 'fixture-boot')
            ami.buffer = b'Event: First\r\nEvent: Other\r\n\r\n'
            with self.assertRaisesRegex(phone.PhoneAdmissionError, 'message_invalid'):
                ami.receive(time.monotonic()+1)

    def test_alias_inventory_preserves_every_transport_and_no_dynamic_fallback(self):
        ami = phone.PhoneAmi({})
        values = {'PJSIP_DIAL_CONTACTS(1000)': '', 'DB(DEVICE/1000/dial)': 'PJSIP/device-a',
                  'PJSIP_DIAL_CONTACTS(device-a)': 'PJSIP/device-a/sip:one@192.0.2.1;transport=TCP&PJSIP/device-a/sips:two@[2001:db8::1];transport=TLS',
                  'PJSIP_DIAL_CONTACTS(1001)': '', 'DB(DEVICE/1001/dial)': 'PJSIP/1001'}
        with mock.patch.object(ami, 'variable', side_effect=lambda expression: values.get(expression, '')):
            targets = ami.contacts(['1000', '1001'])
        self.assertEqual(len(targets['1000']), 2)
        self.assertIn('transport=TCP', targets['1000'][0])
        self.assertIn('transport=TLS', targets['1000'][1])
        self.assertEqual(targets['1001'], [])


if __name__ == '__main__':
    unittest.main()
