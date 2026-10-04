#!/usr/bin/python3
"""Private durable audio journal failure fixtures; no PBX or playback."""
import importlib.util
import json
import os
import pwd
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

PATH = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify/sls_audio_state.py'
spec = importlib.util.spec_from_file_location('audio_state_fixture', PATH)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class AudioState(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.state = self.root / m.STATE
        self.lock = self.root / m.LOCK

    def tearDown(self):
        self.temporary.cleanup()

    def commit(self, value=100):
        with m.audio_state(self.root) as state:
            state['recipients']['1000'] = value

    def test_fresh_reader_does_not_initialize_and_success_marks_stable_inode(self):
        with m.read_audio_state(self.root) as state:
            self.assertEqual(state, {'recipients': {}, 'media': {}, 'waiting': {}})
        self.assertFalse(self.state.exists())
        self.assertEqual(self.lock.read_bytes(), b'')
        inode = self.lock.stat().st_ino
        self.commit()
        first = self.state.stat().st_ino
        self.commit(200)
        self.assertNotEqual(first, self.state.stat().st_ino)
        self.assertEqual(inode, self.lock.stat().st_ino)
        self.assertEqual(self.lock.read_bytes(), m.MARKER)
        self.assertEqual(stat.S_IMODE(self.state.stat().st_mode), 0o640)

    def test_initialized_missing_and_existing_empty_are_rejected_preserved(self):
        self.commit()
        self.state.unlink()
        for operation in (m.audio_state, m.read_audio_state):
            with self.assertRaisesRegex(RuntimeError, 'missing'):
                with operation(self.root):
                    pass
        self.state.write_bytes(b'')
        with self.assertRaisesRegex(RuntimeError, 'corrupt'):
            self.commit()
        self.assertEqual(self.state.read_bytes(), b'')

    def test_legacy_empty_maps_normalize_missing_waiting(self):
        self.state.write_text('{"recipients":[],"media":[]}')
        with m.audio_state(self.root) as state:
            self.assertEqual(state['waiting'], {})
        self.assertEqual(json.loads(self.state.read_bytes()), {key: {} for key in m.FIELDS})

    def test_invalid_json_and_states_never_overwritten(self):
        invalid = [b'', b'{', b'null', b'{"recipients":{},"recipients":{},"media":{}}',
            b'{"recipients":{"1000":true},"media":{}}', b'{"recipients":{},"media":{"../bad.wav":1}}',
            b'{"recipients":[1],"media":{}}', b'{"recipients":{},"media":{},"waiting":{"bad":{}}}',
            b'{"recipients":{"1000":NaN},"media":{}}', b' ' * (m.LIMIT + 1)]
        for raw in invalid:
            with self.subTest(raw=raw[:90]):
                self.state.write_bytes(raw)
                with self.assertRaises(RuntimeError):
                    self.commit()
                self.assertEqual(self.state.read_bytes(), raw)

    def test_invalid_marker_never_repaired(self):
        for marker in (b'SLS_', m.MARKER + b'x', b'junk'):
            self.lock.write_bytes(marker)
            with self.assertRaises(RuntimeError):
                self.commit()
            self.assertEqual(self.lock.read_bytes(), marker)
            self.assertFalse(self.state.exists())

    def test_context_exception_does_not_commit(self):
        self.commit()
        before = self.state.read_bytes()
        with self.assertRaises(ValueError):
            with m.audio_state(self.root) as state:
                state['recipients']['1000'] = 500
                raise ValueError('fixture')
        self.assertEqual(self.state.read_bytes(), before)

    def test_partial_writes_are_completed_and_zero_progress_fails(self):
        write = os.write
        with mock.patch.object(m.os, 'write', side_effect=lambda fd, data: write(fd, data[:3])):
            self.commit()
        self.assertEqual(self.lock.read_bytes(), m.MARKER)
        before = self.state.read_bytes()
        with mock.patch.object(m.os, 'write', return_value=0):
            with self.assertRaises(OSError):
                self.commit(200)
        self.assertEqual(self.state.read_bytes(), before)
        self.assertFalse(list(self.root.glob('*.tmp')))

    def test_precommit_fsync_and_rename_failure_preserve_original(self):
        self.commit()
        before = self.state.read_bytes()
        for name in ('fsync', 'replace'):
            with self.subTest(operation=name), mock.patch.object(m.os, name, side_effect=OSError('fixture failure')):
                with self.assertRaises(OSError):
                    self.commit(500)
            self.assertEqual(self.state.read_bytes(), before)
            self.assertFalse(list(self.root.glob('*.tmp')))

    def test_post_replace_directory_fsync_uncertainty_keeps_complete_new_state(self):
        self.commit()
        fsync = os.fsync
        def fail_directory(fd):
            if stat.S_ISDIR(os.fstat(fd).st_mode):
                raise OSError('directory fsync failed')
            fsync(fd)
        with mock.patch.object(m.os, 'fsync', side_effect=fail_directory):
            with self.assertRaises(OSError):
                self.commit(500)
        self.assertEqual(json.loads(self.state.read_bytes())['recipients']['1000'], 500)
        self.assertEqual(self.lock.read_bytes(), m.MARKER)

    def test_partial_marker_failure_is_preserved_and_blocks_later_transactions(self):
        # Pre-create the permanent lock, avoiding initial directory fsync noise.
        with m.read_audio_state(self.root):
            pass
        write = os.write
        def partial_marker(fd, data):
            if os.fstat(fd).st_ino == self.lock.stat().st_ino:
                write(fd, data[:4])
                raise OSError('marker interrupted')
            return write(fd, data)
        with mock.patch.object(m.os, 'write', side_effect=partial_marker):
            with self.assertRaises(OSError):
                self.commit()
        self.assertEqual(json.loads(self.state.read_bytes())['recipients']['1000'], 100)
        self.assertEqual(self.lock.read_bytes(), b'SLS_')
        with self.assertRaises(RuntimeError):
            self.commit(500)

    def test_unsafe_links_files_and_modes_rejected(self):
        victim = self.root / 'victim'
        victim.write_bytes(b'unchanged')
        for filename in (m.LOCK, m.STATE):
            path = self.root / filename
            for kind in ('symlink', 'hardlink', 'fifo', 'writable'):
                with self.subTest(filename=filename, kind=kind):
                    if path.exists():
                        path.unlink()
                    if kind == 'symlink':
                        path.symlink_to(victim)
                    elif kind == 'hardlink':
                        os.link(victim, path)
                    elif kind == 'fifo':
                        os.mkfifo(path)
                    else:
                        path.write_bytes(b'')
                        path.chmod(0o666)
                    with self.assertRaises((OSError, RuntimeError)):
                        self.commit()
                    path.unlink()
                    self.assertEqual(victim.read_bytes(), b'unchanged')

    def test_destination_or_lock_replacement_during_transaction_is_not_overwritten(self):
        self.commit()
        replacement = b'{"recipients":{"1001":200},"media":{}}'
        with self.assertRaisesRegex(RuntimeError, 'changed before commit'):
            with m.audio_state(self.root) as state:
                (self.root / 'replacement').write_bytes(replacement)
                os.replace(self.root / 'replacement', self.state)
                state['recipients']['1000'] = 500
        self.assertEqual(self.state.read_bytes(), replacement)
        with self.assertRaisesRegex(RuntimeError, 'unsafe'):
            with m.audio_state(self.root):
                self.lock.unlink()
                self.lock.write_bytes(m.MARKER)
        self.assertEqual(self.state.read_bytes(), replacement)

    def test_unsafe_directory_and_symlinked_ancestor_rejected(self):
        child = self.root / 'child'
        child.mkdir()
        link = self.root / 'link'
        link.symlink_to(child, target_is_directory=True)
        for directory in (link,):
            with self.assertRaises(OSError):
                with m.audio_state(directory):
                    pass
        child.chmod(0o777)
        with self.assertRaisesRegex(RuntimeError, 'directory is unsafe'):
            with m.audio_state(child):
                pass
        self.assertFalse((child / m.LOCK).exists())

    def test_marker_fsync_failure_reports_uncertainty_without_undo(self):
        with m.read_audio_state(self.root):
            pass
        fsync = os.fsync
        inode = self.lock.stat().st_ino
        def fail_marker(fd):
            if os.fstat(fd).st_ino == inode:
                raise OSError('marker fsync failed')
            fsync(fd)
        with mock.patch.object(m.os, 'fsync', side_effect=fail_marker):
            with self.assertRaises(OSError):
                self.commit()
        self.assertEqual(self.lock.read_bytes(), m.MARKER)
        with m.read_audio_state(self.root) as state:
            self.assertEqual(state['recipients']['1000'], 100)

    @unittest.skipUnless(os.geteuid() == 0, 'root reader ownership fixture')
    def test_root_bootstrap_creates_only_runtime_owned_lock_and_never_writes_json(self):
        account = pwd.getpwnam('asterisk')
        child = self.root / 'runtime'
        child.mkdir(mode=0o750)
        os.chown(child, account.pw_uid, account.pw_gid)
        with m.read_audio_state(child) as state:
            self.assertEqual(state['recipients'], {})
        lock = child / m.LOCK
        self.assertEqual(lock.stat().st_uid, account.pw_uid)
        self.assertEqual(lock.stat().st_gid, account.pw_gid)
        self.assertEqual(lock.read_bytes(), b'')
        self.assertFalse((child / m.STATE).exists())
        before = lock.stat()
        with self.assertRaisesRegex(RuntimeError, 'runtime directory owner'):
            with m.audio_state(child):
                pass
        with m.read_audio_state(child):
            pass
        self.assertEqual((before.st_ino, before.st_uid, before.st_mode, before.st_ctime_ns),
                         (lock.stat().st_ino, lock.stat().st_uid, lock.stat().st_mode, lock.stat().st_ctime_ns))

    def test_waiting_validation_on_commit(self):
        self.commit()
        original = self.state.read_bytes()
        row = {'recipients':['1000'],'priority':0,'created':1,'expires':301,'heartbeat':16,'media_name':'fixture.wav','duration':5}
        for field, value in (('priority', True), ('duration', float('inf')), ('created', -1), ('recipients', ['1000','1000']), ('extra', 1)):
            with self.subTest(field=field):
                with self.assertRaises(RuntimeError):
                    with m.audio_state(self.root) as state:
                        state['waiting']['a' * 32] = dict(row, **{field:value})
                self.assertEqual(self.state.read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
