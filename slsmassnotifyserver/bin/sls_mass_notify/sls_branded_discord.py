#!/usr/bin/env python3
"""Compatibility wrapper for branded Discord destination delivery."""

import json
import os
import sys
from pathlib import Path

from sls_notification_destinations import (
    alert_profile,
    build_discord_payload,
    dispatch_discord_destinations,
    public_logo_url,
)


DEFAULT_CONFIG = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")

# Preserve the public helper name used by focused payload tests and older local
# integrations while sharing the hardened transport with all webhook delivery.
build_payload = build_discord_payload


def send_branded_discord(
    config,
    subject,
    body,
    event="",
    severity="",
    fields=None,
    timestamp="",
    *,
    source="",
    live=False,
    test=False,
    dry_run=False,
):
    results = dispatch_discord_destinations(
        config,
        subject,
        body,
        event,
        severity,
        fields,
        timestamp,
        source,
        live=live,
        test=test,
        dry_run=dry_run,
    )
    failures = [result for result in results if result["status"] != "accepted"]
    if failures:
        summary = ", ".join(
            f"{result['name']}: {result['error']}"
            for result in failures
        )
        raise RuntimeError("Discord destination submission failed: " + summary)
    return bool(results)


def main():
    config_path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_CONFIG
    with config_path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    field_names = ("Type", "Event", "Severity", "Zone", "Radius", "Recipients", "Audio", "Trigger")
    fields = [(name, os.environ.get("SLS_DISCORD_" + name.upper(), "")) for name in field_names]
    results = dispatch_discord_destinations(
        config,
        os.environ.get("SLS_DISCORD_SUBJECT", "Southland Servers Mass Notification"),
        os.environ.get("SLS_DISCORD_BODY", "A notification was issued."),
        os.environ.get("SLS_DISCORD_EVENT", ""),
        os.environ.get("SLS_DISCORD_SEVERITY", ""),
        fields,
        os.environ.get("SLS_DISCORD_TIME", ""),
        os.environ.get("SLS_NOTIFICATION_SOURCE", ""),
        live=os.environ.get("SLS_NOTIFICATION_LIVE", "0") == "1",
        test=os.environ.get("SLS_NOTIFICATION_TEST", "0") == "1",
        dry_run=os.environ.get("SLS_NOTIFICATION_DRY_RUN", "0") == "1",
        event_id=os.environ.get("SLS_DESTINATION_EVENT_ID", ""),
    )
    print(json.dumps({"results": results}, separators=(",", ":"), ensure_ascii=True))
    return 1 if any(result["status"] != "accepted" for result in results) else 0


if __name__ == "__main__":
    raise SystemExit(main())
