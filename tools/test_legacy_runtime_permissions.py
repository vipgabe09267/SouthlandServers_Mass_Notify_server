#!/usr/bin/python3
"""Private filesystem fixtures only; never run FreePBX chown or touch PBX data."""
import fcntl
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
import types
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_legacy_permissions as recovery
import sls_config_crypto as crypto


@unittest.skipUnless(os.geteuid() == 0, 'Root is required to exercise ownership preservation in isolated fixtures')
class PermissionRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-legacy-permissions-')
        self.root = Path(self.temp.name)
        self.account = types.SimpleNamespace(pw_uid=12345, pw_gid=12345)
        self.lookup = mock.patch.object(recovery.pwd, 'getpwnam', return_value=self.account)
        self.lookup.start()
        self.approval = self.root / 'previous.json'
        self.write_approval()
        self.data = self.root / 'data'
        self.data.mkdir()
        os.chown(self.data, self.account.pw_uid, self.account.pw_gid)
        self.data.chmod(0o755)
        self.config = self.root / 'freepbx_chown.conf'
        self.snapshot = self.root / 'chown-snapshot.json'
        self.state = {'schema': 1, 'collector': None, 'batches': {}}
        self.ring = {'format': crypto.KEYRING_FORMAT, 'active': 'a' * 32,
            'keys': {'a' * 32: {'created_at': 1000, 'key': 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='}}}
        self.keyring = self.root / 'config-keys.json'; self.keyring.write_text(json.dumps(self.ring)); self.keyring.chmod(0o640)
        self.keypath = mock.patch.object(recovery, 'CONFIG_KEYRING', self.keyring); self.keypath.start()
        self.populate()

    def tearDown(self):
        self.lookup.stop()
        self.keypath.stop()
        self.temp.cleanup()

    def write_approval(self, fresh=False):
        value = {'schema': 1, 'source': 'fresh-install', 'files': {}} if fresh else {
            'schema': 1, 'source': 'publisher-verified-previous-release', 'version': '0.1.5-beta',
            'archive_sha256': 'a' * 64, 'files': {'/usr/local/bin/sls_mass_notify/known.py': 'b' * 64}}
        self.approval.write_text(json.dumps(value)); self.approval.chmod(0o600)

    def file(self, name, body=b'', mode=0o644):
        path = self.data / name
        path.write_bytes(body); os.chown(path, self.account.pw_uid, self.account.pw_gid); path.chmod(mode)
        return path

    def populate(self):
        self.file('enterprise-worker.lock')
        self.file('phone-admission.lock')
        self.file('phone-admission.json', json.dumps(self.state, sort_keys=True).encode())

    def metadata(self):
        result = {}
        for path in [self.data, *(self.data / name for name in recovery.NAMES)]:
            if not path.exists(): continue
            info = path.stat()
            result[path.name] = (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
                stat.S_IMODE(info.st_mode), hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None)
        return result

    def protect(self):
        return recovery.protect_chown(self.config, self.snapshot, self.approval)

    def config_body(self, body=b'; administrator configuration\n[custom]\nrdir=/srv/pbx,root,asterisk,0770\n', mode=0o664):
        self.config.write_bytes(body)
        os.chown(self.config, self.account.pw_uid, self.account.pw_gid); self.config.chmod(mode)
        return body

    def test_recognized_legacy_metadata_only_and_idempotent(self):
        unrelated = self.file('do-not-adopt.json', b'unreviewed')
        before = self.metadata()
        result = recovery.repair(self.data, self.approval)
        self.assertEqual(set(result['repaired_files']), {'enterprise-worker.lock', 'phone-admission.lock', 'phone-admission.json', '.'})
        for name, previous in before.items():
            current = self.metadata()[name]
            self.assertEqual(current[:4], previous[:4]); self.assertEqual(current[5], previous[5])
            self.assertEqual(current[4], 0o750 if name == 'data' else 0o640)
        self.assertEqual(stat.S_IMODE(unrelated.stat().st_mode), 0o644)
        self.assertEqual(recovery.repair(self.data, self.approval)['repaired_files'], [])

    def test_historical_admitted_batches_preserve_bytes_and_inodes(self):
        token = 'a' * 32
        state = {'schema': 1, 'collector': {'generation': 'b' * 32, 'boot_id': 'fixture-boot', 'heartbeat_tick': 101},
            'batches': {token: {'service': 'live', 'status': 'closed', 'generation': 'b' * 32, 'boot_id': 'fixture-boot',
                **{name: 100 for name in ('created_at', 'updated_at', 'start_deadline', 'created_tick', 'updated_tick', 'start_deadline_tick')},
                'targets': {'1000': {'dials': ['PJSIP/1000/sip:desk@192.0.2.10']}},
                'channels': {'fixture.1': {'kind': 'origin', 'recipient': '1000', 'channel': 'Local/1000@sls-live-page-000001;2'}}}}}
        path = self.file('phone-admission.json', json.dumps(state, indent=2).encode())
        body = path.read_bytes(); inode = path.stat().st_ino
        recovery.repair(self.data, self.approval)
        self.assertEqual(path.read_bytes(), body); self.assertEqual(path.stat().st_ino, inode)

    def add_configs(self):
        self.file('announcement-activity.lock'); self.file('mass-notifications.config.lock')
        for name in recovery.CONFIG_NAMES:
            self.file(name, crypto.encode_with_keyring({'configured': True, 'audience': ['1000'], 'pending': 'pending' in name}, self.ring))

    def test_015_upgrade_preserves_active_pending_ciphertext_and_keys(self):
        self.add_configs(); before = self.metadata(); ring = self.keyring.read_bytes(); ring_inode = self.keyring.stat().st_ino
        encrypted = {name: (self.data / name).read_bytes() for name in recovery.CONFIG_NAMES}
        result = recovery.repair(self.data, self.approval)
        self.assertEqual(set(result['repaired_files']), {*recovery.NAMES, '.'})
        for name, previous in before.items():
            current = self.metadata()[name]
            self.assertEqual(current[:4], previous[:4]); self.assertEqual(current[5], previous[5])
        for name, body in encrypted.items():
            self.assertEqual((self.data / name).read_bytes(), body)
            self.assertEqual(json.loads(body)['key_id'], self.ring['active'])
        self.assertEqual(self.keyring.read_bytes(), ring); self.assertEqual(self.keyring.stat().st_ino, ring_inode)

    def test_corrupt_pending_ciphertext_key_ids_and_unsafe_keyrings_are_atomic_refusals(self):
        self.add_configs(); original = (self.data / recovery.CONFIG_NAMES[1]).read_bytes()
        for kind in ('ciphertext', 'keyid', 'keymode', 'keyowner', 'keylink'):
            with self.subTest(kind=kind):
                self.file(recovery.CONFIG_NAMES[1], original)
                self.keyring.write_text(json.dumps(self.ring)); os.chown(self.keyring, 0, 0); self.keyring.chmod(0o640)
                if kind in ('ciphertext', 'keyid'):
                    value = json.loads(original)
                    if kind == 'keyid': value['key_id'] = 'b' * 32
                    else:
                        raw = bytearray(base64.b64decode(value['ciphertext'])); raw[-1] ^= 1
                        value['ciphertext'] = base64.b64encode(raw).decode()
                    self.file(recovery.CONFIG_NAMES[1], json.dumps(value).encode())
                elif kind == 'keymode': self.keyring.chmod(0o644)
                elif kind == 'keyowner': os.chown(self.keyring, self.account.pw_uid, self.account.pw_gid)
                else: os.link(self.keyring, self.root / 'key-extra-link')
                before = self.metadata()
                with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'authenticated'): recovery.repair(self.data, self.approval)
                self.assertEqual(self.metadata(), before)

    def test_legacy_plaintext_config_is_validated_without_key_creation(self):
        self.file('mass-notifications.config.lock'); self.keyring.unlink()
        body = b'{"configured":true,"desktop_limit":25}\n'
        target = self.file('mass-notifications.config', body); inode = target.stat().st_ino
        recovery.repair(self.data, self.approval)
        self.assertEqual(target.read_bytes(), body); self.assertEqual(target.stat().st_ino, inode)
        self.assertFalse(self.keyring.exists())

    def test_unsafe_or_unlocked_central_config_is_not_adopted(self):
        self.add_configs(); source = (self.data / recovery.CONFIG_NAMES[0]).read_bytes()
        for kind in ('owner', 'symlink', 'missinglock', 'duplicate', 'invalidenvelope'):
            with self.subTest(kind=kind):
                path = self.data / recovery.CONFIG_NAMES[0]; path.unlink()
                self.file('mass-notifications.config.lock')
                path = self.file(recovery.CONFIG_NAMES[0], source)
                if kind == 'owner': os.chown(path, 0, self.account.pw_gid)
                elif kind == 'symlink': path.unlink(); path.symlink_to(self.keyring)
                elif kind == 'missinglock': (self.data / 'mass-notifications.config.lock').unlink()
                elif kind == 'duplicate': path.write_bytes(b'{"configured":true,"configured":false}')
                else: path.write_bytes(b'{"format":"unsupported","configured":true}')
                modes = {name: stat.S_IMODE((self.data / name).lstat().st_mode) for name in recovery.NAMES if (self.data / name).exists()}
                with self.assertRaises((OSError, recovery.PermissionRecoveryError)): recovery.repair(self.data, self.approval)
                self.assertEqual({name: stat.S_IMODE((self.data / name).lstat().st_mode) for name in modes}, modes)

    def test_malformed_journal_never_partially_tightens_locks(self):
        for body in (b'{', b'{"schema":1,"schema":1,"batches":{}}',
                     b'{"schema":true,"batches":{}}', b'{"schema":1,"batches":{},"collector":NaN}',
                     b'{"schema":1,"batches":[]}'):
            with self.subTest(body=body):
                self.file('phone-admission.json', body)
                before = self.metadata()
                with self.assertRaises(recovery.PermissionRecoveryError): recovery.repair(self.data, self.approval)
                self.assertEqual(self.metadata(), before)

    def test_fresh_approval_cannot_adopt_legacy_modes(self):
        self.write_approval(fresh=True)
        before = self.metadata()
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'previous-release'): recovery.repair(self.data, self.approval)
        self.assertEqual(self.metadata(), before)
        self.data.chmod(0o750)
        before = self.metadata()
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'previous-release'): recovery.repair(self.data, self.approval)
        self.assertEqual(self.metadata(), before)

    def test_fresh_absent_directory_is_safe_noop_and_previous_absence_is_error(self):
        shutil.rmtree(self.data)
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'missing'): recovery.repair(self.data, self.approval)
        self.write_approval(fresh=True)
        self.assertTrue(recovery.repair(self.data, self.approval)['fresh_install'])
        self.assertFalse(self.data.exists())

    def test_private_fresh_files_require_no_migration(self):
        self.write_approval(fresh=True); self.data.chmod(0o750)
        for name in recovery.NAMES:
            if (self.data / name).exists(): (self.data / name).chmod(0o640)
        self.assertEqual(recovery.repair(self.data, self.approval)['repaired_files'], [])

    def test_missing_permanent_phone_lock_rejects_preserved_journal(self):
        (self.data / 'phone-admission.lock').unlink()
        before = self.metadata()
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'permanent lock'): recovery.repair(self.data, self.approval)
        self.assertEqual(self.metadata(), before)

    def test_unknown_ownership_modes_links_and_lock_contents_are_rejected(self):
        for kind in ('owner', 'group', 'mode', 'hardlink', 'symlink', 'fifo', 'nonempty'):
            with self.subTest(kind=kind):
                path = self.data / 'enterprise-worker.lock'; path.unlink()
                victim = self.root / ('victim-' + kind); victim.write_bytes(b'safe'); victim.chmod(0o600)
                path = self.file('enterprise-worker.lock')
                if kind == 'owner': os.chown(path, 0, self.account.pw_gid)
                elif kind == 'group': os.chown(path, self.account.pw_uid, 0)
                elif kind == 'mode': path.chmod(0o666)
                elif kind == 'hardlink': os.link(path, self.root / 'extra-link')
                elif kind == 'symlink': path.unlink(); path.symlink_to(victim)
                elif kind == 'fifo': path.unlink(); os.mkfifo(path, 0o644)
                else: path.write_bytes(b'not an empty lock')
                with self.assertRaises((OSError, recovery.PermissionRecoveryError)): recovery.repair(self.data, self.approval)
                self.assertEqual(victim.read_bytes(), b'safe'); self.assertEqual(stat.S_IMODE(victim.stat().st_mode), 0o600)

    def test_oversize_journal_rejected_before_permission_changes(self):
        path = self.data / 'phone-admission.json'
        with path.open('wb') as handle: handle.truncate(recovery.MAX_STATE + 1)
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'size limit'): recovery.repair(self.data, self.approval)
        self.assertEqual(stat.S_IMODE((self.data / 'enterprise-worker.lock').stat().st_mode), 0o644)

    def test_short_lock_contention_is_retried_without_replacing_inode(self):
        path = self.data / 'phone-admission.lock'; before = path.stat().st_ino
        held = os.open(path, os.O_RDONLY); fcntl.flock(held, fcntl.LOCK_EX)
        release = threading.Thread(target=lambda: (time.sleep(.1), os.close(held)))
        release.start()
        try: recovery.repair(self.data, self.approval)
        finally: release.join()
        self.assertEqual(path.stat().st_ino, before)

    def test_active_worker_fails_boundedly_without_touching_modes(self):
        held = os.open(self.data / 'enterprise-worker.lock', os.O_RDONLY); fcntl.flock(held, fcntl.LOCK_EX)
        try:
            before = self.metadata()
            with mock.patch.object(recovery.time, 'monotonic', side_effect=[0, 6]):
                with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'active'): recovery.repair(self.data, self.approval, timeout=1)
            self.assertEqual(self.metadata(), before)
        finally: os.close(held)

    def test_live_directory_replacement_cannot_report_success(self):
        original = recovery.directory; count = 0
        def swapped(path, account, **kwargs):
            nonlocal count
            if Path(path) == self.data:
                count += 1
                if count == 2:
                    self.data.rename(self.root / 'preserved-data'); self.data.mkdir()
                    os.chown(self.data, self.account.pw_uid, self.account.pw_gid); self.data.chmod(0o755)
            return original(path, account, **kwargs)
        with mock.patch.object(recovery, 'directory', side_effect=swapped):
            with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'changed'): recovery.repair(self.data, self.approval)
        self.assertEqual(stat.S_IMODE((self.root / 'preserved-data/phone-admission.json').stat().st_mode), 0o644)

    def test_approval_must_be_private_authenticated_structure(self):
        for mutate in ('mode', 'owner', 'source', 'schema', 'hash'):
            with self.subTest(mutate=mutate):
                self.write_approval()
                if mutate == 'mode': self.approval.chmod(0o644)
                elif mutate == 'owner': os.chown(self.approval, self.account.pw_uid, self.account.pw_gid)
                else:
                    value = json.loads(self.approval.read_bytes())
                    value[{'source': 'source', 'schema': 'schema', 'hash': 'archive_sha256'}[mutate]] = {'source': 'live-file-baseline', 'schema': True, 'hash': 'bad'}[mutate]
                    self.approval.write_text(json.dumps(value))
                with self.assertRaises(recovery.PermissionRecoveryError): recovery.repair(self.data, self.approval)
                os.chown(self.approval, 0, 0)

    def test_chown_protect_preserves_native_directives_and_exact_rollback(self):
        original = self.config_body(); info = self.config.stat()
        self.assertTrue(self.protect()['changed'])
        self.assertEqual(self.config.read_bytes(), original + recovery.CHOWN_BLOCK)
        self.assertEqual((self.config.stat().st_uid, self.config.stat().st_gid, stat.S_IMODE(self.config.stat().st_mode)),
                         (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))
        self.assertEqual(stat.S_IMODE(self.snapshot.stat().st_mode), 0o600)
        self.assertEqual(self.snapshot.stat().st_uid, 0)
        self.assertTrue(recovery.restore_chown(self.config, self.snapshot)['restored'])
        self.assertEqual(self.config.read_bytes(), original)
        self.assertFalse(recovery.restore_chown(self.config, self.snapshot)['restored'])

    def test_absent_chown_config_restore_only_removes_our_installation(self):
        self.protect(); self.assertEqual(self.config.read_bytes(), recovery.CHOWN_BLOCK)
        self.assertTrue(recovery.restore_chown(self.config, self.snapshot)['restored'])
        self.assertFalse(self.config.exists())

    def test_native_exclusion_idempotence_and_unknown_custom_conflicts(self):
        for prefix in (b'[blacklist]\ndirectory=/srv/pbx\n[blacklist](+)\ndirectory=' + recovery.DATA.encode() + b'\n',
                       b'[blacklist]\ndirectory=/var/lib/asterisk\n'):
            with self.subTest(prefix=prefix): self.assertEqual(recovery.chown_transform(prefix), prefix)
        for key in ('file', 'dir', 'rdir', 'execdir'):
            self.config_body(('[custom]\n' + key + '=' + recovery.DATA + '/phone-admission.json,asterisk,asterisk,0644\n').encode())
            before = self.config.read_bytes()
            with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'conflicts'): self.protect()
            self.assertEqual(self.config.read_bytes(), before); self.assertFalse(self.snapshot.exists())

    def test_comments_cannot_hide_missing_native_exclusion(self):
        body = b';-- whole commented exclusion\n[blacklist]\ndirectory=' + recovery.DATA.encode() + b'\n--;\n[custom]\n'
        self.assertEqual(recovery.chown_transform(body), body + recovery.CHOWN_BLOCK)
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'unterminated'):
            recovery.chown_transform(body.split(b'--;')[0])
        self.assertEqual(recovery.chown_transform(b'[custom]\n;-- inline --;\n'), b'[custom]\n;-- inline --;\n' + recovery.CHOWN_BLOCK)

    def test_incomplete_managed_markers_and_unknown_includes_require_review(self):
        for body in (b'; BEGIN SLS MASS NOTIFY DATA PERMISSIONS\n', b'#include unmanaged.conf\n',
                     b'[blacklist](!)\ndirectory=' + recovery.DATA.encode() + b'\n'):
            with self.subTest(body=body), self.assertRaises(recovery.PermissionRecoveryError): recovery.chown_transform(body)

    def test_recovery_refuses_later_administrator_changes_and_unsafe_snapshots(self):
        self.config_body(); self.protect()
        self.config.write_bytes(self.config.read_bytes() + b'; later administrator edit\n')
        later = self.config.read_bytes()
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'changed after'): recovery.restore_chown(self.config, self.snapshot)
        self.assertEqual(self.config.read_bytes(), later)
        self.snapshot.chmod(0o644)
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'snapshot is unsafe'): recovery.restore_chown(self.config, self.snapshot)

    def test_config_replacement_during_staging_preserves_administrator_edit(self):
        self.config_body(); original = recovery.current_parent; changed = False
        def change(parent, path, account):
            nonlocal changed
            if not changed:
                changed = True; self.config.write_bytes(b'[custom]\n; concurrent administrator edit\n')
            return original(parent, path, account)
        with mock.patch.object(recovery, 'current_parent', side_effect=change):
            with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'changed'): self.protect()
        self.assertIn(b'concurrent administrator edit', self.config.read_bytes())

    def test_unsafe_config_types_never_touch_victim(self):
        victim = self.root / 'outside'; victim.write_bytes(b'untouched'); victim.chmod(0o600)
        for kind in ('symlink', 'hardlink', 'fifo', 'worldwrite', 'oversize', 'owner'):
            with self.subTest(kind=kind):
                if self.config.exists() or self.config.is_symlink(): self.config.unlink()
                if kind == 'symlink': self.config.symlink_to(victim)
                elif kind == 'hardlink': os.link(victim, self.config)
                elif kind == 'fifo': os.mkfifo(self.config, 0o600)
                else:
                    self.config_body()
                    if kind == 'worldwrite': self.config.chmod(0o666)
                    elif kind == 'owner': os.chown(self.config, 45678, 45678)
                    else:
                        with self.config.open('wb') as handle: handle.truncate(recovery.MAX_CHOWN + 1)
                with self.assertRaises((OSError, recovery.PermissionRecoveryError)): self.protect()
                self.assertEqual(victim.read_bytes(), b'untouched'); self.assertFalse(self.snapshot.exists())

    def test_uninstall_removes_only_exact_managed_suffix(self):
        original = self.config_body(); self.protect()
        self.assertTrue(recovery.remove_chown(self.config)['changed']); self.assertEqual(self.config.read_bytes(), original)
        self.config.write_bytes(original + recovery.CHOWN_BLOCK + b'; administrator appended\n')
        before = self.config.read_bytes()
        with self.assertRaisesRegex(recovery.PermissionRecoveryError, 'administrator'): recovery.remove_chown(self.config)
        self.assertEqual(self.config.read_bytes(), before)

    def test_protect_requires_approval_and_cannot_overwrite_recovery_snapshot(self):
        self.config_body(); self.approval.chmod(0o644)
        with self.assertRaises(recovery.PermissionRecoveryError): self.protect()
        self.assertFalse(self.snapshot.exists())
        self.approval.chmod(0o600); self.protect()
        before = self.snapshot.read_bytes()
        with self.assertRaises(FileExistsError): self.protect()
        self.assertEqual(self.snapshot.read_bytes(), before)

    def test_native_loadconfig_parser_and_real_chown_paths_on_private_fixture(self):
        native = Path('/var/www/html/admin/libraries/BMO/LoadConfig.class.php')
        chown = Path('/var/www/html/admin/libraries/Console/Chown.class.php')
        autoload = Path('/var/www/html/admin/libraries/Composer/vendor/autoload.php')
        if not all(path.is_file() for path in (native, chown, autoload)) or shutil.which('php') is None:
            self.skipTest('Native FreePBX parser/Chown classes are unavailable in this isolated environment')
        original = b'[blacklist]\ndirectory=/srv/other\n[blacklist](+)\ndirectory=/srv/third\n[custom]\nfile=/srv/test,root,root,0600\n'
        self.config_body(original); self.protect()
        script = "require $argv[1]; $c=new \\FreePBX\\LoadConfig(new \\stdClass(),null,dirname($argv[2])); echo json_encode($c->getConfig(basename($argv[2]),dirname($argv[2])));"
        parsed = json.loads(subprocess.check_output(['php', '-r', script, str(native), str(self.config)], text=True))
        self.assertEqual(parsed['blacklist']['directory'], ['/srv/other', '/srv/third', recovery.DATA])
        self.assertEqual(parsed['custom']['file'], '/srv/test,root,root,0600')
        recovery.repair(self.data, self.approval)
        before = self.metadata(); unrelated = self.root / 'unrelated'; unrelated.mkdir(mode=0o700)
        item = unrelated / 'file'; item.write_bytes(b'ordinary'); item.chmod(0o600)
        # Invoke only the native normalization methods on this private tree;
        # never bootstrap FreePBX or execute fwconsole against the live PBX.
        operation = r'''
            require $argv[1]; require $argv[2]; require $argv[3];
            $root=realpath($argv[4]); $data=realpath($argv[5]);
            if (!str_starts_with($root, '/tmp/sls-legacy-permissions-') || $data !== $root.'/data') exit(4);
            $c=new \FreePBX\LoadConfig(new \stdClass(),null,$root);
            $parsed=$c->getConfig('freepbx_chown.conf',$root);
            $dirs=array_map(fn($path)=>$path===$argv[6]?$data:$path,$parsed['blacklist']['directory']);
            $reflection=new \ReflectionClass(\FreePBX\Console\Command\Chown::class);
            $object=$reflection->newInstanceWithoutConstructor();
            $blacklist=$reflection->getProperty('blacklist'); $blacklist->setAccessible(true);
            $blacklist->setValue($object,['files'=>[],'dirs'=>$dirs]);
            $object->output=new \Symfony\Component\Console\Output\NullOutput();
            $method=$reflection->getMethod('systemSetRecursivePermissions'); $method->setAccessible(true);
            $method->invoke($object,$root,0755,'root','root','rdir');
            $object->chmod(null,$root,0755,0000,true,true);
            $object->chown(null,$root,'root',true); $object->chgrp(null,$root,'root',true);
            echo json_encode(['ok'=>true]);
        '''
        self.assertTrue(json.loads(subprocess.check_output(['php', '-r', operation, str(autoload), str(native), str(chown),
            str(self.root), str(self.data), recovery.DATA], text=True))['ok'])
        self.assertEqual(self.metadata(), before)
        self.assertEqual(stat.S_IMODE(item.stat().st_mode), 0o644)


if __name__ == '__main__': unittest.main()
