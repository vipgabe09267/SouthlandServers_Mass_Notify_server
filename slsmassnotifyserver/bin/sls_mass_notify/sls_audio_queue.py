#!/usr/bin/python3
"""Recipient-aware paging reservations shared by all notification producers.

Reservations prevent overlapping playback, not delivery acknowledgement.
Abandoned reservations expire; uncertain calls are never automatically replayed.
"""
import argparse
from contextlib import contextmanager
import fcntl
import json
import math
import os
import pwd
import re
import stat
import time
from pathlib import Path

DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
MAX_WAIT = 300

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
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fd = os.open('audio-reservations.json', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
        with os.fdopen(fd, 'r+', encoding='utf-8') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX)
            metadata = os.fstat(handle.fileno())
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > 1048576:
                raise RuntimeError('Paging reservation storage is unsafe')
            if os.geteuid() == 0:
                account = pwd.getpwnam('asterisk')
                os.fchown(handle.fileno(), account.pw_uid, account.pw_gid)
            os.fchmod(handle.fileno(), 0o640)
            text = handle.read()
            state = json.loads(text) if text else {'recipients': {}, 'media': {}}
            if not isinstance(state, dict) or not isinstance(state.get('recipients'), dict) or not isinstance(state.get('media'), dict):
                raise RuntimeError('Paging reservation storage is corrupt')
            busy = {key: float(value) for key, value in state['recipients'].items() if float(value) > current}
            media = {key: float(value) for key, value in state['media'].items() if float(value) > current}
            start = max([current] + [busy.get(recipient, 0) for recipient in recipients])
            if start - current > MAX_WAIT:
                raise RuntimeError('Paging queue is busy; no audio was submitted')
            end = start + math.ceil(duration) + 5
            for recipient in recipients:
                busy[recipient] = end
            # Retain for fifteen minutes after the last reserved playback ends.
            media_name = sound.rsplit('/', 1)[-1] + '.wav'
            media[media_name] = max(media.get(media_name, 0), end + 900)
            result = json.dumps({'recipients': busy, 'media': media, 'waiting': state.get('waiting', {})}, separators=(',', ':'), allow_nan=False)
            if len(result) > 1048576:
                raise RuntimeError('Paging reservation capacity exceeded')
            handle.seek(0); handle.write(result); handle.truncate(); handle.flush()
            return start
    finally:
        os.close(directory_fd)

@contextmanager
def ticket_state(directory=DATA):
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fd = os.open('audio-reservations.json', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=directory_fd)
        with os.fdopen(fd, 'r+', encoding='utf-8') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX)
            metadata = os.fstat(handle.fileno())
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > 1048576:
                raise RuntimeError('Paging reservation storage is unsafe')
            if os.geteuid() == 0:
                account = pwd.getpwnam('asterisk'); os.fchown(handle.fileno(), account.pw_uid, account.pw_gid)
            os.fchmod(handle.fileno(), 0o640)
            raw = handle.read(1048577)
            state = json.loads(raw) if raw else {'recipients': {}, 'media': {}}
            if not isinstance(state, dict) or any(not isinstance(state.get(k, {}), dict) for k in ('recipients', 'media', 'waiting')):
                raise RuntimeError('Paging reservation storage is corrupt')
            for key in ('recipients', 'media'):
                state.setdefault(key, {})
                if any(not isinstance(v, (int, float)) or not math.isfinite(v) for v in state[key].values()):
                    raise RuntimeError('Invalid paging reservation timestamp')
            waiting = state.setdefault('waiting', {})
            for key, row in waiting.items():
                if not re.fullmatch('[a-f0-9]{32}', key) or not isinstance(row, dict) or row.get('priority') not in (0, 1):
                    raise RuntimeError('Invalid waiting page')
                if not isinstance(row.get('recipients'), list) or not 0 < len(row['recipients']) <= 1000 or any(not isinstance(v, str) or not re.fullmatch('[0-9]{1,20}', v) for v in row['recipients']):
                    raise RuntimeError('Invalid waiting page recipients')
                if not isinstance(row.get('media_name'), str) or not re.fullmatch('[A-Za-z0-9_-]+\\.wav', row['media_name']):
                    raise RuntimeError('Invalid waiting page media')
                if not isinstance(row.get('duration'), (int, float)) or not 0 < row['duration'] <= 1800:
                    raise RuntimeError('Invalid waiting page duration')
                for field in ('created', 'expires', 'heartbeat'):
                    if not isinstance(row.get(field), (int, float)) or not math.isfinite(row[field]):
                        raise RuntimeError('Invalid waiting page lifetime')
            yield state
            encoded = json.dumps(state, separators=(',', ':'), allow_nan=False)
            if len(encoded.encode()) > 1048576:
                raise RuntimeError('Paging reservation capacity exceeded')
            handle.seek(0); handle.write(encoded); handle.truncate(); handle.flush(); os.fsync(handle.fileno())
    finally:
        os.close(directory_fd)


def request_ticket(recipients, duration, sound, priority='normal', directory=DATA, now=None):
    now = time.time() if now is None else now
    recipients = sorted(set(str(value) for value in recipients))
    if not recipients or len(recipients) > 1000 or any(not re.fullmatch('[0-9]{1,20}', value) for value in recipients):
        raise ValueError('Invalid paging recipients')
    duration = float(duration)
    if not math.isfinite(duration) or not 0 < duration <= 1800 or priority not in ('normal', 'urgent'):
        raise ValueError('Invalid paging duration or priority')
    if not re.fullmatch(r'SLS_Mass_Notifications_Plugin/(?:tts/)?[A-Za-z0-9_-]+', sound):
        raise ValueError('Invalid generated sound identifier')
    ticket = os.urandom(16).hex()
    with ticket_state(directory) as state:
        state['waiting'] = {key: value for key, value in state['waiting'].items() if value['expires'] > now and value['heartbeat'] > now}
        if len(state['waiting']) >= 100:
            raise RuntimeError('Paging waiting queue is full')
        state['waiting'][ticket] = {'recipients': recipients, 'priority': 0 if priority == 'urgent' else 1,
            'created': now, 'expires': now + MAX_WAIT, 'heartbeat': now + 15,
            'media_name': sound.rsplit('/', 1)[-1] + '.wav', 'duration': duration}
        # Maintenance protects waiting media separately. Start its fifteen-minute
        # post-use retention when the page actually claims a playback slot.
        state['media'] = {key: value for key, value in state['media'].items() if value > now}
    return ticket


def claim_ticket(ticket, directory=DATA, now=None):
    now = time.time() if now is None else now
    with ticket_state(directory) as state:
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


def wait_for_slot(recipients, duration, sound, priority='normal'):
    ticket = request_ticket(recipients, duration, sound, priority)
    try:
        while not claim_ticket(ticket):
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
    args = parser.parse_args()
    try:
        wait_for_slot(args.recipients.split(','), args.duration, args.sound, args.priority)
        return 0
    except (OSError, ValueError, RuntimeError) as error:
        print('Paging reservation failed: ' + str(error), file=__import__('sys').stderr)
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
