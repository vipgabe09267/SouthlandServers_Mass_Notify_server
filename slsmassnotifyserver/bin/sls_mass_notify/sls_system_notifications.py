#!/usr/bin/env python3
"""Send each active Mass Notify system fault once to protected recipients."""

from __future__ import annotations

import fcntl
import copy
import hashlib
import importlib.util
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

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)

# Root maintenance uses -I; load this one authenticated sibling explicitly,
# without adding the runtime directory to global Python module resolution.
sys.dont_write_bytecode = True
_email_spec = importlib.util.spec_from_file_location('sls_system_branded_email', Path(__file__).with_name('sls_branded_email.py'))
_email_module = importlib.util.module_from_spec(_email_spec)
_email_spec.loader.exec_module(_email_module)
send_branded_email = _email_module.send_branded_email
valid_recipient = _email_module.valid_recipient


DATA_DIR = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
CONFIG_FILE = Path(os.environ.get("SLS_CONFIG_FILE", DATA_DIR / "mass-notifications.config"))
STATUS_FILE = Path(os.environ.get("SLS_STATUS_FILE", DATA_DIR / "status.json"))
INSTALL_FAILURE_FILE = Path(os.environ.get("SLS_INSTALL_FAILURE_FILE", DATA_DIR / "install-failure.json"))
UPDATE_PROGRESS_FILE = Path(os.environ.get("SLS_UPDATE_PROGRESS_FILE", DATA_DIR / "update-progress.json"))
MAINTENANCE_PROGRESS_FILE = Path(os.environ.get("SLS_MAINTENANCE_PROGRESS_FILE", "/run/asterisk/sls-mass-notify-maintenance-progress.json"))
STATE_FILE = Path(os.environ.get("SLS_SYSTEM_EMAIL_STATE_FILE", DATA_DIR / "system-notification-email-state.json"))
LOCK_FILE = Path(os.environ.get("SLS_SYSTEM_EMAIL_LOCK_FILE", DATA_DIR / "system-notification-email-state.lock"))
WORKER_HEALTH_FILE = DATA_DIR / "announcement-jobs" / "worker-probe.json"
STORAGE_HEALTH_FILE = DATA_DIR / "storage-summary.json"
MAX_HEALTH_BYTES = 64 * 1024
MAX_JSON_BYTES = 2 * 1024 * 1024
RETRY_SECONDS = 15 * 60
REPEAT_SUPPRESSION_SECONDS = 24 * 60 * 60
RETENTION_SECONDS = 90 * 86400
MAX_XWEATHER_GROUP_FAULTS = 5
MAX_ACTIVE_FAULTS = 24
MAX_HISTORY_RECORDS = 512


def _read_json(path: Path, *, required: bool = False, tolerate_corrupt: bool = False):
    if Path(path) == CONFIG_FILE:
        return _config_crypto.read_config(path)
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(path, flags)
    except FileNotFoundError:
        if required:
            raise RuntimeError(f"required JSON file is missing: {path.name}")
        return None if tolerate_corrupt else {}
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > MAX_JSON_BYTES:
            if tolerate_corrupt:
                return None
            raise RuntimeError(f"unsafe JSON file: {path.name}")
        with os.fdopen(descriptor, "rb") as handle:
            descriptor = -1
            # The Weather status writers take an exclusive inode lock while
            # updating in place. A shared lock prevents this reader from seeing
            # their temporary truncate/write window.
            try:
                fcntl.flock(handle.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                if tolerate_corrupt:
                    return None
                raise RuntimeError(f"JSON file is busy: {path.name}") from exc
            try:
                raw = handle.read(MAX_JSON_BYTES + 1)
            except UnicodeError as exc:
                if tolerate_corrupt:
                    return None
                raise RuntimeError(f"JSON file is corrupt: {path.name}") from exc
        if len(raw) > MAX_JSON_BYTES:
            if tolerate_corrupt:
                return None
            raise RuntimeError(f"JSON file exceeds its size limit: {path.name}")
        if not raw.strip():
            if tolerate_corrupt:
                return None
            raise RuntimeError(f"JSON file is empty: {path.name}")
        try:
            decoded = json.loads(raw)
        except (UnicodeError, json.JSONDecodeError, RecursionError) as exc:
            if tolerate_corrupt:
                return None
            raise RuntimeError(f"JSON file is corrupt: {path.name}") from exc
    finally:
        if descriptor >= 0:
            os.close(descriptor)
    if not isinstance(decoded, dict):
        if tolerate_corrupt:
            return None
        raise RuntimeError(f"JSON root is not an object: {path.name}")
    return decoded



def _read_health_snapshot(path: Path) -> tuple[dict | None, str]:
    """Read operational health only; no raw message/path enters fault emails."""
    parent = descriptor = -1
    try:
        path = Path(path)
        if not path.is_absolute() or ".." in path.parts:
            return None, "unsafe"
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
        parent = os.open("/", flags)
        for component in path.parts[1:-1]:
            child = os.open(component, flags, dir_fd=parent)
            os.close(parent)
            parent = child
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
        metadata = os.fstat(descriptor)
        current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                or metadata.st_size > MAX_HEALTH_BYTES
                or (metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_nlink)
                != (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)):
            return None, "unsafe"
        fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
        with os.fdopen(descriptor, "rb") as handle:
            descriptor = -1
            raw = handle.read(MAX_HEALTH_BYTES + 1)
        if len(raw) > MAX_HEALTH_BYTES:
            return None, "oversized"
        data = json.loads(raw)
        return (data, "") if isinstance(data, dict) else (None, "invalid")
    except FileNotFoundError:
        return None, "missing"
    except BlockingIOError:
        return None, "busy"
    except (OSError, ValueError, UnicodeError, RecursionError):
        return None, "unavailable"
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if parent >= 0:
            os.close(parent)


def _health_time(value, *, iso: bool) -> int | None:
    try:
        if iso:
            if not isinstance(value, str) or len(value) > 40:
                return None
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
            if parsed.tzinfo is None:
                return None
            return int(parsed.timestamp())
        return value if type(value) is int and value > 0 else None
    except (ValueError, TypeError, OverflowError, OSError):
        return None


def collect_health_faults(worker: dict | None, storage: dict | None, *,
                          worker_error: str = "", storage_error: str = "", now: int | None = None) -> dict[str, dict]:
    """Two stable conditions reuse the existing opt-in mail dedup/retry policy."""
    current = int(time.time() if now is None else now)
    faults = {}
    worker_issues = []
    worker_time = _health_time(worker.get("checked_at"), iso=True) if isinstance(worker, dict) else None
    if worker_error or not isinstance(worker, dict):
        worker_issues.append("The announcement worker health report is unavailable. Check Help diagnostics and the maintenance log.")
    elif worker_time is None or worker_time > current + 60:
        worker_issues.append("The announcement worker health timestamp is invalid. Check the PBX clock and worker health probe.")
    elif current - worker_time >= 600:
        worker_issues.append("The announcement worker has no fresh health report. Check its maintenance health probe and queued jobs.")
    elif worker.get("ok") is not True:
        categories = {
            "worker_start_failed": "Required announcement process supervision is unavailable.",
            "worker_bootstrap_failed": "The announcement worker could not load FreePBX.",
            "worker_module_load_failed": "The announcement worker could not load the SLS module.",
            "worker_runtime_failed": "The announcement worker reported an execution failure.",
        }
        category = worker.get("failure_category")
        worker_issues.append(categories.get(category if isinstance(category, str) else "", "The announcement worker health probe failed."))
        worker_issues.append("Review Help diagnostics and the maintenance log before sending; queued delivery may be delayed.")
    elif any(worker.get(field) is not True for field in ("bootstrap", "module_loaded", "storage_writable") if field in worker):
        worker_issues.append("The announcement worker cannot confirm bootstrap, module loading or writable job storage. Review Help diagnostics.")
    if worker_issues:
        faults["announcement_worker"] = _candidate("announcement_worker", "announcement worker health",
            " ".join(worker_issues), datetime.fromtimestamp(current, timezone.utc).isoformat())[1]

    storage_issues = []
    storage_time = _health_time(storage.get("checked_at"), iso=False) if isinstance(storage, dict) else None
    if storage_error or not isinstance(storage, dict):
        storage_issues.append("Storage and delivery-queue health is unavailable. Check the maintenance log; missing data does not establish an empty queue.")
    elif storage_time is None or storage_time > current + 60:
        storage_issues.append("The storage health timestamp is invalid. Check the PBX clock and maintenance worker.")
    elif current - storage_time > 300:
        storage_issues.append("Storage and delivery-queue health is stale. Check scheduled maintenance before relying on its counts.")
    else:
        counters = ("free_bytes", "queue_errors", "expired_external", "failed_weather", "uncertain_weather",
                    "expired_weather", "weather_deadline_misses", "audit_failed_records",
                    "recent_expired_external", "recent_failed_weather", "recent_uncertain_weather",
                    "recent_expired_weather", "recent_weather_deadline_misses")
        if any(type(storage.get(key, 0)) is not int or storage.get(key, 0) < 0 for key in counters):
            storage_issues.append("Storage health contains invalid counters. Check the maintenance log and regenerate diagnostics.")
        elif any(type(storage.get(key, False)) is not bool for key in ("queue_scan_incomplete", "audit_at_capacity", "audit_fault_active", "audit_forwarding_active", "media_scan_incomplete", "media_over_budget")):
            storage_issues.append("Storage health contains invalid status flags. Check the maintenance log and regenerate diagnostics.")
        else:
            if "free_bytes" not in storage:
                storage_issues.append("Storage health cannot confirm available disk space. Check maintenance diagnostics.")
            elif storage["free_bytes"] == 0:
                storage_issues.append("No free space is reported for PBX data. Free space before sending announcements or changing settings.")
            if storage.get("queue_errors") or storage.get("queue_scan_incomplete"):
                storage_issues.append("External delivery inventory is unreadable or incomplete. Review the maintenance log; displayed counts may omit deliveries.")
            if storage.get("recent_expired_external", storage.get("expired_external")):
                storage_issues.append("External deliveries expired without acceptance. Review delivery results and current recipient authorization.")
            if any(storage.get("recent_" + key, storage.get(key)) for key in ("failed_weather", "uncertain_weather", "expired_weather", "weather_deadline_misses")):
                storage_issues.append("Weather or Lightning deliveries failed, expired or have an uncertain outcome. Review delivery evidence; do not blindly replay them.")
            if storage.get("audit_at_capacity") or storage.get("audit_fault_active", bool(storage.get("audit_failed_records") or storage.get("audit_failure_code"))):
                storage_issues.append("Control API audit storage reached capacity or could not confirm records. Review audit fault and recovery evidence; do not resend accepted actions to repair history.")
            if storage.get("audit_forwarding_active"):
                storage_issues.append("Control API audit forwarding cannot confirm local system-logger acceptance. Check /dev/log and logger health, then verify the separate collector. Do not replay accepted API actions.")
            if storage.get("media_scan_incomplete"):
                storage_issues.append("Generated-media storage could not be fully inspected. Check the storage maintenance log; cleanup preserves files when inspection fails.")
            if storage.get("media_over_budget"):
                storage_issues.append("Generated media exceeds its configured cache target. Active and recent files remain protected. Review queued alerts and free space; bounded cleanup retries automatically.")
    if storage_issues:
        faults["storage"] = _candidate("storage", "storage and delivery health",
            " ".join(storage_issues), datetime.fromtimestamp(current, timezone.utc).isoformat())[1]
    return faults


def _recipient_values(config: dict) -> list[str]:
    # System/error mail is deliberately opt-in. The pre-0.1.1-beta mail_to field is
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


def project_status(status: dict, config: dict, now: int) -> dict:
    """Same health projection as StatusHealth.php; the original history is kept."""
    status = copy.deepcopy(status)
    def epoch(value):
        return _health_time(value, iso=isinstance(value, str)) or 0
    def recent(value):
        return epoch(value) > 0 and epoch(value) >= now - 900
    nws = config.get('enabled') == '1'
    lightning_config = config.get('xweather') or {}
    lightning = lightning_config.get('enabled') not in (None, False, 0, '', '0')
    nws_ids = {row.get('id') for row in config.get('nws_zones', []) if isinstance(row, dict)}
    lightning_ids = {row.get('id') for row in lightning_config.get('groups', [])
                     if isinstance(row, dict) and row.get('enabled') == '1'}
    source = str(status.get('last_fault_source', '')).lower()
    group_id = str(status.get('last_fault_group_id', ''))
    stage = str(status.get('last_fault_stage', '')).lower()
    at = epoch(status.get('last_fault_at'))
    applicable = source not in {'test', 'manual_test', 'dry_run'}
    group = status
    if source in {'nws', 'weather', 'weather.gov'}:
        applicable = nws and (not group_id or group_id in nws_ids)
        if group_id:
            group = (status.get('nws_groups') or {}).get(group_id, {})
    elif source in {'xweather', 'lightning'}:
        applicable = lightning and (not group_id or group_id in lightning_ids)
        if group_id:
            group = (status.get('xweather_groups') or {}).get(group_id, {})
    group = group if isinstance(group, dict) else {}
    recovered = (stage == 'api' and group.get('last_poll_status') == 'ok'
                 and epoch(group.get('last_poll_ok_at')) > at)
    recovered = recovered or (stage == 'piper_voice_download'
                 and status.get('last_piper_voice_install_status') == 'ok'
                 and epoch(status.get('last_piper_voice_install_at')) > at)
    ongoing = stage in {'api', 'external', 'dependencies', 'piper_voice_download'}
    if not applicable or recovered or (not ongoing and not recent(status.get('last_fault_at'))):
        status['last_fault_at'] = ''
    delivery_source = str(status.get('last_delivery_source', '')).lower()
    delivery_group = str(status.get('last_delivery_group_id', ''))
    if (not recent(status.get('last_delivery_at')) or
            (delivery_source in {'nws', 'weather', 'weather.gov'} and
             (not nws or (delivery_group and delivery_group not in nws_ids)))):
        if status.get('last_delivery_status') == 'fault':
            status['last_delivery_status'] = ''
    groups = status.get('xweather_groups')
    groups = groups if isinstance(groups, dict) else {}
    status['xweather_groups'] = {key: row if isinstance(row, dict) else {}
                               for key, row in groups.items() if lightning and key in lightning_ids}
    for row in status['xweather_groups'].values():
        if not recent(row.get('last_xweather_delivery_at')):
            row['last_xweather_delivery_status'] = ''
    if not lightning or not recent(status.get('last_xweather_delivery_at')):
        status['last_xweather_delivery_status'] = ''
    if not lightning or not lightning_ids:
        for kind in ('poll', 'delivery', 'external'):
            status['last_xweather_' + kind + '_status'] = ''
    if not any(isinstance(row, dict) and row.get('enabled') not in (None, False, 0, '', '0')
               for row in config.get('scheduled_announcements', [])):
        status['last_schedule_worker_status'] = ''
    return status


def collect_faults(status: dict, install_failure: dict, update_progress: dict, maintenance_progress: dict,
                   *, config: dict | None = None, now: int | None = None) -> dict[str, dict]:
    if config is not None:
        status = project_status(status, config, int(time.time() if now is None else now))
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
    # External retry health is independent of later successful local delivery.
    # The shared worker reports aggregate health even when area records exist.
    grouped_external_fault = any(
        isinstance(group, dict) and _text(group.get("last_xweather_external_status"), 32).lower() == "fault"
        for group in xweather_groups.values()
    )
    if not grouped_external_fault:
        channel_fields.append(("lightning_external", "last_xweather_external_status", "last_xweather_external_message", "last_xweather_external_at"))
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
            ("external", "last_xweather_external_status", "last_xweather_external_message", "last_xweather_external_at"),
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
            for key in ("last_xweather_poll_status", "last_xweather_delivery_status", "last_xweather_external_status")
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


def process_faults(config: dict, faults: dict[str, dict], state_path: Path, *, sender=send_branded_email, on_sent=None,
                   now: int | None = None, preserve_status_faults: bool = False) -> dict:
    current = int(time.time() if now is None else now)
    if len(faults) > MAX_ACTIVE_FAULTS:
        raise RuntimeError("system fault inventory exceeds the protected limit")

    state = _read_json(state_path)
    if state and _integer(state.get("version")) != 1:
        raise RuntimeError("unsupported system notification state version")
    raw_active = state.get("active") if isinstance(state.get("active"), dict) else {}
    raw_attempts = state.get("attempts") if isinstance(state.get("attempts"), dict) else {}
    raw_history = state.get("history") if isinstance(state.get("history"), dict) else {}
    def unobserved_status_channel(channel):
        return preserve_status_faults and (channel in {"weather", "module", "scheduled_worker"}
                                           or str(channel).startswith("lightning_"))
    active = {
        channel: fingerprint
        for channel, fingerprint in raw_active.items()
        if (channel in faults or unobserved_status_channel(channel)) and isinstance(fingerprint, str) and re.fullmatch(r"[0-9a-f]{64}", fingerprint)
    }
    attempts = {
        channel: attempt
        for channel, attempt in raw_attempts.items()
        if (channel in faults or unobserved_status_channel(channel)) and isinstance(attempt, dict)
    }
    if len(set(faults) | set(active) | set(attempts)) > MAX_ACTIVE_FAULTS:
        raise RuntimeError("system notification active fault state exceeds its limit")
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
        return {"sent": 0, "active": len(set(faults) | set(active))}

    sent = 0
    for channel, fault in sorted(faults.items()):
        fingerprint = fault["fingerprint"]
        if active.get(channel) == fingerprint:
            continue
        # A brief recovery or inconsistent probe must not resend the same
        # condition every maintenance cycle. A distinct stage still alerts.
        if current - history.get(fingerprint, 0) < REPEAT_SUPPRESSION_SECONDS:
            active[channel] = fingerprint
            attempts.pop(channel, None)
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
    return {"sent": sent, "active": len(set(faults) | set(active))}


def _open_notification_lock(path: Path) -> int:
    """Open the lock without changing another inode through a link or race."""
    path = Path(path)
    if not path.is_absolute() or ".." in path.parts:
        raise RuntimeError("system notification lock path is unsafe")
    parent = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    descriptor = -1
    try:
        for component in path.parts[1:-1]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            os.close(parent)
            parent = child
        account = pwd.getpwnam("asterisk")
        flags = os.O_RDWR | os.O_NONBLOCK | os.O_CLOEXEC | os.O_NOFOLLOW
        try:
            descriptor = os.open(path.name, flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
        except FileExistsError:
            descriptor = os.open(path.name, flags, dir_fd=parent)
        metadata = os.fstat(descriptor)
        current = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                or metadata.st_uid not in (0, account.pw_uid)
                or (metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_nlink)
                != (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)):
            raise RuntimeError("system notification lock is unsafe or changed during open")
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise RuntimeError("another system notification check is running") from exc
        os.fchmod(descriptor, 0o640)
        if os.geteuid() == 0:
            os.fchown(descriptor, account.pw_uid, account.pw_gid)
        result, descriptor = descriptor, -1
        return result
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        os.close(parent)


def main() -> int:
    config = _read_json(CONFIG_FILE, required=True)
    _recipient_values(config)
    # status.json is operational telemetry written by several bounded workers.
    # A truncated legacy write must neither crash this notifier nor look like a
    # healthy transition that clears the active-fault journal. Preserve its
    # dedup state while still checking independent worker/storage health.
    status = _read_json(STATUS_FILE, tolerate_corrupt=True)
    if status is None:
        print("Weather status is temporarily unavailable; its fault state is preserved while independent worker and storage checks continue.", file=sys.stderr)
    faults = collect_faults(
        status or {},
        _read_json(INSTALL_FAILURE_FILE),
        _read_json(UPDATE_PROGRESS_FILE),
        _read_json(MAINTENANCE_PROGRESS_FILE),
        config=config,
    )
    worker, worker_error = _read_health_snapshot(WORKER_HEALTH_FILE)
    storage, storage_error = _read_health_snapshot(STORAGE_HEALTH_FILE)
    faults.update(collect_health_faults(worker, storage, worker_error=worker_error, storage_error=storage_error))
    descriptor = _open_notification_lock(LOCK_FILE)
    try:
        def record_sent(channel, fault, sent_epoch):
            if channel != "weather":
                return
            fault["group_id"] = _text((status or {}).get("last_fault_group_id"), 64)
            sent_at = datetime.fromtimestamp(sent_epoch, tz=timezone.utc).isoformat()
            _mark_weather_fault_email_sent(STATUS_FILE, fault, sent_at)

        process_faults(config, faults, STATE_FILE, on_sent=record_sent, preserve_status_faults=status is None)
    finally:
        os.close(descriptor)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
