#!/usr/bin/env python3
"""Poll Xweather lightning data and deliver deduplicated PBX alerts."""

import fcntl
import json
import math
import os
import pwd
import re
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from sls_branded_email import send_branded_email
from sls_branded_discord import send_branded_discord


DATA_DIR = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
CONFIG_FILE = Path(os.environ.get("CONFIG_FILE", DATA_DIR / "mass-notifications.config"))
STATE_FILE = Path(os.environ.get("XWEATHER_STATE_FILE", DATA_DIR / "xweather-lightning-state.json"))
STATUS_FILE = Path(os.environ.get("STATUS_FILE", DATA_DIR / "status.json"))
EVENTS_LOG = Path(os.environ.get("EVENTS_LOG", "/var/log/sls_mass_notify_events.jsonl"))
LOG_FILE = Path(os.environ.get("LOG", "/var/log/sls_mass_notify.log"))
TTS_DIR = DATA_DIR / "sounds" / "tts"
TONES_DIR = DATA_DIR / "sounds" / "tones"
SPOOL_DIR = Path("/var/spool/asterisk/outgoing")
PIPER_BIN = Path("/usr/local/bin/sls_mass_notify/piper/venv/bin/piper")
VISUAL_SCRIPT = Path("/usr/local/bin/sls_mass_notify/sls_notify.py")
SOUND_PREFIX = "SLS_Mass_Notifications_Plugin/tts"
LAST_RATE_LIMIT = {}


def log(message):
    with LOG_FILE.open("a", encoding="utf-8") as handle:
        handle.write(f"{datetime.now().astimezone().isoformat()}: Xweather: {message}\n")


def atomic_json_update(path, patch):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.seek(0)
        try:
            data = json.load(handle)
            if not isinstance(data, dict):
                data = {}
        except Exception:
            data = {}
        data.update(patch)
        handle.seek(0)
        handle.truncate(0)
        json.dump(data, handle, indent=2, sort_keys=True)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    os.chmod(path, 0o640)


def append_event(payload):
    with EVENTS_LOG.open("a", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.write(json.dumps(payload, separators=(",", ":"), ensure_ascii=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    os.chmod(EVENTS_LOG, 0o640)


def load_config():
    with CONFIG_FILE.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    if not isinstance(config, dict):
        raise ValueError("central configuration is not an object")
    xweather = config.get("xweather") if isinstance(config.get("xweather"), dict) else {}
    return config, xweather


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
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "SouthlandServers-Mass-Notifications-Server/0.0.7-beta"})
    last_error = None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
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


def rate_limit_status_patch():
    patch = {}
    if "limit" in LAST_RATE_LIMIT:
        patch["xweather_rate_limit_period"] = LAST_RATE_LIMIT["limit"]
    if "remaining" in LAST_RATE_LIMIT:
        patch["xweather_rate_remaining_period"] = LAST_RATE_LIMIT["remaining"]
    if LAST_RATE_LIMIT.get("reset_at"):
        patch["xweather_rate_reset_period"] = LAST_RATE_LIMIT["reset_at"]
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
    try:
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


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
    remaining = max(0, int(status.get("xweather_rate_remaining_period") or allowance))
    reset_marker = str(status.get("xweather_rate_reset_period") or "")[:100]
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


def queue_audio(recipients, sound):
    if not sound:
        return 0
    queued = 0
    for extension in recipients:
        body = (
            f"Channel: Local/{extension}@sls-alert-audio\n"
            "CallerID: \"SLS Lightning Alert\" <SLS>\n"
            f"Setvar: SLS_SOUND={sound}\n"
            "Setvar: SLS_CALLERID_NAME=SLS Lightning Alert\nSetvar: SLS_CALLERID_NUM=SLS\n"
            "MaxRetries: 0\nRetryTime: 5\nWaitTime: 180\nApplication: Wait\nData: 1\n"
        )
        fd, name = tempfile.mkstemp(prefix="sls_xweather_", suffix=".call", dir="/tmp", text=True)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(body)
        os.chmod(name, 0o640)
        # Web and cron delivery normally run as Asterisk. Root-driven repair or
        # validation paths must hand the call file to Asterisk before moving it
        # into the watched outgoing spool, or pbx_spool rejects it as unreadable.
        if os.geteuid() == 0:
            asterisk_account = pwd.getpwnam("asterisk")
            os.chown(name, asterisk_account.pw_uid, asterisk_account.pw_gid)
        os.replace(name, SPOOL_DIR / Path(name).name)
        queued += 1
    return queued


def send_visual(recipients, message, is_test=False):
    # ImageScreen is reliable on the legacy Yealink T48G where a long
    # TextScreen can produce a phone-side "Layout Error". Other vendors still
    # receive the normal safe text fallback from sls_notify.py.
    title = "Lightning Test" if is_test else "Lightning Alert"
    subprocess.run(["/usr/bin/python3", str(VISUAL_SCRIPT), "--announcement", message, "--announcement-image", "--announcement-title", title, "--announcement-bg-color", "#92400e", "--targets", ",".join(recipients), "--desktop-all"], check=True, timeout=30, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def send_discord(config, subject, message, event_name, severity, state_label, radius, nearest_miles=None):
    fields = [("Storm State", state_label), ("Detection Radius", f"{radius} miles")]
    if nearest_miles is not None:
        fields.append(("Nearest Strike", f"{format_miles(nearest_miles)} miles"))
    send_branded_discord(
        config,
        subject,
        message,
        event_name,
        severity,
        fields,
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


def main():
    try:
        config, xweather = load_config()
    except Exception as exc:
        log(f"configuration error: {exc}")
        return 1
    test_event = os.environ.get("XWEATHER_TEST_EVENT", "").strip().lower()
    if str(xweather.get("enabled", "0")) not in {"1", "true", "True"} and test_event not in {"entry", "clear"}:
        return 0
    client_id = str(xweather.get("client_id") or "").strip()
    client_secret = str(xweather.get("client_secret") or "").strip()
    location = str(xweather.get("location") or "").strip()
    recipients = []
    for value in xweather.get("recipients") or []:
        extension = re.sub(r"[^0-9]", "", str(value))
        if extension and extension not in recipients:
            recipients.append(extension)
    if not location or not recipients or (test_event not in {"entry", "clear"} and (not client_id or not client_secret)):
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
    state = read_state()
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
                return 0
        last_query = int(state.get("last_query", 0) or 0)
        if last_query > 0 and now - last_query < settings["query_interval_minutes"] * 60:
            if adaptive:
                atomic_json_update(STATE_FILE, state)
            return 0
        if adaptive:
            quota_allowed, reserved_cost, quota_message = quota_governor(state, now)
            if not quota_allowed:
                atomic_json_update(STATE_FILE, state)
                atomic_json_update(STATUS_FILE, {
                    "last_xweather_poll_at": datetime.now().astimezone().isoformat(),
                    "last_xweather_poll_status": "quota_guard",
                    "last_xweather_poll_message": quota_message,
                    "xweather_adaptive_mode": True,
                    "xweather_adaptive_gate_active": True,
                })
                return 0
        state["last_query"] = now
        atomic_json_update(STATE_FILE, state)
    if test_event in {"entry", "clear"}:
        records = [{"id": f"manual-{int(time.time())}", "timestamp": int(time.time()), "type": "test"}] if test_event == "entry" else []
    else:
        try:
            records = normalize_records(fetch_payload(settings))
            if adaptive:
                actual_cost = max(1, int(LAST_RATE_LIMIT.get("cost_tokens") or reserved_cost))
                adjustment = actual_cost - reserved_cost
                if adjustment:
                    state["quota_bucket_tokens"] = round(max(0.0, float(state.get("quota_bucket_tokens") or 0) - adjustment), 4)
        except Exception as exc:
            safe_error = str(exc)
            for secret in (client_id, client_secret):
                if secret:
                    safe_error = safe_error.replace(secret, "[redacted]")
            log(f"poll failed: {safe_error[:240]}")
            atomic_json_update(STATUS_FILE, {"last_xweather_poll_at": datetime.now().astimezone().isoformat(), "last_xweather_poll_status": "fault", "last_xweather_poll_message": f"Unable to reach or process the Xweather API: {safe_error[:180]}"})
            return 1
    active_before = bool(state.get("active", False))
    notified = bool(state.get("notified", False))
    empty_polls = int(state.get("empty_polls", 0) or 0)
    has_lightning = bool(records)
    nearest_miles = nearest_strike_miles(records)
    event_kind = ""
    if test_event in {"entry", "clear"}:
        event_kind = test_event
    elif has_lightning:
        if not active_before:
            state = {"active": True, "notified": False, "empty_polls": 0, "cluster_started": now, "last_query": now}
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
        return 0
    if event_kind == "":
        if test_event == "":
            atomic_json_update(STATE_FILE, state)
        return 0

    quiet = test_event == "" and quiet_hours_active(xweather)
    if quiet:
        if event_kind == "clear":
            atomic_json_update(STATE_FILE, {"active": False, "notified": False, "empty_polls": 0, "last_poll": now, "last_query": state.get("last_query", now), "last_cleared": now})
        else:
            atomic_json_update(STATE_FILE, state)
        atomic_json_update(STATUS_FILE, {"last_xweather_delivery_at": datetime.now().astimezone().isoformat(), "last_xweather_delivery_status": "skipped", "last_xweather_delivery_message": "Lightning delivery suppressed by independent lightning quiet hours."})
        return 0

    # Asterisk's FILTER() safety pass used by sls-alert-audio removes hyphens
    # on this deployment. Keep the generated sound stem to alphanumerics and
    # underscores so the call file and the file Asterisk opens stay identical.
    alert_id = f"{now}_{event_kind}"
    is_test = test_event in {"entry", "clear"}
    if event_kind == "clear":
        message = f"All clear. Lightning is now outside the configured {settings['radius_miles']}-mile radius of {location}."
        subject = "Southland Servers PBX: Lightning all clear"
        event_name = "Lightning All Clear"
        severity = "All Clear"
        state_label = "Outside radius"
    else:
        detected_miles = format_miles(nearest_miles if nearest_miles is not None else settings["radius_miles"])
        message = f"Warning. Lightning has been detected {detected_miles} miles from {location}. Please seek shelter now."
        subject = "Southland Servers PBX: Lightning detected"
        event_name = "Lightning Radius Alert"
        severity = "Warning"
        state_label = "Inside radius"
    if is_test:
        if event_kind == "clear":
            message = f"TEST ONLY. This is a simulated lightning all-clear for the configured {settings['radius_miles']}-mile radius of {location}. No actual lightning event is being reported."
        else:
            message = f"TEST ONLY. This is a simulated alert. Lightning has been detected within {settings['radius_miles']} miles of {location}. No actual lightning event is being reported."
        subject = "TEST ONLY: Southland Servers PBX Lightning system test"
        event_name = "Lightning System Test"
        severity = "Test"
        state_label = "Simulated test"
    spoken_message = build_spoken_message(event_kind, is_test, settings["radius_miles"], location, nearest_miles)
    dry_run = os.environ.get("XWEATHER_DRY_RUN", "0") == "1"
    sound = ""
    queued = 0
    try:
        if not dry_run:
            sound = generate_audio(config, xweather, spoken_message, re.sub(r"[^A-Za-z0-9_-]", "", alert_id))
            queued = queue_audio(recipients, sound)
            if sound:
                time.sleep(2)
            send_visual(recipients, message, is_test=is_test)
            send_branded_email(config, subject, message, event_name, severity)
            send_discord(config, subject, message, event_name, severity, state_label, settings["radius_miles"], nearest_miles)
        append_event({"event_id": f"xweather-{alert_id}", "logged_at": datetime.now(timezone.utc).astimezone().isoformat(), "type": "xweather", "status": "dry_run" if dry_run else "queued", "system_name": "SLS Mass Notify System", "source_name": "Xweather Lightning API", "trigger_source": "Manual Lightning Test" if test_event else "Xweather API", "trigger_name": os.environ.get("XWEATHER_TEST_TRIGGER_NAME", "")[:80], "page_group": ",".join(recipients), "event": event_name, "severity": severity, "message_type": "Lightning", "audio": "Piper TTS" if sound else "None", "audio_sequence": [sound] if sound else [], "body": message, "radius_miles": settings["radius_miles"], "nearest_strike_miles": round(nearest_miles, 1) if nearest_miles is not None else None, "storm_state": state_label})
    except Exception as exc:
        log(f"delivery failed: {exc}")
        atomic_json_update(STATUS_FILE, {"last_xweather_delivery_at": datetime.now().astimezone().isoformat(), "last_xweather_delivery_status": "fault", "last_xweather_delivery_message": str(exc)[:240]})
        if test_event == "":
            atomic_json_update(STATE_FILE, state)
        return 1
    if test_event == "":
        if event_kind == "clear":
            atomic_json_update(STATE_FILE, {"active": False, "notified": False, "empty_polls": 0, "last_poll": now, "last_query": state.get("last_query", now), "last_cleared": now})
        else:
            state["active"] = True
            state["notified"] = True
            state["empty_polls"] = 0
            state["last_notification"] = now
            atomic_json_update(STATE_FILE, state)
    atomic_json_update(STATUS_FILE, {"last_xweather_delivery_at": datetime.now().astimezone().isoformat(), "last_xweather_delivery_status": "dry_run" if dry_run else "queued", "last_xweather_delivery_message": f"Delivered {event_name.lower()} to {len(recipients)} extension(s); {queued} audio call(s) queued."})
    log(f"delivered {event_name.lower()} to {len(recipients)} extension(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
