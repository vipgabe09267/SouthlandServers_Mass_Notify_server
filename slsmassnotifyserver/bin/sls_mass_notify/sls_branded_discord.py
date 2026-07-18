#!/usr/bin/env python3
"""Build and send compact branded Discord alert embeds."""

import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


DEFAULT_CONFIG = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
WEBHOOK_PATTERN = re.compile(r"https://discord(?:app)?\.com/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+")
HOST_PATTERN = re.compile(r"[A-Za-z0-9.-]+(?::[0-9]{1,5})?")


def alert_profile(subject, body, event="", severity=""):
    text = " ".join((subject, body, event, severity)).lower()
    if any(word in text for word in ("test only", "system test", "demo", "simulation", "simulated")):
        return 0x6D28D9, "🧪", "SYSTEM TEST — NOT AN ACTUAL ALERT"
    profiles = (
        (("tornado",), 0x991B1B, "🌪️", "EXTREME WEATHER"),
        (("severe thunderstorm", "thunderstorm warning", "severe storm"), 0xC2410C, "⛈️", "SEVERE WEATHER"),
        (("flash flood", "flood warning", "coastal flood"), 0x0369A1, "🌊", "FLOOD WARNING"),
        (("winter storm", "blizzard", "ice storm", "snow squall"), 0x1D4ED8, "❄️", "WINTER WEATHER"),
        (("fire warning", "red flag", "wildfire"), 0xB91C1C, "🔥", "FIRE WEATHER"),
        (("lightning",), 0xB45309, "⚡", "LIGHTNING WARNING"),
        (("test",), 0x6D28D9, "🧪", "SYSTEM TEST — NOT AN ACTUAL ALERT"),
    )
    for words, color, icon, label in profiles:
        if any(word in text for word in words):
            return color, icon, label
    return 0x6D28D9, "📢", str(severity).strip().upper() or "MASS NOTIFICATION"


def public_logo_url(config):
    host = str(config.get("public_pbx_host") or "").strip()
    sipnotify = config.get("sipnotify") if isinstance(config.get("sipnotify"), dict) else {}
    if not host:
        host = str(sipnotify.get("pbx_host") or "").strip()
    if "://" in host:
        parsed = urllib.parse.urlparse(host)
        host = parsed.netloc
    host = host.strip().strip("/")
    if not HOST_PATTERN.fullmatch(host) or host.lower() in {"localhost", "pbx"}:
        return ""
    return f"https://{host}/sls_mass_notify/assets/SLS_Mass_Notif_Email.png?v=007b"


def compact_description(body, subject):
    lines = [re.sub(r"\s+", " ", line).strip() for line in str(body).splitlines()]
    lines = [line for line in lines if line]
    description = "\n".join(lines[:4]) if lines else str(subject).strip()
    return description[:900]


def build_payload(config, subject, body, event="", severity="", fields=None, timestamp=""):
    color, icon, urgency = alert_profile(subject, body, event, severity)
    logo_url = public_logo_url(config)
    embed_fields = []
    for name, value in (fields or []):
        value = re.sub(r"\s+", " ", str(value or "")).strip()
        if value:
            embed_fields.append({"name": str(name)[:256], "value": value[:320], "inline": len(value) <= 42})
        if len(embed_fields) >= 6:
            break
    embed = {
        "author": {"name": "Southland Servers Group • SLS Mass Notification System"},
        "title": f"{icon} {str(subject).strip()}"[:256],
        "description": compact_description(body, subject),
        "color": color,
        "fields": embed_fields,
        "footer": {"text": f"{urgency} • SLS Mass Notification System"[:2048]},
        "timestamp": timestamp or datetime.now(timezone.utc).isoformat(),
    }
    if logo_url:
        embed["author"]["icon_url"] = logo_url
        embed["image"] = {"url": logo_url}
    payload = {"username": "SLS Mass Notification System", "embeds": [embed]}
    if logo_url:
        payload["avatar_url"] = logo_url
    return payload


def send_branded_discord(config, subject, body, event="", severity="", fields=None, timestamp=""):
    webhook = str(config.get("discord_webhook_url") or "").strip()
    if not WEBHOOK_PATTERN.fullmatch(webhook):
        return False
    payload = build_payload(config, subject, body, event, severity, fields, timestamp)
    request = urllib.request.Request(
        webhook,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", "User-Agent": "SouthlandServers-Mass-Notifications-Server/0.0.7-beta"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=12) as response:
            if response.status not in (200, 204):
                raise RuntimeError(f"Discord returned HTTP {response.status}")
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"Discord returned HTTP {exc.code}") from exc
    return True


def main():
    config_path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_CONFIG
    with config_path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    field_names = ("Type", "Event", "Severity", "Zone", "Radius", "Recipients", "Audio", "Trigger")
    fields = [(name, os.environ.get("SLS_DISCORD_" + name.upper(), "")) for name in field_names]
    sent = send_branded_discord(
        config,
        os.environ.get("SLS_DISCORD_SUBJECT", "Southland Servers Mass Notification"),
        os.environ.get("SLS_DISCORD_BODY", "A notification was issued."),
        os.environ.get("SLS_DISCORD_EVENT", ""),
        os.environ.get("SLS_DISCORD_SEVERITY", ""),
        fields,
        os.environ.get("SLS_DISCORD_TIME", ""),
    )
    return 0 if sent else 3


if __name__ == "__main__":
    raise SystemExit(main())
