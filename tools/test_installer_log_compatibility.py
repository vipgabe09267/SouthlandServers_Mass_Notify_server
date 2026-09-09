#!/usr/bin/env python3
"""Exercise installer log adoption without running a PBX installation."""

import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = Path(os.environ.get(
    "SLS_INSTALLER_UNDER_TEST", ROOT / "tools/install_release.sh"
)).resolve()
SOURCE = INSTALLER.read_text(encoding="utf-8")


def source_shell(body, *arguments):
    # Sourcing defines helpers but must not run main. Fixtures never inherit a
    # production log, module, credential, or installer override from the caller.
    environment = {
        key: value for key, value in os.environ.items()
        if not key.startswith("SLS_") and key not in {"GITHUB_TOKEN", "BASH_ENV", "ENV"}
    }
    return subprocess.run(
        ["/bin/bash", "--noprofile", "--norc", "-c",
         'source "$1"\nshift\n' + body, "installer-log-fixture",
         str(INSTALLER), *map(str, arguments)],
        env=environment, cwd="/", capture_output=True, text=True, timeout=10,
        check=False,
    )


class InstallerLogSourceContract(unittest.TestCase):
    def test_source_guard_prevents_automatic_installation(self):
        self.assertIn('if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then', SOURCE)

    def test_main_opens_log_with_explicit_log_purpose(self):
        main = SOURCE.split("\nmain() {", 1)[1]
        self.assertIn('open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log', main)
        self.assertIn('INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$INSTALL_LOG_FD"', main)
        self.assertIn(': >"$INSTALL_LOG_OUTPUT"', main)
        self.assertLess(main.index("open_root_owned_file"), main.index("require_freepbx"))

    def test_log_redirects_do_not_reopen_original_path(self):
        # The fallback supports sourced helper fixtures. During main every
        # command must use the already-open descriptor, even after fwconsole
        # changes the pathname's ownership in sticky /tmp.
        unsafe = re.findall(r'(?:[12]?>{1,2})\s*"\$(?:LOG_FILE|\{LOG_FILE\})"', SOURCE)
        self.assertEqual(unsafe, [], "A log write reopened the untrusted pathname")
        self.assertIn('>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"', SOURCE)

    def test_shell_syntax(self):
        result = subprocess.run(
            ["/bin/bash", "-n", str(INSTALLER)],
            capture_output=True, text=True, timeout=10, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)


@unittest.skipUnless(os.geteuid() == 0, "Ownership compatibility fixtures require root")
class InstallerLogCompatibility(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            cls.asterisk = pwd.getpwnam("asterisk")
            cls.untrusted = pwd.getpwnam("nobody")
        except KeyError as error:
            raise unittest.SkipTest("Asterisk and nobody fixture accounts are required") from error
        cls.protected_regular = int(Path("/proc/sys/fs/protected_regular").read_text().strip())

    def setUp(self):
        # The parent is root-only; its sticky child recreates /tmp policy
        # without placing predictable test files into the real /tmp namespace.
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-installer-log-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.root.chmod(0o700)
        self.sticky = self.root / "sticky"
        self.sticky.mkdir(mode=0o700)
        self.sticky.chmod(0o1777)

    def make_file(self, name="install.log", owner=0, mode=0o600, parent=None):
        path = (parent or self.sticky) / name
        path.write_bytes(b"existing diagnostic\n")
        os.chown(path, owner, owner)
        path.chmod(mode)
        return path

    @staticmethod
    def identity(path):
        metadata = path.lstat()
        return (metadata.st_dev, metadata.st_ino, metadata.st_uid,
                metadata.st_gid, metadata.st_mode, metadata.st_nlink)

    def open_and_append(self, path, purpose="log"):
        return source_shell(
            'fixture_fd=""\n'
            'open_root_owned_file fixture_fd "$1" "$2"\n'
            'printf "appended diagnostic\\n" >&"$fixture_fd"\n',
            path, purpose,
        )

    def assert_refused_unchanged(self, path, purpose="log", sentinel=None):
        before = self.identity(path)
        regular = stat.S_ISREG(path.lstat().st_mode)
        contents = path.read_bytes() if regular else None
        sentinel_before = sentinel.read_bytes() if sentinel else None
        result = self.open_and_append(path, purpose)
        self.assertNotEqual(result.returncode, 0, "Unsafe path was accepted: " + str(path))
        self.assertEqual(self.identity(path), before)
        if regular:
            self.assertEqual(path.read_bytes(), contents)
        if sentinel:
            self.assertEqual(sentinel.read_bytes(), sentinel_before)

    def test_fresh_log_is_private_root_owned(self):
        path = self.sticky / "fresh.log"
        result = self.open_and_append(path)
        self.assertEqual(result.returncode, 0, result.stderr)
        metadata = path.stat()
        self.assertEqual((metadata.st_uid, metadata.st_gid), (0, 0))
        self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o600)
        self.assertEqual(path.read_bytes(), b"appended diagnostic\n")

    def test_existing_root_and_service_logs_preserve_contents_and_inode(self):
        for owner in (0, self.asterisk.pw_uid):
            for mode in (0o600, 0o640, 0o664):
                with self.subTest(owner=owner, mode=oct(mode)):
                    path = self.make_file(f"owner-{owner}-{mode}.log", owner, mode)
                    inode = path.stat().st_ino
                    result = self.open_and_append(path)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    metadata = path.stat()
                    self.assertEqual(metadata.st_ino, inode)
                    self.assertEqual((metadata.st_uid, metadata.st_gid), (0, 0))
                    self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o600)
                    self.assertEqual(path.read_bytes(), b"existing diagnostic\nappended diagnostic\n")

    def test_kernel_reproduces_legacy_root_redirection_denial(self):
        if self.protected_regular != 2:
            self.skipTest("Exact reported kernel policy requires fs.protected_regular=2")
        path = self.make_file(owner=self.asterisk.pw_uid, mode=0o664)
        result = subprocess.run(
            ["/bin/bash", "--noprofile", "--norc", "-c", ': >>"$1"', "fixture", str(path)],
            capture_output=True, text=True, timeout=5, check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Permission denied", result.stderr)
        self.assertEqual(path.read_bytes(), b"existing diagnostic\n")

    def test_descriptor_writes_survive_simulated_freepbx_chown(self):
        path = self.make_file()
        result = source_shell(
            'open_root_owned_file fixture_fd "$1" log\n'
            'INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$fixture_fd"\n'
            'chown "$2:$3" "$1"\n'
            'chmod 0664 "$1"\n'
            'printf "parent write\\n" >>"$INSTALL_LOG_OUTPUT"\n'
            '/bin/bash --noprofile --norc -c '
            '\'printf "child write\\n" >>"$1"\' fixture "$INSTALL_LOG_OUTPUT"\n',
            path, self.asterisk.pw_uid, self.asterisk.pw_gid,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(path.read_bytes(), b"existing diagnostic\nparent write\nchild write\n")

    def test_held_descriptor_cannot_be_redirected_by_path_replacement(self):
        path = self.make_file()
        held = self.sticky / "held.log"
        sentinel = self.make_file("sentinel")
        result = source_shell(
            'open_root_owned_file fixture_fd "$1" log\n'
            'INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$fixture_fd"\n'
            'mv -- "$1" "$2"\n'
            'ln -s -- "$3" "$1"\n'
            'printf "held write\\n" >>"$INSTALL_LOG_OUTPUT"\n',
            path, held, sentinel,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(held.read_bytes(), b"existing diagnostic\nheld write\n")
        self.assertEqual(sentinel.read_bytes(), b"existing diagnostic\n")

    def test_lock_ownership_remains_strict(self):
        self.assert_refused_unchanged(self.make_file("service.lock", self.asterisk.pw_uid), "lock")
        self.assert_refused_unchanged(self.make_file("writable.lock", 0, 0o664), "lock")
        root_lock = self.make_file("root.lock")
        self.assertEqual(self.open_and_append(root_lock, "lock").returncode, 0)

    def test_default_helper_purpose_does_not_adopt_service_lock(self):
        path = self.make_file("implicit.lock", self.asterisk.pw_uid)
        before = self.identity(path)
        result = source_shell('open_root_owned_file fixture_fd "$1"\n', path)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.identity(path), before)

    def test_links_and_special_files_are_rejected_without_writing(self):
        sentinel = self.make_file("sentinel")
        symlink = self.sticky / "symlink.log"
        symlink.symlink_to(sentinel)
        hardlink = self.sticky / "hardlink.log"
        os.link(sentinel, hardlink)
        fifo = self.sticky / "fifo.log"
        os.mkfifo(fifo, 0o600)
        directory = self.sticky / "directory.log"
        directory.mkdir(mode=0o700)
        for path in (symlink, hardlink, fifo, directory):
            with self.subTest(kind=path.name):
                self.assert_refused_unchanged(path, sentinel=sentinel)

    def test_untrusted_owner_is_not_adopted(self):
        path = self.make_file("untrusted.log", self.untrusted.pw_uid, 0o664)
        self.assert_refused_unchanged(path)

    def test_unsafe_parents_are_not_followed_or_repaired(self):
        for label, owner, mode in (
            ("world-writable", 0, 0o777),
            ("group-writable", 0, 0o775),
            ("service-owned", self.asterisk.pw_uid, 0o755),
        ):
            with self.subTest(parent=label):
                parent = self.root / label
                parent.mkdir(mode=0o700)
                os.chown(parent, owner, owner)
                parent.chmod(mode)
                before = self.identity(parent)
                path = self.make_file(parent=parent)
                self.assert_refused_unchanged(path)
                self.assertEqual(self.identity(parent), before)
        linked_parent = self.root / "linked-parent"
        linked_parent.symlink_to(self.sticky, target_is_directory=True)
        actual = self.make_file("behind-parent.log")
        self.assert_refused_unchanged(linked_parent / actual.name)

    def test_invalid_purpose_does_not_create_file(self):
        path = self.sticky / "invalid-purpose.log"
        result = self.open_and_append(path, "anything")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(path.exists())

    def test_main_log_preflight_stops_before_all_real_installation(self):
        path = self.make_file(owner=self.asterisk.pw_uid, mode=0o664)
        # This is the real main log initialization only. The first platform
        # function is replaced with a fixture and exits before any PBX, package,
        # network, config, maintenance lock, or Dashboard operation can run.
        result = source_shell(
            'LOG_FILE="$1"\n'
            'fixture_owner="$2:$3"\n'
            'guard_config_on_exit() { :; }\n'
            'require_freepbx() {\n'
            '  printf "preflight fixture\\n" >>"$INSTALL_LOG_OUTPUT"\n'
            '  chown "$fixture_owner" "$LOG_FILE"\n'
            '  printf "after chown\\n" >>"$INSTALL_LOG_OUTPUT"\n'
            '  exit 79\n'
            '}\n'
            'main\n',
            path, self.asterisk.pw_uid, self.asterisk.pw_gid,
        )
        self.assertEqual(result.returncode, 79, result.stderr)
        self.assertEqual(path.read_bytes(), b"preflight fixture\nafter chown\n")


if __name__ == "__main__":
    unittest.main(verbosity=2)
