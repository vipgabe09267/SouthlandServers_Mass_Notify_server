#!/usr/bin/python3
"""Bound local SLS mail submission; never inspect or modify queued messages."""
from __future__ import annotations
import argparse
import fcntl
import json
import os
from pathlib import Path
import pwd
import selectors
import signal
import stat
import subprocess
import sys
import time

sys.dont_write_bytecode = True
DATA = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin')
STATE = 'mail-admission.json'
LOCK = 'mail-admission.lock'
MARKER = b'SLS_MAIL_ADMISSION_V1\n'
QUEUE_LIMIT = 200
PROBE_CACHE_SECONDS = 5
MAX_STATE = 16384
MAX_QUEUE_LINE = 65536
QUEUE_TIMEOUT = 1.0
LIMITS = {
    60: {'total': (120, 1000), 'system': (2, 100), 'account': (10, 10)},
    3600: {'total': (1000, 5000), 'system': (10, 500), 'account': (100, 100)},
    86400: {'total': (5000, 25000), 'system': (20, 1000), 'account': (500, 500)},
}
MESSAGES = {
    'mail_queue_full': 'PBX mail backlog reached the SLS safety limit. No new email was submitted. Review Postfix connectivity and its queue before retrying explicitly.',
    'mail_queue_unavailable': 'The local Postfix queue could not be checked. No email was submitted. Check the Postfix service and postqueue permissions.',
    'mail_rate_limited': 'The SLS mail submission safety limit was reached. No email was submitted. Review recurring faults or excessive requests before retrying explicitly.',
    'mail_admission_unavailable': 'Protected SLS mail admission state is unavailable, busy or damaged. No email was submitted. Preserve its journal and run protected repair.',
}


class MailAdmissionBlocked(RuntimeError):
    def __init__(self, code):
        self.code = code if code in MESSAGES else 'mail_admission_unavailable'
        super().__init__(MESSAGES[self.code])


def inspect_queue(*, command=('/usr/sbin/postqueue', '-j'), timeout=QUEUE_TIMEOUT):
    """Stop at the cap; discard every queue field after counting one record."""
    process = None
    try:
        process = subprocess.Popen(list(command), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, start_new_session=True, close_fds=True,
            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
        os.set_blocking(process.stdout.fileno(), False)
        deadline = time.monotonic() + timeout; pending = bytearray(); count = 0
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0: raise MailAdmissionBlocked('mail_queue_unavailable')
                if not selector.select(min(remaining, .1)): continue
                block = os.read(process.stdout.fileno(), 32768)
                if not block:
                    if pending.strip(): raise MailAdmissionBlocked('mail_queue_unavailable')
                    if process.wait(timeout=max(.01, deadline - time.monotonic())) != 0:
                        raise MailAdmissionBlocked('mail_queue_unavailable')
                    return count
                pending.extend(block)
                while b'\n' in pending:
                    line, _, rest = pending.partition(b'\n'); pending = bytearray(rest)
                    if not line.strip(): continue
                    if len(line) > MAX_QUEUE_LINE: raise MailAdmissionBlocked('mail_queue_unavailable')
                    value = json.loads(line)
                    if (not isinstance(value, dict) or not isinstance(value.get('queue_id'), str)
                            or not isinstance(value.get('queue_name'), str)):
                        raise MailAdmissionBlocked('mail_queue_unavailable')
                    count += 1
                    if count >= QUEUE_LIMIT: return QUEUE_LIMIT
                if len(pending) > MAX_QUEUE_LINE: raise MailAdmissionBlocked('mail_queue_unavailable')
    except (OSError, ValueError, subprocess.TimeoutExpired):
        raise MailAdmissionBlocked('mail_queue_unavailable') from None
    finally:
        if process is not None:
            if process.poll() is None:
                try: os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError: pass
            if process.stdout is not None: process.stdout.close()
            try: process.wait(timeout=.2)
            except subprocess.TimeoutExpired: pass


def _directory(path, uid):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts: raise MailAdmissionBlocked('mail_admission_unavailable')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        for index, name in enumerate(path.parts[1:]):
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd); fd = child; info = os.fstat(fd)
            sticky = index < len(path.parts) - 2 and info.st_uid == 0 and info.st_mode & stat.S_ISVTX
            if info.st_uid not in (0, uid) or info.st_mode & 0o022 and not sticky:
                raise MailAdmissionBlocked('mail_admission_unavailable')
        return fd
    except BaseException:
        os.close(fd); raise


def _regular(fd, parent, name, uid, gid, maximum):
    info = os.fstat(fd); named = os.stat(name, dir_fd=parent, follow_symlinks=False)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, uid)
            or info.st_gid != gid or stat.S_IMODE(info.st_mode) not in (0o600, 0o640) or info.st_size > maximum
            or (info.st_dev, info.st_ino) != (named.st_dev, named.st_ino)):
        raise MailAdmissionBlocked('mail_admission_unavailable')
    return info


def _strict(raw):
    def pairs(items):
        output = {}
        for key, value in items:
            if key in output: raise ValueError('duplicate')
            output[key] = value
        return output
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda _: (_ for _ in ()).throw(ValueError('nonfinite')))


def _checked_state(value):
    if (not isinstance(value, dict) or set(value) != {'schema', 'last_at', 'windows', 'probe'}
            or type(value['schema']) is not int or value['schema'] != 1
            or type(value['last_at']) is not int or not 0 <= value['last_at'] <= 10**12
            or not isinstance(value['windows'], dict) or set(value['windows']) != {str(period) for period in LIMITS}):
        raise ValueError('state')
    for period, rows in value['windows'].items():
        if not isinstance(rows, list) or len(rows) > 2: raise ValueError('windows')
        seen = set()
        for row in rows:
            if (not isinstance(row, dict) or set(row) != {'start', 'counts'} or type(row['start']) is not int
                    or not 0 <= row['start'] <= value['last_at'] or row['start'] % int(period) or row['start'] in seen
                    or not isinstance(row['counts'], dict) or set(row['counts']) != {'total', 'system', 'account'}):
                raise ValueError('bucket')
            seen.add(row['start'])
            for count in row['counts'].values():
                if not isinstance(count, list) or len(count) != 2 or any(type(n) is not int or not 0 <= n <= 25000 for n in count):
                    raise ValueError('count')
            if any(row['counts']['system'][index] + row['counts']['account'][index] > row['counts']['total'][index] for index in (0, 1)):
                raise ValueError('count totals')
    probe = value['probe']
    if probe is not None and (not isinstance(probe, dict) or set(probe) != {'at', 'depth', 'reserved', 'code'}
            or type(probe['at']) is not int or not 0 <= probe['at'] <= value['last_at']
            or type(probe['reserved']) is not int or not 0 <= probe['reserved'] <= QUEUE_LIMIT
            or probe['depth'] is not None and (type(probe['depth']) is not int or not 0 <= probe['depth'] <= QUEUE_LIMIT)
            or probe['code'] not in ('', 'mail_queue_unavailable')
            or (probe['depth'] is None) != (probe['code'] == 'mail_queue_unavailable')):
        raise ValueError('probe')
    return value


def _identity(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _write(parent, value, uid, gid, expected):
    raw = json.dumps(value, separators=(',', ':'), allow_nan=False).encode()
    if len(raw) > MAX_STATE: raise MailAdmissionBlocked('mail_admission_unavailable')
    name = '.mail-admission-' + os.urandom(16).hex()
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=parent)
    try:
        if os.geteuid() == 0: os.fchown(fd, uid, gid)
        with os.fdopen(fd, 'wb', closefd=False) as handle:
            if handle.write(raw) != len(raw): raise MailAdmissionBlocked('mail_admission_unavailable')
            handle.flush(); os.fsync(fd)
        try: current = os.stat(STATE, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError: current = None
        if ((current is None) != (expected is None)
                or current is not None and _identity(current) != _identity(expected)):
            raise MailAdmissionBlocked('mail_admission_unavailable')
        os.replace(name, STATE, src_dir_fd=parent, dst_dir_fd=parent); os.fsync(parent)
    finally:
        os.close(fd)
        try: os.unlink(name, dir_fd=parent)
        except FileNotFoundError: pass


def reserve(category='alert', recipients=1, *, directory=DATA, clock=time.time, queue_inspector=inspect_queue):
    """Consume a durable attempt before submission; never refund uncertainty."""
    if category not in ('alert', 'system', 'account') or type(recipients) is not int or not 1 <= recipients <= 50:
        raise MailAdmissionBlocked('mail_admission_unavailable')
    parent = lock = -1
    try:
        account = pwd.getpwnam('asterisk'); uid, gid = account.pw_uid, account.pw_gid
        parent = _directory(directory, uid)
        state_exists = False
        try: os.stat(STATE, dir_fd=parent, follow_symlinks=False); state_exists = True
        except FileNotFoundError: pass
        try: lock = os.open(LOCK, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            if state_exists: raise MailAdmissionBlocked('mail_admission_unavailable')
            lock = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=parent)
            if os.geteuid() == 0: os.fchown(lock, uid, gid)
        _regular(lock, parent, LOCK, uid, gid, len(MARKER))
        deadline = time.monotonic() + .25
        while True:
            try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB); break
            except BlockingIOError:
                if time.monotonic() >= deadline: raise MailAdmissionBlocked('mail_admission_unavailable')
                time.sleep(.01)
        marker = os.read(lock, len(MARKER) + 1)
        if marker not in (b'', MARKER): raise MailAdmissionBlocked('mail_admission_unavailable')
        now = int(clock())
        if not 0 <= now <= 10**12: raise MailAdmissionBlocked('mail_admission_unavailable')
        try: fd = os.open(STATE, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            if marker: raise MailAdmissionBlocked('mail_admission_unavailable')
            state = {'schema': 1, 'last_at': now, 'windows': {str(period): [] for period in LIMITS}, 'probe': None}
            expected = None
        else:
            try:
                info = _regular(fd, parent, STATE, uid, gid, MAX_STATE)
                raw = os.read(fd, MAX_STATE + 1)
                if not marker or len(raw) != info.st_size: raise MailAdmissionBlocked('mail_admission_unavailable')
                if _identity(info) != _identity(_regular(fd, parent, STATE, uid, gid, MAX_STATE)):
                    raise MailAdmissionBlocked('mail_admission_unavailable')
                expected = info
                state = _checked_state(_strict(raw))
            finally: os.close(fd)
        if now < state['last_at']: raise MailAdmissionBlocked('mail_admission_unavailable')
        state['last_at'] = now
        for period, caps in LIMITS.items():
            start = now // period * period
            rows = [row for row in state['windows'][str(period)] if start - period <= row['start'] <= start]
            if not any(row['start'] == start for row in rows):
                rows.append({'start': start, 'counts': {'total': [0, 0], 'system': [0, 0], 'account': [0, 0]}})
            state['windows'][str(period)] = rows
            for name in ('total', category) if category != 'alert' else ('total',):
                used = [sum(row['counts'][name][index] for row in rows) for index in (0, 1)]
                if used[0] + 1 > caps[name][0] or used[1] + recipients > caps[name][1]:
                    raise MailAdmissionBlocked('mail_rate_limited')
        probe = state['probe']
        if probe is None or now - probe['at'] >= PROBE_CACHE_SECONDS:
            try: depth = queue_inspector(); code = ''
            except MailAdmissionBlocked: depth = None; code = 'mail_queue_unavailable'
            if depth is not None and (type(depth) is not int or not 0 <= depth <= QUEUE_LIMIT):
                raise MailAdmissionBlocked('mail_queue_unavailable')
            probe = state['probe'] = {'at': now, 'depth': depth, 'reserved': 0, 'code': code}
        code = probe['code'] or ('mail_queue_full' if probe['depth'] + probe['reserved'] >= QUEUE_LIMIT else '')
        if not code:
            for period in LIMITS:
                start = now // period * period
                row = next(row for row in state['windows'][str(period)] if row['start'] == start)
                for name in ('total', category) if category != 'alert' else ('total',):
                    row['counts'][name][0] += 1; row['counts'][name][1] += recipients
            probe['reserved'] += 1
        _write(parent, state, uid, gid, expected)
        if not marker:
            if os.write(lock, MARKER) != len(MARKER): raise MailAdmissionBlocked('mail_admission_unavailable')
            os.fsync(lock); os.fsync(parent)
        if code: raise MailAdmissionBlocked(code)
        return {'ok': True}
    except MailAdmissionBlocked: raise
    except (OSError, ValueError, KeyError, TypeError, RecursionError, OverflowError):
        raise MailAdmissionBlocked('mail_admission_unavailable') from None
    finally:
        if lock >= 0: os.close(lock)
        if parent >= 0: os.close(parent)


def main():
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument('action', choices=['reserve']); parser.parse_args()
    try:
        raw = sys.stdin.buffer.read(1025)
        if len(raw) > 1024: raise MailAdmissionBlocked('mail_admission_unavailable')
        request = _strict(raw)
        if not isinstance(request, dict) or set(request) != {'category', 'recipients'}: raise MailAdmissionBlocked('mail_admission_unavailable')
        result = reserve(request['category'], request['recipients'])
    except (MailAdmissionBlocked, ValueError, TypeError, UnicodeError) as error:
        code = error.code if isinstance(error, MailAdmissionBlocked) else 'mail_admission_unavailable'
        print(json.dumps({'ok': False, 'error_code': code, 'message': MESSAGES[code]})); return 1
    print(json.dumps(result)); return 0


if __name__ == '__main__': raise SystemExit(main())
