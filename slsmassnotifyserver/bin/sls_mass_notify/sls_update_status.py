#!/usr/bin/python3
"""Write updater display state as the runtime account through checked descriptors."""
import fcntl
import json
import os
from pathlib import Path
import pwd
import stat
import sys
import time

LIMIT = 65536


def directory(path, uid):
    path = Path(path)
    if not path.is_absolute() or any(part in ('.', '..') for part in path.parts):
        raise ValueError('Invalid updater status directory')
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        parts = path.parts[1:]
        group = pwd.getpwuid(uid).pw_gid
        for index, part in enumerate(parts):
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor); descriptor = child
            info = os.fstat(descriptor)
            sticky = info.st_uid == 0 and part == 'tmp' and info.st_mode & stat.S_ISVTX
            group_write = info.st_mode & 0o020 and (index == len(parts)-1 or info.st_gid not in (0, group))
            if info.st_uid not in (0, uid) or (not sticky and (info.st_mode & 0o002 or group_write)):
                raise ValueError('Unsafe updater status parent')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def checked(info, uid, gid, bound):
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, uid)
            or info.st_gid != gid or info.st_mode & 0o137 or info.st_size > bound):
        raise ValueError('Unsafe updater status file')


def write(path, value, *, lock_seconds=0.5):
    account = pwd.getpwnam('asterisk')
    if os.geteuid() != account.pw_uid:
        raise PermissionError('Updater display state must be written as asterisk')
    path = Path(path)
    if path.name not in ('update-status.json', 'update-progress.json') or not isinstance(value, dict):
        raise ValueError('Invalid updater status identity')
    raw = (json.dumps(value, separators=(',', ':'), allow_nan=False) + '\n').encode()
    if len(raw) > LIMIT:
        raise ValueError('Updater status is oversized')
    parent = directory(path.parent, account.pw_uid)
    parent_info = os.fstat(parent)
    lock = None; temporary = None
    try:
        lock_name = path.name + '.lock'
        lock = os.open(lock_name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=parent)
        checked(os.fstat(lock), account.pw_uid, account.pw_gid, 0)
        def verify_lease():
            held = os.fstat(lock)
            named = os.stat(lock_name, dir_fd=parent, follow_symlinks=False)
            checked(held, account.pw_uid, account.pw_gid, 0)
            checked(named, account.pw_uid, account.pw_gid, 0)
            if (named.st_dev, named.st_ino) != (held.st_dev, held.st_ino):
                raise ValueError('Updater status lock changed')
            current_parent = directory(path.parent, account.pw_uid)
            try:
                info = os.fstat(current_parent)
                if (info.st_dev, info.st_ino) != (parent_info.st_dev, parent_info.st_ino):
                    raise ValueError('Updater status parent changed')
            finally:
                os.close(current_parent)
        deadline = time.monotonic() + lock_seconds
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise TimeoutError('Updater status lock is busy')
                time.sleep(0.01)
        verify_lease()
        try:
            existing = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            before = None
        else:
            try:
                before = os.fstat(existing)
                checked(before, account.pw_uid, account.pw_gid, LIMIT)
            finally:
                os.close(existing)
        temporary = '.update-state-' + os.urandom(12).hex()
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=parent)
        with os.fdopen(fd, 'wb') as output:
            output.write(raw); output.flush(); os.fsync(output.fileno())
        try:
            current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            current = None
        if ((before is None) != (current is None)
                or before is not None and (before.st_dev, before.st_ino) != (current.st_dev, current.st_ino)):
            raise ValueError('Updater status changed during replacement')
        if current is not None:
            checked(current, account.pw_uid, account.pw_gid, LIMIT)
        verify_lease()
        os.replace(temporary, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        temporary = None
        os.fsync(parent)
    finally:
        if temporary is not None:
            try: os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError: pass
        if lock is not None: os.close(lock)
        os.close(parent)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 2:
            raise ValueError('Provide the fixed updater status path')
        body = sys.stdin.buffer.read(LIMIT + 1)
        if len(body) > LIMIT:
            raise ValueError('Updater status is oversized')
        write(sys.argv[1], json.loads(body))
    except (OSError, ValueError, KeyError) as error:
        raise SystemExit('Updater status: ' + str(error)) from None
