#!/usr/bin/env python3
import importlib.util
import argparse
import base64
import configparser
from contextlib import contextmanager
import fcntl
import hashlib
import hmac
import html
import json
import logging
import math
import os
import re
import secrets
import socket
import stat
import subprocess
import sys
import tempfile
import time
import textwrap
from urllib.parse import urlsplit
from datetime import datetime, timedelta, timezone
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_sys.path.insert(0, str(Path(__file__).resolve().parent))
import sls_desktop_history as _desktop_history
import sls_desktop_feed_cache as _desktop_feed_cache
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
_cluster_spec = importlib.util.spec_from_file_location('sls_cluster_guard', Path(__file__).resolve().with_name('sls_cluster_guard.py'))
_cluster_guard = importlib.util.module_from_spec(_cluster_spec)
_cluster_spec.loader.exec_module(_cluster_guard)
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

CENTRAL_SETTINGS_FILE = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
try:
    LOCAL_TZ = ZoneInfo(os.environ.get("TZ") or "UTC")
except (ValueError, ZoneInfoNotFoundError):
    LOCAL_TZ = timezone.utc
DEFAULT_API_EVENTS_FILE = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl")
PHONE_VISUAL_STATE = Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/phone-visual-state')
_LOG_STREAM = None


def valid_event_identifier(value):
    if not isinstance(value, str):
        return False
    try:
        encoded = value.encode('utf-8')
    except UnicodeEncodeError:
        return False
    return (1 <= len(encoded) <= 2048 and value == value.strip()
            and not any(ord(char) < 32 or ord(char) == 127 for char in value))


class PhoneVisualLease:
    """A locked marker update that is committed only after AMI accepts a push."""

    def __init__(self, permitted):
        self.permitted = bool(permitted)
        self.committed = False

    def __bool__(self):
        return self.permitted

    def commit(self):
        if not self.permitted:
            raise OSError('Phone visual ownership is not authorized')
        self.committed = True


class GuardedContactAmi:
    """Bound each connected-contact operation to its current page lease."""
    def __init__(self, ami, guard):
        self.ami, self.guard = ami, guard

    def action(self, *args, **kwargs):
        if not self.guard():
            raise RuntimeError('The live-page visual request expired')
        return self.ami.action(*args, **kwargs)


@contextmanager
def phone_visual_owner(extension, owner='', expected_owner='', directory=None, continuing=False):
    """Serialize a handset's pushes; a page may clear only its own last popup."""
    if not re.fullmatch(r'[0-9]{1,20}', str(extension)):
        raise ValueError('Invalid phone visual recipient')
    for token in (owner, expected_owner):
        if token and not re.fullmatch(r'[a-f0-9]{32}', token):
            raise ValueError('Invalid phone visual owner')
    if owner and expected_owner:
        raise ValueError('A visual push cannot claim and clear an owner together')
    if type(continuing) is not bool or (continuing and not owner):
        raise ValueError('Continuing a visual push requires its owner')
    directory = Path(directory) if directory is not None else PHONE_VISUAL_STATE
    directory.mkdir(mode=0o750, exist_ok=True)
    meta = directory.lstat()
    if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != os.geteuid() or meta.st_mode & 0o022 or directory.resolve() != directory:
        raise OSError('Phone visual state directory is unsafe')
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    opened_directory = os.fstat(directory_fd)
    if ((opened_directory.st_dev, opened_directory.st_ino, opened_directory.st_mode,
         opened_directory.st_uid, opened_directory.st_gid)
            != (meta.st_dev, meta.st_ino, meta.st_mode, meta.st_uid, meta.st_gid)):
        os.close(directory_fd)
        raise OSError('Phone visual state directory changed while it was opened')
    path_name = str(extension) + '.json'
    lock_name = path_name + '.lock'
    descriptor = lock_descriptor = None
    try:
        lock_descriptor = os.open(lock_name,
            os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=directory_fd)
        lock_metadata = os.fstat(lock_descriptor)
        if (not stat.S_ISREG(lock_metadata.st_mode) or lock_metadata.st_uid != os.geteuid()
                or lock_metadata.st_gid != os.getegid() or lock_metadata.st_nlink != 1
                or stat.S_IMODE(lock_metadata.st_mode) not in (0o600, 0o640) or lock_metadata.st_size != 0):
            raise OSError('Phone visual state lock is unsafe')
        def lock_identity(value):
            return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink, value.st_size)
        def verify_lock():
            current_lock = os.stat(lock_name, dir_fd=directory_fd, follow_symlinks=False)
            current_directory = directory.lstat()
            if (lock_identity(current_lock) != lock_identity(lock_metadata)
                    or lock_identity(os.fstat(lock_descriptor)) != lock_identity(lock_metadata)
                    or (current_directory.st_dev, current_directory.st_ino, current_directory.st_mode,
                        current_directory.st_uid, current_directory.st_gid)
                    != (opened_directory.st_dev, opened_directory.st_ino, opened_directory.st_mode,
                        opened_directory.st_uid, opened_directory.st_gid)):
                raise OSError('Phone visual state lock or directory changed')
        deadline = time.monotonic() + 3
        while True:
            try:
                fcntl.flock(lock_descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise OSError('Phone visual state is busy') from None
                time.sleep(.01)
        verify_lock()
        descriptor = os.open(path_name,
            os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o640, dir_fd=directory_fd)
        opened = os.fstat(descriptor)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != os.geteuid()
                or opened.st_gid != os.getegid() or opened.st_nlink != 1
                or stat.S_IMODE(opened.st_mode) not in (0o600, 0o640) or opened.st_size > 512):
            raise OSError('Phone visual state file is unsafe')
        current = os.stat(path_name, dir_fd=directory_fd, follow_symlinks=False)
        if ((current.st_dev, current.st_ino, current.st_mode, current.st_uid,
             current.st_gid, current.st_nlink, current.st_size)
                != (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_uid,
                    opened.st_gid, opened.st_nlink, opened.st_size)):
            raise OSError('Phone visual state changed')
        os.lseek(descriptor, 0, os.SEEK_SET)
        raw = os.read(descriptor, 513)
        if len(raw) > 512:
            raise OSError('Phone visual state exceeds its limit')
        after = os.fstat(descriptor)
        named_after = os.stat(path_name, dir_fd=directory_fd, follow_symlinks=False)
        identity = (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_uid,
                    opened.st_gid, opened.st_nlink, opened.st_size)
        if ((after.st_dev, after.st_ino, after.st_mode, after.st_uid, after.st_gid,
             after.st_nlink, after.st_size) != identity
                or (named_after.st_dev, named_after.st_ino, named_after.st_mode,
                    named_after.st_uid, named_after.st_gid, named_after.st_nlink,
                    named_after.st_size) != identity):
            raise OSError('Phone visual state changed while it was read')
        try:
            saved = _config_crypto._object(raw, 512) if raw else {}
            if set(saved) != {'owner'} or not isinstance(saved['owner'], str) or (
                saved['owner'] and not re.fullmatch(r'[a-f0-9]{32}', saved['owner'])
            ):
                saved = {}
        except (ValueError, UnicodeError):
            # Unknown ownership never authorizes a completion push. A new
            # ordinary alert can replace a corrupt, non-secret marker.
            saved = {}
        verify_lock()
        if expected_owner and (not isinstance(saved, dict) or saved.get('owner') != expected_owner):
            yield PhoneVisualLease(False)
            return
        if continuing and saved.get('owner') != owner:
            # A later-joining handset cannot reclaim an extension after a newer
            # announcement replaced this page's ownership.
            yield PhoneVisualLease(False)
            return
        # Keep the lock until every contact for this extension is submitted.
        lease = PhoneVisualLease(True)
        yield lease
        if lease.committed:
            value = json.dumps({'owner': owner}, separators=(',', ':')).encode()
            temporary_name = '.' + path_name + '.tmp.' + secrets.token_hex(16)
            temporary_fd = None
            temporary_metadata = None
            try:
                temporary_fd = os.open(temporary_name,
                                       os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                                       0o600, dir_fd=directory_fd)
                temporary_metadata = os.fstat(temporary_fd)
                written = 0
                while written < len(value):
                    count = os.write(temporary_fd, value[written:])
                    if count <= 0:
                        raise OSError('Phone visual ownership could not be staged')
                    written += count
                os.fchmod(temporary_fd, 0o640)
                os.fsync(temporary_fd)
                staged = os.fstat(temporary_fd)
                named_staged = os.stat(temporary_name, dir_fd=directory_fd, follow_symlinks=False)
                if (not stat.S_ISREG(staged.st_mode) or staged.st_nlink != 1
                        or staged.st_uid != os.geteuid() or staged.st_gid != os.getegid()
                        or stat.S_IMODE(staged.st_mode) != 0o640 or staged.st_size != len(value)
                        or (staged.st_dev, staged.st_ino, staged.st_size)
                        != (named_staged.st_dev, named_staged.st_ino, named_staged.st_size)):
                    raise OSError('Phone visual ownership staging file is unsafe')
                current = os.stat(path_name, dir_fd=directory_fd, follow_symlinks=False)
                if ((current.st_dev, current.st_ino, current.st_mode, current.st_uid,
                     current.st_gid, current.st_nlink, current.st_size) != identity):
                    raise OSError('Phone visual state changed before it was saved')
                named_directory = directory.lstat()
                if ((named_directory.st_dev, named_directory.st_ino, named_directory.st_mode,
                     named_directory.st_uid, named_directory.st_gid)
                        != (opened_directory.st_dev, opened_directory.st_ino,
                            opened_directory.st_mode, opened_directory.st_uid, opened_directory.st_gid)):
                    raise OSError('Phone visual state directory changed before it was saved')
                verify_lock()
                os.replace(temporary_name, path_name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
                temporary_name = None
                named_committed = os.stat(path_name, dir_fd=directory_fd, follow_symlinks=False)
                if (not stat.S_ISREG(named_committed.st_mode) or named_committed.st_nlink != 1
                        or (named_committed.st_dev, named_committed.st_ino)
                        != (staged.st_dev, staged.st_ino)
                        or named_committed.st_size != len(value)
                        or stat.S_IMODE(named_committed.st_mode) != 0o640):
                    raise OSError('Phone visual ownership did not commit safely')
                os.fsync(directory_fd)
            finally:
                if temporary_fd is not None:
                    os.close(temporary_fd)
                if temporary_name is not None and temporary_metadata is not None:
                    try:
                        current = os.stat(temporary_name, dir_fd=directory_fd, follow_symlinks=False)
                        if ((current.st_dev, current.st_ino)
                                == (temporary_metadata.st_dev, temporary_metadata.st_ino)):
                            os.unlink(temporary_name, dir_fd=directory_fd)
                    except FileNotFoundError:
                        pass
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if lock_descriptor is not None:
            os.close(lock_descriptor)
        os.close(directory_fd)

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
    "Heat Advisory": ("⚠ HEAT ADVISORY", "no"),
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


def ami_response_is_empty_contact_inventory(response):
    if str(response.get("Response") or "").strip().lower() != "error":
        return False
    message = str(response.get("Message") or "").strip().lower()
    return re.fullmatch(r"no\s+(?:contacts?|objects?)\s+found[.!]?", message) is not None


def ami_response_is_missing_probe_endpoint(response, probe="__sls_capability_probe__"):
    if str(response.get("Response") or "").strip().lower() != "error":
        return False
    message = str(response.get("Message") or "").strip().lower()
    if probe.lower() not in message:
        return False
    return any(phrase in message for phrase in (
        "unable to retrieve endpoint",
        "unable to find endpoint",
        "endpoint not found",
        "endpoint does not exist",
    ))


def ami_field(message, *names):
    for name in names:
        wanted = str(name).lower()
        for key, value in (message or {}).items():
            if str(key).lower() == wanted:
                return value
    return ""


class AmiClient:
    MAX_LINE_BYTES = 8192
    MAX_MESSAGE_BYTES = 256 * 1024
    MAX_ACTION_BYTES = 16 * 1024 * 1024
    MAX_ACTION_MESSAGES = 20000
    MAX_REQUEST_BYTES = 256 * 1024
    IDENTITY_FIELDS = {'response', 'event', 'actionid', 'endpoint', 'aor', 'objectname',
                       'contact', 'uri', 'status', 'useragent'}

    def __init__(self, host, port, username, password, timeout=10):
        self.host = host
        self.port = int(port)
        self.username = username
        self.password = password
        self.timeout = timeout
        self.sock = None
        self.file = None
        self.buffer = b''
        self.message_bytes = 0

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, exc_type, exc, tb):
        self.close()

    def connect(self):
        self.sock = socket.create_connection((self.host, self.port), self.timeout)
        self.sock.settimeout(self.timeout)
        self.buffer = b''
        try:
            self.read_line(time.monotonic() + self.timeout)
            response, _ = self.action({
                "Action": "Login",
                "Username": self.username,
                "Secret": self.password,
                "Events": "off",
            })
            if response.get("Response", "").lower() != "success":
                raise RuntimeError("AMI login failed: " + notify_ami_response_summary(response)["message_category"])
        except BaseException:
            self.close()
            raise

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
        self.file = None
        self.sock = None
        self.buffer = b''

    def send(self, fields):
        if not isinstance(fields, dict) or len(fields) > 128:
            raise ValueError('Invalid AMI action fields')
        lines = []
        for key, value in fields.items():
            key = str(key)
            if re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]{0,63}', key) is None:
                raise ValueError("Invalid AMI field name")
            if isinstance(value, list):
                for item in value:
                    item = str(item)
                    if "\r" in item or "\n" in item:
                        raise ValueError(f"AMI field {key} contains a line break")
                    line = f"{key}: {item}"
                    if len(line.encode('utf-8')) > self.MAX_LINE_BYTES:
                        raise ValueError(f"AMI field {key} exceeds its byte limit")
                    lines.append(line)
            else:
                value = str(value)
                if "\r" in value or "\n" in value:
                    raise ValueError(f"AMI field {key} contains a line break")
                line = f"{key}: {value}"
                if len(line.encode('utf-8')) > self.MAX_LINE_BYTES:
                    raise ValueError(f"AMI field {key} exceeds its byte limit")
                lines.append(line)
        data = "\r\n".join(lines) + "\r\n\r\n"
        encoded = data.encode("utf-8")
        if len(encoded) > self.MAX_REQUEST_BYTES:
            raise ValueError('AMI action exceeds its request byte limit')
        self.sock.sendall(encoded)

    def remaining(self, deadline):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError('AMI action exceeded its total timeout')
        if self.sock is not None:
            self.sock.settimeout(remaining)
        return remaining

    def read_line(self, deadline):
        self.remaining(deadline)
        if self.file is not None:
            # In-memory adapters used by private parser fixtures.
            line = self.file.readline(self.MAX_LINE_BYTES + 1)
            if len(line.encode('utf-8')) > self.MAX_LINE_BYTES:
                raise RuntimeError('AMI line exceeds its byte limit')
            return line
        while b'\n' not in self.buffer:
            if len(self.buffer) >= self.MAX_LINE_BYTES:
                raise RuntimeError('AMI line exceeds its byte limit')
            self.remaining(deadline)
            chunk = self.sock.recv(self.MAX_LINE_BYTES - len(self.buffer))
            if not chunk:
                raise RuntimeError('AMI connection closed')
            self.buffer += chunk
        line, self.buffer = self.buffer.split(b'\n', 1)
        if len(line) + 1 > self.MAX_LINE_BYTES:
            raise RuntimeError('AMI line exceeds its byte limit')
        try:
            return (line + b'\n').decode('utf-8')
        except UnicodeError:
            raise RuntimeError('AMI line is not valid UTF-8') from None

    def read_message(self, deadline=None):
        deadline = time.monotonic() + self.timeout if deadline is None else deadline
        message = {}
        identities = set()
        self.message_bytes = 0
        while True:
            line = self.read_line(deadline)
            if line == "":
                raise RuntimeError("AMI connection closed")
            self.message_bytes += len(line.encode('utf-8'))
            if self.message_bytes > self.MAX_MESSAGE_BYTES:
                raise RuntimeError('AMI message exceeds its byte limit')
            line = line.rstrip("\r\n")
            if line == "":
                return message
            if ":" not in line:
                continue
            key, value = line.split(":", 1)
            value = value.lstrip()
            if key.lower() in self.IDENTITY_FIELDS:
                if key.lower() in identities:
                    raise RuntimeError('AMI message contains duplicate identity fields')
                identities.add(key.lower())
            if key in message:
                existing = message[key]
                if isinstance(existing, list):
                    existing.append(value)
                else:
                    message[key] = [existing, value]
            else:
                message[key] = value

    def action(self, fields, complete_event=None):
        deadline = time.monotonic() + self.timeout
        self.remaining(deadline)
        self.send(fields)
        response = {}
        events = []
        count = 0
        total_bytes = 0
        while True:
            self.remaining(deadline)
            message = self.read_message(deadline)
            count += 1
            total_bytes += self.message_bytes
            if count > self.MAX_ACTION_MESSAGES or total_bytes > self.MAX_ACTION_BYTES:
                raise RuntimeError('AMI action exceeds its response limits')
            if not response and "Response" in message:
                response = message
                if complete_event is None or response.get("Response", "").lower() == "error":
                    return response, events
            elif "Event" in message:
                events.append(message)
                if complete_event and message.get("Event") == complete_event:
                    return response, events


def ami_live_paging_actions(ami):
    """Read-only discovery of actions visible to the authenticated AMI account."""
    response, _ = ami.action({"Action": "ListCommands"})
    if (not isinstance(response, dict) or len(response) > 512
            or response.get("Response") != "Success"):
        raise RuntimeError("AMI action discovery failed")
    required = ("MuteAudio", "PlayDTMF", "Setvar", "Getvar")
    names = {name.lower() for name in required}
    commands = {}
    for key, value in response.items():
        if not isinstance(key, str):
            raise RuntimeError("AMI action discovery is invalid")
        lowered = key.lower()
        if lowered in names:
            if (lowered in commands or not isinstance(value, str)
                    or not value.strip() or len(value) > 4096):
                raise RuntimeError("AMI paging action discovery is ambiguous")
            commands[lowered] = value
    if any(name.lower() not in commands for name in required):
        raise RuntimeError("AMI caller-ready paging actions are unavailable or unauthorized")
    return {name: "authorized" for name in required}


def resolve_local_ami_endpoint(ami):
    values = {}
    amportal = Path("/etc/amportal.conf")
    if amportal.is_file():
        try:
            for raw_line in amportal.read_text(encoding="utf-8", errors="replace").splitlines():
                line = raw_line.strip()
                if not line or line.startswith(("#", ";")) or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                values[key.strip().upper()] = value.strip().strip('"\'')
        except OSError:
            values = {}

    host = str(ami.get("host") or values.get("ASTMANAGERHOST") or "localhost").strip().lower()
    if host not in {"localhost", "127.0.0.1", "::1"}:
        raise RuntimeError("FreePBX AMI must use a loopback host for SLS Mass Notify")
    configured_port = ami.get("port") if "port" in ami and str(ami.get("port")).strip() != "" else values.get("ASTMANAGERPORT") or 5038
    try:
        port = int(configured_port)
    except (TypeError, ValueError) as exc:
        raise RuntimeError("FreePBX AMI port is invalid") from exc
    if not 1 <= port <= 65535:
        raise RuntimeError("FreePBX AMI port is outside the supported range")
    # Preserve an explicitly selected IPv6 loopback. localhost intentionally
    # remains IPv4 without DNS or a silent cross-family fallback; IPv6-only
    # installations must select ::1 in the protected AMI settings.
    return ("::1" if host == "::1" else "127.0.0.1"), port


def phone_media_base_url(host, media_scheme, configured_url=""):
    """Keep the PBX host/path fixed while preserving a configured external port."""
    authority = host
    if isinstance(configured_url, str):
        match = re.fullmatch(
            r"https?://" + re.escape(host) + r"(?::([0-9]{1,5}))?/sls_mass_notify/?",
            configured_url,
            flags=re.IGNORECASE | re.ASCII,
        )
        if match and match.group(1):
            port = int(match.group(1))
            if 1 <= port <= 65535:
                authority = f"{host}:{port}"
    return f"{media_scheme}://{authority}/sls_mass_notify"


def desktop_media_base_url(host, configured_api_url=""):
    """Desktop images use HTTPS; phone transport and advertised ports stay separate."""
    authority = host
    if isinstance(configured_api_url, str):
        match = re.fullmatch(
            r"https://" + re.escape(host) + r"(?::([0-9]{1,5}))?/api/sipnotify/?",
            configured_api_url, flags=re.IGNORECASE | re.ASCII,
        )
        if match and match.group(1) and 1 <= int(match.group(1)) <= 65535:
            authority = f"{host}:{int(match.group(1))}"
    return f"https://{authority}/sls_mass_notify"


def radio_gateway_extensions(settings):
    labs = settings.get('enterprise_labs', {})
    if not isinstance(labs, dict) or labs.get('enabled') is not True:
        return []
    integrations = settings.get('enterprise_integrations', {})
    if not isinstance(integrations, dict) or integrations.get('enabled') not in ('1', 1, True):
        return []
    radios = integrations.get('radios', {})
    if not isinstance(radios, dict) or radios.get('enabled') not in ('1', 1, True):
        return []
    rows = radios.get('devices', [])
    if not isinstance(rows, list) or len(rows) > 25:
        raise ValueError('Radio gateway visual exclusions are invalid')
    seen = set(); result = []
    for row in rows:
        if (not isinstance(row, dict) or not isinstance(row.get('extension'), str)
                or not re.fullmatch(r'[0-9]{1,12}', row['extension']) or row['extension'] in seen
                or type(row.get('enabled', '0')) not in (str, int, bool)
                or row.get('enabled', '0') not in ('0', '1', 0, 1, False, True)):
            raise ValueError('Radio gateway visual exclusions are invalid')
        seen.add(row['extension'])
        if row.get('enabled', '0') in ('1', 1, True): result.append(row['extension'])
    return sorted(result)


def audio_only_visual_exclusions(config):
    raw = config.get('visual', 'audio_only_extensions', fallback='')
    rows = raw.split(',') if raw else []
    if len(rows) > 25 or any(not re.fullmatch(r'[0-9]{1,12}', row) for row in rows):
        raise ValueError('Radio gateway visual exclusions are invalid')
    return set(rows)


def load_config():
    config = configparser.ConfigParser(interpolation=None)
    if CENTRAL_SETTINGS_FILE.is_file():
        try:
            settings = _config_crypto.read_config(CENTRAL_SETTINGS_FILE)
        except Exception as exc:
            raise RuntimeError(f"Unable to read central config {CENTRAL_SETTINGS_FILE}: {exc}") from exc
        if not isinstance(settings, dict):
            raise RuntimeError(f"Central config {CENTRAL_SETTINGS_FILE} is not a JSON object")

        ami = settings.get("ami") if isinstance(settings.get("ami"), dict) else {}
        sipnotify = settings.get("sipnotify") if isinstance(settings.get("sipnotify"), dict) else {}
        desktop_rows = settings.get('desktop_clients') if isinstance(settings.get('desktop_clients'), list) else []
        desktop_bindings = {}
        for client in desktop_rows:
            if not isinstance(client, dict) or client.get('enabled') not in (True, 1, '1'):
                continue
            username = client.get('username')
            client_id = client.get('client_id')
            if (not isinstance(username, str) or re.fullmatch(r'[a-z0-9_.-]{1,48}', username) is None
                    or not isinstance(client_id, str) or re.fullmatch(r'[a-z0-9_-]{1,32}', client_id) is None
                    or username in desktop_bindings):
                raise RuntimeError('Enabled desktop enrollment identifiers are invalid or duplicated')
            desktop_bindings[username] = client_id
        format_overrides = sipnotify.get('format_overrides') if isinstance(sipnotify.get('format_overrides'), dict) else {}
        device_overrides = sipnotify.get('device_format_overrides') if isinstance(sipnotify.get('device_format_overrides'), dict) else {}
        host = str(settings.get("public_pbx_host") or sipnotify.get("pbx_host") or "localhost").strip()
        host = re.sub(r"^https?://", "", host, flags=re.I).split("/", 1)[0].strip()
        if (len(host) > 253 or re.fullmatch(r"[A-Za-z0-9.-]+", host) is None
                or '..' in host or any(not label or len(label) > 63 or label.startswith('-') or label.endswith('-')
                                      for label in host.rstrip('.').split('.'))):
            host = "localhost"
        media_scheme = str(sipnotify.get("media_scheme") or "https").strip().lower()
        if media_scheme not in {"http", "https"}:
            media_scheme = "https"

        ami_host, ami_port = resolve_local_ami_endpoint(ami)
        config.read_dict({
            "desktop": {
                "client_ids": json.dumps(desktop_bindings, separators=(",", ":")),
            },
            "nws": {
                "zone": str(settings.get("nws_zone") or ""),
                "api_base_url": str(settings.get("nws_api_base_url") or "https://api.weather.gov"),
                "poll_interval": "60",
            },
            "ami": {
                "host": ami_host,
                "port": str(ami_port),
                "username": str(ami.get("username") or "slsmassnotify"),
                "password": str(ami.get("password") or ""),
            },
            "logging": {"log_file": "/var/log/sls_mass_notify_push.log"},
            "visual": {
                "web_dir": "/var/www/html/sls_mass_notify",
                "public_base_url": phone_media_base_url(host, media_scheme, sipnotify.get("media_base_url")),
                "desktop_public_base_url": desktop_media_base_url(host, sipnotify.get("base_url")),
                "phone_capabilities": "yes" if (settings.get("media_access") or {}).get("phone_capabilities") is True else "no",
                "phone_capability_key": str(settings.get("desktop_auth_key") or ""),
                "phone_capability_generation": str((settings.get("media_access") or {}).get("phone_capability_generation") or "0000000000000000"),
                "audio_only_extensions": ','.join(radio_gateway_extensions(settings)),
                "image_width": "480",
                "image_height": "272",
                "retry_delays": "",
            },
            "api": {"events_file": str(DEFAULT_API_EVENTS_FILE)},
            "endpoint_format_overrides": {
                str(endpoint): str(fmt)
                for endpoint, fmt in format_overrides.items()
                if re.fullmatch(r'[0-9]{1,20}', str(endpoint))
            },
            "device_format_overrides": {
                str(key): str(row.get('format', ''))
                for key, row in device_overrides.items()
                if re.fullmatch('[a-f0-9]{32}', str(key)) and isinstance(row, dict)
            },
        })
        return config

    raise RuntimeError(f"Unable to read central config {CENTRAL_SETTINGS_FILE}")


def setup_logging(log_file):
    global _LOG_STREAM
    log_format = "%(asctime)s %(levelname)s: %(message)s"
    date_format = "%Y-%m-%d %H:%M:%S"
    descriptor = None
    try:
        path = Path(log_file).absolute()
        descriptor = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o640)
        opened = os.fstat(descriptor)
        named = path.lstat()
        accepted_uids = {os.geteuid()}
        accepted_gids = {os.getegid()}
        try:
            import grp
            import pwd
            accepted_uids.add(pwd.getpwnam('asterisk').pw_uid)
            accepted_gids.add(grp.getgrnam('asterisk').gr_gid)
        except KeyError:
            pass
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                or opened.st_uid not in accepted_uids or opened.st_gid not in accepted_gids
                or opened.st_mode & 0o022
                or (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino)):
            raise OSError('Notification log path is unsafe')
        os.fchmod(descriptor, 0o640)
        if logging.getLogger().handlers:
            os.close(descriptor)
            descriptor = None
            return
        _LOG_STREAM = os.fdopen(descriptor, 'a', encoding='utf-8', buffering=1)
        descriptor = None
        logging.basicConfig(
            stream=_LOG_STREAM,
            level=logging.INFO,
            format=log_format,
            datefmt=date_format,
        )
    except OSError as exc:
        if descriptor is not None:
            os.close(descriptor)
        logging.basicConfig(
            stream=sys.stderr,
            level=logging.INFO,
            format=log_format,
            datefmt=date_format,
        )
        logging.warning("Unable to open log file %s; logging to stderr: %s", log_file, exc)


def parse_time(value):
    if not isinstance(value, str) or not value:
        return None
    try:
        if value.endswith("Z"):
            value = value[:-1] + "+00:00"
        parsed = datetime.fromisoformat(value)
        return parsed.astimezone(timezone.utc) if parsed.tzinfo is not None else None
    except (OverflowError, ValueError):
        return None


def format_time(value):
    dt = parse_time(value)
    if dt is None:
        return "Unknown"
    local = dt.astimezone(LOCAL_TZ)
    return local.strftime("%I:%M %p %Z").lstrip("0")


def truncate(value, length):
    value = " ".join((value if isinstance(value, str) else "").split())
    return value[:length]


def imagemagick_text(value, limit=220):
    value = str(value or "").replace("\x00", "").replace("%", "%%")
    if value.startswith("@"):
        value = " " + value
    return value[:limit]


def description_lines(description):
    cleaned = " ".join((description if isinstance(description, str) else "").split())
    excerpt = cleaned[:56]
    lines = textwrap.wrap(excerpt, width=28, break_long_words=False, break_on_hyphens=False)
    while len(lines) < 2:
        lines.append("")
    return lines[:2]


def alert_title(event):
    event = event if isinstance(event, str) else ""
    return ALERT_MAP.get(event, DEFAULT_ALERT)[0].replace("⚠ ", "").strip()


def alert_beep(event):
    event = event if isinstance(event, str) else ""
    return ALERT_MAP.get(event, DEFAULT_ALERT)[1]


def alert_priority(props):
    event = props.get("event", "") if isinstance(props, dict) else ""
    event = event if isinstance(event, str) else ""
    if event in CRITICAL_EVENTS:
        return "critical"
    if event in URGENT_EVENTS:
        return "urgent"
    return "notice"


def image_text_lines(alert):
    props = alert.get("properties", {}) if isinstance(alert, dict) else {}
    props = props if isinstance(props, dict) else {}
    event = props.get("event", "")
    description = " ".join((props.get("description") if isinstance(props.get("description"), str) else "").split())
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
    alert = alert if isinstance(alert, dict) else {}
    props = alert.get("properties") if isinstance(alert.get("properties"), dict) else {}
    raw = str(alert.get("id") or props.get("id") or time.time())
    digest = hashlib.sha256(raw.encode("utf-8")).hexdigest()[:12]
    return f"alert_{digest}_{secrets.token_hex(10)}.png"


def compact_identifier(value):
    return (value if isinstance(value, str) else "").strip().split("/")[-1]


def alert_chain_key(alert):
    alert = alert if isinstance(alert, dict) else {}
    props = alert.get("properties", {})
    props = props if isinstance(props, dict) else {}
    alert_id = compact_identifier(alert.get("id") or props.get("id"))
    event = (props.get("event") if isinstance(props.get("event"), str) else "").strip()
    message_type = (props.get("messageType") if isinstance(props.get("messageType"), str) else "").strip().lower()
    references = props.get("references") or []
    references = references if isinstance(references, list) else []
    reference_id = ""

    # CAP references identify an Update/Cancel/Error chain. A first-issued
    # Alert must retain its own identifier even if a downstream NWS feed
    # unexpectedly includes historical references; otherwise a genuinely new
    # alert can collide with an older processed chain and be suppressed.
    if message_type == "update":
        candidates = []
        for position, ref in enumerate(references):
            if not isinstance(ref, dict):
                continue
            identifier = compact_identifier(ref.get("identifier") or ref.get("@id"))
            if not identifier:
                continue
            sent_time = parse_time(ref.get("sent"))
            sent_key = sent_time.timestamp() if sent_time is not None else float("inf")
            candidates.append((sent_key, position, identifier))
        if candidates:
            reference_id = min(candidates)[2]

    return f"{event}|{reference_id or alert_id}"


def reserve_generated_asset(web_dir, amount):
    # Private previews/test captures are not retained in the managed cache.
    if Path(web_dir) != Path('/var/www/html/sls_mass_notify'):
        return
    helper = Path('/usr/local/bin/sls_mass_notify/sls_speech_admission.py')
    info = helper.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & 0o022:
        raise RuntimeError('The protected generated-media admission helper is unavailable. Run Repair Installation.')
    result = subprocess.run(['/usr/bin/python3', '-I', str(helper), '--reserve-media-bytes', str(amount)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=8)
    if result.returncode:
        raise RuntimeError('Generated media could not reserve cache budget or free space. Review Storage diagnostics before retrying.')


def generated_asset_directory(web_dir):
    """Open the configured media directory without following or trusting a path swap."""
    web_dir = Path(web_dir).absolute()
    web_dir.mkdir(parents=True, exist_ok=True)
    named = web_dir.lstat()
    if (not stat.S_ISDIR(named.st_mode) or named.st_nlink < 2
            or named.st_mode & 0o022 or web_dir.resolve() != web_dir):
        raise OSError('Generated-media directory is unsafe')
    accepted_uids = {os.geteuid()}
    accepted_gids = {os.getegid()}
    try:
        import grp
        import pwd
        accepted_uids.add(pwd.getpwnam('asterisk').pw_uid)
        accepted_gids.add(grp.getgrnam('asterisk').gr_gid)
    except KeyError:
        pass
    if named.st_uid not in accepted_uids or named.st_gid not in accepted_gids:
        raise OSError('Generated-media directory ownership is unsafe')
    descriptor = os.open(web_dir, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    opened = os.fstat(descriptor)
    if ((opened.st_dev, opened.st_ino, opened.st_mode, opened.st_uid, opened.st_gid)
            != (named.st_dev, named.st_ino, named.st_mode, named.st_uid, named.st_gid)):
        os.close(descriptor)
        raise OSError('Generated-media directory changed while it was opened')
    return web_dir, descriptor


def create_generated_stage(directory_fd, suffix):
    for _attempt in range(32):
        name = '.sls-generated-' + secrets.token_hex(16) + suffix
        try:
            descriptor = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                                 0o600, dir_fd=directory_fd)
        except FileExistsError:
            continue
        metadata = os.fstat(descriptor)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size != 0
                or metadata.st_uid != os.geteuid() or metadata.st_gid != os.getegid()
                or stat.S_IMODE(metadata.st_mode) != 0o600):
            os.close(descriptor)
            try:
                current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                if (current.st_dev, current.st_ino) == (metadata.st_dev, metadata.st_ino):
                    os.unlink(name, dir_fd=directory_fd)
            except FileNotFoundError:
                pass
            raise OSError('Generated-media staging file is unsafe')
        return name, descriptor, metadata
    raise OSError('Unable to allocate a generated-media staging file')


def generated_stage_identity(directory_fd, name, descriptor, original, maximum=5 * 1024 * 1024,
                             require_private=True):
    opened = os.fstat(descriptor)
    named = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    identity = (original.st_dev, original.st_ino)
    if (not stat.S_ISREG(opened.st_mode) or not stat.S_ISREG(named.st_mode)
            or opened.st_nlink != 1 or named.st_nlink != 1
            or (opened.st_dev, opened.st_ino) != identity
            or (named.st_dev, named.st_ino) != identity
            or opened.st_size != named.st_size or opened.st_size > maximum
            or opened.st_mode != named.st_mode or opened.st_uid != named.st_uid
            or opened.st_gid != named.st_gid
            or (require_private and (stat.S_IMODE(opened.st_mode) != 0o600
                                     or opened.st_uid != original.st_uid
                                     or opened.st_gid != original.st_gid))):
        raise OSError('Generated-media staging file changed unexpectedly')
    return opened


def unlink_generated_stage(directory_fd, name, original):
    try:
        current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        if (current.st_dev, current.st_ino) == (original.st_dev, original.st_ino):
            os.unlink(name, dir_fd=directory_fd)
    except FileNotFoundError:
        pass


def publish_generated_stage(directory_fd, staging_name, output_name, descriptor, original):
    staged = generated_stage_identity(directory_fd, staging_name, descriptor, original)
    os.fchmod(descriptor, 0o644)
    try:
        import grp
        import pwd
        os.fchown(descriptor, pwd.getpwnam('asterisk').pw_uid, grp.getgrnam('asterisk').gr_gid)
    except (KeyError, PermissionError):
        pass
    os.fsync(descriptor)
    staged = generated_stage_identity(directory_fd, staging_name, descriptor, original, require_private=False)
    if stat.S_IMODE(staged.st_mode) != 0o644:
        raise OSError('Generated-media staging mode is unsafe for publication')
    try:
        os.link(staging_name, output_name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd,
                follow_symlinks=False)
    except FileExistsError:
        raise OSError('Generated-media destination appeared before publication') from None
    try:
        committed = os.stat(output_name, dir_fd=directory_fd, follow_symlinks=False)
        if ((committed.st_dev, committed.st_ino) != (original.st_dev, original.st_ino)
                or committed.st_size != staged.st_size or committed.st_nlink != 2):
            raise OSError('Generated-media publication identity is invalid')
        os.unlink(staging_name, dir_fd=directory_fd)
        final = os.fstat(descriptor)
        named = os.stat(output_name, dir_fd=directory_fd, follow_symlinks=False)
        if (final.st_nlink != 1 or named.st_nlink != 1
                or (named.st_dev, named.st_ino) != (original.st_dev, original.st_ino)
                or named.st_size != staged.st_size):
            raise OSError('Generated-media publication did not commit safely')
        os.fsync(directory_fd)
    except BaseException:
        try:
            current = os.stat(output_name, dir_fd=directory_fd, follow_symlinks=False)
            if (current.st_dev, current.st_ino) == (original.st_dev, original.st_ino):
                os.unlink(output_name, dir_fd=directory_fd)
        except FileNotFoundError:
            pass
        raise


def open_background_image(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
    try:
        opened = os.fstat(descriptor)
        named = os.stat(path, follow_symlinks=False)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                or opened.st_size < 1 or opened.st_size > 25 * 1024 * 1024
                or (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns)
                != (named.st_dev, named.st_ino, named.st_size, named.st_mtime_ns)):
            raise OSError('Announcement background image is unsafe')
        return descriptor, opened
    except BaseException:
        os.close(descriptor)
        raise


def phone_media_url(config, base, filename, *, now=None):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,179}\.(?:png|xml)', filename):
        raise ValueError('Invalid generated phone media filename')
    url = f"{base}/{filename}"
    if not config.getboolean('visual', 'phone_capabilities', fallback=False):
        return url
    if urlsplit(base).scheme!='https':raise ValueError('Short-lived phone media links require HTTPS. Disable the capability option for legacy HTTP phones.')
    try:
        key = base64.b64decode(config.get('visual', 'phone_capability_key', fallback=''), validate=True)
    except (ValueError, TypeError) as error:
        raise ValueError('Phone media capability key is unavailable') from error
    if len(key) != 32:
        raise ValueError('Phone media capability key is unavailable')
    expires = str(int(time.time() if now is None else now) + 600)
    generation=config.get('visual','phone_capability_generation',fallback='0000000000000000')
    if not re.fullmatch(r'[a-f0-9]{16}',generation):raise ValueError('Phone capability generation is unavailable')
    signature = hmac.new(key, f'sls-phone-media-v2\n{filename}\n{expires}\n{generation}'.encode('ascii'), hashlib.sha256).hexdigest()
    return f'{url}?sls_exp={expires}&sls_sig={signature}&sls_gen={generation}'


def render_alert_image(config, alert):
    props = alert.get("properties", {})
    priority = alert_priority(props)
    colors = ALERT_COLORS[priority]
    width = min(2000, max(1, config.getint("visual", "image_width", fallback=480)))
    height = min(2000, max(1, config.getint("visual", "image_height", fallback=272)))
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    public_base_url = config.get("visual", "public_base_url", fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    reserve_generated_asset(web_dir, 5 * 1024 * 1024)
    output_name = safe_alert_filename(alert)
    web_dir, directory_fd = generated_asset_directory(web_dir)
    staging_name = None
    output_fd = None
    staging_metadata = None
    lines = [imagemagick_text(line) for line in image_text_lines(alert)]
    try:
        staging_name, output_fd, staging_metadata = create_generated_stage(directory_fd, '.png')
        command = [
            "/usr/bin/convert",
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
            "-alpha", "off", "-colorspace", "sRGB", "-strip", "-depth", "8", "-interlace", "none",
            "-define", "png:color-type=2", "PNG24:/proc/self/fd/" + str(output_fd),
        ]
        result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True,
                                timeout=30, pass_fds=(output_fd,))
        if result.returncode != 0:
            raise RuntimeError((result.stderr or "ImageMagick convert failed").strip())
        generated_stage_identity(directory_fd, staging_name, output_fd, staging_metadata)
        validate_rendered_png(output_fd, width, height)
        publish_generated_stage(directory_fd, staging_name, output_name, output_fd, staging_metadata)
        staging_name = None
        return phone_media_url(config, public_base_url, output_name)
    finally:
        if staging_name is not None:
            unlink_generated_stage(directory_fd, staging_name, staging_metadata)
        if output_fd is not None:
            os.close(output_fd)
        os.close(directory_fd)


# Periodic storage maintenance owns generated-media retention and protects
# queued work, desktop journal references and active playback reservations.

def validate_rendered_png(descriptor, expected_width, expected_height):
    before = os.fstat(descriptor)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_size < 128 or before.st_size > 5 * 1024 * 1024):
        raise RuntimeError("Generated phone image is missing or empty")
    result = subprocess.run(
        ["/usr/bin/identify", "-format", "%m %w %h %[depth] %[colorspace]", f"/proc/self/fd/{descriptor}"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=5,
        pass_fds=(descriptor,),
    )
    after = os.fstat(descriptor)
    if ((after.st_dev, after.st_ino, after.st_mode, after.st_uid, after.st_gid,
         after.st_nlink, after.st_size, after.st_mtime_ns)
            != (before.st_dev, before.st_ino, before.st_mode, before.st_uid,
                before.st_gid, before.st_nlink, before.st_size, before.st_mtime_ns)):
        raise RuntimeError("Generated phone image changed during validation")
    details = (result.stdout or "").strip()
    expected = f"PNG {expected_width} {expected_height} 8 sRGB"
    if result.returncode != 0 or details.lower() != expected.lower():
        raise RuntimeError(f"Generated phone image failed validation: {details or result.stderr.strip()}")


def normalize_announcement_timeout_seconds(value):
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = 0
    return min(86400, max(0, parsed))


def yealink_image_xml(image_url, beep="yes", width=480, height=272, timeout_seconds=0):
    width = max(1, min(2000, int(width)))
    height = max(1, min(2000, int(height)))
    timeout_seconds = normalize_announcement_timeout_seconds(timeout_seconds)
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        f"<YealinkIPPhoneImageScreen Beep='{html.escape(beep)}' Timeout='{timeout_seconds}' LockIn='no' mode='fullscreen'>"
        f"<Image horizontalAlign='middle' verticalAlign='middle' width='{width}' height='{height}'>{html.escape(image_url)}</Image>"
        "</YealinkIPPhoneImageScreen>"
    )


def build_image_xml(config, alert, image_url):
    event = alert.get("properties", {}).get("event", "")
    beep = alert_beep(event)
    return yealink_image_xml(
        image_url,
        beep,
        config.getint("visual", "image_width", fallback=480),
        config.getint("visual", "image_height", fallback=272),
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


def build_announcement_xml(message, timeout_seconds=0, title='Announcement'):
    message = message if isinstance(message, str) else ""
    cleaned = "\n".join(line.strip() for line in message.splitlines()).strip()
    if not cleaned:
        cleaned = "Announcement"
    wrapped = []
    for paragraph in cleaned.splitlines():
        wrapped.extend(textwrap.wrap(paragraph, width=32, break_long_words=False, break_on_hyphens=False) or [""])
    escaped_text = "&#10;".join(html.escape(line) for line in wrapped[:10])
    timeout_seconds = normalize_announcement_timeout_seconds(timeout_seconds)
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        f"<YealinkIPPhoneTextScreen Beep='yes' Timeout='{timeout_seconds}' LockIn='no'>"
        f"<Title wrap='yes'>{html.escape(clean_payload_text(title, 80) or 'Announcement')}</Title>"
        f"<Text>{escaped_text}</Text>"
        "<SoftKey index='1'>"
        "<Label>Dismiss</Label>"
        "<URI>SoftKey:Exit</URI>"
        "</SoftKey>"
        "</YealinkIPPhoneTextScreen>"
    )



def clean_payload_text(value, limit=700):
    value = re.sub(r"\s+", " ", (value if isinstance(value, str) else "").strip())
    return value[:limit]


def split_payload_lines(value, width=32, max_lines=10):
    value = value if isinstance(value, str) else ""
    cleaned = "\n".join(line.strip() for line in value.splitlines()).strip() or "Announcement"
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
    if not isinstance(xml_payload, str):
        raise ValueError('Hosted phone payload must be text')
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    public_base_url = config.get("visual", "public_base_url", fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    body = xml_payload.encode('utf-8') + b'\n'
    reserve_generated_asset(web_dir, len(body) + 4096)
    payload_hash = hashlib.sha256(xml_payload.encode("utf-8")).hexdigest()[:12]
    output_name = f"phone_payload_{payload_hash}_{secrets.token_hex(10)}.xml"
    _web_dir, directory_fd = generated_asset_directory(web_dir)
    staging_name = None
    staging_fd = None
    staging_metadata = None
    try:
        staging_name, staging_fd, staging_metadata = create_generated_stage(directory_fd, '.xml')
        written = 0
        while written < len(body):
            count = os.write(staging_fd, body[written:])
            if count <= 0:
                raise OSError('Hosted phone payload could not be staged')
            written += count
        generated_stage_identity(directory_fd, staging_name, staging_fd, staging_metadata,
                                 maximum=JOURNAL_MAX_LINE_BYTES)
        publish_generated_stage(directory_fd, staging_name, output_name, staging_fd, staging_metadata)
        staging_name = None
        return phone_media_url(config, public_base_url, output_name)
    finally:
        if staging_name is not None:
            unlink_generated_stage(directory_fd, staging_name, staging_metadata)
        if staging_fd is not None:
            os.close(staging_fd)
        os.close(directory_fd)


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


def panasonic_text_xml(title, message):
    lines = split_payload_lines(f"{title}\n{clean_payload_text(message)}", width=30, max_lines=6)
    labels = []
    for index, line in enumerate(lines, start=1):
        labels.append(
            f"<Label area='Line' name='Line{index}' line='{index}' text='{html.escape(line, quote=True)}' />"
        )
    return (
        "<?xml version='1.0' encoding='UTF-8'?>"
        "<ppxml xmlns='http://panasonic/sip_screen' xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'>"
        "<Screen version='3.0'><Components>"
        + "".join(labels)
        + "</Components></Screen></ppxml>"
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
    if fmt == "panasonic":
        return panasonic_text_xml(title, message)
    if fmt in {"generic", "sangoma", "avaya", "vtech", "ale", "unknown"}:
        return generic_text_xml(title, message)
    return build_announcement_xml(message)


def normalize_hex_color(value, fallback="#1f2937"):
    value = (value if isinstance(value, str) else "").strip()
    if re.match(r"^#[0-9A-Fa-f]{6}$", value):
        return value
    return fallback


def contrasting_text_color(background):
    """Choose the higher WCAG relative-luminance contrast for a solid color."""
    color = normalize_hex_color(background)
    channels = [int(color[offset:offset + 2], 16) / 255 for offset in (1, 3, 5)]
    linear = [value / 12.92 if value <= 0.04045 else ((value + 0.055) / 1.055) ** 2.4 for value in channels]
    luminance = sum(weight * value for weight, value in zip((0.2126, 0.7152, 0.0722), linear))
    white_contrast = 1.05 / (luminance + 0.05)
    black_contrast = (luminance + 0.05) / 0.05
    return "#000000" if black_contrast > white_contrast else "#ffffff"


def render_announcement_image(config, title, message, background_color="#1f2937", background_image="", desktop=False):
    # Render text at its target resolution; upscaling the phone bitmap blurs it.
    scale = 3 if desktop else 1
    width = 1440 if desktop else min(2000, max(1, config.getint("visual", "image_width", fallback=480)))
    height = 816 if desktop else min(2000, max(1, config.getint("visual", "image_height", fallback=272)))
    web_dir = Path(config.get("visual", "web_dir", fallback="/var/www/html/sls_mass_notify"))
    url_setting = "desktop_public_base_url" if desktop else "public_base_url"
    public_base_url = config.get("visual", url_setting, fallback="https://PBX_HOST/sls_mass_notify").rstrip("/")
    reserve_generated_asset(web_dir, 5 * 1024 * 1024)

    title = title if isinstance(title, str) else ""
    message = message if isinstance(message, str) else ""
    title = imagemagick_text((title or "Announcement").strip(), 48) or "Announcement"
    cleaned = " ".join(message.split()).strip() or "Announcement"
    body_lines = [imagemagick_text(line) for line in textwrap.wrap(cleaned, width=34, break_long_words=False, break_on_hyphens=False)[:5]]
    while len(body_lines) < 5:
        body_lines.append("")

    output_name = "announcement_" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S") + "_" + secrets.token_hex(12) + ("_desktop.png" if desktop else ".png")
    background_color = normalize_hex_color(background_color)
    background_image_path = Path(background_image) if background_image else None
    web_dir, directory_fd = generated_asset_directory(web_dir)
    staging_name = None
    output_fd = None
    staging_metadata = None
    background_fd = None
    try:
        staging_name, output_fd, staging_metadata = create_generated_stage(directory_fd, '.png')
        pass_fds = [output_fd]
        if background_image_path:
            background_fd, background_metadata = open_background_image(background_image_path)
            pass_fds.append(background_fd)
            body_color = "#ffffff"
            command = [
                "/usr/bin/convert", f"/proc/self/fd/{background_fd}",
                "-auto-orient", "-resize", f"{width}x{height}^", "-gravity", "center", "-extent", f"{width}x{height}",
                "-fill", "rgba(0,0,0,0.65)", "-draw", f"rectangle 0,0 {width},{height}",
                "-fill", "rgba(17,24,39,0.82)", "-draw", f"rectangle 0,0 {width},{72 * scale}",
            ]
        else:
            background_metadata = None
            body_color = contrasting_text_color(background_color)
            command = [
                "/usr/bin/convert", "-size", f"{width}x{height}", f"xc:{background_color}",
                "-fill", "rgba(17,24,39,0.88)", "-draw", f"rectangle 0,0 {width},{72 * scale}",
            ]

        command.extend([
            "-fill", "#fbbf24", "-draw", f"rectangle 0,{72 * scale} {width},{78 * scale}",
            "-font", "DejaVu-Sans-Bold", "-fill", "#ffffff", "-gravity", "North",
            "-pointsize", str(30 * scale), "-annotate", f"+0+{18 * scale}", title,
            "-font", "DejaVu-Sans", "-fill", body_color, "-gravity", "NorthWest",
            "-pointsize", str(20 * scale), "-annotate", f"+{30 * scale}+{108 * scale}", body_lines[0],
            "-pointsize", str(20 * scale), "-annotate", f"+{30 * scale}+{136 * scale}", body_lines[1],
            "-pointsize", str(20 * scale), "-annotate", f"+{30 * scale}+{164 * scale}", body_lines[2],
            "-pointsize", str(20 * scale), "-annotate", f"+{30 * scale}+{192 * scale}", body_lines[3],
            "-pointsize", str(20 * scale), "-annotate", f"+{30 * scale}+{220 * scale}", body_lines[4],
            "-alpha", "off", "-colorspace", "sRGB", "-strip", "-depth", "8", "-interlace", "none",
            "-define", "png:color-type=2", "PNG24:/proc/self/fd/" + str(output_fd),
        ])
        result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True,
                                timeout=30, pass_fds=tuple(pass_fds))
        if result.returncode != 0:
            raise RuntimeError((result.stderr or "ImageMagick convert failed").strip())
        if os.fstat(output_fd).st_size > 5 * 1024 * 1024:
            raise RuntimeError("Generated desktop image exceeds the application's 5 MiB download limit"
                               if desktop else "Generated phone image exceeds the 5 MiB limit")
        generated_stage_identity(directory_fd, staging_name, output_fd, staging_metadata)
        if background_fd is not None:
            background_after = os.fstat(background_fd)
            if ((background_after.st_dev, background_after.st_ino, background_after.st_size,
                 background_after.st_mtime_ns)
                    != (background_metadata.st_dev, background_metadata.st_ino,
                        background_metadata.st_size, background_metadata.st_mtime_ns)):
                raise OSError('Announcement background image changed during rendering')
        validate_rendered_png(output_fd, width, height)
        publish_generated_stage(directory_fd, staging_name, output_name, output_fd, staging_metadata)
        staging_name = None
        return f"{public_base_url}/{output_name}" if desktop else phone_media_url(config, public_base_url, output_name)
    finally:
        if background_fd is not None:
            os.close(background_fd)
        if staging_name is not None:
            unlink_generated_stage(directory_fd, staging_name, staging_metadata)
        if output_fd is not None:
            os.close(output_fd)
        os.close(directory_fd)


def build_announcement_image_xml(config, message, title="Announcement", background_color="#1f2937", background_image="", timeout_seconds=0):
    image_url = render_announcement_image(config, title, message, background_color, background_image)
    return yealink_image_xml(
        image_url,
        "yes",
        config.getint("visual", "image_width", fallback=480),
        config.getint("visual", "image_height", fallback=272),
        timeout_seconds,
    )


def build_xml(config, alert):
    try:
        return build_image_xml(config, alert, render_alert_image(config, alert))
    except Exception as exc:
        logging.error("Unable to render alert image; falling back to text XML: %s", exc)
        return build_text_xml(alert)


def normalize_target_list(value):
    targets = set()
    value = value if isinstance(value, str) else ""
    for item in value.replace(";", ",").split(","):
        item = item.strip()
        if not item:
            continue
        if re.fullmatch(r"[0-9]{1,20}", item) is None:
            raise ValueError('Phone targets must be numeric PBX extensions of 1–20 digits')
        targets.add(item)
    return targets


def normalize_desktop_target_list(value):
    targets = set()
    value = value if isinstance(value, str) else ""
    for item in value.replace(";", ",").split(","):
        item = item.strip().lower()
        if not item:
            continue
        if re.fullmatch(r"[a-z0-9_.-]{1,48}", item) is None:
            raise ValueError('Desktop targets must be canonical saved usernames')
        targets.add(item)
    return targets


PHONE_FORMAT_ALIASES = {
    "polycom": "poly",
    "poly-com": "poly",
    "mitel": "aastra",
    "generic_xml": "generic",
    "yealink_xml": "yealink",
    "cisco_xml": "cisco",
    "panasonic_kx": "panasonic",
}

SUPPORTED_PHONE_FORMATS = {
    "yealink",
    "yealink_text",
    "yealink_image",
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
    "panasonic",
    "generic",
    "unknown",
}


def normalize_phone_format(value):
    fmt = re.sub(r"[^a-z0-9_-]+", "", (value or "").strip().lower())
    fmt = PHONE_FORMAT_ALIASES.get(fmt, fmt)
    return fmt if fmt in SUPPORTED_PHONE_FORMATS else ""


def endpoint_format_overrides(config):
    overrides = {}
    for endpoint, fmt in config.items("endpoint_format_overrides") if config.has_section("endpoint_format_overrides") else []:
        endpoint = endpoint.strip() if isinstance(endpoint, str) else ""
        fmt = normalize_phone_format(fmt)
        if re.fullmatch(r"[0-9]{1,20}", endpoint) and fmt:
            overrides[endpoint] = fmt
    for key, fmt in config.items('device_format_overrides') if config.has_section('device_format_overrides') else []:
        if re.fullmatch('[a-f0-9]{32}', key) and normalize_phone_format(fmt):
            overrides['device:' + key] = normalize_phone_format(fmt)
    return overrides


def saved_phone_format_overrides(settings):
    """Use the same normalization for background display and live delivery."""
    sipnotify = settings.get('sipnotify') if isinstance(settings.get('sipnotify'), dict) else {}
    format_rows = sipnotify.get('format_overrides') if isinstance(sipnotify.get('format_overrides'), dict) else {}
    device_rows = sipnotify.get('device_format_overrides') if isinstance(sipnotify.get('device_format_overrides'), dict) else {}
    config = configparser.ConfigParser(interpolation=None)
    config.read_dict({
        'endpoint_format_overrides': {str(key): str(value) for key, value in format_rows.items()
                                      if re.fullmatch(r'[0-9]{1,20}', str(key))},
        'device_format_overrides': {str(key): str(value.get('format', '')) for key, value in device_rows.items()
                                    if re.fullmatch('[a-f0-9]{32}', str(key)) and isinstance(value, dict)},
    })
    return endpoint_format_overrides(config)


def device_registration_key(endpoint, uri):
    # Bind the override to this registration, never to an extension's other
    # contacts. Address/port changes intentionally fall back to autodetection.
    return hashlib.sha256((str(endpoint) + '\n' + str(uri)).encode()).hexdigest()[:32] if uri else ''


def contact_transport(uri):
    uri = str(uri).lower()
    transport = re.search(r';transport=([a-z]+)(?:[;?]|$)', uri)
    if transport:
        return transport.group(1) if transport.group(1) in {'udp', 'tcp', 'tls', 'ws', 'wss'} else 'unresolved'
    return 'tls' if uri.startswith('sips:') else ('udp' if uri.startswith('sip:') else 'unresolved')


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
    if "alcatel-lucent" in ua or "alcatel lucent" in ua or "ale deskphone" in ua:
        return "ale"
    if "panasonic" in ua or re.search(r"kx-(?:hdv|ut|tgp|uds|udt)", ua):
        return "panasonic"
    return "unknown"


def asterisk_contact_uri_inventory(endpoints, max_seconds=5, submission_guard=None):
    inventory = {}
    deadline = time.monotonic() + max_seconds
    for endpoint in sorted(set(endpoints or [])):
        if submission_guard is not None and not submission_guard():
            raise RuntimeError('The connected-contact inventory lease expired')
        if not re.fullmatch(r"[0-9]{1,20}", endpoint):
            continue
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            break
        try:
            result = subprocess.run(
                ["/usr/sbin/asterisk", "-rx", f"pjsip show aor {endpoint}"],
                cwd="/",
                capture_output=True,
                text=True,
                timeout=min(1 if submission_guard is not None else 5, remaining),
                check=False,
            )
        except Exception as exc:
            logging.warning("Unable to query Asterisk contact URIs for endpoint %s: %s", endpoint, exc)
            continue
        if result.returncode != 0:
            logging.warning(
                "Asterisk contact URI query failed for endpoint %s; SIP NOTIFY will use endpoint fallback: %s",
                endpoint,
                (result.stderr or result.stdout or "unknown error").strip()[:300],
            )
            continue
        uris = []
        for line in result.stdout.splitlines():
            match = re.match(r"^\s*contact\s*:\s*((?:sips?):\S+)\s*$", line, re.IGNORECASE)
            uri = registered_contact_uri(match.group(1)) if match else ''
            if uri and uri not in uris:
                uris.append(uri)
        if uris:
            inventory[endpoint] = uris
    return inventory


def contact_uri_for_event(event, endpoint, inventory, contact_index=0):
    direct = registered_contact_uri(ami_field(event, "Contact", "Uri"))
    if direct:
        return direct
    candidates = inventory.get(endpoint) or []
    # CLI and AMI list order is not a stable identity binding. With multiple
    # registrations, an unresolved URI must not borrow another phone's format.
    return candidates[0] if len(candidates) == 1 else ""


def get_registered_endpoint_info(ami, allowed_targets=None, format_overrides=None, resolve_contact_uris=False, submission_guard=None):
    response, events = ami.action({"Action": "PJSIPShowContacts"}, "ContactListComplete")
    if response.get("Response", "").lower() == "error":
        if ami_response_is_empty_contact_inventory(response):
            return {}
        raise RuntimeError("PJSIPShowContacts failed: " + notify_ami_response_summary(response)["message_category"])
    allowed_targets = set(allowed_targets or [])
    format_overrides = format_overrides or {}
    candidate_endpoints = set()
    for event in events:
        if ami_field(event, "Event") != "ContactList":
            continue
        endpoint = str(ami_field(event, "Endpoint", "AOR", "ObjectName")).split("/", 1)[0].strip()
        if endpoint and (not allowed_targets or endpoint in allowed_targets) and not registered_contact_uri(ami_field(event, 'Contact', 'Uri')):
            candidate_endpoints.add(endpoint)
    contact_uri_inventory = asterisk_contact_uri_inventory(candidate_endpoints,
        **({'submission_guard': submission_guard} if submission_guard is not None else {})) if resolve_contact_uris else {}
    if submission_guard is not None and not submission_guard():
        raise RuntimeError('The connected-contact inventory lease expired')
    contact_uri_offsets = {}
    endpoints = {}
    skipped = []
    registered_statuses = {"reachable", "nonqualified", "avail", "available", "ok", "unknown"}
    blocked_statuses = {"unreachable", "unavailable", "unavail", "removed", "rejected", "failed"}
    for event in events:
        if ami_field(event, "Event") != "ContactList":
            continue
        endpoint = str(ami_field(event, "Endpoint", "AOR", "ObjectName")).strip()
        if not endpoint:
            contact = str(ami_field(event, "Contact", "Uri")).strip()
            endpoint = contact.split("/", 1)[0] if "/" in contact else contact
        endpoint = endpoint.split("/", 1)[0].strip()
        status = str(ami_field(event, "Status")).strip()
        user_agent = str(ami_field(event, "UserAgent")).strip()
        if not endpoint:
            continue
        if re.fullmatch(r'[0-9]{1,20}', endpoint) is None and not allowed_targets:
            logging.info("Skipping non-extension PJSIP contact %s status=%s user_agent=%s", endpoint, status or "Unknown", user_agent or "unknown")
            continue
        if allowed_targets and endpoint not in allowed_targets:
            logging.info("Skipping endpoint %s because it is not in the requested target list", endpoint)
            continue
        normalized_status = status.lower().split()[0] if status else "unknown"
        if normalized_status in registered_statuses and normalized_status not in blocked_statuses:
            contact_index = contact_uri_offsets.get(endpoint, 0)
            contact_uri_offsets[endpoint] = contact_index + 1
            contact_uri = contact_uri_for_event(event, endpoint, contact_uri_inventory, contact_index)
            device_key = device_registration_key(endpoint, contact_uri)
            detected_format = detect_phone_format(user_agent, endpoint)
            override_format = format_overrides.get('device:' + device_key, '') or format_overrides.get(endpoint, "")
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
                "contact": contact_uri,
                "device_key": device_key,
                "override": bool(override_format),
                "capability": 'unverified_generic' if fmt == 'unknown' else 'vendor_template',
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


def build_phone_xml_for_format(config, fmt, payload_type, alert=None, message="", image=False, title="Announcement", background_color="#1f2937", background_image="", timeout_seconds=0):
    fmt = fmt or "yealink"
    if payload_type == "alert":
        if fmt in {"yealink", "yealink_image"}:
            return build_xml(config, alert)
        if fmt == "yealink_text":
            return build_text_xml(alert)
        alert_title_text, alert_message = phone_title_and_message_from_alert(alert)
        payload = text_xml_for_format(fmt, alert_title_text, alert_message)
        if fmt == "cisco":
            return cisco_execute_xml(hosted_phone_payload(config, payload))
        return payload
    # Opt-in for color handsets whose text browser is unreliable. Keep the
    # desktop's plain-text presentation and every other phone format unchanged.
    if fmt == "yealink_image" or (image and fmt == "yealink"):
        return build_announcement_image_xml(config, message, title, background_color, background_image, timeout_seconds)
    if fmt in {"yealink", "yealink_text"}:
        return build_announcement_xml(message, timeout_seconds, title)
    payload = text_xml_for_format(fmt, title or "Announcement", message)
    if fmt == "cisco":
        return cisco_execute_xml(hosted_phone_payload(config, payload))
    return payload


def notify_events_for_format(phone_format):
    phone_format = (phone_format or "yealink").lower()
    events = {
        "yealink": ["Yealink-xml"],
        "yealink_text": ["Yealink-xml"],
        "yealink_image": ["Yealink-xml"],
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
        "panasonic": ["xml"],
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
    if phone_format in {"yealink", "yealink_text", "yealink_image"}:
        return "application/xml"
    if phone_format in {"poly", "polycom"}:
        return "application/x-com-polycom-spipx"
    return "text/xml"


def registered_contact_uri(contact):
    raw = str(contact or "").strip()
    if not raw or "\r" in raw or "\n" in raw:
        return ""
    match = re.search(r"(?:sips?):[^\s>]+", raw, re.IGNORECASE)
    if not match:
        return ""
    uri = match.group(0).rstrip(",;")
    if len(uri) > 2048 or any(ord(char) < 33 or ord(char) > 126 for char in uri):
        return ""
    return uri


def parse_pjsip_notify_capabilities(manager_help, pjsip_settings, default_endpoint_details=""):
    manager_help = str(manager_help or "")
    pjsip_settings = str(pjsip_settings or "")
    endpoint_target = re.search(r"(?im)^\s*\[?Endpoint:?\]?\s*(?:<value>)?", manager_help) is not None
    uri_target = re.search(r"(?im)^\s*\[?URI:?\]?\s*(?:<value>)?", manager_help) is not None
    match = re.search(r"(?im)^\s*default_outbound_endpoint\s*:\s*(\S*)\s*$", pjsip_settings)
    default_endpoint = match.group(1).strip() if match else ""
    if default_endpoint.lower() in {"", "none", "<none>"}:
        default_endpoint = ""
    endpoint_details = str(default_endpoint_details or "")
    default_endpoint_available = bool(
        default_endpoint
        and "unable to find object" not in endpoint_details.lower()
        and re.search(rf"(?im)^\s*Endpoint:\s+{re.escape(default_endpoint)}(?:/|\s)", endpoint_details)
    )
    return {
        "endpoint_target": endpoint_target,
        "uri_target": uri_target,
        "default_outbound_endpoint": default_endpoint,
        "default_outbound_endpoint_available": default_endpoint_available,
        "contact_uri_usable": bool(uri_target and default_endpoint_available),
        "routing_mode": "contact_uri" if uri_target and default_endpoint_available else "endpoint_fanout",
    }


def pjsip_notify_capabilities():
    def run_cli(command):
        try:
            return subprocess.run(
                ["/usr/sbin/asterisk", "-rx", command],
                cwd="/",
                capture_output=True,
                text=True,
                timeout=8,
                check=False,
            )
        except Exception as exc:
            logging.warning("Unable to inspect Asterisk SIP NOTIFY capability %s: %s", command, exc)
            return None

    manager = run_cli("manager show command PJSIPNotify")
    settings = run_cli("pjsip show settings")
    manager_text = (manager.stdout + manager.stderr) if manager else ""
    settings_text = (settings.stdout + settings.stderr) if settings else ""
    initial = parse_pjsip_notify_capabilities(manager_text, settings_text)
    details_text = ""
    if initial["default_outbound_endpoint"]:
        details = run_cli(f"pjsip show endpoint {initial['default_outbound_endpoint']}")
        details_text = (details.stdout + details.stderr) if details else ""
    capabilities = parse_pjsip_notify_capabilities(manager_text, settings_text, details_text)
    # Endpoint targeting is the portable Asterisk route. If introspection is
    # unavailable at runtime, attempt it and let AMI return a concrete error.
    if not manager_text:
        capabilities["endpoint_target"] = True
    return capabilities


def safe_notify_log_value(value):
    """Bound log identifiers and hide URI credentials without changing send targets."""
    value = str(value or "")
    value = re.sub(r"(?i)((?:sips?:|https?://)[^@\s<>]*?):[^@\s<>]*@", r"\1:[redacted]@", value)
    value = re.sub(r"(?i)\b(?:basic|bearer)\s+[a-z0-9+/_.~=-]+", "[redacted]", value)
    value = re.sub(
        r"(?i)\b(password|passwd|secret|token|api[_-]?key|authorization)(\s*[:=]\s*)(?:\"[^\"]*\"|'[^']*'|[^\s,;]+)",
        r"\1\2[redacted]",
        value,
    )
    return re.sub(r"[\x00-\x1f\x7f]", " ", value)[:512]


def notify_ami_response_summary(response):
    """Do not log arbitrary AMI fields, messages, payloads, or credentials."""
    response_name = str(ami_field(response, "Response") or "").strip().lower()
    message = str(ami_field(response, "Message") or "").lower()
    if response_name == "success":
        category = "request_accepted"
    elif any(word in message for word in ("permission", "authorization", "authentication", "denied")):
        category = "authorization_failed"
    elif "endpoint" in message and any(word in message for word in ("find", "retrieve", "exist", "not found")):
        category = "endpoint_unavailable"
    elif response_name == "error":
        category = "request_rejected"
    else:
        category = "no_explicit_success"
    return {
        "response": response_name if response_name in {"success", "error"} else "missing_or_unrecognized",
        "message_category": category,
    }


class NotifySubmissionResult(str):
    def __new__(cls, message, details):
        result = super().__new__(cls, message)
        result.details = details
        return result


class NotifySubmissionError(RuntimeError):
    def __init__(self, message, details):
        super().__init__(message)
        self.details = details


class NotifyBatchError(RuntimeError):
    def __init__(self, message, result):
        super().__init__(message)
        self.result = result


def send_notify(ami, target, xml_payload, phone_format="yealink", target_field="Endpoint", coordination_deadline=None):
    if target_field not in {"Endpoint", "URI"}:
        raise ValueError("Invalid PJSIPNotify target field")
    if coordination_deadline is not None and (isinstance(coordination_deadline,bool)
            or not isinstance(coordination_deadline,(int,float)) or not math.isfinite(coordination_deadline)):
        raise ValueError('Invalid live-page coordination deadline')
    content_type = notify_content_type_for_format(phone_format)
    attempts = []
    def coordination_budget():
        return {} if coordination_deadline is None else {'timeout':min(2,max(.05,coordination_deadline-time.monotonic()))}
    for event_name in notify_events_for_format(phone_format):
        claim = None
        try:
            claim = _cluster_guard.begin('sip_notify', str(target) + ':' + event_name,
                                         {'xml': xml_payload, 'event': event_name, 'target_field': target_field}, **coordination_budget())
            response, _ = ami.action({
                "Action": "PJSIPNotify",
                target_field: target,
                "Variable": [
                    f"Event={event_name}",
                    f"Content-Type={content_type}",
                    f"Content={xml_payload}",
                ],
            })
            _cluster_guard.finish(claim, uncertain=False, category='ami_response_received', **coordination_budget())
        except Exception:
            try:
                _cluster_guard.finish(claim, uncertain=True, category='ami_submission_uncertain', **coordination_budget())
            except Exception:
                pass
            attempts.append({"event": event_name, "response": "unavailable", "message_category": "ami_action_error"})
            # Preserve the existing no-retry-on-transport-error behavior. The
            # server may have received the action before the connection failed.
            raise NotifySubmissionError("PJSIPNotify AMI action failed; submission not confirmed", {
                "status": "failed", "event_attempts": attempts,
                "event_fallback_outcome": "failed" if len(attempts) > 1 else "not_used",
            }) from None
        attempt = {"event": event_name, **notify_ami_response_summary(response)}
        attempts.append(attempt)
        if attempt["response"] == "success":
            details = {
                "status": "submitted_to_asterisk", "event_attempts": attempts,
                "event_fallback_outcome": "submitted_to_asterisk" if len(attempts) > 1 else "not_used",
            }
            return NotifySubmissionResult(
                f"{event_name}: Asterisk accepted the submission target={target_field} content_type={content_type}",
                details,
            )
        logging.warning(
            "PJSIPNotify event=%s content_type=%s failed for target_field=%s target=%s format=%s ami_response=%s message_category=%s",
            event_name, content_type, target_field, safe_notify_log_value(target), safe_notify_log_value(phone_format),
            attempt["response"], attempt["message_category"],
        )
    raise NotifySubmissionError("PJSIPNotify submission failed: " + "; ".join(
        f"{attempt['event']}: {attempt['message_category']}" for attempt in attempts
    ), {
        "status": "failed", "event_attempts": attempts,
        "event_fallback_outcome": "failed" if len(attempts) > 1 else "not_used",
    })


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
        if 0 < delay <= 300:
            delays.append(delay)
        elif delay > 300:
            logging.warning('Ignoring visual retry delay above the five-minute limit')
    return sorted(set(delays))[:5]


def api_events_file(config):
    return Path(config.get("api", "events_file", fallback=str(DEFAULT_API_EVENTS_FILE)))


def image_url_from_xml(xml_payload):
    match = re.search(r"<Image[^>]*>(.*?)</Image>", xml_payload)
    if not match:
        return ""
    return html.unescape(match.group(1)).strip()


def set_journal_file_metadata(file_descriptor):
    os.fchmod(file_descriptor, 0o640)
    try:
        import grp
        import pwd
        os.fchown(
            file_descriptor,
            pwd.getpwnam("asterisk").pw_uid,
            grp.getgrnam("asterisk").gr_gid,
        )
    except Exception:
        pass


# Match the maintenance pruner's file/line limits. Scan older journals fully
# within these bounds before retaining their newest 1,000 valid records.
JOURNAL_MAX_BYTES = 64 * 1024 * 1024
JOURNAL_MAX_LINE_BYTES = 256 * 1024
JOURNAL_MAX_LINES = 100000
JOURNAL_MAX_RECORDS = 1000
JOURNAL_LOCK_SECONDS = 1.0


def journal_accepted_ids():
    uids = {os.geteuid()}
    gids = {os.getegid()}
    try:
        import grp
        import pwd
        uids.add(pwd.getpwnam('asterisk').pw_uid)
        gids.add(grp.getgrnam('asterisk').gr_gid)
    except KeyError:
        pass
    return uids, gids


def journal_metadata(metadata):
    return (metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_uid,
            metadata.st_gid, metadata.st_nlink, metadata.st_size, metadata.st_mtime_ns)


def journal_identity(file_descriptor, path, maximum=JOURNAL_MAX_BYTES):
    opened = os.fstat(file_descriptor)
    named = os.stat(path, follow_symlinks=False)
    accepted_uids, accepted_gids = journal_accepted_ids()
    if (not stat.S_ISREG(opened.st_mode) or not stat.S_ISREG(named.st_mode)
            or opened.st_nlink != 1 or named.st_nlink != 1
            or stat.S_IMODE(opened.st_mode) not in (0o600, 0o640)
            or opened.st_uid not in accepted_uids or opened.st_gid not in accepted_gids
            or opened.st_size > maximum or journal_metadata(opened) != journal_metadata(named)):
        raise OSError("Desktop journal path is not a single regular file")
    return opened


def open_journal_file(path, maximum=JOURNAL_MAX_BYTES):
    flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
    descriptor = os.open(path, flags, 0o640)
    try:
        opened = os.fstat(descriptor)
        named = os.stat(path, follow_symlinks=False)
        accepted_uids, accepted_gids = journal_accepted_ids()
        if opened.st_size > maximum or named.st_size > maximum:
            raise OSError('Desktop journal exceeds the byte limit; existing evidence was preserved')
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                or opened.st_mode & 0o022 or opened.st_uid not in accepted_uids
                or opened.st_gid not in accepted_gids
                or journal_metadata(opened) != journal_metadata(named)):
            raise OSError('Desktop journal path is unsafe')
        # Bounded migration for older 0644 journals: validate the descriptor
        # first, then make it private before any content is read.
        set_journal_file_metadata(descriptor)
        journal_identity(descriptor, path, maximum)
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def lock_journal_file(descriptor, deadline):
    while True:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return
        except BlockingIOError:
            if time.monotonic() >= deadline:
                raise OSError("Desktop journal is busy; publication was not recorded") from None
            time.sleep(0.01)


def read_journal_lines(handle, path, expected_metadata):
    from collections import deque
    retained = deque()
    if os.fstat(handle.fileno()).st_size > JOURNAL_MAX_BYTES:
        raise OSError("Desktop journal exceeds the byte limit; existing evidence was preserved")
    total = 0
    count = 0
    deadline = time.monotonic() + 5.0
    while True:
        raw = handle.readline(JOURNAL_MAX_LINE_BYTES + 1)
        if not raw:
            break
        total += len(raw)
        count += 1
        if total > JOURNAL_MAX_BYTES or count > JOURNAL_MAX_LINES or time.monotonic() > deadline:
            raise OSError("Desktop journal exceeds the scan limit; existing evidence was preserved")
        if len(raw) > JOURNAL_MAX_LINE_BYTES:
            raise OSError("Desktop journal event exceeds the line limit; existing evidence was preserved")
        if not raw.strip():
            continue
        try:
            line = raw.decode("utf-8").rstrip("\r\n")
            decoded = json.loads(line, object_pairs_hook=_config_crypto._pairs,
                                 parse_constant=_config_crypto._invalid_constant,
                                 parse_float=_config_crypto._finite_float)
            if not isinstance(decoded, dict):
                raise ValueError("Journal records must be objects")
        except (UnicodeError, ValueError, RecursionError):
            raise OSError("Desktop journal contains a corrupt record; existing evidence was preserved") from None
        retained.append(line)
    after = journal_identity(handle.fileno(), path)
    if journal_metadata(after) != journal_metadata(expected_metadata):
        raise OSError("Desktop journal changed while it was read; existing evidence was preserved")
    return list(retained)


def encoded_journal_record(record):
    try:
        encoded = json.dumps(record, ensure_ascii=True, separators=(",", ":"), allow_nan=False)
    except (TypeError, ValueError, RecursionError):
        raise OSError("Desktop publication contains an invalid journal record") from None
    if len(encoded.encode("utf-8")) + 1 > JOURNAL_MAX_LINE_BYTES:
        raise OSError("Desktop publication exceeds the journal event byte limit")
    return encoded


def strict_json_mapping(raw, maximum):
    try:
        encoded = raw.encode('utf-8') if isinstance(raw, str) else b''
    except UnicodeEncodeError:
        encoded = b''
    if not isinstance(raw, str) or not encoded or len(encoded) > maximum:
        raise OSError('Desktop enrollment bindings are invalid or oversized')
    try:
        value = json.loads(raw, object_pairs_hook=_config_crypto._pairs,
                           parse_constant=_config_crypto._invalid_constant,
                           parse_float=_config_crypto._finite_float)
    except (UnicodeError, ValueError, RecursionError):
        raise OSError('Desktop enrollment bindings contain invalid JSON') from None
    if not isinstance(value, dict):
        raise OSError('Desktop enrollment bindings must be a JSON object')
    return value


class ScheduledStartExpired(RuntimeError):
    """No new submission was made at this guarded boundary."""


def scheduled_start_guard(latest_start):
    if latest_start is None:
        return None
    if type(latest_start) is not int or not 0 < latest_start <= 253402300799:
        raise ValueError('Invalid scheduled start deadline')
    latest_tick = time.monotonic() + max(0, latest_start - time.time())
    def check():
        if time.time() >= latest_start or time.monotonic() >= latest_tick:
            raise ScheduledStartExpired('The scheduled start deadline elapsed before submission')
    check()
    return check


def atomic_write_journal(path, lines, expected_metadata=None, submission_guard=None):
    if len(lines) > JOURNAL_MAX_RECORDS:
        raise OSError("Desktop journal replacement exceeds the record limit")
    lengths = [len(line.encode("utf-8")) + 1 for line in lines]
    if any(length > JOURNAL_MAX_LINE_BYTES for length in lengths) or sum(lengths) > JOURNAL_MAX_BYTES:
        raise OSError("Desktop journal replacement exceeds its byte limits")
    before = path.lstat() if path.exists() or path.is_symlink() else None
    if before is not None and (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1):
        raise OSError("Desktop journal replacement path is unsafe")
    if expected_metadata is not None and (before is None or
            journal_metadata(before) != journal_metadata(expected_metadata)):
        raise OSError("Desktop journal changed before replacement")
    temporary_fd = None
    temporary_path = None
    temporary_metadata = None
    try:
        temporary_fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.tmp.", dir=str(path.parent))
        temporary_path = Path(temporary_name)
        temporary_metadata = os.fstat(temporary_fd)
        with os.fdopen(temporary_fd, "wb") as handle:
            temporary_fd = None
            journal_identity(handle.fileno(), temporary_path)
            set_journal_file_metadata(handle.fileno())
            for line in lines:
                handle.write(line.encode("utf-8") + b"\n")
            handle.flush()
            os.fsync(handle.fileno())
            journal_identity(handle.fileno(), temporary_path)
            current = path.lstat() if path.exists() or path.is_symlink() else None
            if before is not None:
                if (current is None or not stat.S_ISREG(current.st_mode) or current.st_nlink != 1
                        or journal_metadata(current) != journal_metadata(before)):
                    raise OSError("Desktop journal changed during replacement")
            elif current is not None:
                raise OSError("Desktop journal appeared during replacement")
            if submission_guard is not None:
                submission_guard()
            final_current = path.lstat() if path.exists() or path.is_symlink() else None
            if before is not None:
                if (final_current is None or journal_metadata(final_current) != journal_metadata(before)):
                    raise OSError('Desktop journal changed at the publication boundary')
            elif final_current is not None:
                raise OSError('Desktop journal appeared at the publication boundary')
            os.replace(temporary_path, path)
            temporary_path = None
            committed_fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
            try:
                committed = journal_identity(committed_fd, path)
                if ((committed.st_dev, committed.st_ino)
                        != (temporary_metadata.st_dev, temporary_metadata.st_ino)
                        or committed.st_size != sum(lengths)):
                    raise OSError('Desktop journal replacement did not commit the staged inode')
            finally:
                os.close(committed_fd)
        directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | os.O_NOFOLLOW
        directory_fd = os.open(path.parent, directory_flags)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    finally:
        if temporary_fd is not None:
            os.close(temporary_fd)
        if temporary_path is not None:
            try:
                current = temporary_path.lstat()
                if (current.st_dev, current.st_ino) == (temporary_metadata.st_dev, temporary_metadata.st_ino):
                    temporary_path.unlink()
            except FileNotFoundError:
                pass


def append_sipnotify_event(config, record, submission_guard=None):
    if submission_guard is not None:
        submission_guard()
    path = api_events_file(config).absolute()
    record = dict(record)
    event_id = record.get('id')
    if not valid_event_identifier(event_id):
        raise OSError('Desktop publication requires a bounded text event ID')
    if 'desktop_recipients' in record or 'desktop_all' in record:
        if type(record.get('desktop_all', False)) is not bool:
            raise OSError('Desktop publication all-clients marker must be a boolean')
        raw_recipients = record.get('desktop_recipients') or []
        if (not isinstance(raw_recipients, list) or len(raw_recipients) > 1000
                or any(not isinstance(value, str)
                       or re.fullmatch(r'[a-z0-9_.-]{1,48}', value) is None
                       for value in raw_recipients)):
            raise OSError('Desktop publication recipients are invalid or oversized')
        recipients = set(raw_recipients)
        bindings = strict_json_mapping(config.get('desktop', 'client_ids', fallback='{}'), 131072)
        if len(bindings) > 1000 or any(
                not isinstance(username, str) or re.fullmatch(r'[a-z0-9_.-]{1,48}', username) is None
                or not isinstance(client_id, str) or re.fullmatch(r'[a-z0-9_-]{1,32}', client_id) is None
                for username, client_id in bindings.items()):
            raise OSError('Desktop enrollment bindings are invalid or oversized')
        record['desktop_client_ids'] = {
            username: client_id for username, client_id in bindings.items()
            if isinstance(username, str) and isinstance(client_id, str)
            and (record.get('desktop_all') or username in recipients)
        }
        frozen = os.environ.get('SLS_DESKTOP_CLIENT_IDS_SNAPSHOT')
        if frozen is not None:
            expected = strict_json_mapping(frozen, 131072)
            if len(expected) > 1000 or any(
                    not isinstance(username, str) or re.fullmatch(r'[a-z0-9_.-]{1,48}', username) is None
                    or not isinstance(client_id, str) or re.fullmatch(r'[a-z0-9_-]{1,32}', client_id) is None
                    for username, client_id in expected.items()):
                raise OSError('Frozen desktop enrollment bindings are invalid')
            record['desktop_client_ids'] = {name: identity for name, identity in record['desktop_client_ids'].items()
                                            if expected.get(name) == identity}
            record['desktop_all'] = False
            record['desktop_recipients'] = list(record['desktop_client_ids'])
    record["created_at"] = datetime.now(timezone.utc).astimezone().isoformat()
    encoded = encoded_journal_record(record)
    path.parent.mkdir(parents=True, exist_ok=True)
    parent = path.parent.lstat()
    accepted_uids, accepted_gids = journal_accepted_ids()
    if (path.parent.resolve() != path.parent or not stat.S_ISDIR(parent.st_mode)
            or parent.st_mode & 0o022 or parent.st_uid not in accepted_uids
            or parent.st_gid not in accepted_gids):
        raise OSError("Desktop journal directory is not a canonical directory")
    lock_path = path.with_name(path.name + ".lock")
    deadline = time.monotonic() + JOURNAL_LOCK_SECONDS
    lock_fd = open_journal_file(lock_path, 512)
    with os.fdopen(lock_fd, "r+b") as lock_handle:
        lock_journal_file(lock_handle.fileno(), deadline)
        if submission_guard is not None:
            submission_guard()
        journal_identity(lock_handle.fileno(), lock_path)
        set_journal_file_metadata(lock_handle.fileno())
        journal_fd = open_journal_file(path)
        with os.fdopen(journal_fd, "r+b") as journal_handle:
            lock_journal_file(journal_handle.fileno(), deadline)
            metadata = journal_identity(journal_handle.fileno(), path)
            set_journal_file_metadata(journal_handle.fileno())
            lines = read_journal_lines(journal_handle, path, metadata)
            record_id = record["id"]
            if not any(json.loads(line).get('id') == record_id for line in lines):
                archived = _desktop_history.lookup(path, record_id)
                if archived is not None:
                    if (archived.get('kind') != record.get('kind')
                            or (archived.get('is_test') is True) != (record.get('is_test') is True)):
                        raise OSError('Desktop publication identity conflicts with retained evidence')
                    # Repeating an archived publication cannot make an expired
                    # event live again or widen its original audience.
                    return
            replaced = False
            if record_id:
                for index, line in enumerate(lines):
                    existing = json.loads(line)
                    if str(existing.get("id") or "") == record_id:
                        if existing.get('kind') != record.get('kind'):
                            raise OSError('Desktop publication ID is already used by another event kind')
                        if (existing.get('is_test') is True) != (record.get('is_test') is True):
                            raise OSError('Desktop publication test identity changed for an existing event')
                        # Repeated publication keeps its original identity/time
                        # and absolute display expiry instead of extending it.
                        for key in ("created_at", "display_expires_at", "display_timeout_seconds"):
                            if key in existing:
                                record[key] = existing[key]
                        if existing.get("kind") == "alert" and record.get("kind") == "alert":
                            old_bindings = existing.get('desktop_client_ids') or {}
                            new_bindings = {name: identifier for name, identifier in record.get('desktop_client_ids', {}).items()
                                            if name not in set(existing.get('desktop_recipients') or [])
                                            and not existing.get('desktop_all')}
                            record['desktop_client_ids'] = {**new_bindings, **old_bindings}
                            record["recipients"] = sorted(set(existing.get("recipients") or []) | set(record.get("recipients") or []))
                            record["desktop_recipients"] = sorted(set(existing.get("desktop_recipients") or []) | set(record.get("desktop_recipients") or []))
                            record["desktop_all"] = bool(existing.get("desktop_all") or record.get("desktop_all"))
                            merged_formats = dict(existing.get("phone_formats") or {})
                            merged_formats.update(record.get("phone_formats") or {})
                            record["phone_formats"] = merged_formats
                        else:
                            record['desktop_client_ids'] = existing.get('desktop_client_ids') or {}
                            record['desktop_recipients'] = existing.get('desktop_recipients') or []
                            record['desktop_all'] = existing.get('desktop_all') is True
                        encoded = encoded_journal_record(record)
                        lines[index] = encoded
                        replaced = True
            if not replaced:
                lines.append(encoded)
            try:
                lines = _desktop_history.compact(path, lines, JOURNAL_MAX_BYTES, JOURNAL_MAX_RECORDS, record_id)
            except (RuntimeError, ValueError) as error:
                raise OSError('Desktop history could not be preserved; publication was not changed') from error
            journal_identity(journal_handle.fileno(), path)
            journal_identity(lock_handle.fileno(), lock_path)
            claim = None
            def guarded_publication():
                nonlocal claim
                if submission_guard is not None:
                    submission_guard()
                claim = _cluster_guard.begin('desktop', 'publication', record,
                                             delivery_id=str(record.get('id') or ''))
            try:
                # The authority check occurs after temporary bytes are fsynced,
                # immediately before replacing the externally visible feed.
                atomic_write_journal(path, lines, metadata,
                                     submission_guard=guarded_publication)
                _cluster_guard.finish(claim, uncertain=False, category='desktop_publication_committed')
                try:_desktop_feed_cache.publish(path,lines,config.get('visual','phone_capability_key',fallback=''))
                except (OSError,ValueError,RuntimeError,TypeError):logging.warning('Desktop feed cache unavailable; authenticated readers will use the durable journal')
            except Exception:
                try:
                    _cluster_guard.finish(claim, uncertain=True, category='desktop_publication_uncertain')
                except Exception:
                    pass
                raise


def new_announcement_id(now=None):
    current = datetime.now(timezone.utc) if now is None else now
    if not isinstance(current, datetime):
        raise TypeError('Announcement ID time must be a datetime')
    if current.tzinfo is None:
        current = current.replace(tzinfo=timezone.utc)
    else:
        current = current.astimezone(timezone.utc)
    return f"announcement-{current.strftime('%Y%m%d%H%M%S%f')}-{secrets.token_hex(16)}"


def alert_api_record(alert, xml_payload, extensions, desktop_targets=None, desktop_all=False, phone_formats=None, is_test=False):
    alert = alert if isinstance(alert, dict) else {}
    props = alert.get("properties", {})
    props = props if isinstance(props, dict) else {}
    event = props.get("event") if isinstance(props.get("event"), str) else "Unknown"
    alert_id = alert.get("id") or props.get("id") or "unknown"
    alert_id = alert_id if isinstance(alert_id, str) else "unknown"
    priority = alert_priority(props)
    colors = ALERT_COLORS[priority]
    return {
        "kind": "alert",
        "id": alert_id,
        "is_test": is_test is True,
        "chain_key": alert_chain_key(alert),
        "event": event,
        "title": alert_title(event),
        "priority": priority,
        "priority_label": colors["label"],
        "announcement_style": "weather_alert",
        "background_color": colors["background"],
        "header_color": colors["header"],
        "accent_color": colors["accent"],
        "text_color": colors["text"],
        "presentation": {
            "style": "weather_alert",
            "background_color": colors["background"],
            "header_color": colors["header"],
            "accent_color": colors["accent"],
            "text_color": colors["text"],
        },
        "beep": alert_beep(event),
        "severity": props.get("severity", "") if isinstance(props.get("severity"), str) else "",
        "message_type": props.get("messageType", "") if isinstance(props.get("messageType"), str) else "",
        "area": props.get("areaDesc", "") if isinstance(props.get("areaDesc"), str) else "",
        "effective": props.get("effective", "") if isinstance(props.get("effective"), str) else "",
        "expires": props.get("expires", "") if isinstance(props.get("expires"), str) else "",
        "description": props.get("description", "") if isinstance(props.get("description"), str) else "",
        "image_url": image_url_from_xml(xml_payload),
        "xml": xml_payload,
        "recipients": extensions,
        "desktop_all": desktop_all is True,
        "desktop_recipients": sorted(set(desktop_targets or [])),
        "phone_formats": phone_formats or {},
    }


def normalize_incident_context(value):
    if value is None:
        return None
    keys = {'schema', 'incident_id', 'sequence', 'kind', 'severity', 'is_test'}
    extra = {'operation_request_id','language_variant','language_batch','language_batches','expires_at'}
    if (not isinstance(value, dict) or not keys.issubset(value) or set(value)-keys-extra
            or type(value['schema']) is not int or value['schema'] != 1
            or not isinstance(value['incident_id'], str)
            or re.fullmatch(r'inc_[0-9a-f]{32}', value['incident_id']) is None
            or type(value['sequence']) is not int or not 1 <= value['sequence'] <= 250
            or value['kind'] not in ('initial', 'update', 'all_clear', 'escalation')
            or value['severity'] not in ('information', 'warning', 'critical')
            or type(value['is_test']) is not bool):
        raise ValueError('Invalid incident publication metadata')
    if set(value) & extra:
        if (not extra.issubset(value) or not isinstance(value['operation_request_id'],str)
                or re.fullmatch('[a-f0-9]{32}',value['operation_request_id']) is None
                or not isinstance(value['language_variant'],str) or re.fullmatch('(?:[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3})?',value['language_variant']) is None
                or type(value['language_batch']) is not int or type(value['language_batches']) is not int
                or not 1 <= value['language_batch'] <= value['language_batches'] <= 7
                or not isinstance(value['expires_at'],str) or re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00',value['expires_at']) is None):
            raise ValueError('Invalid incident language publication metadata')
        datetime.fromisoformat(value['expires_at'])
    return dict(value)


def announcement_api_record(alert_id, message, xml_payload, extensions, desktop_targets=None, desktop_all=False, phone_formats=None, title="Announcement", background_color="#1f2937", image=False, timeout_seconds=0, is_test=False, desktop_image_url=None, incident=None):
    if (not isinstance(alert_id, str) or not 1 <= len(alert_id) <= 2048
            or not isinstance(message, str) or not isinstance(xml_payload, str)):
        raise ValueError('Announcement publication identity and content must be bounded text')
    incident = normalize_incident_context(incident)
    if incident is not None and incident['is_test'] != (is_test is True):
        raise ValueError('Incident and announcement test metadata disagree')
    normalized_title = clean_payload_text(title or "Announcement", 80) or "Announcement"
    normalized_background = normalize_hex_color(background_color)
    timeout_seconds = normalize_announcement_timeout_seconds(timeout_seconds)
    display_expires_at = None
    if timeout_seconds > 0:
        display_expires_at = (
            datetime.now(timezone.utc) + timedelta(seconds=timeout_seconds)
        ).isoformat().replace("+00:00", "Z")
    if incident is not None and 'expires_at' in incident:
        absolute = datetime.fromisoformat(incident['expires_at'])
        if absolute <= datetime.now(timezone.utc):
            raise ValueError('Incident language publication expired before delivery')
        if display_expires_at is None or absolute < datetime.fromisoformat(display_expires_at.replace('Z','+00:00')):
            display_expires_at = absolute.isoformat().replace('+00:00','Z')
    style = "colored" if image else "standard"
    text_color = contrasting_text_color(normalized_background)
    record = {
        "kind": "announcement",
        "id": alert_id,
        "is_test": is_test is True,
        "event": "Announcement",
        "sender": clean_payload_text(os.environ.get("SLS_ANNOUNCEMENT_SENDER", ""), 80),
        "title": normalized_title,
        "priority": "notice",
        "priority_label": ALERT_COLORS["notice"]["label"],
        "announcement_style": style,
        "background_color": normalized_background,
        "header_color": normalized_background,
        "accent_color": text_color,
        "text_color": text_color,
        "presentation": {
            "style": style,
            "background_color": normalized_background,
            "header_color": normalized_background,
            "accent_color": text_color,
            "text_color": text_color,
        },
        "beep": "yes",
        "body": message,
        "text": message,
        "description": message,
        "message": message,
        "display_timeout_seconds": timeout_seconds,
        "display_expires_at": display_expires_at,
        "image_url": image_url_from_xml(xml_payload) if desktop_image_url is None else desktop_image_url,
        "phone_image_url": image_url_from_xml(xml_payload),
        "xml": xml_payload,
        "recipients": extensions,
        "desktop_all": desktop_all is True,
        "desktop_recipients": sorted(set(desktop_targets or [])),
        "phone_formats": phone_formats or {},
    }
    if incident is not None:
        record['incident'] = incident
    return record



def send_notify_batch(ami, endpoint_info, payload_builder, alert_id, print_results=False, attempt_label="initial", unavailable_targets=None, defer_unavailable_failure=False, visual_owner='', clear_visual_owner='', strict_contacts=False, continuation_extensions=(), submission_guard=None, operation_deadline=None):
    # Keep the historical integer return (contact coverage of accepted actions)
    # for callers. It is not a count of handset deliveries. The structured result
    # reports AMI submissions and independently addressed contacts separately.
    successes = 0
    submitted_targets = 0
    failed_targets = 0
    submitted_contact_targets = 0
    failed_contact_targets = 0
    ami_attempts = 0
    superseded_extensions = 0
    results = []
    unavailable_targets = sorted(set(unavailable_targets or []) - set(endpoint_info))
    # A connected-contact request never falls back to Endpoint. Attempt only
    # the URI AMI operation; unsupported servers return a concrete refusal.
    # Avoid three unguarded CLI capability probes in this bounded live path.
    capabilities = {'contact_uri_usable': True} if strict_contacts else pjsip_notify_capabilities()

    def submit(extension, info, target, phone_format, target_field, requested_formats,
               requested_route, fallback_reason, resolved_contacts, coverage, unavailable=False):
        nonlocal successes, submitted_targets, failed_targets
        nonlocal submitted_contact_targets, failed_contact_targets, ami_attempts
        if not unavailable and submission_guard is not None and not submission_guard():
            raise RuntimeError('The live page no longer permits this visual submission')
        record = {
            "alert_id": safe_notify_log_value(alert_id),
            "attempt": safe_notify_log_value(attempt_label),
            "extension": safe_notify_log_value(extension),
            "requested_formats": [safe_notify_log_value(fmt) for fmt in requested_formats],
            "format": safe_notify_log_value(phone_format),
            "requested_route": requested_route,
            "actual_route": "none" if unavailable else "contact_uri" if target_field == "URI" else "endpoint",
            "target_field": target_field,
            "target": safe_notify_log_value(target),
            "registered_contacts": len(info.get("contacts") or []),
            "resolved_contact_uris": resolved_contacts,
            "fallback_reason": fallback_reason,
            "handset_delivery_confirmed": False,
        }
        if unavailable:
            details = {"status": "failed", "event_attempts": [], "event_fallback_outcome": "not_used"}
            record["error_category"] = "endpoint_not_registered_or_reachable"
        else:
            try:
                xml_payload = payload_builder(phone_format)
            except Exception:
                details = {"status": "failed", "event_attempts": [], "event_fallback_outcome": "not_used"}
                record["error_category"] = "payload_build_failed"
                record["actual_route"] = "none"
                record["target_field"] = None
            else:
                try:
                    result = send_notify(ami, target, xml_payload, phone_format, target_field,
                        **({'coordination_deadline':operation_deadline or time.monotonic()+8} if strict_contacts else {}))
                    details = result.details
                except NotifySubmissionError as exc:
                    details = exc.details
                except Exception:
                    # Never expose arbitrary exception text (payloads/secrets).
                    details = {"status": "failed", "event_attempts": [], "event_fallback_outcome": "not_used"}
                    record["error_category"] = "notify_action_failed"
        record.update(details)
        record["ami_response"] = details["event_attempts"][-1] if details["event_attempts"] else None
        record["fallback_outcome"] = record["status"] if fallback_reason != "none" else "not_used"
        ami_attempts += len(details["event_attempts"])
        if record["status"] == "submitted_to_asterisk":
            successes += coverage
            submitted_targets += 1
            if target_field == "URI":
                submitted_contact_targets += 1
        else:
            failed_targets += 1
            if target_field == "URI":
                failed_contact_targets += 1
        # Log each attempted target, but keep subprocess result output bounded.
        logging.log(
            logging.INFO if record["status"] == "submitted_to_asterisk" else logging.ERROR,
            "SIP_NOTIFY_TARGET %s", json.dumps(record, sort_keys=True),
        )
        if len(results) < 1000:
            results.append(record)
        if print_results:
            print(
                f"{record['extension']}: {record['status']} format={record['format']} "
                f"requested_route={requested_route} route={record['actual_route']} "
                f"target_field={record['target_field']} target={record['target']} "
                f"fallback_outcome={record['fallback_outcome']} ({record['attempt']})"
            )
        return record["status"] == "submitted_to_asterisk"

    def route_extension(extension, info):
        contacts = info.get("contacts") or []
        contact_targets = []
        seen_contact_targets = set()
        for contact in contacts:
            contact_uri = registered_contact_uri(contact.get("contact", ""))
            phone_format = contact.get("format") or info.get("format", "yealink")
            contact_key = (contact_uri, phone_format)
            if contact_uri and contact_key not in seen_contact_targets:
                seen_contact_targets.add(contact_key)
                contact_targets.append((contact_uri, phone_format))

        contact_formats = sorted({
            contact.get("format") or info.get("format", "yealink")
            for contact in contacts
        } or set(info.get("formats") or [info.get("format", "yealink")]))
        mixed_formats = len(contact_formats) > 1
        use_contact_uri = bool(
            contact_targets
            and (mixed_formats or strict_contacts)
            and len(contact_targets) == len(contacts)
            and capabilities.get("contact_uri_usable")
        )

        if strict_contacts and not use_contact_uri:
            # Never fan out a connected-contact-only popup to ringing handsets.
            return submit(extension, info, '', 'generic', None, contact_formats,
                          'contact_uri', 'connected_contact_uri_unavailable', len(contact_targets), 0, unavailable=True)

        if mixed_formats and not use_contact_uri:
            # Preserve the portable generic endpoint fallback when each vendor's
            # registration cannot be addressed separately.
            fallback_reason = (
                "mixed_formats_incomplete_contact_uris"
                if not contact_targets or len(contact_targets) != len(contacts)
                else "mixed_formats_uri_route_unavailable"
            )
            return submit(extension, info, extension, "generic", "Endpoint", contact_formats,
                          "contact_uri", fallback_reason, len(contact_targets), max(1, len(contacts)))

        if contact_targets and not use_contact_uri:
            accepted = False
            for phone_format in contact_formats or [info.get("format", "yealink")]:
                accepted = submit(extension, info, extension, phone_format, "Endpoint", [phone_format],
                                  "endpoint", "none", len(contact_targets), max(1, len(contact_targets))) or accepted
            return accepted

        if contact_targets:
            accepted = False
            for contact_uri, phone_format in contact_targets:
                accepted = submit(extension, info, contact_uri, phone_format, "URI", [phone_format],
                                  "contact_uri", "none", len(contact_targets), 1) or accepted
            # A URI failure must not trigger endpoint fan-out, which could submit
            # the wrong vendor format or duplicate contacts already submitted.
            return accepted

        accepted = False
        for phone_format in info.get("formats") or [info.get("format", "yealink")]:
            accepted = submit(extension, info, extension, phone_format, "Endpoint", [phone_format],
                              "endpoint", "none", 0, 1) or accepted
        return accepted

    for extension, info in sorted(endpoint_info.items()):
        with phone_visual_owner(extension, visual_owner, clear_visual_owner,
                                continuing=extension in continuation_extensions) as lease:
            if lease and route_extension(extension, info):
                lease.commit()
            elif not lease:
                superseded_extensions += 1

    for extension in unavailable_targets:
        submit(extension, {}, extension, "", None, [], "endpoint", "none", 0, 0, unavailable=True)

    status = (
        "partial" if submitted_targets and failed_targets else
        "failed" if failed_targets else
        "submitted_to_asterisk" if submitted_targets else "no_targets"
    )
    summary = {
        "kind": "sip_notify_batch",
        "alert_id": safe_notify_log_value(alert_id),
        "attempt": safe_notify_log_value(attempt_label),
        "status": status,
        "submitted_targets": submitted_targets,
        "failed_targets": failed_targets,
        "submitted_contact_targets": submitted_contact_targets,
        "failed_contact_targets": failed_contact_targets,
        "submitted_endpoint_targets": submitted_targets - submitted_contact_targets,
        "failed_endpoint_targets": failed_targets - failed_contact_targets,
        "unavailable_targets": len(unavailable_targets),
        "ami_attempts": ami_attempts,
        "handset_delivery_confirmed": False,
        "targets": results,
        "targets_truncated": submitted_targets + failed_targets > len(results),
    }
    logging.log(
        logging.ERROR if failed_targets else logging.INFO,
        "SIP_NOTIFY_RESULT %s", json.dumps(summary, sort_keys=True),
    )
    if print_results:
        print("SLS_NOTIFY_RESULT " + json.dumps(summary, sort_keys=True), flush=True)
    if failed_targets and (not defer_unavailable_failure or failed_targets > len(unavailable_targets)):
        label = "partial submission" if submitted_targets else "submission failed"
        raise NotifyBatchError(
            f"SIP NOTIFY {label}: submitted_to_asterisk={submitted_targets} failed={failed_targets}; "
            "handset delivery is not confirmed",
            summary,
        )
    if endpoint_info and successes == 0 and not clear_visual_owner and superseded_extensions != len(endpoint_info):
        raise NotifyBatchError("SIP NOTIFY was not submitted to Asterisk for any requested endpoint", summary)
    return successes


def raise_unavailable_notify_targets(unavailable_targets):
    if unavailable_targets:
        raise RuntimeError(
            "SIP NOTIFY partial submission: requested phone endpoints not registered/reachable="
            + str(len(unavailable_targets)) + "; handset delivery is not confirmed"
        )


def endpoint_format_summary(endpoint_info):
    return {
        endpoint: {
            "format": info.get("format", "yealink"),
            "formats": info.get("formats") or [info.get("format", "yealink")],
            "user_agent": info.get("user_agent", ""),
            "contacts": len(info.get("contacts") or []),
            "override": bool(info.get("override")),
            "devices": [{
                'key': row.get('device_key', ''), 'format': row.get('format', 'unknown'),
                'detected_format': row.get('detected_format', 'unknown'),
                'user_agent': row.get('user_agent', ''), 'status': row.get('status', ''),
                'override': row.get('override', False),
                'capability': row.get('capability', 'unverified_generic'),
                'transport': contact_transport(row.get('contact', '')),
            } for row in info.get('contacts', [])],
        }
        for endpoint, info in sorted(endpoint_info.items())
    }


def push_alert(
    config,
    alert,
    print_results=False,
    retries=True,
    targets=None,
    api_publish=True,
    api_only=False,
    desktop_targets=None,
    desktop_all=False,
    require_all_targets=False,
    is_test=False,
):
    if not isinstance(alert, dict) or not isinstance(alert.get('properties'), dict):
        raise ValueError('Alert publication requires a valid feature object')
    props = alert.get("properties", {})
    event = props.get("event", "Unknown")
    priority = alert_priority(props)
    alert_id = alert.get("id") or props.get("id") or "unknown"
    if not valid_event_identifier(alert_id):
        raise ValueError('Alert publication requires a bounded text event ID')
    primary_xml_payload = build_xml(config, alert)
    excluded = audio_only_visual_exclusions(config)
    requested_extensions = sorted(set(targets or []) - excluded)
    desktop_targets = sorted(set(desktop_targets or []))
    if api_only:
        if not desktop_targets and not desktop_all:
            raise RuntimeError("API-only alert publication requires at least one desktop target")
        append_sipnotify_event(config, alert_api_record(alert, primary_xml_payload, [], desktop_targets, desktop_all, {}, is_test=is_test))
        return []
    # Live alert channels remain independent: a targeted desktop alert must
    # still be available if AMI or a handset-side SIP submission fails. Manual
    # delivery tests opt into require_all_targets and intentionally defer the
    # desktop record until every requested phone has passed validation and the
    # initial SIP NOTIFY batch has been accepted by Asterisk.
    desktop_pre_published = bool(
        api_publish and not require_all_targets and (desktop_targets or desktop_all)
    )
    if desktop_pre_published:
        append_sipnotify_event(
            config,
            alert_api_record(alert, primary_xml_payload, [], desktop_targets, desktop_all, {}, is_test=is_test),
        )
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
    ) as ami:
        endpoint_info = get_registered_endpoint_info(ami, targets, endpoint_format_overrides(config), resolve_contact_uris=True)
        endpoint_info = {extension: info for extension, info in endpoint_info.items() if extension not in excluded}
        extensions = sorted(endpoint_info.keys())
        phone_formats = endpoint_format_summary(endpoint_info)
        logging.info("Pushing %s alert %s priority=%s to %d registered endpoints formats=%s", event, alert_id, priority, len(extensions), phone_formats)
        missing_extensions = sorted(set(requested_extensions) - set(extensions))
        if requested_extensions and not extensions:
            requested = ", ".join(missing_extensions)
            raise RuntimeError(f"No requested phone endpoints are registered/reachable for SIP NOTIFY: {requested}")
        if require_all_targets and missing_extensions:
            requested = ", ".join(missing_extensions)
            raise RuntimeError(f"Requested phone endpoints are not registered/reachable for SIP NOTIFY: {requested}")
        payload_builder = lambda fmt: primary_xml_payload if fmt == "yealink" else build_phone_xml_for_format(config, fmt, "alert", alert=alert)
        send_notify_batch(
            ami, endpoint_info, payload_builder, alert_id, print_results, "initial",
            unavailable_targets=missing_extensions, defer_unavailable_failure=True,
        )
        # Manual mixed-channel tests defer publication until every requested
        # phone endpoint resolves and Asterisk accepts the initial batch.
        # Live targeted desktop delivery was already published above and must
        # not be written a second time after phone discovery.
        if api_publish and not desktop_pre_published:
            append_sipnotify_event(config, alert_api_record(alert, primary_xml_payload, extensions, desktop_targets, desktop_all, phone_formats, is_test=is_test))
        if not retries:
            raise_unavailable_notify_targets(missing_extensions)
            return
        for delay in visual_retry_delays(config):
            logging.info("Waiting %s seconds before visual alert retry for %s", delay, alert_id)
            time.sleep(delay)
            send_notify_batch(
                ami, endpoint_info, payload_builder, alert_id, print_results, f"retry+{delay}s",
                unavailable_targets=missing_extensions, defer_unavailable_failure=True,
            )
        raise_unavailable_notify_targets(missing_extensions)


def push_announcement(config, message, targets, print_results=True, api_publish=True, api_only=False, image=False, title="Announcement", background_color="#1f2937", background_image="", desktop_targets=None, desktop_all=False, timeout_seconds=0, is_test=False, incident=None, latest_start=None, visual_owner='', clear_visual_owner='', contact_targets=None, continuation_extensions=(), submission_guard=None, operation_deadline=None):
    if operation_deadline is not None and (contact_targets is None or isinstance(operation_deadline,bool)
            or not isinstance(operation_deadline,(int,float)) or not math.isfinite(operation_deadline) or operation_deadline <= 0):
        raise ValueError('Connected live-page operation deadline is invalid')
    if contact_targets is not None:
        if (api_publish or api_only or desktop_targets or desktop_all or not (visual_owner or clear_visual_owner)
                or not isinstance(contact_targets, dict) or not contact_targets or len(contact_targets) > 1000
                or set(contact_targets) != set(targets or [])
                or any(not isinstance(key, str) or not re.fullmatch(r'[0-9]{1,20}', key)
                       or not isinstance(values, list) or not 1 <= len(values) <= 1000
                       or any(not isinstance(uri, str) or registered_contact_uri(uri) != uri for uri in values)
                       or len(values) != len(set(values))
                       for key, values in contact_targets.items())
                or sum(map(len, contact_targets.values())) > 1000):
            raise ValueError('Connected live-page contact scope is invalid')
    if latest_start is not None and not api_only:
        raise ValueError('A scheduled publication deadline requires the desktop-only sender')
    if latest_start is not None:
        submission_guard = scheduled_start_guard(latest_start)
    incident = normalize_incident_context(incident)
    if incident is not None and incident['is_test'] != (is_test is True):
        raise ValueError('Incident and announcement test metadata disagree')
    timeout_seconds = normalize_announcement_timeout_seconds(timeout_seconds)
    excluded = audio_only_visual_exclusions(config)
    requested_extensions = sorted(set(targets or []) - excluded)
    if image:
        xml_payload = build_announcement_image_xml(config, message, title, background_color, background_image, timeout_seconds)
    else:
        xml_payload = build_announcement_xml(message, timeout_seconds, title)
    if clear_visual_owner:
        # Yealink Timeout accepts whole seconds. A quiet one-second completion
        # replaces only this page's popup; it never dismisses a newer alert.
        xml_payload = xml_payload.replace("Beep='yes'", "Beep='no'")
    alert_id = new_announcement_id()
    desktop_targets = sorted(set(desktop_targets or []))
    desktop_image_url = ""
    if image and api_publish and (desktop_targets or desktop_all):
        try:
            desktop_image_url = render_announcement_image(
                config, title, message, background_color, background_image, desktop=True,
            )
        except Exception as exc:
            # Preserve the complete plain-text announcement if only the desktop
            # rendition fails; never substitute an insecure phone-media URL.
            logging.error("Unable to render desktop announcement image; publishing plain text: %s", exc)
    if api_only:
        if not desktop_targets and not desktop_all:
            raise RuntimeError("API-only announcement publication requires at least one desktop target")
        append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, [], desktop_targets, desktop_all, title=title, background_color=background_color, image=image, timeout_seconds=timeout_seconds, is_test=is_test, desktop_image_url=desktop_image_url, incident=incident),
                              **({'submission_guard': submission_guard} if submission_guard is not None else {}))
        if print_results:
            print("SLS_DESKTOP_PUBLICATION " + json.dumps({"event_id": alert_id}, separators=(",", ":")), flush=True)
        return []
    desktop_pre_published = bool(api_publish and (desktop_targets or desktop_all))
    if desktop_pre_published:
        append_sipnotify_event(
            config,
            announcement_api_record(alert_id, message, xml_payload, sorted(targets or []), desktop_targets, desktop_all, title=title, background_color=background_color, image=image, timeout_seconds=timeout_seconds, is_test=is_test, desktop_image_url=desktop_image_url, incident=incident),
        )
    with AmiClient(
        config["ami"].get("host", "127.0.0.1"),
        config["ami"].getint("port", 5038),
        config["ami"].get("username", "slsmassnotify"),
        config["ami"].get("password", ""),
        **({'timeout': 2} if contact_targets is not None else {}),
    ) as ami:
        if contact_targets is not None and submission_guard is not None:
            ami = GuardedContactAmi(ami, submission_guard)
        endpoint_info = get_registered_endpoint_info(ami, targets, endpoint_format_overrides(config), resolve_contact_uris=True,
            **({'submission_guard': submission_guard} if contact_targets is not None and submission_guard is not None else {}))
        endpoint_info = {extension: info for extension, info in endpoint_info.items() if extension not in excluded}
        if contact_targets is not None:
            narrowed = {}
            for extension, info in endpoint_info.items():
                contacts = [row for row in info.get('contacts', [])
                            if registered_contact_uri(row.get('contact', '')) in contact_targets[extension]]
                if contacts:
                    narrowed[extension] = dict(info, contacts=contacts,
                        formats=sorted({row.get('format') or info.get('format', 'generic') for row in contacts}))
            endpoint_info = narrowed
        extensions = sorted(endpoint_info.keys())
        phone_formats = endpoint_format_summary(endpoint_info)
        logging.info("Pushing SIP NOTIFY announcement %s to %d registered endpoints formats=%s", alert_id, len(extensions), phone_formats)
        if requested_extensions and not extensions:
            requested = ", ".join(requested_extensions)
            raise RuntimeError(f"No requested phone endpoints are registered/reachable for SIP NOTIFY: {requested}")
        missing_extensions = sorted(set(requested_extensions) - set(extensions))
        def payload_builder(fmt):
            payload = xml_payload if fmt == 'yealink' else build_phone_xml_for_format(config, fmt, 'announcement', message=message, image=image, title=title, background_color=background_color, background_image=background_image, timeout_seconds=timeout_seconds)
            return payload.replace("Beep='yes'", "Beep='no'") if clear_visual_owner else payload
        send_notify_batch(
            ami, endpoint_info, payload_builder, alert_id, print_results, "initial",
            unavailable_targets=missing_extensions, defer_unavailable_failure=True,
            visual_owner=visual_owner, clear_visual_owner=clear_visual_owner,
            strict_contacts=contact_targets is not None, continuation_extensions=continuation_extensions,
            submission_guard=submission_guard,
            operation_deadline=operation_deadline,
        )
        if api_publish and not desktop_pre_published:
            append_sipnotify_event(config, announcement_api_record(alert_id, message, xml_payload, extensions, desktop_targets, desktop_all, phone_formats, title=title, background_color=background_color, image=image, timeout_seconds=timeout_seconds, is_test=is_test, desktop_image_url=desktop_image_url, incident=incident))
        raise_unavailable_notify_targets(missing_extensions)
        return extensions


def synthetic_alert(event, severity, area, description, expires_minutes, test_id):
    for value, maximum, label in ((event, 256, 'event'), (severity, 64, 'severity'),
                                  (area, 8192, 'area'), (description, 131072, 'description'),
                                  (test_id, 2048, 'test ID')):
        if not isinstance(value, str) or len(value) > maximum:
            raise ValueError(f'Synthetic alert {label} is invalid')
    if any(ord(char) < 32 or ord(char) == 127 for char in event):
        raise ValueError('Synthetic alert event is invalid')
    if test_id and not valid_event_identifier(test_id):
        raise ValueError('Synthetic alert test ID is invalid')
    if type(expires_minutes) is not int or not 1 <= expires_minutes <= 1440:
        raise ValueError('Synthetic alert expiry must be 1–1440 minutes')
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
        if not isinstance(value, str) or len(value) > 349528:
            raise ValueError('Alert input exceeds the 256 KiB feature limit')
        decoded = base64.b64decode(value.encode("ascii"), validate=True)
        if len(decoded) > 256 * 1024:
            raise ValueError('Alert input exceeds the 256 KiB feature limit')
        alert = json.loads(decoded.decode("utf-8"), object_pairs_hook=_config_crypto._pairs,
                           parse_constant=_config_crypto._invalid_constant,
                           parse_float=_config_crypto._finite_float)
    except Exception as exc:
        raise RuntimeError(f"Unable to decode alert JSON: {exc}") from exc

    if not isinstance(alert, dict) or not isinstance(alert.get("properties"), dict):
        raise RuntimeError("Decoded alert JSON is not a valid NWS alert feature")
    identifier = alert.get('id')
    if identifier not in (None, '') and not valid_event_identifier(identifier):
        raise RuntimeError('Decoded alert JSON has an invalid alert identifier')
    props = alert['properties']
    limits = {
        'id': 2048, 'event': 256, 'severity': 64, 'urgency': 64,
        'messageType': 64, 'effective': 128, 'expires': 128,
        'areaDesc': 8192, 'description': 131072,
    }
    for key, maximum in limits.items():
        field = props.get(key)
        if field is not None and (not isinstance(field, str) or len(field) > maximum):
            raise RuntimeError(f'Decoded alert JSON has an invalid {key} field')
        if key == 'id' and field not in (None, '') and not valid_event_identifier(field):
            raise RuntimeError('Decoded alert JSON has an invalid id field')
    references = props.get('references', [])
    if not isinstance(references, list) or len(references) > 256:
        raise RuntimeError('Decoded alert JSON has invalid references')
    for reference in references:
        if not isinstance(reference, dict):
            raise RuntimeError('Decoded alert JSON has invalid references')
        for key in ('identifier', '@id', 'sent'):
            field = reference.get(key)
            if field is not None and (not isinstance(field, str) or len(field) > 2048):
                raise RuntimeError('Decoded alert JSON has invalid references')
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
            "expires": (datetime.now(timezone.utc) + timedelta(minutes=45)).isoformat(),
            "areaDesc": area,
            "description": description,
        },
    }
    push_alert(config, alert, print_results=True, retries=retries, is_test=True)


def announcement_image_preview(mms=False):
    """Private preview only: never load PBX settings, AMI or event journals."""
    raw = sys.stdin.buffer.read(4097)
    if len(raw) > 4096:
        raise ValueError('Image preview request exceeds its byte limit')
    try:
        request = strict_json_mapping(raw.decode('utf-8'), 4096)
    except (UnicodeError, OSError):
        raise ValueError('Image preview request is invalid') from None
    if (not isinstance(request, dict) or set(request) != {"title", "message", "color"}
            or any(not isinstance(value, str) for value in request.values())
            or not 1 <= len(request["message"]) <= 500 or len(request["title"]) > 80
            or not re.fullmatch(r"#[0-9A-Fa-f]{6}", request["color"])
            or any(ord(char) < 32 and char not in "\r\n\t" for value in request.values() for char in value)):
        raise ValueError("Enter a message of 1–500 characters, a title up to 80 characters and a valid color.")
    with tempfile.TemporaryDirectory(prefix="sls-image-preview-") as directory:
        config = configparser.ConfigParser(interpolation=None)
        config["visual"] = {"web_dir": directory, "public_base_url": "https://preview.invalid"}
        url = render_announcement_image(config, request["title"], request["message"], request["color"], desktop=mms)
        data = (Path(directory) / url.rsplit("/", 1)[-1]).read_bytes()
        if len(data) > (300000 if mms else 1024 * 1024):
            raise ValueError("The rendered MMS image exceeds 300 KB." if mms else "The rendered preview exceeds its one MiB limit.")
        lines = textwrap.wrap(" ".join(request["message"].split()), width=34, break_long_words=False, break_on_hyphens=False)
        return {"success": True, "data": base64.b64encode(data).decode("ascii"), "mime": "image/png",
                "width": 1440 if mms else 480, "height": 816 if mms else 272, "text_clipped": len(lines) > 5 or any(len(line) > 34 for line in lines) or len(request["title"].strip()) > 48}


def main():
    if sys.argv[1:] in (["--preview-image"], ["--preview-mms-image"]):
        try:
            print(json.dumps(announcement_image_preview(mms=sys.argv[1] == "--preview-mms-image")))
            return 0
        except (ValueError, OSError, RuntimeError, subprocess.SubprocessError):
            print(json.dumps({"success": False, "message": "Phone image preview failed. Check the message, ImageMagick and temporary disk space."}))
            return 1
    parser = argparse.ArgumentParser(description="SLS Mass Notify SIP NOTIFY push for supported phones")
    parser.add_argument("--test", action="store_true", help="Send a fake Tornado Warning to registered phones and exit")
    parser.add_argument("--event", default="", help="Synthetic alert event to push, for PBX tests")
    parser.add_argument("--severity", default="Severe", help="Synthetic alert severity")
    parser.add_argument("--area", default="Williamson County TX", help="Synthetic alert area")
    parser.add_argument("--description", default="", help="Synthetic alert description")
    parser.add_argument("--expires-minutes", type=int, default=45, help="Synthetic alert expiration in minutes")
    parser.add_argument("--test-id", default="", help="Synthetic alert ID")
    parser.add_argument("--is-test", action="store_true", help="Explicitly mark a trusted local test publication for desktop clients")
    alert_input = parser.add_mutually_exclusive_group()
    alert_input.add_argument("--alert-json-b64", default="", help="Base64-encoded NWS alert feature JSON to push once")
    alert_input.add_argument("--alert-json-b64-stdin", action="store_true", help="Read the bounded base64 NWS alert feature from standard input")
    parser.add_argument("--targets", default="", help="Comma-separated endpoint list to notify")
    parser.add_argument("--announcement", default="", help="Send this text-only SIP NOTIFY announcement and exit")
    parser.add_argument("--incident-json", default="", help="Typed internal incident context for desktop announcement publication")
    parser.add_argument("--announcement-image", action="store_true", help="Render announcement as an image screen for image-capable phone formats")
    parser.add_argument("--announcement-title", default="Announcement", help="Announcement image title")
    parser.add_argument("--announcement-bg-color", default="#1f2937", help="Announcement image background color")
    parser.add_argument("--announcement-bg-image", default="", help="Announcement image background file")
    parser.add_argument("--announcement-timeout-seconds", type=int, default=0, help="Regular announcement display timeout in seconds (0 means no expiry)")
    parser.add_argument("--desktop-targets", default="", help="Comma-separated desktop app usernames allowed to receive this API event")
    parser.add_argument("--desktop-all", action="store_true", help="Allow all enabled desktop app clients to receive this API event")
    parser.add_argument("--no-api", action="store_true", help="Do not publish this announcement/alert to the desktop API journal")
    parser.add_argument("--api-only", action="store_true", help="Publish announcement to the desktop API journal without sending SIP NOTIFY")
    parser.add_argument('--latest-start', type=int, help='Absolute scheduled desktop publication deadline')
    parser.add_argument("--require-all-targets", action="store_true", help="Fail unless every requested phone endpoint is registered and reachable (manual delivery tests)")
    parser.add_argument("--list-endpoints-json", action="store_true", help="Print registered endpoint vendor detection as JSON and exit")
    parser.add_argument("--ami-health-json", action="store_true", help="Authenticate to AMI, issue Ping, and print a JSON health result")
    parser.add_argument("--notify-capabilities-json", action="store_true", help="Print adaptive Asterisk SIP NOTIFY routing capabilities and exit")
    parser.add_argument("--no-retry", action="store_true", help="Do not send delayed visual retries")
    parser.add_argument('--visual-owner', default='', help='Internal live-page popup identity')
    parser.add_argument('--clear-visual-owner', default='', help='Clear only this live page’s current popup')
    args = parser.parse_args()
    selected_actions = sum(bool(value) for value in (
        args.test, args.event, args.alert_json_b64, args.alert_json_b64_stdin,
        args.announcement, args.list_endpoints_json, args.ami_health_json,
        args.notify_capabilities_json,
    ))
    if selected_actions > 1:
        parser.error('Select exactly one notification or diagnostic action')
    if args.api_only and args.no_api:
        parser.error('--api-only cannot be combined with --no-api')
    if args.latest_start is not None and (not args.announcement or not args.api_only):
        parser.error('--latest-start requires a desktop-only announcement')
    if args.visual_owner or args.clear_visual_owner:
        if (not args.announcement or not args.targets or not args.no_api or not args.no_retry or args.api_only
                or args.desktop_targets or args.desktop_all or args.incident_json
                or (args.visual_owner and args.clear_visual_owner)
                or any(token and not re.fullmatch(r'[a-f0-9]{32}', token) for token in (args.visual_owner, args.clear_visual_owner))
                or (args.clear_visual_owner and (args.announcement_image or args.announcement_timeout_seconds != 1))):
            parser.error('Live-page popup ownership requires a bounded phone-only request')
    incident = None
    if args.incident_json:
        if not args.announcement or not args.api_only or len(args.incident_json) > 1024:
            parser.error('Incident metadata requires a bounded desktop-only announcement')
        try:
            incident = normalize_incident_context(strict_json_mapping(args.incident_json, 1024))
            if incident is None or incident['is_test'] != args.is_test:
                raise ValueError('Incident test marker mismatch')
        except (OSError, ValueError, TypeError):
            parser.error('Incident metadata is invalid or its test marker disagrees')

    try:
        config = load_config()
        setup_logging(config["logging"].get("log_file", "/var/log/sls_mass_notify_push.log"))
        if args.notify_capabilities_json:
            print(json.dumps(pjsip_notify_capabilities(), sort_keys=True))
            return 0
        if args.ami_health_json:
            with AmiClient(
                config["ami"].get("host", "127.0.0.1"),
                config["ami"].getint("port", 5038),
                config["ami"].get("username", "slsmassnotify"),
                config["ami"].get("password", ""),
            ) as ami:
                response, _ = ami.action({"Action": "Ping"})
                if response.get("Response", "").lower() != "success" or response.get("Ping", "").lower() != "pong":
                    raise RuntimeError(response.get("Message", "AMI Ping failed"))
                contact_response, _ = ami.action({"Action": "PJSIPShowContacts"})
                contact_status = "authorized"
                if ami_response_is_empty_contact_inventory(contact_response):
                    contact_status = "authorized_empty"
                elif contact_response.get("Response", "").lower() != "success":
                    raise RuntimeError(contact_response.get("Message", "AMI PJSIPShowContacts authorization failed"))
                notify_probe = "__sls_capability_probe__"
                notify_response, _ = ami.action({
                    "Action": "PJSIPNotify",
                    "Endpoint": notify_probe,
                    "Variable": ["Event=sls-capability-probe"],
                })
                if (
                    notify_response.get("Response", "").lower() != "success"
                    and not ami_response_is_missing_probe_endpoint(notify_response, notify_probe)
                ):
                    raise RuntimeError(
                        notify_response.get("Message", "AMI PJSIPNotify authorization probe returned an unexpected result")
                    )
                print(json.dumps({
                    "status": "ok",
                    "ami": "authenticated",
                    "ping": "pong",
                    "pjsip_show_contacts": contact_status,
                    "pjsip_notify": "authorized",
                    "live_paging_actions": ami_live_paging_actions(ami),
                }, sort_keys=True))
            return 0
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
        desktop_targets = normalize_desktop_target_list(args.desktop_targets)
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
                desktop_targets=desktop_targets,
                desktop_all=args.desktop_all,
                timeout_seconds=normalize_announcement_timeout_seconds(args.announcement_timeout_seconds),
                is_test=args.is_test,
                incident=incident,
                latest_start=args.latest_start,
                visual_owner=args.visual_owner, clear_visual_owner=args.clear_visual_owner,
            )
        elif args.alert_json_b64 or args.alert_json_b64_stdin:
            value = args.alert_json_b64
            if args.alert_json_b64_stdin:
                raw = sys.stdin.buffer.read(349530)
                if len(raw) > 349529:
                    raise RuntimeError('Alert standard input exceeds the 256 KiB feature limit')
                value = raw.decode('ascii').strip()
            alert = alert_from_json_b64(value)
            push_alert(
                config, alert, print_results=True, retries=not args.no_retry,
                targets=targets, api_publish=not args.no_api, api_only=args.api_only,
                desktop_targets=desktop_targets, desktop_all=args.desktop_all,
                require_all_targets=args.require_all_targets,
                is_test=args.is_test,
            )
        elif args.event:
            description = args.description or "PBX test visual alert. This image was generated by the FreePBX Mass Notifications testing page."
            alert = synthetic_alert(args.event, args.severity, args.area, description, args.expires_minutes, args.test_id)
            push_alert(
                config, alert, print_results=True, retries=not args.no_retry,
                targets=targets, api_publish=not args.no_api, api_only=args.api_only,
                desktop_targets=desktop_targets, desktop_all=args.desktop_all,
                require_all_targets=args.require_all_targets,
                is_test=True,
            )
        elif args.test:
            run_test(config, retries=not args.no_retry)
        else:
            parser.print_help(sys.stderr)
            return 2
    except ScheduledStartExpired:
        print('SLS_SUBMISSION_REJECTED schedule_deadline_expired', flush=True)
        return 78
    except Exception as exc:
        logging.error("Fatal startup error: %s", exc)
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
