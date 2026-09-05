#!/usr/bin/python3
"""No-network tests for queue isolation, retention and authenticated webhooks."""
import concurrent.futures
import hashlib
import hmac
import importlib.util
import json
import os
import sys
import tempfile
import time
from pathlib import Path
from unittest import mock

sys.dont_write_bytecode = True
runtime = Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'
sys.path.insert(0, str(runtime))
import sls_audio_queue as queue
import sls_notification_destinations as destinations
import sls_storage_maintenance as storage

with tempfile.TemporaryDirectory() as temporary, mock.patch.object(queue.os, 'geteuid', return_value=1000):
    root = Path(temporary)
    sound = 'SLS_Mass_Notifications_Plugin/tts/fixture'
    def reserve_one(_):
        return queue.reserve(['1000'], 10, sound, directory=root, now=1000)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        starts = sorted(pool.map(reserve_one, range(4)))
    assert starts == [1000, 1015, 1030, 1045], starts
    assert queue.reserve(['1001'], 10, sound, directory=root, now=1000) == 1000
    state = json.loads((root / 'audio-reservations.json').read_text())
    assert state['media']['fixture.wav'] >= 1960, 'Reusing audio shortened its active lease'
    for recipients, duration, identifier in [(['1000\nChannel: bad'], 10, sound), (['1000'], float('nan'), sound), (['1000'], 10, '../escape')]:
        try: queue.reserve(recipients, duration, identifier, directory=root, now=1000)
        except ValueError: pass
        else: raise AssertionError('Unsafe queue input accepted')
    reservation = root / 'audio-reservations.json'
    reservation.unlink()
    victim = root / 'unrelated'; victim.write_text('unchanged')
    reservation.symlink_to(victim)
    try: queue.reserve(['1000'], 10, sound, directory=root, now=1000)
    except OSError: pass
    else: raise AssertionError('Reservation symlink followed')
    assert victim.read_text() == 'unchanged'

with tempfile.TemporaryDirectory() as temporary, mock.patch.object(storage.os, 'geteuid', return_value=1000):
    root = Path(temporary); audio = root / 'sounds/tts'; audio.mkdir(parents=True)
    now = time.time()
    for name in ('leased.wav', 'orphan.wav'):
        path = audio / name; path.write_bytes(b'fixture'); os.utime(path, (now - 3600, now - 3600))
    (root / 'audio-reservations.json').write_text(json.dumps({'recipients': {}, 'media': {'leased.wav': now + 900}}))
    with mock.patch.object(storage, 'DATA', root), mock.patch.object(storage, 'pending_audio_names', return_value=set()):
        storage.main()
    assert (audio / 'leased.wav').exists() and not (audio / 'orphan.wav').exists()
    audit = root / 'audit.jsonl'; audit.write_text('{bad\n' + json.dumps({'created_at': '2026-09-04T00:00:00+00:00'}) + '\n')
    storage.prune_audit(audit, now=1788480000)
    assert '{bad' not in audit.read_text()

body = b'{"event":"test"}'
headers = destinations.webhook_auth_headers({'bearer_token':'test-token','signing_secret':'test-secret'}, body, 'event-1', now=1234)
expected = hmac.new(b'test-secret', b'1234.event-1.' + body, hashlib.sha256).hexdigest()
assert headers['X-SLS-Signature'] == 'sha256=' + expected
assert headers['Authorization'] == 'Bearer test-token'
try: destinations.webhook_auth_headers({'bearer_token':'token\r\nInjected: yes','signing_secret':''}, body, 'event-1')
except destinations.DestinationError: pass
else: raise AssertionError('Header injection accepted')
state = {'deliveries': {'old': {'created_at': 1000, 'completed_at': 0, 'email_pending': True, 'webhook_pending': ['generic:a']}}}
destinations._prune_retry_state(state, now=4600)
assert state['deliveries']['old']['terminal_status'] == 'expired'
assert state['deliveries']['old']['expired_channels'] == ['generic:a', 'email']
print('Recipient concurrency, leases, symlink rejection, HMAC, header injection and retry expiry passed.')
