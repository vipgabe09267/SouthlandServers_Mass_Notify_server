#!/usr/bin/env python3
"""Coordinate bounded, at-most-once NWS delivery across configured zones.

The weather scheduler polls zone groups concurrently.  This helper gives those
workers a deterministic delivery turn and atomically claims individual
destinations for one normalized NWS alert chain.  Only hashes are persisted, so
email addresses and other destination values never appear in coordination
state.
"""

from __future__ import annotations

import fcntl
import hashlib
import json
import math
import os
import pwd
import re
import secrets
import select
import stat
import sys
import time
from pathlib import Path


DEFAULT_STATE_FILE = Path(
    os.environ.get(
        "NWS_CROSS_ZONE_CLAIM_STATE",
        os.environ.get(
            "SLS_NWS_CROSS_ZONE_CLAIM_STATE",
            "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-cross-zone-delivery-claims.json",
        ),
    )
)
MAX_REQUEST_BYTES = 256 * 1024
# Ten thousand worst-case leased records serialize to roughly 5.5 MiB. Keep
# the byte ceiling consistent with MAX_CLAIMS while retaining a finite bound.
MAX_STATE_BYTES = 8 * 1024 * 1024
MAX_CLAIMS = 10_000
MAX_CYCLES = 128
MAX_DESTINATIONS = 3120
LOCK_WAIT_SECONDS = 0.5
HISTORY_MARKER = b'SLS_NWS_COORDINATION_V1\n'
LEGACY_HISTORY_MARKER = b'SLS_NWS_LEGACY_HISTORY_V1\n'
MAX_LEGACY_HISTORY_BYTES = 2 * 1024 * 1024
MAX_LEGACY_HISTORY_LINES = 5000
CLAIM_RETENTION_SECONDS = 30 * 86400
CYCLE_RETENTION_SECONDS = 2 * 3600
# A worker may legitimately wait up to 55 minutes for the serialized audio
# slot. Keep its reservation alive beyond that bound so another zone cannot
# take the same destination while the first worker is still active.
RESERVATION_LEASE_SECONDS = 70 * 60
ALLOWED_KINDS = {"phone", "desktop", "email", "discord", "generic", "voice", "sms"}
KIND_ORDER = ("phone", "desktop", "email", "discord", "generic", "voice", "sms")


class CoordinationError(RuntimeError):
    """Raised when coordination state cannot be used safely."""


def _json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ValueError('duplicate field')
            result[key] = value
        return result
    def constant(value):
        raise ValueError('nonfinite value')
    def number(value):
        result = float(value)
        if not math.isfinite(result):
            raise ValueError('nonfinite number')
        return result
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=constant, parse_float=number)
    except (ValueError, UnicodeError, RecursionError) as error:
        raise CoordinationError('coordination JSON is invalid; preserve its evidence') from error


def _account_ids():
    try:
        account = pwd.getpwnam("asterisk")
        return account.pw_uid, account.pw_gid
    except KeyError:
        return os.geteuid(), os.getegid()


def _open_directory(path: Path) -> int:
    if not path.is_absolute():
        raise CoordinationError("coordination path must be absolute")
    parts = [part for part in path.parts if part not in {"", "/"}]
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open("/", flags)
    try:
        for part in parts:
            if part in {".", ".."} or "/" in part or "\x00" in part:
                raise CoordinationError("coordination directory is unsafe")
            next_descriptor = os.open(part, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = next_descriptor
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def _checked_file_metadata(descriptor: int):
    metadata = os.fstat(descriptor)
    uid, gid = _account_ids()
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
            or metadata.st_uid not in (0, uid) or metadata.st_gid not in (0, gid)
            or stat.S_IMODE(metadata.st_mode) not in (0o600, 0o640, 0o644)):
        raise CoordinationError("coordination file has unsafe ownership or permissions")
    return metadata, uid, gid


def _secure_file_metadata(descriptor: int):
    metadata, uid, gid = _checked_file_metadata(descriptor)
    if stat.S_IMODE(metadata.st_mode) != 0o640:
        os.fchmod(descriptor, 0o640)
    if (metadata.st_uid, metadata.st_gid) == (uid, gid):
        return
    try:
        os.fchown(descriptor, uid, gid)
    except PermissionError:
        if metadata.st_uid != uid or metadata.st_gid != gid:
            raise CoordinationError("coordination file ownership is unsafe")


def _validate_parent_directory(descriptor: int):
    metadata = os.fstat(descriptor)
    asterisk_uid, _asterisk_gid = _account_ids()
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or metadata.st_mode & (stat.S_IWGRP | stat.S_IWOTH)
        or metadata.st_uid not in {0, asterisk_uid}
    ):
        raise CoordinationError("coordination directory ownership or permissions are unsafe")


def hold_worker_lock(path: Path, wait_seconds: int):
    """Lease an empty worker lock until the shell closes its reading pipe."""
    if wait_seconds not in (0, 3300):
        raise CoordinationError('worker lock wait is invalid')
    if not stat.S_ISFIFO(os.fstat(sys.stdout.fileno()).st_mode):
        raise CoordinationError('worker lock requires a shell-owned reading pipe')
    parent = _open_directory(path.parent)
    descriptor = None
    try:
        _validate_parent_directory(parent)
        flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NONBLOCK | os.O_NOFOLLOW
        descriptor = os.open(path.name, flags, 0o640, dir_fd=parent)
        metadata, _uid, _gid = _checked_file_metadata(descriptor)
        if metadata.st_size != 0:
            raise CoordinationError('worker lock contains unexpected data; preserve its evidence')
        deadline = time.monotonic() + wait_seconds
        while True:
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    print('busy', flush=True)
                    return 75
                time.sleep(0.05)
        named_parent = _open_directory(path.parent)
        try:
            _validate_parent_directory(named_parent)
            if ((os.fstat(parent).st_dev, os.fstat(parent).st_ino)
                    != (os.fstat(named_parent).st_dev, os.fstat(named_parent).st_ino)
                    or _identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False))
                    != _identity(os.fstat(descriptor))):
                raise CoordinationError('worker lock changed while acquiring its lease')
        finally:
            os.close(named_parent)
        _secure_file_metadata(descriptor)
        print('locked', flush=True)
        # A pipe reports POLLERR when its last reader closes, including after
        # the shell exits. No lock inode is replaced or truncated on release.
        pipe = select.poll()
        pipe.register(sys.stdout.fileno(), select.POLLERR | select.POLLHUP)
        while not pipe.poll():
            pass
        return 0
    finally:
        if descriptor is not None:
            os.close(descriptor)
        os.close(parent)


def _empty_state():
    return {"version": 1, "claims": {}, "cycles": {}}


def _read_state(parent_fd: int, name: str):
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(name, flags, dir_fd=parent_fd)
    except FileNotFoundError:
        return _empty_state()
    try:
        metadata = os.fstat(descriptor)
        expected_uid, expected_gid = _account_ids()
        if (
            not stat.S_ISREG(metadata.st_mode)
            or metadata.st_nlink != 1
            or metadata.st_size > MAX_STATE_BYTES
            or metadata.st_uid != expected_uid
            or metadata.st_gid != expected_gid
            or metadata.st_mode & (stat.S_IWGRP | stat.S_IWOTH)
        ):
            raise CoordinationError("coordination state is unsafe or too large")
        raw = bytearray()
        while len(raw) <= MAX_STATE_BYTES:
            chunk = os.read(descriptor, min(65536, MAX_STATE_BYTES + 1 - len(raw)))
            if not chunk:
                break
            raw.extend(chunk)
        if len(raw) > MAX_STATE_BYTES:
            raise CoordinationError("coordination state is too large")
    finally:
        os.close(descriptor)
    try:
        decoded = _json(raw.decode("utf-8"))
    except (UnicodeError, CoordinationError) as exc:
        raise CoordinationError("coordination state is corrupt") from exc
    if not isinstance(decoded, dict) or type(decoded.get("version")) is not int or decoded['version'] != 1:
        raise CoordinationError("coordination state schema is invalid")
    if not isinstance(decoded.get("claims"), dict) or not isinstance(decoded.get("cycles"), dict):
        raise CoordinationError("coordination state structure is invalid")
    _validate_state(decoded)
    return decoded


def _identity(metadata):
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_uid, metadata.st_gid,
            metadata.st_nlink, metadata.st_size, metadata.st_mtime_ns, metadata.st_ctime_ns)


def _write_state(parent_fd: int, name: str, state, expected):
    encoded = (json.dumps(state, separators=(",", ":"), sort_keys=True, allow_nan=False) + "\n").encode("utf-8")
    if len(encoded) > MAX_STATE_BYTES:
        raise CoordinationError("coordination state capacity is exhausted")
    _write_bytes(parent_fd, name, encoded, expected)


def _write_bytes(parent_fd: int, name: str, encoded: bytes, expected, parent_path=None):
    temporary = f".{name}.tmp.{os.getpid()}.{secrets.token_hex(8)}"
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(temporary, flags, 0o640, dir_fd=parent_fd)
    try:
        _secure_file_metadata(descriptor)
        offset = 0
        while offset < len(encoded):
            written = os.write(descriptor, encoded[offset:])
            if written <= 0:
                raise CoordinationError('coordination state write could not complete')
            offset += written
        os.fsync(descriptor)
    except BaseException:
        try:
            os.unlink(temporary, dir_fd=parent_fd)
        except FileNotFoundError:
            pass
        raise
    finally:
        os.close(descriptor)
    try:
        try:
            target = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        except FileNotFoundError:
            target = None
        if ((target is None) != (expected is None)
                or (target is not None and _identity(target) != _identity(expected))):
            raise CoordinationError("coordination state changed before commit; evidence was preserved")
        if parent_path is not None:
            named_parent = _open_directory(parent_path)
            try:
                _validate_parent_directory(named_parent)
                if ((os.fstat(parent_fd).st_dev, os.fstat(parent_fd).st_ino)
                        != (os.fstat(named_parent).st_dev, os.fstat(named_parent).st_ino)):
                    raise CoordinationError('legacy history parent changed before commit')
            finally:
                os.close(named_parent)
        os.replace(temporary, name, src_dir_fd=parent_fd, dst_dir_fd=parent_fd)
        os.fsync(parent_fd)
    finally:
        try:
            os.unlink(temporary, dir_fd=parent_fd)
        except FileNotFoundError:
            pass


def legacy_alert_history(path: Path, operation: str, key: str = ''):
    """Preserve the old line-based history behind a permanent checked lease."""
    if operation not in {'ensure', 'contains', 'add', 'remove'}:
        raise CoordinationError('legacy history operation is invalid')
    if operation != 'ensure' and (not key or len(key.encode('utf-8')) > 4096
            or re.search(r'[\x00-\x1f\x7f]', key)):
        raise CoordinationError('legacy history key is invalid')
    parent = _open_directory(path.parent)
    lock = None
    descriptor = None
    try:
        _validate_parent_directory(parent)
        flags = os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        lock_name = path.name + '.lock'
        lock = os.open(lock_name, flags, 0o640, dir_fd=parent)
        lock_meta, _uid, _gid = _checked_file_metadata(lock)
        if lock_meta.st_size > len(LEGACY_HISTORY_MARKER):
            raise CoordinationError('legacy history lock is unsafe')
        deadline = time.monotonic() + LOCK_WAIT_SECONDS
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise CoordinationError('legacy history is busy; retry a later poll')
                time.sleep(0.01)
        marker = os.pread(lock, len(LEGACY_HISTORY_MARKER) + 1, 0)
        if marker and not LEGACY_HISTORY_MARKER.startswith(marker):
            raise CoordinationError('legacy history marker is invalid')
        try:
            descriptor = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            if marker or operation != 'ensure':
                raise CoordinationError('established legacy history is missing; restore it before polling')
            metadata = None
            encoded = b''
            lines = []
        else:
            metadata, _uid, _gid = _checked_file_metadata(descriptor)
            if metadata.st_size > MAX_LEGACY_HISTORY_BYTES:
                raise CoordinationError('legacy history byte capacity is exhausted; preserve it for review')
            encoded = bytearray()
            while len(encoded) <= MAX_LEGACY_HISTORY_BYTES:
                chunk = os.read(descriptor, min(65536, MAX_LEGACY_HISTORY_BYTES + 1 - len(encoded)))
                if not chunk:
                    break
                encoded.extend(chunk)
            if len(encoded) > MAX_LEGACY_HISTORY_BYTES or _identity(os.fstat(descriptor)) != _identity(metadata):
                raise CoordinationError('legacy history changed or exceeded its byte capacity')
            try:
                text = bytes(encoded).decode('utf-8')
            except UnicodeError as error:
                raise CoordinationError('legacy history is not valid UTF-8') from error
            lines = text.splitlines()
            if (len(lines) > MAX_LEGACY_HISTORY_LINES
                    or any(not line or len(line.encode('utf-8')) > 4096
                           or re.search(r'[\x00-\x1f\x7f]', line) for line in lines)
                    or re.search(r'[\x00-\x09\x0b-\x1f\x7f]', text)):
                raise CoordinationError('legacy history structure or capacity is invalid; no records were discarded')
        named_parent = _open_directory(path.parent)
        try:
            _validate_parent_directory(named_parent)
            if ((os.fstat(parent).st_dev, os.fstat(parent).st_ino)
                    != (os.fstat(named_parent).st_dev, os.fstat(named_parent).st_ino)
                    or _identity(os.stat(lock_name, dir_fd=parent, follow_symlinks=False))
                    != _identity(os.fstat(lock))):
                raise CoordinationError('legacy history lease changed before use')
        finally:
            os.close(named_parent)
        expected = metadata
        if descriptor is not None:
            if _identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) != _identity(metadata):
                raise CoordinationError('legacy history changed before use')
            _secure_file_metadata(descriptor)
            expected = os.fstat(descriptor)
            if ((metadata.st_dev, metadata.st_ino, metadata.st_nlink, metadata.st_size, metadata.st_mtime_ns)
                    != (expected.st_dev, expected.st_ino, expected.st_nlink, expected.st_size, expected.st_mtime_ns)):
                raise CoordinationError('legacy history contents changed during permission repair')
        _secure_file_metadata(lock)
        found = key in lines
        if operation == 'contains' and not found and (len(lines) >= MAX_LEGACY_HISTORY_LINES
                or len(encoded) + len(key.encode('utf-8')) + 1 > MAX_LEGACY_HISTORY_BYTES):
            raise CoordinationError('legacy history is full; new delivery was not admitted')
        if operation == 'add' and not found:
            if len(lines) >= MAX_LEGACY_HISTORY_LINES:
                raise CoordinationError('legacy history is full; no active records were discarded')
            lines.append(key)
        elif operation == 'remove' and found:
            lines = [line for line in lines if line != key]
        replacement = ('\n'.join(lines) + ('\n' if lines else '')).encode('utf-8')
        if len(replacement) > MAX_LEGACY_HISTORY_BYTES:
            raise CoordinationError('legacy history byte capacity is exhausted')
        if expected is None or replacement != bytes(encoded):
            _write_bytes(parent, path.name, replacement, expected, path.parent)
        if marker != LEGACY_HISTORY_MARKER:
            offset = len(marker)
            while offset < len(LEGACY_HISTORY_MARKER):
                written = os.pwrite(lock, LEGACY_HISTORY_MARKER[offset:], offset)
                if written <= 0:
                    raise CoordinationError('legacy history marker write failed')
                offset += written
            os.fsync(lock)
        return found
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if lock is not None:
            os.close(lock)
        os.close(parent)


class LockedState:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.parent_fd = -1
        self.lock_fd = -1
        self.state = None

    def __enter__(self):
        if not self.path.is_absolute() or self.path.name in {"", ".", ".."}:
            raise CoordinationError("coordination state path is unsafe")
        try:
            self.parent_fd = _open_directory(self.path.parent)
            _validate_parent_directory(self.parent_fd)
            lock_name = self.path.name + ".lock"
            flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0)
            self.lock_fd = os.open(lock_name, flags, 0o640, dir_fd=self.parent_fd)
            self._read_lock_marker()
            deadline = time.monotonic() + LOCK_WAIT_SECONDS
            while True:
                try:
                    fcntl.flock(self.lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        raise CoordinationError('coordination state is busy; retry the same request')
                    time.sleep(0.01)
            current = os.stat(lock_name, dir_fd=self.parent_fd, follow_symlinks=False)
            if _identity(os.fstat(self.lock_fd)) != _identity(current):
                raise CoordinationError('coordination lock changed during acquisition')
            self.lock_marker = self._read_lock_marker()
            _secure_file_metadata(self.lock_fd)
            self.lock_identity = _identity(os.fstat(self.lock_fd))
            self.state_identity = self._state_metadata()
            if self.lock_marker and self.state_identity is None:
                raise CoordinationError('established coordination history is missing; preserve the lock and restore reviewed evidence')
            self.state = _read_state(self.parent_fd, self.path.name)
            self._verify_named_storage()
            return self
        except BaseException:
            if self.lock_fd >= 0:
                os.close(self.lock_fd)
                self.lock_fd = -1
            if self.parent_fd >= 0:
                os.close(self.parent_fd)
                self.parent_fd = -1
            raise

    def commit(self):
        if self.state is None:
            raise CoordinationError("coordination state is unavailable")
        _validate_state(self.state)
        self._verify_named_storage()
        _write_state(self.parent_fd, self.path.name, self.state, self.state_identity)
        self.state_identity = self._state_metadata()
        self._commit_history_marker()

    def _read_lock_marker(self):
        metadata, _, _ = _checked_file_metadata(self.lock_fd)
        if metadata.st_size > len(HISTORY_MARKER):
            raise CoordinationError('coordination lock has unknown contents')
        raw = os.pread(self.lock_fd, len(HISTORY_MARKER) + 1, 0)
        if not HISTORY_MARKER.startswith(raw):
            raise CoordinationError('coordination lock has unknown contents')
        return raw

    def _commit_history_marker(self):
        if self.lock_marker == HISTORY_MARKER:
            return
        if os.pwrite(self.lock_fd, HISTORY_MARKER, 0) != len(HISTORY_MARKER):
            raise CoordinationError('coordination history marker commit is uncertain; preserve state and retry the same request')
        os.ftruncate(self.lock_fd, len(HISTORY_MARKER))
        os.fsync(self.lock_fd)
        self.lock_marker = HISTORY_MARKER
        self.lock_identity = _identity(os.fstat(self.lock_fd))
        self._verify_named_storage()
        os.fsync(self.parent_fd)

    def _state_metadata(self):
        try:
            return os.stat(self.path.name, dir_fd=self.parent_fd, follow_symlinks=False)
        except FileNotFoundError:
            return None

    def _verify_named_storage(self):
        descriptor = _open_directory(self.path.parent)
        try:
            _validate_parent_directory(descriptor)
            opened, named = os.fstat(self.parent_fd), os.fstat(descriptor)
            if (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino):
                raise CoordinationError('coordination directory changed during access')
        finally:
            os.close(descriptor)
        current = os.stat(self.path.name + '.lock', dir_fd=self.parent_fd, follow_symlinks=False)
        if _identity(current) != self.lock_identity:
            raise CoordinationError('coordination lock changed during access')
        current = self._state_metadata()
        if ((current is None) != (self.state_identity is None)
                or (current is not None and _identity(current) != _identity(self.state_identity))):
            raise CoordinationError('coordination state changed during access')

    def __exit__(self, exc_type, exc, traceback):
        if self.lock_fd >= 0:
            try:
                fcntl.flock(self.lock_fd, fcntl.LOCK_UN)
            finally:
                os.close(self.lock_fd)
                self.lock_fd = -1
        if self.parent_fd >= 0:
            os.close(self.parent_fd)
            self.parent_fd = -1
        return False


def _digest(namespace: str, value: str) -> str:
    return hashlib.sha256((f"sls-nws-{namespace}-v1\0" + value).encode("utf-8")).hexdigest()


def _safe_cycle_id(value) -> str:
    value = str(value or "")
    if not re.fullmatch(r"[A-Za-z0-9_-]{8,128}", value):
        raise CoordinationError("cycle ID is invalid")
    return value


def _safe_rank(value) -> int:
    if isinstance(value, bool):
        raise CoordinationError("group rank is invalid")
    try:
        rank = int(value)
    except (TypeError, ValueError) as exc:
        raise CoordinationError("group rank is invalid") from exc
    if rank < 0 or rank > 4:
        raise CoordinationError("group rank is invalid")
    return rank


def _safe_reservation_id(value) -> str:
    value = str(value or "")
    if value and not re.fullmatch(r"[A-Za-z0-9_-]{8,160}", value):
        raise CoordinationError("reservation ID is invalid")
    return value


def _safe_expected_count(value) -> int:
    if isinstance(value, bool):
        raise CoordinationError("reservation transition count is invalid")
    try:
        expected = int(value)
    except (TypeError, ValueError) as exc:
        raise CoordinationError("reservation transition count is invalid") from exc
    if expected < 0 or expected > MAX_DESTINATIONS:
        raise CoordinationError("reservation transition count is invalid")
    return expected


def _normalize_destination(kind: str, value) -> str:
    raw = str(value or "").strip()
    if kind == "phone":
        return raw if re.fullmatch(r"[0-9]{1,32}", raw) else ""
    if kind == "desktop":
        normalized = raw.lower()
        return normalized if re.fullmatch(r"[a-z0-9_.-]{1,48}", normalized) else ""
    if kind == "email":
        normalized = raw.lower()
        return normalized if len(normalized) <= 254 and re.fullmatch(
            r"[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,63}", normalized
        ) else ""
    if kind in {"voice", "sms"}:
        return raw if re.fullmatch(kind + r"_[a-f0-9]{24}", raw) else ""
    if kind in {"discord", "generic"}:
        return raw if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", raw) else ""
    return ""


def _validate_state(state):
    if (not isinstance(state, dict) or set(state) != {'version', 'claims', 'cycles'}
            or type(state.get('version')) is not int or state['version'] != 1
            or not isinstance(state.get('claims'), dict) or not isinstance(state.get('cycles'), dict)):
        raise CoordinationError('coordination state structure is invalid; preserve its evidence')
    if len(state['claims']) > MAX_CLAIMS or len(state['cycles']) > MAX_CYCLES:
        raise CoordinationError('coordination state capacity is exhausted')
    for key, row in state['claims'].items():
        if (not isinstance(key, str) or not re.fullmatch(r'[a-f0-9]{64}:[a-f0-9]{64}', key)
                or not isinstance(row, dict)
                or set(row) - {'chain', 'destination', 'kind', 'group', 'group_rank', 'claimed_at', 'status', 'owner', 'lease_until'}):
            raise CoordinationError('coordination claim is invalid; preserve its evidence')
        chain, destination = key.split(':', 1)
        if (row.get('chain') != chain or row.get('destination') != destination
                or not isinstance(row.get('kind'), str) or row['kind'] not in ALLOWED_KINDS
                or not isinstance(row.get('group'), str) or not re.fullmatch(r'[a-f0-9]{64}', row['group'])
                or type(row.get('group_rank')) is not int or not 0 <= row['group_rank'] <= 4
                or type(row.get('claimed_at')) is not int or not 0 <= row['claimed_at'] <= 253402300799
                or row.get('status', 'committed') not in ('reserved', 'committed')):
            raise CoordinationError('coordination claim is invalid; preserve its evidence')
        if row.get('status') == 'reserved' and (
                not isinstance(row.get('owner'), str) or not re.fullmatch(r'[a-f0-9]{64}', row['owner'])
                or type(row.get('lease_until')) is not int or not row['claimed_at'] <= row['lease_until'] <= 253402300799):
            raise CoordinationError('coordination reservation is invalid; preserve its evidence')
    for key, row in state['cycles'].items():
        if (not isinstance(key, str) or not re.fullmatch(r'[a-f0-9]{64}', key)
                or not isinstance(row, dict) or set(row) != {'started_at', 'group_count', 'completed'}
                or type(row.get('started_at')) is not int or not 0 <= row['started_at'] <= 253402300799
                or type(row.get('group_count')) is not int or not 1 <= row['group_count'] <= 5
                or not isinstance(row.get('completed'), list)
                or any(type(rank) is not int or not 0 <= rank < row['group_count'] for rank in row['completed'])
                or len(set(row['completed'])) != len(row['completed'])):
            raise CoordinationError('coordination cycle is invalid or unavailable; preserve its evidence')


def _prune(state, now: int):
    state['claims'] = {
        key: row for key, row in state['claims'].items()
        if row['claimed_at'] >= now - CLAIM_RETENTION_SECONDS
        and (row.get('status', 'committed') != 'reserved' or row['lease_until'] >= now)
    }
    state['cycles'] = {
        key: row for key, row in state['cycles'].items()
        if row['started_at'] >= now - CYCLE_RETENTION_SECONDS
    }


def begin_cycle(path: Path, cycle_id: str, group_count: int, now=None):
    cycle_id = _safe_cycle_id(cycle_id)
    if isinstance(group_count, bool):
        raise CoordinationError("group count is invalid")
    try:
        group_count = int(group_count)
    except (TypeError, ValueError) as exc:
        raise CoordinationError("group count is invalid") from exc
    if group_count < 1 or group_count > 5:
        raise CoordinationError("group count is invalid")
    current = int(time.time() if now is None else now)
    cycle_key = _digest("cycle", cycle_id)
    with LockedState(path) as locked:
        _prune(locked.state, current)
        existing = locked.state["cycles"].get(cycle_key)
        if existing is not None and existing.get("group_count") != group_count:
            raise CoordinationError("cycle group count changed")
        locked.state["cycles"].setdefault(cycle_key, {
            "started_at": current,
            "group_count": group_count,
            "completed": [],
        })
        if len(locked.state["cycles"]) > MAX_CYCLES:
            raise CoordinationError("cycle coordination capacity is exhausted")
        locked.commit()


def complete_turn(path: Path, cycle_id: str, rank: int, now=None):
    cycle_id = _safe_cycle_id(cycle_id)
    rank = _safe_rank(rank)
    current = int(time.time() if now is None else now)
    cycle_key = _digest("cycle", cycle_id)
    with LockedState(path) as locked:
        _prune(locked.state, current)
        cycle = locked.state["cycles"].get(cycle_key)
        if not isinstance(cycle, dict) or rank >= int(cycle.get("group_count", 0)):
            raise CoordinationError("delivery cycle is unavailable")
        completed = {int(value) for value in cycle.get("completed", []) if isinstance(value, int)}
        completed.add(rank)
        cycle["completed"] = sorted(completed)
        locked.commit()


def wait_turn(path: Path, cycle_id: str, rank: int, timeout_seconds=3300, clock=time.monotonic, sleep=time.sleep):
    cycle_id = _safe_cycle_id(cycle_id)
    rank = _safe_rank(rank)
    try:
        timeout_seconds = max(1, min(3300, int(timeout_seconds)))
    except (TypeError, ValueError) as exc:
        raise CoordinationError("turn timeout is invalid") from exc
    cycle_key = _digest("cycle", cycle_id)
    deadline = clock() + timeout_seconds
    while True:
        with LockedState(path) as locked:
            _prune(locked.state, int(time.time()))
            cycle = locked.state["cycles"].get(cycle_key)
            if not isinstance(cycle, dict) or rank >= int(cycle.get("group_count", 0)):
                raise CoordinationError("delivery cycle is unavailable")
            completed = {int(value) for value in cycle.get("completed", []) if isinstance(value, int)}
            if all(lower_rank in completed for lower_rank in range(rank)):
                return
        if clock() >= deadline:
            raise CoordinationError("timed out waiting for an earlier Weather zone")
        sleep(min(0.2, max(0.0, deadline - clock())))


def claim_destination_sets(
    path: Path,
    alert_chain: str,
    destination_sets,
    group_id="",
    group_rank=0,
    now=None,
    reservation_id="",
):
    alert_chain = str(alert_chain or "").strip()
    if not alert_chain or len(alert_chain.encode("utf-8")) > 4096 or "\x00" in alert_chain:
        raise CoordinationError("alert chain is invalid")
    if not isinstance(destination_sets, dict) or any(kind not in ALLOWED_KINDS for kind in destination_sets):
        raise CoordinationError("destination sets are invalid")
    rank = _safe_rank(group_rank)
    reservation_id = _safe_reservation_id(reservation_id)
    normalized_sets = {}
    originals = {}
    total = 0
    for kind in KIND_ORDER:
        destinations = destination_sets.get(kind, [])
        limit = 1000 if kind in {'phone','desktop','voice'} else (50 if kind in {'email','sms'} else 10)
        if not isinstance(destinations, list) or len(destinations) > limit:
            raise CoordinationError("destination list is invalid")
        normalized = []
        kind_originals = {}
        for value in destinations:
            item = _normalize_destination(kind, value)
            if not item:
                raise CoordinationError("destination value is invalid")
            if item not in kind_originals:
                kind_originals[item] = str(value).strip()
                normalized.append(item)
        total += len(normalized)
        if total > MAX_DESTINATIONS:
            raise CoordinationError("destination set capacity is exhausted")
        normalized_sets[kind] = normalized
        originals[kind] = kind_originals
    current = int(time.time() if now is None else now)
    chain_hash = _digest("chain", alert_chain)
    group_hash = _digest("group", str(group_id or ""))
    owner_hash = _digest("reservation", reservation_id) if reservation_id else ""
    claimed = {kind: [] for kind in KIND_ORDER}
    duplicates = {kind: [] for kind in KIND_ORDER}
    reserved = {kind: [] for kind in KIND_ORDER}
    with LockedState(path) as locked:
        _prune(locked.state, current)
        pending = []
        for kind in KIND_ORDER:
            for destination in normalized_sets[kind]:
                destination_hash = _digest(f"destination-{kind}", destination)
                claim_key = chain_hash + ":" + destination_hash
                existing = locked.state["claims"].get(claim_key)
                if (
                    isinstance(existing, dict)
                    and existing.get("status") == "reserved"
                    and owner_hash
                    and existing.get("owner") == owner_hash
                ):
                    # Idempotent retry by the same worker still owns this
                    # reservation and must receive the original target set.
                    claimed[kind].append(originals[kind][destination])
                elif isinstance(existing, dict) and existing.get("status") == "reserved":
                    reserved[kind].append(originals[kind][destination])
                elif existing is not None:
                    duplicates[kind].append(originals[kind][destination])
                else:
                    pending.append((kind, destination, destination_hash, claim_key))
        if len(locked.state["claims"]) + len(pending) > MAX_CLAIMS:
            raise CoordinationError("delivery claim capacity is exhausted")
        for kind, destination, destination_hash, claim_key in pending:
            record = {
                "chain": chain_hash,
                "destination": destination_hash,
                "kind": kind,
                "group": group_hash,
                "group_rank": rank,
                "claimed_at": current,
            }
            if owner_hash:
                record.update({
                    "status": "reserved",
                    "owner": owner_hash,
                    "lease_until": current + RESERVATION_LEASE_SECONDS,
                })
            else:
                record["status"] = "committed"
            locked.state["claims"][claim_key] = record
            claimed[kind].append(originals[kind][destination])
        locked.commit()
    return {"claimed": claimed, "duplicates": duplicates, "reserved": reserved}


def claim_destinations(
    path: Path,
    alert_chain: str,
    kind: str,
    destinations,
    group_id="",
    group_rank=0,
    now=None,
    reservation_id="",
):
    kind = str(kind or "").strip().lower()
    if kind not in ALLOWED_KINDS:
        raise CoordinationError("destination kind is invalid")
    result = claim_destination_sets(
        path,
        alert_chain,
        {kind: destinations},
        group_id,
        group_rank,
        now,
        reservation_id,
    )
    return {
        "claimed": result["claimed"][kind],
        "duplicates": result["duplicates"][kind],
        "reserved": result["reserved"][kind],
    }


def finalize_reservation(
    path: Path,
    alert_chain: str,
    reservation_id: str,
    kinds,
    action: str,
    expected_count,
    now=None,
):
    alert_chain = str(alert_chain or "").strip()
    if not alert_chain or len(alert_chain.encode("utf-8")) > 4096 or "\x00" in alert_chain:
        raise CoordinationError("alert chain is invalid")
    reservation_id = _safe_reservation_id(reservation_id)
    if not reservation_id:
        raise CoordinationError("reservation ID is required")
    if not isinstance(kinds, list) or not kinds or any(str(kind) not in ALLOWED_KINDS for kind in kinds):
        raise CoordinationError("reservation kinds are invalid")
    normalized_kinds = set(map(str, kinds))
    if action not in {"commit", "release"}:
        raise CoordinationError("reservation action is invalid")
    expected_count = _safe_expected_count(expected_count)
    current = int(time.time() if now is None else now)
    chain_hash = _digest("chain", alert_chain)
    owner_hash = _digest("reservation", reservation_id)
    changed = 0
    with LockedState(path) as locked:
        _prune(locked.state, current)
        matching = []
        for claim_key, record in locked.state["claims"].items():
            if (
                not isinstance(record, dict)
                or record.get("status") != "reserved"
                or record.get("chain") != chain_hash
                or record.get("owner") != owner_hash
                or record.get("kind") not in normalized_kinds
            ):
                continue
            matching.append((claim_key, record))
        if len(matching) != expected_count:
            raise CoordinationError(
                "reservation transition count changed before finalization"
            )
        for claim_key, record in matching:
            if action == "release":
                locked.state["claims"].pop(claim_key, None)
            else:
                record["status"] = "committed"
                record.pop("owner", None)
                record.pop("lease_until", None)
            changed += 1
        locked.commit()
    return {"changed": changed}


def _request_from_stdin():
    raw = sys.stdin.buffer.read(MAX_REQUEST_BYTES + 1)
    if len(raw) > MAX_REQUEST_BYTES:
        raise CoordinationError("coordination request is too large")
    try:
        request = _json(raw.decode("utf-8"))
    except (UnicodeError, CoordinationError) as exc:
        raise CoordinationError("coordination request is invalid") from exc
    if not isinstance(request, dict):
        raise CoordinationError("coordination request must be an object")
    return request


def main():
    if len(sys.argv) in (4, 5) and sys.argv[1] == '--legacy-history':
        found = legacy_alert_history(Path(sys.argv[3]), sys.argv[2], sys.argv[4] if len(sys.argv) == 5 else '')
        return 0 if sys.argv[2] != 'contains' or found else 1
    if len(sys.argv) == 4 and sys.argv[1] == '--hold-lock':
        try:
            wait_seconds = int(sys.argv[3])
        except ValueError as error:
            raise CoordinationError('worker lock wait is invalid') from error
        return hold_worker_lock(Path(sys.argv[2]), wait_seconds)
    if len(sys.argv) != 1:
        raise CoordinationError('coordination arguments are invalid')
    request = _request_from_stdin()
    operation = str(request.get("op") or "")
    path = DEFAULT_STATE_FILE
    if operation == "begin_cycle":
        begin_cycle(path, request.get("cycle_id"), request.get("group_count"))
        result = {"ok": True}
    elif operation == "wait_turn":
        wait_turn(path, request.get("cycle_id"), request.get("rank"), request.get("timeout_seconds", 3300))
        result = {"ok": True}
    elif operation == "complete_turn":
        complete_turn(path, request.get("cycle_id"), request.get("rank"))
        result = {"ok": True}
    elif operation == "claim":
        result = claim_destinations(
            path,
            request.get("alert_chain"),
            request.get("kind"),
            request.get("destinations"),
            request.get("group_id", ""),
            request.get("group_rank", 0),
            reservation_id=request.get("reservation_id", ""),
        )
        result["ok"] = True
    elif operation == "claim_many":
        result = claim_destination_sets(
            path,
            request.get("alert_chain"),
            request.get("destinations"),
            request.get("group_id", ""),
            request.get("group_rank", 0),
            reservation_id=request.get("reservation_id", ""),
        )
        result["ok"] = True
    elif operation == "finalize":
        result = finalize_reservation(
            path,
            request.get("alert_chain"),
            request.get("reservation_id"),
            request.get("kinds"),
            request.get("action"),
            request.get("expected_count"),
        )
        result["ok"] = True
    else:
        raise CoordinationError("coordination operation is invalid")
    print(json.dumps(result, separators=(",", ":"), ensure_ascii=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CoordinationError, OSError) as exc:
        print(f"NWS delivery coordination failed: {exc}", file=sys.stderr)
        raise SystemExit(2 if sys.argv[1:2] == ['--legacy-history'] else 1)
