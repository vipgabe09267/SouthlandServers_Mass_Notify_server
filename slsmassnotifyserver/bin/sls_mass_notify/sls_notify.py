#!/usr/bin/env python3
import argparse
import base64
import configparser
import html
import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
import textwrap
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import requests


BASE_DIR = Path("/usr/local/bin/sls_mass_notify")
CONFIG_FILE = BASE_DIR / "config.ini"
SEEN_FILE = BASE_DIR / "seen_alerts.json"
LOCAL_TZ = ZoneInfo(os.environ.get("TZ") or "UTC")
DEFAULT_API_EVENTS_FILE = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl")

ALERT_MAP = {
    "Tornado Warning": ("⚠ TORNADO WARNING", "yes"),
    "Tornado Watch": ("⚠ TORNADO WATCH", "yes"),
    "Tornado Emergency": ("⚠ TORNADO EMERGENCY", "yes"),
    "Severe Thunderstorm Warning": ("⚠ SVR TSTORM WARNING", "yes"),
    "Severe Thunderstorm Watch": ("⚠ SVR TSTORM WATCH", "yes"),
    "Flash Flood Emergency": ("⚠ FLASH FLOOD EMERG", "yes"),
    "Flash Flood Warning": ("⚠ FLASH FLOOD WARNING", "yes"),
    "Flash Flood Watch": ("⚠ FLASH FLOOD WATCH", "yes"),
    "Flood Warning": ("⚠ FLOOD WARNING", "no"),
    "Flood Watch": ("⚠ FLOOD WATCH", "yes"),
    "Red Flag Warning": ("⚠ RED FLAG WARNING", "yes"),
    "Fire Weather Watch": ("⚠ FIRE WEATHER WATCH", "no"),
    "Winter Storm Warning": ("⚠ WINTER STORM WARNING", "yes"),
    "Winter Storm Watch": ("⚠ WINTER STORM WATCH", "no"),
    "Ice Storm Warning": ("⚠ ICE STORM WARNING", "yes"),
    "High Wind Warning": ("⚠ HIGH WIND WARNING", "yes"),
    "High Wind Watch": ("⚠ HIGH WIND WATCH", "no"),
    "Excessive Heat Warning": ("⚠ EXCESS HEAT WARNING", "yes"),
    "Extreme Heat Warning": ("⚠ EXTREME HEAT WARNING", "yes"),
    "Extreme Heat Watch": ("⚠ EXTREME HEAT WATCH", "no"),
    "Dust Storm Warning": ("⚠ DUST STORM WARNING", "yes"),
    "Hurricane Warning": ("⚠ HURRICANE WARNING", "no"),
    "Hurricane Watch": ("⚠ HURRICANE WATCH", "no"),
    "Tropical Storm Warning": ("⚠ TROPICAL STORM WARN", "yes"),
    "Tropical Storm Watch": ("⚠ TROPICAL STORM WATCH", "no"),
    "Storm Surge Warning": ("⚠ STORM SURGE WARNING", "no"),
    "Tsunami Warning": ("⚠ TSUNAMI WARNING", "no"),
    "Earthquake Warning": ("⚠ EARTHQUAKE WARNING", "no"),
    "Civil Danger Warning": ("⚠ CIVIL DANGER WARNING", "yes"),
    "Hazardous Materials Warning": ("⚠ HAZMAT WARNING", "yes"),
    "Nuclear Power Plant Warning": ("⚠ NUCLEAR WARNING", "no"),
    "Law Enforcement Warning": ("⚠ LAW ENFORCEMENT WARN", "yes"),
    "Evacuation Warning": ("⚠ EVACUATION WARNING", "yes"),
    "Evacuation Immediate": ("⚠ EVACUATE NOW", "yes"),
}

DEFAULT_ALERT = ("⚠ NWS ALERT", "yes")

CRITICAL_EVENTS = {
    "Tornado Warning",
    "Tornado Emergency",
    "Flash Flood Emergency",
    "Flash Flood Warning",
    "Flood Warning",
    "Evacuation Immediate",
    "Civil Danger Warning",
    "Hazardous Materials Warning",
    "Law Enforcement Warning",
    "Nuclear Power Plant Warning",
    "Earthquake Warning",
    "Tsunami Warning",
}

URGENT_EVENTS = {
    "Tornado Watch",
    "Severe Thunderstorm Warning",
    "Red Flag Warning",
    "Winter Storm Warning",
    "Ice Storm Warning",
    "High Wind Warning",
    "Excessive Heat Warning",
    "Extreme Heat Warning",
    "Dust Storm Warning",
    "Hurricane Warning",
    "Tropical Storm Warning",
    "Storm Surge Warning",
    "Evacuation Warning",
}

ALERT_COLORS = {
    "critical": {
        "label": "CRITICAL",
        "background": "#991b1b",
        "header": "#7f1d1d",
        "accent": "#fecaca",
        "text": "#ffffff",
    },
    "urgent": {
        "label": "URGENT",
        "background": "#c2410c",
        "header": "#9a3412",
        "accent": "#fed7aa",
        "text": "#ffffff",
    },
    "notice": {
        "label": "ADVISORY",
        "background": "#ca8a04",
        "header": "#a16207",
        "accent": "#fef08a",
        "text": "#111827",
    },
}


class AmiClient:
    def __init__(self, host, port, username, password, timeout=10):
        self.host = host
        self.port = int(port)
        self.username = username
        self.password = password
        self.timeout = timeout
        self.sock = None
        self.file = None

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, exc_type, exc, tb):
        self.close()

    def connect(self):
        self.sock = socket.create_connection((self.host, self.port), self.timeout)
        self.sock.settimeout(self.timeout)
        self.file = self.sock.makefile("r", encoding="utf-8", newline="\r\n")
        self.file.readline()
        response, _ = self.action({
            "Action": "Login",
            "Username": self.username,
            "Secret": self.password,
            "Events": "off",
        })
        if response.get("Response", "").lower() != "success":
            raise RuntimeError(response.get("Message", "AMI login failed"))

    def close(self):
        try:
            if self.sock:
                self.send({"Action": "Logoff"})
        except Exception:
            pass
        try:
            if self.file:
                self.file.close()
        except Exception:
            pass
        try:
            if self.sock:
                self.sock.close()
        except Exception:
            pass

    def send(self, fields):
        lines = []
        for key, value in fields.items():
            if isinstance(value, list):
                for item in value:
                    lines.append(f"{key}: {item}")
            else:
                lines.append(f"{key}: {value}")
        data = "\r\n".join(lines) + "\r\n\r\n"
        self.sock.sendall(data.encode("utf-8"))

    def read_message(self):
        message = {}
        while True:
            line = self.file.readline()
            if line == "":
                raise RuntimeError("AMI connection closed")
            line = line.rstrip("\r\n")
            if line == "":
                return message
            if ":" not in line:
                continue
            key, value = line.split(":", 1)
            value = value.lstrip()
            if key in message:
                existing = message[key]
                if isinstance(existing, list):
                    existing.append(value)
                else:
                    message[key] = [existing, value]
            else:
                message[key] = value

    def action(self, fields, complete_event=None):
        self.send(fields)
        response = {}
        events = []
        while True:
            message = self.read_message()
            if not response and "Response" in message:
                response = message
                if complete_event is None:
                    return response, events
            elif "Event" in message:
                events.append(message)
                if complete_event and message.get("Event") == complete_event:
                    return response, events


def load_config():
    config = configparser.ConfigParser()
    if not config.read(CONFIG_FILE):
        raise RuntimeError(f"Unable to read {CONFIG_FILE}")
    return config


def setup_logging(log_file):
    log_format = "%(asctime)s %(levelname)s: %(message)s"
    date_format = "%Y-%m-%d %H:%M:%S"
    try:
        logging.basicConfig(
            filename=log_file,
            level=logging.INFO,
            format=log_format,
            datefmt=date_format,
        )
    except OSError as exc:
        logging.basicConfig(
            stream=sys.stderr,
            level=logging.INFO,
            format=log_format,
            datefmt=date_format,
        )
        logging.warning("Unable to open log file %s; logging to stderr: %s", log_file, exc)


def parse_time(value):
    if not value:
        return None
    try:
        if value.endswith("Z"):
            value = value[:-1] + "+00:00"
        return datetime.fromisoformat(value).astimezone(timezone.utc)
    except ValueError:
        return None


def format_time(value):
    dt = parse_time(value)
    if dt is None:
        return "Unknown"
    local = dt.astimezone(LOCAL_TZ)
    return local.strftime("%I:%M %p %Z").lstrip("0")


def truncate(value, length):
    value = " ".join((value or "").split())
    return value[:length]


def description_lines(description):
    cleaned = " ".join((description or "").split())
    excerpt = cleaned[:56]
    lines = textwrap.wrap(excerpt, width=28, break_long_words=False, break_on_hyphens=False)
    while len(lines) < 2:
        lines.append("")
    return lines[:2]


def alert_title(event):
    return ALERT_MAP.get(event, DEFAULT_ALERT)[0].replace("⚠ ", "").strip()


def alert_beep(event):
    return ALERT_MAP.get(event, DEFAULT_ALERT)[1]


def alert_priority(props):
    event = props.get("event", "")
    if event in CRITICAL_EVENTS:
        return "critical"
    if event in URGENT_EVENTS:
        return "urgent"
    return "notice"


def image_text_lines(alert):
    props = alert.get("properties", {})
    event = props.get("event", "")
    description = " ".join((props.get("description") or "").split())
    desc_lines = textwrap.wrap(description[:92], width=34, break_long_words=False, break_on_hyphens=False)
    while len(desc_lines) < 3:
        desc_lines.append("")
    return [
        alert_title(event),
        f"Severity: {truncate(props.get('severity', 'Unknown'), 18)}",
        f"Issued: {format_time(props.get('effective'))}",
        f"Until:  {format_time(props.get('expires'))}",
        f"Area: {truncate(props.get('areaDesc', ''), 38)}",
        desc_lines[0],
        desc_lines[1],
        desc_lines[2],
    ]


def safe_alert_filename(alert):
    import hashlib
    raw = alert.get("id") or alert.get("properties", {}).get("id") or str(time.time())
    return "alert_" + hashlib.sha256(raw.encode("utf-8")).hexdigest()[:24] + ".png"


def compact_identifier(value):
    return (value or "").strip().split("/")[-1]


def alert_chain_key(alert):
    props = alert.get("properties", {})
    alert_id = compact_identifier(alert.get("id") or props.get("id"))
    event = (props.get("event") or "").strip()
    severity = (props.get("severity") or "").strip()
    references = props.get("references") or []
    reference_id = ""

    for ref in references:
        if not isinstance(ref, dict):
            continue
        if (ref.get("event") or "").strip() == event:
            reference_id = compact_identifier(ref.get("identifier"))
            if reference_id:
                break
    if not reference_id:
        for ref in references:
            if isinstance(ref, dict):
                reference_id = compact_identifier(ref.get("identifier"))
                if reference_id:
                    break

    return f"{event}|{severity}|{reference_id or alert_id}"


def render_alert_image(config, alert):
    props = alert.get("properties", {})
    priority = alert_priority(props)
    colors = ALERT_COLORS[priority]
    width = config.getint("visual", "image_width", fallback=480)
    height = config.getint("visual", "image_height", fallback=272)
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    public_base_url = config.get("visual", "public_base_url", fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    web_dir.mkdir(parents=True, exist_ok=True)
    output = web_dir / safe_alert_filename(alert)
    lines = image_text_lines(alert)

    command = [
        "convert",
        "-size", f"{width}x{height}", f"xc:{colors['background']}",
        "-fill", colors["header"], "-draw", f"rectangle 0,0 {width},70",
        "-fill", colors["accent"], "-draw", f"rectangle 0,70 {width},78",
        "-font", "Helvetica-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "30", "-annotate", "+0+16", colors["label"],
        "-font", "Helvetica-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "28", "-annotate", "+0+82", lines[0],
        "-font", "Helvetica", "-fill", "#ffffff", "-gravity", "NorthWest",
        "-pointsize", "18", "-annotate", "+28+124", lines[1],
        "-pointsize", "18", "-annotate", "+28+148", lines[2],
        "-pointsize", "18", "-annotate", "+28+172", lines[3],
        "-pointsize", "17", "-annotate", "+28+196", lines[4],
        "-font", "Helvetica-Bold", "-fill", colors["accent"], "-gravity", "SouthWest",
        "-pointsize", "17", "-annotate", "+28+30", lines[5],
        "-pointsize", "17", "-annotate", "+28+10", lines[6],
        str(output),
    ]
    result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
    if result.returncode != 0:
        raise RuntimeError((result.stderr or "ImageMagick convert failed").strip())
    os.chmod(output, 0o644)
    try:
        import grp
        import pwd
        os.chown(output, pwd.getpwnam("asterisk").pw_uid, grp.getgrnam("asterisk").gr_gid)
    except Exception:
        pass
    prune_old_images(web_dir)
    return f"{public_base_url}/{output.name}"


def prune_old_images(web_dir, max_age_seconds=259200):
    cutoff = time.time() - max_age_seconds
    for path in web_dir.glob("alert_*.png"):
        try:
            if path.stat().st_mtime < cutoff:
                path.unlink()
                logging.info("Removed old generated alert image %s", path)
        except Exception as exc:
            logging.warning("Unable to remove old generated alert image %s: %s", path, exc)


def build_image_xml(alert, image_url):
    event = alert.get("properties", {}).get("event", "")
    beep = alert_beep(event)
    return (
        "<?xml version='1.0' encoding='ISO-8859-1'?>"
        f"<YealinkIPPhoneImageScreen destroyOnExit='no' Beep='{html.escape(beep)}' Timeout='0' LockIn='no' mode='fullscreen'>"
        f"<Image horizontalAlign='middle' verticalAlign='middle'>{html.escape(image_url)}</Image>"
        "</YealinkIPPhoneImageScreen>"
    )


def build_text_xml(alert):
    props = alert.get("properties", {})
    event = props.get("event", "")
    title = alert_title(event)
    beep = alert_beep(event)
    lines = image_text_lines(alert)
    text_lines = [
        lines[1],
        lines[2],
        lines[3],
        lines[4],
        lines[5],
        lines[6],
    ]
    escaped_text = "&#10;".join(html.escape(line) for line in text_lines)
    return (
        f"<YealinkIPPhoneTextScreen Beep='{html.escape(beep)}' Timeout='0'>"
        f"<Title wrap='yes'>{html.escape(title)}</Title>"
        f"<Text>{escaped_text}</Text>"
        "<SoftKey index='1'>"
        "<Label>Dismiss</Label>"
        "<URI>SoftKey:Exit</URI>"
        "</SoftKey>"
        "</YealinkIPPhoneTextScreen>"
    )


def build_announcement_xml(message):
    cleaned = "\n".join(line.strip() for line in (message or "").splitlines()).strip()
    if not cleaned:
        cleaned = "Announcement"
    wrapped = []
    for paragraph in cleaned.splitlines():
        wrapped.extend(textwrap.wrap(paragraph, width=32, break_long_words=False, break_on_hyphens=False) or [""])
    escaped_text = "&#10;".join(html.escape(line) for line in wrapped[:10])
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<YealinkIPPhoneTextScreen Beep='yes' Timeout='0'>"
        "<Title>Announcement</Title>"
        f"<Text>{escaped_text}</Text>"
        "<SoftKey index='1'>"
        "<Label>Dismiss</Label>"
        "<URI>SoftKey:Exit</URI>"
        "</SoftKey>"
        "</YealinkIPPhoneTextScreen>"
    )


def normalize_hex_color(value, fallback="#1f2937"):
    value = (value or "").strip()
    if re.match(r"^#[0-9A-Fa-f]{6}$", value):
        return value
    return fallback


def render_announcement_image(config, title, message, background_color="#1f2937", background_image=""):
    width = config.getint("visual", "image_width", fallback=480)
    height = config.getint("visual", "image_height", fallback=272)
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    public_base_url = config.get("visual", "public_base_url", fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    web_dir.mkdir(parents=True, exist_ok=True)

    title = (title or "Announcement").strip()[:48] or "Announcement"
    cleaned = " ".join((message or "").split()).strip() or "Announcement"
    body_lines = textwrap.wrap(cleaned, width=34, break_long_words=False, break_on_hyphens=False)[:5]
    while len(body_lines) < 5:
        body_lines.append("")

    image_id = re.sub(r"[^A-Za-z0-9_-]+", "_", f"{title}_{cleaned}")[:80] or "announcement"
    output = web_dir / ("announcement_" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S") + "_" + str(abs(hash(image_id)))[:10] + ".png")
    background_color = normalize_hex_color(background_color)
    background_image_path = Path(background_image) if background_image else None

    if background_image_path and background_image_path.is_file():
        command = [
            "convert", str(background_image_path),
            "-auto-orient", "-resize", f"{width}x{height}^", "-gravity", "center", "-extent", f"{width}x{height}",
            "-fill", "rgba(0,0,0,0.45)", "-draw", f"rectangle 0,0 {width},{height}",
            "-fill", "rgba(17,24,39,0.82)", "-draw", f"rectangle 0,0 {width},72",
        ]
    else:
        command = [
            "convert",
            "-size", f"{width}x{height}", f"xc:{background_color}",
            "-fill", "rgba(17,24,39,0.88)", "-draw", f"rectangle 0,0 {width},72",
        ]

    command.extend([
        "-fill", "#fbbf24", "-draw", f"rectangle 0,72 {width},78",
        "-font", "Helvetica-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "30", "-annotate", "+0+18", title,
        "-font", "Helvetica", "-fill", "#ffffff", "-gravity", "NorthWest",
        "-pointsize", "20", "-annotate", "+30+108", body_lines[0],
        "-pointsize", "20", "-annotate", "+30+136", body_lines[1],
        "-pointsize", "20", "-annotate", "+30+164", body_lines[2],
        "-pointsize", "20", "-annotate", "+30+192", body_lines[3],
        "-pointsize", "20", "-annotate", "+30+220", body_lines[4],
        str(output),
    ])
    result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
    if result.returncode != 0:
        raise RuntimeError((result.stderr or "ImageMagick convert failed").strip())
    os.chmod(output, 0o644)
    try:
        import grp
        import pwd
        os.chown(output, pwd.getpwnam("asterisk").pw_uid, grp.getgrnam("asterisk").gr_gid)
    except Exception:
        pass
    prune_old_images(web_dir)
    return f"{public_base_url}/{output.name}"


def build_announcement_image_xml(config, message, title="Announcement", background_color="#1f2937", background_image=""):
    image_url = render_announcement_image(config, title, message, background_color, background_image)
    return (
        "<?xml version='1.0' encoding='ISO-8859-1'?>"
        "<YealinkIPPhoneImageScreen destroyOnExit='no' Beep='yes' Timeout='0' LockIn='no' mode='fullscreen'>"
        f"<Image horizontalAlign='middle' verticalAlign='middle'>{html.escape(image_url)}</Image>"
        "</YealinkIPPhoneImageScreen>"
    )


def build_xml(config, alert):
    try:
        return build_image_xml(alert, render_alert_image(config, alert))
    except Exception as exc:
        logging.error("Unable to render alert image; falling back to text XML: %s", exc)
        return build_text_xml(alert)


def load_seen():
    if not SEEN_FILE.exists():
        return {}
    try:
        with SEEN_FILE.open() as handle:
            data = json.load(handle)
        if isinstance(data, dict):
            return data
    except Exception as exc:
        logging.warning("Unable to read seen alert file %s: %s", SEEN_FILE, exc)
    return {}


def save_seen(seen):
    tmp = SEEN_FILE.with_suffix(".tmp")
    with tmp.open("w") as handle:
        json.dump(seen, handle, indent=2, sort_keys=True)
    os.replace(tmp, SEEN_FILE)


def cleanup_seen(seen, active_ids):
    now = datetime.now(timezone.utc)
    cleaned = {}
    for alert_id, expires in seen.items():
        expires_dt = parse_time(expires)
        if alert_id in active_ids or expires_dt is None or expires_dt > now:
            cleaned[alert_id] = expires
        else:
            logging.info("Removed expired alert from seen cache: %s", alert_id)
    return cleaned


def fetch_alerts(zone):
    url = f"https://api.weather.gov/alerts/active?zone={zone}"
    headers = {"User-Agent": "SouthlandServers-FreePBX-NWS-VisualPush/1.0"}
    response = requests.get(url, headers=headers, timeout=20)
    response.raise_for_status()
    data = response.json()
    return data.get("features", [])


def normalize_target_list(value):
    targets = set()
    for item in (value or "").replace(";", ",").split(","):
        item = item.strip()
        if item.isdigit():
            targets.add(item)
    return targets


def get_registered_extensions(ami, allowed_targets=None):
    response, events = ami.action({"Action": "PJSIPShowContacts"}, "ContactListComplete")
    if response.get("Response", "").lower() == "error":
        raise RuntimeError(response.get("Message", "PJSIPShowContacts failed"))
    allowed_targets = set(allowed_targets or [])
    extensions = {}
    skipped = []
    for event in events:
        if event.get("Event") != "ContactList":
            continue
        endpoint = (event.get("Endpoint") or "").strip()
        status = (event.get("Status") or "").strip()
        if not endpoint:
            continue
        if allowed_targets and endpoint not in allowed_targets:
            logging.info("Skipping endpoint %s because it is not in the requested target list", endpoint)
            continue
        if status == "Reachable" or status == "NonQualified":
            extensions[endpoint] = status
        else:
            skipped.append((endpoint, status or "Unknown"))
    for endpoint, status in sorted(skipped):
        logging.info("Skipping unregistered/unreachable endpoint %s status=%s", endpoint, status)
    return sorted(extensions)


def send_notify(ami, endpoint, xml_payload):
    response, _ = ami.action({
        "Action": "PJSIPNotify",
        "Endpoint": endpoint,
        "Variable": [
            "Event=Yealink-xml",
            "Content-Type=application/xml",
            f"Content={xml_payload}",
        ],
    })
    if response.get("Response", "").lower() == "error":
        raise RuntimeError(response.get("Message", "PJSIPNotify failed"))
    return response.get("Message", "sent")


def visual_retry_delays(config):
    raw = config.get("visual", "retry_delays", fallback="")
    delays = []
    for item in raw.split(","):
        item = item.strip()
        if not item:
            continue
        try:
            delay = int(item)
        except ValueError:
            logging.warning("Ignoring invalid visual retry delay %r", item)
            continue
        if delay > 0:
            delays.append(delay)
    return sorted(set(delays))


def api_events_file(config):
    return Path(config.get("api", "events_file", fallback=str(DEFAULT_API_EVENTS_FILE)))


def image_url_from_xml(xml_payload):
    match = re.search(r"<Image[^>]*>(.*?)</Image>", xml_payload)
    if not match:
        return ""
    return html.unescape(match.group(1)).strip()


def append_sipnotify_event(config, record):
    path = api_events_file(config)
    path.parent.mkdir(parents=True, exist_ok=True)
    record = dict(record)
    record["created_at"] = datetime.now(timezone.utc).astimezone().isoformat()
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=True, separators=(",", ":")) + "\n")
    try:
        import grp
        import pwd
        os.chown(path, pwd.getpwnam("asterisk").pw_uid, grp.getgrnam("asterisk").gr_gid)
    except Exception:
        pass
    os.chmod(path, 0o640)


def alert_api_record(alert, xml_payload, extensions):
    props = alert.get("properties", {})
    event = props.get("event", "Unknown")
    alert_id = alert.get("id") or props.get("id") or "unknown"
    priority = alert_priority(props)
    return {
        "kind": "alert",
        "id": alert_id,
        "chain_key": alert_chain_key(alert),
        "event": event,
        "title": alert_title(event),
        "priority": priority,
        "priority_label": ALERT_COLORS[priority]["label"],
        "beep": alert_beep(event),
        "severity": props.get("severity", ""),
        "message_type": props.get("messageType", ""),
        "area": props.get("areaDesc", ""),
        "effective": props.get("effective", ""),
        "expires": props.get("expires", ""),
        "description": props.get("description", ""),
        "image_url": image_url_from_xml(xml_payload),
        "xml": xml_payload,
        "recipients": extensions,
    }


def announcement_api_record(alert_id, message, xml_payload, extensions):
    return {
        "kind": "announcement",
        "id": alert_id,
        "event": "Announcement",
        "title": "Announcement",
        "priority": "notice",
        "priority_label": ALERT_COLORS["notice"]["label"],
        "beep": "yes",
        "body": message,
        "text": message,
        "description": message,
        "message": message,
        "image_url": image_url_from_xml(xml_payload),
        "xml": xml_payload,
        "recipients": extensions,
    }


def send_notify_batch(ami, extensions, xml_payload, alert_id, print_results=False, attempt_label="initial"):
    for extension in extensions:
        try:
            result = send_notify(ami, extension, xml_payload)
            logging.info("Pushed visual alert %s to %s attempt=%s: %s", alert_id, extension, attempt_label, result)
            if print_results:
                print(f"{extension}: success ({attempt_label})")
        except Exception as exc:
            logging.error("Failed visual alert %s to %s attempt=%s: %s", alert_id, extension, attempt_label, exc)
            if print_results:
                print(f"{extension}: failed ({attempt_label}) - {exc}")


def push_alert(config, alert, print_results=False, retries=True, targets=None, api_publish=True):
    xml_payload = build_xml(config, alert)
    props = alert.get("properties", {})
    event = props.get("event", "Unknown")
    priority = alert_priority(props)
    alert_id = alert.get("id") or props.get("id") or "unknown"
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
    ) as ami:
        extensions = get_registered_extensions(ami, targets)
        logging.info("Pushing %s alert %s priority=%s to %d registered endpoints", event, alert_id, priority, len(extensions))
        if api_publish:
            append_sipnotify_event(config, alert_api_record(alert, xml_payload, extensions))
        send_notify_batch(ami, extensions, xml_payload, alert_id, print_results, "initial")
        if not retries:
            return
        for delay in visual_retry_delays(config):
            logging.info("Waiting %s seconds before visual alert retry for %s", delay, alert_id)
            time.sleep(delay)
            send_notify_batch(ami, extensions, xml_payload, alert_id, print_results, f"retry+{delay}s")


def push_announcement(config, message, targets, print_results=True, api_publish=True, api_only=False, image=False, title="Announcement", background_color="#1f2937", background_image=""):
    if image:
        xml_payload = build_announcement_image_xml(config, message, title, background_color, background_image)
    else:
        xml_payload = build_announcement_xml(message)
    alert_id = "announcement-" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    if api_only:
        append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, []))
        return []
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
    ) as ami:
        extensions = get_registered_extensions(ami, targets)
        logging.info("Pushing SIP NOTIFY announcement %s to %d registered endpoints", alert_id, len(extensions))
        if api_publish:
            append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, extensions))
        send_notify_batch(ami, extensions, xml_payload, alert_id, print_results, "initial")
        return extensions


def synthetic_alert(event, severity, area, description, expires_minutes, test_id):
    now = datetime.now(timezone.utc)
    return {
        "id": test_id or ("pbx-test-" + event.lower().replace(" ", "-") + "-" + now.strftime("%Y%m%d%H%M%S")),
        "properties": {
            "event": event,
            "severity": severity,
            "urgency": "Immediate" if severity.lower() in {"extreme", "severe"} else "Expected",
            "messageType": "Alert",
            "effective": now.isoformat(),
            "expires": (now + timedelta(minutes=expires_minutes)).isoformat(),
            "areaDesc": area,
            "description": description,
        },
    }


def alert_from_json_b64(value):
    try:
        decoded = base64.b64decode(value.encode("ascii"), validate=True)
        alert = json.loads(decoded.decode("utf-8"))
    except Exception as exc:
        raise RuntimeError(f"Unable to decode alert JSON: {exc}") from exc

    if not isinstance(alert, dict) or not isinstance(alert.get("properties"), dict):
        raise RuntimeError("Decoded alert JSON is not a valid NWS alert feature")
    return alert


def run_test(config, event="Tornado Warning", severity="Extreme", area="Williamson County TX", description="", retries=True):
    if not description:
        description = "PBX test visual alert. This image was generated by the FreePBX Mass Notifications testing page."
    alert = {
        "id": "test-tornado-warning",
        "properties": {
            "event": event,
            "severity": severity,
            "effective": datetime.now(timezone.utc).isoformat(),
            "expires": datetime.now(timezone.utc).replace(minute=59).isoformat(),
            "areaDesc": area,
            "description": description,
        },
    }
    push_alert(config, alert, print_results=True, retries=retries)


def poll_once(config):
    zone = config["nws"].get("zone", "TXZ163")
    seen = load_seen()
    try:
        alerts = fetch_alerts(zone)
    except Exception as exc:
        logging.error("NWS API failure for zone %s: %s", zone, exc)
        return

    now = datetime.now(timezone.utc)
    active_ids = set()
    new_alerts = []
    for alert in alerts:
        props = alert.get("properties", {})
        alert_id = (alert.get("id") or props.get("id") or "").strip()
        if not alert_id:
            logging.warning("Skipping alert without ID: %s", props.get("event", "Unknown"))
            continue
        status = (props.get("status") or "").strip()
        msg_type = (props.get("messageType") or "").strip()
        if status and status != "Actual":
            logging.info("Skipping non-actual alert %s event=%s status=%s", alert_id, props.get("event", "Unknown"), status)
            continue
        if msg_type == "Cancel":
            logging.info("Skipping cancelled alert %s event=%s", alert_id, props.get("event", "Unknown"))
            continue
        expires = props.get("expires") or ""
        expires_dt = parse_time(expires)
        if expires_dt is not None and expires_dt <= now:
            logging.info("Skipping expired active-feed alert %s event=%s expires=%s", alert_id, props.get("event", "Unknown"), expires)
            continue
        chain_key = alert_chain_key(alert)
        active_ids.add(chain_key)
        if chain_key not in seen:
            new_alerts.append(alert)

    seen = cleanup_seen(seen, active_ids)

    if not new_alerts:
        save_seen(seen)
        logging.info("NWS poll complete: no new active alerts for %s", zone)
        return

    for alert in new_alerts:
        props = alert.get("properties", {})
        alert_id = alert.get("id") or props.get("id") or "unknown"
        chain_key = alert_chain_key(alert)
        try:
            push_alert(config, alert)
            seen[chain_key] = props.get("expires") or ""
            save_seen(seen)
        except Exception as exc:
            logging.error("Visual alert push failed for %s: %s", alert_id, exc)


def run_daemon(config):
    interval = max(10, config["nws"].getint("poll_interval", 60))
    logging.info("Starting NWS visual push daemon for zone %s interval=%s", config["nws"].get("zone", "TXZ163"), interval)
    while True:
        try:
            poll_once(config)
        except Exception as exc:
            logging.exception("Unexpected daemon error: %s", exc)
        time.sleep(interval)


def main():
    parser = argparse.ArgumentParser(description="NWS visual alert push for Yealink phones")
    parser.add_argument("--test", action="store_true", help="Send a fake Tornado Warning to registered phones and exit")
    parser.add_argument("--event", default="", help="Synthetic alert event to push, for PBX tests")
    parser.add_argument("--severity", default="Severe", help="Synthetic alert severity")
    parser.add_argument("--area", default="Williamson County TX", help="Synthetic alert area")
    parser.add_argument("--description", default="", help="Synthetic alert description")
    parser.add_argument("--expires-minutes", type=int, default=45, help="Synthetic alert expiration in minutes")
    parser.add_argument("--test-id", default="", help="Synthetic alert ID")
    parser.add_argument("--alert-json-b64", default="", help="Base64-encoded NWS alert feature JSON to push once")
    parser.add_argument("--targets", default="", help="Comma-separated endpoint list to notify")
    parser.add_argument("--announcement", default="", help="Send this text-only SIP NOTIFY announcement and exit")
    parser.add_argument("--announcement-image", action="store_true", help="Render announcement as a Yealink image screen")
    parser.add_argument("--announcement-title", default="Announcement", help="Announcement image title")
    parser.add_argument("--announcement-bg-color", default="#1f2937", help="Announcement image background color")
    parser.add_argument("--announcement-bg-image", default="", help="Announcement image background file")
    parser.add_argument("--no-api", action="store_true", help="Do not publish this announcement/alert to the desktop API journal")
    parser.add_argument("--api-only", action="store_true", help="Publish announcement to the desktop API journal without sending SIP NOTIFY")
    parser.add_argument("--no-retry", action="store_true", help="Do not send delayed visual retries")
    args = parser.parse_args()

    try:
        config = load_config()
        setup_logging(config["logging"].get("log_file", "/var/log/sls_mass_notify_push.log"))
        targets = normalize_target_list(args.targets)
        if args.announcement:
            push_announcement(
                config,
                args.announcement,
                targets,
                print_results=True,
                api_publish=not args.no_api,
                api_only=args.api_only,
                image=args.announcement_image,
                title=args.announcement_title,
                background_color=args.announcement_bg_color,
                background_image=args.announcement_bg_image,
            )
        elif args.alert_json_b64:
            alert = alert_from_json_b64(args.alert_json_b64)
            push_alert(config, alert, print_results=True, retries=not args.no_retry, targets=targets, api_publish=not args.no_api)
        elif args.event:
            description = args.description or "PBX test visual alert. This image was generated by the FreePBX Mass Notifications testing page."
            alert = synthetic_alert(args.event, args.severity, args.area, description, args.expires_minutes, args.test_id)
            push_alert(config, alert, print_results=True, retries=not args.no_retry, targets=targets, api_publish=not args.no_api)
        elif args.test:
            run_test(config, retries=not args.no_retry)
        else:
            run_daemon(config)
    except Exception as exc:
        logging.error("Fatal startup error: %s", exc)
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
