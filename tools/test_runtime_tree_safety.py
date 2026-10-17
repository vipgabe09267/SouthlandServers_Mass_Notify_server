#!/usr/bin/env python3
"""Qualify the embedded runtime-tree helper only on disposable directories."""
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'slsmassnotifyserver/Slsmassnotifyserver.class.php'


class RuntimeTreeSafety(unittest.TestCase):
    def helper(self):
        source = SOURCE.read_text().split('private function secureManagedRuntimeTree(', 1)[1]
        return re.search(r"\$program = <<<'PY'\n(.*?)\nPY;", source, re.S).group(1)

    def run_helper(self, path, profile='data', injection=''):
        source=self.helper()
        if injection:
            source=injection+'\nexec(compile('+repr(source)+', "<actual helper>", "exec"))'
        return subprocess.run(['/usr/bin/python3', '-I', '-c', source, str(path), profile],
                              capture_output=True, text=True, timeout=5)

    def executable_helper(self):
        source = SOURCE.read_text().split('private function secureExecutableRuntimeTree(', 1)[1]
        return re.search(r"\$program = <<<'PY'\n(.*?)\nPY;", source, re.S).group(1)

    def run_executable_helper(self, path, injection=''):
        source = self.executable_helper()
        if injection:
            source = injection + '\nexec(compile(' + repr(source) + ', "<actual executable helper>", "exec"))'
        return subprocess.run([
            '/usr/bin/python3', '-I', '-c', source, str(path), str(pwd.getpwnam('asterisk').pw_uid)
        ], capture_output=True, text=True, timeout=5)

    def test_hard_links_preserve_the_other_name_and_inode(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); tree = root / 'data'; tree.mkdir()
            outside = root / 'unrelated'; outside.write_bytes(b'private unrelated contents'); outside.chmod(0o600)
            before = outside.stat(); os.link(outside, tree / 'linked-file')
            result = self.run_helper(tree)
            self.assertNotEqual(result.returncode, 0, 'A hard-linked runtime file was adopted.')
            after = outside.stat()
            self.assertEqual((after.st_uid, after.st_gid, stat.S_IMODE(after.st_mode)),
                             (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode)))
            self.assertEqual(after.st_ino, before.st_ino)
            self.assertEqual(outside.read_bytes(), b'private unrelated contents')

    def test_valid_legacy_files_and_unknown_ownership(self):
        account = pwd.getpwnam('asterisk')
        with tempfile.TemporaryDirectory() as temporary:
            tree = Path(temporary) / 'data'; tree.mkdir()
            private = tree / 'phone-admission.json'; private.write_bytes(b'unchanged'); private.chmod(0o644)
            inode = private.stat().st_ino
            self.assertEqual(self.run_helper(tree).returncode, 0)
            self.assertEqual(private.stat().st_ino, inode)
            self.assertEqual(private.read_bytes(), b'unchanged')
            self.assertEqual((private.stat().st_uid, stat.S_IMODE(private.stat().st_mode)), (account.pw_uid, 0o640))
            os.chown(private, 65534, 65534); private.chmod(0o600)
            self.assertNotEqual(self.run_helper(tree).returncode, 0, 'Unexpected ownership was adopted.')
            self.assertEqual((private.stat().st_uid, stat.S_IMODE(private.stat().st_mode)), (65534, 0o600))

    def test_replacement_before_open_is_rejected(self):
        for attack in ('regular', 'fifo'):
            with self.subTest(attack=attack), tempfile.TemporaryDirectory() as temporary:
                tree=Path(temporary)/'data';tree.mkdir();target=tree/'target'
                target.write_bytes(b'original');target.chmod(0o600)
                original=target.stat()
                injection=r'''import os
real_open=os.open
def swapped_open(path, flags, *args, **kwargs):
    if path=='target':
        parent=kwargs['dir_fd'];os.rename('target','held',src_dir_fd=parent,dst_dir_fd=parent)
        if ATTACK=='fifo': os.mkfifo('target',0o600,dir_fd=parent)
        else:
            fd=real_open('target',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600,dir_fd=parent)
            os.write(fd,b'replacement');os.close(fd)
    return real_open(path,flags,*args,**kwargs)
os.open=swapped_open
'''.replace('ATTACK',repr(attack))
                self.assertNotEqual(self.run_helper(tree,injection=injection).returncode,0)
                held=(tree/'held').stat()
                self.assertEqual((held.st_uid,held.st_gid,held.st_mode,held.st_ino),
                                 (original.st_uid,original.st_gid,original.st_mode,original.st_ino))
                self.assertEqual(stat.S_IMODE(target.lstat().st_mode),0o600)

    def test_executable_tree_rejects_hard_links_without_mutating_the_other_name(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); tree = root / 'runtime'; tree.mkdir()
            outside = root / 'unrelated'; outside.write_bytes(b'preserve executable victim'); outside.chmod(0o600)
            before = outside.stat(); os.link(outside, tree / 'sls_notify.py')
            result = self.run_executable_helper(tree)
            self.assertNotEqual(result.returncode, 0, result.stderr)
            after = outside.stat()
            self.assertEqual((after.st_uid, after.st_gid, stat.S_IMODE(after.st_mode), after.st_ino),
                             (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode), before.st_ino))
            self.assertEqual(outside.read_bytes(), b'preserve executable victim')

    def test_executable_tree_checks_opened_identity_and_sets_expected_modes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); tree = root / 'runtime'; tree.mkdir()
            executable = tree / 'sls_notify.py'; executable.write_text('print("safe")\n'); executable.chmod(0o600)
            data = tree / 'release-signing.pub'; data.write_text('fixture\n'); data.chmod(0o600)
            self.assertEqual(self.run_executable_helper(tree).returncode, 0)
            self.assertEqual(stat.S_IMODE(executable.stat().st_mode), 0o755)
            self.assertEqual(stat.S_IMODE(data.stat().st_mode), 0o644)

            target = tree / 'target'; target.write_text('original'); target.chmod(0o600)
            original = target.stat()
            injection = r'''import os
real_open=os.open
def swapped_open(path, flags, *args, **kwargs):
    if path=='target':
        parent=kwargs['dir_fd'];os.rename('target','held',src_dir_fd=parent,dst_dir_fd=parent)
        fd=real_open('target',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600,dir_fd=parent)
        os.write(fd,b'replacement');os.close(fd)
    return real_open(path,flags,*args,**kwargs)
os.open=swapped_open
'''
            self.assertNotEqual(self.run_executable_helper(tree, injection).returncode, 0)
            held = (tree / 'held').stat()
            self.assertEqual((held.st_uid, held.st_gid, held.st_mode, held.st_ino),
                             (original.st_uid, original.st_gid, original.st_mode, original.st_ino))
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)

    def test_executable_tree_allows_only_root_protected_standard_venv_links(self):
        with tempfile.TemporaryDirectory() as temporary:
            tree = Path(temporary) / 'runtime'
            binary = tree / 'piper/venv/bin'
            library = tree / 'piper/venv/lib'
            binary.mkdir(parents=True)
            library.mkdir(parents=True)
            (tree / 'piper/venv/lib64').symlink_to('lib')
            (binary / 'python3').symlink_to('/usr/bin/python3')
            (binary / 'python').symlink_to('python3')
            self.assertEqual(self.run_executable_helper(tree).returncode, 0)

            for relative, target in (
                ('unexpected-link', '/etc/passwd'),
                ('piper/venv/lib64', '/tmp/untrusted-lib'),
                ('piper/venv/bin/python', '/tmp/untrusted-python'),
            ):
                with self.subTest(relative=relative):
                    with tempfile.TemporaryDirectory() as attempt:
                        candidate = Path(attempt) / 'runtime'
                        path = candidate / relative
                        path.parent.mkdir(parents=True)
                        path.symlink_to(target)
                        before = path.lstat()
                        result = self.run_executable_helper(candidate)
                        self.assertNotEqual(result.returncode, 0, 'An unexpected runtime link was trusted.')
                        after = path.lstat()
                        self.assertEqual((after.st_dev, after.st_ino, after.st_uid, after.st_mode),
                                         (before.st_dev, before.st_ino, before.st_uid, before.st_mode))
                        self.assertEqual(os.readlink(path), target)

    def test_executable_tree_rejects_asterisk_owned_venv_link(self):
        account = pwd.getpwnam('asterisk')
        with tempfile.TemporaryDirectory() as temporary:
            tree = Path(temporary) / 'runtime'
            binary = tree / 'piper/venv/bin'
            binary.mkdir(parents=True)
            link = binary / 'python3'
            link.symlink_to('/usr/bin/python3')
            os.lchown(link, account.pw_uid, account.pw_gid)
            before = link.lstat()
            result = self.run_executable_helper(tree)
            self.assertNotEqual(result.returncode, 0, 'An Asterisk-owned interpreter link was adopted.')
            after = link.lstat()
            self.assertEqual((after.st_ino, after.st_uid, after.st_gid),
                             (before.st_ino, before.st_uid, before.st_gid))


if __name__ == '__main__':
    unittest.main()
