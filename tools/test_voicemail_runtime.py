#!/usr/bin/python3
"""Private-file adversarial tests for voicemail policy, storage and quotas."""
import hashlib
import fcntl
import json
import math
import os
from pathlib import Path
import pwd
import struct
import sys
import tempfile
import threading
import time
import unittest
from unittest import mock
import wave

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_voicemail as vm


class VoicemailRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-voicemail-media-')
        self.root = Path(self.temp.name)
        self.token = 'b' * 32
        self.config = {'ami': {}, 'generated_media_min_free_mib': 64}
        self.save_config()
        self.worker_lock = self.root / 'enterprise-worker.lock'
        self.worker_lock.write_bytes(b'')
        self.worker_lock.chmod(0o640)
        account = pwd.getpwnam('asterisk')
        os.chown(self.worker_lock, account.pw_uid, account.pw_gid)
        (self.root / 'sounds/tts').mkdir(parents=True)
        self.source = self.root / 'sounds/tts/fixture.wav'
        self.wav(self.source)
        self.media = vm.VoicemailMedia(self.root)

    def tearDown(self):
        self.temp.cleanup()

    def save_config(self):
        path = self.root / 'mass-notifications.config'
        path.write_text(json.dumps(self.config)); path.chmod(0o640)
        account = pwd.getpwnam('asterisk'); os.chown(path, account.pw_uid, account.pw_gid)

    @staticmethod
    def wav(path, seconds=2, rate=8000, silent=False):
        with wave.open(str(path), 'wb') as handle:
            handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(rate)
            frames = b'\0\0' * int(rate * seconds) if silent else b''.join(
                struct.pack('<h', int(8000 * math.sin(index * math.tau * 700 / rate))) for index in range(int(rate * seconds)))
            handle.writeframes(frames)
        path.chmod(0o640)
        account = pwd.getpwnam('asterisk'); os.chown(path, account.pw_uid, account.pw_gid)

    def snapshot(self, duration=3):
        return vm.snapshot_audio(self.token, 'SLS_Mass_Notifications_Plugin/tts/fixture', duration, self.root)

    def policy(self, body):
        directory = self.root / 'etc'; directory.mkdir(exist_ok=True)
        path = directory / 'voicemail.conf'; path.write_text(body); path.chmod(0o644)
        return directory

    def test_snapshot_is_private_immutable_copy_and_survives_source_cleanup(self):
        before = self.source.read_bytes()
        self.assertEqual(self.snapshot(), vm.canonical_sound(self.token))
        self.source.unlink()
        lease = self.media.validate(self.token)
        self.assertEqual(lease['sha256'], hashlib.sha256(before).hexdigest())
        self.assertAlmostEqual(lease['duration_seconds'], 2)
        target = self.media.path / (self.token + '.wav')
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)
        self.assertEqual(target.read_bytes(), before)

    def test_tampered_completed_audio_is_rejected(self):
        self.snapshot()
        path = self.media.path / (self.token + '.wav')
        with path.open('r+b') as handle: handle.seek(100); handle.write(b'xx')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'): self.media.validate(self.token)

    def test_symlinks_and_hardlinks_never_become_audio(self):
        self.source.unlink(); self.source.symlink_to(self.root / 'mass-notifications.config')
        with self.assertRaises(vm.PhoneAdmissionError): self.snapshot()
        self.source.unlink(); self.wav(self.source)
        os.link(self.source, self.root / 'copy.wav')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'): self.snapshot()

    def test_non_pbx_audio_and_truncated_audio_are_rejected(self):
        self.wav(self.source, rate=16000)
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'): self.snapshot()
        self.wav(self.source)
        with self.source.open('r+b') as handle: handle.truncate(100)
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'): self.snapshot()
        self.assertFalse((self.media.path / (self.token + '.wav')).exists())

    def test_overlong_audio_is_rejected_without_silent_cutting(self):
        self.wav(self.source, seconds=4)
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'): self.snapshot(duration=1)
        self.assertFalse((self.media.path / (self.token + '.wav')).exists())

    def test_existing_opaque_target_is_never_overwritten(self):
        self.snapshot(); before = (self.media.path / (self.token + '.wav')).read_bytes()
        self.wav(self.source, seconds=1)
        with self.assertRaises(vm.PhoneAdmissionError): self.snapshot()
        self.assertEqual((self.media.path / (self.token + '.wav')).read_bytes(), before)

    def test_generated_media_quota_and_free_reserve_are_enforced(self):
        self.config['generated_media_cache_mib'] = 64; self.save_config()
        with mock.patch('sls_storage_maintenance.generated_inventory', return_value=[
                ('audio', self.root, 'busy.wav', type('Info', (), {'st_size': 64 * 1024 * 1024})(), 900)]):
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_capacity'): self.snapshot()
        low = type('Stat', (), {'f_bavail': 1, 'f_frsize': 4096})()
        with mock.patch.object(os, 'fstatvfs', return_value=low):
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_capacity'): self.snapshot()

    def test_static_mailbox_limits_and_safe_include_inheritance(self):
        directory = self.policy('[general]\nmaxsecs=60\nminsecs=2\n#include voicemail_custom.conf\n')
        (directory / 'voicemail_custom.conf').write_text('[default]\n1001=1234,Fixture,,,maxsecs=30|minsecs=3\n')
        self.assertEqual(vm.mailbox_limits('1001@default', directory)['maxsecs'], 30)
        self.assertEqual(vm.mailbox_limits('1001@default', directory)['minsecs'], 3)
        (directory / 'voicemail_custom.conf').unlink()
        (directory / 'voicemail_custom.conf').symlink_to(self.root / 'mass-notifications.config')
        with self.assertRaises(vm.PhoneAdmissionError): vm.mailbox_limits('1001@default', directory)

    def test_realtime_missing_mailbox_and_executable_policy_fail_closed(self):
        directory = self.policy('[general]\nmaxsecs=60\n#exec /bin/anything\n')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'policy_unavailable'): vm.mailbox_limits('1001@default', directory)
        self.policy('[general]\nmaxsecs=60\n[default]\n')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'policy_unavailable'): vm.mailbox_limits('1001@default', directory)

    def test_mailbox_duration_and_silence_prevent_partial_deposits(self):
        self.snapshot(); lease = self.media.validate(self.token)
        path = self.media.path / (self.token + '.wav')
        directory = self.policy('[general]\nmaxsecs=1\n[default]\n1001=1234,Fixture\n')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'mailbox_duration'): vm.check_mailbox_audio('1001@default', path, lease, directory)
        self.policy('[general]\nmaxsecs=30\nminsecs=3\n[default]\n1001=1234,Fixture\n')
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'mailbox_duration'): vm.check_mailbox_audio('1001@default', path, lease, directory)
        self.policy('[general]\nmaxsecs=30\nminsecs=0\nmaxsilence=1\n[default]\n1001=1234,Fixture\n')
        self.wav(path, seconds=2, silent=True)
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'mailbox_silence'): vm.check_mailbox_audio('1001@default', path, lease, directory)

    def test_defaults_optout_runtime_stop_and_group_revocation(self):
        settings = {'live_paging': {'enabled': '1', 'groups': [{'group_id': 'grp', 'extensions': ['1001']} ]}}
        batch = {'service': 'live', 'vm_fallback': {'scope': 'grp', 'scope_hash': vm.scope_fingerprint(settings, 'live', 'grp')}}
        self.assertTrue(vm.current_policy(settings, batch, self.root))
        self.assertFalse(vm.current_policy({**settings, 'runtime_enabled': False}, batch, self.root))
        self.assertFalse(vm.current_policy({**settings, 'live_paging': {**settings['live_paging'], 'voicemail_enabled': '0'}}, batch, self.root))
        changed = {**settings, 'live_paging': {'enabled': '1', 'groups': [{'group_id': 'grp', 'extensions': ['1002']}]}}
        self.assertFalse(vm.current_policy(changed, batch, self.root))
        with self.assertRaises(vm.PhoneAdmissionError): vm.current_policy({**settings, 'live_paging': {**settings['live_paging'], 'voicemail_enabled': []}}, batch, self.root)

    def test_legacy_weather_scope_is_stable_and_preserves_inherited_quiet_policy(self):
        settings = {'enabled': '1', 'nws_zone': 'TXC453', 'alert_recipients': ['1001'], 'quiet_hours_enabled': '1',
                    'quiet_hours_start': '21:00', 'quiet_hours_end': '06:00'}
        scope = 'nws_' + hashlib.sha256(b'primary weather zone|TXC453').hexdigest()[:12]
        digest = vm.scope_fingerprint(settings, 'nws', scope)
        self.assertRegex(digest, r'^[a-f0-9]{64}$')
        self.assertNotEqual(digest, vm.scope_fingerprint({**settings, 'quiet_hours_start': '22:00'}, 'nws', scope))
        batch = {'service': 'nws', 'vm_fallback': {'scope': scope, 'scope_hash': digest,
            'weather_context': {'source_validity': {'provider': 'nws'}, 'event': 'Tornado Warning', 'severity': 'Extreme'}}}
        with mock.patch('sls_notification_destinations.validate_external_weather', return_value=('eligible', '', {})) as check:
            self.assertTrue(vm.current_policy(settings, batch, self.root)); check.assert_called_once()
        for status in ('deferred', 'cancelled'):
            with mock.patch('sls_notification_destinations.validate_external_weather', return_value=(status, 'source_expired', {})):
                self.assertFalse(vm.current_policy(settings, batch, self.root))

    def test_cleanup_removes_only_expired_unreferenced_managed_recording(self):
        self.snapshot()
        lease = self.media.path / (self.token + '.json')
        value = json.loads(lease.read_text()); value['expires_at'] = time.time() - 1
        lease.write_text(json.dumps(value)); lease.chmod(0o600)
        unrelated = self.media.path / 'administrator.wav'; unrelated.write_bytes(b'keep')
        self.assertEqual(vm.cleanup_media(self.root), 1)
        self.assertFalse(lease.exists())
        self.assertFalse((self.media.path / (self.token + '.wav')).exists())
        self.assertEqual(unrelated.read_bytes(), b'keep')

    def test_maintenance_blocks_cleanup_before_media_state_or_quota_access(self):
        self.snapshot()
        lease = self.media.path / (self.token + '.json')
        value = json.loads(lease.read_text()); value['expires_at'] = time.time() - 1
        lease.write_text(json.dumps(value)); lease.chmod(0o600)
        before = {path: (path.read_bytes(), path.stat().st_ino, path.stat().st_mode)
                  for path in self.root.rglob('*') if path.is_file()}
        with self.worker_lock.open('rb') as maintenance:
            fcntl.flock(maintenance, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with mock.patch.object(vm, 'VoicemailMedia') as media, \
                    mock.patch.object(vm, 'PhoneAdmissionStore') as store, \
                    mock.patch.object(vm, 'read_private_json') as journal, \
                    mock.patch.object(vm, 'read_settings') as settings, \
                    mock.patch.object(vm, 'release_quota') as quota:
                self.assertEqual(vm.cleanup_media(self.root), 0)
            media.assert_not_called(); store.assert_not_called(); journal.assert_not_called()
            settings.assert_not_called(); quota.assert_not_called()
            self.assertEqual({path: (path.read_bytes(), path.stat().st_ino, path.stat().st_mode)
                              for path in self.root.rglob('*') if path.is_file()}, before)
        original_unlink = os.unlink
        def unlink(name, *args, **kwargs):
            with self.worker_lock.open('rb') as contender:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return original_unlink(name, *args, **kwargs)
        def release(*args):
            with self.worker_lock.open('rb') as contender:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with mock.patch.object(vm.os, 'unlink', side_effect=unlink), \
                mock.patch.object(vm, 'release_quota', side_effect=release) as quota:
            self.assertEqual(vm.cleanup_media(self.root), 1)
        quota.assert_called_once_with(self.root, self.token)
        self.assertFalse(lease.exists())
        self.assertFalse((self.media.path / (self.token + '.wav')).exists())
        self.assertEqual((self.worker_lock.read_bytes(), self.worker_lock.stat().st_ino, self.worker_lock.stat().st_mode),
                         before[self.worker_lock])
        with self.worker_lock.open('rb') as maintenance:
            fcntl.flock(maintenance, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_missing_worker_lease_blocks_cleanup_without_creating_state(self):
        self.snapshot()
        self.worker_lock.unlink()
        before = {path: (path.read_bytes(), path.stat().st_ino, path.stat().st_mode)
                  for path in self.root.rglob('*') if path.is_file()}
        with self.assertRaisesRegex(vm.PhoneAdmissionError, 'worker_lock_unsafe'):
            vm.cleanup_media(self.root)
        self.assertEqual({path: (path.read_bytes(), path.stat().st_ino, path.stat().st_mode)
                          for path in self.root.rglob('*') if path.is_file()}, before)
        self.assertFalse(self.worker_lock.exists())


    def test_native_transport_preserves_existing_cluster_fence(self):
        from sls_cluster_guard import ClusterFenced
        with mock.patch('sls_cluster_guard.fence_legacy', side_effect=ClusterFenced('blocked')) as fence:
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'cluster_fenced'): vm.native_transport_guard(self.config)
            fence.assert_called_once_with(self.config)
        with mock.patch('sls_cluster_guard.fence_legacy') as fence:
            vm.native_transport_guard(self.config); fence.assert_called_once_with(self.config)

    def test_idle_drain_reads_without_rewriting_admission(self):
        store = mock.MagicMock()
        with mock.patch.object(vm, 'PhoneAdmissionStore', return_value=store), \
                mock.patch.object(vm, 'read_private_json', return_value={'batches': {}}):
            self.assertEqual(vm.drain_voicemail(self.root), 0)
        store.validate.assert_called_once()
        store.locked.assert_not_called()

    def test_exclusive_maintenance_skips_before_phone_config_or_ami_access(self):
        store, _ = self.live_source()
        original = {path: (path.read_bytes(), path.stat().st_ino, path.stat().st_mode)
                    for path in (self.worker_lock, self.root / 'mass-notifications.config', self.root / 'phone-admission.json')}
        factory = mock.MagicMock()
        with self.worker_lock.open('rb') as maintenance:
            fcntl.flock(maintenance, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with mock.patch.object(vm, 'PhoneAdmissionStore') as constructor, \
                    mock.patch.object(vm, 'read_private_json') as journal, \
                    mock.patch.object(vm, 'read_settings') as settings, \
                    mock.patch.object(vm.VoicemailMedia, 'validate') as media:
                self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
            constructor.assert_not_called(); journal.assert_not_called()
            settings.assert_not_called(); media.assert_not_called(); factory.assert_not_called()
            for path, before in original.items():
                self.assertEqual((path.read_bytes(), path.stat().st_ino, path.stat().st_mode), before)
            # The collector uses only the phone journal lease. Maintenance must
            # not make the voicemail thread retain it while awaiting admission.
            failures = []
            def heartbeat():
                try: store.collector_heartbeat('d' * 32)
                except BaseException as error: failures.append(error)
            thread = threading.Thread(target=heartbeat)
            thread.start(); thread.join(1)
            self.assertFalse(thread.is_alive()); self.assertEqual(failures, [])
            with store.locked() as state:
                self.assertEqual(store.require_collector(state)['generation'], 'd' * 32)

    def test_worker_lease_held_through_state_read_and_released_after_error(self):
        original = self.worker_lock.stat()
        real_flock = fcntl.flock
        def inspect(*args):
            with self.worker_lock.open('rb') as contender:
                with self.assertRaises(BlockingIOError):
                    real_flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            raise vm.PhoneAdmissionError('phone_state_invalid')
        with mock.patch.object(vm, 'read_private_json', side_effect=inspect):
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'phone_state_invalid'):
                vm.drain_voicemail(self.root)
        with self.worker_lock.open('rb') as contender:
            real_flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.assertEqual(self.worker_lock.stat().st_ino, original.st_ino)
        self.assertEqual(self.worker_lock.read_bytes(), b'')

    def test_releasing_maintenance_allows_one_durable_existing_fallback_submission(self):
        store, channel = self.live_source()
        vm.prepare_live_recording(self.token, channel, 30, self.root)
        self.wav(self.media.path / (self.token + '-receive.wav'), seconds=2)
        vm.finalize_live_recording(self.token, channel, self.root)
        self.close_live_source(store)
        before = (self.root / 'phone-admission.json').read_bytes()
        inode = self.worker_lock.stat().st_ino
        ami = mock.MagicMock()
        ami.mailboxes.return_value = {'1001': '1001@default'}
        ami.inventory.return_value = {'complete': True, 'started_tick': time.monotonic(),
            'generation': 'a' * 32, 'boot_id': store.boot_id, 'channels': []}
        factory = mock.MagicMock(); factory.return_value.__enter__.return_value = ami
        def submitted(action):
            self.assertEqual(action['Action'], 'Originate')
            with self.worker_lock.open('rb') as contender:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            state = vm.read_private_json('phone-admission.json', self.root)
            child = state['batches'][self.token]['vm_fallback']['targets']['1001']['claimed_token']
            self.assertIn(child, state['batches'])
            self.assertEqual(state['batches'][child]['purpose'], 'voicemail')
        ami.action.side_effect = submitted
        with self.worker_lock.open('rb') as maintenance:
            fcntl.flock(maintenance, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
            self.assertEqual((self.root / 'phone-admission.json').read_bytes(), before)
            factory.assert_not_called()
        with mock.patch.object(vm, 'check_mailbox_audio'), mock.patch.object(vm, 'native_transport_guard'):
            self.assertEqual(vm.drain_voicemail(self.root, factory), 1)
            self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
        self.assertEqual(ami.action.call_count, 1)
        self.assertEqual(self.worker_lock.stat().st_ino, inode)
        self.assertEqual(self.worker_lock.read_bytes(), b'')
        with self.worker_lock.open('rb') as maintenance:
            fcntl.flock(maintenance, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_unsafe_worker_lease_fails_closed_without_adopting_it(self):
        account = pwd.getpwnam('asterisk')
        def reset():
            if self.worker_lock.is_dir(): self.worker_lock.rmdir()
            elif self.worker_lock.exists() or self.worker_lock.is_symlink(): self.worker_lock.unlink()
            link = self.root / 'worker-hardlink'
            if link.exists(): link.unlink()
            self.worker_lock.write_bytes(b'')
            self.worker_lock.chmod(0o640)
            os.chown(self.worker_lock, account.pw_uid, account.pw_gid)
        cases = {
            'missing': lambda: self.worker_lock.unlink(),
            'symlink': lambda: (self.worker_lock.unlink(), self.worker_lock.symlink_to(self.root / 'mass-notifications.config')),
            'hardlink': lambda: os.link(self.worker_lock, self.root / 'worker-hardlink'),
            'owner': lambda: os.chown(self.worker_lock, account.pw_uid + 10000, account.pw_gid),
            'group': lambda: os.chown(self.worker_lock, account.pw_uid, account.pw_gid + 10000),
            'public': lambda: self.worker_lock.chmod(0o644),
            'executable': lambda: self.worker_lock.chmod(0o740),
            'oversized': lambda: self.worker_lock.write_bytes(b'x' * 4097),
            'fifo': lambda: (self.worker_lock.unlink(), os.mkfifo(self.worker_lock, 0o640)),
            'directory': lambda: (self.worker_lock.unlink(), self.worker_lock.mkdir()),
        }
        for label, mutate in cases.items():
            with self.subTest(label=label):
                reset(); mutate()
                before = os.lstat(self.worker_lock) if label != 'missing' else None
                factory = mock.MagicMock()
                with mock.patch.object(vm, 'PhoneAdmissionStore') as constructor, \
                        mock.patch.object(vm, 'read_private_json') as journal, \
                        mock.patch.object(vm, 'read_settings') as settings:
                    with self.assertRaisesRegex(vm.PhoneAdmissionError, 'worker_lock_unsafe'):
                        vm.drain_voicemail(self.root, factory)
                constructor.assert_not_called(); journal.assert_not_called()
                settings.assert_not_called(); factory.assert_not_called()
                if before:
                    after = os.lstat(self.worker_lock)
                    self.assertEqual((before.st_ino, before.st_mode, before.st_uid, before.st_gid, before.st_size),
                                     (after.st_ino, after.st_mode, after.st_uid, after.st_gid, after.st_size))
                else:
                    self.assertFalse(self.worker_lock.exists())
        reset()

    def test_replaced_worker_inode_after_kernel_lease_is_rejected(self):
        real_flock = fcntl.flock
        def replace(fd, flags):
            real_flock(fd, flags)
            self.worker_lock.rename(self.root / 'worker-original')
            self.worker_lock.write_bytes(b'')
            self.worker_lock.chmod(0o640)
            account = pwd.getpwnam('asterisk')
            os.chown(self.worker_lock, account.pw_uid, account.pw_gid)
        with mock.patch.object(vm.fcntl, 'flock', side_effect=replace), \
                mock.patch.object(vm, 'PhoneAdmissionStore') as constructor, \
                mock.patch.object(vm, 'read_private_json') as journal, \
                mock.patch.object(vm, 'read_settings') as settings:
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'worker_lock_unsafe'):
                vm.drain_voicemail(self.root)
        constructor.assert_not_called(); journal.assert_not_called(); settings.assert_not_called()

    def test_bad_mailbox_does_not_block_other_recipient_and_is_not_retried(self):
        row = lambda mailbox: {'dials': [], 'mailbox': mailbox, 'claimed_token': None}
        batch = {'purpose': 'page', 'status': 'closed', 'created_at': time.time(), 'channels': {},
            'vm_fallback': {'sound': vm.canonical_sound(self.token), 'expires_at': time.time() + 100,
                'expires_tick': time.monotonic() + 100, 'targets': {'1001': row('1001@default'), '1002': row('1002@default')}}}
        store = mock.MagicMock(); store.boot_id = 'fixture'; store.require_collector.return_value = {'generation': 'fixture'}
        store.admit_voicemail.return_value = 'c' * 32
        store.locked.return_value.__enter__.return_value = {'batches': {}}
        ami = mock.MagicMock(); ami.mailboxes.side_effect = lambda extensions: {extension: extension + '@default' for extension in extensions}
        factory = mock.MagicMock(); factory.return_value.__enter__.return_value = ami
        def check(mailbox, path, lease):
            with self.worker_lock.open('rb') as contender:
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if mailbox.startswith('1001@'): raise vm.PhoneAdmissionError('phone_voicemail_mailbox_duration')
        with mock.patch.object(vm, 'PhoneAdmissionStore', return_value=store), \
                mock.patch.object(vm, 'read_private_json', return_value={'batches': {self.token: batch}}), \
                mock.patch.object(vm, 'read_settings', return_value={'phone_device_limit': 25}), \
                mock.patch.object(vm, 'current_policy', return_value=True), \
                mock.patch.object(vm.VoicemailMedia, 'validate', return_value={}), \
                mock.patch.object(vm, 'check_mailbox_audio', side_effect=check), \
                mock.patch.object(vm, 'native_transport_guard'), \
                mock.patch.object(vm, 'report_problem'):
            self.assertEqual(vm.drain_voicemail(self.root, factory), 1)
        store.record_voicemail_refusal.assert_called_once_with(self.token, '1001', 'phone_voicemail_mailbox_duration')
        self.assertEqual(store.admit_voicemail.call_args.args[1], '1002')
        self.assertEqual(ami.action.call_count, 1)
        batch['voicemail_failures'] = {'1001': 'phone_voicemail_mailbox_duration'}
        self.assertFalse(vm.may_need_fallback(batch, batch['vm_fallback']['targets']['1001'], '1001'))

    def test_malformed_lease_cannot_block_cleanup_of_valid_expired_audio(self):
        self.snapshot()
        bad = self.media.path / (self.token + '.json')
        self.token = 'c' * 32; self.snapshot()
        lease = json.loads(bad.read_text()); lease['expires_at'] = 'forever'; bad.write_text(json.dumps(lease))
        good = self.media.path / (self.token + '.json')
        lease = json.loads(good.read_text()); lease['expires_at'] = time.time() - 1; good.write_text(json.dumps(lease))
        with mock.patch.object(vm, 'report_problem'):
            self.assertEqual(vm.cleanup_media(self.root), 1)
        self.assertTrue(bad.exists()); self.assertFalse(good.exists())


    def test_unsafe_audio_entry_does_not_block_valid_cleanup(self):
        self.snapshot(); bad = self.media.path / (self.token + '.json')
        self.token = 'c' * 32; self.snapshot(); good = self.media.path / (self.token + '.json')
        for path in (bad, good):
            lease = json.loads(path.read_text()); lease['expires_at'] = time.time() - 1; path.write_text(json.dumps(lease))
        unsafe = self.media.path / ('b' * 32 + '.wav'); unsafe.unlink(); unsafe.symlink_to(self.source)
        with mock.patch.object(vm, 'report_problem'): self.assertEqual(vm.cleanup_media(self.root), 1)
        self.assertTrue(unsafe.is_symlink()); self.assertTrue(self.source.exists()); self.assertFalse(good.exists())


    def live_source(self, dials=None):
        dials = dials or []
        self.config['live_paging'] = {'enabled': '1', 'voicemail_enabled': '1', 'groups': [
            {'group_id': 'grp', 'extensions': ['1001'], 'allowed_callers': ['1000']}]}
        self.save_config()
        store = vm.PhoneAdmissionStore(self.root); generation = 'a' * 32
        store.collector_heartbeat(generation)
        fallback = {'schema': 1, 'scope': 'grp', 'scope_hash': vm.scope_fingerprint(self.config, 'live', 'grp'),
            'source_sound': None, 'sound': None, 'weather_context': None, 'live_origin': '1000', 'live_group': 'grp',
            'expires_at': time.time() + 600, 'expires_tick': time.monotonic() + 600,
            'targets': {'1001': {'dials': dials, 'mailbox': '1001@default', 'claimed_token': None}}}
        inventory = {'complete': True, 'started_tick': time.monotonic(), 'generation': generation,
            'boot_id': store.boot_id, 'channels': []}
        self.token = store.admit({'1000': dials}, 25, inventory, service='live', vm_fallback=fallback)
        channel = 'PJSIP/1000-00000001'
        store.bind_origin(self.token, '1000', '8.1', channel)
        return store, channel

    def close_live_source(self, store, seconds=31):
        with store.locked() as state:
            batch = state['batches'][self.token]
            batch.update(status='closed', closed_at=time.time() - seconds, updated_at=time.time() - seconds,
                         updated_tick=time.monotonic() - seconds)
            for row in batch['channels'].values(): row['ended_at'] = time.time() - seconds

    def assert_terminal_live_failure(self, code):
        state = vm.read_private_json('phone-admission.json', self.root)
        self.assertEqual(len(state['batches']), 1)
        batch = state['batches'][self.token]
        self.assertEqual(batch['voicemail_failures'], {'1001': code})
        self.assertIsNone(batch['vm_fallback']['sound'])
        self.assertIsNone(batch['vm_fallback']['targets']['1001']['claimed_token'])
        self.assertFalse((self.media.path / (self.token + '.wav')).exists())
        factory = mock.MagicMock()
        self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
        factory.assert_not_called()

    def test_silent_live_finalization_is_terminal_without_a_mailbox_attempt(self):
        store, channel = self.live_source()
        vm.prepare_live_recording(self.token, channel, 30, self.root)
        self.wav(self.media.path / (self.token + '-receive.wav'), seconds=2, silent=True)
        with mock.patch.object(vm, 'report_problem'):
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'):
                vm.finalize_live_recording(self.token, channel, self.root)
        self.assert_terminal_live_failure('phone_voicemail_audio_invalid')

    def test_truncated_live_recording_is_terminal_without_a_mailbox_attempt(self):
        store, channel = self.live_source()
        vm.prepare_live_recording(self.token, channel, 30, self.root)
        receive = self.media.path / (self.token + '-receive.wav')
        self.wav(receive, seconds=2)
        with receive.open('r+b') as handle: handle.truncate(100)
        with mock.patch.object(vm, 'report_problem'):
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'audio_invalid'):
                vm.finalize_live_recording(self.token, channel, self.root)
        self.assert_terminal_live_failure('phone_voicemail_audio_invalid')

    def test_failed_owned_stop_becomes_terminal_only_after_closed_caller_grace(self):
        store, channel = self.live_source()
        vm.prepare_live_recording(self.token, channel, 30, self.root)
        receive = self.media.path / (self.token + '-receive.wav'); self.wav(receive, seconds=2)
        self.close_live_source(store, seconds=1)
        factory = mock.MagicMock()
        with mock.patch.object(vm, 'release_quota') as release, mock.patch.object(vm, 'report_problem'):
            self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
            self.assertNotIn('voicemail_failures', vm.read_private_json('phone-admission.json', self.root)['batches'][self.token])
            self.close_live_source(store)
            self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
            release.assert_not_called()
        self.assertTrue(receive.exists())
        self.assert_terminal_live_failure('phone_voicemail_recording_unconfirmed')
        before = (self.root / 'phone-admission.json').stat().st_mtime_ns
        self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
        self.assertEqual((self.root / 'phone-admission.json').stat().st_mtime_ns, before)
        factory.assert_not_called()

    def test_failed_recorder_preparation_gets_terminal_diagnostic_after_caller_ends(self):
        store, channel = self.live_source(); self.close_live_source(store)
        with mock.patch.object(vm, 'report_problem'): self.assert_terminal_live_failure_after_drain()

    def assert_terminal_live_failure_after_drain(self):
        factory = mock.MagicMock(); self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
        factory.assert_not_called(); self.assert_terminal_live_failure('phone_voicemail_recording_unconfirmed')

    def test_recording_failure_does_not_overwrite_answered_phone_outcome(self):
        dial = 'PJSIP/1001/sip:fixture@192.0.2.10'
        store, channel = self.live_source([dial])
        store.bind_contact(self.token, '1000', '8.2', 'PJSIP/1001-00000002', dial)
        store.record_event({'Event': 'DialEnd', 'DestUniqueid': '8.2',
                           'DestAccountCode': vm.accountcode(self.token), 'DialStatus': 'ANSWER'})
        self.close_live_source(store)
        before = vm.read_private_json('phone-admission.json', self.root)
        factory = mock.MagicMock(); self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
        after = vm.read_private_json('phone-admission.json', self.root)
        self.assertEqual(before, after); factory.assert_not_called()
        self.assertNotIn('voicemail_failures', after['batches'][self.token])

    def test_other_channel_cannot_refuse_recording_or_release_its_quota(self):
        store, channel = self.live_source(); vm.prepare_live_recording(self.token, channel, 30, self.root)
        with mock.patch.object(vm, 'release_quota') as release:
            with self.assertRaisesRegex(vm.PhoneAdmissionError, 'not_admitted'):
                vm.finalize_live_recording(self.token, 'PJSIP/1000-00000009', self.root)
            release.assert_not_called()
        self.assertNotIn('voicemail_failures', vm.read_private_json('phone-admission.json', self.root)['batches'][self.token])


    def test_large_failed_live_cohort_uses_one_atomic_bulk_refusal(self):
        store, channel = self.live_source(); self.close_live_source(store)
        recipients = [str(number) for number in range(1001, 2001)]
        with store.locked() as state:
            state['batches'][self.token]['vm_fallback']['targets'] = {
                recipient: {'dials': [], 'mailbox': recipient + '@default', 'claimed_token': None}
                for recipient in recipients}
        with mock.patch.object(store, 'record_voicemail_refusals', wraps=store.record_voicemail_refusals) as bulk, \
                mock.patch.object(store, 'locked', wraps=store.locked) as transaction, \
                mock.patch.object(vm, 'report_problem'):
            self.assertEqual(vm.mark_live_recording_failure(store, self.token), 1000)
        bulk.assert_called_once_with(self.token, recipients, 'phone_voicemail_recording_unconfirmed')
        self.assertEqual(transaction.call_count, 1)
        state = vm.read_private_json('phone-admission.json', self.root)
        self.assertEqual(len(state['batches']), 1)
        self.assertEqual(len(state['batches'][self.token]['voicemail_failures']), 1000)
        self.assertTrue(all(row['claimed_token'] is None for row in state['batches'][self.token]['vm_fallback']['targets'].values()))


    def test_voicemail_disabled_closed_live_source_is_ignored_without_mutation(self):
        dial = 'PJSIP/1001/sip:fixture@192.0.2.10'
        store, channel = self.live_source([dial]); self.close_live_source(store)
        for explicit_null in (False, True):
            with store.locked() as state:
                batch = state['batches'][self.token]
                batch.pop('vm_fallback', None)
                if explicit_null: batch['vm_fallback'] = None
            before = vm.read_private_json('phone-admission.json', self.root)
            factory = mock.MagicMock()
            self.assertEqual(vm.drain_voicemail(self.root, factory), 0)
            self.assertEqual(before, vm.read_private_json('phone-admission.json', self.root))
            factory.assert_not_called()


if __name__ == '__main__': unittest.main()
