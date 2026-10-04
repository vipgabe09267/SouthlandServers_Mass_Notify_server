#!/usr/bin/env python3
"""Exercise actual maintenance permission helpers on private inodes only."""

import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh").read_text()


def helper(name):
    block = SOURCE.split(name + "() {", 1)[1]
    match = re.search(r"<<'PY'[^\n]*\n(.*?)\nPY\n", block, re.S)
    if not match:
        raise AssertionError("actual maintenance helper missing: " + name)
    return match.group(1)


@unittest.skipUnless(os.geteuid() == 0, "root permission behavior requires isolated root fixtures")
class PermissionSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sls-permission-safety-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.account = pwd.getpwnam("asterisk")

    def file(self, name, mode=0o600, owner=None):
        path = self.base / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(b"private fixture bytes remain unchanged\n")
        path.chmod(mode)
        if owner is not None:
            os.chown(path, owner, owner)
        return path

    def metadata(self, path):
        info = path.lstat()
        return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_ino

    def run_helper(self, name, path, injection=""):
        source = helper(name)
        if injection:
            source = injection + "\nexec(compile(" + repr(source) + ", '<maintenance fixture>', 'exec'))\n"
        key = "CONFIG_PATH" if name == "secure_central_config" else "RUNTIME_PERMISSION_ROOT"
        return subprocess.run(["/usr/bin/python3", "-I", "-"], input=source, text=True,
                              env={**os.environ, key: str(path)}, capture_output=True, timeout=3)

    def test_valid_central_config_preserves_bytes_and_permissions(self):
        for owner in (0, self.account.pw_uid):
            with self.subTest(owner=owner):
                path = self.file("config", mode=0o666, owner=owner)
                before = path.read_bytes()
                result = self.run_helper("secure_central_config", path)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(path.read_bytes(), before)
                self.assertEqual(self.metadata(path)[:3], (self.account.pw_uid, self.account.pw_gid, 0o640))

    def test_central_config_rejects_hardlinks_without_mutating_victim(self):
        victim = self.file("victim", mode=0o600)
        os.link(victim, self.base / "config")
        before = self.metadata(victim)
        self.assertNotEqual(self.run_helper("secure_central_config", self.base / "config").returncode, 0)
        self.assertEqual(self.metadata(victim), before)

    def test_central_config_rejects_fifo_symlink_and_linked_parent(self):
        victim = self.file("victim")
        before = self.metadata(victim)
        fifo = self.base / "fifo"
        os.mkfifo(fifo, 0o600)
        link = self.base / "link"
        link.symlink_to(victim)
        directory = self.base / "linked-parent"
        directory.symlink_to(self.base, target_is_directory=True)
        for path in (fifo, link, directory / "victim"):
            with self.subTest(path=path.name):
                self.assertNotEqual(self.run_helper("secure_central_config", path).returncode, 0)
        self.assertEqual(self.metadata(victim), before)

    def test_unexpected_config_and_runtime_ownership_is_rejected(self):
        foreign_uid = pwd.getpwnam("nobody").pw_uid
        config = self.file("config", owner=foreign_uid)
        before = self.metadata(config)
        self.assertNotEqual(self.run_helper("secure_central_config", config).returncode, 0)
        self.assertEqual(self.metadata(config), before)
        runtime_file = self.file("runtime/foreign.py", owner=foreign_uid)
        before = self.metadata(runtime_file)
        self.assertNotEqual(self.run_helper("repair_runtime_permissions", runtime_file.parent).returncode, 0)
        self.assertEqual(self.metadata(runtime_file), before)

    def test_valid_runtime_permissions_and_symlink_target_unchanged(self):
        runtime = self.base / "runtime"
        script = self.file("runtime/worker.py", 0o600, self.account.pw_uid)
        data = self.file("runtime/nested/resource.json", 0o600, self.account.pw_uid)
        target = self.file("external-interpreter", 0o700)
        before = self.metadata(target)
        link = runtime / "piper/venv/bin/python"
        link.parent.mkdir(parents=True)
        link.symlink_to(target)
        os.chown(link, self.account.pw_uid, self.account.pw_gid, follow_symlinks=False)
        result = self.run_helper("repair_runtime_permissions", runtime)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.metadata(script)[:3], (0, 0, 0o755))
        self.assertEqual(self.metadata(data)[:3], (0, 0, 0o644))
        self.assertEqual(self.metadata(runtime)[:3], (0, 0, 0o755))
        self.assertEqual(self.metadata(target), before)
        self.assertEqual(link.lstat().st_uid, 0)
        self.assertEqual(script.read_bytes(), data.read_bytes())

    def test_runtime_rejects_hardlink_before_victim_chmod_chown(self):
        victim = self.file("victim", 0o600, self.account.pw_uid)
        runtime = self.base / "runtime"
        runtime.mkdir()
        os.link(victim, runtime / "worker.py")
        before = self.metadata(victim)
        self.assertNotEqual(self.run_helper("repair_runtime_permissions", runtime).returncode, 0)
        self.assertEqual(self.metadata(victim), before)

    def test_runtime_rejects_fifo_and_root_symlink(self):
        runtime = self.base / "runtime"
        runtime.mkdir()
        fifo = runtime / "unexpected"
        os.mkfifo(fifo, 0o600)
        self.assertNotEqual(self.run_helper("repair_runtime_permissions", runtime).returncode, 0)
        self.assertNotEqual(self.run_helper("repair_runtime_permissions", fifo).returncode, 0)
        fifo.unlink()
        link = self.base / "runtime-link"
        link.symlink_to(runtime, target_is_directory=True)
        self.assertNotEqual(self.run_helper("repair_runtime_permissions", link).returncode, 0)

    def test_replacement_with_fifo_or_different_inode_before_open_is_rejected(self):
        for name in ("secure_central_config", "repair_runtime_permissions"):
            for replacement in ("fifo", "regular"):
                with self.subTest(helper=name, replacement=replacement):
                    case = self.base / (name + "-" + replacement)
                    case.mkdir()
                    path = case / "target"
                    path.write_bytes(b"original")
                    path.chmod(0o600)
                    before = self.metadata(path)
                    injection = '''import os
real_open = os.open
def swapped_open(path, flags, *args, **kwargs):
    if path == "target" and not flags & os.O_DIRECTORY:
        parent = kwargs["dir_fd"]
        os.rename("target", "held", src_dir_fd=parent, dst_dir_fd=parent)
        if REPLACEMENT == "fifo":
            os.mkfifo("target", 0o600, dir_fd=parent)
        else:
            fd = real_open("target", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent)
            os.write(fd, b"replacement")
            os.close(fd)
    return real_open(path, flags, *args, **kwargs)
os.open = swapped_open
'''.replace("REPLACEMENT", repr(replacement))
                    result = self.run_helper(name, path if name == "secure_central_config" else case, injection)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertEqual(self.metadata(case / "held"), before)
                    self.assertEqual(stat.S_IMODE(path.lstat().st_mode), 0o600)


if __name__ == "__main__":
    unittest.main()
