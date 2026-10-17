#!/usr/bin/env python3
"""Exercise release signing, rollback, link refusal, and artifact races privately."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

SOURCE = Path(__file__).resolve().parents[1]


class ReleaseSignerTransaction(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-sign-transaction-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        module_name = 'release_signer_fixture_' + self.root.name.replace('-', '_')
        spec = importlib.util.spec_from_file_location(module_name, SOURCE / 'tools/sign_release.py')
        if spec is None or spec.loader is None:
            self.fail('Unable to load release signer fixture.')
        self.signer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.signer)
        self.signer.ROOT = self.root / 'source'
        (self.signer.ROOT / 'slsmassnotifyserver').mkdir(parents=True)
        (self.signer.ROOT / 'tools').mkdir()
        (self.signer.ROOT / 'dist').mkdir()
        (self.signer.ROOT / 'slsmassnotifyserver/module.xml').write_text(
            '<module><version>0.1.7-beta</version></module>', encoding='utf-8')
        (self.signer.ROOT / 'tools/install_release.sh').write_text(
            'fixture installer', encoding='utf-8')
        self.package = self.signer.ROOT / 'dist/slsmassnotifyserver-0.1.7-beta.tgz'
        self.package.write_bytes(b'fixture package')
        self.key = self.root / 'private.key'
        self.public = self.root / 'public.pem'
        subprocess.run(
            ['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(self.key)],
            check=True, capture_output=True, timeout=10)
        subprocess.run(
            ['/usr/bin/openssl', 'pkey', '-in', str(self.key), '-pubout', '-out', str(self.public)],
            check=True, capture_output=True, timeout=10)
        self.key.chmod(0o600)
        actual = self.signer.verifier.verify

        def private_verify(*args):
            return actual(*args, public_key=self.public, trust_directory=self.root / 'trust')

        self.private_verify = private_verify

    def invoke(self, verify=None):
        with mock.patch.object(
                self.signer.verifier, 'verify', side_effect=verify or self.private_verify), \
                mock.patch.object(
                    sys, 'argv', ['sign_release.py', '--key', str(self.key)]):
            self.signer.main()

    def outputs(self):
        distribution = self.signer.ROOT / 'dist'
        return distribution / 'release-manifest.json', distribution / 'release-manifest.sig'

    def test_refuses_linked_output_without_touching_victim(self):
        manifest, _ = self.outputs()
        victim = self.root / 'victim'
        victim.write_bytes(b'preserve')
        manifest.symlink_to(victim)
        with self.assertRaisesRegex(ValueError, 'Unsafe existing release output'):
            self.invoke()
        self.assertEqual(victim.read_bytes(), b'preserve')
        self.assertFalse((manifest.parent / self.signer.TRANSACTION_NAME).exists())

    def test_signer_failure_preserves_previous_pair(self):
        manifest, signature = self.outputs()
        manifest.write_bytes(b'prior manifest')
        signature.write_bytes(b'p' * 64)
        actual_run = self.signer.subprocess.run

        def fail_sign(command, *args, **kwargs):
            if 'pkeyutl' in command:
                raise subprocess.CalledProcessError(1, command)
            return actual_run(command, *args, **kwargs)

        with mock.patch.object(self.signer.subprocess, 'run', side_effect=fail_sign), \
                self.assertRaises(subprocess.CalledProcessError):
            self.invoke()
        self.assertEqual(manifest.read_bytes(), b'prior manifest')
        self.assertEqual(signature.read_bytes(), b'p' * 64)
        self.assertFalse((manifest.parent / self.signer.TRANSACTION_NAME).exists())

    def test_prepared_transaction_restores_moved_output(self):
        manifest, signature = self.outputs()
        manifest.write_bytes(b'prior manifest')
        signature.write_bytes(b'p' * 64)
        transaction, _ = self.signer._begin_transaction(manifest.parent, manifest, signature)
        os.replace(manifest, transaction / 'manifest.previous')
        (transaction / 'manifest.stage').write_bytes(b'new manifest')
        self.signer._recover_transaction(manifest.parent)
        self.assertEqual(manifest.read_bytes(), b'prior manifest')
        self.assertEqual(signature.read_bytes(), b'p' * 64)
        self.assertFalse(transaction.exists())

    def test_commit_syncs_backups_before_publishing_outputs(self):
        manifest, signature = self.outputs()
        manifest.write_bytes(b'prior manifest')
        signature.write_bytes(b'p' * 64)
        transaction, metadata = self.signer._begin_transaction(
            manifest.parent, manifest, signature)
        (transaction / 'manifest.stage').write_bytes(b'new manifest')
        (transaction / 'signature.stage').write_bytes(b'n' * 64)
        synced = []
        with mock.patch.object(
                self.signer, '_fsync_directory', side_effect=lambda path: synced.append(path)):
            self.signer._commit_transaction(manifest.parent, transaction, metadata)
        self.assertGreaterEqual(len(synced), 2)
        self.assertEqual(synced[:2], [transaction, manifest.parent])
        self.assertEqual(manifest.read_bytes(), b'new manifest')
        self.assertEqual(signature.read_bytes(), b'n' * 64)

    def test_fifo_input_is_rejected_without_waiting_for_a_writer(self):
        fifo = self.root / 'artifact.fifo'
        os.mkfifo(fifo, 0o600)
        with self.assertRaisesRegex(ValueError, 'Unsafe release artifact'):
            self.signer._digest_regular(fifo)

    def test_committed_transaction_keeps_recovery_if_output_is_missing(self):
        manifest, signature = self.outputs()
        manifest.write_bytes(b'published manifest')
        signature.write_bytes(b's' * 64)
        transaction, metadata = self.signer._begin_transaction(
            manifest.parent, manifest, signature)
        committed = dict(metadata, phase='committed')
        (transaction / self.signer.METADATA_NAME).write_text(
            self.signer.json.dumps(committed), encoding='ascii')
        signature.unlink()
        with self.assertRaisesRegex(ValueError, 'committed release-signing output is missing'):
            self.signer._recover_transaction(manifest.parent)
        self.assertTrue(transaction.is_dir())
        self.assertEqual(manifest.read_bytes(), b'published manifest')

    def test_package_replacement_aborts_and_restores_outputs(self):
        manifest, signature = self.outputs()
        manifest.write_bytes(b'prior manifest')
        signature.write_bytes(b'p' * 64)

        def replace_after_verify(*args):
            result = self.private_verify(*args)
            replacement = self.package.with_suffix('.replacement')
            replacement.write_bytes(self.package.read_bytes())
            os.replace(replacement, self.package)
            return result

        with self.assertRaisesRegex(ValueError, 'changed during signing'):
            self.invoke(replace_after_verify)
        self.assertEqual(manifest.read_bytes(), b'prior manifest')
        self.assertEqual(signature.read_bytes(), b'p' * 64)

    def test_in_place_package_change_with_restored_timestamp_is_detected(self):
        original = self.package.read_bytes()
        timestamps = self.package.stat()

        def alter_after_verify(*args):
            result = self.private_verify(*args)
            altered = bytes([original[0] ^ 1]) + original[1:]
            self.package.write_bytes(altered)
            os.utime(
                self.package,
                ns=(timestamps.st_atime_ns, timestamps.st_mtime_ns),
            )
            return result

        with self.assertRaisesRegex(ValueError, 'changed during signing'):
            self.invoke(alter_after_verify)

    def test_invalid_version_stops_before_transaction(self):
        (self.signer.ROOT / 'slsmassnotifyserver/module.xml').write_text(
            '<module><version>../bad</version></module>', encoding='utf-8')
        with self.assertRaises(SystemExit):
            self.invoke()
        self.assertFalse((self.signer.ROOT / 'dist' / self.signer.TRANSACTION_NAME).exists())


if __name__ == '__main__':
    unittest.main()
