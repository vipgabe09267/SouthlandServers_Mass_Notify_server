#!/usr/bin/env python3
"""Real Ed25519 verification fixtures; no network or production signing key."""
import importlib.util
import contextlib
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('manifest_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_verify.py')
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)
docs_spec = importlib.util.spec_from_file_location('release_docs_fixture', ROOT / 'tools/check_release_docs.py')
release_docs = importlib.util.module_from_spec(docs_spec)
docs_spec.loader.exec_module(release_docs)


class ReleaseDocumentationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-release-docs-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for name in ('docs/schemas', 'slsmassnotifyserver/docs/schemas', 'tools'):
            (self.root / name).mkdir(parents=True)
        self.version = '0.1.8-beta'
        (self.root / 'slsmassnotifyserver/module.xml').write_text('<module><version>' + self.version + '</version></module>')
        bootstrap = b'fixture bootstrap bytes'
        (self.root / 'tools/install_bootstrap.sh').write_bytes(bootstrap)
        (self.root / 'README.md').write_text(hashlib.sha256(bootstrap).hexdigest() + '  sls-bootstrap.sh\nbash sls-bootstrap.sh ' + self.version)
        self.record = {'schema': 1, 'published_version': self.version, 'candidate_version': self.version,
                       'candidate_status': 'published', 'purpose': 'Documentation state only.'}
        self.write_state()

    def write_state(self):
        contents = {'release-status.json': json.dumps(self.record),
                    'QUALIFICATION_MATRIX.md': self.version + ' is ' + self.record['candidate_status'] + '.',
                    'CONTROL_API.md': 'The ' + self.record['candidate_status'] + ' ' + self.version + ' API.',
                    'openapi.json': '{}', 'schemas/notification.schema.json': '{}'}
        for name, text in contents.items():
            for prefix in ('docs', 'slsmassnotifyserver/docs'):
                (self.root / prefix / name).write_text(text)
        (self.root / 'SECURITY.md').write_text('Supported prerelease: currently `' + self.record['published_version'] + '`.')

    def check(self):
        with contextlib.redirect_stdout(io.StringIO()):
            release_docs.check(self.root)

    def test_published_and_newer_unpublished_documentation(self):
        self.check()
        self.record.update(candidate_status='unpublished', published_version='0.1.7-beta')
        self.write_state()
        self.check()

    def test_current_repository_documentation(self):
        with contextlib.redirect_stdout(io.StringIO()):
            release_docs.check()

    def test_rejects_mismatched_or_ambiguous_release_states(self):
        invalid = [dict(self.record, schema=True), dict(self.record, extra=True),
                   dict(self.record, purpose=''), dict(self.record, candidate_version='0.1.9-beta'),
                   dict(self.record, candidate_status='unknown'), dict(self.record, candidate_status=False),
                   dict(self.record, candidate_status='unpublished'),
                   dict(self.record, candidate_status='unpublished', published_version='0.1.9-beta'),
                   dict(self.record, published_version='0.1.7-beta'),
                   dict(self.record, published_version='0.1.08-beta'),
                   dict(self.record, published_version='invalid'), []]
        for record in invalid:
            with self.subTest(record=record), self.assertRaises(ValueError):
                release_docs.release_state(record, self.version)

    def test_rejects_stale_published_intros_and_packaged_state(self):
        for name in ('CONTROL_API.md', 'QUALIFICATION_MATRIX.md'):
            with self.subTest(name=name):
                self.write_state()
                (self.root / 'docs' / name).write_text(self.version + ' is unpublished.')
                with self.assertRaisesRegex(ValueError, 'release state'): self.check()
        self.write_state()
        (self.root / 'slsmassnotifyserver/docs/release-status.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'Packaged documentation'): self.check()

    def test_rejects_stale_bootstrap_and_security_version(self):
        (self.root / 'tools/install_bootstrap.sh').write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'bootstrap checksum'): self.check()
        (self.root / 'tools/install_bootstrap.sh').write_bytes(b'fixture bootstrap bytes')
        (self.root / 'SECURITY.md').write_text('currently `0.1.7-beta`')
        with self.assertRaisesRegex(ValueError, 'published version'): self.check()


class ReleaseManifestTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.key, self.public = self.root / 'private', self.root / 'public'
        subprocess.run(['openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(self.key)], check=True, capture_output=True)
        subprocess.run(['openssl', 'pkey', '-in', str(self.key), '-pubout', '-out', str(self.public)], check=True, capture_output=True)
        self.installer, self.package = self.root / 'installer', self.root / 'package'
        self.installer.write_bytes(b'fixture installer')
        self.package.write_bytes(b'fixture archive')
        self.manifest, self.signature = self.root / 'manifest', self.root / 'signature'
        self.data = {'schema': 1, 'version': '0.1.2-beta', 'tag': 'slsmassnotifyserver-0.1.2-beta',
                     'package': 'slsmassnotifyserver-0.1.2-beta.tgz',
                     'installer_sha256': verifier.digest(self.installer), 'package_sha256': verifier.digest(self.package)}
        self.sign()

    def sign(self):
        self.manifest.write_text(json.dumps(self.data))
        subprocess.run(['openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(self.key),
                        '-in', str(self.manifest), '-out', str(self.signature)], check=True, capture_output=True)

    def verify(self):
        return verifier.verify(self.manifest, self.signature, self.installer, self.package, '0.1.2-beta', self.public, trust_directory=self.root/'trust')

    def test_valid(self):
        self.assertEqual(self.verify(), self.data)

    def test_rejects_modified_manifest(self):
        self.manifest.write_text(self.manifest.read_text() + ' ')
        with self.assertRaisesRegex(ValueError, 'signature'):
            self.verify()

    def test_rejects_modified_installer(self):
        self.installer.write_bytes(b'changed installer')
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            self.verify()

    def test_rejects_modified_archive(self):
        self.package.write_bytes(b'changed archive')
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            self.verify()

    def test_rejects_signed_wrong_version(self):
        self.data['version'] = '0.1.1-beta'
        self.sign()
        with self.assertRaisesRegex(ValueError, 'identity'):
            self.verify()

    def test_installer_pins_same_public_key(self):
        key_lines = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/release-signing.pub').read_text().strip().splitlines()
        self.assertIn(key_lines[1], (ROOT / 'tools/install_release.sh').read_text())

    def test_updater_verifies_before_execution_and_reuses_authenticated_bytes(self):
        source = (ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_update.sh').read_text()
        self.assertLess(source.index('--manifest "$release_manifest"'), source.index('chmod 0700 "$tmp_script"'))
        self.assertIn('SLS_MASS_NOTIFY_TGZ="$release_package" SLS_MASS_NOTIFY_TGZ_URL=\'\'', source)


if __name__ == '__main__':
    unittest.main()
