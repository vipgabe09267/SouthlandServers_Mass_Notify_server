#!/usr/bin/env python3
"""Read and validate the SLS Mass Notify central JSON configuration."""

import json
import re
import sys
from pathlib import Path
from urllib.parse import urlparse


DEFAULT_CONFIG = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
PLUGIN_DIR = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")


def text(value, default=""):
    if value is None:
        return default
    return str(value).replace("\x00", "").strip()


def enabled(value, default=False):
    if value is None:
        return "1" if default else "0"
    return "1" if str(value).strip().lower() in {"1", "true", "yes", "on"} else "0"


def bounded_int(value, minimum, maximum, default):
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = default
    return min(maximum, max(minimum, parsed))


def scalar(value, default):
    return f"{bounded_int(value, 1, 200, default) / 100:.2f}"


def validated_https_url(value, default):
    value = text(value, default)
    parsed = urlparse(value)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError(f"invalid HTTPS URL: {value}")
    if parsed.query or parsed.fragment:
        raise ValueError(f"URL must not contain a query or fragment: {value}")
    return value.rstrip("/")


def validated_voice(value, default_name="en_US-lessac-low.onnx"):
    default = PLUGIN_DIR / "piper" / "voices" / default_name
    candidate = Path(text(value, str(default)))
    try:
        candidate.relative_to(PLUGIN_DIR / "piper" / "voices")
    except ValueError:
        return str(default)
    return str(candidate) if candidate.suffix == ".onnx" else str(default)


def emails(value):
    output = []
    for candidate in re.split(r"[\s,;]+", text(value)):
        candidate = candidate.strip()
        if re.fullmatch(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}", candidate):
            if candidate not in output:
                output.append(candidate)
    return " ".join(output)


def clock_time(value, default):
    value = text(value, default)
    if not re.fullmatch(r"(?:[01][0-9]|2[0-3]):[0-5][0-9]", value):
        return default
    return value


def emit(key, value):
    sys.stdout.buffer.write(str(key).encode("utf-8") + b"\0")
    sys.stdout.buffer.write(str(value).encode("utf-8") + b"\0")


def main():
    path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_CONFIG
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"Unable to read central config {path}: {exc}", file=sys.stderr)
        return 1
    if not isinstance(data, dict):
        print(f"Central config {path} is not a JSON object", file=sys.stderr)
        return 1

    try:
        api_url = validated_https_url(data.get("nws_api_base_url"), "https://api.weather.gov")
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 1

    zone = text(data.get("nws_zone")).upper()
    if zone and not re.fullmatch(r"[A-Z]{3}[0-9]{3}", zone):
        print(f"Invalid NWS zone in central config: {zone}", file=sys.stderr)
        return 1

    recipients = []
    for value in data.get("alert_recipients") or []:
        value = re.sub(r"[^0-9]", "", text(value))
        if value and value not in recipients:
            recipients.append(value)
    if enabled(data.get("enabled")) == "1" and (not zone or not recipients):
        print("NWS is enabled but its zone or recipient list is empty", file=sys.stderr)
        return 1

    ami = data.get("ami") if isinstance(data.get("ami"), dict) else {}
    updates = data.get("updates") if isinstance(data.get("updates"), dict) else {}
    values = {
        "NWS_ALERTS_ENABLED": enabled(data.get("enabled")),
        "PUBLIC_PBX_HOST": text(data.get("public_pbx_host")),
        "NWS_API_BASE_URL": api_url,
        "NWS_ZONE": zone,
        "SLS_OPENING_TONE": re.sub(r"[^A-Za-z0-9_-]", "", text(data.get("nws_opening_tone"), text(data.get("opening_tone"), "opening_NWS_alert"))),
        "SLS_CLOSING_TONE": re.sub(r"[^A-Za-z0-9_-]", "", text(data.get("nws_closing_tone"), "")),
        "PIPER_BIN": "/usr/local/bin/sls_mass_notify/piper/venv/bin/piper",
        "PIPER_NWS_VOICE": validated_voice(data.get("nws_piper_voice"), "en_US-amy-low.onnx"),
        "PIPER_ANNOUNCEMENT_VOICE": validated_voice(data.get("announcement_piper_voice")),
        "PIPER_NWS_VOLUME": scalar(data.get("nws_tts_volume"), 25),
        "PIPER_ANNOUNCEMENT_VOLUME": scalar(data.get("announcement_tts_volume"), 25),
        "PIPER_MAX_SECONDS": bounded_int(data.get("tts_max_seconds"), 1, 600, 30),
        "LOG_RETENTION_DAYS": bounded_int(data.get("log_retention_days"), 1, 365, 90),
        "MAIL_TO": emails(data.get("mail_to")),
        "DISCORD_WEBHOOK_URL": text(data.get("discord_webhook_url")) if re.fullmatch(r"https://discord(?:app)?\.com/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+", text(data.get("discord_webhook_url"))) else "",
        "QUIET_HOURS_ENABLED": enabled(data.get("quiet_hours_enabled")),
        "QUIET_HOURS_START": clock_time(data.get("quiet_hours_start"), "21:00"),
        "QUIET_HOURS_END": clock_time(data.get("quiet_hours_end"), "06:00"),
        "MAIL_FROM_NAME": re.sub(r"[\r\n]+", " ", text(data.get("mail_from_name"), "SLS Mass Notification System"))[:80],
        "MAIL_FROM_ADDR": (emails(data.get("mail_from_addr")).split() or ["no-reply@localhost"])[0],
        "ALERT_EMAIL_SUBJECT": text(data.get("alert_email_subject")),
        "ALERT_EMAIL_BODY": text(data.get("alert_email_body")),
        "TEST_EMAIL_SUBJECT": text(data.get("test_email_subject")),
        "TEST_EMAIL_BODY": text(data.get("test_email_body")),
        "EMAIL_HTML_ENABLED": enabled(data.get("email_html_enabled"), True),
        "AMI_USERNAME": re.sub(r"[^A-Za-z0-9_.-]", "", text(ami.get("username"), "slsmassnotify")),
        "AMI_PASSWORD": text(ami.get("password")),
        "GITHUB_UPDATES_ENABLED": enabled(updates.get("github_enabled")),
        "GITHUB_UPDATES_REPOSITORY": text(updates.get("repository"), "vipgabe09267/SouthlandServers_Mass_Notify_server"),
        "GITHUB_UPDATES_CHANNEL": "beta",
    }
    for key, value in values.items():
        emit(key, value)
    for recipient in recipients:
        emit("NWS_ALERT_RECIPIENT", recipient)
    for event in data.get("quiet_critical_events") or []:
        event = text(event)
        if event:
            emit("QUIET_HOURS_CRITICAL_EVENT", event)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
