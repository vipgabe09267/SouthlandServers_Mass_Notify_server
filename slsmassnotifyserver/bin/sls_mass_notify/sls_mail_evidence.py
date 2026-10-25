#!/usr/bin/python3
"""Optional read-only Postfix evidence; never submits, retries or removes mail."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import selectors
import signal
import stat
import subprocess
import sys
import time

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).absolute().parent))
from sls_atomic_json import locked_json, read_json, _object
from sls_config_crypto import read_config
from sls_mail_admission import inspect_queue, MailAdmissionBlocked
from sls_cluster_guard import labs_enabled

DATA = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
MAX_BYTES = 4 * 1024 * 1024
MAX_ROWS = 5000
RETENTION = 7 * 86400
ROLES = ("pickup", "cleanup", "smtp", "lmtp", "qmgr")
QUEUE_ID = re.compile(r"^([A-Za-z0-9]{5,64}): (.*)$")
MESSAGE_ID = re.compile(
    r"^message-id=(<sls-[a-f0-9]{32}-[a-f0-9]{24}@[A-Za-z0-9.-]{1,253}>)$"
)
STAGES = {"sent": "relay_accepted", "deferred": "deferred", "bounced": "bounced"}


class EvidenceUnavailable(RuntimeError):
    pass


def digest(value):
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def bounded_output(command, maximum=MAX_BYTES, timeout=3):
    process = subprocess.Popen(
        command,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        close_fds=True,
        start_new_session=True,
        cwd="/",
        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"},
    )
    output = bytearray()
    deadline = time.monotonic() + timeout
    try:
        os.set_blocking(process.stdout.fileno(), False)
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while time.monotonic() < deadline:
                if not selector.select(
                    min(0.1, max(0.001, deadline - time.monotonic()))
                ):
                    continue
                block = os.read(process.stdout.fileno(), 32768)
                if not block:
                    if (
                        process.wait(timeout=max(0.01, deadline - time.monotonic()))
                        != 0
                    ):
                        raise EvidenceUnavailable("mail_journal_unavailable")
                    return bytes(output)
                output.extend(block)
                if len(output) > maximum:
                    raise EvidenceUnavailable("mail_journal_read_budget")
        raise EvidenceUnavailable("mail_journal_deadline")
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        process.stdout.close()


def trusted_programs(directory):
    root = Path(directory)
    if not root.is_absolute() or root.resolve() != root:
        raise EvidenceUnavailable("postfix_program_directory_unsafe")
    for parent in (root, *root.parents):
        metadata = parent.lstat()
        if (
            not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_mode & 0o022
        ):
            raise EvidenceUnavailable("postfix_program_directory_unsafe")
    out = {}
    for role in ROLES:
        path = root / role
        metadata = path.lstat()
        if (
            not stat.S_ISREG(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_mode & 0o7022
            or not metadata.st_mode & 0o111
        ):
            raise EvidenceUnavailable("postfix_program_unsafe")
        out[str(path)] = role
    return out


def empty_state():
    return {
        "schema": 1,
        "generated_at": 0,
        "available": False,
        "reason_code": "not_observed",
        "queue_depth": None,
        "cycles": {},
        "messages": {},
    }


def validate_state(value):
    if (
        not isinstance(value, dict)
        or set(value) != set(empty_state())
        or type(value.get("schema")) is not int
        or value["schema"] != 1
        or type(value.get("generated_at")) is not int
        or value["generated_at"] < 0
        or type(value.get("available")) is not bool
        or not isinstance(value.get("reason_code"), str)
        or not re.fullmatch(r"[a-z_]{0,80}", value["reason_code"])
        or (
            value.get("queue_depth") is not None
            and (
                type(value["queue_depth"]) is not int
                or not 0 <= value["queue_depth"] <= 1000
            )
        )
    ):
        raise EvidenceUnavailable("mail_evidence_cache_invalid")
    for field in ("cycles", "messages"):
        rows = value.get(field)
        if not isinstance(rows, dict) or len(rows) > MAX_ROWS:
            raise EvidenceUnavailable("mail_evidence_cache_invalid")
        for key, row in rows.items():
            if (
                not isinstance(key, str)
                or not re.fullmatch(r"[a-f0-9]{64}", key)
                or not isinstance(row, dict)
            ):
                raise EvidenceUnavailable("mail_evidence_cache_invalid")
            if field == "cycles":
                if (
                    set(row)
                    != {
                        "pickup_at",
                        "cleanup_at",
                        "message_hash",
                        "updated_at",
                        "closed",
                    }
                    or any(
                        type(row.get(k)) is not int or row[k] < 0
                        for k in ("pickup_at", "cleanup_at", "updated_at")
                    )
                    or type(row.get("closed")) is not bool
                    or not isinstance(row.get("message_hash"), str)
                    or not re.fullmatch(r"(?:|[a-f0-9]{64})", row["message_hash"])
                ):
                    raise EvidenceUnavailable("mail_evidence_cache_invalid")
            elif (
                set(row)
                != {
                    "message_hash",
                    "recipient_hash",
                    "stage",
                    "dsn",
                    "observed_at",
                    "expires_at",
                    "queue_hash",
                }
                or any(
                    not isinstance(row.get(k), str)
                    or not re.fullmatch(r"[a-f0-9]{64}", row[k])
                    for k in ("message_hash", "recipient_hash", "queue_hash")
                )
                or row.get("stage") not in STAGES.values()
                or not isinstance(row.get("dsn"), str)
                or not re.fullmatch(r"[245]\.[0-9]{1,3}\.[0-9]{1,3}", row["dsn"])
                or type(row.get("observed_at")) is not int
                or row["observed_at"] < 1
                or type(row.get("expires_at")) is not int
                or row["expires_at"] != row["observed_at"] + RETENTION
            ):
                raise EvidenceUnavailable("mail_evidence_cache_invalid")
    return value


def correlate(previous, entries, *, programs, postfix_uid, asterisk_uid, now):
    state = json.loads(json.dumps(validate_state(previous)))
    cycles = {
        k: v
        for k, v in state["cycles"].items()
        if v["updated_at"] > now - RETENTION
        and not (v["closed"] and v["updated_at"] < now - 600)
        and not (not v["message_hash"] and v["updated_at"] < now - 600)
    }
    messages = {k: v for k, v in state["messages"].items() if v["expires_at"] > now}
    trusted = []
    for ordinal, row in enumerate(entries):
        if (
            not isinstance(row, dict)
            or row.get("_TRANSPORT") not in ("syslog", "journal")
            or not isinstance(row.get("_EXE"), str)
            or row["_EXE"] not in programs
            or row.get("_UID") not in (str(postfix_uid), "0")
            or postfix_uid in (0, asterisk_uid)
            or not isinstance(row.get("__REALTIME_TIMESTAMP"), str)
            or not re.fullmatch(r"[0-9]{1,20}", row["__REALTIME_TIMESTAMP"])
            or not isinstance(row.get("MESSAGE"), str)
            or len(row["MESSAGE"]) > 4096
            or re.search(r"[\x00-\x1f\x7f]", row["MESSAGE"])
        ):
            continue
        at = int(row["__REALTIME_TIMESTAMP"]) // 1_000_000
        if not now - 600 <= at <= now:
            continue
        trusted.append(
            (
                int(row["__REALTIME_TIMESTAMP"]),
                ordinal,
                programs[row["_EXE"]],
                row["MESSAGE"],
            )
        )
    for microseconds, _, role, message in sorted(trusted):
        at = microseconds // 1_000_000
        match = QUEUE_ID.fullmatch(message)
        if not match:
            continue
        queue, text = match.groups()
        queue_hash = digest(queue)
        cycle = cycles.get(queue_hash)
        pickup = (
            re.fullmatch(r"uid=([0-9]{1,10}) from=<[^<>]{0,512}>", text)
            if role == "pickup"
            else None
        )
        cleanup = MESSAGE_ID.fullmatch(text) if role == "cleanup" else None
        if pickup:
            if int(pickup[1]) != asterisk_uid:
                cycles.pop(queue_hash, None)
                continue
            if cycle and not cycle["closed"] and cycle["pickup_at"] == at:
                continue
            # A new pickup ends any previous queue-ID lifecycle. A cleanup can
            # precede pickup in the journal by a few seconds; bind only that window.
            early = (
                cycle
                if cycle
                and not cycle["pickup_at"]
                and abs(cycle["cleanup_at"] - at) <= 5
                else None
            )
            cycles[queue_hash] = {
                "pickup_at": at,
                "cleanup_at": early["cleanup_at"] if early else 0,
                "message_hash": early["message_hash"] if early else "",
                "updated_at": at,
                "closed": False,
            }
        elif cleanup:
            if cycle and cycle["closed"]:
                continue
            if cycle and cycle["pickup_at"] and abs(cycle["pickup_at"] - at) > 5:
                continue
            if (
                cycle
                and cycle["message_hash"]
                and cycle["message_hash"] != digest(cleanup[1])
            ):
                cycles.pop(queue_hash, None)
                continue
            cycle = cycle or {
                "pickup_at": 0,
                "cleanup_at": 0,
                "message_hash": "",
                "updated_at": at,
                "closed": False,
            }
            cycle.update(cleanup_at=at, message_hash=digest(cleanup[1]), updated_at=at)
            cycles[queue_hash] = cycle
        elif role == "cleanup" and text.startswith("message-id="):
            cycles.pop(queue_hash, None)
        elif role == "qmgr" and text == "removed" and cycle:
            cycle.update(closed=True, updated_at=at)
        elif (
            role in ("smtp", "lmtp")
            and cycle
            and cycle["pickup_at"]
            and cycle["cleanup_at"]
            and not cycle["closed"]
        ):
            recipient = re.search(r"(?:^|, )to=<([^<>\x00-\x20]{1,254})>", text)
            outcome = re.search(
                r"(?:^|, )dsn=([245]\.[0-9]{1,3}\.[0-9]{1,3}), status=(sent|deferred|bounced)(?:\s|$)",
                text,
            )
            if (
                not recipient
                or not outcome
                or at < max(cycle["pickup_at"], cycle["cleanup_at"])
            ):
                continue
            dsn, status = outcome.groups()
            if dsn[0] != {"sent": "2", "deferred": "4", "bounced": "5"}[status]:
                continue
            address_hash = digest(recipient[1].lower())
            key = digest(cycle["message_hash"] + "|" + address_hash)
            saved = messages.get(key)
            if saved and (
                saved["observed_at"] > at
                or (
                    saved["stage"] in ("relay_accepted", "bounced")
                    and saved["queue_hash"] == queue_hash
                )
            ):
                continue
            messages[key] = {
                "message_hash": cycle["message_hash"],
                "recipient_hash": address_hash,
                "stage": STAGES[status],
                "dsn": dsn,
                "observed_at": at,
                "expires_at": at + RETENTION,
                "queue_hash": queue_hash,
            }
            cycle["updated_at"] = at
    if len(cycles) > MAX_ROWS or len(messages) > MAX_ROWS:
        raise EvidenceUnavailable("mail_evidence_row_budget")
    state.update(
        generated_at=now,
        available=bool(trusted),
        reason_code="" if trusted else "postfix_journal_origin_unavailable",
        cycles=cycles,
        messages=messages,
    )
    return validate_state(state)


def collect(previous, now):
    daemon = (
        bounded_output(["/usr/sbin/postconf", "-h", "daemon_directory"], 4096, 1)
        .decode()
        .strip()
    )
    owner = (
        bounded_output(["/usr/sbin/postconf", "-h", "mail_owner"], 256, 1)
        .decode()
        .strip()
    )
    programs = trusted_programs(daemon)
    postfix_uid = pwd.getpwnam(owner).pw_uid
    asterisk_uid = pwd.getpwnam("asterisk").pw_uid
    since = (
        max(now - 300, min(now - 1, previous["generated_at"] - 5))
        if previous["generated_at"]
        else now - 300
    )
    command = [
        "/usr/bin/journalctl",
        "--no-pager",
        "--output=json",
        "--since=@" + str(since),
        "--until=@" + str(now),
    ]
    command.extend("_EXE=" + path for path in programs)
    raw = bounded_output(command)
    lines = raw.splitlines()
    if len(lines) > 20000:
        raise EvidenceUnavailable("mail_journal_row_budget")
    entries = [
        json.loads(
            line,
            object_pairs_hook=_object,
            parse_constant=lambda _: (_ for _ in ()).throw(
                ValueError("nonfinite_json")
            ),
        )
        for line in lines
        if line.strip()
    ]
    result = correlate(
        previous,
        entries,
        programs=programs,
        postfix_uid=postfix_uid,
        asterisk_uid=asterisk_uid,
        now=now,
    )
    try:
        result["queue_depth"] = inspect_queue()
    except MailAdmissionBlocked:
        result["queue_depth"] = None
    return result


def main():
    if sys.argv[1:] or os.geteuid() != 0:
        raise SystemExit(
            "Mail evidence is a root maintenance task and accepts no arguments."
        )
    settings = read_config(DATA / "mass-notifications.config")
    config = settings.get("enterprise_integrations", {})
    if (
        not labs_enabled(settings)
        or config.get("enabled") != "1"
        or config.get("mail_evidence", {}).get("enabled") != "1"
    ):
        return 0
    now = int(time.time())
    previous = (
        read_json(DATA / "mail-evidence.json", maximum=MAX_BYTES, runtime_owner=True)
        or empty_state()
    )
    try:
        result = collect(validate_state(previous), now)
    except (OSError, ValueError, KeyError, RuntimeError, subprocess.SubprocessError):
        result = validate_state(previous)
        result.update(
            generated_at=now,
            available=False,
            reason_code="postfix_evidence_unavailable",
            queue_depth=None,
        )
    with locked_json(
        DATA / "mail-evidence.json", maximum=MAX_BYTES, runtime_owner=True
    ) as current:
        current.clear()
        current.update(result)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
