#!/usr/bin/env python3
"""Regression checks for Weather.gov alert-chain identity handling."""

import importlib.util
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
POLLER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
MODULE_CLASS = ROOT / "slsmassnotifyserver/Slsmassnotifyserver.class.php"
SPEC = importlib.util.spec_from_file_location("sls_notify_nws_identity", SENDER)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def alert(identifier, message_type, references=None):
    return {
        "id": f"https://api.weather.gov/alerts/{identifier}",
        "properties": {
            "event": "Heat Advisory",
            "messageType": message_type,
            "references": references or [],
        },
    }


original = alert("original-alert", "Alert")
assert MODULE.alert_chain_key(original) == "Heat Advisory|original-alert"

# A malformed/provider-added historical reference on a first-issued Alert must
# never make that new alert collide with an old processed chain.
new_with_history = alert(
    "new-alert",
    "Alert",
    [{"identifier": "old-alert", "sent": "2026-07-01T12:00:00Z"}],
)
assert MODULE.alert_chain_key(new_with_history) == "Heat Advisory|new-alert"

first_update = alert(
    "update-one",
    "Update",
    [{"identifier": "original-alert", "sent": "2026-07-31T12:00:00Z"}],
)
assert MODULE.alert_chain_key(first_update) == MODULE.alert_chain_key(original)

# Later updates can reference both the immediately previous update and the
# original. Selecting the earliest reference keeps the whole chain stable.
second_update = alert(
    "update-two",
    "Update",
    [
        {"identifier": "update-one", "sent": "2026-07-31T13:00:00Z"},
        {"identifier": "original-alert", "sent": "2026-07-31T12:00:00Z"},
    ],
)
assert MODULE.alert_chain_key(second_update) == MODULE.alert_chain_key(original)

poller_source = POLLER.read_text(encoding="utf-8")
module_class_source = MODULE_CLASS.read_text(encoding="utf-8")
for marker in (
    "if msg_type.lower() == 'update':",
    "reference_id = min(candidates)[2]",
    "key_source = reference_id or alert_id",
):
    assert marker in poller_source, f"missing poller chain-identity guard: {marker}"

assert "'Heat Advisory'," in module_class_source, "Heat Advisory missing from FreePBX event choices"
assert '"Heat Advisory"' in poller_source, "Heat Advisory missing from poller event map"

print("NWS alert-chain deduplication regressions passed.")
