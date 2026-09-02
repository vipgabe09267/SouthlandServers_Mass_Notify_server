#!/usr/bin/env python3
"""Deterministic system/error email regression checks (no real mail)."""

import hashlib
import importlib.util
import json
import sys
import tempfile
from pathlib import Path


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
SPEC = importlib.util.spec_from_file_location(
    "sls_system_notifications_test",
    RUNTIME / "sls_system_notifications.py",
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def fail(message):
    raise AssertionError(message)


if MODULE._recipient_values({"mail_to": "Legacy@Example.com legacy@example.com"}) != []:
    fail("legacy live-alert recipients were silently enabled for system/error mail")
if MODULE._recipient_values({"system_notification_emails": "", "mail_to": "legacy@example.com"}) != []:
    fail("an explicitly empty canonical system recipient list fell back to legacy mail_to")
try:
    MODULE._recipient_values({"system_notification_emails": {"unexpected": "shape"}})
except RuntimeError:
    pass
else:
    fail("a structurally invalid system recipient list was accepted")
try:
    MODULE._recipient_values({"system_notification_emails": "not-an-email"})
except RuntimeError:
    pass
else:
    fail("an invalid system notification email address was accepted")

with tempfile.TemporaryDirectory(prefix="sls-system-status-read-") as directory:
    status_path = Path(directory) / "status.json"
    for corrupt in ("", '{"last_fault_status":', "\xff"):
        status_path.write_bytes(corrupt.encode("latin-1"))
        if MODULE._read_json(status_path, tolerate_corrupt=True) is not None:
            fail("an empty, partial, or corrupt operational status snapshot was not deferred safely")
    status_path.write_text("[]", encoding="utf-8")
    if MODULE._read_json(status_path, tolerate_corrupt=True) is not None:
        fail("a non-object operational status snapshot was not deferred safely")
    status_path.write_text('{"last_fault_status":"ok"}\n', encoding="utf-8")
    if MODULE._read_json(status_path, tolerate_corrupt=True).get("last_fault_status") != "ok":
        fail("a valid operational status snapshot was not read")
    status_path.write_text('{"partial":', encoding="utf-8")
    try:
        MODULE._read_json(status_path)
    except RuntimeError:
        pass
    else:
        fail("corrupt protected JSON was accepted outside the status-tolerant read path")


manual_faults = MODULE.collect_faults(
    {
        "last_fault_at": "2026-08-22T12:00:00Z",
        "last_fault_source": "manual_test",
        "last_fault_stage": "delivery",
        "last_fault_message": "A manual test failed",
        "last_xweather_test_status": "fault",
        "last_xweather_test_message": "A Lightning test failed",
        "xweather_groups": [],
    },
    {},
    {},
    {},
)
if manual_faults:
    fail("manual Weather or Lightning test state became a live system email fault")

weather_faults = MODULE.collect_faults(
    {
        "last_fault_at": "2026-08-22T12:00:00Z",
        "last_fault_source": "nws",
        "last_fault_stage": "poll",
        "last_fault_message": "Weather.gov is unavailable",
    },
    {},
    {},
    {},
)
if set(weather_faults) != {"weather"}:
    fail("a live Weather.gov fault was not classified as Weather")
module_faults = MODULE.collect_faults(
    {
        "last_fault_at": "2026-08-22T12:00:00Z",
        "last_fault_stage": "dependencies",
        "last_fault_message": "A runtime dependency is unavailable",
    },
    {},
    {},
    {},
)
if set(module_faults) != {"module"}:
    fail("a non-Weather module fault was mislabeled as Weather")

group_status = {
    "xweather_groups": {
        f"area_{index}": {
            "group_name": f"Area {index}",
            "last_xweather_poll_status": "fault",
            "last_xweather_poll_message": f"Provider error {index}",
            "last_xweather_poll_at": "2026-08-22T12:00:00Z",
        }
        for index in range(8)
    }
}
group_faults = MODULE.collect_faults(group_status, {}, {}, {})
if len(group_faults) != MODULE.MAX_XWEATHER_GROUP_FAULTS:
    fail("the protected Lightning-area fault cap was not enforced")

multi_area_faults = MODULE.collect_faults(
    {
        "last_xweather_poll_status": "fault",
        "last_xweather_poll_message": "Mirrored aggregate poll fault",
        "last_xweather_poll_at": "2026-08-22T12:00:00Z",
        "last_xweather_delivery_status": "fault",
        "last_xweather_delivery_message": "Mirrored aggregate delivery fault",
        "last_xweather_delivery_at": "2026-08-22T12:00:00Z",
        "xweather_groups": {
            "area_one": {
                "group_name": "Area One",
                "last_xweather_poll_status": "fault",
                "last_xweather_poll_message": "Area poll fault",
                "last_xweather_poll_at": "2026-08-22T12:00:00Z",
                "last_xweather_delivery_status": "fault",
                "last_xweather_delivery_message": "Area delivery fault",
                "last_xweather_delivery_at": "2026-08-22T12:01:00Z",
            },
        },
    },
    {},
    {},
    {},
)
if set(multi_area_faults) != {
    "lightning_group_area_one_poll",
    "lightning_group_area_one_delivery",
}:
    fail(f"Lightning aggregate/group faults were duplicated or incomplete: {sorted(multi_area_faults)}")

with tempfile.TemporaryDirectory(prefix="sls-system-email-status-") as directory:
    status_path = Path(directory) / "status.json"
    status_path.write_text(json.dumps({
        "last_fault_at": "2026-08-22T12:00:00Z",
        "last_fault_stage": "api",
        "fault_email_sent_at": "",
        "nws_groups": {
            "weather": {
                "last_fault_stage": "api",
                "fault_email_sent_at": "",
                "faults": {
                    "api": {
                        "at": "2026-08-22T12:00:00Z",
                        "stage": "api",
                        "message": "Weather.gov is unavailable",
                        "email_sent_at": "",
                    },
                },
            },
        },
    }), encoding="utf-8")
    MODULE._mark_weather_fault_email_sent(status_path, {
        "stage": "api",
        "occurred_at": "2026-08-22T12:00:00Z",
        "group_id": "weather",
    }, "2026-08-22T12:05:00+00:00")
    marked = json.loads(status_path.read_text(encoding="utf-8"))
    if marked.get("fault_email_sent_at") != "2026-08-22T12:05:00+00:00":
        fail("successful system Weather email was not acknowledged in aggregate status")
    if marked["nws_groups"]["weather"]["faults"]["api"].get("email_sent_at") != "2026-08-22T12:05:00+00:00":
        fail("successful system Weather email was not acknowledged in per-zone status")


with tempfile.TemporaryDirectory(prefix="sls-system-email-") as directory:
    state_path = Path(directory) / "system-notification-email-state.json"
    config = {
        "system_notification_emails": "System@Example.com system@example.com",
        "mail_to": "legacy@example.com",
    }
    first_fault = dict(MODULE._candidate(
        "weather",
        "poll",
        "Weather.gov request failed once",
        "2026-08-22T12:00:00Z",
    )[1])
    calls = []

    def accepted_sender(_config, subject, body, event, severity, *, recipients_override=""):
        calls.append((subject, body, event, severity, recipients_override))
        return True

    first = MODULE.process_faults(
        config,
        {"weather": first_fault},
        state_path,
        sender=accepted_sender,
        now=1_000_000,
    )
    if first != {"sent": 1, "active": 1} or len(calls) != 1:
        fail("the first active fault did not send exactly once")
    if calls[0][4] != "System@Example.com":
        fail("system email did not use only the canonical deduplicated recipient list")
    if (state_path.stat().st_mode & 0o777) != 0o640:
        fail("system notification state was not written with mode 0640")

    changed_message = dict(MODULE._candidate(
        "weather",
        "poll",
        "Weather.gov request failed again with a changing counter 2",
        "2026-08-22T12:01:00Z",
    )[1])
    repeated = MODULE.process_faults(
        config,
        {"weather": changed_message},
        state_path,
        sender=accepted_sender,
        now=1_000_060,
    )
    if repeated["sent"] != 0 or len(calls) != 1:
        fail("changing wording for one continuously active fault caused an email storm")

    MODULE.process_faults(config, {}, state_path, sender=accepted_sender, now=1_000_120)
    resolved_state = json.loads(state_path.read_text(encoding="utf-8"))
    if resolved_state.get("active"):
        fail("healthy status did not durably clear the active-fault marker")
    MODULE.process_faults(
        config,
        {"weather": first_fault},
        state_path,
        sender=accepted_sender,
        now=1_000_180,
    )
    if len(calls) != 2:
        fail("a fault recurrence after a healthy transition was not sent")


with tempfile.TemporaryDirectory(prefix="sls-system-email-retry-") as directory:
    state_path = Path(directory) / "state.json"
    config = {"system_notification_emails": "system@example.com"}
    fault = MODULE._candidate(
        "maintenance",
        "repair",
        "Repair did not complete",
        "2026-08-22T12:00:00Z",
    )[1]
    rejected_calls = []

    def rejected_sender(*_args, **_kwargs):
        rejected_calls.append(True)
        return False

    try:
        MODULE.process_faults(
            config,
            {"maintenance": fault},
            state_path,
            sender=rejected_sender,
            now=2_000_000,
        )
    except RuntimeError as exc:
        if "not accepted" not in str(exc):
            raise
    else:
        fail("a local mailer rejection was recorded as successful delivery")
    rejected_state = json.loads(state_path.read_text(encoding="utf-8"))
    if rejected_state.get("active") or "maintenance" not in rejected_state.get("attempts", {}):
        fail("a rejected system email did not retain only its retry marker")

    throttled = MODULE.process_faults(
        config,
        {"maintenance": fault},
        state_path,
        sender=rejected_sender,
        now=2_000_010,
    )
    if throttled["sent"] != 0 or len(rejected_calls) != 1:
        fail("a rejected system email ignored the protected retry throttle")

    retry_calls = []

    def retry_sender(*_args, **_kwargs):
        retry_calls.append(True)
        return True

    retried = MODULE.process_faults(
        config,
        {"maintenance": fault},
        state_path,
        sender=retry_sender,
        now=2_000_000 + MODULE.RETRY_SECONDS,
    )
    if retried["sent"] != 1 or retry_calls != [True]:
        fail("a rejected system email was not retried after its throttle elapsed")


with tempfile.TemporaryDirectory(prefix="sls-system-email-state-") as directory:
    state_path = Path(directory) / "state.json"
    current = 3_000_000
    oversized_history = {
        hashlib.sha256(str(index).encode("ascii")).hexdigest(): current - index
        for index in range(MODULE.MAX_HISTORY_RECORDS + 100)
    }
    state_path.write_text(json.dumps({
        "version": 1,
        "active": {"stale": "f" * 64},
        "attempts": {"stale": {"fingerprint": "f" * 64, "at": current}},
        "history": oversized_history,
    }), encoding="utf-8")
    MODULE.process_faults(
        {"system_notification_emails": ""},
        {},
        state_path,
        sender=lambda *_args, **_kwargs: fail("empty recipients attempted to send real mail"),
        now=current,
    )
    compacted = json.loads(state_path.read_text(encoding="utf-8"))
    if compacted.get("active") or compacted.get("attempts"):
        fail("resolved or unknown state channels were retained")
    if len(compacted.get("history", {})) != MODULE.MAX_HISTORY_RECORDS:
        fail("system notification history was not bounded")

    target = Path(directory) / "target.json"
    target.write_text("{}\n", encoding="utf-8")
    state_path.unlink()
    state_path.symlink_to(target)
    try:
        MODULE.process_faults(
            {"system_notification_emails": ""},
            {},
            state_path,
            sender=lambda *_args, **_kwargs: True,
            now=current,
        )
    except (OSError, RuntimeError):
        pass
    else:
        fail("a symbolic-link system notification state path was followed")
    if target.read_text(encoding="utf-8") != "{}\n":
        fail("symbolic-link rejection modified the link target")


print("System notification email regressions passed.")
