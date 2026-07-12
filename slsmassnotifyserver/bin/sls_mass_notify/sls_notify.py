#!/usr/bin/env python3
import argparse
import base64
import configparser
import fcntl
import hashlib
import html
import json
import logging
import os
import re
import secrets
import socket
import subprocess
import sys
import time
import textwrap
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

CENTRAL_SETTINGS_FILE = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
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
            key = str(key)
            if not key or "\r" in key or "\n" in key or ":" in key:
                raise ValueError("Invalid AMI field name")
            if isinstance(value, list):
                for item in value:
                    item = str(item)
                    if "\r" in item or "\n" in item:
                        raise ValueError(f"AMI field {key} contains a line break")
                    lines.append(f"{key}: {item}")
            else:
                value = str(value)
                if "\r" in value or "\n" in value:
                    raise ValueError(f"AMI field {key} contains a line break")
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
    config = configparser.ConfigParser(interpolation=None)
    if CENTRAL_SETTINGS_FILE.is_file():
        try:
            settings = json.loads(CENTRAL_SETTINGS_FILE.read_text(encoding="utf-8"))
        except Exception as exc:
            raise RuntimeError(f"Unable to read central config {CENTRAL_SETTINGS_FILE}: {exc}") from exc
        if not isinstance(settings, dict):
            raise RuntimeError(f"Central config {CENTRAL_SETTINGS_FILE} is not a JSON object")

        ami = settings.get("ami") if isinstance(settings.get("ami"), dict) else {}
        sipnotify = settings.get("sipnotify") if isinstance(settings.get("sipnotify"), dict) else {}
        host = str(settings.get("public_pbx_host") or sipnotify.get("pbx_host") or "localhost").strip()
        host = re.sub(r"^https?://", "", host, flags=re.I).split("/", 1)[0].strip()
        if not re.match(r"^[A-Za-z0-9.-]+$", host):
            host = "localhost"
        media_scheme = str(sipnotify.get("media_scheme") or "http").strip().lower()
        if media_scheme not in {"http", "https"}:
            media_scheme = "http"

        config.read_dict({
            "nws": {
                "zone": str(settings.get("nws_zone") or ""),
                "api_base_url": str(settings.get("nws_api_base_url") or "https://api.weather.gov"),
                "poll_interval": "60",
            },
            "ami": {
                "host": "127.0.0.1",
                "port": "5038",
                "username": str(ami.get("username") or "slsmassnotify"),
                "password": str(ami.get("password") or ""),
            },
            "logging": {"log_file": "/var/log/sls_mass_notify_push.log"},
            "visual": {
                "web_dir": "/var/www/html/sls_mass_notify",
                "public_base_url": f"{media_scheme}://{host}/sls_mass_notify",
                "image_width": "480",
                "image_height": "272",
                "retry_delays": "",
            },
            "api": {"events_file": str(DEFAULT_API_EVENTS_FILE)},
            "endpoint_format_overrides": {
                str(endpoint): str(fmt)
                for endpoint, fmt in (sipnotify.get("format_overrides") or {}).items()
                if str(endpoint).isdigit()
            },
        })
        return config

    raise RuntimeError(f"Unable to read central config {CENTRAL_SETTINGS_FILE}")


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


def imagemagick_text(value, limit=220):
    value = str(value or "").replace("\x00", "").replace("%", "%%")
    if value.startswith("@"):
        value = " " + value
    return value[:limit]


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
    raw = str(alert.get("id") or alert.get("properties", {}).get("id") or time.time())
    digest = hashlib.sha256(raw.encode("utf-8")).hexdigest()[:12]
    return f"alert_{digest}_{secrets.token_hex(10)}.png"


def compact_identifier(value):
    return (value or "").strip().split("/")[-1]


def alert_chain_key(alert):
    props = alert.get("properties", {})
    alert_id = compact_identifier(alert.get("id") or props.get("id"))
    event = (props.get("event") or "").strip()
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

    return f"{event}|{reference_id or alert_id}"


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
    lines = [imagemagick_text(line) for line in image_text_lines(alert)]

    command = [
        "convert",
        "-size", f"{width}x{height}", f"xc:{colors['background']}",
        "-fill", colors["header"], "-draw", f"rectangle 0,0 {width},70",
        "-fill", colors["accent"], "-draw", f"rectangle 0,70 {width},78",
        "-font", "DejaVu-Sans-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "30", "-annotate", "+0+16", colors["label"],
        "-font", "DejaVu-Sans-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "28", "-annotate", "+0+82", lines[0],
        "-font", "DejaVu-Sans", "-fill", "#ffffff", "-gravity", "NorthWest",
        "-pointsize", "18", "-annotate", "+28+124", lines[1],
        "-pointsize", "18", "-annotate", "+28+148", lines[2],
        "-pointsize", "18", "-annotate", "+28+172", lines[3],
        "-pointsize", "17", "-annotate", "+28+196", lines[4],
        "-font", "DejaVu-Sans-Bold", "-fill", colors["accent"], "-gravity", "SouthWest",
        "-pointsize", "17", "-annotate", "+28+30", lines[5],
        "-pointsize", "17", "-annotate", "+28+10", lines[6],
        "-alpha", "off", "-depth", "8", "-interlace", "none",
        "PNG24:" + str(output),
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
    candidates = []
    for pattern in ("alert_*.png", "announcement_*.png", "phone_payload_*.xml"):
        candidates.extend(web_dir.glob(pattern))
    for path in candidates:
        try:
            if path.stat().st_mtime < cutoff:
                path.unlink()
                logging.info("Removed old generated alert image %s", path)
        except Exception as exc:
            logging.warning("Unable to remove old generated alert image %s: %s", path, exc)


def yealink_image_xml(image_url, beep="yes"):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        f"<YealinkIPPhoneImageScreen Beep='{html.escape(beep)}' Timeout='0' LockIn='no' mode='fullscreen'>"
        f"<Image horizontalAlign='middle' verticalAlign='middle'>{html.escape(image_url)}</Image>"
        "</YealinkIPPhoneImageScreen>"
    )


def build_image_xml(alert, image_url):
    event = alert.get("properties", {}).get("event", "")
    beep = alert_beep(event)
    return yealink_image_xml(image_url, beep)


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



def clean_payload_text(value, limit=700):
    value = re.sub(r"\s+", " ", (value or "").strip())
    return value[:limit]


def split_payload_lines(value, width=32, max_lines=10):
    cleaned = "\n".join(line.strip() for line in (value or "").splitlines()).strip() or "Announcement"
    lines = []
    for paragraph in cleaned.splitlines():
        lines.extend(textwrap.wrap(paragraph, width=width, break_long_words=False, break_on_hyphens=False) or [""])
    return lines[:max_lines]


def cisco_text_xml(title, message):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<CiscoIPPhoneText>"
        f"<Title>{html.escape(title)}</Title>"
        f"<Prompt>{html.escape(title)}</Prompt>"
        f"<Text>{html.escape(clean_payload_text(message))}</Text>"
        "</CiscoIPPhoneText>"
    )


def cisco_execute_xml(url):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<CiscoIPPhoneExecute>"
        f"<ExecuteItem Priority='0' URL='{html.escape(url, quote=True)}'/>"
        "</CiscoIPPhoneExecute>"
    )


def hosted_phone_payload(config, xml_payload):
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    public_base_url = config.get("visual", "public_base_url", fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    web_dir.mkdir(parents=True, exist_ok=True)
    payload_hash = hashlib.sha256(xml_payload.encode("utf-8")).hexdigest()[:12]
    output = web_dir / f"phone_payload_{payload_hash}_{secrets.token_hex(10)}.xml"
    tmp = output.with_name(output.name + f".tmp.{os.getpid()}")
    with tmp.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(xml_payload)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(tmp, 0o644)
    os.replace(tmp, output)
    try:
        import grp
        import pwd
        os.chown(output, pwd.getpwnam("asterisk").pw_uid, grp.getgrnam("asterisk").gr_gid)
    except Exception:
        pass
    prune_old_images(web_dir)
    return f"{public_base_url}/{output.name}"


def snom_text_xml(title, message):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<SnomIPPhoneText>"
        f"<Title>{html.escape(title)}</Title>"
        f"<Text>{html.escape(clean_payload_text(message))}</Text>"
        "</SnomIPPhoneText>"
    )


def poly_text_xml(title, message):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<PolycomIPPhone>"
        f"<Data priority='critical'>{html.escape(title)}&#10;{html.escape(clean_payload_text(message))}</Data>"
        "</PolycomIPPhone>"
    )


def grandstream_text_xml(title, message):
    body = html.escape(clean_payload_text(message))
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<xmlapp>"
        "<view>"
        f"<title>{html.escape(title)}</title>"
        f"<body>{body}</body>"
        "</view>"
        "</xmlapp>"
    )


def aastra_text_xml(title, message):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<AastraIPPhoneTextScreen>"
        f"<Title>{html.escape(title)}</Title>"
        f"<Text>{html.escape(clean_payload_text(message))}</Text>"
        "</AastraIPPhoneTextScreen>"
    )


def generic_text_xml(title, message):
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<MassNotification>"
        f"<Title>{html.escape(title)}</Title>"
        f"<Text>{html.escape(clean_payload_text(message))}</Text>"
        "</MassNotification>"
    )


def text_xml_for_format(fmt, title, message):
    if fmt == "cisco" or fmt == "fanvil":
        return cisco_text_xml(title, message)
    if fmt == "snom":
        return snom_text_xml(title, message)
    if fmt in {"poly", "polycom"}:
        return poly_text_xml(title, message)
    if fmt == "grandstream":
        return grandstream_text_xml(title, message)
    if fmt in {"aastra", "mitel"}:
        return aastra_text_xml(title, message)
    if fmt in {"generic", "sangoma", "avaya", "vtech", "ale", "unknown"}:
        return generic_text_xml(title, message)
    return build_announcement_xml(message)


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

    title = imagemagick_text((title or "Announcement").strip(), 48) or "Announcement"
    cleaned = " ".join((message or "").split()).strip() or "Announcement"
    body_lines = [imagemagick_text(line) for line in textwrap.wrap(cleaned, width=34, break_long_words=False, break_on_hyphens=False)[:5]]
    while len(body_lines) < 5:
        body_lines.append("")

    output = web_dir / ("announcement_" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S") + "_" + secrets.token_hex(12) + ".png")
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
        "-font", "DejaVu-Sans-Bold", "-fill", "#ffffff", "-gravity", "North",
        "-pointsize", "30", "-annotate", "+0+18", title,
        "-font", "DejaVu-Sans", "-fill", "#ffffff", "-gravity", "NorthWest",
        "-pointsize", "20", "-annotate", "+30+108", body_lines[0],
        "-pointsize", "20", "-annotate", "+30+136", body_lines[1],
        "-pointsize", "20", "-annotate", "+30+164", body_lines[2],
        "-pointsize", "20", "-annotate", "+30+192", body_lines[3],
        "-pointsize", "20", "-annotate", "+30+220", body_lines[4],
        "-alpha", "off", "-depth", "8", "-interlace", "none",
        "PNG24:" + str(output),
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
    return yealink_image_xml(image_url, "yes")


def build_xml(config, alert):
    try:
        return build_image_xml(alert, render_alert_image(config, alert))
    except Exception as exc:
        logging.error("Unable to render alert image; falling back to text XML: %s", exc)
        return build_text_xml(alert)


def normalize_target_list(value):
    targets = set()
    for item in (value or "").replace(";", ",").split(","):
        item = item.strip()
        if item.isdigit():
            targets.add(item)
    return targets


def normalize_desktop_target_list(value):
    targets = set()
    for item in (value or "").replace(";", ",").split(","):
        item = item.strip().lower()
        item = re.sub(r"[^a-z0-9_.-]+", "", item)
        if item:
            targets.add(item[:48])
    return targets


PHONE_FORMAT_ALIASES = {
    "polycom": "poly",
    "poly-com": "poly",
    "mitel": "aastra",
    "generic_xml": "generic",
    "yealink_xml": "yealink",
    "cisco_xml": "cisco",
}

SUPPORTED_PHONE_FORMATS = {
    "yealink",
    "yealink_text",
    "cisco",
    "poly",
    "grandstream",
    "fanvil",
    "snom",
    "aastra",
    "sangoma",
    "avaya",
    "vtech",
    "ale",
    "generic",
    "unknown",
}


def normalize_phone_format(value):
    fmt = re.sub(r"[^a-z0-9_-]+", "", (value or "").strip().lower())
    fmt = PHONE_FORMAT_ALIASES.get(fmt, fmt)
    return fmt if fmt in SUPPORTED_PHONE_FORMATS else ""


def endpoint_format_overrides(config):
    overrides = {}
    if not config.has_section("endpoint_format_overrides"):
        return overrides
    for endpoint, fmt in config.items("endpoint_format_overrides"):
        endpoint = re.sub(r"[^0-9]+", "", endpoint or "")
        fmt = normalize_phone_format(fmt)
        if endpoint and fmt:
            overrides[endpoint] = fmt
    return overrides


def detect_phone_format(user_agent, endpoint=""):
    ua = (user_agent or "").lower()
    if "yealink" in ua:
        return "yealink"
    if "polycom" in ua or re.search(r"\bpoly\b", ua) or "vvx" in ua:
        return "poly"
    if "cisco" in ua or "cp-" in ua or "spa" in ua:
        return "cisco"
    if "grandstream" in ua or "gxp" in ua or "grp" in ua:
        return "grandstream"
    if "fanvil" in ua:
        return "fanvil"
    if "snom" in ua:
        return "snom"
    if "aastra" in ua or "mitel" in ua:
        return "aastra"
    if "sangoma" in ua:
        return "sangoma"
    if "avaya" in ua:
        return "avaya"
    if "vtech" in ua:
        return "vtech"
    return "unknown"


def get_registered_endpoint_info(ami, allowed_targets=None, format_overrides=None):
    response, events = ami.action({"Action": "PJSIPShowContacts"}, "ContactListComplete")
    if response.get("Response", "").lower() == "error":
        raise RuntimeError(response.get("Message", "PJSIPShowContacts failed"))
    allowed_targets = set(allowed_targets or [])
    format_overrides = format_overrides or {}
    endpoints = {}
    skipped = []
    registered_statuses = {"reachable", "nonqualified", "avail", "available", "ok", "unknown"}
    blocked_statuses = {"unreachable", "unavailable", "unavail", "removed", "rejected", "failed"}
    for event in events:
        if event.get("Event") != "ContactList":
            continue
        endpoint = (event.get("Endpoint") or event.get("AOR") or event.get("ObjectName") or "").strip()
        if not endpoint:
            contact = (event.get("Contact") or "").strip()
            endpoint = contact.split("/", 1)[0] if "/" in contact else contact
        endpoint = endpoint.split("/", 1)[0].strip()
        status = (event.get("Status") or "").strip()
        user_agent = (event.get("UserAgent") or "").strip()
        if not endpoint:
            continue
        if not endpoint.isdigit() and not allowed_targets:
            logging.info("Skipping non-extension PJSIP contact %s status=%s user_agent=%s", endpoint, status or "Unknown", user_agent or "unknown")
            continue
        if allowed_targets and endpoint not in allowed_targets:
            logging.info("Skipping endpoint %s because it is not in the requested target list", endpoint)
            continue
        normalized_status = status.lower().split()[0] if status else "unknown"
        if normalized_status in registered_statuses and normalized_status not in blocked_statuses:
            detected_format = detect_phone_format(user_agent, endpoint)
            override_format = format_overrides.get(endpoint, "")
            fmt = override_format or detected_format
            info = endpoints.setdefault(endpoint, {
                "status": status,
                "user_agent": "",
                "format": fmt,
                "formats": [],
                "contacts": [],
                "override": bool(override_format),
            })
            if fmt not in info["formats"]:
                info["formats"].append(fmt)
            user_agents = [part for part in info.get("user_agent", "").split(" | ") if part]
            if user_agent and user_agent not in user_agents:
                user_agents.append(user_agent)
            info["user_agent"] = " | ".join(user_agents[:6])
            info["status"] = status or info.get("status", "")
            info["format"] = info["formats"][0] if info["formats"] else fmt
            info["override"] = info.get("override", False) or bool(override_format)
            info["contacts"].append({
                "status": status,
                "user_agent": user_agent,
                "detected_format": detected_format,
                "format": fmt,
                "contact": (event.get("Contact") or "").strip(),
            })
        else:
            skipped.append((endpoint, status or "Unknown", user_agent))
    for endpoint, status, user_agent in sorted(skipped):
        logging.info("Skipping unregistered/unreachable endpoint %s status=%s user_agent=%s", endpoint, status, user_agent or "unknown")
    return endpoints


def get_registered_extensions(ami, allowed_targets=None):
    return sorted(get_registered_endpoint_info(ami, allowed_targets).keys())


def phone_title_and_message_from_alert(alert):
    props = alert.get("properties", {})
    title = alert_title(props.get("event", ""))
    lines = image_text_lines(alert)
    message = "\n".join([line for line in lines[1:7] if line])
    return title, message


def build_phone_xml_for_format(config, fmt, payload_type, alert=None, message="", image=False, title="Announcement", background_color="#1f2937", background_image=""):
    fmt = fmt or "yealink"
    if payload_type == "alert":
        if fmt == "yealink":
            return build_xml(config, alert)
        if fmt == "yealink_text":
            return build_text_xml(alert)
        alert_title_text, alert_message = phone_title_and_message_from_alert(alert)
        payload = text_xml_for_format(fmt, alert_title_text, alert_message)
        if fmt == "cisco":
            return cisco_execute_xml(hosted_phone_payload(config, payload))
        return payload
    if image and fmt == "yealink":
        return build_announcement_image_xml(config, message, title, background_color, background_image)
    payload = text_xml_for_format(fmt, title or "Announcement", message)
    if fmt == "cisco":
        return cisco_execute_xml(hosted_phone_payload(config, payload))
    return payload


def notify_events_for_format(phone_format):
    phone_format = (phone_format or "yealink").lower()
    events = {
        "yealink": ["Yealink-xml"],
        "yealink_text": ["Yealink-xml"],
        "cisco": ["XML-Service"],
        "fanvil": ["xml", "CiscoIPPhoneText"],
        "poly": ["polycom-push", "xml"],
        "polycom": ["polycom-push", "xml"],
        "snom": ["snom", "xml"],
        "grandstream": ["xml"],
        "aastra": ["aastra-xml", "xml"],
        "mitel": ["aastra-xml", "xml"],
        "sangoma": ["xml"],
        "avaya": ["xml"],
        "vtech": ["xml"],
        "ale": ["xml"],
        "unknown": ["xml"],
        "generic": ["xml"],
    }.get(phone_format, ["xml"])
    deduped = []
    for event in events:
        if event not in deduped:
            deduped.append(event)
    return deduped


def notify_content_type_for_format(phone_format):
    phone_format = (phone_format or "yealink").lower()
    if phone_format in {"yealink", "yealink_text"}:
        return "application/xml"
    if phone_format in {"poly", "polycom"}:
        return "application/x-com-polycom-spipx"
    return "text/xml"


def send_notify(ami, endpoint, xml_payload, phone_format="yealink"):
    content_type = notify_content_type_for_format(phone_format)
    errors = []
    successes = []
    for event_name in notify_events_for_format(phone_format):
        response, _ = ami.action({
            "Action": "PJSIPNotify",
            "Endpoint": endpoint,
            "Variable": [
                f"Event={event_name}",
                f"Content-Type={content_type}",
                f"Content={xml_payload}",
            ],
        })
        if response.get("Response", "").lower() != "error":
            successes.append(f"{event_name}: {response.get('Message', 'sent')}")
            break
        message = response.get("Message", "PJSIPNotify failed")
        errors.append(f"{event_name}: {message}")
        logging.warning("PJSIPNotify event=%s content_type=%s failed for %s format=%s: %s", event_name, content_type, endpoint, phone_format, message)
    if successes:
        return f"{'; '.join(successes)} content_type={content_type}"
    raise RuntimeError("; ".join(errors) if errors else "PJSIPNotify failed")


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
    with path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.seek(0)
        lines = [line.rstrip("\n") for line in handle if line.strip()]
        record_id = str(record.get("id") or "")
        encoded = json.dumps(record, ensure_ascii=True, separators=(",", ":"))
        replaced = False
        if record_id:
            for index, line in enumerate(lines):
                try:
                    existing = json.loads(line)
                except Exception:
                    continue
                if str(existing.get("id") or "") == record_id:
                    lines[index] = encoded
                    replaced = True
        if not replaced:
            lines.append(encoded)
        lines = lines[-1000:]
        handle.seek(0)
        handle.truncate(0)
        if lines:
            handle.write("\n".join(lines) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
    try:
        import grp
        import pwd
        os.chown(path, pwd.getpwnam("asterisk").pw_uid, grp.getgrnam("asterisk").gr_gid)
    except Exception:
        pass
    os.chmod(path, 0o640)


def alert_api_record(alert, xml_payload, extensions, phone_formats=None):
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
        "desktop_all": True,
        "desktop_recipients": [],
        "phone_formats": phone_formats or {},
    }


def announcement_api_record(alert_id, message, xml_payload, extensions, desktop_targets=None, desktop_all=False, phone_formats=None):
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
        "desktop_all": bool(desktop_all),
        "desktop_recipients": sorted(set(desktop_targets or [])),
        "phone_formats": phone_formats or {},
    }


def send_notify_batch(ami, endpoint_info, payload_builder, alert_id, print_results=False, attempt_label="initial"):
    successes = 0
    failures = []
    for extension, info in sorted(endpoint_info.items()):
        formats = info.get("formats") or [info.get("format", "yealink")]
        user_agent = info.get("user_agent", "")
        if len(formats) > 1:
            logging.warning(
                "Endpoint %s has mixed phone vendors on one extension (%s). Each vendor event will be sent to every registered contact for that endpoint.",
                extension,
                ", ".join(formats),
            )
        for phone_format in formats:
            try:
                xml_payload = payload_builder(phone_format)
                result = send_notify(ami, extension, xml_payload, phone_format)
                successes += 1
                logging.info("Pushed SIP NOTIFY %s to %s format=%s user_agent=%s attempt=%s: %s", alert_id, extension, phone_format, user_agent or "unknown", attempt_label, result)
                if print_results:
                    print(f"{extension}: success format={phone_format} ({attempt_label})")
            except Exception as exc:
                failures.append(f"{extension}/{phone_format}: {exc}")
                logging.error("Failed SIP NOTIFY %s to %s format=%s user_agent=%s attempt=%s: %s", alert_id, extension, phone_format, user_agent or "unknown", attempt_label, exc)
                if print_results:
                    print(f"{extension}: failed format={phone_format} ({attempt_label}) - {exc}")
    if failures:
        raise RuntimeError(f"SIP NOTIFY delivery failed for {len(failures)} target format(s): " + "; ".join(failures))
    if endpoint_info and successes == 0:
        raise RuntimeError("SIP NOTIFY did not succeed for any requested endpoint")
    return successes


def endpoint_format_summary(endpoint_info):
    return {
        endpoint: {
            "format": info.get("format", "yealink"),
            "formats": info.get("formats") or [info.get("format", "yealink")],
            "user_agent": info.get("user_agent", ""),
            "contacts": len(info.get("contacts") or []),
            "override": bool(info.get("override")),
        }
        for endpoint, info in sorted(endpoint_info.items())
    }


def push_alert(config, alert, print_results=False, retries=True, targets=None, api_publish=True):
    primary_xml_payload = build_xml(config, alert)
    props = alert.get("properties", {})
    event = props.get("event", "Unknown")
    priority = alert_priority(props)
    alert_id = alert.get("id") or props.get("id") or "unknown"
    requested_extensions = sorted(targets or [])
    if api_publish:
        append_sipnotify_event(config, alert_api_record(alert, primary_xml_payload, requested_extensions, {}))
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
    ) as ami:
        endpoint_info = get_registered_endpoint_info(ami, targets, endpoint_format_overrides(config))
        extensions = sorted(endpoint_info.keys())
        phone_formats = endpoint_format_summary(endpoint_info)
        logging.info("Pushing %s alert %s priority=%s to %d registered endpoints formats=%s", event, alert_id, priority, len(extensions), phone_formats)
        if api_publish:
            append_sipnotify_event(config, alert_api_record(alert, primary_xml_payload, extensions, phone_formats))
        if targets and not extensions:
            requested = ", ".join(sorted(targets))
            raise RuntimeError(f"No requested phone endpoints are registered/reachable for SIP NOTIFY: {requested}")
        payload_builder = lambda fmt: primary_xml_payload if fmt == "yealink" else build_phone_xml_for_format(config, fmt, "alert", alert=alert)
        send_notify_batch(ami, endpoint_info, payload_builder, alert_id, print_results, "initial")
        if not retries:
            return
        for delay in visual_retry_delays(config):
            logging.info("Waiting %s seconds before visual alert retry for %s", delay, alert_id)
            time.sleep(delay)
            send_notify_batch(ami, endpoint_info, payload_builder, alert_id, print_results, f"retry+{delay}s")


def push_announcement(config, message, targets, print_results=True, api_publish=True, api_only=False, image=False, title="Announcement", background_color="#1f2937", background_image="", desktop_targets=None, desktop_all=False):
    if image:
        xml_payload = build_announcement_image_xml(config, message, title, background_color, background_image)
    else:
        xml_payload = build_announcement_xml(message)
    alert_id = "announcement-" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    if api_only:
        append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, [], desktop_targets, desktop_all))
        return []
    if api_publish:
        append_sipnotify_event(
            config,
            announcement_api_record(alert_id, message, xml_payload, sorted(targets or []), desktop_targets, desktop_all),
        )
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
    ) as ami:
        endpoint_info = get_registered_endpoint_info(ami, targets, endpoint_format_overrides(config))
        extensions = sorted(endpoint_info.keys())
        phone_formats = endpoint_format_summary(endpoint_info)
        logging.info("Pushing SIP NOTIFY announcement %s to %d registered endpoints formats=%s", alert_id, len(extensions), phone_formats)
        if api_publish:
            append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, extensions, desktop_targets, desktop_all, phone_formats))
        if targets and not extensions:
            requested = ", ".join(sorted(targets))
            raise RuntimeError(f"No requested phone endpoints are registered/reachable for SIP NOTIFY: {requested}")
        payload_builder = lambda fmt: xml_payload if fmt == "yealink" else build_phone_xml_for_format(config, fmt, "announcement", message=message, image=image, title=title, background_color=background_color, background_image=background_image)
        send_notify_batch(ami, endpoint_info, payload_builder, alert_id, print_results, "initial")
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


def main():
    parser = argparse.ArgumentParser(description="SLS Mass Notify SIP NOTIFY push for supported phones")
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
    parser.add_argument("--announcement-image", action="store_true", help="Render announcement as an image screen for image-capable phone formats")
    parser.add_argument("--announcement-title", default="Announcement", help="Announcement image title")
    parser.add_argument("--announcement-bg-color", default="#1f2937", help="Announcement image background color")
    parser.add_argument("--announcement-bg-image", default="", help="Announcement image background file")
    parser.add_argument("--desktop-targets", default="", help="Comma-separated desktop app usernames allowed to receive this API event")
    parser.add_argument("--desktop-all", action="store_true", help="Allow all enabled desktop app clients to receive this API event")
    parser.add_argument("--no-api", action="store_true", help="Do not publish this announcement/alert to the desktop API journal")
    parser.add_argument("--api-only", action="store_true", help="Publish announcement to the desktop API journal without sending SIP NOTIFY")
    parser.add_argument("--list-endpoints-json", action="store_true", help="Print registered endpoint vendor detection as JSON and exit")
    parser.add_argument("--no-retry", action="store_true", help="Do not send delayed visual retries")
    args = parser.parse_args()

    try:
        config = load_config()
        setup_logging(config["logging"].get("log_file", "/var/log/sls_mass_notify_push.log"))
        if args.list_endpoints_json:
            with AmiClient(
                config["ami"].get("host", "127.0.0.1"),
                config["ami"].getint("port", 5038),
                config["ami"].get("username", "slsmassnotify"),
                config["ami"].get("password", ""),
            ) as ami:
                print(json.dumps(endpoint_format_summary(get_registered_endpoint_info(ami, format_overrides=endpoint_format_overrides(config))), sort_keys=True))
            return 0
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
                desktop_targets=normalize_desktop_target_list(args.desktop_targets),
                desktop_all=args.desktop_all,
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
            parser.print_help(sys.stderr)
            return 2
    except Exception as exc:
        logging.error("Fatal startup error: %s", exc)
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
