#!/usr/bin/env python3
"""Exercise the bootstrap downloader with mocked HTTP and fixture-only files."""
import hashlib
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import time
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
        for supplied, accepted in (
            (digest, True), (digest.upper(), True), ('', False), ('0' * 64, False),
            ('xyz', False), (digest + '\n' + '0' * 64, False),
        ):
            result = subprocess.run(['bash', '-c', script, '_', str(INSTALLER), str(archive), supplied],
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode == 0, accepted, result.stdout + result.stderr)

    def test_current_release_basename_keeps_pinned_digest_after_private_copy(self):
        archive = self.directory / f'slsmassnotifyserver-{VERSION}.tgz'
        archive.write_bytes(b'not-the-published-package')
        script = r'''
source "$1"
TGZ="$2"
URL="https://github.com/example/project/releases/download/slsmassnotifyserver-0.1.7-beta/slsmassnotifyserver-0.1.7-beta.tgz"
SHA256=""
fetch_release_asset() { printf 'not-the-published-package' >"$2"; }
verify_publisher_release() { return 0; }
download_tgz
verify_tgz
'''
        result = subprocess.run(
            ['bash', '-c', script, '_', str(INSTALLER), str(archive)],
            capture_output=True,
            text=True,
            timeout=15,
        )
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('does not match the current', result.stdout + result.stderr)

    def test_archive_rejects_ambiguous_paths_unsafe_modes_and_special_members(self):
        xml = f'<module><rawname>slsmassnotifyserver</rawname><version>{VERSION}</version></module>'.encode()
        regular = ('slsmassnotifyserver/module.xml', 0o644, xml, tarfile.REGTYPE)
        for label, entries in (
            ('duplicate', [regular, regular]),
            ('dot-alias', [regular, ('slsmassnotifyserver/./module.xml', 0o644, xml, tarfile.REGTYPE)]),
            ('empty-component-alias', [regular, ('slsmassnotifyserver//module.xml', 0o644, xml, tarfile.REGTYPE)]),
            ('group-writable', [('slsmassnotifyserver/module.xml', 0o664, xml, tarfile.REGTYPE)]),
            ('sticky', [('slsmassnotifyserver/module.xml', 0o1644, xml, tarfile.REGTYPE)]),
            ('fifo', [regular, ('slsmassnotifyserver/runtime.pipe', 0o600, b'', tarfile.FIFOTYPE)]),
        ):
            archive = self.directory / f'{label}.tgz'
            with tarfile.open(archive, 'w:gz') as handle:
                for name, mode, body, member_type in entries:
                    info = tarfile.TarInfo(name)
                    info.mode = mode
                    info.type = member_type
                    info.size = len(body)
                    handle.addfile(info, io.BytesIO(body))
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            script = 'source "$1"; URL=""; TGZ="$2"; SHA256="$3"; verify_publisher_release() { return 0; }; verify_tgz'
            result = subprocess.run(['bash', '-c', script, '_', str(INSTALLER), str(archive), digest],
                                    capture_output=True, text=True, timeout=15)
            self.assertNotEqual(result.returncode, 0, label)

    def test_archive_rejects_ambiguous_or_unsafe_module_metadata(self):
        valid = f'<module><rawname>slsmassnotifyserver</rawname><version>{VERSION}</version></module>'.encode()
        cases = {
            'duplicate-identity': valid.replace(b'</module>', b'<rawname>slsmassnotifyserver</rawname></module>'),
            'duplicate-version': valid.replace(b'</module>', f'<version>{VERSION}</version></module>'.encode()),
            'entity-declaration': (b'<!DOCTYPE module [<!ENTITY x "slsmassnotifyserver">]>'
                                   + valid.replace(b'slsmassnotifyserver', b'&x;', 1)),
            'oversized': valid + b' ' * (1024 * 1024),
        }
        script = 'source "$1"; URL=""; TGZ="$2"; SHA256="$3"; verify_publisher_release() { return 0; }; verify_tgz'
        for label, body in cases.items():
            archive = self.directory / f'{label}.tgz'
            with tarfile.open(archive, 'w:gz') as handle:
                info = tarfile.TarInfo('slsmassnotifyserver/module.xml')
                info.mode = 0o644; info.size = len(body)
                handle.addfile(info, io.BytesIO(body))
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            result = subprocess.run(['bash', '-c', script, '_', str(INSTALLER), str(archive), digest],
                                    capture_output=True, text=True, timeout=15)
            self.assertNotEqual(result.returncode, 0, (label, result.stdout, result.stderr))

    def test_module_install_output_is_bounded_and_preserves_failure_status(self):
        with tempfile.TemporaryDirectory(prefix='sls-install-output-') as name:
            log = Path(name) / 'install.log'
            script = r'''
source "$1"
LOG_FILE="$2"
run_without_install_maintenance_lock() {
  /usr/bin/python3 -c 'import sys; sys.stdout.write("x" * 262144 + "\nEND-SENTINEL\n"); raise SystemExit(7)'
}
if install_module_with_autoenable; then
  exit 99
else
  status=$?
fi
printf '%s\n' "$status"
'''
            result = subprocess.run(
                ['bash', '-c', script, '_', str(INSTALLER), str(log)],
                capture_output=True, text=True, timeout=15,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, '7\n')
            body = log.read_bytes()
            self.assertLessEqual(len(body), 131073)
            self.assertTrue(body.endswith(b'END-SENTINEL\n'))

    def test_legacy_module_install_retry_is_also_bounded(self):
        with tempfile.TemporaryDirectory(prefix='sls-install-retry-output-') as name:
            log = Path(name) / 'install.log'
            calls = Path(name) / 'calls'
            script = r'''
source "$1"
LOG_FILE="$2"
calls="$3"
run_without_install_maintenance_lock() {
  count=0
  [[ ! -f "$calls" ]] || count="$(cat "$calls")"
  count=$((count + 1)); printf '%s' "$count" >"$calls"
  if [[ "$count" -eq 1 ]]; then
    /usr/bin/python3 -c 'import sys; sys.stdout.write("x" * 262144 + "\nunknown option autoenable\n"); raise SystemExit(2)'
  else
    /usr/bin/python3 -c 'import sys; sys.stdout.write("y" * 262144 + "\nRETRY-SENTINEL\n"); raise SystemExit(9)'
  fi
}
if install_module_with_autoenable; then
  exit 99
else
  status=$?
fi
printf '%s\n' "$status"
'''
            result = subprocess.run(
                ['bash', '-c', script, '_', str(INSTALLER), str(log), str(calls)],
                capture_output=True, text=True, timeout=15,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(result.stdout.endswith('9\n'), result.stdout)
            self.assertIn('requires global options before the module action', result.stdout)
            self.assertEqual(calls.read_text(), '2')
            body = log.read_bytes()
            self.assertLessEqual(len(body), 262146)
            self.assertTrue(body.endswith(b'RETRY-SENTINEL\n'))

    def test_executed_installer_rejects_ambiguous_or_unknown_arguments(self):
        cases = (
            (['--unknown'], {}),
            (['https://github.com/one', 'https://github.com/two'], {}),
            (['--recover', '/var/lib/sls-mass-notify-installer/transaction-' + 'a' * 24, '--inspect'], {}),
            (['https://github.com/positional'], {'SLS_MASS_NOTIFY_TGZ_URL': 'https://github.com/environment'}),
        )
        for arguments, extra_environment in cases:
            environment = os.environ.copy()
            environment.update(extra_environment)
            result = subprocess.run(['bash', str(INSTALLER), *arguments], env=environment,
                                    capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 2, (arguments, result.stdout, result.stderr))
        # Helper fixtures and administrators may source the library in a shell
        # that already has unrelated positional arguments; sourcing must not
        # seize or reject those arguments.
        result = subprocess.run(
            ['bash', '-c', 'source "$1"; printf sourced', '_', str(INSTALLER), 'unrelated', 'arguments'],
            capture_output=True, text=True, timeout=15,
        )
        self.assertEqual((result.returncode, result.stdout), (0, 'sourced'), result.stderr)

    def test_logged_commands_stream_output_and_preserve_failure_status(self):
        script = r'''
source "$1"
INSTALL_LOG_FD=""
if run_logged bash -c 'printf "first\n"; sleep 1; printf "second\n"; exit 7'; then
  exit 99
else
  exit $?
fi
'''
        started = time.monotonic()
        process = subprocess.Popen(
            ['bash', '-c', script, '_', str(INSTALLER)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        self.assertEqual(process.stdout.readline(), 'first\n')
        self.assertLess(time.monotonic() - started, 0.8, 'child output was buffered until process exit')
        stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 7, stderr)
        self.assertEqual(stdout, 'second\n')


if __name__ == '__main__':
    unittest.main()
