#!/usr/bin/env python3
"""Real Ed25519 verification fixtures; no network or production signing key."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('manifest_fixture', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_release_verify.py')
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


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
        return verifier.verify(self.manifest, self.signature, self.installer, self.package, '0.1.2-beta', self.public)

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
