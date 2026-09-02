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
import os
import pwd
import re
import secrets
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
CLAIM_RETENTION_SECONDS = 30 * 86400
CYCLE_RETENTION_SECONDS = 2 * 3600
# A worker may legitimately wait up to 55 minutes for the serialized audio
# slot. Keep its reservation alive beyond that bound so another zone cannot
# take the same destination while the first worker is still active.
RESERVATION_LEASE_SECONDS = 70 * 60
ALLOWED_KINDS = {"phone", "desktop", "email", "discord", "generic"}
KIND_ORDER = ("phone", "desktop", "email", "discord", "generic")


class CoordinationError(RuntimeError):
    """Raised when coordination state cannot be used safely."""


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


def _secure_file_metadata(descriptor: int):
    metadata = os.fstat(descriptor)
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise CoordinationError("coordination path is not a regular file")
    os.fchmod(descriptor, 0o640)
    uid, gid = _account_ids()
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
        decoded = json.loads(raw.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise CoordinationError("coordination state is corrupt") from exc
    if not isinstance(decoded, dict) or decoded.get("version") != 1:
        raise CoordinationError("coordination state schema is invalid")
    if not isinstance(decoded.get("claims"), dict) or not isinstance(decoded.get("cycles"), dict):
        raise CoordinationError("coordination state structure is invalid")
    return decoded


def _write_state(parent_fd: int, name: str, state):
    encoded = (json.dumps(state, separators=(",", ":"), sort_keys=True) + "\n").encode("utf-8")
    if len(encoded) > MAX_STATE_BYTES:
        raise CoordinationError("coordination state capacity is exhausted")
    temporary = f".{name}.tmp.{os.getpid()}.{secrets.token_hex(8)}"
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(temporary, flags, 0o640, dir_fd=parent_fd)
    try:
        _secure_file_metadata(descriptor)
        offset = 0
        while offset < len(encoded):
            offset += os.write(descriptor, encoded[offset:])
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
        if target is not None and (not stat.S_ISREG(target.st_mode) or target.st_nlink != 1):
            raise CoordinationError("coordination state target is unsafe")
        os.replace(temporary, name, src_dir_fd=parent_fd, dst_dir_fd=parent_fd)
        os.fsync(parent_fd)
    finally:
        try:
            os.unlink(temporary, dir_fd=parent_fd)
        except FileNotFoundError:
            pass


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
            _secure_file_metadata(self.lock_fd)
            fcntl.flock(self.lock_fd, fcntl.LOCK_EX)
            self.state = _read_state(self.parent_fd, self.path.name)
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
        _write_state(self.parent_fd, self.path.name, self.state)

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
    if expected < 0 or expected > 300:
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
    if kind in {"discord", "generic"}:
        return raw if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", raw) else ""
    return ""


def _prune(state, now: int):
    def valid_claim(key, record):
        if not isinstance(record, dict) or not re.fullmatch(r"[0-9a-f]{64}:[0-9a-f]{64}", str(key)):
            return False
        chain_hash, destination_hash = str(key).split(":", 1)
        rank = record.get("group_rank")
        if (
            record.get("chain") != chain_hash
            or record.get("destination") != destination_hash
            or record.get("kind") not in ALLOWED_KINDS
            or not re.fullmatch(r"[0-9a-f]{64}", str(record.get("group", "")))
            or isinstance(rank, bool)
            or not isinstance(rank, int)
            or rank < 0
            or rank > 4
            or not isinstance(record.get("claimed_at"), int)
            or isinstance(record.get("claimed_at"), bool)
            or record["claimed_at"] < now - CLAIM_RETENTION_SECONDS
        ):
            return False
        if record.get("status", "committed") == "committed":
            return True
        return (
            record.get("status") == "reserved"
            and isinstance(record.get("lease_until"), int)
            and not isinstance(record.get("lease_until"), bool)
            and record["lease_until"] >= now
            and re.fullmatch(r"[0-9a-f]{64}", str(record.get("owner", ""))) is not None
        )

    def valid_cycle(key, record):
        if not isinstance(record, dict) or not re.fullmatch(r"[0-9a-f]{64}", str(key)):
            return False
        group_count = record.get("group_count")
        completed = record.get("completed")
        return (
            isinstance(record.get("started_at"), int)
            and not isinstance(record.get("started_at"), bool)
            and record["started_at"] >= now - CYCLE_RETENTION_SECONDS
            and isinstance(group_count, int)
            and not isinstance(group_count, bool)
            and 1 <= group_count <= 5
            and isinstance(completed, list)
            and all(
                isinstance(rank, int)
                and not isinstance(rank, bool)
                and 0 <= rank < group_count
                for rank in completed
            )
        )

    state["claims"] = {
        key: record
        for key, record in state["claims"].items()
        if valid_claim(key, record)
    }
    state["cycles"] = {
        key: record
        for key, record in state["cycles"].items()
        if valid_cycle(key, record)
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
        if not isinstance(destinations, list) or len(destinations) > 100:
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
        if total > 300:
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
        request = json.loads(raw.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise CoordinationError("coordination request is invalid") from exc
    if not isinstance(request, dict):
        raise CoordinationError("coordination request must be an object")
    return request


def main():
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
        raise SystemExit(1)
