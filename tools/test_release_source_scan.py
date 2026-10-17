#!/usr/bin/python3
"""Release source and test-gate integrity checks."""
import importlib.util
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
scanner_path = Path(__file__).resolve().with_name('scan_release_source.py')
spec = importlib.util.spec_from_file_location('release_source_scan', scanner_path)
if spec is None or spec.loader is None:
    raise RuntimeError('Unable to load the release source scanner.')
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)
CLEAN_ENV = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'}


class SourceScan(unittest.TestCase):
    def test_release_builder_rejects_unknown_options_and_invalid_versions_early(self):
        root = Path(__file__).resolve().parents[1]
        build = root / 'tools/build_tgz.sh'
        invalid = subprocess.run(['bash', str(build), '--not-a-release-option'], text=True,
                                 capture_output=True, env=CLEAN_ENV, timeout=10)
        self.assertEqual(invalid.returncode, 2)
        self.assertIn('Usage:', invalid.stderr)
        with tempfile.TemporaryDirectory() as directory:
            fixture = Path(directory)
            (fixture / 'tools').mkdir(); (fixture / 'slsmassnotifyserver').mkdir()
            shutil.copy2(build, fixture / 'tools/build_tgz.sh')
            (fixture / 'slsmassnotifyserver/module.xml').write_text(
                '<module><rawname>slsmassnotifyserver</rawname><version>../../unsafe</version></module>')
            result = subprocess.run(['bash', str(fixture / 'tools/build_tgz.sh'), '--unsigned'], text=True,
                                    capture_output=True, env={**CLEAN_ENV, 'SLS_TEST_NAMESPACE': 'entered',
                                    'SLS_TEST_PARENT_MOUNT_NS': 'fixture', 'SLS_TEST_PARENT_NET_NS': 'fixture',
                                    'SLS_TEST_PARENT_PID_NS': 'fixture'}, timeout=10)
            self.assertEqual(result.returncode, 2)
            self.assertIn('invalid release version', result.stderr)

    def test_control_api_apache_guard_exposes_only_entry_points(self):
        root = Path(__file__).resolve().parents[1]
        source = (root / 'slsmassnotifyserver/api/sls-mass-notify/.htaccess').read_text()
        self.assertIn('Options -Indexes -MultiViews', source)
        match = re.search(r'<FilesMatch "([^"]+)">', source)
        self.assertIsNotNone(match, 'Control API source-file access guard is missing.')
        denied = re.compile(match.group(1))
        for name in ['index.php', 'edge.php', 'edge-view.php', 'media.php', 'peer.php', 'sms-callback.php', 'trigger.php']:
            self.assertIsNone(denied.fullmatch(name), name)
        for name in ['config-crypto.php', 'contract.php', 'event-log.php', 'media-policy.php', 'security.php']:
            self.assertIsNotNone(denied.fullmatch(name), name)

    def test_every_runnable_test_is_registered_once(self):
        root = Path(__file__).resolve().parents[1]
        build = (root / 'tools/build_tgz.sh').read_text()
        registered = re.findall(r'\$\{ROOT_DIR\}/(tools/test_[^" ]+)', build)
        runnable = {
            path.relative_to(root).as_posix()
            for path in (root / 'tools').glob('test_*')
            if path.is_file() and path.suffix in {'.js', '.php', '.py', '.sh'}
        }
        self.assertEqual(len(registered), len(set(registered)), 'A release test is registered more than once.')
        self.assertEqual(set(registered), runnable, 'Every runnable release test must be registered exactly once.')

    def test_actual_generated_private_key_and_escaped_copy_are_rejected(self):
        key = subprocess.check_output(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519'],
                                      stderr=subprocess.DEVNULL, timeout=10, env=CLEAN_ENV)
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
