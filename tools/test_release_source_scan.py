#!/usr/bin/python3
"""Credential fixtures are generated in memory; no secret is saved or printed."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('release_source_scan', Path(__file__).with_name('scan_release_source.py'))
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)


class SourceScan(unittest.TestCase):
    def test_actual_generated_private_key_and_escaped_copy_are_rejected(self):
        key = subprocess.check_output(['openssl', 'genpkey', '-algorithm', 'ED25519'], stderr=subprocess.DEVNULL)
        self.assertTrue(scanner.contains_private_material(key))
        self.assertTrue(scanner.contains_private_material(key.replace(b'\n', b'\\n')))

    def test_formatting_markers_and_public_keys_are_allowed(self):
        source = b"strpos($key, '-----BEGIN " + b"PRIVATE KEY-----');"
        self.assertFalse(scanner.contains_private_material(source))
        source = b'"-----BEGIN ' + b'RSA PRIVATE KEY-----\\n".chunk_split($key,64,"\\n");'
        self.assertFalse(scanner.contains_private_material(source))
        public = b'-----BEGIN PUBLIC KEY-----\n' + b'A' * 64 + b'\n-----END PUBLIC KEY-----'
        self.assertFalse(scanner.contains_private_material(public))

    def test_tokens_and_linked_sources_are_rejected_without_payload_output(self):
        for prefix in [b'gh' + b'p_', b'github_' + b'pat_']:
            self.assertTrue(scanner.contains_private_material(prefix + b'A' * 40))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'safe.py').write_text('pass\n')
            (root / 'linked.py').symlink_to(root / 'safe.py')
            self.assertEqual([(p.name, reason) for p, reason in scanner.scan([root])], [('linked.py', 'linked source')])


if __name__ == '__main__':
    unittest.main()
