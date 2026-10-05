#!/usr/bin/env python3
"""Exercise the shipped policy and feed parser with a synthetic GitHub feed."""
from contextlib import redirect_stdout
from datetime import datetime
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import ssl
import urllib.error
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_update_policy.py'
spec = importlib.util.spec_from_file_location('sls_update_policy', HELPER)
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)
REPO = 'vipgabe09267/SouthlandServers_Mass_Notify_server'


def release(version='1.2.3', **values):
    tag = 'slsmassnotifyserver-' + version
    result = {'tag_name': tag, 'draft': False, 'prerelease': version.endswith('-beta'),
              'published_at': '2026-01-01T00:00:00Z', 'assets': [{
                  'name': tag + '.tgz', 'digest': 'sha256:' + 'a' * 64,
                  'browser_download_url': f'https://github.com/{REPO}/releases/download/{tag}/{tag}.tgz'}]}
    result.update(values)
    return result


class UpdatePolicy(unittest.TestCase):
    def test_root_feed_parser_does_not_create_private_runtime_bytecode(self):
        source = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_update.sh').read_text()
        code = source.split('release_json="$(' , 1)[1].split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]
        with tempfile.TemporaryDirectory(prefix='sls-updater-cache-') as name:
            helper = Path(name) / 'sls_update_policy.py'
            helper.write_bytes(HELPER.read_bytes())
            code = code.replace('/usr/local/bin/sls_mass_notify/sls_update_policy.py', str(helper))
            prefix = ('import io,json,os,urllib.request\nos.umask(0o027)\n'
                      'urllib.request.urlopen=lambda request,**kwargs: io.BytesIO(json.dumps('
                      + repr([release()]) + " if '/releases?' in request.full_url else {'sha':'b'*40}).encode())\n")
            env = {'REPOSITORY': REPO, 'CURRENT_VERSION': '1.0.0', 'GITHUB_UPDATES_CHANNEL': 'beta',
                   'GITHUB_UPDATES_PIN': '', 'GITHUB_UPDATES_WINDOW_START': '', 'GITHUB_UPDATES_WINDOW_END': '',
                   'GITHUB_UPDATES_DELAY_HOURS': '0'}
            result = subprocess.run(['/usr/bin/python3', '-I', '-c', prefix + code], env=env,
                                    text=True, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(json.loads(result.stdout)['ok'], result.stdout)
            self.assertFalse((Path(name) / '__pycache__').exists(), 'Root updater created private runtime bytecode')

    def test_invalid_settings_fail_closed(self):
        for value in [{'channel': None}, {'channel': 'nightly'}, {'pinned_version': '../x'},
                      {'pinned_version': '1.2.3\n'}, {'channel': 'stable', 'pinned_version': '1.0.0-beta'},
                      {'window_start': '25:00', 'window_end': '03:00'}, {'window_start': '02:00'},
                      {'window_start': '02:00', 'window_end': '02:00'},
                      {'window_start': '01:45', 'window_end': '02:15'},
                      *[{'rollout_delay_hours': x} for x in (-1, 169, '1', True, 1.5)]]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                policy.validate(value)

    def test_channels_pins_and_malformed_release(self):
        stable = policy.validate({'channel': 'stable'})
        self.assertTrue(policy.release_allowed(release(), stable))
        for row in [release('1.2.4-beta'), release(prerelease=True), release(draft=True),
                    release(draft='false'), release(prerelease=None), release(tag_name='../1.2.3')]:
            self.assertFalse(policy.release_allowed(row, stable))
        pinned = policy.validate({'pinned_version': '1.2.3-beta'})
        self.assertTrue(policy.release_allowed(release('1.2.3-beta'), pinned))
        self.assertFalse(policy.release_allowed(release('1.2.4-beta'), pinned))

    def test_absolute_delay_overnight_window_and_local_time(self):
        p = policy.validate({'window_start': '22:00', 'window_end': '03:00', 'rollout_delay_hours': 24})
        r = release(published_at='2026-01-01T06:00:00Z')
        for now, expected in [('2026-01-01T23:59:59-06:00', False),
                              ('2026-01-02T00:00:00-06:00', True),
                              ('2026-01-02T03:00:00-06:00', False),
                              ('2026-01-02T21:59:59-06:00', False),
                              ('2026-01-02T22:00:00-06:00', True)]:
            self.assertIs(policy.automatic_gate(r, p, datetime.fromisoformat(now))['automatic_eligible'], expected)
        for timestamp in ('invalid', None, '2026-01-01T00:00:00', '2099-01-01T00:00:00Z'):
            self.assertFalse(policy.automatic_gate(release(published_at=timestamp), p)['automatic_eligible'])

    def feed(self, rows, **overrides):
        source = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_update.sh').read_text()
        code = source.split('release_json="$(', 1)[1].split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]
        code = code.replace('/usr/local/bin/sls_mass_notify/sls_update_policy.py', str(HELPER))
        env = {'REPOSITORY': REPO, 'CURRENT_VERSION': '1.0.0', 'GITHUB_UPDATES_CHANNEL': 'beta',
               'GITHUB_UPDATES_PIN': '', 'GITHUB_UPDATES_WINDOW_START': '', 'GITHUB_UPDATES_WINDOW_END': '',
               'GITHUB_UPDATES_DELAY_HOURS': '0', **overrides}
        def response(request, **kwargs):
            if isinstance(rows, BaseException):
                raise rows
            if request.full_url == f'https://api.github.com/repos/{REPO}/releases?per_page=100':
                return io.BytesIO(rows if isinstance(rows, bytes) else json.dumps(rows).encode())
            if request.full_url.startswith(f'https://api.github.com/repos/{REPO}/commits/slsmassnotifyserver-'):
                return io.BytesIO(json.dumps({'sha': 'b' * 40}).encode())
            raise AssertionError('Unexpected network destination: ' + request.full_url)
        output = io.StringIO()
        with patch.dict(os.environ, env, clear=True), patch('urllib.request.urlopen', response), redirect_stdout(output):
            try:
                exec(compile(code, '<shipped release feed parser>', 'exec'), {})
            except SystemExit as error:
                self.assertEqual(error.code, 0)
        return json.loads(output.getvalue())

    def test_actual_feed_applies_channels_pin_and_never_downgrades(self):
        rows = [release('1.2.3'), release('1.3.0-beta')]
        self.assertEqual(self.feed(rows)['latest_version'], '1.3.0-beta')
        self.assertEqual(self.feed(rows, GITHUB_UPDATES_CHANNEL='stable')['latest_version'], '1.2.3')
        self.assertEqual(self.feed(rows, GITHUB_UPDATES_PIN='1.2.3')['latest_version'], '1.2.3')
        result = self.feed(rows, GITHUB_UPDATES_CHANNEL='stable', GITHUB_UPDATES_PIN='1.2.3', CURRENT_VERSION='2.0.0')
        self.assertTrue(result['ok'])
        self.assertFalse(result['update_available'])
        self.assertEqual(result['installer_url'], '')

    def test_missing_channel_or_pin_is_a_hold_and_bad_metadata_is_failure(self):
        for params in ({'GITHUB_UPDATES_CHANNEL': 'stable'}, {'GITHUB_UPDATES_PIN': '1.0.1'}):
            result = self.feed([release('1.2.3-beta')], **params)
            self.assertTrue(result['ok'])
            self.assertFalse(result['update_available'])
        bad = release(); bad['assets'][0]['browser_download_url'] = 'https://example.invalid/package.tgz'
        for rows in ([bad], [release(assets=[{'name': 'wrong'}])], {'message': 'bad response'}, b' ' * 2097153):
            result = self.feed(rows)
            self.assertFalse(result['ok'])
            self.assertFalse(result['update_available'])
        self.assertFalse(self.feed([release()], GITHUB_UPDATES_DELAY_HOURS='1.0')['ok'])

    def test_feed_failures_have_specific_safe_categories(self):
        for error, expected in ((urllib.error.HTTPError('https://api.github.com/',429,'private',{},None), 'release_rate_limited'),
                                (urllib.error.HTTPError('https://api.github.com/',403,'private',{'X-RateLimit-Remaining':'0'},None), 'release_rate_limited'),
                                (urllib.error.URLError(ssl.SSLCertVerificationError('private')), 'release_tls_failed'),
                                (urllib.error.URLError('private-dns-error'), 'release_network_failed'),
                                (b'<html>private-proxy-error</html>', 'release_feed_invalid')):
            with self.subTest(expected=expected):
                result = self.feed(error)
                self.assertFalse(result['ok'])
                self.assertEqual(result['error_category'], expected)
                self.assertNotIn('private', json.dumps(result))


if __name__ == '__main__':
    unittest.main()
