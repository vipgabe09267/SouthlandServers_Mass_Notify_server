#!/usr/bin/python3
"""Recipient-aware paging reservations shared by all notification producers.

Reservations prevent overlapping playback, not delivery acknowledgement.
Abandoned reservations expire; uncertain calls are never automatically replayed.
"""
import argparse
import importlib.util
import math
import os
import re
import time
from pathlib import Path

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
MAX_WAIT = 300

# Resolve only the authenticated installed sibling, including python -I callers.
# Do not import a same-named file from cwd or an ambient Python search path.
import sys
sys.dont_write_bytecode = True
_spec = importlib.util.spec_from_file_location('sls_audio_state', Path(__file__).resolve().with_name('sls_audio_state.py'))
_audio_state = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_audio_state)


def reserve(recipients, duration, sound, directory=DATA, now=None):
    current = time.time() if now is None else float(now)
    recipients = sorted(set(str(value) for value in recipients))
    if not recipients or len(recipients) > 1000 or any(not re.fullmatch(r'[0-9]{1,20}', value) for value in recipients):
        raise ValueError('Invalid paging recipients')
    duration = float(duration)
    if not math.isfinite(duration) or not 0 < duration <= 1800:
        raise ValueError('Invalid paging duration')
    if not re.fullmatch(r'SLS_Mass_Notifications_Plugin/(?:tts/)?[A-Za-z0-9_-]+', sound):
        raise ValueError('Invalid generated sound identifier')
    with ticket_state(directory) as state:
        busy = {key: value for key, value in state['recipients'].items() if value > current}
        media = {key: value for key, value in state['media'].items() if value > current}
        start = max([current] + [busy.get(recipient, 0) for recipient in recipients])
        if start - current > MAX_WAIT:
            raise RuntimeError('Paging queue is busy; no audio was submitted')
        end = start + math.ceil(duration) + 5
        for recipient in recipients:
            busy[recipient] = end
        media_name = sound.rsplit('/', 1)[-1] + '.wav'
        media[media_name] = max(media.get(media_name, 0), end + 900)
        state['recipients'] = busy
        state['media'] = media
        return start


def ticket_state(directory=DATA):
    return _audio_state.audio_state(directory)


def request_ticket(recipients, duration, sound, priority='normal', directory=DATA, now=None, latest_start=None):
    recipients = sorted(set(str(value) for value in recipients))
    if not recipients or len(recipients) > 1000 or any(not re.fullmatch('[0-9]{1,20}', value) for value in recipients):
        raise ValueError('Invalid paging recipients')
    duration = float(duration)
    if not math.isfinite(duration) or not 0 < duration <= 1800 or priority not in ('normal', 'urgent'):
        raise ValueError('Invalid paging duration or priority')
    if not re.fullmatch(r'SLS_Mass_Notifications_Plugin/(?:tts/)?[A-Za-z0-9_-]+', sound):
        raise ValueError('Invalid generated sound identifier')
    if latest_start is not None and (type(latest_start) is not int or not 0 < latest_start <= 253402300799):
        raise ValueError('Invalid paging start deadline')
    ticket = os.urandom(16).hex()
    with ticket_state(directory) as state:
        # Lock contention must not leave us using a timestamp from before the wait.
        now = time.time() if now is None else now
        if latest_start is not None and now >= latest_start:
            raise RuntimeError('Scheduled start deadline elapsed; no audio was submitted')
        state['waiting'] = {key: value for key, value in state['waiting'].items() if value['expires'] > now and value['heartbeat'] > now}
        if len(state['waiting']) >= 100:
            raise RuntimeError('Paging waiting queue is full')
        state['waiting'][ticket] = {'recipients': recipients, 'priority': 0 if priority == 'urgent' else 1,
            'created': now, 'expires': min(now + MAX_WAIT, latest_start) if latest_start is not None else now + MAX_WAIT, 'heartbeat': now + 15,
            'media_name': sound.rsplit('/', 1)[-1] + '.wav', 'duration': duration}
        # Maintenance protects waiting media separately. Start its fifteen-minute
        # post-use retention when the page actually claims a playback slot.
        state['media'] = {key: value for key, value in state['media'].items() if value > now}
    return ticket


def claim_ticket(ticket, directory=DATA, now=None, latest_start_tick=None):
    with ticket_state(directory) as state:
        now = time.time() if now is None else now
        if latest_start_tick is not None and time.monotonic() >= latest_start_tick:
            raise RuntimeError('Scheduled start deadline elapsed; no audio was submitted')
        row = state['waiting'].get(ticket)
        if not row or row['expires'] <= now:
            raise RuntimeError('Paging queue wait expired; no audio was submitted')
        row['heartbeat'] = now + 15
        state['waiting'] = {key: value for key, value in state['waiting'].items() if value['expires'] > now and value['heartbeat'] > now}
        state['recipients'] = {key: value for key, value in state['recipients'].items() if value > now}
        recipients = set(row['recipients'])
        if any(state['recipients'].get(value, 0) > now for value in recipients):
            return False
        order = lambda key, value: (value['priority'], value['created'], key)
        if any(order(key, value) < order(ticket, row) and recipients.intersection(value['recipients'])
               for key, value in state['waiting'].items() if key != ticket):
            return False
        end = now + math.ceil(row['duration']) + 5
        for recipient in recipients:
            state['recipients'][recipient] = end
        state['media'][row['media_name']] = max(state['media'].get(row['media_name'], 0), end + 900)
        del state['waiting'][ticket]
        return True


def wait_for_slot(recipients, duration, sound, priority='normal', latest_start=None):
    latest_start_tick = None if latest_start is None else time.monotonic() + max(0, latest_start - time.time())
    ticket = request_ticket(recipients, duration, sound, priority, latest_start=latest_start)
    try:
        while not claim_ticket(ticket, latest_start_tick=latest_start_tick):
            # No lock is held during the wait. Active playback is never displaced.
            time.sleep(0.25)
        return time.time()
    finally:
        with ticket_state() as state:
            state['waiting'].pop(ticket, None)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--recipients', required=True)
    parser.add_argument('--duration', required=True, type=float)
    parser.add_argument('--sound', required=True)
    parser.add_argument('--priority', choices=['normal', 'urgent'], default='normal')
    parser.add_argument('--latest-start', type=int, help='Absolute scheduled submission deadline, as a Unix timestamp')
    args = parser.parse_args()
    try:
        wait_for_slot(args.recipients.split(','), args.duration, args.sound, args.priority, args.latest_start)
        return 0
    except (OSError, ValueError, RuntimeError) as error:
        print('Paging reservation failed: ' + str(error), file=__import__('sys').stderr)
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
