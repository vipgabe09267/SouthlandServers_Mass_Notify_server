#!/usr/bin/env python3
"""Focused regression checks for the v0.1.0 external destination runtime."""

import importlib.util
import json
import os
import socket
import sys
import time
from pathlib import Path


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver" / "bin" / "sls_mass_notify"


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


destinations = load_module("sls_notification_destinations_test", RUNTIME / "sls_notification_destinations.py")


def resolver_for(*addresses):
    def resolve(_hostname, port, type=0):
        assert port == 443 and type == socket.SOCK_STREAM
        return [
            (socket.AF_INET6 if ":" in address else socket.AF_INET, socket.SOCK_STREAM, 6, "", (address, port))
            for address in addresses
        ]

    return resolve


public_resolver = resolver_for("8.8.8.8")
host, path, addresses = destinations._validated_url(
    "https://alerts.example.com/hooks/receive?tenant=one", "generic", public_resolver
)
assert host == "alerts.example.com"
assert path == "/hooks/receive?tenant=one"
assert addresses == ["8.8.8.8"]
destinations._validated_url(
    "https://canary.discord.com/api/webhooks/123456/secret-token", "discord", public_resolver
)

for url in (
    "http://alerts.example.com/hook",
    "https://user:password@alerts.example.com/hook",
    "https://alerts.example.com:8443/hook",
    "https://alerts.example.com/hook#fragment",
    "https://127.0.0.1/hook",
    "https://localhost/hook",
):
    try:
        destinations._validated_url(url, "generic", public_resolver)
        raise AssertionError(url)
    except destinations.DestinationError:
        pass

for resolver in (resolver_for("10.0.0.1"), resolver_for("8.8.8.8", "127.0.0.1")):
    try:
        destinations._validated_url("https://alerts.example.com/hook", "generic", resolver)
        raise AssertionError("non-public DNS answer was accepted")
    except destinations.DestinationError as exc:
        assert exc.code == "private_address_blocked"

generic_payload = destinations.build_generic_payload(
    "Tornado Warning",
    "Take shelter now.",
    "Tornado Warning",
    "Extreme",
    "nws",
    "nws-123",
    "2026-08-15T12:00:00Z",
    {
        "zone": "TXC491",
        "client_secret": "DO-NOT-SERIALIZE",
        "recipients": ["1000", "1001"],
        "long": "x" * 5000,
    },
)
encoded_payload = json.dumps(generic_payload, separators=(",", ":")).encode("utf-8")
assert generic_payload["schema"] == "com.southlandservers.massnotify.event.v1"
assert generic_payload["schema_version"] == 1
assert generic_payload["test"] is False
assert generic_payload["source"] == "nws"
assert len(encoded_payload) < destinations.MAX_PAYLOAD_BYTES
assert b"DO-NOT-SERIALIZE" not in encoded_payload

discord_payload = destinations.build_discord_payload(
    {
        # Branding must not depend on an Internet-reachable PBX hostname.
        "public_pbx_host": "private-pbx.invalid",
        "sipnotify": {"pbx_host": "pbx"},
    },
    "Tornado Warning",
    "Take shelter now.",
    "Tornado Warning",
    "Extreme",
    timestamp="2026-08-15T12:00:00Z",
)
discord_embed = discord_payload["embeds"][0]
assert discord_payload["avatar_url"] == "https://southlandservers.xyz/images/webhook.png"
assert discord_embed["author"]["icon_url"] == "https://southlandservers.xyz/images/webhook.png"
assert discord_embed["footer"]["icon_url"] == "https://southlandservers.xyz/images/webhook.png"
assert discord_embed["thumbnail"]["url"] == "https://southlandservers.xyz/images/webhook_proxy.png"
assert "image" not in discord_embed
assert "sls_mass_notify/assets" not in json.dumps(discord_payload)
assert "v=010" not in json.dumps(discord_payload)
assert destinations.public_logo_url({}) == "https://southlandservers.xyz/images/webhook.png"

announcement_payload = destinations.build_announcement_discord_payload(
    {},
    "Facility announcement",
    "Please report to the main lobby.",
    "#123456",
    [("Audio", "TONES + TTS")],
    "2026-09-02T12:00:00Z",
)
announcement_embed = announcement_payload["embeds"][0]
assert announcement_embed["color"] == 0x123456
assert announcement_embed["footer"]["text"] == "DASHBOARD ANNOUNCEMENT • SLS Mass Notification System"
assert announcement_payload["avatar_url"] == destinations.DISCORD_AVATAR_URL
assert announcement_embed["author"]["icon_url"] == destinations.SOUTHLAND_SERVERS_LOGO_URL
assert announcement_embed["footer"]["icon_url"] == destinations.SOUTHLAND_SERVERS_LOGO_URL
assert announcement_embed["thumbnail"]["url"] == destinations.DISCORD_EMBED_IMAGE_URL

def assert_discord_limits(payload):
    embed = payload["embeds"][0]
    assert len(payload["username"]) <= 80
    assert len(embed["author"]["name"]) <= 256
    assert len(embed["title"]) <= 256
    assert len(embed["description"]) <= 4096
    assert len(embed["fields"]) <= 25
    assert all(len(field["name"]) <= 256 and len(field["value"]) <= 1024 for field in embed["fields"])
    assert len(embed["footer"]["text"]) <= 2048
    text_total = (
        len(embed["author"]["name"])
        + len(embed["title"])
        + len(embed["description"])
        + len(embed["footer"]["text"])
        + sum(len(field["name"]) + len(field["value"]) for field in embed["fields"])
    )
    assert text_total <= 6000


# Keep every user-controlled Discord field comfortably inside Discord's
# documented webhook/embed limits, including the aggregate 6000-character cap.
assert_discord_limits(discord_payload)
oversized_discord_payload = destinations.build_discord_payload(
    {},
    "S" * 2000,
    "\n".join(["B" * 2000] * 12),
    "E" * 1000,
    "Extreme",
    fields=[("N" * 1000, "V" * 4000) for _index in range(30)],
    timestamp="2026-08-15T12:00:00Z",
)
assert_discord_limits(oversized_discord_payload)
assert len(oversized_discord_payload["embeds"][0]["fields"]) == 6

config = {
    "discord_webhooks": [
        {"id": "discord_one", "name": "Operations", "url": "https://discord.com/api/webhooks/1/FIRST-SECRET", "enabled": "1"},
        {"id": "discord_disabled", "name": "Disabled", "url": "https://discord.com/api/webhooks/2/DISABLED-SECRET", "enabled": "0"},
    ],
    "generic_webhooks": [
        {"id": "generic_one", "name": "Automation", "url": "https://hooks.example.com/FIRST-GENERIC-SECRET", "enabled": "1"},
        {"id": "generic_two", "name": "Archive", "url": "https://archive.example.com/SECOND-GENERIC-SECRET", "enabled": "1"},
    ],
}
calls = []


def independent_transport(url, kind, payload, timeout, resolver, event_id):
    assert event_id and len(event_id) <= 128
    calls.append((url, kind, payload, timeout, resolver, event_id))
    return (400, "") if "SECOND-GENERIC" in url else (204, "")


for live, test, dry_run, source in (
    (False, False, False, "nws"),
    (True, True, False, "nws"),
    (True, False, True, "xweather"),
    (True, False, False, "manual"),
):
    results = destinations.dispatch_webhook_destinations(
        config, "Alert", "Body", source=source, live=live, test=test, dry_run=dry_run,
        transport=independent_transport, resolver=public_resolver, sleep=lambda _seconds: None,
    )
    assert results == []
assert calls == []

announcement_config = {
    "announcement_webhooks": [
        {"id": "announcement_one", "name": "Main channel", "url": "https://discord.com/api/webhooks/11/ANNOUNCEMENT-SECRET", "enabled": "1"},
        {"id": "announcement_two", "name": "Compatible receiver", "url": "https://hooks.example.com/discord-compatible/BACKUP-SECRET", "enabled": "1"},
        {"id": "announcement_disabled", "name": "Disabled", "url": "https://discord.com/api/webhooks/13/DISABLED-SECRET", "enabled": "0"},
    ],
    "discord_webhooks": config["discord_webhooks"],
    "generic_webhooks": config["generic_webhooks"],
}
announcement_calls = []


def announcement_transport(url, kind, payload, timeout, resolver, event_id):
    announcement_calls.append((url, kind, payload, timeout, resolver, event_id))
    return (500, "0") if "BACKUP-SECRET" in url else (204, "")


for kwargs in (
    {"live": False, "source": "dashboard"},
    {"live": True, "test": True, "source": "dashboard"},
    {"live": True, "dry_run": True, "source": "dashboard"},
    {"live": True, "source": "nws"},
):
    assert destinations.dispatch_announcement_webhooks(
        announcement_config,
        "Announcement",
        "Body",
        destination_ids=["announcement_one"],
        transport=announcement_transport,
        resolver=public_resolver,
        sleep=lambda _seconds: None,
        **kwargs,
    ) == []
assert announcement_calls == []

announcement_results = destinations.dispatch_announcement_webhooks(
    announcement_config,
    "Facility announcement",
    "Please report to the main lobby.",
    "#123456",
    [("Presentation", "Colored / Labs")],
    "2026-09-02T12:00:00Z",
    "dashboard-event-1",
    ["announcement_one", "announcement_two", "announcement_disabled", "unknown"],
    source="dashboard",
    live=True,
    transport=announcement_transport,
    resolver=public_resolver,
    sleep=lambda _seconds: None,
)
assert [result["id"] for result in announcement_results] == ["announcement_one", "announcement_two"]
assert [result["status"] for result in announcement_results] == ["accepted", "failed"]
assert announcement_calls[0][1] == "discord"
assert all(call[1] == "generic" for call in announcement_calls[1:])
assert announcement_calls[0][2]["embeds"][0]["color"] == 0x123456
assert announcement_calls[1][2]["embeds"][0]["color"] == 0x123456
serialized_announcement_results = json.dumps(announcement_results)
for secret in ("ANNOUNCEMENT-SECRET", "BACKUP-SECRET", "DISABLED-SECRET"):
    assert secret not in serialized_announcement_results

results = destinations.dispatch_webhook_destinations(
    config,
    "Alert",
    "Body",
    "Severe Thunderstorm Warning",
    "Severe",
    source="nws",
    event_id="nws-chain-1",
    live=True,
    transport=independent_transport,
    resolver=public_resolver,
    sleep=lambda _seconds: None,
)
assert len(results) == 3
assert [result["id"] for result in results] == ["discord_one", "generic_one", "generic_two"]
assert [result["status"] for result in results] == ["accepted", "accepted", "failed"]
serialized_results = json.dumps(results)
for secret in ("FIRST-SECRET", "DISABLED-SECRET", "FIRST-GENERIC-SECRET", "SECOND-GENERIC-SECRET"):
    assert secret not in serialized_results

retry_calls = []
retry_responses = [(500, "0"), (204, "")]


def retry_transport(*_args):
    retry_calls.append(True)
    return retry_responses.pop(0)


retry_result = destinations.dispatch_discord_destinations(
    {"discord_webhook_url": "https://discord.com/api/webhooks/9/LEGACY-SECRET"},
    "Alert",
    "Body",
    source="nws",
    live=True,
    transport=retry_transport,
    resolver=public_resolver,
    sleep=lambda _seconds: None,
)
assert len(retry_calls) == 2
assert retry_result[0]["status"] == "accepted" and retry_result[0]["attempts"] == 2
assert "LEGACY-SECRET" not in json.dumps(retry_result)

redirect_calls = []


def redirect_transport(*_args):
    redirect_calls.append(True)
    return 302, ""


redirect_result = destinations.dispatch_discord_destinations(
    {"discord_webhook_url": "https://discord.com/api/webhooks/9/REDIRECT-SECRET"},
    "Alert",
    "Body",
    source="nws",
    live=True,
    transport=redirect_transport,
    resolver=public_resolver,
    sleep=lambda _seconds: None,
)
assert len(redirect_calls) == 1
assert redirect_result[0]["error"] == "redirect_blocked"


class FakeClock:
    def __init__(self):
        self.now = 0.0

    def __call__(self):
        return self.now

    def advance(self, seconds):
        self.now += float(seconds)


budget_clock = FakeClock()
budget_calls = []


def over_budget_transport(url, kind, payload, timeout, resolver, event_id):
    budget_calls.append((url, timeout))
    budget_clock.advance(timeout)
    raise destinations.DestinationError("request_timeout")


budget_results = destinations.dispatch_webhook_destinations(
    config,
    "Alert",
    "Body",
    source="nws",
    live=True,
    transport=over_budget_transport,
    resolver=public_resolver,
    sleep=budget_clock.advance,
    budget_seconds=1,
    clock=budget_clock,
    enforce_wall_clock=False,
)
assert len(budget_calls) == 3
assert all(0 < timeout <= 1 / 3 + 0.001 for _url, timeout in budget_calls)
assert len(budget_results) == 3
assert all(result["status"] == "failed" for result in budget_results)
assert all(result["error"] == "delivery_budget_exhausted" for result in budget_results)
budget_serialized = json.dumps(budget_results)
for secret in ("FIRST-SECRET", "FIRST-GENERIC-SECRET", "SECOND-GENERIC-SECRET"):
    assert secret not in budget_serialized


def blocking_transport(*_args):
    time.sleep(2)
    return 204, ""


wall_started = time.monotonic()
wall_results = destinations.dispatch_discord_destinations(
    {"discord_webhook_url": "https://discord.com/api/webhooks/9/WALL-CLOCK-SECRET"},
    "Alert",
    "Body",
    source="nws",
    live=True,
    transport=blocking_transport,
    resolver=public_resolver,
    budget_seconds=0.1,
)
wall_elapsed = time.monotonic() - wall_started
assert wall_elapsed < 1.0
assert wall_results[0]["error"] == "delivery_budget_exhausted"
assert "WALL-CLOCK-SECRET" not in json.dumps(wall_results)

previous_worker_deadline = os.environ.get("SLS_WORKER_DEADLINE_EPOCH")
try:
    os.environ["SLS_WORKER_DEADLINE_EPOCH"] = "105"
    assert destinations._effective_delivery_budget(8, wall_clock=lambda: 100) == 3
    os.environ["SLS_WORKER_DEADLINE_EPOCH"] = "101"
    assert destinations._effective_delivery_budget(8, wall_clock=lambda: 100) == 0
finally:
    if previous_worker_deadline is None:
        os.environ.pop("SLS_WORKER_DEADLINE_EPOCH", None)
    else:
        os.environ["SLS_WORKER_DEADLINE_EPOCH"] = previous_worker_deadline

nws_source = (ROOT / "slsmassnotifyserver" / "bin" / "sls_mass_notify_nws_poll.sh").read_text(encoding="utf-8")
fault_function = nws_source.split("report_fault() {", 1)[1].split("\n}\n", 1)[0]
assert "send_notification_email" not in fault_function
assert "urllib.request" not in nws_source
assert "external_destinations_allowed" in nws_source
assert "/usr/bin/timeout --signal=TERM --kill-after=1" in nws_source
quiet_block = nws_source.split('if [ "$QUIET_SUPPRESS_PAGING" = "1" ]; then', 1)[1].split(
    'LOCAL_PHONE_REQUESTED=0', 1
)[0]
assert "Sent email/webhook" not in quiet_block
assert 'QUIET_DELIVERY_STATUS="queued"' in quiet_block
assert 'QUIET_DELIVERY_STATUS="partial_failure"' in quiet_block
assert quiet_block.rfind("update_status") > quiet_block.index("queue_external_destinations")

weather_scheduler_source = (ROOT / "slsmassnotifyserver" / "bin" / "sls_mass_notify_weather_poll.sh").read_text(encoding="utf-8")
assert "SLS_WORKER_DEADLINE_EPOCH" not in weather_scheduler_source
assert "CORE_WORKER_TIMEOUT_SECONDS=5400" in weather_scheduler_source
assert "/usr/bin/timeout --signal=TERM --kill-after=10" in weather_scheduler_source
assert "sls_mass_notify_xweather_poll.lock" in weather_scheduler_source
assert "/usr/bin/flock -n 8" in weather_scheduler_source
assert "/usr/bin/timeout 50" not in weather_scheduler_source

class_source = (ROOT / "slsmassnotifyserver" / "Slsmassnotifyserver.class.php").read_text(encoding="utf-8")
assert "* * * * * /usr/bin/timeout 5500 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh" in class_source
assert "$weatherCronLine = '* * * * * /usr/bin/timeout 5500 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh';" in class_source
assert "trim($line) !== $weatherCronLine" in class_source
assert "strpos((string)$line, '/usr/bin/timeout 1200')" not in class_source
assert "/usr/bin/timeout 55 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh" not in class_source

xweather_source = (RUNTIME / "sls_mass_notify_xweather_poll.py").read_text(encoding="utf-8")
assert "XWEATHER_TEST_PAYLOAD" in xweather_source
assert "external_live" in xweather_source
assert "dispatch_webhook_destinations" in xweather_source
assert destinations.DEFAULT_DELIVERY_BUDGET <= 8
assert destinations.DEFAULT_TIMEOUT <= 2
assert destinations.MAX_ATTEMPTS <= 2

print("Notification destination runtime tests passed.")
