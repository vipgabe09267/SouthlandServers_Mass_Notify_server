#!/usr/bin/env python3
"""Read and validate the SLS Mass Notify central JSON configuration."""

import json
import ipaddress
import os
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
    if (
        parsed.hostname.lower().rstrip(".") != "api.weather.gov"
        or parsed.port is not None
        or parsed.path not in {"", "/"}
    ):
        raise ValueError("Weather Alerts API must use https://api.weather.gov")
    return "https://api.weather.gov"


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
            local_part, domain = candidate.rsplit("@", 1)
            valid = (
                len(candidate) <= 254
                and len(local_part) <= 64
                and not local_part.startswith(".")
                and not local_part.endswith(".")
                and ".." not in local_part
                and normalized_sender_domain(domain) != ""
            )
        else:
            valid = False
        if valid:
            if candidate not in output:
                output.append(candidate)
        if len(output) >= 50:
            break
    return " ".join(output)


def normalized_sender_domain(value):
    domain = text(value).lower()
    if domain.startswith("@"):
        domain = domain[1:]
    domain = domain.rstrip(".")
    if not domain or len(domain) > 253 or "." not in domain:
        return ""
    try:
        ipaddress.ip_address(domain)
        return ""
    except ValueError:
        pass
    label_pattern = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?")
    return domain if all(label_pattern.fullmatch(label) for label in domain.split(".")) else ""


def normalized_sender_local_part(value):
    local_part = text(value).lower()
    if not local_part or len(local_part) > 64 or ".." in local_part:
        return ""
    pattern = re.compile(r"[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?")
    return local_part if pattern.fullmatch(local_part) else ""


def sender_address(data):
    domain = normalized_sender_domain(data.get("mail_from_domain"))
    if domain:
        return (normalized_sender_local_part(data.get("mail_from_local_part")) or "no-reply") + "@" + domain
    legacy = (emails(data.get("mail_from_addr")).split() or [""])[0]
    if legacy and normalized_sender_domain(legacy.rsplit("@", 1)[1]):
        return legacy
    return "no-reply@localhost.localdomain"


def legacy_discord_url(data):
    destinations = data.get("discord_webhooks")
    if isinstance(destinations, list) and destinations:
        for destination in destinations[:10]:
            if not isinstance(destination, dict) or enabled(destination.get("enabled", "1")) != "1":
                continue
            candidate = text(destination.get("url") or destination.get("webhook_url"))
            if re.fullmatch(r"https://(?:discord|discordapp|canary\.discord|ptb\.discord)\.com/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+", candidate):
                return candidate
        return ""
    candidate = text(data.get("discord_webhook_url"))
    return candidate if re.fullmatch(r"https://(?:discord|discordapp|canary\.discord|ptb\.discord)\.com/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+", candidate) else ""


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
    configured_desktop_recipients = {
        text(client.get("username")).lower()
        for client in (data.get("desktop_clients") or [])
        if isinstance(client, dict) and enabled(client.get("enabled")) == "1"
    }
    zone_desktop_recipients = []
    zone_email_recipients = []
    matching_zone_groups = []
    for group in data.get("nws_zones") or []:
        if not isinstance(group, dict) or text(group.get("zone")).upper() != zone:
            continue
        matching_zone_groups.append(group)
        for username in group.get("desktop_clients") or []:
            username = text(username).lower()
            if (
                re.fullmatch(r"[a-z0-9_.-]{1,48}", username)
                and username in configured_desktop_recipients
                and username not in zone_desktop_recipients
            ):
                zone_desktop_recipients.append(username)
        for recipient in emails(" ".join(map(str, group.get("email_recipients") or []))).split():
            if recipient.lower() not in {value.lower() for value in zone_email_recipients}:
                zone_email_recipients.append(recipient)
    # Pre-0.1.1-beta configurations used mail_to for both live alerts and faults.
    # Preserve that live route only until the canonical system-recipient key is
    # written; new configurations use the selected zone list exclusively.
    if "system_notification_emails" not in data:
        for recipient in emails(data.get("mail_to")).split():
            if recipient.lower() not in {value.lower() for value in zone_email_recipients}:
                zone_email_recipients.append(recipient)
            if len(zone_email_recipients) >= 50:
                break

    enabled_webhook_ids = {"discord": set(), "generic": set()}
    enabled_webhook_kinds = set()
    for kind, config_key in (("discord", "discord_webhooks"), ("generic", "generic_webhooks")):
        destinations = data.get(config_key)
        if not isinstance(destinations, list):
            continue
        for destination in destinations[:10]:
            if not isinstance(destination, dict) or enabled(destination.get("enabled", "1")) != "1":
                continue
            candidate = text(destination.get("url") or destination.get("webhook_url"))
            parsed = urlparse(candidate)
            try:
                valid_port = parsed.port in {None, 443}
            except ValueError:
                valid_port = False
            valid_url = (
                parsed.scheme == "https"
                and bool(parsed.hostname)
                and not parsed.username
                and not parsed.password
                and not parsed.fragment
                and valid_port
            )
            if kind == "discord":
                valid_url = bool(re.fullmatch(
                    r"https://(?:discord|discordapp|canary\.discord|ptb\.discord)\.com/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+",
                    candidate,
                ))
            if not valid_url:
                continue
            enabled_webhook_kinds.add(kind)
            identifier = re.sub(r"[^A-Za-z0-9_-]", "", text(destination.get("id")))[:64]
            if identifier:
                enabled_webhook_ids[kind].add(identifier)
    if not (data.get("discord_webhooks") or []) and legacy_discord_url(data):
        enabled_webhook_kinds.add("discord")
        enabled_webhook_ids["discord"].add("discord_legacy")

    zone_has_webhook_destination = False
    for group in matching_zone_groups:
        if "discord_webhook_ids" not in group and "generic_webhook_ids" not in group:
            zone_has_webhook_destination = bool(enabled_webhook_kinds)
        else:
            zone_has_webhook_destination = any(
                re.sub(r"[^A-Za-z0-9_-]", "", text(identifier))[:64] in enabled_webhook_ids[kind]
                for kind, key in (("discord", "discord_webhook_ids"), ("generic", "generic_webhook_ids"))
                for identifier in (group.get(key) if isinstance(group.get(key), list) else [])
            )
        if zone_has_webhook_destination:
            break
    worker_has_destination_override = any(text(os.environ.get(key)) for key in (
        "NWS_RECIPIENTS_OVERRIDE",
        "NWS_DESKTOP_CLIENTS_OVERRIDE",
        "NWS_EMAIL_RECIPIENTS_OVERRIDE",
        "NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE",
    ))
    if enabled(data.get("enabled")) == "1" and (
        not zone
        or not (
            recipients
            or zone_desktop_recipients
            or zone_email_recipients
            or zone_has_webhook_destination
            or worker_has_destination_override
        )
    ):
        print("NWS is enabled but its zone has no configured delivery destination", file=sys.stderr)
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
        "MAIL_TO": emails(data.get("system_notification_emails")),
        "DISCORD_WEBHOOK_URL": legacy_discord_url(data),
        "QUIET_HOURS_ENABLED": enabled(data.get("quiet_hours_enabled")),
        "QUIET_HOURS_START": clock_time(data.get("quiet_hours_start"), "21:00"),
        "QUIET_HOURS_END": clock_time(data.get("quiet_hours_end"), "06:00"),
        "MAIL_FROM_NAME": re.sub(r"[\r\n]+", " ", text(data.get("mail_from_name"), "SLS Mass Notification System"))[:80],
        "MAIL_FROM_LOCAL_PART": normalized_sender_local_part(data.get("mail_from_local_part")) or "no-reply",
        "MAIL_FROM_ADDR": sender_address(data),
        "ALERT_EMAIL_SUBJECT": text(data.get("alert_email_subject")),
        "ALERT_EMAIL_BODY": text(data.get("alert_email_body")),
        "TEST_EMAIL_SUBJECT": text(data.get("test_email_subject")),
        "TEST_EMAIL_BODY": text(data.get("test_email_body")),
        "EMAIL_HTML_ENABLED": enabled(data.get("email_html_enabled"), True),
        "AMI_USERNAME": re.sub(r"[^A-Za-z0-9_.-]", "", text(ami.get("username"), "slsmassnotify")),
        "AMI_PASSWORD": text(ami.get("password")),
        "AMI_HOST": "127.0.0.1",
        "AMI_PORT": bounded_int(ami.get("port"), 1, 65535, 5038),
        "GITHUB_UPDATES_ENABLED": enabled(updates.get("github_enabled")),
        "GITHUB_UPDATES_REPOSITORY": text(updates.get("repository"), "vipgabe09267/SouthlandServers_Mass_Notify_server"),
        "GITHUB_UPDATES_CHANNEL": "beta",
    }
    for key, value in values.items():
        emit(key, value)
    for recipient in recipients:
        emit("NWS_ALERT_RECIPIENT", recipient)
    for username in zone_desktop_recipients:
        emit("NWS_DESKTOP_CLIENT", username)
    for recipient in zone_email_recipients:
        emit("NWS_ZONE_EMAIL_RECIPIENT", recipient)
    for event in data.get("quiet_critical_events") or []:
        event = text(event)
        if event:
            emit("QUIET_HOURS_CRITICAL_EVENT", event)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
