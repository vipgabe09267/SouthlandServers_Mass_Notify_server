#!/usr/bin/env python3
"""Isolated GPIO/HMAC/TLS/DTMF simulations; no ports, hardware or live providers."""
import hashlib
import hmac
import importlib.util
import json
from pathlib import Path
import ssl
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'))

def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value

agi_module = module('sls_incident_agi_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_phone_agi.py')
sensor = module('sls_sensor_fixture', ROOT / 'slsmassnotifyserver/examples/sensors/raspberry_pi_sensor.py')

class FakeAgi:
    def __init__(self, digit='49'):
        self.environment = {'agi_uniqueid': 'fixture.1', 'agi_channel': 'PJSIP/fixture-1'}
        self.values = {'${SLS_PHONE_TOKEN}': 'a' * 32, '${SLS_PHONE_RECIPIENT}': 'voice_' + 'a' * 24, '${READSTATUS}': 'OK', '${SLS_ACK}': '1'}
        self.commands = []
        self.sets = []
        self.digit = digit
    def variable(self, name): return self.values.get(name, '')
    def set(self, name, value): self.sets.append((name, value))
    def command(self, text, timeout=2):
        self.commands.append((text, timeout))
        return '200 result=' + self.digit
    def quote(self, value): return agi_module.PhoneAgi.quote(value)

class FakeStore:
    directory = '/fixture-only'
    def __init__(self, answered=True, completed=True, ack=False):
        self.answer = answered
        self.target = {'outbound': {'id': 'voice_' + 'a' * 24, 'acknowledgement_required': ack,
                       'incident_response': {'binding_id': 'a' * 64, 'token': 'A' * 12, 'incident_id': 'inc_' + 'b' * 32,
                       'person_id': 'person_a', 'recipient_id': 'voice_' + 'a' * 24}}, 'playback': {'completed_at': 1000} if completed else {}}
    def outbound_target(self, token, recipient): return self.target
    def outbound_answered(self, token, recipient, channel): return self.answer

class ProtocolTests(unittest.TestCase):
    def run_agi(self, agi, store, enabled=True):
        calls = []
        def submit(command, **options):
            calls.append(json.loads(options['input']))
            return subprocess.CompletedProcess(command, 0, '{"ok":true,"state":"ignored"}', '')
        settings = {'enterprise_integrations': {'enabled': '1' if enabled else '0', 'responses': {'enabled': '1'}}}
        with mock.patch.object(agi_module, 'read_settings', return_value=settings), mock.patch.object(agi_module, 'check_ack_prompt'), mock.patch.object(agi_module.subprocess, 'run', side_effect=submit):
            agi_module.handle('incident-response', agi, store)
        return calls
    def test_disabled_normal_call_has_no_prompt_or_helper(self):
        agi = FakeAgi()
        self.assertEqual(self.run_agi(agi, FakeStore(), False), [])
        self.assertEqual(agi.commands, [])
        self.assertIn(('SLS_PHONE_ALLOW', '1'), agi.sets)
    def test_ordinary_call_without_binding_needs_no_enterprise_config(self):
        agi, store = FakeAgi(), FakeStore()
        del store.target['outbound']['incident_response']
        with mock.patch.object(agi_module, 'read_settings', side_effect=AssertionError('Ordinary audio read Labs configuration')), mock.patch.object(agi_module.subprocess, 'run', side_effect=AssertionError('Ordinary audio called response helper')):
            self.assertTrue(agi_module.handle('incident-response', agi, store))
        self.assertEqual(agi.commands, [])
        self.assertIn(('SLS_PHONE_ALLOW', '1'), agi.sets)
    def test_bound_completed_call_requires_explicit_key_one(self):
        agi = FakeAgi()
        calls = self.run_agi(agi, FakeStore())
        self.assertEqual([call['digit'] for call in calls], ['', '1'])
        self.assertTrue(agi.commands[0][0].startswith('GET OPTION '))
        self.assertIn('"1" 10000', agi.commands[0][0])
        self.assertEqual(calls[1]['event_id'], 'pbx_fixture.1')
    def test_timeout_or_other_digit_records_no_human_response(self):
        for digit in ('0', '-1', '50'):
            self.assertEqual([call['digit'] for call in self.run_agi(FakeAgi(digit), FakeStore())], [''])
    def test_existing_ack_is_reused_without_second_prompt(self):
        agi = FakeAgi()
        calls = self.run_agi(agi, FakeStore(ack=True))
        self.assertEqual(agi.commands, [])
        self.assertEqual([call['digit'] for call in calls], ['', '1'])
    def test_answer_without_completed_audio_is_rejected(self):
        for answered, completed in ((False, True), (True, False)):
            with self.assertRaises(agi_module.PhoneAdmissionError): self.run_agi(FakeAgi(), FakeStore(answered, completed))
    def test_binding_cannot_target_another_saved_number(self):
        store = FakeStore()
        store.target['outbound']['incident_response']['recipient_id'] = 'voice_' + 'c' * 24
        with self.assertRaises(agi_module.PhoneAdmissionError): self.run_agi(FakeAgi(), store)
    def test_exact_hmac_and_debounce_baseline(self):
        body = {'operation': 'heartbeat', 'request_id': 'a' * 32, 'sent_at': 1700000000, 'expires_at': 1700000300, 'is_test': True}
        rule = 'trg_' + 'b' * 24
        secret = 'c' * 64
        raw, headers = sensor.signed_request(rule, secret, body, 1700000000)
        self.assertEqual(headers['X-SLS-Signature'], hmac.new(secret.encode(), rule.encode() + b'.1700000000.' + raw, hashlib.sha256).hexdigest())
        self.assertNotEqual(headers['X-SLS-Signature'], hmac.new(bytes.fromhex(secret), rule.encode() + b'.1700000000.' + raw, hashlib.sha256).hexdigest())
        debounce = sensor.DebouncedContact()
        self.assertFalse(debounce.sample(True, 0))
        self.assertFalse(debounce.sample(True, 0.1))
        self.assertFalse(debounce.sample(False, 0.2))
        self.assertFalse(debounce.sample(False, 0.3))
        self.assertFalse(debounce.sample(True, 0.4))
        self.assertTrue(debounce.sample(True, 0.5))
        self.assertFalse(debounce.sample(True, 1))
    def test_pending_sensor_retry_retains_identity_and_expiry(self):
        class Opener:
            requests = []
            def open(self, request, timeout):
                self.requests.append(request)
                raise TimeoutError('fixture timeout')
        config = {'enabled': True, 'url': 'https://pbx.example.org/api/sls-mass-notify/trigger.php?rule_id=trg_' + 'b' * 24,
                  'rule_id': 'trg_' + 'b' * 24, 'secret': 'c' * 64, 'event': 'contact', 'is_test': True}
        with tempfile.TemporaryDirectory(prefix='sls-sensor-fixture-') as directory:
            path = Path(directory) / 'state.json'
            opener = Opener()
            client = sensor.SensorClient(config, path, True, opener)
            with self.assertRaises(TimeoutError): client.tick(True, 1700000000)
            pending = dict(client.state['pending'])
            client = sensor.SensorClient(config, path, True, opener)
            with self.assertRaises(TimeoutError): client.tick(False, 1700000001)
            self.assertEqual(client.state['pending'], pending)
            self.assertEqual(opener.requests[0].data, opener.requests[1].data)
            self.assertNotEqual(opener.requests[0].get_header('X-sls-signature'), opener.requests[1].get_header('X-sls-signature'))
            with self.assertRaisesRegex(ValueError, 'expired'): client.tick(False, 1700000300)
            disabled = sensor.SensorClient(dict(config, enabled=False), Path(directory) / 'disabled.json', True, opener)
            before = len(opener.requests)
            disabled.tick(True, 1700000000)
            self.assertEqual(len(opener.requests), before)
    def test_redirects_are_refused(self):
        with self.assertRaisesRegex(ValueError, 'redirects'): sensor.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://elsewhere.example')
    def test_memory_tls_untrusted_wrong_host_and_trusted(self):
        # MemoryBIO handshakes use no sockets/listeners or host configuration.
        with tempfile.TemporaryDirectory(prefix='sls-tls-fixture-') as directory:
            cert, key = Path(directory) / 'cert.pem', Path(directory) / 'key.pem'
            subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=fixture.example',
                            '-addext', 'subjectAltName=DNS:fixture.example', '-keyout', str(key), '-out', str(cert)], check=True, capture_output=True)
            server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            server_context.load_cert_chain(cert, key)
            def handshake(client_context, hostname):
                cin, cout, sin, sout = ssl.MemoryBIO(), ssl.MemoryBIO(), ssl.MemoryBIO(), ssl.MemoryBIO()
                client = client_context.wrap_bio(cin, cout, server_hostname=hostname)
                server = server_context.wrap_bio(sin, sout, server_side=True)
                done = [False, False]
                for _ in range(30):
                    for index, value in enumerate((client, server)):
                        try: value.do_handshake(); done[index] = True
                        except ssl.SSLWantReadError: pass
                    if cout.pending: sin.write(cout.read())
                    if sout.pending: cin.write(sout.read())
                    if all(done): return
                raise RuntimeError('TLS fixture did not converge')
            with self.assertRaises(ssl.SSLCertVerificationError): handshake(ssl.create_default_context(), 'fixture.example')
            trusted = ssl.create_default_context(cafile=str(cert))
            with self.assertRaises(ssl.SSLCertVerificationError): handshake(trusted, 'wrong.example')
            handshake(trusted, 'fixture.example')

if __name__ == '__main__': unittest.main()
