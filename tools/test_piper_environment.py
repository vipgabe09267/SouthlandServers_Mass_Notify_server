#!/usr/bin/python3
"""Real private directory exchanges and fault injection; no PBX or downloads."""
import fcntl
import importlib.util
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_piper_environment.py'
SPEC = importlib.util.spec_from_file_location('piper_environment_fixture', HELPER)
module = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(module)


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned environment fixture required')
class Replacement(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-piper-environment-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'venv').mkdir()
        (self.root / 'venv/working').write_bytes(b'old package')
        self.original = (self.root / 'venv').stat().st_ino
        self.builds = 0; self.validations = 0

    def build(self, stage, lock):
        self.assertEqual((self.root / 'venv/working').read_bytes(), b'old package')
        self.assertEqual((self.root / 'venv').stat().st_ino, self.original)
        self.assertTrue(stat.S_ISREG(os.fstat(lock).st_mode))
        (stage / 'venv/replacement').write_bytes(b'new verified package')
        self.builds += 1

    def validate(self, root):
        self.assertEqual((root / 'venv/replacement').read_bytes(), b'new verified package')
        self.assertFalse((root / 'venv/working').exists())
        self.validations += 1

    def replace(self, builder=None, validator=None):
        return module.replace_environment(self.root, builder or self.build, validator or self.validate, True)

    def assert_original(self):
        self.assertEqual((self.root / 'venv/working').read_bytes(), b'old package')
        self.assertEqual((self.root / 'venv').stat().st_ino, self.original)

    def test_success_uses_real_atomic_exchange_and_validates_before_cleanup(self):
        self.assertEqual(self.replace(), {'ok': True, 'replaced': True})
        self.assertEqual((self.builds, self.validations), (1, 1))
        self.assertNotEqual((self.root / 'venv').stat().st_ino, self.original)
        self.assertEqual(list(self.root.glob('.replacement-*')), [])
        self.assertEqual((self.root / '.replacement.lock').stat().st_mode & 0o777, 0o600)

    def test_failed_build_never_changes_live_environment(self):
        def failed(stage, lock):
            self.build(stage, lock)
            raise RuntimeError('private injected package failure')
        with self.assertRaisesRegex(module.EnvironmentError, 'Recovery directory'):
            self.replace(builder=failed)
        self.assert_original(); self.assertEqual(self.validations, 0)
        self.assertEqual(len(list(self.root.glob('.replacement-*'))), 1)

    def test_failed_postcheck_atomically_restores_original_and_retains_failed_candidate(self):
        def failed(root):
            self.validate(root)
            raise RuntimeError('private injected postcheck')
        with self.assertRaisesRegex(module.EnvironmentError, 'Recovery directory'):
            self.replace(validator=failed)
        self.assert_original()
        stage = next(self.root.glob('.replacement-*'))
        self.assertEqual((stage / 'venv/replacement').read_bytes(), b'new verified package')

    def test_unsupported_exchange_preserves_original(self):
        with mock.patch.object(module, 'exchange', side_effect=module.EnvironmentError('Unsupported exchange')):
            with self.assertRaisesRegex(module.EnvironmentError, 'Unsupported exchange'): self.replace()
        self.assert_original(); self.assertEqual(self.validations, 0)

    def test_partial_cleanup_never_reactivates_incomplete_old_environment(self):
        real_delete = module.shutil.rmtree
        def failed(name, *, dir_fd):
            os.unlink('venv/working', dir_fd=dir_fd)
            raise OSError('injected partial cleanup failure')
        failed.avoids_symlink_attacks = real_delete.avoids_symlink_attacks
        with mock.patch.object(module.shutil, 'rmtree', failed):
            result = self.replace()
        self.assertTrue(result['ok'] and result['cleanup_pending'])
        self.assertEqual((self.root / 'venv/replacement').read_bytes(), b'new verified package')
        self.assertNotEqual((self.root / 'venv').stat().st_ino, self.original)
        self.assertTrue(Path(result['recovery_directory']).is_dir())

    def test_untrusted_files_are_rejected_before_builder_runs(self):
        victim = self.root / 'private'; victim.write_text('preserve')
        for kind in ('symlink', 'hardlink', 'fifo', 'writable'):
            with self.subTest(kind=kind):
                entry = self.root / 'venv/unsafe'
                if kind == 'symlink': entry.symlink_to(victim)
                elif kind == 'hardlink': os.link(victim, entry)
                elif kind == 'fifo': os.mkfifo(entry)
                else: entry.write_text('unsafe'); entry.chmod(0o666)
                with self.assertRaises(module.EnvironmentError): self.replace()
                self.assertEqual(self.builds, 0); self.assert_original(); entry.unlink()
                self.assertEqual(victim.read_text(), 'preserve')

    def test_lock_is_permanent_and_contention_does_not_run_builder(self):
        lock = self.root / '.replacement.lock'; lock.touch(mode=0o600)
        with lock.open('r+b') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaises(module.EnvironmentError): self.replace()
        self.assert_original(); self.assertEqual(self.builds, 0)
        inode = lock.stat().st_ino; self.replace(); self.assertEqual(lock.stat().st_ino, inode)

    def test_recovery_directory_bound_preserves_all_evidence(self):
        for number in range(3): (self.root / ('.replacement-' + ('%024x' % number))).mkdir(mode=0o700)
        with self.assertRaisesRegex(module.EnvironmentError, 'Three Piper recovery directories'): self.replace()
        self.assert_original(); self.assertEqual(self.builds, 0)

    def test_parent_alias_and_symlink_venv_are_rejected(self):
        alias = self.root / 'alias'; alias.symlink_to(self.root)
        with self.assertRaises(OSError): module.replace_environment(alias, self.build, self.validate, True)
        original = self.root / 'saved'; (self.root / 'venv').rename(original)
        (self.root / 'venv').symlink_to(original)
        with self.assertRaises(module.EnvironmentError): self.replace()
        self.assertEqual((original / 'working').read_bytes(), b'old package')

    def test_direct_private_build_cannot_mount_in_callers_namespace(self):
        with mock.patch.object(module, 'checked') as command:
            with self.assertRaisesRegex(module.EnvironmentError, 'separate private mount namespace'):
                module.build_private(self.root / 'stage', self.root)
            command.assert_not_called()

    def test_worker_guard_is_obtained_before_exchange_and_released(self):
        guard = mock.Mock()
        with mock.patch.object(module, 'guard_workers', return_value=guard) as acquire:
            module.replace_environment(self.root, self.build, self.validate)
            acquire.assert_called_once_with()
            guard.communicate.assert_called_once_with('release\n', timeout=5)

    def test_worker_guard_failure_preserves_live_environment(self):
        with mock.patch.object(module, 'guard_workers', side_effect=module.EnvironmentError('workers busy')):
            with self.assertRaisesRegex(module.EnvironmentError, 'workers busy'):
                module.replace_environment(self.root, self.build, self.validate)
        self.assert_original()


if __name__ == '__main__': unittest.main()
