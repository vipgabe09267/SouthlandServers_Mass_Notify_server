#!/usr/bin/python3
"""Cache bounded resource and audit aggregates; no notification or repair actions."""
from __future__ import annotations

from datetime import datetime
import json
import math
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).absolute().parent))
from sls_atomic_json import locked_json, open_directory, read_json, _object
from sls_resource_capacity import probe_compute
import sls_install_transaction as transactions

DATA = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
QUEUE_FIELDS = (
    "pending_external",
    "expired_external",
    "queue_errors",
    "pending_weather",
    "failed_weather",
    "uncertain_weather",
    "expired_weather",
    "weather_running",
    "weather_deadline_misses",
    "audit_failed_records",
)


def postfix_health() -> dict:
    """Inspect the daemon and queue without submitting or modifying mail."""
    from sls_mail_admission import inspect_queue, MailAdmissionBlocked, QUEUE_LIMIT
    result = {"running": False, "queue_available": False, "queue_depth": None,
              "queue_limit": QUEUE_LIMIT, "queue_at_limit": False, "code": "postfix_unavailable"}
    try:
        path = Path('/usr/sbin/postfix')
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o7022:
            return result
        status = subprocess.run([str(path), 'status'], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, cwd='/', timeout=3, env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C'})
        if status.returncode != 0:
            result['code'] = 'postfix_not_running'
            return result
        result['running'] = True
        result['queue_depth'] = inspect_queue()
        result['queue_available'] = True
        result['queue_at_limit'] = result['queue_depth'] >= QUEUE_LIMIT
        result['code'] = 'postfix_queue_full' if result['queue_at_limit'] else 'postfix_running'
    except MailAdmissionBlocked:
        result['code'] = 'postfix_queue_unavailable'
    except (OSError, ValueError, subprocess.SubprocessError):
        pass
    return result


def audit_aggregates(path: Path, now: int) -> dict:
    owner = pwd.getpwnam("asterisk").pw_uid
    parent = open_directory(path.parent, owner)
    try:
        descriptor = os.open(
            path.name,
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
            dir_fd=parent,
        )
    except FileNotFoundError:
        os.close(parent)
        return {"sample_count": 0, "credential_usage": {}, "complete": True}
    except OSError:
        os.close(parent)
        raise
    try:
        before = os.fstat(descriptor)
        if (
            not stat.S_ISREG(before.st_mode)
            or before.st_nlink != 1
            or before.st_uid not in (0, owner)
            or stat.S_IMODE(before.st_mode) not in (0o600, 0o640)
            or before.st_size > 64 * 1024 * 1024
        ):
            raise ValueError("unsafe_audit")
        offset = max(0, before.st_size - 1024 * 1024)
        os.lseek(descriptor, offset, os.SEEK_SET)
        raw = b""
        while len(raw) < 1024 * 1024 + 1:
            block = os.read(descriptor, min(65536, 1024 * 1024 + 1 - len(raw)))
            if not block:
                break
            raw += block
        after = os.fstat(descriptor)
        named = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_size,
            value.st_mtime_ns,
            value.st_ctime_ns,
            value.st_mode,
            value.st_uid,
            value.st_nlink,
        )
        if (
            len(raw) > 1024 * 1024
            or identity(before) != identity(after)
            or identity(after) != identity(named)
        ):
            raise ValueError("changed_audit")
        lines = raw.splitlines()
        if offset:
            lines = lines[1:]
        complete = not offset and len(lines) <= 2000
        durations = []
        usage = {}
        responses = {}
        samples = 0
        for line in lines[-2000:]:
            if not line or len(line) > 4096:
                raise ValueError("invalid_audit")
            row = json.loads(
                line,
                object_pairs_hook=_object,
                parse_constant=lambda _: (_ for _ in ()).throw(
                    ValueError("invalid_audit")
                ),
            )
            if not isinstance(row, dict):
                raise ValueError("invalid_audit")
            stamp = row.get("created_at")
            if not isinstance(stamp, str) or len(stamp) > 40:
                raise ValueError("invalid_audit")
            at = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
            if at.tzinfo is None or not 0 < at.timestamp() <= now + 60:
                raise ValueError("invalid_audit")
            credential = row.get("credential_id")
            if isinstance(credential, str) and re.fullmatch(
                r"(?:legacy|api_[a-f0-9]{24})", credential
            ):
                if len(usage) >= 101 and credential not in usage:
                    raise ValueError("usage_capacity")
                usage[credential] = max(usage.get(credential, 0), int(at.timestamp()))
            if now - at.timestamp() > 3600:
                continue
            status = row.get("status")
            if type(status) is not int or not 100 <= status <= 599:
                raise ValueError("invalid_audit")
            responses[str(status // 100) + "xx"] = (
                responses.get(str(status // 100) + "xx", 0) + 1
            )
            samples += 1
            duration = row.get("duration_ms")
            if type(duration) is int and 0 <= duration <= 600000:
                durations.append(duration)
        durations.sort()
        return {
            "sample_count": samples,
            "response_classes": responses,
            "credential_usage": usage,
            "complete": complete,
            "duration_samples": len(durations),
            "duration_p95_ms": (
                durations[max(0, math.ceil(len(durations) * 0.95) - 1)]
                if durations
                else None
            ),
        }
    finally:
        os.close(descriptor)
        os.close(parent)


def collect(
    data: Path = DATA, installer: Path = transactions.ROOT, now: int | None = None
) -> dict:
    now = int(time.time()) if now is None else now
    result = {"schema": 1, "checked_at": now, "errors": []}
    result['postfix'] = postfix_health()
    try:
        cpu, memory = probe_compute()
        result["compute"] = {
            "effective_cpu_count": cpu,
            "effective_memory_bytes": memory,
        }
    except (OSError, ValueError):
        result["errors"].append("compute_unavailable")
    result["filesystems"] = {}
    for label, path in [
        ("sls_data", data),
        ("runtime", Path("/usr/local/bin/sls_mass_notify")),
        ("logs", Path("/var/log")),
        ("temporary", Path("/tmp")),
    ]:
        try:
            descriptor = os.open(
                path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
            )
            try:
                info = os.fstatvfs(descriptor)
            finally:
                os.close(descriptor)
            result["filesystems"][label] = {
                "available_bytes": info.f_bavail * info.f_frsize,
                "available_inodes": info.f_favail,
                "read_only": bool(info.f_flag & os.ST_RDONLY),
            }
        except OSError:
            result["errors"].append(label + "_unavailable")
    try:
        storage = read_json(
            data / "storage-summary.json", maximum=65536, runtime_owner=True
        )
        result["queues"] = {
            key: storage[key]
            for key in QUEUE_FIELDS
            if type(storage.get(key)) is int and 0 <= storage[key] <= 1000000
        }
        result["storage_checked_at"] = (
            storage.get("checked_at")
            if type(storage.get("checked_at")) is int
            else None
        )
    except (OSError, RuntimeError, ValueError):
        result["errors"].append("storage_unavailable")
    try:
        result["api"] = audit_aggregates(data / "control-api-audit.jsonl", now)
    except (OSError, RuntimeError, ValueError):
        result["errors"].append("api_aggregates_unavailable")
    result["installer_transactions"] = {"counts": {}, "complete": True}
    try:
        descriptor = open_directory(installer, 0)
    except FileNotFoundError:
        descriptor = None
    except (OSError, RuntimeError):
        descriptor = None
        result["installer_transactions"]["complete"] = False
    if descriptor is not None:
        try:
            deadline = time.monotonic() + 2
            with os.scandir(descriptor) as entries:
                for index, entry in enumerate(entries):
                    name = entry.name
                    if index >= 256 or time.monotonic() > deadline:
                        result["installer_transactions"]["complete"] = False
                        break
                    if not transactions.NAME.fullmatch(name):
                        continue
                    row = transactions.state(installer / name)
                    phase = row["phase"]
                    counts = result["installer_transactions"]["counts"]
                    counts[phase] = counts.get(phase, 0) + 1
        except (OSError, RuntimeError, ValueError):
            result["installer_transactions"]["complete"] = False
        finally:
            os.close(descriptor)
    try:
        from sls_mail_evidence import validate_state

        mail = validate_state(
            read_json(
                data / "mail-evidence.json", maximum=4 * 1024 * 1024, runtime_owner=True
            )
        )
        fresh = 0 < mail["generated_at"] <= now and now - mail["generated_at"] <= 180
        result["mail"] = {"available": fresh and mail["available"], "fresh": fresh}
        if fresh and mail["queue_depth"] is not None:
            result["mail"]["queue_depth"] = mail["queue_depth"]
    except (OSError, RuntimeError, ValueError):
        pass  # Optional observer; absence is not a core readiness failure.
    return result


def main() -> int:
    if sys.argv[1:] or os.geteuid() != 0:
        raise SystemExit(
            "The support snapshot is a root maintenance task and accepts no arguments."
        )
    snapshot = collect()
    with locked_json(
        DATA / "support-snapshot.json", maximum=65536, runtime_owner=True
    ) as current:
        current.clear()
        current.update(snapshot)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
