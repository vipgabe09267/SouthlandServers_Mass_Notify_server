#!/usr/bin/env python3
"""Private-only PHP/Python audio ledger contention and interrupted commits."""
import fcntl
import importlib
import json
import os
from pathlib import Path
import select
import signal
import subprocess
import sys
import tempfile
import time
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(RUNTIME))
MARKER = b'SLS_AUDIO_STATE_V1\n'


class AudioInterop(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sls-audio-interop-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.data = self.root / 'data'
        self.data.mkdir(mode=0o750)
        self.php = self.root / 'probe.php'
        self.php.write_text('''<?php
namespace SLS\\MassNotify {
function fsync($fd) {
    $meta = fstat($fd);
    if (getenv('SLS_FIXTURE_HOLD_SYNC') === 'directory' && ($meta['mode'] & 0170000) === 0040000) {
        echo "ready\\n"; flush(); fgets(STDIN);
    }
    if (getenv('SLS_FIXTURE_FAIL_SYNC') === 'directory' && ($meta['mode'] & 0170000) === 0040000) {
        return false;
    }
    return \\fsync($fd);
}
function rename($source, $destination) {
    if (getenv('SLS_FIXTURE_HOLD_RENAME') === '1') {
        echo "ready\\n"; flush(); fgets(STDIN);
    }
    return \\rename($source, $destination);
}
}
namespace {
require $argv[1];
$state = new \\SLS\\MassNotify\\LivePagingState($argv[2]);
try {
    if ($argv[3] === 'claim') { $result = $state->claim([$argv[4]], 30, 1000.0); }
    elseif ($argv[3] === 'release') { $state->release(json_decode($argv[4], true)); $result = true; }
    else { throw new \\RuntimeException('fixture_action'); }
    echo json_encode(['ok'=>true, 'result'=>$result]);
} catch (\\Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(2); }
}
''')
        self.state = importlib.import_module('sls_audio_state')

    def command(self, action='claim', argument='1000'):
        return ['/usr/bin/php', str(self.php), str(RUNTIME / 'sls_live_paging.php'),
                str(self.data), action, argument]

    def php_run(self, action='claim', argument='1000', fail=False):
        env = dict(os.environ)
        if fail:
            env['SLS_FIXTURE_FAIL_SYNC'] = 'directory'
        proc = subprocess.run(self.command(action, argument), capture_output=True, text=True,
                              timeout=6, env=env)
        self.assertIn(proc.returncode, (0, 2), proc.stderr)
        return json.loads(proc.stdout)

    def seed(self):
        with self.state.audio_state(self.data) as state:
            state['recipients']['9000'] = 1200
            state['media']['fixture.wav'] = 2100
        self.assertEqual((self.data / 'audio-reservations.lock').read_bytes(), MARKER)

    def contents(self):
        return json.loads((self.data / 'audio-reservations.json').read_text())

    def child(self, source):
        proc = subprocess.Popen([sys.executable, '-B', '-c', source, str(RUNTIME), str(self.data)],
                                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                text=True)
        def cleanup():
            if proc.poll() is None:
                proc.kill()
            proc.communicate(timeout=5)
        self.addCleanup(cleanup)
        return proc

    def ready(self, proc):
        self.assertTrue(select.select([proc.stdout], [], [], 5)[0], 'Child did not reach barrier')
        self.assertEqual(proc.stdout.readline().strip(), 'ready')

    def test_php_python_preserve_leases_and_empty_objects(self):
        claim = self.php_run()
        self.assertTrue(claim['ok'], claim)
        self.assertEqual(self.contents()['waiting'], {})
        with self.state.audio_state(self.data) as state:
            self.assertEqual(state['recipients']['1000'], 1035)
            state['recipients']['1001'] = 1040
            state['media']['fixture.wav'] = 1940
        self.assertEqual(self.php_run(argument='1001')['result'], [])
        self.assertTrue(self.php_run('release', json.dumps(claim['result']))['ok'])
        self.assertEqual(self.contents()['recipients'], {'1001': 1040})
        self.assertEqual(self.contents()['media'], {'fixture.wav': 1940})

    def test_old_claim_does_not_remove_newer_python_lease(self):
        claim = self.php_run()['result']
        with self.state.audio_state(self.data) as state:
            state['recipients']['1000'] = 1100
        self.assertTrue(self.php_run('release', json.dumps(claim))['ok'])
        self.assertEqual(self.contents()['recipients']['1000'], 1100)

    def test_python_writer_blocks_php_across_atomic_replace(self):
        self.seed()
        proc = self.child('''import sys
sys.path.insert(0, sys.argv[1])
from sls_audio_state import audio_state
with audio_state(sys.argv[2]) as state:
 state['recipients']['1000'] = 1100
 print('ready', flush=True)
 sys.stdin.readline()
''')
        self.ready(proc)
        php = subprocess.Popen(self.command(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            time.sleep(.15)
            self.assertIsNone(php.poll(), 'PHP ignored the permanent Python writer lock')
            proc.stdin.write('release\n'); proc.stdin.flush()
            self.assertEqual(proc.wait(timeout=5), 0)
            out, err = php.communicate(timeout=5)
            self.assertEqual(php.returncode, 0, err)
            self.assertEqual(json.loads(out)['result'], [], 'PHP lost the committed conflicting lease')
            self.assertEqual(self.contents()['recipients']['9000'], 1200)
        finally:
            if php.poll() is None: php.kill()
            php.communicate(timeout=5)

    def test_shared_reader_blocks_php_writer_but_allows_other_reader(self):
        self.seed()
        with self.state.read_audio_state(self.data) as state:
            with self.state.read_audio_state(self.data) as second:
                self.assertEqual(state, second)
            failure = self.php_run()
            self.assertFalse(failure['ok'], 'PHP wrote while the SH lease was held')
        self.assertTrue(self.php_run()['ok'])

    def test_interrupted_python_transaction_preserves_old_document(self):
        self.seed()
        original = (self.data / 'audio-reservations.json').read_bytes()
        proc = self.child('''import sys
sys.path.insert(0, sys.argv[1])
from sls_audio_state import audio_state
with audio_state(sys.argv[2]) as state:
 state['recipients']['1000'] = 1100
 print('ready', flush=True)
 sys.stdin.readline()
''')
        self.ready(proc)
        proc.send_signal(signal.SIGKILL); proc.wait(timeout=5)
        self.assertEqual((self.data / 'audio-reservations.json').read_bytes(), original)
        self.assertTrue(self.php_run()['ok'], 'Dead writer retained lock ownership')

    def test_php_uncertain_directory_sync_returns_no_claim(self):
        self.seed()
        result = self.php_run(fail=True)
        self.assertFalse(result['ok'], 'Uncertain durable commit authorized playback')
        self.assertEqual(self.contents()['recipients']['1000'], 1035)
        self.assertEqual(self.php_run()['result'], [], 'Uncertain committed lease was erased/replayed')

    def test_php_holds_sidecar_after_replace_until_commit_finishes(self):
        self.seed()
        env = dict(os.environ, SLS_FIXTURE_HOLD_SYNC='directory')
        php = subprocess.Popen(self.command(), stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, text=True, env=env)
        try:
            self.ready(php)
            with self.assertRaises(Exception):
                with self.state.read_audio_state(self.data, nonblocking=True): pass
            proc = self.child('''import sys
sys.path.insert(0, sys.argv[1])
from sls_audio_state import audio_state
with audio_state(sys.argv[2]) as state:
 assert state['recipients']['1000'] == 1035
 state['recipients']['1001'] = 1100
print('ready', flush=True)
''')
            time.sleep(.15)
            self.assertIsNone(proc.poll(), 'Python writer bypassed PHP lock after inode replacement')
            php.stdin.write('release\n'); php.stdin.flush()
            out, err = php.communicate(timeout=5)
            self.assertEqual(php.returncode, 0, err)
            self.assertTrue(json.loads(out)['ok'])
            self.ready(proc)
            self.assertEqual(proc.wait(timeout=5), 0)
            self.assertEqual(self.contents()['recipients']['1001'], 1100)
        finally:
            if php.poll() is None: php.kill()
            php.communicate(timeout=5)

    def test_killed_php_before_replace_keeps_old_lease_document(self):
        self.seed()
        before = (self.data / 'audio-reservations.json').read_bytes()
        php = subprocess.Popen(self.command(), stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, text=True,
                               env=dict(os.environ, SLS_FIXTURE_HOLD_RENAME='1'))
        try:
            self.ready(php)
            php.kill(); php.wait(timeout=5)
            self.assertEqual((self.data / 'audio-reservations.json').read_bytes(), before)
            self.assertTrue(self.php_run()['ok'])
        finally:
            if php.poll() is None: php.kill()
            php.communicate(timeout=5)

    def test_legacy_valid_document_migrates_without_losing_leases(self):
        path = self.data / 'audio-reservations.json'
        path.write_text(json.dumps({'recipients': {'1000': 1100}, 'media': {'legacy.wav': 2000}}))
        path.chmod(0o640)
        self.assertEqual(self.php_run()['result'], [])
        with self.state.audio_state(self.data) as state:
            self.assertEqual(state['recipients']['1000'], 1100)
            state['recipients']['1001'] = 1150
        self.assertEqual((self.data / 'audio-reservations.lock').read_bytes(), MARKER)
        self.assertEqual(self.contents()['media'], {'legacy.wav': 2000})

    def test_missing_after_initialization_and_corruption_fail_closed(self):
        self.seed()
        path = self.data / 'audio-reservations.json'
        for raw in (None, b'', b'{broken', b'{"recipients":[1100],"media":{},"waiting":{}}'):
            if path.exists(): path.unlink()
            if raw is not None: path.write_bytes(raw); path.chmod(0o640)
            self.assertFalse(self.php_run()['ok'])
            with self.assertRaises(Exception):
                with self.state.audio_state(self.data): pass
            self.assertEqual(path.read_bytes() if path.exists() else None, raw)

    def test_legacy_empty_php_arrays_normalize_to_objects(self):
        path = self.data / 'audio-reservations.json'
        path.write_text('{"recipients":[],"media":[],"waiting":[]}')
        path.chmod(0o640)
        with self.state.audio_state(self.data) as state:
            self.assertEqual(state, {'recipients': {}, 'media': {}, 'waiting': {}})
        self.assertEqual(self.contents(), {'recipients': {}, 'media': {}, 'waiting': {}})
        self.assertTrue(self.php_run()['ok'])

    def test_corrupt_duplicate_keys_and_unknown_schema_rejected_by_both(self):
        self.seed()
        path = self.data / 'audio-reservations.json'
        samples = [
            '{"recipients":{"1000":1100,"1000":0},"media":{},"waiting":{}}',
            '{"recipients":{"1000":1100},"recipients":{},"media":{},"waiting":{}}',
            '{"recipients":{},"media":{},"waiting":{},"unexpected":true}',
            json.dumps({'recipients': {}, 'media': {}, 'waiting': {'a' * 32: {
                'recipients': ['1000'], 'priority': 0, 'created': 990, 'expires': 1200,
                'heartbeat': 1100, 'media_name': 'fixture.wav', 'duration': 30,
                'unexpected': True}}}),
            json.dumps({'recipients': {}, 'media': {}, 'waiting': {'a' * 32: {
                'recipients': {'0': '1000'}, 'priority': 0, 'created': 990, 'expires': 1200,
                'heartbeat': 1100, 'media_name': 'fixture.wav', 'duration': 30}}}),
        ]
        for raw in samples:
            with self.subTest(raw=raw):
                path.write_text(raw); path.chmod(0o640)
                self.assertFalse(self.php_run()['ok'], 'PHP accepted corrupt or incompatible ledger')
                with self.assertRaises(Exception):
                    with self.state.audio_state(self.data): pass
                self.assertEqual(path.read_text(), raw)

    def test_sidecar_links_and_unsafe_owner_refused_without_changes(self):
        self.seed()
        lock = self.data / 'audio-reservations.lock'
        original = (self.data / 'audio-reservations.json').read_bytes()
        target = self.root / 'untouched'
        target.write_bytes(MARKER); target.chmod(0o640)
        for kind in ('symlink', 'hardlink', 'writable', 'foreign_owner'):
            if kind == 'foreign_owner' and os.geteuid() != 0:
                continue
            with self.subTest(kind=kind):
                lock.unlink()
                if kind == 'symlink': lock.symlink_to(target)
                elif kind == 'hardlink': os.link(target, lock)
                else:
                    lock.write_bytes(MARKER)
                    lock.chmod(0o666 if kind == 'writable' else 0o640)
                    if kind == 'foreign_owner': os.chown(lock, 65534, 65534)
                self.assertFalse(self.php_run()['ok'])
                with self.assertRaises(Exception):
                    with self.state.audio_state(self.data): pass
                self.assertEqual((self.data / 'audio-reservations.json').read_bytes(), original)
                self.assertEqual(target.read_bytes(), MARKER)

    def test_partial_marker_refused_by_both_without_reinitializing(self):
        self.seed()
        lock = self.data / 'audio-reservations.lock'
        original = (self.data / 'audio-reservations.json').read_bytes()
        lock.write_bytes(b'SLS_AUDIO_')
        self.assertFalse(self.php_run()['ok'])
        with self.assertRaises(Exception):
            with self.state.audio_state(self.data): pass
        self.assertEqual(lock.read_bytes(), b'SLS_AUDIO_')
        self.assertEqual((self.data / 'audio-reservations.json').read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
