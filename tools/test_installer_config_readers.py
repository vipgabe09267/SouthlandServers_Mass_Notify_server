#!/usr/bin/env python3
"""Qualify embedded config I/O against private files and controlled changes."""
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / "tools/install_release.sh").read_text()


def embedded(name, following):
    part = SOURCE[SOURCE.index(name + "() {"):SOURCE.index(following + "() {")]
    return part.split("<<'PY'\n", 1)[1].rsplit("\nPY", 1)[0]


HASH = embedded("safe_config_hash", "safe_config_restore")
SNAPSHOT = embedded("safe_config_snapshot", "safe_config_hash")
RESTORE = embedded("safe_config_restore", "snapshot_pending_config")
MUTATION = """import os
fixture_read = os.read
fixture_changed = False
def changed_read(fd, count):
    global fixture_changed
    if not fixture_changed:
        fixture_changed = True
        if os.environ['SLS_FIXTURE_CHANGE'] == 'grow':
            with open(os.environ['SLS_FIXTURE_SOURCE'], 'ab') as handle:
                handle.write(b'x' * (17 * 1024 * 1024))
        else:
            with open(os.environ['SLS_FIXTURE_SOURCE'], 'r+b') as handle:
                handle.write(b'z')
    return fixture_read(fd, count)
os.read = changed_read
"""


class InstallerConfigReaders(unittest.TestCase):
    def run_reader(self, body, settings, change=""):
        return subprocess.run(
            ["/usr/bin/python3", "-I", "-c", (MUTATION if change else "") + body],
            env={**os.environ, **settings, "SLS_FIXTURE_CHANGE": change},
            capture_output=True, text=True, timeout=3,
        )

    def test_hash_rejects_growth_and_same_size_changes_after_inspection(self):
        for change in ("grow", "rewrite"):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as name:
                source = Path(name) / "config"; source.write_bytes(b"valid")
                result = self.run_reader(HASH, {"CONFIG_SOURCE_PATH": str(source), "SLS_FIXTURE_SOURCE": str(source)}, change)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")

    def test_snapshot_rejects_concurrent_source_changes(self):
        for change in ("grow", "rewrite"):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as name:
                source = Path(name) / "config"; source.write_bytes(b"valid")
                snapshot = Path(name) / "snapshot"; snapshot.touch(mode=0o600)
                result = self.run_reader(SNAPSHOT, {"CONFIG_SOURCE_PATH": str(source), "CONFIG_SNAPSHOT_PATH": str(snapshot), "SLS_FIXTURE_SOURCE": str(source)}, change)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")

    def test_linked_snapshot_is_rejected_before_truncating_its_victim(self):
        with tempfile.TemporaryDirectory() as name:
            source = Path(name) / "config"; source.write_bytes(b"valid")
            victim = Path(name) / "victim"; victim.write_bytes(b"preserved"); victim.chmod(0o600)
            target = Path(name) / "snapshot"; os.link(victim, target)
            result = self.run_reader(SNAPSHOT, {"CONFIG_SOURCE_PATH": str(source), "CONFIG_SNAPSHOT_PATH": str(target)})
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(victim.read_bytes(), b"preserved")

    def test_restore_rejects_changed_snapshot_before_promoting_anything(self):
        for change in ("grow", "rewrite"):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as name:
                source = Path(name) / "snapshot"; source.write_bytes(b"valid"); source.chmod(0o600)
                target = Path(name) / "config"; target.write_bytes(b"preserved")
                result = self.run_reader(RESTORE, {"CONFIG_SNAPSHOT_PATH": str(source), "CONFIG_DESTINATION_PATH": str(target), "SLS_FIXTURE_SOURCE": str(source)}, change)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(target.read_bytes(), b"preserved")
                self.assertFalse(list(Path(name).glob(".mass-notifications.config.restore.*")))

    def test_stable_configuration_roundtrip_retains_exact_bytes(self):
        with tempfile.TemporaryDirectory() as name:
            body = b'{"private_fixture":"preserve"}\n'
            source = Path(name) / "config"; source.write_bytes(body)
            snapshot = Path(name) / "snapshot"; snapshot.touch(mode=0o600)
            result = self.run_reader(SNAPSHOT, {"CONFIG_SOURCE_PATH": str(source), "CONFIG_SNAPSHOT_PATH": str(snapshot)})
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(snapshot.read_bytes(), body)
            result = self.run_reader(HASH, {"CONFIG_SOURCE_PATH": str(source)})
            self.assertEqual(result.stdout.strip(), hashlib.sha256(body).hexdigest())
            result = self.run_reader(RESTORE, {"CONFIG_SNAPSHOT_PATH": str(snapshot), "CONFIG_DESTINATION_PATH": str(source)})
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(source.read_bytes(), body)


if __name__ == "__main__":
    unittest.main()
