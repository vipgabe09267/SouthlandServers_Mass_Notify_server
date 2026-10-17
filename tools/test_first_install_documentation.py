#!/usr/bin/env python3
"""Execute the documented stock verifier against a private ephemeral publisher."""
import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENVIRONMENT = {
    'PATH': '/usr/bin:/bin',
    'LANG': 'C',
    'LC_ALL': 'C',
    'PYTHONOPTIMIZE': '1',
}


class FirstInstallDocumentationTests(unittest.TestCase):
    def test_signed_schema_and_rejection_with_optimized_python(self):
        document_path = ROOT / 'docs/FIRST_INSTALL_VERIFICATION.md'
        document_info = document_path.lstat()
        self.assertTrue(stat.S_ISREG(document_info.st_mode) and document_info.st_nlink == 1
                        and document_info.st_size <= 64 * 1024, 'Documentation source is unsafe.')
        document_bytes = document_path.read_bytes()
        self.assertEqual(len(document_bytes), document_info.st_size)
        self.assertEqual((ROOT / 'slsmassnotifyserver/docs/FIRST_INSTALL_VERIFICATION.md').read_bytes(),
                         document_bytes, 'Packaged first-install guide diverged from the repository guide.')
        document = document_bytes.decode('utf-8')
        matches = re.findall(r"python3 -I - .*?<<'PY'\n(.*?)\nPY", document, re.S)
        self.assertEqual(len(matches), 1, 'Documented verifier block is missing or ambiguous.')
        code = matches[0]
        with tempfile.TemporaryDirectory(prefix='sls-bootstrap-document-') as temporary:
            directory = Path(temporary)

            def write_text(name, contents):
                self.assertEqual((directory / name).write_text(contents, encoding='utf-8'), len(contents))

            def write_bytes(name, contents):
                self.assertEqual((directory / name).write_bytes(contents), len(contents))

            def openssl(*args):
                return subprocess.run(
                    ['/usr/bin/openssl', *args],
                    cwd=directory,
                    capture_output=True,
                    timeout=10,
                    env=ENVIRONMENT,
                )

            self.assertEqual(openssl('genpkey', '-algorithm', 'ED25519', '-out', 'key').returncode, 0)
            self.assertEqual(openssl('pkey', '-in', 'key', '-pubout', '-out', 'publisher.pub').returncode, 0)
            public_der = openssl('pkey', '-in', 'key', '-pubout', '-outform', 'DER')
            self.assertEqual(public_der.returncode, 0, public_der.stderr)
            der = public_der.stdout
            write_bytes('sls-install.sh', b'fixture installer, never executed')
            valid = {'schema': 1, 'version': '0.1.7-beta', 'tag': 'slsmassnotifyserver-0.1.7-beta',
                     'package': 'slsmassnotifyserver-0.1.7-beta.tgz',
                     'package_sha256': '1' * 64,
                     'installer_sha256': hashlib.sha256((directory / 'sls-install.sh').read_bytes()).hexdigest(),
                     'signing': {'key_id': hashlib.sha256(der).hexdigest(), 'issued_at': int(time.time()) - 60, 'expires_at': int(time.time()) + 3600}}
            diagnostics = {
                'hash': 'Installer hash mismatch', 'package_hash': 'Missing package hash',
                'expiry': 'Release validity failed', 'key': 'Publisher key mismatch',
                'version': 'Release version mismatch', 'schema': 'Unsupported release schema',
                'legacy_dates': 'Release manifest fields are incomplete or unsupported',
                'unknown': 'Release manifest fields are incomplete or unsupported',
            }
            for field in (None, *diagnostics):
                data = json.loads(json.dumps(valid))
                if field == 'hash': data['installer_sha256'] = '0' * 64
                elif field == 'package_hash': data['package_sha256'] = 'invalid'
                elif field == 'expiry': data['signing']['expires_at'] = 1
                elif field == 'key': data['signing']['key_id'] = '0' * 64
                elif field == 'version': data['version'] = '0.1.6-beta'
                elif field == 'schema': data['schema'] = True
                elif field == 'legacy_dates': data.update(data.pop('signing'))
                elif field == 'unknown': data['unexpected'] = True
                write_text('release-manifest.json', json.dumps(data))
                signed = openssl('pkeyutl', '-sign', '-rawin', '-inkey', 'key', '-in', 'release-manifest.json', '-out', 'release-manifest.sig')
                self.assertEqual(signed.returncode, 0, signed.stderr)
                verified = openssl('pkeyutl', '-verify', '-pubin', '-inkey', 'publisher.pub', '-rawin', '-in', 'release-manifest.json', '-sigfile', 'release-manifest.sig')
                self.assertEqual(verified.returncode, 0, verified.stderr)
                result = subprocess.run(
                    [sys.executable, '-I', '-O', '-', '0.1.7-beta'],
                    cwd=directory,
                    input=code,
                    text=True,
                    capture_output=True,
                    timeout=10,
                    env=ENVIRONMENT,
                )
                self.assertEqual(result.returncode == 0, field is None, (field, result.stdout, result.stderr))
                if field is not None:
                    self.assertIn(diagnostics[field], result.stderr)
            malformed = {
                '{"schema":1,"schema":1}': 'Release manifest contains duplicate members',
                json.dumps(valid).replace('"issued_at": ', '"issued_at": NaN, "old_issued_at": ', 1):
                    'Release manifest contains a non-finite number',
            }
            for raw, diagnostic in malformed.items():
                write_text('release-manifest.json', raw)
                result = subprocess.run(
                    [sys.executable, '-I', '-O', '-', '0.1.7-beta'], cwd=directory, input=code,
                    text=True, capture_output=True, timeout=10, env=ENVIRONMENT,
                )
                self.assertNotEqual(result.returncode, 0, raw)
                self.assertIn(diagnostic, result.stderr)
            write_text('release-manifest.json', json.dumps(valid))
            signed = openssl('pkeyutl', '-sign', '-rawin', '-inkey', 'key', '-in', 'release-manifest.json', '-out', 'release-manifest.sig')
            self.assertEqual(signed.returncode, 0, signed.stderr)
            verified = openssl('pkeyutl', '-verify', '-pubin', '-inkey', 'publisher.pub', '-rawin', '-in', 'release-manifest.json', '-sigfile', 'release-manifest.sig')
            self.assertEqual(verified.returncode, 0, verified.stderr)
            with (directory / 'release-manifest.json').open('a', encoding='utf-8') as stream:
                self.assertEqual(stream.write(' '), 1)
            tampered = openssl('pkeyutl', '-verify', '-pubin', '-inkey', 'publisher.pub', '-rawin', '-in', 'release-manifest.json', '-sigfile', 'release-manifest.sig')
            self.assertNotEqual(tampered.returncode, 0)


if __name__ == '__main__':
    unittest.main()
