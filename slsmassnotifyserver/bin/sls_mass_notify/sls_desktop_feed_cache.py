#!/usr/bin/python3
"""Derived recipient-set cache. The durable journal remains authoritative."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import stat
from sls_atomic_json import open_directory

MAX_BYTES = 64 * 1024 * 1024
NAME = ".desktop-feed.cache"


def metadata(value):
    return [
        value.st_dev,
        value.st_ino,
        value.st_size,
        int(value.st_mtime),
        int(value.st_ctime),
    ]


def body(lines):
    sets = {}
    events = []
    if len(lines) > 1000:
        raise ValueError("Derived feed exceeds its event bound")
    for line in lines:
        event = json.loads(line)
        if not isinstance(event, dict):
            raise ValueError("Invalid derived event")
        bindings = event.get("desktop_client_ids", {})
        recipients = event.get("desktop_recipients", [])
        all_clients = event.get("desktop_all", False)
        if (
            not isinstance(bindings, dict)
            or len(bindings) > 1000
            or not isinstance(recipients, list)
            or len(recipients) > 1000
            or type(all_clients) is not bool
            or any(not isinstance(name, str) or len(name) > 80 for name in recipients)
        ):
            raise ValueError("Invalid derived routing set")
        recipients = set(recipients)
        routing = {}
        for name, identity in bindings.items():
            if (
                not isinstance(name, str)
                or not re.fullmatch(r"[a-z0-9_.-]{1,48}", name)
                or not isinstance(identity, str)
                or not re.fullmatch(r"[a-z0-9_-]{1,32}", identity)
            ):
                raise ValueError("Invalid original enrollment")
            if all_clients or name in recipients:
                routing[name] = identity
        encoded = json.dumps(
            routing, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        ).encode("ascii")
        key = hashlib.sha256(encoded).hexdigest()
        sets[key] = routing
        payload = {
            key: value
            for key, value in event.items()
            if key not in ("desktop_client_ids", "desktop_recipients", "desktop_all")
        }
        events.append({"set": key, "event": payload})
    encoded = json.dumps(
        {"sets": sets, "events": events},
        separators=(",", ":"),
        ensure_ascii=True,
        allow_nan=False,
    ).encode("ascii")
    if len(encoded) > MAX_BYTES:
        raise ValueError("Derived feed exceeds its byte bound")
    return encoded


def publish(journal, lines, key_encoded):
    """Called only while the publisher holds its permanent journal lock."""
    try:
        key = base64.b64decode(key_encoded, validate=True)
    except (ValueError, TypeError):
        return False
    if len(key) != 32:
        return False
    journal = Path(journal)
    account = pwd.getpwnam("asterisk")
    parent = open_directory(journal.parent, account.pw_uid)
    descriptor = None
    temporary = None
    try:
        original = os.stat(journal.name, dir_fd=parent, follow_symlinks=False)
        if (
            not stat.S_ISREG(original.st_mode)
            or original.st_nlink != 1
            or original.st_mode & 0o7027
            or original.st_uid not in (0, account.pw_uid)
        ):
            raise OSError("Unsafe cache source")
        raw = body(lines)
        identity = metadata(original)
        signed = (
            b"sls-desktop-feed-v1\n"
            + json.dumps(identity, separators=(",", ":")).encode("ascii")
            + b"\n"
            + raw
        )
        header = (
            json.dumps(
                {
                    "schema": 1,
                    "journal": identity,
                    "bytes": len(raw),
                    "hmac": hmac.new(key, signed, hashlib.sha256).hexdigest(),
                },
                separators=(",", ":"),
            ).encode("ascii")
            + b"\n"
        )
        try:
            old = os.stat(NAME, dir_fd=parent, follow_symlinks=False)
            if (
                not stat.S_ISREG(old.st_mode)
                or old.st_nlink != 1
                or old.st_mode & 0o7027
                or old.st_uid not in (0, account.pw_uid)
            ):
                raise OSError("Unsafe derived cache destination")
        except FileNotFoundError:
            old = None
        temporary = ".sls-feed-cache-" + secrets.token_hex(16)
        descriptor = os.open(
            temporary,
            os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
            0o640,
            dir_fd=parent,
        )
        if os.geteuid() == 0:
            os.fchown(descriptor, account.pw_uid, account.pw_gid)
        for block in (header, raw):
            position = 0
            while position < len(block):
                written = os.write(descriptor, block[position:])
                if written <= 0:
                    raise OSError("Derived cache write failed")
                position += written
        os.fsync(descriptor)
        current = os.stat(journal.name, dir_fd=parent, follow_symlinks=False)
        if metadata(current) != identity:
            raise OSError("Journal changed while deriving cache")
        os.replace(temporary, NAME, src_dir_fd=parent, dst_dir_fd=parent)
        temporary = None
        os.fsync(parent)
        return True
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if temporary is not None:
            try:
                os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError:
                pass
        os.close(parent)
