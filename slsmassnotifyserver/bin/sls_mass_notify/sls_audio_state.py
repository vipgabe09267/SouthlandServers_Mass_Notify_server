#!/usr/bin/python3
"""Durable paging state, serialized by a permanent lock inode.

A failed commit is never rolled back after replacement: callers must treat an
exception as an uncertain reservation and must not submit the associated audio.
Readers keep their shared lock until their context exits.
"""
from contextlib import contextmanager
import fcntl
import json
import math
import os
from pathlib import Path
import pwd
import re
import stat
import secrets

LIMIT = 1048576
LOCK = 'audio-reservations.lock'
STATE = 'audio-reservations.json'
MARKER = b'SLS_AUDIO_STATE_V1\n'
FIELDS = ('recipients', 'media', 'waiting')


def _number(value):
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


def validate_state(value):
    """Return canonical maps; accept only the empty-array legacy PHP encoding."""
    if not isinstance(value, dict) or set(value) - set(FIELDS) or not {'recipients', 'media'} <= set(value):
        raise RuntimeError('Paging reservation storage is corrupt')
    state = {}
    for key in FIELDS:
        item = value.get(key, {})
        if item == []:
            item = {}
        if not isinstance(item, dict):
            raise RuntimeError('Invalid paging reservation map: ' + key)
        state[key] = dict(item)
    for key, pattern in (('recipients', r'[0-9]{1,20}'), ('media', r'[A-Za-z0-9_-]+\.wav')):
        if any(not isinstance(name, str) or not re.fullmatch(pattern, name) or not _number(end)
               for name, end in state[key].items()):
            raise RuntimeError('Invalid paging reservation identity or timestamp')
    if len(state['waiting']) > 100:
        raise RuntimeError('Paging waiting queue capacity exceeded')
    required = {'recipients', 'priority', 'created', 'expires', 'heartbeat', 'media_name', 'duration'}
    for ticket, row in state['waiting'].items():
        if not isinstance(ticket, str) or not re.fullmatch(r'[a-f0-9]{32}', ticket) or not isinstance(row, dict) or set(row) != required:
            raise RuntimeError('Invalid waiting page')
        recipients = row['recipients']
        if not isinstance(recipients, list) or not 0 < len(recipients) <= 1000 or any(not isinstance(v, str) or not re.fullmatch(r'[0-9]{1,20}', v) for v in recipients) or len(set(recipients)) != len(recipients):
            raise RuntimeError('Invalid waiting page recipients')
        if type(row['priority']) is not int or row['priority'] not in (0, 1):
            raise RuntimeError('Invalid waiting page priority')
        if not isinstance(row['media_name'], str) or not re.fullmatch(r'[A-Za-z0-9_-]+\.wav', row['media_name']):
            raise RuntimeError('Invalid waiting page media')
        if not _number(row['duration']) or not 0 < row['duration'] <= 1800:
            raise RuntimeError('Invalid waiting page duration')
        if any(not _number(row[field]) for field in ('created', 'expires', 'heartbeat')):
            raise RuntimeError('Invalid waiting page lifetime')
    return state


def _identity(info):
    return info.st_dev, info.st_ino


def _version(info):
    return (_identity(info), info.st_uid, info.st_gid, info.st_mode, info.st_nlink,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _regular(fd, directory_fd, name, owner, limit):
    info = os.fstat(fd)
    linked = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != owner
            or info.st_mode & 0o7022 or info.st_size > limit or _identity(info) != _identity(linked)):
        raise RuntimeError('Paging reservation file is unsafe: ' + name)
    return info


@contextmanager
def _directory(directory, writing):
    path = Path(os.path.abspath(directory))
    trusted = {0, os.geteuid()}
    if os.geteuid() == 0:
        try:
            trusted.add(pwd.getpwnam('asterisk').pw_uid)
        except KeyError:
            pass
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for index, part in enumerate(path.parts[1:]):
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            last = index == len(path.parts) - 2
            sticky_root_ancestor = not last and info.st_uid == 0 and info.st_mode & stat.S_ISVTX
            if info.st_uid not in trusted or (info.st_mode & 0o022 and not sticky_root_ancestor) or (last and info.st_mode & 0o7000):
                raise RuntimeError('Paging reservation directory is unsafe')
        info = os.fstat(fd)
        if writing and info.st_uid != os.geteuid():
            raise RuntimeError('Paging reservations must be written by the runtime directory owner')
        yield fd, info, path
    finally:
        os.close(fd)


def _directory_identity(fd, path):
    if _identity(os.fstat(fd)) != _identity(os.stat(path, follow_symlinks=False)):
        raise RuntimeError('Paging reservation directory changed')


def _write_all(fd, data):
    view = memoryview(data)
    while view:
        count = os.write(fd, view)
        if count <= 0:
            raise OSError('Paging reservation write made no progress')
        view = view[count:]


def _read(fd, maximum):
    chunks = []
    total = 0
    while True:
        block = os.read(fd, min(65536, maximum + 1 - total))
        if not block:
            return b''.join(chunks)
        total += len(block)
        if total > maximum:
            raise RuntimeError('Paging reservation capacity exceeded')
        chunks.append(block)


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate paging reservation field')
        result[key] = value
    return result


@contextmanager
def _locked(directory, writing, nonblocking):
    with _directory(directory, writing) as (directory_fd, info, path):
        flags = os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
        created = False
        try:
            lock_fd = os.open(LOCK, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=directory_fd)
            created = True
        except FileExistsError:
            lock_fd = os.open(LOCK, flags, dir_fd=directory_fd)
        try:
            if created:
                if os.geteuid() == 0 and info.st_uid != 0:
                    os.fchown(lock_fd, info.st_uid, info.st_gid)
                os.fchmod(lock_fd, 0o640)
            _regular(lock_fd, directory_fd, LOCK, info.st_uid, len(MARKER))
            fcntl.flock(lock_fd, (fcntl.LOCK_EX if writing else fcntl.LOCK_SH) | (fcntl.LOCK_NB if nonblocking else 0))
            _regular(lock_fd, directory_fd, LOCK, info.st_uid, len(MARKER))
            _directory_identity(directory_fd, path)
            if created:
                os.fsync(lock_fd)
                os.fsync(directory_fd)
            marker = _read(lock_fd, len(MARKER))
            if marker not in (b'', MARKER):
                raise RuntimeError('Paging reservation initialization marker is corrupt')
            try:
                state_fd = os.open(STATE, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory_fd)
            except FileNotFoundError:
                if marker:
                    raise RuntimeError('Initialized paging reservation storage is missing')
                state = {key: {} for key in FIELDS}
                original = None
            else:
                try:
                    before = _regular(state_fd, directory_fd, STATE, info.st_uid, LIMIT)
                    raw = _read(state_fd, LIMIT)
                    after = _regular(state_fd, directory_fd, STATE, info.st_uid, LIMIT)
                    if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                        raise RuntimeError('Paging reservation storage changed while reading')
                    try:
                        state = validate_state(json.loads(raw, object_pairs_hook=_pairs))
                    except (ValueError, UnicodeError) as error:
                        raise RuntimeError('Paging reservation storage is corrupt') from error
                    original = _version(after)
                finally:
                    os.close(state_fd)
            yield state, directory_fd, lock_fd, info, path, marker, original
        finally:
            os.close(lock_fd)


@contextmanager
def read_audio_state(directory, nonblocking=True):
    """Read a validated snapshot while retaining the shared sidecar lease."""
    with _locked(directory, False, nonblocking) as values:
        yield values[0]
        _regular(values[2], values[1], LOCK, values[3].st_uid, len(MARKER))
        _directory_identity(values[1], values[4])


@contextmanager
def audio_state(directory):
    """Atomically commit a valid state after a successful exclusive transaction."""
    with _locked(directory, True, False) as (state, directory_fd, lock_fd, info, path, marker, original):
        yield state
        encoded = json.dumps(validate_state(state), separators=(',', ':'), allow_nan=False).encode('utf-8')
        if len(encoded) > LIMIT:
            raise RuntimeError('Paging reservation capacity exceeded')
        name = '.audio-reservations-' + secrets.token_hex(16) + '.tmp'
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640, dir_fd=directory_fd)
        replaced = False
        try:
            os.fchmod(fd, 0o640)
            _write_all(fd, encoded)
            os.fsync(fd)
            _regular(fd, directory_fd, name, info.st_uid, LIMIT)
            _regular(lock_fd, directory_fd, LOCK, info.st_uid, len(MARKER))
            _directory_identity(directory_fd, path)
            try:
                current = os.stat(STATE, dir_fd=directory_fd, follow_symlinks=False)
            except FileNotFoundError:
                current = None
            if (None if current is None else _version(current)) != original:
                raise RuntimeError('Paging reservation storage changed before commit')
            if os.pread(lock_fd, len(MARKER) + 1, 0) != marker:
                raise RuntimeError('Paging reservation initialization marker changed')
            os.replace(name, STATE, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
            replaced = True
            os.fsync(directory_fd)
            if not marker:
                os.lseek(lock_fd, 0, os.SEEK_SET)
                _write_all(lock_fd, MARKER)
                os.fsync(lock_fd)
        finally:
            try:
                if not replaced:
                    try:
                        linked = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                        if _identity(linked) == _identity(os.fstat(fd)):
                            os.unlink(name, dir_fd=directory_fd)
                    except FileNotFoundError:
                        pass
            finally:
                os.close(fd)
