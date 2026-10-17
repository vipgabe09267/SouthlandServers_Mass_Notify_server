#!/usr/bin/env python3
"""Check audit-storage refusals without touching any live PBX file."""
import fcntl
import importlib.util
import os
from pathlib import Path
import pwd
import sys
import tempfile
import time
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('private_audit_boundary', ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_atomic_json.py')
atomic = importlib.util.module_from_spec(spec)
spec.loader.exec_module(atomic)


class AuditBounds(unittest.TestCase):
    def setUp(self):
        if os.environ.get('SLS_TEST_NAMESPACE') != 'entered':
            raise RuntimeError('Use the isolated fixture runner; no host file is tested.')
        self.temporary = tempfile.TemporaryDirectory(prefix='sls-audit-boundary-')
        self.root = Path(self.temporary.name)
        self.path = self.root / 'events.jsonl'

    def tearDown(self):
        self.temporary.cleanup()

    def test_linked_or_nonregular_log_never_changes_another_file(self):
        target = self.root / 'prior-evidence'
        target.write_bytes(b'preserve\n')
        target.chmod(0o600)
        before = target.stat()
        for kind in ('symlink', 'hardlink', 'fifo'):
            with self.subTest(kind=kind):
                if kind == 'symlink': self.path.symlink_to(target)
                elif kind == 'hardlink': os.link(target, self.path)
                else: os.mkfifo(self.path, 0o640)
                with self.assertRaises((OSError, RuntimeError)):
                    atomic.append_json_line(self.path, {'event_id': 'fixture'})
                self.path.unlink()
                self.assertEqual(target.read_bytes(), b'preserve\n')
                self.assertEqual(target.stat().st_mode, before.st_mode)
                self.assertEqual(target.stat().st_uid, before.st_uid)

    def test_busy_log_has_a_bounded_wait_without_permission_changes(self):
        self.path.write_bytes(b'prior\n')
        self.path.chmod(0o644)
        with self.path.open('r+b') as holder:
            fcntl.flock(holder, fcntl.LOCK_EX)
            started = time.monotonic()
            with self.assertRaisesRegex(RuntimeError, 'event_log_busy'):
                atomic.append_json_line(self.path, {'event_id': 'fixture'}, timeout=.05)
            self.assertLess(time.monotonic() - started, .5)
            self.assertEqual(self.path.read_bytes(), b'prior\n')
            self.assertEqual(self.path.stat().st_mode & 0o777, 0o644)

    def test_full_or_incomplete_history_is_preserved(self):
        for body, error in [(b'prior\n', 'capacity_reached'), (b'incomplete', 'incomplete_record')]:
            self.path.write_bytes(body)
            self.path.chmod(0o640)
            inode = self.path.stat().st_ino
            with self.assertRaisesRegex(RuntimeError, error):
                atomic.append_json_line(self.path, {'event_id': 'fixture'}, maximum=len(body) if body.endswith(b'\n') else 1000)
            self.assertEqual(self.path.read_bytes(), body)
            self.assertEqual(self.path.stat().st_ino, inode)

    def test_unrecognized_file_or_directory_permissions_are_refused(self):
        self.path.write_bytes(b'prior\n')
        self.path.chmod(0o666)
        with self.assertRaises(RuntimeError):
            atomic.append_json_line(self.path, {'event_id': 'fixture'})
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o666)
        self.path.chmod(0o640)
        self.root.chmod(0o770)
        with self.assertRaises(RuntimeError):
            atomic.append_json_line(self.path, {'event_id': 'fixture'})
        self.root.chmod(0o700)
        self.assertEqual(self.path.read_bytes(), b'prior\n')

    def test_untrusted_group_writable_ancestor_cannot_host_a_private_journal(self):
        account = pwd.getpwnam('asterisk')
        unrelated = pwd.getpwnam('nobody')
        ancestor = self.root / 'untrusted-group'
        ancestor.mkdir()
        os.chown(ancestor, account.pw_uid, unrelated.pw_gid)
        ancestor.chmod(0o2775)
        private = ancestor / 'sls-state'
        private.mkdir(mode=0o750)
        os.chown(private, account.pw_uid, account.pw_gid)
        with self.assertRaises(RuntimeError):
            with atomic.locked_json(private / 'status.json', runtime_owner=True):
                self.fail('Unexpected journal write')
        self.assertFalse((private / 'status.json').exists())


if __name__ == '__main__':
    unittest.main()
