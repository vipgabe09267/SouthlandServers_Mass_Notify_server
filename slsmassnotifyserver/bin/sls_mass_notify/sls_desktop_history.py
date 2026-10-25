#!/usr/bin/python3
"""Private immutable desktop evidence removed from the bounded live feed."""
import base64
import fcntl
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import time
import zlib

from sls_atomic_json import locked_json, open_directory, read_json, _object

MAX_EVENT_BYTES = 256 * 1024
MAX_HISTORY_BYTES = 64 * 1024 * 1024
MAX_HISTORY_FILES = 10000


def history_path(journal, identifier):
    return (
        Path(journal).parent
        / "history"
        / (hashlib.sha256(identifier.encode("utf-8")).hexdigest() + ".json")
    )


def decode_record(record, identifier):
    if (
        not isinstance(identifier, str)
        or not 1 <= len(identifier.encode("utf-8")) <= 2048
        or not isinstance(record, dict)
        or set(record) != {"schema", "id", "bytes", "sha256", "zlib_base64"}
        or type(record["schema"]) is not int
        or record["schema"] != 1
        or record["id"] != identifier
        or type(record["bytes"]) is not int
        or not 1 <= record["bytes"] <= MAX_EVENT_BYTES
        or not isinstance(record["sha256"], str)
        or not re.fullmatch(r"[a-f0-9]{64}", record["sha256"])
        or not isinstance(record["zlib_base64"], str)
        or len(record["zlib_base64"]) > MAX_EVENT_BYTES * 2
    ):
        raise OSError(
            "Desktop history contains an invalid record; preserve it for review"
        )
    try:
        compressed = base64.b64decode(record["zlib_base64"], validate=True)
        if base64.b64encode(compressed).decode("ascii") != record["zlib_base64"]:
            raise ValueError("noncanonical_base64")
        decoder = zlib.decompressobj()
        raw = decoder.decompress(compressed, MAX_EVENT_BYTES + 1)
        if (
            not decoder.eof
            or decoder.unused_data
            or decoder.unconsumed_tail
            or len(raw) != record["bytes"]
            or hashlib.sha256(raw).hexdigest() != record["sha256"]
        ):
            raise ValueError("changed_history")
        event = json.loads(
            raw,
            object_pairs_hook=_object,
            parse_constant=lambda _: (_ for _ in ()).throw(
                ValueError("nonfinite_json")
            ),
        )
        if not isinstance(event, dict) or event.get("id") != identifier:
            raise ValueError("changed_identity")
        json.dumps(event, allow_nan=False)
        return event
    except (ValueError, zlib.error, RuntimeError):
        raise OSError(
            "Desktop history integrity check failed; no alert was republished"
        ) from None


def lookup(journal, identifier):
    path = history_path(journal, identifier)
    try:
        record = read_json(path, maximum=MAX_EVENT_BYTES * 2 + 4096, runtime_owner=True)
    except FileNotFoundError:
        return None
    return decode_record(record, identifier) if record else None


def expired(event, now):
    value = event.get("created_at")
    if not isinstance(value, str):
        return False
    try:
        created = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if created.tzinfo is None:
            return False
        deadline = created.timestamp() + 600
        explicit = event.get("display_expires_at")
        if isinstance(explicit, str):
            end = datetime.fromisoformat(explicit.replace("Z", "+00:00"))
            if end.tzinfo is None:
                return False
            deadline = min(deadline, end.timestamp())
        return 0 < created.timestamp() <= now and deadline <= now
    except (ValueError, OverflowError, OSError):
        return False


def archive(journal, lines):
    if not lines:
        return
    folder = Path(journal).parent / "history"
    account = pwd.getpwnam("asterisk")
    parent = open_directory(folder.parent, account.pw_uid)
    created = False
    try:
        try:
            os.mkdir(folder.name, 0o750, dir_fd=parent)
            created = True
            os.fsync(parent)
        except FileExistsError:
            pass
    finally:
        os.close(parent)
    descriptor = open_directory(folder, account.pw_uid)
    if created and os.geteuid() == 0:
        os.fchown(descriptor, account.pw_uid, account.pw_gid)
    if created:
        os.fsync(descriptor)
    try:
        total = count = 0
        started = time.monotonic()
        with os.scandir(descriptor) as entries:
            for entry in entries:
                if time.monotonic() - started > 2:
                    raise OSError(
                        "Desktop history scan timed out; publication was not changed"
                    )
                meta = entry.stat(follow_symlinks=False)
                if (
                    not re.fullmatch(r"[a-f0-9]{64}\.json(?:\.lock)?", entry.name)
                    or not stat.S_ISREG(meta.st_mode)
                    or meta.st_nlink != 1
                    or meta.st_uid not in (0, account.pw_uid)
                    or meta.st_mode & 0o7027
                ):
                    raise OSError(
                        "Desktop history storage is unsafe; preserve it for review"
                    )
                total += meta.st_size
                count += not entry.name.endswith(".lock")
        for line in lines:
            raw = line.encode("utf-8")
            event = json.loads(raw, object_pairs_hook=_object)
            if not isinstance(event, dict) or not 1 <= len(raw) <= MAX_EVENT_BYTES:
                raise OSError("Desktop archive event is invalid or oversized")
            identifier = event.get("id")
            if not isinstance(identifier, str) or not identifier:
                raise OSError("A legacy desktop record has no archivable identity")
            record = {
                "schema": 1,
                "id": identifier,
                "bytes": len(raw),
                "sha256": hashlib.sha256(raw).hexdigest(),
                "zlib_base64": base64.b64encode(zlib.compress(raw, 6)).decode("ascii"),
            }
            decode_record(record, identifier)
            size = len(json.dumps(record).encode("utf-8")) + 64
            path = history_path(journal, identifier)
            existing = lookup(journal, identifier)
            if existing is None:
                if count >= MAX_HISTORY_FILES or total + size > MAX_HISTORY_BYTES:
                    raise OSError(
                        "Desktop evidence archive is full. Export retained history and review retention; no live events were removed"
                    )
                count += 1
                total += size
            with locked_json(
                path, maximum=MAX_EVENT_BYTES * 2 + 4096, runtime_owner=True
            ) as current:
                if current:
                    decode_record(current, identifier)
                    if current != record:
                        raise OSError(
                            "An archived desktop identity has conflicting content; preserve both records for review"
                        )
                else:
                    current.update(record)
    finally:
        os.close(descriptor)


def compact(journal, lines, maximum_bytes, maximum_records, protected_id):
    sizes = [len(line.encode("utf-8")) + 1 for line in lines]
    total = sum(sizes)
    count = len(lines)
    if total <= maximum_bytes and count <= maximum_records:
        return lines
    # Preserve the existing newest-thousand contract, retaining evicted records
    # separately instead of discarding their delivery authorization evidence.
    removed = set()
    now = time.time()
    for index, line in enumerate(lines):
        if total <= maximum_bytes and count <= maximum_records:
            break
        if index in removed:
            continue
        event = json.loads(line)
        if event.get("id") == protected_id or (
            "created_at" in event and not expired(event, now)
        ):
            continue
        # Untimed legacy records cannot be a live announcement. Keep their
        # exact bytes and original enrollment evidence in the private archive.
        removed.add(index)
        total -= sizes[index]
        count -= 1
        if total <= maximum_bytes and count <= maximum_records:
            break
    if total > maximum_bytes or count > maximum_records:
        raise OSError(
            "Desktop live feed is full of unexpired events. Wait for expiry or reduce publication rate; no active event was removed"
        )
    archive(journal, [line for index, line in enumerate(lines) if index in removed])
    return [line for index, line in enumerate(lines) if index not in removed]


def prune(journal, retention_days=90, now=None, maximum_delete=250):
    """Retire expired evidence under the same permanent publisher lock."""
    if type(retention_days) is not int or not 1 <= retention_days <= 365:
        raise ValueError("Invalid desktop evidence retention")
    now = time.time() if now is None else now
    folder = Path(journal).parent / "history"
    account = pwd.getpwnam("asterisk")
    try:
        directory = open_directory(folder, account.pw_uid)
    except FileNotFoundError:
        return 0
    journal_lock = Path(str(journal) + ".lock")
    guard = None
    removed = 0
    try:
        guard = os.open(
            journal_lock, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        )
        info = os.fstat(guard)
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_nlink != 1
            or info.st_size
            or info.st_uid not in (0, account.pw_uid)
            or info.st_mode & 0o7027
        ):
            raise OSError("Unsafe desktop journal coordination lock")
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0
        deadline = time.monotonic() + 2
        names = []
        with os.scandir(directory) as entries:
            for index, entry in enumerate(entries):
                if index >= MAX_HISTORY_FILES * 2 or time.monotonic() > deadline:
                    break
                if not re.fullmatch(r"[a-f0-9]{64}\.json", entry.name):
                    continue
                metadata = entry.stat(follow_symlinks=False)
                if (
                    not stat.S_ISREG(metadata.st_mode)
                    or metadata.st_nlink != 1
                    or metadata.st_uid not in (0, account.pw_uid)
                    or metadata.st_mode & 0o7027
                ):
                    raise OSError("Unsafe desktop evidence; retention deferred")
                if metadata.st_mtime >= now - retention_days * 86400:
                    continue
                record = read_json(
                    folder / entry.name,
                    maximum=MAX_EVENT_BYTES * 2 + 4096,
                    runtime_owner=True,
                )
                event = decode_record(record, record.get("id"))
                if history_path(journal, event["id"]).name != entry.name:
                    raise OSError("Desktop history filename identity mismatch")
                try:
                    created = datetime.fromisoformat(
                        event["created_at"].replace("Z", "+00:00")
                    )
                except (KeyError, ValueError, TypeError):
                    continue
                if (
                    created.tzinfo is None
                    or created.timestamp() >= now - retention_days * 86400
                    or not expired(event, now)
                ):
                    continue
                names.append((entry.name, metadata))
                if len(names) >= maximum_delete:
                    break
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_mode,
            value.st_uid,
            value.st_gid,
            value.st_size,
            value.st_mtime_ns,
            value.st_ctime_ns,
            value.st_nlink,
        )
        for name, before in names:
            lock = None
            try:
                lock = os.open(
                    name + ".lock",
                    os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                    dir_fd=directory,
                )
                meta = os.fstat(lock)
                if (
                    not stat.S_ISREG(meta.st_mode)
                    or meta.st_nlink != 1
                    or meta.st_size > 64
                    or meta.st_uid not in (0, account.pw_uid)
                    or meta.st_mode & 0o7027
                ):
                    raise OSError("Unsafe desktop evidence lock")
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    continue
                if os.read(lock, 65) not in (b"", b"SLS_JSON_V1\n"):
                    raise OSError("Unknown desktop evidence lock marker")
                if identity(before) != identity(
                    os.stat(name, dir_fd=directory, follow_symlinks=False)
                ) or identity(meta) != identity(
                    os.stat(name + ".lock", dir_fd=directory, follow_symlinks=False)
                ):
                    raise OSError("Desktop evidence changed during retention")
                os.unlink(name, dir_fd=directory)
                # All archive writers hold the permanent journal lock. This
                # per-record lock is retired only with its expired evidence.
                os.unlink(name + ".lock", dir_fd=directory)
                removed += 1
            except FileNotFoundError:
                continue
            finally:
                if lock is not None:
                    os.close(lock)
        if removed:
            os.fsync(directory)
        return removed
    finally:
        if guard is not None:
            os.close(guard)
        os.close(directory)
