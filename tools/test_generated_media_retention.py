#!/usr/bin/python3
"""Disposable, no-render/no-playback tests of periodic generated-media cleanup."""
import fcntl
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from contextlib import contextmanager
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_storage_maintenance as storage


class RetentionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-media-retention-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.data = self.root / 'data'; self.data.mkdir()
        self.audio = self.data / 'sounds/tts'; self.audio.mkdir(parents=True)
        self.web = self.root / 'web'; self.web.mkdir()
        self.spool = self.root / 'outgoing'; self.spool.mkdir()
        self.now = 1800000000
        self.config = self.data / 'mass-notifications.config'; self.config.write_text('{"enabled":"0"}'); self.config.chmod(0o640)
        self.reservations = self.data / 'audio-reservations.json'
        self.reservations.write_text(json.dumps({'recipients': {}, 'media': {}, 'waiting': {}}))

    def stale(self, directory, name, age=400000):
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / name; path.write_text('fixture')
        os.utime(path, (self.now - age, self.now - age))
        return path

    def clean(self):
        return storage.prune_generated_media(self.data, self.web, self.spool, self.now)

    def test_idle_periodic_cleanup_protects_all_reference_sources(self):
        protected = []
        for suffix in ('leased', 'waiting', 'scheduled', 'pending', 'job', 'weather', 'spooled'):
            protected.append(self.stale(self.audio, 'announcement_tts_' + suffix + '.wav'))
        self.reservations.write_text(json.dumps({'recipients': {}, 'media': {'announcement_tts_leased.wav': self.now + 900},
            'waiting': {'a' * 32: {'recipients': ['1000'], 'priority': 1, 'created': self.now, 'duration': 10, 'media_name': 'announcement_tts_waiting.wav', 'expires': self.now + 50, 'heartbeat': self.now + 10}}}))
        self.config.write_text(json.dumps({'enabled': '0', 'scheduled_announcements': [
            {'delivery': {'audio_sequence': 'SLS_Mass_Notifications_Plugin/tts/announcement_tts_scheduled'}}]}))
        (self.data / 'mass-notifications.pending.config').write_text(json.dumps({'sound': 'announcement_tts_pending.wav'}))
        (self.data / 'mass-notifications.pending.config').chmod(0o640)
        jobs = self.data / 'announcement-jobs'; jobs.mkdir()
        job_id = 'job_' + 'a' * 32
        job = jobs / (job_id + '.json')
        job.write_text(json.dumps({'id': job_id, 'state': 'worker_starting', 'request': {'sound': 'announcement_tts_job.wav'}}))
        os.utime(job, (self.now - 4000, self.now - 4000))
        (jobs / ('pending_' + job_id + '.mark')).touch()
        (self.data / 'weather-delivery.json').write_text(json.dumps({'jobs': {
            'queued': {'state': 'queued', 'payload': {'audio_sequence': 'SLS_Mass_Notifications_Plugin/tts/announcement_tts_weather'}},
            'terminal': {'state': 'complete', 'key': 'compact-dedup-only'},
        }}))
        (self.spool / 'sls_fixture.call').write_text('Setvar: SLS_SOUND=SLS_Mass_Notifications_Plugin/tts/announcement_tts_spooled\n')
        image = self.stale(self.web, 'alert_' + 'a' * 12 + '_' + 'b' * 20 + '.png')
        xml = self.stale(self.web, 'phone_payload_' + 'c' * 12 + '_' + 'd' * 20 + '.xml')
        journal = self.data / 'sipnotify'; journal.mkdir()
        (journal / 'sipnotify_events.jsonl').write_text(json.dumps({'image_url': 'https://fixture/' + image.name, 'xml': '<URL>https://fixture/' + xml.name + '</URL>'}) + '\n')
        protected += [image, xml, self.stale(self.audio, 'custom-user-recording.wav'),
                      self.stale(self.data / 'sounds/tones', 'user-tone.wav'),
                      self.stale(self.data / 'piper/voices', 'user-voice.onnx')]
        orphan = self.stale(self.audio, 'nws_alert_orphan.wav')
        orphan_image = self.stale(self.web, 'announcement_20260920120000_' + 'a' * 24 + '.png')
        orphan_xml = self.stale(self.web, 'phone_payload_' + 'f' * 12 + '.xml')
        recent = self.stale(self.audio, 'xweather_sequence_recent.wav', age=899)
        # No new image generation and no enabled weather service is required.
        result = self.clean()
        self.assertEqual(result, {'audio_removed': 1, 'images_removed': 2})
        self.assertTrue(all(path.exists() for path in protected + [recent]))
        self.assertTrue(all(not path.exists() for path in [orphan, orphan_image, orphan_xml]))

    def test_exact_playback_end_plus_fifteen_minutes(self):
        path = self.stale(self.audio, 'nws_sequence_reserved.wav')
        self.reservations.write_text(json.dumps({'recipients': {}, 'media': {path.name: self.now + 905}}))
        self.now += 904; self.clean(); self.assertTrue(path.exists())
        self.now += 1; self.clean(); self.assertFalse(path.exists())

    def sized_image(self, suffix, mib, age):
        path = self.stale(self.web, 'alert_' + suffix * 12 + '.png', age=age)
        with path.open('r+b') as output: output.truncate(mib * 1024 * 1024)
        os.utime(path, (self.now - age, self.now - age))
        return path

    def test_combined_quota_removes_oldest_unused_image_before_age_expiry(self):
        self.config.write_text(json.dumps({'generated_media_cache_mib': 64}))
        audio = self.stale(self.audio, 'announcement_tts_protected.wav')
        with audio.open('r+b') as output: output.truncate(30 * 1024 * 1024)
        self.reservations.write_text(json.dumps({'recipients': {}, 'media': {audio.name: self.now + 1000}}))
        older = self.sized_image('a', 40, 7200)
        newer = self.sized_image('b', 20, 3600)
        self.assertEqual(self.clean(), {'audio_removed': 0, 'images_removed': 1})
        self.assertFalse(older.exists()); self.assertTrue(newer.exists() and audio.exists())

    def test_under_target_keeps_unexpired_images(self):
        self.config.write_text(json.dumps({'generated_media_cache_mib': 64}))
        image = self.sized_image('a', 32, 3600)
        self.assertEqual(self.clean()['images_removed'], 0)
        self.assertTrue(image.exists())

    def test_active_and_recent_media_survive_pressure_and_warning_clears_after_recovery(self):
        self.config.write_text(json.dumps({'generated_media_cache_mib': 64}))
        protected = self.sized_image('a', 40, 7200)
        recent = self.sized_image('b', 40, 899)
        journal = self.data / 'sipnotify'; journal.mkdir()
        record = journal / 'sipnotify_events.jsonl'
        record.write_text(json.dumps({'image_url': 'https://fixture/' + protected.name}) + '\n')
        with self.assertRaisesRegex(RuntimeError, 'Active/referenced/recent files were preserved'):
            self.clean()
        self.assertTrue(protected.exists() and recent.exists())
        with mock.patch.object(storage, 'WEB', self.web):
            summary = storage.storage_summary(self.data, self.now)
            self.assertTrue(summary['media_over_budget']); self.assertFalse(summary['media_scan_incomplete'])
            record.write_text('')
            self.assertEqual(self.clean()['images_removed'], 1)
            self.assertFalse(storage.storage_summary(self.data, self.now)['media_over_budget'])
        self.assertTrue(recent.exists())

    def test_incomplete_inventory_or_invalid_limit_preserves_files(self):
        files = [self.sized_image(c, 1, 400000) for c in 'abc']
        with mock.patch.object(storage, 'MAX_SCAN', 2):
            with self.assertRaisesRegex(RuntimeError, 'inventory exceeded'): self.clean()
        for value in (0, 63, 4097, '64', True, None, []):
            self.config.write_text(json.dumps({'generated_media_cache_mib': value}))
            with self.assertRaisesRegex(RuntimeError, 'cache target'): self.clean()
        self.assertTrue(all(path.exists() for path in files))

    def test_unknown_or_unsafe_references_stop_deletion(self):
        path = self.stale(self.audio, 'announcement_tts_keep.wav')
        for value in ('{broken', json.dumps({'recipients': {}, 'media': {path.name: float('nan')}}), json.dumps({'recipients': {}, 'media': [1], 'waiting': {}})):
            self.reservations.write_text(value)
            with self.assertRaises((ValueError, RuntimeError)):
                self.clean()
            self.assertTrue(path.exists())
        self.reservations.write_text('{"recipients":{},"media":{}}')
        self.config.write_text('{broken')
        with self.assertRaises(ValueError): self.clean()
        self.assertTrue(path.exists())
        self.config.write_text('{}')
        victim = self.root / 'victim'; victim.write_text('unchanged')
        self.reservations.unlink(); self.reservations.symlink_to(victim)
        with self.assertRaises(OSError): self.clean()
        self.assertEqual(victim.read_text(), 'unchanged')
        self.assertTrue(path.exists())

    def test_links_and_unrelated_files_are_never_removed(self):
        victim = self.stale(self.root, 'victim')
        hard = self.audio / 'announcement_tts_hard.wav'; os.link(victim, hard)
        link = self.audio / 'announcement_tts_link.wav'; link.symlink_to(victim)
        self.clean()
        self.assertTrue(hard.exists() and link.is_symlink() and victim.exists())
        moved = self.root / 'other-audio'; self.audio.rename(moved); self.audio.symlink_to(moved)
        with self.assertRaises(OSError): self.clean()
        self.assertTrue(hard.exists() and victim.exists())

    def test_active_reservation_writer_and_bounded_deletion(self):
        paths = [self.stale(self.audio, 'announcement_tts_orphan_' + str(index) + '.wav') for index in range(5)]
        with (self.data / 'announcement-activity.lock').open('w') as preparing:
            fcntl.flock(preparing, fcntl.LOCK_SH)
            with self.assertRaises(BlockingIOError): self.clean()
            self.assertTrue(all(path.exists() for path in paths), 'Cached audio was deleted before the active announcement reserved playback')
        with (self.data / 'audio-reservations.lock').open('a+') as locked:
            fcntl.flock(locked, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError): self.clean()
            self.assertTrue(all(path.exists() for path in paths))
        with mock.patch.object(storage, 'MAX_DELETE', 2):
            self.assertEqual(self.clean()['audio_removed'], 2)
            self.assertEqual(self.clean()['audio_removed'], 2)
            self.assertEqual(self.clean()['audio_removed'], 1)

    def test_terminal_jobs_require_original_unlocked_inode(self):
        jobs = self.data / 'announcement-jobs'; jobs.mkdir()
        records = {}
        for index, state in enumerate(('complete', 'worker_starting', 'queued', 'running', 'failed', 'expired')):
            job_id = 'job_' + format(index, '032x')
            path = jobs / (job_id + '.json')
            path.write_text(json.dumps({'id': job_id, 'state': state}))
            os.utime(path, (self.now - 31 * 86400, self.now - 31 * 86400))
            lock = jobs / (path.name + '.lock')
            if state != 'expired': lock.touch()
            records[state] = (path, lock)
        with records['failed'][1].open('r') as held, \
                mock.patch.object(storage, 'lock_reclamation_ready', return_value=False):
            fcntl.flock(held, fcntl.LOCK_EX)
            self.assertEqual(storage.prune_announcement_jobs(jobs, self.now), 1)
        self.assertFalse(records['complete'][0].exists())
        self.assertTrue(records['complete'][1].exists(), 'Cleanup replaced or removed a lock inode')
        self.assertTrue(all(records[state][0].exists() for state in ('worker_starting', 'queued', 'running', 'failed', 'expired')))

    def test_busy_audit_does_not_block_independent_media_cleanup(self):
        orphan = self.stale(self.audio, 'announcement_tts_idle.wav')
        audit = self.data / 'control-api-audit.jsonl'; audit.write_text('{}\n')
        with audit.open('r+') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with mock.patch.object(storage, 'DATA', self.data), mock.patch.object(storage, 'WEB', self.web), \
                    mock.patch.object(storage, 'OUTGOING', self.spool), mock.patch.object(storage, 'EVENT_LOGS', ()), \
                    mock.patch.object(storage.time, 'time', return_value=self.now), mock.patch('builtins.print'):
                self.assertEqual(storage.main(), 1)
        self.assertFalse(orphan.exists())
        self.assertEqual(audit.read_text(), '{}\n')

    def test_state_reader_and_audit_refuse_hardlinks_and_busy_locks(self):
        victim = self.root / 'state'; victim.write_text('{}')
        linked = self.root / 'linked'; os.link(victim, linked)
        with self.assertRaises(ValueError): storage.read_locked_object(linked)
        with self.assertRaises(RuntimeError): storage.prune_audit(linked, self.now)
        self.assertEqual(victim.read_text(), '{}')
        with victim.open('r+') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError): storage.read_locked_object(victim)
        linked.unlink()
        with victim.open('r+') as held:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError): storage.prune_audit(victim, self.now)

    def test_historical_lock_files_do_not_starve_pending_references_or_job_cleanup(self):
        jobs = self.data / 'announcement-jobs'; jobs.mkdir()
        for index in range(20001):
            (jobs / ('job_' + format(index, '032x') + '.json.lock')).touch()
        active_id = 'job_' + 'e' * 32
        terminal_id = 'job_' + 'f' * 32
        protected = self.stale(self.audio, 'announcement_tts_queued.wav')
        orphan = self.stale(self.audio, 'announcement_tts_orphan.wav')
        active = jobs / (active_id + '.json')
        active.write_text(json.dumps({'id': active_id, 'state': 'queued', 'request': {'sound': protected.name}}))
        os.utime(active, (self.now - 4000, self.now - 4000))
        (jobs / ('pending_' + active_id + '.mark')).touch()
        terminal = jobs / (terminal_id + '.json')
        terminal.write_text(json.dumps({'id': terminal_id, 'state': 'complete'}))
        os.utime(terminal, (self.now - 31 * 86400, self.now - 31 * 86400))
        terminal_lock = jobs / (terminal.name + '.lock'); terminal_lock.touch()
        real_scandir = storage.os.scandir
        @contextmanager
        def locks_first(descriptor):
            # Ensure the regression is independent of filesystem enumeration:
            # all 20,002 real lock entries precede the two real JSON records.
            with real_scandir(descriptor) as entries:
                yield iter(sorted(entries, key=lambda entry: not entry.name.endswith('.lock')))
        with mock.patch.object(storage.os, 'scandir', side_effect=locks_first), \
                mock.patch.object(storage, 'lock_reclamation_ready', return_value=True):
            self.assertEqual(storage.prune_announcement_jobs(jobs, self.now), 1)
            self.assertEqual(self.clean()['audio_removed'], 1)
        self.assertFalse(terminal.exists() or orphan.exists())
        self.assertTrue(active.exists() and protected.exists())
        self.assertFalse(terminal_lock.exists())
        remaining = len(list(jobs.glob('*.lock')))
        self.assertGreaterEqual(remaining, 20002 - storage.MAX_DELETE)
        self.assertLess(remaining, 20002)

    def test_directory_deadline_defers_without_dropping_references(self):
        jobs = self.data / 'announcement-jobs'; jobs.mkdir()
        (jobs / ('job_' + 'a' * 32 + '.json.lock')).touch()
        protected = self.stale(self.audio, 'announcement_tts_preserved.wav')
        with mock.patch.object(storage, 'DIRECTORY_SCAN_SECONDS', -1), mock.patch('builtins.print'):
            with self.assertRaisesRegex(RuntimeError, 'time limit'):
                self.clean()
            self.assertEqual(storage.prune_announcement_jobs(jobs, self.now), 0)
        self.assertTrue(protected.exists())


if __name__ == '__main__':
    unittest.main()
