#!/usr/bin/env python3
"""AES-256-GCM central settings and root-only key maintenance."""

import argparse
import base64
import contextlib
import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import time

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

FORMAT = "sls-mass-notify-config-aes256gcm-v1"
KEYRING_FORMAT = "sls-mass-notify-config-keyring-v1"
KEYRING_PATH = Path("/etc/sls-mass-notify/config-keys.json")
DEFAULT_DATA = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
MAX_PLAIN_BYTES = 2 * 1024 * 1024
MAX_FILE_BYTES = 3 * 1024 * 1024
MAX_KEYRING_BYTES = 32768
ROTATE_SECONDS = 365 * 86400
_ID = re.compile(r"[a-f0-9]{32}")
_DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW


class ConfigCryptoError(ValueError):
    """Configuration authentication, protected storage, or format failure."""


def _pairs(items):
    result = {}
    for key, value in items:
        if key in result:
            raise ConfigCryptoError("The protected configuration has duplicate JSON fields.")
        result[key] = value
    return result


def _invalid_constant(value):
    raise ValueError("Non-finite JSON values are unsupported.")


def _object(raw, maximum):
    if isinstance(raw, str):
        raw = raw.encode("utf-8")
    if not isinstance(raw, bytes) or not raw or len(raw) > maximum:
        raise ConfigCryptoError("The protected configuration exceeds its size limit.")
    try:
        value = json.loads(raw.decode("utf-8"), object_pairs_hook=_pairs, parse_constant=_invalid_constant)
    except (UnicodeError, ValueError, RecursionError) as exc:
        raise ConfigCryptoError("The protected configuration contains invalid JSON.") from exc
    if not isinstance(value, dict) or not value:
        raise ConfigCryptoError("The protected configuration must contain a JSON object.")
    pending = [(value, 1)]
    while pending:
        item, depth = pending.pop()
        if depth > 64:
            raise ConfigCryptoError("The protected configuration exceeds its JSON nesting limit.")
        if isinstance(item, dict):
            pending.extend((child, depth + 1) for child in item.values() if isinstance(child, (dict, list)))
        elif isinstance(item, list):
            pending.extend((child, depth + 1) for child in item if isinstance(child, (dict, list)))
    return value


def _binary(value, minimum, maximum):
    try:
        decoded = base64.b64decode(value, validate=True) if isinstance(value, str) else b""
    except (ValueError, UnicodeError) as exc:
        raise ConfigCryptoError("The protected configuration contains invalid encryption data.") from exc
    if not minimum <= len(decoded) <= maximum or base64.b64encode(decoded).decode("ascii") != value:
        raise ConfigCryptoError("The protected configuration contains invalid encryption data.")
    return decoded


def validate_keyring(ring):
    if (not isinstance(ring, dict) or set(ring) != {"format", "active", "keys"}
            or ring["format"] != KEYRING_FORMAT or not isinstance(ring["active"], str)
            or not _ID.fullmatch(ring["active"]) or not isinstance(ring["keys"], dict)
            or not 1 <= len(ring["keys"]) <= 128 or ring["active"] not in ring["keys"]):
        raise ConfigCryptoError("The protected configuration keyring is invalid. Run the signed installer or protected repair.")
    for key_id, record in ring["keys"].items():
        if (not isinstance(key_id, str) or not _ID.fullmatch(key_id) or not isinstance(record, dict)
                or set(record) != {"created_at", "key"} or type(record["created_at"]) is not int
                or not 1 <= record["created_at"] <= 253402300799):
            raise ConfigCryptoError("The protected configuration keyring has invalid key metadata.")
        _binary(record["key"], 32, 32)
    return ring


@contextlib.contextmanager
def _parent(path, root_controlled=False):
    path = Path(path)
    if not path.is_absolute() or ".." in path.parts:
        raise ConfigCryptoError("Protected configuration paths must be absolute and must not contain parent traversal.")
    descriptor = os.open("/", _DIR_FLAGS)
    try:
        for component in path.parts[1:-1]:
            child = os.open(component, _DIR_FLAGS, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
            metadata = os.fstat(descriptor)
            if root_controlled and (metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX)):
                raise ConfigCryptoError("Encryption keys must not be stored below a directory writable by the PBX runtime account.")
        yield descriptor, path.name
    finally:
        os.close(descriptor)


def _read_protected(path, maximum, keyring=False):
    try:
        with _parent(path, root_controlled=keyring) as (parent, name):
            directory = os.fstat(parent)
            if keyring and (directory.st_uid != 0 or directory.st_mode & 0o022):
                raise ConfigCryptoError("The encryption key directory must be owned by root and protected against group or public writes.")
            descriptor = os.open(name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            try:
                before = os.fstat(descriptor)
                if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_mode & 0o027
                        or (keyring and before.st_uid != 0) or not 1 <= before.st_size <= maximum):
                    raise ConfigCryptoError("The protected configuration or encryption key file has unsafe permissions or size.")
                with os.fdopen(os.dup(descriptor), "rb") as stream:
                    raw = stream.read(maximum + 1)
                after = os.fstat(descriptor)
                current = os.stat(name, dir_fd=parent, follow_symlinks=False)
                fields = ("st_dev", "st_ino", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns")
                if any(getattr(before, field) != getattr(after, field) or getattr(after, field) != getattr(current, field) for field in fields) or len(raw) != before.st_size:
                    raise ConfigCryptoError("The protected configuration changed or could not be read completely.")
                return raw
            finally:
                os.close(descriptor)
    except OSError as exc:
        raise ConfigCryptoError("The protected configuration or encryption key file is missing or cannot be read safely.") from exc


def read_keyring(path=None):
    return validate_keyring(_object(_read_protected(path or KEYRING_PATH, MAX_KEYRING_BYTES, True), MAX_KEYRING_BYTES))


def is_encrypted(value):
    return isinstance(value, dict) and value.get("format") == FORMAT


def decode_with_keyring(raw, ring, allow_legacy=False):
    value = _object(raw, MAX_FILE_BYTES)
    if not is_encrypted(value):
        size = len(raw.encode("utf-8")) if isinstance(raw, str) else len(raw)
        if not allow_legacy or any(key in value for key in ("format", "ciphertext", "key_id", "nonce")) or size > MAX_PLAIN_BYTES:
            raise ConfigCryptoError("The central configuration encryption format is unsupported or damaged. No default settings were substituted.")
        return value
    validate_keyring(ring)
    if (set(value) != {"format", "key_id", "nonce", "ciphertext"} or not isinstance(value.get("key_id"), str)
            or not _ID.fullmatch(value["key_id"]) or value["key_id"] not in ring["keys"]):
        raise ConfigCryptoError("The encrypted configuration key is unavailable or its envelope is invalid. Restore its protected key material or configuration backup.")
    nonce = _binary(value["nonce"], 12, 12)
    ciphertext = _binary(value["ciphertext"], 17, MAX_PLAIN_BYTES + 16)
    key = _binary(ring["keys"][value["key_id"]]["key"], 32, 32)
    try:
        plain = AESGCM(key).decrypt(nonce, ciphertext, (FORMAT + "\n" + value["key_id"]).encode("ascii"))
    except InvalidTag as exc:
        raise ConfigCryptoError("The central configuration failed authentication. No settings were loaded; verify the configuration and key backups.") from exc
    return _object(plain, MAX_PLAIN_BYTES)


def decode_config(raw, keyring_path=None, allow_legacy=True):
    value = _object(raw, MAX_FILE_BYTES)
    return decode_with_keyring(raw, read_keyring(keyring_path) if is_encrypted(value) else {}, allow_legacy)


def read_config(path, keyring_path=None, allow_legacy=True):
    return decode_config(_read_protected(path, MAX_FILE_BYTES), keyring_path, allow_legacy)


def encode_with_keyring(settings, ring):
    validate_keyring(ring)
    plain = json.dumps(settings, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")
    _object(plain, MAX_PLAIN_BYTES)
    key_id = ring["active"]
    nonce = os.urandom(12)
    key = _binary(ring["keys"][key_id]["key"], 32, 32)
    ciphertext = AESGCM(key).encrypt(nonce, plain, (FORMAT + "\n" + key_id).encode("ascii"))
    return (json.dumps({"format": FORMAT, "key_id": key_id, "nonce": base64.b64encode(nonce).decode("ascii"),
                        "ciphertext": base64.b64encode(ciphertext).decode("ascii")}, separators=(",", ":")) + "\n").encode("ascii")


def encode_config(settings, keyring_path=None):
    return encode_with_keyring(settings, read_keyring(keyring_path))


def _atomic_write(path, raw, uid, gid):
    with _parent(path) as (parent, name):
        try:
            previous = os.stat(name, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            previous = None
        if previous is not None and (not stat.S_ISREG(previous.st_mode) or previous.st_nlink != 1):
            raise ConfigCryptoError("A protected configuration destination is not a safe regular file.")
        temporary = "." + name + ".tmp." + os.urandom(16).hex()
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        try:
            os.fchown(descriptor, uid, gid)
            os.fchmod(descriptor, 0o640)
            with os.fdopen(os.dup(descriptor), "wb") as stream:
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
            opened = os.fstat(descriptor)
            staged = os.stat(temporary, dir_fd=parent, follow_symlinks=False)
            if (opened.st_dev, opened.st_ino, opened.st_nlink) != (staged.st_dev, staged.st_ino, 1):
                raise ConfigCryptoError("The private configuration staging file changed unexpectedly.")
            os.replace(temporary, name, src_dir_fd=parent, dst_dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(descriptor)
            try:
                os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError:
                pass


def _new_key(now):
    return os.urandom(16).hex(), {"created_at": now, "key": base64.b64encode(os.urandom(32)).decode("ascii")}


def initialize_keyring(path, gid, now=None):
    if os.geteuid() != 0:
        raise ConfigCryptoError("Configuration encryption key creation requires root.")
    path = Path(path)
    now = int(time.time()) if now is None else now
    parent = path.parent
    with _parent(parent, root_controlled=True) as (ancestor, name):
        try:
            os.mkdir(name, 0o750, dir_fd=ancestor)
            os.chown(name, 0, gid, dir_fd=ancestor, follow_symlinks=False)
            os.fsync(ancestor)
        except FileExistsError:
            pass
    with _parent(path, root_controlled=True) as (directory, _):
        metadata = os.fstat(directory)
        if metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise ConfigCryptoError("The encryption key directory is not protected against unauthorized writes.")
        if metadata.st_gid != gid or stat.S_IMODE(metadata.st_mode) != 0o750:
            os.fchown(directory, 0, gid)
            os.fchmod(directory, 0o750)
            os.fsync(directory)
    with _lock(parent / "config-keyring.lock", 0, gid):
        try:
            ring = read_keyring(path)
        except ConfigCryptoError:
            if path.exists() or path.is_symlink():
                raise
            key_id, key = _new_key(now)
            ring = {"format": KEYRING_FORMAT, "active": key_id, "keys": {key_id: key}}
            _atomic_write(path, (json.dumps(ring, separators=(",", ":")) + "\n").encode("ascii"), 0, gid)
        # Correct private root-only permissions without replacing existing keys.
        # Validation above must succeed before a repair grants runtime read access.
        with _parent(path, root_controlled=True) as (directory, name):
            descriptor = os.open(name, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
            try:
                opened = os.fstat(descriptor)
                named = os.stat(name, dir_fd=directory, follow_symlinks=False)
                if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_nlink != 1
                        or opened.st_mode & 0o027 or (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino)):
                    raise ConfigCryptoError("The protected encryption key file changed during permission repair.")
                if opened.st_gid != gid or stat.S_IMODE(opened.st_mode) != 0o640:
                    os.fchown(descriptor, 0, gid)
                    os.fchmod(descriptor, 0o640)
                    os.fsync(descriptor)
            finally:
                os.close(descriptor)
        return ring


@contextlib.contextmanager
def _lock(path, uid, gid):
    with _parent(path) as (parent, name):
        descriptor = os.open(name, os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, 0o640, dir_fd=parent)
        try:
            metadata = os.fstat(descriptor)
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_mode & 0o027
                    or (uid == 0 and metadata.st_uid != 0)):
                raise ConfigCryptoError("A configuration maintenance lock is unsafe.")
            os.fchown(descriptor, uid, gid)
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            yield
        finally:
            os.close(descriptor)


def maintain(data, keyring_path, uid, gid, *, rotate=False, now=None):
    """Keyring commits first; retained keys make partial migration recoverable."""
    if os.geteuid() != 0:
        raise ConfigCryptoError("Configuration encryption maintenance requires root.")
    data, keyring_path = Path(data), Path(keyring_path)
    now = int(time.time()) if now is None else now
    with _lock(data / "announcement-activity.lock", uid, gid), _lock(data / "mass-notifications.config.lock", uid, gid):
        ring = initialize_keyring(keyring_path, gid, now)
        born = ring["keys"][ring["active"]]["created_at"]
        if born > now:
            raise ConfigCryptoError("The encryption key creation time is in the future. Correct the PBX clock before key maintenance.")
        rotated = False
        if rotate and now - born >= ROTATE_SECONDS:
            if len(ring["keys"]) >= 128:
                raise ConfigCryptoError("The encryption keyring retention limit was reached. Archive old backups and review retained keys before rotating.")
            key_id, key = _new_key(now)
            ring["keys"][key_id] = key
            ring["active"] = key_id
            _atomic_write(keyring_path, (json.dumps(ring, separators=(",", ":")) + "\n").encode("ascii"), 0, gid)
            rotated = True
        candidates = [data / name for name in ("mass-notifications.config", "mass-notifications.pending.config",
                      "mass-notifications-settings.json", "mass-notifications-settings.pending.json")]
        if data == DEFAULT_DATA:
            candidates.extend(data.parent / name for name in ("slsmassnotifyserver-settings.json", "slsmassnotifyserver-settings.pending.json"))
        backups = data / "config-backups"
        if backups.exists() or backups.is_symlink():
            with _parent(backups / "entry") as (directory, _):
                names = []
                with os.scandir(directory) as entries:
                    for entry in entries:
                        names.append(entry.name)
                        if len(names) > 1000:
                            raise ConfigCryptoError("Configuration backup maintenance exceeds its file-count limit.")
                candidates.extend(backups / name for name in names if re.fullmatch(r"mass-notifications-[A-Za-z0-9_-]+\.config", name))
        migrated = 0
        for path in candidates:
            if not path.exists() and not path.is_symlink():
                continue
            raw = _read_protected(path, MAX_FILE_BYTES)
            value = _object(raw, MAX_FILE_BYTES)
            settings = decode_with_keyring(raw, ring, allow_legacy=True)
            if is_encrypted(value) and value["key_id"] == ring["active"]:
                continue
            sealed = encode_with_keyring(settings, ring)
            if decode_with_keyring(sealed, ring) != settings:
                raise ConfigCryptoError("Configuration encryption verification failed. Existing settings were preserved.")
            _atomic_write(path, sealed, uid, gid)
            migrated += 1
        return {"ok": True, "rotated": rotated, "encrypted_files": migrated, "next_rotation_at": ring["keys"][ring["active"]]["created_at"] + ROTATE_SECONDS}


def daily(data, keyring_path, uid, gid, now=None):
    """A private success marker bounds full maintenance to once per day."""
    if os.geteuid() != 0:
        raise ConfigCryptoError("Configuration encryption maintenance requires root.")
    now = int(time.time()) if now is None else now
    keyring_path = Path(keyring_path)
    marker = keyring_path.parent / "maintenance-check.json"
    if marker.exists() or marker.is_symlink():
        record = _object(_read_protected(marker, 1024, True), 1024)
        if set(record) != {"last_success", "active"} or type(record["last_success"]) is not int or not isinstance(record["active"], str):
            raise ConfigCryptoError("The protected encryption maintenance marker is invalid.")
        ring = read_keyring(keyring_path)
        if ring["keys"][ring["active"]]["created_at"] > now:
            raise ConfigCryptoError("The encryption key creation time is in the future. Correct the PBX clock before key maintenance.")
        born = ring["keys"][ring["active"]]["created_at"]
        if (0 <= now - record["last_success"] < 86400 and record["active"] == ring["active"]
                and now - born < ROTATE_SECONDS):
            return {"ok": True, "skipped": True}
    result = maintain(data, keyring_path, uid, gid, rotate=True, now=now)
    ring = read_keyring(keyring_path)
    _atomic_write(marker, (json.dumps({"last_success": now, "active": ring["active"]}, separators=(",", ":")) + "\n").encode("ascii"), 0, gid)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("status", "init", "migrate", "rotate", "daily"))
    parser.add_argument("--data", type=Path, default=DEFAULT_DATA)
    parser.add_argument("--keyring", type=Path, default=KEYRING_PATH)
    arguments = parser.parse_args()
    try:
        if arguments.action == "status":
            ring = read_keyring(arguments.keyring)
            born = ring["keys"][ring["active"]]["created_at"]
            result = {"ok": True, "next_rotation_at": born + ROTATE_SECONDS, "retained_keys": len(ring["keys"])}
        else:
            account = pwd.getpwnam("asterisk")
            if arguments.action == "init":
                initialize_keyring(arguments.keyring, account.pw_gid)
                result = {"ok": True}
            elif arguments.action == "daily":
                result = daily(arguments.data, arguments.keyring, account.pw_uid, account.pw_gid)
            else:
                result = maintain(arguments.data, arguments.keyring, account.pw_uid, account.pw_gid, rotate=arguments.action == "rotate")
        print(json.dumps(result, separators=(",", ":")))
        return 0
    except BlockingIOError:
        print('{"ok":true,"deferred":true}')
        return 0
    except (ConfigCryptoError, OSError, KeyError) as exc:
        print(json.dumps({"ok": False, "message": str(exc)}, separators=(",", ":")))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
