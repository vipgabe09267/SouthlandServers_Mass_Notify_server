#!/usr/bin/env python3
"""Exercise the real SSE endpoint loop against private journals and credentials."""
import fcntl
import json
import os
import selectors
import subprocess
import time
import unittest

from test_desktop_api_delivery import DesktopApiDeliveryTests


class DesktopStreamingTests(DesktopApiDeliveryTests):
    # Inherit fixture setup/helpers, not the unrelated API cases.
    def run_stream(self, duration, events=None):
        script = self.directory / 'stream.php'
        setup = self.authenticated_endpoint_setup('desktop/stream') + """
            $s = json_decode(file_get_contents(SETTINGS_FILE), true);
            $s['public_pbx_host'] = 'pbx.example.com';
            file_put_contents(SETTINGS_FILE, json_encode($s));
            $_SERVER['HTTP_HOST'] = 'pbx.example.com:8443';
        """
        setup += '\n$_GET["stream_seconds"] = ' + str(duration) + ';\n'
        if events:
            (self.directory / 'events.jsonl').write_text(''.join(json.dumps(e) + '\n' for e in events))
        script.write_text(self.prefix + '\n' + setup + '\n$endpoint =' + self.endpoint)
        process = subprocess.Popen(['php', str(script)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(self.close_process, process)
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ)
        self.addCleanup(selector.close)
        return process, selector

    @staticmethod
    def close_process(process):
        if process.poll() is None:
            process.kill()
        process.communicate(timeout=3)

    def read_until(self, process, selector, marker, timeout):
        result = b''
        deadline = time.monotonic() + timeout
        while marker not in result and time.monotonic() < deadline:
            for key, _ in selector.select(max(0, deadline - time.monotonic())):
                data = os.read(key.fileobj.fileno(), 65536)
                if not data:
                    self.fail('Stream ended before expected frame: ' + repr(result[-500:]))
                result += data
        self.assertIn(marker, result)
        return result

    def event(self, event_id, recipient='alice'):
        return {'id': event_id, 'schema_version': 1, 'kind': 'announcement', 'is_test': True,
                'created_at': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
                'title': 'Fixture', 'message': 'Private test only',
                'desktop_enabled': True, 'desktop_all': False, 'desktop_recipients': [recipient],
                'display_timeout_seconds': 0,
                'image_url': 'http://pbx.example.com/sls_mass_notify/announcement_20260920235959_abcdef123456.png'}

    def replace_events(self, events):
        temporary = self.directory / 'next.jsonl'
        temporary.write_text(''.join(json.dumps(e) + '\n' for e in events))
        temporary.replace(self.directory / 'events.jsonl')

    def test_live_baseline_burst_projection_and_normal_slot_cleanup(self):
        old = self.event('before-connection')
        process, selector = self.run_stream(3, [old])
        first = self.read_until(process, selector, b': keepalive ', 2)
        self.assertNotIn(b'event: notification', first)
        self.assertIn(b'"protocol_version":2', first)
        self.assertIn(b':' + b' ' * 8192 + b'\n\n', first)
        self.replace_events([old, self.event('first'), self.event('private', 'bob'), self.event('second')])
        batch = self.read_until(process, selector, b'"id":"second"', 2)
        self.assertIn(b'id: first\nevent: notification', batch)
        self.assertIn(b'id: second\nevent: notification', batch)
        self.assertNotIn(b'"id":"private"', batch)
        self.assertIn(b'https://pbx.example.com:8443/sls_mass_notify/', batch)
        self.assertEqual(batch.count(b'event: notification'), 2)
        remainder, error = process.communicate(timeout=4)
        self.assertEqual(error, b'')
        self.assertEqual(process.returncode, 0)
        self.assertIn(b'event: reconnect', batch + remainder)
        self.assert_slots_reusable()

    def assert_slots_reusable(self):
        slots = list((self.directory / 'stream-slots').glob('*.lock'))
        self.assertGreaterEqual(len(slots), 2)
        for path in slots:
            with path.open('rb') as handle:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                fcntl.flock(handle, fcntl.LOCK_UN)

    def test_regular_heartbeat_is_flushed_before_stream_ends(self):
        process, selector = self.run_stream(20)
        first = self.read_until(process, selector, b': keepalive ', 2)
        began = time.monotonic()
        following = self.read_until(process, selector, b': keepalive ', 17)
        self.assertGreater(time.monotonic() - began, 12)
        self.assertLess(time.monotonic() - began, 17)
        self.assertIsNone(process.poll())
        self.assertIn(b':' + b' ' * 8192 + b'\n\n', following)
        self.assertNotIn(b'event: reconnect', first + following)
        process.kill()
        process.communicate(timeout=3)
        self.assert_slots_reusable()

    def test_credentials_revoked_during_connection(self):
        process, selector = self.run_stream(20)
        self.read_until(process, selector, b': keepalive ', 2)
        path = self.directory / 'settings.json'
        settings = json.loads(path.read_text())
        settings['desktop_clients'][0]['enabled'] = '0'
        path.write_text(json.dumps(settings))
        revoked = self.read_until(process, selector, b'event: revoked', 7)
        rest, error = process.communicate(timeout=3)
        self.assertIn(b'credentials_revoked', revoked + rest)
        self.assertEqual(error, b'')
        self.assertEqual(process.returncode, 0)
        self.assert_slots_reusable()


if __name__ == '__main__':
    # Restrict this suite to the three real stream-loop tests; the base API
    # suite is separately required by the build.
    suite = unittest.TestSuite(DesktopStreamingTests(name) for name in (
        'test_live_baseline_burst_projection_and_normal_slot_cleanup',
        'test_regular_heartbeat_is_flushed_before_stream_ends',
        'test_credentials_revoked_during_connection',
    ))
    raise SystemExit(not unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful())
