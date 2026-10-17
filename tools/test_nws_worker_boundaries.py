#!/usr/bin/env python3
"""Exercise Weather shell boundaries using private files and no delivery."""
import base64
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh'
SOURCE = WORKER.read_text()
HELPER = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify/sls_nws_delivery_claims.py'
QUEUE = HELPER.with_name('sls_weather_queue.py')
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('nws_worker_claim_boundaries', HELPER)
claims = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(claims)
NOTIFY_SPEC = importlib.util.spec_from_file_location('nws_stdin_notify', HELPER.with_name('sls_notify.py'))
notify = importlib.util.module_from_spec(NOTIFY_SPEC)
NOTIFY_SPEC.loader.exec_module(notify)


def function(name):
    return name + '() {' + SOURCE.split(name + '() {', 1)[1].split('\n}', 1)[0] + '\n}\n'


def shell(source, variables=None):
    settings = '\n'.join(key + '=' + shlex.quote(str(value)) for key, value in (variables or {}).items())
    return subprocess.run(['bash', '-c', settings + '\n' + source], text=True,
                          capture_output=True, timeout=8)


class WorkerBoundaries(unittest.TestCase):
    def parsed_records(self, raw):
        with tempfile.TemporaryDirectory(prefix='sls-nws-parser-') as temporary:
            payload = Path(temporary) / 'source.json'
            payload.write_text(raw)
            start = SOURCE.index('\nPARSED_ALERTS="$(')
            end = SOURCE.index('\nPARSE_RC=$?', start) + len('\nPARSE_RC=$?')
            script = 'export NWS_SOURCE_HELPER\nALERTS="$(cat ' + shlex.quote(str(payload)) + ')"\n' + SOURCE[start:end]
            script += '\n[ "$PARSE_RC" -eq 0 ] || exit "$PARSE_RC"\n'
            script += 'printf "%s\\n" "$PARSED_ALERTS" | while IFS=$\'\\t\' read -r id event severity kind key payload; do printf "%s|%s|%s|%s|%s\\n" "$id" "$event" "$severity" "$kind" "$key"; done'
            return shell(script, {'NWS_SOURCE_HELPER': QUEUE})

    def test_provider_parser_rejects_ambiguous_nonfinite_and_frame_control_fields(self):
        feature = {'id': 'https://api.weather.gov/alerts/fixture', 'properties': {'event': 'Heat Advisory',
            'severity': 'Moderate', 'status': 'Actual', 'messageType': 'Alert', 'expires': '2099-01-01T00:00:00Z'}}
        normal = json.dumps(feature)
        invalid = ['{"type":"FeatureCollection","features":[],"features":[' + normal + ']}',
            '{"type":"FeatureCollection","features":[' + normal + '],"metric":1e999}']
        for field in ['event', 'severity', 'messageType']:
            changed = json.loads(normal)
            changed['properties'][field] += '\nsecond frame'
            invalid.append(json.dumps({'type': 'FeatureCollection', 'features': [changed]}))
        for raw in invalid:
            with self.subTest(raw=raw[:100]):
                result = self.parsed_records(raw)
                self.assertNotEqual(result.returncode, 0, 'Ambiguous provider data reached the shell frame')

    def test_absent_provider_severity_does_not_shift_shell_fields(self):
        raw = json.dumps({'type': 'FeatureCollection', 'features': [{'id': 'fixture', 'properties': {
            'event': 'Heat Advisory', 'status': 'Actual', 'messageType': 'Alert', 'expires': '2099-01-01T00:00:00Z'}}]})
        result = self.parsed_records(raw)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'fixture|Heat Advisory|Unknown|Alert|Heat Advisory|fixture')

    def test_actual_alert_missing_message_type_is_rejected(self):
        raw = json.dumps({'type': 'FeatureCollection', 'features': [{'id': 'fixture', 'properties': {
            'event': 'Heat Advisory', 'status': 'Actual', 'expires': '2099-01-01T00:00:00Z'}}]})
        self.assertNotEqual(self.parsed_records(raw).returncode, 0)

    def test_large_valid_weather_geometry_preserves_spoken_instructions(self):
        feature = {'id': 'fixture-large', 'geometry': {'type': 'LineString', 'coordinates': [[41, -90]] * 10000},
            'properties': {'event': 'Tornado Warning', 'status': 'Actual', 'messageType': 'Alert',
                'expires': '2099-01-01T00:00:00Z', 'areaDesc': 'Fixture Area',
                'instruction': 'Use the east shelter entrance and avoid the flooded west road.'}}
        encoded = json.dumps(feature).encode()
        self.assertLess(len(encoded), 256 * 1024)
        payload = base64.b64encode(encoded).decode()
        self.assertGreater(len(payload), 131072)
        with tempfile.TemporaryDirectory(prefix='sls-nws-large-feature-') as temporary:
            path = Path(temporary) / 'feature.b64'
            path.write_text(payload)
            result = shell(function('build_tts_text') + 'payload="$(cat "$FEATURE")"\nbuild_tts_text "$payload"',
                           {'FEATURE': path})
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn(feature['properties']['instruction'], result.stdout)

    def test_visual_cli_stdin_retains_large_feature_and_bounds_bad_input(self):
        feature = {'id': 'fixture-large', 'properties': {'event': 'Tornado Warning'},
                   'geometry': {'type': 'LineString', 'coordinates': [[41, -90]] * 10000}}
        payload = base64.b64encode(json.dumps(feature).encode())
        for raw, expected in [(payload, 0), (b'A' * 400000, 1),
                              (base64.b64encode(b'{"properties":{"event":"first","event":"second"}}'), 1),
                              (base64.b64encode(b'{"properties":{"metric":1e999}}'), 1)]:
            with self.subTest(expected=expected), \
                 mock.patch.object(sys, 'argv', ['notify', '--alert-json-b64-stdin', '--api-only', '--desktop-targets', 'fixture']), \
                 mock.patch.object(sys, 'stdin', type('PrivateInput', (), {'buffer': io.BytesIO(raw)})()), \
                 mock.patch.object(notify, 'load_config', return_value={'logging': {}}), \
                 mock.patch.object(notify, 'setup_logging'), \
                 mock.patch.object(notify, 'push_alert') as sender, \
                 mock.patch.object(notify.logging, 'error'), \
                 mock.patch.object(sys, 'stderr', io.StringIO()):
                self.assertEqual(notify.main(), expected)
                if expected == 0:
                    self.assertEqual(sender.call_args.args[1], feature)
                else:
                    sender.assert_not_called()

    def test_valid_quiet_windows_and_explicit_critical_override(self):
        definitions = function('time_to_minutes') + function('quiet_hours_active') + function('quiet_hours_allows_event')
        for enabled, start, end, now, expected in [(1, '21:00', '06:00', '23:00', 0),
                (1, '21:00', '06:00', '12:00', 1), (1, '09:00', '17:00', '10:00', 0),
                (1, '09:00', '17:00', '17:00', 1), (1, '12:00', '12:00', '12:00', 1),
                (0, 'invalid', 'invalid', 'invalid', 1)]:
            result = shell(definitions + 'quiet_hours_active', {'QUIET_HOURS_ENABLED': enabled,
                'QUIET_HOURS_START': start, 'QUIET_HOURS_END': end, 'NWS_ALERTS_NOW': now})
            self.assertEqual(result.returncode, expected, result.stderr)
        result = shell(definitions + "QUIET_HOURS_CRITICAL_EVENTS=('Tornado Warning')\n"
                       "quiet_hours_allows_event 'Tornado Warning'")
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_enabled_invalid_quiet_times_suppress_noncritical_delivery(self):
        definitions = function('time_to_minutes') + function('quiet_hours_active')
        for start, end, now in [('invalid', 'invalid', '12:00'), ('25:00', '06:00', '12:00'),
                                ('21:00', '06:99', '12:00'), ('21:00', '06:00', 'invalid')]:
            with self.subTest(start=start, end=end, now=now):
                result = shell(definitions + 'quiet_hours_active', {'QUIET_HOURS_ENABLED': 1,
                    'QUIET_HOURS_START': start, 'QUIET_HOURS_END': end, 'NWS_ALERTS_NOW': now})
                self.assertEqual(result.returncode, 0, 'Malformed enabled quiet hours allowed delivery')

    def test_critical_speech_preserves_official_instructions(self):
        for event in ['Tornado Warning', 'Flash Flood Warning', 'Severe Thunderstorm Warning',
                      'Evacuation Immediate', 'Heat Advisory', 'Winter Storm Warning', 'Red Flag Warning']:
            with self.subTest(event=event):
                instruction = 'Use the EAST shelter entrance. Do not enter the flooded west access road.'
                feature = {'properties': {'event': event, 'areaDesc': 'Fixture Area', 'instruction': instruction}}
                payload = base64.b64encode(json.dumps(feature).encode()).decode()
                result = shell(function('build_tts_text') + 'build_tts_text ' + shlex.quote(payload))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(instruction, result.stdout)

    def test_audio_lock_symlink_does_not_change_target(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-lock-boundary-') as temporary:
            directory = Path(temporary)
            target = directory / 'protected-evidence'
            target.write_bytes(b'preserve this evidence\n')
            before = target.stat()
            lock = directory / 'audio.lock'
            lock.symlink_to(target)
            definitions = function('acquire_audio_delivery_slot') + function('release_audio_delivery_slot')
            if 'start_nws_lock' in SOURCE:
                definitions = function('start_nws_lock') + definitions
            result = shell(definitions + '\nacquire_audio_delivery_slot; status=$?\n'
                           'release_audio_delivery_slot\nexit "$status"',
                           {'NWS_AUDIO_DELIVERY_LOCK': lock, 'NWS_AUDIO_LOCK_FD': '',
                            'NWS_CROSS_ZONE_CLAIM_HELPER': HELPER, 'LOG': directory / 'log'})
            self.assertNotEqual(result.returncode, 0, 'Symlink lock was accepted')
            self.assertEqual(target.read_bytes(), b'preserve this evidence\n')
            after = target.stat()
            self.assertEqual((before.st_mode, before.st_uid, before.st_gid),
                             (after.st_mode, after.st_uid, after.st_gid))

    def test_worker_lock_lease_lasts_until_shell_closes_pipe(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-lock-lifetime-') as temporary:
            directory = Path(temporary)
            lock = directory / 'poll.lock'
            lock.touch(mode=0o644)
            inode = lock.stat().st_ino
            process = subprocess.Popen(['/usr/bin/python3', '-I', str(HELPER), '--hold-lock', str(lock), '0'],
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                self.assertEqual(process.stdout.readline(), 'locked\n')
                with lock.open('r+') as descriptor:
                    with self.assertRaises(BlockingIOError):
                        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    second = subprocess.run(['/usr/bin/python3', '-I', str(HELPER), '--hold-lock', str(lock), '0'],
                                            text=True, capture_output=True, timeout=5)
                    self.assertEqual(second.returncode, 75, second.stderr)
                    self.assertEqual(second.stdout, 'busy\n')
                    process.stdout.close()
                    self.assertEqual(process.wait(timeout=5), 0)
                    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.assertEqual(lock.stat().st_ino, inode)
                self.assertEqual(lock.read_bytes(), b'')
                self.assertEqual(lock.stat().st_mode & 0o777, 0o640)
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                if not process.stdout.closed:
                    process.stdout.close()
                process.stderr.close()

    def test_busy_legacy_lock_is_not_repaired_before_lease(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-busy-lock-') as temporary:
            lock = Path(temporary) / 'legacy.lock'
            lock.touch(mode=0o644)
            with lock.open('r+') as descriptor:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                before = lock.stat()
                result = subprocess.run(['/usr/bin/python3', '-I', str(HELPER), '--hold-lock', str(lock), '0'],
                                        text=True, capture_output=True, timeout=5)
                self.assertEqual(result.returncode, 75, result.stderr)
                after = lock.stat()
                self.assertEqual((before.st_ino, before.st_mode, before.st_uid, before.st_gid),
                                 (after.st_ino, after.st_mode, after.st_uid, after.st_gid))

    def test_poll_startup_rejects_symlink_before_any_polling(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-poll-lock-') as temporary:
            directory = Path(temporary)
            target = directory / 'target'
            target.write_bytes(b'keep\n')
            lock = directory / 'poll.lock'
            lock.symlink_to(target)
            start = SOURCE.index('\nNWS_POLL_LOCK_FD=""')
            end = SOURCE.index('\ntimestamp_now() {', start)
            result = shell(function('start_nws_lock') + SOURCE[start:end] + '\necho UNSAFE_POLL',
                {'LOCK_FILE': lock, 'NWS_CROSS_ZONE_CLAIM_HELPER': HELPER, 'LOG': directory / 'log'})
            self.assertEqual(result.returncode, 1, result.stderr)
            self.assertNotIn('UNSAFE_POLL', result.stdout)
            self.assertEqual(target.read_bytes(), b'keep\n')

    def test_shell_audio_release_and_exit_release_real_lease(self):
        definitions = function('start_nws_lock') + function('acquire_audio_delivery_slot') + function('release_audio_delivery_slot')
        with tempfile.TemporaryDirectory(prefix='sls-nws-shell-lock-') as temporary:
            directory = Path(temporary)
            lock = directory / 'audio.lock'
            for ending in ['release_audio_delivery_slot', ':']:
                result = shell(definitions + '\nacquire_audio_delivery_slot || exit 1\n' + ending,
                               {'NWS_AUDIO_DELIVERY_LOCK': lock, 'NWS_AUDIO_LOCK_FD': '',
                                'NWS_CROSS_ZONE_CLAIM_HELPER': HELPER})
                self.assertEqual(result.returncode, 0, result.stderr)
                # Pipe-close notification is asynchronous; bound the observer's wait.
                with lock.open('r+') as descriptor:
                    deadline = time.monotonic() + 2
                    while True:
                        try:
                            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                            break
                        except BlockingIOError:
                            self.assertLess(time.monotonic(), deadline, 'Shell exit retained its lease')
                            time.sleep(0.01)

    def test_worker_lock_rejects_unknown_contents_links_and_permissions(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-bad-lock-') as temporary:
            directory = Path(temporary)
            for kind in ['contents', 'hardlink', 'mode', 'fifo', 'owner']:
                with self.subTest(kind=kind):
                    lock = directory / kind
                    if kind == 'fifo':
                        os.mkfifo(lock)
                    else:
                        lock.write_bytes(b'keep' if kind == 'contents' else b'')
                        lock.chmod(0o640)
                    if kind == 'hardlink':
                        os.link(lock, directory / 'second-name')
                    if kind == 'mode':
                        lock.chmod(0o666)
                    if kind == 'owner':
                        if os.geteuid() != 0:
                            continue
                        os.chown(lock, 65534, 65534)
                    before = lock.stat()
                    result = subprocess.run(['/usr/bin/python3', '-I', str(HELPER), '--hold-lock', str(lock), '0'],
                                            text=True, capture_output=True, timeout=5)
                    self.assertNotEqual(result.returncode, 0, result.stderr)
                    after = lock.stat()
                    self.assertEqual((before.st_ino, before.st_mode, before.st_uid, before.st_gid, before.st_size),
                                     (after.st_ino, after.st_mode, after.st_uid, after.st_gid, after.st_size))

    def test_dedup_initialization_preserves_heat_advisory_and_oldest_records(self):
        start = SOURCE.index('\ntouch "$SEEN_ALERTS"') if '\ntouch "$SEEN_ALERTS"' in SOURCE else SOURCE.index('\nprepare_alert_history ||')
        end = SOURCE.index('\necho "$(date): Checking NWS alerts', start)
        initialization = SOURCE[start:end]
        definitions = 'timestamp_now() { date --iso-8601=seconds; }\n'
        if 'prepare_alert_history() {' in SOURCE:
            definitions += function('legacy_alert_history') + function('prepare_alert_history')
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-preserve-') as temporary:
            directory = Path(temporary)
            paths = [directory / name for name in ['seen.txt', 'processed.txt', 'audio.txt']]
            content = 'Heat Advisory|still-active\n' + ''.join('Severe Thunderstorm Warning|old-' + str(i) + '\n' for i in range(5001))
            for path in paths:
                path.write_text(content)
            before = [path.read_bytes() for path in paths]
            shell(definitions + initialization, {'SEEN_ALERTS': paths[0], 'PROCESSED_ALERTS': paths[1],
                'AUDIO_DELIVERED_ALERTS': paths[2], 'NWS_CROSS_ZONE_CLAIM_HELPER': HELPER})
            self.assertTrue([path.read_bytes() for path in paths] == before,
                            'Initialization erased existing advisory/oldest history')

    def test_dedup_initialization_rejects_symlink_without_permission_changes(self):
        start = SOURCE.index('\ntouch "$SEEN_ALERTS"') if '\ntouch "$SEEN_ALERTS"' in SOURCE else SOURCE.index('\nprepare_alert_history ||')
        end = SOURCE.index('\necho "$(date): Checking NWS alerts', start)
        definitions = 'timestamp_now() { date --iso-8601=seconds; }\n'
        if 'prepare_alert_history() {' in SOURCE:
            definitions += function('legacy_alert_history') + function('prepare_alert_history')
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-link-') as temporary:
            directory = Path(temporary)
            victim = directory / 'victim'
            victim.write_bytes(b'preserve\n')
            victim.chmod(0o600)
            before = victim.stat()
            seen = directory / 'seen.txt'
            seen.symlink_to(victim)
            result = shell(definitions + SOURCE[start:end], {'SEEN_ALERTS': seen,
                'PROCESSED_ALERTS': directory / 'processed.txt', 'AUDIO_DELIVERED_ALERTS': directory / 'audio.txt',
                'NWS_CROSS_ZONE_CLAIM_HELPER': HELPER})
            self.assertNotEqual(result.returncode, 0, 'Symlink history was accepted')
            self.assertEqual(victim.read_bytes(), b'preserve\n')
            self.assertEqual((victim.stat().st_mode, victim.stat().st_uid, victim.stat().st_gid),
                             (before.st_mode, before.st_uid, before.st_gid))

    def test_legacy_history_mutations_and_required_marker_preserve_evidence(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-lease-') as temporary:
            path = Path(temporary) / 'history.txt'
            path.write_bytes(b'Heat Advisory|existing\n')
            claims.legacy_alert_history(path, 'ensure')
            lock = Path(str(path) + '.lock')
            inode = lock.stat().st_ino
            self.assertEqual(lock.read_bytes(), claims.LEGACY_HISTORY_MARKER)
            self.assertTrue(claims.legacy_alert_history(path, 'contains', 'Heat Advisory|existing'))
            self.assertFalse(claims.legacy_alert_history(path, 'contains', 'Heat Advisory|new'))
            self.assertFalse(claims.legacy_alert_history(path, 'add', 'Heat Advisory|new'))
            self.assertTrue(claims.legacy_alert_history(path, 'add', 'Heat Advisory|new'))
            self.assertEqual(path.read_bytes(), b'Heat Advisory|existing\nHeat Advisory|new\n')
            self.assertTrue(claims.legacy_alert_history(path, 'remove', 'Heat Advisory|new'))
            self.assertEqual(path.read_bytes(), b'Heat Advisory|existing\n')
            self.assertEqual(lock.stat().st_ino, inode)
            path.unlink()
            with self.assertRaisesRegex(claims.CoordinationError, 'established legacy history is missing'):
                claims.legacy_alert_history(path, 'ensure')
            self.assertFalse(path.exists())
            self.assertEqual(lock.stat().st_ino, inode)

    def test_full_history_refuses_new_delivery_without_pruning_known_alerts(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-full-') as temporary:
            path = Path(temporary) / 'history.txt'
            content = ''.join('Heat Advisory|' + str(i) + '\n' for i in range(claims.MAX_LEGACY_HISTORY_LINES)).encode()
            path.write_bytes(content)
            claims.legacy_alert_history(path, 'ensure')
            self.assertTrue(claims.legacy_alert_history(path, 'contains', 'Heat Advisory|0'))
            with self.assertRaisesRegex(claims.CoordinationError, 'new delivery was not admitted'):
                claims.legacy_alert_history(path, 'contains', 'Heat Advisory|new')
            with self.assertRaisesRegex(claims.CoordinationError, 'no active records were discarded'):
                claims.legacy_alert_history(path, 'add', 'Heat Advisory|new')
            self.assertTrue(path.read_bytes() == content)

    def test_interrupted_history_marker_resumes_with_retained_records(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-marker-') as temporary:
            path = Path(temporary) / 'history.txt'
            path.write_bytes(b'Heat Advisory|existing\n')
            lock = Path(str(path) + '.lock')
            lock.write_bytes(claims.LEGACY_HISTORY_MARKER[:7])
            inode = lock.stat().st_ino
            claims.legacy_alert_history(path, 'ensure')
            self.assertEqual(path.read_bytes(), b'Heat Advisory|existing\n')
            self.assertEqual(lock.read_bytes(), claims.LEGACY_HISTORY_MARKER)
            self.assertEqual(lock.stat().st_ino, inode)

    def test_changed_history_is_not_overwritten_during_permission_repair(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-repair-race-') as temporary:
            path = Path(temporary) / 'history.txt'
            path.write_bytes(b'Heat Advisory|original\n')
            original = claims._secure_file_metadata
            def competing_write(descriptor):
                if os.fstat(descriptor).st_ino == path.stat().st_ino:
                    path.write_bytes(b'Heat Advisory|competing-update\n')
                return original(descriptor)
            with mock.patch.object(claims, '_secure_file_metadata', side_effect=competing_write):
                with self.assertRaisesRegex(claims.CoordinationError, 'contents changed during permission repair'):
                    claims.legacy_alert_history(path, 'add', 'Heat Advisory|new')
            self.assertEqual(path.read_bytes(), b'Heat Advisory|competing-update\n')

    def test_parent_changed_during_temporary_write_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='sls-nws-dedup-parent-race-') as temporary:
            root = Path(temporary)
            directory = root / 'parent'
            directory.mkdir()
            path = directory / 'history.txt'
            path.write_bytes(b'Heat Advisory|original\n')
            original = claims.os.fsync
            changed = False
            def rename_parent(descriptor):
                nonlocal changed
                if not changed:
                    changed = True
                    directory.rename(root / 'retained-parent')
                    directory.mkdir()
                return original(descriptor)
            with mock.patch.object(claims.os, 'fsync', side_effect=rename_parent):
                with self.assertRaisesRegex(claims.CoordinationError, 'parent changed before commit'):
                    claims.legacy_alert_history(path, 'add', 'Heat Advisory|new')
            self.assertFalse(path.exists())
            self.assertEqual((root / 'retained-parent/history.txt').read_bytes(), b'Heat Advisory|original\n')
            self.assertFalse(list((root / 'retained-parent').glob('*.tmp.*')))


if __name__ == '__main__':
    unittest.main()
