#!/usr/bin/env python3
"""Actual API HTTP claims with inert delivery in a private network namespace."""
import importlib.util
import json
import os
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
if os.environ.get('SLS_TEST_NAMESPACE') != 'entered' or os.environ.get('SLS_TEST_PARENT_NET_NS') == os.readlink('/proc/self/ns/net'):
    raise SystemExit('Use the isolated fixture runner; no listener was started.')
spec = importlib.util.spec_from_file_location('idempotency_http_helper', ROOT / 'tools/test_api_security.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

class ControlIdempotencyHttpTests(unittest.TestCase):
    def setUp(self):
        self.api = helper.ApiSecurityTests()
        self.api.setUp()
        self.named = self.api.issue(scopes=('read', 'send'), unrestricted=True)
        self.restricted = self.api.issue(scopes=('send',), extensions=['1000'])
        self.control = {'enabled': True, 'rate_limit_enabled': False, 'credentials': [self.named['credential'], self.restricted['credential']]}
        self.api.write_config(control_api=self.control)
        self.api.start_api()
        source = (self.api.directory / 'index.php').read_text()
        source = source.replace("'/var/www/html/admin/modules/slsmassnotifyserver/ControlIdempotency.php'", json.dumps(str(ROOT / 'slsmassnotifyserver/ControlIdempotency.php')))
        source = source.replace("'/var/lib/asterisk/SLS_Mass_Notifications_Plugin/control-idempotency'", json.dumps(str(self.api.directory / 'control-idempotency')))
        (self.api.directory / 'index.php').write_text(source)
        self.payload = {'action': 'send_announcement', 'targets': ['1000'], 'message': 'Private fixture only', 'is_test': True}

    def tearDown(self):
        self.api.tearDown()

    def test_duplicate_conflict_key_isolation_and_missing_history(self):
        headers = {'Idempotency-Key': 'fixture-key-00000001'}
        first = self.api.request(self.named['secret'], self.payload, **headers)
        second = self.api.request(self.named['secret'], self.payload, **headers)
        self.assertEqual(first[0], 200, first)
        self.assertEqual(first, second)
        calls = self.api.directory / 'module-calls.jsonl'
        self.assertEqual(len(calls.read_text().splitlines()), 1)
        changed = self.api.request(self.named['secret'], {**self.payload, 'message': 'Changed'}, **headers)
        self.assertEqual(changed[0], 409, changed)
        self.assertEqual(changed[1]['error'], 'idempotency_conflict')
        self.assertEqual(self.api.request(self.named['secret'], self.payload, **{'Idempotency-Key': 'short'})[0], 400)
        other = self.api.request(self.restricted['secret'], self.payload, **headers)
        self.assertEqual(other[0], 200, other)
        self.assertEqual(len(calls.read_text().splitlines()), 2)
        (self.api.directory / 'control-idempotency/announcement_claims.json').unlink()
        failed = self.api.request(self.named['secret'], self.payload, **headers)
        self.assertEqual(failed[0], 503, failed)
        self.assertFalse(failed[1]['retryable'])
        self.assertEqual(len(calls.read_text().splitlines()), 2)

    def test_revocation_and_restricted_labs_rejected_before_claim(self):
        for field, identifier in [('browser_recipient_ids', 'sub_'+'a'*24), ('connector_ids', 'conn_'+'b'*24)]:
            response = self.api.request(self.restricted['secret'], {**self.payload, field: [identifier]}, **{'Idempotency-Key': 'fixture-key-00000002'})
            self.assertEqual(response[0], 403, response)
        self.assertFalse((self.api.directory / 'module-calls.jsonl').exists())
        self.control['credentials'][0]['revoked_at'] = '2026-10-09T00:00:00Z'
        self.api.write_config(control_api=self.control)
        self.assertEqual(self.api.request(self.named['secret'], self.payload, **{'Idempotency-Key': 'fixture-key-00000002'})[0], 401)
        self.assertFalse((self.api.directory / 'control-idempotency').exists())

if __name__ == '__main__':
    unittest.main()
