#!/usr/bin/env python3
"""Concurrency-safe, per-zone Weather.gov status storage.

The Weather.gov scheduler can run up to five zone workers at once.  Every
mutation in this module is performed while holding the status-file lock, then
the legacy top-level NWS fields are derived from the complete per-zone state.
"""

from __future__ import annotations

import fcntl
import json
import os
import re
import stat
import sys
import tempfile
import time
from collections import Counter
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable


MAX_GROUPS = 16
MAX_EVENT_TYPES = 12
MAX_LOCAL_DISPATCH_INTENTS = 5000
MAX_LOCAL_DISPATCH_STATE_BYTES = 2 * 1024 * 1024
LOCAL_DISPATCH_RETENTION_SECONDS = 90 * 24 * 60 * 60
POLL_STATUS_RANK = {
    "fault": 60,
    "warning": 50,
    "already_running": 40,
    "skipped": 30,
    "ok": 20,
}
NWS_FAULT_STAGES = {
    "api",
    "audio",
    "config",
    "delivery",
    "email",
    "payload",
    "visual",
    "webhook",
}
POLL_PATCH_KEYS = {
    "last_poll_at",
    "last_poll_status",
    "last_poll_message",
    "last_poll_ok_at",
    "last_poll_fail_count",
    "last_poll_fail_started_at",
    "last_poll_feature_count",
    "last_poll_candidate_count",
    "last_poll_events",
    "last_poll_candidate_events",
}
DELIVERY_PATCH_KEYS = {
    "last_delivery_at",
    "last_delivery_status",
    "last_delivery_source",
    "last_delivery_event",
    "last_delivery_audio",
    "last_delivery_message",
    "last_delivery_page_group",
    "last_delivery_alert_id",
}
FAULT_LEGACY_KEYS = {
    "last_fault_at",
    "last_fault_stage",
    "last_fault_message",
    "last_fault_event",
    "last_fault_alert_id",
    "fault_email_sent_at",
    "last_fault_source",
    "last_fault_group_id",
    "last_fault_group_name",
    "last_fault_zone",
}


class LocalDispatchStateError(RuntimeError):
    """Raised when the at-most-once local-dispatch journal is unsafe."""


def _local_dispatch_key(value: Any) -> str:
    key = str(value or "")
    if not key or len(key) > 1024 or re.search(r"[\x00-\x1f\x7f]", key):
        raise LocalDispatchStateError("local_dispatch_key_invalid")
    return key


@contextmanager
def _locked_local_dispatch_state(path: Path):
    """Serialize replace-based journal updates with a stable sidecar lock."""
    state_path = Path(path)
    try:
        state_path.parent.mkdir(parents=True, exist_ok=True)
        if state_path.is_symlink():
            raise LocalDispatchStateError("local_dispatch_state_unsafe")
        lock_path = state_path.with_name(state_path.name + ".lock")
        descriptor = os.open(
            lock_path,
            os.O_RDWR
            | os.O_CREAT
            | os.O_CLOEXEC
            | getattr(os, "O_NOFOLLOW", 0),
            0o640,
        )
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            os.close(descriptor)
            raise LocalDispatchStateError("local_dispatch_lock_unsafe")
    except LocalDispatchStateError:
        raise
    except OSError as exc:
        raise LocalDispatchStateError("local_dispatch_lock_failed") from exc
    try:
        with os.fdopen(descriptor, "r+") as handle:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
            if state_path.is_symlink():
                raise LocalDispatchStateError("local_dispatch_state_unsafe")
            yield state_path
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
        os.chmod(lock_path, 0o640)
    except LocalDispatchStateError:
        raise
    except OSError as exc:
        raise LocalDispatchStateError("local_dispatch_lock_failed") from exc


def _load_local_dispatch_state(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"version": 1, "intents": {}}
    try:
        descriptor = os.open(
            path,
            os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0),
        )
        try:
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_LOCAL_DISPATCH_STATE_BYTES:
                raise LocalDispatchStateError("local_dispatch_state_unsafe")
            with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
                descriptor = -1
                loaded = json.load(handle)
        finally:
            if descriptor >= 0:
                os.close(descriptor)
    except LocalDispatchStateError:
        raise
    except (OSError, UnicodeError, ValueError) as exc:
        raise LocalDispatchStateError("local_dispatch_state_corrupt") from exc
    if (
        not isinstance(loaded, dict)
        or loaded.get("version") != 1
        or not isinstance(loaded.get("intents"), dict)
        or len(loaded["intents"]) > MAX_LOCAL_DISPATCH_INTENTS
    ):
        raise LocalDispatchStateError("local_dispatch_state_corrupt")
    for key, record in loaded["intents"].items():
        try:
            safe_key = _local_dispatch_key(key)
        except LocalDispatchStateError as exc:
            raise LocalDispatchStateError("local_dispatch_state_corrupt") from exc
        if (
            safe_key != key
            or not isinstance(record, dict)
            or record.get("alert_key") != key
            or not isinstance(record.get("queued_at"), int)
            or record.get("queued_at", -1) < 0
        ):
            raise LocalDispatchStateError("local_dispatch_state_corrupt")
    return {"version": 1, "intents": loaded["intents"]}


def _write_local_dispatch_state(path: Path, state: dict[str, Any]) -> None:
    encoded = (json.dumps(state, separators=(",", ":"), ensure_ascii=True) + "\n").encode("utf-8")
    if len(encoded) > MAX_LOCAL_DISPATCH_STATE_BYTES:
        raise LocalDispatchStateError("local_dispatch_state_too_large")
    descriptor = -1
    temporary = ""
    try:
        descriptor, temporary = tempfile.mkstemp(
            prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent)
        )
        os.fchmod(descriptor, 0o640)
        with os.fdopen(descriptor, "wb") as handle:
            descriptor = -1
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        temporary = ""
        directory_descriptor = os.open(
            path.parent,
            os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_DIRECTORY", 0),
        )
        try:
            os.fsync(directory_descriptor)
        finally:
            os.close(directory_descriptor)
    except OSError as exc:
        raise LocalDispatchStateError("local_dispatch_state_write_failed") from exc
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if temporary:
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass


def _prune_local_dispatch_state(state: dict[str, Any], now: int | None = None) -> bool:
    current = max(0, int(time.time() if now is None else now))
    cutoff = current - LOCAL_DISPATCH_RETENTION_SECONDS
    intents = state["intents"]
    expired = [
        key
        for key, record in intents.items()
        if int(record.get("queued_at", 0)) < cutoff
    ]
    for key in expired:
        intents.pop(key, None)
    return bool(expired)


def local_dispatch_intent_recorded(path: Path, alert_key: Any) -> bool:
    """Return whether this alert chain already crossed the local intent gate."""
    key = _local_dispatch_key(alert_key)
    with _locked_local_dispatch_state(Path(path)) as state_path:
        state = _load_local_dispatch_state(state_path)
        if _prune_local_dispatch_state(state):
            _write_local_dispatch_state(state_path, state)
        return key in state["intents"]


def queue_local_dispatch_intent(
    path: Path,
    alert_key: Any,
    alert_id: Any,
    event: Any,
    *,
    phone_requested: bool,
    visual_requested: bool,
    now: int | None = None,
) -> bool:
    """Durably queue a local attempt; False means an older intent exists."""
    key = _local_dispatch_key(alert_key)
    with _locked_local_dispatch_state(Path(path)) as state_path:
        state = _load_local_dispatch_state(state_path)
        current = max(0, int(time.time() if now is None else now))
        _prune_local_dispatch_state(state, current)
        if key in state["intents"]:
            return False
        if len(state["intents"]) >= MAX_LOCAL_DISPATCH_INTENTS:
            raise LocalDispatchStateError("local_dispatch_state_capacity_exhausted")
        state["intents"][key] = {
            "alert_key": key,
            "alert_id": _text(alert_id, 512),
            "event": _text(event, 160),
            "queued_at": current,
            "phone_requested": bool(phone_requested),
            "visual_requested": bool(visual_requested),
        }
        _write_local_dispatch_state(state_path, state)
    return True


def cancel_local_dispatch_intent(path: Path, alert_key: Any) -> bool:
    """Remove an intent only when the caller proved that zero local work ran."""
    key = _local_dispatch_key(alert_key)
    with _locked_local_dispatch_state(Path(path)) as state_path:
        state = _load_local_dispatch_state(state_path)
        _prune_local_dispatch_state(state)
        if key not in state["intents"]:
            return False
        state["intents"].pop(key, None)
        _write_local_dispatch_state(state_path, state)
    return True


def _text(value: Any, limit: int) -> str:
    return re.sub(r"[\x00-\x1f\x7f]+", " ", str(value or "")).strip()[:limit]


def normalize_group_id(value: Any) -> str:
    candidate = _text(value, 64)
    if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", candidate):
        return candidate
    return "default"


def _timestamp_key(value: Any) -> float:
    text = _text(value, 64)
    if not text:
        return 0.0
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00")).timestamp()
    except (OverflowError, TypeError, ValueError):
        return 0.0


def _integer(value: Any, minimum: int = 0, maximum: int = 1_000_000) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = minimum
    return max(minimum, min(maximum, parsed))


def _event_counts(value: Any) -> dict[str, int]:
    if not isinstance(value, dict):
        return {}
    counts: dict[str, int] = {}
    for name, count in list(value.items())[:MAX_EVENT_TYPES]:
        safe_name = _text(name, 120)
        if safe_name:
            counts[safe_name] = _integer(count, 0, 100_000)
    return counts


def _normalize_patch(patch: Any) -> dict[str, Any]:
    if not isinstance(patch, dict):
        return {}
    normalized: dict[str, Any] = {}
    for key, value in patch.items():
        if key not in POLL_PATCH_KEYS | DELIVERY_PATCH_KEYS:
            continue
        if key in {"last_poll_fail_count", "last_poll_feature_count", "last_poll_candidate_count"}:
            normalized[key] = _integer(value)
        elif key in {"last_poll_events", "last_poll_candidate_events"}:
            normalized[key] = _event_counts(value)
        else:
            normalized[key] = _text(value, 1024 if key.endswith("_message") else 512)
    return normalized


def _normalize_fault(value: Any) -> dict[str, str] | None:
    if not isinstance(value, dict):
        return None
    stage = re.sub(r"[^a-z0-9_-]", "", _text(value.get("stage"), 48).lower())
    message = _text(value.get("message"), 1024)
    at = _text(value.get("at"), 64)
    if not stage or not message or not at:
        return None
    return {
        "at": at,
        "stage": stage,
        "message": message,
        "event": _text(value.get("event"), 160),
        "alert_id": _text(value.get("alert_id"), 512),
        "email_sent_at": _text(value.get("email_sent_at"), 64),
    }


def _configured_ids(values: Iterable[Any] | None) -> set[str] | None:
    if values is None:
        return None
    return {normalize_group_id(value) for value in values if _text(value, 64)}


def _newest(records: Iterable[dict[str, Any]], key: str) -> dict[str, Any] | None:
    values = [record for record in records if _text(record.get(key), 64)]
    if not values:
        return None
    return max(values, key=lambda record: (_timestamp_key(record.get(key)), _text(record.get("id"), 64)))


def _derive_group_fault(group: dict[str, Any]) -> None:
    faults = group.get("faults") if isinstance(group.get("faults"), dict) else {}
    safe_faults = {
        str(stage): fault
        for stage, fault in faults.items()
        if isinstance(fault, dict) and _normalize_fault(fault) is not None
    }
    group["faults"] = safe_faults
    latest = max(
        safe_faults.values(),
        key=lambda fault: (_timestamp_key(fault.get("at")), _text(fault.get("stage"), 48)),
        default=None,
    )
    if latest is None:
        group.update({
            "last_fault_at": "",
            "last_fault_stage": "",
            "last_fault_message": "",
            "last_fault_event": "",
            "last_fault_alert_id": "",
            "fault_email_sent_at": "",
        })
        return
    group.update({
        "last_fault_at": _text(latest.get("at"), 64),
        "last_fault_stage": _text(latest.get("stage"), 48),
        "last_fault_message": _text(latest.get("message"), 1024),
        "last_fault_event": _text(latest.get("event"), 160),
        "last_fault_alert_id": _text(latest.get("alert_id"), 512),
        "fault_email_sent_at": _text(latest.get("email_sent_at"), 64),
    })


def _aggregate_event_counts(groups: list[dict[str, Any]], key: str) -> dict[str, int]:
    totals: Counter[str] = Counter()
    for group in groups:
        totals.update(_event_counts(group.get(key)))
    return dict(totals.most_common(MAX_EVENT_TYPES))


def _derive_legacy_fields(data: dict[str, Any], delivery_touched: bool) -> None:
    groups_object = data.get("nws_groups") if isinstance(data.get("nws_groups"), dict) else {}
    groups = [group for group in groups_object.values() if isinstance(group, dict)]

    poll_groups = [
        group for group in groups
        if _text(group.get("last_poll_status"), 32) or _text(group.get("last_poll_at"), 64)
    ]
    if poll_groups:
        selected_poll = max(
            poll_groups,
            key=lambda group: (
                POLL_STATUS_RANK.get(_text(group.get("last_poll_status"), 32).lower(), 10),
                _timestamp_key(group.get("last_poll_at")),
                _text(group.get("id"), 64),
            ),
        )
        for key in (
            "last_poll_at",
            "last_poll_status",
            "last_poll_message",
            "last_poll_fail_count",
            "last_poll_fail_started_at",
        ):
            data[key] = selected_poll.get(key, "" if key != "last_poll_fail_count" else 0)
        data["last_poll_group_id"] = _text(selected_poll.get("id"), 64)
        data["last_poll_group_name"] = _text(selected_poll.get("name"), 64)
        data["last_poll_zone"] = _text(selected_poll.get("zone"), 12)
        newest_ok = _newest(groups, "last_poll_ok_at")
        data["last_poll_ok_at"] = _text(newest_ok.get("last_poll_ok_at"), 64) if newest_ok else ""
        data["last_poll_feature_count"] = sum(_integer(group.get("last_poll_feature_count")) for group in groups)
        data["last_poll_candidate_count"] = sum(_integer(group.get("last_poll_candidate_count")) for group in groups)
        data["last_poll_events"] = _aggregate_event_counts(groups, "last_poll_events")
        data["last_poll_candidate_events"] = _aggregate_event_counts(groups, "last_poll_candidate_events")
    else:
        for key in POLL_PATCH_KEYS | {"last_poll_group_id", "last_poll_group_name", "last_poll_zone"}:
            data[key] = 0 if key in {"last_poll_fail_count", "last_poll_feature_count", "last_poll_candidate_count"} else ({} if key in {"last_poll_events", "last_poll_candidate_events"} else "")

    current_delivery_is_nws = _text(data.get("last_delivery_source"), 32).lower() == "nws"
    if delivery_touched or current_delivery_is_nws:
        selected_delivery = _newest(groups, "last_delivery_at")
        if selected_delivery:
            for key in DELIVERY_PATCH_KEYS:
                data[key] = selected_delivery.get(key, "")
            data["last_delivery_group_id"] = _text(selected_delivery.get("id"), 64)
            data["last_delivery_group_name"] = _text(selected_delivery.get("name"), 64)
            data["last_delivery_zone"] = _text(selected_delivery.get("zone"), 12)
        elif current_delivery_is_nws:
            for key in DELIVERY_PATCH_KEYS | {"last_delivery_group_id", "last_delivery_group_name", "last_delivery_zone"}:
                data[key] = ""

    fault_records: list[dict[str, Any]] = []
    for group in groups:
        faults = group.get("faults") if isinstance(group.get("faults"), dict) else {}
        for fault in faults.values():
            if isinstance(fault, dict):
                fault_records.append({**fault, "group": group})
    selected_fault = max(
        fault_records,
        key=lambda record: (_timestamp_key(record.get("at")), _text(record.get("stage"), 48)),
        default=None,
    )
    current_stage = _text(data.get("last_fault_stage"), 48).lower()
    current_source = _text(data.get("last_fault_source"), 32).lower()
    current_is_nws = current_source == "nws" or current_stage in NWS_FAULT_STAGES or not current_stage
    if current_is_nws:
        if selected_fault:
            group = selected_fault["group"]
            data.update({
                "last_fault_at": _text(selected_fault.get("at"), 64),
                "last_fault_stage": _text(selected_fault.get("stage"), 48),
                "last_fault_message": _text(selected_fault.get("message"), 1024),
                "last_fault_event": _text(selected_fault.get("event"), 160),
                "last_fault_alert_id": _text(selected_fault.get("alert_id"), 512),
                "fault_email_sent_at": _text(selected_fault.get("email_sent_at"), 64),
                "last_fault_source": "nws",
                "last_fault_group_id": _text(group.get("id"), 64),
                "last_fault_group_name": _text(group.get("name"), 64),
                "last_fault_zone": _text(group.get("zone"), 12),
            })
        else:
            for key in FAULT_LEGACY_KEYS:
                data[key] = ""


def _load_locked(handle: Any) -> dict[str, Any]:
    handle.seek(0)
    try:
        loaded = json.load(handle)
    except Exception:
        loaded = {}
    return loaded if isinstance(loaded, dict) else {}


def _write_locked(handle: Any, data: dict[str, Any]) -> None:
    handle.seek(0)
    handle.truncate(0)
    json.dump(data, handle, indent=2, sort_keys=True)
    handle.write("\n")
    handle.flush()
    os.fsync(handle.fileno())


def mutate_status(
    path: Path,
    group_id: str,
    group_name: str,
    zone: str,
    mutation: dict[str, Any],
    configured_group_ids: Iterable[Any] | None = None,
) -> dict[str, Any]:
    """Apply one group mutation and derive all backward-compatible fields."""
    path.parent.mkdir(parents=True, exist_ok=True)
    safe_id = normalize_group_id(group_id)
    allowed_ids = _configured_ids(configured_group_ids)
    delivery_touched = False
    with path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        data = _load_locked(handle)
        groups = data.get("nws_groups") if isinstance(data.get("nws_groups"), dict) else {}
        groups = {
            normalize_group_id(key): value
            for key, value in list(groups.items())[:MAX_GROUPS]
            if isinstance(value, dict)
        }
        if allowed_ids is not None:
            groups = {key: value for key, value in groups.items() if key in allowed_ids}
            data["nws_configured_group_ids"] = sorted(allowed_ids)
        authoritative_ids = _configured_ids(data.get("nws_configured_group_ids"))
        if authoritative_ids is not None and safe_id not in authoritative_ids:
            data["nws_groups"] = groups
            _derive_legacy_fields(data, delivery_touched=False)
            _write_locked(handle, data)
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            os.chmod(path, 0o640)
            return {}

        group = dict(groups.get(safe_id) or {})
        group.update({
            "id": safe_id,
            "name": _text(group_name, 64),
            "zone": _text(zone, 12).upper(),
        })
        patch = _normalize_patch(mutation.get("patch"))
        group.update(patch)
        delivery_touched = any(key in DELIVERY_PATCH_KEYS for key in patch)

        faults = group.get("faults") if isinstance(group.get("faults"), dict) else {}
        if mutation.get("clear_faults") is True:
            faults = {}
        clear_stage = re.sub(r"[^a-z0-9_-]", "", _text(mutation.get("clear_fault_stage"), 48).lower())
        if clear_stage:
            faults.pop(clear_stage, None)
        fault = _normalize_fault(mutation.get("fault"))
        if fault is not None:
            faults[fault["stage"]] = fault

        api_failure = mutation.get("api_failure")
        if isinstance(api_failure, dict):
            now = _text(api_failure.get("at"), 64)
            message = _text(api_failure.get("message"), 1024)
            threshold = _integer(api_failure.get("threshold"), 1, 100)
            count = _integer(group.get("last_poll_fail_count")) + 1
            started_at = _text(group.get("last_poll_fail_started_at"), 64) or now
            state = "fault" if count >= threshold else "warning"
            group.update({
                "last_poll_at": now,
                "last_poll_status": state,
                "last_poll_message": f"NWS API poll failure {count}/{threshold}: {message}"[:1024],
                "last_poll_fail_count": count,
                "last_poll_fail_started_at": started_at,
            })
            if state == "fault":
                faults["api"] = {
                    "at": now,
                    "stage": "api",
                    "message": message,
                    "event": "",
                    "alert_id": "",
                    "email_sent_at": "",
                }
        if mutation.get("reset_api") is True:
            group["last_poll_fail_count"] = 0
            group["last_poll_fail_started_at"] = ""
            faults.pop("api", None)

        group["faults"] = faults
        _derive_group_fault(group)
        groups[safe_id] = group
        data["nws_groups"] = groups
        _derive_legacy_fields(data, delivery_touched)
        _write_locked(handle, data)
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    os.chmod(path, 0o640)
    return group


def reconcile_status(path: Path, configured_group_ids: Iterable[Any]) -> None:
    """Remove status entries for Weather.gov groups no longer configured."""
    path.parent.mkdir(parents=True, exist_ok=True)
    allowed_ids = _configured_ids(configured_group_ids) or set()
    with path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        data = _load_locked(handle)
        groups = data.get("nws_groups") if isinstance(data.get("nws_groups"), dict) else {}
        data["nws_configured_group_ids"] = sorted(allowed_ids)
        data["nws_groups"] = {
            normalize_group_id(key): value
            for key, value in groups.items()
            if normalize_group_id(key) in allowed_ids and isinstance(value, dict)
        }
        _derive_legacy_fields(data, delivery_touched=False)
        _write_locked(handle, data)
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    os.chmod(path, 0o640)


def _json_env(name: str, fallback: Any) -> Any:
    try:
        return json.loads(os.environ.get(name, ""))
    except (TypeError, ValueError):
        return fallback


def main() -> int:
    commands = {"mutate", "reconcile", "local-intent", "local-recorded", "local-cancel"}
    if len(sys.argv) != 2 or sys.argv[1] not in commands:
        print(
            "Usage: sls_nws_status.py {mutate|reconcile|local-intent|local-recorded|local-cancel}",
            file=sys.stderr,
        )
        return 2
    if sys.argv[1] in {"local-intent", "local-recorded", "local-cancel"}:
        state_path = Path(os.environ.get("NWS_LOCAL_DISPATCH_STATE_PATH", ""))
        if not str(state_path) or str(state_path) == ".":
            print("NWS_LOCAL_DISPATCH_STATE_PATH is required", file=sys.stderr)
            return 2
        try:
            if sys.argv[1] == "local-recorded":
                recorded = local_dispatch_intent_recorded(
                    state_path,
                    os.environ.get("NWS_LOCAL_DISPATCH_KEY", ""),
                )
                print(json.dumps({"recorded": recorded}, separators=(",", ":")))
                return 0 if recorded else 1
            if sys.argv[1] == "local-cancel":
                cancelled = cancel_local_dispatch_intent(
                    state_path,
                    os.environ.get("NWS_LOCAL_DISPATCH_KEY", ""),
                )
                print(json.dumps({"cancelled": cancelled}, separators=(",", ":")))
                return 0 if cancelled else 10
            queued = queue_local_dispatch_intent(
                state_path,
                os.environ.get("NWS_LOCAL_DISPATCH_KEY", ""),
                os.environ.get("NWS_LOCAL_DISPATCH_ALERT_ID", ""),
                os.environ.get("NWS_LOCAL_DISPATCH_EVENT", ""),
                phone_requested=os.environ.get("NWS_LOCAL_DISPATCH_PHONE", "0") == "1",
                visual_requested=os.environ.get("NWS_LOCAL_DISPATCH_VISUAL", "0") == "1",
            )
        except LocalDispatchStateError as exc:
            print(json.dumps({"error": str(exc)}, separators=(",", ":")), file=sys.stderr)
            return 75
        print(json.dumps({"queued": queued}, separators=(",", ":")))
        return 0 if queued else 10
    path = Path(os.environ.get("STATUS_FILE_PATH", ""))
    if not str(path) or str(path) == ".":
        print("STATUS_FILE_PATH is required", file=sys.stderr)
        return 2
    configured = _json_env("NWS_CONFIGURED_GROUP_IDS_JSON", None)
    if configured is not None and not isinstance(configured, list):
        print("NWS_CONFIGURED_GROUP_IDS_JSON must be an array", file=sys.stderr)
        return 2
    if sys.argv[1] == "reconcile":
        reconcile_status(path, configured or [])
        return 0
    mutation = _json_env("NWS_STATUS_MUTATION_JSON", None)
    if not isinstance(mutation, dict):
        print("NWS_STATUS_MUTATION_JSON must be an object", file=sys.stderr)
        return 2
    mutate_status(
        path,
        os.environ.get("NWS_STATUS_GROUP_ID", "default"),
        os.environ.get("NWS_STATUS_GROUP_NAME", ""),
        os.environ.get("NWS_STATUS_ZONE", ""),
        mutation,
        configured,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
