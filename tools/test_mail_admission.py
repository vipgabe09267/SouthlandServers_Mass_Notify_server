#!/usr/bin/python3
"""Bounded queue and mail-admission fixtures; never contact the live MTA."""
import concurrent.futures
import json
import os
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
import sls_mail_admission as mail
import sls_announcement_email as announcement
import sls_system_notifications as faults
import sls_notification_destinations as destinations
import sls_branded_email as branded


class MailAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-mail-admission-'); self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.account = types.SimpleNamespace(pw_uid=os.geteuid(), pw_gid=os.getegid())
        self.lookup = mock.patch.object(mail.pwd, 'getpwnam', return_value=self.account)
        self.lookup.start(); self.addCleanup(self.lookup.stop)
        self.now = 1_000_000
        self.scans = 0

    def queue(self):
        self.scans += 1; return 0

    def reserve(self, category='alert', recipients=1, inspector=None):
        return mail.reserve(category, recipients, directory=self.directory, clock=lambda: self.now,
                            queue_inspector=inspector or self.queue)

    def state(self): return json.loads((self.directory / mail.STATE).read_bytes())

    def fake_queue(self, source):
        program = self.directory / 'postqueue-fixture'
        program.write_text('#!/usr/bin/python3\n' + source); program.chmod(0o700)
        return [str(program)]

    def test_burst_scans_once_and_uses_reserved_depth(self):
        for _ in range(20): self.reserve()
        self.assertEqual(self.scans, 1)
        self.assertEqual(self.state()['probe']['reserved'], 20)
        self.assertLess((self.directory / mail.STATE).stat().st_size, mail.MAX_STATE)
        self.assertNotIn('recipient', (self.directory / mail.STATE).read_text())

    def test_offline_postfix_backpressure_does_not_enqueue_or_consume_mail_budget(self):
        with self.assertRaisesRegex(mail.MailAdmissionBlocked, 'backlog'): self.reserve(inspector=lambda: 200)
        state = self.state(); self.assertEqual(state['probe']['reserved'], 0)
        self.assertEqual(sum(row['counts']['total'][0] for row in state['windows']['60']), 0)
        self.now += mail.PROBE_CACHE_SECONDS
        self.assertTrue(self.reserve()['ok'])

    def test_cached_backlog_accounts_for_new_reservations(self):
        self.assertTrue(self.reserve(inspector=lambda: 199)['ok'])
        with self.assertRaisesRegex(mail.MailAdmissionBlocked, 'backlog'): self.reserve(inspector=lambda: 0)
        self.assertEqual(self.state()['probe']['reserved'], 1)

    def test_unavailable_queue_is_explicit_bounded_and_recovers(self):
        def unavailable():
            self.scans += 1; raise mail.MailAdmissionBlocked('mail_queue_unavailable')
        for _ in range(10):
            with self.assertRaisesRegex(mail.MailAdmissionBlocked, 'could not be checked'): self.reserve(inspector=unavailable)
        self.assertEqual(self.scans, 1)
        self.now += mail.PROBE_CACHE_SECONDS
        self.assertTrue(self.reserve()['ok'])

    def test_system_fault_budget_does_not_use_up_authorized_alert_budget(self):
        self.reserve('system', 50); self.reserve('system', 50)
        with self.assertRaises(mail.MailAdmissionBlocked) as error: self.reserve('system')
        self.assertEqual(error.exception.code, 'mail_rate_limited')
        self.assertTrue(self.reserve('alert', 50)['ok'])
        self.assertTrue(self.reserve('account')['ok'])

    def test_recipient_and_message_limits_are_distinct(self):
        for _ in range(20): self.reserve('alert', 50)
        with self.assertRaises(mail.MailAdmissionBlocked) as error: self.reserve('alert', 1)
        self.assertEqual(error.exception.code, 'mail_rate_limited')
        self.now += 121
        self.assertTrue(self.reserve('alert', 1)['ok'])

    def test_daily_fault_cap_survives_stage_changes_and_processes(self):
        for _ in range(20):
            self.reserve('system'); self.now += 4000
        with self.assertRaises(mail.MailAdmissionBlocked) as error: self.reserve('system')
        self.assertEqual(error.exception.code, 'mail_rate_limited')

    def test_concurrent_fault_senders_cannot_bypass_durable_limit(self):
        def attempt(_):
            try: return self.reserve('system')['ok']
            except mail.MailAdmissionBlocked: return False
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            accepted = list(pool.map(attempt, range(12)))
        self.assertEqual(sum(accepted), 2)
        self.assertEqual(sum(row['counts']['system'][0] for row in self.state()['windows']['60']), 2)

    def test_clock_rollback_and_missing_established_state_do_not_reset_counts(self):
        self.reserve(); before = (self.directory / mail.STATE).read_bytes(); self.now -= 1
        with self.assertRaises(mail.MailAdmissionBlocked): self.reserve()
        self.assertEqual((self.directory / mail.STATE).read_bytes(), before)
        self.now += 2; (self.directory / mail.STATE).unlink()
        with self.assertRaises(mail.MailAdmissionBlocked): self.reserve()
        self.assertFalse((self.directory / mail.STATE).exists())

    def test_bad_state_modes_duplicate_keys_and_links_are_rejected(self):
        self.reserve(); original = (self.directory / mail.STATE).read_bytes()
        for kind in ('mode', 'duplicate', 'symlink', 'hardlink'):
            with self.subTest(kind=kind):
                path = self.directory / mail.STATE; path.unlink(); path.write_bytes(original); path.chmod(0o640)
                if kind == 'mode': path.chmod(0o644)
                elif kind == 'duplicate': path.write_bytes(b'{"schema":1,"schema":1}')
                elif kind == 'symlink': path.unlink(); path.symlink_to(self.directory / mail.LOCK)
                else: os.link(path, self.directory / 'extra-state')
                scans = self.scans
                with self.assertRaises(mail.MailAdmissionBlocked): self.reserve()
                self.assertEqual(self.scans, scans)

    def test_concurrent_journal_edit_during_queue_probe_is_preserved(self):
        self.reserve(); self.now += mail.PROBE_CACHE_SECONDS
        path = self.directory / mail.STATE
        def changed():
            path.write_bytes(b'{"administrator":"preserve for review"}')
            return 0
        with self.assertRaises(mail.MailAdmissionBlocked): self.reserve(inspector=changed)
        self.assertEqual(path.read_bytes(), b'{"administrator":"preserve for review"}')

    def test_real_private_queue_process_stops_at_cap_without_recording_addresses(self):
        program = self.fake_queue('import json\nfor n in range(19000):\n print(json.dumps({"queue_id":str(n),"queue_name":"deferred","sender":"PRIVATE@example.com","recipients":[{"address":"SECRET@example.com"}]}))\n')
        start = time.monotonic(); self.assertEqual(mail.inspect_queue(command=program), mail.QUEUE_LIMIT)
        self.assertLess(time.monotonic() - start, 1)
        self.assertFalse((self.directory / mail.STATE).exists())

    def test_queue_failure_timeout_invalid_and_oversized_lines_are_bounded(self):
        for source in ('import sys\nsys.exit(1)\n', 'print("not JSON")\n', 'print("x"*70000)\n', 'import time\ntime.sleep(30)\n'):
            with self.subTest(source=source):
                program = self.fake_queue(source); start = time.monotonic()
                with self.assertRaises(mail.MailAdmissionBlocked) as error: mail.inspect_queue(command=program, timeout=.1)
                self.assertEqual(error.exception.code, 'mail_queue_unavailable'); self.assertLess(time.monotonic() - start, .5)

    def test_fault_uncertain_submission_does_not_replay_after_retry_window(self):
        path = self.directory / 'fault-state.json'; calls = []
        fault = faults._candidate('weather', 'poll', 'Provider is unreachable', 'time')[1]
        def uncertain(*args, **kwargs):
            calls.append(1); raise TimeoutError('synthetic uncertain sendmail')
        with self.assertRaises(TimeoutError): faults.process_faults({'system_notification_emails': 'a@example.com'},
            {'weather': fault}, path, sender=uncertain, now=self.now)
        self.now += faults.RETRY_SECONDS + 1
        result = faults.process_faults({'system_notification_emails': 'a@example.com'}, {'weather': fault}, path,
            sender=uncertain, now=self.now)
        self.assertEqual(result['sent'], 0); self.assertEqual(calls, [1])

    def test_default_or_empty_recipient_settings_never_submit_active_fault_mail(self):
        path = self.directory / 'fault-state.json'
        fault = faults._candidate('weather', 'poll', 'Provider is unreachable', 'time')[1]
        def forbidden_sender(*args, **kwargs):
            raise AssertionError('Unconfigured email started a transport')
        for config in ({}, {'system_notification_emails': ''},
                       {'system_notification_emails': '', 'mail_to': 'legacy@example.com'},
                       {'mail_from_domain': 'example.com', 'email_html_enabled': '1'}):
            result = faults.process_faults(config, {'weather': fault}, path,
                sender=forbidden_sender, now=self.now)
            self.assertEqual(result['sent'], 0)
            with (mock.patch.object(branded._mail_admission, 'reserve', side_effect=forbidden_sender),
                    mock.patch.object(branded.subprocess, 'run', side_effect=forbidden_sender)):
                self.assertIs(branded.send_branded_email(config, 'Fault', 'Details', 'System Fault'), False)
        self.assertFalse((self.directory / mail.STATE).exists())

    def test_fault_accepted_then_receipt_write_failure_stays_suppressed(self):
        path = self.directory / 'fault-state.json'; calls = []; writes = 0; original = faults._write_state
        fault = faults._candidate('module', 'dependencies', 'Missing dependency', 'time')[1]
        def write(target, state):
            nonlocal writes
            writes += 1
            if writes == 2: raise OSError('synthetic full disk after acceptance')
            return original(target, state)
        def accepted(*args, **kwargs): calls.append(1); return True
        with mock.patch.object(faults, '_write_state', side_effect=write):
            with self.assertRaises(OSError): faults.process_faults({'system_notification_emails': 'a@example.com'},
                {'module': fault}, path, sender=accepted, now=self.now)
        self.now += faults.RETRY_SECONDS + 1
        faults.process_faults({'system_notification_emails': 'a@example.com'}, {'module': fault}, path,
            sender=accepted, now=self.now)
        self.assertEqual(calls, [1])

    def test_announcement_backpressure_is_definitive_before_sendmail(self):
        payload = {'recipient_id': 'email_' + 'a'*24, 'address': 'person@example.com',
            'sender': {'name': 'SLS', 'address': 'notify@example.com'}, 'title': 'Test', 'message': 'Message',
            'is_test': False, 'severity': 'info', 'message_id': '<sls-' + 'b'*32 + '-' + 'a'*24 + '@example.com>',
            'created_at': '2026-10-10T12:00:00Z'}
        with (mock.patch.object(announcement._mail_admission, 'reserve', side_effect=announcement._mail_admission.MailAdmissionBlocked('mail_queue_full')),
                mock.patch.object(announcement.subprocess, 'Popen', side_effect=AssertionError('sendmail must not start'))):
            result = announcement.submit(payload, logo_paths=())
        self.assertEqual(result['state'], 'rejected'); self.assertFalse(result['retryable'])
        self.assertFalse(result['submission_started']); self.assertEqual(result['error_code'], 'mail_queue_full')

    def weather_source(self):
        path = self.directory / 'weather-retry.json'
        destinations.queue_external_delivery(path, {}, 'fixture', 'Weather', 'Details', source='nws',
            email_recipients='a@example.com', now=self.now)
        return path

    def retry_weather(self, path, sender):
        with mock.patch.object(destinations.time, 'time', return_value=self.now):
            return destinations.retry_external_deliveries(path, {}, 'nws', live=True, email_sender=sender,
                validity_checker=lambda *_: ('eligible', '', None))

    def test_weather_uncertain_mail_is_terminal_and_does_not_replay(self):
        path = self.weather_source(); calls = []
        def timeout(*args): calls.append(1); raise TimeoutError('synthetic uncertain mail')
        first = self.retry_weather(path, timeout)
        self.assertEqual(first['pending'], 0); self.assertEqual(first['uncertain'], 1)
        self.retry_weather(path, timeout); self.assertEqual(calls, [1])
        self.assertEqual(destinations.external_delivery_status(path, 'nws', 'fixture'), 'uncertain')

    def test_weather_admission_refusal_preserves_valid_retry(self):
        path = self.weather_source()
        def refused(*args): raise branded._mail_admission.MailAdmissionBlocked('mail_queue_full')
        first = self.retry_weather(path, refused)
        self.assertEqual(first['pending'], 1); self.assertEqual(first['uncertain'], 0)
        second = self.retry_weather(path, lambda *_: True)
        self.assertEqual(second['pending'], 0); self.assertEqual(second['uncertain'], 0)

    def test_weather_acceptance_before_failed_receipt_write_never_replays(self):
        path = self.weather_source(); writes = 0; calls = []; original = destinations._write_retry_state
        def write(target, state):
            nonlocal writes
            writes += 1
            if writes == 3: raise destinations.RetryStateError('synthetic failed receipt write')
            return original(target, state)
        def accepted(*args):
            stored = json.loads(path.read_bytes())
            self.assertTrue(next(iter(stored['deliveries'].values()))['email_inflight'])
            calls.append(1); return True
        with mock.patch.object(destinations, '_write_retry_state', side_effect=write):
            with self.assertRaises(destinations.RetryStateError): self.retry_weather(path, accepted)
        recovered = self.retry_weather(path, accepted)
        self.assertEqual(calls, [1]); self.assertEqual(recovered['uncertain'], 1); self.assertEqual(recovered['pending'], 0)


if __name__ == '__main__': unittest.main()
