#!/usr/bin/env python3
"""Deterministic regressions for durable per-destination external retry state."""

import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
SPEC = importlib.util.spec_from_file_location(
    "sls_notification_destinations_retry_test",
    RUNTIME / "sls_notification_destinations.py",
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)
# Use the same deterministic clock for enqueue, expiry and retry operations.
from unittest import mock
test_clock = mock.patch.object(MODULE.time, 'time', return_value=1100)
test_clock.start()


def fail(message):
    raise AssertionError(message)


config = {
    "mail_to": "alerts@example.com",
    "discord_webhooks": [
        {"id": "primary", "name": "Primary", "url": "https://discord.com/api/webhooks/1/token", "enabled": "1"},
        {"id": "secondary", "name": "Secondary", "url": "https://discord.com/api/webhooks/2/token", "enabled": "1"},
    ],
    "generic_webhooks": [],
}


with tempfile.TemporaryDirectory(prefix="sls-external-retry-") as directory:
    state_path = Path(directory) / "external-deliveries.json"
    delivery_key = MODULE.queue_external_delivery(
        state_path,
        config,
        "Tornado Warning|provider-chain-1",
        "Tornado Warning",
        "Take shelter now.",
        "Tornado Warning",
        "Extreme",
        [("Zone", "TXC491")],
        "2026-08-22T12:00:00Z",
        "nws",
        "provider-alert-1",
        {"zone": "TXC491"},
        "alerts@example.com",
        now=1000,
    )
    if not state_path.is_file() or (state_path.stat().st_mode & 0o777) != 0o640:
        fail("retry task was not durably written with mode 0640 before delivery")
    if not MODULE.external_delivery_recorded(state_path, "nws", "Tornado Warning|provider-chain-1"):
        fail("durable retry task was not available as the local-delivery crash marker")
    if "discord.com/api/webhooks" in state_path.read_text(encoding="utf-8"):
        fail("retry state persisted a webhook secret instead of its stable destination ID")

    email_calls = []
    webhook_calls = []

    def email_sender(_config, subject, body, event, severity, recipients):
        email_calls.append((subject, body, event, severity, recipients))
        return True

    def webhook_dispatcher(*_args, destination_keys=None, **_kwargs):
        keys = set(destination_keys or [])
        webhook_calls.append(keys)
        results = []
        for key in sorted(keys):
            kind, identifier = key.split(":", 1)
            accepted = key == "discord:primary" or len(webhook_calls) > 1
            results.append({
                "type": kind,
                "id": identifier,
                "name": identifier,
                "status": "accepted" if accepted else "failed",
                "attempts": 1,
                "http_status": 204 if accepted else 503,
                "error": "" if accepted else "http_failure",
            })
        return results

    first = MODULE.retry_external_deliveries(
        state_path,
        config,
        "nws",
        live=True,
        preferred_correlation_key="Tornado Warning|provider-chain-1",
        email_sender=email_sender,
        webhook_dispatcher=webhook_dispatcher,
    )
    if first["pending"] != 1 or len(email_calls) != 1:
        fail("first attempt did not retain only its failed external work")
    if webhook_calls != [{"discord:primary", "discord:secondary"}]:
        fail(f"first attempt did not address the configured destination set: {webhook_calls}")

    second = MODULE.retry_external_deliveries(
        state_path,
        config,
        "nws",
        live=True,
        preferred_correlation_key="Tornado Warning|provider-chain-1",
        email_sender=email_sender,
        webhook_dispatcher=webhook_dispatcher,
    )
    if second["pending"] != 0 or MODULE.external_delivery_pending(
        state_path, "nws", "Tornado Warning|provider-chain-1"
    ):
        fail("accepted retry work did not become terminal")
    if len(email_calls) != 1:
        fail("an already accepted email channel was replayed")
    if webhook_calls[-1] != {"discord:secondary"}:
        fail(f"an already accepted webhook destination was replayed: {webhook_calls[-1]}")

    selected_key = MODULE.queue_external_delivery(
        state_path,
        config,
        "Heat Advisory|zone-specific-destination",
        "Heat Advisory",
        "Take heat precautions.",
        source="nws",
        event_id="provider-alert-zone-specific",
        webhook_destination_keys=["discord:secondary"],
        now=1002,
    )
    selected_state = json.loads(state_path.read_text(encoding="utf-8"))
    selected_record = selected_state["deliveries"][selected_key]
    if selected_record.get("webhook_pending") != ["discord:secondary"]:
        fail("zone-specific Weather delivery queued a webhook from another zone")
    if selected_record.get("email_pending"):
        fail("zone-specific webhook-only delivery invented an email route")

    before = state_path.read_bytes()
    state_path.write_text("{corrupt\n", encoding="utf-8")
    corrupt = state_path.read_bytes()
    try:
        MODULE.queue_external_delivery(
            state_path,
            config,
            "new-chain",
            "Subject",
            "Body",
            source="nws",
            event_id="new-event",
        )
    except MODULE.RetryStateError:
        pass
    else:
        fail("corrupt retry state was silently reset")
    if state_path.read_bytes() != corrupt or before == corrupt:
        fail("fail-closed corrupt-state handling unexpectedly rewrote state")


with tempfile.TemporaryDirectory(prefix="sls-external-retry-fairness-") as directory:
    state_path = Path(directory) / "external-deliveries.json"
    # This regression exercises email scheduling only.  Keep the destination
    # inventory empty so a test failure can never fall through to real HTTP.
    fairness_config = {
        "mail_to": "alerts@example.com",
        "discord_webhooks": [],
        "generic_webhooks": [],
    }
    for index in range(4):
        MODULE.queue_external_delivery(
            state_path,
            fairness_config,
            f"chain-{index}",
            f"subject-{index}",
            "body",
            source="nws",
            event_id=f"event-{index}",
            email_recipients="alerts@example.com",
            now=1000 + index,
        )

    fairness_calls = []

    def failing_email(_config, subject, *_args):
        fairness_calls.append(subject)
        return False

    for _run in range(2):
        fairness = MODULE.retry_external_deliveries(
            state_path,
            fairness_config,
            "nws",
            live=True,
            email_sender=failing_email,
            max_records=3,
        )
    if fairness_calls != [
        "subject-0", "subject-1", "subject-2",
        "subject-3", "subject-0", "subject-1",
    ]:
        fail(f"least-recently-attempted retry ordering was not fair: {fairness_calls}")
    if fairness["pending"] != 4:
        fail("failed fairness records unexpectedly became terminal")
    fairness_state = json.loads(state_path.read_text(encoding="utf-8"))
    if fairness_state.get("attempt_sequence") != 6:
        fail("retry attempt sequence was not durably advanced before each delivery")
    if not all(
        int(record.get("last_attempt_at", 0) or 0) > 0
        and int(record.get("last_attempt_sequence", 0) or 0) > 0
        for record in fairness_state.get("deliveries", {}).values()
    ):
        fail("a retry record was starved without a durable attempt marker")

    # Upgrade timestamp-only legacy records without comparing an epoch value to
    # the newer small sequence counter.  Deliberately make the newest record a
    # legacy record; it must get one migration turn before normal rotation.
    legacy_record = next(
        record
        for record in fairness_state["deliveries"].values()
        if record.get("payload", {}).get("subject") == "subject-1"
    )
    legacy_record["last_attempt_sequence"] = 0
    legacy_record["last_attempt_at"] = 2_000_000_000
    state_path.write_text(json.dumps(fairness_state) + "\n", encoding="utf-8")
    MODULE.retry_external_deliveries(
        state_path,
        fairness_config,
        "nws",
        live=True,
        email_sender=failing_email,
        max_records=1,
    )
    if fairness_calls[-1] != "subject-1":
        fail(f"timestamp-only legacy retry record was starved: {fairness_calls[-1]}")
    migrated_state = json.loads(state_path.read_text(encoding="utf-8"))
    if migrated_state.get("attempt_sequence") != 7:
        fail("legacy retry record did not receive the next durable attempt sequence")


source = (RUNTIME.parent / "sls_mass_notify_nws_poll.sh").read_text(encoding="utf-8")
stage_one_position = source.index("# Stage 1: durable external work")
queue_position = source.index('queue_external_destinations "$MAIL_SUBJECT"', stage_one_position)
intent_position = source.index('queue_local_dispatch_intent "$ALERT_KEY"', queue_position)
audio_position = source.index('queue_audio_to_recipients "$AUDIO_SEQUENCE"', intent_position)
visual_position = source.index('trigger_visual_alert "$ALERT_B64"', audio_position)
mark_position = source.index('mark_processed_alert "$ALERT_KEY"', visual_position)
stage_three_position = source.index("# Stage 3 runs only after every actionable alert", mark_position)
retry_position = source.index("retry_pending_external_destinations", stage_three_position)
if not (
    stage_one_position
    < queue_position
    < intent_position
    < audio_position
    < visual_position
    < mark_position
    < stage_three_position
    < retry_position
):
    fail("NWS external/local intent and post-local retry ordering is unsafe")
for marker in (
    "EXTERNAL_DELIVERY_STATE",
    "queue_external_destinations",
    "module.queue_external_delivery(",
    "retry_pending_external_destinations",
    'SLS_EXTERNAL_RETRY_ONLY="1"',
    "--retry-state",
):
    if marker not in source:
        fail(f"NWS durable external retry integration is missing {marker}")

# Live alert email recipients belong to the selected Weather/Lightning service
# group.  The General Settings system/error list must never be merged into a
# routine live alert by an implicit config fallback.
if 'SLS_EMAIL_RECIPIENTS="$LIVE_EMAIL_TO"' not in source:
    fail("NWS live delivery does not use the selected zone email list")
if 'MAIL_TO="${MAIL_TO:+${MAIL_TO} }${zone_email_recipients}"' in source:
    fail("NWS still merges General Settings system email into live zone alerts")

config_source = (RUNTIME / "sls_config.py").read_text(encoding="utf-8")
if 'if "system_notification_emails" not in data:' not in config_source:
    fail("pre-0.1.0 Weather email routes are not preserved during migration")
xweather_source = (RUNTIME / "sls_mass_notify_xweather_poll.py").read_text(encoding="utf-8")
if 'if "system_notification_emails" not in config:' not in xweather_source:
    fail("pre-0.1.0 Lightning email routes are not preserved during migration")

xweather_source = (RUNTIME / "sls_mass_notify_xweather_poll.py").read_text(encoding="utf-8")
if 'raw_mail_to = config.get("mail_to")' in xweather_source or "global_email_values" in xweather_source:
    fail("Lightning still merges General Settings system email into live area alerts")

destination_source = (RUNTIME / "sls_notification_destinations.py").read_text(encoding="utf-8")
if 'os.environ.get("SLS_EMAIL_RECIPIENTS", str(config.get("mail_to") or ""))' in destination_source:
    fail("The live external dispatcher still falls back to General Settings system email")

print("Durable per-channel and per-destination external retry regressions passed.")
