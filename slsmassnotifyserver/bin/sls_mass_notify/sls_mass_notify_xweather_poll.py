#!/usr/bin/env python3
"""Poll Xweather lightning data and deliver deduplicated PBX alerts."""

import fcntl
import hashlib
import json
import math
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_branded_email import send_branded_email, valid_recipient
from sls_notification_destinations import (
    RetryStateError,
    dispatch_webhook_destinations,
    external_delivery_pending,
    external_delivery_recorded,
    queue_external_delivery,
    retry_external_deliveries,
)


DATA_DIR = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
CONFIG_FILE = Path(os.environ.get("CONFIG_FILE", DATA_DIR / "mass-notifications.config"))
LEGACY_STATE_FILE = DATA_DIR / "xweather-lightning-state.json"
STATE_FILE_EXPLICIT = "XWEATHER_STATE_FILE" in os.environ
STATE_FILE = Path(os.environ.get("XWEATHER_STATE_FILE", LEGACY_STATE_FILE))
EXTERNAL_DELIVERY_STATE_FILE = Path(
    os.environ.get("XWEATHER_EXTERNAL_DELIVERY_STATE", DATA_DIR / "xweather-external-deliveries.json")
)
WORKER_LOCK_FILE = Path(os.environ.get("XWEATHER_LOCK_FILE", DATA_DIR / "xweather-poll.lock"))
QUOTA_STATE_FILE = Path(os.environ.get("XWEATHER_QUOTA_STATE_FILE", DATA_DIR / "xweather-quota-state.json"))
STATUS_FILE = Path(os.environ.get("STATUS_FILE", DATA_DIR / "status.json"))
EVENTS_LOG = Path(os.environ.get("EVENTS_LOG", "/var/log/sls_mass_notify_events.jsonl"))
LOG_FILE = Path(os.environ.get("LOG", "/var/log/sls_mass_notify.log"))
TTS_DIR = DATA_DIR / "sounds" / "tts"
TONES_DIR = DATA_DIR / "sounds" / "tones"
ASTERISK_SOUNDS_DIR = Path(os.environ.get("ASTERISK_SOUNDS_DIR", "/var/lib/asterisk/sounds"))
SPOOL_DIR = Path("/var/spool/asterisk/outgoing")
SPOOL_DONE_DIR = Path("/var/spool/asterisk/outgoing_done")
PIPER_BIN = Path("/usr/local/bin/sls_mass_notify/piper/venv/bin/piper")
VISUAL_SCRIPT = Path("/usr/local/bin/sls_mass_notify/sls_notify.py")
SOUND_PREFIX = "SLS_Mass_Notifications_Plugin/tts"
TEST_CALL_WAIT_SECONDS = 30
LAST_RATE_LIMIT = {}
CURRENT_GROUP_ID = ""
CURRENT_GROUP_NAME = ""
CURRENT_GROUP_INDEX = 0
CURRENT_GROUP_LEGACY = False


class _RejectRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError("redirect_blocked")


XWEATHER_OPENER = urllib.request.build_opener(_RejectRedirects())


def log(message):
    with LOG_FILE.open("a", encoding="utf-8") as handle:
        handle.write(f"{datetime.now().astimezone().isoformat()}: Xweather: {message}\n")


def _read_json_file(path, missing_ok=True):
    flags = os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(path, flags)
    except FileNotFoundError:
        if missing_ok:
            return {}
        raise
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > 4 * 1024 * 1024:
            raise RuntimeError(f"invalid JSON state file: {path}")
        with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
            descriptor = -1
            data = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"corrupt JSON state file: {path}") from exc
    finally:
        if descriptor >= 0:
            os.close(descriptor)
    if not isinstance(data, dict):
        raise RuntimeError(f"JSON state is not an object: {path}")
    return data


def _atomic_state_update(path, patch):
    path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = Path(str(path) + ".lock")
    lock_flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    lock_descriptor = os.open(lock_path, lock_flags, 0o640)
    temporary_descriptor = -1
    temporary_name = ""
    try:
        if not stat.S_ISREG(os.fstat(lock_descriptor).st_mode):
            raise RuntimeError("Xweather state lock is not a regular file")
        os.fchmod(lock_descriptor, 0o640)
        fcntl.flock(lock_descriptor, fcntl.LOCK_EX)
        data = _read_json_file(path)
        data.update(patch)
        temporary_descriptor, temporary_name = tempfile.mkstemp(
            prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent)
        )
        os.fchmod(temporary_descriptor, 0o640)
        with os.fdopen(temporary_descriptor, "w", encoding="utf-8") as handle:
            temporary_descriptor = -1
            json.dump(data, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_name, path)
        temporary_name = ""
        directory_descriptor = os.open(
            path.parent,
            os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_DIRECTORY", 0),
        )
        try:
            os.fsync(directory_descriptor)
        finally:
            os.close(directory_descriptor)
    finally:
        if temporary_descriptor >= 0:
            os.close(temporary_descriptor)
        if temporary_name:
            try:
                os.unlink(temporary_name)
            except FileNotFoundError:
                pass
        try:
            fcntl.flock(lock_descriptor, fcntl.LOCK_UN)
        finally:
            os.close(lock_descriptor)


def atomic_json_update(path, patch):
    path = Path(path)
    if path == STATE_FILE:
        _atomic_state_update(path, patch)
        return
    # NWS workers also update the shared status file and currently coordinate by
    # locking that inode. Keep the shared locking protocol here; state critical
    # to Lightning dedup uses the atomic replacement path above.
    path.parent.mkdir(parents=True, exist_ok=True)
    flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(path, flags, 0o640)
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise RuntimeError("status path is not a regular file")
        with os.fdopen(descriptor, "r+", encoding="utf-8") as handle:
            descriptor = -1
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
            handle.seek(0)
            raw = handle.read()
            if raw.strip():
                try:
                    data = json.loads(raw)
                except json.JSONDecodeError as exc:
                    raise RuntimeError("shared status file is corrupt") from exc
                if not isinstance(data, dict):
                    raise RuntimeError("shared status file is not an object")
            else:
                data = {}
            data.update(patch)
            if path == STATUS_FILE and CURRENT_GROUP_ID:
                group_statuses = data.get("xweather_groups")
                if not isinstance(group_statuses, dict):
                    group_statuses = {}
                group_status = group_statuses.get(CURRENT_GROUP_ID)
                if not isinstance(group_status, dict):
                    group_status = {}
                group_status.update({
                    key: value for key, value in patch.items()
                    if key.startswith("last_xweather_") or key.startswith("xweather_adaptive_")
                })
                group_status["group_id"] = CURRENT_GROUP_ID
                group_status["group_name"] = CURRENT_GROUP_NAME
                group_statuses[CURRENT_GROUP_ID] = group_status
                data["xweather_groups"] = group_statuses
            handle.seek(0)
            handle.truncate(0)
            json.dump(data, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
    finally:
        if descriptor >= 0:
            os.close(descriptor)
    os.chmod(path, 0o640)


def append_event(payload):
    with EVENTS_LOG.open("a", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.write(json.dumps(payload, separators=(",", ":"), ensure_ascii=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    os.chmod(EVENTS_LOG, 0o640)


def _merge_email_recipients(configured, legacy):
    configured_values = configured if isinstance(configured, list) else re.split(r"[\s,;]+", str(configured or ""))
    legacy_values = legacy if isinstance(legacy, list) else re.split(r"[\s,;]+", str(legacy or ""))
    merged = {}
    for raw_value in [*configured_values, *legacy_values]:
        value = str(raw_value).strip()
        if not valid_recipient(value):
            continue
        merged.setdefault(value.lower(), value)
        if len(merged) >= 50:
            break
    return list(merged.values())


def load_config():
    with CONFIG_FILE.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    if not isinstance(config, dict):
        raise ValueError("central configuration is not an object")
    xweather = config.get("xweather") if isinstance(config.get("xweather"), dict) else {}
    # Before 0.1.0, mail_to was shared by live alerts and fault notices. Keep
    # those live routes during an upgrade only when the new canonical system
    # recipient key is absent. Newly saved configurations use each area's
    # email_recipients list exclusively.
    if "system_notification_emails" not in config:
        legacy_recipients = re.split(r"[\s,;]+", str(config.get("mail_to") or ""))
        raw_groups = xweather.get("groups")
        if isinstance(raw_groups, list):
            for group in raw_groups[:5]:
                if not isinstance(group, dict):
                    continue
                group["email_recipients"] = _merge_email_recipients(
                    group.get("email_recipients") or [],
                    legacy_recipients,
                )
        else:
            xweather["email_recipients"] = _merge_email_recipients(
                xweather.get("email_recipients") or [],
                legacy_recipients,
            )
    return config, xweather


def _safe_group_id(value, index=0):
    identifier = re.sub(r"[^A-Za-z0-9_-]", "", str(value or ""))[:64]
    return identifier or f"lightning_{index + 1}"


def configured_groups(xweather, include_disabled=False):
    """Return normalized runtime areas while retaining singleton compatibility."""
    raw_groups = xweather.get("groups") if isinstance(xweather, dict) else None
    legacy = not isinstance(raw_groups, list)
    if legacy:
        raw_groups = [{
            "id": "",
            "name": "Primary Lightning Area",
            "enabled": xweather.get("enabled", "0"),
            "adaptive_nws_zone_id": xweather.get("adaptive_nws_zone_id", ""),
            "location": xweather.get("location", ""),
            "radius_miles": xweather.get("radius_miles", 25),
            "extensions": xweather.get("recipients", []),
            "desktop_clients": [],
            "email_recipients": xweather.get("email_recipients", []),
            "all_clear": xweather.get("all_clear", "none"),
        }]
    groups = []
    seen = set()
    for index, raw_group in enumerate(raw_groups[:5]):
        if not isinstance(raw_group, dict):
            continue
        # Match the stable ID used by PHP normalization so an applied legacy
        # singleton can be selected from the upgraded UI before the protected
        # config is ever rewritten in the new groups schema.
        group_id = "lightning_primary" if legacy else _safe_group_id(raw_group.get("id"), index)
        if group_id in seen:
            continue
        seen.add(group_id)
        enabled = str(raw_group.get("enabled", "0")).strip().lower() not in {"0", "false", "no", "off", ""}
        if not include_disabled and not enabled:
            continue
        merged = dict(xweather)
        merged.update(raw_group)
        merged["id"] = group_id
        merged["name"] = re.sub(r"\s+", " ", str(raw_group.get("name") or f"Lightning Area {index + 1}")).strip()[:64]
        merged["enabled"] = "1" if enabled else "0"
        merged["recipients"] = raw_group.get("extensions", raw_group.get("recipients", []))
        merged["desktop_clients"] = raw_group.get("desktop_clients", [])
        merged["email_recipients"] = raw_group.get("email_recipients", [])
        merged["all_clear"] = raw_group.get("all_clear", "none")
        merged["_service_enabled"] = str(xweather.get("enabled", "0"))
        merged["_group_index"] = index
        merged["_legacy_singleton"] = legacy
        groups.append(merged)
    return groups


def select_group(xweather, requested_group_id=""):
    groups = configured_groups(xweather, include_disabled=True)
    if requested_group_id:
        requested_group_id = _safe_group_id(requested_group_id)
        for group in groups:
            if group.get("id") == requested_group_id:
                return group
        raise ValueError("requested Lightning trigger area is unavailable")
    return groups[0] if groups else None


def configure_group_runtime(group):
    """Select isolated state for an area without changing shared retry/quota files."""
    global STATE_FILE, CURRENT_GROUP_ID, CURRENT_GROUP_NAME, CURRENT_GROUP_INDEX, CURRENT_GROUP_LEGACY
    CURRENT_GROUP_ID = str(group.get("id") or "")
    CURRENT_GROUP_NAME = str(group.get("name") or "Lightning Area")[:64]
    CURRENT_GROUP_INDEX = int(group.get("_group_index") or 0)
    CURRENT_GROUP_LEGACY = bool(group.get("_legacy_singleton"))
    if STATE_FILE_EXPLICIT or not CURRENT_GROUP_ID:
        return
    STATE_FILE = DATA_DIR / f"xweather-lightning-state-{CURRENT_GROUP_ID}.json"
    if STATE_FILE.exists() or CURRENT_GROUP_INDEX != 0 or not LEGACY_STATE_FILE.is_file():
        return
    legacy_state = _read_json_file(LEGACY_STATE_FILE)
    legacy_state["migrated_group_id"] = CURRENT_GROUP_ID
    _atomic_state_update(STATE_FILE, legacy_state)


def fetch_payload(xweather):
    global LAST_RATE_LIMIT
    LAST_RATE_LIMIT = {}
    fixture = os.environ.get("XWEATHER_TEST_PAYLOAD", "").strip()
    if fixture:
        fixture_path = Path(fixture)
        if not fixture_path.is_file() or fixture_path.stat().st_size > 10 * 1024 * 1024:
            raise ValueError("test payload is unavailable or too large")
        return json.loads(fixture_path.read_text(encoding="utf-8"))
    params = {
        "p": xweather["location"],
        "format": "json",
        "radius": f"{xweather['radius_miles']}miles",
        "filter": "cg",
        # The worker only needs the closest current CG strike to determine
        # cluster state and announce the measured nearest distance.
        "limit": "1",
        "fields": "id,ob.timestamp,ob.pulse.type,relativeTo.distanceMI",
        "client_id": xweather["client_id"],
        "client_secret": xweather["client_secret"],
    }
    url = "https://data.api.xweather.com/lightning/closest?" + urllib.parse.urlencode(params)
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "SouthlandServers-Mass-Notifications-Server/0.1.0"})
    last_error = None
    for attempt in range(3):
        try:
            with XWEATHER_OPENER.open(request, timeout=20) as response:
                if response.status != 200:
                    raise RuntimeError(f"HTTP {response.status}")
                for source, target in (
                    ("X-Ratelimit-Limit-Period", "limit"),
                    ("X-Ratelimit-Remaining-Period", "remaining"),
                    ("X-Cost-Tokens", "cost_tokens"),
                ):
                    try:
                        LAST_RATE_LIMIT[target] = max(0, int(response.headers.get(source, "0")))
                    except (TypeError, ValueError):
                        pass
                LAST_RATE_LIMIT["reset_at"] = str(response.headers.get("X-Ratelimit-Reset-Period", ""))[:100]
                return json.loads(response.read(10 * 1024 * 1024 + 1).decode("utf-8"))
        except Exception as exc:
            last_error = exc
            if attempt < 2:
                time.sleep(attempt + 1)
    raise RuntimeError(f"request failed after retries: {last_error}")


def normalize_records(payload):
    if not isinstance(payload, dict) or payload.get("success") is not True:
        raise ValueError("API response did not report success")
    response = payload.get("response")
    records = response if isinstance(response, list) else ([response] if isinstance(response, dict) else [])
    normalized = []
    now = int(os.environ.get("XWEATHER_TEST_NOW") or time.time())
    for record in records:
        if not isinstance(record, dict):
            continue
        record_id = re.sub(r"[^A-Za-z0-9_.:-]", "", str(record.get("id") or ""))[:160]
        observation = record.get("ob") if isinstance(record.get("ob"), dict) else {}
        pulse = observation.get("pulse") if isinstance(observation.get("pulse"), dict) else {}
        try:
            timestamp = int(observation.get("timestamp") or 0)
        except (TypeError, ValueError):
            timestamp = 0
        relative_to = record.get("relativeTo") if isinstance(record.get("relativeTo"), dict) else {}
        try:
            distance_miles = float(relative_to.get("distanceMI"))
            if not math.isfinite(distance_miles) or distance_miles < 0:
                distance_miles = None
        except (TypeError, ValueError):
            distance_miles = None
        if not record_id or timestamp <= 0 or timestamp < now - 600 or timestamp > now + 120:
            continue
        normalized.append({"id": record_id, "timestamp": timestamp, "type": str(pulse.get("type") or "").lower(), "distance_miles": distance_miles})
    return normalized


def parse_rate_reset_epoch(value):
    """Normalize Xweather's GMT period-reset header without locale dependence."""
    reset_value = str(value or "").strip()
    if not reset_value:
        return 0
    if re.fullmatch(r"[0-9]{1,12}", reset_value):
        try:
            return max(0, int(reset_value))
        except ValueError:
            return 0
    try:
        parsed = parsedate_to_datetime(reset_value)
    except (TypeError, ValueError, OverflowError):
        return 0
    if parsed is None:
        return 0
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    try:
        return max(0, int(parsed.timestamp()))
    except (OverflowError, OSError, ValueError):
        return 0


def rate_limit_status_patch(observed_epoch=None):
    patch = {}
    # Treat limit, remaining, and reset as one snapshot. Mixing a new partial
    # response with values cached from an earlier account period would be more
    # misleading than retaining the clearly dated earlier snapshot.
    if all(key in LAST_RATE_LIMIT for key in ("limit", "remaining")) and LAST_RATE_LIMIT.get("reset_at"):
        observed_epoch = int(time.time() if observed_epoch is None else observed_epoch)
        reset_value = str(LAST_RATE_LIMIT["reset_at"])[:100]
        patch.update({
            "xweather_rate_limit_period": LAST_RATE_LIMIT["limit"],
            "xweather_rate_remaining_period": LAST_RATE_LIMIT["remaining"],
            "xweather_rate_reset_period": reset_value,
            "xweather_rate_reset_epoch": parse_rate_reset_epoch(reset_value),
            "xweather_rate_observed_at": datetime.fromtimestamp(observed_epoch, timezone.utc).isoformat(),
        })
    if "cost_tokens" in LAST_RATE_LIMIT:
        patch["xweather_last_query_cost_tokens"] = LAST_RATE_LIMIT["cost_tokens"]
    return patch


def nearest_strike_miles(records):
    distances = [record.get("distance_miles") for record in records if isinstance(record.get("distance_miles"), (int, float))]
    return min(distances) if distances else None


def format_miles(distance_miles):
    rounded = round(float(distance_miles), 1)
    return str(int(rounded)) if rounded.is_integer() else f"{rounded:.1f}"


def read_state():
    return _read_json_file(STATE_FILE)


def read_json_object(path):
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except Exception:
        return {}


def adaptive_storm_gate(state, now, grace_minutes, selected_zone_id):
    """Use fresh per-zone NWS summaries to decide whether storm mode is active."""
    active_events = []
    fresh_gates = 0
    for gate_path in DATA_DIR.glob("nws-lightning-gate-*.json"):
        gate = read_json_object(gate_path)
        gate_zone_id = re.sub(r"[^A-Za-z0-9_-]", "", str(gate.get("group_id") or gate_path.stem.replace("nws-lightning-gate-", "")))[:64]
        if selected_zone_id and gate_zone_id != selected_zone_id:
            continue
        try:
            updated_at = int(float(gate.get("updated_at") or 0))
        except (TypeError, ValueError):
            updated_at = 0
        if updated_at <= 0 or now - updated_at > 180:
            continue
        fresh_gates += 1
        if gate.get("active"):
            for event in gate.get("events") or []:
                label = re.sub(r"\s+", " ", str(event)).strip()[:120]
                if label and label not in active_events:
                    active_events.append(label)
    if active_events:
        state["last_nws_storm_active"] = now
        return True, f"Weather.gov storm gate active: {', '.join(active_events[:3])}.", fresh_gates
    last_active = int(state.get("last_nws_storm_active", 0) or 0)
    grace_seconds = max(5, min(120, int(grace_minutes))) * 60
    if last_active > 0 and now - last_active <= grace_seconds:
        remaining = max(1, math.ceil((grace_seconds - (now - last_active)) / 60))
        return True, f"Weather.gov storm gate grace period active for about {remaining} more minute(s).", fresh_gates
    if fresh_gates == 0:
        return False, "Adaptive standby: waiting for fresh Weather.gov zone status; no Xweather tokens used.", fresh_gates
    return False, "Adaptive standby: no active Weather.gov thunderstorm event; no Xweather tokens used.", fresh_gates


def quota_governor(state, now):
    """Token bucket: one daily allowance initially, up to seven days banked."""
    status = read_json_object(STATUS_FILE)
    allowance = max(1, int(status.get("xweather_rate_limit_period") or 15000))
    observed_cost = max(1, int(status.get("xweather_last_query_cost_tokens") or 10))
    remaining_value = status.get("xweather_rate_remaining_period")
    try:
        remaining = allowance if remaining_value in (None, "") else max(0, int(remaining_value))
    except (TypeError, ValueError):
        remaining = allowance
    reset_marker = str(status.get("xweather_rate_reset_period") or "")[:100]
    try:
        reset_epoch = max(0, int(status.get("xweather_rate_reset_epoch") or 0))
    except (TypeError, ValueError):
        reset_epoch = 0
    if reset_epoch <= 0:
        reset_epoch = parse_rate_reset_epoch(reset_marker)
    if reset_epoch > 0 and reset_epoch <= now:
        # The counters describe an account period that has already ended. Allow
        # one budgeted query to obtain authoritative counters for the new period;
        # otherwise an old zero balance could deadlock adaptive polling forever.
        remaining = allowance
        reset_marker = f"expired:{reset_epoch}"
    bucket_cap = allowance * 7 / 30
    initial_bucket = allowance / 30
    refill_allowance = max(1.0, allowance - initial_bucket)
    previous_reset = str(state.get("quota_reset_marker") or "")
    if previous_reset != reset_marker or "quota_bucket_tokens" not in state:
        bucket = initial_bucket
        updated = now
    else:
        try:
            bucket = float(state.get("quota_bucket_tokens") or 0)
            updated = int(state.get("quota_bucket_updated") or now)
        except (TypeError, ValueError):
            bucket, updated = initial_bucket, now
        refill = max(0, now - updated) * refill_allowance / (30 * 86400)
        bucket = min(bucket_cap, bucket + refill)
    state["quota_bucket_tokens"] = round(bucket, 4)
    state["quota_bucket_updated"] = now
    state["quota_reset_marker"] = reset_marker
    if remaining < observed_cost:
        return False, observed_cost, "Quota governor paused Xweather polling because the account-period balance is too low."
    if bucket < observed_cost:
        wait_minutes = max(1, math.ceil((observed_cost - bucket) * (30 * 86400) / refill_allowance / 60))
        return False, observed_cost, f"Quota governor paused storm-mode polling for about {wait_minutes} minute(s) to preserve the monthly allowance."
    state["quota_bucket_tokens"] = round(bucket - observed_cost, 4)
    return True, observed_cost, ""


def reserve_shared_quota(now):
    quota_state = read_json_object(QUOTA_STATE_FILE)
    allowed, reserved_cost, message = quota_governor(quota_state, now)
    _atomic_state_update(QUOTA_STATE_FILE, quota_state)
    return allowed, reserved_cost, message


def adjust_shared_quota(actual_cost, reserved_cost):
    adjustment = int(actual_cost) - int(reserved_cost)
    if not adjustment:
        return
    quota_state = read_json_object(QUOTA_STATE_FILE)
    try:
        bucket = float(quota_state.get("quota_bucket_tokens") or 0)
    except (TypeError, ValueError):
        bucket = 0.0
    quota_state["quota_bucket_tokens"] = round(max(0.0, bucket - adjustment), 4)
    quota_state["quota_bucket_updated"] = int(time.time())
    _atomic_state_update(QUOTA_STATE_FILE, quota_state)


def safe_tone(name):
    name = re.sub(r"[^A-Za-z0-9_-]", "", str(name or ""))[:64]
    path = TONES_DIR / f"{name}.wav"
    return path if name and path.is_file() else None


def spoken_location(location):
    """Return a natural TTS label without reading latitude/longitude aloud."""
    value = re.sub(r"\s+", " ", str(location or "")).strip()
    coordinate_pair = re.fullmatch(
        r"[+-]?(?:\d{1,2}(?:\.\d+)?|1[0-7]\d(?:\.\d+)?|180(?:\.0+)?)\s*,\s*"
        r"[+-]?(?:\d{1,2}(?:\.\d+)?|1[0-7]\d(?:\.\d+)?|180(?:\.0+)?)",
        value,
    )
    return "this area" if coordinate_pair else (value or "this area")


def build_spoken_message(event_kind, is_test, radius_miles, location, nearest_miles=None):
    location_label = spoken_location(location)
    if event_kind == "clear":
        message = f"All clear. Lightning is now outside the configured {radius_miles}-mile radius of {location_label}."
    else:
        detected_miles = format_miles(nearest_miles if nearest_miles is not None else radius_miles)
        message = f"Warning. Lightning has been detected {detected_miles} miles from {location_label}. Please seek shelter now."
    if is_test and event_kind == "clear":
        return f"Test only. This is a simulated lightning all clear for the configured {radius_miles}-mile radius of {location_label}. No actual lightning event is being reported."
    if is_test:
        return f"Test only. This is a simulated alert. Lightning has been detected within {radius_miles} miles of {location_label}. No actual lightning event is being reported."
    return message


def generate_audio(config, xweather, message, alert_id):
    TTS_DIR.mkdir(parents=True, exist_ok=True)
    sources = []
    opening_name = xweather.get("opening_tone", "opening_Lightning_alert")
    closing_name = xweather.get("closing_tone", "")
    # Pre-0.0.7 used a shared Weather-tone sentinel. Keep upgrades safe while
    # enforcing Lightning's independent default opening and no closing tone.
    if opening_name == "use_default":
        opening_name = "opening_Lightning_alert"
    if closing_name == "use_default":
        closing_name = ""
    opening = safe_tone(opening_name)
    closing = safe_tone(closing_name)
    if opening:
        sources.append(opening)
    voice = Path(str(config.get("nws_piper_voice") or DATA_DIR / "piper/voices/en_US-amy-low.onnx"))
    if not PIPER_BIN.is_file() or not os.access(PIPER_BIN, os.X_OK) or not voice.is_file():
        raise RuntimeError("Piper runtime or selected weather voice is unavailable")
    raw = Path(tempfile.mkstemp(prefix="sls_xweather_", suffix=".wav", dir="/tmp")[1])
    tts = TTS_DIR / f"xweather_tts_{alert_id}.wav"
    try:
        subprocess.run(["/usr/bin/timeout", "90", str(PIPER_BIN), "--model", str(voice), "--volume", "1.00", "--output-file", str(raw)], input=message + "\n", text=True, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        volume_value = xweather.get("tts_volume", config.get("nws_tts_volume", 25))
        volume = min(2.0, max(0.01, int(volume_value) / 100))
        subprocess.run(["/usr/bin/sox", "-v", f"{volume:.2f}", str(raw), "-r", "8000", "-c", "1", "-b", "16", str(tts)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    finally:
        raw.unlink(missing_ok=True)
    sources.append(tts)
    if closing:
        sources.append(closing)
    if not sources:
        return ""
    silence = Path(tempfile.mkstemp(prefix="sls_silence_", suffix=".wav", dir="/tmp")[1])
    target = TTS_DIR / f"xweather_sequence_{alert_id}.wav"
    try:
        subprocess.run(["/usr/bin/sox", "-n", "-r", "8000", "-c", "1", "-b", "16", str(silence), "trim", "0", "1"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(["/usr/bin/sox", str(silence), *map(str, sources), "-r", "8000", "-c", "1", "-b", "16", str(target)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    finally:
        silence.unlink(missing_ok=True)
    os.chmod(target, 0o644)
    return f"{SOUND_PREFIX}/{target.stem}"


def audio_page_hold_seconds(sound):
    if not re.fullmatch(r"[A-Za-z0-9_/-]+", str(sound or "")):
        raise RuntimeError("generated audio path is invalid")
    sound_file = ASTERISK_SOUNDS_DIR / f"{sound}.wav"
    if not sound_file.is_file():
        raise RuntimeError("generated audio file is unavailable to Asterisk")
    result = subprocess.run(
        ["/usr/bin/soxi", "-D", str(sound_file)],
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        timeout=5,
        env={**os.environ, "LC_ALL": "C"},
    )
    duration = float(result.stdout.strip())
    if not math.isfinite(duration) or duration <= 0 or duration > 1767:
        raise RuntimeError("generated audio duration is outside the supported paging range")
    # Keep Page's originating Local channel through the complete WAV and a
    # bounded teardown margin without adding its separate participant timeout.
    return math.ceil(duration) + 2


def queue_audio(recipients, sound, archive=False):
    if not sound:
        return 0, [], 0
    page_hold_seconds = audio_page_hold_seconds(sound)
    call_wait_seconds = page_hold_seconds + 30
    queued = 0
    archived_results = []
    if archive:
        SPOOL_DONE_DIR.mkdir(parents=True, exist_ok=True)
        if os.geteuid() == 0:
            asterisk_account = pwd.getpwnam("asterisk")
            os.chown(SPOOL_DONE_DIR, asterisk_account.pw_uid, asterisk_account.pw_gid)
        os.chmod(SPOOL_DONE_DIR, 0o750)
    for extension in recipients:
        wait_time = TEST_CALL_WAIT_SECONDS if archive else 180
        body = (
            f"Channel: Local/{extension}@sls-alert-audio\n"
            "CallerID: \"SLS Lightning Alert\" <SLS>\n"
            f"Setvar: SLS_SOUND={sound}\n"
            "Setvar: SLS_CALLERID_NAME=SLS Lightning Alert\nSetvar: SLS_CALLERID_NUM=SLS\n"
            f"MaxRetries: 0\nRetryTime: 5\nWaitTime: {max(wait_time, call_wait_seconds)}\n"
            + ("Archive: yes\n" if archive else "")
            + f"Application: Wait\nData: {page_hold_seconds}\n"
        )
        fd, name = tempfile.mkstemp(prefix="sls_xweather_", suffix=".call", dir="/var/spool/asterisk/tmp", text=True)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(body)
        os.chmod(name, 0o640)
        # Web and cron delivery normally run as Asterisk. Root-driven repair or
        # validation paths must hand the call file to Asterisk before moving it
        # into the watched outgoing spool, or pbx_spool rejects it as unreadable.
        if os.geteuid() == 0:
            asterisk_account = pwd.getpwnam("asterisk")
            os.chown(name, asterisk_account.pw_uid, asterisk_account.pw_gid)
        target = SPOOL_DIR / Path(name).name
        os.replace(name, target)
        if archive:
            archived_results.append((SPOOL_DONE_DIR / target.name, extension))
        queued += 1
    return queued, archived_results, page_hold_seconds


def wait_for_archived_calls(results, timeout=45):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline and any(not path.is_file() for path, _extension in results):
        time.sleep(1)
    failures = []
    for path, extension in results:
        if not path.is_file():
            failures.append(f"Extension {extension}: timed out waiting for the Asterisk test call result")
            continue
        status = ""
        try:
            for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
                key, separator, value = line.partition(":")
                if separator and key.strip().lower() == "status":
                    status = value.strip()
                    break
        finally:
            path.unlink(missing_ok=True)
        if status.lower() == "completed":
            continue
        if status.lower() == "expired":
            failures.append(
                f"Extension {extension}: page did not answer within {TEST_CALL_WAIT_SECONDS} seconds "
                "(Asterisk status: Expired)"
            )
        elif status:
            failures.append(f"Extension {extension}: Asterisk test call ended with status {status}")
        else:
            failures.append(f"Extension {extension}: Asterisk did not record a test call status")
    if failures:
        raise RuntimeError("Asterisk test audio did not complete: " + "; ".join(failures))


def record_xweather_outcome(is_test, status, message):
    prefix = "last_xweather_test" if is_test else "last_xweather_delivery"
    atomic_json_update(STATUS_FILE, {
        f"{prefix}_at": datetime.now().astimezone().isoformat(),
        f"{prefix}_status": status,
        f"{prefix}_message": message[:240],
    })


def send_visual(recipients, desktop_clients, message, is_test=False):
    # ImageScreen is reliable on the legacy Yealink T48G where a long
    # TextScreen can produce a phone-side "Layout Error". Other vendors still
    # receive the normal safe text fallback from sls_notify.py.
    title = "Lightning Test" if is_test else "Lightning Alert"
    command = [
        "/usr/bin/python3", str(VISUAL_SCRIPT), "--announcement", message,
        "--announcement-image", "--announcement-title", title,
        "--announcement-bg-color", "#92400e",
    ]
    if recipients:
        command.extend(["--targets", ",".join(recipients)])
    if desktop_clients:
        command.extend(["--desktop-targets", ",".join(desktop_clients)])
    if not recipients and not desktop_clients:
        raise RuntimeError("Lightning trigger area has no local delivery routes")
    subprocess.run(command, check=True, timeout=30, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def send_webhooks(config, subject, message, event_name, severity, state_label, radius, alert_id, nearest_miles=None):
    fields = [("Storm State", state_label), ("Detection Radius", f"{radius} miles")]
    if nearest_miles is not None:
        fields.append(("Nearest Strike", f"{format_miles(nearest_miles)} miles"))
    return dispatch_webhook_destinations(
        config,
        subject,
        message,
        event_name,
        severity,
        fields,
        datetime.now(timezone.utc).isoformat(),
        "xweather",
        f"xweather-{alert_id}",
        {
            "storm_state": state_label,
            "radius_miles": radius,
            "nearest_strike_miles": round(nearest_miles, 1) if nearest_miles is not None else None,
        },
        live=True,
        test=False,
        dry_run=False,
    )


def quiet_hours_active(xweather):
    if str(xweather.get("quiet_hours_enabled", "0")) not in {"1", "true", "True"}:
        return False
    try:
        start_hour, start_minute = map(int, str(xweather.get("quiet_hours_start") or "21:00").split(":", 1))
        end_hour, end_minute = map(int, str(xweather.get("quiet_hours_end") or "06:00").split(":", 1))
    except (TypeError, ValueError):
        return False
    now = datetime.now().astimezone()
    minute = now.hour * 60 + now.minute
    start = start_hour * 60 + start_minute
    end = end_hour * 60 + end_minute
    if start == end:
        return False
    return start <= minute < end if start < end else minute >= start or minute < end


def commit_local_event_state(state, event_kind, now):
    if event_kind == "clear":
        atomic_json_update(STATE_FILE, {
            "active": False,
            "notified": False,
            "empty_polls": 0,
            "last_poll": now,
            "last_query": state.get("last_query", now),
            "last_cleared": now,
            "local_dispatch_intent": "",
        })
        return
    state["active"] = True
    state["notified"] = True
    state["empty_polls"] = 0
    state["last_notification"] = now
    state["local_dispatch_intent"] = ""
    atomic_json_update(STATE_FILE, state)


def main():
    requested_group_id = os.environ.get("XWEATHER_ACTIVE_GROUP_ID", "").strip()
    try:
        config, xweather_root = load_config()
        xweather = select_group(xweather_root, requested_group_id)
        if xweather is None:
            raise ValueError("no Lightning trigger area is configured")
        if xweather.get("_legacy_singleton") and not xweather.get("desktop_clients"):
            xweather["desktop_clients"] = [
                str(client.get("username") or "")
                for client in (config.get("desktop_clients") or [])
                if isinstance(client, dict)
                and str(client.get("enabled", "0")).strip().lower() in {"1", "true"}
                and str(client.get("username") or "").strip()
            ]
        configure_group_runtime(xweather)
    except Exception as exc:
        log(f"configuration error: {exc}")
        return 1
    test_event = os.environ.get("XWEATHER_TEST_EVENT", "").strip().lower()
    dry_run = os.environ.get("XWEATHER_DRY_RUN", "0") == "1"
    external_live = (
        test_event == ""
        and not dry_run
        and not os.environ.get("XWEATHER_TEST_PAYLOAD", "").strip()
        and not os.environ.get("XWEATHER_TEST_NOW", "").strip()
    )

    def retry_external_now(preferred_correlation_key=""):
        if not external_live:
            return {"results": [], "pending": 0}
        try:
            outcome = retry_external_deliveries(
                EXTERNAL_DELIVERY_STATE_FILE,
                config,
                "xweather",
                live=True,
                preferred_correlation_key=preferred_correlation_key,
                email_sender=send_branded_email,
                webhook_dispatcher=dispatch_webhook_destinations,
            )
            if outcome["pending"]:
                log(f"{outcome['pending']} durable external delivery record(s) remain pending")
            return outcome
        except RetryStateError as exc:
            log(f"external delivery retry state is unavailable: {exc}")
            atomic_json_update(STATUS_FILE, {
                "last_xweather_delivery_at": datetime.now().astimezone().isoformat(),
                "last_xweather_delivery_status": "fault",
                "last_xweather_delivery_message": "External delivery retry state is unavailable; live Lightning polling stopped without replaying local channels.",
            })
            return None

    if str(xweather.get("_service_enabled", xweather.get("enabled", "0"))).lower() not in {"1", "true"} and test_event not in {"entry", "clear"}:
        return 0
    if str(xweather.get("enabled", "0")).lower() not in {"1", "true"}:
        log(f"Lightning trigger area {CURRENT_GROUP_NAME} is disabled")
        return 1 if test_event in {"entry", "clear"} or os.environ.get("XWEATHER_VERIFY_ONLY", "0") == "1" else 0
    client_id = str(xweather.get("client_id") or "").strip()
    client_secret = str(xweather.get("client_secret") or "").strip()
    location = str(xweather.get("location") or "").strip()
    recipients = []
    for value in xweather.get("recipients") or []:
        extension = re.sub(r"[^0-9]", "", str(value))
        if extension and extension not in recipients:
            recipients.append(extension)
    desktop_clients = []
    for value in xweather.get("desktop_clients") or []:
        username = re.sub(r"[^A-Za-z0-9_.@-]", "", str(value))[:80]
        if username and username not in desktop_clients:
            desktop_clients.append(username)
    if not location or (not recipients and not desktop_clients) or (test_event not in {"entry", "clear"} and (not client_id or not client_secret)):
        log("enabled integration is missing credentials, location, or recipients")
        return 1
    adaptive = str(xweather.get("adaptive_free_tier", "1")) not in {"0", "false", "False"}
    settings = {
        "location": location,
        "radius_miles": min(62, max(1, int(xweather.get("radius_miles", 25)))),
        "query_interval_minutes": 5 if adaptive else min(10, max(1, int(xweather.get("query_interval_minutes", 5)))),
        "client_id": client_id,
        "client_secret": client_secret,
    }
    verify_only = os.environ.get("XWEATHER_VERIFY_ONLY", "0") == "1"
    if verify_only:
        try:
            records = normalize_records(fetch_payload(settings))
        except Exception as exc:
            safe_error = str(exc)
            for secret in (client_id, client_secret):
                if secret:
                    safe_error = safe_error.replace(secret, "[redacted]")
            log(f"credential validation failed: {safe_error[:240]}")
            atomic_json_update(STATUS_FILE, {"last_xweather_poll_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_status": "fault", "last_xweather_poll_message": f"Xweather credential validation failed: {safe_error[:180]}"})
            return 1
        atomic_json_update(STATUS_FILE, {"last_xweather_poll_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_ok_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_status": "ok", "last_xweather_poll_message": f"Xweather credentials accepted; API returned {len(records)} recent cloud-to-ground strike record(s) inside the configured radius.", **rate_limit_status_patch()})
        log(f"credential validation succeeded; {len(records)} recent cloud-to-ground strike record(s)")
        return 0
    try:
        state = read_state()
    except Exception as exc:
        log(f"lightning state is unavailable or corrupt: {exc}")
        atomic_json_update(STATUS_FILE, {
            "last_xweather_poll_at": datetime.now().astimezone().isoformat(),
            "last_xweather_poll_status": "fault",
            "last_xweather_poll_message": "Lightning deduplication state is unavailable or corrupt; polling stopped without sending.",
        })
        retry_external_now()
        return 1
    now = int(os.environ.get("XWEATHER_TEST_NOW") or time.time())
    if test_event == "":
        if adaptive:
            selected_zone_id = re.sub(r"[^A-Za-z0-9_-]", "", str(xweather.get("adaptive_nws_zone_id") or ""))[:64]
            gate_open, gate_message, fresh_gate_count = adaptive_storm_gate(state, now, xweather.get("adaptive_grace_minutes", 60), selected_zone_id)
            state["adaptive_last_check"] = now
            state["adaptive_fresh_gate_count"] = fresh_gate_count
            if not gate_open:
                atomic_json_update(STATE_FILE, state)
                atomic_json_update(STATUS_FILE, {
                    "last_xweather_poll_at": datetime.now().astimezone().isoformat(),
                    "last_xweather_poll_status": "standby",
                    "last_xweather_poll_message": gate_message,
                    "xweather_adaptive_mode": True,
                    "xweather_adaptive_gate_active": False,
                })
                return 1 if retry_external_now() is None else 0
        last_query = int(state.get("last_query", 0) or 0)
        if last_query > 0 and now - last_query < settings["query_interval_minutes"] * 60:
            if adaptive:
                atomic_json_update(STATE_FILE, state)
            return 1 if retry_external_now() is None else 0
        if adaptive:
            quota_allowed, reserved_cost, quota_message = reserve_shared_quota(now)
            if not quota_allowed:
                atomic_json_update(STATE_FILE, state)
                atomic_json_update(STATUS_FILE, {
                    "last_xweather_poll_at": datetime.now().astimezone().isoformat(),
                    "last_xweather_poll_status": "quota_guard",
                    "last_xweather_poll_message": quota_message,
                    "xweather_adaptive_mode": True,
                    "xweather_adaptive_gate_active": True,
                })
                return 1 if retry_external_now() is None else 0
        state["last_query"] = now
        atomic_json_update(STATE_FILE, state)
    if test_event in {"entry", "clear"}:
        records = [{"id": f"manual-{int(time.time())}", "timestamp": int(time.time()), "type": "test"}] if test_event == "entry" else []
    else:
        try:
            records = normalize_records(fetch_payload(settings))
            if adaptive:
                actual_cost = max(1, int(LAST_RATE_LIMIT.get("cost_tokens") or reserved_cost))
                adjust_shared_quota(actual_cost, reserved_cost)
        except Exception as exc:
            safe_error = str(exc)
            for secret in (client_id, client_secret):
                if secret:
                    safe_error = safe_error.replace(secret, "[redacted]")
            log(f"poll failed: {safe_error[:240]}")
            atomic_json_update(STATUS_FILE, {"last_xweather_poll_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_status": "fault", "last_xweather_poll_message": f"Unable to reach or process the Xweather API: {safe_error[:180]}"})
            retry_external_now()
            return 1
    active_before = bool(state.get("active", False))
    notified = bool(state.get("notified", False))
    empty_polls = int(state.get("empty_polls", 0) or 0)
    has_lightning = bool(records)
    nearest_miles = nearest_strike_miles(records)
    event_kind = ""
    recovering_local_intent = False
    recovered_intent_key = ""
    if test_event in {"entry", "clear"}:
        event_kind = test_event
    elif has_lightning:
        if not active_before:
            state = {"active": True, "notified": False, "empty_polls": 0, "cluster_started": now, "last_query": now}
            # Persist the cluster identity before any local delivery.  Keeping
            # notified=false makes an early crash retryable, while a crash after
            # the external record is queued can recover the same correlation key
            # without replaying phones or desktops.
            atomic_json_update(STATE_FILE, state)
            notified = False
        else:
            state["empty_polls"] = 0
        if not notified:
            event_kind = "entry"
    elif active_before:
        empty_polls += 1
        state["empty_polls"] = empty_polls
        if empty_polls >= 2:
            event_kind = "clear" if notified and str(xweather.get("all_clear", "none")) == "send" else "reset"
    if external_live:
        recovered_intent_key = str(state.get("local_dispatch_intent") or "")
        intent_match = re.fullmatch(r"(?:(?P<group>[A-Za-z0-9_-]{1,64}):)?(?P<kind>entry|clear):(?P<started>[1-9][0-9]{0,9})", recovered_intent_key)
        intent_group = intent_match.group("group") if intent_match else ""
        intent_belongs_here = bool(intent_match) and (not intent_group or intent_group == CURRENT_GROUP_ID)
        if intent_belongs_here and int(intent_match.group("started")) <= 9_999_999_999:
            # Current weather may have changed while the process was down. The
            # durable intent, not the next API response, identifies which local
            # outcome must be finalized without replay.
            event_kind = intent_match.group("kind")
            recovering_local_intent = True
    state["last_poll"] = now
    if has_lightning and nearest_miles is not None:
        poll_message = f"Lightning cluster active; nearest recent strike is {format_miles(nearest_miles)} miles away inside the configured {settings['radius_miles']}-mile radius."
    elif has_lightning:
        poll_message = f"Lightning cluster active inside the {settings['radius_miles']}-mile radius."
    else:
        poll_message = f"No recent lightning inside the {settings['radius_miles']}-mile radius."
    atomic_json_update(STATUS_FILE, {"last_xweather_poll_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_status": "ok", "last_xweather_poll_message": poll_message, "xweather_adaptive_mode": adaptive, "xweather_adaptive_gate_active": adaptive, **rate_limit_status_patch()})

    if event_kind == "reset":
        atomic_json_update(STATE_FILE, {"active": False, "notified": False, "empty_polls": 0, "last_poll": now, "last_query": state.get("last_query", now), "last_cleared": now})
        return 1 if retry_external_now() is None else 0
    if event_kind == "":
        if test_event == "":
            atomic_json_update(STATE_FILE, state)
        return 1 if retry_external_now() is None else 0

    quiet = test_event == "" and not recovering_local_intent and quiet_hours_active(xweather)
    if quiet:
        if event_kind == "clear":
            atomic_json_update(STATE_FILE, {"active": False, "notified": False, "empty_polls": 0, "last_poll": now, "last_query": state.get("last_query", now), "last_cleared": now})
        else:
            atomic_json_update(STATE_FILE, state)
        atomic_json_update(STATUS_FILE, {"last_xweather_delivery_at": datetime.now().astimezone().isoformat(), "last_xweather_delivery_status": "skipped", "last_xweather_delivery_message": "Lightning delivery suppressed by independent lightning quiet hours."})
        return 1 if retry_external_now() is None else 0

    # Asterisk's FILTER() safety pass used by sls-alert-audio removes hyphens
    # on this deployment. Keep the generated sound stem to alphanumerics and
    # underscores so the call file and the file Asterisk opens stay identical.
    correlation_group_id = "" if CURRENT_GROUP_LEGACY else CURRENT_GROUP_ID
    alert_id = f"{correlation_group_id + '_' if correlation_group_id else ''}{now}_{event_kind}"
    is_test = test_event in {"entry", "clear"}
    location_label = spoken_location(location)
    if event_kind == "clear":
        message = f"All clear. Lightning is now outside the configured {settings['radius_miles']}-mile radius of {location_label}."
        subject = "Southland Servers PBX: Lightning all clear"
        event_name = "Lightning All Clear"
        severity = "All Clear"
        state_label = "Outside radius"
    else:
        detected_miles = format_miles(nearest_miles if nearest_miles is not None else settings["radius_miles"])
        message = f"Warning. Lightning has been detected {detected_miles} miles from {location_label}. Please seek shelter now."
        subject = "Southland Servers PBX: Lightning detected"
        event_name = "Lightning Radius Alert"
        severity = "Warning"
        state_label = "Inside radius"
    if is_test:
        if event_kind == "clear":
            message = f"TEST ONLY. This is a simulated lightning all-clear for the configured {settings['radius_miles']}-mile radius of {location_label}. No actual lightning event is being reported."
        else:
            message = f"TEST ONLY. This is a simulated alert. Lightning has been detected within {settings['radius_miles']} miles of {location_label}. No actual lightning event is being reported."
        subject = "TEST ONLY: Southland Servers PBX Lightning system test"
        event_name = "Lightning System Test"
        severity = "Test"
        state_label = "Simulated test"
    try:
        cluster_started = int(
            recovered_intent_key.rsplit(":", 1)[1]
            if recovering_local_intent
            else (state.get("cluster_started", 0) or 0)
        )
    except (IndexError, TypeError, ValueError, OverflowError):
        cluster_started = 0
    if cluster_started <= 0 or cluster_started > 9_999_999_999:
        cluster_started = now
        if external_live:
            # Older installations can have an active pre-0.1.0 state without a
            # cluster identity. Persist its migration before deriving the key;
            # otherwise a crash would substitute a different poll time and
            # defeat the durable local-dispatch marker on restart.
            state["cluster_started"] = cluster_started
            atomic_json_update(STATE_FILE, state)
    correlation_key = recovered_intent_key if recovering_local_intent else f"{correlation_group_id + ':' if correlation_group_id else ''}{event_kind}:{cluster_started}"
    external_fields = [("Trigger Area", CURRENT_GROUP_NAME), ("Storm State", state_label), ("Detection Radius", f"{settings['radius_miles']} miles")]
    if nearest_miles is not None:
        external_fields.append(("Nearest Strike", f"{format_miles(nearest_miles)} miles"))
    group_email_values = xweather.get("email_recipients") or []
    if not isinstance(group_email_values, list):
        group_email_values = re.split(r"[\s,;]+", str(group_email_values))
    email_lookup = {}
    for value in group_email_values:
        value = str(value).strip()
        if valid_recipient(value):
            email_lookup[value.lower()] = value
    email_recipients = " ".join(list(email_lookup.values())[:50])

    def queue_current_external_delivery():
        return queue_external_delivery(
            EXTERNAL_DELIVERY_STATE_FILE,
            config,
            correlation_key,
            subject,
            message,
            event_name,
            severity,
            external_fields,
            datetime.now(timezone.utc).isoformat(),
            "xweather",
            f"xweather-{alert_id}",
            {
                "trigger_area_id": CURRENT_GROUP_ID,
                "trigger_area_name": CURRENT_GROUP_NAME,
                "storm_state": state_label,
                "radius_miles": settings["radius_miles"],
                "nearest_strike_miles": round(nearest_miles, 1) if nearest_miles is not None else None,
            },
            email_recipients,
        )

    if external_live:
        try:
            already_recorded = external_delivery_recorded(
                EXTERNAL_DELIVERY_STATE_FILE, "xweather", correlation_key
            )
        except RetryStateError as exc:
            log(f"external delivery retry state became unavailable: {exc}")
            return 1
        local_intent_recorded = str(state.get("local_dispatch_intent") or "") == correlation_key
        if local_intent_recorded or already_recorded:
            # A dedicated state marker survives external-record retention. If a
            # stop happened between that marker and the external queue, create
            # the missing external work now, but never replay local submission.
            if not already_recorded:
                try:
                    queue_current_external_delivery()
                except RetryStateError as exc:
                    recovery_message = (
                        "Recovered a durable Lightning local dispatch intent, but external work "
                        "could not be persisted; phone/Desktop delivery remains indeterminate and will not be replayed."
                    )
                    log(f"{recovery_message} Error: {exc}")
                    record_xweather_outcome(False, "fault", recovery_message)
                    return 1
            commit_local_event_state(state, event_kind, now)
            try:
                retry_outcome = retry_external_now(correlation_key)
                if retry_outcome is None:
                    raise RetryStateError("retry_state_unavailable")
                still_pending = external_delivery_pending(
                    EXTERNAL_DELIVERY_STATE_FILE, "xweather", correlation_key
                )
            except RetryStateError as exc:
                still_pending = True
                log(f"recovered external delivery remains pending because retry state failed: {exc}")
            recovery_message = (
                "Recovered a durable Lightning dispatch intent after an interrupted run; "
                "the local phone/Desktop outcome is indeterminate and will not be replayed; "
                + ("external destinations remain pending." if still_pending else "external destinations are complete.")
            )
            record_xweather_outcome(False, "partial_failure", recovery_message)
            log(recovery_message)
            return 0
    spoken_message = build_spoken_message(event_kind, is_test, settings["radius_miles"], location, nearest_miles)
    sound = ""
    queued = 0
    archived_results = []
    try:
        if not dry_run:
            sound = generate_audio(config, xweather, spoken_message, re.sub(r"[^A-Za-z0-9_-]", "", alert_id))
    except Exception as exc:
        log(f"alert preparation failed before dispatch intent was recorded: {exc}")
        record_xweather_outcome(is_test, "fault", str(exc))
        if test_event == "":
            atomic_json_update(STATE_FILE, state)
        retry_external_now()
        return 1

    delivery_key = ""
    external_failures = []
    if external_live:
        # Audio generation above is reversible preparation. Persist the local
        # at-most-once marker next, then require the external retry record, all
        # before invoking either Asterisk/phone or Desktop delivery.
        state["local_dispatch_intent"] = correlation_key
        try:
            atomic_json_update(STATE_FILE, state)
        except Exception as exc:
            log(f"local dispatch intent could not be persisted; no local alert was submitted: {exc}")
            record_xweather_outcome(False, "fault", "Local dispatch intent could not be persisted; no phone or Desktop delivery was attempted.")
            return 1
        try:
            delivery_key = queue_current_external_delivery()
        except RetryStateError as exc:
            # The caller has not crossed either local submission boundary, so a
            # known external-queue failure can safely clear the local marker and
            # leave this cluster retryable. If clearing fails, retaining it is
            # conservative and recovery will report an indeterminate outcome.
            state["local_dispatch_intent"] = ""
            try:
                atomic_json_update(STATE_FILE, {"local_dispatch_intent": ""})
            except Exception as clear_exc:
                log(f"failed to clear unused local dispatch intent safely: {clear_exc}")
            log(f"dispatch intent could not be persisted; no local alert was submitted: {exc}")
            record_xweather_outcome(False, "fault", "Dispatch intent could not be persisted; no phone or Desktop delivery was attempted.")
            return 1

    # Live delivery is deliberately at-most-once across a process interruption.
    # If the process stops after the local marker was persisted, the next poll
    # treats phone/Desktop acceptance as indeterminate and does not replay it;
    # SIP/Asterisk cannot make endpoint receipt atomic or exactly once.
    local_delivery_outcome = "dry_run" if dry_run else "submitted"
    local_delivery_error = ""
    try:
        if not dry_run:
            queued, archived_results, page_hold_seconds = queue_audio(recipients, sound, archive=is_test)
            if sound:
                time.sleep(2)
            send_visual(recipients, desktop_clients, message, is_test=is_test)
            if is_test:
                wait_for_archived_calls(archived_results, timeout=page_hold_seconds + 45)
    except Exception as exc:
        local_delivery_outcome = "indeterminate"
        local_delivery_error = str(exc)
        log(f"local alert dispatch outcome is indeterminate and will not be replayed: {exc}")
        record_xweather_outcome(is_test, "fault", "Local phone/Desktop dispatch outcome is indeterminate; the durable intent prevents replay.")
        if not external_live:
            if test_event == "":
                atomic_json_update(STATE_FILE, state)
            return 1

    if test_event == "":
        commit_local_event_state(state, event_kind, now)

    if external_live:
        try:
            retry_outcome = retry_external_now(correlation_key)
            if retry_outcome is None:
                raise RetryStateError("retry_state_unavailable")
            current_results = [
                result for result in retry_outcome["results"]
                if result.get("delivery") == delivery_key
            ]
            for result in current_results:
                log(
                    f"external {result.get('type')} {result.get('id')}: "
                    f"{result.get('status')} error={result.get('error') or 'none'}"
                )
            if external_delivery_pending(EXTERNAL_DELIVERY_STATE_FILE, "xweather", correlation_key):
                external_failures = sorted({
                    str(result.get("type") or "external")
                    for result in current_results
                    if result.get("status") != "accepted"
                } or {"external"})
        except RetryStateError as exc:
            external_failures = ["external_state"]
            log(f"external delivery remains pending because retry state failed: {exc}")

    event_status = "dry_run" if dry_run else ("completed" if is_test else ("partial_failure" if external_failures or local_delivery_outcome == "indeterminate" else "queued"))
    append_event({"event_id": f"xweather-{alert_id}", "logged_at": datetime.now(timezone.utc).astimezone().isoformat(), "type": "xweather", "status": event_status, "system_name": "SLS Mass Notify System", "source_name": "Xweather Lightning API", "trigger_source": "Manual Lightning Test" if test_event else "Xweather API", "trigger_name": os.environ.get("XWEATHER_TEST_TRIGGER_NAME", "")[:80], "trigger_area_id": CURRENT_GROUP_ID, "trigger_area_name": CURRENT_GROUP_NAME, "page_group": ",".join(recipients), "desktop_targets": desktop_clients, "event": event_name, "severity": severity, "message_type": "Lightning", "audio": "Piper TTS" if sound else "None", "audio_sequence": [sound] if sound else [], "body": message, "radius_miles": settings["radius_miles"], "nearest_strike_miles": round(nearest_miles, 1) if nearest_miles is not None else None, "storm_state": state_label, "local_delivery_outcome": local_delivery_outcome, "local_delivery_error": local_delivery_error[:240], "external_destination_failures": external_failures})
    delivery_state = event_status
    call_label = "completed" if is_test else "queued"
    delivery_message = (
        f"Submitted {event_name.lower()} for area {CURRENT_GROUP_NAME}: {len(recipients)} extension(s), {len(desktop_clients)} desktop target(s); "
        f"{queued} audio call(s) {call_label}. Handset acceptance is not confirmed by Asterisk queueing."
    )
    if local_delivery_outcome == "indeterminate":
        delivery_message = "Local phone/Desktop dispatch outcome is indeterminate; the durable intent prevents replay."
    if external_failures:
        delivery_message += " One or more external destinations failed; phone and desktop delivery will not be replayed."
    record_xweather_outcome(is_test, delivery_state, delivery_message)
    log(delivery_message)
    print(delivery_message + (" External destinations were not sent." if not external_live else ""))
    return 1 if local_delivery_outcome == "indeterminate" else 0


def run_configured_group_cycle():
    try:
        _config, xweather = load_config()
    except Exception as exc:
        log(f"configuration error: {exc}")
        return 1
    test_event = os.environ.get("XWEATHER_TEST_EVENT", "").strip().lower()
    verify_only = os.environ.get("XWEATHER_VERIFY_ONLY", "0") == "1"
    requested = []
    for value in os.environ.get("XWEATHER_GROUP_IDS", "").split(","):
        identifier = re.sub(r"[^A-Za-z0-9_-]", "", value)[:64]
        if identifier and identifier not in requested:
            requested.append(identifier)
    groups = configured_groups(xweather, include_disabled=True)
    by_id = {str(group.get("id") or ""): group for group in groups}
    if requested:
        missing = [identifier for identifier in requested if identifier not in by_id]
        disabled = [identifier for identifier in requested if identifier in by_id and str(by_id[identifier].get("enabled", "0")) != "1"]
        if missing or disabled:
            log("requested Lightning trigger area selection is missing or disabled")
            return 1
        selected = [by_id[identifier] for identifier in requested]
    else:
        enabled = [group for group in groups if str(group.get("enabled", "0")) == "1"]
        selected = enabled[:1] if test_event in {"entry", "clear"} else enabled
    if not selected:
        service_enabled = str(xweather.get("enabled", "0")).strip().lower() in {"1", "true"}
        return 1 if test_event in {"entry", "clear"} or verify_only or service_enabled else 0
    results = []
    for group in selected:
        previous_group_id = os.environ.get("XWEATHER_ACTIVE_GROUP_ID")
        selected_group_id = str(group.get("id") or "")
        if selected_group_id:
            os.environ["XWEATHER_ACTIVE_GROUP_ID"] = selected_group_id
        else:
            os.environ.pop("XWEATHER_ACTIVE_GROUP_ID", None)
        try:
            results.append(main())
        finally:
            if previous_group_id is None:
                os.environ.pop("XWEATHER_ACTIVE_GROUP_ID", None)
            else:
                os.environ["XWEATHER_ACTIVE_GROUP_ID"] = previous_group_id
    return 0 if results and all(result == 0 for result in results) else 1


def cli(argv=None):
    args = list(sys.argv[1:] if argv is None else argv)
    if args in (["-h"], ["--help"]):
        print("Usage: sls_mass_notify_xweather_poll.py")
        print("Run one configured Xweather lightning polling cycle.")
        return 0
    if args:
        print(f"Unknown argument: {args[0]}", file=sys.stderr)
        print("Usage: sls_mass_notify_xweather_poll.py", file=sys.stderr)
        return 2
    WORKER_LOCK_FILE.parent.mkdir(parents=True, exist_ok=True)
    with WORKER_LOCK_FILE.open("a+", encoding="utf-8") as lock_handle:
        try:
            fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print("Another Xweather poll or manual Lightning test is already active.", file=sys.stderr)
            return 75
        try:
            return run_configured_group_cycle()
        finally:
            fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)


if __name__ == "__main__":
    raise SystemExit(cli())
