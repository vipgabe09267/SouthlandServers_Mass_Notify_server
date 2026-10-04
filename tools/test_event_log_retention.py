#!/usr/bin/python3
"""Same-inode event retention fixtures, with no production files or services."""
import fcntl
import json
import os
import pwd
import subprocess
import textwrap
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_storage_maintenance as storage


class EventRetentionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-event-retention-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.path = self.root / 'events.jsonl'
        self.now = 1800000000
        self.old = b'{"logged_at":"2020-01-01T00:00:00Z","event":"old"}\n'
        self.new = b'{"logged_at":"2030-01-01T00:00:00Z","event":"new"}\n'
        self.path.write_bytes(self.old + self.new)

    def clean(self, **kwargs):
        return storage.prune_event_log(self.path, now=self.now, **kwargs)

    def test_same_inode_and_forensic_records_preserved_exactly(self):
        malformed = b'{broken JSON\n'
        unknown = b'{"event":"missing timestamp"}\n'
        fallback = b'{"created_at":"2030-01-01T00:00:00Z","event":"fallback"}\n'
        raw = malformed + self.old + self.new + unknown + fallback
        self.path.write_bytes(raw)
        before = self.path.stat()
        result = self.clean(retention_days=1)
        self.assertEqual(result, {'removed': 1, 'preserved_invalid': 2})
        self.assertEqual(self.path.read_bytes(), malformed + self.new + unknown + fallback)
        after = self.path.stat()
        self.assertEqual((before.st_ino, before.st_dev, before.st_mode, before.st_uid),
                         (after.st_ino, after.st_dev, after.st_mode, after.st_uid))
        self.assertEqual(list(self.root.glob('.sls-retention-*')), [])

    def test_busy_appender_is_never_displaced(self):
        original = self.path.read_bytes()
        with self.path.open('a') as writer:
            fcntl.flock(writer, fcntl.LOCK_EX)
            self.assertTrue(self.clean()['busy'])
        self.assertEqual(self.path.read_bytes(), original)

    def test_bounds_preserve_original(self):
        for content, options in ((self.old + self.new, {'maximum_bytes': 8}),
                                 (b'x' * 262145 + b'\n', {}),
                                 (self.old + self.new, {'scan_seconds': -1})):
            self.path.write_bytes(content)
            with self.assertRaisesRegex(RuntimeError, 'event_log_retention_'):
                self.clean(**options)
            self.assertEqual(self.path.read_bytes(), content)
            self.assertEqual(list(self.root.glob('.sls-retention-*')), [])

    def test_copy_failure_restores_original_before_unlock(self):
        original = self.path.read_bytes()
        real_copy = storage.copy_event_stream
        calls = []
        def interrupted(source, target):
            calls.append(True)
            if len(calls) == 1:
                target.seek(0); target.write(b'broken'); target.flush()
                raise OSError('fixture write interruption')
            return real_copy(source, target)
        with mock.patch.object(storage, 'copy_event_stream', side_effect=interrupted):
            with self.assertRaises(OSError): self.clean()
        self.assertEqual(len(calls), 2)
        self.assertEqual(self.path.read_bytes(), original)
        self.assertEqual(list(self.root.glob('.sls-retention-*')), [])

    def test_failed_recovery_keeps_durable_original_and_stops_next_prune(self):
        original = self.path.read_bytes()
        def interrupted(source, target):
            target.seek(0); target.write(b'broken'); target.flush()
            raise OSError('fixture disk failure')
        with mock.patch.object(storage, 'copy_event_stream', side_effect=interrupted):
            with self.assertRaises(OSError): self.clean()
        backup = self.root / '.sls-retention-events.jsonl.backup'
        self.assertEqual(backup.read_bytes(), original)
        self.assertEqual(backup.stat().st_mode & 0o777, 0o600)
        with self.assertRaisesRegex(RuntimeError, 'recovery_required') as failure: self.clean()
        self.assertIn(str(backup), str(failure.exception))
        self.assertIn('Preserve the live log', str(failure.exception))
        self.assertEqual(backup.read_bytes(), original)

    def test_symbolic_and_hard_links_are_refused(self):
        victim = self.root / 'victim'; victim.write_bytes(self.old + self.new)
        self.path.unlink(); self.path.symlink_to(victim)
        with self.assertRaises(OSError): self.clean()
        self.path.unlink(); self.path.hardlink_to(victim)
        with self.assertRaisesRegex(RuntimeError, 'unsafe'): self.clean()
        self.assertEqual(victim.read_bytes(), self.old + self.new)

    def test_periodic_configured_retention_runs_with_weather_disabled(self):
        config = self.root / 'mass-notifications.config'
        config.write_text(json.dumps({'enabled': '0', 'log_retention_days': 1}))
        # 2027-01-14 is 24 hours before the frozen clock; default90 would keep it.
        from datetime import datetime, timezone
        old = json.dumps({'logged_at': datetime.fromtimestamp(self.now - 2 * 86400, timezone.utc).isoformat()}).encode() + b'\n'
        self.path.write_bytes(old + self.new)
        with mock.patch.object(storage.time, 'time', return_value=self.now):
            storage.prune_configured_event_logs(self.root, [self.path])
        self.assertEqual(self.path.read_bytes(), self.new)

    def test_one_retention_failure_does_not_disable_other_stages(self):
        with mock.patch.object(storage, 'storage_summary'), mock.patch.object(storage, 'prune_audit'), \
                mock.patch.object(storage, 'prune_announcement_jobs'), \
                mock.patch.object(storage, 'prune_configured_event_logs', side_effect=RuntimeError('event_log_retention_oversized')), \
                mock.patch.object(storage, 'prune_generated_media') as media, mock.patch('builtins.print'):
            self.assertEqual(storage.main(), 1)
            media.assert_called_once_with()

    def test_actual_service_account_under_root_owned_log_directory(self):
        account = pwd.getpwnam('asterisk')
        self.root.chmod(0o755)
        helper = self.root / 'storage.py'; helper.write_bytes(Path(storage.__file__).read_bytes()); helper.chmod(0o644)
        sibling = self.root / 'sls_audio_state.py'; sibling.write_bytes(Path(storage.__file__).with_name('sls_audio_state.py').read_bytes()); sibling.chmod(0o644)
        crypto_helper = self.root / 'sls_config_crypto.py'; crypto_helper.write_bytes(Path(storage.__file__).with_name('sls_config_crypto.py').read_bytes()); crypto_helper.chmod(0o644)
        for scenario in ('success', 'copy_failure', 'restore_failure', 'legacy_backup', 'legacy_kept', 'linked_recovery'):
            with self.subTest(scenario=scenario):
                base = self.root / scenario; base.mkdir(mode=0o755); base.chmod(0o755)
                logs = base / 'logs'; logs.mkdir(mode=0o755); logs.chmod(0o755)
                data = base / 'data'; data.mkdir(mode=0o750); os.chown(data, account.pw_uid, account.pw_gid)
                live = logs / 'events.jsonl'; live.write_bytes(self.old + self.new); live.chmod(0o640)
                os.chown(live, account.pw_uid, account.pw_gid)
                config = data / 'mass-notifications.config'; config.write_text('{"log_retention_days":1}')
                os.chown(config, account.pw_uid, account.pw_gid); config.chmod(0o640)
                evidence = None
                if scenario.startswith('legacy_'):
                    evidence = logs / ('.sls-retention-events.jsonl.' + scenario.removeprefix('legacy_'))
                    evidence.write_bytes(b'original recovery evidence'); evidence.chmod(0o600)
                if scenario == 'linked_recovery':
                    (data / 'event-log-recovery').symlink_to(logs)
                code = textwrap.dedent("""
                    import importlib.util,json,sys
                    from pathlib import Path
                    spec=importlib.util.spec_from_file_location('retention',sys.argv[1]);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
                    m.time.time=lambda:1800000000
                    scenario=sys.argv[4]
                    if scenario in ('copy_failure','restore_failure'):
                        real=m.copy_event_stream
                        calls=[]
                        def broken(source,target):
                            calls.append(1)
                            if scenario=='restore_failure' or len(calls)==1:
                                target.seek(0);target.write(b'broken');target.flush()
                                raise OSError('inert disk failure')
                            return real(source,target)
                        m.copy_event_stream=broken
                    try:m.prune_configured_event_logs(Path(sys.argv[2]),[Path(sys.argv[3])])
                    except Exception as error:
                        print(type(error).__name__+': '+str(error));sys.exit(7)
                """)
                before = live.stat()
                result = subprocess.run(['/usr/sbin/runuser','-u','asterisk','--','/usr/bin/python3','-B','-I','-c',code,
                                         str(helper),str(data),str(live),scenario],capture_output=True,text=True,timeout=5)
                self.assertEqual(result.returncode,0 if scenario=='success' else 7,result.stdout+result.stderr)
                after=live.stat()
                self.assertEqual((before.st_dev,before.st_ino,before.st_uid,before.st_mode),(after.st_dev,after.st_ino,after.st_uid,after.st_mode))
                self.assertEqual((logs.stat().st_uid,logs.stat().st_mode & 0o777),(0,0o755))
                if scenario=='restore_failure':
                    backup=data/'event-log-recovery/.sls-retention-events.jsonl.backup'
                    self.assertEqual(backup.read_bytes(),self.old+self.new)
                    self.assertEqual(backup.stat().st_mode & 0o777,0o600)
                    second=subprocess.run(['/usr/sbin/runuser','-u','asterisk','--','/usr/bin/python3','-B','-I','-c',code,
                                           str(helper),str(data),str(live),'success'],capture_output=True,text=True,timeout=5)
                    self.assertEqual(second.returncode,7);self.assertIn(str(backup),second.stdout)
                    self.assertEqual(backup.read_bytes(),self.old+self.new)
                else:
                    self.assertEqual(live.read_bytes(),self.new if scenario=='success' else self.old+self.new)
                if evidence:
                    self.assertEqual(evidence.read_bytes(),b'original recovery evidence')
                    self.assertIn(str(evidence),result.stdout)

    def test_alert_producers_cannot_bypass_periodic_recovery_guard(self):
        scripts = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin'
        for filename in ('sls_mass_notify_nws_poll.sh', 'sls_mass_notify_test.sh'):
            source = (scripts / filename).read_text()
            self.assertNotIn('prune_event_log', source)
            self.assertIn('fcntl.flock(handle.fileno(), fcntl.LOCK_EX)', source)
            self.assertIn('EVENTS_LOG', source)


if __name__ == '__main__':
    unittest.main()
