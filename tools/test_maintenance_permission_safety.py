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
RUNTIME_LOG_SOURCES = {
    "updater": (ROOT / "slsmassnotifyserver/bin/sls_mass_notify_update.sh").read_text(),
    "piper": (ROOT / "slsmassnotifyserver/bin/sls_mass_notify_install_piper_voices.sh").read_text(),
}


def helper(name):
    block = SOURCE.split(name + "() {", 1)[1]
    match = re.search(r"<<'PY'[^\n]*\n(.*?)\nPY\n", block, re.S)
    if not match:
        raise AssertionError("actual maintenance helper missing: " + name)
    return match.group(1)


def tagged_helper(tag):
    match = re.search(r"<<'" + re.escape(tag) + r"'[^\n]*\n(.*?)\n" + re.escape(tag) + r"\n", SOURCE, re.S)
    if not match:
        raise AssertionError("actual maintenance helper missing: " + tag)
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
            os.chown(path, owner, self.account.pw_gid if owner==self.account.pw_uid else owner)
        return path

    def metadata(self, path):
        info = path.lstat()
        return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_ino

    def run_log_open(self, path):
        definitions = SOURCE[:SOURCE.index('[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1')]
        script = definitions + '\nopen_maintenance_log LOG_FD "$1"\nprintf pinned-log-write >&"$LOG_FD"\n'
        return subprocess.run(['/bin/bash', '-c', script, '_', str(path)],
                              capture_output=True, text=True, timeout=3)

    def test_maintenance_log_is_pinned_and_unsafe_entries_are_preserved(self):
        valid = self.file('maintenance.log', 0o640, self.account.pw_uid)
        before = valid.read_bytes()
        result = self.run_log_open(valid)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(valid.read_bytes(), before + b'pinned-log-write')

        for case in ('symlink', 'hardlink', 'fifo', 'foreign-owner', 'group-writable'):
            with self.subTest(case=case):
                directory = self.base / ('log-' + case)
                directory.mkdir()
                path = directory / 'maintenance.log'
                victim = self.file('log-' + case + '-victim', 0o600)
                if case == 'symlink':
                    path.symlink_to(victim)
                elif case == 'hardlink':
                    os.link(victim, path)
                elif case == 'fifo':
                    os.mkfifo(path, 0o600)
                else:
                    path.write_bytes(b'preserved log')
                    path.chmod(0o660 if case == 'group-writable' else 0o640)
                    if case == 'foreign-owner':
                        foreign = pwd.getpwnam('nobody')
                        os.chown(path, foreign.pw_uid, foreign.pw_gid)
                victim_before = victim.read_bytes()
                path_before = path.lstat()
                result = self.run_log_open(path)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(victim.read_bytes(), victim_before)
                path_after = path.lstat()
                self.assertEqual((path_after.st_dev, path_after.st_ino, path_after.st_mode),
                                 (path_before.st_dev, path_before.st_ino, path_before.st_mode))

    def test_maintenance_has_no_privileged_path_based_log_append(self):
        self.assertNotRegex(SOURCE, r'(?:^|\s)(?:2?>{1,2})\s*"?\$LOG_FILE',
                            'maintenance must append only through its verified log descriptor')

    def test_runtime_log_openers_pin_valid_files_and_reject_hostile_entries(self):
        for label, source in RUNTIME_LOG_SOURCES.items():
            start = source.index('open_append_log() {')
            end = source.index('\nlog() {', start)
            definition = source[start:end]
            script = ('set -euo pipefail\n' + definition
                      + '\nLOG_FD=""\nopen_append_log LOG_FD "$1"\nprintf runtime-log-write >&"$LOG_FD"\n')
            valid = self.file(label + '-valid.log', 0o640, self.account.pw_uid)
            before = valid.read_bytes()
            result = subprocess.run(['/bin/bash', '-c', script, '_', str(valid)],
                                    capture_output=True, text=True, timeout=3)
            self.assertEqual(result.returncode, 0, label + ': ' + result.stderr)
            self.assertEqual(valid.read_bytes(), before + b'runtime-log-write')
            for case in ('symlink', 'hardlink', 'fifo', 'foreign-owner', 'group-writable'):
                with self.subTest(script=label, case=case):
                    directory = self.base / (label + '-' + case)
                    directory.mkdir()
                    path = directory / 'runtime.log'
                    victim = self.file(label + '-' + case + '-victim', 0o600)
                    if case == 'symlink': path.symlink_to(victim)
                    elif case == 'hardlink': os.link(victim, path)
                    elif case == 'fifo': os.mkfifo(path, 0o600)
                    else:
                        path.write_bytes(b'preserved runtime log')
                        path.chmod(0o660 if case == 'group-writable' else 0o640)
                        if case == 'foreign-owner':
                            foreign = pwd.getpwnam('nobody')
                            os.chown(path, foreign.pw_uid, foreign.pw_gid)
                    victim_before = victim.read_bytes()
                    entry_before = path.lstat()
                    result = subprocess.run(['/bin/bash', '-c', script, '_', str(path)],
                                            capture_output=True, text=True, timeout=3)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertEqual(victim.read_bytes(), victim_before)
                    entry_after = path.lstat()
                    self.assertEqual((entry_after.st_dev, entry_after.st_ino, entry_after.st_mode),
                                     (entry_before.st_dev, entry_before.st_ino, entry_before.st_mode))

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
                path = self.file("config", mode=0o644, owner=owner)
                before = path.read_bytes()
                result = self.run_helper("secure_central_config", path)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(path.read_bytes(), before)
                self.assertEqual(self.metadata(path)[:3], (self.account.pw_uid, self.account.pw_gid, 0o640))

    def test_unknown_central_config_modes_groups_and_size_are_preserved(self):
        for case in ('group_write','public_write','special_mode','foreign_group','oversized'):
            with self.subTest(case=case):
                path=self.file('config-'+case,mode=0o640)
                if case=='group_write':path.chmod(0o660)
                elif case=='public_write':path.chmod(0o666)
                elif case=='special_mode':path.chmod(0o4640)
                elif case=='foreign_group':os.chown(path,0,65000)
                else:
                    with path.open('r+b') as handle:handle.truncate(3*1024*1024+1)
                before=self.metadata(path);raw=path.read_bytes()
                self.assertNotEqual(self.run_helper('secure_central_config',path).returncode,0)
                self.assertEqual(self.metadata(path),before);self.assertEqual(path.read_bytes(),raw)

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

    def test_unexpected_config_ownership_is_rejected(self):
        foreign_uid = pwd.getpwnam("nobody").pw_uid
        config = self.file("config", owner=foreign_uid)
        before = self.metadata(config)
        self.assertNotEqual(self.run_helper("secure_central_config", config).returncode, 0)
        self.assertEqual(self.metadata(config), before)

    def test_replacement_with_fifo_or_different_inode_before_open_is_rejected(self):
        for replacement in ("fifo", "regular"):
            with self.subTest(replacement=replacement):
                case = self.base / ("secure_central_config-" + replacement)
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
                result = self.run_helper("secure_central_config", path, injection)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(self.metadata(case / "held"), before)
                self.assertEqual(stat.S_IMODE(path.lstat().st_mode), 0o600)

    def run_request_claim(self, path, kind, injection=""):
        source = tagged_helper("PYREQUEST")
        if injection:
            source = injection + "\nexec(compile(" + repr(source) + ", '<request fixture>', 'exec'))\n"
        return subprocess.run(["/usr/bin/python3", "-I", "-", str(path), kind], input=source,
                              text=True, capture_output=True, timeout=3)

    def run_request_finish(self, operation, claim, original="", injection=""):
        source = tagged_helper("PYREQUESTFINISH")
        if injection:
            source = injection + "\nexec(compile(" + repr(source) + ", '<request-finish fixture>', 'exec'))\n"
        return subprocess.run(["/usr/bin/python3", "-I", "-", operation, str(claim), str(original)],
                              input=source, text=True,
                              capture_output=True, timeout=3)

    def test_request_claim_consumes_only_valid_private_single_link_marker(self):
        marker = self.file("update.request", 0o640, self.account.pw_uid)
        marker.write_text("2026-10-16T00:00:00+00:00\ncheck-only\n")
        result = self.run_request_claim(marker, "update")
        self.assertEqual(result.returncode, 0, result.stderr)
        claim_text, mode = result.stdout.strip().split("\t")
        claim = Path(claim_text)
        self.assertFalse(marker.exists())
        self.assertEqual(mode, "check-only")
        self.assertEqual(self.metadata(claim)[:3], (0, 0, 0o600))
        self.assertEqual(self.run_request_finish("consume", claim).returncode, 0)
        self.assertFalse(claim.exists())

    def test_request_claim_restores_timezone_marker_privately(self):
        marker = self.file("timezone.request", 0o640, self.account.pw_uid)
        marker.write_bytes(b"1\n")
        result = self.run_request_claim(marker, "timezone")
        self.assertEqual(result.returncode, 0, result.stderr)
        claim = Path(result.stdout.strip().split("\t")[0])
        restored = self.run_request_finish("restore", claim, marker)
        self.assertEqual(restored.returncode, 0, restored.stderr)
        self.assertEqual(marker.read_bytes(), b"1\n")
        self.assertEqual(self.metadata(marker)[:3],
                         (self.account.pw_uid, self.account.pw_gid, 0o640))

    def test_request_restore_never_overwrites_a_newer_marker(self):
        marker = self.file("timezone-newer.request", 0o640, self.account.pw_uid)
        marker.write_bytes(b"1\n")
        result = self.run_request_claim(marker, "timezone")
        self.assertEqual(result.returncode, 0, result.stderr)
        claim = Path(result.stdout.strip().split("\t")[0])
        marker.write_bytes(b"newer request remains\n")
        marker.chmod(0o640)
        before = self.metadata(marker)
        restored = self.run_request_finish("restore", claim, marker)
        self.assertNotEqual(restored.returncode, 0)
        self.assertEqual(marker.read_bytes(), b"newer request remains\n")
        self.assertEqual(self.metadata(marker), before)
        self.assertEqual(self.metadata(claim)[:3], (0, 0, 0o600))

    def test_request_restore_preparation_failure_preserves_the_private_claim(self):
        marker = self.file("timezone-prepare-failure.request", 0o640, self.account.pw_uid)
        marker.write_bytes(b"1\n")
        result = self.run_request_claim(marker, "timezone")
        self.assertEqual(result.returncode, 0, result.stderr)
        claim = Path(result.stdout.strip().split("\t")[0])
        restored = self.run_request_finish(
            "restore", claim, marker,
            "import os\nreal_fchown = os.fchown\ndef fail_fchown(fd, uid, gid):\n    raise OSError('injected ownership failure')\nos.fchown = fail_fchown",
        )
        self.assertNotEqual(restored.returncode, 0)
        self.assertFalse(marker.exists())
        self.assertEqual(claim.read_bytes(), b"1\n")
        self.assertEqual(self.metadata(claim)[:3], (0, 0, 0o600))
        self.assertEqual(list(marker.parent.glob('.sls-maintenance-restore-*')), [])

    def test_request_claim_rejects_and_preserves_unsafe_or_invalid_markers(self):
        for case in ("invalid", "writable", "hardlink", "symlink", "oversized"):
            with self.subTest(case=case):
                directory = self.base / ("request-" + case)
                directory.mkdir()
                marker = directory / "repair.request"
                if case == "symlink":
                    victim = self.file("request-" + case + "-victim", 0o600)
                    marker.symlink_to(victim)
                else:
                    marker.write_text("2026-10-16T00:00:00+00:00\n")
                    marker.chmod(0o640)
                    if case == "invalid": marker.write_text("not a request\n")
                    elif case == "writable": marker.chmod(0o660)
                    elif case == "hardlink":
                        victim = directory / "victim"
                        marker.rename(victim)
                        os.link(victim, marker)
                    elif case == "oversized": marker.write_bytes(b"x" * 1025)
                before = marker.lstat()
                result = self.run_request_claim(marker, "repair")
                self.assertEqual(result.returncode, 2)
                after = marker.lstat()
                self.assertEqual((after.st_dev, after.st_ino), (before.st_dev, before.st_ino))

    def test_request_claim_detects_replacement_during_atomic_claim(self):
        marker = self.file("raced.request", 0o640, self.account.pw_uid)
        marker.write_text("2026-10-16T00:00:00+00:00\n")
        before = self.metadata(marker)
        injection = r'''import os
real_rename = os.rename
def raced_rename(source, destination, *args, **kwargs):
    source_parent = kwargs['src_dir_fd']
    real_rename(source, 'held-request', src_dir_fd=source_parent, dst_dir_fd=source_parent)
    descriptor = os.open(source, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=source_parent)
    os.write(descriptor, b'2026-10-16T00:00:00+00:00\n')
    os.close(descriptor)
    return real_rename(source, destination, *args, **kwargs)
os.rename = raced_rename'''
        result = self.run_request_claim(marker, "repair", injection)
        self.assertEqual(result.returncode, 2)
        held = marker.parent / "held-request"
        self.assertEqual(self.metadata(held), before)

    def test_request_claim_restores_the_public_marker_if_securing_fails(self):
        marker = self.file("claim-secure-failure.request", 0o640, self.account.pw_uid)
        marker.write_text("2026-10-16T00:00:00+00:00\n")
        before_body = marker.read_bytes()
        injection = r'''import os
def fail_fchown(fd, uid, gid):
    raise OSError('injected ownership failure')
os.fchown = fail_fchown'''
        result = self.run_request_claim(marker, "repair", injection)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(marker.read_bytes(), before_body)
        self.assertEqual(self.metadata(marker)[:3],
                         (self.account.pw_uid, self.account.pw_gid, 0o640))
        self.assertEqual(list(marker.parent.glob('.sls-maintenance-claim-*')), [])


if __name__ == "__main__":
    unittest.main()
