#!/usr/bin/python3
"""Own installation/repair worker leases until the parent closes stdin.

No PBX code, service operation, notification, or delivery-state mutation occurs.
"""
import argparse
import fcntl
import os
import pwd
import re
import stat
import sys
import time


def settings_metadata(fd, parent, account):
    name = 'mass-notifications.config.lock'
    info = os.fstat(fd)
    named = os.stat(name, dir_fd=parent, follow_symlinks=False)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_uid not in (0, account.pw_uid) or info.st_gid not in (0, account.pw_gid)
            or stat.S_IMODE(info.st_mode) not in (0o600, 0o640) or info.st_size != 0
            or (info.st_dev, info.st_ino) != (named.st_dev, named.st_ino)):
        raise RuntimeError('configuration lock has unsafe ownership, mode, contents or identity')


def check_current_directory(data, parent):
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    current = os.open('/', flags)
    try:
        for part in data.split('/'):
            if not part: continue
            child = os.open(part, flags, dir_fd=current)
            os.close(current); current = child
        opened = os.fstat(parent); named = os.fstat(current)
        if (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino):
            raise RuntimeError('worker data directory changed before configuration coordination')
    finally:
        os.close(current)


def acquire_settings(parent, account, locks, timeout):
    name = 'mass-notifications.config.lock'
    flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        fd = os.open(name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
    except FileExistsError:
        fd = os.open(name, flags, dir_fd=parent)
        created = False
    else:
        created = True
    locks.append(fd)
    if created:
        settings_metadata(fd, parent, account)
        os.fchown(fd, account.pw_uid, account.pw_gid)
        os.fchmod(fd, 0o640)
        os.fsync(fd); os.fsync(parent)
    settings_metadata(fd, parent, account)
    deadline = time.monotonic() + timeout
    while True:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            if time.monotonic() >= deadline:
                raise RuntimeError('active configuration editor did not become idle') from None
            time.sleep(.05)
    settings_metadata(fd, parent, account)


def hold(data, timeout=120, settings=False):
    account = pwd.getpwnam('asterisk')
    if account.pw_uid == 0: raise SystemExit('Asterisk UID must be nonzero')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    parent = os.open('/', flags)
    locks = []
    paging = -1
    try:
        for part in data.split('/'):
            if not part: continue
            if part in ('.', '..'): raise RuntimeError('unsafe data directory')
            child = os.open(part, flags, dir_fd=parent)
            os.close(parent); parent = child
            info = os.fstat(parent)
            sticky_root = info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
            if (info.st_uid not in (0, account.pw_uid)
                    or info.st_gid not in (0, account.pw_gid)
                    or (info.st_mode & 0o022 and not sticky_root)):
                raise RuntimeError('unsafe worker lock parent')
        names = os.listdir(parent)
        if len(names) > 20000: raise RuntimeError('worker guard inventory exceeds limit')
        legacy = [name for name in ('weather-poll-cycle.lock', 'sls_mass_notify_xweather_poll.lock') if name in names]
        zones = sorted(name for name in names if re.fullmatch(r'nws-poll-[A-Za-z0-9_-]{1,64}\.lock', name))
        if len(zones) > 1000: raise RuntimeError('weather worker guard count exceeds limit')
        # Preserve the established outer-to-inner worker ordering.
        required = ['schedule-runner.lock', 'schedule-worker.lock', 'announcement-send.lock', 'announcement-activity.lock',
                    'weather-observation.lock', 'weather-dispatch-worker.lock', 'weather-external-worker.lock', 'xweather-poll.lock']
        tail = ['sls_mass_notify_nws_poll.lock', 'nws-audio-delivery.lock', 'test-cooldown.ts.lock']
        try:
            os.mkdir('live-paging-slots', 0o750, dir_fd=parent)
            paging = os.open('live-paging-slots', flags, dir_fd=parent)
            os.fchown(paging, account.pw_uid, account.pw_gid)
        except FileExistsError:
            paging = os.open('live-paging-slots', flags, dir_fd=parent)
        info = os.fstat(paging)
        named = os.stat('live-paging-slots', dir_fd=parent, follow_symlinks=False)
        if (info.st_uid not in (0, account.pw_uid)
                or info.st_gid not in (0, account.pw_gid)
                or stat.S_IMODE(info.st_mode) not in (0o700, 0o750, 0o755)
                or (info.st_dev, info.st_ino) != (named.st_dev, named.st_ino)):
            raise RuntimeError('unsafe live paging slot directory')
        deadline = time.monotonic() + timeout
        # Workers take this shared lease before module bootstrap and retain it
        # through exit. Acquire its exclusive lease FIRST so a supervisor cannot
        # load mutable code after the ordinary activity guard was established.
        entries = [(parent, 'enterprise-worker.lock', fcntl.LOCK_EX, True)]
        entries += [(parent, name, fcntl.LOCK_EX, True) for name in legacy + required + zones + tail]
        entries += [(paging, 'global-' + str(slot) + '.lock', fcntl.LOCK_EX, True) for slot in range(16)]
        # Both generations of audio writers are excluded during installation.
        # Shared leases allow the independent idle inspector to read, while
        # blocking writers that require an exclusive lease. Never create the
        # operational JSON here or replace either locked inode.
        entries += [(parent, 'audio-reservations.lock', fcntl.LOCK_SH, True),
                    (parent, 'audio-reservations.json', fcntl.LOCK_SH, False)]
        if settings: entries.append((parent, 'mass-notifications.config.lock', fcntl.LOCK_EX, True))
        for lock_parent, name, lock_mode, create in entries:
            if not create:
                try:
                    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=lock_parent)
                except FileNotFoundError:
                    continue
            else:
                try:
                    fd = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=lock_parent)
                    owner = os.fstat(parent) if name == 'audio-reservations.lock' else None
                    os.fchown(fd, owner.st_uid if owner else account.pw_uid, owner.st_gid if owner else account.pw_gid)
                except FileExistsError:
                    fd = os.open(name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=lock_parent)
            locks.append(fd)
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, account.pw_uid):
                raise RuntimeError('unsafe worker lock: ' + name)
            if name == 'enterprise-worker.lock' and (info.st_mode & 0o7027 or info.st_size > 4096):
                raise RuntimeError('enterprise worker lock permissions or size are unsafe')
            if name == 'mass-notifications.config.lock':
                settings_metadata(fd, lock_parent, account)
            if name in ('audio-reservations.lock', 'audio-reservations.json'):
                if info.st_uid != os.fstat(parent).st_uid:
                    raise RuntimeError('audio state owner must match the runtime data directory: ' + name)
                if info.st_mode & 0o7022:
                    raise RuntimeError('audio state permissions are unsafe: ' + name)
                maximum = len(b'SLS_AUDIO_STATE_V1\n') if name.endswith('.lock') else 1048576
                if info.st_size > maximum:
                    raise RuntimeError('audio state exceeds its safe size limit: ' + name)
            while True:
                try: fcntl.flock(fd, lock_mode | fcntl.LOCK_NB); break
                except BlockingIOError:
                    if time.monotonic() >= deadline: raise RuntimeError('active worker did not become idle: ' + name)
                    time.sleep(0.1)
            entry = os.stat(name, dir_fd=lock_parent, follow_symlinks=False)
            if (info.st_dev, info.st_ino) != (entry.st_dev, entry.st_ino): raise RuntimeError('worker lock identity changed')
            if name == 'mass-notifications.config.lock':
                settings_metadata(fd, lock_parent, account)
        check_current_directory(data, parent)
        print('ready', flush=True)
        settings_held = settings
        while True:
            command = sys.stdin.buffer.readline(33)
            if command in (b'', b'release\n'):
                break
            if command != b'settings\n':
                raise RuntimeError('unknown installer guard command')
            check_current_directory(data, parent)
            if not settings_held:
                acquire_settings(parent, account, locks, timeout)
                settings_held = True
            print('settings-ready', flush=True)
    finally:
        for fd in locks: os.close(fd)
        if paging >= 0: os.close(paging)
        os.close(parent)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', required=True)
    parser.add_argument('--settings', action='store_true')
    parser.add_argument('--timeout', type=float, default=120)
    parser.add_argument('action', choices=['hold'])
    args = parser.parse_args()
    if not 0.1 <= args.timeout <= 120:
        parser.error('Timeout must be between 0.1 and 120 seconds')
    if not args.data.startswith('/'):
        parser.error('Data directory must be absolute')
    hold(args.data, args.timeout, args.settings)


if __name__ == '__main__':
    main()
