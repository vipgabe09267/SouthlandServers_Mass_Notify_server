#!/usr/bin/env python3
"""Exercise the bootstrap downloader with mocked HTTP and fixture-only files."""
import hashlib
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / 'tools/install_release.sh'
SOURCE = INSTALLER.read_text()
VERSION = ET.parse(ROOT / 'slsmassnotifyserver/module.xml').getroot().findtext('version')
DOWNLOAD = SOURCE.split('fetch_release_asset() {', 1)[1].split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]
URL = ('https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/'
       f'slsmassnotifyserver-{VERSION}/slsmassnotifyserver-{VERSION}.tgz')


class Response(io.BytesIO):
    def __init__(self, body=b'package', headers=None):
        super().__init__(body)
        self.headers = headers or {}


class DownloadTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-download-fixture-')
        self.directory = Path(self.temporary.name)
        self.output = self.directory / 'asset'
        self.namespace = {}

    def tearDown(self):
        self.temporary.cleanup()

    def run_download(self, *, url=URL, limit=64, token='fixture-token', response=None, effects=None):
        with patch.dict(os.environ, {
            'SLS_ASSET_URL': url, 'SLS_ASSET_OUTPUT': str(self.output),
            'SLS_ASSET_LIMIT': str(limit), 'SLS_ASSET_TOKEN': token,
        }), patch('urllib.request.build_opener') as factory, patch('time.sleep'):
            opener = factory.return_value
            if effects is not None:
                opener.open.side_effect = effects
            else:
                opener.open.return_value = response or Response()
            try:
                exec(compile(DOWNLOAD, str(INSTALLER), 'exec'), self.namespace)
            except SystemExit:
                self.last_factory = factory
                raise
            return factory

    def test_invalid_urls_are_rejected_before_network_or_file_creation(self):
        for url in (
            URL.replace('https:', 'http:'), URL.replace('github.com', 'example.com'),
            URL.replace('github.com', 'github.com.example.com'),
            URL.replace('github.com', 'github.com@evil.example'),
            URL.replace('github.com', 'github.com:8443'),
            URL + '?token=hidden', URL + '#fragment', URL + '\n',
            'file:///etc/passwd', URL.replace(f'{VERSION}.tgz', '0.0.1-beta.tgz'),
        ):
            with self.subTest(url=url), self.assertRaises(SystemExit):
                self.run_download(url=url)
            self.last_factory.assert_not_called()
            self.assertFalse(self.output.exists())

    def test_only_initial_request_has_authorization(self):
        factory = self.run_download()
        request = factory.return_value.open.call_args.args[0]
        self.assertEqual(request.get_header('Authorization'), 'Bearer fixture-token')
        handler = factory.call_args.args[0]
        target = 'https://release-assets.githubusercontent.com/github-production-release-asset/123?sig=secret'
        redirected = handler.redirect_request(request, None, 302, 'Found', {}, target)
        self.assertIsNone(redirected.get_header('Authorization'))
        back = handler.redirect_request(redirected, None, 302, 'Found', {}, URL)
        self.assertIsNone(back.get_header('Authorization'))
        self.assertEqual(self.output.read_bytes(), b'package')
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)

    def test_redirect_rejects_insecure_or_unapproved_host(self):
        factory = self.run_download()
        request = factory.return_value.open.call_args.args[0]
        handler = factory.call_args.args[0]
        for target in ('http://github.com/asset', 'https://example.com/asset',
                       'https://github.com:444/asset', 'https://user@github.com/asset',
                       'https://release-assets.githubusercontent.com.evil.example/asset'):
            with self.subTest(target=target), self.assertRaises(ValueError):
                handler.redirect_request(request, None, 302, 'Found', {}, target)

    def test_size_limit_with_and_without_content_length(self):
        for headers in ({'Content-Length': '100'}, {}):
            with self.subTest(headers=headers), self.assertRaises(SystemExit):
                self.run_download(limit=8, response=Response(b'0123456789', headers))
            self.assertLessEqual(self.output.stat().st_size, 8)
            self.output.unlink()

    def test_truncated_response_is_rejected(self):
        with self.assertRaises(SystemExit):
            self.run_download(response=Response(b'short', {'Content-Length': '30'}))

    def test_retry_does_not_append_partial_download(self):
        class BrokenResponse(Response):
            def read(self, count=-1):
                if self.tell():
                    raise urllib.error.URLError('interrupted')
                return super().read(3)
        self.run_download(effects=[BrokenResponse(), Response(b'complete')])
        self.assertEqual(self.output.read_bytes(), b'complete')

    def test_existing_output_and_links_are_never_truncated(self):
        sentinel = self.directory / 'sentinel'
        sentinel.write_text('preserve')
        self.output.symlink_to(sentinel)
        with self.assertRaises(SystemExit):
            self.run_download()
        self.assertEqual(sentinel.read_text(), 'preserve')
        self.last_factory.return_value.open.assert_not_called()

    def test_header_injection_is_rejected_before_network(self):
        with self.assertRaises(SystemExit):
            self.run_download(token='secret\r\nInjected: header')
        self.last_factory.assert_not_called()

    def test_network_errors_do_not_echo_signed_url_or_credentials(self):
        with self.assertRaises(SystemExit) as error:
            self.run_download(effects=urllib.error.URLError('https://host/path?token=do-not-print'))
        self.assertNotIn('do-not-print', str(error.exception))
        self.assertNotIn('fixture-token', str(error.exception))

    def test_download_uses_private_directory_and_preserves_supplied_path(self):
        sentinel = self.directory / 'provided.tgz'
        sentinel.write_text('preserve')
        script = r'''
source "$1"
TGZ="$2"
URL="$3"
fetch_release_asset() { printf 'fixture' >"$2"; }
download_tgz
[[ "$TGZ" == "$DOWNLOAD_DIR/package.tgz" ]]
[[ "$(stat -c '%a' "$DOWNLOAD_DIR")" == 700 ]]
[[ "$(cat "$2")" == preserve ]]
rm -rf -- "$DOWNLOAD_DIR"
'''
        result = subprocess.run(['bash', '-c', script, '_', str(INSTALLER), str(sentinel), URL],
                                capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_offline_archive_requires_correct_explicit_digest(self):
        archive = self.directory / 'offline.tgz'
        xml = f'<module><rawname>slsmassnotifyserver</rawname><version>{VERSION}</version></module>'.encode()
        with tarfile.open(archive, 'w:gz') as handle:
            info = tarfile.TarInfo('slsmassnotifyserver/module.xml')
            info.size = len(xml)
            handle.addfile(info, io.BytesIO(xml))
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        script = 'source "$1"; URL=""; TGZ="$2"; SHA256="$3"; verify_publisher_release() { return 0; }; verify_tgz'
        for supplied, accepted in ((digest, True), ('', False), ('0' * 64, False)):
            result = subprocess.run(['bash', '-c', script, '_', str(INSTALLER), str(archive), supplied],
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode == 0, accepted, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
