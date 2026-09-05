#!/usr/bin/python3
"""No-call, no-network priority/retention regression tests."""
import json
import os
import sys
import tempfile
import time
from pathlib import Path
from unittest import mock

sys.dont_write_bytecode = True
sys.path.insert(0, sys.argv[1] if len(sys.argv) > 1 else str(Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_audio_queue as queue
import sls_storage_maintenance as storage

sound = 'SLS_Mass_Notifications_Plugin/tts/fixture'
with tempfile.TemporaryDirectory() as temporary, mock.patch.object(queue.os, 'geteuid', return_value=1000):
    root = Path(temporary)
    normal = queue.request_ticket(['1000'], 10, sound, directory=root, now=1000)
    urgent = queue.request_ticket(['1000'], 10, sound, 'urgent', directory=root, now=1001)
    assert not queue.claim_ticket(normal, directory=root, now=1002)
    assert queue.claim_ticket(urgent, directory=root, now=1002)
    assert not queue.claim_ticket(normal, directory=root, now=1003), 'Active audio was displaced'
    other = queue.request_ticket(['1001'], 10, sound, directory=root, now=1003)
    assert queue.claim_ticket(other, directory=root, now=1003), 'Disjoint phones unnecessarily blocked'
    assert not queue.claim_ticket(normal, directory=root, now=1010)
    assert queue.claim_ticket(normal, directory=root, now=1017)
    state = json.loads((root / 'audio-reservations.json').read_text())
    assert state['media']['fixture.wav'] == 1932, 'Retention is not tied to actual reserved playback'
    assert state['waiting'] == {}
    first = queue.request_ticket(['1002'], 1, sound, directory=root, now=1100)
    second = queue.request_ticket(['1002'], 1, sound, directory=root, now=1101)
    assert not queue.claim_ticket(second, directory=root, now=1102)
    assert queue.claim_ticket(first, directory=root, now=1102), 'Routine FIFO order lost'
    # A process that dies before claiming cannot hold up every later page.
    queue.request_ticket(['1003'], 1, sound, 'urgent', directory=root, now=1200)
    replacement = queue.request_ticket(['1003'], 1, sound, directory=root, now=1216)
    assert queue.claim_ticket(replacement, directory=root, now=1216)
    stale = queue.request_ticket(['1004'], 1, sound, directory=root, now=1300)
    try: queue.claim_ticket(stale, directory=root, now=1601)
    except RuntimeError: pass
    else: raise AssertionError('Expired waiting job was played')
    for priority in ('URGENT', 'urgent\n--sound bad', None):
        try: queue.request_ticket(['1000'], 10, sound, priority, directory=root)
        except ValueError: pass
        else: raise AssertionError('Invalid priority accepted')
    # Existing pre-upgrade reservations still protect active audio.
    queue.reserve(['1005'], 10, sound, directory=root, now=1700)
    ticket = queue.request_ticket(['1005'], 1, sound, 'urgent', directory=root, now=1701)
    assert not queue.claim_ticket(ticket, directory=root, now=1702)
    queue.reserve(['1006'], 1, sound, directory=root, now=1702)
    assert ticket in json.loads((root / 'audio-reservations.json').read_text())['waiting']
    assert queue.claim_ticket(ticket, directory=root, now=1715)

with tempfile.TemporaryDirectory() as temporary, mock.patch.object(queue.os, 'geteuid', return_value=1000):
    root = Path(temporary); audio = root / 'sounds/tts'; audio.mkdir(parents=True)
    path = audio / 'fixture.wav'; path.write_bytes(b'fixture'); now = time.time()
    os.utime(path, (now - 3600, now - 3600))
    ticket = queue.request_ticket(['1000'], 10, sound, directory=root, now=now)
    with mock.patch.object(storage, 'DATA', root), mock.patch.object(storage, 'storage_summary'), mock.patch.object(storage, 'pending_audio_names', side_effect=set):
        storage.main()
        assert path.exists(), 'Waiting audio was deleted'
        queue.claim_ticket(ticket, directory=root, now=now)
        storage.main()
        assert path.exists(), 'Claimed audio was deleted'
        with mock.patch.object(storage.time, 'time', return_value=now + 916):
            storage.main()
        assert not path.exists(), 'Unused audio exceeded its post-playback lease'
    journal = root / 'audio-reservations.json'; journal.unlink()
    victim = root / 'victim'; victim.write_text('unchanged'); journal.symlink_to(victim)
    try: queue.request_ticket(['1000'], 1, sound, directory=root)
    except OSError: pass
    else: raise AssertionError('Queue followed a symbolic link')
    assert victim.read_text() == 'unchanged'
    journal.unlink(); os.link(victim, journal)
    try: queue.request_ticket(['1000'], 1, sound, directory=root)
    except RuntimeError: pass
    else: raise AssertionError('Queue accepted a hard link')
    assert victim.read_text() == 'unchanged'
print('Urgent ordering, non-preemption, FIFO, disjoint recipients, expiry, leases and unsafe-path checks passed.')
