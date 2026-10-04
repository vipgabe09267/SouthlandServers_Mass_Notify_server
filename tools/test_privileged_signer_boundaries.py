#!/usr/bin/env python3
"""Exercise root file safety and actual PHP privilege dropping in private fixtures."""
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'slsmassnotifyserver/bin/sign_sls_mass_notify_local_sig.sh').read_text()


def program(marker):
    match = re.search(r"<<'" + marker + r"'\n(.*?)\n" + marker, SOURCE, re.S)
    if not match: raise AssertionError('actual signer helper not found')
    return match.group(1)


class SignerBoundary(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-signer-boundary-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.account = pwd.getpwnam('asterisk')

    def run_code(self, marker, args):
        return subprocess.run(['/usr/bin/python3', '-I', '-', *map(str, args)], input=program(marker), text=True, capture_output=True, timeout=3)

    def test_account_home_matches_passwd_and_rejects_root_or_forged_metadata(self):
        home = Path(self.account.pw_dir)
        if home.is_dir():
            result = self.run_code('PYACCOUNT', ['asterisk', home, '/var/spool/asterisk', home / '.gnupg'])
            self.assertEqual(result.returncode, 0, result.stderr)
        for user, reported, gpg in [('root', '/root', '/root/.gnupg'), ('asterisk', '/etc', '/etc/.gnupg'), ('asterisk', str(home), '/root/.gnupg')]:
            with self.subTest(user=user, gpg=gpg):
                self.assertNotEqual(self.run_code('PYACCOUNT', [user, reported, '/var/spool/asterisk', gpg]).returncode, 0)

    def test_gpg_home_hardlink_never_mutates_victim(self):
        victim = self.base / 'root-file'; victim.write_bytes(b'private'); victim.chmod(0o600)
        keyring = self.base / 'keyring'; keyring.mkdir()
        os.link(victim, keyring / 'trustdb.gpg')
        before = victim.stat()
        self.assertNotEqual(self.run_code('PYHOME', [keyring, 'asterisk']).returncode, 0)
        after = victim.stat()
        self.assertEqual((before.st_uid, before.st_gid, before.st_mode), (after.st_uid, after.st_gid, after.st_mode))
        self.assertEqual(victim.read_bytes(), b'private')

    def test_gpg_home_rejects_fifo_and_symlink_without_following(self):
        victim = self.base / 'victim'; victim.write_bytes(b'private'); victim.chmod(0o600)
        keyring = self.base / 'keyring'; keyring.mkdir()
        for kind in ('fifo', 'symlink'):
            target = keyring / 'bad'
            if kind == 'fifo': os.mkfifo(target)
            else: target.symlink_to(victim)
            with self.subTest(kind=kind):
                self.assertNotEqual(self.run_code('PYHOME', [keyring, 'asterisk']).returncode, 0)
                self.assertEqual(victim.stat().st_uid, 0)
            target.unlink()
        linked_home = self.base / 'link'; linked_home.symlink_to(keyring, target_is_directory=True)
        self.assertNotEqual(self.run_code('PYHOME', [linked_home, 'asterisk']).returncode, 0)

    def test_gpg_home_repairs_regular_restored_files_and_preserves_agent_socket(self):
        keyring = self.base / 'keyring'; keyring.mkdir(mode=0o755)
        inner = keyring / 'private-keys-v1.d'; inner.mkdir()
        payload = inner / 'key'; payload.write_bytes(b'fixture key bytes'); payload.chmod(0o666)
        endpoint = socket.socket(socket.AF_UNIX)
        self.addCleanup(endpoint.close)
        path = keyring / 'S.gpg-agent'; endpoint.bind(str(path))
        os.chown(path, self.account.pw_uid, self.account.pw_gid)
        original = path.stat()
        result = self.run_code('PYHOME', [keyring, 'asterisk'])
        self.assertEqual(result.returncode, 0, result.stderr)
        for item, mode in ((keyring, 0o700), (inner, 0o700), (payload, 0o600)):
            self.assertEqual((item.stat().st_uid, stat.S_IMODE(item.stat().st_mode)), (self.account.pw_uid, mode))
        after = path.stat()
        self.assertEqual((original.st_ino, original.st_uid, original.st_mode), (after.st_ino, after.st_uid, after.st_mode))

    def test_real_dirmngr_socket_is_preserved_but_impostors_are_rejected(self):
        keyring = self.base / 'dirmngr-keyring'; keyring.mkdir(mode=0o700)
        for kind in ('legitimate', 'root_owned', 'wrong_group', 'unknown_name', 'nested', 'hardlink', 'symlink', 'fifo'):
            with self.subTest(kind=kind):
                parent = keyring
                if kind == 'nested':
                    parent = keyring / 'private-keys-v1.d'; parent.mkdir()
                path = parent / ('unknown.socket' if kind == 'unknown_name' else 'S.dirmngr')
                endpoint = None
                linked = keyring / 'socket-alias'
                if kind == 'symlink':
                    victim = self.base / 'socket-victim'; victim.write_bytes(b'unchanged')
                    path.symlink_to(victim)
                elif kind == 'fifo': os.mkfifo(path)
                else:
                    endpoint = socket.socket(socket.AF_UNIX); endpoint.bind(str(path))
                    os.chown(path, 0 if kind == 'root_owned' else self.account.pw_uid,
                             0 if kind == 'wrong_group' else self.account.pw_gid)
                    if kind == 'hardlink': os.link(path, linked)
                before = path.lstat()
                try:
                    result = self.run_code('PYHOME', [keyring, 'asterisk'])
                    self.assertEqual(result.returncode == 0, kind == 'legitimate', result.stderr)
                    after = path.lstat()
                    self.assertEqual((before.st_dev, before.st_ino, before.st_uid, before.st_gid, before.st_mode, before.st_nlink),
                                     (after.st_dev, after.st_ino, after.st_uid, after.st_gid, after.st_mode, after.st_nlink))
                    if kind == 'symlink': self.assertEqual(victim.read_bytes(), b'unchanged')
                finally:
                    if endpoint is not None: endpoint.close()
                    path.unlink()
                    if linked.exists(): linked.unlink()
                    if parent != keyring: parent.rmdir()

    def test_actual_metadata_and_signature_php_commands_drop_root(self):
        # Insert an immediate identity probe before the actual PHP bodies can
        # bootstrap anything. The actual shell/runuser command remains intact.
        source = SOURCE.replace('$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];', 'fwrite(STDERR, "FIXTURE_UID=" . posix_geteuid() . "\\n"); exit(37);')
        candidate = self.base / 'signer.sh'; candidate.write_text(source)
        for method in ('load_freepbx_metadata', 'verify_published_signature'):
            work = self.base / method; work.mkdir()
            result = subprocess.run(['/bin/bash', '-c', 'source "$1"; WORKDIR="$2"; FREEPBX_WEB_USER=asterisk; MODULE=dashboard; "$3"', '_', str(candidate), str(work), method], capture_output=True, text=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('FIXTURE_UID=' + str(self.account.pw_uid), result.stderr)
            self.assertNotIn('FIXTURE_UID=0\n', result.stderr)

    def test_actual_uninstaller_php_and_fwconsole_commands_drop_root(self):
        source=(ROOT/'slsmassnotifyserver/bin/sls_mass_notify_uninstall.sh').read_text()
        probe='file_put_contents("php://stderr", "FIXTURE_UID=" . posix_geteuid() . "\\n"); exit(37);'
        # Stop each actual PHP body before any PBX bootstrap or other operation.
        source=source.replace('require "/etc/freepbx.conf";',probe).replace("require '/etc/freepbx.conf';",probe)
        source=source.replace('/usr/sbin/fwconsole "$@"', "-r '"+probe+"'")
        candidate=self.base/'uninstall.sh';candidate.write_text(source)
        for method in ('module_registry_exists','remove_freepbx_manager_users','remove_bundled_system_recordings','verify_stock_module','refresh_dashboard_hook_index','run_fwconsole'):
            with self.subTest(method=method):
                result=subprocess.run(['/bin/bash','-c','source "$1"; "$2" dashboard','_',str(candidate),method],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,37,result.stderr)
                self.assertIn('FIXTURE_UID='+str(self.account.pw_uid),result.stderr)
                self.assertNotIn('FIXTURE_UID=0\n',result.stderr)


if __name__ == '__main__':
    unittest.main()
