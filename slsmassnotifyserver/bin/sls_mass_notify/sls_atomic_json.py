#!/usr/bin/env python3
"""Bounded JSON transactions using a permanent lock and atomic replacement."""
from contextlib import contextmanager
import fcntl
import json
import os
from pathlib import Path
import pwd
import secrets
import stat
import sys
import time


def _object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise RuntimeError('json_storage_duplicate_key')
        result[key] = value
    return result


def open_directory(path, owner):
    """Open an existing trusted directory without creating or following parents."""
    path = Path(path)
    if not path.is_absolute() or any(part in ('.', '..') for part in path.parts):
        raise RuntimeError('json_storage_directory_unsafe')
    group = pwd.getpwuid(owner).pw_gid
    parts = path.parts[1:]
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for index, part in enumerate(parts):
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
            info = os.fstat(descriptor)
            sticky = info.st_uid == 0 and part == 'tmp' and info.st_mode & stat.S_ISVTX
            # FreePBX may give its asterisk group write access to a core
            # ancestor. The final SLS journal directory remains private.
            group_write = info.st_mode & 0o020 and (index == len(parts)-1 or info.st_gid not in (0, group))
            if (info.st_uid not in (0, os.geteuid(), owner)
                    or not sticky and (info.st_mode & 0o002 or group_write)):
                raise RuntimeError('json_storage_directory_unsafe')
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def read_json(path, *, maximum=2 * 1024 * 1024, missing_ok=True, runtime_owner=False):
    path = Path(path)
    owner = pwd.getpwnam('asterisk').pw_uid if runtime_owner else os.geteuid()
    parent = open_directory(path.parent, owner)
    try:
        try:
            descriptor = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            if not missing_ok:
                raise
            try:
                lock = os.open(path.name+'.lock', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            except FileNotFoundError:
                return {}
            try:
                info = os.fstat(lock)
                if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, owner)
                        or info.st_mode & 0o7027 or info.st_size > 64):
                    raise RuntimeError('json_storage_file_unsafe')
                if os.read(lock, 65):
                    raise RuntimeError('json_storage_missing')
            finally:
                os.close(lock)
            return {}
        with os.fdopen(descriptor, 'rb') as stream:
            before = os.fstat(stream.fileno())
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in (0, owner)
                    or stat.S_IMODE(before.st_mode) not in (0o600, 0o640) or before.st_size > maximum):
                raise RuntimeError('json_storage_file_unsafe')
            raw = stream.read(maximum+1)
            after = os.fstat(stream.fileno())
            if (not raw or len(raw) != before.st_size or (before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                    != (after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                raise RuntimeError('json_storage_changed')
        data = json.loads(raw, object_pairs_hook=_object, parse_constant=lambda _: (_ for _ in ()).throw(ValueError('nonfinite_json')))
        if not isinstance(data, dict):
            raise RuntimeError('json_storage_invalid')
        # Also reject finite-looking exponent syntax that decodes to infinity.
        json.dumps(data, allow_nan=False)
        return data
    finally:
        os.close(parent)


def append_json_line(path, value, *, maximum=64 * 1024 * 1024, line_maximum=262144, timeout=2):
    """Append private audit evidence using the same inode lease as retention."""
    raw = (json.dumps(value, ensure_ascii=True, allow_nan=False, separators=(',', ':')) + '\n').encode('ascii')
    if not isinstance(value, dict) or len(raw) > line_maximum:
        raise RuntimeError('event_log_record_oversized_or_invalid')
    path = Path(path)
    account = pwd.getpwnam('asterisk')
    if os.geteuid() not in (0, account.pw_uid):
        raise PermissionError('event_log_runtime_account_required')
    parent = open_directory(path.parent, account.pw_uid)
    descriptor = -1
    try:
        descriptor = os.open(path.name, os.O_RDWR | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW
                             | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=parent)
        deadline = time.monotonic() + timeout
        while True:
            info = os.fstat(descriptor)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, account.pw_uid)
                    or stat.S_IMODE(info.st_mode) not in (0o600, 0o640, 0o644) or info.st_size > maximum):
                raise RuntimeError('event_log_file_unsafe')
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('event_log_busy')
                time.sleep(0.02)
        info = os.fstat(descriptor)
        named = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        pm = os.fstat(parent)
        named_parent = os.stat(path.parent, follow_symlinks=False)
        if ((info.st_dev, info.st_ino) != (named.st_dev, named.st_ino) or info.st_nlink != 1
                or (pm.st_dev, pm.st_ino) != (named_parent.st_dev, named_parent.st_ino)
                or path.parent.resolve() != path.parent.absolute()
                or info.st_uid not in (0, account.pw_uid) or stat.S_IMODE(info.st_mode) not in (0o600, 0o640, 0o644)
                or pm.st_uid not in (0, account.pw_uid) or pm.st_mode & 0o022):
            raise RuntimeError('event_log_changed')
        if info.st_size + len(raw) > maximum:
            raise RuntimeError('event_log_capacity_reached: run protected log cleanup before retrying')
        if info.st_size and os.pread(descriptor, 1, info.st_size - 1) != b'\n':
            raise RuntimeError('event_log_incomplete_record: preserve and review the log before appending')
        if os.geteuid() == 0:
            os.fchown(descriptor, account.pw_uid, account.pw_gid)
        os.fchmod(descriptor, 0o640)
        offset = 0
        while offset < len(raw):
            written = os.write(descriptor, raw[offset:])
            if written <= 0:
                raise RuntimeError('event_log_write_incomplete')
            offset += written
        os.fsync(descriptor)
        os.fsync(parent)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)


@contextmanager
def locked_json(path, *, maximum=2 * 1024 * 1024, timeout=2, require_existing=False, runtime_owner=False):
    path = Path(path)
    account = pwd.getpwnam('asterisk') if runtime_owner else None
    owner = account.pw_uid if account else os.geteuid()
    if os.geteuid() not in (0, owner):
        raise PermissionError('json_storage_runtime_account_required')
    parent = open_directory(path.parent, owner)
    lock = descriptor = temporary = -1
    temporary_name = ''
    try:
        pm = os.fstat(parent)
        named_parent = os.stat(path.parent, follow_symlinks=False)
        if (pm.st_mode & 0o022 or pm.st_uid not in (0, os.geteuid(), owner)
                or path.parent.resolve() != path.parent.absolute()
                or (pm.st_dev, pm.st_ino) != (named_parent.st_dev, named_parent.st_ino)):
            raise RuntimeError('json_storage_directory_unsafe')

        def checked(fd, name, limit):
            info = os.fstat(fd)
            named = os.stat(name, dir_fd=parent, follow_symlinks=False)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o7027
                    or info.st_uid not in (0, owner) or info.st_size > limit
                    or (info.st_dev, info.st_ino) != (named.st_dev, named.st_ino)):
                raise RuntimeError('json_storage_file_unsafe')
            return info

        flags = os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
        lock_name = path.name + '.lock'
        try:
            lock = os.open(lock_name, flags, dir_fd=parent)
        except FileNotFoundError:
            try:
                lock = os.open(lock_name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
                if account and os.geteuid() == 0:
                    os.fchown(lock, account.pw_uid, account.pw_gid)
            except FileExistsError:
                lock = os.open(lock_name, flags, dir_fd=parent)
        checked(lock, lock_name, 64)
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('json_storage_busy') from None
                time.sleep(.01)
        checked(lock, lock_name, 64)
        marker = os.read(lock, 65)
        if not b'SLS_JSON_V1\n'.startswith(marker):
            raise RuntimeError('json_storage_marker_invalid')
        before = None
        try:
            descriptor = os.open(path.name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        except FileNotFoundError:
            if marker and require_existing:
                raise RuntimeError('json_storage_missing')
            data = {}
        else:
            before = checked(descriptor, path.name, maximum)
            with os.fdopen(descriptor, 'rb') as stream:
                descriptor = -1
                raw = stream.read(maximum + 1)
                after = os.fstat(stream.fileno())
            if not raw or len(raw) != before.st_size:
                raise RuntimeError('json_storage_invalid')
            if ((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                    != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                raise RuntimeError('json_storage_changed')
            data = json.loads(raw, object_pairs_hook=_object, parse_constant=lambda _: (_ for _ in ()).throw(ValueError('nonfinite_json')))
            if not isinstance(data, dict):
                raise RuntimeError('json_storage_invalid')
        old = json.dumps(data, sort_keys=True, allow_nan=False)
        yield data
        if before is None or json.dumps(data, sort_keys=True, allow_nan=False) != old:
            raw = (json.dumps(data, indent=2, sort_keys=True, allow_nan=False) + '\n').encode('utf-8')
            if len(raw) > maximum:
                raise RuntimeError('json_storage_capacity')
            temporary_name = '.' + path.name + '.' + secrets.token_hex(16) + '.tmp'
            temporary = os.open(temporary_name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
            if account and os.geteuid() == 0:
                os.fchown(temporary, account.pw_uid, account.pw_gid)
            with os.fdopen(temporary, 'wb') as stream:
                temporary = -1
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
            try:
                current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                current = None
            if before is not None:
                if current is None or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (
                        current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns, current.st_ctime_ns):
                    raise RuntimeError('json_storage_changed')
            elif current is not None:
                raise RuntimeError('json_storage_changed')
            named_parent = os.stat(path.parent, follow_symlinks=False)
            if (pm.st_dev, pm.st_ino) != (named_parent.st_dev, named_parent.st_ino):
                raise RuntimeError('json_storage_directory_changed')
            checked(lock, lock_name, 64)
            os.replace(temporary_name, path.name, src_dir_fd=parent, dst_dir_fd=parent)
            temporary_name = ''
            os.fsync(parent)
        if marker != b'SLS_JSON_V1\n':
            os.lseek(lock, 0, os.SEEK_SET)
            if os.write(lock, b'SLS_JSON_V1\n') != len(b'SLS_JSON_V1\n'):
                raise RuntimeError('json_storage_commit_uncertain')
            os.fsync(lock)
            os.fsync(parent)
    finally:
        for fd in (descriptor, temporary, lock):
            if fd >= 0:
                os.close(fd)
        if temporary_name:
            try:
                os.unlink(temporary_name, dir_fd=parent)
            except FileNotFoundError:
                pass
        os.close(parent)


if __name__ == '__main__':
    try:
        if len(sys.argv) == 3 and sys.argv[1] == '--read-announcement-admission':
            target = Path(sys.argv[2])
            if not target.is_absolute() or target.name != 'announcement-admission.json':
                raise ValueError('Admission state path is invalid.')
            state = read_json(target, maximum=2 * 1024 * 1024, missing_ok=False)
            sys.stdout.write(json.dumps({'ok': True, 'state': state}, ensure_ascii=True,
                                        allow_nan=False, separators=(',', ':')))
            raise SystemExit(0)
        if sys.argv[1:] != ['--patch-status'] or os.geteuid() != pwd.getpwnam('asterisk').pw_uid:
            raise ValueError('Status updates require the runtime account and fixed action.')
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError('Status patch is oversized.')
        patch = json.loads(raw, object_pairs_hook=_object)
        if not isinstance(patch, dict):
            raise ValueError('Status patch must be an object.')
        with locked_json('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json', runtime_owner=True) as data:
            data.update(patch)
        print('{"ok":true}')
    except Exception:
        raise SystemExit('SLS status update failed. Preserve its journal and review permissions, storage and the permanent status lock.') from None
