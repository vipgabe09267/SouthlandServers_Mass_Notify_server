#!/usr/bin/python3
"""Absolute schedule windows at audio admission and the HTTPS POST boundary.

Temporary ledgers, synthetic Asterisk inventory and an inert HTTPS connection
exercise real code. No PBX calls, public network or production configuration.
"""
from contextlib import contextmanager
import json
from pathlib import Path
import socket
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_audio_queue as audio
import sls_phone_admission as phone
import sls_notification_destinations as webhooks


class AudioDeadlineTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.sound = 'SLS_Mass_Notifications_Plugin/tts/fixture'

    def test_waiting_ticket_expires_without_reserving_playback(self):
        ticket = audio.request_ticket(['1000'], 30, self.sound, directory=self.root, now=1000, latest_start=1005)
        with self.assertRaisesRegex(RuntimeError, 'expired'):
            audio.claim_ticket(ticket, directory=self.root, now=1005)
        state = json.loads((self.root/'audio-reservations.json').read_text())
        self.assertEqual(state['waiting'][ticket]['expires'], 1005)
        self.assertEqual(state['recipients'], {})
        self.assertEqual(state['media'], {})

    def test_deadline_is_checked_after_lock_acquisition(self):
        ticket = audio.request_ticket(['1000'], 30, self.sound, directory=self.root, now=1000, latest_start=1005)
        locked = audio.ticket_state
        clock = [1004]
        @contextmanager
        def delayed_lock(directory):
            with locked(directory) as state:
                clock[0] = 1006
                yield state
        with patch.object(audio, 'ticket_state', delayed_lock), patch.object(audio.time, 'time', lambda: clock[0]):
            with self.assertRaisesRegex(RuntimeError, 'expired'):
                audio.claim_ticket(ticket, directory=self.root)
            with self.assertRaisesRegex(RuntimeError, 'deadline'):
                audio.request_ticket(['1001'], 30, self.sound, directory=self.root, latest_start=1005)
        self.assertEqual(json.loads((self.root/'audio-reservations.json').read_text())['recipients'], {})

    def test_monotonic_deadline_survives_wall_clock_rollback(self):
        ticket = audio.request_ticket(['1000'], 30, self.sound, directory=self.root, now=1000, latest_start=1005)
        with patch.object(audio.time, 'monotonic', return_value=25):
            with self.assertRaisesRegex(RuntimeError, 'deadline'):
                audio.claim_ticket(ticket, directory=self.root, now=900, latest_start_tick=24)

    def test_phone_ticket_cannot_start_after_schedule_deadline(self):
        now = [1000.0]
        store = phone.PhoneAdmissionStore(self.root, clock=lambda: now[0])
        store.collector_heartbeat('a'*32)
        inventory = {'complete': True, 'started_tick': 1000.0, 'generation': 'a'*32,
                     'boot_id': store.boot_id, 'channels': []}
        targets = {'1000': ['PJSIP/1000/sip:fixture@192.0.2.1']}
        token = store.admit(targets, 25, inventory, service='announcement', latest_start=1003)
        batch = json.loads((self.root/'phone-admission.json').read_text())['batches'][token]
        self.assertEqual(batch['start_deadline'], 1003)
        now[0] = 1003
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'expired_or_used'):
            store.bind_origin(token, '1000', '1.1', 'Local/1000@sls-alert-audio-1;2')
        with self.assertRaisesRegex(phone.PhoneAdmissionError, 'schedule_deadline_expired'):
            store.admit(targets, 25, inventory, service='announcement', latest_start=1003)


class WebhookDeadlineTests(unittest.TestCase):
    def setUp(self):
        self.wall, self.tick = 1000.0, 10.0
        self.calls = []
        self.rows = [{'id': name, 'name': name, 'enabled': '1', 'url': 'https://hooks.example.com/'+name}
                     for name in ('first', 'second')]
        self.config = {'announcement_webhooks': self.rows}

    def dispatch(self, transport, latest_start=1001):
        return webhooks.dispatch_announcement_webhooks(self.config, 'Notice', 'Fixture', live=True,
            destination_ids=['first', 'second'], expected_fingerprints={r['id']: webhooks.destination_fingerprint(r) for r in self.rows},
            transport=transport, clock=lambda: self.tick, wall_clock=lambda: self.wall,
            sleep=self.sleep, latest_start=latest_start, enforce_wall_clock=False)

    def sleep(self, seconds):
        self.wall += seconds; self.tick += seconds

    def test_started_request_can_finish_but_next_destination_cannot_start(self):
        def transport(*args, **kwargs):
            self.assertTrue(kwargs['submission_guard']())
            self.calls.append(args)
            self.sleep(2)
            return 204, ''
        result = self.dispatch(transport)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual([r['status'] for r in result], ['accepted', 'failed'])
        self.assertEqual(result[1]['error'], 'schedule_deadline_expired')
        self.assertFalse(result[1]['retryable'])

    def test_retry_after_delay_does_not_extend_start_window(self):
        def transport(*args, **kwargs):
            self.calls.append(args)
            self.wall = 1000.9; self.tick = 10.9
            return 429, '2'
        result = self.dispatch(transport)
        self.assertEqual(len(self.calls), 1)
        self.assertTrue(all(r['error'] == 'schedule_deadline_expired' for r in result))

    def test_connection_completes_after_window_without_sending_post(self):
        test = self
        class Connection:
            sock = None
            def __init__(self, *args): pass
            def connect(self): test.wall = 1002
            def request(self, *args, **kwargs): test.fail('POST started after TLS used the remaining window')
            def close(self): pass
        resolver = lambda *args, **kwargs: [(socket.AF_INET, socket.SOCK_STREAM, 6, '', ('8.8.8.8', 443))]
        with patch.object(webhooks, '_PinnedHTTPSConnection', Connection):
            with self.assertRaises(webhooks.DestinationError) as error:
                webhooks._request_once(self.rows[0]['url'], 'generic', {}, resolver=resolver,
                                       submission_guard=lambda: self.wall < 1001)
        self.assertEqual(error.exception.code, 'schedule_deadline_expired')
        self.assertFalse(error.exception.submission_possible)

    def test_clock_rollback_does_not_admit_next_destination(self):
        def transport(*args, **kwargs):
            self.calls.append(args)
            self.wall = 900; self.tick = 12
            return 200, ''
        result = self.dispatch(transport)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(result[1]['error'], 'schedule_deadline_expired')

    def test_invalid_and_expired_deadlines_never_use_transport(self):
        def transport(*args, **kwargs): self.fail('Unexpected transport')
        for deadline in (True, 1001.5, '1001', -1):
            with self.subTest(deadline=deadline), self.assertRaises(webhooks.DestinationError):
                self.dispatch(transport, latest_start=deadline)
        self.assertTrue(all(r['error'] == 'schedule_deadline_expired' for r in self.dispatch(transport, latest_start=1000)))


if __name__ == '__main__':
    unittest.main()
