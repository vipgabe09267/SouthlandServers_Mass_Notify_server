#!/usr/bin/env python3
"""Send each active Mass Notify system fault once to protected recipients."""

from __future__ import annotations

import fcntl
import hashlib
import json
import os
import pwd
import re
import stat
import tempfile
import time
import sys
from datetime import datetime, timezone
from pathlib import Path

from sls_branded_email import send_branded_email, valid_recipient


DATA_DIR = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
CONFIG_FILE = Path(os.environ.get("SLS_CONFIG_FILE", DATA_DIR / "mass-notifications.config"))
STATUS_FILE = Path(os.environ.get("SLS_STATUS_FILE", DATA_DIR / "status.json"))
INSTALL_FAILURE_FILE = Path(os.environ.get("SLS_INSTALL_FAILURE_FILE", DATA_DIR / "install-failure.json"))
UPDATE_PROGRESS_FILE = Path(os.environ.get("SLS_UPDATE_PROGRESS_FILE", DATA_DIR / "update-progress.json"))
MAINTENANCE_PROGRESS_FILE = Path(os.environ.get("SLS_MAINTENANCE_PROGRESS_FILE", "/run/asterisk/sls-mass-notify-maintenance-progress.json"))
STATE_FILE = Path(os.environ.get("SLS_SYSTEM_EMAIL_STATE_FILE", DATA_DIR / "system-notification-email-state.json"))
LOCK_FILE = Path(os.environ.get("SLS_SYSTEM_EMAIL_LOCK_FILE", DATA_DIR / "system-notification-email-state.lock"))
MAX_JSON_BYTES = 2 * 1024 * 1024
RETRY_SECONDS = 15 * 60
RETENTION_SECONDS = 90 * 86400
MAX_XWEATHER_GROUP_FAULTS = 5
MAX_ACTIVE_FAULTS = 16
MAX_HISTORY_RECORDS = 512


def _read_json(path: Path, *, required: bool = False) -> dict:
    flags = os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(path, flags)
    except FileNotFoundError:
        if required:
            raise RuntimeError(f"required JSON file is missing: {path.name}")
        return {}
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_JSON_BYTES:
            raise RuntimeError(f"unsafe JSON file: {path.name}")
        with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
            descriptor = -1
            decoded = json.load(handle)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
    if not isinstance(decoded, dict):
        raise RuntimeError(f"JSON root is not an object: {path.name}")
    return decoded


def _recipient_values(config: dict) -> list[str]:
    # System/error mail is deliberately opt-in. The pre-0.1.0 mail_to field is
    # migrated into Weather/Lightning service routes by the module, not into
    # this operational-notification channel.
    raw = config.get("system_notification_emails", "")
    if not isinstance(raw, (str, list)):
        raise RuntimeError("system notification email list has an invalid type")
    values = raw if isinstance(raw, list) else re.split(r"[\s,;]+", str(raw or ""))
    recipients: dict[str, str] = {}
    for value in values:
        value = str(value).strip()
        if value and not valid_recipient(value):
            raise RuntimeError("system notification email list contains an invalid address")
        if value:
            recipients.setdefault(value.lower(), value)
        if len(recipients) > 50:
            raise RuntimeError("system notification email list exceeds 50 recipients")
    return list(recipients.values())


def _integer(value, default=0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError, OverflowError):
        return int(default)


def _text(value, limit=500) -> str:
    return re.sub(r"[\x00-\x1f\x7f]+", " ", str(value or "")).strip()[:limit]


def _candidate(channel: str, stage, message, occurred_at) -> tuple[str, dict] | None:
    stage = _text(stage, 80)
    message = _text(message, 1000)
    occurred_at = _text(occurred_at, 80)
    if not message:
        return None
    # Messages commonly include changing counters, timestamps, or provider
    # wording while one fault remains active.  Key the active condition by its
    # protected channel and stage so those harmless changes cannot produce an
    # email every minute.  A cleared channel is removed from state below and
    # can notify again if the same fault later returns.
    fingerprint = hashlib.sha256(f"{channel}|{stage}".encode("utf-8")).hexdigest()
    return channel, {
        "fingerprint": fingerprint,
        "stage": stage or channel.replace("_", " "),
        "message": message,
        "occurred_at": occurred_at,
    }


def collect_faults(status: dict, install_failure: dict, update_progress: dict, maintenance_progress: dict) -> dict[str, dict]:
    faults: dict[str, dict] = {}

    if _integer(install_failure.get("version")) == 1 and install_failure.get("failed_at"):
        item = _candidate(
            "installation",
            install_failure.get("stage", "installation"),
            install_failure.get("message") or install_failure.get("solution"),
            install_failure.get("failed_at"),
        )
        if item:
            faults[item[0]] = item[1]

    fault_source = _text(status.get("last_fault_source"), 40).lower()
    if status.get("last_fault_at") and fault_source not in {"test", "manual_test", "dry_run"}:
        channel = "weather" if fault_source in {"nws", "weather", "weather.gov"} else "module"
        item = _candidate(
            channel,
            status.get("last_fault_stage", "Weather Alerts"),
            status.get("last_fault_message"),
            status.get("last_fault_at"),
        )
        if item:
            faults[item[0]] = item[1]

    xweather_groups = status.get("xweather_groups")
    if not isinstance(xweather_groups, dict):
        xweather_groups = {}
    channel_fields = [
        ("scheduled_worker", "last_schedule_worker_status", "last_schedule_worker_message", "last_schedule_worker_at"),
    ]
    # Multi-area Lightning mirrors the most recently touched area into legacy
    # top-level fields. Use those aggregate fields only for an old status file;
    # otherwise one area fault would be emailed twice.
    if not xweather_groups:
        channel_fields.extend([
            ("lightning_poll", "last_xweather_poll_status", "last_xweather_poll_message", "last_xweather_poll_at"),
            ("lightning_delivery", "last_xweather_delivery_status", "last_xweather_delivery_message", "last_xweather_delivery_at"),
        ])
    for channel, state_key, message_key, time_key in channel_fields:
        if _text(status.get(state_key), 32).lower() != "fault":
            continue
        item = _candidate(channel, channel.replace("_", " "), status.get(message_key), status.get(time_key))
        if item:
            faults[item[0]] = item[1]

    group_faults = 0
    for group_id, group in sorted(xweather_groups.items(), key=lambda item: str(item[0])):
        if not isinstance(group, dict):
            continue
        safe_id = re.sub(r"[^A-Za-z0-9_-]", "", str(group_id))[:64]
        if not safe_id:
            continue
        area_name = _text(group.get("group_name") or safe_id, 64)
        for fault_kind, status_key, message_key, time_key in (
            ("poll", "last_xweather_poll_status", "last_xweather_poll_message", "last_xweather_poll_at"),
            ("delivery", "last_xweather_delivery_status", "last_xweather_delivery_message", "last_xweather_delivery_at"),
        ):
            if _text(group.get(status_key), 32).lower() != "fault":
                continue
            item = _candidate(
                f"lightning_group_{safe_id}_{fault_kind}",
                f"Lightning area {area_name} {fault_kind}",
                group.get(message_key),
                group.get(time_key),
            )
            if item:
                faults[item[0]] = item[1]
        if any(
            _text(group.get(key), 32).lower() == "fault"
            for key in ("last_xweather_poll_status", "last_xweather_delivery_status")
        ):
            group_faults += 1
            if group_faults >= MAX_XWEATHER_GROUP_FAULTS:
                break

    if _text(update_progress.get("state"), 32).lower() == "failed":
        item = _candidate("update", "module update", update_progress.get("message"), update_progress.get("updated_at"))
        if item:
            faults[item[0]] = item[1]
    if _text(maintenance_progress.get("state"), 32).lower() == "failed":
        item = _candidate(
            "maintenance",
            maintenance_progress.get("action", "maintenance"),
            maintenance_progress.get("message"),
            maintenance_progress.get("updated_at"),
        )
        if item:
            faults[item[0]] = item[1]
    if len(faults) > MAX_ACTIVE_FAULTS:
        raise RuntimeError("system fault inventory exceeds the protected limit")
    return faults


def _write_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(prefix=".system-email-state.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(state, handle, sort_keys=True, separators=(",", ":"))
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o640)
        try:
            account = pwd.getpwnam("asterisk")
            os.chown(temporary, account.pw_uid, account.pw_gid)
        except (KeyError, PermissionError):
            pass
        if path.is_symlink():
            raise RuntimeError("system notification state path is a symbolic link")
        os.replace(temporary, path)
        directory_descriptor = os.open(
            path.parent,
            os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0),
        )
        try:
            os.fsync(directory_descriptor)
        finally:
            os.close(directory_descriptor)
    finally:
        if temporary.exists():
            temporary.unlink()


def _mark_weather_fault_email_sent(path: Path, fault: dict, sent_at: str) -> None:
    flags = os.O_RDWR | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(path, flags)
    except FileNotFoundError:
        return
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_JSON_BYTES:
            raise RuntimeError("unsafe Weather status file")
        with os.fdopen(descriptor, "r+", encoding="utf-8") as handle:
            descriptor = -1
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
            try:
                status = json.load(handle)
            except (TypeError, ValueError):
                status = {}
            if not isinstance(status, dict):
                raise RuntimeError("Weather status file is not an object")
            if _text(status.get("last_fault_stage"), 80) != _text(fault.get("stage"), 80):
                return
            occurred_at = _text(fault.get("occurred_at"), 80)
            if occurred_at and _text(status.get("last_fault_at"), 80) != occurred_at:
                return
            status["fault_email_sent_at"] = sent_at
            group_id = _text(fault.get("group_id"), 64)
            groups = status.get("nws_groups") if isinstance(status.get("nws_groups"), dict) else {}
            group = groups.get(group_id) if group_id else None
            if isinstance(group, dict):
                stage_key = re.sub(r"[^a-z0-9_-]", "", _text(fault.get("stage"), 48).lower())
                group_faults = group.get("faults") if isinstance(group.get("faults"), dict) else {}
                group_fault = group_faults.get(stage_key)
                if isinstance(group_fault, dict):
                    group_fault["email_sent_at"] = sent_at
                    group_faults[stage_key] = group_fault
                    group["faults"] = group_faults
                if _text(group.get("last_fault_stage"), 48).lower() == stage_key:
                    group["fault_email_sent_at"] = sent_at
                groups[group_id] = group
                status["nws_groups"] = groups
            handle.seek(0)
            handle.truncate(0)
            json.dump(status, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
            os.fchmod(handle.fileno(), 0o640)
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def process_faults(config: dict, faults: dict[str, dict], state_path: Path, *, sender=send_branded_email, on_sent=None, now: int | None = None) -> dict:
    current = int(time.time() if now is None else now)
    if len(faults) > MAX_ACTIVE_FAULTS:
        raise RuntimeError("system fault inventory exceeds the protected limit")

    state = _read_json(state_path)
    if state and _integer(state.get("version")) != 1:
        raise RuntimeError("unsupported system notification state version")
    raw_active = state.get("active") if isinstance(state.get("active"), dict) else {}
    raw_attempts = state.get("attempts") if isinstance(state.get("attempts"), dict) else {}
    raw_history = state.get("history") if isinstance(state.get("history"), dict) else {}
    active = {
        channel: fingerprint
        for channel, fingerprint in raw_active.items()
        if channel in faults and isinstance(fingerprint, str) and re.fullmatch(r"[0-9a-f]{64}", fingerprint)
    }
    attempts = {
        channel: attempt
        for channel, attempt in raw_attempts.items()
        if channel in faults and isinstance(attempt, dict)
    }
    retained_history = sorted(
        (
            (key, value)
            for key, value in raw_history.items()
            if isinstance(key, str)
            and re.fullmatch(r"[0-9a-f]{64}", key)
            and isinstance(value, int)
            and value >= current - RETENTION_SECONDS
        ),
        key=lambda item: (item[1], item[0]),
        reverse=True,
    )[:MAX_HISTORY_RECORDS]
    history = dict(retained_history)
    recipients = _recipient_values(config)
    if not recipients:
        _write_state(state_path, {"version": 1, "active": active, "attempts": attempts, "history": history})
        return {"sent": 0, "active": len(faults)}

    sent = 0
    for channel, fault in sorted(faults.items()):
        fingerprint = fault["fingerprint"]
        if active.get(channel) == fingerprint:
            continue
        attempt = attempts.get(channel) if isinstance(attempts.get(channel), dict) else {}
        if attempt.get("fingerprint") == fingerprint and current - _integer(attempt.get("at")) < RETRY_SECONDS:
            continue
        attempts[channel] = {"fingerprint": fingerprint, "at": current}
        state = {"version": 1, "active": active, "attempts": attempts, "history": history}
        _write_state(state_path, state)
        subject = "SLS Mass Notify system fault — " + fault["stage"]
        body_lines = [
            "The Mass Notifications Module detected a system fault.",
            "",
            "Stage: " + fault["stage"],
            "Message: " + fault["message"],
        ]
        if fault.get("occurred_at"):
            body_lines.append("Time: " + fault["occurred_at"])
        accepted = sender(
            config,
            subject,
            "\n".join(body_lines),
            "System Fault",
            "Warning",
            recipients_override=" ".join(recipients),
        )
        if accepted is not True:
            raise RuntimeError("system fault email was not accepted by the local mailer")
        active[channel] = fingerprint
        history[fingerprint] = current
        if len(history) > MAX_HISTORY_RECORDS:
            history = dict(sorted(history.items(), key=lambda item: (item[1], item[0]), reverse=True)[:MAX_HISTORY_RECORDS])
        attempts.pop(channel, None)
        sent += 1
        _write_state(state_path, {"version": 1, "active": active, "attempts": attempts, "history": history})
        if on_sent is not None:
            try:
                on_sent(channel, fault, current)
            except Exception:
                print("System/error email was submitted, but its status acknowledgement could not be recorded.", file=sys.stderr)
    # Persist fault resolution even when no new mail was due.  Without this
    # final write, a fault that became healthy remained active forever and the
    # same condition could never notify after a later recurrence.
    _write_state(state_path, {"version": 1, "active": active, "attempts": attempts, "history": history})
    return {"sent": sent, "active": len(faults)}


def main() -> int:
    config = _read_json(CONFIG_FILE, required=True)
    _recipient_values(config)
    status = _read_json(STATUS_FILE)
    faults = collect_faults(
        status,
        _read_json(INSTALL_FAILURE_FILE),
        _read_json(UPDATE_PROGRESS_FILE),
        _read_json(MAINTENANCE_PROGRESS_FILE),
    )
    LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(LOCK_FILE, flags, 0o640)
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise RuntimeError("system notification lock is unsafe")
        os.fchmod(descriptor, 0o640)
        try:
            account = pwd.getpwnam("asterisk")
            os.fchown(descriptor, account.pw_uid, account.pw_gid)
        except (KeyError, PermissionError):
            pass
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        def record_sent(channel, fault, sent_epoch):
            if channel != "weather":
                return
            fault["group_id"] = _text(status.get("last_fault_group_id"), 64)
            sent_at = datetime.fromtimestamp(sent_epoch, tz=timezone.utc).isoformat()
            _mark_weather_fault_email_sent(STATUS_FILE, fault, sent_at)

        process_faults(config, faults, STATE_FILE, on_sent=record_sent)
    finally:
        os.close(descriptor)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
